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

use tool_vae\local\source_frameworks;

/**
 * Which imported records have lost their competency framework.
 *
 * WHAT IS ASSERTED. That a record whose framework an administrator deleted in tool_lp, and a record whose first import
 * died and left frameworkid 0, are both named - and only those: a record with its framework, hidden or not, is not.
 * This is what the administration screen says in words, with the way out.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\source_frameworks::class)]
final class source_frameworks_test extends \advanced_testcase {
    /**
     * Three records, each with its framework.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('tool_vae');
        foreach (['RNCP100', 'RNCP200', 'RS300'] as $code) {
            $generator->create_source(['fichecode' => $code, 'ficherang' => 'C01']);
        }
    }

    /**
     * The rows of tool_vae_source, in the order the screen lists them.
     *
     * @return \stdClass[]
     */
    private function sources(): array {
        global $DB;

        return $DB->get_records('tool_vae_source', null, 'fichecode ASC');
    }

    public function test_a_record_with_its_framework_is_not_reported(): void {
        global $DB;

        $this->assertSame([], source_frameworks::missing($this->sources()));

        // Hidden is not gone: the framework is there, and the course screen says the rest.
        $DB->set_field('competency_framework', 'visible', 0);
        $this->assertSame([], source_frameworks::missing($this->sources()));
    }

    public function test_a_record_whose_framework_was_deleted_is_reported(): void {
        global $DB;

        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP200']);
        $DB->delete_records('competency', ['competencyframeworkid' => $frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $frameworkid]);

        $this->assertSame(['RNCP200'], source_frameworks::missing($this->sources()));
    }

    public function test_a_record_whose_first_import_died_is_reported(): void {
        global $DB;

        $DB->set_field('tool_vae_source', 'frameworkid', 0, ['fichecode' => 'RS300']);

        $this->assertSame(['RS300'], source_frameworks::missing($this->sources()));
    }
}
