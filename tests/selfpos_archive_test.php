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
use tool_vae\local\dossier_importer;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\positioning_rules;
use tool_vae\local\purge;
use tool_vae\local\schema_validator;
use tool_vae\local\selfpos_archive;
use tool_vae\local\selfpos_manager;

/**
 * The self-positioning of the vaeiste in the archive of the dossier, and back (lot 1a).
 *
 * WHAT IS ASSERTED. That an archive holds autopositionnement.json only when a line was written, listed in the manifest with
 * its schema, valid by it, saying in its first lines that it is not a validation; that every line is there, the one that
 * stands marked; that the import brings the lines back once, line by line, dated as written and saying when they came,
 * leaving a line already here or on a record this site does not hold, and refusing whole a file that is forged, too long,
 * or out of order, writing nothing.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(selfpos_archive::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(dossier_importer::class)]
final class selfpos_archive_test extends \advanced_testcase {
    /** @var \stdClass The vaeiste. */
    private $vaeiste;

    /** @var \tool_vae_generator */
    private $generator;

    /**
     * RNCP100 (BC01 holding C01, C02; BC02 holding C03) and RNCP400 (four levels); a vaeiste who declared on RNCP100.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $datagenerator = $this->getDataGenerator();
        $this->generator = $datagenerator->get_plugin_generator('tool_vae');
        $this->generator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        $this->generator->create_competency(['fichecode' => 'RNCP100', 'ficherang' => 'BC02', 'shortname' => 'Bloc 2']);
        foreach ([['C01', 'BC01'], ['C02', 'BC01'], ['C03', 'BC02']] as [$rank, $bloc]) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP100', 'ficherang' => $rank, 'shortname' => 'Competence ' . $rank, 'bloc' => $bloc,
            ]);
        }
        $this->generator->create_source(['fichecode' => 'RNCP400', 'ficherang' => 'D1', 'shortname' => 'Domaine 1']);
        foreach ([['C11', 'D1'], ['H111', 'C11'], ['H112', 'C11'], ['C12', 'D1'], ['D2', '']] as [$rank, $parent]) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP400', 'ficherang' => $rank, 'shortname' => 'Noeud ' . $rank, 'parent' => $parent,
            ]);
        }

        $this->vaeiste = $datagenerator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $role = $datagenerator->create_role();
        foreach (['declare', 'export', 'viewown', 'positionself'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $role, \context_system::instance()->id, true);
        }
        role_assign($role, $this->vaeiste->id, \context_user::instance($this->vaeiste->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01',
            'situation' => 'Une situation vecue en etablissement.',
        ]);
        $this->setAdminUser();
    }

    /**
     * Five lines through the manager, dated apart: the record of RNCP100, C01 twice (the second stands), H111 of RNCP400 given
     * then withdrawn.
     */
    private function write_lines(): void {
        global $DB;
        $lines = [
            ['RNCP100', '', 1, 'Je commence a lire.', FORMAT_PLAIN, 1762000000],
            ['RNCP100', 'C01', 2, 'Deux situations, avec ma collegue.', FORMAT_PLAIN, 1762100000],
            ['RNCP100', 'C01', 3, '**Seul** depuis trois semaines.', FORMAT_MARKDOWN, 1762200000],
            ['RNCP400', 'H111', 4, '', FORMAT_PLAIN, 1762300000],
            ['RNCP400', 'H111', 0, 'Je me suis trompe d\'element.', FORMAT_PLAIN, 1762400000],
        ];
        foreach ($lines as [$code, $rank, $level, $text, $format, $time]) {
            $row = $this->generator->create_selfpos([
                'userid' => $this->vaeiste->id, 'fichecode' => $code, 'ficherang' => $rank, 'level' => $level,
                'justification' => $text, 'format' => $format,
            ]);
            $DB->set_field(selfpos_manager::TABLE, 'timecreated', $time, ['id' => $row->id]);
        }
    }

    /**
     * The files of the archive the CURRENT user builds for the vaeiste, as the vaeiste's own export is built.
     *
     * @return string[]
     */
    private function build(): array {
        global $DB;

        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->vaeiste->id], 'timecreated ASC, id ASC');

        return (new exporter())->build((int) $this->vaeiste->id, $claims, identity_map::generate_ulid(), time(), false, true, true);
    }

    /**
     * Rewrite autopositionnement.json in an archive, digest included.
     *
     * @param string[] $files
     * @param callable $change Receives the decoded document, returns it changed.
     * @return string[]
     */
    private static function forge(array $files, callable $change): array {
        $content = json_encode(
            $change(json_decode($files[selfpos_archive::PATH], true, 512, JSON_THROW_ON_ERROR)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $files[selfpos_archive::PATH] = $content;
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['fichiers'] as $index => $entry) {
            if ($entry['chemin'] === selfpos_archive::PATH) {
                $manifest['fichiers'][$index]['octets'] = strlen($content);
                $manifest['fichiers'][$index]['sha256'] = hash('sha256', $content);
            }
        }
        $files['manifeste.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $files;
    }

    /**
     * The fingerprint of a line of the file, as the importer recomputes it.
     *
     * @param array $line
     * @return string
     */
    private static function line_hash(array $line): string {
        return positioning_rules::hash([$line['palier'], $line['libelle'], $line['fiche'], $line['rang'], $line['justification']]);
    }

    /**
     * Empty the dossier, import as the administrator, and give the result back.
     *
     * @param string[] $files
     * @return \stdClass
     */
    private function import_after_purge(array $files): \stdClass {
        purge::dossier((int) $this->vaeiste->id);
        $this->setAdminUser();

        return (new dossier_importer())->import_files((int) $this->vaeiste->id, $files);
    }

    /**
     * Import after the dossier is emptied, and expect a refusal that writes nothing.
     *
     * @param string[] $files
     */
    private function assert_refused(array $files): void {
        global $DB;

        try {
            $this->import_after_purge($files);
            $this->fail('The archive was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorarchiveselfpos', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records(selfpos_manager::TABLE));
        $this->assertSame(0, $DB->count_records('tool_vae_evidence'));
    }

    public function test_the_document_is_null_without_a_line_and_carries_every_line_with_the_standing_one_marked(): void {
        $this->assertNull(selfpos_archive::document((int) $this->vaeiste->id));
        $this->write_lines();

        $document = selfpos_archive::document((int) $this->vaeiste->id);

        $this->assertFalse($document['officiel']);
        $this->assertSame(get_string('selfposarchivesentence', 'tool_vae'), $document['avertissement']);
        $this->assertCount(5, $document['lignes']);
        $this->assertSame([1, 2, 3, 4, 0], array_column($document['lignes'], 'palier'));
        $this->assertSame([true, false, true, false, true], array_column($document['lignes'], 'fait_foi'));
        $this->assertSame(['texte', 'texte', 'markdown', 'texte', 'texte'], array_column($document['lignes'], 'format'));
        $this->assertSame('', $document['lignes'][0]['rang']);
        $this->assertArrayNotHasKey('intitule', $document['lignes'][0], 'The record itself has no label of its own.');
        $this->assertSame('Competence C01', $document['lignes'][1]['intitule']);
        $this->assertSame('Noeud H111', $document['lignes'][3]['intitule']);
        $this->assertSame(get_string('selfposlabeldefault3', 'tool_vae'), $document['lignes'][2]['libelle']);
        $this->assertSame(get_string('selfposwithdrawn', 'tool_vae'), $document['lignes'][4]['libelle']);
        $this->assertSame('', $document['lignes'][3]['justification']);
        $this->assertSame(exporter::iso(1762200000), $document['lignes'][2]['ecrit_le']);
        $this->assertSame(self::line_hash($document['lignes'][2]), $document['lignes'][2]['empreinte']);
        $this->assertStringNotContainsString('valid', json_encode($document['lignes']));
    }

    public function test_the_document_is_valid_against_the_schema(): void {
        $this->write_lines();

        $document = json_decode(json_encode(selfpos_archive::document((int) $this->vaeiste->id)), false);

        $this->assertSame([], (new schema_validator())->validate($document, 'autopositionnement.schema.json'));
    }

    public function test_the_archive_of_the_vaeiste_carries_the_file_only_when_a_line_was_written(): void {
        $this->setUser($this->vaeiste);
        $files = $this->build();
        $this->assertArrayNotHasKey(selfpos_archive::PATH, $files);
        $this->assertArrayNotHasKey('schema/autopositionnement.schema.json', $files);
        $this->assertArrayNotHasKey('schema_autopositionnement', json_decode($files['manifeste.json'], true));
        $this->assertStringNotContainsString('autopositionnement.json', $files['LISEZMOI.txt']);

        $this->write_lines();
        $this->setUser($this->vaeiste);
        $files = $this->build();

        $this->assertArrayHasKey(selfpos_archive::PATH, $files);
        $this->assertArrayHasKey('schema/autopositionnement.schema.json', $files);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('0.1.0', $manifest['schema_autopositionnement']);
        $this->assertContains(selfpos_archive::PATH, array_column($manifest['fichiers'], 'chemin'));
        $this->assertStringContainsString('autopositionnement.json', $files['LISEZMOI.txt']);
        $this->assertArrayNotHasKey('positionnement.json', $files, 'The word of the structure stays out of this archive.');
    }

    public function test_the_backup_carries_the_file_too(): void {
        global $USER;
        $this->write_lines();

        $file = \tool_vae\local\backup::save((int) $this->vaeiste->id, (int) $USER->id);

        $names = array_map(fn($entry) => $entry->pathname, $file->list_files(get_file_packer('application/zip')));
        $this->assertContains(selfpos_archive::PATH, $names);
    }

    public function test_a_vaeiste_who_only_positioned_themselves_has_no_export_and_no_backup_yet(): void {
        global $USER;

        // A vaeiste who declared nothing yet, and wrote one line (D1: the trace begins before the first declaration).
        // The export refuses a dossier without a declaration (errorexportempty), so the backup of every dossier leaves
        // them out: KNOWN, and left to the project owner to decide. This essay pins the behaviour so that a change is seen.
        $lonely = $this->getDataGenerator()->create_user(['firstname' => 'Lou', 'lastname' => 'Seule']);
        $role = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:positionself', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, $lonely->id, \context_user::instance($lonely->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->generator->create_selfpos([
            'userid' => $lonely->id, 'fichecode' => 'RNCP400', 'ficherang' => 'D1', 'level' => 1, 'justification' => 'Je lis.',
        ]);
        $this->setAdminUser();

        $this->assertSame(1, \tool_vae\local\backup::save_all((int) $USER->id));
        try {
            \tool_vae\local\backup::save((int) $lonely->id, (int) $USER->id);
            $this->fail('A dossier without a declaration was exported.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorexportempty', $e->errorcode);
        }
    }

    public function test_a_line_whose_identifier_is_not_a_ulid_is_refused(): void {
        $this->write_lines();
        $this->setUser($this->vaeiste);

        // The schema admits a UUID v4 as any identifier of the archive; the table holds 26 characters: refused by name,
        // never by a database error.
        $this->assert_refused(self::forge($this->build(), function (array $document): array {
            $document['lignes'][1]['identifiant'] = '123e4567-e89b-42d3-a456-426614174000';

            return $document;
        }));
    }

    public function test_a_node_that_cannot_hold_every_line_of_a_key_takes_none_of_them(): void {
        global $DB;
        $this->write_lines();
        $this->setUser($this->vaeiste);
        $files = $this->build();
        purge::dossier((int) $this->vaeiste->id);

        // One line short of full on (RNCP100, C01): the archive brings two, the second being the one that stands. Writing
        // the first alone would leave standing a line the archive says was followed by another.
        for ($i = 1; $i < selfpos_manager::MAX_PER_NODE; $i++) {
            $DB->insert_record(selfpos_manager::TABLE, (object) [
                'uuid' => identity_map::generate_ulid(), 'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100',
                'ficherang' => 'C01', 'level' => 1, 'levellabel' => 'Je decouvre', 'justification' => '',
                'justificationformat' => (int) FORMAT_PLAIN, 'linehash' => str_repeat('0', 64), 'importedat' => null,
                'timecreated' => 1700000000 + $i,
            ]);
        }
        $this->setAdminUser();

        $result = (new dossier_importer())->import_files((int) $this->vaeiste->id, $files);

        $this->assertSame(3, $result->autopositionnement, 'The record line of RNCP100 and the two lines of H111.');
        $this->assertSame(2, $result->autopositionnementlaissees);
        $this->assertSame(selfpos_manager::MAX_PER_NODE - 1, $DB->count_records(selfpos_manager::TABLE, [
            'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01',
        ]));
    }

    public function test_a_round_trip_restores_every_line_once(): void {
        global $DB;
        $this->write_lines();
        $this->setUser($this->vaeiste);
        $files = $this->build();
        $before = $DB->get_records(selfpos_manager::TABLE, [], 'timecreated ASC, id ASC');

        $result = $this->import_after_purge($files);

        $this->assertSame(5, $result->autopositionnement);
        $this->assertSame(0, $result->autopositionnementlaissees);
        $after = $DB->get_records(selfpos_manager::TABLE, [], 'timecreated ASC, id ASC');
        $this->assertCount(5, $after);
        $fields = [
            'uuid', 'fichecode', 'ficherang', 'level', 'levellabel', 'justification', 'justificationformat', 'linehash',
            'timecreated',
        ];
        foreach ($fields as $field) {
            $this->assertSame(array_column($before, $field), array_column($after, $field), $field);
        }
        foreach ($after as $row) {
            $this->assertNotNull($row->importedat, 'A restored line says when it came.');
        }
        $this->setUser($this->vaeiste);
        $current = (new selfpos_manager())->current((int) $this->vaeiste->id, 'RNCP100');
        $this->assertSame(3, (int) $current['C01']->level, 'The line that stands is the one that stood.');

        // A second restoration of the same lines adds nothing.
        $document = json_decode($files[selfpos_archive::PATH], false, 512, JSON_THROW_ON_ERROR);
        $lines = array_map(fn($entry) => (object) [
            'uuid' => $entry->identifiant, 'fichecode' => $entry->fiche, 'ficherang' => $entry->rang, 'level' => $entry->palier,
            'levellabel' => $entry->libelle, 'justification' => $entry->justification,
            'format' => $entry->format === 'markdown' ? 4 : 2, 'hash' => $entry->empreinte,
            'writtenat' => strtotime($entry->ecrit_le),
        ], $document->lignes);
        $this->assertSame([0, 5], selfpos_archive::restore((int) $this->vaeiste->id, $lines, time()));
        $this->assertSame(5, $DB->count_records(selfpos_manager::TABLE));
    }

    public function test_a_document_of_more_than_twenty_thousand_lines_is_refused_whole(): void {
        $this->write_lines();
        $this->setUser($this->vaeiste);
        $files = self::forge($this->build(), function (array $document): array {
            $document['lignes'] = array_fill(0, selfpos_archive::MAX_LINES + 1, $document['lignes'][0]);

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_a_line_of_an_unknown_record_is_left_not_written(): void {
        global $DB;
        $this->write_lines();
        $this->setUser($this->vaeiste);
        $files = self::forge($this->build(), function (array $document): array {
            $document['lignes'][0]['fiche'] = 'RNCP999';
            $document['lignes'][0]['empreinte'] = self::line_hash($document['lignes'][0]);

            return $document;
        });

        $result = $this->import_after_purge($files);

        $this->assertSame(4, $result->autopositionnement);
        $this->assertSame(1, $result->autopositionnementlaissees);
        $this->assertSame(0, $DB->count_records(selfpos_manager::TABLE, ['fichecode' => 'RNCP999']));
    }

    public function test_a_line_on_a_node_this_site_cannot_name_is_left(): void {
        global $DB;
        $this->write_lines();
        $this->setUser($this->vaeiste);
        $files = self::forge($this->build(), function (array $document): array {
            $document['lignes'][1]['rang'] = 'C99';
            $document['lignes'][1]['empreinte'] = self::line_hash($document['lignes'][1]);
            $document['lignes'][2]['rang'] = 'C99';
            $document['lignes'][2]['empreinte'] = self::line_hash($document['lignes'][2]);

            return $document;
        });

        $result = $this->import_after_purge($files);

        $this->assertSame(3, $result->autopositionnement);
        $this->assertSame(2, $result->autopositionnementlaissees);
        $this->assertSame(0, $DB->count_records(selfpos_manager::TABLE, ['ficherang' => 'C99']));
    }

    public function test_a_forged_fingerprint_is_refused(): void {
        $this->write_lines();
        $this->setUser($this->vaeiste);

        $this->assert_refused(self::forge($this->build(), function (array $document): array {
            $document['lignes'][1]['justification'] = 'Un autre texte, sous la meme empreinte.';

            return $document;
        }));
    }

    public function test_a_level_off_the_scale_a_long_justification_and_an_html_format_are_refused_by_the_schema(): void {
        $this->write_lines();
        $this->setUser($this->vaeiste);
        $files = $this->build();

        foreach ([['palier', 5], ['justification', str_repeat('a', 10001)], ['format', 'html']] as [$property, $value]) {
            $this->assert_refused(self::forge($files, function (array $document) use ($property, $value): array {
                $document['lignes'][1][$property] = $value;
                $document['lignes'][1]['empreinte'] = self::line_hash($document['lignes'][1]);

                return $document;
            }));
        }
    }

    public function test_a_withdrawal_with_nothing_before_it_and_a_wrong_standing_mark_are_refused(): void {
        $this->write_lines();
        $this->setUser($this->vaeiste);
        $files = $this->build();

        $this->assert_refused(self::forge($files, function (array $document): array {
            unset($document['lignes'][3]);
            $document['lignes'] = array_values($document['lignes']);

            return $document;
        }));
        $this->assert_refused(self::forge($files, function (array $document): array {
            $document['lignes'][1]['fait_foi'] = true;

            return $document;
        }));
    }

    public function test_a_duplicate_identifier_in_the_file_is_refused(): void {
        $this->write_lines();
        $this->setUser($this->vaeiste);

        $this->assert_refused(self::forge($this->build(), function (array $document): array {
            $document['lignes'][2]['identifiant'] = $document['lignes'][1]['identifiant'];

            return $document;
        }));
    }

    public function test_the_document_is_read_with_the_competencies_switched_off(): void {
        $this->write_lines();
        set_config('enabled', 0, competency_gateway::SUBSYSTEM);

        $document = selfpos_archive::document((int) $this->vaeiste->id);

        $this->assertCount(5, $document['lignes']);
        $this->assertArrayNotHasKey('intitule', $document['lignes'][1], 'No label without the core; the file stays whole.');
    }
}
