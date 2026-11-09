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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;
use tool_vae\local\booklet_authorization;
use tool_vae\local\evidence_manager;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\positioning_rules;
use tool_vae\local\structure_archive;
use tool_vae\privacy\provider;

/**
 * The authorization to produce the archive of the structure, and that archive, under the rights of the people they name.
 *
 * WHAT IS ASSERTED. That the manager and the member of the direction who wrote a line are announced as holders of data, that the
 * export of the manager carries their lines, that an erasure takes the lines of the manager with them and leaves the line of the
 * one who wrote it without their name, and that the archive of the structure goes with the dossier of the vaeiste it is about.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\privacy\provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\structure_archive::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\booklet_authorization::class)]
final class booklet_privacy_test extends \advanced_testcase {
    /** @var \stdClass The vaeiste. */
    private $candidate;

    /** @var \stdClass The manager who follows them and was authorized. */
    private $manager;

    /** @var \stdClass The member of the direction who authorized. */
    private $direction;

    /**
     * A vaeiste with a declaration and one positioning, a manager who follows them, a direction who authorized the manager.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        (new importer())->import((object) [
            'fichecode' => 'RNCP90000', 'fichetype' => 'rncp', 'name' => 'Fiche RNCP90000',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv("rang,intitule,description,bloc\nBC01,Bloc un,,\nC01,Premiere,,BC01\nC02,Seconde,,BC01\n"));

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $this->candidate = $generator->create_user();
        $this->manager = $generator->create_user();
        $this->direction = $generator->create_user();

        $own = $generator->create_role();
        foreach (['declare', 'export', 'viewown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, $system, true);
        }
        role_assign($own, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        $follow = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, \context_user::instance($this->candidate->id)->id);
        $director = $generator->create_role();
        foreach (['tool/vae:viewany', 'tool/vae:issuestructure'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $director, $system, true);
        }
        role_assign($director, $this->direction->id, $system);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->candidate);
        $competency = $DB->get_field('competency', 'id', ['idnumber' => 'RNCP90000-C01'], MUST_EXIST);
        (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $competency, 'periodstart' => 1756684800, 'periodend' => 1782777600,
            'settingtype' => 'ehpad', 'situation' => 'La situation de travail du vaeiste.', 'situationformat' => FORMAT_PLAIN,
        ]);
        $DB->insert_record('tool_vae_eval', (object) [
            'uuid' => identity_map::generate_ulid(), 'candidateid' => $this->candidate->id, 'fichecode' => 'RNCP90000',
            'ficherang' => 'C01', 'status' => 'sufficient', 'reason' => 'Trois situations vues.',
            'reasonhash' => positioning_rules::hash(['sufficient', 'RNCP90000', 'C01', 'Trois situations vues.']),
            'authorid' => $this->manager->id, 'authoruuid' => null, 'importedat' => null, 'timecreated' => 1762100000,
        ]);
        $this->setUser($this->direction);
        booklet_authorization::grant((int) $this->manager->id);
        $this->setAdminUser();
    }

    /**
     * The approval of a site-level erasure.
     *
     * @param \stdClass $user
     * @return approved_contextlist
     */
    private function site_approval(\stdClass $user): approved_contextlist {
        return new approved_contextlist(\core_user::get_user($user->id), 'tool_vae', [\context_system::instance()->id]);
    }

    public function test_the_manager_and_the_direction_are_announced_as_holders_of_data(): void {
        $system = \context_system::instance()->id;

        foreach ([$this->manager, $this->direction] as $user) {
            $contexts = provider::get_contexts_for_userid((int) $user->id)->get_contextids();
            $this->assertContains((string) $system, array_map('strval', $contexts), fullname($user));
        }
    }

    public function test_both_are_found_in_the_list_of_users_of_the_system_context(): void {
        $userlist = new \core_privacy\local\request\userlist(\context_system::instance(), 'tool_vae');
        provider::get_users_in_context($userlist);

        $this->assertContains((int) $this->manager->id, array_map('intval', $userlist->get_userids()));
        $this->assertContains((int) $this->direction->id, array_map('intval', $userlist->get_userids()));
    }

    public function test_the_export_of_the_manager_carries_their_lines(): void {
        provider::export_user_data($this->site_approval($this->manager));

        $data = writer::with_context(\context_system::instance())->get_data([get_string('pluginname', 'tool_vae')]);
        $this->assertCount(1, $data->structure_authorizations);
        $this->assertSame('granted', $data->structure_authorizations[0]['status']);
    }

    public function test_the_export_of_the_direction_carries_the_lines_they_wrote(): void {
        provider::export_user_data($this->site_approval($this->direction));

        $data = writer::with_context(\context_system::instance())->get_data([get_string('pluginname', 'tool_vae')]);
        $this->assertCount(1, $data->structure_authorizations_written);
    }

    public function test_erasing_the_manager_takes_their_lines_with_them(): void {
        global $DB;

        provider::delete_data_for_user($this->site_approval($this->manager));

        $this->assertSame(0, $DB->count_records('tool_vae_booklet_auth', ['managerid' => $this->manager->id]));
        $this->assertFalse(booklet_authorization::stands((int) $this->manager->id));
    }

    public function test_erasing_the_direction_leaves_the_line_without_their_name(): void {
        global $DB;

        provider::delete_data_for_user($this->site_approval($this->direction));

        $rows = $DB->get_records('tool_vae_booklet_auth', ['managerid' => $this->manager->id]);
        $this->assertCount(1, $rows);
        $this->assertNull(reset($rows)->grantedby);
        $this->assertTrue(booklet_authorization::stands((int) $this->manager->id), 'The authorization outlives who wrote it.');
    }

    public function test_the_bulk_path_reaches_the_authorizations_too(): void {
        global $DB;

        provider::delete_data_for_users(new approved_userlist(
            \context_system::instance(),
            'tool_vae',
            [(int) $this->manager->id]
        ));

        $this->assertSame(0, $DB->count_records('tool_vae_booklet_auth', ['managerid' => $this->manager->id]));
    }

    public function test_the_archive_of_the_structure_goes_with_the_dossier_of_the_vaeiste(): void {
        $this->setUser($this->direction);
        structure_archive::issue((int) $this->candidate->id);
        $this->assertNotNull(structure_archive::current((int) $this->candidate->id));

        $this->setAdminUser();
        provider::delete_data_for_all_users_in_context(\context_user::instance($this->candidate->id));

        $this->assertNull(structure_archive::current((int) $this->candidate->id));
    }

    public function test_the_export_of_the_vaeiste_carries_the_archive_of_the_structure(): void {
        $this->setUser($this->direction);
        $file = structure_archive::issue((int) $this->candidate->id);
        $this->setAdminUser();

        provider::export_user_data(new approved_contextlist(
            \core_user::get_user($this->candidate->id),
            'tool_vae',
            [\context_user::instance($this->candidate->id)->id]
        ));

        $files = writer::with_context(\context_user::instance($this->candidate->id))->get_files([
            get_string('pluginname', 'tool_vae'),
            get_string('privacy:path:structure', 'tool_vae'),
        ]);
        $this->assertArrayHasKey($file->get_filename(), $files);
    }

    public function test_erasing_a_positioning_author_discards_the_archive_of_the_structure_that_carries_their_identifier(): void {
        $this->setUser($this->direction);
        structure_archive::issue((int) $this->candidate->id);
        $this->assertNotNull(structure_archive::current((int) $this->candidate->id));
        $this->setAdminUser();

        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($this->manager->id),
            'tool_vae',
            [\context_user::instance($this->candidate->id)->id]
        ));

        $this->assertNull(
            structure_archive::current((int) $this->candidate->id),
            'The archive carried the opaque identifier of the author who is erased: the direction produces a new one.'
        );
    }

    public function test_deleting_the_account_of_the_manager_through_the_core_takes_their_lines_with_it(): void {
        global $DB;

        delete_user($this->manager);

        $this->assertSame(0, $DB->count_records('tool_vae_booklet_auth', ['managerid' => $this->manager->id]));
    }

    public function test_deleting_the_account_of_the_direction_through_the_core_leaves_the_line_without_the_name(): void {
        global $DB;

        delete_user($this->direction);

        $rows = $DB->get_records('tool_vae_booklet_auth', ['managerid' => $this->manager->id]);
        $this->assertCount(1, $rows);
        $this->assertNull(reset($rows)->grantedby);
    }
}
