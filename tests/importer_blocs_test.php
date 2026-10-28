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
use tool_vae\local\importer;

/**
 * The blocs of a record, kept as a tree by the import (T-33, lot 1).
 *
 * WHAT IS ASSERTED. That a file with a `bloc` column gives a tree of two levels in the core, whatever the order of
 * its lines; that a file without the column changes nothing, before or after a hierarchy exists; that a re-import
 * puts a competency under another bloc without touching what was declared against it; that a hierarchy can be
 * added to a record imported flat; and that a file naming a bloc it does not hold, a bloc under a bloc, or a line
 * as its own bloc is refused whole, with its lines named.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\importer::class)]
final class importer_blocs_test extends \advanced_testcase {
    /**
     * Every test starts from a site holding nothing.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The provenance an administrator types on the import screen.
     *
     * @return \stdClass
     */
    private function meta(): \stdClass {
        return (object) [
            'fichecode' => 'RS00000',
            'fichetype' => 'rs',
            'name' => 'Certification inventée pour les tests',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'Personne — jeu d\'essai',
        ];
    }

    /**
     * Rows as the CSV reader hands them over.
     *
     * @param array[] $lines Each [rank, label] for a file without the column, or [rank, label, bloc].
     * @return array
     */
    private function rows(array $lines): array {
        $out = [];
        foreach ($lines as $line) {
            $row = (object) ['rang' => $line[0], 'intitule' => $line[1], 'description' => ''];
            if (array_key_exists(2, $line)) {
                $row->bloc = $line[2];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * The competencies of a record, keyed by idnumber.
     *
     * @param int $frameworkid
     * @return \stdClass[]
     */
    private function by_idnumber(int $frameworkid): array {
        $out = [];
        foreach ((new competency_gateway())->list_framework_competencies($frameworkid) as $competency) {
            $out[$competency->idnumber] = $competency;
        }

        return $out;
    }

    /**
     * A file with two blocs and three competencies.
     *
     * @return array
     */
    private function tree(): array {
        return $this->rows([
            ['BC01', 'Accompagner la personne', ''],
            ['C01', 'Aider au lever', 'BC01'],
            ['C02', 'Aider au repas', 'BC01'],
            ['BC02', 'Travailler en équipe', ''],
            ['C03', 'Transmettre', 'BC02'],
        ]);
    }

    public function test_a_file_with_blocs_gives_a_tree_of_two_levels(): void {
        $result = (new importer())->import($this->meta(), $this->tree());

        $this->assertSame(5, $result->created);
        $this->assertSame(0, $result->moved);
        $c = $this->by_idnumber($result->frameworkid);
        $this->assertSame(0, $c['RS00000-BC01']->parentid);
        $this->assertSame(0, $c['RS00000-BC02']->parentid);
        $this->assertSame($c['RS00000-BC01']->id, $c['RS00000-C01']->parentid);
        $this->assertSame($c['RS00000-BC01']->id, $c['RS00000-C02']->parentid);
        $this->assertSame($c['RS00000-BC02']->id, $c['RS00000-C03']->parentid);
    }

    public function test_a_bloc_listed_after_its_competencies_is_created_first(): void {
        $result = (new importer())->import($this->meta(), $this->rows([
            ['C01', 'Aider au lever', 'BC01'],
            ['BC01', 'Accompagner la personne', ''],
        ]));

        $c = $this->by_idnumber($result->frameworkid);
        $this->assertSame($c['RS00000-BC01']->id, $c['RS00000-C01']->parentid);
    }

    public function test_the_bloc_is_read_whatever_its_case(): void {
        $result = (new importer())->import($this->meta(), $this->rows([
            ['BC01', 'Accompagner la personne', ''],
            ['C01', 'Aider au lever', ' bc01 '],
        ]));

        $c = $this->by_idnumber($result->frameworkid);
        $this->assertSame($c['RS00000-BC01']->id, $c['RS00000-C01']->parentid);
    }

    public function test_a_file_without_the_column_stays_flat(): void {
        $result = (new importer())->import($this->meta(), $this->rows([
            ['BC01', 'Accompagner la personne'],
            ['BC02', 'Travailler en équipe'],
        ]));

        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->moved);
        foreach ($this->by_idnumber($result->frameworkid) as $competency) {
            $this->assertSame(0, $competency->parentid);
        }
    }

    public function test_the_same_file_twice_changes_nothing(): void {
        $importer = new importer();
        $first = $importer->import($this->meta(), $this->tree());
        $before = $this->by_idnumber($first->frameworkid);

        $second = $importer->import($this->meta(), $this->tree());

        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $this->assertSame(0, $second->moved);
        $this->assertEquals($before, $this->by_idnumber($second->frameworkid));
    }

    public function test_a_re_import_puts_a_competency_under_another_bloc_and_touches_nothing_declared_on_it(): void {
        global $DB;
        $importer = new importer();
        $first = $importer->import($this->meta(), $this->tree());
        $before = $this->by_idnumber($first->frameworkid);
        $candidate = $this->getDataGenerator()->create_user();
        $now = time();
        $claim = (object) [
            'uuid' => local\identity_map::generate_ulid(),
            'userevidenceid' => 1,
            'candidateid' => (int) $candidate->id,
            'periodstart' => $now - 1000,
            'periodend' => $now,
            'settingtype' => 'ehpad',
            'situationformat' => FORMAT_PLAIN,
            'situation' => 'Une situation.',
            'fichecode' => 'RS00000',
            'ficherang' => 'C01',
            'ficheupdated' => $now,
            'competencyid' => $before['RS00000-C01']->id,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int) $candidate->id,
        ];
        $claim->id = $DB->insert_record('tool_vae_evidence', $claim);
        $stored = $DB->get_record('tool_vae_evidence', ['id' => $claim->id], '*', MUST_EXIST);

        $rows = $this->tree();
        $rows[1]->bloc = 'BC02';
        $second = $importer->import($this->meta(), $rows);

        $this->assertSame(1, $second->moved);
        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $after = $this->by_idnumber($second->frameworkid);
        $this->assertSame($before['RS00000-C01']->id, $after['RS00000-C01']->id, 'The competency is the same one.');
        $this->assertSame($after['RS00000-BC02']->id, $after['RS00000-C01']->parentid);
        $this->assertSame($after['RS00000-BC01']->id, $after['RS00000-C02']->parentid, 'The others stay.');
        $this->assertEquals($stored, $DB->get_record('tool_vae_evidence', ['id' => $claim->id]));
    }

    public function test_a_hierarchy_is_added_to_a_record_imported_flat(): void {
        $importer = new importer();
        $flat = $importer->import($this->meta(), $this->rows([
            ['BC01', 'Accompagner la personne'],
            ['C01', 'Aider au lever'],
        ]));
        $ids = $this->by_idnumber($flat->frameworkid);

        $second = $importer->import($this->meta(), $this->rows([
            ['BC01', 'Accompagner la personne', ''],
            ['C01', 'Aider au lever', 'BC01'],
        ]));

        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->moved);
        $after = $this->by_idnumber($second->frameworkid);
        $this->assertSame($ids['RS00000-C01']->id, $after['RS00000-C01']->id);
        $this->assertSame($after['RS00000-BC01']->id, $after['RS00000-C01']->parentid);
    }

    public function test_a_file_without_the_column_leaves_an_existing_hierarchy_alone(): void {
        $importer = new importer();
        $first = $importer->import($this->meta(), $this->tree());

        $second = $importer->import($this->meta(), $this->rows([
            ['BC01', 'Accompagner la personne'],
            ['C01', 'Aider au lever'],
            ['C02', 'Aider au repas'],
            ['BC02', 'Travailler en équipe'],
            ['C03', 'Transmettre'],
        ]));

        $this->assertSame(0, $second->moved);
        $c = $this->by_idnumber($second->frameworkid);
        $this->assertSame($c['RS00000-BC01']->id, $c['RS00000-C01']->parentid);
        $this->assertSame($first->frameworkid, $second->frameworkid);
    }

    public function test_an_empty_cell_puts_a_competency_back_at_the_top(): void {
        $importer = new importer();
        $first = $importer->import($this->meta(), $this->tree());

        $rows = $this->tree();
        $rows[1]->bloc = '';
        $second = $importer->import($this->meta(), $rows);

        $this->assertSame(1, $second->moved);
        $this->assertSame(0, $this->by_idnumber($second->frameworkid)['RS00000-C01']->parentid);
        $this->assertSame($first->frameworkid, $second->frameworkid);
    }

    public function test_a_bloc_that_is_not_a_line_of_the_file_refuses_it_whole(): void {
        $this->assert_refused_whole($this->rows([
            ['BC01', 'Accompagner la personne', ''],
            ['C01', 'Aider au lever', 'BC09'],
        ]), 'errorimportblocunknown', 2, 'BC09');
    }

    public function test_a_bloc_under_a_bloc_refuses_it_whole(): void {
        $this->assert_refused_whole($this->rows([
            ['BC01', 'Accompagner la personne', 'BC02'],
            ['BC02', 'Travailler en équipe', ''],
            ['C01', 'Aider au lever', 'BC01'],
        ]), 'errorimportblocdepth', 3, 'BC01');
    }

    public function test_a_line_that_is_its_own_bloc_refuses_it_whole(): void {
        $this->assert_refused_whole($this->rows([
            ['BC01', 'Accompagner la personne', 'BC01'],
        ]), 'errorimportblocself', 1, 'BC01');
    }

    public function test_a_bloc_that_is_not_a_rank_refuses_it_whole(): void {
        $this->assert_refused_whole($this->rows([
            ['BC01', 'Accompagner la personne', ''],
            ['C01', 'Aider au lever', 'bloc un'],
        ]), 'errorimportbadrank', 2, 'BLOC UN');
    }

    public function test_a_bloc_that_still_has_competencies_cannot_be_put_under_another_bloc(): void {
        global $DB;
        $importer = new importer();
        $first = $importer->import($this->meta(), $this->tree());
        $before = $this->by_idnumber($first->frameworkid);

        // BC01 would become a competency of BC02 and carry C01 and C02 down to a third level.
        $rows = $this->rows([
            ['BC02', 'Travailler en équipe', ''],
            ['BC01', 'Accompagner la personne', 'BC02'],
            ['C03', 'Transmettre', 'BC02'],
        ]);
        try {
            $importer->import($this->meta(), $rows);
            $this->fail('The file was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportrows', $e->errorcode);
            $expected = [];
            foreach (['en', 'fr'] as $lang) {
                $expected[] = get_string_manager()->get_string('errorimportblocdemote', 'tool_vae', (object) [
                    'line' => 2,
                    'rank' => 'BC01',
                ], $lang);
            }
            $this->assertContains($e->a, $expected);
        }

        $this->assertEquals($before, $this->by_idnumber($first->frameworkid), 'Nothing moved.');
        $this->assertSame(1, $DB->count_records('tool_vae_source'));
    }

    public function test_a_bloc_that_leaves_the_record_stays_with_its_competencies_and_is_marked_gone(): void {
        global $DB;
        $importer = new importer();
        $first = $importer->import($this->meta(), $this->tree());

        $second = $importer->import($this->meta(), $this->rows([
            ['BC02', 'Travailler en équipe', ''],
            ['C03', 'Transmettre', 'BC02'],
        ]));

        $c = $this->by_idnumber($second->frameworkid);
        $this->assertCount(5, $c, 'Nothing is deleted.');
        $this->assertSame($c['RS00000-BC01']->id, $c['RS00000-C01']->parentid, 'The tree stays.');
        $this->assertSame(3, $second->gone);
        $this->assertSame(3, $DB->count_records('tool_vae_obsolete', ['kind' => importer::GONE]));
        $this->assertSame($first->frameworkid, $second->frameworkid);
    }

    /**
     * Import and expect a refusal that writes nothing.
     *
     * @param array $rows
     * @param string $stringkey The message the refusal carries.
     * @param int $line The line it names.
     * @param string $rank The rank it names.
     */
    private function assert_refused_whole(array $rows, string $stringkey, int $line, string $rank): void {
        global $DB;

        try {
            (new importer())->import($this->meta(), $rows);
            $this->fail('The file was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportrows', $e->errorcode);
            $expected = [];
            foreach (['en', 'fr'] as $lang) {
                $expected[] = get_string_manager()->get_string($stringkey, 'tool_vae', (object) [
                    'line' => $line,
                    'rank' => $rank,
                ], $lang);
            }
            $this->assertContains($e->a, $expected, 'Not the refusal that was expected.');
        }

        $this->assertSame(0, $DB->count_records('tool_vae_source'));
        $this->assertSame(0, $DB->count_records('competency'));
    }

    public function test_the_csv_reader_hands_over_the_bloc_column_only_when_the_file_has_it(): void {
        $with = importer::rows_from_csv("rang,intitule,description,bloc\nBC01,Un bloc,,\nC01,Une compétence,,BC01\n");
        $this->assertSame('', $with[0]->bloc);
        $this->assertSame('BC01', $with[1]->bloc);

        $without = importer::rows_from_csv("rang;intitule;description\nBC01;Un bloc;\n");
        $this->assertObjectNotHasProperty('bloc', $without[0]);
    }

    public function test_the_example_file_with_blocs_reads_and_imports_as_a_tree(): void {
        $rows = importer::rows_from_csv(file_get_contents(__DIR__ . '/fixtures/fiche-exemple-blocs.csv'));
        $this->assertCount(5, $rows);

        $result = (new importer())->import($this->meta(), $rows);

        $c = $this->by_idnumber($result->frameworkid);
        $this->assertSame($c['RS00000-BC02']->id, $c['RS00000-C03']->parentid);
        $this->assertSame(0, $c['RS00000-BC01']->parentid);
    }

    public function test_the_moves_are_said_on_the_import_page_in_both_languages(): void {
        $strings = get_string_manager();
        foreach (['en', 'fr'] as $lang) {
            $this->assertStringContainsString('3', $strings->get_string('importmoved', 'tool_vae', 3, $lang));
        }
    }
}
