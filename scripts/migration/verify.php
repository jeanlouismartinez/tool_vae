<?php
// @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
//
// Migration 4.5 -> 5.3, seconde moitie : apres la mise a jour vers Moodle 5.3 et le paquet
// MOODLE_503, verifie que rien n'a bouge et que l'archive et la sauvegarde prises sous 4.5 se
// reimportent sous 5.3.
//
// Usage : php verify.php <racine moodle> <dossier ecrit par seed.php>
// Sort en erreur (code 1) au premier ecart, en le nommant.
//
// CE QUI EST COMPARE A L'OCTET, ET CE QUI NE L'EST PAS. La reference est l'archive exportee sous
// 4.5, telle quelle. Ses fichiers preuves/<ULID>.json, un par declaration, doivent revenir sous 5.3
// avec les memes noms et les memes octets : ni l'identifiant de l'archive ni sa date n'y entrent,
// et exporter::preuve() comme exporter::encode() sont les memes dans les deux paquets.
// Le reste de l'archive differe par construction et n'est pas compare : manifeste.json (ULID et
// date de l'archive, versions de Moodle et du plugin, empreintes, dossier.pdf dans la liste depuis
// la 0.10.0), LISEZMOI.txt (date et ULID de l'archive), dossier.pdf (nouveau en 0.10.0) ;
// referentiel.json et schema/ ne sont pas le dossier, et le reimport les verifie par leurs empreintes.
// Apres un reimport, une partie d'une preuve change, et doit changer : correspondances[], le seul
// tableau des numeros de la plateforme. Une attestation reimportee n'est liee a aucun compte (voir
// dossier_importer::attestation_row()), et sur un compte neuf le numero du candidat change. Le reste
// est compare sur les octets re-encodes sans ce tableau : memes cles, meme ordre, memes types.

define('CLI_SCRIPT', true);

[$script, $moodleroot, $out] = $argv;
require($moodleroot . '/config.php');
require_once($CFG->dirroot . '/user/lib.php');

use tool_vae\local\dossier_importer;
use tool_vae\local\exporter;
use tool_vae\local\purge;

/**
 * Arrete la verification sur un ecart.
 *
 * @param string $message
 */
function ecart(string $message): void {
    fwrite(STDERR, "ECART : $message\n");
    exit(1);
}

/**
 * Les fichiers preuves/*.json d'une archive, chemin => octets, ranges par chemin.
 *
 * @param string[] $files chemin => contenu, comme exporter::build() et dossier_importer::read_archive() les donnent.
 * @return string[]
 */
function preuves_de(array $files): array {
    $preuves = array_filter($files, function ($path): bool {
        return strpos($path, 'preuves/') === 0;
    }, ARRAY_FILTER_USE_KEY);
    ksort($preuves);

    return $preuves;
}

/**
 * Les preuves telles que l'export du paquet 5.3 les ecrit aujourd'hui.
 *
 * build() plutot qu'export() : ni archive stockee ni ligne au registre, et l'identifiant comme la
 * date passes ici ne vont que dans le manifeste, la notice et le PDF.
 *
 * @param int $candidateid
 * @return string[] chemin => octets.
 */
function preuves(int $candidateid): array {
    global $DB;

    $rows = $DB->get_records('tool_vae_evidence', ['candidateid' => $candidateid], 'timecreated ASC, id ASC');

    return preuves_de((new exporter())->build($candidateid, $rows, 'X', time()));
}

/**
 * Les memes preuves sans correspondances[], re-encodees de la meme facon des deux cotes.
 *
 * @param string[] $preuves chemin => octets.
 * @return string[]
 */
function sans_correspondances(array $preuves): array {
    return array_map(function (string $octets): string {
        $preuve = json_decode($octets, true, 512, JSON_THROW_ON_ERROR);
        unset($preuve['correspondances']);

        return json_encode($preuve, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }, $preuves);
}

/**
 * Compare deux jeux de preuves fichier par fichier, octet par octet, et nomme le premier ecart.
 *
 * @param string[] $attendues chemin => octets.
 * @param string[] $relues chemin => octets.
 * @param string $quoi Ce qui est compare, pour le message.
 */
function comparer(array $attendues, array $relues, string $quoi): void {
    if (array_keys($attendues) !== array_keys($relues)) {
        ecart("$quoi : pas les memes preuves (attendues " . implode(', ', array_keys($attendues)) . ' ; trouvees ' .
            implode(', ', array_keys($relues)) . ')');
    }
    foreach ($attendues as $path => $octets) {
        if ($relues[$path] !== $octets) {
            ecart("$quoi : $path differe");
        }
    }
}

/**
 * Retire les declarations d'un compte une a une, sans toucher a la personne.
 *
 * Comme si le candidat effacait ses preuves dans les plans d'apprentissage : son identite et les
 * lignes du registre restent. purge::dossier() ne convient pas ici : depuis la 0.10.1, il efface
 * aussi les lignes du registre du candidat, et l'archive se lirait alors, a bon droit, comme etrangere.
 *
 * @param int $candidateid
 */
function vider(int $candidateid): void {
    global $DB;

    $userevidenceids = $DB->get_fieldset_select('tool_vae_evidence', 'userevidenceid', 'candidateid = ?', [$candidateid]);
    foreach ($userevidenceids as $userevidenceid) {
        purge::by_userevidence((int) $userevidenceid);
    }
    if ($DB->record_exists('tool_vae_evidence', ['candidateid' => $candidateid])) {
        ecart('le retrait des declarations a laisse des preuves');
    }
}

/**
 * Efface le dossier d'un compte et sa personne, comme une demande d'effacement.
 *
 * @param int $candidateid
 */
function purger(int $candidateid): void {
    global $DB;

    purge::dossier($candidateid);
    // Compte en base, pas par l'export : exporter::build() donnerait un nouvel ULID au candidat, et le
    // reimport refuserait alors, a bon droit, un compte connu sous un autre identifiant.
    if ($DB->record_exists('tool_vae_evidence', ['candidateid' => $candidateid])) {
        ecart('la purge a laisse des preuves');
    }
}

/**
 * Reimporte une archive prise sous 4.5, et verifie ce que le reimport en a fait.
 *
 * @param int $candidateid Le compte qui recoit le dossier.
 * @param string $zippath
 * @param string[] $avant Les preuves de l'archive exportee sous 4.5, chemin => octets.
 * @param string $quoi Ce qui est reimporte, pour les messages.
 * @param bool $etrangere Ce que le registre doit repondre : delivree ici (false) ou etrangere (true).
 * @return stdClass Ce que dossier_importer::import() renvoie.
 */
function reimporter(int $candidateid, string $zippath, array $avant, string $quoi, bool $etrangere): stdClass {
    $resultat = (new dossier_importer())->import($candidateid, $zippath);

    // Le paquet 4.5 inscrit deja ses archives au registre, et la table traverse la mise a jour :
    // une archive 4.5 lue ici comme etrangere voudrait dire que le registre s'est perdu en route.
    if (!$etrangere && $resultat->foreign) {
        ecart("$quoi : lue comme etrangere, le registre des archives n'a pas traverse la mise a jour");
    }
    // Et une archive dont le candidat a ete efface ne doit plus etre garantie par la plateforme.
    if ($etrangere && !$resultat->foreign) {
        ecart("$quoi : garantie par le registre alors que le candidat a ete efface");
    }

    $attestations = 0;
    foreach ($avant as $octets) {
        $attestations += count(json_decode($octets, true)['attestations']);
    }
    if ($resultat->declarations !== count($avant) || $resultat->attestations !== $attestations) {
        ecart("$quoi : {$resultat->declarations} declarations et {$resultat->attestations} attestations " .
            'reecrites, ' . count($avant) . " et $attestations attendues");
    }

    comparer(sans_correspondances($avant), sans_correspondances(preuves($candidateid)), $quoi);

    return $resultat;
}

$etat = json_decode(file_get_contents($out . '/etat-405.json'), true);
$candidateid = (int) $etat['candidat'];

// La reference : les preuves telles que l'export sous 4.5 les a ecrites dans l'archive.
$avant = preuves_de(dossier_importer::read_archive($out . '/archive-405.zip'));
if (!$avant) {
    ecart("l'archive exportee sous 4.5 ne contient aucune preuve");
}

\core\session\manager::set_user(get_admin());

// 1. La mise a jour est allee a son terme : le plugin porte le numero du paquet 5.3.
$plugin = new stdClass();
require($CFG->dirroot . '/admin/tool/vae/version.php');
$installe = get_config('tool_vae', 'version');
if ((string) $installe !== (string) $plugin->version) {
    ecart("version installee $installe, paquet {$plugin->version}");
}
if ((int) $installe <= (int) $etat['version']) {
    ecart("le numero de version n'a pas monte ({$etat['version']} -> $installe)");
}

// 1 bis. Les etapes propres a la 5.3 ont tourne : la demande de retrait (etape 2026101311) ajoute deux
// colonnes a tool_vae_attest. Un paquet 4.5 numerote au-dessus de cette etape passerait le controle de
// version ci-dessus et sauterait l'etape sans que rien ne le dise (voir docs/BRANCHES.md).
$colonnes = $DB->get_columns('tool_vae_attest');
foreach (['withdrawreqby', 'withdrawreqat'] as $colonne) {
    if (!isset($colonnes[$colonne])) {
        ecart("la colonne tool_vae_attest.$colonne manque apres la mise a jour : l'etape 2026101311 n'a pas tourne");
    }
}

// 2. Les dossiers ont traverse la mise a jour a l'octet pres, correspondances comprises : meme
// plateforme, memes numeros.
comparer($avant, preuves($candidateid), 'les preuves relues sous 5.3');

// 3. La sauvegarde prise sous 4.5 est toujours dans les fichiers prives de l'administrateur, et elle
// tient le meme dossier. Copiee hors de Moodle avant les purges, qui effacent les sauvegardes du
// candidat : c'est ce fichier-la, garde par Moodle a travers la mise a jour, que l'etape 5 reimporte.
$admincontext = context_user::instance(get_admin()->id)->id;
$sauvegarde = get_file_storage()->get_file($admincontext, 'user', 'private', 0, '/vae/', $etat['sauvegarde']);
if (!$sauvegarde) {
    ecart('la sauvegarde prise sous 4.5 a disparu : ' . $etat['sauvegarde']);
}
$copie = $out . '/sauvegarde-405.zip';
if (!$sauvegarde->copy_content_to($copie)) {
    ecart('la sauvegarde prise sous 4.5 ne se copie pas vers ' . $copie);
}
comparer($avant, preuves_de(dossier_importer::read_archive($copie)), 'la sauvegarde prise sous 4.5');

// 4. L'archive exportee sous 4.5 se reimporte sous 5.3, sur le compte d'origine, et le registre
// la reconnait comme delivree ici : c'est la preuve qu'il a traverse la mise a jour. Les declarations
// sont retirees une a une, la personne reste, et avec elle les lignes du registre.
vider($candidateid);
$archive = reimporter($candidateid, $out . '/archive-405.zip', $avant, "l'archive exportee sous 4.5", false);

// 5. La sauvegarde se reimporte sur un compte neuf : le dossier ne tient pas au numero du compte
// qui l'a porte. Le premier est efface d'abord, sans quoi ses ULID refuseraient le reimport ; ses
// lignes du registre partent avec lui (0.10.1), et la sauvegarde se lit donc comme etrangere.
purger($candidateid);
$neuf = user_create_user((object) [
    'username' => 'reprise',
    'firstname' => 'Reprise',
    'lastname' => 'Migration',
    'email' => 'reprise@example.com',
    'auth' => 'manual',
    'confirmed' => 1,
    'mnethostid' => $CFG->mnet_localhost_id,
]);
$reprise = reimporter((int) $neuf, $copie, $avant, 'la sauvegarde prise sous 4.5', true);

echo "5.3 : mise a jour {$etat['version']} -> $installe sur Moodle {$CFG->release} ; " .
    count($avant) . " preuves identiques a l'octet ; archive 4.5 reimportee ({$archive->declarations} " .
    "declarations, {$archive->attestations} attestations, emise ici selon le registre) ; sauvegarde 4.5 " .
    "presente et reimportee sur un compte neuf apres effacement du premier ({$reprise->declarations} " .
    "declarations, {$reprise->attestations} attestations, etrangere une fois le candidat efface).\n";
