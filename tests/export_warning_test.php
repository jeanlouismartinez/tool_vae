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
use tool_vae\local\dossier_importer;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\pdf_renderer;
use tool_vae\local\piece_manager;
use tool_vae\local\purge;

/**
 * A line the schema refuses no longer refuses the archive: it is produced, and the person is warned (decision of the
 * project owner, 27 October 2026).
 *
 * WHAT IS ASSERTED. That a dossier holding a rank written `c7`, directly in the database as an earlier version recorded
 * it (a declaration on such a rank is refused since 0.46.0, and no screen rewrites a row already recorded), gives an
 * archive, its register line, its event and its PDF, with a warning that names the declaration by its code and rank, its
 * file and the value; that the notice inside the archive carries the mention; that the schema, the manifest and the
 * reimport are not touched (the reimport refuses the archive whole, the valid declarations beside the faulty one with it);
 * that nothing of another candidate is in the warning; that the backup the page takes passes the same way. And that the two
 * other protections, which this change must not loosen, still refuse: a piece whose file is lost or altered, and a dossier
 * too large to be read back - the latter FAILS before the change for the right reason, since the schema check stood in
 * front of it and refused first.
 *
 * WHAT THE SCREENS SAY, AND DO NOT PROMISE (review of 27 October 2026). The texts that tell of a faulty line say where it
 * comes from and that Moodle cannot correct it: correcting the identification number of the competency does not rewrite the
 * row that holds the rank, so a text that sent the administrator there sent them to a correction without effect. The log
 * report says how many such lines an export carried, since it is where an administrator looks for the exports a reimport
 * will refuse. The lines are given to the screens by one helper, which escapes them; and a value that is empty is not told
 * as a field that is missing.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\backup::class)]
final class export_warning_test extends \advanced_testcase {
    /** @var int 1 September 2025, 00:00 UTC. */
    private const PERIODSTART = 1756684800;

    /** @var int 30 June 2026, 00:00 UTC. */
    private const PERIODEND = 1782777600;

    /** @var \stdClass Declares and exports their own dossier. */
    private $candidate;

    /** @var \stdClass Another candidate, whose dossier is not the first one's. */
    private $other;

    /** @var \stdClass Reads and exports every dossier. */
    private $director;

    /** @var int[] The competencies of the records seeded in setUp(), by identification number. */
    private $competencies = [];

    /**
     * Two imported records, two candidates and the direction.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();

        $generator = $this->getDataGenerator();
        $plugin = $generator->get_plugin_generator('tool_vae');
        $plugin->create_source(['fichecode' => 'RNCP36832', 'ficherang' => 'C03']);
        $plugin->create_source(['fichecode' => 'RNCP40692', 'ficherang' => 'BC03']);
        foreach (['RNCP36832-C03', 'RNCP40692-BC03'] as $idnumber) {
            $this->competencies[$idnumber] = (int) $DB->get_field('competency', 'id', ['idnumber' => $idnumber], MUST_EXIST);
        }

        $system = \context_system::instance()->id;

        $candidaterole = $generator->create_role();
        foreach (['declare', 'viewown', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $candidaterole, $system, true);
        }
        $this->candidate = $generator->create_user(['firstname' => 'Camille', 'lastname' => 'Martin']);
        $this->other = $generator->create_user(['firstname' => 'Lucie', 'lastname' => 'Durand']);
        foreach ([$this->candidate, $this->other] as $user) {
            role_assign($candidaterole, $user->id, \context_user::instance($user->id)->id);
        }

        $directorrole = $generator->create_role();
        foreach (['viewany', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $directorrole, $system, true);
        }
        $this->director = $generator->create_user();
        role_assign($directorrole, $this->director->id, $system);

        set_config('piecesopen', 1, 'tool_vae');
        set_config('piecenoantivirus', 1, 'tool_vae');
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * One declaration by a candidate, on one of the competencies seeded in setUp().
     *
     * @param \stdClass $user The candidate.
     * @param string $idnumber RNCP36832-C03 or RNCP40692-BC03.
     * @param string $situation
     * @return \stdClass The stored declaration.
     */
    private function declare_as(\stdClass $user, string $idnumber, string $situation): \stdClass {
        global $DB;

        $this->setUser($user);
        $claim = (new evidence_manager())->declare_activity((int) $user->id, (object) [
            'competencyid' => $this->competencies[$idnumber],
            'periodstart' => self::PERIODSTART,
            'periodend' => self::PERIODEND,
            'settingtype' => 'ehpad',
            'situation' => $situation,
            'situationformat' => FORMAT_PLAIN,
        ]);

        return $DB->get_record('tool_vae_evidence', ['id' => $claim->id], '*', MUST_EXIST);
    }

    /**
     * Write a rank straight into the declaration, as an earlier version recorded it.
     *
     * The rank is read from the identification number of the competency, which an administrator types freely in the core's
     * screen of the competencies; the declaration copies it once, and the plugin never asks the person for one. A declaration
     * on a rank outside the format is refused since 0.46.0 (evidence_manager::validate()): only a row recorded before, or
     * written to the database, holds one, and no screen rewrites it.
     *
     * @param \stdClass $claim
     * @param string $rank
     */
    private function write_rank(\stdClass $claim, string $rank): void {
        global $DB;

        $DB->set_field('tool_vae_evidence', 'ficherang', $rank, ['id' => $claim->id]);
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
     * The archives stored in the export area of a candidate.
     *
     * @param int $candidateid
     * @return int
     */
    private static function stored_exports(int $candidateid): int {
        $context = \context_user::instance($candidateid);

        return count(get_file_storage()->get_area_files($context->id, 'tool_vae', exporter::FILEAREA, 0, 'id', false));
    }

    /**
     * The backups held in a user's private files.
     *
     * @param int $userid
     * @return int
     */
    private static function stored_backups(int $userid): int {
        return count(get_file_storage()->get_directory_files(
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
     * The real case: a rank written c7 gives an archive, its register line, its event and its PDF.
     *
     * Before the change: exporter::build() threw errorexportfailed, nothing was registered, stored or logged, and the
     * person met the error page of Moodle on the day the dossier was due.
     */
    public function test_a_rank_written_c7_gives_an_archive_all_the_same(): void {
        global $DB;

        $good = $this->declare_as($this->candidate, 'RNCP40692-BC03', 'Aide a la toilette, respect de la pudeur.');
        $faulty = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $this->write_rank($faulty, 'c7');
        $id = (int) $this->candidate->id;
        $registered = $DB->count_records(exporter::REGISTER);

        $sink = $this->redirectEvents();
        $this->setUser($this->candidate);
        $file = (new exporter())->export($id);
        $events = array_filter($sink->get_events(), fn($event) => $event instanceof dossier_exported);
        $sink->close();

        $this->assertInstanceOf(\stored_file::class, $file);
        $this->assertSame(1, self::stored_exports($id));
        $this->assertSame($registered + 1, $DB->count_records(exporter::REGISTER), 'The archive is registered like any other.');
        $this->assertCount(1, $events, 'The export is logged like any other.');
        $this->assertSame(1, (int) reset($events)->other['warnings'], 'The log says that the archive carries a faulty line.');
        // The log report shows get_description() and nothing of `other`: the count is of no use to an administrator unless the
        // description says it.
        $this->assertStringContainsString(
            'The archive holds 1 line(s) whose format the schema refuses',
            reset($events)->get_description(),
            'The log report does not say that the archive carries a faulty line.'
        );

        $files = self::unzip($file);
        $this->assertArrayHasKey('preuves/' . $faulty->uuid . '.json', $files, 'The faulty declaration is in the archive.');
        $this->assertArrayHasKey('preuves/' . $good->uuid . '.json', $files, 'The valid declaration beside it too.');
        $this->assertArrayHasKey(pdf_renderer::PATH, $files, 'The PDF the panel reads is produced.');
        $claim = json_decode($files['preuves/' . $faulty->uuid . '.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('c7', $claim['fiche']['rang'], 'The rank is written as it stands: nothing is cleaned.');
    }

    /**
     * The warning names the declaration by its code and rank, its file and the value, and nothing else of the dossier.
     */
    public function test_the_warning_names_the_declaration_the_file_and_the_value(): void {
        $this->declare_as($this->candidate, 'RNCP40692-BC03', 'Aide a la toilette, respect de la pudeur.');
        $faulty = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $this->write_rank($faulty, 'c7');

        $this->setUser($this->candidate);
        $exporter = new exporter();
        $this->assertSame([], $exporter->warnings(), 'Nothing is reported before an archive is built.');
        $exporter->export((int) $this->candidate->id);
        $warnings = $exporter->warnings();

        $this->assertCount(1, $warnings, 'The valid declaration is not named.');
        $this->assertSame('preuves/' . $faulty->uuid . '.json', $warnings[0]['file']);
        $this->assertSame('RNCP36832-c7', $warnings[0]['declaration']);
        $this->assertSame('RNCP36832', $warnings[0]['fiche']);
        $this->assertSame('c7', $warnings[0]['rang']);
        $this->assertSame('/fiche/rang', $warnings[0]['field']);
        $this->assertSame('c7', $warnings[0]['value']);
        $this->assertStringContainsString('/fiche/rang', $warnings[0]['error']);

        $lines = exporter::describe_warnings($warnings);
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('RNCP36832-c7', $lines[0]);
        $this->assertStringContainsString('preuves/' . $faulty->uuid . '.json', $lines[0]);
        $this->assertStringContainsString('/fiche/rang', $lines[0]);
    }

    /**
     * Two faulty declarations are two warnings, each naming its own; a dossier without a fault has none.
     */
    public function test_every_faulty_declaration_is_named_and_a_sound_dossier_has_none(): void {
        $first = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $second = $this->declare_as($this->candidate, 'RNCP40692-BC03', 'Aide a la toilette, respect de la pudeur.');

        $this->setUser($this->candidate);
        $sound = new exporter();
        $sound->export((int) $this->candidate->id);
        $this->assertSame([], $sound->warnings(), 'A dossier whose rows the schema accepts is not reported.');

        $this->write_rank($first, 'c7');
        $this->write_rank($second, 'bc03');
        $exporter = new exporter();
        $exporter->export((int) $this->candidate->id);

        $named = array_column($exporter->warnings(), 'declaration', 'file');
        $this->assertSame(
            [
                'preuves/' . $first->uuid . '.json' => 'RNCP36832-c7',
                'preuves/' . $second->uuid . '.json' => 'RNCP40692-bc03',
            ],
            $named
        );
    }

    /**
     * The notice inside the archive carries the mention, and the digests still vouch for it.
     */
    public function test_the_notice_in_the_archive_carries_the_mention(): void {
        $faulty = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $this->write_rank($faulty, 'c7');

        $this->setUser($this->candidate);
        $files = self::unzip((new exporter())->export((int) $this->candidate->id));
        $notice = $files['LISEZMOI.txt'];

        $this->assertStringContainsString(get_string('exportreadmewarning', 'tool_vae'), $notice);
        $this->assertStringContainsString('RNCP36832-c7', $notice, 'The notice names the declaration.');
        $this->assertStringContainsString('/fiche/rang', $notice, 'The notice names the field.');

        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $listed = array_column($manifest['fichiers'], 'sha256', 'chemin');
        $this->assertSame(hash('sha256', $notice), $listed['LISEZMOI.txt'], 'The manifest vouches for the notice as written.');
    }

    /**
     * A sound archive carries no mention, in its notice nor in the log report.
     */
    public function test_a_sound_archive_carries_no_mention(): void {
        $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');

        $sink = $this->redirectEvents();
        $this->setUser($this->candidate);
        $files = self::unzip((new exporter())->export((int) $this->candidate->id));
        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof dossier_exported));
        $sink->close();

        $this->assertStringNotContainsString(get_string('exportreadmewarning', 'tool_vae'), $files['LISEZMOI.txt']);
        $this->assertCount(1, $events);
        $this->assertSame(0, (int) $events[0]->other['warnings']);
        $this->assertStringNotContainsString('schema refuses', $events[0]->get_description(), 'The log report cries wolf.');
    }

    /**
     * An export logged before the count existed has none in its `other`: its description reads as an archive without a fault.
     *
     * The log store keeps what it was given, and `warnings` was added later: the description must not fail on the events
     * already in the table, nor claim a fault they never recorded.
     */
    public function test_an_event_logged_before_the_count_existed_is_described_without_a_fault(): void {
        $event = dossier_exported::create([
            'context' => \context_user::instance((int) $this->candidate->id),
            'objectid' => 1,
            'relateduserid' => (int) $this->candidate->id,
            'userid' => (int) $this->candidate->id,
            'other' => ['declarations' => 1, 'sha256' => str_repeat('a', 64), 'pieces' => 0],
        ]);

        $description = $event->get_description();
        $this->assertStringContainsString("exported the dossier of the user with id '{$this->candidate->id}'", $description);
        $this->assertStringNotContainsString('schema refuses', $description);
    }

    /**
     * The published schema, the manifest and the layout version are not touched: the warning is a mention, not a change.
     */
    public function test_the_published_schema_and_the_manifest_are_not_touched(): void {
        global $CFG;

        $faulty = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $this->write_rank($faulty, 'c7');

        $this->setUser($this->candidate);
        $files = self::unzip((new exporter())->export((int) $this->candidate->id));

        $shipped = $CFG->dirroot . '/admin/tool/vae/docs/schema/';
        $this->assertSame(file_get_contents($shipped . 'preuve.schema.json'), $files['schema/preuve.schema.json']);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(exporter::FORMAT_VERSION, $manifest['version_format']);
        $this->assertSame(exporter::SCHEMA_VERSION, $manifest['schema_preuve']);
        $this->assertSame(get_string('exportwithdrawalnotice', 'tool_vae'), $manifest['avertissement']);
        $this->assertArrayNotHasKey('avertissements', $manifest);
    }

    /**
     * The import stays strict: it refuses the archive whole, the valid declaration beside the faulty one with it.
     *
     * This is what the warning says to the person, and why: an archive that holds a line the schema refuses cannot be
     * reimported as it stands.
     */
    public function test_the_import_still_refuses_the_archive_whole(): void {
        global $DB;

        $this->declare_as($this->candidate, 'RNCP40692-BC03', 'Aide a la toilette, respect de la pudeur.');
        $faulty = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $this->write_rank($faulty, 'c7');
        $id = (int) $this->candidate->id;

        $this->setUser($this->candidate);
        $files = self::unzip((new exporter())->export($id));
        purge::dossier($id);
        $this->assertSame(0, $DB->count_records('tool_vae_evidence', ['candidateid' => $id]));

        $this->setAdminUser();
        try {
            (new dossier_importer())->import_files($id, $files);
            $this->fail('An archive holding a line the schema refuses was imported.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorarchiveschema', $e->errorcode);
            $this->assertSame('preuves/' . $faulty->uuid . '.json', $e->a->file);
        }
        $this->assertSame(0, $DB->count_records('tool_vae_evidence', ['candidateid' => $id]), 'Nothing was restored.');
    }

    /**
     * Nothing of another candidate is in the warning, whoever exports.
     */
    public function test_the_warning_carries_nothing_of_another_candidate(): void {
        $mine = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $theirs = $this->declare_as($this->other, 'RNCP36832-C03', 'Preparation des repas, regime sans sel.');
        $this->write_rank($theirs, 'c7');

        $this->setUser($this->candidate);
        $exporter = new exporter();
        $exporter->export((int) $this->candidate->id);
        $this->assertSame([], $exporter->warnings(), 'The fault of somebody else is not reported to this candidate.');

        // The direction exports the dossier of the other candidate: it is told of that dossier, and of that one alone.
        $this->setUser($this->director);
        $exporter = new exporter();
        $exporter->export((int) $this->other->id);
        $encoded = json_encode($exporter->warnings(), JSON_THROW_ON_ERROR);
        $notice = implode("\n", exporter::describe_warnings($exporter->warnings()));

        $this->assertCount(1, $exporter->warnings());
        $this->assertSame('preuves/' . $theirs->uuid . '.json', $exporter->warnings()[0]['file']);
        foreach ([$encoded, $notice] as $text) {
            $this->assertStringNotContainsString($mine->uuid, $text);
            $this->assertStringNotContainsString('Camille', $text);
            $this->assertStringNotContainsString('Accompagnement', $text);
        }
    }

    /**
     * The lines listed are bounded, and say how many more there are.
     */
    public function test_the_lines_are_bounded_and_say_how_many_more(): void {
        $warnings = [];
        for ($index = 0; $index < exporter::WARNINGS_LISTED + 2; $index++) {
            $warnings[] = [
                'file' => 'preuves/' . identity_map::generate_ulid() . '.json',
                'declaration' => 'RNCP36832-c7',
                'fiche' => 'RNCP36832',
                'rang' => 'c7',
                'field' => '/fiche/rang',
                'value' => 'c7',
                'error' => '/fiche/rang does not match ^[A-Z0-9]{1,8}$',
            ];
        }

        $lines = exporter::describe_warnings($warnings);

        $this->assertCount(exporter::WARNINGS_LISTED + 1, $lines);
        $this->assertSame(get_string('exportwarningmore', 'tool_vae', 2), end($lines));
        $this->assertSame([], exporter::describe_warnings([]));
        $exactly = array_slice($warnings, 0, exporter::WARNINGS_LISTED);
        $this->assertCount(exporter::WARNINGS_LISTED, exporter::describe_warnings($exactly), 'Nothing left out, no summary.');
    }

    /**
     * A value an administrator typed, markup included, is given as plain text: the screens escape it, the notice writes it.
     *
     * The rank `<b>x` is one of the three the tests of the scope already use for what the core's screen lets through. The
     * warning does not clean it, and does not interpret it: describe_warnings() and the notice give it as it stands, and the
     * one helper both pages use, warnings_html(), escapes the lead and every line. The export page and the backup page are
     * not run from here, so what makes the escaping stand is that they call that helper and nothing else (see the test of
     * the pages below), and that the helper is tested here.
     */
    public function test_a_value_with_markup_is_given_as_plain_text(): void {
        $faulty = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $this->write_rank($faulty, '<b>x');

        $this->setUser($this->candidate);
        $exporter = new exporter();
        $files = self::unzip($exporter->export((int) $this->candidate->id));

        $this->assertCount(1, $exporter->warnings());
        $this->assertSame('<b>x', $exporter->warnings()[0]['value']);
        $this->assertSame('RNCP36832-<b>x', $exporter->warnings()[0]['declaration']);
        $this->assertStringContainsString('<b>x', exporter::describe_warnings($exporter->warnings())[0]);
        $this->assertStringContainsString('<b>x', $files['LISEZMOI.txt'], 'The notice is plain text.');

        // What the pages show: the same lines in HTML, with nothing a browser would read as markup. The lead is escaped too.
        $html = exporter::warnings_html($exporter->warnings(), 'Lead with <i>markup</i>');
        $this->assertStringContainsString(s('<b>x'), $html, 'The value is shown as text.');
        $this->assertStringNotContainsString('<b>', $html, 'The value was read as markup.');
        $this->assertStringContainsString(s('Lead with <i>markup</i>'), $html, 'The lead is shown as text.');
        $this->assertStringNotContainsString('<i>', $html, 'The lead was read as markup.');
        $this->assertSame(1, substr_count($html, '<li'), 'One line, one item.');
        $this->assertStringStartsWith('<p>', $html, 'The lead comes first.');
    }

    /**
     * Both pages give the lines through exporter::warnings_html(), and the page of the backup ends in a redirect.
     *
     * The pages cannot be run from here (the export page has a Behat scenario; the backup page has no page name in Behat), so
     * what is asserted is what they ask for, as the test of the page after an import does. A page that formats the lines itself
     * escapes them or not on its own account, with nothing to tell: that is how a missing escape went unseen. And the backup
     * page, after the POST that makes the copy, shows no page of its own: a page shown there is the answer to a POST, and
     * reloading it - with the confirmation of the browser - makes another copy. Both messages travel as notifications of the
     * session, the one that does not promise the reimport and the warning that says why.
     */
    public function test_the_pages_give_the_lines_through_the_one_helper_and_the_backup_page_redirects(): void {
        global $CFG;

        foreach (['export.php', 'backup.php'] as $name) {
            $page = file_get_contents($CFG->dirroot . '/admin/tool/vae/' . $name);
            $this->assertStringContainsString('exporter::warnings_html(', $page, $name);
            $this->assertStringNotContainsString('describe_warnings(', $page, $name . ' formats the lines itself.');
            $this->assertStringNotContainsString("array_map('s'", $page, $name . ' escapes the lines itself.');
        }

        // Read with LF line ends, whichever the checkout writes.
        $page = str_replace("\r", '', file_get_contents($CFG->dirroot . '/admin/tool/vae/backup.php'));
        $start = strpos($page, 'if ($confirm) {');
        $this->assertNotFalse($start, 'The confirmed branch of the backup page was not found.');
        $end = strpos($page, "\n}\n", $start);
        $this->assertNotFalse($end, 'The end of the confirmed branch of the backup page was not found.');
        $lines = array_map('trim', explode("\n", substr($page, $start, $end - $start)));
        $code = implode("\n", array_filter($lines, fn($line) => !str_starts_with($line, '//')));

        $this->assertStringContainsString('redirect($dossierurl)', $code, 'The warned backup does not end in a redirect.');
        $this->assertStringContainsString('\core\notification::success(', $code);
        $this->assertStringContainsString("'backupdossierdonewarned'", $code);
        $this->assertStringContainsString('\core\notification::warning(', $code);
        $this->assertStringContainsString('exporter::warnings_html(', $code);
        $this->assertStringNotContainsString('echo ', $code, 'A page is shown after the POST: reloading it makes another backup.');
        $this->assertStringNotContainsString('die(', $code, 'A page is shown after the POST: reloading it makes another backup.');
    }

    /**
     * A value that is empty is not told as a field that is missing.
     *
     * The real row: a rank that is the empty string, which the schema refuses (/fiche/rang does not match). The field is in the
     * file and holds nothing; describe_warnings() wrote "(missing)" for it, as for a field that is not in the file at all, and
     * the reader looked for a field that was there.
     */
    public function test_a_value_that_is_empty_is_not_told_as_a_field_that_is_missing(): void {
        $faulty = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $this->write_rank($faulty, '');

        $this->setUser($this->candidate);
        $exporter = new exporter();
        $exporter->export((int) $this->candidate->id);
        $warnings = $exporter->warnings();

        $this->assertCount(1, $warnings);
        $this->assertSame('/fiche/rang', $warnings[0]['field']);
        $this->assertSame('', $warnings[0]['value'], 'The field is in the file, and empty: that is not null.');
        $empty = exporter::describe_warnings($warnings)[0];

        // The same line for a field the file does not hold at all.
        $missing = exporter::describe_warnings([array_merge($warnings[0], ['value' => null])])[0];

        $this->assertNotSame($missing, $empty, 'An empty value and a missing field are told the same.');
        $this->assertStringContainsString(get_string('exportwarningnovalue', 'tool_vae'), $missing);
        $this->assertStringContainsString(get_string('exportwarningemptyvalue', 'tool_vae'), $empty);
        $this->assertStringNotContainsString(get_string('exportwarningnovalue', 'tool_vae'), $empty);
    }

    /**
     * The backup the page takes goes through the same way, with the warning given back to the page.
     */
    public function test_the_backup_of_the_page_goes_through_with_the_same_warning(): void {
        $faulty = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $this->write_rank($faulty, 'c7');

        $this->setAdminUser();
        $admin = (int) get_admin()->id;
        $exporter = new exporter();
        $file = backup::save((int) $this->candidate->id, $admin, true, $exporter);

        $this->assertSame(1, self::stored_backups($admin));
        $this->assertCount(1, $exporter->warnings());
        $this->assertSame('RNCP36832-c7', $exporter->warnings()[0]['declaration']);
        $files = self::unzip($file);
        $this->assertStringContainsString(get_string('exportreadmewarning', 'tool_vae'), $files['LISEZMOI.txt']);

        // A caller that passes no exporter gets the same backup, as before.
        $again = backup::save((int) $this->candidate->id, $admin);
        $this->assertInstanceOf(\stored_file::class, $again);
        $this->assertSame(2, self::stored_backups($admin));
    }

    /**
     * The backup an uninstall takes, which never refused, carries the mention too.
     */
    public function test_the_backup_of_an_uninstall_carries_the_mention_too(): void {
        $faulty = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $this->write_rank($faulty, 'c7');

        $this->setAdminUser();
        $file = backup::save((int) $this->candidate->id, (int) get_admin()->id, false);

        $this->assertStringContainsString(get_string('exportreadmewarning', 'tool_vae'), self::unzip($file)['LISEZMOI.txt']);
    }

    /**
     * The strings of the plugin in one language, read from its own file.
     *
     * Not through the string manager: the French pack is not installed in the test site, and the manager then answers in English
     * whatever language is asked, which would make every French check read English text.
     *
     * @param string $lang en or fr.
     * @return string[] Identifier => text.
     */
    private static function strings_of(string $lang): array {
        $string = [];
        include(__DIR__ . '/../lang/' . $lang . '/tool_vae.php');

        return $string;
    }

    /**
     * The three texts that tell of a faulty line say where it comes from and that Moodle cannot correct it.
     *
     * The real case: a row recorded with the rank `c7`, which a correction of the identification number of the competency does
     * not rewrite - the rank is copied once, to the declaration and to two other tables, and no screen writes it again. The
     * texts of the export page, of the backup page and of the notice inside the archive asked the administrator to correct that
     * number, and the first two went on to say that it changes nothing: a remedy and its refutation in one paragraph. They now
     * give the origin (a row recorded by an earlier version, or written to the database), say that Moodle cannot correct it,
     * and promise no manoeuvre. A declaration on such a rank is refused since 0.46.0, and no text says that an administrator
     * "most often" made the line. The words are written out here on purpose: changing the wording means changing them too.
     */
    public function test_the_texts_about_a_faulty_line_promise_no_correction(): void {
        $expected = [
            'en' => ['earlier version of the plugin', 'cannot be corrected from Moodle'],
            'fr' => ['version antérieure du plugin', 'ne se corrige pas depuis Moodle'],
        ];
        $banned = [
            'en' => ['must correct', 'Correct the identification number', 'most often'],
            'fr' => ['doit corriger', 'Corrigez le numéro', 'corriger le numéro', 'le plus souvent', 'candidat'],
        ];

        foreach (['en', 'fr'] as $lang) {
            $strings = self::strings_of($lang);
            foreach (['exportwarning', 'backupdossierwarning', 'exportreadmewarning'] as $identifier) {
                $this->assertArrayHasKey($identifier, $strings);
                foreach ($expected[$lang] as $words) {
                    $this->assertStringContainsString($words, $strings[$identifier], "$lang $identifier");
                }
                foreach ($banned[$lang] as $words) {
                    $this->assertStringNotContainsString($words, $strings[$identifier], "$lang $identifier");
                }
            }
            // The export screen still says what the person can do instead: the editable copy of the dossier.
            $this->assertStringContainsString(
                $lang === 'fr' ? 'Word ou OpenDocument' : 'Word or OpenDocument',
                $strings['exportwarning'],
                $lang
            );
        }
    }

    /**
     * A piece whose file is lost or altered still refuses the export and the backup of the page, with or without a faulty row.
     *
     * The protection that stands in front of the schema check: kept as it was. The file is replaced by another one under the
     * same name, as the existing test of the pieces does.
     */
    public function test_a_piece_altered_still_refuses_the_export_and_the_backup(): void {
        global $DB;

        $claim = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $id = (int) $this->candidate->id;
        $path = make_request_directory() . '/upload';
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog /Title (a) >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $this->setUser($this->candidate);
        $piece = (new piece_manager())->add_file((int) $claim->id, 'contract', 'Un contrat', $path, 'contrat.pdf');

        $context = \context_user::instance($id);
        $fs = get_file_storage();
        $coordinates = [
            'contextid' => $context->id, 'component' => 'tool_vae', 'filearea' => piece_manager::FILEAREA,
            'itemid' => (int) $piece->id, 'filepath' => '/', 'filename' => $piece->uuid . '.pdf',
        ];
        $fs->get_file(...array_values($coordinates))->delete();
        $fs->create_file_from_string($coordinates, "%PDF-1.4\n1 0 obj << /Type /Catalog /Title (autre) >> endobj\n%%EOF\n");

        $registered = $DB->count_records(exporter::REGISTER);
        $admin = (int) get_admin()->id;
        foreach ([false, true] as $faultyrow) {
            if ($faultyrow) {
                $this->write_rank($claim, 'c7');
            }

            $this->setUser($this->candidate);
            $sink = $this->redirectEvents();
            try {
                (new exporter())->export($id);
                $this->fail('An altered file went into an archive.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errorpiecealtered', $e->errorcode);
                $this->assertStringContainsString($piece->uuid, (string) $e->a);
            }
            $this->assertCount(0, $sink->get_events(), 'A refused export was logged.');
            $sink->close();

            $this->setAdminUser();
            try {
                backup::save($id, $admin);
                $this->fail('An altered file went into a backup.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errorpiecealtered', $e->errorcode);
            }

            $this->assertSame($registered, $DB->count_records(exporter::REGISTER), 'A refusal left a line in the register.');
            $this->assertSame(0, self::stored_exports($id));
            $this->assertSame(0, self::stored_backups($admin));
        }
    }

    /**
     * A dossier too large to be read back still refuses the export, even when a row is also faulty.
     *
     * Two files of 26 MiB, written straight into the area of the pieces: the pages of the plugin cap what a candidate keeps
     * at 30 MiB, so the limit of the import (50 MiB) is out of reach through them, and a test cannot make an honest dossier
     * of that size. The count of files is the other limit; it is tested on its own in exporter_test.
     *
     * Before the change, the schema check stood in front of this one: a dossier both faulty and too large was refused for its
     * rank (errorexportfailed), and the warning would have hidden nothing - this test pins that the size is still the reason.
     */
    public function test_a_dossier_too_large_still_refuses_even_with_a_faulty_row(): void {
        global $DB;

        raise_memory_limit(MEMORY_HUGE);
        $claim = $this->declare_as($this->candidate, 'RNCP36832-C03', 'Accompagnement au lever, recherche de son accord.');
        $this->write_rank($claim, 'c7');
        $id = (int) $this->candidate->id;
        $context = \context_user::instance($id);
        $fs = get_file_storage();
        foreach (['1', '2'] as $filler) {
            $content = "%PDF-1.4\n%" . str_repeat($filler, 26 * 1048576) . "\n%%EOF\n";
            $row = (object) [
                'uuid' => identity_map::generate_ulid(), 'evidenceid' => (int) $claim->id, 'candidateid' => $id,
                'kind' => piece_manager::KIND_FILE, 'nature' => 'contract', 'title' => '', 'ext' => 'pdf',
                'mimetype' => 'application/pdf', 'filesize' => strlen($content), 'sha256' => hash('sha256', $content),
                'timecreated' => time(), 'usercreated' => $id,
            ];
            $row->id = $DB->insert_record('tool_vae_piece', $row);
            $fs->create_file_from_string([
                'contextid' => $context->id, 'component' => 'tool_vae', 'filearea' => piece_manager::FILEAREA,
                'itemid' => (int) $row->id, 'filepath' => '/', 'filename' => $row->uuid . '.pdf',
            ], $content);
            unset($content);
        }
        $registered = $DB->count_records(exporter::REGISTER);

        $this->setUser($this->candidate);
        $sink = $this->redirectEvents();
        try {
            (new exporter())->export($id);
            $this->fail('A dossier too large to be read back was exported.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorexporttoolarge', $e->errorcode);
        }
        $this->assertCount(0, $sink->get_events(), 'A refused export was logged.');
        $sink->close();
        $this->assertSame($registered, $DB->count_records(exporter::REGISTER), 'A refusal left a line in the register.');
        $this->assertSame(0, self::stored_exports($id));
    }
}
