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

use tool_vae\local\attestation_manager;
use tool_vae\local\competency_gateway;
use tool_vae\local\organisation;
use tool_vae\local\piece_manager;
use tool_vae\local\scope_manager;

/**
 * The pathway of a candidate: their records × blocs × competencies × what they did and where it is taught (T-90).
 *
 * COUNTS, NEVER A VERDICT, as in coverage: no total, no rate, no threshold, dates alone (T-40). What is new here is
 * read from the core through the gateway - the courses that carry a competency, the learning plans that contain it -
 * and shown next to the counts; a record the candidate never declared on but that a plan names is cited, with its
 * courses and plans, without a table of counts (decision of 2 November 2026: the plan is information on the offer of
 * training, not a measure of the dossier).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\organisation::class)]
final class organisation_test extends \advanced_testcase {
    /** @var \stdClass The candidate whose pathway is read. */
    private $candidate;

    /** @var \stdClass A manager who may attest the candidate's declarations. */
    private $manager;

    /** @var \tool_vae_generator */
    private $plugingenerator;

    /** @var \component_generator_base The generator of the competency subsystem, got by the gateway's constant. */
    private $coregenerator;

    /** @var int[] Competency ids by idnumber. */
    private $competencies = [];

    /**
     * RNCP100 with two blocs (BC01 holding C01, BC02 holding C02); RS200 with one competency, never declared on.
     * A candidate who may declare and read their own dossier; a manager who may attest.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('piecesopen', 1, 'tool_vae');

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
            'fichecode' => 'RS200', 'ficherang' => 'C01', 'shortname' => 'Utiliser un lève-personne',
        ]);

        $gateway = new competency_gateway();
        foreach (['RNCP100', 'RS200'] as $code) {
            foreach ($gateway->list_framework_competencies((int) $gateway->find_framework($code)) as $competency) {
                $this->competencies[$competency->idnumber] = (int) $competency->id;
            }
        }

        $this->candidate = $generator->create_user();
        $this->manager = $generator->create_user();
        $own = $generator->create_role();
        foreach (['tool/vae:declare', 'tool/vae:viewown'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $own, \context_system::instance()->id, true);
        }
        role_assign($own, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        $follows = $generator->create_role();
        foreach (['tool/vae:viewassigned', 'tool/vae:attest'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $follows, \context_system::instance()->id, true);
        }
        role_assign($follows, $this->manager->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * A declaration of the candidate on a competency of RNCP100.
     *
     * @param string $rank
     * @param string $situation
     * @return \stdClass
     */
    private function declare(string $rank, string $situation): \stdClass {
        return $this->plugingenerator->create_declaration([
            'userid' => $this->candidate->id,
            'fichecode' => 'RNCP100',
            'ficherang' => $rank,
            'situation' => $situation,
        ]);
    }

    /**
     * The pathway, read as the candidate.
     *
     * @return \stdClass
     */
    private function pathway(): \stdClass {
        $this->setUser($this->candidate);

        return organisation::for_candidate((int) $this->candidate->id);
    }

    /**
     * The row of one competency of a declared record.
     *
     * @param \stdClass $pathway
     * @param string $idnumber
     * @return \stdClass
     */
    private function row(\stdClass $pathway, string $idnumber): \stdClass {
        return $pathway->declared['RNCP100']->competencies[$idnumber];
    }

    /**
     * Declarations, pieces and attestations in force are counted per competency; the last act is the most recent date.
     */
    public function test_counts_declarations_pieces_attestations_and_last_act_per_competency(): void {
        global $DB;
        $first = $this->declare('C01', 'Nuit avec la soignante');
        $second = $this->declare('C01', 'Toilette du matin');
        $this->plugingenerator->create_attestation(['evidenceid' => $first->id, 'userid' => $this->manager->id]);
        $this->plugingenerator->create_piece([
            'evidenceid' => $second->id, 'nature' => 'other', 'url' => 'https://example.com/planning',
        ]);
        $DB->set_field('tool_vae_attest', 'attestedat', 1770000000, ['evidenceid' => $first->id]);
        $DB->set_field('tool_vae_evidence', 'timecreated', 1760000000, []);
        $DB->set_field('tool_vae_piece', 'timecreated', 1780000000, []);

        $pathway = $this->pathway();

        $c01 = $this->row($pathway, 'RNCP100-C01');
        $this->assertSame(2, $c01->declarations);
        $this->assertSame(1, $c01->attested);
        $this->assertSame(1, $c01->pieces);
        $this->assertSame(1780000000, $c01->lastact);
        $c02 = $this->row($pathway, 'RNCP100-C02');
        $this->assertSame(0, $c02->declarations);
        $this->assertSame(0, $c02->pieces);
        $this->assertNull($c02->lastact);
        $this->assertSame(['RNCP100'], array_keys($pathway->declared));
    }

    /**
     * A bloc line adds up the pieces and the last act of the competencies under it, as coverage does for the declarations.
     */
    public function test_a_bloc_line_adds_up_the_pieces_and_the_last_act_of_its_competencies(): void {
        global $DB;
        // BC01 holds two competencies and is itself declared on; the most recent act is neither the bloc's, nor the one of
        // the competency that comes last, so that a copy, a "last one wins" and a sum that forgets the bloc are all seen.
        $this->plugingenerator->create_competency([
            'fichecode' => 'RNCP100', 'ficherang' => 'C03', 'shortname' => 'Aider a la toilette', 'bloc' => 'BC01',
        ]);
        $claims = [
            $this->declare('BC01', 'Coordination de l\'equipe'),
            $this->declare('C01', 'Nuit avec la soignante'),
            $this->declare('C01', 'Toilette du matin'),
            $this->declare('C03', 'Aide au repas'),
        ];
        $when = [1760000000, 1780000000, 1770000000, 1765000000];
        foreach ($claims as $n => $claim) {
            $this->plugingenerator->create_piece([
                'evidenceid' => $claim->id, 'nature' => 'other', 'url' => 'https://example.com/p' . $n,
            ]);
            $DB->set_field('tool_vae_piece', 'timecreated', $when[$n], [
                'id' => (int) $DB->get_field_sql('SELECT MAX(id) FROM {tool_vae_piece}'),
            ]);
        }
        $DB->set_field('tool_vae_evidence', 'timecreated', 1750000000, []);

        $pathway = $this->pathway();

        $bloc = $this->row($pathway, 'RNCP100-BC01');
        $this->assertTrue($bloc->isbloc);
        $this->assertSame(1, $bloc->pieces, 'The bloc\'s own line keeps its own count.');
        $this->assertSame(1760000000, $bloc->lastact);
        $this->assertSame(4, $bloc->blocpieces, 'Its own piece, the two of C01 and the one of C03.');
        $this->assertSame(1780000000, $bloc->bloclastact, 'The most recent act of the bloc and of what is under it.');
        $this->assertSame(2, $this->row($pathway, 'RNCP100-C01')->pieces);
        $untouched = $this->row($pathway, 'RNCP100-BC02');
        $this->assertSame(0, $untouched->blocpieces);
        $this->assertNull($untouched->bloclastact);
    }

    /**
     * A declared record carries the name its framework was given at the import, as a record cited by a plan does.
     */
    public function test_a_declared_record_carries_the_name_of_its_framework(): void {
        global $DB;
        $this->declare('C01', 'Nuit avec la soignante');
        $gateway = new competency_gateway();
        $DB->set_field('competency_framework', 'shortname', 'Assistant de vie', ['id' => $gateway->find_framework('RNCP100')]);

        $this->assertSame('Assistant de vie', $this->pathway()->declared['RNCP100']->name);
    }

    /**
     * The courses that carry a competency, and the candidate's plans that contain it, with the core's words for the status.
     */
    public function test_lists_courses_and_plans_of_a_competency_with_core_labels(): void {
        $this->declare('C01', 'Nuit avec la soignante');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cours visible']);
        $this->coregenerator->create_course_competency([
            'courseid' => $course->id, 'competencyid' => $this->competencies['RNCP100-C01'],
        ]);
        $plan = $this->coregenerator->create_plan([
            'userid' => $this->candidate->id, 'name' => 'Plan AES', 'status' => competency_gateway::PLAN_ACTIVE,
        ]);
        $this->coregenerator->create_plan_competency([
            'planid' => $plan->get('id'), 'competencyid' => $this->competencies['RNCP100-C01'],
        ]);

        $pathway = $this->pathway();

        $c01 = $this->row($pathway, 'RNCP100-C01');
        $this->assertSame(['Cours visible'], array_column($c01->courses, 'fullname'));
        $this->assertSame(['Plan AES'], array_column($c01->plans, 'name'));
        $this->assertSame(
            get_string('planstatusactive', competency_gateway::SUBSYSTEM),
            $c01->plans[0]->statuslabel
        );
        $this->assertSame([], $this->row($pathway, 'RNCP100-C02')->courses);
        $this->assertSame([], $this->row($pathway, 'RNCP100-C02')->plans);
        $this->assertSame(['Plan AES'], array_column($pathway->plans, 'name'));
        $this->assertTrue($pathway->plansreadable);
    }

    /**
     * A site that took planviewown from its users hides their plans from them: the pathway says so, and does not say "none".
     */
    public function test_plans_the_reader_may_not_read_are_reported_unreadable_not_absent(): void {
        $this->declare('C01', 'Nuit avec la soignante');
        $plan = $this->coregenerator->create_plan([
            'userid' => $this->candidate->id, 'name' => 'Plan AES', 'status' => competency_gateway::PLAN_ACTIVE,
        ]);
        $this->coregenerator->create_plan_competency([
            'planid' => $plan->get('id'), 'competencyid' => $this->competencies['RNCP100-C01'],
        ]);
        $userrole = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:planviewown', CAP_PROHIBIT, $userrole, \context_system::instance()->id, true);
        role_assign($userrole, $this->candidate->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $pathway = $this->pathway();

        $this->assertFalse($pathway->plansreadable);
        $this->assertSame([], $pathway->plans);
        $this->assertSame([], $this->row($pathway, 'RNCP100-C01')->plans);
    }

    /**
     * A hidden course is still where the competency is taught: it is listed, but its name stays the core's secret.
     *
     * The core shows the name of a hidden course only to who holds moodle/course:viewhiddencourses. The candidate
     * sees that a course exists (no false zero) and not which (decision of 2 November 2026).
     */
    public function test_a_hidden_course_is_listed_without_its_name_unless_the_reader_may_see_hidden_courses(): void {
        $this->declare('C01', 'Nuit avec la soignante');
        $visible = $this->getDataGenerator()->create_course(['fullname' => 'Cours visible']);
        // The second hidden course is created after the first and comes first in the alphabet: the place of a hidden course
        // is its id, which says nothing of its name.
        $hidden = $this->getDataGenerator()->create_course(['fullname' => 'Reclassement du service X', 'visible' => 0]);
        $other = $this->getDataGenerator()->create_course(['fullname' => 'Affectation de Mme Y', 'visible' => 0]);
        foreach ([$visible, $hidden, $other] as $course) {
            $this->coregenerator->create_course_competency([
                'courseid' => $course->id, 'competencyid' => $this->competencies['RNCP100-C01'],
            ]);
        }

        $courses = $this->row($this->pathway(), 'RNCP100-C01')->courses;

        $this->assertCount(3, $courses);
        $this->assertSame(
            [(int) $visible->id, (int) $hidden->id, (int) $other->id],
            array_map('intval', array_column($courses, 'courseid'))
        );
        $this->assertSame([1, 0, 0], array_map('intval', array_column($courses, 'visible')));
        $this->assertSame('Cours visible', $courses[0]->fullname);
        $this->assertNull($courses[1]->fullname, 'The name of a hidden course is not given to who may not see it.');
        $this->assertNull($courses[2]->fullname);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:viewhiddencourses', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $this->candidate->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $courses = $this->row($this->pathway(), 'RNCP100-C01')->courses;

        $this->assertSame(
            ['Affectation de Mme Y', 'Cours visible', 'Reclassement du service X'],
            array_column($courses, 'fullname'),
            'Named, the hidden courses take their place in the order of the words.'
        );
        $this->assertSame([0, 1, 0], array_map('intval', array_column($courses, 'visible')));
    }

    /**
     * The plans of a row are in the order of the words, not the database's: an accent sorts with its letter.
     */
    public function test_plans_are_in_the_order_of_the_words(): void {
        $this->declare('C01', 'Nuit avec la soignante');
        foreach (['Été', 'Zèbre', 'Abeille'] as $name) {
            $plan = $this->coregenerator->create_plan([
                'userid' => $this->candidate->id, 'name' => $name, 'status' => competency_gateway::PLAN_ACTIVE,
            ]);
            $this->coregenerator->create_plan_competency([
                'planid' => $plan->get('id'), 'competencyid' => $this->competencies['RNCP100-C01'],
            ]);
        }

        $pathway = $this->pathway();

        $this->assertSame(['Abeille', 'Été', 'Zèbre'], array_column($this->row($pathway, 'RNCP100-C01')->plans, 'name'));
        $this->assertSame(['Abeille', 'Été', 'Zèbre'], array_column($pathway->plans, 'name'));
    }

    /**
     * The drafts of a plan - draft, waiting for review, in review - are the core's secret: the pathway leaves them out, and
     * the records only they name, until the reader holds the right to see them.
     */
    public function test_draft_plans_are_left_out_until_the_reader_may_see_drafts(): void {
        $this->declare('C01', 'Nuit avec la soignante');
        $named = [
            'Actif' => ['RNCP100-C01', competency_gateway::PLAN_ACTIVE],
            'Brouillon' => ['RNCP100-C01', competency_gateway::PLAN_DRAFT],
            'Plan du lève-personne' => ['RS200-C01', competency_gateway::PLAN_IN_REVIEW],
        ];
        foreach ($named as $name => [$idnumber, $status]) {
            $plan = $this->coregenerator->create_plan(['userid' => $this->candidate->id, 'name' => $name, 'status' => $status]);
            $this->coregenerator->create_plan_competency([
                'planid' => $plan->get('id'), 'competencyid' => $this->competencies[$idnumber],
            ]);
        }

        $pathway = $this->pathway();

        $this->assertSame(['Actif'], array_column($pathway->plans, 'name'));
        $this->assertSame(['Actif'], array_column($this->row($pathway, 'RNCP100-C01')->plans, 'name'));
        $this->assertSame([], $pathway->planned, 'A record only a draft plan names is not cited.');
        $this->assertTrue($pathway->plansreadable, 'The plans are readable: only their drafts are not.');

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:planviewowndraft', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $pathway = $this->pathway();

        $this->assertSame(['Actif', 'Brouillon', 'Plan du lève-personne'], array_column($pathway->plans, 'name'));
        $this->assertSame(['Actif', 'Brouillon'], array_column($this->row($pathway, 'RNCP100-C01')->plans, 'name'));
        $this->assertSame(['RS200'], array_keys($pathway->planned));
    }

    /**
     * A reader who holds the right on drafts and not the right on plans reads the drafts, as the core lists them
     * (api::list_user_plans()): the plans are readable, the active ones are not listed (decision of 3 November 2026).
     */
    public function test_a_reader_who_may_see_only_the_drafts_reads_the_drafts_as_the_core_lists_them(): void {
        $this->declare('C01', 'Nuit avec la soignante');
        $named = [
            'Actif' => ['RNCP100-C01', competency_gateway::PLAN_ACTIVE],
            'Brouillon' => ['RNCP100-C01', competency_gateway::PLAN_DRAFT],
            'Plan du lève-personne' => ['RS200-C01', competency_gateway::PLAN_IN_REVIEW],
        ];
        foreach ($named as $name => [$idnumber, $status]) {
            $plan = $this->coregenerator->create_plan(['userid' => $this->candidate->id, 'name' => $name, 'status' => $status]);
            $this->coregenerator->create_plan_competency([
                'planid' => $plan->get('id'), 'competencyid' => $this->competencies[$idnumber],
            ]);
        }
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('moodle/competency:planviewown', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        assign_capability('moodle/competency:planviewowndraft', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $this->candidate->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $pathway = $this->pathway();

        $this->assertTrue($pathway->plansreadable, 'The right on drafts is a right to read: the plans are not "hidden".');
        $this->assertSame(['Brouillon', 'Plan du lève-personne'], array_column($pathway->plans, 'name'));
        $this->assertSame(['Brouillon'], array_column($this->row($pathway, 'RNCP100-C01')->plans, 'name'));
        $this->assertSame(['RS200'], array_keys($pathway->planned));
    }

    /**
     * The core lists the courses that carry a competency to who holds coursecompetencyview in them, and to nobody else: a
     * course the reader has not that right in is left out, hidden or not, and a competency whose courses are all of those
     * is told apart from one that no course carries (decision of 3 November 2026).
     */
    public function test_a_course_whose_competencies_the_reader_may_not_see_is_left_out(): void {
        global $CFG;
        $this->declare('C01', 'Nuit avec la soignante');
        $open = $this->getDataGenerator()->create_course(['fullname' => 'Cours ouvert']);
        $withdrawn = $this->getDataGenerator()->create_course(['fullname' => 'Cours retiré']);
        $hiddenwithdrawn = $this->getDataGenerator()->create_course(['fullname' => 'Cours caché retiré', 'visible' => 0]);
        foreach ([$open, $withdrawn, $hiddenwithdrawn] as $course) {
            $this->coregenerator->create_course_competency([
                'courseid' => $course->id, 'competencyid' => $this->competencies['RNCP100-C01'],
            ]);
        }
        $this->assertCount(
            3,
            $this->row($this->pathway(), 'RNCP100-C01')->courses,
            'By default every signed-in account reads them.'
        );

        $withdraw = function (\stdClass $course) use ($CFG): void {
            assign_capability(
                'moodle/competency:coursecompetencyview',
                CAP_PROHIBIT,
                $CFG->defaultuserroleid,
                \context_course::instance($course->id)->id,
                true
            );
            accesslib_clear_all_caches_for_unit_testing();
        };
        $withdraw($withdrawn);
        $withdraw($hiddenwithdrawn);

        $row = $this->row($this->pathway(), 'RNCP100-C01');

        $this->assertSame(['Cours ouvert'], array_column($row->courses, 'fullname'));
        $this->assertFalse($row->coursesunreadable, 'One course is readable: the column lists it.');

        $withdraw($open);

        $pathway = $this->pathway();
        $row = $this->row($pathway, 'RNCP100-C01');

        $this->assertSame([], $row->courses);
        $this->assertTrue($row->coursesunreadable, 'Courses carry it and the reader may see none: not "none".');
        $none = $this->row($pathway, 'RNCP100-C02');
        $this->assertSame([], $none->courses);
        $this->assertFalse($none->coursesunreadable, 'No course carries it: "none" is true.');
    }

    /**
     * A record only a plan names tells the courses it may not show from no course at all, as a declared line does.
     */
    public function test_a_planned_record_tells_withheld_courses_from_none(): void {
        global $CFG;
        $this->declare('C01', 'Nuit avec la soignante');
        $plan = $this->coregenerator->create_plan([
            'userid' => $this->candidate->id, 'name' => 'Plan du lève-personne', 'status' => competency_gateway::PLAN_ACTIVE,
        ]);
        $this->coregenerator->create_plan_competency([
            'planid' => $plan->get('id'), 'competencyid' => $this->competencies['RS200-C01'],
        ]);
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cours lève-personne']);
        $this->coregenerator->create_course_competency([
            'courseid' => $course->id, 'competencyid' => $this->competencies['RS200-C01'],
        ]);

        $record = $this->pathway()->planned['RS200'];
        $this->assertSame(['Cours lève-personne'], array_column($record->courses, 'fullname'));
        $this->assertFalse($record->coursesunreadable);

        assign_capability(
            'moodle/competency:coursecompetencyview',
            CAP_PROHIBIT,
            $CFG->defaultuserroleid,
            \context_course::instance($course->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $record = $this->pathway()->planned['RS200'];
        $this->assertSame([], $record->courses);
        $this->assertTrue($record->coursesunreadable);
    }

    /**
     * An active plan of the candidate that names both competencies of RS200, a record they never declared on.
     *
     * RS200 holds one competency in setUp; a second one is added, and the ids are read again.
     */
    private function plan_naming_both_competencies_of_rs200(): void {
        $this->plugingenerator->create_competency(['fichecode' => 'RS200', 'ficherang' => 'C02', 'shortname' => 'Transferer']);
        $gateway = new competency_gateway();
        foreach ($gateway->list_framework_competencies((int) $gateway->find_framework('RS200')) as $competency) {
            $this->competencies[$competency->idnumber] = (int) $competency->id;
        }
        $this->declare('C01', 'Nuit avec la soignante');
        $plan = $this->coregenerator->create_plan([
            'userid' => $this->candidate->id, 'name' => 'Plan du lève-personne', 'status' => competency_gateway::PLAN_ACTIVE,
        ]);
        foreach (['RS200-C01', 'RS200-C02'] as $idnumber) {
            $this->coregenerator->create_plan_competency([
                'planid' => $plan->get('id'), 'competencyid' => $this->competencies[$idnumber],
            ]);
        }
    }

    /**
     * A course carries a competency, and the reader may not see the competencies of it.
     *
     * @param string $idnumber The competency the course carries.
     */
    private function withheld_course_on(string $idnumber): void {
        global $CFG;
        $course = $this->getDataGenerator()->create_course();
        $this->coregenerator->create_course_competency([
            'courseid' => $course->id, 'competencyid' => $this->competencies[$idnumber],
        ]);
        assign_capability(
            'moodle/competency:coursecompetencyview',
            CAP_PROHIBIT,
            $CFG->defaultuserroleid,
            \context_course::instance($course->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The competencies a withheld course may sit on, in a record of two: first or last, whatever order the core gives them.
     *
     * @return array[]
     */
    public static function the_competency_that_holds_the_withheld_course(): array {
        return ['the first one' => ['RS200-C01'], 'the last one' => ['RS200-C02']];
    }

    /**
     * The flag of a record only a plan names is about the whole record: true when courses carry one of its competencies and
     * the reader may see none, whichever competency holds them, the other carrying no course at all.
     *
     * @param string $carrying The competency of RS200 that the withheld course carries.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('the_competency_that_holds_the_withheld_course')]
    public function test_a_planned_record_flags_a_withheld_course_wherever_it_sits(string $carrying): void {
        $this->plan_naming_both_competencies_of_rs200();
        $this->withheld_course_on($carrying);

        $record = $this->pathway()->planned['RS200'];

        $this->assertSame([], $record->courses);
        $this->assertTrue($record->coursesunreadable, 'A course carries the record and the reader sees none: not "none".');
    }

    /**
     * With one course the reader may see, on one competency of the record, and one they may not, on another, the record
     * lists the first and does not say "not readable": it has courses to show.
     */
    public function test_a_planned_record_with_a_readable_course_is_not_flagged_for_the_one_withheld(): void {
        $this->plan_naming_both_competencies_of_rs200();
        $open = $this->getDataGenerator()->create_course(['fullname' => 'Cours lève-personne']);
        $this->coregenerator->create_course_competency([
            'courseid' => $open->id, 'competencyid' => $this->competencies['RS200-C01'],
        ]);
        $this->withheld_course_on('RS200-C02');

        $record = $this->pathway()->planned['RS200'];

        $this->assertSame(['Cours lève-personne'], array_column($record->courses, 'fullname'));
        $this->assertFalse($record->coursesunreadable);
    }

    /**
     * The last act is the most recent of three dates, whichever it is: here the declaration itself, newer than the piece and
     * the attestation.
     */
    public function test_the_last_act_can_be_the_declaration_itself(): void {
        global $DB;
        $claim = $this->declare('C01', 'Nuit avec la soignante');
        $this->plugingenerator->create_attestation(['evidenceid' => $claim->id, 'userid' => $this->manager->id]);
        $this->plugingenerator->create_piece([
            'evidenceid' => $claim->id, 'nature' => 'other', 'url' => 'https://example.com/planning',
        ]);
        $DB->set_field('tool_vae_attest', 'attestedat', 1770000000, ['evidenceid' => $claim->id]);
        $DB->set_field('tool_vae_piece', 'timecreated', 1780000000, []);
        $DB->set_field('tool_vae_evidence', 'timecreated', 1790000000, ['id' => $claim->id]);

        $this->assertSame(1790000000, $this->row($this->pathway(), 'RNCP100-C01')->lastact);
    }

    /**
     * The last act of a bloc counts its own line: here the bloc is declared on after the competency under it.
     */
    public function test_the_last_act_of_a_bloc_counts_its_own_line(): void {
        global $DB;
        $under = $this->declare('C02', 'Repas du soir');
        $onbloc = $this->declare('BC02', 'Organisation des repas');
        $DB->set_field('tool_vae_evidence', 'timecreated', 1760000000, ['id' => $under->id]);
        $DB->set_field('tool_vae_evidence', 'timecreated', 1790000000, ['id' => $onbloc->id]);

        $bloc = $this->row($this->pathway(), 'RNCP100-BC02');

        $this->assertSame(1790000000, $bloc->bloclastact);
        $this->assertSame(1790000000, $bloc->lastact);
        $this->assertSame(1760000000, $this->row($this->pathway(), 'RNCP100-C02')->lastact);
        $this->assertSame(2, $bloc->blocdeclarations);
    }

    /**
     * A record only plans name carries the name of its framework, and the courses and plans of every competency of it.
     */
    public function test_a_planned_record_gathers_the_courses_and_plans_of_all_its_competencies(): void {
        global $DB;
        $this->plugingenerator->create_competency(['fichecode' => 'RS200', 'ficherang' => 'C02', 'shortname' => 'Transferer']);
        $gateway = new competency_gateway();
        $framework = (int) $gateway->find_framework('RS200');
        $DB->set_field('competency_framework', 'shortname', 'Utiliser un leve-personne', ['id' => $framework]);
        foreach ($gateway->list_framework_competencies($framework) as $competency) {
            $this->competencies[$competency->idnumber] = (int) $competency->id;
        }
        $this->declare('C01', 'Nuit avec la soignante');
        $wanted = ['RS200-C01' => ['Plan B', 'Cours B'], 'RS200-C02' => ['Plan A', 'Cours A']];
        foreach ($wanted as $idnumber => [$planname, $coursename]) {
            $plan = $this->coregenerator->create_plan([
                'userid' => $this->candidate->id, 'name' => $planname, 'status' => competency_gateway::PLAN_ACTIVE,
            ]);
            $this->coregenerator->create_plan_competency([
                'planid' => $plan->get('id'), 'competencyid' => $this->competencies[$idnumber],
            ]);
            $course = $this->getDataGenerator()->create_course(['fullname' => $coursename]);
            $this->coregenerator->create_course_competency([
                'courseid' => $course->id, 'competencyid' => $this->competencies[$idnumber],
            ]);
        }

        $planned = $this->pathway()->planned['RS200'];

        $this->assertSame('Utiliser un leve-personne', $planned->name);
        $this->assertSame(['Cours A', 'Cours B'], array_column($planned->courses, 'fullname'));
        $this->assertSame(['Plan A', 'Plan B'], array_column($planned->plans, 'name'));
    }

    /**
     * A record a plan names but the candidate never declared on is cited, with its courses and plans, without counts.
     */
    public function test_a_record_present_in_a_plan_without_declaration_is_named_without_counts(): void {
        $this->declare('C01', 'Nuit avec la soignante');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Manutention']);
        $this->coregenerator->create_course_competency([
            'courseid' => $course->id, 'competencyid' => $this->competencies['RS200-C01'],
        ]);
        $plan = $this->coregenerator->create_plan([
            'userid' => $this->candidate->id, 'name' => 'Plan RS', 'status' => competency_gateway::PLAN_ACTIVE,
        ]);
        $this->coregenerator->create_plan_competency([
            'planid' => $plan->get('id'), 'competencyid' => $this->competencies['RS200-C01'],
        ]);

        $pathway = $this->pathway();

        $this->assertSame(['RNCP100'], array_keys($pathway->declared));
        $this->assertSame(['RS200'], array_keys($pathway->planned));
        $planned = $pathway->planned['RS200'];
        $this->assertSame('RS200', $planned->fichecode);
        $this->assertSame(['Manutention'], array_column($planned->courses, 'fullname'));
        $this->assertSame(['Plan RS'], array_column($planned->plans, 'name'));
        $this->assertObjectNotHasProperty('competencies', $planned);
        $this->assertObjectNotHasProperty('declarations', $planned);
    }

    /**
     * A removed piece and a withdrawn attestation vouch for nothing: neither counted nor dated.
     */
    public function test_a_removed_piece_and_a_withdrawn_attestation_are_not_counted(): void {
        global $DB;
        $declaration = $this->declare('C01', 'Nuit avec la soignante');
        $this->plugingenerator->create_attestation(['evidenceid' => $declaration->id, 'userid' => $this->manager->id]);
        $this->plugingenerator->create_piece([
            'evidenceid' => $declaration->id, 'nature' => 'other', 'url' => 'https://example.com/planning',
        ]);
        $DB->set_field('tool_vae_evidence', 'timecreated', 1760000000, []);
        $DB->set_field('tool_vae_attest', 'attestedat', 1770000000, []);
        $DB->set_field('tool_vae_piece', 'timecreated', 1780000000, []);
        $this->setUser($this->candidate);
        (new piece_manager())->remove((int) $DB->get_field('tool_vae_piece', 'id', []));
        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $DB->get_field('tool_vae_attest', 'id', []), 'erreur');

        $c01 = $this->row($this->pathway(), 'RNCP100-C01');

        $this->assertSame(1, $c01->declarations);
        $this->assertSame(0, $c01->attested);
        $this->assertSame(0, $c01->pieces);
        $this->assertSame(1760000000, $c01->lastact);
    }

    /**
     * An attestation in force counts in the last act when it is the most recent date.
     */
    public function test_an_attestation_in_force_can_be_the_last_act(): void {
        global $DB;
        $declaration = $this->declare('C01', 'Nuit avec la soignante');
        $this->plugingenerator->create_attestation(['evidenceid' => $declaration->id, 'userid' => $this->manager->id]);
        $DB->set_field('tool_vae_evidence', 'timecreated', 1760000000, []);
        $DB->set_field('tool_vae_attest', 'attestedat', 1790000000, []);

        $this->assertSame(1790000000, $this->row($this->pathway(), 'RNCP100-C01')->lastact);
    }

    /**
     * The candidate's "outside my activity" marks are carried along, by competency, as a fact and not a count (T-42).
     */
    public function test_marks_outside_activity_are_carried_by_competency(): void {
        $this->declare('C01', 'Nuit avec la soignante');
        set_config('scopeopen', 1, 'tool_vae');
        $this->setUser($this->candidate);
        scope_manager::mark((int) $this->candidate->id, 'RNCP100', 'C02');

        $pathway = $this->pathway();

        $this->assertFalse($this->row($pathway, 'RNCP100-C01')->scoped);
        $this->assertTrue($this->row($pathway, 'RNCP100-C02')->scoped);
        $this->assertSame(['RNCP100-C02'], array_keys($pathway->marks));
    }

    /**
     * A declared record whose provenance row is gone keeps its counts, and has no courses nor plans to show.
     */
    public function test_a_declared_record_whose_source_is_gone_keeps_its_counts_without_courses(): void {
        global $DB;
        $this->declare('C01', 'Nuit avec la soignante');
        $DB->delete_records('tool_vae_source', ['fichecode' => 'RNCP100']);

        $pathway = $this->pathway();

        $this->assertArrayHasKey('RNCP100', $pathway->declared);
        $this->assertSame('RNCP100', $pathway->declared['RNCP100']->name, 'No framework, so the code stands for the name.');
        $row = $pathway->declared['RNCP100']->competencies['RNCP100-C01'];
        $this->assertSame(1, $row->declarations);
        $this->assertSame([], $row->courses);
        $this->assertSame([], $row->plans);
    }

    /**
     * The number of readings does not grow with the number of competencies: one query per table, never one per row.
     *
     * No absolute bound - it differs between PostgreSQL and MariaDB. The pathway is read on a record of 2 competencies,
     * then on the same record carrying 12, and the two counts must be equal (patron identity_map_test).
     */
    public function test_reads_do_not_grow_with_the_number_of_competencies(): void {
        global $DB;
        $this->declare('C01', 'Nuit avec la soignante');
        $this->declare('C02', 'Repas du soir');
        // A course, a plan on the declared record and a plan on a record only a plan names: the readings of the core are
        // part of the count, and must not grow with the competencies either.
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cours des repas']);
        $plan = $this->coregenerator->create_plan([
            'userid' => $this->candidate->id, 'name' => 'Plan AES', 'status' => competency_gateway::PLAN_ACTIVE,
        ]);
        foreach (['RNCP100-C01', 'RNCP100-C02', 'RS200-C01'] as $idnumber) {
            $this->coregenerator->create_plan_competency([
                'planid' => $plan->get('id'), 'competencyid' => $this->competencies[$idnumber],
            ]);
            $this->coregenerator->create_course_competency([
                'courseid' => $course->id, 'competencyid' => $this->competencies[$idnumber],
            ]);
        }
        $this->setUser($this->candidate);
        organisation::for_candidate((int) $this->candidate->id);

        $before = $DB->perf_get_reads();
        $first = organisation::for_candidate((int) $this->candidate->id);
        $small = $DB->perf_get_reads() - $before;
        $this->assertGreaterThan(0, $small);
        $this->assertSame(['Plan AES'], array_column($first->declared['RNCP100']->competencies['RNCP100-C01']->plans, 'name'));
        $this->assertSame(['RS200'], array_keys($first->planned));

        $known = $this->competencies;
        for ($i = 3; $i <= 12; $i++) {
            $rank = sprintf('C%02d', $i);
            $this->plugingenerator->create_competency([
                'fichecode' => 'RNCP100', 'ficherang' => $rank, 'shortname' => "Compétence $rank", 'bloc' => 'BC02',
            ]);
            $this->declare($rank, "Situation $rank");
        }
        $gateway = new competency_gateway();
        foreach ($gateway->list_framework_competencies((int) $gateway->find_framework('RNCP100')) as $competency) {
            if (!in_array((int) $competency->id, $known, true)) {
                $this->coregenerator->create_plan_competency([
                    'planid' => $plan->get('id'), 'competencyid' => $competency->id,
                ]);
                $this->coregenerator->create_course_competency(['courseid' => $course->id, 'competencyid' => $competency->id]);
            }
        }
        $this->setUser($this->candidate);
        organisation::for_candidate((int) $this->candidate->id);

        $before = $DB->perf_get_reads();
        $pathway = organisation::for_candidate((int) $this->candidate->id);
        $large = $DB->perf_get_reads() - $before;

        $this->assertCount(14, $pathway->declared['RNCP100']->competencies);
        $last = $pathway->declared['RNCP100']->competencies['RNCP100-C12'];
        $this->assertSame(['Plan AES'], array_column($last->plans, 'name'), 'The new competencies carry the plan.');
        $this->assertSame(['Cours des repas'], array_column($last->courses, 'fullname'), 'And the course.');
        $this->assertSame($small, $large, 'The pathway was read once per competency.');
    }

    /**
     * The pathway names an activity to who is in its course, and only says there is one to who is not (lot 1 bis).
     */
    public function test_an_activity_is_named_to_who_can_see_it_and_said_to_exist_to_who_cannot(): void {
        $this->declare('C01', 'Nuit avec la soignante');
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cours du lever']);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz du lever']);
        $this->coregenerator->create_course_competency([
            'courseid' => $course->id, 'competencyid' => $this->competencies['RNCP100-C01'],
        ]);
        $this->coregenerator->create_course_module_competency([
            'cmid' => $quiz->cmid, 'competencyid' => $this->competencies['RNCP100-C01'],
        ]);

        $row = $this->row($this->pathway(), 'RNCP100-C01');
        $this->assertCount(1, $row->activities);
        $this->assertSame('Cours du lever', $row->activities[0]->course);
        $this->assertNull($row->activities[0]->name, 'Not in the course: the core does not show the activity.');
        $this->assertNull($row->activities[0]->type);
        $this->assertNull($row->activities[0]->url);
        $this->assertFalse($row->activitiesunreadable);

        $this->getDataGenerator()->enrol_user($this->candidate->id, $course->id, 'student');
        accesslib_clear_all_caches_for_unit_testing();
        \course_modinfo::clear_instance_cache();

        $row = $this->row($this->pathway(), 'RNCP100-C01');
        $this->assertSame('Quiz du lever', $row->activities[0]->name);
        $this->assertSame(get_string('modulename', 'mod_quiz'), $row->activities[0]->type);
        $this->assertSame((new \moodle_url('/mod/quiz/view.php', ['id' => $quiz->cmid]))->out(false), $row->activities[0]->url);

        $none = $this->row($this->pathway(), 'RNCP100-C02');
        $this->assertSame([], $none->activities);
        $this->assertFalse($none->activitiesunreadable, 'No activity carries it: "none" is true.');
    }

    /**
     * An activity the reader may not read the competencies of is set aside, and the line says "not readable", not "none".
     */
    public function test_an_activity_whose_competencies_the_reader_may_not_read_is_not_drawn_as_none(): void {
        $this->declare('C01', 'Nuit avec la soignante');
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz retenu']);
        $this->coregenerator->create_course_module_competency([
            'cmid' => $quiz->cmid, 'competencyid' => $this->competencies['RNCP100-C01'],
        ]);
        $this->getDataGenerator()->enrol_user($this->candidate->id, $course->id, 'student');
        $this->assertCount(1, $this->row($this->pathway(), 'RNCP100-C01')->activities);

        global $CFG;
        assign_capability(
            'moodle/competency:coursecompetencyview',
            CAP_PROHIBIT,
            $CFG->defaultuserroleid,
            \context_module::instance($quiz->cmid)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $row = $this->row($this->pathway(), 'RNCP100-C01');
        $this->assertSame([], $row->activities);
        $this->assertTrue($row->activitiesunreadable);
    }

    /**
     * A record only a plan names carries its activities, deduplicated, as it carries its courses (lot 1 bis).
     */
    public function test_a_planned_record_carries_its_activities(): void {
        $this->declare('C01', 'Nuit avec la soignante');
        $plan = $this->coregenerator->create_plan([
            'userid' => $this->candidate->id, 'name' => 'Plan du lève-personne', 'status' => competency_gateway::PLAN_ACTIVE,
        ]);
        $this->coregenerator->create_plan_competency([
            'planid' => $plan->get('id'), 'competencyid' => $this->competencies['RS200-C01'],
        ]);
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cours lève-personne']);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Quiz lève-personne']);
        $this->coregenerator->create_course_module_competency([
            'cmid' => $quiz->cmid, 'competencyid' => $this->competencies['RS200-C01'],
        ]);
        $this->getDataGenerator()->enrol_user($this->candidate->id, $course->id, 'student');
        accesslib_clear_all_caches_for_unit_testing();

        $record = $this->pathway()->planned['RS200'];

        $this->assertSame(['Quiz lève-personne'], array_column($record->activities, 'name'));
        $this->assertFalse($record->activitiesunreadable);
    }
}
