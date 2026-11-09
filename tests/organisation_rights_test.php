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

use tool_vae\local\organisation;

/**
 * The pathway of a candidate is read by the candidate alone (T-90, conception § 4.3).
 *
 * THE CLASS CARRIES THE RIGHT, NOT THE PAGE. A page that filtered what it draws would leave the rule untestable here
 * and the structure readable by any caller; the refusal is in organisation::for_candidate() itself, so that a manager
 * who follows the candidate, the direction, and the site administrator - who passes every capability check - are all
 * refused by the identity test before any right is asked. The decision of 12 October 2026: read by anybody else, the
 * same counts become a gauge on a person.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\organisation::class)]
final class organisation_rights_test extends \advanced_testcase {
    /**
     * Grant a capability to a user on a user context.
     *
     * @param \stdClass $user The holder.
     * @param string $capability
     * @param int $contextuserid Whose context; the holder's own when 0.
     */
    private function grant(\stdClass $user, string $capability, int $contextuserid = 0): void {
        $context = \context_user::instance($contextuserid ?: $user->id);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The candidate, with viewown, reads their own pathway.
     */
    public function test_the_candidate_reads_their_own_pathway(): void {
        $this->resetAfterTest();
        $candidate = $this->getDataGenerator()->create_user();
        $this->grant($candidate, 'tool/vae:viewown');
        $this->setUser($candidate);

        $pathway = organisation::for_candidate((int) $candidate->id);

        $this->assertSame([], $pathway->declared);
        $this->assertSame([], $pathway->planned);
    }

    /**
     * The site administrator passes every capability check and is refused all the same: the test is on identity.
     */
    public function test_for_candidate_refuses_anyone_but_the_candidate_admin_included(): void {
        $this->resetAfterTest();
        $candidate = $this->getDataGenerator()->create_user();
        $this->grant($candidate, 'tool/vae:viewown');
        $this->setAdminUser();

        $this->expectException(\required_capability_exception::class);
        organisation::for_candidate((int) $candidate->id);
    }

    /**
     * "Log in as" turns the administrator into the candidate for the session: the pathway stays closed to them.
     */
    public function test_for_candidate_refuses_an_administrator_logged_in_as_the_candidate(): void {
        $this->resetAfterTest();
        $candidate = $this->getDataGenerator()->create_user();
        $this->grant($candidate, 'tool/vae:viewown');
        $this->setAdminUser();
        \core\session\manager::loginas((int) $candidate->id, \context_system::instance());
        $this->assertSame((int) $candidate->id, (int) $GLOBALS['USER']->id);

        $this->expectException(\required_capability_exception::class);
        organisation::for_candidate((int) $candidate->id);
    }

    /**
     * A manager who follows the candidate reads the dossier, never the pathway.
     */
    public function test_for_candidate_refuses_a_follower(): void {
        $this->resetAfterTest();
        $candidate = $this->getDataGenerator()->create_user();
        $follower = $this->getDataGenerator()->create_user();
        $this->grant($candidate, 'tool/vae:viewown');
        $this->grant($follower, 'tool/vae:viewassigned', (int) $candidate->id);
        $this->setUser($follower);

        $this->expectException(\required_capability_exception::class);
        organisation::for_candidate((int) $candidate->id);
    }

    /**
     * The followed candidates are the reader's: nobody reads another person's list, the site administrator included.
     */
    public function test_followed_refuses_anyone_but_the_reader_admin_included(): void {
        $this->resetAfterTest();
        $manager = $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        $this->expectException(\required_capability_exception::class);
        organisation::followed((int) $manager->id);
    }

    /**
     * A reader who follows nobody has an empty table, not an error (conception § 5, test 3).
     */
    public function test_followed_of_a_reader_who_follows_nobody_is_empty_not_refused(): void {
        $this->resetAfterTest();
        $reader = $this->getDataGenerator()->create_user();
        $this->setUser($reader);

        $result = organisation::followed((int) $reader->id);

        $this->assertSame([], $result->candidates);
        $this->assertSame(['candidates', 'choices', 'chosen'], array_keys((array) $result));
    }

    /**
     * Whoever holds viewassigned site-wide reads every dossier and follows nobody: the table is empty, not an error and
     * not a list of everybody (the site administrator passes every check, so it is the case here).
     */
    public function test_followed_of_a_site_wide_holder_is_empty_not_everybody(): void {
        $this->resetAfterTest();
        $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        $result = organisation::followed((int) $GLOBALS['USER']->id);

        $this->assertSame([], $result->candidates);
    }

    /**
     * The tabs offered are the tabs the reader may open, and the same test serves the address.
     */
    public function test_tabs_follow_the_rights(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $candidate = $generator->create_user();
        $this->grant($candidate, 'tool/vae:viewown');
        $this->setUser($candidate);
        $this->assertSame(['mine'], organisation::tabs());

        $manager = $generator->create_user();
        $this->grant($manager, 'tool/vae:viewown');
        $this->grant($manager, 'tool/vae:viewassigned', (int) $candidate->id);
        $this->setUser($manager);
        $this->assertSame(['mine', 'followed'], organisation::tabs());

        $follower = $generator->create_user();
        $this->grant($follower, 'tool/vae:viewassigned', (int) $candidate->id);
        $this->setUser($follower);
        $this->assertSame(['followed'], organisation::tabs());

        $alone = $generator->create_user();
        $this->grant($alone, 'tool/vae:viewown');
        $this->setUser($alone);
        $this->assertSame(['mine'], organisation::tabs());

        $nothing = $generator->create_user();
        $this->setUser($nothing);
        $this->assertSame([], organisation::tabs());

        // The administrator follows nobody (the site-wide right is not a following): no table of followed candidates.
        $this->setAdminUser();
        $this->assertNotContains('followed', organisation::tabs());

        // Under "log in as" the pathway of the person is closed, and the tab with it.
        $this->setAdminUser();
        \core\session\manager::loginas((int) $candidate->id, \context_system::instance());
        $this->assertNotContains('mine', organisation::tabs());
    }

    /**
     * The address of a tab the reader may not open is refused with the right that opens it.
     */
    public function test_require_tab_refuses_an_unopenable_tab_with_the_right_capability(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $candidate = $generator->create_user();
        $this->grant($candidate, 'tool/vae:viewown');
        $manager = $generator->create_user();
        $this->grant($manager, 'tool/vae:viewassigned', (int) $candidate->id);

        $this->setUser($candidate);
        try {
            organisation::require_tab('followed');
            $this->fail('A candidate who follows nobody opened the followed tab.');
        } catch (\required_capability_exception $exception) {
            $this->assertSame(get_capability_string('tool/vae:viewassigned'), $exception->a);
        }

        $this->setUser($manager);
        organisation::require_tab('followed');
        try {
            organisation::require_tab('mine');
            $this->fail('A manager without viewown opened the pathway.');
        } catch (\required_capability_exception $exception) {
            $this->assertSame(get_capability_string('tool/vae:viewown'), $exception->a);
        }
    }

    /**
     * Give a user a capability everywhere, as a role of the site does.
     *
     * @param \stdClass $user The holder.
     * @param string $capability
     */
    private function grant_site_wide(\stdClass $user, string $capability): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The tab of the configuration is for who may view any dossier (T-90 lot 4), and opens next to the others.
     */
    public function test_the_site_tab_follows_viewany_and_comes_last(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $direction = $generator->create_user();
        $this->grant_site_wide($direction, 'tool/vae:viewany');
        $this->setUser($direction);
        $this->assertSame(['site'], organisation::tabs(), 'Viewany alone opens the configuration and nothing else.');
        $this->assertTrue(organisation::can_open_tab('site'));

        // The administrator passes every check and is the candidate of their own dossier: two tabs, no following.
        $this->setAdminUser();
        $this->assertSame(['mine', 'site'], organisation::tabs());

        $both = $generator->create_user();
        $this->grant($both, 'tool/vae:viewown');
        $this->grant_site_wide($both, 'tool/vae:viewany');
        $this->setUser($both);
        $this->assertSame(['mine', 'site'], organisation::tabs());

        $candidate = $generator->create_user();
        $this->grant($candidate, 'tool/vae:viewown');
        $manager = $generator->create_user();
        $this->grant($manager, 'tool/vae:viewassigned', (int) $candidate->id);
        $this->grant_site_wide($manager, 'tool/vae:viewany');
        $this->setUser($manager);
        $this->assertSame(['followed', 'site'], organisation::tabs());

        foreach ([$candidate, $generator->create_user()] as $user) {
            $this->setUser($user);
            $this->assertFalse(organisation::can_open_tab('site'));
            $this->assertNotContains('site', organisation::tabs());
        }
        $this->assertFalse(organisation::can_open_tab('anything else'));
    }

    /**
     * The address of the configuration is refused with the right that opens it.
     */
    public function test_require_tab_refuses_the_site_tab_without_viewany(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $candidate = $generator->create_user();
        $this->grant($candidate, 'tool/vae:viewown');
        $this->setUser($candidate);
        try {
            organisation::require_tab('site');
            $this->fail('A candidate opened the configuration.');
        } catch (\required_capability_exception $exception) {
            $this->assertSame(get_capability_string('tool/vae:viewany'), $exception->a);
        }

        $direction = $generator->create_user();
        $this->grant_site_wide($direction, 'tool/vae:viewany');
        $this->setUser($direction);
        organisation::require_tab('site');
        $this->setAdminUser();
        organisation::require_tab('site');
        $this->addToAssertionCount(1);
    }

    /**
     * The page opens on the configuration for who may read it and has declared nothing (decision of 4 November 2026).
     */
    public function test_the_default_tab_is_the_configuration_for_a_reader_who_declared_nothing(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();

        $this->setAdminUser();
        $this->assertSame('site', organisation::default_tab());

        $candidate = $generator->create_user();
        $this->grant($candidate, 'tool/vae:viewown');
        $this->setUser($candidate);
        $this->assertSame('mine', organisation::default_tab());

        $nothing = $generator->create_user();
        $this->setUser($nothing);
        $this->assertSame('mine', organisation::default_tab(), 'Nothing to open: the fallback of the page.');

        $manager = $generator->create_user();
        $this->grant($manager, 'tool/vae:viewassigned', (int) $candidate->id);
        $this->setUser($manager);
        $this->assertSame('followed', organisation::default_tab(), 'Without viewany nothing changes.');

        // Viewany and a declaration of one's own: the pathway first, as before.
        $both = $generator->create_user();
        $this->grant($both, 'tool/vae:viewown');
        $this->grant_site_wide($both, 'tool/vae:viewany');
        $this->setUser($both);
        $this->assertSame('site', organisation::default_tab());
        $this->grant($both, 'tool/vae:declare');
        $plugin = $generator->get_plugin_generator('tool_vae');
        $plugin->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'C01', 'shortname' => 'Aider au lever']);
        $plugin->create_declaration([
            'userid' => $both->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Une situation vécue.',
        ]);
        $this->setUser($both);
        $this->assertSame('mine', organisation::default_tab());
    }

    /**
     * Identity is not enough: the candidate needs viewown, like for the dossier.
     */
    public function test_for_candidate_refuses_the_candidate_without_viewown(): void {
        $this->resetAfterTest();
        $candidate = $this->getDataGenerator()->create_user();
        $this->setUser($candidate);

        $this->expectException(\required_capability_exception::class);
        organisation::for_candidate((int) $candidate->id);
    }
}
