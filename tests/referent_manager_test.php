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

use tool_vae\event\referent_added;
use tool_vae\event\referent_removed;
use tool_vae\local\evidence_manager;
use tool_vae\local\importer;
use tool_vae\local\referent_manager;
use tool_vae\task\send_notification;

/**
 * Who is the referent of a bloc, and who is told first of a declaration (T-23, lots 1 and 2).
 *
 * WHAT IS ASSERTED. That an administrator names and takes back a referent for a bloc of an imported record or for the whole
 * record, and nobody else; that a rank that is no bloc, an account that is not active and a name given twice are refused; that
 * the log carries a code, a rank and the referent, no more; that among the managers who follow a candidate the referents of the
 * bloc of a declaration (and of the whole record) are the ones told, every follower when none of them is among them, and
 * everyone when the record has no blocs; that nobody is made able or unable to read or attest because of it; and that the rows
 * of a person leave with them.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\referent_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\notifier::class)]
final class referent_manager_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass[] Three managers who follow the candidate, by name. */
    private $managers = [];

    /**
     * A record with two blocs and a flat one, a candidate, and three managers who follow them and may attest.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();

        $meta = fn(string $code) => (object) [
            'fichecode' => $code, 'fichetype' => 'rs', 'name' => 'Fiche ' . $code,
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ];
        $importer = new importer();
        $importer->import($meta('RS00000'), importer::rows_from_csv(
            "rang,intitule,description,bloc\nBC01,Accompagner,,\nC01,Aider au lever,,BC01\nBC02,Travailler en équipe,,\n"
            . "C02,Transmettre,,BC02\nZ01,Hors bloc,,\n"
        ));
        $importer->import($meta('RS11111'), importer::rows_from_csv("rang,intitule\nC01,Une\nC02,Deux\n"));

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $candidaterole = $generator->create_role();
        $system = \context_system::instance()->id;
        foreach (['declare', 'viewown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $candidaterole, $system, true);
        }
        role_assign($candidaterole, $this->candidate->id, \context_user::instance($this->candidate->id)->id);

        $managerrole = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $managerrole, $system, true);
        assign_capability('tool/vae:attest', CAP_ALLOW, $managerrole, $system, true);
        set_role_contextlevels($managerrole, [CONTEXT_USER]);
        foreach (['alice', 'bruno', 'chloe'] as $name) {
            $this->managers[$name] = $generator->create_user(['username' => $name]);
            role_assign($managerrole, $this->managers[$name]->id, \context_user::instance($this->candidate->id)->id);
        }
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Who is told of a declaration on a competency, by the name of the manager.
     *
     * @param string $idnumber
     * @return string[] Sorted.
     */
    private function told_of_declaring_on(string $idnumber): array {
        global $DB;

        $this->setUser($this->candidate);
        $DB->delete_records('task_adhoc');
        $competency = $DB->get_field('competency', 'id', ['idnumber' => $idnumber], MUST_EXIST);
        (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $competency, 'periodstart' => 1756684800, 'periodend' => 1782777600,
            'settingtype' => 'ehpad', 'situation' => 'Une situation.', 'situationformat' => FORMAT_PLAIN,
        ]);
        $names = [];
        $byid = [];
        foreach ($this->managers as $name => $user) {
            $byid[(int) $user->id] = $name;
        }
        foreach (\core\task\manager::get_adhoc_tasks(send_notification::class) as $task) {
            $data = $task->get_custom_data();
            if ($data->provider === notifier::DECLARATION_PENDING && isset($byid[(int) $data->recipientid])) {
                $names[] = $byid[(int) $data->recipientid];
            }
        }
        $DB->delete_records('task_adhoc');
        sort($names);

        return $names;
    }

    public function test_the_administrator_names_and_takes_back_a_referent(): void {
        $row = referent_manager::add('RS00000', 'BC01', (int) $this->managers['alice']->id);

        $listed = referent_manager::for_fiches(['RS00000', 'RS11111']);
        $this->assertSame(['RS00000'], array_keys($listed));
        $this->assertSame('BC01', $listed['RS00000'][0]->ficherang);
        $this->assertSame((int) $this->managers['alice']->id, $listed['RS00000'][0]->userid);

        referent_manager::remove((int) $row->id);
        $this->assertSame([], referent_manager::for_fiches(['RS00000']));
    }

    public function test_the_whole_record_is_a_choice_too(): void {
        $row = referent_manager::add('RS11111', '', (int) $this->managers['alice']->id);

        $this->assertSame('', $row->ficherang);
    }

    public function test_a_rank_that_is_no_bloc_is_refused(): void {
        // C01 is a competency under a bloc, Z01 sits under none, C99 does not exist.
        foreach (['C01', 'Z01', 'C99'] as $rank) {
            try {
                referent_manager::add('RS00000', $rank, (int) $this->managers['alice']->id);
                $this->fail("$rank was accepted as a bloc.");
            } catch (\moodle_exception $e) {
                $this->assertSame('errorreferentbloc', $e->errorcode);
            }
        }
        // A flat record has no bloc, only itself.
        try {
            referent_manager::add('RS11111', 'C01', (int) $this->managers['alice']->id);
            $this->fail('A competency of a flat record was accepted as a bloc.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorreferentbloc', $e->errorcode);
        }
    }

    public function test_a_bloc_whose_code_was_rewritten_by_hand_is_no_bloc(): void {
        global $DB, $PAGE;

        // A manager rewrote the code of BC01 in the competencies screens of the core, which take any text.
        $hostile = 'RS00000-<img src=x onerror=alert(1)>';
        $DB->set_field('competency', 'idnumber', $hostile, ['idnumber' => 'RS00000-BC01']);
        $source = $DB->get_record('tool_vae_source', ['fichecode' => 'RS00000'], '*', MUST_EXIST);

        $this->assertSame(['BC02'], referent_manager::blocs($source));
        $map = referent_manager::bloc_map('RS00000');
        $this->assertArrayNotHasKey('<img src=x onerror=alert(1)>', $map);
        $this->assertSame('', $map['C01'], 'What sat under a bloc that is no bloc any more sits under none.');
        try {
            referent_manager::add('RS00000', '<img src=x onerror=alert(1)>', (int) $this->managers['alice']->id);
            $this->fail('A rank the import would have refused was accepted as a bloc.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorreferentbloc', $e->errorcode);
        }

        // And what the administrator is offered is text.
        $PAGE->set_url('/admin/tool/vae/referents.php');
        $PAGE->set_context(\context_system::instance());
        [$options] = referent_manager::choices(\tool_vae\local\supersession::overview());
        $this->assertSame(['RS00000', 'RS00000|BC02', 'RS11111'], array_keys($options));
        foreach ($options as $label) {
            $this->assertStringNotContainsString('<img', $label);
        }
    }

    public function test_the_choices_of_the_referent_form_name_the_record_and_its_blocs(): void {
        global $PAGE;

        $PAGE->set_url('/admin/tool/vae/referents.php');
        $PAGE->set_context(\context_system::instance());

        [$options, $parts] = referent_manager::choices(\tool_vae\local\supersession::overview());

        $this->assertSame(['RS00000', 'RS00000|BC01', 'RS00000|BC02', 'RS11111'], array_keys($options));
        $this->assertSame(['RS00000', 'BC02'], $parts['RS00000|BC02']);
        $this->assertSame(['RS11111', ''], $parts['RS11111']);
        $this->assertStringContainsString('RS00000 — Fiche RS00000 — ', $options['RS00000|BC01']);
    }

    public function test_an_unknown_record_an_inactive_account_and_a_duplicate_are_refused(): void {
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);
        $cases = [
            ['RS99999', 'BC01', (int) $this->managers['alice']->id, 'errorsupersessionunknown'],
            ['RS00000', 'BC01', (int) $suspended->id, 'errorreferentuser'],
            ['RS00000', 'BC01', (int) $deleted->id, 'errorreferentuser'],
            ['RS00000', 'BC01', 99999, 'errorreferentuser'],
        ];
        foreach ($cases as [$code, $rank, $userid, $expected]) {
            try {
                referent_manager::add($code, $rank, $userid);
                $this->fail("$code $rank $userid was accepted.");
            } catch (\moodle_exception $e) {
                $this->assertSame($expected, $e->errorcode);
            }
        }

        referent_manager::add('RS00000', 'BC01', (int) $this->managers['alice']->id);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorreferentduplicate', 'tool_vae'));
        referent_manager::add('RS00000', 'BC01', (int) $this->managers['alice']->id);
    }

    public function test_only_who_may_import_names_a_referent(): void {
        $this->setUser($this->managers['alice']);

        try {
            referent_manager::add('RS00000', 'BC01', (int) $this->managers['alice']->id);
            $this->fail('A manager named a referent.');
        } catch (\required_capability_exception $e) {
            $this->assertSame([], referent_manager::for_fiches(['RS00000']));
        }
        $this->setAdminUser();
        $row = referent_manager::add('RS00000', 'BC01', (int) $this->managers['alice']->id);
        $this->setUser($this->managers['alice']);
        $this->expectException(\required_capability_exception::class);
        referent_manager::remove((int) $row->id);
    }

    public function test_the_log_names_a_code_a_rank_and_the_referent(): void {
        $sink = $this->redirectEvents();
        $row = referent_manager::add('RS00000', 'BC01', (int) $this->managers['alice']->id);
        referent_manager::remove((int) $row->id);
        $events = $sink->get_events();
        $sink->close();

        $added = array_values(array_filter($events, fn($e) => $e instanceof referent_added));
        $removed = array_values(array_filter($events, fn($e) => $e instanceof referent_removed));
        $this->assertCount(1, $added);
        $this->assertCount(1, $removed);
        $this->assertSame('c', $added[0]->crud);
        $this->assertSame('d', $removed[0]->crud);
        foreach ([$added[0], $removed[0]] as $event) {
            $this->assertSame('RS00000', $event->other['fichecode']);
            $this->assertSame('BC01', $event->other['ficherang']);
            $this->assertSame((int) $this->managers['alice']->id, (int) $event->relateduserid);
        }
    }

    public function test_the_bloc_of_a_competency(): void {
        $this->assertSame('BC01', referent_manager::bloc_of('RS00000', 'C01'));
        $this->assertSame('BC02', referent_manager::bloc_of('RS00000', 'C02'));
        $this->assertSame('BC01', referent_manager::bloc_of('RS00000', 'BC01'), 'A bloc is its own bloc.');
        $this->assertSame('', referent_manager::bloc_of('RS00000', 'Z01'));
        $this->assertSame('', referent_manager::bloc_of('RS11111', 'C01'));
        $this->assertSame('', referent_manager::bloc_of('RS99999', 'C01'));
    }

    public function test_route_tells_the_referents_of_the_bloc_and_of_the_whole_record(): void {
        $alice = (int) $this->managers['alice']->id;
        $bruno = (int) $this->managers['bruno']->id;
        $chloe = (int) $this->managers['chloe']->id;
        referent_manager::add('RS00000', 'BC01', $alice);
        referent_manager::add('RS00000', 'BC02', $bruno);
        referent_manager::add('RS00000', '', $chloe);
        $followers = [$alice, $bruno, $chloe];

        $this->assertEquals([$alice, $chloe], referent_manager::route($followers, 'RS00000', 'C01'));
        $this->assertEquals([$bruno, $chloe], referent_manager::route($followers, 'RS00000', 'C02'));
        // A competency under no bloc: the referent of the whole record alone.
        $this->assertEquals([$chloe], referent_manager::route($followers, 'RS00000', 'Z01'));
    }

    public function test_route_falls_back_on_every_follower(): void {
        $alice = (int) $this->managers['alice']->id;
        $bruno = (int) $this->managers['bruno']->id;
        $outsider = (int) $this->getDataGenerator()->create_user()->id;
        referent_manager::add('RS00000', 'BC01', $outsider);
        $followers = [$alice, $bruno];

        // The referent does not follow this candidate: nothing is lost in silence.
        $this->assertEquals($followers, referent_manager::route($followers, 'RS00000', 'C01'));
        // A bloc with no referent at all.
        $this->assertEquals($followers, referent_manager::route($followers, 'RS00000', 'C02'));
        // A record with no blocs and no referent.
        $this->assertEquals($followers, referent_manager::route($followers, 'RS11111', 'C01'));
        $this->assertSame([], referent_manager::route([], 'RS00000', 'C01'));
    }

    public function test_a_declaration_is_told_to_the_referents_of_its_bloc_first(): void {
        referent_manager::add('RS00000', 'BC01', (int) $this->managers['alice']->id);
        referent_manager::add('RS00000', 'BC02', (int) $this->managers['bruno']->id);

        $this->assertSame(['alice'], $this->told_of_declaring_on('RS00000-C01'));
        $this->assertSame(['bruno'], $this->told_of_declaring_on('RS00000-C02'));
        // Nobody is referent of a competency under no bloc: every follower, as before.
        $this->assertSame(['alice', 'bruno', 'chloe'], $this->told_of_declaring_on('RS00000-Z01'));
        $this->assertSame(['alice', 'bruno', 'chloe'], $this->told_of_declaring_on('RS11111-C01'));
    }

    public function test_a_referent_who_cannot_attest_does_not_hide_the_declaration_from_those_who_can(): void {
        // Dora follows the candidate and reads, but holds no right to attest: she is chosen as the referent and cannot be told.
        $generator = $this->getDataGenerator();
        $readonly = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $readonly, \context_system::instance()->id, true);
        set_role_contextlevels($readonly, [CONTEXT_USER]);
        $dora = $generator->create_user(['username' => 'dora']);
        role_assign($readonly, $dora->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(local\access::follows((int) $this->candidate->id, (int) $dora->id));
        $this->assertFalse(local\access::can_attest((int) $this->candidate->id, (int) $dora->id));

        referent_manager::add('RS00000', 'BC01', (int) $dora->id);

        // Nobody is lost in silence: those who can attest are told, as before.
        $this->assertSame(['alice', 'bruno', 'chloe'], $this->told_of_declaring_on('RS00000-C01'));
    }

    public function test_a_suspended_referent_does_not_hide_the_declaration_from_the_others(): void {
        global $DB;
        referent_manager::add('RS00000', 'BC01', (int) $this->managers['alice']->id);
        referent_manager::add('RS00000', 'BC01', (int) $this->managers['bruno']->id);
        $DB->set_field('user', 'suspended', 1, ['id' => $this->managers['alice']->id]);
        $DB->set_field('user', 'suspended', 1, ['id' => $this->managers['bruno']->id]);

        // Both referents are suspended: the announcement goes to the managers who can still be told.
        $this->assertSame(['chloe'], $this->told_of_declaring_on('RS00000-C01'));
    }

    public function test_the_active_filter_keeps_the_order_and_drops_the_suspended_and_the_deleted(): void {
        global $DB;
        $alice = (int) $this->managers['alice']->id;
        $bruno = (int) $this->managers['bruno']->id;
        $chloe = (int) $this->managers['chloe']->id;
        $DB->set_field('user', 'suspended', 1, ['id' => $bruno]);
        $DB->set_field('user', 'deleted', 1, ['id' => $chloe]);

        $this->assertSame([$alice], referent_manager::active([$alice, $bruno, $chloe]));
        $this->assertSame([], referent_manager::active([]));
    }

    public function test_a_referent_gives_and_takes_away_no_right(): void {
        referent_manager::add('RS00000', 'BC01', (int) $this->managers['alice']->id);

        // Bruno is not the referent of anything, and still follows and may attest.
        $this->assertTrue(local\access::follows((int) $this->candidate->id, (int) $this->managers['bruno']->id));
        $this->assertTrue(local\access::can_attest((int) $this->candidate->id, (int) $this->managers['bruno']->id));
    }

    public function test_the_rows_of_a_person_leave_with_them(): void {
        global $DB;
        referent_manager::add('RS00000', 'BC01', (int) $this->managers['alice']->id);
        referent_manager::add('RS00000', 'BC02', (int) $this->managers['bruno']->id);
        $context = \context_system::instance();

        $contexts = \tool_vae\privacy\provider::get_contexts_for_userid((int) $this->managers['alice']->id)->get_contextids();
        $this->assertContains((int) $context->id, array_map('intval', $contexts));
        $users = new \core_privacy\local\request\userlist($context, 'tool_vae');
        \tool_vae\privacy\provider::get_users_in_context($users);
        $this->assertContains((int) $this->managers['alice']->id, array_map('intval', $users->get_userids()));

        $list = new \core_privacy\local\request\approved_contextlist($this->managers['alice'], 'tool_vae', [$context->id]);
        \tool_vae\privacy\provider::export_user_data($list);
        $data = \core_privacy\local\request\writer::with_context($context)->get_data([get_string('pluginname', 'tool_vae')]);
        $this->assertSame('RS00000', $data->referent_of[0]['record']);

        \tool_vae\privacy\provider::delete_data_for_user($list);
        $this->assertSame(0, $DB->count_records('tool_vae_referent', ['userid' => $this->managers['alice']->id]));
        $this->assertSame(1, $DB->count_records('tool_vae_referent', ['userid' => $this->managers['bruno']->id]));
        // Not the administrator's trace of whom they named, which is theirs: it leaves when they do.
        $this->assertSame(
            (int) get_admin()->id,
            (int) $DB->get_field('tool_vae_referent', 'createdby', ['userid' => $this->managers['bruno']->id])
        );
    }
}
