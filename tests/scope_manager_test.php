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

use tool_vae\event\scope_marked;
use tool_vae\event\scope_unmarked;
use tool_vae\local\competency_gateway;
use tool_vae\local\coverage;
use tool_vae\local\evidence_manager;
use tool_vae\local\importer;
use tool_vae\local\pending_report;
use tool_vae\local\presenter;
use tool_vae\local\purge;
use tool_vae\local\scope_manager;

/**
 * What a candidate says is outside their activity (T-42, lots 1 and 2).
 *
 * WHAT IS ASSERTED. That the candidate alone says it and takes it back; that a closed site refuses a new mark and still lets
 * one be taken back; that a competency of an imported record, a bloc included, and nothing else is accepted, once; that the
 * note is short and on one line; that the log carries a code and a rank and never the note; that the box on the dossier lists
 * the marks with the sentence that says it exempts from nothing, and appears for nobody who has nothing to show; that an
 * erasure of the dossier removes the marks; and that nothing is counted, reminded or attested because of a mark.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\scope_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\presenter::class)]
final class scope_manager_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /**
     * A record with a bloc and a competency under it, and a candidate who may declare.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        (new importer())->import((object) [
            'fichecode' => 'RS00000', 'fichetype' => 'rs', 'name' => 'Fiche RS00000',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne — jeu d\'essai',
        ], importer::rows_from_csv(
            "rang,intitule,description,bloc\nBC01,Accompagner la personne,,\nC01,Aider au lever,,BC01\nC02,Transmettre,,BC01\n"
        ));

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->candidate);
        set_config('scopeopen', 1, 'tool_vae');
    }

    public function test_the_candidate_says_it_and_takes_it_back(): void {
        $row = scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01', 'Mon poste ne passe pas par là.');

        $marks = scope_manager::for_candidate((int) $this->candidate->id);
        $this->assertSame(['RS00000-C01'], array_keys($marks));
        $this->assertSame('Mon poste ne passe pas par là.', $marks['RS00000-C01']->note);
        $this->assertSame(26, strlen($row->uuid));

        scope_manager::unmark((int) $this->candidate->id, (int) $row->id);
        $this->assertSame([], scope_manager::for_candidate((int) $this->candidate->id));
    }

    public function test_the_ranks_of_a_record_come_from_one_reading_and_serve_the_restore(): void {
        global $DB;
        $id = (int) $this->candidate->id;

        $ranks = scope_manager::ranks_of('RS00000');
        sort($ranks);
        $this->assertSame(['BC01', 'C01', 'C02'], $ranks);
        $this->assertSame([], scope_manager::ranks_of('RS99999'));

        // With the ranks in hand the record is not asked again: a rank they do not hold is left, one they hold is written.
        $this->assertFalse(scope_manager::restore($id, 'RS00000', 'C01', null, 1760000000, []));
        $this->assertTrue(scope_manager::restore($id, 'RS00000', 'C01', null, 1760000000, $ranks));
        $this->assertFalse(scope_manager::restore($id, 'RS00000', 'C01', null, 1760000000, $ranks), 'A mark that is there stays.');
        // Without them the record is asked, as before.
        $this->assertTrue(scope_manager::restore($id, 'RS00000', 'C02', 'Une note.', 1760000000));
        $this->assertFalse(scope_manager::restore($id, 'RS00000', 'C99', null, 1760000000));
        $this->assertSame(2, $DB->count_records('tool_vae_scope', ['candidateid' => $id]));
    }

    public function test_a_bloc_is_accepted_and_the_note_is_facultative(): void {
        $row = scope_manager::mark((int) $this->candidate->id, 'RS00000', 'BC01');

        $this->assertNull($row->note);
    }

    public function test_a_competency_outside_the_imported_records_is_refused(): void {
        foreach ([['RS99999', 'C01'], ['RS00000', 'C99']] as [$code, $rank]) {
            try {
                scope_manager::mark((int) $this->candidate->id, $code, $rank);
                $this->fail("$code-$rank was accepted.");
            } catch (\moodle_exception $e) {
                $this->assertSame('errorscopeunknown', $e->errorcode);
            }
        }
    }

    /**
     * A rank outside the form of the import is refused by name, and nothing is written.
     *
     * tool_lp lets an administrator type `RS00000-BC 1` or `RS00000-c7` into the idnumber of a competency of an imported
     * framework, so the competency IS in the record. Before: the mark was written, exported in hors_activite.json and refused
     * on reimport (errorarchivescope), the valid marks beside it with it.
     */
    public function test_a_rank_outside_the_form_of_the_import_is_refused_by_name_and_nothing_is_written(): void {
        global $DB;

        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RS00000'], MUST_EXIST);
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        foreach (['BC 1', 'c7', '<b>x'] as $rank) {
            $generator->create_competency([
                'competencyframeworkid' => $frameworkid,
                'idnumber' => 'RS00000-' . $rank,
                'shortname' => 'Rang saisi à la main',
            ]);

            try {
                scope_manager::mark((int) $this->candidate->id, 'RS00000', $rank);
                $this->fail("The rank \"$rank\" is outside the form of the import and was accepted.");
            } catch (\moodle_exception $e) {
                $this->assertSame('errorficherankinvalid', $e->errorcode, $rank);
                $this->assertStringNotContainsString('<b>', $e->getMessage());
            }
        }

        $this->assertSame(0, $DB->count_records('tool_vae_scope'));
        // The marks the form allows are still accepted: the lock refuses a value, not the record.
        $this->assertSame('C01', scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01')->ficherang);
    }

    public function test_once_only(): void {
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorscopeduplicate', 'tool_vae'));
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01');
    }

    public function test_the_note_is_short_and_on_one_line(): void {
        foreach ([str_repeat('a', 256), "deux\nlignes", "Titre \u{202E}piege"] as $note) {
            try {
                scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01', $note);
                $this->fail('A note that is refused was accepted.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errorscopenote', $e->errorcode);
            }
        }
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01', str_repeat('é', 255));
        $this->assertCount(1, scope_manager::for_candidate((int) $this->candidate->id));
    }

    public function test_a_closed_site_refuses_a_new_mark_and_lets_one_be_taken_back(): void {
        $row = scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01');
        set_config('scopeopen', 0, 'tool_vae');

        try {
            scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C02');
            $this->fail('A new mark was accepted on a closed site.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorscopeclosed', $e->errorcode);
        }
        $this->assertCount(1, scope_manager::for_candidate((int) $this->candidate->id));

        scope_manager::unmark((int) $this->candidate->id, (int) $row->id);
        $this->assertSame([], scope_manager::for_candidate((int) $this->candidate->id));
    }

    public function test_nobody_says_it_for_the_candidate(): void {
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($other);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errordeclareforothers', 'tool_vae'));
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01');
    }

    public function test_nobody_takes_it_back_for_the_candidate(): void {
        $row = scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01');
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            scope_manager::unmark((int) $this->candidate->id, (int) $row->id);
            $this->fail('Someone else took the mark back.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordeclareforothers', $e->errorcode);
        }
        $this->setUser($this->candidate);
        $this->assertCount(1, scope_manager::for_candidate((int) $this->candidate->id));
    }

    public function test_the_log_names_a_code_and_a_rank_and_never_the_note(): void {
        $sink = $this->redirectEvents();
        $row = scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01', 'une note secrete');
        scope_manager::unmark((int) $this->candidate->id, (int) $row->id);
        $events = $sink->get_events();
        $sink->close();

        $marked = array_values(array_filter($events, fn($e) => $e instanceof scope_marked));
        $unmarked = array_values(array_filter($events, fn($e) => $e instanceof scope_unmarked));
        $this->assertCount(1, $marked);
        $this->assertCount(1, $unmarked);
        $this->assertSame('c', $marked[0]->crud);
        $this->assertSame('d', $unmarked[0]->crud);
        foreach ([$marked[0], $unmarked[0]] as $event) {
            $this->assertSame('RS00000', $event->other['fichecode']);
            $this->assertSame('C01', $event->other['ficherang']);
            $this->assertSame((int) $this->candidate->id, (int) $event->relateduserid);
            $this->assertStringNotContainsString('secrete', $event->get_description());
            $this->assertStringNotContainsString('secrete', json_encode($event->other));
        }
    }

    public function test_the_box_lists_the_marks_with_the_sentence_and_hides_when_empty(): void {
        $this->assertSame('', presenter::scope_box([], [], false, true), 'Nothing for a reader who is not the candidate.');
        $this->assertSame('', presenter::scope_box([], [], true, false), 'Nothing for a candidate on a closed site.');

        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01', 'Note du <b>candidat</b>');
        $marks = scope_manager::for_candidate((int) $this->candidate->id);
        $html = presenter::scope_box($marks, scope_manager::labels($marks), false, false);

        $this->assertStringContainsString('RS00000-C01', $html);
        $this->assertStringContainsString('Aider au lever', $html);
        $this->assertStringContainsString(s(get_string('scopesentence', 'tool_vae')), $html);
        $this->assertStringContainsString('&lt;b&gt;candidat&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('scope.php', $html, 'Only the candidate is offered the way to change it.');
        $this->assertStringContainsString('scope.php', presenter::scope_box($marks, [], true, true));
    }

    public function test_an_erasure_of_the_dossier_removes_the_marks_of_this_candidate_only(): void {
        $other = $this->make_candidate();
        scope_manager::mark((int) $other->id, 'RS00000', 'C02');
        $this->setUser($this->candidate);
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01');

        purge::dossier((int) $this->candidate->id);

        $this->assertSame([], scope_manager::for_candidate((int) $this->candidate->id));
        $this->assertSame(['RS00000-C02'], array_keys(scope_manager::for_candidate((int) $other->id)));
    }

    public function test_the_orphan_sweep_removes_the_marks_of_an_account_that_is_gone(): void {
        global $DB;
        $gone = $this->make_candidate();
        scope_manager::mark((int) $gone->id, 'RS00000', 'C02');
        $this->setUser($this->candidate);
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01');
        $DB->set_field('user', 'deleted', 1, ['id' => $gone->id]);

        purge::orphan_sections();

        $this->assertSame([], scope_manager::for_candidate((int) $gone->id));
        $this->assertCount(1, scope_manager::for_candidate((int) $this->candidate->id));
    }

    public function test_one_candidate_cannot_take_back_the_mark_of_another(): void {
        $other = $this->make_candidate();
        $row = scope_manager::mark((int) $other->id, 'RS00000', 'C02');
        $this->setUser($this->candidate);

        try {
            scope_manager::unmark((int) $this->candidate->id, (int) $row->id);
            $this->fail('A mark of another candidate was taken back.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorscopeunknownmark', $e->errorcode);
        }
        $this->assertCount(1, scope_manager::for_candidate((int) $other->id));
    }

    public function test_a_line_separator_in_the_note_is_refused(): void {
        foreach (["ligne1\u{2028}ligne2", "ligne1\u{0085}ligne2", "ligne1\u{2029}ligne2"] as $note) {
            try {
                scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01', $note);
                $this->fail('A note with a separator was accepted.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errorscopenote', $e->errorcode);
            }
        }
    }

    public function test_a_mark_changes_neither_the_coverage_nor_the_reminders(): void {
        global $DB;
        $competency = $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-C01'], MUST_EXIST);
        (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $competency, 'periodstart' => 1756684800, 'periodend' => 1782777600,
            'settingtype' => 'ehpad', 'situation' => 'Une situation.', 'situationformat' => FORMAT_PLAIN,
        ]);
        $coverage = coverage::for_candidate((int) $this->candidate->id);
        $awaiting = pending_report::awaiting(0);

        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01');
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'BC01');

        $this->assertEquals($coverage, coverage::for_candidate((int) $this->candidate->id));
        $this->assertEquals($awaiting, pending_report::awaiting(0));
        $this->assertSame(0, $DB->count_records('tool_vae_attest'));
    }

    public function test_the_labels_are_empty_and_the_marks_still_there_when_the_subsystem_is_off(): void {
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01');
        set_config('enabled', 0, \tool_vae\local\competency_gateway::SUBSYSTEM);
        $marks = scope_manager::for_candidate((int) $this->candidate->id);

        $this->assertSame(['RS00000-C01' => ''], scope_manager::labels($marks));
        scope_manager::unmark((int) $this->candidate->id, (int) reset($marks)->id);
        $this->assertSame([], scope_manager::for_candidate((int) $this->candidate->id));
    }

    /**
     * The labels of the marks are read where nobody is logged in: the backup of the uninstall, the command line.
     *
     * scope_manager::document() is what every archive and every backup carries, and it asked the core's listing,
     * which wants moodle/competency:competencyview of $USER. User 0 does not hold it, so the uninstall of the
     * plugin stopped on a refusal as soon as one candidate had a mark - and README and FAQ present the command
     * line as the safe way.
     */
    public function test_the_labels_and_the_document_are_read_where_nobody_is_logged_in(): void {
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01', 'Hors de mon poste.');
        $marks = scope_manager::for_candidate((int) $this->candidate->id);

        $this->setUser(0);
        $this->assertFalse(
            has_capability('moodle/competency:competencyview', \context_system::instance()),
            'Nobody holds the right: the test proves nothing.'
        );

        $this->assertSame(['RS00000-C01' => 'Aider au lever'], scope_manager::labels($marks));
        $document = scope_manager::document((int) $this->candidate->id);
        $this->assertSame('Aider au lever', $document['competences'][0]['intitule']);
    }

    /**
     * The ranks of a record, and whether it holds one, are read where nobody is logged in: the import of a dossier.
     */
    public function test_the_ranks_of_a_record_are_read_without_the_viewing_right_of_the_core(): void {
        $this->setUser(0);

        $ranks = scope_manager::ranks_of('RS00000');
        sort($ranks);
        $this->assertSame(['BC01', 'C01', 'C02'], $ranks);

        // Without the ranks in hand the record is asked, which is the other reading of the tree.
        $this->assertTrue(scope_manager::restore((int) $this->candidate->id, 'RS00000', 'C02', null, 1760000000));
    }

    /**
     * A new mark is refused, by the plugin's own message, while the competencies are off.
     *
     * The core's listing used to refuse the read of the record, and with it the mark, with its own exception;
     * the record is read without that listing now, so the refusal is asked for here.
     */
    public function test_a_new_mark_is_refused_by_name_while_the_competencies_are_off(): void {
        set_config('enabled', 0, \tool_vae\local\competency_gateway::SUBSYSTEM);

        try {
            scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01');
            $this->fail('A mark was accepted with the competency subsystem off.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorcompetenciesdisabled', $e->errorcode);
        }
        $this->assertSame([], scope_manager::for_candidate((int) $this->candidate->id));
    }

    /**
     * A record whose framework was deleted in tool_lp gives no rank and no label, and raises nothing.
     */
    public function test_a_record_whose_framework_is_gone_gives_nothing_and_raises_nothing(): void {
        global $DB;

        $row = scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01');
        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RS00000']);
        $DB->delete_records('competency', ['competencyframeworkid' => $frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $frameworkid]);

        $marks = scope_manager::for_candidate((int) $this->candidate->id);
        $this->assertSame(['RS00000-C01' => ''], scope_manager::labels($marks));
        $this->assertSame([], scope_manager::ranks_of('RS00000'));
        try {
            scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C02');
            $this->fail('A mark was accepted on a record that holds no competency.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorscopeunknown', $e->errorcode);
        }
        // And what was said stays, and can be taken back.
        scope_manager::unmark((int) $this->candidate->id, (int) $row->id);
        $this->assertSame([], scope_manager::for_candidate((int) $this->candidate->id));
    }

    public function test_the_provider_lists_exports_and_erases_the_marks(): void {
        global $DB;
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01', 'Une note.');
        $context = \context_user::instance((int) $this->candidate->id);

        $contexts = \tool_vae\privacy\provider::get_contexts_for_userid((int) $this->candidate->id)->get_contextids();
        $this->assertContains((int) $context->id, array_map('intval', $contexts));

        $users = new \core_privacy\local\request\userlist($context, 'tool_vae');
        \tool_vae\privacy\provider::get_users_in_context($users);
        $this->assertContains((int) $this->candidate->id, array_map('intval', $users->get_userids()));

        $list = new \core_privacy\local\request\approved_contextlist($this->candidate, 'tool_vae', [$context->id]);
        \tool_vae\privacy\provider::export_user_data($list);
        $data = \core_privacy\local\request\writer::with_context($context)->get_data(
            [get_string('pluginname', 'tool_vae'), get_string('privacy:path:scope', 'tool_vae')]
        );
        $this->assertSame('RS00000', $data->outside_activity[0]['record']);
        $this->assertSame('Une note.', $data->outside_activity[0]['note']);

        \tool_vae\privacy\provider::delete_data_for_user($list);
        $this->assertSame(0, $DB->count_records('tool_vae_scope', ['candidateid' => $this->candidate->id]));
    }

    /**
     * Another candidate who may declare, as the current user.
     *
     * @return \stdClass
     */
    private function make_candidate(): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_user::instance($user->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        return $user;
    }
}
