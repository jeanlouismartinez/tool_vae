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
 * Tests for the core competency gateway.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\competency_gateway::class)]
final class competency_gateway_test extends \advanced_testcase {
    /**
     * Acceptance criterion 10 - the subsystem being off is reported, not thrown.
     *
     * The setting lives under the subsystem's own component name, key "enabled".
     * $CFG->enablecompetencies does not exist; reading it returns null on every site, so a
     * guard written against that name would report "disabled" permanently and the plugin
     * would refuse to work everywhere.
     */
    public function test_subsystem_reported_disabled_when_setting_is_off(): void {
        $this->resetAfterTest();

        set_config('enabled', 0, competency_gateway::SUBSYSTEM);

        $this->assertFalse(competency_gateway::is_subsystem_enabled());
    }

    /**
     * The mirror case, so the assertion above cannot pass for the wrong reason.
     */
    public function test_subsystem_reported_enabled_when_setting_is_on(): void {
        $this->resetAfterTest();

        set_config('enabled', 1, competency_gateway::SUBSYSTEM);

        $this->assertTrue(competency_gateway::is_subsystem_enabled());
    }

    /**
     * The guard must read the real setting, not a name that never resolves.
     *
     * Without this, a guard reading a non-existent config key would satisfy the test above
     * by always returning false. Here the setting is switched on and the legacy name is
     * asserted absent, so a gateway consulting $CFG->enablecompetencies fails.
     */
    public function test_guard_does_not_rely_on_the_legacy_setting_name(): void {
        global $CFG;
        $this->resetAfterTest();

        set_config('enabled', 1, competency_gateway::SUBSYSTEM);

        $this->assertFalse(
            isset($CFG->enablecompetencies),
            'Le reglage ne vit pas dans $CFG mais dans config_plugins.'
        );
        $this->assertTrue(competency_gateway::is_subsystem_enabled());
    }

    /**
     * Lot 8 quater, B3: an imported framework carries the core's competence scale, not scale 1.
     *
     * Scale 1 is "Separate and Connected ways of knowing" on a standard install. The framework
     * is created through api::create_framework(), which validates the configuration: reaching the
     * assertions proves the core accepts it.
     */
    public function test_a_created_framework_uses_the_default_competence_scale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = (new competency_gateway())->create_framework('RS00000', 'Certification inventée');

        $framework = $DB->get_record('competency_framework', ['id' => $id], '*', MUST_EXIST);
        $scale = $DB->get_record('scale', ['id' => $framework->scaleid], '*', MUST_EXIST);
        $this->assertSame(get_string('defaultcompetencescale'), $scale->name);
        $this->assertEquals(0, $scale->courseid);

        $configuration = json_decode($framework->scaleconfiguration);
        $this->assertEquals($scale->id, array_shift($configuration)->scaleid);
        $this->assertCount(count(explode(',', $scale->scale)), $configuration, 'Not one entry per item of the scale.');
        $this->assertEquals(1, reset($configuration)->scaledefault, 'The first item is not the default.');
        $this->assertEquals(1, end($configuration)->proficient, 'The last item is not proficient.');
    }

    /**
     * A site whose competence scale is gone is told so, rather than handed another scale.
     */
    public function test_a_site_without_the_competence_scale_is_told_so(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $DB->set_field('scale', 'name', 'Renommée', ['id' => competency_gateway::default_competence_scale()->id]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('cannot be found');
        (new competency_gateway())->create_framework('RS00000', 'Certification inventée');
    }

    /**
     * Rename the core's default competence scale, then add the site scale found on fildargent.net on 2 November 2026.
     *
     * That site held no scale under the name Moodle looks for today: its three-level scale was named
     * "Echelle d'évaluation de compétence par défault" (an older wording, with a typing error), so every import stopped at
     * "the default competence scale cannot be found". Both facts are read from the real site, not invented.
     *
     * @return int The id of the three-level site scale.
     */
    private function a_site_whose_scale_has_another_name(): int {
        global $DB;

        $DB->set_field('scale', 'name', 'Renommée', ['id' => competency_gateway::default_competence_scale()->id]);

        return $DB->insert_record('scale', (object) [
            'courseid' => 0,
            'userid' => 0,
            'name' => 'Echelle d\'évaluation de compétence par défault',
            'scale' => 'Non acquis,En cours d\'acquisition,Acquis',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'timemodified' => time(),
        ]);
    }

    /**
     * The administrator may name the scale itself, which is the way out when the name Moodle looks for is not the site's.
     */
    public function test_a_chosen_scale_is_used_when_the_core_one_is_not_found_by_name(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $chosen = $this->a_site_whose_scale_has_another_name();

        set_config('importscaleid', $chosen, 'tool_vae');

        $this->assertEquals($chosen, competency_gateway::default_competence_scale()->id);

        $id = (new competency_gateway())->create_framework('RS00000', 'Certification inventée');
        $framework = $DB->get_record('competency_framework', ['id' => $id], '*', MUST_EXIST);
        $this->assertEquals($chosen, $framework->scaleid);
        $configuration = json_decode($framework->scaleconfiguration);
        array_shift($configuration);
        $this->assertCount(3, $configuration, 'Not one entry per item of the chosen scale.');
        $this->assertEquals(1, end($configuration)->proficient, 'The last item is not proficient.');
    }

    /**
     * Without a choice the site is still told so, and now told what was looked for and where to choose.
     */
    public function test_the_error_names_the_scales_looked_for_and_the_setting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->a_site_whose_scale_has_another_name();

        try {
            competency_gateway::default_competence_scale();
            $this->fail('A site with no scale under the core name must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornocompetencescale', $e->errorcode);
            $this->assertStringContainsString('Default competence scale', $e->getMessage(), 'The name looked for is not given.');
            $this->assertStringContainsString('Scale of imported frameworks', $e->getMessage(), 'The setting is not named.');
        }
    }

    /**
     * A choice whose scale was deleted since is not a reason to refuse: the core's scale, found by name, is the fallback.
     */
    public function test_a_chosen_scale_that_is_gone_falls_back_to_the_core_one(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $core = competency_gateway::default_competence_scale()->id;

        set_config('importscaleid', 987654, 'tool_vae');

        $this->assertEquals($core, competency_gateway::default_competence_scale()->id);
    }

    /**
     * Only a scale of the site may be chosen: a course's own scale is never handed to an imported framework.
     */
    public function test_a_course_scale_is_never_taken_as_the_chosen_one(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $core = competency_gateway::default_competence_scale()->id;
        $course = $this->getDataGenerator()->create_course();
        $courseonly = $DB->insert_record('scale', (object) [
            'courseid' => $course->id,
            'userid' => 0,
            'name' => 'Barème du cours',
            'scale' => 'Non,Oui',
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'timemodified' => time(),
        ]);

        set_config('importscaleid', $courseonly, 'tool_vae');

        $this->assertEquals($core, competency_gateway::default_competence_scale()->id);
    }

    /**
     * A framework of the core with one competency, as an imported record leaves it.
     *
     * @return int[] The framework id and the competency id.
     */
    private function framework_with_a_competency(): array {
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $framework = $generator->create_framework(['idnumber' => 'RS00000', 'shortname' => 'Certification inventée']);
        $competency = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => 'RS00000-C01',
            'shortname' => 'Aider au lever',
        ]);

        return [(int) $framework->get('id'), (int) $competency->get('id')];
    }

    /**
     * Frameworkid 0 is what a first import that died leaves in tool_vae_source, and no screen may die on it.
     *
     * The core builds an empty persistent for id 0 and its get_context() raises a coding_exception: the
     * declaration screen, the course screen and the scope screen went down for every user, for one record.
     */
    public function test_a_framework_id_of_zero_lists_nothing(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $gateway = new competency_gateway();

        $this->assertSame([], $gateway->list_framework_competencies(0));
        $this->assertSame([], $gateway->list_framework_competencies(-1));
        $this->assertSame([], $gateway->framework_tree(0));
        $this->assertSame(0, $gateway->count_framework_competencies(0));
        $this->assertFalse($gateway->framework_exists(0));
        $this->assertNull($gateway->framework_idnumber(0));
        $this->assertNull($gateway->framework_name(0));
        $this->assertFalse($gateway->framework_visible(0));
        $this->assertNull($gateway->get_competency(0));
    }

    /**
     * A framework deleted in tool_lp lists nothing, and raises nothing.
     *
     * The core lets an administrator delete a framework as long as nobody has declared on it, and the
     * foreign key of tool_vae_source is declared but never enforced: the row survives with an id the core
     * no longer holds, and api::list_competencies() raised dml_missing_record_exception for it.
     */
    public function test_a_framework_the_core_no_longer_holds_lists_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$frameworkid, $competencyid] = $this->framework_with_a_competency();
        $gateway = new competency_gateway();
        $this->assertCount(1, $gateway->list_framework_competencies($frameworkid));

        // The framework goes, its competencies with it: the state tool_lp leaves, whatever the route.
        $DB->delete_records('competency', ['competencyframeworkid' => $frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $frameworkid]);

        $this->assertSame([], $gateway->list_framework_competencies($frameworkid));
        $this->assertSame([], $gateway->framework_tree($frameworkid));
        $this->assertSame(0, $gateway->count_framework_competencies($frameworkid));
        $this->assertFalse($gateway->framework_exists($frameworkid));
        $this->assertNull($gateway->framework_name($frameworkid));
        $this->assertNull($gateway->get_competency($competencyid));
    }

    /**
     * What is listed is read without moodle/competency:competencyview, which only the authenticated-user
     * archetype holds by default.
     *
     * A site that took it away from that role - to keep its own frameworks out of tool_lp - lost the
     * declaration screen, the dossier and the course screen to a refusal nobody could read; and the backup
     * run by the command line, where nobody is logged in, never had it.
     */
    public function test_the_competencies_are_read_without_the_viewing_right_of_the_core(): void {
        global $CFG;
        $this->resetAfterTest();
        [$frameworkid, $competencyid] = $this->framework_with_a_competency();

        unassign_capability('moodle/competency:competencyview', $CFG->defaultuserroleid, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(
            has_capability('moodle/competency:competencyview', \context_system::instance()),
            'The reader still holds the right: the test proves nothing.'
        );

        $gateway = new competency_gateway();
        $listed = $gateway->list_framework_competencies($frameworkid);
        $this->assertSame(['RS00000-C01'], array_values(array_column($listed, 'idnumber')));
        $competency = $gateway->get_competency($competencyid);
        $this->assertNotNull($competency, 'A reader without the right was told the competency does not exist.');
        $this->assertSame('Aider au lever', $competency->label);
        $this->assertSame($frameworkid, $competency->frameworkid);
    }

    /**
     * The declaration screen asks, before the form, the right the core asks again when a declaration links to a competency.
     *
     * The competencies are listed without moodle/competency:competencyview, so the form opens for anyone; the core reads the
     * competency through api::read_competency() when it links the evidence, which takes competencyview or competencymanage and
     * rolls the declaration back. Before: the gateway had no way to ask, and a site that took competencyview away from the
     * authenticated-user role let the vaeiste write the whole situation to lose it on an error page.
     */
    public function test_the_viewing_right_of_the_core_is_asked_before_the_form_by_the_two_rights_it_accepts(): void {
        global $CFG;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $system = \context_system::instance();

        $this->setUser($generator->create_user());
        $this->assertTrue(competency_gateway::can_view_competencies($system), 'By default the authenticated user holds it.');

        unassign_capability('moodle/competency:competencyview', $CFG->defaultuserroleid, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(has_capability('moodle/competency:competencyview', $system), 'The premise: the right is gone.');
        $this->assertFalse(competency_gateway::can_view_competencies($system));

        // The core accepts the right to manage in its place: so does the gateway.
        $manager = $generator->create_user();
        $role = $generator->create_role();
        assign_capability('moodle/competency:competencymanage', CAP_ALLOW, $role, $system->id, true);
        role_assign($role, $manager->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($manager);
        $this->assertTrue(competency_gateway::can_view_competencies($system));

        // And the viewing right alone, given back to a role of its own.
        $viewer = $generator->create_user();
        $viewrole = $generator->create_role();
        assign_capability('moodle/competency:competencyview', CAP_ALLOW, $viewrole, $system->id, true);
        role_assign($viewrole, $viewer->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($viewer);
        $this->assertTrue(competency_gateway::can_view_competencies($system));
    }

    /**
     * A framework is read by its identifier, its name and its visibility, and an id the core does not hold gives nothing.
     */
    public function test_a_framework_is_read_by_its_identifier_its_name_and_its_visibility(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gateway = new competency_gateway();

        $id = $gateway->create_framework('RS00000', 'Certification inventée');

        $this->assertTrue($gateway->framework_exists($id));
        $this->assertSame('RS00000', $gateway->framework_idnumber($id));
        $this->assertSame('Certification inventée', $gateway->framework_name($id));
        $this->assertTrue($gateway->framework_visible($id));

        // The gesture of the administrator in tool_lp: "Visible: No".
        $DB->set_field('competency_framework', 'visible', 0, ['id' => $id]);
        $this->assertFalse($gateway->framework_visible($id));
        $this->assertTrue($gateway->framework_exists($id), 'A hidden framework is still there.');

        $this->assertFalse($gateway->framework_exists($id + 1000));
        $this->assertNull($gateway->framework_idnumber($id + 1000));
        $this->assertNull($gateway->framework_name($id + 1000));
        $this->assertFalse($gateway->framework_visible($id + 1000));
    }

    /**
     * The core's own door deletes a framework nobody has declared on, which is what an administrator does in tool_lp.
     */
    public function test_the_core_door_deletes_a_framework_nobody_declared_on(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$frameworkid] = $this->framework_with_a_competency();
        $gateway = new competency_gateway();

        $this->assertTrue($gateway->delete_framework($frameworkid));

        $this->assertFalse($gateway->framework_exists($frameworkid));
        $this->assertSame([], $gateway->list_framework_competencies($frameworkid));
    }
}
