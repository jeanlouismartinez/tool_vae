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
 * The check of an import that writes nothing, and the figures it announces (0.47.0).
 *
 * WHY IT EXISTS. The preview of an import of records from an export must refuse exactly what the import would refuse -
 * a framework that is not the plugin's, a renamed one, faulty lines, no competence scale - BEFORE the first write, or a
 * refusal falls in the middle of a batch already written; and it must say what the import will change, because the
 * competencies that leave a record warn the people who declared on them a day later. importer::check() is the part of
 * import() that comes before any write, and it announces, from what the site holds, the figures import() will give.
 *
 * WHAT IS ASSERTED. That check() writes nothing, after a success as after a refusal; that it refuses what import()
 * refuses with the same messages; that it says what the site holds (first time, already imported, framework gone,
 * framework adopted); that its forecast equals what import() then does on nine scenarios; that it leaves the metadata of
 * the caller alone; that import() gives the identifier of the provenance row.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\importer::class)]
final class importer_check_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The provenance the import writes.
     *
     * @param array $overrides
     * @return \stdClass
     */
    private function meta(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'fichecode' => 'RS00000',
            'fichetype' => 'rs',
            'name' => 'Certification inventée pour les tests',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'Personne — jeu d\'essai',
        ]);
    }

    /**
     * Rows as the CSV reader hands them over.
     *
     * @param array $rows rank => label, or rank => [label, description]
     * @return array
     */
    private function rows(array $rows): array {
        $out = [];
        foreach ($rows as $rang => $label) {
            [$intitule, $description] = is_array($label) ? $label : [$label, ''];
            $out[] = (object) ['rang' => $rang, 'intitule' => $intitule, 'description' => $description];
        }

        return $out;
    }

    /**
     * What the site holds that an import could change or add.
     *
     * @return array
     */
    private function holdings(): array {
        global $DB;

        return [
            'sources' => $DB->get_records('tool_vae_source'),
            'frameworks' => $DB->count_records('competency_framework'),
            'competencies' => $DB->get_records('competency', null, 'id', 'id, shortname, description, parentid'),
            'marks' => $DB->count_records('tool_vae_obsolete'),
        ];
    }

    public function test_check_writes_nothing_after_a_success_or_a_refusal(): void {
        $importer = new importer();
        $first = $importer->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));
        $before = $this->holdings();
        $sink = $this->redirectEvents();

        $importer->check($this->meta(), $this->rows(['BC01' => 'Un autre libellé', 'BC02' => 'Un nouveau bloc']));
        $importer->check($this->meta(['fichecode' => 'RNCP00000']), $this->rows(['BC01' => 'Une fiche neuve']));
        try {
            $importer->check($this->meta(), $this->rows(['bad rank' => 'Accompagner']));
            $this->fail('The faulty row must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportrows', $e->errorcode);
        }

        $this->assertEquals($before, $this->holdings(), 'Nothing was written, not even the provenance row.');
        $this->assertSame([], $sink->get_events());
        $this->assertSame($first->frameworkid, (int) reset($before['sources'])->frameworkid);
    }

    public function test_check_refuses_what_import_refuses_with_the_same_messages(): void {
        global $DB;

        $importer = new importer();
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);

        // A framework the plugin did not write.
        $framework = $generator->create_framework(['idnumber' => 'RS00001', 'shortname' => 'Cadre maison']);
        $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'), 'idnumber' => 'C1.1', 'shortname' => 'Compétence maison',
        ]);
        $refusals = [];
        $meta = $this->meta(['fichecode' => 'RS00001']);
        $rows = $this->rows(['BC01' => 'Accompagner la personne']);
        try {
            $importer->check($meta, $rows);
            $this->fail('A foreign framework was accepted by the check.');
        } catch (\moodle_exception $e) {
            $refusals['foreign'] = $e;
        }
        try {
            $importer->import($meta, $rows);
            $this->fail('A foreign framework was accepted by the import.');
        } catch (\moodle_exception $e) {
            $this->assertSame($e->errorcode, $refusals['foreign']->errorcode);
            $this->assertSame($e->getMessage(), $refusals['foreign']->getMessage());
        }

        // A framework whose identifier was changed after the import.
        $first = $importer->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));
        $DB->set_field('competency_framework', 'idnumber', 'RS00000-2025', ['id' => $first->frameworkid]);
        try {
            $importer->check($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));
            $this->fail('A renamed framework was accepted by the check.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportframeworkrenamed', $e->errorcode);
            $this->assertStringContainsString('RS00000-2025', $e->getMessage());
        }
        $DB->set_field('competency_framework', 'idnumber', 'RS00000', ['id' => $first->frameworkid]);

        // Faulty rows: all of them, in one message.
        try {
            $importer->check($this->meta(), $this->rows(['bad rank' => 'Un', 'BC02' => '']));
            $this->fail('Faulty rows were accepted by the check.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportrows', $e->errorcode);
            $this->assertStringContainsString('bad rank', strtolower($e->a));
        }

        // No competence scale to give a first framework.
        $scale = competency_gateway::default_competence_scale();
        $DB->set_field('scale', 'name', 'Autre barème', ['courseid' => 0, 'name' => $scale->name]);
        try {
            $importer->check($this->meta(['fichecode' => 'RS00002']), $this->rows(['BC01' => 'Accompagner la personne']));
            $this->fail('A first record was accepted with no competence scale.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornocompetencescale', $e->errorcode);
        }
    }

    public function test_check_refuses_a_bloc_that_still_has_competencies_being_put_under_another(): void {
        $importer = new importer();
        $tree = function (array $lines): array {
            return array_map(function ($line) {
                return (object) ['rang' => $line[0], 'intitule' => $line[1], 'description' => '', 'bloc' => $line[2]];
            }, $lines);
        };
        $importer->import($this->meta(), $tree([
            ['BC01', 'Accompagner la personne', ''], ['BC02', 'Travailler en équipe', ''], ['C01', 'Aider au lever', 'BC01'],
        ]));

        try {
            $importer->check($this->meta(), $tree([
                ['BC02', 'Travailler en équipe', ''], ['BC01', 'Accompagner la personne', 'BC02'],
            ]));
            $this->fail('The demotion was accepted by the check.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportrows', $e->errorcode);
        }
    }

    public function test_check_says_what_the_site_holds(): void {
        global $DB;

        $importer = new importer();
        $rows = $this->rows(['BC01' => 'Accompagner la personne']);

        // The first time.
        $new = $importer->check($this->meta(), $rows);
        $this->assertNull($new->previous);
        $this->assertNull($new->frameworkid);
        $this->assertFalse($new->adopted);
        $this->assertTrue($new->sourcemoved);

        // Already imported, the framework alive.
        $first = $importer->import($this->meta(), $rows);
        $held = $importer->check($this->meta(), $rows);
        $this->assertSame((int) $first->frameworkid, $held->frameworkid);
        $this->assertSame(strtotime('2026-06-01'), (int) $held->previous->sourceupdated);
        $this->assertFalse($held->sourcemoved);
        $this->assertFalse($held->adopted);

        // The framework deleted in Moodle: the row is still there, the framework is not.
        $DB->delete_records('competency', ['competencyframeworkid' => $first->frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $first->frameworkid]);
        $gone = $importer->check($this->meta(), $rows);
        $this->assertNotNull($gone->previous);
        $this->assertNull($gone->frameworkid);
    }

    public function test_check_says_a_framework_left_by_an_uninstall_will_be_adopted(): void {
        global $DB;

        $importer = new importer();
        $first = $importer->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));
        // The row of the provenance goes, the framework stays: what an uninstall leaves behind.
        $DB->delete_records('tool_vae_source', ['fichecode' => 'RS00000']);

        $adopted = $importer->check($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));

        $this->assertTrue($adopted->adopted);
        $this->assertSame((int) $first->frameworkid, $adopted->frameworkid);
        $this->assertNull($adopted->previous);
    }

    /**
     * The forecast equals what the import then does, on nine scenarios.
     */
    public function test_the_forecast_is_what_the_import_then_does(): void {
        global $DB;

        $one = strtotime('2026-06-01');
        $two = strtotime('2026-07-01');
        $scenarios = [
            'a new record' => function (string $code) use ($one): array {
                return [$this->meta(['fichecode' => $code]), $this->rows(['BC01' => 'Un', 'BC02' => 'Deux'])];
            },
            'the same content again' => function (string $code) use ($one): array {
                $meta = $this->meta(['fichecode' => $code]);
                $rows = $this->rows(['BC01' => 'Un', 'BC02' => 'Deux']);
                (new importer())->import($meta, $rows);

                return [$meta, $rows];
            },
            'a label changed after a move of the date' => function (string $code) use ($one, $two): array {
                (new importer())->import($this->meta(['fichecode' => $code]), $this->rows(['BC01' => 'Un', 'BC02' => 'Deux']));

                return [
                    $this->meta(['fichecode' => $code, 'sourceupdated' => $two]),
                    $this->rows(['BC01' => 'Un autre', 'BC02' => 'Deux']),
                ];
            },
            'a label changed at the same date' => function (string $code) use ($one): array {
                (new importer())->import($this->meta(['fichecode' => $code]), $this->rows(['BC01' => 'Un', 'BC02' => 'Deux']));

                return [$this->meta(['fichecode' => $code]), $this->rows(['BC01' => 'Un autre', 'BC02' => 'Deux'])];
            },
            'a description changed' => function (string $code) use ($one, $two): array {
                (new importer())->import(
                    $this->meta(['fichecode' => $code]),
                    $this->rows(['BC01' => ['Un', 'Avant'], 'BC02' => 'Deux'])
                );

                return [
                    $this->meta(['fichecode' => $code, 'sourceupdated' => $two]),
                    $this->rows(['BC01' => ['Un', 'Après'], 'BC02' => 'Deux']),
                ];
            },
            'a bloc that left the record' => function (string $code) use ($one, $two): array {
                (new importer())->import($this->meta(['fichecode' => $code]), $this->rows(['BC01' => 'Un', 'BC02' => 'Deux']));

                return [$this->meta(['fichecode' => $code, 'sourceupdated' => $two]), $this->rows(['BC01' => 'Un'])];
            },
            'a framework taken over' => function (string $code) use ($one, $DB): array {
                (new importer())->import($this->meta(['fichecode' => $code]), $this->rows(['BC01' => 'Un']));
                $DB->delete_records('tool_vae_source', ['fichecode' => $code]);

                return [$this->meta(['fichecode' => $code]), $this->rows(['BC01' => 'Un', 'BC02' => 'Deux'])];
            },
            'a description saved as html' => function (string $code) use ($DB): array {
                $meta = $this->meta(['fichecode' => $code]);
                $rows = $this->rows(['BC01' => ['Un', 'Avant'], 'BC02' => 'Deux']);
                (new importer())->import($meta, $rows);
                // What the editor of the core does when somebody saves a competency: the text is the same, its format is not.
                $DB->set_field('competency', 'descriptionformat', FORMAT_HTML, ['idnumber' => $code . '-BC01']);

                return [$meta, $rows];
            },
            'a departure already recorded' => function (string $code) use ($two): array {
                (new importer())->import($this->meta(['fichecode' => $code]), $this->rows(['BC01' => 'Un', 'BC02' => 'Deux']));
                $meta = $this->meta(['fichecode' => $code, 'sourceupdated' => $two]);
                (new importer())->import($meta, $this->rows(['BC01' => 'Un']));

                // A third pass over the same file: the bloc is still gone, and is counted again, as the import counts it.
                return [$meta, $this->rows(['BC01' => 'Un'])];
            },
        ];

        $number = 10;
        foreach ($scenarios as $label => $prepare) {
            [$meta, $rows] = $prepare('RS000' . $number++);
            $importer = new importer();

            $check = $importer->check($meta, $rows);
            $result = $importer->import($meta, $rows);

            $this->assertNotNull($check->forecast, $label);
            foreach (['created', 'updated', 'gone', 'diverged'] as $field) {
                $this->assertSame((int) $result->$field, (int) $check->forecast->$field, "$label: $field");
            }
        }
    }

    public function test_the_forecast_is_null_when_a_row_carries_a_bloc(): void {
        $rows = [(object) ['rang' => 'BC01', 'intitule' => 'Un', 'description' => '', 'bloc' => '']];

        $check = (new importer())->check($this->meta(), $rows);

        $this->assertNull($check->forecast);
    }

    public function test_check_leaves_the_metadata_of_the_caller_alone_and_gives_the_code_in_capitals(): void {
        $meta = $this->meta(['fichecode' => 'rs00000']);

        $check = (new importer())->check($meta, $this->rows(['BC01' => 'Un']));

        $this->assertSame('rs00000', $meta->fichecode, 'The caller\'s object is not modified.');
        $this->assertSame('RS00000', $check->meta->fichecode);
        $this->assertSame('BC01', $check->rows[0]->rang);

        $result = (new importer())->import($meta, $this->rows(['BC01' => 'Un']));
        $this->assertSame('rs00000', $meta->fichecode, 'Nor by import().');
        $this->assertGreaterThan(0, $result->frameworkid);
    }

    public function test_import_gives_the_identifier_of_the_provenance_row(): void {
        global $DB;

        $result = (new importer())->import($this->meta(), $this->rows(['BC01' => 'Un']));

        $this->assertSame(
            (int) $DB->get_field('tool_vae_source', 'id', ['fichecode' => 'RS00000']),
            $result->sourceid
        );
    }
}
