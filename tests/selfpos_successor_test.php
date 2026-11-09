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

use tool_vae\local\identity_map;
use tool_vae\local\selfpos_manager;
use tool_vae\local\selfpos_successor;
use tool_vae\local\selfpos_tree;

/**
 * The self-positioning written on a record that another replaces, read on the one that replaces it (lot 1a).
 *
 * READ, NEVER COPIED: the lines stay on the old record; the new one shows them where the administrator decided an
 * equivalent, node to node, one hop, the line that stands only, never the record itself.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(selfpos_successor::class)]
final class selfpos_successor_test extends \advanced_testcase {
    /** @var \tool_vae_generator */
    private $generator;

    /** @var \stdClass */
    private $vaeiste;

    /**
     * RNCP100 (BC01 holding C01 and C02, BC02 holding C03) replaced by RNCP200 (BC01 holding C01, BC02 holding C03 and C04);
     * C01 of the old record is decided to be C01 of the new one, C02 to be C04; C03 and the blocs are not decided.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('tool_vae');
        $this->record('RNCP100', [['BC01', ''], ['BC02', ''], ['C01', 'BC01'], ['C02', 'BC01'], ['C03', 'BC02']]);
        $this->record('RNCP200', [['BC01', ''], ['BC02', ''], ['C01', 'BC01'], ['C03', 'BC02'], ['C04', 'BC02']]);
        $this->generator->create_replacement(['fichecode' => 'RNCP100', 'successorcode' => 'RNCP200']);
        $this->generator->create_correspondence(['from' => 'RNCP100-C01', 'to' => 'RNCP200-C01']);
        $this->generator->create_correspondence(['from' => 'RNCP100-C02', 'to' => 'RNCP200-C04']);

        $this->vaeiste = $this->getDataGenerator()->create_user();
    }

    /**
     * Import a record: the first rank is the source's first competency, the others are added.
     *
     * @param string $code
     * @param array $ranks [rank, parent] pairs.
     */
    private function record(string $code, array $ranks): void {
        foreach ($ranks as $index => [$rank, $parent]) {
            $data = ['fichecode' => $code, 'ficherang' => $rank, 'shortname' => 'Noeud ' . $rank . ' ' . $code];
            if ($index === 0) {
                $this->generator->create_source($data);
            } else {
                $this->generator->create_competency($data + ($parent === '' ? [] : ['parent' => $parent]));
            }
        }
    }

    /**
     * Write one line straight into the table.
     *
     * @param string $code
     * @param string $rank
     * @param int $level
     * @param int $time
     * @return \stdClass
     */
    private function line(string $code, string $rank, int $level, int $time = 1762000000): \stdClass {
        global $DB;

        $row = (object) [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => $this->vaeiste->id,
            'fichecode' => $code,
            'ficherang' => $rank,
            'level' => $level,
            'levellabel' => 'Palier ' . $level,
            'justification' => 'Ligne ' . $code . ' ' . $rank,
            'justificationformat' => 2,
            'linehash' => hash('sha256', $code . $rank . $level . $time),
            'importedat' => null,
            'timecreated' => $time,
        ];
        $row->id = $DB->insert_record(selfpos_manager::TABLE, $row);

        return $row;
    }

    /**
     * What the successor reads for the vaeiste.
     *
     * @param string $code The record read on.
     * @return array Rank => entries.
     */
    private function readings(string $code = 'RNCP200'): array {
        return selfpos_successor::readings((int) $this->vaeiste->id, $code, selfpos_tree::for_record($code));
    }

    public function test_a_node_is_read_on_its_declared_equivalent(): void {
        $this->line('RNCP100', 'C01', 1, 1762000000);
        $this->line('RNCP100', 'C01', 3, 1762000100);
        $this->line('RNCP100', 'C02', 2, 1762000200);

        $read = $this->readings();

        $this->assertSame(['C01', 'C04'], array_keys($read));
        $this->assertCount(1, $read['C01']);
        $this->assertSame('RNCP100', $read['C01'][0]->fromcode);
        $this->assertSame('C01', $read['C01'][0]->fromrank);
        $this->assertSame(3, $read['C01'][0]->level, 'The line that stands, not the first.');
        $this->assertSame('Palier 3', $read['C01'][0]->levellabel);
        $this->assertSame(1762000100, $read['C01'][0]->time);
        $this->assertSame(2, $read['C04'][0]->level, 'The equivalent need not have the same rank.');
    }

    public function test_nothing_is_copied_or_written(): void {
        global $DB;
        $this->line('RNCP100', 'C01', 2);
        $before = $DB->count_records(selfpos_manager::TABLE);
        $hashes = $DB->get_fieldset_select(selfpos_manager::TABLE, 'linehash', '1 = 1');

        $this->readings();

        $this->assertSame($before, $DB->count_records(selfpos_manager::TABLE));
        $this->assertSame($hashes, $DB->get_fieldset_select(selfpos_manager::TABLE, 'linehash', '1 = 1'));
        $this->assertSame(0, $DB->count_records(selfpos_manager::TABLE, ['fichecode' => 'RNCP200']));
    }

    public function test_one_hop_only(): void {
        $this->record('RNCP300', [['BC01', ''], ['C01', 'BC01']]);
        $this->generator->create_replacement(['fichecode' => 'RNCP200', 'successorcode' => 'RNCP300']);
        $this->generator->create_correspondence(['from' => 'RNCP200-C01', 'to' => 'RNCP300-C01']);
        $this->line('RNCP100', 'C01', 4);
        $this->line('RNCP200', 'C01', 2);

        $onthird = $this->readings('RNCP300');

        $this->assertSame(['C01'], array_keys($onthird));
        $this->assertCount(1, $onthird['C01']);
        $this->assertSame('RNCP200', $onthird['C01'][0]->fromcode, 'Read from the record before, not from the first.');
    }

    public function test_a_withdrawn_line_is_not_read(): void {
        $this->line('RNCP100', 'C01', 3, 1762000000);
        $this->line('RNCP100', 'C01', 0, 1762000100);

        $this->assertSame([], $this->readings());
    }

    public function test_the_record_level_is_not_read(): void {
        $this->line('RNCP100', '', 2);
        $this->line('RNCP100', 'C01', 3);

        $read = $this->readings();

        $this->assertArrayNotHasKey('', $read);
        $this->assertSame(['C01'], array_keys($read));
    }

    public function test_a_bloc_is_read_on_a_bloc_when_the_bloc_itself_was_decided(): void {
        $this->line('RNCP100', 'BC01', 2);
        $this->assertSame([], $this->readings(), 'The competencies are decided, the bloc is not: nothing is deduced.');

        $this->generator->create_correspondence(['from' => 'RNCP100-BC01', 'to' => 'RNCP200-BC01']);

        $this->assertSame(['BC01'], array_keys($this->readings()));
    }

    public function test_a_decision_with_a_missing_end_is_not_read(): void {
        global $DB;
        $this->line('RNCP100', 'C01', 3);
        $target = $DB->get_field('competency', 'id', ['idnumber' => 'RNCP200-C01'], MUST_EXIST);
        $DB->delete_records('competency', ['id' => $target]);

        $this->assertSame([], $this->readings());
    }

    public function test_the_lines_of_another_vaeiste_are_never_read(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_user();
        $row = $this->line('RNCP100', 'C01', 3);
        $DB->set_field(selfpos_manager::TABLE, 'candidateid', $other->id, ['id' => $row->id]);

        $this->assertSame([], $this->readings());
        $theirs = selfpos_successor::readings((int) $other->id, 'RNCP200', selfpos_tree::for_record('RNCP200'));
        $this->assertSame(['C01'], array_keys($theirs));
    }

    public function test_several_old_records_give_one_line_each_ordered_by_code(): void {
        $this->record('RNCP050', [['BC01', ''], ['C01', 'BC01']]);
        $this->generator->create_replacement(['fichecode' => 'RNCP050', 'successorcode' => 'RNCP200']);
        $this->generator->create_correspondence(['from' => 'RNCP050-C01', 'to' => 'RNCP200-C01']);
        $this->line('RNCP100', 'C01', 3);
        $this->line('RNCP050', 'C01', 1);

        $this->assertSame(['RNCP050', 'RNCP100'], array_map(fn($entry) => $entry->fromcode, $this->readings()['C01']));
    }

    public function test_the_number_of_reads_does_not_grow_with_the_number_of_nodes_decided(): void {
        global $DB;
        $this->line('RNCP100', 'C01', 3);
        $this->readings();
        $before = $DB->perf_get_reads();
        $this->readings();
        $few = $DB->perf_get_reads() - $before;

        $this->generator->create_correspondence(['from' => 'RNCP100-C03', 'to' => 'RNCP200-C03']);
        $this->generator->create_correspondence(['from' => 'RNCP100-BC01', 'to' => 'RNCP200-BC01']);
        $this->generator->create_correspondence(['from' => 'RNCP100-BC02', 'to' => 'RNCP200-BC02']);
        $this->line('RNCP100', 'C02', 2);
        $this->line('RNCP100', 'C03', 4);
        $this->line('RNCP100', 'BC01', 1);
        $this->line('RNCP100', 'BC02', 2);
        $before = $DB->perf_get_reads();
        $read = $this->readings();
        $many = $DB->perf_get_reads() - $before;

        $this->assertCount(5, $read);
        $this->assertSame($few, $many);
    }

    /**
     * The succession reads, and carries nothing (lot 1b): a tag of a situation on the old record is not a tag on the new one,
     * and no situation nor annotation is written on the new record by it.
     */
    public function test_the_succession_carries_no_tag_to_the_new_record(): void {
        global $DB;
        set_config('validationopen', 1, 'tool_vae');
        $own = $this->getDataGenerator()->create_role();
        foreach (['viewown', 'draftown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, \context_system::instance()->id, true);
        }
        role_assign($own, $this->vaeiste->id, \context_user::instance($this->vaeiste->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->generator->create_situation([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'title' => 'Sur l\'ancienne fiche', 'tags' => 'C01,C02',
        ]);
        $this->line('RNCP100', 'C01', 3);
        $before = [
            $DB->count_records('tool_vae_situation'), $DB->count_records('tool_vae_sitetag'), $DB->count_records('tool_vae_note'),
        ];
        $this->setUser($this->vaeiste);
        $situations = new \tool_vae\local\situation_manager();

        // The self-positioning is read on the new record; the tags are not.
        $this->assertSame(['C01'], array_keys($this->readings()));
        $this->assertSame(['C01' => 1, 'C02' => 1], $situations->counts((int) $this->vaeiste->id, 'RNCP100'));
        $this->assertSame([], $situations->counts((int) $this->vaeiste->id, 'RNCP200'));
        $this->assertSame([], $situations->list((int) $this->vaeiste->id, 'RNCP200'));

        // And nothing was written.
        $this->assertSame($before, [
            $DB->count_records('tool_vae_situation'), $DB->count_records('tool_vae_sitetag'), $DB->count_records('tool_vae_note'),
        ]);
        $this->assertSame(0, $DB->count_records('tool_vae_situation', ['fichecode' => 'RNCP200']));
        $this->assertSame(0, $DB->count_records('tool_vae_sitetag', ['fichecode' => 'RNCP200']));
    }
}
