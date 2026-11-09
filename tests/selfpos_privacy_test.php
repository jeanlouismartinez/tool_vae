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
use core_privacy\local\request\userlist;
use tool_vae\local\purge;
use tool_vae\local\record_remover;
use tool_vae\local\selfpos_manager;
use tool_vae\privacy\provider;

/**
 * The self-positioning of the vaeiste and the right to be forgotten (lot 1a).
 *
 * The vaeiste's own word about themselves: it is exported to them and to nobody else through the privacy API, and every path
 * of erasure of the vaeiste removes it all - the request of the person, the purge of the dossier, the deletion of the account.
 * There is no author to strip: the vaeiste is the author.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(purge::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(record_remover::class)]
final class selfpos_privacy_test extends \advanced_testcase {
    /** @var \stdClass The vaeiste. */
    private $vaeiste;

    /** @var \stdClass Follows the vaeiste, wrote nothing. */
    private $manager;

    /** @var \tool_vae_generator */
    private $generator;

    /**
     * RNCP100 (BC01 holding C01 and C02); a vaeiste who declared on C01 and positioned themselves twice.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $datagenerator = $this->getDataGenerator();
        $this->generator = $datagenerator->get_plugin_generator('tool_vae');
        $this->generator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        foreach (['C01', 'C02'] as $rank) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP100', 'ficherang' => $rank, 'shortname' => 'Competence ' . $rank, 'bloc' => 'BC01',
            ]);
        }

        $this->vaeiste = $datagenerator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->manager = $datagenerator->create_user();
        $system = \context_system::instance()->id;
        $own = $datagenerator->create_role();
        foreach (['declare', 'viewown', 'positionself'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, $system, true);
        }
        role_assign($own, $this->vaeiste->id, \context_user::instance($this->vaeiste->id)->id);
        $follow = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, \context_user::instance($this->vaeiste->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01',
            'situation' => 'Une situation vecue en etablissement.',
        ]);
        $this->write_lines();
    }

    /**
     * Two lines: the record itself, then C01.
     */
    private function write_lines(): void {
        $this->generator->create_selfpos([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => '', 'level' => 1,
            'justification' => 'Je commence.',
        ]);
        $this->generator->create_selfpos([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'level' => 3,
            'justification' => 'Une justification personnelle.',
        ]);
    }

    /**
     * What a request made by this person would carry for the user context of the vaeiste.
     *
     * @param \stdClass $user
     * @return approved_contextlist
     */
    private function approval(\stdClass $user): approved_contextlist {
        return new approved_contextlist(\core_user::get_user($user->id), 'tool_vae', [
            \context_user::instance($this->vaeiste->id)->id,
        ]);
    }

    public function test_the_privacy_metadata_lists_the_table(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('tool_vae'));
        $tables = array_filter($collection->get_collection(), fn($item) => $item->get_name() === 'tool_vae_selfpos');

        $this->assertCount(1, $tables);
        $fields = array_keys(reset($tables)->get_privacy_fields());
        $this->assertEqualsCanonicalizing(
            ['uuid', 'candidateid', 'fichecode', 'ficherang', 'level', 'levellabel', 'justification', 'justificationformat',
                'linehash', 'importedat', 'timecreated'],
            $fields
        );
        foreach (reset($tables)->get_privacy_fields() as $field => $string) {
            $this->assertStringNotContainsString('[[', get_string($string, 'tool_vae'), $field);
        }
    }

    public function test_the_vaeiste_is_pointed_at_the_dossier_by_these_lines_and_nobody_else(): void {
        global $DB;
        // Only the self-positioning: the declaration would point too, so it goes.
        $DB->delete_records('tool_vae_evidence');
        $context = \context_user::instance($this->vaeiste->id)->id;

        $this->assertContains(
            $context,
            array_map('intval', provider::get_contexts_for_userid((int) $this->vaeiste->id)->get_contextids())
        );
        $this->assertNotContains(
            $context,
            array_map('intval', provider::get_contexts_for_userid((int) $this->manager->id)->get_contextids()),
            'The manager wrote nothing: these lines name nobody but the vaeiste.'
        );
        $userlist = new userlist(\context_user::instance($this->vaeiste->id), 'tool_vae');
        provider::get_users_in_context($userlist);
        $ids = array_map('intval', $userlist->get_userids());
        $this->assertContains((int) $this->vaeiste->id, $ids);
        $this->assertNotContains((int) $this->manager->id, $ids);
    }

    public function test_the_export_gives_the_vaeiste_every_one_of_his_selfpositioning_lines(): void {
        provider::export_user_data($this->approval($this->vaeiste));
        $writer = \core_privacy\local\request\writer::with_context(\context_user::instance($this->vaeiste->id));
        $path = [get_string('pluginname', 'tool_vae'), get_string('privacy:path:selfpositionings', 'tool_vae')];
        $data = $writer->get_data($path);

        $this->assertCount(2, $data->lines);
        $this->assertSame(['', 'C01'], array_column($data->lines, 'rank'));
        $this->assertSame([1, 3], array_column($data->lines, 'level'));
        $this->assertSame(get_string('selfposlabeldefault3', 'tool_vae'), $data->lines[1]['label']);
        $this->assertSame('Une justification personnelle.', $data->lines[1]['justification']);
        $this->assertNotEmpty($data->lines[1]['written']);
        $this->assertNotEmpty($data->lines[1]['sha256']);

        // The manager who follows reads the lines on the screen, but the privacy export of the manager is about the manager.
        \core_privacy\local\request\writer::reset();
        provider::export_user_data($this->approval($this->manager));
        $writer = \core_privacy\local\request\writer::with_context(\context_user::instance($this->vaeiste->id));
        $this->assertEmpty((array) $writer->get_data($path), 'Not theirs: nothing.');
    }

    public function test_the_three_erasure_paths_empty_the_table(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:positionself', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, $other->id, \context_user::instance($other->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->generator->create_selfpos(['userid' => $other->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C02', 'level' => 2]);
        $this->assertSame(3, $DB->count_records(selfpos_manager::TABLE));

        // 1. The request of the person (right to be forgotten).
        provider::delete_data_for_user($this->approval($this->vaeiste));
        $this->assertSame(0, $DB->count_records(selfpos_manager::TABLE, ['candidateid' => $this->vaeiste->id]));
        $this->assertSame(1, $DB->count_records(selfpos_manager::TABLE), 'The other vaeiste\'s line stays.');

        // 2. The purge of the dossier, the one place that knows what a dossier is made of.
        $this->write_lines();
        purge::dossier((int) $this->vaeiste->id);
        $this->assertSame(0, $DB->count_records(selfpos_manager::TABLE, ['candidateid' => $this->vaeiste->id]));

        // 3. The deletion of the account, caught up by the scheduled purge whether or not the observer ran.
        $this->write_lines();
        delete_user($this->vaeiste);
        purge::orphan_sections();
        $this->assertSame(0, $DB->count_records(selfpos_manager::TABLE, ['candidateid' => $this->vaeiste->id]));
        $this->assertSame(1, $DB->count_records(selfpos_manager::TABLE));
    }

    public function test_a_deleted_account_leaves_no_line(): void {
        global $DB;

        delete_user($this->vaeiste);
        purge::orphan_sections();

        $this->assertSame(0, $DB->count_records(selfpos_manager::TABLE));
    }

    public function test_a_selfpositioning_line_stops_a_wrongly_imported_record_from_being_removed(): void {
        global $DB;
        $this->setAdminUser();
        // The record is gone from the core, and the vaeiste's other traces too: only the self-positioning is left.
        $DB->delete_records('tool_vae_evidence');
        // The core refuses to delete a framework a user evidence hangs on: the state is made as a restored database leaves it,
        // the rows gone from the tables without the checks of the core.
        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP100'], MUST_EXIST);
        $DB->delete_records('competency', ['competencyframeworkid' => $frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $frameworkid]);
        $remover = new record_remover();

        $refusal = $remover->refusal('RNCP100');

        $this->assertSame('errorremoverecordtraces', $refusal->errorcode);
        $this->assertSame(2, $refusal->a->selfpositions);
        $this->assertSame(0, $refusal->a->evaluations);
        $this->assertStringContainsString('2', get_string('errorremoverecordtraces', 'tool_vae', $refusal->a));
        try {
            $remover->remove('RNCP100');
            $this->fail('The removal was not refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorremoverecordtraces', $e->errorcode);
        }
        $this->assertSame(1, $DB->count_records('tool_vae_source', ['fichecode' => 'RNCP100']));

        $DB->delete_records(selfpos_manager::TABLE);
        $this->assertNull($remover->refusal('RNCP100'), 'Without the lines, nothing keeps the row.');
    }
}
