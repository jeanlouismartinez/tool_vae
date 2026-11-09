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
use tool_vae\local\positioning_rules;
use tool_vae\local\positioning_screen;
use tool_vae\local\positioning_successor;

/**
 * The positionings written on a record that another replaces, read on the one that replaces it (T-90, plan 7).
 *
 * READ, NEVER COPIED. The lines stay on the old record; the new record shows them where the administrator decided that a
 * competency (or a bloc) of the old one has an equivalent in the new one. One hop only, nothing written, nothing carried.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(positioning_successor::class)]
final class positioning_successor_test extends \advanced_testcase {
    /** @var \tool_vae_generator */
    private $generator;

    /** @var \stdClass */
    private $candidate;

    /**
     * RNCP100 (BC01 holding C01 and C02, BC02 holding C03) replaced by RNCP200 (BC01 holding C01, BC02 holding C03 and C04);
     * C01 of the old record is decided to be C01 of the new one, C02 to be C04; C03 is not decided.
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

        $this->candidate = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Import a record: the first rank is the source's first competency, the others are added.
     *
     * @param string $code
     * @param array $ranks [rank, bloc] pairs.
     */
    private function record(string $code, array $ranks): void {
        foreach ($ranks as $index => [$rank, $bloc]) {
            $data = ['fichecode' => $code, 'ficherang' => $rank, 'shortname' => 'Competence ' . $rank . ' ' . $code];
            if ($index === 0) {
                $this->generator->create_source($data);
            } else {
                $this->generator->create_competency($data + ($bloc === '' ? [] : ['bloc' => $bloc]));
            }
        }
    }

    /**
     * Write one line straight into a table.
     *
     * @param string $table tool_vae_eval or tool_vae_certif.
     * @param string $code
     * @param string $rank
     * @param string $status
     * @param int $time
     * @param string|null $until
     * @return \stdClass
     */
    private function line(
        string $table,
        string $code,
        string $rank,
        string $status,
        int $time = 1762000000,
        ?string $until = null
    ): \stdClass {
        global $DB;

        $certif = $table === 'tool_vae_certif';
        $row = (object) [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => $this->candidate->id,
            'fichecode' => $code,
            'ficherang' => $rank,
            'status' => $status,
            'reason' => 'Motif de ' . $code . ' ' . $rank,
            'reasonhash' => hash('sha256', $table . $code . $rank . $status . $time),
            'authorid' => null,
            'authoruuid' => null,
            'importedat' => null,
            'timecreated' => $time,
        ];
        if ($certif) {
            $row->decisiondate = '2026-10-15';
            $row->alertreason = null;
            $row->validuntil = $until;
        }
        $row->id = $DB->insert_record($table, $row);

        return $row;
    }

    /**
     * What the successor reads for the candidate.
     *
     * @param string $code The record read on.
     * @return array eval and certif, each rank => entries.
     */
    private function readings(string $code = 'RNCP200'): array {
        return positioning_successor::readings((int) $this->candidate->id, $code, positioning_rules::layout($code));
    }

    public function test_a_competency_decided_as_the_equivalent_shows_the_line_that_stands_on_the_old_record(): void {
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'inproof', 1762000000);
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient', 1762000100);
        $this->line('tool_vae_eval', 'RNCP100', 'C02', 'toconstruct', 1762000200);

        $read = $this->readings();

        $this->assertSame(['C01', 'C04'], array_keys($read['eval']));
        $this->assertCount(1, $read['eval']['C01']);
        $this->assertSame('RNCP100', $read['eval']['C01'][0]->fromcode);
        $this->assertSame('C01', $read['eval']['C01'][0]->fromrank);
        $this->assertSame('sufficient', $read['eval']['C01'][0]->status, 'The line that stands, not the first.');
        $this->assertSame('toconstruct', $read['eval']['C04'][0]->status, 'The equivalent need not have the same rank.');
        $this->assertSame([], $read['certif']);
    }

    public function test_the_lines_of_another_candidate_are_never_read(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_user();
        $row = $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient');
        $row->id = null;
        unset($row->id);
        $row->uuid = identity_map::generate_ulid();
        $row->candidateid = $other->id;
        $row->reasonhash = hash('sha256', 'other');
        $DB->insert_record('tool_vae_eval', $row);
        $DB->delete_records('tool_vae_eval', ['candidateid' => $this->candidate->id]);

        $this->assertSame([], $this->readings()['eval']);
        $theirs = positioning_successor::readings((int) $other->id, 'RNCP200', positioning_rules::layout('RNCP200'));
        $this->assertSame(['C01'], array_keys($theirs['eval']));
    }

    public function test_a_competency_with_no_decision_shows_nothing(): void {
        $this->line('tool_vae_eval', 'RNCP100', 'C03', 'sufficient');

        $this->assertSame([], $this->readings()['eval']);
    }

    public function test_a_line_withdrawn_on_the_old_record_shows_nothing(): void {
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient', 1762000000);
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'withdrawn', 1762000100);

        $this->assertSame([], $this->readings()['eval']);
    }

    public function test_nothing_is_read_on_a_record_that_does_not_replace_the_one_the_line_is_on(): void {
        global $DB;
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient');
        $this->record('RNCP300', [['BC01', ''], ['C01', 'BC01']]);
        $DB->set_field('tool_vae_source', 'successorcode', 'RNCP300', ['fichecode' => 'RNCP100']);

        $this->assertSame([], $this->readings('RNCP200')['eval'], 'The decision points at RNCP200 but RNCP100 now says RNCP300.');
    }

    public function test_a_decision_whose_target_competency_is_gone_shows_nothing(): void {
        global $DB;
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient');
        $target = $DB->get_field('competency', 'id', ['idnumber' => 'RNCP200-C01'], MUST_EXIST);
        $DB->delete_records('competency', ['id' => $target]);

        $this->assertSame([], $this->readings()['eval']);
    }

    public function test_one_hop_only_a_record_replaced_by_one_that_is_replaced_is_not_followed(): void {
        $this->record('RNCP300', [['BC01', ''], ['C01', 'BC01']]);
        $this->generator->create_replacement(['fichecode' => 'RNCP200', 'successorcode' => 'RNCP300']);
        $this->generator->create_correspondence(['from' => 'RNCP200-C01', 'to' => 'RNCP300-C01']);
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient');
        $this->line('tool_vae_eval', 'RNCP200', 'C01', 'inproof');

        $onthird = $this->readings('RNCP300');

        $this->assertSame(['C01'], array_keys($onthird['eval']));
        $this->assertSame('RNCP200', $onthird['eval']['C01'][0]->fromcode, 'Read from the record before, not from the first.');
        $this->assertCount(1, $onthird['eval']['C01']);
    }

    public function test_several_old_records_give_one_line_each_ordered_by_code(): void {
        $this->record('RNCP050', [['BC01', ''], ['C01', 'BC01']]);
        $this->generator->create_replacement(['fichecode' => 'RNCP050', 'successorcode' => 'RNCP200']);
        $this->generator->create_correspondence(['from' => 'RNCP050-C01', 'to' => 'RNCP200-C01']);
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient');
        $this->line('tool_vae_eval', 'RNCP050', 'C01', 'inproof');

        $read = $this->readings();

        $this->assertSame(['RNCP050', 'RNCP100'], array_map(fn($entry) => $entry->fromcode, $read['eval']['C01']));
    }

    public function test_a_bloc_is_read_on_a_bloc_only_when_the_bloc_itself_was_decided(): void {
        $this->line('tool_vae_certif', 'RNCP100', 'BC01', 'certified');
        $this->assertSame([], $this->readings()['certif'], 'The leaves are decided, the bloc is not: nothing is deduced.');

        $this->generator->create_correspondence(['from' => 'RNCP100-BC01', 'to' => 'RNCP200-BC01']);
        $read = $this->readings();

        $this->assertSame(['BC01'], array_keys($read['certif']));
        $this->assertSame('RNCP100', $read['certif']['BC01'][0]->fromcode);
        $this->assertSame('BC01', $read['certif']['BC01'][0]->fromrank);
        $this->assertSame('certified', $read['certif']['BC01'][0]->status);
        $this->assertSame('2026-10-15', $read['certif']['BC01'][0]->decisiondate);
    }

    public function test_a_bloc_withdrawn_on_the_old_record_shows_nothing(): void {
        $this->generator->create_correspondence(['from' => 'RNCP100-BC01', 'to' => 'RNCP200-BC01']);
        $this->line('tool_vae_certif', 'RNCP100', 'BC01', 'certified', 1762000000);
        $this->line('tool_vae_certif', 'RNCP100', 'BC01', 'withdrawn', 1762000100);

        $this->assertSame([], $this->readings()['certif']);
    }

    public function test_a_decision_between_a_competency_and_a_bloc_is_not_read(): void {
        $this->generator->create_correspondence(['from' => 'RNCP100-C03', 'to' => 'RNCP200-BC02']);
        $this->generator->create_correspondence(['from' => 'RNCP100-BC02', 'to' => 'RNCP200-C03']);
        $this->line('tool_vae_eval', 'RNCP100', 'C03', 'sufficient');
        $this->line('tool_vae_certif', 'RNCP100', 'BC02', 'certified');
        // Lines no screen writes (a bloc evaluated, a competency certified) are read nowhere either.
        $this->line('tool_vae_eval', 'RNCP100', 'BC02', 'sufficient');
        $this->line('tool_vae_certif', 'RNCP100', 'C03', 'certified');

        $read = $this->readings();

        $this->assertArrayNotHasKey('BC02', $read['eval']);
        $this->assertArrayNotHasKey('BC02', $read['certif']);
        $this->assertArrayNotHasKey('C03', $read['eval'], 'A bloc is not read on a competency.');
        $this->assertArrayNotHasKey('C03', $read['certif']);
    }

    public function test_the_validity_of_the_old_certification_is_carried_in_the_reading_without_being_told_as_due(): void {
        $this->generator->create_correspondence(['from' => 'RNCP100-BC01', 'to' => 'RNCP200-BC01']);
        $this->line('tool_vae_certif', 'RNCP100', 'BC01', 'certified', 1762000000, '2020-06-30');

        $entry = $this->readings()['certif']['BC01'][0];

        $this->assertSame('2020-06-30', $entry->validuntil);
    }

    public function test_reading_writes_nothing(): void {
        global $DB;
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient');
        $this->generator->create_correspondence(['from' => 'RNCP100-BC01', 'to' => 'RNCP200-BC01']);
        $this->line('tool_vae_certif', 'RNCP100', 'BC01', 'certified');
        $tables = ['tool_vae_eval', 'tool_vae_certif', 'tool_vae_passage', 'tool_vae_source', 'tool_vae_evidence'];
        $before = array_map(fn($table) => $DB->count_records($table), $tables);
        $hashes = $DB->get_fieldset_select('tool_vae_eval', 'reasonhash', '1 = 1');

        $this->readings();

        $this->assertSame($before, array_map(fn($table) => $DB->count_records($table), $tables));
        $this->assertSame($hashes, $DB->get_fieldset_select('tool_vae_eval', 'reasonhash', '1 = 1'));
        $this->assertSame(0, $DB->count_records('tool_vae_eval', ['fichecode' => 'RNCP200']));
    }

    public function test_the_number_of_reads_does_not_grow_with_the_number_of_competencies_decided(): void {
        global $DB;
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient');
        $this->readings();
        $before = $DB->perf_get_reads();
        $this->readings();
        $few = $DB->perf_get_reads() - $before;

        $this->generator->create_correspondence(['from' => 'RNCP100-C03', 'to' => 'RNCP200-C03']);
        $this->generator->create_correspondence(['from' => 'RNCP100-BC01', 'to' => 'RNCP200-BC01']);
        $this->generator->create_correspondence(['from' => 'RNCP100-BC02', 'to' => 'RNCP200-BC02']);
        $this->line('tool_vae_eval', 'RNCP100', 'C02', 'inproof');
        $this->line('tool_vae_eval', 'RNCP100', 'C03', 'sufficient');
        $this->line('tool_vae_certif', 'RNCP100', 'BC01', 'certified');
        $this->line('tool_vae_certif', 'RNCP100', 'BC02', 'certified');
        $before = $DB->perf_get_reads();
        $read = $this->readings();
        $many = $DB->perf_get_reads() - $before;

        $this->assertCount(3, $read['eval']);
        $this->assertCount(2, $read['certif']);
        $this->assertSame($few, $many);
    }

    public function test_a_record_with_no_old_record_reads_nothing(): void {
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient');

        $this->assertSame(['eval' => [], 'certif' => []], $this->readings('RNCP100'));
    }

    public function test_the_section_of_the_new_record_says_where_the_old_one_positioned_with_no_way_to_write_on_it(): void {
        $this->generator->create_declaration([
            'userid' => $this->candidate->id, 'fichecode' => 'RNCP200', 'ficherang' => 'C01',
            'situation' => 'Une situation vecue.',
        ]);
        $this->generator->create_correspondence(['from' => 'RNCP100-BC01', 'to' => 'RNCP200-BC01']);
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient');
        $this->line('tool_vae_certif', 'RNCP100', 'BC01', 'certified', 1762000000, '2020-06-30');

        $html = positioning_screen::section((int) $this->candidate->id, \context_user::instance($this->candidate->id));

        $this->assertStringContainsString(
            s(get_string('positioningonold', 'tool_vae', (object) [
                'code' => 'RNCP100', 'rank' => 'C01', 'status' => get_string('evalstatussufficient', 'tool_vae'),
                'when' => userdate(1762000000, get_string('strftimedate', 'langconfig')),
            ])),
            $html
        );
        $this->assertStringContainsString(
            s(get_string('positioningonold', 'tool_vae', (object) [
                'code' => 'RNCP100', 'rank' => 'BC01', 'status' => get_string('certifstatuscertified', 'tool_vae'),
                'when' => '2026-10-15',
            ])),
            $html
        );
        $this->assertStringNotContainsString(get_string('positioningtorenew', 'tool_vae'), $html, 'Not told as due from here.');
        $this->assertStringNotContainsString('rank=BC01&amp;fichecode=RNCP100', $html);
        $this->assertStringNotContainsString('fichecode=RNCP100', $html, 'No address leads to a line of the old record from here.');
    }

    public function test_a_candidate_who_declared_only_on_the_old_record_gets_no_section_for_the_new_one(): void {
        $this->generator->create_declaration([
            'userid' => $this->candidate->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01',
            'situation' => 'Une situation vecue.',
        ]);
        $this->line('tool_vae_eval', 'RNCP100', 'C01', 'sufficient');

        $html = positioning_screen::section((int) $this->candidate->id, \context_user::instance($this->candidate->id));

        $this->assertStringContainsString('RNCP100', $html, 'The section of the old record is there.');
        $this->assertStringNotContainsString('RNCP200', $html);
        $this->assertStringNotContainsString('earlier record', $html);
    }
}
