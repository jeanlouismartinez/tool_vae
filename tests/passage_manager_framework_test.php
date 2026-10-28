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

use tool_vae\local\passage_manager;
use tool_vae\local\supersession;

/**
 * The correspondences between two records when the framework of one of them is not there any more.
 *
 * WHAT IS ASSERTED. That a record that holds no competency - its framework deleted in tool_lp, or never created because
 * its first import died - is refused by name by the screen of correspondences, and not listed as an empty table: a
 * table with no row would read as "everything is decided". Neither the replaced record nor the replacing one is let
 * through, and the core's exceptions never reach the administrator.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\passage_manager::class)]
final class passage_manager_framework_test extends \advanced_testcase {
    /**
     * Two records, the second declared to replace the first.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator('tool_vae');
        $generator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'C01']);
        $generator->create_source(['fichecode' => 'RNCP200', 'ficherang' => 'C01']);
        supersession::declare_successor('RNCP100', 'RNCP200');
    }

    /**
     * What an administrator leaves in tool_lp when nobody had declared on the framework: it is deleted.
     *
     * @param string $fichecode
     */
    private function delete_framework_of(string $fichecode): void {
        global $DB;

        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => $fichecode], MUST_EXIST);
        $DB->delete_records('competency', ['competencyframeworkid' => $frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $frameworkid]);
    }

    /**
     * Run a call that must be refused, and say with which error code.
     *
     * @param callable $call
     * @param string $errorcode
     */
    private function assert_refused(callable $call, string $errorcode): void {
        try {
            $call();
            $this->fail("Expected $errorcode.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    public function test_the_two_records_with_their_frameworks_are_listed(): void {
        $rows = (new passage_manager())->overview('RNCP100');

        $this->assertCount(1, $rows);
        $this->assertCount(1, (new passage_manager())->targets('RNCP100'));
    }

    public function test_a_replaced_record_whose_framework_was_deleted_is_refused_by_name(): void {
        $this->delete_framework_of('RNCP100');

        $this->assert_refused(function () {
            (new passage_manager())->overview('RNCP100');
        }, 'errorpassagenocompetencies');
    }

    public function test_a_replacing_record_whose_framework_was_deleted_is_refused_by_name(): void {
        $this->delete_framework_of('RNCP200');

        $this->assert_refused(function () {
            (new passage_manager())->overview('RNCP100');
        }, 'errorpassagenocompetencies');
        $this->assert_refused(function () {
            (new passage_manager())->targets('RNCP100');
        }, 'errorpassagenocompetencies');
    }

    public function test_a_record_with_no_framework_at_all_is_refused_by_name(): void {
        global $DB;

        // What a first import that died leaves: a row, and frameworkid 0.
        $DB->set_field('tool_vae_source', 'frameworkid', 0, ['fichecode' => 'RNCP100']);

        $this->assert_refused(function () {
            (new passage_manager())->overview('RNCP100');
        }, 'errorpassagenocompetencies');
    }
}
