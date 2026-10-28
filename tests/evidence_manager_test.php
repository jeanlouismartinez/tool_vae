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

use tool_vae\fixtures\failing_gateway;
use tool_vae\local\competency_gateway;
use tool_vae\local\evidence_manager;

/**
 * Acceptance criteria 1, 2 and 3, plus atomicity.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\evidence_manager::class)]
final class evidence_manager_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var int The competency being claimed. */
    private $competencyid;

    /** @var int Publication date of the certification record. */
    private $sourceupdated;

    /**
     * A candidate who may declare, and one imported certification record to declare against.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/formslib.php');
        require_once(__DIR__ . '/fixtures/failing_gateway.php');

        $generator = $this->getDataGenerator();
        $lpg = $generator->get_plugin_generator(competency_gateway::SUBSYSTEM);

        $framework = $lpg->create_framework(['shortname' => 'RNCP36832']);
        $competency = $lpg->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => 'RNCP36832-C03',
            'shortname' => 'Repérer les facteurs de risque de chute',
        ]);
        $this->competencyid = (int) $competency->get('id');

        $this->sourceupdated = 1781136000;
        $this->insert_source((int) $framework->get('id'), $this->sourceupdated);

        $this->candidate = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability(
            'tool/vae:declare',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id,
            true
        );
        role_assign($roleid, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->candidate);
    }

    /**
     * Record the provenance of the imported framework.
     *
     * @param int $frameworkid
     * @param int $sourceupdated
     */
    private function insert_source(int $frameworkid, int $sourceupdated): void {
        global $DB, $USER;

        $DB->insert_record('tool_vae_source', (object) [
            'frameworkid' => $frameworkid,
            'fichecode' => 'RNCP36832',
            'fichetype' => 'rncp',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => $sourceupdated,
            'licence' => 'LO-2.0',
            'attribution' => 'France compétences',
            'lastimport' => time(),
            'lastimportstatus' => 'ok',
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => (int) ($USER->id ?: 2),
        ]);
    }

    /**
     * A well formed submission.
     *
     * @param array $overrides
     * @return \stdClass
     */
    private function submission(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'competencyid' => $this->competencyid,
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'settingdetail' => 'Unité protégée, équipe de nuit',
            'situation' => "Accompagnement au lever et à la toilette de personnes en perte d'autonomie, "
                . 'en binôme avec une aide-soignante.',
            'situationformat' => FORMAT_PLAIN,
        ]);
    }

    // ----------------------------------------------------------------------
    // Criterion 1.

    /**
     * Criterion 1 - the declaration lands where it should, and nowhere else.
     */
    public function test_a_declaration_creates_core_evidence_linked_to_the_competency(): void {
        global $DB;

        $record = (new evidence_manager())->declare_activity((int) $this->candidate->id, $this->submission());

        $evidence = $DB->get_record('competency_userevidence', ['id' => $record->userevidenceid], '*', MUST_EXIST);
        $this->assertEquals($this->candidate->id, $evidence->userid);

        $this->assertTrue($DB->record_exists('competency_userevidencecomp', [
            'userevidenceid' => $record->userevidenceid,
            'competencyid' => $this->competencyid,
        ]));

        $this->assertEquals($this->candidate->id, $record->candidateid);
        $this->assertSame('RNCP36832', $record->fichecode);
        $this->assertSame('C03', $record->ficherang);
        $this->assertEquals($this->sourceupdated, $record->ficheupdated);
        $this->assertTrue(\tool_vae\local\identity_map::is_valid_ulid($record->uuid));
    }

    /**
     * Criterion 1, correction C1 - "not marked proficient" is about the grade, not the row.
     *
     * create_user_evidence_competency() calls add_evidence() with ACTION_LOG, and
     * add_evidence() creates the user competency row BEFORE looking at the action. So a row
     * does appear, at the not-rated state. A test asserting that no row exists would fail on
     * correct code - which is why the acceptance criterion had to be reworded.
     */
    public function test_the_candidate_is_never_marked_proficient(): void {
        global $DB;

        (new evidence_manager())->declare_activity((int) $this->candidate->id, $this->submission());

        $usercompetency = $DB->get_record('competency_usercomp', [
            'userid' => $this->candidate->id,
            'competencyid' => $this->competencyid,
        ]);

        $this->assertNotFalse($usercompetency, 'Core creates this row; the criterion allows it.');
        $this->assertNull($usercompetency->grade, 'No grade may be set.');
        $this->assertNull($usercompetency->proficiency, 'The candidate must never be marked proficient.');
    }

    /**
     * Correction C4 - the narrative never reaches the core record.
     *
     * If it did, it would be readable through tool_lp and through a core web service under
     * moodle/competency:userevidenceview, whose default archetype is manager.
     */
    public function test_the_narrative_never_reaches_the_core_record(): void {
        global $DB;

        $submission = $this->submission();
        $record = (new evidence_manager())->declare_activity((int) $this->candidate->id, $submission);

        $evidence = $DB->get_record('competency_userevidence', ['id' => $record->userevidenceid]);

        $this->assertSame('', $evidence->description);
        $this->assertSame('', $evidence->url);
        $this->assertStringNotContainsString('toilette', $evidence->name);
        $this->assertStringContainsString('RNCP36832-C03', $evidence->name);
        $this->assertSame($submission->situation, $record->situation, 'It lives in the plugin table.');
    }

    /**
     * Core copies `name` into competency_evidence.desca. Nothing a user typed may end there.
     */
    public function test_nothing_typed_by_a_user_reaches_the_core_evidence_log(): void {
        global $DB;

        $submission = $this->submission(['settingdetail' => 'MOTTEMOIN']);
        (new evidence_manager())->declare_activity((int) $this->candidate->id, $submission);

        foreach ($DB->get_records('competency_evidence') as $row) {
            $this->assertStringNotContainsString('MOTTEMOIN', (string) $row->desca);
            $this->assertStringNotContainsString('toilette', (string) $row->desca);
        }
    }

    // ----------------------------------------------------------------------
    // Criterion 2.

    /**
     * Criterion 2 - refused before any write, and the attempt is logged.
     */
    public function test_without_the_capability_nothing_is_written_and_the_attempt_is_logged(): void {
        global $DB;

        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        $before = $DB->count_records('competency_userevidence');
        $sink = $this->redirectEvents();

        try {
            (new evidence_manager())->declare_activity((int) $stranger->id, $this->submission());
            $this->fail('A user without tool/vae:declare must be refused.');
        } catch (\required_capability_exception $e) {
            // The message carries the capability's human readable name, not its identifier.
            $this->assertSame('nopermissions', $e->errorcode);
        }

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(\tool_vae\event\declaration_denied::class, $events[0]);
        $this->assertEquals($stranger->id, $events[0]->relateduserid);

        $this->assertEquals($before, $DB->count_records('competency_userevidence'));
        $this->assertEquals(0, $DB->count_records('tool_vae_evidence'));
    }

    /**
     * A declaration is first person. Declaring for someone else is refused outright.
     */
    public function test_a_declaration_cannot_be_recorded_for_someone_else(): void {
        global $DB;

        $other = $this->getDataGenerator()->create_user();

        $this->expectException(\moodle_exception::class);

        try {
            (new evidence_manager())->declare_activity((int) $other->id, $this->submission());
        } finally {
            $this->assertEquals(0, $DB->count_records('tool_vae_evidence'));
        }
    }

    // ----------------------------------------------------------------------
    // Criterion 3.

    /**
     * Criterion 3 - a reversed period is refused before anything is written.
     */
    public function test_a_reversed_period_is_refused_before_any_write(): void {
        global $DB;

        $submission = $this->submission(['periodstart' => 1782172800, 'periodend' => 1756684800]);

        try {
            (new evidence_manager())->declare_activity((int) $this->candidate->id, $submission);
            $this->fail('A period ending before it starts must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorperiodreversed', $e->errorcode);
        }

        $this->assertEquals(0, $DB->count_records('competency_userevidence'));
        $this->assertEquals(0, $DB->count_records('tool_vae_evidence'));
    }

    /**
     * Criterion 3, in the form - the field is named and the values survive.
     *
     * The manager refuses server side; the form is what makes the refusal usable. The error
     * is keyed by field, which is what attaches it to its input and lets it be announced by
     * a screen reader - never colour alone.
     */
    public function test_the_form_names_the_offending_field(): void {
        $form = new \tool_vae\form\declare_form(null, ['competencies' => []]);

        $errors = $form->validation([
            'periodstart' => 1782172800,
            'periodend' => 1756684800,
            'situation' => 'Quelque chose.',
            'situationformat' => FORMAT_PLAIN,
        ], []);

        $this->assertArrayHasKey('periodend', $errors);
        $this->assertNotEmpty($errors['periodend']);
        $this->assertArrayNotHasKey('situation', $errors);
    }

    /**
     * The form offers no choice of format: a candidate writes plain text, nothing else.
     *
     * Neither a field nor a validation error: a posted format is ignored, and declare.php
     * sets plain text itself. HTML stays refused by the manager whatever reaches it.
     */
    public function test_the_form_offers_no_choice_of_format(): void {
        $form = new \tool_vae\form\declare_form(null, ['competencies' => []]);

        $this->assertStringNotContainsString('situationformat', $form->render());

        $errors = $form->validation([
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'situation' => 'Quelque chose.',
            'situationformat' => FORMAT_HTML,
        ], []);
        $this->assertArrayNotHasKey('situationformat', $errors);
    }

    /**
     * T-54: the anonymisation reminder is said once in full, in view, and briefly by each free field.
     *
     * Found on a real Moodle 5.3 on 13 October 2026: the long text was repeated against every free
     * field, so a candidate read it twice before writing a word and learnt to skip it. It stays
     * non-maskable and attached to each field - that is what regime 2 rests on - but only the
     * short one is repeated, and each field points at its own and at the full one.
     */
    public function test_the_anonymisation_reminder_is_said_once_in_full_and_briefly_by_each_free_field(): void {
        $html = (new \tool_vae\form\declare_form(null, ['competencies' => []]))->render();

        $this->assertSame(1, substr_count($html, get_string('anonymisereminder', 'tool_vae')));
        $this->assertSame(2, substr_count($html, get_string('anonymiseshort', 'tool_vae')));

        foreach (['settingdetail', 'situation'] as $field) {
            $this->assertMatchesRegularExpression(
                '~aria-describedby="[^"]*\btool_vae_anonymise_' . $field . '\b[^"]*\btool_vae_anonymise\b[^"]*"~',
                $html,
                "The field $field points at its own reminder and at the full one."
            );
        }

        // The pointers have targets, each present once, and each reminder is read before what it
        // is about: the short ones before their field, the full one before the first field.
        $position = function (string $needle) use ($html): int {
            $at = strpos($html, $needle);
            $this->assertNotFalse($at, "$needle is in the form.");
            return (int) $at;
        };
        $this->assertSame(1, substr_count($html, 'id="tool_vae_anonymise"'));
        $this->assertLessThan($position('id="id_competencyid"'), $position('id="tool_vae_anonymise"'));
        foreach (['settingdetail', 'situation'] as $field) {
            $target = 'id="tool_vae_anonymise_' . $field . '"';
            $this->assertSame(1, substr_count($html, $target), "The short reminder of $field is present once.");
            $this->assertLessThan($position('id="id_' . $field . '"'), $position($target));
        }
    }

    /**
     * T-73: the attestation statement, the third free field, points at its reminder.
     *
     * The reminder of the declaration form was tested (T-54); the one of the attestation form was
     * not. The statement is written by the manager, and free text is where a name gets written.
     */
    public function test_the_attestation_statement_points_at_its_reminder(): void {
        $html = (new \tool_vae\form\attest_form(null))->render();

        $this->assertSame(1, substr_count($html, 'id="tool_vae_anonymise_statement"'));
        $this->assertStringContainsString(get_string('anonymisereminder', 'tool_vae'), $html);
        $this->assertMatchesRegularExpression(
            '~<textarea[^>]*aria-describedby="[^"]*\btool_vae_anonymise_statement\b[^"]*"~',
            $html
        );
        $this->assertLessThan(
            (int) strpos($html, 'id="id_statement"'),
            (int) strpos($html, 'id="tool_vae_anonymise_statement"'),
            'The reminder is read before the field.'
        );
    }

    /**
     * T-72: a field in error keeps its reminder, because the script merges ids rather than replacing.
     *
     * Found on a real Moodle 5.3 on 14 October 2026: the core template wrote the error id first and
     * the browser kept only that one, so the reminder was no longer read with the field. The merge
     * itself runs in the browser; what is checked here is that the script names each field and each
     * reminder, merges (the existing attribute is read, not overwritten) and never lists an id the
     * page does not have.
     */
    public function test_the_reminder_script_merges_the_reminder_into_the_attribute_of_a_field(): void {
        $script = \tool_vae\form\reminder_pointers::script([
            'id_situation' => ['tool_vae_anonymise_situation', 'tool_vae_anonymise'],
        ]);

        $this->assertStringContainsString('"id_situation"', $script);
        $this->assertStringContainsString('"tool_vae_anonymise_situation"', $script);
        $this->assertStringContainsString('"tool_vae_anonymise"', $script);
        $this->assertStringContainsString('getAttribute("aria-describedby")', $script);
        $this->assertStringContainsString('ids.indexOf(t)<0', $script, 'An id already there is not added twice.');
        $this->assertStringContainsString('document.getElementById(t)', $script, 'An id with no target is skipped.');
    }

    /**
     * T-55: the period selectors do not offer 1900 to 2050.
     *
     * Found on a real Moodle 5.3 on 13 October 2026. A working life is a few decades and nothing is
     * practised in the future, so the year list stops at the current year and reaches back fifty.
     */
    public function test_the_period_selectors_offer_a_working_life_not_a_century_and_a_half(): void {
        $html = (new \tool_vae\form\declare_form(null, ['competencies' => []]))->render();
        $thisyear = (int) userdate(time(), '%Y');

        $this->assertStringContainsString('<option value="' . $thisyear . '"', $html);
        $this->assertStringContainsString(
            '<option value="' . ($thisyear - \tool_vae\form\declare_form::PERIOD_SPAN_YEARS) . '"',
            $html
        );
        $this->assertStringNotContainsString('<option value="1900"', $html);
        $this->assertStringNotContainsString('<option value="' . ($thisyear + 1) . '"', $html);
    }

    /**
     * The declaration shown to a reader carries the name of the competency, not its code alone.
     *
     * Found on a real Moodle 5.3 on 13 October 2026: the page gave the code, the PDF gave the name.
     */
    public function test_the_declaration_is_shown_with_the_name_of_its_competency(): void {
        global $DB;

        $record = (new evidence_manager())->declare_activity((int) $this->candidate->id, $this->submission());
        $row = $DB->get_record('tool_vae_evidence', ['id' => $record->id], '*', MUST_EXIST);

        $html = \tool_vae\local\presenter::declaration($row, \context_user::instance((int) $this->candidate->id));

        $this->assertStringContainsString('Repérer les facteurs de risque de chute', $html);
        $this->assertStringContainsString('RNCP36832-C03', $html, 'The code stays: it is what an archive names.');
    }

    /**
     * HTML is refused. The narrative is rendered in a manager's session.
     */
    public function test_an_html_narrative_is_refused(): void {
        $submission = $this->submission([
            'situation' => '<img src=x onerror=alert(1)>',
            'situationformat' => FORMAT_HTML,
        ]);

        $this->expectException(\moodle_exception::class);
        (new evidence_manager())->declare_activity((int) $this->candidate->id, $submission);
    }

    /**
     * A claim must point at an imported certification record, or it cannot say which
     * version of the framework it refers to.
     */
    public function test_a_competency_outside_an_imported_record_is_refused(): void {
        $lpg = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $other = $lpg->create_framework(['shortname' => 'Cadre local']);
        $competency = $lpg->create_competency([
            'competencyframeworkid' => $other->get('id'),
            'idnumber' => 'LOCAL-01',
        ]);

        try {
            (new evidence_manager())->declare_activity(
                (int) $this->candidate->id,
                $this->submission(['competencyid' => (int) $competency->get('id')])
            );
            $this->fail('A competency outside an imported record must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorframeworknotimported', $e->errorcode);
        }
    }

    // ----------------------------------------------------------------------
    // Atomicity.

    /**
     * A failure after the core write must leave nothing behind.
     *
     * This is the defect the adversarial review raised: core creates the evidence record,
     * saves its files and fires its event before the plugin writes a single row. Without a
     * transaction, a failure on the plugin write leaves an evidence record with no metadata,
     * no period and no attestation possible - invisible here and orphaned in tool_lp.
     *
     * preventResetByRollback() IS WHAT MAKES THIS TEST MEAN ANYTHING.
     * advanced_testcase::setUp() opens a delegated transaction around every test, so the
     * plugin's transaction is nested inside it - and moodle_database only rolls back the
     * outermost level: "if (count($this->transactions) == 1)". A nested rollback merely sets
     * force_rollback and defers the undo to the end of the test. The rows therefore stay
     * visible, and this test failed while the code was correct.
     *
     * Closing the harness transaction first makes the plugin's the top level, so the
     * rollback actually happens where the assertions can see it. Without this call the test
     * reports a defect that is not there - and, worse, a version with no transaction at all
     * would report the same thing, so the test would be useless in both directions.
     */
    public function test_a_failure_after_the_core_write_leaves_nothing_behind(): void {
        global $DB;

        $this->preventResetByRollback();

        $manager = new evidence_manager(new failing_gateway());

        try {
            $manager->declare_activity((int) $this->candidate->id, $this->submission());
            $this->fail('The substituted gateway must fail.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Deliberate failure', $e->getMessage());
        }

        $this->assertEquals(
            0,
            $DB->count_records('competency_userevidence'),
            'The core record survived a rolled back declaration.'
        );
        $this->assertEquals(0, $DB->count_records('tool_vae_evidence'));
        $this->assertEquals(0, $DB->count_records('competency_userevidencecomp'));
    }

    /**
     * The subsystem being off is reported, never left to core to throw about.
     */
    public function test_a_declaration_is_refused_when_the_subsystem_is_off(): void {
        set_config('enabled', 0, competency_gateway::SUBSYSTEM);

        try {
            (new evidence_manager())->declare_activity((int) $this->candidate->id, $this->submission());
            $this->fail('A declaration must be refused when competencies are off.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorcompetenciesdisabled', $e->errorcode);
        }
    }

    /**
     * The format check must survive Moodle declaring its constants as strings.
     *
     * FORMAT_PLAIN is the string '2'. A strict comparison against an integer is always
     * false, which silently rejected every declaration until this was run. This test exists
     * so the trap cannot come back unnoticed.
     */
    public function test_the_format_check_survives_moodle_string_constants(): void {
        $this->assertTrue(evidence_manager::is_allowed_format(FORMAT_PLAIN));
        $this->assertTrue(evidence_manager::is_allowed_format((int) FORMAT_PLAIN));
        $this->assertTrue(evidence_manager::is_allowed_format('2'));
        $this->assertTrue(evidence_manager::is_allowed_format(FORMAT_MARKDOWN));
        $this->assertFalse(evidence_manager::is_allowed_format(FORMAT_HTML));
        $this->assertFalse(evidence_manager::is_allowed_format(FORMAT_MOODLE));
    }

    /**
     * The event carries identifiers and nothing else - anti-pattern 16.
     */
    public function test_the_event_carries_no_free_text(): void {
        $sink = $this->redirectEvents();
        (new evidence_manager())->declare_activity((int) $this->candidate->id, $this->submission());
        $events = $sink->get_events();
        $sink->close();

        $declared = null;
        foreach ($events as $event) {
            if ($event instanceof \tool_vae\event\evidence_declared) {
                $declared = $event;
            }
        }

        $this->assertNotNull($declared, 'The declaration must be logged.');
        foreach ($declared->other as $key => $value) {
            $this->assertIsInt($value, "The '$key' entry is not an identifier.");
        }
        $this->assertStringNotContainsString('toilette', $declared->get_description());
    }

    /**
     * A rank wider than the column is refused by name, not by the database.
     *
     * `RNCP36832-BC01C0299` is an ordinary shape for a block of competencies, and the rank it
     * yields is nine characters against a column of eight. Before this check, it reached the
     * driver and came back to the candidate as
     * `dml_write_exception: value too long for type character varying(8)` - measured. Criterion
     * 3 requires an error to name the field it is about; a driver names a column type.
     *
     * Neither value is typed by anyone: they are split off the idnumber of a framework someone
     * imported, so no form validation can catch this. It has to be caught here.
     */
    public function test_a_rank_wider_than_the_column_is_refused_by_name(): void {
        global $DB;

        $competencyid = $this->competency_with_idnumber('RNCP36832-BC01C0299');

        try {
            (new evidence_manager())->declare_activity(
                (int) $this->candidate->id,
                $this->submission(['competencyid' => $competencyid])
            );
            $this->fail('A rank too wide for the column was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorficheranktoolong', $e->errorcode);
            $this->assertStringContainsString('BC01C0299', $e->getMessage());
        }

        $this->assertEquals(0, $DB->count_records('tool_vae_evidence'));
        $this->assertEquals(
            0,
            $DB->count_records('competency_userevidence'),
            'The refusal came after the core write; the transaction did not hold.'
        );
    }

    /**
     * And a code wider than its own column, likewise.
     */
    public function test_a_code_wider_than_the_column_is_refused_by_name(): void {
        $competencyid = $this->competency_with_idnumber(str_repeat('RNCP36832', 4) . '-C03');

        try {
            (new evidence_manager())->declare_activity(
                (int) $this->candidate->id,
                $this->submission(['competencyid' => $competencyid])
            );
            $this->fail('A code too wide for the column was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfichecodetoolong', $e->errorcode);
        }
    }

    /**
     * A rank of exactly the width of the column is accepted.
     *
     * The other side of the boundary. Without it, a check written with >= instead of > would
     * refuse a perfectly valid rank and pass the two tests above.
     */
    public function test_a_rank_exactly_as_wide_as_the_column_is_accepted(): void {
        $competencyid = $this->competency_with_idnumber('RNCP36832-BC01C029');

        $record = (new evidence_manager())->declare_activity(
            (int) $this->candidate->id,
            $this->submission(['competencyid' => $competencyid])
        );

        $this->assertSame('BC01C029', $record->ficherang);
    }

    /**
     * A rank that fits the column but not the schema is refused by name, not accepted into an archive nobody can reimport.
     *
     * The three ranks are within eight characters, which is all the check of the width asks, and tool_lp accepts each of them
     * in an imported framework (the idnumber is PARAM_RAW there, only its uniqueness is checked). The claim was recorded, then
     * exported and registered, and the archive was refused whole on reimport: `/fiche/rang does not match ^[A-Z0-9]{1,8}$`,
     * the valid declarations beside it with it, the uninstall backup likewise.
     */
    public function test_a_rank_outside_the_schema_pattern_is_refused_by_name(): void {
        global $DB;

        foreach (['RNCP36832-c7' => 'c7', 'RNCP36832-C7.1' => 'C7.1', 'RNCP36832-BC 1' => 'BC 1'] as $idnumber => $rank) {
            $competencyid = $this->competency_with_idnumber($idnumber);

            try {
                (new evidence_manager())->declare_activity(
                    (int) $this->candidate->id,
                    $this->submission(['competencyid' => $competencyid])
                );
                $this->fail("The rank \"$rank\" is outside the schema pattern and was accepted.");
            } catch (\moodle_exception $e) {
                $this->assertSame('errorficherankinvalid', $e->errorcode, $idnumber);
                $this->assertStringContainsString($rank, $e->getMessage());
            }
        }

        $this->assertEquals(0, $DB->count_records('tool_vae_evidence'));
        $this->assertEquals(
            0,
            $DB->count_records('competency_userevidence'),
            'The refusal came after the core write; the transaction did not hold.'
        );
    }

    /**
     * The rank of the refusal is shown on the page as HTML, and by construction it is a value the pattern refuses.
     */
    public function test_the_rank_of_the_refusal_is_escaped(): void {
        $competencyid = $this->competency_with_idnumber('RNCP36832-<b>x');

        try {
            (new evidence_manager())->declare_activity(
                (int) $this->candidate->id,
                $this->submission(['competencyid' => $competencyid])
            );
            $this->fail('A rank holding markup was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorficherankinvalid', $e->errorcode);
            $this->assertStringNotContainsString('<b>', $e->getMessage());
            $this->assertStringContainsString('&lt;b&gt;x', $e->getMessage());
        }
    }

    /**
     * The two refusals of the width escape what they quote as the refusals after them do.
     *
     * Both values are fragments of an idnumber typed by hand into tool_lp, and the message is shown as HTML. A rank of eight
     * characters or fewer reached the escaping refusal; one of nine or more, such as `<img src=x>`, was refused first by the
     * width, which passed it raw: only the shortest ranks were protected, and the test above proved it for those alone.
     */
    public function test_the_refusals_of_the_width_escape_what_they_quote(): void {
        $cases = [
            ['RNCP36832-<img src=x>', 'errorficheranktoolong', '<img src=x>', '&lt;img src=x&gt;'],
            [str_repeat('<b>', 12) . '-C03', 'errorfichecodetoolong', '<b><b>', '&lt;b&gt;&lt;b&gt;'],
        ];
        foreach ($cases as [$idnumber, $errorcode, $raw, $escaped]) {
            $competencyid = $this->competency_with_idnumber($idnumber);

            try {
                (new evidence_manager())->declare_activity(
                    (int) $this->candidate->id,
                    $this->submission(['competencyid' => $competencyid])
                );
                $this->fail("$errorcode: a value too wide was accepted.");
            } catch (\moodle_exception $e) {
                $this->assertSame($errorcode, $e->errorcode);
                $this->assertStringNotContainsString($raw, $e->getMessage());
                $this->assertStringContainsString($escaped, $e->getMessage());
            }
        }
    }

    /**
     * A framework hidden in tool_lp is not declared on, before anything is written, and what was declared stays.
     *
     * "Visible: No" is the gesture the core's help recommends while a framework is set up or updated to a new version. The
     * core keeps a hidden framework out of its picker and refuses to attach it to a course, but its API does not ask for the
     * link of a piece of evidence: read_competency() and create_user_evidence_competency() ignore `visible`. Before: the
     * declaration was recorded, and "hide" had a different effect on every screen of the plugin.
     */
    public function test_a_declaration_on_a_hidden_framework_is_refused_before_any_write(): void {
        global $DB;

        $manager = new evidence_manager();
        $earlier = $manager->declare_activity((int) $this->candidate->id, $this->submission());

        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', [], MUST_EXIST);
        // The gesture of the administrator in tool_lp, by the table so that no test names the core.
        $DB->set_field('competency_framework', 'visible', 0, ['id' => $frameworkid]);

        try {
            $manager->declare_activity((int) $this->candidate->id, $this->submission());
            $this->fail('A declaration on a hidden framework was recorded.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorframeworkhidden', $e->errorcode);
            $this->assertStringContainsString('RNCP36832', $e->getMessage());
        }

        $this->assertEquals(1, $DB->count_records('tool_vae_evidence'), 'Only the earlier declaration is there.');
        $this->assertEquals(
            1,
            $DB->count_records('competency_userevidence'),
            'The refusal came after the core write; nothing should have been written.'
        );
        $this->assertTrue($DB->record_exists('tool_vae_evidence', ['id' => $earlier->id]), 'What was declared stays.');

        // Visible again: declared as before.
        $DB->set_field('competency_framework', 'visible', 1, ['id' => $frameworkid]);
        $record = $manager->declare_activity((int) $this->candidate->id, $this->submission());
        $this->assertSame('C03', $record->ficherang);
        $this->assertEquals(2, $DB->count_records('tool_vae_evidence'));
    }

    /**
     * The refusal on a hidden framework is written for the vaeiste, who can do nothing in the administration screens.
     *
     * Decision of the project owner, 27 October 2026: the refusal stays, and says clearly what it is. Before: it named the
     * record by its code alone and sent the reader to "Site administration, Competencies, Competency frameworks", a place a
     * vaeiste does not go and cannot act in. The title carries an apostrophe, the common case of a French certification.
     */
    public function test_the_refusal_on_a_hidden_framework_is_written_for_the_vaeiste(): void {
        global $DB;

        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', [], MUST_EXIST);
        $DB->set_field('competency_framework', 'shortname', 'Titre d\'accompagnant en établissement', ['id' => $frameworkid]);
        $DB->set_field('competency_framework', 'visible', 0, ['id' => $frameworkid]);

        try {
            (new evidence_manager())->declare_activity((int) $this->candidate->id, $this->submission());
            $this->fail('A declaration on a hidden framework was recorded.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorframeworkhidden', $e->errorcode);
            $message = $e->getMessage();
        }

        // The record by its title and its code, written as text.
        $this->assertSame(
            get_string('errorframeworkhidden', 'tool_vae', s('Titre d\'accompagnant en établissement (RNCP36832)')),
            $message
        );
        $this->assertStringContainsString('Titre d&#039;accompagnant en établissement (RNCP36832)', $message);
        // That it is hidden, and whom to ask.
        $this->assertStringContainsString('hidden', $message);
        $this->assertStringContainsString('manager', $message);
        // No path of the administration, no technical word.
        foreach (['Site administration', 'Competency frameworks', 'administrator', 'Moodle'] as $term) {
            $this->assertStringNotContainsString($term, $message);
        }
    }

    /**
     * What the plugin itself writes into an idnumber passes the same check.
     *
     * The importer holds a rank to importer::RANK_PATTERN and fiche_converter writes `BC` and up to six digits, so every
     * competency the plugin creates can be declared on. Only what an administrator typed by hand into tool_lp is refused.
     */
    public function test_the_ranks_the_plugin_writes_itself_are_accepted(): void {
        foreach (['C03', 'BC01', 'BC123456', '1', 'A1B2'] as $rank) {
            $this->assertMatchesRegularExpression(local\importer::RANK_PATTERN, $rank);
            $competencyid = $rank === 'C03' ? $this->competencyid : $this->competency_with_idnumber('RNCP36832-' . $rank);

            $record = (new evidence_manager())->declare_activity(
                (int) $this->candidate->id,
                $this->submission(['competencyid' => $competencyid])
            );

            $this->assertSame($rank, $record->ficherang);
        }
    }

    /**
     * A competency whose idnumber does not start with the code of its own record is refused by name.
     *
     * `RNCP36832bis-C7` splits into the code `RNCP36832bis`, which is not the record this framework was imported as: the claim
     * would be written under a record that does not exist here, and the archive that carries it is refused on reimport
     * (errorarchivenocompetency), for the same reason as above.
     */
    public function test_a_competency_whose_code_is_not_the_records_is_refused(): void {
        global $DB;

        $competencyid = $this->competency_with_idnumber('RNCP36832bis-C7');

        try {
            (new evidence_manager())->declare_activity(
                (int) $this->candidate->id,
                $this->submission(['competencyid' => $competencyid])
            );
            $this->fail('A competency outside the code of its record was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorcompetencyoutsiderecord', $e->errorcode);
            $this->assertStringContainsString('RNCP36832bis-C7', $e->getMessage());
        }

        $this->assertEquals(0, $DB->count_records('tool_vae_evidence'));
        $this->assertEquals(0, $DB->count_records('competency_userevidence'));
    }

    /**
     * A competency of the imported framework, carrying the idnumber given.
     *
     * @param string $idnumber
     * @return int
     */
    private function competency_with_idnumber(string $idnumber): int {
        global $DB;

        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', [], MUST_EXIST);
        $competency = $this->getDataGenerator()
            ->get_plugin_generator(competency_gateway::SUBSYSTEM)
            ->create_competency([
                'competencyframeworkid' => $frameworkid,
                'idnumber' => $idnumber,
                'shortname' => 'Bloc de compétences',
            ]);

        return (int) $competency->get('id');
    }
}
