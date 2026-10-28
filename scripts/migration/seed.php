<?php
// @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
//
// Migration 4.5 -> 5.3, premiere moitie : sur un Moodle 4.5 portant le paquet MOODLE_405, cree des
// dossiers, en exporte un et en sauvegarde un. verify.php relit tout cela apres la mise a jour vers
// 5.3 et le paquet MOODLE_503.
//
// Usage : php seed.php <racine moodle> <dossier de sortie>
// Hors de Moodle et hors du standard de codage (voir .moodle-plugin-ci.yml) : un outil de la CI.

define('CLI_SCRIPT', true);

[$script, $moodleroot, $out] = $argv;
require($moodleroot . '/config.php');
require_once($CFG->dirroot . '/user/lib.php');

use tool_vae\local\attestation_manager;
use tool_vae\local\backup;
use tool_vae\local\competency_gateway;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\importer;

$admin = get_admin();
\core\session\manager::set_user($admin);

// Ce qu'un administrateur ferait : importer la fiche par le plugin, pas par un generateur de test
// (qui demande PHPUnit) ni par la base (que MC-5 interdit hors de la passerelle).
$csv = file_get_contents(__DIR__ . '/../../tests/fixtures/fiche-exemple.csv');
(new importer())->import((object) [
    'fichecode' => 'RNCP36832',
    'fichetype' => 'rncp',
    'name' => 'Fiche de la migration',
    'sourceurl' => 'https://www.francecompetences.fr/',
    'sourceupdated' => 1748736000,
    'licence' => 'Licence Ouverte 2.0',
    'attribution' => 'France competences',
], importer::rows_from_csv($csv));
$frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP36832'], MUST_EXIST);
$competencyid = 0;
foreach ((new competency_gateway())->list_framework_competencies($frameworkid) as $competency) {
    if ($competency->idnumber === 'RNCP36832-BC01') {
        $competencyid = (int) $competency->id;
    }
}

/**
 * Un compte, comme le cree l'administration.
 *
 * @param string $username
 * @return stdClass
 */
function compte(string $username): stdClass {
    global $CFG;

    $id = user_create_user((object) [
        'username' => $username,
        'password' => 'Migration-1234',
        'firstname' => ucfirst($username),
        'lastname' => 'Migration',
        'email' => $username . '@example.com',
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
    ]);

    return core_user::get_user($id, '*', MUST_EXIST);
}

$system = context_system::instance()->id;
$candidaterole = create_role('Candidat VAE', 'candidatvae', '');
foreach (['declare', 'viewown', 'export'] as $capability) {
    assign_capability('tool/vae:' . $capability, CAP_ALLOW, $candidaterole, $system, true);
}
$managerrole = create_role('Responsable VAE', 'responsablevae', '');
foreach (['viewassigned', 'attest'] as $capability) {
    assign_capability('tool/vae:' . $capability, CAP_ALLOW, $managerrole, $system, true);
}
set_role_contextlevels($managerrole, [CONTEXT_USER]);

$candidate = compte('candidat');
$manager = compte('responsable');
$usercontext = context_user::instance($candidate->id)->id;
role_assign($candidaterole, $candidate->id, $usercontext);
role_assign($managerrole, $manager->id, $usercontext);
accesslib_clear_all_caches(true);

$claims = [];
foreach (['ehpad', 'domicile'] as $setting) {
    \core\session\manager::set_user($candidate);
    $claims[] = (new evidence_manager())->declare_activity((int) $candidate->id, (object) [
        'competencyid' => $competencyid,
        'periodstart' => 1756684800,
        'periodend' => 1782777600,
        'settingtype' => $setting,
        'settingdetail' => 'Equipe de jour',
        'situation' => 'Accompagnement au lever, recherche de son accord a chaque geste.',
        'situationformat' => FORMAT_MARKDOWN,
    ]);
}

\core\session\manager::set_user($manager);
$statement = (object) [
    'attestantrole' => 'Cadre de sante, EHPAD Les Tilleuls',
    'statement' => 'Je soussignee atteste avoir encadre le candidat.',
];
(new attestation_manager())->attest((int) $claims[0]->id, $statement);
$second = (new attestation_manager())->attest((int) $claims[1]->id, $statement);

// Depuis la 0.8.4, seul l'administrateur du site retire une attestation.
\core\session\manager::set_user($admin);
(new attestation_manager())->withdraw((int) $second->id, 'erreur');
check_dir_exists($out, true, true);

(new exporter())->export((int) $candidate->id)->copy_content_to($out . '/archive-405.zip');
$backup = backup::save((int) $candidate->id, (int) $admin->id);

// Pas de copie des preuves ici : verify.php compare a l'octet celles de archive-405.zip, l'export
// lui-meme, et relit la sauvegarde dans les fichiers prives, la ou Moodle l'a gardee.
file_put_contents($out . '/etat-405.json', json_encode([
    'version' => get_config('tool_vae', 'version'),
    'moodle' => $CFG->release,
    'candidat' => (int) $candidate->id,
    'sauvegarde' => $backup->get_filename(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "4.5 : " . $DB->count_records('tool_vae_evidence', ['candidateid' => $candidate->id]) .
    " preuves, archive et sauvegarde ecrites, plugin " .
    get_config('tool_vae', 'version') . " sur Moodle {$CFG->release}\n";
