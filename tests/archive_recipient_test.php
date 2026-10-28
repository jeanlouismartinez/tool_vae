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

use tool_vae\event\dossier_exported;
use tool_vae\local\backup;
use tool_vae\local\display_date;
use tool_vae\local\document_copies;
use tool_vae\local\document_writer;
use tool_vae\local\dossier_document;
use tool_vae\local\draft_manager;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\piece_manager;
use tool_vae\local\validation_catalog;

/**
 * Who an archive or a copy is FOR, and who made it, are said by the caller and never read from the session (audit of
 * 26 October 2026, group B1).
 *
 * WHAT IS ASSERTED. That the backup rewritten in the request of the candidate who removes a piece carries neither their
 * journal nor the preparation of their validation dossier, and is signed by the account that holds it; that no backup carries
 * them, whoever triggers it - the candidate, an administrator, nobody (the command line); that the author's own export still
 * does; that the copy kept in the candidate's files, written by a task that runs as the main administrator or refreshed by a
 * manager, carries the preparation of the validation dossier; that a reader who may not read the saved sections of the dossier
 * de faisabilite, nor open the pieces, carries neither out in an export or a copy; that the dates are the candidate's, whoever
 * reads; and that the editable copy keeps the hierarchy of its headings.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\backup::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_document::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\document_copies::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\display_date::class)]
final class archive_recipient_test extends \advanced_testcase {
    /** @var string A text the candidate wrote in the journal and told nobody. */
    private const JOURNAL = 'Un texte tres personnel.';

    /** @var string A section of the preparation of the validation dossier, saved and never deposited. */
    private const PREPARATION = 'Enregistré, jamais déposé.';

    /** @var string A saved section of the dossier de faisabilité. */
    private const SECTION = 'Mon objectif de formation.';

    /** @var string The title of a piece, which names someone as real ones do. */
    private const TITLE = 'Contrat Mme Durand EHPAD Les Lilas';

    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Follows the candidate and may attest: may open the pieces. */
    private $manager;

    /** @var \stdClass Reads and exports every dossier, and nothing else: no draftview, no identityview. */
    private $direction;

    /** @var \stdClass The direction of the user guide: also draftview and identityview, and not attest. */
    private $fulldirection;

    /** @var \stdClass Holds nothing. */
    private $stranger;

    /** @var int The declaration. */
    private $evidenceid;

    /**
     * A record with two blocs, a candidate with a declaration, and everything open.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;

        $this->resetAfterTest();
        // The pieces are added under a lock, which a transaction a test rolls back would hold.
        $this->preventResetByRollback();
        validation_catalog::reset();
        foreach (['validationopen', 'reflectionopen', 'piecesopen', 'piecenoantivirus'] as $name) {
            set_config($name, 1, 'tool_vae');
        }
        $this->setAdminUser();

        (new importer())->import((object) [
            'fichecode' => 'RS00000', 'fichetype' => 'rs', 'name' => 'Fiche RS00000',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv(
            "rang,intitule,description,bloc\nBC10,Dixième,,\nC10,Une,,BC10\nBC02,Deuxième,,\nC02,Deux,,BC02\n"
        ));

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;

        $role = $generator->create_role();
        foreach (['declare', 'viewown', 'draftown', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $role, $system, true);
        }
        $this->candidate = $generator->create_user(['firstname' => 'Camille', 'lastname' => 'Martin']);
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);

        $managerrole = $generator->create_role();
        foreach (['viewassigned', 'attest'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $managerrole, $system, true);
        }
        set_role_contextlevels($managerrole, [CONTEXT_USER]);
        $this->manager = $generator->create_user();
        role_assign($managerrole, $this->manager->id, \context_user::instance($this->candidate->id)->id);

        // The direction of the README reads and exports every dossier; the one of the user guide holds more.
        $this->direction = $generator->create_user();
        $this->fulldirection = $generator->create_user();
        $directions = [
            [$this->direction, ['viewany', 'export']],
            [$this->fulldirection, ['viewany', 'export', 'draftview', 'identityview']],
        ];
        foreach ($directions as [$user, $capabilities]) {
            $directionrole = $generator->create_role();
            foreach ($capabilities as $capability) {
                assign_capability('tool/vae:' . $capability, CAP_ALLOW, $directionrole, $system, true);
            }
            role_assign($directionrole, $user->id, $system);
        }
        $this->stranger = $generator->create_user();
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->candidate);
        $competency = $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-C02'], MUST_EXIST);
        $evidence = (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $competency, 'periodstart' => 1756684800, 'periodend' => 1782777600,
            'settingtype' => 'ehpad', 'situation' => 'Une situation.', 'situationformat' => FORMAT_PLAIN,
        ]);
        $this->evidenceid = (int) $DB->get_field('tool_vae_evidence', 'id', ['uuid' => $evidence->uuid], MUST_EXIST);
    }

    /**
     * Give the timezone back, whatever a test left forced.
     *
     * display_date::$forced is a static of the process, and resetAfterTest() does not reach it. A test that fails between
     * forcing a timezone and giving it back would leave it forced for every test that follows in the same PHPUnit process,
     * and their failures would hide the one that caused it.
     */
    protected function tearDown(): void {
        display_date::force_timezone(null);
        parent::tearDown();
    }

    /**
     * Attach a link as the candidate.
     *
     * @param string $title
     * @return \stdClass The piece.
     */
    private function link(string $title = 'Mon diplome'): \stdClass {
        $this->setUser($this->candidate);

        return (new piece_manager())->add_link($this->evidenceid, 'diploma', $title, 'https://exemple.test/diplome/secret-1');
    }

    /**
     * Save a version of the journal, as the candidate.
     *
     * @param string $text
     */
    private function journal(string $text): void {
        $this->setUser($this->candidate);
        $manager = new draft_manager();
        $draft = $manager->section(
            (int) $this->candidate->id,
            draft_manager::DOCTYPE_REFLEXION,
            draft_manager::REFLEXION_SECTION,
            1
        );
        $manager->save_version((int) $draft->id, $text);
    }

    /**
     * Save a version of a section of the preparation of the validation dossier, as the candidate.
     *
     * @param string $section
     * @param string $text
     */
    private function preparation(string $section, string $text): void {
        $this->setUser($this->candidate);
        $manager = new draft_manager();
        $draft = $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_VALIDATION, $section, 0, 'RS00000');
        $manager->save_version((int) $draft->id, $text);
    }

    /**
     * Save a version of a section of the dossier de faisabilité, as the candidate.
     *
     * @param string $section
     * @param string $text
     */
    private function section(string $section, string $text): void {
        $this->setUser($this->candidate);
        $manager = new draft_manager();
        $draft = $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, $section, 0);
        $manager->save_version((int) $draft->id, $text);
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
                $files[$name] = (string) $zip->getFromIndex($index);
            }
        }
        $zip->close();
        unlink($path);

        return $files;
    }

    /**
     * The backups held in a user's private files.
     *
     * @param int $userid
     * @return \stored_file[]
     */
    private static function backups_of(int $userid): array {
        return array_values(get_file_storage()->get_directory_files(
            \context_user::instance($userid)->id,
            'user',
            'private',
            0,
            backup::FILEPATH,
            false,
            false
        ));
    }

    /**
     * A part of a .docx, raw.
     *
     * @param string $bytes
     * @param string $part
     * @return string
     */
    private static function docx_part(string $bytes, string $part = 'word/document.xml'): string {
        $path = make_request_directory() . '/x.docx';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $xml = (string) $zip->getFromName($part);
        $zip->close();

        return $xml;
    }

    /**
     * The text of a .docx, paragraphs on lines.
     *
     * @param string $bytes
     * @return string
     */
    private static function docx_text(string $bytes): string {
        $xml = self::docx_part($bytes);

        return html_entity_decode(strip_tags(str_replace('</w:p>', "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Assert that nothing of the journal and of the preparation is in an archive.
     *
     * @param string[] $files Path => content.
     * @param string $why
     */
    private function assert_no_journal_nor_preparation(array $files, string $why): void {
        $this->assertArrayNotHasKey(exporter::REFLEXION_PATH, $files, $why);
        $this->assertArrayNotHasKey(exporter::VALIDATION_PATH, $files, $why);
        $this->assertArrayNotHasKey('schema/reflexion.schema.json', $files, $why);
        $this->assertArrayNotHasKey('schema/validation.schema.json', $files, $why);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('schema_reflexion', $manifest, $why);
        $this->assertArrayNotHasKey('schema_validation', $manifest, $why);
        $this->assertStringNotContainsString('tres personnel', implode('', $files), $why);
        $this->assertStringNotContainsString('jamais déposé', implode('', $files), $why);
    }

    /**
     * The scenario of the audit: the administrator holds a backup, the candidate writes, then removes a piece.
     *
     * The removal is the candidate's own act, in the candidate's session; it asks for the backups of the dossier to be written
     * again - backup::refresh() - in the private files of whoever held one.
     *
     * @return array [the administrator's id, the backups the administrator holds now, the dossier_exported events raised]
     */
    private function backup_rewritten_by_a_piece_removal(): array {
        $piece = $this->link();
        $this->journal(self::JOURNAL);
        $this->preparation('bc10_decrire', self::PREPARATION);

        $this->setAdminUser();
        $admin = (int) get_admin()->id;
        $first = self::unzip(backup::save((int) $this->candidate->id, $admin));
        // The premise: taken by the administrator, a backup carries neither.
        $this->assert_no_journal_nor_preparation($first, 'The premise: the first backup is the administrator\'s own.');

        $sink = $this->redirectEvents();
        $this->setUser($this->candidate);
        (new piece_manager())->remove((int) $piece->id);
        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof dossier_exported));
        $sink->close();

        return [$admin, self::backups_of($admin), $events];
    }

    /**
     * Audit 1 and 5: a backup rewritten in the candidate's request carries nothing of their journal and their preparation.
     *
     * Before: assemble() asked the session whether to carry them (document_for_owner(), document_for_viewer()), and in the
     * request where the candidate removes a piece the session IS the candidate: the rewritten backup, written into the
     * private files of the administrator, held reflexion.json and validation.json, for good.
     */
    public function test_a_backup_rewritten_in_the_candidates_own_request_carries_neither_journal_nor_preparation(): void {
        [$admin, $backups, $events] = $this->backup_rewritten_by_a_piece_removal();

        $this->assertCount(1, $backups, 'The old backup went and one new one took its place.');
        $this->assert_no_journal_nor_preparation(self::unzip($backups[0]), 'Rewritten in the candidate\'s request.');
    }

    /**
     * Audit 38: the backup rewritten in the candidate's request is the holder's, in the register, the file and the log.
     *
     * Before: store() wrote the current user, the candidate, as the producer of an archive that sits in the administrator's
     * files - in tool_vae_archive.userid, in files.userid and as the author of the dossier_exported event.
     */
    public function test_a_backup_rewritten_in_the_candidates_own_request_is_signed_by_its_holder(): void {
        [$admin, $backups, $events] = $this->backup_rewritten_by_a_piece_removal();

        $this->assertCount(1, $backups);
        $this->assertSame($admin, (int) $backups[0]->get_userid(), 'The file.');
        $this->assertSame($admin, (int) exporter::registered($backups[0])->userid, 'The register.');
        $this->assertCount(1, $events);
        $this->assertSame($admin, (int) $events[0]->userid, 'The event.');
        $this->assertSame((int) $this->candidate->id, (int) $events[0]->relateduserid, 'About the candidate\'s dossier.');
    }

    /**
     * Audit 1, 5 and 35: whoever triggers it, nothing - the candidate, an administrator, a stranger, nobody - a backup never
     * carries the journal nor the preparation.
     *
     * Before: the candidate's session put both in, and the command line ($USER->id = 0), where the uninstall runs, put the
     * preparation in: validation_overview::document_for_viewer() answered yes to "nobody in particular".
     */
    public function test_a_backup_never_carries_the_journal_nor_the_preparation_whoever_triggers_it(): void {
        $this->journal(self::JOURNAL);
        $this->preparation('bc10_decrire', self::PREPARATION);
        $this->setAdminUser();
        $admin = (int) get_admin()->id;

        $triggers = ['the candidate' => $this->candidate, 'an administrator' => get_admin(), 'a stranger' => $this->stranger];
        foreach ($triggers as $who => $user) {
            $this->setUser($user);
            $files = self::unzip(backup::save((int) $this->candidate->id, $admin));
            $this->assert_no_journal_nor_preparation($files, 'Triggered by ' . $who . '.');
        }

        $this->setUser(0);
        $files = self::unzip(backup::save((int) $this->candidate->id, $admin));
        $this->assert_no_journal_nor_preparation($files, 'Triggered by nobody: the command line.');
    }

    /**
     * Audit 1 and 5: the archive the author takes away still carries both, and the direction's export carries neither.
     *
     * Not a case the old code got wrong: it holds the line the fix must not cross.
     */
    public function test_the_authors_own_export_carries_the_journal_and_the_preparation_and_the_directions_does_not(): void {
        $this->journal(self::JOURNAL);
        $this->preparation('bc10_decrire', self::PREPARATION);
        $id = (int) $this->candidate->id;

        $this->setUser($this->candidate);
        $files = self::unzip((new exporter())->export($id));
        $this->assertArrayHasKey(exporter::REFLEXION_PATH, $files);
        $this->assertArrayHasKey(exporter::VALIDATION_PATH, $files);
        $this->assertStringNotContainsString(
            get_string('exportreadmeabsentauthor', 'tool_vae'),
            $files['LISEZMOI.txt'],
            'An archive that carries them does not say it does not.'
        );

        $this->setUser($this->direction);
        $files = self::unzip((new exporter())->export($id));
        $this->assert_no_journal_nor_preparation($files, 'Exported by the direction.');
        $this->assertStringContainsString(get_string('exportreadmeabsentauthor', 'tool_vae'), $files['LISEZMOI.txt']);
    }

    /**
     * Audit 35: the weekly copy, written by a task that runs as the main administrator, carries the preparation.
     *
     * Before: for_candidate() asked validation_overview::document_for_viewer(), which answers yes to the candidate and to
     * nobody - and \core\cron::setup_user() makes the user the administrator, never nobody: the copy kept in the candidate's
     * own files never held the section, and a section saved again never made a new copy, the digest being the same.
     */
    public function test_the_weekly_copy_carries_the_preparation_whoever_runs_it(): void {
        $id = (int) $this->candidate->id;
        $this->preparation('_synthese', 'Ma synthèse enregistrée.');

        // What \core\cron::setup_user() does before a scheduled task.
        $this->setAdminUser();
        $file = document_copies::take($id, 'docx', 1790000000, true);
        $this->assertNotNull($file);
        $text = self::docx_text($file->get_content());
        $this->assertStringContainsString(get_string('validationtitle', 'tool_vae'), $text);
        $this->assertStringContainsString('Ma synthèse enregistrée.', $text);

        $this->assertNull(document_copies::take($id, 'docx', 1790000000 + 7 * DAYSECS, true), 'Nothing changed.');

        $this->preparation('_synthese', 'Ma synthèse corrigée.');
        $this->setAdminUser();
        $again = document_copies::take($id, 'docx', 1790000000 + 14 * DAYSECS, true);
        $this->assertNotNull($again, 'A section saved again makes a new weekly copy.');
        $this->assertStringContainsString('Ma synthèse corrigée.', self::docx_text($again->get_content()));
    }

    /**
     * Audit 35: a copy refreshed in a manager's session keeps the preparation the candidate's copy had.
     *
     * Before: refresh() takes the copy again as whoever runs it; a manager is not the candidate, so the new copy, which
     * replaces the candidate's own, came out without the section.
     */
    public function test_a_copy_refreshed_in_another_session_keeps_the_preparation(): void {
        $id = (int) $this->candidate->id;
        $this->preparation('_synthese', 'Ma synthèse enregistrée.');
        $this->setUser($this->candidate);
        $this->assertNotNull(document_copies::take($id, 'docx', 1790000000));

        $this->setUser($this->manager);
        $this->assertSame(1, document_copies::refresh($id));

        $copies = get_file_storage()->get_directory_files(
            \context_user::instance($id)->id,
            'user',
            'private',
            0,
            document_copies::FILEPATH,
            false,
            false
        );
        $this->assertCount(1, $copies);
        $this->assertStringContainsString('Ma synthèse enregistrée.', self::docx_text(reset($copies)->get_content()));
    }

    /**
     * Audit 35: a download by the direction does not carry the preparation, which only its author reads.
     *
     * The line the fix must not cross; the direction's copy comes from document.php, which says it is not for the author.
     */
    public function test_a_copy_for_a_reader_who_is_not_the_author_leaves_the_preparation_out(): void {
        $id = (int) $this->candidate->id;
        $this->preparation('_synthese', 'Ma synthèse enregistrée.');

        foreach ([$this->fulldirection, get_admin()] as $reader) {
            $this->setUser($reader);
            $text = self::docx_text(dossier_document::for_candidate($id, 1790000000, false, null, false)['writer']->docx());
            $this->assertStringNotContainsString('Ma synthèse enregistrée.', $text);
        }
        $this->setUser($this->candidate);
        $text = self::docx_text(dossier_document::for_candidate($id, 1790000000, true, null, true)['writer']->docx());
        $this->assertStringContainsString('Ma synthèse enregistrée.', $text);
    }

    /**
     * Audit 33: a reader who holds viewany and export but not draftview does not carry the saved sections out.
     *
     * Before: exporter::faisabilite() read the saved sections for whoever exports, while history.php refuses them the same
     * sections on the screen (draft_manager::can_read()): the direction of the README read in a file what it cannot read
     * on the platform.
     */
    public function test_a_reader_without_draftview_does_not_carry_the_saved_sections_out_of_an_export(): void {
        $id = (int) $this->candidate->id;
        $this->section('objectifs', self::SECTION);

        $this->setUser($this->direction);
        $files = self::unzip((new exporter())->export($id));
        $this->assertArrayNotHasKey(exporter::FAISABILITE_PATH, $files);
        $this->assertArrayNotHasKey('schema/faisabilite.schema.json', $files);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('schema_faisabilite', $manifest);
        $this->assertStringNotContainsString('objectif de formation', implode('', $files));

        // The same reader, with the right the screen asks, or the administrator, or the author: the sections go in.
        $readers = ['draftview' => $this->fulldirection, 'the administrator' => get_admin(), 'the author' => $this->candidate];
        foreach ($readers as $who => $user) {
            $this->setUser($user);
            $files = self::unzip((new exporter())->export($id));
            $this->assertArrayHasKey(exporter::FAISABILITE_PATH, $files, $who);
            $document = json_decode($files[exporter::FAISABILITE_PATH], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(self::SECTION, $document['rubriques'][0]['texte'], $who);
        }
    }

    /**
     * Audit 33: nor does the editable copy of such a reader; the copy kept in the candidate's files and the backup keep them.
     */
    public function test_a_reader_without_draftview_carries_no_saved_section_out_of_a_copy_and_the_backup_keeps_them(): void {
        $id = (int) $this->candidate->id;
        $this->section('objectifs', self::SECTION);

        $this->setUser($this->direction);
        $text = self::docx_text(dossier_document::for_candidate($id, 1790000000)['writer']->docx());
        $this->assertStringNotContainsString(self::SECTION, $text);

        $this->setUser($this->fulldirection);
        $text = self::docx_text(dossier_document::for_candidate($id, 1790000000)['writer']->docx());
        $this->assertStringContainsString(self::SECTION, $text);

        // The copy kept in the candidate's own files, whoever writes it, and the backup, which is the safety net of an
        // uninstall: both keep every saved section.
        $this->setUser($this->direction);
        $copy = document_copies::take($id, 'docx', 1790000000);
        $this->assertStringContainsString(self::SECTION, self::docx_text($copy->get_content()));
        $this->setAdminUser();
        $files = self::unzip(backup::save($id, (int) get_admin()->id));
        $this->assertArrayHasKey(exporter::FAISABILITE_PATH, $files);
    }

    /**
     * Audit 34: the editable copy gives the title of a piece to whoever may open the piece, as the screen does.
     *
     * Before: the title was written for whoever reads the identity (draft_manager::can_read_fields()), which the direction of
     * the user guide does - viewany, export, draftview, identityview, and no right to attest - and which may not open a piece:
     * piece.php shows that reader the line without the title, and the archive they export carries none.
     */
    public function test_the_copy_gives_the_title_of_a_piece_to_whoever_may_open_it_and_not_to_the_direction(): void {
        $id = (int) $this->candidate->id;
        $piece = $this->link(self::TITLE);

        $this->setUser($this->fulldirection);
        $this->assertTrue(draft_manager::can_read_fields($id), 'The premise: they read the identity.');
        $this->assertFalse(piece_manager::may_open($piece), 'The premise: they may not open the piece.');
        $text = self::docx_text(
            dossier_document::for_candidate($id, 1790000000, draft_manager::can_read_fields($id))['writer']->docx()
        );
        $this->assertStringNotContainsString(self::TITLE, $text);
        $this->assertStringContainsString(get_string('piecenature:diploma', 'tool_vae'), $text, 'Listed all the same.');

        // The reader the screen shows the title to, though they may not export and so reach no copy: the rule is the screen's.
        $this->setUser($this->manager);
        $text = self::docx_text(dossier_document::for_candidate($id, 1790000000, false)['writer']->docx());
        $this->assertStringContainsString(self::TITLE, $text);

        $this->setUser($this->candidate);
        $text = self::docx_text(dossier_document::for_candidate($id, 1790000000, true)['writer']->docx());
        $this->assertStringContainsString(self::TITLE, $text);

        // The copy kept in the candidate's own files is theirs, whoever writes it.
        $this->setAdminUser();
        $copy = document_copies::take($id, 'docx', 1790000000 + DAYSECS);
        $this->assertStringContainsString(self::TITLE, self::docx_text($copy->get_content()));
    }

    /**
     * Audit 34: the rule the copy applies is piece_manager::may_open()'s, for every kind of reader.
     */
    public function test_the_rule_of_the_copy_is_the_one_of_the_screen(): void {
        $piece = $this->link(self::TITLE);
        $id = (int) $this->candidate->id;

        $expected = [
            'the candidate' => [$this->candidate, true],
            'the manager who follows them' => [$this->manager, true],
            'the administrator' => [get_admin(), true],
            'the direction' => [$this->fulldirection, false],
            'a stranger' => [$this->stranger, false],
        ];
        foreach ($expected as $who => [$user, $may]) {
            $this->assertSame($may, piece_manager::may_open($piece, (int) $user->id), $who . ' (the screen)');
            $this->assertSame($may, dossier_document::may_open_pieces_of($id, (int) $user->id), $who . ' (the copy)');
        }
        $this->assertFalse(dossier_document::may_open_pieces_of($id, 0), 'Nobody at all.');
    }

    /**
     * Audit 21: a period typed as a day is that day in the candidate's timezone, in a copy read from another one.
     *
     * Before: display_date::format() asked for the timezone of the reader (99), the direction's here, further west: the
     * period that begins on 1 September at midnight in La Réunion began on 31 August in the copy.
     */
    public function test_the_dates_of_a_copy_are_the_candidates_whoever_reads(): void {
        global $DB;

        $this->setTimezone('Europe/Paris', 'Europe/Paris');
        $id = (int) $this->candidate->id;
        $start = make_timestamp(2025, 9, 1, 0, 0, 0, 'Indian/Reunion');
        $end = make_timestamp(2026, 6, 30, 0, 0, 0, 'Indian/Reunion');
        $DB->set_field('user', 'timezone', 'Indian/Reunion', ['id' => $id]);
        $DB->set_field('tool_vae_evidence', 'periodstart', $start, ['candidateid' => $id]);
        $DB->set_field('tool_vae_evidence', 'periodend', $end, ['candidateid' => $id]);
        $DB->set_field('user', 'timezone', 'America/Guadeloupe', ['id' => $this->direction->id]);

        $format = get_string('strftimedate', 'langconfig');
        $expected = get_string('periodfromto', 'tool_vae', (object) [
            'from' => userdate($start, $format, 'Indian/Reunion'),
            'to' => userdate($end, $format, 'Indian/Reunion'),
        ]);
        $wrong = userdate($start, $format, 'America/Guadeloupe');
        $this->assertNotSame(userdate($start, $format, 'Indian/Reunion'), $wrong, 'The fixture tells the two apart.');

        $this->setUser(\core_user::get_user((int) $this->direction->id));
        $text = self::docx_text(dossier_document::for_candidate($id, 1790000000)['writer']->docx());
        $this->assertStringContainsString($expected, $text, 'Read in another timezone, by the direction.');
        $this->assertStringNotContainsString($wrong, $text);

        // The task of the weekly copy runs as the main administrator, in the timezone of the site.
        $this->setAdminUser();
        $copy = document_copies::take($id, 'docx', 1790000000);
        $this->assertStringContainsString($expected, self::docx_text($copy->get_content()), 'Written by the cron.');
        $this->assertStringNotContainsString(userdate($start, $format, 'Europe/Paris'), self::docx_text($copy->get_content()));
    }

    /**
     * Audit 21: the archive says the date it was made in the candidate's timezone too, and gives the reader's back.
     *
     * Before: the notice of LISEZMOI.txt wrote the time of the export in the timezone of whoever exported.
     */
    public function test_the_archive_is_dated_in_the_candidates_timezone_and_the_readers_comes_back(): void {
        global $DB;

        $this->setTimezone('Europe/Paris', 'Europe/Paris');
        $id = (int) $this->candidate->id;
        $DB->set_field('user', 'timezone', 'Indian/Reunion', ['id' => $id]);
        $DB->set_field('user', 'timezone', 'America/Guadeloupe', ['id' => $this->direction->id]);
        $now = make_timestamp(2025, 9, 1, 0, 1, 0, 'Indian/Reunion');
        $format = get_string('strftimedatetime', 'langconfig');
        $this->assertNotSame(userdate($now, $format, 'Indian/Reunion'), userdate($now, $format, 'America/Guadeloupe'));

        $this->setUser(\core_user::get_user((int) $this->direction->id));
        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $id], 'timecreated ASC, id ASC');
        $files = (new exporter())->build($id, $claims, identity_map::generate_ulid(), $now, false, true, false, false);

        $this->assertStringContainsString(userdate($now, $format, 'Indian/Reunion'), $files['LISEZMOI.txt']);
        $this->assertStringNotContainsString(userdate($now, $format, 'America/Guadeloupe'), $files['LISEZMOI.txt']);
        // The reader's own timezone is given back: the screen they read next is in it.
        $this->assertSame(
            userdate($now, $format, 99),
            display_date::format($now, $format),
            'The timezone is not left forced.'
        );
    }

    /**
     * Audit 21: the timezone is forced for the dates asked for with the reader's own, and only until it is given back.
     */
    public function test_display_date_forces_the_timezone_only_for_the_readers_own_and_gives_it_back(): void {
        display_date::force_timezone(null);
        $this->setTimezone('Europe/Paris', 'Europe/Paris');
        $time = make_timestamp(2025, 9, 1, 0, 0, 0, 'Indian/Reunion');
        $format = '%d %B %Y';
        $own = display_date::format($time, $format);

        $this->assertNull(display_date::force_timezone('Indian/Reunion'));
        $this->assertSame(userdate($time, $format, 'Indian/Reunion'), display_date::format($time, $format));
        $this->assertNotSame($own, display_date::format($time, $format));
        $this->assertSame(
            userdate($time, $format, 'UTC'),
            display_date::format($time, $format, 'UTC'),
            'A day stored in UTC keeps the timezone it asks for.'
        );
        $this->assertSame('Indian/Reunion', display_date::force_timezone(null));
        $this->assertSame($own, display_date::format($time, $format));
    }

    /**
     * Audit 54: a declaration under a bloc, and a section of the preparation under its record, are one level deeper.
     *
     * Before: MAX_HEADING was 3 and compose() asks for 4 - the title of the bloc and of the declaration under it, the
     * record and its section, were brothers in the navigation pane of the word processor.
     */
    public function test_the_copy_keeps_the_hierarchy_of_its_headings(): void {
        $id = (int) $this->candidate->id;
        $this->preparation('bc10_decrire', self::PREPARATION);
        $this->setUser($this->candidate);

        $document = dossier_document::for_candidate($id, 1790000000, true, null, true);
        $xml = self::docx_part($document['writer']->docx());

        $heading = fn(int $level, string $text) => '<w:pStyle w:val="Heading' . $level . '"/></w:pPr>'
            . '<w:r><w:t xml:space="preserve">' . document_writer::xml($text) . '</w:t>';
        $bloc = get_string('exportpdfbloc', 'tool_vae', 'BC02 - Deuxième');
        $this->assertStringContainsString($heading(3, $bloc), $xml, 'The bloc.');
        $this->assertStringContainsString($heading(4, 'C02 - Deux'), $xml, 'The declaration, under it.');
        $this->assertStringContainsString($heading(3, 'RS00000'), $xml, 'The record of the preparation.');
        $this->assertStringContainsString(
            $heading(4, validation_catalog::label('RS00000', 'bc10_decrire')),
            $xml,
            'The section, under it.'
        );
        $this->assertStringContainsString('w:styleId="Heading4"', self::docx_part($document['writer']->docx(), 'word/styles.xml'));
    }
}
