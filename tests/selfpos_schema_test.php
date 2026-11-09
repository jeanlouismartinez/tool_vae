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

/**
 * The table of the self-positioning of the vaeiste (lot 1a): what the spec names is there.
 *
 * THE POSITIONING OF THE VAEISTE, NEVER A VALIDATION. The vaeiste says, by a step of a readable scale, where they stand on
 * every node of their record, at every level. Lines are added and never changed; the latest line stands. Nothing is
 * ever computed from them.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class selfpos_schema_test extends \advanced_testcase {
    /**
     * Every column the spec names is in the table.
     */
    public function test_the_table_carries_the_columns_the_spec_names(): void {
        global $DB;

        $manager = $DB->get_manager();
        $this->assertTrue($manager->table_exists('tool_vae_selfpos'), 'The table tool_vae_selfpos is missing.');
        $table = new \xmldb_table('tool_vae_selfpos');
        $fields = [
            'id', 'uuid', 'candidateid', 'fichecode', 'ficherang', 'level', 'levellabel', 'justification',
            'justificationformat', 'linehash', 'importedat', 'timecreated',
        ];
        foreach ($fields as $field) {
            $this->assertTrue($manager->field_exists($table, $field), 'The column ' . $field . ' is missing.');
        }
    }

    /**
     * The index that reads the latest line of a (vaeiste, record, rank) is there, and the uuid is unique.
     */
    public function test_the_index_that_reads_the_latest_line_exists(): void {
        global $DB;

        $manager = $DB->get_manager();
        $table = new \xmldb_table('tool_vae_selfpos');
        $latest = new \xmldb_index('candidaterank', XMLDB_INDEX_NOTUNIQUE, ['candidateid', 'fichecode', 'ficherang', 'id']);
        $this->assertTrue($manager->index_exists($table, $latest), 'The index candidaterank is missing.');
        $uuid = new \xmldb_index('uuid', XMLDB_INDEX_UNIQUE, ['uuid']);
        $this->assertTrue($manager->index_exists($table, $uuid), 'The unique index on uuid is missing.');
    }

    /**
     * A line as the spec describes it is accepted by the database: the record alone (empty rank), a withdrawal (level 0)
     * and a justification of ten thousand characters.
     */
    public function test_a_line_as_the_spec_describes_it_fits(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $id = $DB->insert_record('tool_vae_selfpos', (object) [
            'uuid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'candidateid' => (int) $user->id,
            'fichecode' => 'RNCP100',
            'ficherang' => '',
            'level' => 0,
            'levellabel' => str_repeat('a', 100),
            'justification' => str_repeat('j', 10000),
            'justificationformat' => FORMAT_MARKDOWN,
            'linehash' => str_repeat('0', 64),
            'importedat' => null,
            'timecreated' => time(),
        ]);
        $row = $DB->get_record('tool_vae_selfpos', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('', $row->ficherang);
        $this->assertSame(0, (int) $row->level);
        $this->assertSame(10000, \core_text::strlen($row->justification));
        $this->assertNull($row->importedat);
    }
}
