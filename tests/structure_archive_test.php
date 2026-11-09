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

use tool_vae\local\booklet_authorization;
use tool_vae\local\dossier_importer;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\positioning_archive;
use tool_vae\local\positioning_rules;
use tool_vae\local\schema_validator;
use tool_vae\local\structure_archive;

/**
 * The archive of the accompanying structure: what it holds, who produces and reads it, that a new one replaces the old one.
 *
 * WHAT IS ASSERTED. That the archive holds the positionings and nothing of the vaeiste's dossiers, registered with the
 * digest of its manifest and said not to be the dossier nor a validation; that the direction produces it, and a manager
 * only while the direction authorized them and only for a candidate they follow; that nobody produces their own; that it
 * is refused when nothing was written; that a second one replaces the first file and leaves both lines in the register;
 * and that a reimport refuses it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\structure_archive::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\structure_archive_issued::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\pdf_renderer::class)]
final class structure_archive_test extends \advanced_testcase {
    /** @var \stdClass The vaeiste. */
    private $candidate;

    /** @var \stdClass A manager who follows the vaeiste. */
    private $manager;

    /** @var \stdClass A manager who follows somebody else. */
    private $other;

    /** @var \stdClass Holds viewany and issuestructure. */
    private $direction;

    /**
     * RNCP90000 with a bloc BC01 over C01 and C02; a vaeiste who declared on it; a direction and two managers.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        (new importer())->import((object) [
            'fichecode' => 'RNCP90000', 'fichetype' => 'rncp', 'name' => 'Fiche RNCP90000',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv("rang,intitule,description,bloc\nBC01,Bloc un,,\nC01,Premiere,,BC01\nC02,Seconde,,BC01\n"));

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $this->candidate = $generator->create_user();
        $this->manager = $generator->create_user();
        $this->other = $generator->create_user();
        $this->direction = $generator->create_user();

        $own = $generator->create_role();
        foreach (['declare', 'export', 'viewown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, $system, true);
        }
        role_assign($own, $this->candidate->id, \context_user::instance($this->candidate->id)->id);

        $follow = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, \context_user::instance($this->candidate->id)->id);
        $stranger = $generator->create_user();
        role_assign($follow, $this->other->id, \context_user::instance($stranger->id)->id);

        $director = $generator->create_role();
        foreach (['tool/vae:viewany', 'tool/vae:issuestructure'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $director, $system, true);
        }
        role_assign($director, $this->direction->id, $system);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->candidate);
        $competency = $DB->get_field('competency', 'id', ['idnumber' => 'RNCP90000-C01'], MUST_EXIST);
        (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $competency, 'periodstart' => 1756684800, 'periodend' => 1782777600,
            'settingtype' => 'ehpad', 'situation' => 'La situation de travail du vaeiste.', 'situationformat' => FORMAT_PLAIN,
        ]);
        $this->setAdminUser();
    }

    /**
     * Write one evaluation straight into its table.
     *
     * @param string $reason
     * @param int $time
     */
    private function write_line(string $reason, int $time): void {
        global $DB;

        $DB->insert_record('tool_vae_eval', (object) [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => $this->candidate->id,
            'fichecode' => 'RNCP90000',
            'ficherang' => 'C01',
            'status' => 'sufficient',
            'reason' => $reason,
            'reasonhash' => positioning_rules::hash(['sufficient', 'RNCP90000', 'C01', $reason]),
            'authorid' => $this->manager->id,
            'authoruuid' => null,
            'importedat' => null,
            'timecreated' => $time,
        ]);
    }

    /**
     * The files of an archive of the structure, path => content.
     *
     * @param \stored_file $file
     * @return string[]
     */
    private function unzip(\stored_file $file): array {
        $files = [];
        $dir = make_request_directory();
        $file->extract_to_pathname(get_file_packer('application/zip'), $dir);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            $files[substr($item->getPathname(), strlen($dir) + 1)] = file_get_contents($item->getPathname());
        }

        return $files;
    }

    public function test_the_direction_produces_it_registered_with_the_digest_of_its_manifest(): void {
        global $DB;
        $this->write_line('Trois situations vues.', 1762100000);
        $this->setUser($this->direction);

        $file = structure_archive::issue((int) $this->candidate->id);

        $files = $this->unzip($file);
        $this->assertArrayHasKey('manifeste.json', $files);
        $this->assertArrayHasKey(positioning_archive::PATH, $files);
        $this->assertArrayHasKey(structure_archive::PDF_PATH, $files);
        $this->assertArrayHasKey('LISEZMOI.txt', $files);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(structure_archive::FORMAT, $manifest['format']);
        foreach ($manifest['fichiers'] as $entry) {
            $this->assertSame($entry['sha256'], hash('sha256', $files[$entry['chemin']]), $entry['chemin']);
        }
        $row = $DB->get_record(exporter::REGISTER, ['archiveuuid' => $manifest['archive']], '*', MUST_EXIST);
        $this->assertSame(hash('sha256', $files['manifeste.json']), $row->sha256);
        $this->assertEquals($this->direction->id, $row->userid);
        $document = json_decode($files[positioning_archive::PATH], false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], (new schema_validator())->validate($document, 'positionnement.schema.json'));
        $this->assertStringContainsString('Trois situations vues.', $files[positioning_archive::PATH]);
        $this->assertStringStartsWith('%PDF', $files[structure_archive::PDF_PATH]);
    }

    public function test_it_holds_nothing_of_the_dossiers_of_the_vaeiste_and_says_it_is_neither_one_nor_a_validation(): void {
        $this->write_line('Trois situations vues.', 1762100000);
        $this->setUser($this->direction);

        $files = $this->unzip(structure_archive::issue((int) $this->candidate->id));

        $this->assertSame(
            ['LISEZMOI.txt', 'livret-structure.pdf', 'manifeste.json', 'positionnement.json', 'schema/positionnement.schema.json'],
            array_values(array_keys($this->sorted($files)))
        );
        $manifest = json_decode($files['manifeste.json'], true);
        $this->assertArrayNotHasKey('preuves', $manifest);
        $this->assertArrayNotHasKey('schema_preuve', $manifest);
        $this->assertStringNotContainsString('La situation de travail du vaeiste.', implode("\n", $files));
        $this->assertStringContainsString(get_string('structurenotice', 'tool_vae'), $files['LISEZMOI.txt']);
        $this->assertSame(get_string('structurenotice', 'tool_vae'), $manifest['avertissement']);
    }

    /**
     * The files by path, in a stable order.
     *
     * @param string[] $files
     * @return string[]
     */
    private function sorted(array $files): array {
        ksort($files);

        return $files;
    }

    public function test_a_second_archive_replaces_the_first_and_the_register_keeps_both_lines(): void {
        global $DB;
        $this->write_line('Deux situations vues.', 1762000000);
        $this->setUser($this->direction);
        $first = structure_archive::issue((int) $this->candidate->id);
        $this->write_line('Trois situations vues.', 1762100000);

        $second = structure_archive::issue((int) $this->candidate->id);

        $context = \context_user::instance($this->candidate->id);
        $held = get_file_storage()->get_area_files($context->id, 'tool_vae', structure_archive::FILEAREA, 0, 'id', false);
        $this->assertCount(1, $held, 'One archive of the structure per vaeiste.');
        $this->assertSame($second->get_filename(), reset($held)->get_filename());
        $this->assertNotSame($first->get_filename(), $second->get_filename());
        $this->assertSame($second->get_filename(), structure_archive::current((int) $this->candidate->id)->get_filename());
        $archiveid = substr($first->get_filename(), -30, 26);
        $this->assertTrue(
            $DB->record_exists(exporter::REGISTER, ['archiveuuid' => $archiveid]),
            'The replaced archive stays in the register: a panel that holds a copy of it has it confirmed.'
        );
        $this->assertSame(2, $DB->count_records(exporter::REGISTER));
    }

    public function test_it_is_still_there_after_the_hour_the_exports_are_purged(): void {
        $this->write_line('Trois situations vues.', 1762100000);
        $this->setUser($this->direction);
        $file = structure_archive::issue((int) $this->candidate->id);

        exporter::purge_expired(time() + 30 * DAYSECS);

        $this->assertNotNull(structure_archive::current((int) $this->candidate->id));
        $this->assertSame($file->get_filename(), structure_archive::current((int) $this->candidate->id)->get_filename());
    }

    public function test_a_manager_is_refused_until_the_direction_authorizes_them_and_after_it_takes_that_back(): void {
        $this->write_line('Trois situations vues.', 1762100000);
        $candidateid = (int) $this->candidate->id;

        $this->setUser($this->manager);
        $this->assertFalse(structure_archive::can_issue($candidateid));
        $this->setUser($this->direction);
        booklet_authorization::grant((int) $this->manager->id);
        $this->setUser($this->manager);
        $this->assertTrue(structure_archive::can_issue($candidateid));
        $this->assertNotNull(structure_archive::issue($candidateid));
        $this->setUser($this->direction);
        booklet_authorization::revoke((int) $this->manager->id);
        $this->setUser($this->manager);
        $this->assertFalse(structure_archive::can_issue($candidateid));

        $this->expectException(\moodle_exception::class);
        structure_archive::issue($candidateid);
    }

    public function test_an_authorized_manager_cannot_produce_it_for_a_vaeiste_they_do_not_follow(): void {
        $this->write_line('Trois situations vues.', 1762100000);
        $this->setUser($this->direction);
        booklet_authorization::grant((int) $this->other->id);

        $this->setUser($this->other);
        $this->assertFalse(structure_archive::can_issue((int) $this->candidate->id));
        $this->expectException(\moodle_exception::class);
        structure_archive::issue((int) $this->candidate->id);
    }

    public function test_nobody_produces_their_own(): void {
        $this->write_line('Trois situations vues.', 1762100000);
        $this->setUser($this->candidate);

        $this->assertFalse(structure_archive::can_issue((int) $this->candidate->id));
        $this->setAdminUser();
        $this->assertTrue(structure_archive::can_issue((int) $this->candidate->id), 'The administrator may, for another person.');
        $admin = get_admin();
        $this->assertFalse(structure_archive::can_issue((int) $admin->id), 'And never for themself.');
    }

    public function test_it_is_refused_when_no_positioning_was_ever_written(): void {
        $this->setUser($this->direction);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorstructureempty', 'tool_vae'));
        structure_archive::issue((int) $this->candidate->id);
    }

    public function test_the_vaeiste_reads_it_and_a_stranger_does_not(): void {
        $this->write_line('Trois situations vues.', 1762100000);
        $this->setUser($this->direction);
        $file = structure_archive::issue((int) $this->candidate->id);

        $this->setUser($this->candidate);
        $this->assertTrue(structure_archive::can_read((int) $this->candidate->id));
        $this->assertTrue(structure_archive::may_download($file));
        $this->setUser($this->other);
        $this->assertFalse(structure_archive::can_read((int) $this->candidate->id));
        $this->assertFalse(structure_archive::may_download($file));
    }

    public function test_a_reimport_refuses_it_for_a_dossier(): void {
        $this->write_line('Trois situations vues.', 1762100000);
        $this->setUser($this->direction);
        $files = $this->unzip(structure_archive::issue((int) $this->candidate->id));

        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        (new dossier_importer())->import_files((int) $this->candidate->id, $files);
    }
}
