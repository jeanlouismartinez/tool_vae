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

use tool_vae\local\dossier_importer;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\pdf_renderer;
use tool_vae\local\positioning_archive;
use tool_vae\local\positioning_rules;
use tool_vae\local\purge;
use tool_vae\local\schema_validator;

/**
 * The positionings of the structure in the archive of the dossier, and back (T-90, lot 3).
 *
 * WHAT IS ASSERTED. That an archive holds positionnement.json only when a line was written, listed in the manifest with its
 * schema, valid by it, saying in its first lines that it is not a validation and that nothing in it is official; that every line
 * is there, the one that stands marked; that an author who is gone has no identifier in it; that the printed copy says so too;
 * and that the import brings the lines back key by key, all or nothing, in the order of the archive, with the author named by
 * ULID and the date of the import - leaving a key this site already holds, a record or a rank it does not, and refusing a file
 * whose fingerprints, order, days or statuses are not what they claim, writing nothing.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\positioning_archive::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\pdf_renderer::class)]
final class positioning_archive_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Wrote the evaluations. */
    private $manager;

    /** @var \stdClass Wrote the certification. */
    private $direction;

    /**
     * RS00000 with no bloc (C01..C03) and RNCP90000 with a bloc BC01 over C01 and C02; a candidate who declared on both.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $import = fn(string $code, string $type, string $csv) => (new importer())->import((object) [
            'fichecode' => $code, 'fichetype' => $type, 'name' => 'Fiche ' . $code,
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv($csv));
        $import('RS00000', 'rs', "rang,intitule,description,bloc\nC01,Une,,\nC02,Deux,,\nC03,Trois,,\n");
        $import('RNCP90000', 'rncp', "rang,intitule,description,bloc\nBC01,Bloc un,,\nC01,Premiere,,BC01\nC02,Seconde,,BC01\n");

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $this->manager = $generator->create_user();
        $this->direction = $generator->create_user();
        $role = $generator->create_role();
        foreach (['declare', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $role, \context_system::instance()->id, true);
        }
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->candidate);
        foreach (['RS00000-C02', 'RNCP90000-C01'] as $idnumber) {
            $competency = $DB->get_field('competency', 'id', ['idnumber' => $idnumber], MUST_EXIST);
            (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
                'competencyid' => (int) $competency, 'periodstart' => 1756684800, 'periodend' => 1782777600,
                'settingtype' => 'ehpad', 'situation' => 'Situation ' . $idnumber, 'situationformat' => FORMAT_PLAIN,
            ]);
        }
        $this->setAdminUser();
    }

    /**
     * Write one line straight into a table, with the fingerprint the managers give it.
     *
     * @param string $table tool_vae_eval or tool_vae_certif.
     * @param string $code
     * @param string $rank
     * @param string $status
     * @param string $reason
     * @param int $time
     * @param \stdClass|null $author
     * @param array $more decisiondate, alertreason and validuntil for a certification.
     * @return \stdClass
     */
    private function line(
        string $table,
        string $code,
        string $rank,
        string $status,
        string $reason,
        int $time,
        ?\stdClass $author,
        array $more = []
    ): \stdClass {
        global $DB;

        $certif = $table === 'tool_vae_certif';
        $date = $more['decisiondate'] ?? '2026-11-04';
        $alert = $more['alertreason'] ?? '';
        $until = $more['validuntil'] ?? '';
        $row = (object) [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => $this->candidate->id,
            'fichecode' => $code,
            'ficherang' => $rank,
            'status' => $status,
            'reason' => $reason,
            'reasonhash' => positioning_rules::hash(
                $certif
                    ? array_merge([$status, $code, $rank, $reason, $date, $alert], $until === '' ? [] : [$until])
                    : [$status, $code, $rank, $reason]
            ),
            'authorid' => $author ? $author->id : null,
            'authoruuid' => null,
            'importedat' => null,
            'timecreated' => $time,
        ];
        if ($certif) {
            $row->decisiondate = $date;
            $row->alertreason = $alert === '' ? null : $alert;
            $row->validuntil = $until === '' ? null : $until;
        }
        $row->id = $DB->insert_record($table, $row);

        return $row;
    }

    /**
     * Three evaluations (one of them corrected) and two certifications.
     */
    private function write_positionings(): void {
        $this->line('tool_vae_eval', 'RNCP90000', 'C01', 'inproof', 'Deux situations vues.', 1762000000, $this->manager);
        $this->line('tool_vae_eval', 'RNCP90000', 'C01', 'sufficient', 'Trois situations vues.', 1762100000, $this->manager);
        $this->line('tool_vae_eval', 'RNCP90000', 'C02', 'toconstruct', 'Rien encore.', 1762100100, $this->manager);
        $this->line(
            'tool_vae_certif',
            'RNCP90000',
            'BC01',
            'certified',
            'La structure se positionne.',
            1762200000,
            $this->direction,
            [
                'decisiondate' => '2026-11-03', 'alertreason' => 'C02 sera vue la semaine prochaine.',
                'validuntil' => '2027-11-03',
            ]
        );
        $this->line('tool_vae_certif', 'RS00000', '', 'certified', 'La fiche entiere.', 1762300000, $this->direction);
    }

    /**
     * The files of the archive the CURRENT user builds for the candidate.
     *
     * With the positionings by default: the archive that holds them is the backup and the archive of the structure, and the
     * tests of the file are tests of that one. The archives of the vaeiste ask for none (see the tests at the end of the
     * first block).
     *
     * @param bool $withpositioning Whether the archive carries the positionings.
     * @return string[]
     */
    private function build(bool $withpositioning = true): array {
        global $DB;

        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');

        return (new exporter())->build(
            (int) $this->candidate->id,
            $claims,
            identity_map::generate_ulid(),
            time(),
            false,
            true,
            null,
            null,
            $withpositioning
        );
    }

    /**
     * Rewrite positionnement.json in an archive, digest included.
     *
     * @param string[] $files
     * @param callable $change Receives the decoded document, returns it changed.
     * @return string[]
     */
    private static function forge(array $files, callable $change): array {
        $content = json_encode(
            $change(json_decode($files[positioning_archive::PATH], true, 512, JSON_THROW_ON_ERROR)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $files[positioning_archive::PATH] = $content;
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['fichiers'] as $index => $entry) {
            if ($entry['chemin'] === positioning_archive::PATH) {
                $manifest['fichiers'][$index]['octets'] = strlen($content);
                $manifest['fichiers'][$index]['sha256'] = hash('sha256', $content);
            }
        }
        $files['manifeste.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $files;
    }

    /**
     * The fingerprint of a certification line of the file, as the importer recomputes it.
     *
     * @param array $line
     * @return string
     */
    private static function certification_hash(array $line): string {
        $status = ['certifie' => 'certified', 'retire' => 'withdrawn'][$line['statut']];

        return positioning_rules::hash(array_merge(
            [$status, $line['fiche'], $line['rang'], $line['motif'], $line['date_decision'], $line['motif_alerte'] ?? ''],
            isset($line['valable_jusqu_au']) ? [$line['valable_jusqu_au']] : []
        ));
    }

    /**
     * Forge the first certification of the archive with a validity, the fingerprint recomputed, and expect a refusal.
     *
     * @param array $changes Properties of the line to set (property of the file => value; null removes the property).
     */
    private function assert_refused_with(array $changes): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document) use ($changes): array {
            $line = &$document['certifications'][0];
            foreach ($changes as $property => $value) {
                if ($value === null) {
                    unset($line[$property]);
                } else {
                    $line[$property] = $value;
                }
            }
            $line['empreinte'] = self::certification_hash($line);
            unset($line);

            return $document;
        });

        $this->assert_refused($files);
    }

    /**
     * Import as the administrator after the dossier is emptied, and expect a refusal that writes nothing.
     *
     * @param string[] $files
     */
    private function assert_refused(array $files): void {
        global $DB;

        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        try {
            (new dossier_importer())->import_files((int) $this->candidate->id, $files);
            $this->fail('The archive was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorarchivepositioning', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('tool_vae_eval'));
        $this->assertSame(0, $DB->count_records('tool_vae_certif'));
        $this->assertSame(0, $DB->count_records('tool_vae_evidence'));
    }

    public function test_an_archive_without_a_line_carries_no_file(): void {
        $files = $this->build();

        $this->assertArrayNotHasKey(positioning_archive::PATH, $files);
        $this->assertArrayNotHasKey('schema/positionnement.schema.json', $files);
        $this->assertArrayNotHasKey('schema_positionnement', json_decode($files['manifeste.json'], true));
    }

    public function test_the_file_is_listed_valid_and_says_it_is_not_a_validation(): void {
        $this->write_positionings();

        $files = $this->build();

        $this->assertArrayHasKey('schema/positionnement.schema.json', $files);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('0.1.0', $manifest['schema_positionnement']);
        $this->assertContains(positioning_archive::PATH, array_column($manifest['fichiers'], 'chemin'));
        $document = json_decode($files[positioning_archive::PATH], false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], (new schema_validator())->validate($document, 'positionnement.schema.json'));
        $this->assertFalse($document->officiel);
        $this->assertSame(get_string('positioningarchivesentence', 'tool_vae'), $document->avertissement);
        $this->assertStringContainsString('positionnement.json', $files['LISEZMOI.txt']);
        $this->assertDoesNotMatchRegularExpression('/"statut":\s*"valid/', $files[positioning_archive::PATH]);
    }

    public function test_every_line_is_there_the_one_that_stands_is_marked_and_the_labels_are_read(): void {
        $this->write_positionings();

        $document = json_decode($this->build()[positioning_archive::PATH], false, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(3, $document->evaluations);
        $this->assertCount(2, $document->certifications);
        $this->assertSame(
            ['en_cours_de_preuve', 'suffisante_pour_certification', 'a_construire'],
            array_column($document->evaluations, 'statut')
        );
        $this->assertSame([false, true, true], array_column($document->evaluations, 'fait_foi'));
        $this->assertSame('Premiere', $document->evaluations[0]->intitule);
        $this->assertSame('Bloc un', $document->certifications[0]->intitule);
        $this->assertSame('2026-11-03', $document->certifications[0]->date_decision);
        $this->assertSame('C02 sera vue la semaine prochaine.', $document->certifications[0]->motif_alerte);
        $this->assertSame('', $document->certifications[1]->rang);
        $this->assertFalse(property_exists($document->certifications[1], 'motif_alerte'), 'No alert, no key.');
        $this->assertSame(
            exporter::iso(1762100000),
            $document->evaluations[1]->ecrit_le
        );
        $this->assertSame(
            identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->manager->id),
            $document->evaluations[0]->auteur
        );
    }

    public function test_an_author_who_is_gone_has_no_identifier_in_the_file(): void {
        global $DB;
        $this->write_positionings();
        $DB->set_field('tool_vae_eval', 'authorid', null);

        $document = json_decode($this->build()[positioning_archive::PATH], false, 512, JSON_THROW_ON_ERROR);

        foreach ($document->evaluations as $line) {
            $this->assertFalse(property_exists($line, 'auteur'));
        }
        $this->assertTrue(property_exists($document->certifications[0], 'auteur'), 'The other author is still named.');
    }

    public function test_a_line_restored_from_an_archive_is_exported_again_with_the_ulid_of_its_author(): void {
        global $DB;
        $this->write_positionings();
        $first = $this->build();
        $ulid = json_decode($first[positioning_archive::PATH], true)['evaluations'][0]['auteur'];
        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        (new dossier_importer())->import_files((int) $this->candidate->id, $first);
        $this->assertSame(3, $DB->count_records_select('tool_vae_eval', 'authorid IS NULL AND authoruuid IS NOT NULL'));

        $again = json_decode($this->build()[positioning_archive::PATH], true);

        $this->assertSame($ulid, $again['evaluations'][0]['auteur'], 'The author, named by the ULID the archive gave.');
        $this->assertSame(
            array_column(json_decode($first[positioning_archive::PATH], true)['evaluations'], 'empreinte'),
            array_column($again['evaluations'], 'empreinte')
        );
    }

    public function test_the_archive_the_vaeiste_exports_carries_no_positioning(): void {
        $this->write_positionings();
        $this->setUser($this->candidate);

        $files = $this->build(false);

        $this->assertArrayNotHasKey(positioning_archive::PATH, $files);
        $this->assertArrayNotHasKey('schema/positionnement.schema.json', $files);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('schema_positionnement', $manifest);
        $this->assertNotContains(positioning_archive::PATH, array_column($manifest['fichiers'], 'chemin'));
        $this->assertStringNotContainsString('positionnement.json', $files['LISEZMOI.txt']);
        $this->assertStringContainsString(get_string('exportreadmeabsentstructure', 'tool_vae'), $files['LISEZMOI.txt']);
    }

    public function test_the_backup_kept_for_an_uninstall_still_carries_the_positionings(): void {
        global $USER;
        $this->write_positionings();

        $file = \tool_vae\local\backup::save((int) $this->candidate->id, (int) $USER->id);

        $names = array_map(
            fn($entry) => $entry->pathname,
            $file->list_files(get_file_packer('application/zip'))
        );
        $this->assertContains(positioning_archive::PATH, $names, 'The backup is the safety net: it keeps every line.');
    }

    public function test_the_archive_that_is_not_asked_for_the_positionings_never_has_them_by_default(): void {
        global $DB;
        $this->write_positionings();
        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');

        $files = (new exporter())->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time());

        $this->assertArrayNotHasKey(positioning_archive::PATH, $files);
    }

    public function test_the_editable_copy_of_the_dossier_never_carries_the_positionings(): void {
        $now = 1762500000;
        $before = \tool_vae\local\dossier_document::for_candidate((int) $this->candidate->id, $now);

        $this->write_positionings();
        $after = \tool_vae\local\dossier_document::for_candidate((int) $this->candidate->id, $now);

        $this->assertSame($before['digest'], $after['digest'], 'A positioning of the structure makes no new copy of the dossier.');
    }

    public function test_the_printable_copy_writes_the_lines_with_the_sentence(): void {
        $this->write_positionings();
        $files = $this->build();
        $manifest = json_decode($files['manifeste.json'], true);
        $snapshot = (new exporter())->snapshot((int) $this->candidate->id);
        $positioning = json_decode($files[positioning_archive::PATH], true);

        $html = pdf_renderer::html($manifest, $snapshot['preuves'], $snapshot['referentiel'], [], [], [], [], $positioning);

        $this->assertStringContainsString(s(get_string('positioningdossierheading', 'tool_vae')), $html);
        $this->assertStringContainsString(s($positioning['avertissement']), $html);
        $this->assertStringContainsString('Trois situations vues.', $html);
        $this->assertStringContainsString('C02 sera vue la semaine prochaine.', $html);
        $this->assertStringContainsString(s(get_string('positioningpdfearlier', 'tool_vae')), $html);
    }

    public function test_the_editable_copy_writes_the_positionings_and_its_digest_moves_with_a_line(): void {
        $this->write_positionings();
        $files = $this->build();
        $positioning = json_decode($files[positioning_archive::PATH], true);
        $snapshot = (new exporter())->snapshot((int) $this->candidate->id);

        $writer = \tool_vae\local\dossier_document::compose(
            $this->candidate,
            $snapshot['preuves'],
            $snapshot['referentiel'],
            time(),
            [],
            [],
            [],
            [],
            $positioning
        );
        $bare = \tool_vae\local\dossier_document::digest($snapshot['preuves'], $snapshot['referentiel']);
        $with = \tool_vae\local\dossier_document::digest(
            $snapshot['preuves'],
            $snapshot['referentiel'],
            [],
            [],
            [],
            [],
            $positioning
        );

        $this->assertNotSame($bare, $with, 'A positioning makes a new copy.');
        $this->assertSame(
            $bare,
            \tool_vae\local\dossier_document::digest($snapshot['preuves'], $snapshot['referentiel'], [], [], [], [], []),
            'No positioning, the digest of before.'
        );
        $lines = positioning_archive::reading_lines($positioning);
        $this->assertCount(5, $lines);
        $this->assertStringContainsString('Trois situations vues.', implode("\n", $lines));
        $this->assertNotNull($writer);
    }

    public function test_the_lines_come_back_in_order_with_their_author_named_by_ulid_and_the_date_of_the_import(): void {
        global $DB;
        $this->write_positionings();
        $files = $this->build();
        $before = $DB->get_records('tool_vae_eval', null, 'id ASC');
        purge::dossier((int) $this->candidate->id);
        $this->assertSame(0, $DB->count_records('tool_vae_eval'));
        $this->setAdminUser();

        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(5, $result->positionnement);
        $this->assertSame(0, $result->positionnementlaissees);
        $rows = array_values($DB->get_records('tool_vae_eval', null, 'id ASC'));
        $this->assertCount(3, $rows);
        $this->assertSame(
            array_values(array_map(fn($row) => $row->reason, $before)),
            array_map(fn($row) => $row->reason, $rows),
            'The same text, in the same order.'
        );
        $this->assertSame(
            array_values(array_map(fn($row) => $row->reasonhash, $before)),
            array_map(fn($row) => $row->reasonhash, $rows)
        );
        $this->assertSame(array_values(array_map(fn($row) => $row->uuid, $before)), array_map(fn($row) => $row->uuid, $rows));
        foreach ($rows as $row) {
            $this->assertNull($row->authorid);
            $this->assertSame(26, strlen((string) $row->authoruuid));
            $this->assertGreaterThan(0, (int) $row->importedat);
        }
        $this->assertSame(1762000000, (int) $rows[0]->timecreated, 'The date of the line is the archive\'s.');
        $current = positioning_rules::latest('tool_vae_eval', (int) $this->candidate->id, 'RNCP90000');
        $this->assertSame('sufficient', $current['C01']->status, 'The line that stood still stands.');
        $certifs = $DB->get_records('tool_vae_certif', null, 'id ASC');
        $this->assertCount(2, $certifs);
        $this->assertSame('2026-11-03', reset($certifs)->decisiondate);
        $this->assertSame('', end($certifs)->ficherang);
    }

    public function test_a_key_that_holds_a_line_here_is_left_as_it_is_and_the_others_come_back(): void {
        global $DB;
        $this->write_positionings();
        $files = $this->build();
        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        $this->line('tool_vae_eval', 'RNCP90000', 'C01', 'toconstruct', 'Ecrit ici entre-temps.', time(), null);

        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(2, $result->positionnementlaissees, 'The two lines of the key C01.');
        $this->assertSame(3, $result->positionnement);
        $this->assertSame(1, $DB->count_records('tool_vae_eval', ['ficherang' => 'C01']));
        $this->assertSame('Ecrit ici entre-temps.', $DB->get_field('tool_vae_eval', 'reason', ['ficherang' => 'C01']));
        $this->assertSame(1, $DB->count_records('tool_vae_eval', ['ficherang' => 'C02']));
    }

    public function test_a_record_or_a_rank_this_site_does_not_hold_is_left(): void {
        global $DB;
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            // A record this site does not hold, with a fingerprint that is the one of the line it now says.
            $document['evaluations'][2]['fiche'] = 'ZZ99999';
            $document['evaluations'][2]['empreinte'] = positioning_rules::hash(['toconstruct', 'ZZ99999', 'C02', 'Rien encore.']);

            return $document;
        });
        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();

        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(1, $result->positionnementlaissees);
        $this->assertSame(4, $result->positionnement);
        $this->assertSame(0, $DB->count_records('tool_vae_eval', ['fichecode' => 'ZZ99999']));
    }

    public function test_an_archive_with_a_forged_fingerprint_is_refused_and_writes_nothing(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            $document['evaluations'][0]['motif'] = 'Un autre texte que celui qui est empreint.';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_an_archive_that_says_a_line_stands_when_it_does_not_is_refused(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            $document['evaluations'][0]['fait_foi'] = true;

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_an_archive_that_repeats_an_identifier_is_refused(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            $document['evaluations'][1]['identifiant'] = $document['evaluations'][0]['identifiant'];

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_an_archive_with_a_status_that_reads_as_a_validation_is_refused(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            $document['certifications'][0]['statut'] = 'valide';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_an_archive_with_a_day_that_is_not_a_day_is_refused(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            // The fingerprint is recomputed for the forged day: the refusal is the day's, not the fingerprint's.
            $line = &$document['certifications'][0];
            $line['date_decision'] = '2026-02-30';
            $line['valable_jusqu_au'] = '2027-11-03';
            $line['empreinte'] = self::certification_hash($line);
            unset($line);

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_an_archive_with_an_evaluation_of_a_bloc_without_a_rank_is_refused(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            $document['evaluations'][0]['rang'] = '';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_an_archive_with_too_many_lines_is_refused(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            $line = $document['evaluations'][2];
            for ($i = 0; $i <= positioning_archive::MAX_LINES; $i++) {
                $line['identifiant'] = identity_map::generate_ulid();
                $document['evaluations'][] = $line;
            }

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_an_archive_that_dates_a_line_after_now_is_refused(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            $document['evaluations'][1]['ecrit_le'] = '2099-01-01T00:00:00Z';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_an_archive_that_dates_a_line_before_the_one_that_precedes_it_is_refused(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            // The second line of the key C01 dated before the first: the one that stands would not be the last of the file.
            $document['evaluations'][1]['ecrit_le'] = '2001-01-01T00:00:00Z';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_the_validity_of_a_certification_is_in_the_file_only_when_there_is_one(): void {
        $this->write_positionings();

        $document = json_decode($this->build()[positioning_archive::PATH], false, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('2027-11-03', $document->certifications[0]->valable_jusqu_au);
        $this->assertFalse(property_exists($document->certifications[1], 'valable_jusqu_au'));
    }

    public function test_the_validity_comes_back_with_the_line_and_a_line_without_one_stays_null(): void {
        global $DB;
        $this->write_positionings();
        $before = $DB->get_records('tool_vae_certif', null, 'id ASC');
        $files = $this->build();
        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();

        (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $rows = array_values($DB->get_records('tool_vae_certif', null, 'id ASC'));
        $this->assertCount(2, $rows);
        $this->assertSame('2027-11-03', $rows[0]->validuntil);
        $this->assertNull($rows[1]->validuntil, 'No validity is NULL, never an empty string.');
        $this->assertSame(
            array_values(array_map(fn($row) => $row->reasonhash, $before)),
            array_map(fn($row) => $row->reasonhash, $rows),
            'The fingerprint of the line is the same on both sides.'
        );
    }

    public function test_an_archive_whose_validity_was_changed_without_its_fingerprint_is_refused(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            $document['certifications'][0]['valable_jusqu_au'] = '2030-01-01';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_an_archive_that_adds_a_validity_to_a_line_that_had_none_is_refused(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            $document['certifications'][1]['valable_jusqu_au'] = '2030-01-01';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_an_archive_with_a_validity_that_is_not_a_day_is_refused(): void {
        $this->assert_refused_with(['valable_jusqu_au' => '2027-02-30']);
    }

    public function test_an_archive_with_a_validity_before_the_day_of_the_decision_is_refused(): void {
        $this->assert_refused_with(['valable_jusqu_au' => '2026-11-02']);
    }

    public function test_an_archive_with_a_validity_past_the_last_day_allowed_is_refused(): void {
        $this->assert_refused_with(['valable_jusqu_au' => '2100-01-02']);
    }

    public function test_an_archive_with_a_validity_on_a_withdrawal_is_refused(): void {
        // The alert is removed too: a withdrawal that carries one is refused for that, not for the validity.
        $this->assert_refused_with(['statut' => 'retire', 'motif_alerte' => null]);
    }

    /**
     * Restore the archive of the dossier with the validity of its first certification forged to a day that must be accepted.
     *
     * @param string $day
     */
    private function assert_restored_with_validity(string $day): void {
        global $DB;
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document) use ($day): array {
            $line = &$document['certifications'][0];
            $line['valable_jusqu_au'] = $day;
            $line['empreinte'] = self::certification_hash($line);
            unset($line);

            return $document;
        });
        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();

        (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame($day, $DB->get_record('tool_vae_certif', ['ficherang' => 'BC01'])->validuntil);
    }

    public function test_a_validity_on_the_day_of_the_decision_comes_back(): void {
        $this->assert_restored_with_validity('2026-11-03');
    }

    public function test_a_validity_on_the_last_day_allowed_comes_back(): void {
        $this->assert_restored_with_validity('2100-01-01');
    }

    public function test_the_readings_say_until_when_a_certification_stands(): void {
        $this->write_positionings();
        $document = json_decode($this->build()[positioning_archive::PATH], true, 512, JSON_THROW_ON_ERROR);

        $lines = implode("\n", positioning_archive::reading_lines($document));

        $this->assertStringContainsString(get_string('positioninguntil', 'tool_vae', '2027-11-03'), $lines);
        $this->assertSame(1, substr_count($lines, get_string('positioninguntil', 'tool_vae', '2027-11-03')));
    }

    public function test_an_archive_whose_first_line_of_a_key_is_a_withdrawal_is_refused(): void {
        $this->write_positionings();
        $files = self::forge($this->build(), function (array $document): array {
            $line = &$document['evaluations'][0];
            $line['statut'] = 'retiree';
            $line['empreinte'] = positioning_rules::hash(['withdrawn', $line['fiche'], $line['rang'], $line['motif']]);
            unset($line);

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_a_restored_line_never_hides_a_line_written_here_afterwards(): void {
        global $DB;
        $this->write_positionings();
        $files = $this->build();
        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        (new dossier_importer())->import_files((int) $this->candidate->id, $files);
        $before = positioning_rules::latest('tool_vae_eval', (int) $this->candidate->id, 'RNCP90000')['C01'];

        $later = $this->line('tool_vae_eval', 'RNCP90000', 'C01', 'withdrawn', 'Retire ici.', time(), null);

        $current = positioning_rules::latest('tool_vae_eval', (int) $this->candidate->id, 'RNCP90000')['C01'];
        $this->assertSame((int) $later->id, (int) $current->id);
        $this->assertNotSame((int) $before->id, (int) $current->id);
        $this->assertSame(1, $DB->count_records('tool_vae_eval', ['status' => 'withdrawn']));
    }

    public function test_an_author_whose_account_is_deleted_is_not_named_in_the_file(): void {
        global $DB;
        $this->write_positionings();
        $DB->set_field('user', 'deleted', 1, ['id' => $this->manager->id]);

        $document = json_decode($this->build()[positioning_archive::PATH], false, 512, JSON_THROW_ON_ERROR);

        foreach ($document->evaluations as $line) {
            $this->assertFalse(property_exists($line, 'auteur'));
        }
        $this->assertTrue(property_exists($document->certifications[0], 'auteur'));
        $this->assertSame(
            0,
            $DB->count_records('tool_vae_identity', ['itemtype' => 'user', 'itemid' => $this->manager->id]),
            'No identifier is minted for a deleted account.'
        );
    }
}
