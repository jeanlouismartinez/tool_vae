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

use tool_vae\local\dossier_document;
use tool_vae\local\dossier_importer;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\pdf_renderer;
use tool_vae\local\purge;
use tool_vae\local\schema_validator;
use tool_vae\local\scope_manager;

/**
 * What a candidate says is outside their activity, in the archive, the printed copy and the import (T-42, lot 3).
 *
 * WHAT IS ASSERTED. That an archive holds hors_activite.json only when there is a mark, listed in the manifest with its
 * schema, valid by that schema, whoever exports it and whether the site lets a candidate add one or not; that the printed copy
 * and the editable copy write the marks with the sentence that says they exempt from nothing; that the digest of a copy moves
 * with a mark; and that the import brings the marks back for the candidate it is imported for, never over a mark that is
 * there, leaves a competency this site does not hold, and refuses a file the schema or the rules refuse - writing nothing.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\scope_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\pdf_renderer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_document::class)]
final class scope_archive_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /**
     * A record with two competencies, a candidate who declared on one, and the function open.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('scopeopen', 1, 'tool_vae');

        (new importer())->import((object) [
            'fichecode' => 'RS00000', 'fichetype' => 'rs', 'name' => 'Fiche RS00000',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv("rang,intitule,description,bloc\nC01,Une,,\nC02,Deux,,\nC03,Trois,,\n"));

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $role = $generator->create_role();
        foreach (['declare', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $role, \context_system::instance()->id, true);
        }
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->candidate);
        $competency = $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-C02'], MUST_EXIST);
        (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $competency, 'periodstart' => 1756684800, 'periodend' => 1782777600,
            'settingtype' => 'ehpad', 'situation' => 'Une situation.', 'situationformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * The files of the archive the CURRENT user builds for the candidate.
     *
     * @return string[]
     */
    private function build(): array {
        global $DB;

        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');

        return (new exporter())->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time());
    }

    /**
     * Rewrite hors_activite.json in an archive, digest included.
     *
     * @param string[] $files
     * @param callable $change Receives the decoded document, returns it changed.
     * @return string[]
     */
    private static function forge(array $files, callable $change): array {
        $content = json_encode(
            $change(json_decode($files[scope_manager::PATH], true, 512, JSON_THROW_ON_ERROR)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $files[scope_manager::PATH] = $content;
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['fichiers'] as $index => $entry) {
            if ($entry['chemin'] === scope_manager::PATH) {
                $manifest['fichiers'][$index]['octets'] = strlen($content);
                $manifest['fichiers'][$index]['sha256'] = hash('sha256', $content);
            }
        }
        $files['manifeste.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $files;
    }

    /**
     * An archive of the candidate with two marks, one of them with a note.
     *
     * @return string[]
     */
    private function archive_with_marks(): array {
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01', 'Mon poste ne passe pas par là.');
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C03');

        return $this->build();
    }

    /**
     * Import as the administrator and expect a refusal that writes nothing.
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
            $this->assertSame('errorarchivescope', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('tool_vae_scope'));
        $this->assertSame(0, $DB->count_records('tool_vae_evidence'));
    }

    public function test_an_archive_without_a_mark_carries_no_file(): void {
        $files = $this->build();

        $this->assertArrayNotHasKey(scope_manager::PATH, $files);
        $this->assertArrayNotHasKey('schema/hors_activite.schema.json', $files);
        $this->assertArrayNotHasKey('schema_hors_activite', json_decode($files['manifeste.json'], true));
    }

    public function test_the_file_is_listed_valid_and_says_what_it_is(): void {
        $files = $this->archive_with_marks();

        $this->assertArrayHasKey('schema/hors_activite.schema.json', $files);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('0.1.0', $manifest['schema_hors_activite']);
        $this->assertContains(scope_manager::PATH, array_column($manifest['fichiers'], 'chemin'));

        $document = json_decode($files[scope_manager::PATH], false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], (new schema_validator())->validate($document, 'hors_activite.schema.json'));
        $this->assertSame(get_string('scopesentence', 'tool_vae'), $document->avertissement);
        $this->assertCount(2, $document->competences);
        $this->assertSame('RS00000', $document->competences[0]->fiche);
        $this->assertSame('C01', $document->competences[0]->rang);
        $this->assertSame('Une', $document->competences[0]->intitule);
        $this->assertSame('Mon poste ne passe pas par là.', $document->competences[0]->note);
        $this->assertFalse(property_exists($document->competences[1], 'note'), 'No note, no key.');
    }

    public function test_whoever_exports_carries_it_and_a_closed_site_does_too(): void {
        $this->archive_with_marks();
        set_config('scopeopen', 0, 'tool_vae');
        $this->setAdminUser();

        $this->assertArrayHasKey(scope_manager::PATH, $this->build());
    }

    public function test_the_printable_copy_writes_the_marks_with_the_sentence(): void {
        $files = $this->archive_with_marks();
        $manifest = json_decode($files['manifeste.json'], true);
        $snapshot = (new exporter())->snapshot((int) $this->candidate->id);
        $scope = json_decode($files[scope_manager::PATH], true);

        $html = pdf_renderer::html($manifest, $snapshot['preuves'], $snapshot['referentiel'], [], [], [], $scope);

        $this->assertStringContainsString(s(get_string('scopesentence', 'tool_vae')), $html);
        $this->assertStringContainsString('RS00000-C01', $html);
        $this->assertStringContainsString('RS00000-C03', $html);
        $this->assertStringContainsString('Mon poste ne passe pas par là.', $html);
        $this->assertStringNotContainsString(
            s(get_string('scopesentence', 'tool_vae')),
            pdf_renderer::html($manifest, $snapshot['preuves'], $snapshot['referentiel'])
        );
    }

    public function test_the_editable_copy_writes_them_and_the_digest_moves(): void {
        $files = $this->archive_with_marks();
        $snapshot = (new exporter())->snapshot((int) $this->candidate->id);
        $scope = json_decode($files[scope_manager::PATH], true);

        $bytes = dossier_document::compose(
            $this->candidate,
            $snapshot['preuves'],
            $snapshot['referentiel'],
            time(),
            [],
            [],
            [],
            $scope
        )->odt();
        $path = make_request_directory() . '/copie.odt';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $content = (string) $zip->getFromName('content.xml');
        $zip->close();
        $this->assertStringContainsString('RS00000-C01', $content);

        $without = dossier_document::digest($snapshot['preuves'], $snapshot['referentiel']);
        $with = dossier_document::digest($snapshot['preuves'], $snapshot['referentiel'], [], [], [], $scope);
        $this->assertNotSame($without, $with);
        // With none, the digest is what it always was.
        $this->assertSame($without, dossier_document::digest($snapshot['preuves'], $snapshot['referentiel'], [], [], [], []));
    }

    public function test_the_import_brings_the_marks_back_for_the_candidate(): void {
        global $DB;
        $files = $this->archive_with_marks();
        $said = (int) $DB->get_field('tool_vae_scope', 'timecreated', ['ficherang' => 'C01']);

        purge::dossier((int) $this->candidate->id);
        $this->assertSame(0, $DB->count_records('tool_vae_scope'));
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(2, $result->horsactivite);
        $this->assertSame(0, $result->horsactivitelaissees);
        $rows = $DB->get_records('tool_vae_scope', ['candidateid' => $this->candidate->id], 'ficherang');
        $this->assertCount(2, $rows);
        $first = reset($rows);
        $this->assertSame('Mon poste ne passe pas par là.', $first->note);
        $this->assertEqualsWithDelta($said, (int) $first->timecreated, 1);
    }

    public function test_a_mark_already_there_is_left_as_it_is(): void {
        global $DB;
        $files = $this->archive_with_marks();

        purge::dossier((int) $this->candidate->id);
        $this->setUser($this->candidate);
        scope_manager::mark((int) $this->candidate->id, 'RS00000', 'C01', 'Ma note d\'ici.');
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(1, $result->horsactivite);
        $this->assertSame(1, $result->horsactivitelaissees);
        $this->assertSame('Ma note d\'ici.', $DB->get_field('tool_vae_scope', 'note', ['ficherang' => 'C01']));
    }

    public function test_a_competency_this_site_does_not_hold_is_left(): void {
        global $DB;
        $files = self::forge($this->archive_with_marks(), function (array $document): array {
            $document['competences'][1]['fiche'] = 'RS99999';

            return $document;
        });

        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(1, $result->horsactivite);
        $this->assertSame(1, $result->horsactivitelaissees);
        $this->assertFalse($DB->record_exists('tool_vae_scope', ['fichecode' => 'RS99999']));
    }

    public function test_the_same_competency_twice_refuses_the_archive(): void {
        $files = self::forge($this->archive_with_marks(), function (array $document): array {
            $document['competences'][1] = $document['competences'][0];

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_a_file_the_schema_refuses_refuses_the_archive_even_with_a_matching_digest(): void {
        $files = self::forge($this->archive_with_marks(), function (array $document): array {
            $document['competences'][0]['dispense'] = true;

            return $document;
        });

        $this->assert_refused($files);
    }

    public function test_a_note_with_a_control_character_refuses_the_archive(): void {
        $files = self::forge($this->archive_with_marks(), function (array $document): array {
            $document['competences'][0]['note'] = "deux\nlignes";

            return $document;
        });

        $this->assert_refused($files);
    }
}
