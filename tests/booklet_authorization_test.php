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

use tool_vae\event\booklet_authorization_changed;
use tool_vae\local\booklet_authorization;

/**
 * The authorization the direction gives a manager to produce the archive of the structure.
 *
 * WHAT IS ASSERTED. That the direction (tool/vae:issuestructure and tool/vae:viewany together) authorizes a manager who follows
 * at least one candidate, once, and takes the authorization back; that nobody else does, nobody authorizes themself, an account
 * that follows nobody is refused, and a decision that changes nothing is refused; that a line is added and never changed; and
 * that each decision is in the log.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\booklet_authorization::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\booklet_authorization_changed::class)]
final class booklet_authorization_test extends \advanced_testcase {
    /** @var \stdClass A manager who follows one candidate. */
    private $manager;

    /** @var \stdClass A second manager who follows one candidate. */
    private $other;

    /** @var \stdClass Holds an assignment in a user context, and follows nobody. */
    private $outsider;

    /** @var \stdClass Holds issuestructure and viewany at the site. */
    private $direction;

    /** @var \stdClass Holds viewany and not issuestructure. */
    private $reader;

    /** @var \stdClass Holds issuestructure and viewany, and follows a candidate. */
    private $both;

    /**
     * The people: a direction, a reader, two managers each following a candidate, an outsider, and one who is both.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $this->manager = $generator->create_user();
        $this->other = $generator->create_user();
        $this->outsider = $generator->create_user();
        $this->direction = $generator->create_user();
        $this->reader = $generator->create_user();
        $this->both = $generator->create_user();

        $follow = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        foreach ([$this->manager, $this->other, $this->both] as $person) {
            $candidate = $generator->create_user();
            role_assign($follow, $person->id, \context_user::instance($candidate->id)->id);
        }

        $director = $generator->create_role();
        foreach (['tool/vae:viewany', 'tool/vae:issuestructure'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $director, $system, true);
        }
        role_assign($director, $this->direction->id, $system);
        role_assign($director, $this->both->id, $system);

        $viewer = $generator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $viewer, $system, true);
        role_assign($viewer, $this->reader->id, $system);
        accesslib_clear_all_caches_for_unit_testing();
    }

    public function test_grant_then_stands(): void {
        $this->setUser($this->direction);
        $this->assertFalse(booklet_authorization::stands((int) $this->manager->id));

        $row = booklet_authorization::grant((int) $this->manager->id);

        $this->assertTrue(booklet_authorization::stands((int) $this->manager->id));
        $this->assertSame(booklet_authorization::GRANTED, $row->status);
        $this->assertEquals($this->direction->id, $row->grantedby);
        $this->assertFalse(booklet_authorization::stands((int) $this->other->id), 'It is the manager\'s, and nobody else\'s.');
    }

    public function test_revoke_ends_it(): void {
        $this->setUser($this->direction);
        booklet_authorization::grant((int) $this->manager->id);

        $row = booklet_authorization::revoke((int) $this->manager->id);

        $this->assertFalse(booklet_authorization::stands((int) $this->manager->id));
        $this->assertSame(booklet_authorization::REVOKED, $row->status);
    }

    public function test_it_can_be_granted_again_after_it_was_taken_back(): void {
        $this->setUser($this->direction);
        booklet_authorization::grant((int) $this->manager->id);
        booklet_authorization::revoke((int) $this->manager->id);

        booklet_authorization::grant((int) $this->manager->id);

        $this->assertTrue(booklet_authorization::stands((int) $this->manager->id));
    }

    public function test_an_account_that_follows_nobody_is_refused(): void {
        $this->setUser($this->direction);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorbookletmanager', 'tool_vae'));
        booklet_authorization::grant((int) $this->outsider->id);
    }

    public function test_nobody_authorizes_themself(): void {
        $this->setUser($this->both);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorbookletself', 'tool_vae'));
        booklet_authorization::grant((int) $this->both->id);
    }

    public function test_without_the_capability_it_is_refused_and_nothing_is_written(): void {
        global $DB;
        $this->setUser($this->reader);

        try {
            booklet_authorization::grant((int) $this->manager->id);
            $this->fail('A reader without issuestructure authorized a manager.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(get_string('errorbookletdenied', 'tool_vae'), $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records(booklet_authorization::TABLE));
    }

    public function test_the_manager_cannot_authorize_themself_nor_anyone(): void {
        $this->setUser($this->manager);

        $this->expectException(\moodle_exception::class);
        booklet_authorization::grant((int) $this->other->id);
    }

    public function test_a_decision_that_changes_nothing_is_refused(): void {
        $this->setUser($this->direction);

        try {
            booklet_authorization::revoke((int) $this->manager->id);
            $this->fail('Nothing stood, and revoking was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString(get_string('errorbookletnothing', 'tool_vae'), $e->getMessage());
        }
        booklet_authorization::grant((int) $this->manager->id);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorbookletnothing', 'tool_vae'));
        booklet_authorization::grant((int) $this->manager->id);
    }

    public function test_lines_are_added_and_never_changed(): void {
        global $DB;
        $this->setUser($this->direction);
        $first = booklet_authorization::grant((int) $this->manager->id);
        $before = $DB->get_record(booklet_authorization::TABLE, ['id' => $first->id]);

        booklet_authorization::revoke((int) $this->manager->id);
        booklet_authorization::grant((int) $this->manager->id);

        $this->assertEquals($before, $DB->get_record(booklet_authorization::TABLE, ['id' => $first->id]));
        $lines = booklet_authorization::lines((int) $this->manager->id);
        $this->assertSame(
            [booklet_authorization::GRANTED, booklet_authorization::REVOKED, booklet_authorization::GRANTED],
            array_column($lines, 'status')
        );
    }

    public function test_each_decision_is_in_the_log(): void {
        $this->setUser($this->direction);
        $sink = $this->redirectEvents();

        booklet_authorization::grant((int) $this->manager->id);
        booklet_authorization::revoke((int) $this->manager->id);

        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof booklet_authorization_changed
        ));
        $this->assertCount(2, $events);
        // The state alone in other (T15, point Y): the manager is relateduserid, where the privacy provider of the log finds
        // them, so that their erasure reaches this line; the direction is userid.
        $this->assertSame(['status' => 'granted'], $events[0]->other);
        $this->assertSame(['status' => 'revoked'], $events[1]->other);
        $this->assertEquals($this->manager->id, $events[0]->relateduserid);
        $this->assertEquals($this->manager->id, $events[1]->relateduserid);
        $this->assertEquals($this->direction->id, $events[0]->userid);
        $this->assertSame(\context_system::instance()->id, (int) $events[0]->contextid);
        $description = $events[0]->get_description();
        $this->assertStringContainsString("'{$this->direction->id}'", $description);
        $this->assertStringContainsString("'{$this->manager->id}'", $description);
        $this->assertStringContainsString("'granted'", $description);
        $this->assertStringNotContainsString(fullname($this->manager), $description);
        $this->assertFalse(booklet_authorization_changed::get_other_mapping(), 'Nothing in other names a record.');
    }

    public function test_the_event_refuses_an_account_id_in_other_and_a_line_without_the_manager(): void {
        $this->setUser($this->direction);
        $context = \context_system::instance();
        $forged = [
            'an account id in other' => [
                'context' => $context, 'objectid' => 1, 'relateduserid' => (int) $this->manager->id,
                'other' => ['managerid' => (int) $this->manager->id, 'status' => 'granted'],
            ],
            'no relateduserid' => ['context' => $context, 'objectid' => 1, 'other' => ['status' => 'granted']],
            'an unknown status' => [
                'context' => $context, 'objectid' => 1, 'relateduserid' => (int) $this->manager->id,
                'other' => ['status' => 'maybe'],
            ],
        ];
        foreach ($forged as $what => $data) {
            try {
                booklet_authorization_changed::create($data);
                $this->fail($what . ' is a coding error.');
            } catch (\coding_exception $e) {
                $this->assertNotSame('', $e->getMessage(), $what);
            }
        }
    }

    public function test_an_account_deleted_since_cannot_be_authorized(): void {
        $this->setUser($this->direction);
        delete_user($this->manager);

        $this->expectException(\moodle_exception::class);
        booklet_authorization::grant((int) $this->manager->id);
    }

    public function test_a_standing_authorization_stays_listed_and_revocable_when_the_manager_follows_nobody_any_more(): void {
        $this->setUser($this->direction);
        booklet_authorization::grant((int) $this->manager->id);
        $this->assertContains((int) $this->manager->id, array_map(fn($u) => (int) $u->id, booklet_authorization::managers()));

        // The roles that made the manager follow someone are gone: nothing to follow any more.
        $this->unfollow($this->manager);

        $this->assertFalse(booklet_authorization::follows_anyone((int) $this->manager->id));
        $listed = array_map(fn($u) => (int) $u->id, booklet_authorization::managers());
        $this->assertContains((int) $this->manager->id, $listed, 'It must stay in sight to be taken back.');
        booklet_authorization::revoke((int) $this->manager->id);
        $this->assertFalse(booklet_authorization::stands((int) $this->manager->id));
    }

    public function test_an_account_that_follows_nobody_and_never_had_an_authorization_is_not_listed(): void {
        $this->setUser($this->direction);

        $listed = array_map(fn($u) => (int) $u->id, booklet_authorization::managers());

        $this->assertNotContains((int) $this->outsider->id, $listed);
        $this->assertNotContains((int) $this->direction->id, $listed, 'Never the current user.');
    }

    /**
     * Take every follow assignment away from an account.
     *
     * @param \stdClass $user
     */
    private function unfollow(\stdClass $user): void {
        global $DB;

        $DB->delete_records('role_assignments', ['userid' => $user->id]);
        accesslib_clear_all_caches_for_unit_testing();
    }
}
