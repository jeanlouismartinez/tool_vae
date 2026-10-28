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
use tool_vae\local\exporter;

/**
 * Is the plugin actually plugged into the platform - or only apparently?
 *
 * WHY A FILE OF ITS OWN. Every other test in this suite asks whether the plugin's own code is
 * right. These ask something else: whether the CORE can see it. A hook whose name is off by one
 * letter, an administration node placed under a category that does not exist, a search area
 * quietly declared - none of that fails. It just does not happen, and the plugin goes on
 * looking correct while the platform ignores it. The failures this file is for are silent by
 * nature, which is exactly why they need a test rather than a reading.
 *
 * The rule the plugin follows: it must not rebuild what the platform already does, it must be
 * WIRED to it. A wire is either connected or it is not, and that is a fact one can measure.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class integration_test extends \advanced_testcase {
    /**
     * The candidate reaches their dossier from their own profile page.
     *
     * The node is built by the core, from a callback the core finds by NAME in lib.php. Rename
     * the function, or place the node in a category the tree does not have, and the link simply
     * never appears - no error, no warning, and the dossier becomes reachable only by typing
     * its URL.
     */
    public function test_the_dossier_is_reachable_from_the_users_profile(): void {
        global $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $user = $this->getDataGenerator()->create_user();
        $this->grant('tool/vae:viewown', $user);
        $this->setUser($user);

        $node = $this->find_profile_node($user);

        $this->assertNotNull($node, 'The core does not see this plugin on the profile page.');
        $this->assertStringContainsString('/admin/tool/vae/dossier.php', (string) $node->url);
        $this->assertStringContainsString('userid=' . $user->id, (string) $node->url);
    }

    /**
     * And someone without the capability is not offered a link they cannot follow.
     *
     * The other direction of the same wire. Without it, a test that only checks the presence of
     * the node would pass just as well on a callback that adds it unconditionally.
     */
    public function test_the_profile_offers_nothing_without_the_capability(): void {
        global $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertNull($this->find_profile_node($user), 'A link was offered to someone who may not follow it.');
    }

    /**
     * The subsystem being off removes the link, as it removes the pages behind it.
     */
    public function test_the_profile_offers_nothing_when_the_subsystem_is_off(): void {
        global $CFG;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/user/profile/lib.php');

        set_config('enabled', 0, competency_gateway::SUBSYSTEM);

        $user = $this->getDataGenerator()->create_user();
        $this->grant('tool/vae:viewown', $user);
        $this->setUser($user);

        $this->assertNull($this->find_profile_node($user), 'The link survived the subsystem it depends on.');
    }

    /**
     * The plugin has a place in the administration tree, and it is under competencies.
     *
     * Trap 3 of docs/BANC-ESSAI.md: on the command line, with no session, has_capability() is
     * false for everyone and the whole tree builds empty - which reads exactly like a plugin
     * that failed to register. Setting the admin user first is what makes this test able to
     * fail for the right reason.
     */
    public function test_the_plugin_has_a_page_in_the_administration_tree(): void {
        global $CFG;

        $this->resetAfterTest();
        require_once($CFG->libdir . '/adminlib.php');

        \core\session\manager::set_user(get_admin());
        $root = admin_get_root(true, true);

        $category = $root->locate('competencies');
        $this->assertNotNull($category, 'The category the plugin files itself under is gone.');
        $this->assertNotNull(
            $root->locate('toolvae'),
            'The core does not see this plugin in the administration tree.'
        );

        // WHERE it sits, not merely that it exists. locate() searches the whole tree, so an
        // assertion on presence alone passes just as well on a page filed under 'tools' - the
        // exact defect lot 0 found: 'tools' is created only for a site administrator, and this
        // test runs as one, so the entry would look present here and vanish for everyone else.
        // Verified by mutation: filed under 'tools', the assertion above stays green and this
        // one turns red.
        $children = array_map(function ($child) {
            return $child->name;
        }, $category->children);

        $this->assertContains(
            'toolvae',
            $children,
            'The page left the competencies category, where every administrator can reach it.'
        );
    }

    /**
     * The plugin declares no global search area, and that is deliberate.
     *
     * A search area would put the narrative in an index OUTSIDE this plugin's tables: a second
     * access-control system to write from scratch - \core_search\base::check_access() is
     * abstract, the engine never consults a plugin's capabilities - and a second erasure path,
     * since a purge of the database would leave the indexed copy alive. This test is what turns
     * that reasoning into something a later commit cannot quietly undo.
     */
    public function test_the_narrative_is_not_offered_to_global_search(): void {
        $areas = \core_component::get_component_classes_in_namespace('tool_vae', 'search');

        $this->assertSame([], $areas, 'A search area would index the narrative outside this plugin.');
    }

    /**
     * The plugin holds files in one area only - the exported archives - and serves nothing else.
     *
     * Until lot 8 this test asserted that the plugin held no file at all, and that no file
     * serving callback existed: the generic branch of file_pluginfile() performs no
     * require_login() and no capability check of its own, so the day a callback appeared it had
     * to carry the WHOLE check. It appeared with the export, and this test failed as intended.
     * The check it carries - author only, within a day, while the right to export holds - is
     * tested in exporter_test. What stays here is the perimeter: one area, in a user context,
     * and a refusal for any other request. Attachments still belong to the competency
     * subsystem, which has its own provider and its own deletion path.
     */
    public function test_the_plugin_serves_its_export_area_and_nothing_else(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/admin/tool/vae/lib.php');

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertEquals(0, $DB->count_records_select(
            'files',
            'component = :component AND filearea <> :filearea',
            ['component' => 'tool_vae', 'filearea' => exporter::FILEAREA]
        ));

        $this->assertFalse(
            tool_vae_pluginfile(null, null, \context_system::instance(), exporter::FILEAREA, ['0', 'a.zip'], true),
            'An archive is only ever served from a user context.'
        );
        $this->assertFalse(
            tool_vae_pluginfile(null, null, \context_user::instance($user->id), 'attachment', ['0', 'a.zip'], true),
            'No file area other than the export one is served.'
        );
    }

    /**
     * Find this plugin's node in the profile tree the core builds.
     *
     * @param \stdClass $user
     * @return \core_user\output\myprofile\node|null
     */
    private function find_profile_node(\stdClass $user) {
        $tree = \core_user\output\myprofile\manager::build_tree($user, true);

        foreach ($tree->categories as $category) {
            foreach ($category->nodes as $node) {
                if (strpos($node->name, 'toolvae') !== false) {
                    return $node;
                }
            }
        }

        return null;
    }

    /**
     * Grant one capability on the user's own context.
     *
     * @param string $capability
     * @param \stdClass $user
     */
    private function grant(string $capability, \stdClass $user): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_user::instance($user->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }
}
