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

use tool_vae\local\competency_gateway;

/**
 * The links the plugin adds to a profile page (lib.php).
 *
 * The pathway of a person is theirs alone (T-90): its link is offered on one's own profile, to a person who may read
 * their own dossier, and not in a session of "log in as", which the page refuses. A link to a page that refuses is a
 * smaller fault than an opening, but a fault.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('tool_vae_myprofile_navigation')]
final class myprofile_navigation_test extends \advanced_testcase {
    /** @var \stdClass A person who may read their own dossier. */
    private $candidate;

    /**
     * The competency subsystem is on, and a candidate holds viewown.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/admin/tool/vae/lib.php');
        set_config('enabled', 1, competency_gateway::SUBSYSTEM);

        $this->candidate = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewown', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The names of the nodes the callback adds.
     *
     * @param \stdClass $user The profile that is displayed.
     * @param bool $iscurrentuser
     * @return string[]
     */
    private function nodes(\stdClass $user, bool $iscurrentuser): array {
        $tree = new \core_user\output\myprofile\tree();
        tool_vae_myprofile_navigation($tree, $user, $iscurrentuser, null);

        return array_keys($tree->nodes);
    }

    /**
     * On one's own profile, the dossier and the pathway are offered.
     */
    public function test_the_pathway_is_offered_on_one_s_own_profile(): void {
        $this->setUser($this->candidate);

        $nodes = $this->nodes($this->candidate, true);

        $this->assertContains('toolvaedossier', $nodes);
        $this->assertContains('toolvaeorganisation', $nodes);
    }

    /**
     * On somebody else's profile, even for a reader who may read the dossier, the pathway is not offered.
     */
    public function test_the_pathway_is_not_offered_on_another_profile(): void {
        $this->setAdminUser();

        $nodes = $this->nodes($this->candidate, false);

        $this->assertContains('toolvaedossier', $nodes, 'The administrator reads the dossier.');
        $this->assertNotContains('toolvaeorganisation', $nodes);
    }

    /**
     * Without the right to read one's own dossier, the pathway is not offered.
     */
    public function test_the_pathway_is_not_offered_without_viewown(): void {
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        $this->assertNotContains('toolvaeorganisation', $this->nodes($stranger, true));
    }

    /**
     * In a session of "log in as" the page refuses: the link is not offered.
     */
    public function test_the_pathway_is_not_offered_in_a_session_of_log_in_as(): void {
        $this->setAdminUser();
        \core\session\manager::loginas((int) $this->candidate->id, \context_system::instance());
        $this->assertSame((int) $this->candidate->id, (int) $GLOBALS['USER']->id);

        $this->assertNotContains('toolvaeorganisation', $this->nodes($this->candidate, true));
    }

    /**
     * With the competency subsystem off, nothing is offered.
     */
    public function test_nothing_is_offered_when_the_competencies_are_off(): void {
        set_config('enabled', 0, competency_gateway::SUBSYSTEM);
        $this->setUser($this->candidate);

        $this->assertSame([], $this->nodes($this->candidate, true));
    }

    /**
     * Who may view any dossier is offered the configuration of the scheme on their own profile, and nobody else is.
     */
    public function test_the_configuration_is_offered_to_who_may_view_any_dossier_on_their_own_profile_only(): void {
        $director = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $director->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($director);
        $this->assertContains('toolvaeorganisationsite', $this->nodes($director, true));
        $this->assertNotContains('toolvaeorganisationsite', $this->nodes($this->candidate, false), 'Not on another profile.');

        $this->setUser($this->candidate);
        $nodes = $this->nodes($this->candidate, true);
        $this->assertNotContains('toolvaeorganisationsite', $nodes, 'An ordinary candidate has no such right.');
    }

    /**
     * The node reads the site, not the competency subsystem: it stays when that subsystem is off, as the page does.
     */
    public function test_the_configuration_is_offered_whether_or_not_the_competency_subsystem_is_on(): void {
        $director = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $director->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        set_config('enabled', 0, competency_gateway::SUBSYSTEM);
        $this->setUser($director);

        $nodes = $this->nodes($director, true);

        $this->assertContains('toolvaeorganisationsite', $nodes);
        $this->assertNotContains('toolvaedossier', $nodes, 'The nodes that read the subsystem are gone.');
    }
}
