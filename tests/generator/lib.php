<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Test data generator for tool_vae.
 *
 * WHY THIS EXISTS, AND WHY IT WAS MISSING FOR TOO LONG. Every screen of this plugin needs an
 * imported certification record to show anything at all: without one, `declare.php` stops on
 * "no certification record has been imported yet" before it builds its form. Behat therefore
 * could not reach a single form of this plugin, and the one class of defect only a browser can
 * see went unseen - a fatal error on the declaration screen, invisible to 101 unit tests
 * because each of them loads in its own setUp() a library the page did not load.
 *
 * The import itself is lot 6. This generator writes what that import will write, so the screens
 * can be tested before it exists.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Writes what the import of lot 6 will write, so the screens can be tested before it exists.
 *
 * @package    tool_vae
 * @category   test
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_vae_generator extends component_generator_base {
    /**
     * An imported certification record: a framework, one competency, and the provenance row.
     *
     * @param array $data fichecode, ficherang, shortname; description (optional, T-31: the published label).
     */
    public function create_source($data) {
        global $DB;

        $fichecode = $data['fichecode'] ?? 'RNCP36832';
        $ficherang = $data['ficherang'] ?? 'C03';

        // Le nom du sous-systeme vient de la passerelle : MC-5 vaut aussi ici, et la constante
        // est le seul endroit du plugin ou ce nom est ecrit.
        $lpg = $this->datagenerator->get_plugin_generator(\tool_vae\local\competency_gateway::SUBSYSTEM);
        // L'IDNUMBER, PAS LE SHORTNAME. C'est sur competency_framework.idnumber que
        // find_framework() retrouve un referentiel deja importe ; poser le code de fiche dans
        // le shortname laissait l'idnumber a « frm1 », et un scenario qui semait une fiche puis
        // passait par import.php avec le meme code creait un SECOND referentiel complet.
        // Le docbloc ci-dessus promet que ce generateur ecrit ce que l'import ecrit : il le
        // fait maintenant.
        $framework = $lpg->create_framework([
            'shortname' => $data['name'] ?? 'Referentiel importe',
            'idnumber' => $fichecode,
        ]);
        $lpg->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => $fichecode . '-' . $ficherang,
            'shortname' => $data['shortname'] ?? 'Competency of an imported record',
            'description' => $data['description'] ?? '',
        ]);

        // The date of the source: a number of seconds, or a day written YYYY-MM-DD (a scenario has no use for a magic number),
        // read as the date selector of the import screen reads it - midnight in the timezone of the person.
        $updated = $data['sourceupdated'] ?? strtotime('2026-06-01');
        if (is_string($updated) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $updated, $day)) {
            $updated = make_timestamp((int) $day[1], (int) $day[2], (int) $day[3], 0, 0, 0, 99);
        }

        $now = time();
        $DB->insert_record('tool_vae_source', (object) [
            'frameworkid' => (int) $framework->get('id'),
            'fichecode' => $fichecode,
            'fichetype' => $data['fichetype'] ?? 'rncp',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => (int) $updated,
            'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'France competences',
            'lastimport' => $now,
            'lastimportstatus' => 'ok',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 2,
        ]);
    }

    /**
     * One more competency in an imported record, under a bloc when `bloc` names one (T-33), through the gateway.
     *
     * @param array $data fichecode, ficherang, shortname; bloc (the rank of the bloc, optional); description (optional).
     */
    public function create_competency(array $data): void {
        $this->as_user((int) get_admin()->id, function () use ($data) {
            $gateway = new \tool_vae\local\competency_gateway();
            $code = (string) $data['fichecode'];
            $frameworkid = (int) $gateway->find_framework($code);
            $parentid = 0;
            if (!empty($data['bloc'])) {
                foreach ($gateway->list_framework_competencies($frameworkid) as $competency) {
                    if ($competency->idnumber === $code . '-' . $data['bloc']) {
                        $parentid = (int) $competency->id;
                    }
                }
                if ($parentid === 0) {
                    throw new \coding_exception('The bloc ' . $data['bloc'] . ' is not a competency of the record ' . $code . '.');
                }
            }
            $gateway->create_competency(
                $frameworkid,
                $code . '-' . $data['ficherang'],
                (string) $data['shortname'],
                (string) ($data['description'] ?? ''),
                $parentid
            );
        });
    }

    /**
     * A link attached to a declaration as a supporting piece (T-13), through the plugin's own API in the candidate's name.
     *
     * @param array $data evidenceid (the declaration), nature, title (optional), url.
     */
    public function create_piece(array $data): void {
        global $DB;

        $candidateid = (int) $DB->get_field('tool_vae_evidence', 'candidateid', ['id' => (int) $data['evidenceid']], MUST_EXIST);
        $this->as_user($candidateid, function () use ($data) {
            (new \tool_vae\local\piece_manager())->add_link(
                (int) $data['evidenceid'],
                (string) $data['nature'],
                (string) ($data['title'] ?? ''),
                (string) $data['url']
            );
        });
    }

    /**
     * A record declared to replace another (T-30), through the plugin's own API in the administrator's name.
     *
     * @param array $data fichecode (the record that is replaced), successorcode (the record that replaces it).
     */
    public function create_replacement(array $data): void {
        $this->as_user((int) get_admin()->id, function () use ($data) {
            \tool_vae\local\supersession::declare_successor((string) $data['fichecode'], (string) $data['successorcode']);
        });
    }

    /**
     * An AI provider the site has set up for text (T-70): Ollama, enabled, at an address this server never reaches.
     *
     * Only the screens up to the confirmation are played with it: nothing is ever sent.
     *
     * @param array $data name (the name the administrator gave it).
     */
    public function create_aiprovider(array $data): void {
        \core\di::get(\core_ai\manager::class)->create_provider_instance(
            'aiprovider_ollama\provider',
            (string) $data['name'],
            true,
            ['endpoint' => 'http://localhost:11434']
        );
    }

    /**
     * The equivalent an administrator decides between two competencies (T-30, lot 2), through the plugin's own API.
     *
     * @param array $data from and to (idnumbers of the two competencies, `<record>-<rank>`), note (optional).
     */
    public function create_correspondence(array $data): void {
        $this->as_user((int) get_admin()->id, function () use ($data) {
            $gateway = new \tool_vae\local\competency_gateway();
            $find = function (string $idnumber) use ($gateway): int {
                $code = substr($idnumber, 0, (int) strrpos($idnumber, '-'));
                $competencies = $gateway->list_framework_competencies((int) $gateway->find_framework($code));

                return (int) array_search($idnumber, array_column($competencies, 'idnumber', 'id'));
            };
            (new \tool_vae\local\passage_manager())->decide(
                $find((string) $data['from']),
                $find((string) $data['to']),
                (string) ($data['note'] ?? '')
            );
        });
    }

    /**
     * A declaration, made through the plugin's own API in the candidate's name.
     *
     * Through evidence_manager and never by writing rows: a declaration is two core records and
     * one of ours, and only the API knows how they hang together (MC-5). The API also checks
     * tool/vae:declare, so the scenario grants it first, exactly as a site would.
     *
     * @param array $data userid (the candidate), situation; fichecode and ficherang name the competency.
     * @return stdClass The stored declaration.
     */
    public function create_declaration(array $data): stdClass {
        $fichecode = $data['fichecode'] ?? 'RNCP36832';
        $idnumber = $fichecode . '-' . ($data['ficherang'] ?? 'C03');

        return $this->as_user((int) $data['userid'], function () use ($data, $fichecode, $idnumber) {
            // Looked up as the candidate, as declare.php does. The listing no longer asks the core's right to view the
            // competencies, but the declaration below reads $USER: nobody declares in someone else's name.
            $gateway = new \tool_vae\local\competency_gateway();
            $competencies = $gateway->list_framework_competencies((int) $gateway->find_framework($fichecode));

            // A rank the record lacks gives 0 here, and so does a record that was never created: its list is empty, not a
            // coding_exception, since list_framework_competencies() answers nothing for an id that is no framework.
            // Declaring on competency 0 is then refused by evidence_manager::validate() with errorunknowncompetency,
            // before anything is written. A scenario creates the source before its declarations.
            $competencyid = (int) array_search($idnumber, array_column($competencies, 'idnumber', 'id'));

            return (new \tool_vae\local\evidence_manager())->declare_activity((int) $data['userid'], (object) [
                'competencyid' => $competencyid,
                'periodstart' => 1756684800,
                'periodend' => 1782172800,
                'settingtype' => 'ehpad',
                'situation' => $data['situation'],
                'situationformat' => FORMAT_PLAIN,
            ]);
        });
    }

    /**
     * An attestation, made through the plugin's own API by the person who signs.
     *
     * The API checks that this person may attest this candidate's declarations, so the
     * scenario grants it first, exactly as a site would.
     *
     * @param array $data evidenceid, userid (the attestant); attestantrole and statement are optional.
     * @return stdClass The stored attestation.
     */
    public function create_attestation(array $data): stdClass {
        return $this->as_user((int) $data['userid'], function () use ($data) {
            return (new \tool_vae\local\attestation_manager())->attest((int) $data['evidenceid'], (object) [
                'attestantrole' => $data['attestantrole'] ?? 'Care manager, Les Tilleuls residential home',
                'statement' => $data['statement'] ?? 'I supervised this person over the period declared.',
            ]);
        });
    }

    /**
     * A declaration, named by the account of the situation it gives.
     *
     * By its text and not by an id: an id is assigned by the database and differs on every run.
     * The one lookup of its kind: the Behat generator and the Behat steps both ask it here.
     *
     * @param string $situation
     * @return int
     */
    public function get_declaration_id(string $situation): int {
        global $DB;

        return (int) $DB->get_field_select(
            'tool_vae_evidence',
            'id',
            $DB->sql_compare_text('situation') . ' = ' . $DB->sql_compare_text(':situation'),
            ['situation' => $situation],
            MUST_EXIST
        );
    }

    /**
     * Performs an act of the plugin's API as the person it belongs to, then restores the previous user.
     *
     * The API reads $USER on purpose - nobody declares or attests in someone else's name - so
     * a generator writing through it has to become that person for the length of the call.
     *
     * @param int $userid
     * @param callable $act
     * @return mixed What the act returns.
     */
    private function as_user(int $userid, callable $act) {
        global $USER;

        $previous = $USER;
        $this->set_user(core_user::get_user($userid, '*', MUST_EXIST));

        try {
            return $act();
        } finally {
            $this->set_user($previous);
        }
    }
}
