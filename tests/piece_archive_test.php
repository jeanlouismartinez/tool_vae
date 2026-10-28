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

use tool_vae\local\attestation_manager;
use tool_vae\local\backup;
use tool_vae\local\dossier_document;
use tool_vae\local\dossier_importer;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\pdf_renderer;
use tool_vae\local\piece_manager;
use tool_vae\local\piece_overview;
use tool_vae\local\purge;
use tool_vae\local\schema_validator;

/**
 * The supporting pieces in the archive, the backup, the import and the printed copies (T-13, lot 3).
 *
 * WHAT IS ASSERTED. That pieces.json lists the pieces and is valid against its schema; that the bytes of the files and the
 * addresses of the links are in the archive the candidate exports themself and in the backup, and NOT in the export made by
 * someone else; that the original name of a file is nowhere; that an archive refuses a file that is not the one it says;
 * that the import restores pieces with their bytes and the signature that covered them, only when attaching is open, and
 * leaves what comes without bytes or address; that an archive the plugin would refuse a person is refused whole, by the name
 * of the piece; that the printed copy and the editable copy list pieces and never give an address.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\piece_overview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\backup::class)]
final class piece_archive_test extends \advanced_testcase {
    /** @var string The address of the link of the tests. */
    private const ADDRESS = 'https://exemple.test/diplome/secret-1';

    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Follows the candidate and may attest. */
    private $manager;

    /** @var int The declaration. */
    private $evidenceid;

    /**
     * A record, a candidate with a declaration, a manager who attests, and attaching open.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();

        (new importer())->import((object) [
            'fichecode' => 'RS00000', 'fichetype' => 'rs', 'name' => 'Fiche RS00000',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv("rang,intitule\nC01,Une\nC02,Deux\n"));

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $this->candidate = $generator->create_user();
        $this->manager = $generator->create_user();

        $role = $generator->create_role();
        foreach (['declare', 'export', 'viewown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $role, $system, true);
        }
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);

        $attest = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $attest, $system, true);
        assign_capability('tool/vae:attest', CAP_ALLOW, $attest, $system, true);
        set_role_contextlevels($attest, [CONTEXT_USER]);
        role_assign($attest, $this->manager->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        set_config('piecesopen', 1, 'tool_vae');
        set_config('piecenoantivirus', 1, 'tool_vae');

        $this->setUser($this->candidate);
        $competency = $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-C02'], MUST_EXIST);
        $evidence = (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $competency, 'periodstart' => 1756684800, 'periodend' => 1782777600,
            'settingtype' => 'ehpad', 'situation' => 'Une situation.', 'situationformat' => FORMAT_PLAIN,
        ]);
        $this->evidenceid = (int) $DB->get_field('tool_vae_evidence', 'id', ['uuid' => $evidence->uuid], MUST_EXIST);
    }

    /**
     * The bytes of a small PDF, distinct for each name.
     *
     * @param string $name
     * @return string
     */
    private function pdf(string $name = 'a'): string {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Title ($name) >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * Attach a file as the candidate.
     *
     * @param string $name A name that must never be kept.
     * @param string $title
     * @return \stdClass
     */
    private function file(string $name = 'Projet_Mme_X.pdf', string $title = 'Un contrat'): \stdClass {
        $this->setUser($this->candidate);
        $path = make_request_directory() . '/upload';
        file_put_contents($path, $this->pdf($name));

        return (new piece_manager())->add_file($this->evidenceid, 'contract', $title, $path, $name);
    }

    /**
     * Attach a link as the candidate.
     *
     * @return \stdClass
     */
    private function link(): \stdClass {
        $this->setUser($this->candidate);

        return (new piece_manager())->add_link($this->evidenceid, 'diploma', 'Mon diplome', self::ADDRESS);
    }

    /**
     * The files of the archive built for the candidate.
     *
     * @param bool $full Whether the bytes and the addresses go in (the archive the candidate exports, the backup).
     * @return string[]
     */
    private function build(bool $full): array {
        global $DB;

        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');

        return (new exporter())->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time(), $full);
    }

    /**
     * The files of a stored archive, path => content.
     *
     * @param \stored_file $archive
     * @return string[]
     */
    private static function unzip(\stored_file $archive): array {
        $path = $archive->copy_content_to_temp();
        $zip = new \ZipArchive();
        $zip->open($path);
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (substr($name, -1) !== '/') {
                $files[$name] = $zip->getFromIndex($index);
            }
        }
        $zip->close();
        unlink($path);

        return $files;
    }

    /**
     * Change files of an archive and write the manifest again, digests included: what a forger who knows the format does.
     *
     * @param string[] $files
     * @param array $changes Path => new content, or null to take the file out.
     * @return string[]
     */
    private static function reseal(array $files, array $changes): array {
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $entries = array_column($manifest['fichiers'], null, 'chemin');
        foreach ($changes as $path => $content) {
            if ($content === null) {
                unset($files[$path], $entries[$path]);
                continue;
            }
            $files[$path] = $content;
            $entries[$path] = ['chemin' => $path, 'octets' => strlen($content), 'sha256' => hash('sha256', $content)];
        }
        $manifest['fichiers'] = array_values($entries);
        $files['manifeste.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $files;
    }

    /**
     * Rewrite pieces.json in an archive, digest included.
     *
     * @param string[] $files
     * @param callable $change Receives the decoded document, returns it changed.
     * @param array $more Other changes, as reseal() takes them.
     * @return string[]
     */
    private static function forge(array $files, callable $change, array $more = []): array {
        $document = $change(json_decode($files[piece_overview::PATH], true, 512, JSON_THROW_ON_ERROR));

        return self::reseal($files, [piece_overview::PATH => json_encode(
            $document,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )] + $more);
    }

    /**
     * Import an archive after the dossier is destroyed, and expect a refusal that writes nothing.
     *
     * @param string[] $files
     * @param string $code The error code expected.
     */
    private function assert_refused(array $files, string $code = 'errorarchivepieces'): void {
        global $DB;

        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        try {
            (new dossier_importer())->import_files((int) $this->candidate->id, $files);
            $this->fail('The archive was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('tool_vae_evidence'));
        $this->assertSame(0, $DB->count_records('tool_vae_piece'));
    }

    /**
     * Sign the declaration as the manager, with the pieces there are.
     *
     * @return \stdClass
     */
    private function attest(): \stdClass {
        $this->setUser($this->manager);
        $active = piece_manager::active_for_evidence($this->evidenceid);

        return (new attestation_manager())->attest($this->evidenceid, (object) [
            'attestantrole' => 'Directeur', 'statement' => 'Je confirme.',
            'piecesseen' => piece_manager::list_token($active), 'piecesread' => 1,
        ]);
    }

    public function test_an_archive_without_a_piece_carries_nothing_of_them(): void {
        $files = $this->build(true);

        $this->assertArrayNotHasKey(piece_overview::PATH, $files);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('schema_pieces', $manifest);
    }

    public function test_the_archive_the_candidate_exports_carries_the_list_the_bytes_and_the_addresses(): void {
        $file = $this->file();
        $link = $this->link();
        $files = $this->build(true);

        $document = json_decode($files[piece_overview::PATH], true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($document['officiel']);
        $rows = array_column($document['pieces'], null, 'id');
        $this->assertSame('fichier', $rows[$file->uuid]['genre']);
        $this->assertSame($file->sha256, $rows[$file->uuid]['sha256']);
        $path = 'pieces/' . $file->uuid . '.pdf';
        $this->assertSame($path, $rows[$file->uuid]['fichier']);
        $this->assertSame($this->pdf('Projet_Mme_X.pdf'), $files[$path]);
        $this->assertSame(self::ADDRESS, $rows[$link->uuid]['adresse']);
        $this->assertArrayNotHasKey('sha256', $rows[$link->uuid], 'A link has no digest.');

        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(piece_overview::SCHEMA_VERSION, $manifest['schema_pieces']);
        $listed = array_column($manifest['fichiers'], 'sha256', 'chemin');
        $this->assertSame($file->sha256, $listed[$path]);
        $this->assertArrayHasKey('schema/pieces.schema.json', $files);
        $this->assertSame([], (new schema_validator())->validate(json_decode($files[piece_overview::PATH]), 'pieces.schema.json'));
    }

    public function test_the_export_of_a_third_party_lists_the_pieces_and_carries_neither_bytes_nor_addresses(): void {
        $file = $this->file();
        $this->link();
        $files = $this->build(false);

        $document = json_decode($files[piece_overview::PATH], true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $document['pieces']);
        foreach ($document['pieces'] as $entry) {
            $this->assertArrayNotHasKey('fichier', $entry);
            $this->assertArrayNotHasKey('adresse', $entry);
        }
        $this->assertArrayNotHasKey('intitule', $document['pieces'][0], 'The title is a free field: not for a third party.');
        $this->assertSame($file->sha256, $document['pieces'][0]['sha256'], 'The digest says that the piece exists.');
        $this->assertSame([], array_filter(array_keys($files), fn($path) => strpos($path, 'pieces/') === 0));
        $this->assertStringNotContainsString('secret-1', implode('', $files));
        $this->assertSame([], (new schema_validator())->validate(json_decode($files[piece_overview::PATH]), 'pieces.schema.json'));
    }

    public function test_the_export_decides_by_who_presses_the_button(): void {
        $file = $this->file();
        $this->link();

        $this->setUser($this->candidate);
        $own = self::unzip((new exporter())->export((int) $this->candidate->id));
        $this->assertArrayHasKey('pieces/' . $file->uuid . '.pdf', $own);
        $this->assertStringContainsString('secret-1', $own[piece_overview::PATH]);

        $this->setAdminUser();
        $theirs = self::unzip((new exporter())->export((int) $this->candidate->id));
        $this->assertArrayNotHasKey('pieces/' . $file->uuid . '.pdf', $theirs);
        $this->assertStringNotContainsString('secret-1', $theirs[piece_overview::PATH]);
    }

    public function test_the_backup_kept_on_the_platform_carries_the_bytes(): void {
        $file = $this->file();
        $this->link();

        $this->setAdminUser();
        $backup = self::unzip(backup::save((int) $this->candidate->id, (int) get_admin()->id));

        $this->assertArrayHasKey('pieces/' . $file->uuid . '.pdf', $backup);
        $this->assertStringContainsString('secret-1', $backup[piece_overview::PATH]);
    }

    public function test_the_original_name_of_a_file_is_nowhere_in_the_archive(): void {
        $this->file('Projet_Mme_X.pdf', '');
        $files = $this->build(true);

        foreach (array_keys($files) as $path) {
            $this->assertStringNotContainsString('Mme_X', $path);
        }
        // The bytes of the file carry the word the test put inside them; only the names and the JSON are looked at.
        foreach ($files as $path => $content) {
            if (substr($path, -4) === '.pdf' && strpos($path, 'pieces/') === 0) {
                continue;
            }
            $this->assertStringNotContainsString('Mme_X', $content, $path);
        }
    }

    public function test_an_export_is_refused_by_the_name_of_a_file_that_is_no_longer_the_one_received(): void {
        $file = $this->file();
        $context = \context_user::instance($this->candidate->id);
        $fs = get_file_storage();
        $stored = $fs->get_file($context->id, 'tool_vae', 'piece', (int) $file->id, '/', $file->uuid . '.pdf');
        $stored->delete();
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'tool_vae', 'filearea' => 'piece', 'itemid' => (int) $file->id,
            'filepath' => '/', 'filename' => $file->uuid . '.pdf',
        ], $this->pdf('autre'));

        try {
            $this->build(true);
            $this->fail('An altered file went into an archive.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorpiecealtered', $e->errorcode);
            $this->assertStringContainsString($file->uuid, (string) $e->a);
        }
    }

    public function test_the_import_restores_pieces_with_their_bytes_and_the_signature_that_covered_them(): void {
        global $DB;
        $file = $this->file();
        $link = $this->link();
        $record = $this->attest();
        $this->setUser($this->candidate);
        $gone = (new piece_manager())->add_link($this->evidenceid, 'other', 'Retiree', 'https://exemple.test/x');
        // A piece added after the signature: it is not covered, and the dossier says so after the import as before.
        $late = $this->file('b.pdf', 'Tardive');
        (new piece_manager())->remove((int) $gone->id);
        $files = $this->build(true);

        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(4, $result->pieces);
        $this->assertSame(0, $result->piecesleft);
        $evidenceid = (int) $DB->get_field('tool_vae_evidence', 'id', ['candidateid' => $this->candidate->id], MUST_EXIST);
        $rows = array_column(piece_manager::for_evidence($evidenceid), null, 'uuid');
        $this->assertCount(4, $rows);
        $this->assertSame(self::ADDRESS, $rows[$link->uuid]->url);
        $this->assertSame($file->sha256, $rows[$file->uuid]->sha256);
        $this->assertNotNull($rows[$gone->uuid]->timeremoved);
        $this->assertSame('', $rows[$gone->uuid]->title);

        $context = \context_user::instance($this->candidate->id);
        $stored = get_file_storage()->get_file(
            $context->id,
            'tool_vae',
            'piece',
            (int) $rows[$file->uuid]->id,
            '/',
            $file->uuid . '.pdf'
        );
        $this->assertNotFalse($stored);
        $this->assertSame($this->pdf('Projet_Mme_X.pdf'), $stored->get_content());

        $standing = attestation_manager::active_attestation($evidenceid);
        $covered = piece_manager::covered_by((int) $standing->id);
        $this->assertSame($record->uuid, $standing->uuid);
        $this->assertArrayHasKey((int) $rows[$file->uuid]->id, $covered);
        $this->assertArrayHasKey((int) $rows[$link->uuid]->id, $covered);
        $this->assertArrayNotHasKey((int) $rows[$late->uuid]->id, $covered, 'The late piece is still not covered.');
    }

    public function test_an_archive_listing_pieces_without_bytes_or_addresses_restores_only_the_trace_of_removed_ones(): void {
        global $DB;
        $this->file();
        $this->link();
        $removed = $this->link();
        (new piece_manager())->remove((int) $removed->id);
        $files = $this->build(false);
        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();

        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(1, $result->pieces, 'Only the removed one: it has a trace and nothing else to bring.');
        $this->assertSame(2, $result->piecesleft);
        $this->assertSame(1, $DB->count_records('tool_vae_piece'));
    }

    public function test_a_piece_for_a_declaration_the_archive_does_not_hold_refuses_the_archive(): void {
        $this->file();
        $files = self::forge($this->build(true), function (array $document): array {
            $document['pieces'][0]['preuve'] = '01M5FVS7278BPYR1YZRMCM53JW';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_a_piece_identifier_written_twice_refuses_the_archive(): void {
        $this->link();
        $files = self::forge($this->build(true), function (array $document): array {
            $document['pieces'][] = $document['pieces'][0];

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_a_file_that_is_not_the_one_the_archive_says_refuses_the_archive_even_with_matching_manifest(): void {
        $file = $this->file();
        $files = $this->build(true);
        $files = self::reseal($files, ['pieces/' . $file->uuid . '.pdf' => $this->pdf('autre chose')]);

        $this->assert_refused($files);
    }

    public function test_html_under_the_name_of_a_pdf_refuses_the_archive_even_when_every_digest_agrees(): void {
        $file = $this->file();
        $html = '<html><script>alert(1)</script></html>';
        $files = self::forge($this->build(true), function (array $document) use ($html): array {
            $document['pieces'][0]['taille'] = strlen($html);
            $document['pieces'][0]['sha256'] = hash('sha256', $html);

            return $document;
        }, ['pieces/' . $file->uuid . '.pdf' => $html]);

        $this->assert_refused($files);
    }

    public function test_a_pdf_that_starts_something_on_opening_refuses_the_archive(): void {
        $file = $this->file();
        $bad = $this->pdf('x') . "\n/JavaScript (app.alert(1))\n";
        $files = self::forge($this->build(true), function (array $document) use ($bad): array {
            $document['pieces'][0]['taille'] = strlen($bad);
            $document['pieces'][0]['sha256'] = hash('sha256', $bad);

            return $document;
        }, ['pieces/' . $file->uuid . '.pdf' => $bad]);

        $this->assert_refused($files);
    }

    public function test_a_file_in_the_pieces_folder_that_no_piece_names_refuses_the_archive(): void {
        $this->file();
        $files = self::reseal($this->build(true), ['pieces/01M5FVS7278BPYR1YZRMCM53JW.pdf' => $this->pdf('orphan')]);

        $this->assert_refused($files);
    }

    public function test_a_pieces_folder_without_pieces_json_refuses_the_archive(): void {
        $this->file();
        $files = $this->build(true);
        $files = self::reseal($files, [piece_overview::PATH => null]);

        $this->assert_refused($files);
    }

    public function test_a_piece_covered_by_a_signature_the_declaration_does_not_carry_refuses_the_archive(): void {
        $this->link();
        $this->attest();
        $files = self::forge($this->build(true), function (array $document): array {
            $document['pieces'][0]['couverte_par'] = '01M5FVS7278BPYR1YZRMCM53JW';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_a_link_that_carries_a_file_property_is_refused(): void {
        $this->link();
        $files = self::forge($this->build(true), function (array $document): array {
            $document['pieces'][0]['taille'] = 5;

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_a_closed_site_refuses_an_archive_that_carries_pieces_and_imports_nothing(): void {
        global $DB;
        $this->file();
        $this->link();
        $files = $this->build(true);
        purge::dossier((int) $this->candidate->id);
        set_config('piecesopen', 0, 'tool_vae');
        $this->setAdminUser();

        try {
            (new dossier_importer())->import_files((int) $this->candidate->id, $files);
            $this->fail('Pieces were dropped in silence.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorarchivepiecesclosed', $e->errorcode);
        }
        $this->assertSame(
            0,
            $DB->count_records('tool_vae_evidence'),
            'Nothing imported: the declaration can still come, with its pieces.'
        );

        // Opened, the same archive goes through, and the pieces come with it.
        set_config('piecesopen', 1, 'tool_vae');
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);
        $this->assertSame(2, $result->pieces);
    }

    public function test_a_closed_site_without_antivirus_imports_an_archive_that_only_lists_pieces(): void {
        global $DB;
        $this->file();
        $this->link();
        $files = $this->build(false);
        purge::dossier((int) $this->candidate->id);
        set_config('piecesopen', 0, 'tool_vae');
        set_config('piecenoantivirus', 0, 'tool_vae');
        $this->setAdminUser();

        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(1, $result->declarations);
        $this->assertSame(0, $result->pieces);
        $this->assertSame(2, $result->piecesleft);
        $this->assertSame(0, $DB->count_records('tool_vae_piece'));
    }

    public function test_a_property_name_of_the_archive_is_escaped_in_the_refusal(): void {
        $this->link();
        $files = self::forge($this->build(true), function (array $document): array {
            $document['<img src=x onerror=alert(1)>'] = 1;

            return $document;
        });
        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();

        try {
            (new dossier_importer())->import_files((int) $this->candidate->id, $files);
            $this->fail('The archive was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorarchivepieces', $e->errorcode);
            $this->assertStringNotContainsString('<img', (string) $e->a);
            $this->assertStringContainsString('&lt;img', (string) $e->a);
        }
    }

    public function test_an_address_typed_with_a_capital_scheme_is_kept_in_lower_case_and_the_archive_can_carry_it(): void {
        $this->setUser($this->candidate);
        $row = (new piece_manager())->add_link($this->evidenceid, 'other', '', 'Https://Exemple.test/a');

        $this->assertSame('https://Exemple.test/a', $row->url);
        $files = $this->build(true);
        $this->assertSame([], (new schema_validator())->validate(json_decode($files[piece_overview::PATH]), 'pieces.schema.json'));
        $this->assert_round_trip($files, 1);
    }

    /**
     * Import an archive after the dossier is destroyed, and expect the pieces to come back.
     *
     * @param string[] $files
     * @param int $expected How many pieces are restored.
     */
    private function assert_round_trip(array $files, int $expected): void {
        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);
        $this->assertSame($expected, $result->pieces);
    }

    public function test_an_address_with_a_no_break_space_is_refused_at_the_door(): void {
        $this->setUser($this->candidate);
        try {
            (new piece_manager())->add_link($this->evidenceid, 'other', '', "https://exemple.test/a\u{00A0}b");
            $this->fail('An address the archive could not carry was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorpieceurl', $e->errorcode);
        }
    }

    public function test_an_address_with_identifiers_in_the_archive_is_refused(): void {
        $this->link();
        $files = self::forge($this->build(true), function (array $document): array {
            $document['pieces'][0]['adresse'] = 'https://admin:secret@exemple.test/';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_a_title_the_plugin_would_have_cleaned_is_refused_in_the_archive(): void {
        $this->file();
        $files = self::forge($this->build(true), function (array $document): array {
            $document['pieces'][0]['intitule'] = 'Un <b>titre</b>';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_an_identifier_that_is_not_a_ulid_is_refused_by_the_name_of_the_piece(): void {
        $this->link();
        $files = self::forge($this->build(true), function (array $document): array {
            $document['pieces'][0]['id'] = '3f2b8c1e-9a4d-4e6f-8b7a-1c2d3e4f5a6b';

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_a_piece_said_covered_by_a_signature_given_before_it_was_added_is_refused(): void {
        $this->link();
        $this->attest();
        $this->link();
        $files = self::forge($this->build(true), function (array $document): array {
            $covered = array_values(array_filter($document['pieces'], fn($piece) => isset($piece['couverte_par'])));
            $late = array_search(null, array_map(fn($piece) => $piece['couverte_par'] ?? null, $document['pieces']), true);
            $document['pieces'][$late]['couverte_par'] = $covered[0]['couverte_par'];
            // Said to have been added well after the signature (the two can fall in the same second in a test).
            $document['pieces'][$late]['ajoutee_le'] = gmdate('Y-m-d\TH:i:s\Z', time() + 7200);

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_more_than_the_bound_of_active_pieces_on_a_declaration_is_refused_in_the_archive(): void {
        $this->link();
        $files = self::forge($this->build(true), function (array $document): array {
            $first = $document['pieces'][0];
            for ($i = 0; $i < piece_manager::MAX_ACTIVE; $i++) {
                $first['id'] = identity_map::generate_ulid();
                $document['pieces'][] = $first;
            }

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_the_importer_is_not_the_author_of_the_pieces_it_restores(): void {
        global $DB;
        $this->link();
        $gone = $this->link();
        (new piece_manager())->remove((int) $gone->id);
        $files = $this->build(true);

        $this->assert_round_trip($files, 2);

        foreach ($DB->get_records('tool_vae_piece') as $row) {
            $this->assertSame((int) $this->candidate->id, (int) $row->usercreated);
            $this->assertNotSame((int) get_admin()->id, (int) $row->usercreated);
            if ($row->timeremoved !== null) {
                $this->assertSame((int) $this->candidate->id, (int) $row->removedby);
            }
        }
    }

    public function test_the_title_does_not_leave_with_a_third_party_but_the_page_and_the_candidate_keep_it(): void {
        $this->file('a.pdf', 'Titre libre');
        $this->assertStringNotContainsString('Titre libre', implode('', $this->build(false)));
        $this->assertStringContainsString('Titre libre', $this->build(true)[piece_overview::PATH]);
    }

    public function test_a_lost_file_stops_an_export_the_person_is_promised_but_not_a_refresh(): void {
        global $DB;
        $file = $this->file();
        $this->link();
        $context = \context_user::instance($this->candidate->id);
        get_file_storage()->get_file($context->id, 'tool_vae', 'piece', (int) $file->id, '/', $file->uuid . '.pdf')->delete();
        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');

        // The acts that must not stop for one lost file: the archive is built without it, the piece listed without its path.
        $files = (new exporter())->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time(), true, false);
        $this->assertDebuggingCalled();

        $document = json_decode($files[piece_overview::PATH], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('fichier', $document['pieces'][0]);
        $this->assertArrayNotHasKey('pieces/' . $file->uuid . '.pdf', $files);
        $this->assertSame([], (new schema_validator())->validate(json_decode($files[piece_overview::PATH]), 'pieces.schema.json'));

        $this->expectException(\moodle_exception::class);
        (new exporter())->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time(), true, true);
    }

    public function test_a_backup_made_for_an_account_that_is_not_the_administrator_carries_the_list_only(): void {
        $file = $this->file();
        $this->link();

        $this->setAdminUser();
        $backup = self::unzip(backup::save((int) $this->candidate->id, (int) $this->manager->id));

        $this->assertArrayNotHasKey('pieces/' . $file->uuid . '.pdf', $backup);
        $this->assertStringNotContainsString('secret-1', $backup[piece_overview::PATH]);
        $this->assertArrayHasKey(piece_overview::PATH, $backup);
    }

    public function test_the_event_of_an_export_says_whether_the_bytes_left(): void {
        $this->file();
        $sink = $this->redirectEvents();

        $this->setUser($this->candidate);
        (new exporter())->export((int) $this->candidate->id);
        $this->setAdminUser();
        (new exporter())->export((int) $this->candidate->id);

        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \tool_vae\event\dossier_exported
        ));
        $this->assertCount(2, $events);
        $this->assertSame(1, (int) $events[0]->other['pieces']);
        $this->assertSame(0, (int) $events[1]->other['pieces']);
    }

    public function test_the_printed_copy_lists_the_pieces_and_never_an_address(): void {
        $file = $this->file();
        $this->link();
        $files = $this->build(true);
        $document = json_decode($files[piece_overview::PATH], true, 512, JSON_THROW_ON_ERROR);
        $snapshot = (new exporter())->snapshot((int) $this->candidate->id);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);

        $html = pdf_renderer::html(
            $manifest,
            $snapshot['preuves'],
            $snapshot['referentiel'],
            [],
            [],
            piece_overview::by_declaration($document)
        );

        $this->assertStringContainsString('Supporting pieces', $html);
        $this->assertStringContainsString('Employment contract', $html);
        $this->assertStringContainsString($file->sha256, $html);
        $this->assertStringContainsString('no digest', $html, 'A link says that it has none.');
        $this->assertStringNotContainsString('secret-1', $html);
        $this->assertStringNotContainsString('exemple.test', $html);
    }

    public function test_the_editable_copy_lists_the_pieces_and_never_an_address_and_changes_with_them(): void {
        $this->link();
        $this->setAdminUser();
        $before = dossier_document::for_candidate((int) $this->candidate->id, 1790000000, true);
        $this->file();
        $this->setAdminUser();
        $after = dossier_document::for_candidate((int) $this->candidate->id, 1790000000, true);

        $text = self::zip_text($after['writer']->odt());
        $this->assertStringContainsString('Supporting pieces', $text);
        $this->assertStringContainsString('Employment contract', $text);
        $this->assertStringNotContainsString('secret-1', $text);
        $this->assertStringNotContainsString('exemple.test', $text);
        $this->assertNotSame($before['digest'], $after['digest'], 'A piece added makes a new weekly copy.');
    }

    /**
     * The content.xml of an .odt.
     *
     * @param string $bytes
     * @return string
     */
    private static function zip_text(string $bytes): string {
        $path = make_request_directory() . '/copie.odt';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $zip->open($path);
        $content = (string) $zip->getFromName('content.xml');
        $zip->close();

        return $content;
    }
}
