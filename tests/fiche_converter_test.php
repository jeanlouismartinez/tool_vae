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

use tool_vae\fixtures\export_zip_builder;
use tool_vae\local\competency_gateway;
use tool_vae\local\fiche_converter;
use tool_vae\local\fiche_refusal;
use tool_vae\local\importer;

/**
 * From the export of France compétences to the import file (T-31).
 *
 * THE FIXTURES ARE INVENTED, and carry the columns, the separator, the byte-order mark and the line endings of the
 * real export (read on 19 October 2026): no data of France compétences enters the tree (open point PO-2).
 *
 * WHAT IS ASSERTED. That a label of 100 characters or fewer reaches the import as published (blanks around it
 * trimmed, nothing else); that a longer one is never cut - the line gets the neutral title `Bloc <rank>` and the
 * label whole goes to the description; that a title too long for a framework name is not cut either; that a record
 * with no bloc, an unknown record and a bloc code of another record are refused by name and convert to nothing; that
 * what is written is read by the importer and shown with its published label.
 *
 * THE EXPORT IS READ AS IT IS WRITTEN, ONCE (0.47.0). convert_many() reads each file as a stream and keeps the
 * lines of the numbers asked for; a refusal is a named object with a reason and its parameters, and keeps the
 * English message of the exceptions it replaced; the state and the end of registration travel as published; a
 * short label that the core would refuse (a `<` that strip_tags reads as a tag) goes to the description like a
 * long one.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\fiche_converter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\competency_gateway::class)]
final class fiche_converter_test extends \advanced_testcase {
    /**
     * A fixture file.
     *
     * @param string $name
     * @return string
     */
    private static function fixture(string $name): string {
        return file_get_contents(__DIR__ . '/fixtures/' . $name);
    }

    /**
     * Convert a record of the invented export.
     *
     * @param string $number
     * @return \stdClass
     */
    private static function convert(string $number): \stdClass {
        return fiche_converter::convert(
            self::fixture('export-blocs-exemple.csv'),
            self::fixture('export-standard-exemple.csv'),
            $number
        );
    }

    public function test_the_blocs_are_written_by_rank_with_the_label_as_published(): void {
        $result = self::convert('RNCP99001');

        $this->assertSame(['BC01', 'BC02', 'BC03', 'BC10'], array_column($result->rows, 'rang'));
        $rows = array_column($result->rows, null, 'rang');
        $this->assertSame('Premier bloc inventé, avec un blanc devant', $rows['BC01']['intitule'], 'Trimmed, nothing else.');
        $this->assertSame('', $rows['BC01']['description']);
        $this->assertSame('Évaluer l\'état de la personne et agir avec soin', $rows['BC02']['intitule']);
        $this->assertSame('rncp', $result->meta->fichetype);
        $this->assertSame('Diplôme inventé d\'accompagnement (DIA)', $result->meta->name);
    }

    public function test_a_label_of_more_than_a_hundred_characters_is_never_cut(): void {
        $result = self::convert('RNCP99001');

        $row = array_column($result->rows, null, 'rang')['BC03'];
        $this->assertSame('Bloc BC03', $row['intitule']);
        $this->assertGreaterThan(fiche_converter::LABEL_MAXLENGTH, mb_strlen($row['description']));
        $this->assertStringStartsWith('Un libellé de bloc inventé', $row['description']);
        $this->assertStringEndsWith('publié en entier dans la description', $row['description'], 'Whole.');
        $this->assertSame(['BC03'], $result->shortened);
    }

    public function test_a_title_too_long_for_a_framework_name_is_not_cut_either(): void {
        $result = self::convert('RNCP99002');

        $this->assertSame('RNCP99002', $result->meta->name, 'The code stands as the name.');
        $this->assertStringStartsWith('Titre inventé d\'une certification', $result->meta->title);
        $this->assertGreaterThan(100, mb_strlen($result->meta->title));
    }

    public function test_a_record_of_the_specific_repertoire_is_typed_rs(): void {
        $this->assertSame('rs', self::convert('RS99005')->meta->fichetype);
    }

    public function test_a_record_with_no_bloc_is_refused_by_name(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RNCP99003 has no bloc');
        self::convert('RNCP99003');
    }

    public function test_an_unknown_record_is_refused_by_name(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RNCP00000 is not in the standard file');
        self::convert('rncp00000');
    }

    public function test_a_bloc_code_of_another_record_refuses_the_whole_record(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('RNCP99999BC02');
        self::convert('RNCP99004');
    }

    public function test_a_file_without_the_expected_column_is_refused_by_name(): void {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Bloc_Competences_Libelle is missing');
        $blocs = "\"Numero_Fiche\";\"Bloc_Competences_Code\"\r\n";
        fiche_converter::convert($blocs, self::fixture('export-standard-exemple.csv'), 'RNCP99001');
    }

    public function test_the_csv_written_is_the_one_the_import_reads(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = self::convert('RNCP99001');

        $rows = importer::rows_from_csv(fiche_converter::to_csv($result->rows));
        $this->assertCount(4, $rows);
        $imported = (new importer())->import((object) [
            'fichecode' => $result->meta->fichecode, 'fichetype' => $result->meta->fichetype,
            'name' => $result->meta->name, 'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => strtotime('2026-10-19'), 'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'Personne - jeu d\'essai',
        ], $rows);

        $this->assertSame(4, $imported->created);
        $competencies = array_column(
            (new competency_gateway())->list_framework_competencies($imported->frameworkid),
            null,
            'idnumber'
        );
        // What the core holds, and what a person reads.
        $this->assertSame('Bloc BC03', $competencies['RNCP99001-BC03']->shortname);
        $this->assertStringStartsWith('Un libellé de bloc inventé', $competencies['RNCP99001-BC03']->label);
        $this->assertSame('Dixième bloc inventé', $competencies['RNCP99001-BC10']->label);
        $this->assertSame($competencies['RNCP99001-BC10']->shortname, $competencies['RNCP99001-BC10']->label);

        // Imported again, nothing changes.
        $again = (new importer())->import((object) [
            'fichecode' => $result->meta->fichecode, 'fichetype' => $result->meta->fichetype,
            'name' => $result->meta->name, 'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => strtotime('2026-10-19'), 'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'Personne - jeu d\'essai',
        ], $rows);
        $this->assertSame(0, $again->created);
        $this->assertSame(0, $again->updated);
    }

    public function test_the_label_to_show_is_the_description_only_for_the_neutral_title(): void {
        // The neutral title of the converter, with a description: the description is the published label.
        $this->assertSame(
            'Le libellé publié',
            competency_gateway::label_of('RNCP1-BC03', 'Bloc BC03', ' Le libellé publié ')
        );
        // Anything else shows its name, description or not.
        $this->assertSame('Un nom', competency_gateway::label_of('RNCP1-BC03', 'Un nom', 'Une description'));
        $this->assertSame('Bloc BC04', competency_gateway::label_of('RNCP1-BC03', 'Bloc BC04', 'Une description'));
        $this->assertSame('Bloc BC03', competency_gateway::label_of('RNCP1-BC03', 'Bloc BC03', '  '));
        $this->assertSame('Bloc BC03', competency_gateway::label_of('sans-tiret-final-', 'Bloc BC03', 'Texte'));
    }

    public function test_the_file_of_the_script_is_the_file_of_the_class(): void {
        $csv = fiche_converter::to_csv(self::convert('RNCP99001')->rows);

        $this->assertStringStartsWith("rang,intitule,description\n", $csv);
        $this->assertStringNotContainsString("\r", $csv);
        $this->assertStringContainsString('BC03,"Bloc BC03","Un libellé de bloc inventé', $csv);
    }

    /**
     * The invented records, in the shape of the real export, restricted to some numbers.
     *
     * @param string[] $numbers
     * @return array[]
     */
    private static function records(array $numbers): array {
        require_once(__DIR__ . '/fixtures/export_zip_builder.php');

        return array_values(array_filter(
            export_zip_builder::records(),
            fn($record) => in_array($record['number'], $numbers, true)
        ));
    }

    /**
     * Convert records written by the builder of the invented export.
     *
     * @param array[] $records
     * @param string[] $numbers
     * @param array $options
     * @return array
     */
    private static function convert_many(array $records, array $numbers, array $options = []): array {
        require_once(__DIR__ . '/fixtures/export_zip_builder.php');

        return fiche_converter::convert_many(
            export_zip_builder::blocs($records, $options),
            export_zip_builder::standard($records, $options),
            $numbers
        );
    }

    public function test_a_refusal_carries_its_reason_and_keeps_its_english_message(): void {
        $cases = [
            ['rncp00000', fiche_refusal::NOT_FOUND, 'RNCP00000 is not in the standard file', ['number' => 'RNCP00000']],
            ['RNCP99003', fiche_refusal::NO_BLOC_ACTIVE, 'RNCP99003 has no bloc', ['number' => 'RNCP99003']],
            ['RNCP99004', fiche_refusal::BAD_BLOC, 'RNCP99999BC02', ['number' => 'RNCP99004', 'codes' => 'RNCP99999BC02']],
        ];
        foreach ($cases as [$number, $reason, $message, $params]) {
            try {
                self::convert($number);
                $this->fail("$number must be refused.");
            } catch (fiche_refusal $refusal) {
                $this->assertInstanceOf(\RuntimeException::class, $refusal);
                $this->assertSame($reason, $refusal->reason());
                $this->assertStringContainsString($message, $refusal->getMessage());
                $this->assertSame($params, $refusal->params());
            }
        }

        try {
            fiche_converter::convert(
                "\"Numero_Fiche\";\"Bloc_Competences_Code\"\r\n",
                self::fixture('export-standard-exemple.csv'),
                'RNCP99001'
            );
            $this->fail('A missing column must be refused.');
        } catch (fiche_refusal $refusal) {
            $this->assertSame(fiche_refusal::FORMAT, $refusal->reason());
            $this->assertSame(
                ['file' => fiche_converter::FILE_BLOCS, 'column' => 'Bloc_Competences_Libelle'],
                $refusal->params()
            );
        }

        try {
            fiche_converter::convert('', self::fixture('export-standard-exemple.csv'), 'RNCP99001');
            $this->fail('An empty file must be refused.');
        } catch (fiche_refusal $refusal) {
            $this->assertSame(fiche_refusal::FILE_EMPTY, $refusal->reason());
            $this->assertSame(['file' => fiche_converter::FILE_BLOCS], $refusal->params());
        }
    }

    public function test_convert_many_equals_convert_for_every_number(): void {
        $many = fiche_converter::convert_many(
            self::fixture('export-blocs-exemple.csv'),
            self::fixture('export-standard-exemple.csv'),
            ['RNCP99001', 'RNCP99002', 'RS99005', 'RNCP99003', 'RNCP99004', 'RNCP00000']
        );

        $this->assertSame(['RNCP99001', 'RNCP99002', 'RS99005', 'RNCP99003', 'RNCP99004', 'RNCP00000'], array_keys($many));
        foreach (['RNCP99001', 'RNCP99002', 'RS99005'] as $number) {
            $this->assertEquals(self::convert($number), $many[$number], $number);
        }
        // A refused record does not stop the others.
        $this->assertSame(fiche_refusal::NO_BLOC_ACTIVE, $many['RNCP99003']->reason());
        $this->assertSame(fiche_refusal::BAD_BLOC, $many['RNCP99004']->reason());
        $this->assertSame(fiche_refusal::NOT_FOUND, $many['RNCP00000']->reason());
    }

    public function test_convert_many_keeps_the_order_asked_and_reads_a_number_once(): void {
        $many = fiche_converter::convert_many(
            self::fixture('export-blocs-exemple.csv'),
            self::fixture('export-standard-exemple.csv'),
            ['rncp99002', 'RNCP99001', ' RNCP99001 ']
        );

        $this->assertSame(['RNCP99002', 'RNCP99001'], array_keys($many));
    }

    public function test_the_real_shape_of_the_export_is_read(): void {
        $expected = self::convert('RNCP99001');
        foreach ([[], ['bom' => true], ['eol' => "\n"], ['bom' => true, 'eol' => "\n"]] as $options) {
            $got = self::convert_many(self::records(['RNCP99001', 'RNCP99002']), ['RNCP99001', 'RNCP99002'], $options);

            $this->assertEquals($expected->rows, $got['RNCP99001']->rows, json_encode($options));
            $this->assertSame($expected->meta->title, $got['RNCP99001']->meta->title);
            // A line feed inside a quoted field is part of the field, not the end of a line.
            $this->assertStringContainsString("\n", $got['RNCP99002']->meta->title);
            $this->assertSame('RNCP99002', $got['RNCP99002']->meta->name);
            $this->assertSame([['BC01', 'Bloc de la fiche au titre trop long']], array_map(
                fn($row) => [$row['rang'], $row['intitule']],
                $got['RNCP99002']->rows
            ));
        }
    }

    public function test_the_state_and_the_end_of_registration_are_carried_as_published(): void {
        $this->assertSame('ACTIVE', self::convert('RNCP99001')->meta->active);
        $this->assertSame('31/08/2030', self::convert('RNCP99001')->meta->enddate);

        $got = self::convert_many(self::records(['RNCP99006']), ['RNCP99006']);
        $this->assertSame('INACTIVE', $got['RNCP99006']->meta->active);
        $this->assertSame('31/12/2020', $got['RNCP99006']->meta->enddate);

        // The columns are optional: without them nothing is invented and nothing is refused.
        $standard = "\"Numero_Fiche\";\"Intitule\"\r\n\"RNCP99001\";\"Titre\"\r\n";
        $blocs = "\"Numero_Fiche\";\"Bloc_Competences_Code\";\"Bloc_Competences_Libelle\"\r\n"
            . "\"RNCP99001\";\"RNCP99001BC01\";\"Un bloc\"\r\n";
        $bare = fiche_converter::convert_many($blocs, $standard, ['RNCP99001'])['RNCP99001'];
        $this->assertNull($bare->meta->active);
        $this->assertNull($bare->meta->enddate);
    }

    public function test_a_short_label_with_a_less_than_sign_goes_to_the_description(): void {
        $got = self::convert_many(self::records(['RNCP99006']), ['RNCP99006'])['RNCP99006'];
        $rows = array_column($got->rows, null, 'rang');

        $this->assertSame('Bloc BC01', $rows['BC01']['intitule']);
        $this->assertSame('Installer un mât (<10m) dans une gaine', $rows['BC01']['description'], 'Whole, byte for byte.');
        // A `>` alone is not a tag: the label stays the title.
        $this->assertSame('Hauteur de levée > 1,20 m', $rows['BC02']['intitule']);
        $this->assertSame('', $rows['BC02']['description']);
        $this->assertSame(['BC01'], $got->markup);
        $this->assertSame(['BC01'], $got->shortened);
        $this->assertSame([], $got->long);
    }

    public function test_a_label_over_a_hundred_characters_is_long_not_markup(): void {
        $result = self::convert('RNCP99001');

        $this->assertSame(['BC03'], $result->long);
        $this->assertSame([], $result->markup);
        $this->assertSame(['BC03'], $result->shortened);
    }

    public function test_a_title_with_a_less_than_sign_gives_the_code_as_name(): void {
        $records = [[
            'number' => 'RNCP99101', 'title' => 'Fiche (<5 ans) inventée', 'active' => 'ACTIVE', 'end' => '',
            'blocs' => [['BC01', 'Un bloc']],
        ]];
        $got = self::convert_many($records, ['RNCP99101'])['RNCP99101'];

        $this->assertSame('RNCP99101', $got->meta->name);
        $this->assertSame('Fiche (<5 ans) inventée', $got->meta->title, 'The published title is not touched.');
    }

    public function test_control_characters_refuse_the_record_by_name(): void {
        $records = [];
        foreach (["Libell\u{202E}e", "Libell\0e", "Libell\u{0085}e"] as $position => $label) {
            $records[] = [
                'number' => 'RNCP9920' . $position, 'title' => 'Fiche', 'active' => 'ACTIVE', 'end' => '',
                'blocs' => [['BC01', $label]],
            ];
        }
        $records[] = [
            'number' => 'RNCP99210', 'title' => 'Fiche', 'active' => 'ACTIVE', 'end' => '',
            'blocs' => [['BC01', "Une\ttabulation, un retour\r\nde ligne, tout va bien"]],
        ];
        $records[] = [
            'number' => 'RNCP99211', 'title' => 'Fiche', 'active' => 'ACTIVE', 'end' => '',
            'blocs' => [['BC01', str_repeat('a', 20001)]],
        ];
        $numbers = array_column($records, 'number');

        $got = self::convert_many($records, $numbers);

        foreach (['RNCP99200' => 'U+202E', 'RNCP99201' => 'U+0000', 'RNCP99202' => 'U+0085'] as $number => $code) {
            $this->assertSame(fiche_refusal::CONTROL, $got[$number]->reason(), $number);
            $this->assertSame($code, $got[$number]->params()['code']);
            $this->assertSame($number, $got[$number]->params()['number']);
            $this->assertStringNotContainsString("\u{202E}", $got[$number]->getMessage());
        }
        $this->assertSame(fiche_refusal::TOO_LONG, $got['RNCP99211']->reason());
        $this->assertSame('RNCP99210', $got['RNCP99210']->meta->fichecode, 'A tab, a line feed and a return are text.');
    }

    public function test_non_utf8_content_is_refused_for_that_record(): void {
        $records = [
            ['number' => 'RNCP99301', 'title' => 'Fiche', 'active' => 'ACTIVE', 'end' => '', 'blocs' => [['BC01', "Libell\xE9"]]],
            ['number' => 'RNCP99302', 'title' => 'Fiche', 'active' => 'ACTIVE', 'end' => '', 'blocs' => [['BC01', 'Libellé']]],
        ];
        $got = self::convert_many($records, ['RNCP99301', 'RNCP99302']);

        $this->assertSame(fiche_refusal::NOT_UTF8, $got['RNCP99301']->reason());
        $this->assertSame('RNCP99302', $got['RNCP99302']->meta->fichecode);
    }

    public function test_the_stream_keeps_the_memory_flat(): void {
        $blocs = "\"Numero_Fiche\";\"Bloc_Competences_Code\";\"Bloc_Competences_Libelle\"\r\n";
        $standard = "\"Id_Fiche\";\"Numero_Fiche\";\"Intitule\";\"Date_Fin_Enregistrement\";\"Actif\"\r\n";
        for ($i = 1; $i <= 30000; $i++) {
            $number = 'RNCP' . (100000 + $i);
            $standard .= "\"$i\";\"$number\";\"Titre inventé de la fiche numéro $i, écrit pour remplir un fichier de test\";"
                . "\"31/08/2030\";\"ACTIVE\"\r\n";
            $blocs .= "\"$number\";\"{$number}BC01\";\"Premier bloc inventé de la fiche $i, pour remplir un fichier de test\"\r\n";
            $blocs .= "\"$number\";\"{$number}BC02\";\"Second bloc inventé de la fiche $i, pour remplir un fichier de test\"\r\n";
        }

        $base = memory_get_usage();
        memory_reset_peak_usage();
        $got = fiche_converter::convert_many($blocs, $standard, ['RNCP100001', 'RNCP115000', 'RNCP130000']);
        $growth = memory_get_peak_usage() - $base;

        $this->assertCount(3, $got);
        $this->assertCount(2, $got['RNCP115000']->rows);
        $this->assertLessThan(24 * 1024 * 1024, $growth, 'The files are read as a stream: the peak does not follow their size.');
    }

    public function test_the_label_width_is_the_same_in_the_converter_and_the_importer(): void {
        $this->assertSame(importer::LABEL_MAXLENGTH, fiche_converter::LABEL_MAXLENGTH);
    }

    public function test_the_core_accepts_what_the_converter_writes_for_a_label_with_a_less_than_sign(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $got = self::convert_many(self::records(['RNCP99006']), ['RNCP99006'])['RNCP99006'];
        $meta = (object) [
            'fichecode' => $got->meta->fichecode, 'fichetype' => $got->meta->fichetype, 'name' => $got->meta->name,
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-10-19'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne - jeu d\'essai',
        ];

        $imported = (new importer())->import($meta, array_map(fn($row) => (object) $row, $got->rows));

        $this->assertSame(2, $imported->created);
        $competencies = array_column(
            (new competency_gateway())->list_framework_competencies($imported->frameworkid),
            null,
            'idnumber'
        );
        $this->assertSame('Bloc BC01', $competencies['RNCP99006-BC01']->shortname);
        $this->assertSame('Installer un mât (<10m) dans une gaine', $competencies['RNCP99006-BC01']->label);
        $this->assertSame('Hauteur de levée > 1,20 m', $competencies['RNCP99006-BC02']->shortname);

        // Imported again, nothing changes: the description with its `<` is read back as written.
        $again = (new importer())->import($meta, array_map(fn($row) => (object) $row, $got->rows));
        $this->assertSame(0, $again->created);
        $this->assertSame(0, $again->updated);
    }

    public function test_the_core_refuses_a_raw_less_than_sign_in_a_title(): void {
        global $DB;

        // CHARACTERISATION (green before the change and after it): the line as the real export has it, passed straight to
        // the import, is refused by the core - the reason the converter moves such a label to the description.
        $this->resetAfterTest();
        $this->setAdminUser();

        try {
            (new importer())->import((object) [
                'fichecode' => 'RNCP99006', 'fichetype' => 'rncp', 'name' => 'Fiche inventée',
                'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-10-19'),
                'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne - jeu d\'essai',
            ], [(object) ['rang' => 'BC01', 'intitule' => 'Installer un mât (<10m) dans une gaine', 'description' => '']]);
            $this->fail('The core must refuse a title with an open tag.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error', $DB->get_field('tool_vae_source', 'lastimportstatus', ['fichecode' => 'RNCP99006']));
        }
    }

    public function test_a_refusal_is_a_named_runtime_exception(): void {
        $refusal = new fiche_refusal(fiche_refusal::NO_BLOC, ['number' => 'RNCP99003', 'count' => 2]);

        $this->assertInstanceOf(\RuntimeException::class, $refusal);
        $this->assertSame('nobloc', $refusal->reason());
        $this->assertSame('fcimporterrornobloc', $refusal->lang_key());
        $this->assertSame('Refused: nobloc', $refusal->getMessage());
        $this->assertEquals($refusal->to_array(), fiche_refusal::from_array($refusal->to_array())->to_array());
        $this->assertSame(
            ['reason' => 'nobloc', 'params' => ['number' => 'RNCP99003', 'count' => 2]],
            $refusal->to_array()
        );

        $this->expectException(\InvalidArgumentException::class);
        new fiche_refusal(fiche_refusal::NO_BLOC, ['number' => ['not', 'a', 'string']]);
    }

    /** @var string[] The columns of a standard file written by hand. */
    private const STANDARD_COLUMNS = ['Id_Fiche', 'Numero_Fiche', 'Intitule', 'Date_Fin_Enregistrement', 'Actif'];

    /** @var string[] The columns of a blocs file written by hand. */
    private const BLOCS_COLUMNS = ['Numero_Fiche', 'Bloc_Competences_Code', 'Bloc_Competences_Libelle'];

    /**
     * A file of the export written by hand, every value quoted.
     *
     * @param string[] $header
     * @param array[] $lines
     * @return string
     */
    private static function csv(array $header, array $lines): string {
        $quote = fn(string $value) => '"' . str_replace('"', '""', $value) . '"';
        $out = implode(';', array_map($quote, $header)) . "\r\n";
        foreach ($lines as $line) {
            $out .= implode(';', array_map($quote, $line)) . "\r\n";
        }

        return $out;
    }

    /**
     * A record of the standard file written by hand.
     *
     * @param string $number
     * @param string $title
     * @param string $end
     * @param string $active
     * @return string[]
     */
    private static function standard_line(
        string $number,
        string $title = 'Fiche',
        string $end = '31/08/2030',
        string $active = 'ACTIVE'
    ): array {
        return ['1', $number, $title, $end, $active];
    }

    public function test_a_record_twice_in_the_standard_file_is_refused_not_guessed(): void {
        $standard = self::csv(self::STANDARD_COLUMNS, [
            self::standard_line('RNCP99401', 'Première fiche'),
            self::standard_line('RNCP99401', 'Seconde fiche'),
            self::standard_line('RNCP99402'),
        ]);
        $blocs = self::csv(self::BLOCS_COLUMNS, [
            ['RNCP99401', 'RNCP99401BC01', 'Un bloc'],
            ['RNCP99402', 'RNCP99402BC01', 'Un autre bloc'],
        ]);

        $got = fiche_converter::convert_many($blocs, $standard, ['RNCP99401', 'RNCP99402']);

        $this->assertSame(fiche_refusal::DUPLICATE, $got['RNCP99401']->reason());
        $this->assertSame('RNCP99402', $got['RNCP99402']->meta->fichecode, 'The others go on.');
    }

    public function test_a_bloc_rank_twice_is_refused_not_overwritten(): void {
        $standard = self::csv(self::STANDARD_COLUMNS, [self::standard_line('RNCP99403')]);
        $blocs = self::csv(self::BLOCS_COLUMNS, [
            ['RNCP99403', 'RNCP99403BC01', 'Le premier libellé'],
            ['RNCP99403', 'RNCP99403BC01', 'Un autre libellé, pour le même rang'],
        ]);

        $got = fiche_converter::convert_many($blocs, $standard, ['RNCP99403'])['RNCP99403'];

        $this->assertSame(fiche_refusal::DUPLICATE, $got->reason());
        $this->assertSame('RNCP99403BC01', $got->params()['codes']);
    }

    public function test_a_record_with_more_blocs_than_any_has_is_refused_and_the_others_go_on(): void {
        $standard = self::csv(self::STANDARD_COLUMNS, [
            self::standard_line('RNCP99411'), self::standard_line('RNCP99412'), self::standard_line('RNCP99413'),
        ]);
        $lines = [['RNCP99413', 'RNCP99413BC01', 'Le seul bloc']];
        for ($i = 1; $i <= fiche_converter::MAX_BLOCS_PER_RECORD + 1; $i++) {
            $lines[] = ['RNCP99411', sprintf('RNCP99411BC%03d', $i), "Bloc $i"];
            if ($i <= fiche_converter::MAX_BLOCS_PER_RECORD) {
                $lines[] = ['RNCP99412', sprintf('RNCP99412BC%03d', $i), "Bloc $i"];
            }
        }

        $got = fiche_converter::convert_many(
            self::csv(self::BLOCS_COLUMNS, $lines),
            $standard,
            ['RNCP99411', 'RNCP99412', 'RNCP99413']
        );

        $this->assertSame(fiche_refusal::TOO_MANY_BLOCS, $got['RNCP99411']->reason());
        $this->assertSame(fiche_converter::MAX_BLOCS_PER_RECORD, $got['RNCP99411']->params()['max']);
        $this->assertCount(fiche_converter::MAX_BLOCS_PER_RECORD, $got['RNCP99412']->rows, 'The cap itself is allowed.');
        $this->assertCount(1, $got['RNCP99413']->rows);
    }

    public function test_a_header_that_is_not_one_is_refused(): void {
        $standard = self::csv(self::STANDARD_COLUMNS, [self::standard_line('RNCP99421')]);
        $headers = [
            'a first line that never ends' => str_repeat('x;', fiche_converter::MAX_HEADER_BYTES),
            // Few columns, the right ones first, and a last one longer than any header: cut at the cap it would pass as one.
            'a first line over the cap, with the right columns' => '"' . implode('";"', self::BLOCS_COLUMNS) . '";"'
                . str_repeat('x', fiche_converter::MAX_HEADER_BYTES + 100) . "\"\r\n",
            'more columns than any export has' => implode(';', array_merge(
                self::BLOCS_COLUMNS,
                array_map(fn($i) => "C$i", range(1, fiche_converter::MAX_COLUMNS))
            )) . "\r\n",
        ];
        foreach ($headers as $label => $blocs) {
            try {
                fiche_converter::convert_many($blocs, $standard, ['RNCP99421']);
                $this->fail("$label must be refused.");
            } catch (fiche_refusal $refusal) {
                $this->assertSame(fiche_refusal::FORMAT, $refusal->reason(), $label);
            }
        }
    }

    public function test_the_state_and_the_end_date_are_texts_like_the_others(): void {
        $standard = self::csv(self::STANDARD_COLUMNS, [
            self::standard_line('RNCP99431', 'Fiche', '31/08/2030', "AC\u{202E}TIVE"),
            self::standard_line('RNCP99432', 'Fiche', str_repeat('9', fiche_converter::MAX_SHORT_CHARS + 1)),
            self::standard_line('RNCP99433'),
        ]);
        $blocs = self::csv(self::BLOCS_COLUMNS, [
            ['RNCP99431', 'RNCP99431BC01', 'Un bloc'], ['RNCP99432', 'RNCP99432BC01', 'Un bloc'],
            ['RNCP99433', 'RNCP99433BC01', 'Un bloc'],
        ]);

        $got = fiche_converter::convert_many($blocs, $standard, ['RNCP99431', 'RNCP99432', 'RNCP99433']);

        $this->assertSame(fiche_refusal::CONTROL, $got['RNCP99431']->reason());
        $this->assertSame(fiche_refusal::TOO_LONG, $got['RNCP99432']->reason());
        $this->assertSame(fiche_converter::MAX_SHORT_CHARS, $got['RNCP99432']->params()['max']);
        $this->assertSame('ACTIVE', $got['RNCP99433']->meta->active);
    }

    public function test_the_last_two_noncharacters_are_control_characters(): void {
        $standard = self::csv(self::STANDARD_COLUMNS, [self::standard_line('RNCP99441'), self::standard_line('RNCP99442')]);
        $blocs = self::csv(self::BLOCS_COLUMNS, [
            ['RNCP99441', 'RNCP99441BC01', "Libell\u{FFFE}e"], ['RNCP99442', 'RNCP99442BC01', "Libell\u{FFFF}e"],
        ]);

        $got = fiche_converter::convert_many($blocs, $standard, ['RNCP99441', 'RNCP99442']);

        $this->assertSame('U+FFFE', $got['RNCP99441']->params()['code']);
        $this->assertSame('U+FFFF', $got['RNCP99442']->params()['code']);
    }

    public function test_the_length_is_measured_without_the_blanks_the_importer_takes_off(): void {
        $nbsp = "\u{00A0}";
        $hundred = str_repeat('é', fiche_converter::LABEL_MAXLENGTH);
        $standard = self::csv(self::STANDARD_COLUMNS, [
            self::standard_line('RNCP99451'), self::standard_line('RNCP99452'), self::standard_line('RNCP99453'),
        ]);
        $blocs = self::csv(self::BLOCS_COLUMNS, [
            // U+FEFF is not a space for the pattern \s: only the list of the importer takes it off.
            ['RNCP99451', 'RNCP99451BC01', $hundred . "\u{FEFF}" . $nbsp],
            ['RNCP99452', 'RNCP99452BC01', $hundred . 'é'],
            ['RNCP99453', 'RNCP99453BC01', $nbsp . $hundred . "\u{202F}"],
        ]);

        $got = fiche_converter::convert_many($blocs, $standard, ['RNCP99451', 'RNCP99452', 'RNCP99453']);

        $this->assertSame($hundred, $got['RNCP99451']->rows[0]['intitule'], 'A hundred characters and a blank: a hundred.');
        $this->assertSame('', $got['RNCP99451']->rows[0]['description']);
        $this->assertSame('Bloc BC01', $got['RNCP99452']->rows[0]['intitule'], 'A hundred and one: long.');
        $this->assertSame($hundred . 'é', $got['RNCP99452']->rows[0]['description']);
        $this->assertSame($hundred, $got['RNCP99453']->rows[0]['intitule'], 'Blanks of both ends, of all three kinds.');
    }

    public function test_a_title_that_cannot_be_a_name_gives_the_code_and_one_that_can_is_kept(): void {
        $hundred = str_repeat('a', 100);
        $titles = [
            'RNCP99461' => "Une ligne\net une autre",
            'RNCP99462' => '',
            'RNCP99463' => $hundred,
            'RNCP99464' => $hundred . 'a',
            'RNCP99465' => 'Fiche <b>en gras</b>',
        ];
        $standard = self::csv(self::STANDARD_COLUMNS, array_map(
            fn($number, $title) => self::standard_line($number, $title),
            array_keys($titles),
            $titles
        ));
        $blocs = self::csv(self::BLOCS_COLUMNS, array_map(
            fn($number) => [$number, $number . 'BC01', 'Un bloc'],
            array_keys($titles)
        ));

        $got = fiche_converter::convert_many($blocs, $standard, array_keys($titles));

        $this->assertSame('RNCP99461', $got['RNCP99461']->meta->name, 'A short title on several lines.');
        $this->assertSame("Une ligne\net une autre", $got['RNCP99461']->meta->title, 'And shown whole.');
        $this->assertSame('RNCP99462', $got['RNCP99462']->meta->name, 'Empty.');
        $this->assertSame($hundred, $got['RNCP99463']->meta->name, 'A hundred characters fit.');
        $this->assertSame('RNCP99464', $got['RNCP99464']->meta->name, 'A hundred and one do not.');
        $this->assertSame('RNCP99465', $got['RNCP99465']->meta->name, 'A tag.');
    }

    public function test_a_bloc_code_that_is_not_one_is_shown_cut_and_never_whole(): void {
        $standard = self::csv(self::STANDARD_COLUMNS, [self::standard_line('RNCP99471')]);
        $blocs = self::csv(self::BLOCS_COLUMNS, [['RNCP99471', 'RNCP99471' . str_repeat('B', 200), 'Un bloc']]);

        $got = fiche_converter::convert_many($blocs, $standard, ['RNCP99471'])['RNCP99471'];

        $this->assertSame(fiche_refusal::BAD_BLOC, $got->reason());
        $this->assertSame(40, mb_strlen($got->params()['codes']));
    }

    public function test_the_script_does_not_say_a_file_was_written_when_it_was_not(): void {
        $script = dirname(__DIR__) . '/scripts/convertir-fiche-rncp.php';
        $directory = make_request_directory();
        file_put_contents("$directory/blocs.csv", self::fixture('export-blocs-exemple.csv'));
        file_put_contents("$directory/standard.csv", self::fixture('export-standard-exemple.csv'));
        $run = function (string $output) use ($script, $directory): array {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg("$directory/blocs.csv")
                . ' ' . escapeshellarg("$directory/standard.csv") . ' RNCP99001 ' . escapeshellarg($output) . ' 2>&1';
            exec($command, $lines, $code);

            return [$code, implode(' | ', $lines)];
        };

        // The witness: a place that exists gets its file, and the script says so.
        [$code, $text] = $run("$directory/sortie.csv");
        $this->assertSame(0, $code, $text);
        $this->assertFileExists("$directory/sortie.csv");
        $this->assertStringContainsString('4 bloc(s) ecrit(s)', $text);

        // A place that does not exist: no file, no claim, an error code.
        [$code, $text] = $run("$directory/nulle/part/sortie.csv");
        $this->assertSame(2, $code, $text);
        $this->assertStringNotContainsString('bloc(s) ecrit(s)', $text);
        $this->assertStringContainsString('Impossible d', $text);
        $this->assertFileDoesNotExist("$directory/nulle/part/sortie.csv");
    }

    public function test_a_record_in_force_with_no_bloc_is_said_apart_from_an_old_one(): void {
        // On 19 October 2026, 236 RNCP records in force and every RS record had no bloc: for them "look for the number in force"
        // is wrong advice, since there is no successor.
        $standard = self::csv(self::STANDARD_COLUMNS, [
            self::standard_line('RNCP99461', 'Fiche en vigueur', '31/12/2029', 'ACTIVE'),
            self::standard_line('RNCP99462', 'Fiche ancienne', '31/12/2020', 'INACTIVE'),
            self::standard_line('RS99463', 'Habilitation en vigueur', '31/12/2029', 'ACTIVE'),
            self::standard_line('RNCP99464', 'Fiche sans état dit', '', ''),
        ]);
        $blocs = self::csv(self::BLOCS_COLUMNS, []);

        $got = fiche_converter::convert_many($blocs, $standard, ['RNCP99461', 'RNCP99462', 'RS99463', 'RNCP99464']);

        $this->assertSame(fiche_refusal::NO_BLOC_ACTIVE, $got['RNCP99461']->reason());
        $this->assertSame(fiche_refusal::NO_BLOC, $got['RNCP99462']->reason());
        $this->assertSame(fiche_refusal::NO_BLOC_ACTIVE, $got['RS99463']->reason());
        $this->assertSame(fiche_refusal::NO_BLOC, $got['RNCP99464']->reason(), 'No state said: the general sentence.');
        $this->assertSame('fcimporterrornoblocactive', $got['RNCP99461']->lang_key());

        // Without the column Actif at all, nothing is invented either.
        $bare = "\"Numero_Fiche\";\"Intitule\"\r\n\"RNCP99465\";\"Titre\"\r\n";
        $none = fiche_converter::convert_many($blocs, $bare, ['RNCP99465'])['RNCP99465'];
        $this->assertSame(fiche_refusal::NO_BLOC, $none->reason());
    }
}
