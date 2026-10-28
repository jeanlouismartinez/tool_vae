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

use tool_vae\local\backup;
use tool_vae\local\document_copies;
use tool_vae\local\draft_manager;
use tool_vae\local\dossier_document;
use tool_vae\local\evidence_manager;
use tool_vae\local\faisabilite_catalog;
use tool_vae\local\identity_map;
use tool_vae\local\purge;

/**
 * The editable copies of a dossier, on demand and weekly (T-83, decision D-8).
 *
 * WHAT IS ASSERTED. What a copy says, that it lands in the candidate's own files with the mark,
 * that an unchanged dossier gets no new copy, that only the last two are kept, that a rename or
 * a move does not lose one, and that an erasure removes them without turning them into archives.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\document_copies::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_document::class)]
final class document_copies_test extends \advanced_testcase {
    /** @var int A day, in seconds. */
    private const DAY = 86400;

    /** @var int The role that lets a user declare. */
    private $candidaterole;

    /** @var int The competency of the record seeded in setUp(). */
    private $competencyid;

    /**
     * A record, and a role to declare with.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $generator->get_plugin_generator('tool_vae')->create_source([
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
        ]);
        $this->competencyid = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RNCP36832-C03'], MUST_EXIST);

        $this->candidaterole = $generator->create_role();
        foreach (['declare', 'viewown', 'draftown'] as $capability) {
            assign_capability(
                'tool/vae:' . $capability,
                CAP_ALLOW,
                $this->candidaterole,
                \context_system::instance()->id,
                true
            );
        }
    }

    /**
     * A candidate with one declaration.
     *
     * @param string $situation
     * @return \stdClass The candidate.
     */
    private function candidate_with_a_claim(string $situation = 'Accompagnement au lever.'): \stdClass {
        $candidate = $this->getDataGenerator()->create_user();
        role_assign($this->candidaterole, $candidate->id, \context_user::instance($candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($candidate);
        (new evidence_manager())->declare_activity((int) $candidate->id, (object) [
            'competencyid' => $this->competencyid,
            'periodstart' => 1756684800,
            'periodend' => 1782777600,
            'settingtype' => 'ehpad',
            'situation' => $situation,
            'situationformat' => FORMAT_PLAIN,
        ]);

        return $candidate;
    }

    /**
     * The copies held in a user's private files, whatever their folder.
     *
     * @param int $userid
     * @return \stored_file[]
     */
    private static function copies_of(int $userid): array {
        global $DB;

        $rows = $DB->get_records_select(
            'files',
            'contextid = :contextid AND component = :component AND filearea = :filearea AND '
                . $DB->sql_like('source', ':source'),
            [
                'contextid' => \context_user::instance($userid)->id,
                'component' => 'user',
                'filearea' => 'private',
                'source' => $DB->sql_like_escape(document_copies::SOURCE_PREFIX) . '%',
            ]
        );
        $fs = get_file_storage();

        return array_values(array_map(function ($row) use ($fs) {
            return $fs->get_file_instance($row);
        }, $rows));
    }

    /**
     * The text of the main part of a .docx.
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
     * The copy says what the dossier says: heading, record, declaration, situation.
     */
    public function test_the_copy_carries_the_declaration_and_says_what_it_is_not(): void {
        $candidate = $this->candidate_with_a_claim("Premier paragraphe.\n\nSecond paragraphe.");

        $document = dossier_document::for_candidate((int) $candidate->id, 1790000000);
        $text = self::docx_text($document['writer']->docx());

        // The strings are asked in the language in force: the test site has the English pack only.
        $this->assertStringContainsString(get_string('exportpdftitle', 'tool_vae', fullname($candidate)), $text);
        $this->assertStringContainsString('RNCP36832', $text);
        $this->assertStringContainsString('C03', $text);
        $this->assertStringContainsString('Premier paragraphe.', $text);
        $this->assertStringContainsString('Second paragraphe.', $text);
        $this->assertStringContainsString(get_string('documentnotice', 'tool_vae'), $text);
        $this->assertSame(1, $document['declarations']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $document['digest']);
    }

    /**
     * Audit 53: the name of the platform is written as text, not as the HTML format_string() answers.
     *
     * Before: format_string() turned the ampersand into an entity, which the writer then escaped for XML a second time -
     * Word and LibreOffice showed "Fil d'argent &amp; Cie" in the line of the platform, in the .docx and in the .odt.
     */
    public function test_the_platform_name_is_written_as_text_not_as_html(): void {
        global $DB, $SITE;

        $DB->set_field('course', 'fullname', "Fil d'argent & Cie", ['id' => $SITE->id]);
        $SITE->fullname = "Fil d'argent & Cie";
        $candidate = $this->candidate_with_a_claim();

        $writer = dossier_document::for_candidate((int) $candidate->id, 1790000000)['writer'];

        $this->assertStringContainsString(
            get_string('exportpdfplatform', 'tool_vae') . " : Fil d'argent & Cie - ",
            self::docx_text($writer->docx())
        );

        $path = make_request_directory() . '/x.odt';
        file_put_contents($path, $writer->odt());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $content = (string) $zip->getFromName('content.xml');
        $zip->close();
        $this->assertStringContainsString('argent &amp; Cie', $content);
        $this->assertStringNotContainsString('&amp;amp;', $content, 'The ampersand is escaped once.');
    }

    /**
     * Save a version of a section as the candidate.
     *
     * @param \stdClass $candidate
     * @param string $section
     * @param string $content
     * @param int $slot
     */
    private function save_section(\stdClass $candidate, string $section, string $content, int $slot = 0): void {
        $this->setUser($candidate);
        $manager = new draft_manager();
        $draft = $manager->section((int) $candidate->id, draft_manager::DOCTYPE_FAISABILITE, $section, $slot);
        $manager->save_version((int) $draft->id, $content);
    }

    /**
     * The sections come first, in the order of the annex, from the latest saved version.
     */
    public function test_the_sections_are_written_from_the_saved_versions_in_the_order_of_the_annex(): void {
        $candidate = $this->candidate_with_a_claim();
        $this->save_section($candidate, 'parcours', 'Mon parcours prévu.');
        $this->save_section($candidate, 'objectifs', 'Premier objectif.');
        $this->save_section($candidate, 'objectifs', 'Objectif corrigé.');
        $this->save_section($candidate, 'experiences', 'Ma deuxième expérience.', 2);
        $manager = new draft_manager();
        $unsaved = $manager->section((int) $candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'prerequis');
        $manager->save_working((int) $unsaved->id, 'Un brouillon jamais enregistré.');

        $text = self::docx_text(dossier_document::for_candidate((int) $candidate->id, 1790000000)['writer']->docx());

        $this->assertStringContainsString(get_string('faisabheading', 'tool_vae'), $text);
        $this->assertStringContainsString('Objectif corrigé.', $text);
        $this->assertStringNotContainsString('Premier objectif.', $text, 'Only the latest version.');
        $this->assertStringNotContainsString('Un brouillon jamais enregistré.', $text, 'A draft is not in the copy.');
        $this->assertStringContainsString('Ma deuxième expérience.', $text);
        $this->assertStringContainsString(faisabilite_catalog::label('experiences', 2), $text);
        $this->assertLessThan(
            strpos($text, 'Ma deuxième expérience.'),
            strpos($text, 'Objectif corrigé.'),
            'The annex puts the objectives before the experiences.'
        );
        $this->assertLessThan(
            strpos($text, 'Mon parcours prévu.'),
            strpos($text, 'Ma deuxième expérience.')
        );
        $this->assertLessThan(
            strpos($text, 'Accompagnement au lever.'),
            strpos($text, 'Mon parcours prévu.'),
            'The sections come before the declarations.'
        );
    }

    /**
     * Identity, contact and status are written for whoever may read them, and left out, saying so, for the others.
     */
    public function test_identity_is_written_only_when_the_caller_says_so(): void {
        $candidate = $this->candidate_with_a_claim();
        $this->save_section($candidate, 'identite', '{"nom_naissance":"Martin","nationalite":"FR"}');
        $this->save_section($candidate, 'statut', '{"statut":["benevole"],"convention":"1486"}');
        $id = (int) $candidate->id;

        $with = self::docx_text(dossier_document::for_candidate($id, 1790000000, true)['writer']->docx());
        $this->assertStringContainsString('Martin', $with);
        $this->assertStringContainsString($candidate->email, $with);
        $this->assertStringContainsString('1486', $with);
        $this->assertStringContainsString(get_string('faisabopt:statut_benevole', 'tool_vae'), $with);
        $this->assertStringNotContainsString(get_string('documentidentityomitted', 'tool_vae'), $with);

        $without = self::docx_text(dossier_document::for_candidate($id, 1790000000, false)['writer']->docx());
        $this->assertStringNotContainsString('Martin', $without);
        $this->assertStringNotContainsString($candidate->email, $without);
        $this->assertStringNotContainsString('1486', $without);
        $this->assertStringContainsString(get_string('documentidentityomitted', 'tool_vae'), $without);
    }

    /**
     * A changed section, or a changed profile, makes a new digest; the same state, the same one.
     */
    public function test_the_digest_follows_the_sections_and_the_account(): void {
        global $DB;

        $candidate = $this->candidate_with_a_claim();
        $id = (int) $candidate->id;
        $this->save_section($candidate, 'objectifs', 'Un objectif.');

        $first = dossier_document::for_candidate($id, 1790000000, true)['digest'];
        $this->assertSame($first, dossier_document::for_candidate($id, 1790000000 + self::DAY, true)['digest']);
        $this->assertNotSame($first, dossier_document::for_candidate($id, 1790000000, false)['digest']);

        $DB->set_field('user', 'city', 'Rennes', ['id' => $id]);
        $this->assertNotSame($first, dossier_document::for_candidate($id, 1790000000, true)['digest']);

        $this->save_section($candidate, 'objectifs', 'Un autre objectif.');
        $this->assertNotSame($first, dossier_document::for_candidate($id, 1790000000, false)['digest']);
    }

    /**
     * The digest ignores the day, so that the weekly task can tell a dossier that did not change.
     */
    public function test_the_digest_does_not_depend_on_the_day(): void {
        $candidate = $this->candidate_with_a_claim();

        $first = dossier_document::for_candidate((int) $candidate->id, 1790000000);
        $second = dossier_document::for_candidate((int) $candidate->id, 1790000000 + 30 * self::DAY);

        $this->assertSame($first['digest'], $second['digest']);
    }

    /**
     * A copy lands in the candidate's own private files, marked, named by the date.
     */
    public function test_a_copy_lands_in_the_candidates_private_files_with_the_mark(): void {
        $candidate = $this->candidate_with_a_claim();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $candidate->id);

        $file = document_copies::take((int) $candidate->id, 'docx', 1790000000);

        $this->assertNotNull($file);
        $this->assertSame(\context_user::instance((int) $candidate->id)->id, $file->get_contextid());
        $this->assertSame('/vae/copies/', $file->get_filepath());
        $this->assertSame('dossier-' . gmdate('Y-m-d', 1790000000) . '.docx', $file->get_filename());
        $this->assertStringStartsWith(document_copies::SOURCE_PREFIX . $personne . ':', $file->get_source());
        $this->assertStringContainsString('Accompagnement au lever.', self::docx_text($file->get_content()));
    }

    /**
     * The ODT format is written and kept the same way.
     */
    public function test_an_odt_copy_is_written(): void {
        $candidate = $this->candidate_with_a_claim();

        $file = document_copies::take((int) $candidate->id, 'odt', 1790000000);

        $this->assertSame('.odt', substr($file->get_filename(), -4));
        $this->assertStringStartsWith('PK', $file->get_content());
        $this->assertStringContainsString('application/vnd.oasis.opendocument.text', $file->get_content());
    }

    /**
     * An unchanged dossier gets no new copy; a changed one does; only the last two are kept.
     */
    public function test_an_unchanged_dossier_gets_no_copy_and_only_two_copies_are_kept(): void {
        global $DB;

        $candidate = $this->candidate_with_a_claim();
        $id = (int) $candidate->id;
        $day = 1790000000;

        $this->assertNotNull(document_copies::take($id, 'docx', $day));
        $this->assertNull(document_copies::take($id, 'docx', $day + 7 * self::DAY), 'Nothing changed.');
        $this->assertCount(1, self::copies_of($id));

        foreach ([2, 3] as $week) {
            $DB->set_field('tool_vae_evidence', 'situation', 'Version ' . $week, ['candidateid' => $id]);
            $this->assertNotNull(document_copies::take($id, 'docx', $day + 7 * $week * self::DAY));
        }

        $kept = self::copies_of($id);
        $this->assertCount(document_copies::KEEP, $kept);
        $names = array_map(function ($file) {
            return $file->get_filename();
        }, $kept);
        sort($names);
        $this->assertSame([
            'dossier-' . gmdate('Y-m-d', $day + 14 * self::DAY) . '.docx',
            'dossier-' . gmdate('Y-m-d', $day + 21 * self::DAY) . '.docx',
        ], $names, 'The oldest went.');
    }

    /**
     * A copy in the other format does not stand for the one the setting asks for.
     */
    public function test_a_copy_in_another_format_does_not_count_as_unchanged(): void {
        $candidate = $this->candidate_with_a_claim();
        $id = (int) $candidate->id;

        document_copies::take($id, 'docx', 1790000000);

        $this->assertNotNull(document_copies::take($id, 'odt', 1790000000 + 7 * self::DAY));
        $this->assertNull(document_copies::take($id, 'odt', 1790000000 + 14 * self::DAY));
    }

    /**
     * The weekly run: off writes nothing; on writes one per changed dossier, then none.
     */
    public function test_the_weekly_run_is_off_by_default_and_then_writes_once(): void {
        $candidate = $this->candidate_with_a_claim();
        $id = (int) $candidate->id;
        $this->candidate_with_a_claim();

        $this->assertFalse(document_copies::enabled());
        $this->assertSame(['written' => 0, 'unchanged' => 0, 'failed' => 0], document_copies::run(1790000000));
        $this->assertCount(0, self::copies_of($id));

        set_config('copyenabled', 1, 'tool_vae');
        $this->assertSame('docx', document_copies::format());
        $this->assertSame(['written' => 2, 'unchanged' => 0, 'failed' => 0], document_copies::run(1790000000));
        $this->assertSame(
            ['written' => 0, 'unchanged' => 2, 'failed' => 0],
            document_copies::run(1790000000 + 7 * self::DAY)
        );
        $this->assertCount(1, self::copies_of($id));

        set_config('copyformat', 'odt', 'tool_vae');
        $this->assertSame('odt', document_copies::format());
        set_config('copyformat', 'rtf', 'tool_vae');
        $this->assertSame('docx', document_copies::format(), 'An unknown format falls back.');
    }

    /**
     * A renamed and moved copy is still found, and an erasure removes it.
     */
    public function test_a_renamed_copy_is_found_and_an_erasure_removes_it(): void {
        $candidate = $this->candidate_with_a_claim();
        $id = (int) $candidate->id;
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, $id);

        $file = document_copies::take($id, 'docx', 1790000000);
        $file->rename('/ailleurs/', 'mon-dossier.docx');

        $this->assertCount(1, self::copies_of($id));
        $this->assertSame(0, backup::discard($personne), 'A backup is not a copy.');
        $this->assertCount(1, self::copies_of($id), 'The backups left the copy alone.');

        purge::dossier($id);

        $this->assertCount(0, self::copies_of($id));
    }

    /**
     * When a signatory is stripped from a dossier, its copies are written again, in the same format.
     *
     * The old ones carry the signed statement; refresh() drops them before taking the new one, as
     * backup::refresh() does. A candidate who never had a copy gets none.
     */
    public function test_a_refresh_replaces_the_copies_and_keeps_the_format(): void {
        global $DB;

        $candidate = $this->candidate_with_a_claim('Avant.');
        $stranger = $this->candidate_with_a_claim();
        $id = (int) $candidate->id;

        document_copies::take($id, 'odt', 1790000000);
        $DB->set_field('tool_vae_evidence', 'situation', 'Apres.', ['candidateid' => $id]);

        $this->assertSame(1, document_copies::refresh($id));
        $copies = self::copies_of($id);
        $this->assertCount(1, $copies);
        $this->assertSame('.odt', substr($copies[0]->get_filename(), -4));
        $this->assertStringContainsString('application/vnd.oasis.opendocument.text', $copies[0]->get_content());

        $this->assertSame(0, document_copies::refresh((int) $stranger->id), 'No copy, none made.');
        $this->assertCount(0, self::copies_of((int) $stranger->id));
    }

    /**
     * The daily reconciliation removes the copies of a candidate this platform no longer knows.
     */
    public function test_orphaned_copies_are_deleted_and_the_others_kept(): void {
        global $DB;

        $gone = $this->candidate_with_a_claim();
        $kept = $this->candidate_with_a_claim();
        document_copies::take((int) $gone->id, 'docx', 1790000000);
        document_copies::take((int) $kept->id, 'docx', 1790000000);

        $DB->delete_records('tool_vae_identity', ['itemtype' => identity_map::TYPE_USER, 'itemid' => (int) $gone->id]);

        $this->assertSame(1, document_copies::discard_orphans());
        $this->assertCount(0, self::copies_of((int) $gone->id));
        $this->assertCount(1, self::copies_of((int) $kept->id));
    }

    /**
     * A dossier with no declaration has no copy to make.
     */
    public function test_a_dossier_without_declaration_is_refused(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->expectException(\moodle_exception::class);
        document_copies::take((int) $user->id, 'docx', 1790000000);
    }

    /**
     * An unknown format is a coding error, not a silent default.
     */
    public function test_an_unknown_format_is_refused(): void {
        $candidate = $this->candidate_with_a_claim();

        $this->expectException(\coding_exception::class);
        document_copies::take((int) $candidate->id, 'rtf', 1790000000);
    }
}
