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

use tool_vae\local\role_installer;

/**
 * The creation of the VAE roles from the presets shipped with the plugin.
 *
 * WHAT IS ASSERTED. That a role is created with exactly the rights of its preset and nothing else; that a role which
 * exists is never touched; that only an administrator who may manage roles creates one; that the rights kept out of the
 * roles are refused even if a preset were edited to grant them; that the three base roles never carry evaluate or
 * certify, which only the two optional presets add.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(role_installer::class)]
final class role_installer_test extends \advanced_testcase {
    /**
     * Rights of a role of the plugin, as granted at the site.
     *
     * @param string $shortname
     * @return string[] Sorted.
     */
    private function rights_of(string $shortname): array {
        global $DB;

        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
        $rights = $DB->get_fieldset_select(
            'role_capabilities',
            'capability',
            'roleid = ? AND contextid = ? AND permission = ?',
            [$roleid, \context_system::instance()->id, CAP_ALLOW]
        );
        sort($rights);

        return $rights;
    }

    public function test_the_five_presets_are_listed_three_of_base_and_two_optional(): void {
        $presets = role_installer::presets();

        $this->assertEqualsCanonicalizing(
            ['vaeiste', 'vae_responsable', 'vae_direction', 'vae_responsable_evaluateur', 'vae_direction_certificatrice'],
            array_keys($presets)
        );
        foreach (['vaeiste', 'vae_responsable', 'vae_direction'] as $base) {
            $this->assertFalse($presets[$base]->optional, $base);
        }
        foreach (['vae_responsable_evaluateur', 'vae_direction_certificatrice'] as $extra) {
            $this->assertTrue($presets[$extra]->optional, $extra);
        }
    }

    public function test_a_role_is_created_with_exactly_the_rights_of_its_preset_and_the_event_is_logged(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $sink = $this->redirectEvents();

        $roleid = role_installer::create('vaeiste');

        $role = $DB->get_record('role', ['id' => $roleid], '*', MUST_EXIST);
        $this->assertSame('vaeiste', $role->shortname);
        $this->assertSame('student', $role->archetype);
        $this->assertEqualsCanonicalizing(
            ['tool/vae:declare', 'tool/vae:draftown', 'tool/vae:export', 'tool/vae:positionself', 'tool/vae:viewown'],
            $this->rights_of('vaeiste'),
            'No right of the student archetype comes with the role: exactly the five of the preset.'
        );
        $levels = $DB->get_fieldset_select('role_context_levels', 'contextlevel', 'roleid = ?', [$roleid]);
        $this->assertSame([(string) CONTEXT_SYSTEM], array_map('strval', $levels));
        $events = array_filter($sink->get_events(), fn($e) => $e instanceof \core\event\role_created);
        $this->assertCount(1, $events);
    }

    public function test_a_user_level_role_gets_the_user_context_level_only(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $roleid = role_installer::create('vae_responsable');

        $levels = $DB->get_fieldset_select('role_context_levels', 'contextlevel', 'roleid = ?', [$roleid]);
        $this->assertSame([(string) CONTEXT_USER], array_map('strval', $levels));
    }

    public function test_a_role_that_exists_is_never_touched(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $roleid = create_role('Mon rôle', 'vae_direction', 'Fait à la main.');
        assign_capability('tool/vae:viewany', CAP_ALLOW, $roleid, \context_system::instance()->id, true);

        try {
            role_installer::create('vae_direction');
            $this->fail('A refusal was expected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorroleexists', $e->errorcode);
        }

        $this->assertSame('Fait à la main.', $DB->get_field('role', 'description', ['id' => $roleid]));
        $this->assertSame(['tool/vae:viewany'], $this->rights_of('vae_direction'));
    }

    public function test_an_unknown_preset_is_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorroleunknownpreset', 'tool_vae'));
        role_installer::create('../../etc/passwd');
    }

    public function test_only_who_may_manage_roles_creates_one(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            role_installer::create('vaeiste');
            $this->fail('A refusal was expected.');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString(get_capability_string('moodle/role:manage'), $e->getMessage());
        }

        $this->assertFalse($DB->record_exists('role', ['shortname' => 'vaeiste']));
    }

    public function test_a_right_kept_out_of_the_roles_is_refused_even_in_an_edited_preset(): void {
        foreach (['import', 'importdossier', 'identityview', 'useai', 'withdrawany', 'linkcourse'] as $barred) {
            try {
                role_installer::check_rights(['tool/vae:declare' => CAP_ALLOW, 'tool/vae:' . $barred => CAP_ALLOW], true);
                $this->fail("'$barred' must be refused.");
            } catch (\moodle_exception $e) {
                $this->assertSame('errorrolebadright', $e->errorcode, $barred);
            }
        }
        // A right the plugin does not declare is refused too.
        $this->expectException(\moodle_exception::class);
        role_installer::check_rights(['tool/vae:nothing' => CAP_ALLOW], true);
    }

    public function test_only_the_two_optional_presets_carry_evaluate_or_certify(): void {
        foreach (role_installer::presets() as $shortname => $preset) {
            $sensitive = array_intersect($preset->rights, ['tool/vae:evaluate', 'tool/vae:certify', 'tool/vae:issuestructure']);
            if ($preset->optional) {
                $this->assertNotEmpty($sensitive, $shortname);
            } else {
                $this->assertSame([], array_values($sensitive), $shortname . ' is a base role.');
            }
        }
        $presets = role_installer::presets();
        $this->assertContains('tool/vae:evaluate', $presets['vae_responsable_evaluateur']->rights);
        $this->assertContains('tool/vae:certify', $presets['vae_direction_certificatrice']->rights);
        $this->assertContains('tool/vae:issuestructure', $presets['vae_direction_certificatrice']->rights);
        $this->assertContains('tool/vae:viewany', $presets['vae_direction_certificatrice']->rights, 'certify needs viewany.');
    }

    public function test_the_upgrade_grants_a_missing_right_to_a_preset_role_and_nothing_else(): void {
        global $DB;
        $this->resetAfterTest();
        $system = \context_system::instance();
        // A Vaeiste role installed before positionself existed: the four rights of the older preset, a description of the site.
        $roleid = create_role('Vaeiste', 'vaeiste', 'Créé avant le lot 1a.', 'student');
        foreach (['declare', 'draftown', 'export', 'viewown'] as $right) {
            assign_capability('tool/vae:' . $right, CAP_ALLOW, $roleid, $system->id, true);
        }
        $before = $DB->get_record('role', ['id' => $roleid], '*', MUST_EXIST);
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        $levels = get_role_contextlevels($roleid);
        $this->assertSame([CONTEXT_SYSTEM], array_map('intval', array_values($levels)), 'A level set beforehand.');

        $this->assertTrue(role_installer::grant_missing('vaeiste', 'tool/vae:positionself'));

        $this->assertSame(
            ['tool/vae:declare', 'tool/vae:draftown', 'tool/vae:export', 'tool/vae:positionself', 'tool/vae:viewown'],
            $this->rights_of('vaeiste')
        );
        $this->assertEquals($before, $DB->get_record('role', ['id' => $roleid], '*', MUST_EXIST), 'No column of the role moves.');
        $this->assertSame($levels, get_role_contextlevels($roleid), 'The context levels of the role do not move.');
        $this->assertSame(
            5,
            $DB->count_records('role_capabilities', ['roleid' => $roleid]),
            'Nothing is granted outside the system level.'
        );
        $this->assertFalse(role_installer::grant_missing('vaeiste', 'tool/vae:positionself'), 'A second call adds nothing.');
        $this->assertSame(5, $DB->count_records('role_capabilities', ['roleid' => $roleid]));
    }

    public function test_a_right_the_administrator_prevented_or_prohibited_is_never_rewritten(): void {
        global $DB;
        $this->resetAfterTest();
        $system = \context_system::instance();
        $held = ['contextid' => $system->id, 'capability' => 'tool/vae:positionself'];
        foreach ([CAP_PREVENT => 'vaeiste', CAP_PROHIBIT => 'vae_responsable'] as $permission => $shortname) {
            $roleid = create_role('Rôle ' . $shortname, $shortname, '');
            assign_capability('tool/vae:positionself', $permission, $roleid, $system->id);

            $this->assertFalse(role_installer::grant_missing($shortname, 'tool/vae:positionself'), $shortname);

            $this->assertSame(
                (string) $permission,
                (string) $DB->get_field('role_capabilities', 'permission', $held + ['roleid' => $roleid], MUST_EXIST),
                $shortname
            );
        }
    }

    public function test_the_upgrade_grants_nothing_to_a_role_that_is_not_a_preset(): void {
        global $DB;
        $this->resetAfterTest();
        $managerid = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        $before = $DB->count_records('role_capabilities', ['roleid' => $managerid]);

        $this->assertFalse(role_installer::grant_missing('manager', 'tool/vae:positionself'));

        $this->assertSame($before, $DB->count_records('role_capabilities', ['roleid' => $managerid]));
        $this->assertFalse($DB->record_exists(
            'role_capabilities',
            ['roleid' => $managerid, 'capability' => 'tool/vae:positionself']
        ));
        // A preset whose role is not on the site: nothing is created.
        $this->assertFalse(role_installer::grant_missing('vaeiste', 'tool/vae:positionself'));
        $this->assertFalse($DB->record_exists('role', ['shortname' => 'vaeiste']));
    }

    public function test_a_barred_right_is_never_granted(): void {
        global $DB;
        $this->resetAfterTest();
        $roleid = create_role('Direction', 'vae_direction', '');

        foreach (array_merge(role_installer::BARRED, ['tool/vae:evaluate', 'tool/vae:certify']) as $capability) {
            try {
                role_installer::grant_missing('vae_direction', $capability);
                $this->fail("'$capability' must be refused.");
            } catch (\moodle_exception $e) {
                $this->assertSame('errorrolebadright', $e->errorcode, $capability);
            }
        }
        $this->assertSame(0, $DB->count_records('role_capabilities', ['roleid' => $roleid]));
    }

    public function test_grant_missing_refuses_a_right_the_core_has_not_registered(): void {
        global $DB;
        $this->resetAfterTest();
        create_role('Vaeiste', 'vaeiste', '', 'student');
        // The state of a site whose upgrade has not yet registered db/access.php: the capability is not in the table.
        $DB->delete_records('capabilities', ['name' => 'tool/vae:positionself']);
        \cache::make('core', 'capabilities')->purge();
        $this->assertNull(get_capability_info('tool/vae:positionself'));

        try {
            role_installer::grant_missing('vaeiste', 'tool/vae:positionself');
            $this->fail('A refusal was expected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorrolebadright', $e->errorcode);
        }

        // What the upgrade step does first: register the capabilities; then the right is granted.
        update_capabilities('tool_vae');
        $this->assertTrue(role_installer::grant_missing('vaeiste', 'tool/vae:positionself'));
        $this->assertContains('tool/vae:positionself', $this->rights_of('vaeiste'));
    }

    /**
     * The step 2026110408 of db/upgrade.php, played as the site plays it on a plugin at 2026110407 (D-1b-C): the four presets
     * of the managers and the direction installed before lot 1b receive annotate; one the administrator prevented keeps its
     * prevention; one that is not on the site is not created; the vaeiste receives positionself, never annotate. The upgrade then
     * runs on to the version the plugin declares (the publication build 2026110409 adds an empty step).
     */
    public function test_the_upgrade_grants_annotate_to_the_four_preset_roles_when_they_exist(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/admin/tool/vae/db/upgrade.php');
        $this->resetAfterTest();
        $system = \context_system::instance();
        $old = [
            'vaeiste' => ['declare', 'viewown', 'draftown', 'export'],
            'vae_responsable' => ['viewassigned', 'attest', 'readdeposit', 'writeopinion'],
            'vae_responsable_evaluateur' => ['viewassigned', 'attest', 'readdeposit', 'writeopinion', 'evaluate'],
            'vae_direction_certificatrice' => ['viewany', 'export', 'draftview', 'readdeposit', 'certify', 'issuestructure'],
        ];
        $ids = [];
        foreach ($old as $shortname => $rights) {
            $ids[$shortname] = create_role('Rôle ' . $shortname, $shortname, 'Créé avant le lot 1b.');
            foreach ($rights as $right) {
                assign_capability('tool/vae:' . $right, CAP_ALLOW, $ids[$shortname], $system->id, true);
            }
        }
        // The administrator prevented annotate for the evaluating managers: the upgrade leaves it so.
        assign_capability('tool/vae:annotate', CAP_PREVENT, $ids['vae_responsable_evaluateur'], $system->id, true);
        // A role of the site that is no preset's, with a shortname of its own.
        $other = create_role('Autre', 'autre_role', '');
        set_config('version', 2026110407, 'tool_vae');

        $this->assertTrue(xmldb_tool_vae_upgrade(2026110407));

        // The upgrade runs to the version the plugin declares: the last savepoint of db/upgrade.php is version.php's.
        $plugin = new \stdClass();
        require($CFG->dirroot . '/admin/tool/vae/version.php');
        $this->assertSame((string) $plugin->version, (string) get_config('tool_vae', 'version'));
        $this->assertContains('tool/vae:annotate', $this->rights_of('vae_responsable'));
        $this->assertContains('tool/vae:annotate', $this->rights_of('vae_direction_certificatrice'));
        $this->assertNotContains('tool/vae:annotate', $this->rights_of('vae_responsable_evaluateur'));
        $this->assertSame((string) CAP_PREVENT, (string) $DB->get_field('role_capabilities', 'permission', [
            'roleid' => $ids['vae_responsable_evaluateur'], 'contextid' => $system->id, 'capability' => 'tool/vae:annotate',
        ], MUST_EXIST));
        $this->assertFalse($DB->record_exists('role', ['shortname' => 'vae_direction']), 'A missing role is not created.');
        $this->assertSame(
            ['tool/vae:declare', 'tool/vae:draftown', 'tool/vae:export', 'tool/vae:positionself', 'tool/vae:viewown'],
            $this->rights_of('vaeiste'),
            'The vaeiste receives positionself and never annotate.'
        );
        $this->assertSame(
            ['tool/vae:annotate', 'tool/vae:attest', 'tool/vae:readdeposit', 'tool/vae:viewassigned', 'tool/vae:writeopinion'],
            $this->rights_of('vae_responsable'),
            'Nothing else moves.'
        );
        $this->assertSame(0, $DB->count_records('role_capabilities', ['roleid' => $other]));
    }

    /**
     * The same step on a site where the Direction VAE role is installed: it receives annotate too, and nothing else moves.
     */
    public function test_the_upgrade_grants_annotate_to_an_installed_direction_role(): void {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/admin/tool/vae/db/upgrade.php');
        $this->resetAfterTest();
        $system = \context_system::instance();
        $roleid = create_role('Direction VAE', 'vae_direction', 'Créée avant le lot 1b.');
        foreach (['viewany', 'export', 'draftview', 'readdeposit'] as $right) {
            assign_capability('tool/vae:' . $right, CAP_ALLOW, $roleid, $system->id, true);
        }
        set_config('version', 2026110407, 'tool_vae');

        $this->assertTrue(xmldb_tool_vae_upgrade(2026110407));

        $this->assertSame(
            ['tool/vae:annotate', 'tool/vae:draftview', 'tool/vae:export', 'tool/vae:readdeposit', 'tool/vae:viewany'],
            $this->rights_of('vae_direction')
        );
    }
}
