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

use tool_vae\local\access;

/**
 * Tests for the access checks.
 *
 * THESE ARE THE REGRESSION TESTS FOR A PRIVILEGE ESCALATION found in adversarial review
 * before a line of code existed. The first version of the plugin declared viewown and
 * export at CONTEXT_USER with the "user" archetype - the Authenticated user role, which is
 * assigned at SYSTEM context. Since a user context is a direct child of the system context,
 * every authenticated user would have held those capabilities in every other user's
 * context, and dossier.php?userid=<someone else> would have worked.
 *
 * The fix is twofold: no default archetype on any capability touching the data, and
 * ownership proved by comparing identities in PHP. Both halves are asserted below - a test
 * that only checked the happy path would pass either way.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\access::class)]
final class access_test extends \advanced_testcase {
    /**
     * Grant a capability to a user on their own user context only.
     *
     * @param \stdClass $user
     * @param string $capability
     */
    private function grant_on_own_context(\stdClass $user, string $capability): void {
        $context = \context_user::instance($user->id);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * No capability of this plugin may be held by default.
     *
     * If an archetype ever reappears in db/access.php, this fails - which is the whole
     * point. A site administrator is excluded from the check because they bypass every
     * capability test, so asserting anything with an admin account proves nothing.
     */
    public function test_a_plain_authenticated_user_holds_nothing_by_default(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertFalse(is_siteadmin(), 'This test is meaningless for a site administrator.');

        // The export capability was in this list until 28 August 2026, when it was removed from
        // db/access.php along with `managepassage`: both were declared ahead of the code that
        // reads them, and nothing read them. They come back with their lot, and this list with
        // them - has_capability() on an unknown capability raises a coding exception, so the
        // omission cannot go unnoticed.
        foreach (['declare', 'attest', 'viewown', 'viewassigned'] as $capability) {
            $this->assertFalse(
                has_capability("tool/vae:$capability", \context_user::instance($user->id)),
                "tool/vae:$capability is granted by default on one's own context"
            );
            $this->assertFalse(
                has_capability("tool/vae:$capability", \context_user::instance($other->id)),
                "tool/vae:$capability is granted by default on someone else's context"
            );
        }

        $this->assertFalse(has_capability('tool/vae:viewany', \context_system::instance()));
    }

    /**
     * The escalation itself: viewown granted on one's own context must not reach further.
     */
    public function test_viewown_granted_on_own_context_does_not_reach_another_user(): void {
        $this->resetAfterTest();

        $candidate = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->grant_on_own_context($candidate, 'tool/vae:viewown');
        $this->setUser($candidate);

        $this->assertTrue(has_capability('tool/vae:viewown', \context_user::instance($candidate->id)));
        $this->assertFalse(has_capability('tool/vae:viewown', \context_user::instance($other->id)));
    }

    public function test_a_candidate_may_read_their_own_dossier(): void {
        $this->resetAfterTest();

        $candidate = $this->getDataGenerator()->create_user();
        $this->grant_on_own_context($candidate, 'tool/vae:viewown');
        $this->setUser($candidate);

        $context = access::require_can_view((int) $candidate->id);

        $this->assertInstanceOf(\context_user::class, $context);
        $this->assertEquals($candidate->id, $context->instanceid);
    }

    /**
     * Reading someone else's dossier requires viewany at system level, not viewown.
     *
     * Without the identity comparison in access::require_can_view(), holding viewown would
     * be enough here - which is exactly the defect.
     */
    public function test_viewown_is_not_enough_to_read_someone_elses_dossier(): void {
        $this->resetAfterTest();

        $candidate = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->grant_on_own_context($candidate, 'tool/vae:viewown');
        $this->setUser($candidate);

        $this->expectException(\required_capability_exception::class);
        access::require_can_view((int) $other->id);
    }

    public function test_viewany_allows_reading_someone_elses_dossier(): void {
        $this->resetAfterTest();

        $reader = $this->getDataGenerator()->create_user();
        $candidate = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $reader->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($reader);

        $context = access::require_can_view((int) $candidate->id);

        $this->assertEquals($candidate->id, $context->instanceid);
    }

    /**
     * A declaration is always first person.
     *
     * Declaring for a third party would require moodle/competency:userevidencemanage in
     * that person's user context - a right this plugin never asks anyone to hand out.
     */
    public function test_a_declaration_cannot_be_made_on_behalf_of_someone_else(): void {
        $this->resetAfterTest();

        $candidate = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->grant_on_own_context($candidate, 'tool/vae:declare');
        $this->setUser($candidate);

        $this->expectException(\moodle_exception::class);
        access::require_can_declare((int) $other->id);
    }

    public function test_a_candidate_may_declare_for_themselves(): void {
        $this->resetAfterTest();

        $candidate = $this->getDataGenerator()->create_user();
        $this->grant_on_own_context($candidate, 'tool/vae:declare');
        $this->setUser($candidate);

        $context = access::require_can_declare((int) $candidate->id);

        $this->assertEquals($candidate->id, $context->instanceid);
    }

    /**
     * Create the role a manager holds over the candidates they follow.
     *
     * @return int The role id.
     */
    private function create_follower_role(): int {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        set_role_contextlevels($roleid, [CONTEXT_USER]);

        return $roleid;
    }

    /**
     * Make a manager follow a candidate, the way tool_cohortroles does it: a role assigned in
     * the candidate's own user context.
     *
     * @param \stdClass $manager
     * @param \stdClass $candidate
     * @param int $roleid
     */
    private function follow(\stdClass $manager, \stdClass $candidate, int $roleid): void {
        role_assign($roleid, $manager->id, \context_user::instance($candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Lot 7 bis, the point of it: a manager reads the dossier of a candidate they follow,
     * without holding viewany - without reading everyone.
     */
    public function test_a_manager_may_read_the_dossier_of_a_candidate_they_follow(): void {
        $this->resetAfterTest();

        $manager = $this->getDataGenerator()->create_user();
        $candidate = $this->getDataGenerator()->create_user();
        $this->follow($manager, $candidate, $this->create_follower_role());
        $this->setUser($manager);

        $this->assertFalse(has_capability('tool/vae:viewany', \context_system::instance()));
        $this->assertTrue(access::follows((int) $candidate->id));
        $this->assertTrue(access::can_view((int) $candidate->id));

        $context = access::require_can_view((int) $candidate->id);
        $this->assertEquals($candidate->id, $context->instanceid);
    }

    /**
     * Following one candidate opens that dossier, and no other.
     */
    public function test_following_a_candidate_does_not_open_another_dossier(): void {
        $this->resetAfterTest();

        $manager = $this->getDataGenerator()->create_user();
        $candidate = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $this->follow($manager, $candidate, $this->create_follower_role());
        $this->setUser($manager);

        $this->assertFalse(access::follows((int) $stranger->id));
        $this->assertFalse(access::can_view((int) $stranger->id));

        $this->expectException(\required_capability_exception::class);
        access::require_can_view((int) $stranger->id);
    }

    /**
     * The guard in access::follows(): viewassigned assigned at system level would reach every
     * user context on the site. It must open nothing at all, rather than everything.
     *
     * Remove the guard and this test fails on the first assertion - the stranger's dossier
     * becomes readable.
     */
    public function test_viewassigned_held_at_system_level_opens_nothing(): void {
        $this->resetAfterTest();

        $misconfigured = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $misconfigured->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($misconfigured);

        // The trap is real: the capability is inherited by the stranger's context.
        $this->assertTrue(has_capability('tool/vae:viewassigned', \context_user::instance($stranger->id)));

        $this->assertFalse(access::follows((int) $stranger->id));
        $this->assertFalse(access::can_view((int) $stranger->id));
        $this->assertSame([], access::followed_candidates());
    }

    /**
     * Nobody follows themselves: one's own dossier is read with viewown, nothing else.
     */
    public function test_nobody_follows_themselves(): void {
        $this->resetAfterTest();

        $manager = $this->getDataGenerator()->create_user();
        $this->follow($manager, $manager, $this->create_follower_role());
        $this->setUser($manager);

        $this->assertFalse(access::follows((int) $manager->id));
        $this->assertSame([], access::followed_candidates());
    }

    /**
     * The list behind "My candidates": exactly the candidates followed, and a role held in a
     * user context without the capability does not count.
     */
    public function test_followed_candidates_lists_exactly_those_followed(): void {
        $this->resetAfterTest();

        $manager = $this->getDataGenerator()->create_user();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $followerrole = $this->create_follower_role();
        $this->follow($manager, $first, $followerrole);
        $this->follow($manager, $second, $followerrole);

        // A role in a user context that carries nothing of this plugin.
        $emptyrole = $this->getDataGenerator()->create_role();
        set_role_contextlevels($emptyrole, [CONTEXT_USER]);
        $this->follow($manager, $other, $emptyrole);

        $this->setUser($manager);

        $expected = [(int) $first->id, (int) $second->id];
        sort($expected);
        $this->assertSame($expected, access::followed_candidates());
    }

    /**
     * The other direction, for the notifications of lot 7 ter: from a candidate to the people
     * who follow them. The guard against viewassigned held at system level holds here exactly
     * as it holds on the dossier page - remove it from follows() and the misconfigured reader
     * appears in the list.
     */
    public function test_followers_are_exactly_those_who_follow(): void {
        $this->resetAfterTest();

        $candidate = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $misconfigured = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $followerrole = $this->create_follower_role();
        $this->follow($manager, $candidate, $followerrole);

        // Holds viewassigned at system level, AND a role in the candidate's context: found among
        // the assignments, then refused by the guard.
        $sitewide = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $sitewide, \context_system::instance()->id, true);
        role_assign($sitewide, $misconfigured->id, \context_system::instance()->id);
        $this->follow($misconfigured, $candidate, $followerrole);

        // A role in the candidate's context that carries nothing of this plugin.
        $emptyrole = $this->getDataGenerator()->create_role();
        set_role_contextlevels($emptyrole, [CONTEXT_USER]);
        $this->follow($other, $candidate, $emptyrole);

        // The current user is nobody in particular: followers() must not depend on who asks.
        $this->setUser($candidate);

        $this->assertSame([(int) $manager->id], access::followers((int) $candidate->id));
        $this->assertTrue(access::follows((int) $candidate->id, (int) $manager->id));
        $this->assertFalse(access::follows((int) $candidate->id, (int) $misconfigured->id));
    }

    /**
     * End to end, with the core tool the administrator will actually use: tool_cohortroles
     * links a manager to every member of a cohort, and to them only.
     */
    public function test_cohort_roles_link_a_manager_to_every_member_of_a_cohort(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $manager = $this->getDataGenerator()->create_user();
        $member = $this->getDataGenerator()->create_user();
        $outsider = $this->getDataGenerator()->create_user();

        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $member->id);

        \tool_cohortroles\api::create_cohort_role_assignment((object) [
            'userid' => $manager->id,
            'roleid' => $this->create_follower_role(),
            'cohortid' => $cohort->id,
        ]);
        \tool_cohortroles\api::sync_all_cohort_roles();
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($manager);

        $this->assertTrue(access::can_view((int) $member->id));
        $this->assertFalse(access::can_view((int) $outsider->id));
        $this->assertSame([(int) $member->id], access::followed_candidates());
    }

    /**
     * Grant capabilities to a user through a role assigned at system level.
     *
     * @param \stdClass $user
     * @param string[] $capabilities
     */
    private function grant_at_system_level(\stdClass $user, array $capabilities): void {
        $system = \context_system::instance();
        $roleid = $this->getDataGenerator()->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $system->id, true);
        }
        role_assign($roleid, $user->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Lot 8 ter: attest held at system level is inherited by every user context, exactly as
     * viewassigned would be. Without reading the dossier it signs nothing.
     *
     * Drop the reading check from access::can_attest() and the first assertion after the trap
     * fails - the stranger's claims become signable.
     */
    public function test_attest_held_at_system_level_attests_nothing_without_reading(): void {
        $this->resetAfterTest();

        $signer = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $this->grant_at_system_level($signer, ['tool/vae:attest']);
        $this->setUser($signer);

        // The trap is real: the capability is inherited by the stranger's context.
        $this->assertTrue(has_capability('tool/vae:attest', \context_user::instance($stranger->id)));

        $this->assertFalse(access::can_attest((int) $stranger->id));
        $this->assertFalse(access::can_view((int) $stranger->id));
    }

    /**
     * A manager attests for the candidates they follow, and for them only.
     */
    public function test_a_manager_may_attest_for_a_candidate_they_follow(): void {
        $this->resetAfterTest();

        $manager = $this->getDataGenerator()->create_user();
        $candidate = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $this->grant_at_system_level($manager, ['tool/vae:attest']);
        $this->follow($manager, $candidate, $this->create_follower_role());
        $this->setUser($manager);

        $this->assertTrue(access::can_attest((int) $candidate->id));
        $this->assertFalse(access::can_attest((int) $stranger->id));
    }

    /**
     * Reading every dossier and holding attest at system level: the direction may sign any
     * dossier. Reading alone signs nothing - reading is not attesting.
     */
    public function test_viewany_and_attest_at_system_level_may_attest_for_anyone(): void {
        $this->resetAfterTest();

        $director = $this->getDataGenerator()->create_user();
        $auditor = $this->getDataGenerator()->create_user();
        $candidate = $this->getDataGenerator()->create_user();
        $this->grant_at_system_level($director, ['tool/vae:viewany', 'tool/vae:attest']);
        $this->grant_at_system_level($auditor, ['tool/vae:viewany']);

        $this->setUser($director);
        $this->assertTrue(access::can_attest((int) $candidate->id));

        $this->setUser($auditor);
        $this->assertTrue(access::can_view((int) $candidate->id));
        $this->assertFalse(access::can_attest((int) $candidate->id));
    }

    /**
     * Naming a reader asks the question about that reader, whoever is logged in. This is how a
     * notification chooses its recipients, and a check that quietly used the current user would
     * send them to the wrong people.
     */
    public function test_can_attest_asks_about_the_reader_named_not_the_current_user(): void {
        $this->resetAfterTest();

        $manager = $this->getDataGenerator()->create_user();
        $signer = $this->getDataGenerator()->create_user();
        $candidate = $this->getDataGenerator()->create_user();
        $this->grant_at_system_level($manager, ['tool/vae:attest']);
        $this->grant_at_system_level($signer, ['tool/vae:attest']);
        $this->follow($manager, $candidate, $this->create_follower_role());

        $this->setUser($manager);
        $this->assertFalse(access::can_view((int) $candidate->id, (int) $signer->id));
        $this->assertFalse(access::can_attest((int) $candidate->id, (int) $signer->id));

        $this->setUser($signer);
        $this->assertTrue(access::can_view((int) $candidate->id, (int) $manager->id));
        $this->assertTrue(access::can_attest((int) $candidate->id, (int) $manager->id));
    }

    /**
     * A user id that belongs to no account.
     *
     * @return int
     */
    private function missing_userid(): int {
        global $DB;

        return (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}') + 1;
    }

    /**
     * M-3 at the root: an id that is no account is refused whoever asks, and never with a
     * database error.
     *
     * The director below reads, signs and exports every dossier, so no capability says no here:
     * the account lookup has to. Read the candidate's context with MUST_EXIST again in access
     * and this test fails on a dml exception.
     */
    public function test_an_id_that_is_no_account_is_refused_even_to_the_direction(): void {
        $this->resetAfterTest();

        $director = $this->getDataGenerator()->create_user();
        $this->grant_at_system_level($director, [
            'tool/vae:viewany',
            'tool/vae:attest',
            'tool/vae:export',
        ]);
        $this->setUser($director);
        $missing = $this->missing_userid();

        $this->assertFalse(access::can_view($missing));
        $this->assertFalse(access::follows($missing));
        $this->assertFalse(access::can_attest($missing));
        $this->assertFalse(access::can_export($missing));

        foreach (['require_can_view', 'require_can_export'] as $method) {
            try {
                access::$method($missing);
                $this->fail("access::$method() opened the dossier of an id that is no account.");
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
    }

    /**
     * And the refusal says nothing more: an id that is no account is refused word for word as a
     * stranger's dossier is, link included. A refusal pointing at the candidate's context - their
     * profile - would still tell which ids are accounts.
     */
    public function test_an_id_that_is_no_account_is_refused_as_a_strangers_dossier(): void {
        $this->resetAfterTest();

        $reader = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $missing = $this->missing_userid();
        $this->setUser($reader);

        foreach (['require_can_view', 'require_can_export'] as $method) {
            $refusals = [];
            foreach ([(int) $stranger->id, $missing] as $id) {
                try {
                    access::$method($id);
                    $this->fail("access::$method() let a reader with no right through.");
                } catch (\required_capability_exception $e) {
                    $refusals[] = $e->getMessage() . ' ' . $e->link;
                }
            }
            $this->assertSame($refusals[0], $refusals[1], "access::$method() tells an account from an id");
        }
    }
}
