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

use tool_vae\local\draft_manager;
use tool_vae\local\draft_schema;
use tool_vae\local\importer;
use tool_vae\local\validation_catalog;
use tool_vae\local\validation_status;

/**
 * The preparation of the validation dossier, written in the same engine as the dossier de faisabilité (T-11, lot 1).
 *
 * WHAT IS ASSERTED. That the sections of a record are the three parts of each bloc and one synthesis, or the three parts
 * once for a record without blocs or with too many; that only those places can be written; that two records have the
 * same section codes without meeting, because the record is part of the address; that these sections are read by their
 * author and by whoever may view the dossier, never by the site administrator (MC-16, D-1b-G), in reading only, while the
 * export by anyone but the author still carries none of them and the dossier de faisabilité stays readable as it was;
 * that a bloc withdrawn from the record keeps its text and its name; that the column and the
 * index of the table are the ones the address needs.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\validation_catalog::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\draft_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\validation_status::class)]
final class validation_preparation_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Follows the candidate (viewassigned) and holds tool/vae:draftview in their context. */
    private $reader;

    /** @var int Numbers the anchors of the declarations written as rows (the column is unique). */
    private $fakeanchor = 1000;

    /**
     * A record with blocs, a flat one and one with too many blocs; a candidate; a reader.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        validation_catalog::reset();
        set_config('validationopen', 1, 'tool_vae');
        $this->setAdminUser();

        $meta = fn(string $code) => (object) [
            'fichecode' => $code, 'fichetype' => 'rs', 'name' => 'Fiche ' . $code,
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ];
        $importer = new importer();
        $importer->import($meta('RS00000'), importer::rows_from_csv(
            "rang,intitule,description,bloc\nBC02,Travailler en équipe,,\nC03,Transmettre,,BC02\nBC01,Accompagner,,\n"
            . "C01,Aider au lever,,BC01\nC02,Aider au repas,,BC01\n"
        ));
        $importer->import($meta('RS11111'), importer::rows_from_csv("rang,intitule\nC01,Une\nC02,Deux\n"));
        $many = "rang,intitule,description,bloc\n";
        for ($i = 1; $i <= validation_catalog::MAX_BLOCS + 1; $i++) {
            $many .= sprintf("BC%02d,Bloc %d,,\nC%02d,Compétence %d,,BC%02d\n", $i, $i, $i, $i, $i);
        }
        $importer->import($meta('RS22222'), importer::rows_from_csv($many));

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $this->reader = $generator->create_user();
        $ownrole = $generator->create_role();
        assign_capability('tool/vae:draftown', CAP_ALLOW, $ownrole, \context_system::instance()->id, true);
        role_assign($ownrole, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        // The reader follows the candidate, as a manager does: reading the dossier de faisabilité needs the right to read the
        // dossier first (viewany, or following the candidate), and draftview on top.
        $viewrole = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $viewrole, \context_system::instance()->id, true);
        assign_capability('tool/vae:draftview', CAP_ALLOW, $viewrole, \context_system::instance()->id, true);
        role_assign($viewrole, $this->reader->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        // The preparation follows the records the candidate declared on: one declaration on each, written as a row.
        foreach (['RS00000', 'RS11111', 'RS22222'] as $code) {
            $this->declared_on($code);
        }
    }

    /**
     * A declaration of the candidate on a record, written as a row (the preparation only needs that it exists).
     *
     * @param string $fichecode
     */
    private function declared_on(string $fichecode): void {
        global $DB;

        $now = time();
        $DB->insert_record('tool_vae_evidence', (object) [
            'uuid' => \tool_vae\local\identity_map::generate_ulid(), 'userevidenceid' => ++$this->fakeanchor,
            'candidateid' => (int) $this->candidate->id, 'periodstart' => $now - 1000, 'periodend' => $now,
            'settingtype' => 'ehpad', 'situationformat' => FORMAT_PLAIN, 'situation' => 'Une situation.',
            'fichecode' => $fichecode, 'ficherang' => 'C01', 'ficheupdated' => $now,
            'timecreated' => $now, 'timemodified' => $now, 'usermodified' => (int) $this->candidate->id,
        ]);
    }

    /**
     * A section of the preparation, as the candidate.
     *
     * @param string $fichecode
     * @param string $section
     * @return \stdClass
     */
    private function prepare(string $fichecode, string $section): \stdClass {
        $this->setUser($this->candidate);

        return (new draft_manager())->section(
            (int) $this->candidate->id,
            draft_manager::DOCTYPE_VALIDATION,
            $section,
            0,
            $fichecode
        );
    }

    /**
     * Refuse, and say with which error code.
     *
     * @param callable $call
     * @param string $errorcode
     */
    private function assert_refused(callable $call, string $errorcode): void {
        try {
            $call();
            $this->fail("Expected $errorcode.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    public function test_a_record_with_blocs_has_three_parts_per_bloc_in_rank_order_and_a_synthesis(): void {
        $sections = array_column(validation_catalog::sections('RS00000'), 'section');

        $this->assertSame([
            'bc01_decrire', 'bc01_analyser', 'bc01_lier',
            'bc02_decrire', 'bc02_analyser', 'bc02_lier',
            '_synthese',
        ], $sections);
        $this->assertTrue(validation_catalog::structure('RS00000')->byblocs);
    }

    public function test_a_record_without_blocs_has_the_three_parts_once_for_the_whole_record(): void {
        $this->assertSame(
            ['_decrire', '_analyser', '_lier', '_synthese'],
            array_column(validation_catalog::sections('RS11111'), 'section')
        );
        $this->assertFalse(validation_catalog::structure('RS11111')->byblocs);
    }

    public function test_a_record_with_too_many_blocs_is_treated_as_a_whole(): void {
        $structure = validation_catalog::structure('RS22222');

        $this->assertTrue($structure->overflow);
        $this->assertFalse($structure->byblocs);
        $this->assertSame(
            ['_decrire', '_analyser', '_lier', '_synthese'],
            array_column(validation_catalog::sections('RS22222'), 'section')
        );
    }

    public function test_an_unknown_record_has_no_place(): void {
        $this->assertNull(validation_catalog::structure('RS99999'));
        $this->assertSame([], validation_catalog::sections('RS99999'));
        $this->assertFalse(validation_catalog::valid_address('RS99999', '_synthese', 0));
    }

    public function test_only_the_places_of_the_record_can_be_written(): void {
        $this->assertTrue(validation_catalog::valid_address('RS00000', 'bc01_analyser', 0));
        $this->assertTrue(validation_catalog::valid_address('RS00000', '_synthese', 0));
        $this->assertTrue(validation_catalog::valid_address('RS11111', '_lier', 0));

        $this->assertFalse(validation_catalog::valid_address('RS00000', 'bc09_decrire', 0), 'No such bloc.');
        $this->assertFalse(validation_catalog::valid_address('RS00000', '_decrire', 0), 'A record with blocs goes bloc by bloc.');
        $this->assertFalse(validation_catalog::valid_address('RS11111', 'bc01_decrire', 0), 'A flat record has no bloc.');
        $this->assertFalse(validation_catalog::valid_address('RS00000', 'bc01_synthese', 0), 'The synthesis is the record\'s.');
        $this->assertFalse(validation_catalog::valid_address('RS00000', 'bc01_verdict', 0));
        $this->assertFalse(validation_catalog::valid_address('RS00000', 'bc01_decrire', 1), 'No slot.');
        $this->assertFalse(validation_catalog::valid_address('RS00000', 'objectifs', 0));
    }

    public function test_a_section_is_named_by_its_bloc_and_its_part_or_by_its_rank_when_the_bloc_is_gone(): void {
        $this->assertSame('Bloc BC01 - Accompagner - Describe my activities', validation_catalog::label('RS00000', 'bc01_decrire'));
        $this->assertSame('Synthesis', validation_catalog::label('RS00000', '_synthese'));
        $this->assertSame(
            'Bloc BC09 (withdrawn from the record) - Analyse my practice',
            validation_catalog::label('RS00000', 'bc09_analyser')
        );
        $this->assertSame('rien_du_tout', validation_catalog::label('RS00000', 'rien_du_tout'));
    }

    public function test_the_candidate_writes_a_section_and_two_records_do_not_meet(): void {
        global $DB;

        $first = $this->prepare('RS11111', '_decrire');
        $again = $this->prepare('RS11111', '_decrire');
        $this->assertSame((int) $first->id, (int) $again->id);
        $this->assertSame('RS11111', $first->fichecode);

        // The same code on another flat record is another section.
        \tool_vae\local\validation_catalog::reset();
        $other = $this->prepare('RS22222', '_decrire');
        $this->assertNotSame((int) $first->id, (int) $other->id);
        $this->assertSame(2, $DB->count_records('tool_vae_draft', ['doctype' => 'validation', 'section' => '_decrire']));
    }

    public function test_a_place_the_record_does_not_hold_cannot_be_opened(): void {
        $this->assert_refused(function () {
            $this->prepare('RS00000', 'bc09_decrire');
        }, 'errordraftsection');
        $this->assert_refused(function () {
            $this->prepare('RS00000', '_decrire');
        }, 'errordraftsection');
        $this->assert_refused(function () {
            $this->prepare('', '_synthese');
        }, 'errordraftsection');
        $this->assert_refused(function () {
            $this->prepare('RS99999', '_synthese');
        }, 'errordraftsection');
    }

    public function test_the_dossier_de_faisabilite_depends_on_no_record(): void {
        $this->setUser($this->candidate);
        $manager = new draft_manager();

        $this->assert_refused(function () use ($manager) {
            $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'objectifs', 0, 'RS00000');
        }, 'errordraftsection');
        $draft = $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'objectifs');
        $this->assertSame('', $draft->fichecode);
    }

    /**
     * A user holding some capabilities through a role assigned in one context.
     *
     * @param string[] $capabilities
     * @param \context $context Where the role is assigned.
     * @return \stdClass
     */
    private function user_with(array $capabilities, \context $context): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $role = $generator->create_role();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $role, \context_system::instance()->id, true);
        }
        role_assign($role, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $user;
    }

    /**
     * The readers of D-1b-G: who follows the candidate without draftview, the direction, who follows someone else, another
     * vaeiste.
     *
     * @return \stdClass[] Keyed by who they are.
     */
    private function readers(): array {
        $someoneelse = $this->getDataGenerator()->create_user();
        $other = $this->user_with(['tool/vae:draftown', 'tool/vae:viewown'], \context_system::instance());

        return [
            'follower' => $this->user_with(['tool/vae:viewassigned'], \context_user::instance($this->candidate->id)),
            'direction' => $this->user_with(['tool/vae:viewany'], \context_system::instance()),
            'stranger' => $this->user_with(['tool/vae:viewassigned'], \context_user::instance($someoneelse->id)),
            'vaeiste' => $other,
        ];
    }

    public function test_the_author_and_whoever_may_view_the_dossier_read_the_preparation(): void {
        $draft = $this->prepare('RS11111', '_decrire');
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Mon texte.');
        $readers = $this->readers();

        $this->setUser($this->candidate);
        $this->assertCount(1, $manager->versions((int) $draft->id));
        $this->assertTrue(draft_manager::can_read_doctype((int) $this->candidate->id, 'validation'));

        // Whoever may view the dossier reads the preparation: the follower holds no draftview, the direction holds viewany.
        foreach (['follower', 'direction'] as $who) {
            $this->setUser($readers[$who]);
            $this->assertTrue(draft_manager::can_read_doctype((int) $this->candidate->id, 'validation'), $who);
            $this->assertCount(1, $manager->versions((int) $draft->id), $who);
            draft_manager::require_can_read_draft($draft);
        }

        // Whoever may not view the dossier does not, and the site administrator stays refused (MC-16): it is the one reader
        // the rule sets aside by hand, who would pass can_view() through viewany.
        $refused = ['the administrator' => get_admin(), 'stranger' => $readers['stranger'], 'vaeiste' => $readers['vaeiste']];
        foreach ($refused as $who => $other) {
            $this->setUser($other);
            $this->assertFalse(draft_manager::can_read_doctype((int) $this->candidate->id, 'validation'), $who);
            $this->assert_refused(function () use ($manager, $draft) {
                $manager->versions((int) $draft->id);
            }, 'errordraftrefused');
            $this->assert_refused(function () use ($draft) {
                draft_manager::require_can_read_draft($draft);
            }, 'errordraftrefused');
        }
    }

    public function test_the_journal_stays_the_authors_alone_for_whoever_may_view_the_dossier(): void {
        foreach ($this->readers() + ['the administrator' => get_admin()] as $who => $other) {
            $this->setUser($other);
            $this->assertFalse(draft_manager::can_read_doctype((int) $this->candidate->id, 'reflexion'), $who);
        }
    }

    /**
     * The rule of the export is the one of amendment 3 of the DER (D-1b-A): an archive taken by anyone but the author carries
     * no preparation, even now that whoever follows the candidate reads it on the screen. Always true: this holds the line.
     */
    public function test_the_export_rule_of_the_der_is_unchanged_a_third_party_export_carries_no_preparation(): void {
        global $DB;

        // An archive needs declarations the plugin can name: the rows of setUp give way to one declared as the screen does.
        $DB->delete_records('tool_vae_evidence', ['candidateid' => (int) $this->candidate->id]);
        $role = $this->getDataGenerator()->create_role();
        foreach (['declare', 'viewown', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $role, \context_system::instance()->id, true);
        }
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->candidate);
        (new \tool_vae\local\evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $DB->get_field('competency', 'id', ['idnumber' => 'RS11111-C01'], MUST_EXIST),
            'periodstart' => 1756684800, 'periodend' => 1782777600,
            'settingtype' => 'ehpad', 'situation' => 'Une situation.', 'situationformat' => FORMAT_PLAIN,
        ]);
        $draft = $this->prepare('RS11111', '_decrire');
        (new draft_manager())->save_version((int) $draft->id, 'Ma préparation enregistrée.');
        $readers = $this->readers();

        foreach (['follower' => $readers['follower'], 'direction' => $readers['direction'], 'admin' => get_admin()] as $who => $u) {
            $this->setUser($u);
            $files = $this->unzip((new \tool_vae\local\exporter())->export((int) $this->candidate->id));
            $this->assertArrayNotHasKey(\tool_vae\local\exporter::VALIDATION_PATH, $files, $who);
            $this->assertStringNotContainsString('Ma préparation enregistrée.', implode('', $files), $who);
        }

        $this->setUser($this->candidate);
        $files = $this->unzip((new \tool_vae\local\exporter())->export((int) $this->candidate->id));
        $this->assertArrayHasKey(\tool_vae\local\exporter::VALIDATION_PATH, $files, 'The author carries it away.');
    }

    /**
     * The files of a stored archive, path => content.
     *
     * @param \stored_file $archive
     * @return string[]
     */
    private function unzip(\stored_file $archive): array {
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
     * What a page of the plugin writes for the current user, played as the browser asks it.
     *
     * @param array $get The parameters of the request.
     * @param string $script The page, validation.php unless said.
     * @return string
     */
    private function page(array $get, string $script = 'validation.php'): string {
        // The page is a script of the site: it reads these as globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SITE, $USER;

        $_GET = $get;
        $PAGE = new \moodle_page();
        ob_start();
        try {
            include(__DIR__ . '/../' . $script);
        } finally {
            $out = ob_get_clean();
            $_GET = [];
        }

        return $out;
    }

    public function test_a_reader_opens_the_preparation_of_the_vaeiste_in_reading_only(): void {
        $manager = new draft_manager();
        $described = $this->prepare('RS11111', '_decrire');
        $manager->save_version((int) $described->id, 'Le texte de la version.');
        $manager->save_working((int) $described->id, 'Le texte en cours.');
        $linked = $this->prepare('RS11111', '_lier');
        $manager->save_version((int) $linked->id, 'Le lien enregistré.');
        // Closed, the preparation is still read.
        set_config('validationopen', 0, 'tool_vae');
        $follower = $this->readers()['follower'];

        $this->setUser($follower);
        $out = $this->page(['userid' => (int) $this->candidate->id, 'fiche' => 'RS11111']);

        $this->assertStringNotContainsString('section.php', $out, 'A reader writes nothing.');
        $this->assertStringContainsString('Le texte de la version.', $out);
        $this->assertStringContainsString('Le lien enregistré.', $out);
        $this->assertStringContainsString('Le texte en cours.', $out);
        $this->assertStringContainsString(s(get_string('situationworkingnotice', 'tool_vae')), $out);
        $this->assertStringContainsString('history.php?draftid=' . $described->id, $out);
        $this->assertStringContainsString('history.php?draftid=' . $linked->id, $out);
        $this->assertStringContainsString(s(get_string('validationreadnotice', 'tool_vae')), $out);
        $this->assertStringContainsString(
            s(get_string('validationreadheading', 'tool_vae', fullname($this->candidate))),
            $out
        );
    }

    public function test_a_reader_who_may_not_read_the_preparation_is_refused_the_page(): void {
        $this->prepare('RS11111', '_decrire');
        $this->setAdminUser();
        $this->assert_refused(function () {
            $this->page(['userid' => (int) $this->candidate->id, 'fiche' => 'RS11111']);
        }, 'errordraftrefused');

        $this->setUser($this->readers()['stranger']);
        $this->expectException(\required_capability_exception::class);
        $this->page(['userid' => (int) $this->candidate->id, 'fiche' => 'RS11111']);
    }

    public function test_the_dossier_offers_the_reader_the_preparation_once_begun_and_never_to_the_administrator(): void {
        $readers = $this->readers();
        $link = s(get_string('validationreadlink', 'tool_vae'));
        $get = ['userid' => (int) $this->candidate->id];

        $this->setUser($readers['direction']);
        $this->assertStringNotContainsString($link, $this->page($get, 'dossier.php'), 'Nothing begun, nothing offered.');

        $this->prepare('RS11111', '_decrire');
        foreach (['follower' => $readers['follower'], 'direction' => $readers['direction']] as $who => $reader) {
            $this->setUser($reader);
            $this->assertStringContainsString($link, $this->page($get, 'dossier.php'), $who);
        }
        $this->setAdminUser();
        $this->assertStringNotContainsString($link, $this->page($get, 'dossier.php'), 'The administrator is not offered it.');
        $role = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewown', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->candidate);
        $this->assertStringNotContainsString($link, $this->page([], 'dossier.php'), 'The author writes it from their own button.');
    }

    public function test_the_author_still_writes_from_the_page(): void {
        $this->prepare('RS11111', '_decrire');
        $this->setUser($this->candidate);
        $out = $this->page(['fiche' => 'RS11111']);

        $this->assertStringContainsString('section.php', $out);
        $this->assertStringNotContainsString(s(get_string('validationreadnotice', 'tool_vae')), $out);
    }

    public function test_the_dossier_de_faisabilite_stays_readable_as_it_was(): void {
        $this->setUser($this->candidate);
        $manager = new draft_manager();
        $draft = $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'objectifs');
        $manager->save_version((int) $draft->id, 'Mes objectifs.');

        foreach ([get_admin(), $this->reader] as $other) {
            $this->setUser($other);
            $this->assertTrue(draft_manager::can_read_doctype((int) $this->candidate->id, 'faisabilite'));
            $this->assertCount(1, $manager->versions((int) $draft->id));
        }
    }

    public function test_a_version_with_a_later_draft_says_so_and_gives_the_date_of_the_version(): void {
        global $DB;

        $draft = $this->prepare('RS11111', '_decrire');
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Un.');
        $saved = time() - 15 * DAYSECS;
        $DB->set_field('tool_vae_version', 'timecreated', $saved, ['draftid' => $draft->id, 'number' => 1]);
        // Fifteen days later the writer retouches a word: the autosave moves the date of the section, not the one of the version.
        $manager->save_working((int) $draft->id, 'Un, retouché.');

        $place = validation_status::for_record((int) $this->candidate->id, 'RS11111')['_decrire'];

        $this->assertSame(validation_status::STATE_VERSION_DRAFT, $place['state']);
        $this->assertSame(1, $place['version']);
        $this->assertSame($saved, $place['date'], 'The date of the version, not the one of the last autosave.');
        $this->assertNotSame((int) $DB->get_field('tool_vae_draft', 'timemodified', ['id' => $draft->id]), $place['date']);

        $words = validation_status::words($place);
        $this->assertSame(get_string('validationversiondraft', 'tool_vae', (object) [
            'number' => 1,
            'date' => userdate($saved, get_string('strftimedate', 'langconfig')),
        ]), $words);
        $this->assertStringNotContainsString(
            get_string('faisabstatedraft', 'tool_vae'),
            $words,
            'A version and a draft in progress are not said "no version saved".'
        );
    }

    public function test_the_four_states_of_a_section_in_words(): void {
        $manager = new draft_manager();
        $empty = $this->prepare('RS00000', 'bc01_decrire');
        $draftonly = $this->prepare('RS00000', 'bc01_analyser');
        $versiononly = $this->prepare('RS00000', 'bc01_lier');
        $emptied = $this->prepare('RS00000', 'bc02_decrire');
        $manager->save_working((int) $draftonly->id, 'Un début.');
        $manager->save_version((int) $versiononly->id, 'Un texte.');
        $manager->save_version((int) $emptied->id, 'Un autre texte.');
        // The writer empties the area: what is left is no draft, as the dossier de faisabilité counts it.
        $manager->save_working((int) $emptied->id, '');

        $places = validation_status::for_record((int) $this->candidate->id, 'RS00000');

        $this->assertSame(validation_status::STATE_EMPTY, $places['bc01_decrire']['state'], 'A section opened and not written.');
        $this->assertSame(validation_status::STATE_DRAFT, $places['bc01_analyser']['state']);
        $this->assertSame(validation_status::STATE_VERSION, $places['bc01_lier']['state']);
        $this->assertSame(validation_status::STATE_VERSION, $places['bc02_decrire']['state'], 'An emptied draft is no draft.');
        $this->assertArrayNotHasKey('bc02_lier', $places, 'A section nobody opened has no row.');
        $this->assertSame(validation_status::STATE_EMPTY, validation_status::place(null)['state']);

        $this->assertSame(get_string('validationnotwritten', 'tool_vae'), validation_status::words($places['bc01_decrire']));
        $this->assertSame(get_string('faisabstatedraft', 'tool_vae'), validation_status::words($places['bc01_analyser']));
        $this->assertSame(get_string('validationversion', 'tool_vae', (object) [
            'number' => 1,
            'date' => userdate($places['bc01_lier']['date'], get_string('strftimedate', 'langconfig')),
        ]), validation_status::words($places['bc01_lier']));
        $this->assertSame(
            get_string('validationnotwritten', 'tool_vae'),
            validation_status::words(validation_status::place(null))
        );
    }

    public function test_the_date_of_a_version_brought_back_from_an_archive_is_the_one_the_archive_gives(): void {
        $savedat = time() - 40 * DAYSECS;
        (new draft_manager())->restore_from_archive(
            (int) $this->candidate->id,
            '_decrire',
            0,
            'Écrit avant l\'export.',
            $savedat,
            'Entrée reprise de l\'archive',
            draft_manager::DOCTYPE_VALIDATION,
            'RS11111'
        );

        $place = validation_status::for_record((int) $this->candidate->id, 'RS11111')['_decrire'];

        $this->assertSame(validation_status::STATE_VERSION, $place['state']);
        $this->assertSame($savedat, $place['date'], 'The day of the import is not the day the version was saved.');
    }

    public function test_the_label_of_a_section_follows_the_document(): void {
        $validation = $this->prepare('RS00000', 'bc02_lier');
        $this->setUser($this->candidate);
        $faisabilite = (new draft_manager())->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'objectifs');

        $this->assertSame('Bloc BC02 - Travailler en équipe - Make the link with the bloc', draft_manager::label($validation));
        $this->assertSame(\tool_vae\local\faisabilite_catalog::label('objectifs', 0), draft_manager::label($faisabilite));
    }

    public function test_a_bloc_that_left_the_record_keeps_its_text_and_is_still_named(): void {
        global $DB;

        $draft = $this->prepare('RS00000', 'bc02_decrire');
        (new draft_manager())->save_version((int) $draft->id, 'Écrit avant le départ du bloc.');

        // The record is republished without BC02: the import never deletes, the competency stays in the core.
        $this->setAdminUser();
        (new importer())->import((object) [
            'fichecode' => 'RS00000', 'fichetype' => 'rs', 'name' => 'Fiche RS00000',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-09-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv("rang,intitule,description,bloc\nBC01,Accompagner,,\nC01,Aider au lever,,BC01\n"));
        validation_catalog::reset();

        // The text is still there, with its version.
        $row = $DB->get_record('tool_vae_draft', ['id' => $draft->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $row->currentversion);
        $this->setUser($this->candidate);
        $this->assertSame(
            'Écrit avant le départ du bloc.',
            (new draft_manager())->version((int) $draft->id, 1)->content
        );
        $this->assertStringContainsString('BC02', draft_manager::label($row));
    }

    public function test_the_preparation_is_closed_until_the_administrator_opens_it(): void {
        set_config('validationopen', 0, 'tool_vae');

        $this->assert_refused(function () {
            $this->prepare('RS11111', '_decrire');
        }, 'errorvalidationclosed');
        $this->assertFalse(draft_manager::validation_open());

        set_config('validationopen', 1, 'tool_vae');
        $this->assertSame('RS11111', $this->prepare('RS11111', '_decrire')->fichecode);
    }

    public function test_a_closed_preparation_is_closed_for_every_door(): void {
        $draft = $this->prepare('RS11111', '_decrire');
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Un.');
        $manager->save_version((int) $draft->id, 'Deux.');
        set_config('validationopen', 0, 'tool_vae');
        $this->setUser($this->candidate);

        $writes = [
            fn() => $manager->save_working((int) $draft->id, 'Brouillon'),
            fn() => $manager->save_version((int) $draft->id, 'Trois.'),
            fn() => $manager->restore((int) $draft->id, 1),
        ];
        foreach ($writes as $write) {
            $this->assert_refused($write, 'errorvalidationclosed');
        }
    }

    public function test_only_a_record_the_candidate_declared_on_can_be_prepared(): void {
        global $DB;

        $DB->delete_records('tool_vae_evidence', ['fichecode' => 'RS22222']);

        $this->assert_refused(function () {
            $this->prepare('RS22222', '_synthese');
        }, 'errordraftsection');
    }

    public function test_a_record_is_taken_as_written_and_not_as_the_database_folds_it(): void {
        $this->declared_on('rs00000');
        validation_catalog::reset();

        $this->assertNull(validation_catalog::structure('rs00000'));
        $this->assert_refused(function () {
            $this->prepare('rs00000', 'bc01_decrire');
        }, 'errordraftsection');
    }

    public function test_the_name_of_a_bloc_taken_from_the_record_is_escaped_in_the_list_to_declare_in(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');

        $this->setAdminUser();
        $result = (new importer())->import((object) [
            'fichecode' => 'RS33333', 'fichetype' => 'rs', 'name' => 'Fiche piégée',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv(
            "rang,intitule,description,bloc\nBC01,Bloc BC01,\"<img src=x onerror=alert(1)>\",\nC01,Une,,BC01\n"
        ));
        $competencies = (new \tool_vae\local\competency_gateway())->list_framework_competencies((int) $result->frameworkid);
        $nodes = \tool_vae\local\outline::group($competencies);
        foreach ($nodes as $node) {
            $node->fichecode = 'RS33333';
        }

        $form = new \tool_vae\form\declare_form('/admin/tool/vae/declare.php', [
            'competencies' => $competencies, 'outline' => $nodes,
        ]);
        $html = $form->render();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x', $html);
    }

    public function test_the_step_of_schema_puts_the_record_in_the_address_from_either_state(): void {
        global $DB;
        $this->preventResetByRollback();

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_vae_draft');
        $whole = new \xmldb_index('section', XMLDB_INDEX_UNIQUE, ['candidateid', 'doctype', 'fichecode', 'section', 'slot']);
        $oldindex = new \xmldb_index('section', XMLDB_INDEX_UNIQUE, ['candidateid', 'doctype', 'section', 'slot']);

        try {
            // The state of a site coming from the 4.5 package: the table was made from the current install.xml.
            draft_schema::address_with_record($dbman);
            $this->assertTrue($dbman->index_exists($table, $whole));
            $this->assertTrue($DB->get_columns('tool_vae_draft')['fichecode']->not_null);

            // The state of a site that already had the table: the column nullable, the index without the record.
            $dbman->drop_index($table, $whole);
            $nullable = new \xmldb_field('fichecode', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'slot');
            $dbman->change_field_notnull($table, $nullable);
            $dbman->add_index($table, $oldindex);
            $now = time();
            $DB->insert_record('tool_vae_draft', (object) [
                'uuid' => \tool_vae\local\identity_map::generate_ulid(), 'candidateid' => (int) $this->candidate->id,
                'doctype' => 'faisabilite', 'section' => 'objectifs', 'slot' => 0, 'fichecode' => null,
                'currentversion' => 0, 'timecreated' => $now, 'timemodified' => $now,
                'usermodified' => (int) $this->candidate->id,
            ]);
            $this->assertFalse($DB->get_columns('tool_vae_draft')['fichecode']->not_null);

            draft_schema::address_with_record($dbman);

            $this->assertTrue($dbman->index_exists($table, $whole));
            $this->assertFalse($dbman->index_exists($table, $oldindex));
            $this->assertTrue($DB->get_columns('tool_vae_draft')['fichecode']->not_null);
            $this->assertSame(0, $DB->count_records_select('tool_vae_draft', 'fichecode IS NULL'));
            $this->assertSame('', $DB->get_field('tool_vae_draft', 'fichecode', ['section' => 'objectifs']));

            // Run again, nothing changes.
            draft_schema::address_with_record($dbman);
            $this->assertTrue($dbman->index_exists($table, $whole));
        } finally {
            draft_schema::address_with_record($dbman);
        }
    }

    public function test_the_table_holds_the_record_in_its_address(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('tool_vae_draft');
        $this->assertTrue($dbman->index_exists(
            $table,
            new \xmldb_index('section', XMLDB_INDEX_UNIQUE, ['candidateid', 'doctype', 'fichecode', 'section', 'slot'])
        ));
        $this->assertFalse($dbman->index_exists(
            $table,
            new \xmldb_index('section', XMLDB_INDEX_UNIQUE, ['candidateid', 'doctype', 'section', 'slot'])
        ));
        $columns = $DB->get_columns('tool_vae_draft');
        $this->assertTrue($columns['fichecode']->not_null);
    }

    /**
     * The work situations are not a section of the preparation (D-1b-D): they have no deposit, no PDF, no place in the
     * document of validation.schema.json and no line in the status of the record. The catalogue knows their address all the
     * same, so that a draft can hold their text.
     */
    public function test_the_situations_never_enter_the_preparation_document_nor_its_status(): void {
        global $DB;

        $draft = $this->prepare('RS11111', '_decrire');
        $drafts = new draft_manager();
        $drafts->save_version((int) $draft->id, 'Ma partie.');
        $situations = new \tool_vae\local\situation_manager();
        $situation = $situations->create((int) $this->candidate->id, 'RS11111');
        $situations->save_version((int) $situation->id, 'Ma situation.');
        $this->assertSame(2, $DB->count_records('tool_vae_draft', [
            'candidateid' => $this->candidate->id, 'doctype' => draft_manager::DOCTYPE_VALIDATION, 'fichecode' => 'RS11111',
        ]));

        $saved = \tool_vae\local\validation_overview::saved_versions((int) $this->candidate->id);
        $this->assertSame(['_decrire'], array_map(fn($row) => (string) $row->section, $saved));
        $this->assertSame(['_decrire'], array_keys(validation_status::for_record((int) $this->candidate->id, 'RS11111')));
        $this->assertNotContains(
            validation_catalog::SITUATION_SECTION,
            array_column(validation_catalog::sections('RS11111'), 'section')
        );
        $this->assertNotContains(
            validation_catalog::SITUATION_SECTION,
            array_column(validation_catalog::sections('RS00000'), 'section')
        );

        // The catalogue knows the address of a situation: a slot from 1 to MAX_SITUATIONS, on an imported record.
        $this->assertSame(['', validation_catalog::PART_SITUATION], validation_catalog::parse('_situation'));
        $this->assertNull(validation_catalog::parse('bc01_situation'), 'A situation is never under a bloc.');
        $this->assertTrue(validation_catalog::valid_address('RS11111', '_situation', 1));
        $this->assertTrue(validation_catalog::valid_address('RS00000', '_situation', validation_catalog::MAX_SITUATIONS));
        $this->assertFalse(validation_catalog::valid_address('RS11111', '_situation', 0));
        $this->assertFalse(validation_catalog::valid_address('RS11111', '_situation', validation_catalog::MAX_SITUATIONS + 1));
        $this->assertFalse(validation_catalog::valid_address('RS99999', '_situation', 1));
        $this->assertFalse(validation_catalog::valid_address('RS11111', '_decrire', 1), 'The other parts keep slot 0.');
        $this->assertSame(
            get_string('validpart:situation', 'tool_vae', 3),
            validation_catalog::label('RS11111', '_situation', 3)
        );
        $this->assertSame(
            get_string('validpart:situation', 'tool_vae', 1),
            draft_manager::label($DB->get_record('tool_vae_draft', ['id' => $situation->draftid]))
        );
    }

    /**
     * The doors of draft_manager reserved to situation_manager open no other section, and an archive brings no situation back
     * as a section of the preparation (lot 1b, correction round 1).
     */
    public function test_the_reserved_doors_of_the_situations_open_no_other_section(): void {
        global $DB;

        $draft = $this->prepare('RS11111', '_decrire');
        $drafts = new draft_manager();
        $drafts->save_version((int) $draft->id, 'Ma partie.');

        $this->assert_refused(fn() => $drafts->save_version_of_situation((int) $draft->id, 'Autre.'), 'errordraftsection');
        $this->assert_refused(fn() => $drafts->restore_of_situation((int) $draft->id, 1), 'errordraftsection');
        $this->assertSame(1, $DB->count_records('tool_vae_version', ['draftid' => $draft->id]));

        // The archive: a rubric coded _situation is no section of the preparation (D-1b-D).
        $this->assert_refused(function () use ($drafts) {
            $drafts->restore_from_archive(
                (int) $this->candidate->id,
                validation_catalog::SITUATION_SECTION,
                0,
                'Texte.',
                time(),
                '',
                draft_manager::DOCTYPE_VALIDATION,
                'RS11111'
            );
        }, 'errordraftsection');
        $this->assertSame(0, $DB->count_records('tool_vae_draft', [
            'candidateid' => $this->candidate->id, 'section' => validation_catalog::SITUATION_SECTION,
        ]));
    }
}
