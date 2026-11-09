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
use core_privacy\local\request\userlist;
use tool_vae\local\identity_map;
use tool_vae\local\purge;
use tool_vae\privacy\provider;

/**
 * The positionings of the structure and the right to be forgotten (T-90, lot 3).
 *
 * Free text of a third party about the candidate. The candidate is its destination: it is exported to them, and erasing them
 * removes it all. Erasing the AUTHOR keeps the line - it is about the candidate, who has the right to read it - and removes who
 * wrote it, by account and by the ULID of a line restored from an archive.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\privacy\provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\purge::class)]
final class positioning_privacy_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass The manager who evaluated. */
    private $manager;

    /** @var \stdClass The member of the direction who certified. */
    private $direction;

    /** @var \stdClass Wrote nothing. */
    private $stranger;

    /** @var string The ULID this site gave the manager. */
    private $managerulid;

    /**
     * A candidate with two lines of evaluation and two of certification, written by two people, one of them restored from an
     * archive and named by ULID.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $this->manager = $generator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->direction = $generator->create_user();
        $this->stranger = $generator->create_user();
        $this->managerulid = identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->manager->id);

        $now = time();
        $common = fn(string $status, string $rank, string $reason, array $more = []) => (object) ($more + [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => $this->candidate->id,
            'fichecode' => 'RNCP100',
            'ficherang' => $rank,
            'status' => $status,
            'reason' => $reason,
            'reasonhash' => hash('sha256', $reason),
            'authorid' => null,
            'authoruuid' => null,
            'importedat' => null,
            'timecreated' => $now,
        ]);
        $DB->insert_record('tool_vae_eval', $common('inproof', 'C01', 'Deux situations vues.', ['authorid' => $this->manager->id]));
        $DB->insert_record('tool_vae_eval', $common('sufficient', 'C01', 'Trois situations vues.', [
            'authoruuid' => $this->managerulid, 'importedat' => $now,
        ]));
        $DB->insert_record('tool_vae_certif', $common('certified', 'BC01', 'La structure se positionne.', [
            'authorid' => $this->direction->id, 'decisiondate' => '2026-11-04', 'alertreason' => 'Une exception motivee.',
            'validuntil' => '2027-11-04',
        ]));
        $DB->insert_record('tool_vae_certif', $common('withdrawn', 'BC01', 'Retrait.', [
            'authorid' => $this->manager->id, 'decisiondate' => '2026-11-05',
        ]));
    }

    /**
     * What a request made by this person would carry for the user context of the candidate.
     *
     * @param \stdClass $user
     * @return approved_contextlist
     */
    private function approval(\stdClass $user): approved_contextlist {
        return new approved_contextlist(\core_user::get_user($user->id), 'tool_vae', [
            \context_user::instance($this->candidate->id)->id,
        ]);
    }

    public function test_the_two_tables_are_declared(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('tool_vae'));
        $names = array_map(fn($item) => $item->get_name(), $collection->get_collection());

        $this->assertContains('tool_vae_eval', $names);
        $this->assertContains('tool_vae_certif', $names);
    }

    public function test_the_candidate_and_the_authors_are_pointed_at_the_dossier(): void {
        $context = \context_user::instance($this->candidate->id)->id;

        foreach ([$this->candidate, $this->manager, $this->direction] as $user) {
            $this->assertContains(
                $context,
                array_map('intval', provider::get_contexts_for_userid((int) $user->id)->get_contextids()),
                fullname($user)
            );
        }
        $this->assertNotContains(
            $context,
            array_map('intval', provider::get_contexts_for_userid((int) $this->stranger->id)->get_contextids())
        );

        $userlist = new userlist(\context_user::instance($this->candidate->id), 'tool_vae');
        provider::get_users_in_context($userlist);
        $ids = array_map('intval', $userlist->get_userids());
        foreach ([$this->candidate, $this->manager, $this->direction] as $user) {
            $this->assertContains((int) $user->id, $ids, fullname($user));
        }
        $this->assertNotContains((int) $this->stranger->id, $ids);
    }

    public function test_the_candidate_export_holds_every_line_and_an_author_export_holds_theirs_alone(): void {
        provider::export_user_data($this->approval($this->candidate));
        $writer = \core_privacy\local\request\writer::with_context(\context_user::instance($this->candidate->id));
        $path = [get_string('pluginname', 'tool_vae'), get_string('privacy:path:positionings', 'tool_vae')];
        $data = $writer->get_data($path);

        $this->assertCount(2, $data->evaluations);
        $this->assertCount(2, $data->certifications);
        $reasons = array_column($data->evaluations, 'reason');
        $this->assertContains('Deux situations vues.', $reasons);
        $certified = array_values(array_filter($data->certifications, fn($line) => $line['status'] === 'certified'))[0];
        $this->assertSame('2026-11-04', $certified['decision']);
        $this->assertSame('Une exception motivee.', $certified['alert']);
        $this->assertSame('2027-11-04', $certified['validuntil']);
        $withdrawn = array_values(array_filter($data->certifications, fn($line) => $line['status'] === 'withdrawn'))[0];
        $this->assertNull($withdrawn['validuntil'], 'A line without a validity says so.');
        $this->assertNotEmpty($certified['written']);
        $this->assertNotEmpty($certified['sha256']);

        \core_privacy\local\request\writer::reset();
        provider::export_user_data($this->approval($this->direction));
        $writer = \core_privacy\local\request\writer::with_context(\context_user::instance($this->candidate->id));
        $theirs = $writer->get_data($path);
        $this->assertCount(1, $theirs->certifications, 'The certification they wrote.');
        $this->assertObjectNotHasProperty('evaluations', $theirs, 'They wrote no evaluation.');
    }

    public function test_erasing_an_author_keeps_the_lines_and_removes_who_wrote_them(): void {
        global $DB;

        provider::delete_data_for_user($this->approval($this->manager));

        $this->assertSame(2, $DB->count_records('tool_vae_eval'));
        $this->assertSame(2, $DB->count_records('tool_vae_certif'));
        $this->assertSame(0, $DB->count_records_select('tool_vae_eval', 'authorid IS NOT NULL'));
        $this->assertSame(
            0,
            $DB->count_records_select('tool_vae_eval', 'authoruuid IS NOT NULL'),
            'The ULID of a restored line goes too.'
        );
        $this->assertSame(
            1,
            $DB->count_records_select('tool_vae_certif', 'authorid IS NOT NULL'),
            'The other author is untouched.'
        );
        $this->assertEqualsCanonicalizing(
            ['Deux situations vues.', 'Trois situations vues.'],
            $DB->get_fieldset_select('tool_vae_eval', 'reason', '1 = 1')
        );
    }

    public function test_the_bulk_path_strips_an_author_the_same_way(): void {
        global $DB;
        $userlist = new approved_userlist(\context_user::instance($this->candidate->id), 'tool_vae', [$this->direction->id]);

        provider::delete_data_for_users($userlist);

        $this->assertSame(0, $DB->count_records_select('tool_vae_certif', 'authorid = :a', ['a' => $this->direction->id]));
        $this->assertSame(2, $DB->count_records('tool_vae_certif'));
    }

    public function test_erasing_the_candidate_removes_every_line_about_them(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_user();
        $DB->insert_record('tool_vae_eval', (object) [
            'uuid' => identity_map::generate_ulid(), 'candidateid' => $other->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01',
            'status' => 'inproof', 'reason' => 'Une autre personne.', 'reasonhash' => hash('sha256', 'Une autre personne.'),
            'authorid' => $this->manager->id, 'authoruuid' => null, 'importedat' => null, 'timecreated' => time(),
        ]);

        provider::delete_data_for_user($this->approval($this->candidate));

        $this->assertSame(0, $DB->count_records('tool_vae_certif'));
        $this->assertSame(1, $DB->count_records('tool_vae_eval'), 'Only the other person\'s line is left.');
        $this->assertSame($other->id, $DB->get_field('tool_vae_eval', 'candidateid', []));
    }

    public function test_erasing_the_author_in_their_own_context_removes_their_ulid_from_other_dossiers(): void {
        global $DB;
        $own = new approved_contextlist(\core_user::get_user($this->manager->id), 'tool_vae', [
            \context_user::instance($this->manager->id)->id,
        ]);

        provider::delete_data_for_user($own);

        $this->assertSame(0, $DB->count_records_select('tool_vae_eval', 'authoruuid IS NOT NULL'));
        $this->assertSame(2, $DB->count_records('tool_vae_eval'), 'The lines of the candidate stay.');
    }

    public function test_the_scheduled_catch_up_removes_the_lines_of_an_account_that_is_gone(): void {
        global $DB;
        delete_user($this->candidate);
        // The observer may already have done it; the catch-up must leave nothing either way.
        purge::orphan_sections();

        $this->assertSame(0, $DB->count_records('tool_vae_eval'));
        $this->assertSame(0, $DB->count_records('tool_vae_certif'));
    }

    public function test_purging_a_dossier_removes_its_lines(): void {
        global $DB;

        purge::dossier((int) $this->candidate->id);

        $this->assertSame(0, $DB->count_records('tool_vae_eval'));
        $this->assertSame(0, $DB->count_records('tool_vae_certif'));
    }

    public function test_an_author_known_only_by_the_ulid_of_a_restored_line_is_found_served_and_stripped(): void {
        global $DB;
        $second = $this->getDataGenerator()->create_user();
        $ulid = identity_map::uuid_for(identity_map::TYPE_USER, (int) $second->id);
        $DB->insert_record('tool_vae_eval', (object) [
            'uuid' => identity_map::generate_ulid(), 'candidateid' => $this->candidate->id, 'fichecode' => 'RNCP100',
            'ficherang' => 'C09', 'status' => 'inproof', 'reason' => 'Ligne restauree.', 'reasonhash' => hash('sha256', 'x'),
            'authorid' => null, 'authoruuid' => $ulid, 'importedat' => time(), 'timecreated' => time(),
        ]);
        $context = \context_user::instance($this->candidate->id)->id;

        $this->assertContains(
            $context,
            array_map('intval', provider::get_contexts_for_userid((int) $second->id)->get_contextids())
        );
        $userlist = new userlist(\context_user::instance($this->candidate->id), 'tool_vae');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $second->id, array_map('intval', $userlist->get_userids()));

        provider::export_user_data($this->approval($second));
        $writer = \core_privacy\local\request\writer::with_context(\context_user::instance($this->candidate->id));
        $data = $writer->get_data([get_string('pluginname', 'tool_vae'), get_string('privacy:path:positionings', 'tool_vae')]);
        $this->assertSame(['Ligne restauree.'], array_column($data->evaluations, 'reason'));

        provider::delete_data_for_user($this->approval($second));
        $this->assertSame(0, $DB->count_records('tool_vae_eval', ['authoruuid' => $ulid]));
        $this->assertSame(1, $DB->count_records('tool_vae_eval', ['ficherang' => 'C09']), 'The line stays, the author goes.');
    }
}
