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
use tool_vae\local\organisation;
use tool_vae\local\referent_manager;

/**
 * The configuration of the scheme, read by who may view any dossier (T-90 lot 4).
 *
 * THE CONFIGURATION, NEVER A PERSON. The page lists the records the site imported, what the core holds around them and
 * a count of learning plans, then the roles, cohorts and settings behind the rights of the plugin. The only people it
 * names are the referents of a bloc. The tests hold the shape by a white list of keys, and look in the whole answer for
 * the names and ids of the people the data was made of.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\organisation::class)]
final class organisation_site_test extends \advanced_testcase {
    /** @var \tool_vae_generator */
    private $plugingenerator;

    /** @var \component_generator_base The generator of the competency subsystem, got by the gateway's constant. */
    private $coregenerator;

    /** @var \stdClass Somebody who holds viewany and nothing else. */
    private $direction;

    /** @var \stdClass A candidate who declared, with a plan. */
    private $candidate;

    /** @var int The role of the direction. */
    private $directionrole;

    /** @var int How many records the reading test has added. */
    private $made = 0;

    /** @var \stdClass|null The hidden course all the records of the reading test share. */
    private $sharedcourse;

    /** @var int|null The role that gives a right of the plugin, for the cohorts of the reading test. */
    private $followrole;

    /**
     * RNCP100 with two blocs (BC01 holding C01, BC02 holding C02), RS200 of one level, a record whose framework is gone,
     * and a framework with no competency; a direction who holds viewany.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->plugingenerator = $generator->get_plugin_generator('tool_vae');
        $this->coregenerator = $generator->get_plugin_generator(competency_gateway::SUBSYSTEM);

        $this->plugingenerator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        $this->plugingenerator->create_competency(['fichecode' => 'RNCP100', 'ficherang' => 'BC02', 'shortname' => 'Bloc 2']);
        $this->plugingenerator->create_competency([
            'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'shortname' => 'Aider au lever', 'bloc' => 'BC01',
        ]);
        $this->plugingenerator->create_competency([
            'fichecode' => 'RNCP100', 'ficherang' => 'C02', 'shortname' => 'Aider au repas', 'bloc' => 'BC02',
        ]);
        $this->plugingenerator->create_source([
            'fichecode' => 'RS200', 'ficherang' => 'C01', 'shortname' => 'Utiliser un lève-personne', 'name' => 'Un niveau',
        ]);
        // A record whose framework the core no longer holds, and one whose framework has nothing in it.
        $this->source('RNCP404', 999999);
        $this->setAdminUser();
        $empty = $this->coregenerator->create_framework(['idnumber' => 'RNCP000', 'shortname' => 'Sans competence']);
        $this->source('RNCP000', (int) $empty->get('id'));

        $this->directionrole = $generator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $this->directionrole, \context_system::instance()->id, true);
        $this->direction = $generator->create_user(['lastname' => 'Direction']);
        role_assign($this->directionrole, $this->direction->id, \context_system::instance()->id);

        $this->candidate = $generator->create_user([
            'firstname' => 'Camille', 'lastname' => 'Vaeiste', 'username' => 'camille.vaeiste',
        ]);
        $own = $generator->create_role();
        foreach (['tool/vae:declare', 'tool/vae:viewown'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $own, \context_system::instance()->id, true);
        }
        role_assign($own, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * A row of tool_vae_source for a framework id.
     *
     * @param string $fichecode
     * @param int $frameworkid
     */
    private function source(string $fichecode, int $frameworkid): void {
        global $DB;
        $now = time();
        $DB->insert_record('tool_vae_source', (object) [
            'frameworkid' => $frameworkid, 'fichecode' => $fichecode, 'fichetype' => 'rncp', 'sourceurl' => 'https://example.org/',
            'sourceupdated' => $now, 'licence' => 'Licence Ouverte 2.0', 'attribution' => 'France competences',
            'lastimport' => $now, 'lastimportstatus' => 'ok', 'timecreated' => $now, 'timemodified' => $now, 'usermodified' => 2,
        ]);
    }

    /**
     * What the direction reads.
     *
     * @return \stdClass
     */
    private function site(): \stdClass {
        $this->setUser($this->direction);

        return organisation::site();
    }

    /**
     * The competency ids of a record, by rank.
     *
     * @param string $fichecode
     * @return int[] Rank => competency id.
     */
    private function ids(string $fichecode): array {
        $gateway = new competency_gateway();
        $out = [];
        foreach ($gateway->list_framework_competencies((int) $gateway->find_framework($fichecode)) as $competency) {
            $out[substr($competency->idnumber, strlen($fichecode) + 1)] = (int) $competency->id;
        }

        return $out;
    }

    /**
     * A declaration of the candidate, which leaves a trace of them in the plugin.
     */
    private function declare(): void {
        $this->plugingenerator->create_declaration([
            'userid' => $this->candidate->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01',
            'situation' => 'Une situation vécue en établissement.',
        ]);
    }

    public function test_site_is_refused_to_everybody_who_may_not_view_any_dossier(): void {
        $stranger = $this->getDataGenerator()->create_user();
        $follower = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, $follower->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        foreach ([$stranger, $follower, $this->candidate] as $user) {
            $this->setUser($user);
            try {
                organisation::site();
                $this->fail('site() must refuse ' . fullname($user));
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }

        $this->assertIsObject($this->site());
        $this->setAdminUser();
        $this->assertIsObject(organisation::site());
    }

    public function test_site_has_a_white_list_of_keys_at_every_level(): void {
        $this->declare();
        $site = $this->site();

        $this->assertSame(['fiches', 'powers'], array_keys((array) $site));
        $this->assertSame(['capabilities', 'cohorts', 'settings', 'actors'], array_keys((array) $site->powers));
        foreach ($site->powers->actors as $actor) {
            $this->assertSame(['key', 'account', 'rights'], array_keys((array) $actor));
            foreach ($actor->rights as $right) {
                $this->assertSame(
                    ['name', 'label', 'status', 'roles', 'forbidden', 'state', 'adminmay'],
                    array_keys((array) $right)
                );
                $this->assertContainsOnly('string', $right->roles);
            }
        }
        foreach ($site->fiches as $fiche) {
            $this->assertSame([
                'fichecode', 'name', 'blocs', 'competencies', 'courses', 'coursesunreadable', 'activities',
                'activitiesunreadable', 'models', 'plans',
                'referents', 'rules',
            ], array_keys((array) $fiche));
            $this->assertIsInt($fiche->plans);
        }
        foreach ($site->powers->capabilities as $capability) {
            $this->assertSame(['name', 'label', 'allow', 'prevent', 'prohibit'], array_keys((array) $capability));
        }
        foreach ($site->powers->settings as $setting) {
            $this->assertSame(['name', 'value', 'open'], array_keys((array) $setting));
        }
    }

    public function test_site_names_and_counts_nobody_but_the_referents(): void {
        $this->declare();
        $this->setAdminUser();
        // The plan names a competency of the record, so that it is counted: the name is there to be left out.
        $plan = $this->coregenerator->create_plan(['userid' => $this->candidate->id, 'name' => 'Plan secret de Camille']);
        $this->coregenerator->create_plan_competency([
            'planid' => $plan->get('id'), 'competencyid' => $this->ids('RNCP100')['C01'],
        ]);
        $site = $this->site();
        $this->assertSame(1, $site->fiches['RNCP100']->plans, 'The plan is counted, so the name has had its chance to leak.');
        $json = json_encode($site);

        foreach (['Camille', 'Vaeiste', 'camille.vaeiste', 'Plan secret'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
        $this->assertDoesNotMatchRegularExpression('~"(userid|candidateid)"~', $json);
    }

    public function test_the_records_are_all_listed_with_their_sizes(): void {
        $fiches = $this->site()->fiches;

        $this->assertSame(['RNCP000', 'RNCP100', 'RNCP404', 'RS200'], array_keys($fiches));
        $this->assertSame([2, 4], [$fiches['RNCP100']->blocs, $fiches['RNCP100']->competencies]);
        $this->assertSame([0, 1], [$fiches['RS200']->blocs, $fiches['RS200']->competencies], 'A record of one level has no bloc.');
        $this->assertSame([0, 0], [$fiches['RNCP000']->blocs, $fiches['RNCP000']->competencies], 'Empty: there, with zeros.');
        $this->assertSame('Sans competence', $fiches['RNCP000']->name);
        // A record whose framework is gone is listed, by its code, with no figure: leaving it out would say it never was.
        $this->assertSame('RNCP404', $fiches['RNCP404']->name);
        $this->assertNull($fiches['RNCP404']->blocs);
        $this->assertNull($fiches['RNCP404']->competencies);
        $this->assertSame(0, $fiches['RNCP404']->plans);
        $this->assertSame('Un niveau', $fiches['RS200']->name);
    }

    public function test_a_record_named_with_nothing_or_spaces_is_the_code_alone(): void {
        global $DB;
        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP000']);
        $DB->set_field('competency_framework', 'shortname', '   ', ['id' => $frameworkid]);

        $this->assertSame('RNCP000', $this->site()->fiches['RNCP000']->name);
    }

    public function test_the_courses_are_those_of_the_competencies_of_the_record_and_a_hidden_one_is_not_named(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $visible = $this->getDataGenerator()->create_course(['fullname' => 'Accompagner le lever']);
        $hidden = $this->getDataGenerator()->create_course(['fullname' => 'Titre de travail', 'visible' => 0]);
        foreach ([$ids['C01'], $ids['C02']] as $competencyid) {
            $this->coregenerator->create_course_competency(['courseid' => $visible->id, 'competencyid' => $competencyid]);
        }
        $this->coregenerator->create_course_competency(['courseid' => $hidden->id, 'competencyid' => $ids['C01']]);

        $courses = $this->site()->fiches['RNCP100']->courses;

        $this->assertCount(2, $courses, 'The same course on two competencies counts once.');
        $this->assertSame('Accompagner le lever', $courses[0]->fullname);
        $this->assertNull($courses[1]->fullname, 'The name of a hidden course is the core\'s secret.');
        $this->assertSame(0, $courses[1]->visible);
        $this->assertStringNotContainsString('Titre de travail', json_encode($this->site()));
        $this->assertFalse($this->site()->fiches['RNCP100']->coursesunreadable);

        // Who may see hidden courses reads the name.
        $role = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:viewhiddencourses', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, $this->direction->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame('Titre de travail', $this->site()->fiches['RNCP100']->courses[1]->fullname);
    }

    public function test_courses_whose_competencies_the_reader_may_not_see_are_withheld_and_said_so(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Accompagner le lever']);
        $this->coregenerator->create_course_competency(['courseid' => $course->id, 'competencyid' => $ids['C01']]);
        $role = $this->getDataGenerator()->create_role();
        foreach (['moodle/competency:coursecompetencyview', 'moodle/competency:coursecompetencymanage'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $role, \context_system::instance()->id, true);
        }
        role_assign($role, $this->direction->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $fiche = $this->site()->fiches['RNCP100'];

        $this->assertSame([], $fiche->courses);
        $this->assertTrue($fiche->coursesunreadable, 'An empty list the reader\'s rights emptied is not "none".');
        $this->assertStringNotContainsString('Accompagner le lever', json_encode($this->site()));
    }

    public function test_courses_of_one_competency_withheld_do_not_hide_the_courses_of_another(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $readable = $this->getDataGenerator()->create_course(['fullname' => 'Cours lisible']);
        $withheld = $this->getDataGenerator()->create_course(['fullname' => 'Cours retenu']);
        $this->coregenerator->create_course_competency(['courseid' => $readable->id, 'competencyid' => $ids['C01']]);
        $this->coregenerator->create_course_competency(['courseid' => $withheld->id, 'competencyid' => $ids['C02']]);
        // The reader may not see the competencies of the second course only: C02 loses all its courses, C01 keeps its.
        $role = $this->getDataGenerator()->create_role();
        foreach (['moodle/competency:coursecompetencyview', 'moodle/competency:coursecompetencymanage'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $role, \context_course::instance($withheld->id)->id, true);
        }
        role_assign($role, $this->direction->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $fiche = $this->site()->fiches['RNCP100'];

        $this->assertSame(['Cours lisible'], array_map(fn($course) => $course->fullname, $fiche->courses));
        $this->assertFalse($fiche->coursesunreadable, 'A course is shown: the sign "not readable" would be false.');
    }

    public function test_the_templates_are_named_and_a_hidden_one_only_to_who_may_manage_them(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $open = $this->coregenerator->create_template(['shortname' => 'Parcours AES']);
        $secret = $this->coregenerator->create_template(['shortname' => 'Parcours en préparation', 'visible' => 0]);
        $this->coregenerator->create_template_competency(['templateid' => $open->get('id'), 'competencyid' => $ids['C01']]);
        $this->coregenerator->create_template_competency(['templateid' => $open->get('id'), 'competencyid' => $ids['C02']]);
        $this->coregenerator->create_template_competency(['templateid' => $secret->get('id'), 'competencyid' => $ids['C01']]);

        $models = $this->site()->fiches['RNCP100']->models;

        $this->assertCount(2, $models);
        $this->assertSame('Parcours AES', $models[0]->name);
        $this->assertNull($models[1]->name);
        $this->assertSame(0, $models[1]->visible);
        $this->assertStringNotContainsString('Parcours en préparation', json_encode($this->site()));

        $role = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:templatemanage', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, $this->direction->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame('Parcours en préparation', $this->site()->fiches['RNCP100']->models[1]->name);
        $this->assertSame([], $this->site()->fiches['RS200']->models);
    }

    public function test_the_plans_are_counted_for_the_accounts_that_left_a_trace_and_never_named(): void {
        global $DB;
        $ids = $this->ids('RNCP100');
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $untraced = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        // The candidate: a declaration, a plan, and a draft plan - both counted (decision of 4 November 2026).
        $this->declare();
        foreach ([competency_gateway::PLAN_ACTIVE, competency_gateway::PLAN_DRAFT] as $status) {
            $plan = $this->coregenerator->create_plan(['userid' => $this->candidate->id, 'status' => $status]);
            $this->coregenerator->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $ids['C01']]);
        }
        // A suspended account with a trace keeps its plan, which counts.
        $DB->insert_record('tool_vae_scope', (object) [
            'uuid' => 'sc' . str_repeat('0', 24), 'candidateid' => $suspended->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01',
            'note' => null, 'timecreated' => time(),
        ]);
        $plan = $this->coregenerator->create_plan(['userid' => $suspended->id, 'status' => competency_gateway::PLAN_ACTIVE]);
        $this->coregenerator->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $ids['C02']]);
        // Nothing of the plugin for this one: no count.
        $plan = $this->coregenerator->create_plan(['userid' => $untraced->id, 'status' => competency_gateway::PLAN_ACTIVE]);
        $this->coregenerator->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $ids['C01']]);

        $fiches = $this->site()->fiches;

        $this->assertSame(3, $fiches['RNCP100']->plans);
        $this->assertSame(0, $fiches['RS200']->plans);
        $this->assertSame(0, $fiches['RNCP404']->plans);
    }

    public function test_a_validation_draft_is_a_trace_and_other_documents_are_not(): void {
        global $DB;
        $ids = $this->ids('RNCP100');
        $this->setAdminUser();
        $drafter = $this->getDataGenerator()->create_user();
        $feasibility = $this->getDataGenerator()->create_user();
        $reflexion = $this->getDataGenerator()->create_user();
        $types = [
            [$drafter, \tool_vae\local\draft_manager::DOCTYPE_VALIDATION],
            [$feasibility, \tool_vae\local\draft_manager::DOCTYPE_FAISABILITE],
            [$reflexion, \tool_vae\local\draft_manager::DOCTYPE_REFLEXION],
        ];
        foreach ($types as $n => [$user, $doctype]) {
            $now = time();
            $DB->insert_record('tool_vae_draft', (object) [
                'uuid' => 'dr' . str_pad((string) $n, 24, '0'), 'candidateid' => $user->id, 'doctype' => $doctype,
                'section' => 's1', 'slot' => 0, 'fichecode' => 'RNCP100', 'working' => null, 'workingat' => null,
                'currentversion' => 0, 'timecreated' => $now, 'timemodified' => $now, 'usermodified' => $user->id,
            ]);
            $plan = $this->coregenerator->create_plan(['userid' => $user->id, 'status' => competency_gateway::PLAN_ACTIVE]);
            $this->coregenerator->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $ids['C01']]);
        }

        $this->assertSame(1, $this->site()->fiches['RNCP100']->plans, 'Only the preparation of the validation dossier is a trace.');
    }

    public function test_a_deleted_account_is_not_counted(): void {
        global $DB;
        $ids = $this->ids('RNCP100');
        $this->setAdminUser();
        $this->declare();
        $plan = $this->coregenerator->create_plan(['userid' => $this->candidate->id, 'status' => competency_gateway::PLAN_ACTIVE]);
        $this->coregenerator->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $ids['C01']]);
        $this->assertSame(1, $this->site()->fiches['RNCP100']->plans);

        // Half-purged: the account is flagged deleted while its plan and its declaration are still there.
        $DB->set_field('user', 'deleted', 1, ['id' => $this->candidate->id]);
        $this->assertSame(0, $this->site()->fiches['RNCP100']->plans);
    }

    public function test_the_referents_are_named_by_bloc_and_an_inactive_one_leaves_the_line_without_a_name(): void {
        global $DB;
        $this->setAdminUser();
        $active = $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'Referente']);
        $gone = $this->getDataGenerator()->create_user(['firstname' => 'Bruno', 'lastname' => 'Parti']);
        $role = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $role, \context_system::instance()->id, true);
        foreach ([$active, $gone] as $user) {
            role_assign($role, $user->id, \context_system::instance()->id);
        }
        accesslib_clear_all_caches_for_unit_testing();
        referent_manager::add('RNCP100', 'BC01', (int) $active->id);
        referent_manager::add('RNCP100', 'BC02', (int) $gone->id);
        referent_manager::add('RNCP100', '', (int) $active->id);
        $DB->set_field('user', 'suspended', 1, ['id' => $gone->id]);

        $referents = $this->site()->fiches['RNCP100']->referents;

        $byrank = array_column(array_map(fn($row) => (array) $row, $referents), 'names', 'ficherang');
        $this->assertSame(['Alice Referente'], $byrank['BC01']);
        $this->assertSame(['Alice Referente'], $byrank['']);
        $this->assertSame([], $byrank['BC02'], 'A bloc whose only referent is not active keeps its line, with no name.');
        $this->assertStringNotContainsString('Bruno', json_encode($this->site()));
        $this->assertSame([], $this->site()->fiches['RS200']->referents);
    }

    public function test_the_rule_addresses_are_listed_only_when_they_are_open(): void {
        $this->setAdminUser();
        \tool_vae\local\ref_manager::add('RNCP100', 'Règle de validation', 'https://example.org/regle');

        set_config('refopen', 0, 'tool_vae');
        $this->assertNull($this->site()->fiches['RNCP100']->rules, 'Closed is not none: the addresses may exist.');

        set_config('refopen', 1, 'tool_vae');
        $rules = $this->site()->fiches['RNCP100']->rules;
        $this->assertCount(1, $rules);
        $this->assertSame(['title', 'url'], array_keys((array) $rules[0]));
        $this->assertSame('Règle de validation', $rules[0]->title);
    }

    public function test_the_capabilities_listed_are_those_of_the_plugin(): void {
        global $CFG;
        $capabilities = [];
        require($CFG->dirroot . '/admin/tool/vae/db/access.php');
        $names = array_map(fn($capability) => $capability->name, $this->site()->powers->capabilities);

        $this->assertEqualsCanonicalizing(array_keys($capabilities), $names);
        foreach ($this->site()->powers->capabilities as $capability) {
            $this->assertNotSame('', $capability->label);
            $this->assertStringNotContainsString('[[', $capability->label);
        }
    }

    public function test_the_roles_that_give_refuse_and_forbid_a_right_are_three_lists(): void {
        $system = \context_system::instance()->id;
        $allow = $this->getDataGenerator()->create_role(['name' => 'Role donnant', 'shortname' => 'donnant']);
        $prevent = $this->getDataGenerator()->create_role(['name' => 'Role retirant', 'shortname' => 'retirant']);
        $prohibit = $this->getDataGenerator()->create_role(['name' => 'Role interdisant', 'shortname' => 'interdisant']);
        $below = $this->getDataGenerator()->create_role(['name' => 'Role local', 'shortname' => 'local']);
        assign_capability('tool/vae:export', CAP_ALLOW, $allow, $system, true);
        assign_capability('tool/vae:export', CAP_PREVENT, $prevent, $system, true);
        assign_capability('tool/vae:export', CAP_PROHIBIT, $prohibit, $system, true);
        // Given only in the context of a user: not a role of the site.
        assign_capability('tool/vae:export', CAP_ALLOW, $below, \context_user::instance($this->candidate->id)->id, true);

        $rows = array_column(array_map(fn($row) => (array) $row, $this->site()->powers->capabilities), null, 'name');

        $this->assertContains('Role donnant', $rows['tool/vae:export']['allow']);
        $this->assertContains('Role retirant', $rows['tool/vae:export']['prevent']);
        $this->assertContains('Role interdisant', $rows['tool/vae:export']['prohibit']);
        $this->assertNotContains('Role donnant', $rows['tool/vae:export']['prevent']);
        foreach (['allow', 'prevent', 'prohibit'] as $slot) {
            $this->assertNotContains('Role local', $rows['tool/vae:export'][$slot]);
        }
        // A right nobody gives keeps its line.
        $this->assertSame([], $rows['tool/vae:withdrawany']['prevent']);
        $this->assertArrayHasKey('tool/vae:linkcourse', $rows);
    }

    public function test_the_cohorts_listed_are_those_followed_through_a_role_of_the_plugin_and_never_the_manager(): void {
        global $DB;
        $this->setAdminUser();
        $manager = $this->getDataGenerator()->create_user(['firstname' => 'Marie', 'lastname' => 'Responsable']);
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Promotion 2026 AES']);
        $hidden = $this->getDataGenerator()->create_cohort(['name' => 'Cohorte masquée', 'visible' => 0]);
        $unrelated = $this->getDataGenerator()->create_cohort(['name' => 'Equipe pedagogique']);
        $teacher = $this->getDataGenerator()->create_role(['name' => 'Enseignant', 'shortname' => 'ens']);
        $follow = $this->getDataGenerator()->create_role(['name' => 'Suivi VAE', 'shortname' => 'suivivae']);
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, \context_system::instance()->id, true);
        // Roles that do not GIVE a right of the plugin at the level of the site: one that forbids it, one that withholds it,
        // one that gives it in the context of a user only. Their cohorts are not "followed through a role of the plugin".
        $system = \context_system::instance()->id;
        $forbids = $this->getDataGenerator()->create_role(['name' => 'Interdit export', 'shortname' => 'interditexport']);
        $withholds = $this->getDataGenerator()->create_role(['name' => 'Retire export', 'shortname' => 'retireexport']);
        $local = $this->getDataGenerator()->create_role(['name' => 'Role local', 'shortname' => 'rolelocal']);
        assign_capability('tool/vae:export', CAP_PROHIBIT, $forbids, $system, true);
        assign_capability('tool/vae:export', CAP_PREVENT, $withholds, $system, true);
        assign_capability('tool/vae:export', CAP_ALLOW, $local, \context_user::instance($manager->id)->id, true);
        $others = $this->getDataGenerator()->create_cohort(['name' => 'Cohorte autres roles']);
        $rows = [
            [$cohort, $follow], [$hidden, $follow], [$unrelated, $teacher],
            [$others, $forbids], [$others, $withholds], [$others, $local],
        ];
        foreach ($rows as [$c, $r]) {
            $DB->insert_record('tool_cohortroles', (object) [
                'cohortid' => $c->id, 'roleid' => $r, 'userid' => $manager->id,
                'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 2,
            ]);
        }
        // Two managers on the same cohort and role are one line.
        $DB->insert_record('tool_cohortroles', (object) [
            'cohortid' => $cohort->id, 'roleid' => $follow, 'userid' => $this->direction->id,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 2,
        ]);

        $cohorts = $this->site()->powers->cohorts;

        $this->assertSame(
            [['Cohorte masquée', 'Suivi VAE'], ['Promotion 2026 AES', 'Suivi VAE']],
            array_map(fn($row) => [$row->name, $row->rolename], $cohorts),
            'Named whatever their visibility; one line per cohort and role; the unrelated role is not listed.'
        );
        foreach ($cohorts as $row) {
            $this->assertSame(['cohortid', 'name', 'roleid', 'rolename'], array_keys((array) $row));
        }
        $json = json_encode($this->site());
        $this->assertStringNotContainsString('Marie', $json);
        $this->assertStringNotContainsString('Equipe pedagogique', $json);
        $this->assertStringNotContainsString(
            'Cohorte autres roles',
            $json,
            'A role that only forbids or withholds gives no right.'
        );
    }

    public function test_a_role_name_is_given_ready_for_the_page_and_shown_once(): void {
        global $DB;
        $this->setAdminUser();
        $role = $this->getDataGenerator()->create_role(['name' => 'Suivi R&D', 'shortname' => 'suivird']);
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $role, \context_system::instance()->id, true);
        $cohort = $this->getDataGenerator()->create_cohort(['name' => 'Promotion A&B']);
        $DB->insert_record('tool_cohortroles', (object) [
            'cohortid' => $cohort->id, 'roleid' => $role, 'userid' => 2,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 2,
        ]);

        $site = $this->site();
        $html = \tool_vae\local\organisation_screen::site($site);

        $this->assertSame('Suivi R&amp;D', $site->powers->cohorts[0]->rolename, 'format_string() has already escaped it.');
        $this->assertStringContainsString('Suivi R&amp;D', $html);
        $this->assertStringContainsString('Promotion A&amp;B', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function test_a_cohort_that_was_deleted_keeps_its_line_without_a_name(): void {
        global $DB;
        $this->setAdminUser();
        $follow = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, \context_system::instance()->id, true);
        $DB->insert_record('tool_cohortroles', (object) [
            'cohortid' => 987654, 'roleid' => $follow, 'userid' => 2, 'timecreated' => time(), 'timemodified' => time(),
            'usermodified' => 2,
        ]);

        $cohorts = $this->site()->powers->cohorts;

        $this->assertCount(1, $cohorts);
        $this->assertNull($cohorts[0]->name);
        $this->assertSame(987654, $cohorts[0]->cohortid);
    }

    public function test_a_site_without_cohort_roles_is_told_so_without_reading_the_database(): void {
        global $DB;
        $this->setAdminUser();
        $before = $DB->perf_get_reads();
        $this->assertNull(organisation::cohort_roles(false, [1, 2]));
        $this->assertSame($before, $DB->perf_get_reads());
        $this->assertSame([], organisation::cohort_roles(true, []));
    }

    public function test_the_configuration_of_a_site_without_cohort_roles_says_so(): void {
        $this->setAdminUser();
        $method = new \ReflectionMethod(organisation::class, 'site_powers');

        $without = $method->invoke(null, false);
        $with = $method->invoke(null, true);

        $this->assertNull($without->cohorts, 'No tool_cohortroles: no list of cohorts.');
        $this->assertIsArray($with->cohorts);
    }

    public function test_the_names_of_the_cohorts_are_given_to_nobody_without_the_right_to_view_any_dossier(): void {
        $stranger = $this->getDataGenerator()->create_user();
        foreach ([true, false] as $available) {
            $this->setUser($stranger);
            try {
                organisation::cohort_roles($available, [1]);
                $this->fail('cohort_roles() must ask the right before it answers, even for a site without the tool.');
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
    }

    public function test_the_reminder_is_on_only_for_a_number_of_days_the_task_accepts(): void {
        foreach ([0 => false, 1 => true, 365 => true, 366 => false, 400 => false] as $days => $on) {
            set_config('overduedays', $days, 'tool_vae');
            $settings = array_column(array_map(fn($row) => (array) $row, $this->site()->powers->settings), null, 'name');
            $this->assertSame($on, $settings['overduedays']['open'], $days . ' days');
            $this->assertSame($days, $settings['overduedays']['value']);
        }
    }

    public function test_the_settings_are_open_or_closed_and_the_reminder_is_a_number_of_days(): void {
        set_config('validationopen', 1, 'tool_vae');
        set_config('aiopen', 0, 'tool_vae');
        set_config('overduedays', 14, 'tool_vae');
        unset_config('scopeopen', 'tool_vae');

        $settings = array_column(array_map(fn($row) => (array) $row, $this->site()->powers->settings), null, 'name');

        $this->assertSame(1, $settings['validationopen']['value']);
        $this->assertTrue($settings['validationopen']['open']);
        $this->assertFalse($settings['aiopen']['open']);
        $this->assertFalse($settings['scopeopen']['open'], 'A setting never saved is closed.');
        $this->assertSame(14, $settings['overduedays']['value']);
        $this->assertTrue($settings['overduedays']['open']);
        $this->assertEqualsCanonicalizing([
            'managerwithdrawown', 'copyenabled', 'overduedays', 'validationopen', 'reflectionopen', 'scopeopen', 'aiopen',
            'refopen', 'piecesopen', 'piecenoantivirus',
        ], array_keys($settings));
    }

    /**
     * Reads of site() with a given number of records, each with a competency, a template and a referent, all carried by
     * the same hidden course.
     *
     * The core's right on a course (to see its competencies, its name when hidden) is asked once per DISTINCT course, as
     * on the pathway of a candidate: the records here share one course so that the reads measured are those of the
     * records, which must not grow with their number. The cost of a site with many courses is the core's own.
     *
     * @param int $add How many more records to add to those already there.
     * @return int
     */
    private function reads_of_site(int $add): int {
        global $DB;
        $this->setAdminUser();
        $referent = $this->getDataGenerator()->create_user();
        $this->sharedcourse ??= $this->getDataGenerator()->create_course(['visible' => 0]);
        $course = $this->sharedcourse;
        // What grows with each record: a competency, a template, a referent, a rule address, an account with a trace and a
        // plan, and a cohort followed through a role of the plugin.
        set_config('refopen', 1, 'tool_vae');
        $this->followrole ??= $this->getDataGenerator()->create_role(['name' => 'Suivi lectures', 'shortname' => 'suivilect']);
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $this->followrole, \context_system::instance()->id, true);
        for ($i = 1; $i <= $add; $i++) {
            $code = 'RNCP9' . str_pad((string) ++$this->made, 2, '0', STR_PAD_LEFT);
            $this->plugingenerator->create_source(['fichecode' => $code, 'ficherang' => 'C01', 'shortname' => 'Competence']);
            $this->plugingenerator->create_competency(['fichecode' => $code, 'ficherang' => 'C02', 'shortname' => 'Autre']);
            $competencyid = $this->ids($code)['C01'];
            $this->coregenerator->create_course_competency(['courseid' => $course->id, 'competencyid' => $competencyid]);
            $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
            $this->coregenerator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $competencyid]);
            $template = $this->coregenerator->create_template(['shortname' => 'Modele ' . $code, 'visible' => 0]);
            $this->coregenerator->create_template_competency([
                'templateid' => $template->get('id'), 'competencyid' => $competencyid,
            ]);
            referent_manager::add($code, '', (int) $referent->id);
            \tool_vae\local\ref_manager::add($code, 'Regle ' . $code, 'https://example.org/' . $code);
            $traced = $this->getDataGenerator()->create_user();
            $DB->insert_record('tool_vae_scope', (object) [
                'uuid' => 'sc' . str_pad((string) $this->made, 24, '0', STR_PAD_LEFT), 'candidateid' => $traced->id,
                'fichecode' => $code, 'ficherang' => 'C01', 'note' => null, 'timecreated' => time(),
            ]);
            $plan = $this->coregenerator->create_plan(['userid' => $traced->id, 'status' => competency_gateway::PLAN_ACTIVE]);
            $this->coregenerator->create_plan_competency(['planid' => $plan->get('id'), 'competencyid' => $competencyid]);
            $cohort = $this->getDataGenerator()->create_cohort();
            $DB->insert_record('tool_cohortroles', (object) [
                'cohortid' => $cohort->id, 'roleid' => $this->followrole, 'userid' => $referent->id,
                'timecreated' => time(), 'timemodified' => time(), 'usermodified' => 2,
            ]);
        }
        $this->setUser($this->direction);
        \context_helper::reset_caches();
        accesslib_clear_all_caches_for_unit_testing();
        organisation::site();

        \context_helper::reset_caches();
        accesslib_clear_all_caches_for_unit_testing();
        $before = $DB->perf_get_reads();
        organisation::site();

        return $DB->perf_get_reads() - $before;
    }

    public function test_the_reads_do_not_grow_with_the_number_of_records(): void {
        $this->declare();
        $few = $this->reads_of_site(1);
        $many = $this->reads_of_site(4);

        $this->assertSame($few, $many);
    }

    /**
     * A user who holds viewany and the rights that let an activity be seen, but not the right to see a hidden course.
     *
     * @param bool $viewhiddencourses Whether to give that one too.
     */
    private function direction_sees_activities(bool $viewhiddencourses = false): void {
        $role = $this->getDataGenerator()->create_role();
        $rights = ['moodle/course:viewhiddenactivities', 'mod/quiz:view', 'mod/assign:view'];
        if ($viewhiddencourses) {
            $rights[] = 'moodle/course:viewhiddencourses';
        }
        foreach ($rights as $capability) {
            assign_capability($capability, CAP_ALLOW, $role, \context_system::instance()->id, true);
        }
        role_assign($role, $this->direction->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    public function test_an_activity_the_reader_cannot_see_is_listed_without_its_name_type_or_address(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Accompagner le lever']);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz du lever']);
        $this->coregenerator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $ids['C01']]);

        // The direction is not in the course: the core does not show them the activity (decision of 4 November 2026).
        $fiche = $this->site()->fiches['RNCP100'];

        $this->assertCount(1, $fiche->activities);
        $this->assertSame('Accompagner le lever', $fiche->activities[0]->course);
        $this->assertNull($fiche->activities[0]->name);
        $this->assertNull($fiche->activities[0]->type);
        $this->assertNull($fiche->activities[0]->url);
        $this->assertFalse($fiche->activitiesunreadable);
        $this->assertStringNotContainsString('Quiz du lever', json_encode($this->site()));
    }

    public function test_a_visible_course_gives_the_name_of_a_visible_activity(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Accompagner le lever']);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz du lever']);
        $this->coregenerator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $ids['C01']]);
        $this->direction_sees_activities();

        $activity = $this->site()->fiches['RNCP100']->activities[0];

        $this->assertSame('Accompagner le lever', $activity->course);
        $this->assertSame('Quiz du lever', $activity->name);
        $this->assertSame(get_string('modulename', 'mod_quiz'), $activity->type);
        $this->assertSame((new \moodle_url('/mod/quiz/view.php', ['id' => $quiz->cmid]))->out(false), $activity->url);
    }

    public function test_a_hidden_course_keeps_the_activity_and_names_neither(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Titre de travail', 'visible' => 0]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz secret']);
        $this->coregenerator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $ids['C01']]);
        // The activity is visible to this reader, the course is not: the course's rule comes first.
        $this->direction_sees_activities();

        $json = json_encode($this->site());
        $activities = $this->site()->fiches['RNCP100']->activities;

        $this->assertCount(1, $activities);
        $this->assertNull($activities[0]->course);
        $this->assertNull($activities[0]->name);
        $this->assertStringNotContainsString('Quiz secret', $json);
        $this->assertStringNotContainsString('Titre de travail', $json);

        $this->direction_sees_activities(true);
        $activities = $this->site()->fiches['RNCP100']->activities;
        $this->assertSame('Titre de travail', $activities[0]->course);
        $this->assertSame('Quiz secret', $activities[0]->name);
    }

    public function test_an_activity_whose_competencies_the_reader_may_not_read_is_withheld_and_said_so(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz retenu']);
        $this->coregenerator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $ids['C01']]);
        $this->direction_sees_activities();
        $role = $this->getDataGenerator()->create_role();
        foreach (['moodle/competency:coursecompetencyview', 'moodle/competency:coursecompetencymanage'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $role, \context_module::instance($quiz->cmid)->id, true);
        }
        role_assign($role, $this->direction->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $fiche = $this->site()->fiches['RNCP100'];

        $this->assertSame([], $fiche->activities);
        $this->assertTrue($fiche->activitiesunreadable, 'An empty list the reader\'s rights emptied is not "none".');
        $this->assertStringNotContainsString('Quiz retenu', json_encode($this->site()));
    }

    public function test_a_course_whose_competencies_the_reader_may_not_see_sets_its_activities_aside(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz du cours retenu']);
        $this->coregenerator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $ids['C01']]);
        $this->direction_sees_activities();
        // The course withholds the right, the activity gives it back: the course's rule comes first.
        global $CFG;
        assign_capability(
            'moodle/competency:coursecompetencyview',
            CAP_PREVENT,
            $CFG->defaultuserroleid,
            \context_course::instance($course->id)->id,
            true
        );
        assign_capability(
            'moodle/competency:coursecompetencyview',
            CAP_ALLOW,
            $CFG->defaultuserroleid,
            \context_module::instance($quiz->cmid)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $fiche = $this->site()->fiches['RNCP100'];

        $this->assertSame([], $fiche->activities);
        $this->assertTrue($fiche->activitiesunreadable);
    }

    public function test_the_same_activity_on_two_competencies_of_a_record_is_counted_once(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cours']);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz commun']);
        $this->coregenerator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $ids['C01']]);
        $this->coregenerator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $ids['C02']]);
        $this->direction_sees_activities();

        $this->assertCount(1, $this->site()->fiches['RNCP100']->activities);
    }

    public function test_an_activity_the_module_information_does_not_hold_is_left_out_without_an_error(): void {
        global $DB;
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz fantome']);
        $this->coregenerator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $ids['C01']]);
        // The row of the activity stays, the activity itself is gone: the module information has no name for it.
        $DB->delete_records('quiz', ['id' => $quiz->id]);
        \course_modinfo::clear_instance_cache();
        rebuild_course_cache($course->id, true);

        $fiche = $this->site()->fiches['RNCP100'];

        $this->assertSame([], $fiche->activities);
        $this->assertFalse($fiche->activitiesunreadable, 'No right took it away: a gap of the module information is no refusal.');
    }

    public function test_the_activities_are_in_order_the_unnamed_last(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cours']);
        $names = ['Zebre', 'Été', 'alpha'];
        foreach ($names as $name) {
            $module = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => $name]);
            $this->coregenerator->create_course_module_competency(['cmid' => $module->cmid, 'competencyid' => $ids['C01']]);
        }
        $hidden = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'name' => 'Milieu', 'visible' => 0,
        ]);
        $this->coregenerator->create_course_module_competency(['cmid' => $hidden->cmid, 'competencyid' => $ids['C01']]);
        $this->direction_sees_activities();
        // The hidden activity is seen by who holds viewhiddenactivities: take that right away for the direction.
        $role = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:viewhiddenactivities', CAP_PROHIBIT, $role, \context_system::instance()->id, true);
        role_assign($role, $this->direction->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $activities = $this->site()->fiches['RNCP100']->activities;

        $this->assertSame(['alpha', 'Été', 'Zebre', null], array_column($activities, 'name'));
    }

    public function test_activities_withheld_for_one_competency_do_not_hide_the_activities_of_another(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cours mixte']);
        $readable = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz lisible']);
        $withheld = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz retenu']);
        $this->coregenerator->create_course_module_competency(['cmid' => $readable->cmid, 'competencyid' => $ids['C01']]);
        $this->coregenerator->create_course_module_competency(['cmid' => $withheld->cmid, 'competencyid' => $ids['C02']]);
        $this->direction_sees_activities();
        $role = $this->getDataGenerator()->create_role();
        foreach (['moodle/competency:coursecompetencyview', 'moodle/competency:coursecompetencymanage'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $role, \context_module::instance($withheld->cmid)->id, true);
        }
        role_assign($role, $this->direction->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $fiche = $this->site()->fiches['RNCP100'];

        $this->assertSame(['Quiz lisible'], array_column($fiche->activities, 'name'));
        $this->assertFalse($fiche->activitiesunreadable, 'An activity is shown: the sign "not readable" would be false.');
    }

    public function test_an_activity_without_an_address_is_named_and_has_none(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cours']);
        $label = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'intro' => 'Etiquette du cours']);
        $this->coregenerator->create_course_module_competency(['cmid' => $label->cmid, 'competencyid' => $ids['C01']]);
        $this->direction_sees_activities();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('mod/label:view', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, $this->direction->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $activities = $this->site()->fiches['RNCP100']->activities;

        $this->assertCount(1, $activities);
        $this->assertNotNull($activities[0]->name);
        $this->assertNull($activities[0]->url, 'A label has no page of its own: the core gives no address.');
    }

    public function test_every_level_of_the_answer_has_a_white_list_of_keys_with_the_data_that_fills_it(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        $visible = $this->getDataGenerator()->create_course(['fullname' => 'Cours ouvert']);
        $hidden = $this->getDataGenerator()->create_course(['fullname' => 'Cours cache', 'visible' => 0]);
        foreach ([$visible, $hidden] as $course) {
            $this->coregenerator->create_course_competency(['courseid' => $course->id, 'competencyid' => $ids['C01']]);
        }
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $visible->id, 'name' => 'Quiz']);
        $this->coregenerator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $ids['C01']]);
        $template = $this->coregenerator->create_template(['shortname' => 'Modele', 'visible' => 1]);
        $this->coregenerator->create_template_competency(['templateid' => $template->get('id'), 'competencyid' => $ids['C01']]);
        referent_manager::add('RNCP100', 'BC01', (int) $this->getDataGenerator()->create_user()->id);
        $this->direction_sees_activities();

        $fiche = $this->site()->fiches['RNCP100'];

        $this->assertNotEmpty($fiche->courses);
        $this->assertNotEmpty($fiche->activities);
        $this->assertNotEmpty($fiche->models);
        $this->assertNotEmpty($fiche->referents);
        foreach ($fiche->courses as $course) {
            $this->assertSame(['courseid', 'fullname', 'visible'], array_keys((array) $course));
        }
        foreach ($fiche->activities as $activity) {
            $this->assertSame(['course', 'name', 'type', 'url'], array_keys((array) $activity));
        }
        foreach ($fiche->models as $model) {
            $this->assertSame(['templateid', 'name', 'visible'], array_keys((array) $model));
        }
        foreach ($fiche->referents as $referent) {
            $this->assertSame(['ficherang', 'names'], array_keys((array) $referent));
        }
    }

    public function test_a_cohort_of_a_category_is_named_to_the_direction(): void {
        global $DB;
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $cohort = $this->getDataGenerator()->create_cohort([
            'name' => 'Cohorte de categorie', 'contextid' => \context_coursecat::instance($category->id)->id,
        ]);
        $follow = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, \context_system::instance()->id, true);
        $DB->insert_record('tool_cohortroles', (object) [
            'cohortid' => $cohort->id, 'roleid' => $follow, 'userid' => 2, 'timecreated' => time(), 'timemodified' => time(),
            'usermodified' => 2,
        ]);

        $cohorts = $this->site()->powers->cohorts;

        $this->assertCount(1, $cohorts);
        $this->assertSame('Cohorte de categorie', $cohorts[0]->name, 'Decision D9: a cohort is always named.');
    }

    public function test_the_addresses_of_the_rule_are_said_closed_not_none_when_the_setting_is_closed(): void {
        $this->setAdminUser();
        \tool_vae\local\ref_manager::add('RNCP100', 'Regle', 'https://example.org/regle');
        set_config('refopen', 0, 'tool_vae');

        $this->assertNull($this->site()->fiches['RNCP100']->rules);
        $html = \tool_vae\local\organisation_screen::site($this->site());
        $this->assertStringContainsString(get_string('orgrulesclosed', 'tool_vae'), $html);

        set_config('refopen', 1, 'tool_vae');
        $this->assertCount(1, $this->site()->fiches['RNCP100']->rules);
    }

    public function test_the_courses_of_the_activities_are_in_the_order_of_the_words_not_of_the_bytes(): void {
        $this->setAdminUser();
        $ids = $this->ids('RNCP100');
        foreach (['Az' => 'Alpha', 'A' => 'Zebre'] as $coursename => $activityname) {
            $course = $this->getDataGenerator()->create_course(['fullname' => $coursename]);
            $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => $activityname]);
            $this->coregenerator->create_course_module_competency(['cmid' => $quiz->cmid, 'competencyid' => $ids['C01']]);
        }
        $this->direction_sees_activities();
        // Enrolled readers see the quiz: the direction here is made a student of both to see the names.
        foreach (\get_courses() as $course) {
            if ($course->id != SITEID) {
                $this->getDataGenerator()->enrol_user($this->direction->id, $course->id, 'student');
            }
        }
        \course_modinfo::clear_instance_cache();

        $activities = $this->site()->fiches['RNCP100']->activities;

        $this->assertSame(['A', 'Az'], array_column($activities, 'course'));
        $this->assertSame(['Zebre', 'Alpha'], array_column($activities, 'name'));
    }

    public function test_the_grid_of_the_actors_says_what_the_roles_of_the_site_give(): void {
        $this->setAdminUser();
        $roles = [];
        foreach (['declare', 'viewown', 'draftown', 'export'] as $short) {
            assign_capability('tool/vae:' . $short, CAP_ALLOW, $this->directionrole, \context_system::instance()->id, true);
        }
        $grid = array_column($this->site()->powers->actors, null, 'key');

        $rights = array_column($grid['vaeiste']->rights, null, 'name');
        $this->assertSame(\tool_vae\local\actors::GIVEN, $rights['tool/vae:declare']->state);
        $this->assertNotEmpty($rights['tool/vae:declare']->roles);
        $direction = array_column($grid['direction']->rights, null, 'name');
        $this->assertSame(\tool_vae\local\actors::GIVEN, $direction['tool/vae:viewany']->state);
        $this->assertSame(
            \tool_vae\local\actors::MISSING,
            $direction['tool/vae:readdeposit']->state,
            'The direction of the setUp holds viewany only: it reads no deposit.'
        );
        $this->assertSame(
            \tool_vae\local\actors::MISSING,
            array_column($grid['responsable']->rights, null, 'name')['tool/vae:attest']->state
        );
    }

    public function test_the_direction_that_the_grid_calls_complete_can_read_a_deposit_and_the_other_cannot(): void {
        $this->declare();
        $this->setUser($this->direction);
        $this->assertFalse(\tool_vae\local\deposit_manager::can_read((int) $this->candidate->id));

        assign_capability('tool/vae:readdeposit', CAP_ALLOW, $this->directionrole, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(\tool_vae\local\deposit_manager::can_read((int) $this->candidate->id));
        $grid = array_column($this->site()->powers->actors, null, 'key');
        $this->assertSame(
            \tool_vae\local\actors::GIVEN,
            array_column($grid['direction']->rights, null, 'name')['tool/vae:readdeposit']->state
        );
    }

    public function test_the_grid_names_no_person_and_is_a_function_of_the_powers_read(): void {
        $this->declare();
        $site = $this->site();

        $json = json_encode($site->powers->actors);

        $this->assertStringNotContainsString('Camille', $json);
        $this->assertStringNotContainsString('camille.vaeiste', $json);
        $this->assertEquals(
            $site->powers->actors,
            \tool_vae\local\actors::grid($site->powers->capabilities, $site->powers->settings)
        );
    }
}
