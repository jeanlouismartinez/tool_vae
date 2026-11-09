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

    /**
     * T-90: the learning plans of a user are read by name, status and template - and by nothing else.
     *
     * The plan is a fact of the person's pathway; who reviews it is the beginning of an evaluation, and the plugin
     * never reads one (D-1). The SQL is captured so that a column read in excess fails here, where a check on the
     * returned rows would not see it.
     */
    public function test_list_user_plans_reads_name_status_and_template_only(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $template = $generator->create_template(['shortname' => 'Parcours AES']);
        $generator->create_plan(['userid' => $user->id, 'name' => 'Zèbre', 'status' => competency_gateway::PLAN_ACTIVE]);
        $generator->create_plan([
            'userid' => $user->id,
            'name' => 'Abeille',
            'templateid' => $template->get('id'),
            'status' => competency_gateway::PLAN_ACTIVE,
        ]);
        $generator->create_plan(['userid' => $this->getDataGenerator()->create_user()->id, 'name' => 'Autrui']);

        // The SQL goes to the output under CLI_SCRIPT, which the PHPUnit bootstrap defines; the positive assertion
        // below is what proves the capture caught the query, so that the two absences are not asserted on nothing.
        $DB->set_debug(true);
        ob_start();
        try {
            $plans = array_values((new competency_gateway())->list_user_plans((int) $user->id));
        } finally {
            $sql = ob_get_clean();
            $DB->set_debug(false);
        }

        $this->assertStringContainsString('origtemplateid', $sql);
        $this->assertCount(2, $plans);
        $this->assertSame(['Abeille', 'Zèbre'], array_column($plans, 'name'));
        $this->assertSame((int) $template->get('id'), (int) $plans[0]->templateid);
        $this->assertSame(competency_gateway::PLAN_ACTIVE, (int) $plans[1]->status);
        $this->assertSame(
            ['id', 'name', 'status', 'templateid', 'origtemplateid', 'timemodified'],
            array_keys((array) $plans[0])
        );
        $this->assertStringNotContainsString('reviewerid', $sql);
        $this->assertStringNotContainsString('*', $sql);
    }

    /**
     * No plan: an empty list, never an exception.
     */
    public function test_list_user_plans_is_empty_without_a_plan(): void {
        $this->resetAfterTest();

        $this->assertSame([], (new competency_gateway())->list_user_plans(999999));
    }

    /**
     * T-90 lot 3: the plans of several people come in one query, with the same columns as one person's, and only
     * the people asked for.
     */
    public function test_list_plans_of_users_reads_the_plans_of_those_asked_in_one_query(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $third = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $generator->create_plan(['userid' => $first->id, 'name' => 'Zèbre', 'status' => competency_gateway::PLAN_ACTIVE]);
        $generator->create_plan(['userid' => $first->id, 'name' => 'Abeille', 'status' => competency_gateway::PLAN_ACTIVE]);
        $generator->create_plan(['userid' => $second->id, 'name' => 'Mouette', 'status' => competency_gateway::PLAN_ACTIVE]);
        $generator->create_plan(['userid' => $third->id, 'name' => 'Autrui', 'status' => competency_gateway::PLAN_ACTIVE]);

        $DB->set_debug(true);
        ob_start();
        try {
            $plans = array_values((new competency_gateway())->list_plans_of_users([(int) $first->id, (int) $second->id]));
        } finally {
            $sql = ob_get_clean();
            $DB->set_debug(false);
        }

        $this->assertCount(3, $plans);
        $this->assertSame(['Abeille', 'Mouette', 'Zèbre'], array_column($plans, 'name'));
        $this->assertSame(['id', 'userid', 'name', 'status'], array_keys((array) $plans[0]));
        $this->assertNotContains('Autrui', array_column($plans, 'name'));
        // The positive assertion proves the capture caught the query: one read of the plan table, no more.
        $this->assertSame(1, preg_match_all('/FROM\s+\w*competency_plan\b/', $sql));
        $this->assertStringNotContainsString('reviewerid', $sql);
        $this->assertStringNotContainsString('templateid', $sql);
        $this->assertStringNotContainsString('*', $sql);
    }

    /**
     * The two flags leave out what they leave out, and the grouped reading agrees with the reading of one person.
     *
     * @param bool $drafts
     * @param bool $active
     * @param string[] $expected The names listed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('plan_flag_provider')]
    public function test_list_plans_of_users_leaves_out_what_the_flags_leave_out(
        bool $drafts,
        bool $active,
        array $expected
    ): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $statuses = [
            'Brouillon' => competency_gateway::PLAN_DRAFT,
            'Attente' => competency_gateway::PLAN_WAITING_FOR_REVIEW,
            'Revision' => competency_gateway::PLAN_IN_REVIEW,
            'Actif' => competency_gateway::PLAN_ACTIVE,
            'Termine' => competency_gateway::PLAN_COMPLETE,
        ];
        foreach ($statuses as $name => $status) {
            $generator->create_plan(['userid' => $user->id, 'name' => $name, 'status' => $status]);
        }

        $gateway = new competency_gateway();
        $grouped = $gateway->list_plans_of_users([(int) $user->id], $drafts, $active);
        $names = array_column(array_values($grouped), 'name');
        sort($names);
        sort($expected);
        $this->assertSame($expected, $names);
        $this->assertEqualsCanonicalizing(
            array_keys($gateway->list_user_plans((int) $user->id, $drafts, $active)),
            array_column(array_values($grouped), 'id')
        );
    }

    /**
     * The four combinations of the flags, with the plans each one lists.
     *
     * @return array[]
     */
    public static function plan_flag_provider(): array {
        return [
            'active only' => [false, true, ['Actif', 'Termine']],
            'drafts only' => [true, false, ['Brouillon', 'Attente', 'Revision']],
            'neither' => [false, false, []],
            'both' => [true, true, ['Brouillon', 'Attente', 'Revision', 'Actif', 'Termine']],
        ];
    }

    /**
     * Nobody asked for: an empty list, and not a single read.
     */
    public function test_list_plans_of_users_is_empty_for_nobody(): void {
        $this->resetAfterTest();

        $reads = $GLOBALS['DB']->perf_get_reads();
        $this->assertSame([], (new competency_gateway())->list_plans_of_users([]));
        $this->assertSame($reads, $GLOBALS['DB']->perf_get_reads());
    }

    /**
     * The status of a plan is said with the words of the core, so that the screen never invents its own.
     */
    public function test_plan_status_label_uses_the_words_of_the_core(): void {
        $this->resetAfterTest();

        $expected = [
            competency_gateway::PLAN_DRAFT => 'planstatusdraft',
            competency_gateway::PLAN_ACTIVE => 'planstatusactive',
            competency_gateway::PLAN_COMPLETE => 'planstatuscomplete',
            competency_gateway::PLAN_WAITING_FOR_REVIEW => 'planstatuswaitingforreview',
            competency_gateway::PLAN_IN_REVIEW => 'planstatusinreview',
        ];
        $this->assertCount(5, array_unique(array_keys($expected)), 'The five statuses are distinct numbers.');
        foreach ($expected as $status => $key) {
            $this->assertSame(
                get_string($key, competency_gateway::SUBSYSTEM),
                competency_gateway::plan_status_label($status),
                "Status $status"
            );
        }

        // A number the core does not know is given back as it is: the screen says nothing that is not in the row
        // (conception § 4.2), where the core itself would throw errorplanstatus.
        $this->assertSame('7', competency_gateway::plan_status_label(7));
    }

    /**
     * A framework of the core with two competencies, C01 and C02, as an imported record leaves them.
     *
     * @return int[] The framework id, then the two competency ids.
     */
    private function framework_with_two_competencies(): array {
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $framework = $generator->create_framework(['idnumber' => 'RS00001', 'shortname' => 'Autre certification']);
        $ids = [(int) $framework->get('id')];
        foreach (['C01' => 'Aider au lever', 'C02' => 'Aider au repas'] as $rank => $name) {
            $ids[] = (int) $generator->create_competency([
                'competencyframeworkid' => $framework->get('id'),
                'idnumber' => 'RS00001-' . $rank,
                'shortname' => $name,
            ])->get('id');
        }

        return $ids;
    }

    /**
     * T-90: a plan on a template reads the competencies of the template, in the template's order.
     */
    public function test_plan_competency_ids_reads_the_template_of_a_plan_on_a_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01, $c02] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $template = $generator->create_template(['shortname' => 'Parcours AES']);
        $first = $generator->create_template_competency(['templateid' => $template->get('id'), 'competencyid' => $c01]);
        $second = $generator->create_template_competency(['templateid' => $template->get('id'), 'competencyid' => $c02]);
        // The core numbers the links as they are created; the order of the template is then changed by hand, so
        // that it contradicts both the order of insertion and the order of the ids (relecture).
        $first->set('sortorder', 5);
        $first->update();
        $second->set('sortorder', 2);
        $second->update();
        $user = $this->getDataGenerator()->create_user();
        $plan = $generator->create_plan(['userid' => $user->id, 'templateid' => $template->get('id')]);

        $this->assertSame([$c02, $c01], (new competency_gateway())->plan_competency_ids((int) $plan->get('id')));
    }

    /**
     * T-90: a complete plan that kept its template reads the archive, not the template - the usual case in VAE.
     *
     * The core never detaches a complete plan from its template (api::complete_plan keeps templateid,
     * unlink_plan_from_template refuses a complete plan): status is tested before the template, as the core does.
     */
    public function test_a_complete_plan_on_a_template_reads_the_archive_not_the_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01, $c02] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $template = $generator->create_template(['shortname' => 'Parcours AES']);
        $generator->create_template_competency(['templateid' => $template->get('id'), 'competencyid' => $c02]);
        $user = $this->getDataGenerator()->create_user();
        $plan = $generator->create_plan([
            'userid' => $user->id,
            'templateid' => $template->get('id'),
            'status' => competency_gateway::PLAN_COMPLETE,
        ]);
        $generator->create_user_competency_plan(['userid' => $user->id, 'competencyid' => $c01, 'planid' => $plan->get('id')]);

        $this->assertSame([$c01], (new competency_gateway())->plan_competency_ids((int) $plan->get('id')));
    }

    /**
     * T-90, point of vigilance 1: a plan whose template is gone, rows and all, has no competencies and throws nothing.
     *
     * The core deletes the template's links with the template (api::delete_template); the plugin reads them by
     * templateid, as the core does, so a template gone with its rows leaves an empty list - never an invented one.
     */
    public function test_plan_competency_ids_of_a_plan_whose_template_is_gone_is_empty(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $template = $generator->create_template(['shortname' => 'Parcours AES']);
        $generator->create_template_competency(['templateid' => $template->get('id'), 'competencyid' => $c01]);
        $user = $this->getDataGenerator()->create_user();
        $plan = $generator->create_plan(['userid' => $user->id, 'templateid' => $template->get('id')]);
        // The state the API never leaves (it refuses to delete a template that has plans): staged by hand.
        $DB->delete_records('competency_templatecomp', ['templateid' => $template->get('id')]);
        $DB->delete_records('competency_template', ['id' => $template->get('id')]);

        $this->assertSame([], (new competency_gateway())->plan_competency_ids((int) $plan->get('id')));
    }

    /**
     * T-90: a plan without a template reads its own competencies.
     */
    public function test_plan_competency_ids_reads_plancomp_of_a_plan_without_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $user = $this->getDataGenerator()->create_user();
        $plan = $generator->create_plan(['userid' => $user->id]);
        $generator->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $c01]);

        $this->assertSame([$c01], (new competency_gateway())->plan_competency_ids((int) $plan->get('id')));
    }

    /**
     * T-90: a plan the core detached from its template (templateid null, origtemplateid kept) reads its own competencies.
     */
    public function test_plan_competency_ids_reads_plancomp_of_a_plan_detached_from_its_template(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01, $c02] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $template = $generator->create_template(['shortname' => 'Parcours AES']);
        // The template of origin still lists C02: a detached plan does not read it (relecture).
        $generator->create_template_competency(['templateid' => $template->get('id'), 'competencyid' => $c02]);
        $user = $this->getDataGenerator()->create_user();
        $plan = $generator->create_plan(['userid' => $user->id, 'origtemplateid' => $template->get('id')]);
        $generator->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $c01]);

        $this->assertSame([$c01], (new competency_gateway())->plan_competency_ids((int) $plan->get('id')));
    }

    /**
     * T-90: a complete plan reads the core's frozen archive of it - and no grade along the way.
     *
     * The archive row carries a grade and a proficiency: the beginning of an evaluation, which the plugin never
     * reads (D-1). The SQL is captured so that a column read in excess fails here.
     */
    public function test_plan_competency_ids_reads_the_archive_of_a_complete_plan_and_no_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01, $c02] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $user = $this->getDataGenerator()->create_user();
        $plan = $generator->create_plan(['userid' => $user->id, 'status' => competency_gateway::PLAN_COMPLETE]);
        $generator->create_user_competency_plan([
            'userid' => $user->id,
            'competencyid' => $c01,
            'planid' => $plan->get('id'),
            'sortorder' => 0,
            'grade' => 1,
            'proficiency' => 1,
        ]);
        // Still in the live list, but the plan is closed: the archive alone speaks.
        $generator->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $c02]);

        $DB->set_debug(true);
        ob_start();
        try {
            $ids = (new competency_gateway())->plan_competency_ids((int) $plan->get('id'));
        } finally {
            $sql = ob_get_clean();
            $DB->set_debug(false);
        }

        $this->assertSame([$c01], $ids);
        $this->assertStringContainsString('competency_usercompplan', $sql);
        $this->assertStringNotContainsString('grade', $sql);
        $this->assertStringNotContainsString('proficiency', $sql);
        $this->assertStringNotContainsString('reviewerid', $sql);
    }

    /**
     * T-90: a plan that does not exist has no competencies, and nothing is thrown.
     */
    public function test_plan_competency_ids_of_an_unknown_plan_is_empty(): void {
        $this->resetAfterTest();
        $gateway = new competency_gateway();

        $this->assertSame([], $gateway->plan_competency_ids(0));
        $this->assertSame([], $gateway->plan_competency_ids(-1));
    }

    /**
     * T-90: the courses that carry a competency, hidden ones included and marked - never omitted.
     *
     * Omitting a hidden course would make the pathway say "no course" where there is one: a false zero.
     */
    public function test_courses_with_competency_returns_hidden_courses_marked_not_omitted(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01, $c02] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        // Names chosen so that the order of the bytes (upper case first, accented initial last) differs from the order
        // of the words: the sort is the collator's, in PHP, the same on PostgreSQL musl, PostgreSQL glibc and MariaDB.
        $visible = $this->getDataGenerator()->create_course(['fullname' => 'Zeta']);
        $hidden = $this->getDataGenerator()->create_course(['fullname' => 'Été caché', 'visible' => 0]);
        $lower = $this->getDataGenerator()->create_course(['fullname' => 'alpha']);
        $other = $this->getDataGenerator()->create_course(['fullname' => 'Autre']);
        foreach ([$visible, $hidden, $lower] as $course) {
            $generator->create_course_competency(['courseid' => $course->id, 'competencyid' => $c01]);
        }
        $generator->create_course_competency(['courseid' => $other->id, 'competencyid' => $c02]);

        $rows = array_values((new competency_gateway())->courses_with_competency($c01));

        $this->assertSame(['alpha', 'Été caché', 'Zeta'], array_column($rows, 'fullname'));
        $this->assertSame(
            [(int) $lower->id, (int) $hidden->id, (int) $visible->id],
            array_map('intval', array_column($rows, 'courseid'))
        );
        $this->assertSame(0, (int) $rows[1]->visible);
        $this->assertSame(1, (int) $rows[2]->visible);
        $this->assertSame(['courseid', 'fullname', 'visible'], array_keys((array) $rows[0]));
        $this->assertSame([], (new competency_gateway())->courses_with_competency(0));
    }

    /**
     * T-90: the bulk form gives, per competency, the same courses in the same order as the single form, in one reading.
     */
    public function test_courses_with_competencies_groups_the_same_rows_by_competency(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01, $c02] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $zeta = $this->getDataGenerator()->create_course(['fullname' => 'Zeta']);
        $hidden = $this->getDataGenerator()->create_course(['fullname' => 'Été caché', 'visible' => 0]);
        $alpha = $this->getDataGenerator()->create_course(['fullname' => 'alpha']);
        foreach ([$zeta, $hidden] as $course) {
            $generator->create_course_competency(['courseid' => $course->id, 'competencyid' => $c01]);
        }
        $generator->create_course_competency(['courseid' => $alpha->id, 'competencyid' => $c02]);
        $gateway = new competency_gateway();

        $rows = $gateway->courses_with_competencies([$c01, $c02, 0]);

        $this->assertSame([$c01, $c02], array_keys($rows));
        $this->assertSame(['Été caché', 'Zeta'], array_column($rows[$c01], 'fullname'));
        $this->assertEquals($gateway->courses_with_competency($c01), $rows[$c01]);
        $this->assertSame(['alpha'], array_column($rows[$c02], 'fullname'));
        $this->assertSame([], $gateway->courses_with_competencies([]));
    }

    /**
     * T-90: the framework of each competency, in one reading; an id the core does not hold has no key.
     */
    public function test_frameworks_of_competencies_maps_each_competency_to_its_framework(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$frameworkid, $c01, $c02] = $this->framework_with_two_competencies();
        $gateway = new competency_gateway();

        $this->assertSame(
            [$c01 => $frameworkid, $c02 => $frameworkid],
            $gateway->frameworks_of_competencies([$c01, $c02, 999999])
        );
        $this->assertSame([], $gateway->frameworks_of_competencies([]));
    }

    /**
     * T-90: the rule of the core for reading plans - one's own under planviewown, another's under planview in that
     * person's context. The plugin opens nothing the core does not (conception § 4.3).
     *
     * planviewown is the user archetype's by default; planview is the manager archetype's, so a site manager reads
     * everyone's plans here as in the core. A site that withdrew planviewown from its users hides their plans from them.
     */
    public function test_can_view_plans_of_follows_planviewown_for_self_and_planview_for_others(): void {
        $this->resetAfterTest();
        $candidate = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($candidate->id);

        $this->setUser($candidate);
        $this->assertTrue(competency_gateway::can_view_plans_of((int) $candidate->id), 'planviewown is the user\'s by default.');

        $this->setUser($reader);
        $this->assertFalse(competency_gateway::can_view_plans_of((int) $candidate->id));

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:planview', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $reader->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(competency_gateway::can_view_plans_of((int) $candidate->id));

        // A site that took planviewown away from its users: their own plans are hidden from them, as in the core.
        $userrole = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:planviewown', CAP_PROHIBIT, $userrole, \context_system::instance()->id, true);
        role_assign($userrole, $candidate->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($candidate);
        $this->assertFalse(competency_gateway::can_view_plans_of((int) $candidate->id));
    }

    /**
     * T-90: the core also lets in whoever may MANAGE a person's plans (plan::can_read_user() ends on can_manage_user()):
     * planmanageown on oneself, planmanage on another. The plugin reads under the very rule of the core, no narrower
     * (a site that gave planmanageown and took planviewown would see the plans in the core and "unreadable" here).
     */
    public function test_can_view_plans_of_accepts_the_manage_capabilities_as_the_core_does(): void {
        $this->resetAfterTest();
        $candidate = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $system = \context_system::instance();
        $usercontext = \context_user::instance($candidate->id);

        $userrole = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:planviewown', CAP_PROHIBIT, $userrole, $system->id, true);
        role_assign($userrole, $candidate->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($candidate);
        $this->assertFalse(competency_gateway::can_view_plans_of((int) $candidate->id));

        $ownrole = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:planmanageown', CAP_ALLOW, $ownrole, $system->id, true);
        role_assign($ownrole, $candidate->id, $usercontext->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(competency_gateway::can_view_plans_of((int) $candidate->id));

        // The rights "own" are for oneself only: held by another reader, even everywhere, they open nothing.
        $this->setUser($reader);
        $this->assertFalse(competency_gateway::can_view_plans_of((int) $candidate->id));
        $ownrights = $this->getDataGenerator()->create_role();
        foreach (['planviewown', 'planmanageown'] as $own) {
            assign_capability('moodle/competency:' . $own, CAP_ALLOW, $ownrights, $system->id, true);
        }
        role_assign($ownrights, $reader->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(competency_gateway::can_view_plans_of((int) $candidate->id));
        $managerrole = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:planmanage', CAP_ALLOW, $managerrole, $system->id, true);
        role_assign($managerrole, $reader->id, $usercontext->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(competency_gateway::can_view_plans_of((int) $candidate->id));
    }

    /**
     * T-90: the same for the drafts - planmanageowndraft on oneself and planmanagedraft on another open them as the
     * core's plan::can_read_user_draft() does, through can_manage_user_draft().
     */
    public function test_can_view_draft_plans_of_accepts_the_manage_capabilities_as_the_core_does(): void {
        $this->resetAfterTest();
        $candidate = $this->getDataGenerator()->create_user();
        $reader = $this->getDataGenerator()->create_user();
        $system = \context_system::instance();
        $usercontext = \context_user::instance($candidate->id);

        $this->setUser($candidate);
        $this->assertFalse(competency_gateway::can_view_draft_plans_of((int) $candidate->id));
        $ownrole = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:planmanageowndraft', CAP_ALLOW, $ownrole, $system->id, true);
        role_assign($ownrole, $candidate->id, $usercontext->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(competency_gateway::can_view_draft_plans_of((int) $candidate->id));

        // The rights "own" are for oneself only: held by another reader, even everywhere, they open nothing.
        $this->setUser($reader);
        $this->assertFalse(competency_gateway::can_view_draft_plans_of((int) $candidate->id));
        $ownrights = $this->getDataGenerator()->create_role();
        foreach (['planviewowndraft', 'planmanageowndraft'] as $own) {
            assign_capability('moodle/competency:' . $own, CAP_ALLOW, $ownrights, $system->id, true);
        }
        role_assign($ownrights, $reader->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(competency_gateway::can_view_draft_plans_of((int) $candidate->id));
        $managerrole = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:planmanagedraft', CAP_ALLOW, $managerrole, $system->id, true);
        role_assign($managerrole, $reader->id, $usercontext->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(competency_gateway::can_view_draft_plans_of((int) $candidate->id));
    }

    /**
     * T-90: a draft plan - draft, waiting for review, in review - is the core's secret until planviewowndraft, which no
     * role holds by default; a third party needs planviewdraft. The listing leaves the drafts out unless asked for them.
     */
    public function test_draft_plans_are_left_out_unless_the_reader_may_see_drafts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $candidate = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $statuses = [
            'Actif' => competency_gateway::PLAN_ACTIVE,
            'Brouillon' => competency_gateway::PLAN_DRAFT,
            'En attente' => competency_gateway::PLAN_WAITING_FOR_REVIEW,
            'En révision' => competency_gateway::PLAN_IN_REVIEW,
            'Terminé' => competency_gateway::PLAN_COMPLETE,
        ];
        foreach ($statuses as $name => $status) {
            $generator->create_plan(['userid' => $candidate->id, 'name' => $name, 'status' => $status]);
        }
        $gateway = new competency_gateway();
        $names = fn(bool $drafts, bool $active = true): array => array_column(
            array_values($gateway->list_user_plans((int) $candidate->id, $drafts, $active)),
            'name'
        );

        $this->assertSame(['Actif', 'Terminé'], $names(false));
        $this->assertSame(['Actif', 'Brouillon', 'En attente', 'En révision', 'Terminé'], $names(true));
        // A reader who holds the right on drafts and not the right on plans is listed the drafts alone, as in the core.
        $this->assertSame(['Brouillon', 'En attente', 'En révision'], $names(true, false));
        $this->assertSame([], $names(false, false), 'Asked for neither kind, the listing gives none.');

        $this->setUser($candidate);
        $this->assertFalse(
            competency_gateway::can_view_draft_plans_of((int) $candidate->id),
            'planviewowndraft is nobody\'s by default.'
        );
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:planviewowndraft', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $candidate->id, \context_user::instance($candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(competency_gateway::can_view_draft_plans_of((int) $candidate->id));

        $reader = $this->getDataGenerator()->create_user();
        $this->setUser($reader);
        $this->assertFalse(competency_gateway::can_view_draft_plans_of((int) $candidate->id));
    }

    /**
     * T-90: a plan whose status the core does not know is listed whichever kind of plan is asked for, as the core lists it:
     * its filters leave out the statuses a reader may not read (NOT IN) and keep any other. The core's own persistence refuses
     * such a status, so the test writes it straight into the table.
     */
    public function test_a_plan_of_a_status_the_core_does_not_know_is_listed_as_the_core_lists_it(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $candidate = $this->getDataGenerator()->create_user();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $plan = $generator->create_plan([
            'userid' => $candidate->id, 'name' => 'Inconnu', 'status' => competency_gateway::PLAN_ACTIVE,
        ]);
        $DB->set_field('competency_plan', 'status', 9, ['id' => $plan->get('id')]);
        $gateway = new competency_gateway();

        foreach ([[false, true], [true, false], [true, true], [false, false]] as [$drafts, $active]) {
            $this->assertSame(
                ['Inconnu'],
                array_column(array_values($gateway->list_user_plans((int) $candidate->id, $drafts, $active)), 'name'),
                'Drafts ' . var_export($drafts, true) . ', active ' . var_export($active, true) . '.'
            );
        }
    }

    /**
     * T-90: the core lists the courses that carry a competency to who holds coursecompetencyview, or coursecompetencymanage,
     * in the course (api::list_courses_using_competency()). Every signed-in account holds the first by default; a site may
     * withdraw it from one course.
     */
    public function test_can_view_course_competencies_follows_the_core_rule(): void {
        global $CFG;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertTrue(
            competency_gateway::can_view_course_competencies((int) $course->id),
            'coursecompetencyview is the user\'s by default.'
        );

        assign_capability(
            'moodle/competency:coursecompetencyview',
            CAP_PROHIBIT,
            $CFG->defaultuserroleid,
            \context_course::instance($course->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(competency_gateway::can_view_course_competencies((int) $course->id));
        $other = $this->getDataGenerator()->create_course();
        $this->assertTrue(
            competency_gateway::can_view_course_competencies((int) $other->id),
            'The withdrawal is that of one course.'
        );

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:coursecompetencymanage', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(
            competency_gateway::can_view_course_competencies((int) $course->id),
            'Who may manage the competencies of a course reads them, as in the core.'
        );

        $this->assertFalse(
            competency_gateway::can_view_course_competencies(999999),
            'A course that does not exist is not readable.'
        );
    }

    /**
     * A framework of the core with the competencies given, in the order given, a parent being named before its children.
     *
     * @param string $idnumber The identifier of the framework (and the prefix of its competencies').
     * @param string $name Its short name.
     * @param array $competencies Key => key of the parent, or null for a competency that sits under none.
     * @return array The framework id, then the competency ids by key.
     */
    private function a_framework_of(string $idnumber, string $name, array $competencies): array {
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $framework = $generator->create_framework(['idnumber' => $idnumber, 'shortname' => $name]);
        $ids = [];
        foreach ($competencies as $key => $parent) {
            $record = [
                'competencyframeworkid' => $framework->get('id'),
                'idnumber' => $idnumber . '-' . $key,
                'shortname' => 'Competency ' . $key,
            ];
            if ($parent !== null) {
                $record['parentid'] = $ids[$parent];
            }
            $ids[$key] = (int) $generator->create_competency($record)->get('id');
        }

        return [(int) $framework->get('id'), $ids];
    }

    /**
     * T-90 lot 4: the size of a framework is its blocs (a competency other competencies sit under) and its competencies.
     *
     * A record with two levels has blocs; a record of one level - the France compétences export, whose five competencies
     * are five blocs of nothing - has none, which the screen says as such. A parent that is not in the same framework
     * is not a bloc of it. A framework without any competency is there, with zeros; one the core no longer holds is not.
     */
    public function test_framework_summaries_counts_blocs_and_competencies_per_framework(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$two, $in] = $this->a_framework_of(
            'SZ2',
            'Two levels',
            ['B1' => null, 'B2' => null, 'C1' => 'B1', 'C2' => 'B1', 'C3' => 'B2']
        );
        [$flat] = $this->a_framework_of('SZ1', 'One level', ['F1' => null, 'F2' => null, 'F3' => null]);
        [$empty] = $this->a_framework_of('SZ0', 'Empty', []);
        // A competency whose parent sits in another framework: that parent is a bloc of nothing here.
        [$stray, $strayids] = $this->a_framework_of('SZS', 'Stray', ['S1' => null]);
        $DB->set_field('competency', 'parentid', $in['B1'], ['id' => $strayids['S1']]);

        $summaries = (new competency_gateway())->framework_summaries([$two, $flat, $empty, $stray, 0, 999999]);

        $this->assertEqualsCanonicalizing([$two, $flat, $empty, $stray], array_keys($summaries));
        $this->assertSame('Two levels', $summaries[$two]->name);
        $this->assertSame(2, $summaries[$two]->blocs);
        $this->assertSame(5, $summaries[$two]->competencies);
        $this->assertSame(0, $summaries[$flat]->blocs);
        $this->assertSame(3, $summaries[$flat]->competencies);
        $this->assertSame(0, $summaries[$empty]->blocs);
        $this->assertSame(
            0,
            $summaries[$empty]->competencies,
            'An empty framework is there, with a zero: it is not a deleted one.'
        );
        $this->assertSame(0, $summaries[$stray]->blocs);
        $this->assertSame(1, $summaries[$stray]->competencies);
        $this->assertSame([], (new competency_gateway())->framework_summaries([]));
    }

    /**
     * T-90 lot 4: the reading of the sizes is the same few queries for one framework or for many.
     */
    public function test_framework_summaries_reads_do_not_grow_with_the_number_of_frameworks(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            [$ids[]] = $this->a_framework_of('RD' . $i, 'Record ' . $i, ['B' => null, 'C1' => 'B', 'C2' => 'B']);
        }
        $gateway = new competency_gateway();
        $gateway->framework_summaries([$ids[0]]);

        $before = $DB->perf_get_reads();
        $gateway->framework_summaries([$ids[0]]);
        $one = $DB->perf_get_reads() - $before;
        $before = $DB->perf_get_reads();
        $gateway->framework_summaries($ids);
        $five = $DB->perf_get_reads() - $before;

        $this->assertSame($one, $five);
    }

    /**
     * T-90 lot 4: the competencies of several frameworks, in one reading.
     */
    public function test_competencies_of_frameworks_groups_the_ids_by_framework(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$first, $a] = $this->a_framework_of('CF1', 'First', ['B' => null, 'C' => 'B']);
        [$second, $b] = $this->a_framework_of('CF2', 'Second', ['X' => null]);
        [$empty] = $this->a_framework_of('CF0', 'Empty', []);

        $out = (new competency_gateway())->competencies_of_frameworks([$second, $first, $empty, 999999]);

        $this->assertSame([$a['B'], $a['C']], $out[$first]);
        $this->assertSame([$b['X']], $out[$second]);
        $this->assertArrayNotHasKey($empty, $out);
        $this->assertArrayNotHasKey(999999, $out);
        $this->assertSame([], (new competency_gateway())->competencies_of_frameworks([]));
    }

    /**
     * T-90 lot 4: the templates of the core that carry a competency, a hidden one said to be hidden and never omitted.
     */
    public function test_templates_with_competencies_names_the_templates_and_marks_the_hidden_ones(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $ids] = $this->a_framework_of('TP1', 'Templates', ['C1' => null, 'C2' => null, 'C3' => null]);
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $zeta = $generator->create_template(['shortname' => 'Zeta pathway']);
        $alpha = $generator->create_template(['shortname' => 'Alpha pathway']);
        $secret = $generator->create_template(['shortname' => 'Secret pathway', 'visible' => 0]);
        $generator->create_template_competency(['templateid' => $zeta->get('id'), 'competencyid' => $ids['C1']]);
        $generator->create_template_competency(['templateid' => $alpha->get('id'), 'competencyid' => $ids['C1']]);
        $generator->create_template_competency(['templateid' => $alpha->get('id'), 'competencyid' => $ids['C2']]);
        $generator->create_template_competency(['templateid' => $secret->get('id'), 'competencyid' => $ids['C2']]);

        $out = (new competency_gateway())->templates_with_competencies([$ids['C1'], $ids['C2'], $ids['C3']]);

        $this->assertSame(
            ['Alpha pathway', 'Zeta pathway'],
            array_values(array_map(fn($template) => $template->shortname, $out[$ids['C1']]))
        );
        $this->assertSame(
            ['Alpha pathway', 'Secret pathway'],
            array_values(array_map(fn($template) => $template->shortname, $out[$ids['C2']]))
        );
        $this->assertSame(1, $out[$ids['C1']][(int) $zeta->get('id')]->visible);
        $this->assertSame(0, $out[$ids['C2']][(int) $secret->get('id')]->visible);
        $this->assertArrayNotHasKey($ids['C3'], $out, 'A competency no template carries has no key.');
        $this->assertSame([], (new competency_gateway())->templates_with_competencies([]));
    }

    /**
     * T-90 lot 4: the name of a hidden template is the core's secret unless the reader may manage templates, which the
     * core asks at the system level (api::list_templates_using_competency).
     */
    public function test_can_view_hidden_templates_follows_templatemanage_at_the_system_level(): void {
        $this->resetAfterTest();
        $reader = $this->getDataGenerator()->create_user();
        $this->setUser($reader);
        $this->assertFalse(competency_gateway::can_view_hidden_templates());

        $role = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:templateview', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, $reader->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(
            competency_gateway::can_view_hidden_templates(),
            'To read the templates is not to read the hidden ones.'
        );

        assign_capability('moodle/competency:templatemanage', CAP_ALLOW, $role, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(competency_gateway::can_view_hidden_templates());
    }

    /**
     * Two frameworks and the people and plans of the count of plans: see test_plan_counts_of_frameworks_*.
     *
     * Framework A has C1 and C2, framework F has F1. Four people:
     *  - one has a plan of its own on C1 and F1, and a second plan, on a template, that carries C2;
     *  - one has a complete plan on a template whose archive holds C1, while the plan's own list still holds F1;
     *  - one has a draft plan on C1, and a plan waiting for review that holds nothing;
     *  - one has a plan on C1 and is not among those asked.
     *
     * @return array The framework ids (A, F), the competency ids, and the user ids in the order above.
     */
    private function people_with_plans(): array {
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        [$a, $ac] = $this->a_framework_of('PA', 'Framework A', ['C1' => null, 'C2' => null]);
        [$f, $fc] = $this->a_framework_of('PF', 'Framework F', ['F1' => null]);
        $users = [];
        for ($i = 0; $i < 4; $i++) {
            $users[] = (int) $this->getDataGenerator()->create_user()->id;
        }

        $plan = $generator->create_plan(['userid' => $users[0], 'status' => competency_gateway::PLAN_ACTIVE]);
        $generator->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $ac['C1']]);
        $generator->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $fc['F1']]);
        $template = $generator->create_template(['shortname' => 'Template A']);
        $generator->create_template_competency(['templateid' => $template->get('id'), 'competencyid' => $ac['C2']]);
        $templated = $generator->create_plan([
            'userid' => $users[0], 'templateid' => $template->get('id'), 'status' => competency_gateway::PLAN_ACTIVE,
        ]);
        // A leftover line of its own list, F1: a plan on a template reads the template's list alone, so F1 is not its.
        $generator->create_plan_competency(['planid' => $templated->get('id'), 'competencyid' => $fc['F1']]);

        $complete = $generator->create_plan([
            'userid' => $users[1],
            'templateid' => $template->get('id'),
            'status' => competency_gateway::PLAN_COMPLETE,
        ]);
        $generator->create_user_competency_plan([
            'userid' => $users[1], 'competencyid' => $ac['C1'], 'planid' => $complete->get('id'),
        ]);
        $generator->create_plan_competency(['planid' => $complete->get('id'), 'competencyid' => $fc['F1']]);
        // Two more complete plans whose archive is empty: one on a template that carries F1, one with F1 in its own list.
        // A complete plan is read from its archive alone, so neither names F1.
        $templatef = $generator->create_template(['shortname' => 'Template F']);
        $generator->create_template_competency(['templateid' => $templatef->get('id'), 'competencyid' => $fc['F1']]);
        $generator->create_plan([
            'userid' => $users[1], 'templateid' => $templatef->get('id'), 'status' => competency_gateway::PLAN_COMPLETE,
        ]);
        $closed = $generator->create_plan(['userid' => $users[1], 'status' => competency_gateway::PLAN_COMPLETE]);
        $generator->create_plan_competency(['planid' => $closed->get('id'), 'competencyid' => $fc['F1']]);

        $draft = $generator->create_plan(['userid' => $users[2], 'status' => competency_gateway::PLAN_DRAFT]);
        $generator->create_plan_competency(['planid' => $draft->get('id'), 'competencyid' => $ac['C1']]);
        $generator->create_plan(['userid' => $users[2], 'status' => competency_gateway::PLAN_WAITING_FOR_REVIEW]);
        // Plans awaiting a review, or in one, that hold a competency of A: counted like the draft, whatever their status.
        $waiting = $generator->create_plan(['userid' => $users[2], 'status' => competency_gateway::PLAN_WAITING_FOR_REVIEW]);
        $generator->create_plan_competency(['planid' => $waiting->get('id'), 'competencyid' => $ac['C1']]);
        $inreview = $generator->create_plan(['userid' => $users[2], 'status' => competency_gateway::PLAN_IN_REVIEW]);
        $generator->create_plan_competency(['planid' => $inreview->get('id'), 'competencyid' => $ac['C1']]);

        $other = $generator->create_plan(['userid' => $users[3], 'status' => competency_gateway::PLAN_ACTIVE]);
        $generator->create_plan_competency(['planid' => $other->get('id'), 'competencyid' => $ac['C1']]);

        return [$a, $f, $users];
    }

    /**
     * T-90 lot 4: the number of plans per framework counts PLANS - once each - of the people asked, drafts included
     * (decision of the owner, 4 November 2026), and reads the competencies of a plan where the core reads them.
     */
    public function test_plan_counts_of_frameworks_counts_each_plan_once_where_the_core_reads_its_competencies(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [$a, $f, $users] = $this->people_with_plans();

        $counts = (new competency_gateway())->plan_counts_of_frameworks([$users[0], $users[1], $users[2]]);

        // A: the plan of the first (C1), its plan on a template (C2), the complete plan (archive C1), the draft (C1), the
        // plan awaiting a review (C1) and the plan in review (C1): six plans.
        $this->assertSame(6, $counts[$a]);
        // F: the plan of the first only - the complete plan is read from its archive, not from its live list.
        $this->assertSame(1, $counts[$f]);
        $this->assertCount(2, $counts, 'A plan that holds nothing, or that nobody asked for, adds no framework.');
    }

    /**
     * T-90 lot 4: a person who is not asked about is not counted, nobody asked is no query, and a long list of people
     * is cut without counting a plan twice.
     */
    public function test_plan_counts_of_frameworks_leaves_out_who_is_not_asked_and_cuts_a_long_list(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$a, , $users] = $this->people_with_plans();
        $gateway = new competency_gateway();

        $before = $DB->perf_get_reads();
        $this->assertSame([], $gateway->plan_counts_of_frameworks([]));
        $this->assertSame($before, $DB->perf_get_reads());

        $this->assertSame([], $gateway->plan_counts_of_frameworks([$users[3] + 100000]));
        $this->assertSame(1, $gateway->plan_counts_of_frameworks([$users[3]])[$a]);

        // The people are spread over four cuts of 1000 so that the counts of the cuts have to be added, not overwritten.
        $long = array_merge(
            [$users[0]],
            range(500000, 501499),
            [$users[1], $users[2]],
            range(510000, 511499),
            [$users[3]]
        );
        $this->assertGreaterThan(3000, count($long));
        $this->assertSame(7, $gateway->plan_counts_of_frameworks($long)[$a]);
    }

    /**
     * T-90 lot 4: the count reads neither the name of a plan, nor a grade, nor a reviewer, and returns nobody's id.
     */
    public function test_plan_counts_of_frameworks_reads_neither_name_nor_grade_nor_person(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, , $users] = $this->people_with_plans();

        $DB->set_debug(true);
        ob_start();
        try {
            (new competency_gateway())->plan_counts_of_frameworks($users);
        } finally {
            $sql = ob_get_clean();
            $DB->set_debug(false);
        }

        $this->assertStringContainsString('competency_usercompplan', $sql);
        $this->assertStringNotContainsString('grade', $sql);
        $this->assertStringNotContainsString('proficiency', $sql);
        $this->assertStringNotContainsString('reviewerid', $sql);
        $this->assertDoesNotMatchRegularExpression('~\bp\.name\b~', $sql);
        $this->assertDoesNotMatchRegularExpression(
            '~SELECT[^F]*\bp\.userid\b~',
            $sql,
            'The people stay in the WHERE, never in the answer.'
        );
    }

    /**
     * T-90 lot 1 bis: the activities that carry each competency, in one reading, with the course they are in.
     */
    public function test_modules_with_competencies_groups_the_activities_by_competency(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01, $c02] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cours A']);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz un']);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Devoir']);
        $generator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $c01]);
        $generator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $c02]);
        $generator->create_course_module_competency(['cmid' => $assign->cmid, 'competencyid' => $c02]);

        $rows = (new competency_gateway())->modules_with_competencies([$c01, $c02, 0]);

        $this->assertSame([$c01, $c02], array_keys($rows));
        $this->assertCount(1, $rows[$c01]);
        $this->assertCount(2, $rows[$c02]);
        $first = $rows[$c01][(int) $quiz->cmid];
        $this->assertSame((int) $quiz->cmid, $first->cmid);
        $this->assertSame((int) $course->id, $first->courseid);
        $this->assertSame('quiz', $first->modname);
        $this->assertSame('Cours A', $first->coursefullname);
        $this->assertSame(1, $first->coursevisible);
        $this->assertSame([], (new competency_gateway())->modules_with_competencies([]));
        $this->assertSame([], (new competency_gateway())->modules_with_competencies([999999]));
    }

    /**
     * T-90 lot 1 bis: an activity being deleted is not counted.
     */
    public function test_modules_with_competencies_leaves_out_an_activity_being_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $course = $this->getDataGenerator()->create_course();
        $gone = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $kept = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $generator->create_course_module_competency(['cmid' => $gone->cmid, 'competencyid' => $c01]);
        $generator->create_course_module_competency(['cmid' => $kept->cmid, 'competencyid' => $c01]);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $gone->cmid]);

        $rows = (new competency_gateway())->modules_with_competencies([$c01]);

        $this->assertSame([(int) $kept->cmid], array_keys($rows[$c01]));
    }

    /**
     * T-90 lot 1 bis: one reading for any number of competencies and activities, contexts included.
     */
    public function test_modules_with_competencies_reads_once_whatever_the_number_of_activities(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01, $c02] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $course = $this->getDataGenerator()->create_course();
        $gateway = new competency_gateway();

        $one = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $generator->create_course_module_competency(['cmid' => $one->cmid, 'competencyid' => $c01]);
        $before = $DB->perf_get_reads();
        $gateway->modules_with_competencies([$c01, $c02]);
        $small = $DB->perf_get_reads() - $before;

        for ($i = 0; $i < 6; $i++) {
            $more = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
            $generator->create_course_module_competency(['cmid' => $more->cmid, 'competencyid' => $c02]);
        }
        \context_helper::reset_caches();
        $before = $DB->perf_get_reads();
        $gateway->modules_with_competencies([$c01, $c02]);
        $large = $DB->perf_get_reads() - $before;

        $this->assertGreaterThan(0, $small);
        $this->assertSame($small, $large, 'The number of readings does not grow with the number of activities.');
    }

    /**
     * T-90 lot 1 bis: the rule the activity applies to a competency (the start of an assessment) is never read.
     */
    public function test_modules_with_competencies_reads_neither_the_rule_nor_the_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $generator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $c01]);

        $DB->set_debug(true);
        ob_start();
        try {
            $rows = (new competency_gateway())->modules_with_competencies([$c01]);
        } finally {
            $sql = ob_get_clean();
            $DB->set_debug(false);
        }

        $this->assertStringContainsString('competency_modulecomp', $sql);
        $this->assertStringNotContainsString('ruleoutcome', $sql);
        $this->assertStringNotContainsString('overridegrade', $sql);
        // The whole list between SELECT and FROM, whatever its order: no star anywhere in it.
        $this->assertSame(1, preg_match('/SELECT(.*?)FROM/is', $sql, $list));
        $this->assertDoesNotMatchRegularExpression('/\*/', $list[1]);
        $this->assertSame(
            ['cmid', 'courseid', 'modname', 'coursefullname', 'coursevisible'],
            array_keys((array) $rows[$c01][(int) $quiz->cmid])
        );
    }

    /**
     * T-90 lot 1 bis: the right to read the competencies of an activity is the core's, in the context of the module.
     */
    public function test_can_view_module_competencies_follows_the_core_rule(): void {
        global $CFG;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertTrue(competency_gateway::can_view_module_competencies((int) $quiz->cmid));
        $this->assertFalse(competency_gateway::can_view_module_competencies(999999), 'No such activity: no right.');

        assign_capability(
            'moodle/competency:coursecompetencyview',
            CAP_PROHIBIT,
            $CFG->defaultuserroleid,
            \context_module::instance($quiz->cmid)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(competency_gateway::can_view_module_competencies((int) $quiz->cmid));
        $other = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $this->assertTrue(
            competency_gateway::can_view_module_competencies((int) $other->cmid),
            'The withdrawal is that of one activity.'
        );
    }

    /**
     * T-90 lot 1 bis: an orphan row (an activity that is gone) gives nothing, and the rows are not mixed between courses.
     */
    public function test_modules_with_competencies_gives_nothing_for_an_orphan_row(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [, $c01] = $this->framework_with_two_competencies();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $first = $this->getDataGenerator()->create_course(['fullname' => 'Premier']);
        $second = $this->getDataGenerator()->create_course(['fullname' => 'Second']);
        $one = $this->getDataGenerator()->create_module('quiz', ['course' => $first->id]);
        $two = $this->getDataGenerator()->create_module('assign', ['course' => $second->id]);
        $generator->create_course_module_competency(['cmid' => $one->cmid, 'competencyid' => $c01]);
        $generator->create_course_module_competency(['cmid' => $two->cmid, 'competencyid' => $c01]);
        // A row for an activity that does not exist at all.
        $DB->insert_record('competency_modulecomp', (object) [
            'cmid' => 987654, 'competencyid' => $c01, 'sortorder' => 5, 'ruleoutcome' => 0, 'overridegrade' => 0,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 2,
        ]);

        $rows = (new competency_gateway())->modules_with_competencies([$c01])[$c01];

        $this->assertEqualsCanonicalizing([(int) $one->cmid, (int) $two->cmid], array_keys($rows));
        $this->assertSame((int) $first->id, $rows[(int) $one->cmid]->courseid);
        $this->assertSame((int) $second->id, $rows[(int) $two->cmid]->courseid);
        $this->assertSame('assign', $rows[(int) $two->cmid]->modname);
    }
}
