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

namespace tool_vae;

use tool_vae\event\record_removed;
use tool_vae\event\supersession_changed;
use tool_vae\form\remove_record_form;
use tool_vae\local\competency_gateway;
use tool_vae\local\draft_manager;
use tool_vae\local\evidence_manager;
use tool_vae\local\identity_map;
use tool_vae\local\passage_manager;
use tool_vae\local\record_remover;
use tool_vae\local\ref_manager;
use tool_vae\local\referent_manager;
use tool_vae\local\scope_manager;
use tool_vae\local\supersession;
use tool_vae\local\validation_catalog;

/**
 * Removing the row of a record that was imported by mistake, once Moodle no longer holds its framework.
 *
 * THE CASE. The file of one diploma imported under the code of another: the record exists, the manager deletes its framework in
 * Moodle (the core allows it while nothing uses a competency of it), and the row of this plugin stays, with its addresses, its
 * referents, its correspondences and its replacing record. The tests use the code RNCP36832 for the record imported by mistake,
 * RNCP40692 for a record that stays and RNCP41747 for a third one that was declared to be replaced by the first.
 *
 * WHAT IS ASSERTED. That the row is removable only when Moodle holds no framework for it (the row pointing at nothing, or at a
 * framework gone) and no candidate has left a trace on the code: a declaration, a mark "Outside my activity", a section of the
 * preparation of the validation dossier; that a trace on another record blocks nothing; that the list of the records tells the two
 * causes of a missing button apart (Moodle holds a framework carrying the code, a candidate left a trace) and that each cause has
 * its own sentence in both languages; that everything the plugin kept for the record goes in one act (provenance, addresses,
 * referents, marks of departure, correspondences, replacing record) and no row of another record does, except a correspondence
 * that has lost a competency at one end (the table keeps two ids, not the framework of a competency that is gone, so it cannot be
 * told from this record's own: it is taken, and counted); that the link of another record naming this one as its replacement is
 * cleared; that only the right to import is asked; that the log names the record and the counts; that the confirmation screen
 * lists what leaves, and writes every value it prints as text (titles and addresses, licence, attribution, codes of records);
 * and that nothing is written in the tables of the competency subsystem.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\record_remover::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\form\remove_record_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\record_removed::class)]
final class record_remover_test extends \advanced_testcase {
    /** @var int[] Competency ids by idnumber, as the record numbered them. */
    private $ids = [];

    /** @var \stdClass[] The two members of the staff named referents, by first name. */
    private $staff = [];

    /**
     * Three records with their competencies, and everything an administrator may have entered for the first one.
     *
     * RNCP36832 is the record imported by mistake: a bloc with two competencies, two addresses, two referents, a mark of
     * departure, a replacing record (RNCP40692) and two correspondences towards it. RNCP41747 was declared to be replaced by
     * RNCP40692 first, then by RNCP36832 (a mistake of the same family): it holds one correspondence towards each. RNCP40692
     * keeps an address, a referent and a mark of its own.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        require_once($CFG->libdir . '/formslib.php');
        $this->setAdminUser();
        validation_catalog::reset();

        $this->record('RNCP41747', 'Educateur specialise', [
            'C03' => ['Evaluer la situation de la personne', null],
            'C04' => ['Contribuer au projet personnalise', null],
        ]);
        $this->record('RNCP36832', 'Aide-soignant (importe par erreur)', [
            'BC01' => ['Accompagner la personne dans les actes de la vie quotidienne', null],
            'C03' => ['Reperer les facteurs de risque de chute', 'BC01'],
            'C04' => ['Aider a la prise des repas', 'BC01'],
        ]);
        $this->record('RNCP40692', 'Aide-soignant', [
            'BC03' => ['Evaluer l\'etat clinique d\'une personne et mettre en oeuvre des soins adaptes', null],
            'C01' => ['Accompagner la personne dans ses deplacements', null],
        ]);

        // The correspondences are decided against the record that replaces, so the replacing record is declared first.
        supersession::declare_successor('RNCP36832', 'RNCP40692');
        $passages = new passage_manager();
        $passages->decide($this->ids['RNCP36832-C03'], $this->ids['RNCP40692-BC03'], 'Meme domaine');
        $passages->decide($this->ids['RNCP36832-BC01'], $this->ids['RNCP40692-C01']);
        supersession::declare_successor('RNCP41747', 'RNCP40692');
        $passages->decide($this->ids['RNCP41747-C03'], $this->ids['RNCP40692-BC03']);
        supersession::declare_successor('RNCP41747', 'RNCP36832');
        $passages->decide($this->ids['RNCP41747-C04'], $this->ids['RNCP36832-C04']);

        ref_manager::add('RNCP36832', 'Fiche du repertoire', 'https://example.org/rncp/36832/', time() - DAYSECS);
        ref_manager::add('RNCP36832', 'Arrete <b>du</b> diplome', 'https://example.org/arrete/36832');
        ref_manager::add('RNCP40692', 'Fiche du repertoire', 'https://example.org/rncp/40692/');

        $generator = $this->getDataGenerator();
        foreach (['alice', 'bruno'] as $name) {
            $this->staff[$name] = $generator->create_user(['username' => $name]);
        }
        referent_manager::add('RNCP36832', 'BC01', (int) $this->staff['alice']->id);
        referent_manager::add('RNCP36832', '', (int) $this->staff['bruno']->id);
        referent_manager::add('RNCP40692', '', (int) $this->staff['alice']->id);

        // One competency of each record has left its file at an import.
        foreach (['RNCP36832-C04', 'RNCP40692-C01'] as $idnumber) {
            $code = substr($idnumber, 0, (int) strrpos($idnumber, '-'));
            $DB->insert_record('tool_vae_obsolete', (object) [
                'competencyid' => $this->ids[$idnumber],
                'sourceid' => (int) $DB->get_field('tool_vae_source', 'id', ['fichecode' => $code], MUST_EXIST),
                'detectedat' => time(),
                'kind' => 'missing',
            ]);
        }
    }

    /**
     * An imported record, as the import leaves it: a framework, its competencies (a bloc first), and the provenance row.
     *
     * @param string $code The code of the record.
     * @param string $name The name of the framework.
     * @param array $plan Rank => [label, rank of the bloc it sits under or null], blocs first.
     */
    private function record(string $code, string $name, array $plan): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $framework = $generator->create_framework(['idnumber' => $code, 'shortname' => $name]);
        $now = time();
        $DB->insert_record('tool_vae_source', (object) [
            'frameworkid' => (int) $framework->get('id'),
            'fichecode' => $code,
            'fichetype' => 'rncp',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'France competences',
            'lastimport' => $now,
            'lastimportstatus' => 'ok',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int) get_admin()->id,
        ]);
        foreach ($plan as $rank => [$label, $bloc]) {
            $competency = $generator->create_competency([
                'competencyframeworkid' => $framework->get('id'),
                'idnumber' => "$code-$rank",
                'shortname' => $label,
                'parentid' => $bloc === null ? 0 : $this->ids["$code-$bloc"],
            ]);
            $this->ids["$code-$rank"] = (int) $competency->get('id');
        }
    }

    /**
     * The framework of a record deleted the way the administrator does it in Moodle: through the core, which raises its event.
     *
     * @param string $code
     */
    private function delete_in_moodle(string $code): void {
        global $DB;

        $this->setAdminUser();
        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => $code], MUST_EXIST);
        $this->assertTrue((new competency_gateway())->delete_framework($frameworkid), "Moodle refused to delete $code.");
    }

    /**
     * The framework of a record gone from the tables with no event, so that the observer of the plugin does not run.
     *
     * The state an interrupted deletion or a restored database leaves, and the one the core's lock leaves behind once a
     * candidate's data have been erased: the row of the plugin points at a framework that is not there.
     *
     * @param string $code
     */
    private function erase_from_moodle(string $code): void {
        global $DB;

        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => $code], MUST_EXIST);
        $DB->delete_records('competency', ['competencyframeworkid' => $frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $frameworkid]);
    }

    /**
     * A candidate who may declare and write the preparation of the validation dossier.
     *
     * @return \stdClass
     */
    private function candidate(): \stdClass {
        $generator = $this->getDataGenerator();
        $candidate = $generator->create_user();
        $roleid = $generator->create_role();
        foreach (['declare', 'draftown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        }
        role_assign($roleid, $candidate->id, \context_user::instance($candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $candidate;
    }

    /**
     * Refuse, and say with which error code.
     *
     * @param callable $call
     * @param string $errorcode
     */
    private function assert_refused(callable $call, string $errorcode): void {
        try {
            $call();
            $this->fail("Expected $errorcode.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * How many rows each table of the plugin that mentions a record holds.
     *
     * @return int[] By table.
     */
    private function counts(): array {
        global $DB;

        $counts = [];
        foreach (['source', 'ref', 'referent', 'obsolete', 'passage', 'evidence', 'scope', 'draft'] as $table) {
            $counts[$table] = $DB->count_records('tool_vae_' . $table);
        }

        return $counts;
    }

    /**
     * A record gone from Moodle and untouched by any candidate can be removed.
     */
    public function test_a_record_gone_from_moodle_and_untouched_by_any_candidate_can_be_removed(): void {
        $this->delete_in_moodle('RNCP36832');
        $remover = new record_remover();

        $this->assertNull($remover->refusal('RNCP36832'));
        // Of the three, only the one Moodle no longer holds is offered.
        $this->assertSame(['RNCP36832'], $remover->removable(['RNCP41747', 'RNCP36832', 'RNCP40692']));
    }

    /**
     * A first import that died leaves a removable row.
     */
    public function test_a_first_import_that_died_leaves_a_removable_row(): void {
        global $DB;

        // A code typed with a digit missing, imported once: the import died, the row says failed and points at nothing.
        $now = time();
        $DB->insert_record('tool_vae_source', (object) [
            'frameworkid' => 0, 'fichecode' => 'RNCP4069', 'fichetype' => 'rncp', 'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => 0, 'licence' => 'Licence Ouverte 2.0', 'attribution' => 'France competences',
            'lastimport' => $now, 'lastimportstatus' => 'error', 'timecreated' => $now, 'timemodified' => $now,
            'usermodified' => (int) get_admin()->id,
        ]);

        $remover = new record_remover();
        $this->assertNull($remover->refusal('RNCP4069'));
        $remover->remove('RNCP4069');
        $this->assertFalse($DB->record_exists('tool_vae_source', ['fichecode' => 'RNCP4069']));
    }

    /**
     * The row stays while Moodle still holds the framework.
     */
    public function test_the_row_stays_while_moodle_still_holds_the_framework(): void {
        global $DB;

        $before = $this->counts();
        $remover = new record_remover();

        $refusal = $remover->refusal('RNCP36832');
        $this->assertSame('errorremoverecordframework', $refusal->errorcode);
        $this->assert_refused(fn() => $remover->remove('RNCP36832'), 'errorremoverecordframework');

        // Hidden is not gone: the framework is there, and hiding it is the first gesture the guide gives.
        $DB->set_field('competency_framework', 'visible', 0);
        $this->assert_refused(fn() => $remover->remove('RNCP36832'), 'errorremoverecordframework');
        $this->assertSame($before, $this->counts());
        $this->assertSame([], $remover->removable(['RNCP36832', 'RNCP40692']));
    }

    /**
     * A framework carrying the code in Moodle blocks a row that points at nothing.
     */
    public function test_a_framework_carrying_the_code_in_moodle_blocks_a_row_that_points_at_nothing(): void {
        global $DB;

        // The row has lost its framework, but another one, made by hand in Moodle, carries the code: a new import would take
        // it over, and the record is not gone from Moodle.
        $this->delete_in_moodle('RNCP36832');
        $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM)
            ->create_framework(['idnumber' => 'RNCP36832', 'shortname' => 'Fait a la main']);

        $this->assert_refused(fn() => (new record_remover())->remove('RNCP36832'), 'errorremoverecordframework');
        $this->assertTrue($DB->record_exists('tool_vae_source', ['fichecode' => 'RNCP36832']));
    }

    /**
     * The list of the records tells the two causes of a missing button apart.
     *
     * The real case of the review: the record imported by mistake is deleted in Moodle, then a manager makes a framework by hand
     * under the same code, and no candidate has written anything on it. The page said "a candidate has left a trace" for every
     * row without a button; the manager looked for a trace that was not there.
     */
    public function test_the_list_tells_a_framework_held_by_moodle_from_a_trace_of_a_candidate(): void {
        $candidate = $this->candidate();
        set_config('scopeopen', 1, 'tool_vae');
        $this->setUser($candidate);
        // A mark "Outside my activity" never locked the framework: Moodle lets it go, and the trace stays.
        scope_manager::mark((int) $candidate->id, 'RNCP41747', 'C03');
        $this->setAdminUser();

        // The three records are gone from Moodle, as source_frameworks::missing() hands them to the page.
        foreach (['RNCP36832', 'RNCP41747', 'RNCP40692'] as $code) {
            $this->delete_in_moodle($code);
        }
        // RNCP36832: a framework made by hand carries the code again. Nobody has written on any of the three but the mark above.
        $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM)
            ->create_framework(['idnumber' => 'RNCP36832', 'shortname' => 'Fait a la main']);

        // RNCP00000 is a row that is no longer there when the question is asked: it belongs to no list.
        $sorted = (new record_remover())->sort_by_refusal(['RNCP36832', 'RNCP41747', 'RNCP40692', 'RNCP00000']);

        $this->assertSame(['RNCP40692'], $sorted->removable);
        $this->assertSame(['RNCP36832'], $sorted->framework);
        $this->assertSame(['RNCP41747'], $sorted->traces);
        // The same answers, one by one, as the screen of a single record gives them.
        $remover = new record_remover();
        $this->assertSame('errorremoverecordframework', $remover->refusal('RNCP36832')->errorcode);
        $this->assertSame('errorremoverecordtraces', $remover->refusal('RNCP41747')->errorcode);
        $this->assertSame(['RNCP40692'], $remover->removable(['RNCP36832', 'RNCP41747', 'RNCP40692']));
    }

    /**
     * Each cause of a missing button has its own sentence on the list, in both languages, and neither names the other's cause.
     */
    public function test_each_cause_of_a_missing_button_has_its_own_sentence_on_the_list(): void {
        foreach (['en', 'fr'] as $lang) {
            // Read from the plugin's own file: the French pack is not installed in the test site, and the string manager then
            // answers in English whatever language is asked.
            $string = [];
            include(__DIR__ . '/../lang/' . $lang . '/tool_vae.php');

            $this->assertArrayHasKey('sourcenoframeworkheld', $string, "lang/$lang has no sentence for a framework Moodle holds.");
            $held = $string['sourcenoframeworkheld'];
            $traced = $string['sourcenoframeworktraced'];

            // Each lists its own records.
            $this->assertStringContainsString('{$a}', $held, "lang/$lang: the sentence does not name the records.");
            $this->assertStringContainsString('{$a}', $traced, "lang/$lang: the sentence does not name the records.");
            // The framework: Moodle holds it, and the way out is in Moodle; not a word of a trace.
            $this->assertMatchesRegularExpression('/Moodle (holds|détient)/u', $held, "lang/$lang");
            $this->assertDoesNotMatchRegularExpression('/trace|candidate|vaéiste|vaeiste/iu', $held, "lang/$lang blames a trace.");
            // The trace: a person wrote; not a word of a framework.
            $this->assertMatchesRegularExpression('/trace/iu', $traced, "lang/$lang");
            $this->assertDoesNotMatchRegularExpression('/framework|référentiel/iu', $traced, "lang/$lang blames a framework.");
            $this->assertNotSame($held, $traced);
        }
    }

    /**
     * A declaration blocks the removal and Moodle itself would have refused to delete the framework.
     */
    public function test_a_declaration_blocks_the_removal_and_moodle_itself_would_have_refused_to_delete_the_framework(): void {
        global $DB;

        $candidate = $this->candidate();
        $this->setUser($candidate);
        (new evidence_manager())->declare_activity((int) $candidate->id, (object) [
            'competencyid' => $this->ids['RNCP36832-C03'], 'periodstart' => 1756684800, 'periodend' => 1782777600,
            'settingtype' => 'ehpad', 'situation' => 'Ronde de nuit aupres de residents a risque de chute.',
            'situationformat' => FORMAT_PLAIN,
        ]);
        $this->setAdminUser();

        // The core's own lock, which nothing measured until now: one declaration links a competency of the record.
        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP36832'], MUST_EXIST);
        $this->assertFalse((new competency_gateway())->delete_framework($frameworkid));

        // The lock is lifted when the data of the candidate are erased in Moodle: the tables are emptied here to reach that state.
        $this->erase_from_moodle('RNCP36832');
        $before = $this->counts();

        $remover = new record_remover();
        $refusal = $remover->refusal('RNCP36832');
        $this->assertSame('errorremoverecordtraces', $refusal->errorcode);
        $this->assertSame(1, $refusal->a->declarations);
        $this->assertSame(0, $refusal->a->marks);
        $this->assertSame(0, $refusal->a->sections);
        $this->assert_refused(fn() => $remover->remove('RNCP36832'), 'errorremoverecordtraces');
        $this->assertSame($before, $this->counts());
        $this->assertSame([], $remover->removable(['RNCP36832']));
    }

    /**
     * A mark outside my activity blocks the removal though it never locked the framework.
     */
    public function test_a_mark_outside_my_activity_blocks_the_removal_though_it_never_locked_the_framework(): void {
        $candidate = $this->candidate();
        set_config('scopeopen', 1, 'tool_vae');
        $this->setUser($candidate);
        scope_manager::mark((int) $candidate->id, 'RNCP36832', 'C03', 'Mon poste ne passe pas par la.');
        // A mark on another record is nobody's business here.
        scope_manager::mark((int) $candidate->id, 'RNCP40692', 'C01');
        $this->setAdminUser();

        // No user competency is created by a mark: Moodle lets the framework go.
        $this->delete_in_moodle('RNCP36832');

        $remover = new record_remover();
        $refusal = $remover->refusal('RNCP36832');
        $this->assertSame('errorremoverecordtraces', $refusal->errorcode);
        $this->assertSame(0, $refusal->a->declarations);
        $this->assertSame(1, $refusal->a->marks);
        $this->assertSame(0, $refusal->a->sections);
        $this->assert_refused(fn() => $remover->remove('RNCP36832'), 'errorremoverecordtraces');
    }

    /**
     * A section of the preparation blocks the removal even when the declaration is gone.
     */
    public function test_a_section_of_the_preparation_blocks_the_removal_even_when_the_declaration_is_gone(): void {
        global $DB;

        $candidate = $this->candidate();
        set_config('validationopen', 1, 'tool_vae');
        // The preparation follows the records the candidate declared on: one declaration on each, as rows.
        $anchor = 5000;
        foreach (['RNCP36832', 'RNCP40692'] as $code) {
            $DB->insert_record('tool_vae_evidence', (object) [
                'uuid' => identity_map::generate_ulid(), 'userevidenceid' => ++$anchor,
                'candidateid' => (int) $candidate->id, 'periodstart' => 1756684800, 'periodend' => 1782777600,
                'settingtype' => 'ehpad', 'situationformat' => FORMAT_PLAIN, 'situation' => 'Une situation.',
                'fichecode' => $code, 'ficherang' => 'C01', 'ficheupdated' => time(),
                'timecreated' => time(), 'timemodified' => time(), 'usermodified' => (int) $candidate->id,
            ]);
        }
        $this->setUser($candidate);
        $manager = new draft_manager();
        $manager->section((int) $candidate->id, draft_manager::DOCTYPE_VALIDATION, 'bc01_decrire', 0, 'RNCP36832');
        $manager->section((int) $candidate->id, draft_manager::DOCTYPE_VALIDATION, '_decrire', 0, 'RNCP40692');
        $this->setAdminUser();

        // The candidate's declaration is deleted in Moodle (its purge leaves the sections of the preparation where they are).
        $DB->delete_records('tool_vae_evidence', ['fichecode' => 'RNCP36832']);
        $this->delete_in_moodle('RNCP36832');

        $remover = new record_remover();
        $refusal = $remover->refusal('RNCP36832');
        $this->assertSame('errorremoverecordtraces', $refusal->errorcode);
        $this->assertSame(0, $refusal->a->declarations);
        $this->assertSame(0, $refusal->a->marks);
        $this->assertSame(1, $refusal->a->sections);
        $this->assert_refused(fn() => $remover->remove('RNCP36832'), 'errorremoverecordtraces');
    }

    /**
     * A trace on another record blocks nothing.
     */
    public function test_a_trace_on_another_record_blocks_nothing(): void {
        $candidate = $this->candidate();
        set_config('scopeopen', 1, 'tool_vae');
        $this->setUser($candidate);
        scope_manager::mark((int) $candidate->id, 'RNCP40692', 'C01');
        $this->setAdminUser();
        $this->delete_in_moodle('RNCP36832');

        $this->assertNull((new record_remover())->refusal('RNCP36832'));
    }

    /**
     * The removal deletes what the plugin kept for this record and nothing else.
     */
    public function test_the_removal_deletes_what_the_plugin_kept_for_this_record_and_nothing_else(): void {
        global $DB;

        $this->delete_in_moodle('RNCP36832');
        $mistaken = $DB->get_record('tool_vae_source', ['fichecode' => 'RNCP36832'], '*', MUST_EXIST);
        $stays = $DB->get_record('tool_vae_source', ['fichecode' => 'RNCP40692'], '*', MUST_EXIST);
        $this->assertSame(2, $DB->count_records('tool_vae_ref', ['sourceid' => $mistaken->id]));
        $this->assertSame(2, $DB->count_records('tool_vae_referent', ['fichecode' => 'RNCP36832']));
        $this->assertSame(4, $DB->count_records('tool_vae_passage'));
        $before = $this->counts();

        (new record_remover())->remove('RNCP36832');

        // The provenance, with the licence, the attribution, the dates and the replacing record it held.
        $this->assertFalse($DB->record_exists('tool_vae_source', ['id' => $mistaken->id]));
        $this->assertSame($before['source'] - 1, $this->counts()['source']);
        // The addresses and the referents: two each, and the ones of the record that stays are still there.
        $this->assertSame(0, $DB->count_records('tool_vae_ref', ['sourceid' => $mistaken->id]));
        $this->assertSame(0, $DB->count_records('tool_vae_referent', ['fichecode' => 'RNCP36832']));
        $this->assertSame(1, $DB->count_records('tool_vae_ref', ['sourceid' => $stays->id]));
        $this->assertSame(1, $DB->count_records('tool_vae_referent', ['fichecode' => 'RNCP40692']));
        // The marks of departure: the one of the record that stays is still there.
        $this->assertSame(0, $DB->count_records('tool_vae_obsolete', ['sourceid' => $mistaken->id]));
        $this->assertSame(1, $DB->count_records('tool_vae_obsolete', ['sourceid' => $stays->id]));
        // The record that stays is exactly as it was.
        $this->assertEquals($stays, $DB->get_record('tool_vae_source', ['fichecode' => 'RNCP40692']));
    }

    /**
     * The correspondences of this record go and those of the others stay.
     */
    public function test_the_correspondences_of_this_record_go_and_those_of_the_others_stay(): void {
        global $DB;

        $this->delete_in_moodle('RNCP36832');
        (new record_remover())->remove('RNCP36832');

        // Two decided from the competencies of this record, one decided towards them from another record: three of four.
        $this->assertSame(1, $DB->count_records('tool_vae_passage'));
        $left = $DB->get_record('tool_vae_passage', [], '*', MUST_EXIST);
        // The one that stays is the old decision of RNCP41747 towards RNCP40692, which was its replacing record before.
        $this->assertSame($this->ids['RNCP41747-C03'], (int) $left->fromcompetencyid);
        $this->assertSame($this->ids['RNCP40692-BC03'], (int) $left->tocompetencyid);
    }

    /**
     * A correspondence with no competency left at one end goes with the record, whichever record held it.
     *
     * What is taken, and what is not, said as it is. RNCP41747 is a record that stays. One of its competencies (C03) is deleted by
     * itself in Moodle, which the core allows while nothing uses it: the decision it carried towards RNCP40692 is left with no
     * competency at one end. The table keeps two ids and not the framework of a competency that is gone, so nothing tells this
     * decision from the ones of RNCP36832: it is found by its other end, a competency of the record that replaces RNCP36832,
     * and it is taken and counted. A decision of the same record with both its ends still there is not taken.
     */
    public function test_a_correspondence_with_no_competency_left_at_one_end_goes_whichever_record_held_it(): void {
        global $DB;

        $orphan = $DB->get_record('tool_vae_passage', ['fromcompetencyid' => $this->ids['RNCP41747-C03']], '*', MUST_EXIST);
        $DB->delete_records('competency', ['id' => $this->ids['RNCP41747-C03']]);
        $bothends = (int) $DB->insert_record('tool_vae_passage', (object) [
            'fromcompetencyid' => $this->ids['RNCP41747-C04'], 'tocompetencyid' => $this->ids['RNCP40692-C01'],
            'decidedby' => (int) get_admin()->id, 'decidedat' => time(), 'note' => 'Les deux extremites existent.',
        ]);
        $this->delete_in_moodle('RNCP36832');
        $remover = new record_remover();

        // The three of the record (two decided from its competencies, one decided towards them) and the one of RNCP41747 that
        // has lost a competency: four, not three.
        $this->assertCount(4, $remover->inventory('RNCP36832')->passageids);
        $this->assertContains((int) $orphan->id, $remover->inventory('RNCP36832')->passageids);
        $this->assertNotContains($bothends, $remover->inventory('RNCP36832')->passageids);

        $remover->remove('RNCP36832');

        $this->assertFalse(
            $DB->record_exists('tool_vae_passage', ['id' => $orphan->id]),
            'The orphan is taken: nothing says whose it is.'
        );
        $this->assertTrue(
            $DB->record_exists('tool_vae_passage', ['id' => $bothends]),
            'A decision with both ends still there is not taken.'
        );
        $this->assertSame(1, $DB->count_records('tool_vae_passage'));
        // The record that stays is still there, with its framework.
        $this->assertTrue($DB->record_exists('tool_vae_source', ['fichecode' => 'RNCP41747']));
        $this->assertTrue((new competency_gateway())->framework_exists(
            (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP41747'], MUST_EXIST)
        ));
    }

    /**
     * The link of another record naming this one as its replacement is cleared.
     */
    public function test_the_link_of_another_record_naming_this_one_as_its_replacement_is_cleared(): void {
        global $DB;

        $this->delete_in_moodle('RNCP36832');
        $this->assertSame('RNCP36832', $DB->get_field('tool_vae_source', 'successorcode', ['fichecode' => 'RNCP41747']));
        $before = $DB->get_record('tool_vae_source', ['fichecode' => 'RNCP41747'], '*', MUST_EXIST);

        (new record_remover())->remove('RNCP36832');

        $after = $DB->get_record('tool_vae_source', ['fichecode' => 'RNCP41747'], '*', MUST_EXIST);
        $this->assertNull($after->successorcode, 'A record is still said to be replaced by a record that no longer exists.');
        // The rest of the row is its own.
        foreach (['frameworkid', 'licence', 'attribution', 'sourceupdated', 'lastimport'] as $field) {
            $this->assertEquals($before->$field, $after->$field, $field);
        }
        // And the record that stays has no link to clear.
        $this->assertNull($DB->get_field('tool_vae_source', 'successorcode', ['fichecode' => 'RNCP40692']));
    }

    /**
     * The marks of departure go with the row even when the observer did not run.
     */
    public function test_the_marks_of_departure_go_with_the_row_even_when_the_observer_did_not_run(): void {
        global $DB;

        // Erased from the tables with no event: the observer that deletes the marks with the framework did not run.
        $this->erase_from_moodle('RNCP36832');
        $mistaken = (int) $DB->get_field('tool_vae_source', 'id', ['fichecode' => 'RNCP36832'], MUST_EXIST);
        $this->assertSame(1, $DB->count_records('tool_vae_obsolete', ['sourceid' => $mistaken]), 'The premise.');

        (new record_remover())->remove('RNCP36832');

        $this->assertSame(0, $DB->count_records('tool_vae_obsolete', ['sourceid' => $mistaken]));
        $this->assertSame(1, $DB->count_records('tool_vae_obsolete'), 'The mark of the other record is untouched.');
        // The correspondences are found without the competencies of the record, which are gone from Moodle.
        $this->assertSame(1, $DB->count_records('tool_vae_passage'));
    }

    /**
     * The inventory lists what leaves with the row.
     */
    public function test_the_inventory_lists_what_leaves_with_the_row(): void {
        $this->erase_from_moodle('RNCP36832');

        $inventory = (new record_remover())->inventory('RNCP36832');

        $this->assertSame('RNCP36832', $inventory->fichecode);
        $this->assertSame('Licence Ouverte 2.0', $inventory->source->licence);
        $this->assertSame('France competences', $inventory->source->attribution);
        $this->assertSame(
            ['Fiche du repertoire', 'Arrete <b>du</b> diplome'],
            array_values(array_map(fn($ref) => $ref->title, $inventory->refs))
        );
        $this->assertSame(2, $inventory->referents);
        $this->assertSame(1, $inventory->marks);
        $this->assertCount(3, $inventory->passageids);
        $this->assertSame('RNCP40692', $inventory->successor);
        $this->assertSame(['RNCP41747'], $inventory->replaced);
        $this->assertNull((new record_remover())->inventory('RNCP00000'));
    }

    /**
     * Nothing is written in the tables of the competency subsystem.
     */
    public function test_nothing_is_written_in_the_tables_of_the_competency_subsystem(): void {
        global $DB;

        $this->delete_in_moodle('RNCP36832');
        $tables = ['competency', 'competency_framework', 'competency_usercomp', 'competency_userevidence',
            'competency_userevidencecomp', 'competency_relatedcomp', 'competency_coursecomp'];
        $snapshot = function () use ($DB, $tables): array {
            $out = [];
            foreach ($tables as $table) {
                $out[$table] = md5(json_encode(array_values($DB->get_records($table, [], 'id ASC'))));
            }

            return $out;
        };
        $before = $snapshot();

        (new record_remover())->remove('RNCP36832');

        $this->assertSame($before, $snapshot());
    }

    /**
     * Only the right to import is asked and it is enough.
     */
    public function test_only_the_right_to_import_is_asked_and_it_is_enough(): void {
        global $DB;

        $this->delete_in_moodle('RNCP36832');
        $before = $this->counts();

        // A manager who follows candidates and holds nothing else.
        $nobody = $this->getDataGenerator()->create_user();
        $this->setUser($nobody);
        try {
            (new record_remover())->remove('RNCP36832');
            $this->fail('A person without the right to import removed a row.');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(get_capability_string('tool/vae:import'), $e->getMessage());
        }
        $this->assertSame($before, $this->counts());

        // The right to import, given by hand to a role: the same right as the import page asks.
        $generator = $this->getDataGenerator();
        $importer = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('tool/vae:import', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $importer->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($importer);

        (new record_remover())->remove('RNCP36832');
        $this->assertFalse($DB->record_exists('tool_vae_source', ['fichecode' => 'RNCP36832']));
    }

    /**
     * The log names the record and the counts and the links that were cleared.
     */
    public function test_the_log_names_the_record_and_the_counts_and_the_links_that_were_cleared(): void {
        $this->erase_from_moodle('RNCP36832');
        $sink = $this->redirectEvents();

        (new record_remover())->remove('RNCP36832');

        $events = $sink->get_events();
        $sink->close();
        $removed = array_values(array_filter($events, fn($event) => $event instanceof record_removed));
        $links = array_values(array_filter($events, fn($event) => $event instanceof supersession_changed));

        $this->assertCount(1, $removed);
        $this->assertSame('d', $removed[0]->crud);
        $this->assertSame('tool_vae_source', $removed[0]->objecttable);
        $this->assertEquals([
            'fichecode' => 'RNCP36832', 'refs' => 2, 'referents' => 2, 'marks' => 1, 'passages' => 3,
            'successor' => 'RNCP40692', 'links' => 1,
        ], $removed[0]->other);
        $this->assertStringContainsString('RNCP36832', $removed[0]->get_description());
        $this->assertSame(get_string('eventrecordremoved', 'tool_vae'), record_removed::get_name());

        // The record that was declared to be replaced by the removed one: its link is cleared, and the log of the
        // replacements says so, as when an administrator clears it by hand.
        $this->assertCount(1, $links);
        $this->assertEquals(['fichecode' => 'RNCP41747', 'successor' => ''], $links[0]->other);
    }

    /**
     * A row that is not there is refused and a second removal finds nothing.
     */
    public function test_a_row_that_is_not_there_is_refused_and_a_second_removal_finds_nothing(): void {
        $remover = new record_remover();
        $this->assert_refused(fn() => $remover->remove('RNCP00000'), 'errorrefunknownfiche');

        $this->erase_from_moodle('RNCP36832');
        $remover->remove('RNCP36832');
        $this->assert_refused(fn() => $remover->remove('RNCP36832'), 'errorrefunknownfiche');
        $this->assertSame('errorrefunknownfiche', $remover->refusal('RNCP36832')->errorcode);
    }

    /**
     * The confirmation screen lists what leaves and escapes it.
     */
    public function test_the_confirmation_screen_lists_what_leaves_and_escapes_it(): void {
        $this->erase_from_moodle('RNCP36832');
        $inventory = (new record_remover())->inventory('RNCP36832');

        $html = (new remove_record_form(null, ['inventory' => $inventory]))->render();

        // A form of the library: the key of the session is in it, and the record travels in a hidden field.
        $this->assertStringContainsString('name="sesskey"', $html);
        $hidden = '/name="fichecode"[^>]*value="RNCP36832"|value="RNCP36832"[^>]*name="fichecode"/';
        $this->assertMatchesRegularExpression($hidden, $html);
        $this->assertStringContainsString(get_string('removerecordbutton', 'tool_vae'), $html);
        // Licence, attribution and dates.
        $this->assertStringContainsString('Licence Ouverte 2.0', $html);
        $this->assertStringContainsString('France competences', $html);
        // The addresses, one by one, as text: what an administrator typed is never markup. The title and the address of each.
        $this->assertStringContainsString('Fiche du repertoire (https://example.org/rncp/36832/)', $html);
        $this->assertStringContainsString(s('Arrete <b>du</b> diplome') . ' (https://example.org/arrete/36832)', $html);
        $this->assertStringNotContainsString('<b>du</b>', $html);
        // The replacing record, and the record whose link is cleared.
        $this->assertStringContainsString(get_string('removerecordsuccessor', 'tool_vae', 'RNCP40692'), $html);
        $this->assertStringContainsString(get_string('removerecordreplaced', 'tool_vae', 'RNCP41747'), $html);
        // The counts: two referents, one mark, three correspondences.
        $this->assertStringContainsString(get_string('removerecordreferents', 'tool_vae', 2), $html);
        $this->assertStringContainsString(get_string('removerecordmarks', 'tool_vae', 1), $html);
        $this->assertStringContainsString(get_string('removerecordpassages', 'tool_vae', 3), $html);
    }

    /**
     * The confirmation screen writes every value it prints as text: the licence, the attribution, the addresses, the codes.
     *
     * The title of an address was the only value tried with markup in it. The others come from forms and files that mostly
     * strip it, but a value written by the command line, restored from a backup or typed in a field the form lets through is in
     * the table all the same, and the screen is the last thing between it and the manager's browser. The address is the one that
     * passes the validation of the plugin with markup in it: it has no space. Written straight into the tables here, as a form
     * would not.
     */
    public function test_the_confirmation_screen_writes_every_value_it_prints_as_text(): void {
        global $DB;

        $this->erase_from_moodle('RNCP36832');
        $sourceid = (int) $DB->get_field('tool_vae_source', 'id', ['fichecode' => 'RNCP36832'], MUST_EXIST);
        $hostile = [
            'licence' => 'Licence <u>hostilelicence</u> & co',
            'attribution' => 'France <b>hostileattribution</b>',
            'address' => 'https://example.org/"onmouseover="x"><i>hostileurl</i>',
            'successor' => '<i>hostilesuccessor</i>',
            'replaced' => '<em>hostilereplaced</em>',
        ];
        $DB->set_field('tool_vae_source', 'licence', $hostile['licence'], ['id' => $sourceid]);
        $DB->set_field('tool_vae_source', 'attribution', $hostile['attribution'], ['id' => $sourceid]);
        $DB->set_field('tool_vae_source', 'successorcode', $hostile['successor'], ['id' => $sourceid]);
        $DB->set_field('tool_vae_ref', 'url', $hostile['address'], ['sourceid' => $sourceid, 'title' => 'Fiche du repertoire']);
        // A record that names this one as its replacing record, under a code that is markup.
        $now = time();
        $DB->insert_record('tool_vae_source', (object) [
            'frameworkid' => 0, 'fichecode' => $hostile['replaced'], 'fichetype' => 'rncp',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => 0, 'successorcode' => 'RNCP36832',
            'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'France competences', 'lastimport' => $now, 'lastimportstatus' => 'error',
            'timecreated' => $now, 'timemodified' => $now, 'usermodified' => (int) get_admin()->id,
        ]);

        $inventory = (new record_remover())->inventory('RNCP36832');
        $html = (new remove_record_form(null, ['inventory' => $inventory]))->render();

        // Sent as text: the escaped form is on the screen, whatever else is.
        foreach ($hostile as $field => $value) {
            $this->assertStringContainsString(s($value), $html, "The $field is not written as text.");
        }
        // And no tag of theirs, nor the quote that would close an attribute, is.
        $markups = ['<u>hostilelicence</u>', '<b>hostileattribution</b>', '<i>hostileurl</i>', '<i>hostilesuccessor</i>',
            '<em>hostilereplaced</em>', '"onmouseover="'];
        foreach ($markups as $markup) {
            $this->assertStringNotContainsString($markup, $html, "$markup is on the screen as markup.");
        }
    }
}
