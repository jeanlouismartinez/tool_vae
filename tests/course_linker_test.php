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
use tool_vae\local\course_linker;

/**
 * Tests for attaching a certification record to a course - lot 8 bis, asked by the project owner
 * on 12 October 2026.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\course_linker::class)]
final class course_linker_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass Holds the manager role in the course. */
    private $manager;

    /** @var int[] The two competencies of the record, C03 then C04. */
    private $competencies;

    /**
     * A record of two competencies, a course, and a manager of that course.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $generator->get_plugin_generator('tool_vae')->create_source([
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
        ]);
        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP36832']);
        $generator->get_plugin_generator(competency_gateway::SUBSYSTEM)->create_competency([
            'competencyframeworkid' => $frameworkid,
            'idnumber' => 'RNCP36832-C04',
            'shortname' => 'Second competency',
        ]);
        $this->competencies = [
            (int) $DB->get_field('competency', 'id', ['idnumber' => 'RNCP36832-C03'], MUST_EXIST),
            (int) $DB->get_field('competency', 'id', ['idnumber' => 'RNCP36832-C04'], MUST_EXIST),
        ];

        $this->course = $generator->create_course();
        $this->manager = $generator->create_user();
        $managerrole = (int) $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerrole, $this->manager->id, \context_course::instance($this->course->id)->id);
    }

    /**
     * The competencies of the course.
     *
     * @return int[]
     */
    private function in_course(): array {
        $ids = (new competency_gateway())->course_competency_ids((int) $this->course->id);
        sort($ids);

        return $ids;
    }

    public function test_the_whole_record_comes_into_the_course_once(): void {
        $this->setUser($this->manager);
        course_linker::require_can_link((int) $this->course->id);
        $linker = new course_linker();

        $this->assertSame(2, $linker->link((int) $this->course->id, 'RNCP36832'));
        $this->assertSame($this->competencies, $this->in_course());

        // A second time adds nothing and breaks nothing.
        $this->assertSame(0, $linker->link((int) $this->course->id, 'RNCP36832'));
        $this->assertSame($this->competencies, $this->in_course());
    }

    public function test_the_page_says_how_much_of_each_record_the_course_holds(): void {
        $this->setUser($this->manager);
        $linker = new course_linker();

        $before = $linker->records((int) $this->course->id)['RNCP36832'];
        $this->assertSame(2, $before->total);
        $this->assertSame(0, $before->incourse);

        $linker->link((int) $this->course->id, 'RNCP36832');
        $this->assertSame(2, $linker->records((int) $this->course->id)['RNCP36832']->incourse);
    }

    public function test_a_competency_the_source_no_longer_carries_is_not_brought_in(): void {
        global $DB;

        $DB->insert_record('tool_vae_obsolete', (object) [
            'competencyid' => $this->competencies[1],
            'sourceid' => (int) $DB->get_field('tool_vae_source', 'id', ['fichecode' => 'RNCP36832']),
            'detectedat' => time(),
            'kind' => 'missing',
        ]);

        $this->setUser($this->manager);
        $this->assertSame(1, (new course_linker())->link((int) $this->course->id, 'RNCP36832'));
        $this->assertSame([$this->competencies[0]], $this->in_course());
    }

    public function test_a_record_not_imported_is_refused_by_name(): void {
        $this->setUser($this->manager);

        try {
            (new course_linker())->link((int) $this->course->id, 'RS9999');
            $this->fail('A record that was never imported was attached.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorlinkunknownrecord', $e->errorcode);
        }
    }

    /**
     * A record whose framework was deleted in tool_lp is left out of the screen, and breaks nobody's.
     *
     * The core lets an administrator delete a framework nobody has declared on, and tool_vae_source keeps a row
     * pointing at nothing. The screen lists every record, and reading the competencies of that one raised for
     * every manager in every course - the records that are fine included.
     */
    public function test_a_record_whose_framework_was_deleted_in_the_core_is_left_out(): void {
        global $DB;

        $this->getDataGenerator()->get_plugin_generator('tool_vae')->create_source([
            'fichecode' => 'RS00000',
            'ficherang' => 'C01',
        ]);
        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RS00000']);
        $DB->delete_records('competency', ['competencyframeworkid' => $frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $frameworkid]);

        $this->setUser($this->manager);
        $linker = new course_linker();

        $this->assertSame(['RNCP36832'], array_keys($linker->records((int) $this->course->id)));
        $this->assertSame(2, $linker->link((int) $this->course->id, 'RNCP36832'), 'The record that is fine was not usable.');
    }

    /**
     * Attaching a record whose framework is gone is refused by name, not told as "already in the course".
     */
    public function test_a_record_whose_framework_is_gone_is_refused_by_name(): void {
        global $DB;

        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP36832']);
        $DB->delete_records('competency', ['competencyframeworkid' => $frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $frameworkid]);

        $this->setUser($this->manager);
        try {
            (new course_linker())->link((int) $this->course->id, 'RNCP36832');
            $this->fail('A record with no framework was reported as attached.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorlinknoframework', $e->errorcode);
        }
    }

    /**
     * A hidden framework is refused by name, and the screen says why before the button is pressed.
     *
     * The core refuses to attach a competency of a hidden framework to a course with a coding_exception, an error
     * meant for a programmer. "Visible: No" is a routine gesture of an administrator in tool_lp, the one the core's
     * own help recommends while a framework is being updated - and the screen went on offering the button.
     */
    public function test_a_record_whose_framework_is_hidden_is_refused_with_a_message(): void {
        global $DB;

        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP36832']);
        $this->setUser($this->manager);
        $linker = new course_linker();
        $this->assertFalse($linker->records((int) $this->course->id)['RNCP36832']->hidden, 'The premise is a visible one.');

        // The gesture of the administrator in tool_lp: "Visible: No".
        $DB->set_field('competency_framework', 'visible', 0, ['id' => $frameworkid]);

        $this->assertTrue($linker->records((int) $this->course->id)['RNCP36832']->hidden);
        try {
            $linker->link((int) $this->course->id, 'RNCP36832');
            $this->fail('A record of a hidden framework was attached.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorlinkhiddenframework', $e->errorcode);
        }
        $this->assertSame([], $this->in_course());

        // And visible again, the same record is not hidden and goes into the course.
        $DB->set_field('competency_framework', 'visible', 1, ['id' => $frameworkid]);
        $this->assertFalse($linker->records((int) $this->course->id)['RNCP36832']->hidden);
        $this->assertSame(2, $linker->link((int) $this->course->id, 'RNCP36832'));
    }

    public function test_managers_may_link_and_teachers_may_not(): void {
        $context = \context_course::instance($this->course->id);

        $this->setUser($this->manager);
        $this->assertTrue(course_linker::can_link($context));

        // The editing teacher manages the course's competencies in the core, and still does not
        // choose its certification record: that is the direction's and the managers' decision.
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        $this->assertFalse(course_linker::can_link($context));

        $this->expectException(\required_capability_exception::class);
        course_linker::require_can_link((int) $this->course->id);
    }

    public function test_the_direction_needs_the_capability_and_the_core_right(): void {
        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $context = \context_course::instance($this->course->id);
        $director = $generator->create_user();
        $role = $generator->create_role();
        assign_capability('tool/vae:linkcourse', CAP_ALLOW, $role, $system, true);
        role_assign($role, $director->id, $system);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($director);

        $this->assertFalse(course_linker::can_link($context), 'The core right to change course competencies was not asked for.');

        assign_capability('moodle/competency:coursecompetencymanage', CAP_ALLOW, $role, $system, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(course_linker::can_link($context));
    }
}
