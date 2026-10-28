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
 * Page resolver for tool_vae.
 *
 * WHY THIS FILE HAD TO EXIST BEFORE THE SCENARIOS COULD RUN. `I am on the "..." page` is
 * resolved by behat_navigation, which counts the " > " separators in the name: with none, it
 * hands the name to the CORE resolver, which knows nothing of this plugin and throws
 * `Unrecognised core page type`. A component resolver is the only thing that makes a page name
 * of this plugin mean anything - and without it, two of the three scenarios of
 * lot0_skeleton.feature could never have run, including the one guarding against the privilege
 * escalation found in adversarial review.
 *
 * @package    tool_vae
 * @category   test
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Steps and page names for tool_vae.
 *
 * @package    tool_vae
 * @category   test
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_vae extends behat_base {
    /**
     * Pages of this plugin that need no identifier.
     *
     * Recognised page names are:
     * | pagetype | description                                        |
     * | Index    | The plugin's own administration page, index.php    |
     * | Candidates | The candidates the user follows, candidates.php |
     * | Declare  | The declaration form, declare.php                  |
     * | Import   | The import form, import.php                        |
     * | Import fiches | The import from the export of France compétences, import_fiches.php |
     * | Supersession | The replaced records, supersession.php          |
     * | Refs     | The addresses of the rules of validation, refs.php |
     * | Referents | The referents of the blocs, referents.php |
     * | Scope    | What the candidate says is outside their activity, scope.php |
     *
     * @param string $page
     * @return moodle_url
     */
    protected function resolve_page_url(string $page): moodle_url {
        switch (strtolower($page)) {
            case 'index':
                return new moodle_url('/admin/tool/vae/index.php');
            case 'candidates':
                return new moodle_url('/admin/tool/vae/candidates.php');
            case 'declare':
                return new moodle_url('/admin/tool/vae/declare.php');
            case 'import':
                return new moodle_url('/admin/tool/vae/import.php');
            case 'import fiches':
                return new moodle_url('/admin/tool/vae/import_fiches.php');
            case 'supersession':
                return new moodle_url('/admin/tool/vae/supersession.php');
            case 'scope':
                return new moodle_url('/admin/tool/vae/scope.php');
            case 'referents':
                return new moodle_url('/admin/tool/vae/referents.php');
            case 'refs':
                return new moodle_url('/admin/tool/vae/refs.php');
            case 'validation':
                return new moodle_url('/admin/tool/vae/validation.php');
            default:
                throw new Exception('Unrecognised tool_vae page type "' . $page . '."');
        }
    }

    /**
     * Pages of this plugin that name the person or the declaration they are about.
     *
     * Recognised page names are:
     * | pagetype | name meaning                | description                                                   |
     * | Dossier  | The user's username         | That candidate's dossier, dossier.php                         |
     * | Export   | The user's username         | Export of that candidate's dossier, export.php                |
     * | Attest   | The declaration's situation | Attesting that declaration, attest.php                   |
     * | Withdraw | The declaration's situation | Withdrawing the attestation standing on it, withdraw.php |
     * | History  | The user's username         | History of that candidate's first section, history.php   |
     * | Precision | The declaration's situation | Questions and answers on that declaration, precision.php |
     * | Piece    | The declaration's situation | Supporting pieces of that declaration, piece.php          |
     * | Deposit  | The user's username        | That candidate's first deposit, deposit.php              |
     * | Passage  | The record's code          | The correspondences for a replaced record, passage.php   |
     * | Remove record | The record's code     | Removing the row of that record, remove_record.php       |
     * | Import fiches | Any text             | The import from the export of France compétences, import_fiches.php |
     *
     * The identifier is a USERNAME or a TEXT, never an id: an id is assigned by the database and
     * differs on every run, so a scenario written against one would pass or fail depending on
     * the order the fixtures happened to be created in.
     *
     * @param string $page
     * @param string $identifier
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $page, string $identifier): moodle_url {
        global $DB;

        switch (strtolower($page)) {
            case 'dossier':
                $userid = $DB->get_field('user', 'id', ['username' => $identifier], MUST_EXIST);
                return new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $userid]);
            case 'export':
                $userid = $DB->get_field('user', 'id', ['username' => $identifier], MUST_EXIST);
                return new moodle_url('/admin/tool/vae/export.php', ['userid' => $userid]);
            case 'precision':
                return new moodle_url('/admin/tool/vae/precision.php', [
                    'evidenceid' => $this->get_declaration_id($identifier),
                ]);
            case 'piece':
                return new moodle_url('/admin/tool/vae/piece.php', [
                    'evidenceid' => $this->get_declaration_id($identifier),
                ]);
            case 'passage':
                return new moodle_url('/admin/tool/vae/passage.php', ['fiche' => $identifier]);
            case 'remove record':
                return new moodle_url('/admin/tool/vae/remove_record.php', ['fichecode' => $identifier]);
            case 'import fiches':
                // No identifier: the page is the same for everybody, the step only asks whether it opens.
                return new moodle_url('/admin/tool/vae/import_fiches.php');
            case 'deposit':
                $userid = $DB->get_field('user', 'id', ['username' => $identifier], MUST_EXIST);
                return new moodle_url('/admin/tool/vae/deposit.php', ['userid' => $userid, 'number' => 1]);
            case 'history':
                // The history of the first section that candidate wrote (T-81).
                $userid = $DB->get_field('user', 'id', ['username' => $identifier], MUST_EXIST);
                $draftid = $DB->get_field_select(
                    'tool_vae_draft',
                    'id',
                    "candidateid = :c AND doctype = 'faisabilite'",
                    ['c' => $userid],
                    IGNORE_MULTIPLE
                );
                return new moodle_url('/admin/tool/vae/history.php', ['draftid' => $draftid]);
            case 'attest':
                return new moodle_url('/admin/tool/vae/attest.php', [
                    'evidenceid' => $this->get_declaration_id($identifier),
                ]);
            case 'withdraw':
                $attestationid = $DB->get_field('tool_vae_attest', 'id', [
                    'evidenceid' => $this->get_declaration_id($identifier),
                    'withdrawnat' => null,
                ], MUST_EXIST);
                return new moodle_url('/admin/tool/vae/withdraw.php', ['attestationid' => $attestationid]);
            default:
                throw new Exception('Unrecognised tool_vae page type "' . $page . '."');
        }
    }

    /**
     * Opens a page of this plugin by its address and checks that it refuses, with the message given.
     *
     * WHY ONE STEP, AND NOT `I am on ... page` FOLLOWED BY `I should see`. A refusal is an
     * exception page, and Moodle's Behat inspects the page after EVERY step and fails the step
     * that leaves an exception on screen - which is why lot0_skeleton.feature gave up on
     * expressing a refusal at all. This step reads the refusal itself, then leaves for the home
     * page, so that the inspection which follows finds nothing to object to.
     *
     * The message is compared, not merely the presence of an error: a page that died on a
     * missing record would otherwise pass for a page that refused.
     *
     * @Then the :identifier :page page should be refused with :message
     * @param string $identifier As for resolve_page_instance_url().
     * @param string $page A page name of this plugin, without the component: Dossier, Attest...
     * @param string $message What the error page says, or the beginning of it.
     */
    public function the_page_should_be_refused_with(string $identifier, string $page, string $message): void {
        $url = $this->resolve_page_instance_url($page, $identifier);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));

        $error = $this->getSession()->getPage()->find('xpath', "//div[@data-rel='fatalerror']//p[@class='errormessage']");
        if (!$error) {
            throw new ExpectationException(
                'The "' . $page . '" page of "' . $identifier . '" opened instead of refusing.',
                $this->getSession()
            );
        }
        if (strpos($error->getText(), $message) === false) {
            throw new ExpectationException(
                'The "' . $page . '" page was refused with "' . $error->getText() . '", not "' . $message . '".',
                $this->getSession()
            );
        }

        $this->getSession()->visit($this->locate_path('/'));
    }

    /**
     * Attaches a fixture file of this plugin to a plain upload field.
     *
     * WHY A STEP OF ITS OWN. The core has none for a plain `<input type="file">` (its steps are for the file manager and the
     * file picker), and Mink's path is read from the working directory of the test process, which is not the plugin's:
     * `I set the field` with a relative path attaches nothing and the page then says that no file was chosen. The path is
     * built here from the place of the plugin, and the fixtures are the invented ones of tests/fixtures.
     *
     * @When I attach the fixture :file to the field :field
     * @param string $file A file name under tests/fixtures.
     * @param string $field The label, id or name of the upload field.
     */
    public function i_attach_the_fixture_to_the_field(string $file, string $field): void {
        $path = core_component::get_plugin_directory('tool', 'vae') . '/tests/fixtures/' . $file;
        if (!is_readable($path)) {
            throw new Exception('The fixture "' . $path . '" cannot be read.');
        }

        $this->find_field($field)->attachFile($path);
    }

    /**
     * Attaches to a plain upload field an invented archive of the daily export of France compétences, under the name given.
     *
     * WHY A STEP OF ITS OWN. The archive is built when the step runs, from invented records, in the shape measured on the real
     * exports (tests/fixtures/export_zip_builder.php): no datum of France compétences is in the tree (open point PO-2), and the
     * name matters, since the browser sends it and the date of the export is read from it. The file is written in a directory
     * of its own, so that two scenarios never share one.
     *
     * @When I attach a synthetic France Competences export named :name to the field :field
     * @param string $name The name the browser will send, e.g. export-fiches-csv-2026-10-19.zip.
     * @param string $field The label, id or name of the upload field.
     */
    public function i_attach_a_synthetic_export_to_the_field(string $name, string $field): void {
        require_once(__DIR__ . '/../fixtures/export_zip_builder.php');

        $directory = make_temp_directory('tool_vae_behat/' . uniqid('', true));
        $path = \tool_vae\fixtures\export_zip_builder::build($directory, $name);

        $this->find_field($field)->attachFile($path);
    }

    /**
     * Writes a text into a free-text field of an account exactly as given, with no clean-up.
     *
     * WHY A STEP OF ITS OWN. The data generator makes an account through the core, and the core strips the tags
     * from every free-text field of the profile as it does (checked on 5.3: the town "<i>Ville</i> & cie" is stored
     * as "Ville & cie"). A value that carries markup can therefore only reach the account from outside the core's
     * own paths, by a direct write or a synchronisation from another system, and it is for that value that the
     * pages of this plugin escape what they show of an account. A scenario that gives the markup to the generator
     * tests nothing: the page then rightly shows the text without it.
     *
     * @Given the :field of the account :username holds the raw text :value
     * @param string $field A free-text column of the user table that the plugin shows: city, address, phone1...
     * @param string $username The account's username.
     * @param string $value What the column must hold.
     */
    public function the_field_of_the_account_holds_the_raw_text(string $field, string $username, string $value): void {
        global $DB;

        $allowed = ['firstname', 'middlename', 'lastname', 'address', 'city', 'phone1', 'phone2'];
        if (!in_array($field, $allowed, true)) {
            throw new Exception('The field "' . $field . '" is not one that this step writes: ' . implode(', ', $allowed) . '.');
        }
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $DB->set_field('user', $field, $value, ['id' => $userid]);
    }

    /**
     * Hides the competency framework of an imported record, as "Visible: No" does in the competency frameworks screen.
     *
     * WHY A STEP OF ITS OWN. The data generator makes every framework visible, as the import does, and a scenario that must
     * show what a hidden record does needs the gesture an administrator makes in the competency frameworks screen. It is made
     * here by the table, which a test may do, and it works in the middle of a scenario too: a form opened, the record hidden,
     * then the form sent.
     *
     * @Given the framework of the record :fichecode is hidden
     * @param string $fichecode The code of the imported record.
     */
    public function the_framework_of_the_record_is_hidden(string $fichecode): void {
        global $DB;

        $frameworkid = $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => $fichecode], MUST_EXIST);
        $DB->set_field('competency_framework', 'visible', 0, ['id' => $frameworkid]);
    }

    /**
     * Writes a rank straight into a declaration, as an earlier version recorded it.
     *
     * WHY A STEP OF ITS OWN. The pages of the plugin refuse any rank outside ^[A-Z0-9]{1,8}$ (evidence_manager::validate()), so a
     * scenario cannot declare one. The rank is read from the identification number of the competency, which an administrator types
     * freely in the screen of the core; a declaration recorded by an earlier version, or written to the database, may hold `c7`.
     *
     * @Given the declaration :situation holds the rank :rank written directly
     * @param string $situation The account of the situation the declaration gives.
     * @param string $rank What the column must hold.
     */
    public function the_declaration_holds_the_rank_written_directly(string $situation, string $rank): void {
        global $DB;

        $DB->set_field('tool_vae_evidence', 'ficherang', $rank, ['id' => $this->get_declaration_id($situation)]);
    }

    /**
     * Deletes the competency framework of an imported record the way the administrator does in Moodle.
     *
     * Through the door of the plugin's gateway, which is the core's own, as the administrator: Moodle decides whether the framework
     * can go and raises the event the plugin observes. The step fails, and says so, when Moodle refuses.
     *
     * @Given the competency framework of the record :code is deleted in Moodle
     * @param string $code The code of the record.
     */
    public function the_competency_framework_of_the_record_is_deleted_in_moodle(string $code): void {
        global $USER;

        $gateway = new \tool_vae\local\competency_gateway();
        $frameworkid = $gateway->find_framework($code);
        if ($frameworkid === null) {
            throw new Exception('No competency framework carries the code "' . $code . '".');
        }

        $previous = $USER;
        \core\session\manager::set_user(get_admin());
        try {
            $deleted = $gateway->delete_framework($frameworkid);
        } finally {
            \core\session\manager::set_user($previous);
        }
        if (!$deleted) {
            throw new Exception('Moodle refused to delete the competency framework of "' . $code . '".');
        }
    }

    /**
     * Empties the tables of the competency framework of an imported record: no event, no check of the core.
     *
     * The state a framework deleted while a declaration stands would leave if the core's lock were lifted (the data of the
     * candidate erased in Moodle): the provenance row of the plugin points at a framework that is not there, and the
     * declaration is still in its table. The core would refuse the deletion, so the tables are emptied directly, which
     * tests may do.
     *
     * @Given the competency framework of the record :code is erased from Moodle without the checks of the core
     * @param string $code The code of the record.
     */
    public function the_competency_framework_of_the_record_is_erased_from_moodle(string $code): void {
        global $DB;

        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => $code], MUST_EXIST);
        $DB->delete_records('competency', ['competencyframeworkid' => $frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $frameworkid]);
    }

    /**
     * A declaration, named by the account of the situation it gives.
     *
     * The data generator's lookup, reached as behat_mod_forum reaches its own: a page and a
     * generated row then name a declaration the same way.
     *
     * @param string $situation
     * @return int
     */
    private function get_declaration_id(string $situation): int {
        return behat_util::get_data_generator()->get_plugin_generator('tool_vae')->get_declaration_id($situation);
    }
}
