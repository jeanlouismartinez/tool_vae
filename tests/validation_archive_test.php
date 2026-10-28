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
use tool_vae\local\draft_manager;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\pdf_renderer;
use tool_vae\local\purge;
use tool_vae\local\schema_validator;
use tool_vae\local\validation_catalog;
use tool_vae\local\validation_overview;

/**
 * The preparation of the validation dossier in the archive, the printed copy and the word-processed copy (T-11, lot 2).
 *
 * WHAT IS ASSERTED. That validation.json holds the latest SAVED version of each section - never the draft - with the
 * sentence that says it is not the official dossier and that nobody attests it, and is valid against its schema; that the
 * file is listed in the manifest and absent when nothing is written; that a section of a bloc withdrawn from the record is
 * exported all the same; that the printed copy and the word-processed copy say the same thing, and that a section saved again
 * makes a new weekly copy; that the import brings the sections back as version 1 without ever overwriting, and refuses
 * whole an archive that names a record this site does not hold, a code that does not read as its bloc and part, or the
 * same section twice.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\validation_overview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_importer::class)]
final class validation_archive_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /**
     * A record with two blocs, a candidate with a declaration, and the preparation open.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        validation_catalog::reset();
        set_config('validationopen', 1, 'tool_vae');
        $this->setAdminUser();

        (new importer())->import((object) [
            'fichecode' => 'RS00000', 'fichetype' => 'rs', 'name' => 'Fiche RS00000',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv(
            "rang,intitule,description,bloc\nBC10,Dixième,,\nC10,Une,,BC10\nBC02,Deuxième,,\nC02,Deux,,BC02\n"
        ));

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $role = $generator->create_role();
        foreach (['declare', 'draftown', 'export'] as $capability) {
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
     * Write a section of the preparation and save a version of it.
     *
     * @param string $section
     * @param string ...$versions The texts of the versions, in order.
     * @return \stdClass The section.
     */
    private function write(string $section, string ...$versions): \stdClass {
        $this->setUser($this->candidate);
        $manager = new draft_manager();
        $draft = $manager->section(
            (int) $this->candidate->id,
            draft_manager::DOCTYPE_VALIDATION,
            $section,
            0,
            'RS00000'
        );
        foreach ($versions as $text) {
            $manager->save_version((int) $draft->id, $text);
        }

        return $draft;
    }

    /**
     * The files of the candidate's archive.
     *
     * @param bool|null $forauthor Whether the archive is for the candidate, said in so many words; null leaves it to the
     *        session, which is the candidate's own when they are the current user.
     * @return string[]
     */
    private function build(?bool $forauthor = null): array {
        global $DB;

        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');

        return (new exporter())->build(
            (int) $this->candidate->id,
            $claims,
            identity_map::generate_ulid(),
            time(),
            false,
            true,
            $forauthor
        );
    }

    /**
     * Rewrite validation.json in an archive, digest included.
     *
     * @param string[] $files
     * @param callable $change Receives the decoded document, returns it changed.
     * @return string[]
     */
    private static function forge(array $files, callable $change): array {
        $content = json_encode(
            $change(json_decode($files[exporter::VALIDATION_PATH], true, 512, JSON_THROW_ON_ERROR)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $files[exporter::VALIDATION_PATH] = $content;
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['fichiers'] as $index => $entry) {
            if ($entry['chemin'] === exporter::VALIDATION_PATH) {
                $manifest['fichiers'][$index]['octets'] = strlen($content);
                $manifest['fichiers'][$index]['sha256'] = hash('sha256', $content);
            }
        }
        $files['manifeste.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $files;
    }

    /**
     * The files of an archive carrying the sections written by the test.
     *
     * @return string[]
     */
    private function archive_with_sections(): array {
        $this->write('bc10_decrire', 'Premier jet.', 'Texte enregistré du bloc dix.');
        $this->write('bc02_analyser', 'Texte du bloc deux.');
        $this->write('_synthese', 'La synthèse de la fiche.');

        return $this->build();
    }

    /**
     * The text of a .docx, paragraphs on lines.
     *
     * @param string $bytes
     * @return string
     */
    private static function docx_text(string $bytes): string {
        $path = make_request_directory() . '/x.docx';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Import as the administrator and expect a refusal that writes nothing.
     *
     * @param string[] $files
     */
    private function assert_refused_after_destroy(array $files): void {
        global $DB;

        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        try {
            (new dossier_importer())->import_files((int) $this->candidate->id, $files);
            $this->fail('The archive was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorarchivevalidation', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('tool_vae_draft'));
        $this->assertSame(0, $DB->count_records('tool_vae_evidence'));
    }

    public function test_an_archive_without_a_written_section_carries_no_validation_file(): void {
        $files = $this->build();

        $this->assertArrayNotHasKey(exporter::VALIDATION_PATH, $files);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('schema_validation', $manifest);
    }

    public function test_the_file_holds_the_latest_saved_version_and_says_what_it_is(): void {
        $files = $this->archive_with_sections();
        // A draft in progress is not exported.
        $draft = $this->write('bc10_lier');
        (new draft_manager())->save_working((int) $draft->id, 'Brouillon jamais enregistré.');

        $document = json_decode($this->build()[exporter::VALIDATION_PATH], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('par-bloc', $document['gabarit']);
        $this->assertFalse($document['officiel']);
        $this->assertStringContainsString('Writing aid proposed by this plugin', $document['avertissement']);
        $this->assertStringContainsString('Nobody attests this text', $document['avertissement']);
        // By bloc in natural order (BC02 before BC10), the synthesis last.
        $this->assertSame(['bc02_analyser', 'bc10_decrire', '_synthese'], array_column($document['rubriques'], 'code'));
        $rows = array_column($document['rubriques'], null, 'code');
        $this->assertSame('Texte enregistré du bloc dix.', $rows['bc10_decrire']['texte']);
        $this->assertSame(2, $rows['bc10_decrire']['version']);
        $this->assertSame('BC10', $rows['bc10_decrire']['bloc']);
        $this->assertNull($rows['_synthese']['bloc']);
        $this->assertSame('synthese', $rows['_synthese']['partie']);
        $this->assertStringNotContainsString('Brouillon jamais enregistré', json_encode($document));
        $this->assertArrayHasKey(exporter::VALIDATION_PATH, $files);
    }

    public function test_the_file_is_valid_against_its_schema_and_listed_in_the_manifest(): void {
        $files = $this->archive_with_sections();

        $document = json_decode($files[exporter::VALIDATION_PATH], false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], (new schema_validator())->validate($document, 'validation.schema.json'));
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(validation_overview::SCHEMA_VERSION, $manifest['schema_validation']);
        $listed = array_column($manifest['fichiers'], null, 'chemin');
        $this->assertSame(hash('sha256', $files[exporter::VALIDATION_PATH]), $listed[exporter::VALIDATION_PATH]['sha256']);
        $this->assertArrayHasKey('schema/validation.schema.json', $listed);
        // The schema of the claims and its version are untouched.
        $this->assertSame(exporter::SCHEMA_VERSION, $manifest['schema_preuve']);
        $this->assertSame(exporter::FORMAT_VERSION, $manifest['version_format']);
    }

    public function test_a_third_party_export_carries_nothing_that_was_not_deposited(): void {
        $this->write('bc10_decrire', 'Enregistré, jamais déposé.');

        $this->assertArrayHasKey(exporter::VALIDATION_PATH, $this->build());

        // The administrator, who may export the dossier, does not read what is only saved (D-15 (3)).
        $this->setAdminUser();
        $files = $this->build();
        $this->assertArrayNotHasKey(exporter::VALIDATION_PATH, $files);
        $this->assertStringNotContainsString('jamais déposé', implode('', $files));
    }

    /**
     * Audit 1 and 35: whether an archive carries the preparation is said by the caller, not read from the session.
     *
     * Before: build() asked $USER - the candidate in the request where they remove a piece, nobody on the command line - and
     * a backup refreshed there came out with the preparation, into the private files of the administrator. Said "not for the
     * author", the archive a candidate's own session builds carries none; said "for the author", it does, whoever is there.
     */
    public function test_an_archive_not_said_to_be_for_its_author_carries_no_preparation_in_the_authors_own_session(): void {
        $this->write('bc10_decrire', 'Enregistré, jamais déposé.');

        $this->setUser($this->candidate);
        $files = $this->build(false);
        $this->assertArrayNotHasKey(exporter::VALIDATION_PATH, $files);
        $this->assertStringNotContainsString('jamais déposé', implode('', $files));
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('schema_validation', $manifest);

        $this->setAdminUser();
        $this->assertArrayHasKey(exporter::VALIDATION_PATH, $this->build(true));

        // Nobody at all - the command line, where an uninstall takes its backups - is not the author either.
        $this->setUser(0);
        $this->assertArrayNotHasKey(exporter::VALIDATION_PATH, $this->build());
    }

    public function test_a_section_of_a_bloc_withdrawn_from_the_record_is_still_exported(): void {
        $this->write('bc10_decrire', 'Écrit avant le départ du bloc.');

        $this->setAdminUser();
        (new importer())->import((object) [
            'fichecode' => 'RS00000', 'fichetype' => 'rs', 'name' => 'Fiche RS00000',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-09-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv("rang,intitule,description,bloc\nBC02,Deuxième,,\nC02,Deux,,BC02\n"));
        validation_catalog::reset();
        $this->setUser($this->candidate);

        $document = json_decode($this->build()[exporter::VALIDATION_PATH], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(['bc10_decrire'], array_column($document['rubriques'], 'code'));
        $this->assertSame('Écrit avant le départ du bloc.', $document['rubriques'][0]['texte']);
    }

    public function test_the_printed_copy_says_what_it_is_and_carries_the_texts(): void {
        $files = $this->archive_with_sections();
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $validation = json_decode($files[exporter::VALIDATION_PATH], true, 512, JSON_THROW_ON_ERROR);
        $snapshot = (new exporter())->snapshot((int) $this->candidate->id);

        $html = pdf_renderer::html($manifest, $snapshot['preuves'], $snapshot['referentiel'], [], $validation);

        $this->assertStringContainsString(get_string('validationtitle', 'tool_vae'), $html);
        $this->assertStringContainsString('Nobody attests this text', $html);
        $this->assertStringContainsString('La synthèse de la fiche.', $html);
        $this->assertLessThan(strpos($html, 'Texte enregistré du bloc dix.'), strpos($html, 'Texte du bloc deux.'));
        $this->assertStringNotContainsString(
            get_string('validationtitle', 'tool_vae'),
            pdf_renderer::html($manifest, $snapshot['preuves'], $snapshot['referentiel'])
        );
    }

    public function test_the_word_processed_copy_carries_the_same_and_a_section_saved_again_makes_a_new_copy(): void {
        $this->archive_with_sections();
        $this->setUser($this->candidate);

        $first = dossier_document::for_candidate((int) $this->candidate->id, time());
        $text = self::docx_text($first['writer']->docx());
        $this->assertStringContainsString(get_string('validationtitle', 'tool_vae'), $text);
        $this->assertStringContainsString('Nobody attests this text', $text);
        $this->assertStringContainsString('La synthèse de la fiche.', $text);

        $same = dossier_document::for_candidate((int) $this->candidate->id, time() + 60);
        $this->assertSame($first['digest'], $same['digest'], 'The time of issue is not part of the digest.');

        (new draft_manager())->save_version(
            (int) (new draft_manager())->section(
                (int) $this->candidate->id,
                draft_manager::DOCTYPE_VALIDATION,
                '_synthese',
                0,
                'RS00000'
            )->id,
            'Une synthèse réécrite.'
        );
        $again = dossier_document::for_candidate((int) $this->candidate->id, time());
        $this->assertNotSame($first['digest'], $again['digest']);
    }

    public function test_the_import_brings_the_sections_back_as_version_one_with_a_note(): void {
        global $DB;
        $files = $this->archive_with_sections();

        purge::dossier((int) $this->candidate->id);
        $this->assertSame(0, $DB->count_records('tool_vae_draft'));
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(3, $result->validation);
        $this->assertSame(0, $result->validationgardees);
        $draft = $DB->get_record('tool_vae_draft', [
            'candidateid' => $this->candidate->id, 'doctype' => 'validation', 'fichecode' => 'RS00000', 'section' => 'bc10_decrire',
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int) $draft->currentversion);
        $version = $DB->get_record('tool_vae_version', ['draftid' => $draft->id, 'number' => 1], '*', MUST_EXIST);
        $this->assertSame('Texte enregistré du bloc dix.', $version->content);
        $this->assertStringContainsString('Restored from the archive', (string) $version->note);
        // And the second export says the same.
        $this->setUser($this->candidate);
        $again = json_decode($this->build()[exporter::VALIDATION_PATH], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['bc02_analyser', 'bc10_decrire', '_synthese'], array_column($again['rubriques'], 'code'));
    }

    public function test_a_section_already_written_here_is_left_as_it_is(): void {
        global $DB;
        $files = $this->archive_with_sections();

        // The dossier is lost but the section of the bloc ten, written again here, stays.
        $DB->delete_records('tool_vae_evidence', ['candidateid' => $this->candidate->id]);
        $DB->delete_records('tool_vae_attest');
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(0, $result->validation, 'Every section of the archive was already there.');
        $this->assertSame(3, $result->validationgardees);
        $this->assertSame(
            'Texte enregistré du bloc dix.',
            $DB->get_field_sql(
                "SELECT v.content FROM {tool_vae_version} v JOIN {tool_vae_draft} d ON d.id = v.draftid
                  WHERE d.section = 'bc10_decrire' AND v.number = d.currentversion"
            )
        );
    }

    public function test_a_record_this_site_does_not_hold_refuses_the_whole_archive(): void {
        $files = self::forge($this->archive_with_sections(), function (array $document): array {
            $document['rubriques'][0]['fiche'] = 'RNCP00000';

            return $document;
        });

        $this->assert_refused_after_destroy($files);
    }

    public function test_a_code_that_does_not_read_as_its_bloc_and_part_refuses_the_archive(): void {
        $files = self::forge($this->archive_with_sections(), function (array $document): array {
            $document['rubriques'][0]['bloc'] = 'BC99';

            return $document;
        });

        $this->assert_refused_after_destroy($files);
    }

    public function test_the_same_section_twice_refuses_the_archive(): void {
        $files = self::forge($this->archive_with_sections(), function (array $document): array {
            $document['rubriques'][] = $document['rubriques'][0];

            return $document;
        });

        $this->assert_refused_after_destroy($files);
    }

    public function test_a_file_the_schema_refuses_refuses_the_archive_even_with_a_matching_digest(): void {
        $files = self::forge($this->archive_with_sections(), function (array $document): array {
            $document['officiel'] = true;

            return $document;
        });

        $this->assert_refused_after_destroy($files);
    }
}
