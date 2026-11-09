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

use tool_vae\event\spiece_added;
use tool_vae\event\spiece_read;
use tool_vae\event\spiece_removed;
use tool_vae\local\piece_manager;
use tool_vae\local\situation_manager;
use tool_vae\local\spiece_manager;
use tool_vae\local\validation_catalog;

/**
 * The supporting pieces of the work situations (lot 1b, task 6).
 *
 * WHAT IS ASSERTED. That a piece of a situation follows the rules of a piece of a declaration - type read on the content,
 * antivirus, the name of the file never kept, a link never visited, removal that erases the file, the title and the address
 * and keeps the rest -; that one piece may serve several situations, each link with its own part concerned, and counts once
 * against the quota it shares with the pieces of the declarations; that deleting a situation leaves the piece; that the vaeiste
 * alone writes and whoever may view the dossier opens the file; that the events carry identifiers only.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(spiece_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\spiece_added::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\spiece_removed::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\spiece_read::class)]
final class spiece_manager_test extends \advanced_testcase {
    /** @var \stdClass The vaeiste: viewown, draftown and declare on their own context. */
    private $vaeiste;

    /** @var \stdClass Another vaeiste, with the same rights on their own context. */
    private $othervaeiste;

    /** @var \stdClass Follows the vaeiste (viewassigned in their context). */
    private $manager;

    /** @var \stdClass Holds viewany at the site. */
    private $direction;

    /** @var \stdClass A first situation of the vaeiste on RNCP100. */
    private $first;

    /** @var \stdClass A second situation of the vaeiste on RNCP100. */
    private $second;

    /** @var int A declaration of the vaeiste, inserted as a row. */
    private $evidenceid;

    /**
     * RNCP100 with one bloc; the people; two situations of the vaeiste; a declaration; adding open, no antivirus needed.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        validation_catalog::reset();
        set_config('validationopen', 1, 'tool_vae');
        set_config('piecesopen', 1, 'tool_vae');
        set_config('piecenoantivirus', 1, 'tool_vae');

        $datagenerator = $this->getDataGenerator();
        $generator = $datagenerator->get_plugin_generator('tool_vae');
        $system = \context_system::instance()->id;

        $generator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        $generator->create_competency([
            'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'shortname' => 'Competence C01', 'bloc' => 'BC01',
        ]);

        $this->vaeiste = $datagenerator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->othervaeiste = $datagenerator->create_user(['firstname' => 'Sacha', 'lastname' => 'Autre']);
        $this->manager = $datagenerator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->direction = $datagenerator->create_user(['firstname' => 'Dana', 'lastname' => 'Direction']);

        $own = $datagenerator->create_role();
        foreach (['tool/vae:viewown', 'tool/vae:draftown', 'tool/vae:declare'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $own, $system, true);
        }
        set_role_contextlevels($own, [CONTEXT_USER]);
        foreach ([$this->vaeiste, $this->othervaeiste] as $person) {
            role_assign($own, $person->id, \context_user::instance($person->id)->id);
        }

        $follow = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, \context_user::instance($this->vaeiste->id)->id);

        $director = $datagenerator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $director, $system, true);
        role_assign($director, $this->direction->id, $system);
        accesslib_clear_all_caches_for_unit_testing();

        $this->first = $generator->create_situation(['userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100']);
        $this->second = $generator->create_situation(['userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100']);

        $now = time();
        $this->evidenceid = (int) $DB->insert_record('tool_vae_evidence', (object) [
            'uuid' => local\identity_map::generate_ulid(),
            'userevidenceid' => 1,
            'candidateid' => (int) $this->vaeiste->id,
            'periodstart' => $now - 1000,
            'periodend' => $now,
            'settingtype' => 'ehpad',
            'situationformat' => FORMAT_PLAIN,
            'situation' => 'Une situation.',
            'fichecode' => 'RNCP100',
            'ficherang' => 'C01',
            'ficheupdated' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int) $this->vaeiste->id,
        ]);

        $this->setUser($this->vaeiste);
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
     * @param string $name The name of the file on the disk; never handed to the API.
     * @return string Its path.
     */
    private function file(string $bytes, string $name = ''): string {
        $directory = make_request_directory();
        $path = $directory . '/' . ($name !== '' ? $name : uniqid('upload', true));
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * How many files a piece of the vaeiste has in the area.
     *
     * @param int $pieceid
     * @return int
     */
    private function stored(int $pieceid): int {
        $contextid = \context_user::instance($this->vaeiste->id)->id;

        return count(get_file_storage()->get_area_files($contextid, 'tool_vae', 'spiece', $pieceid, 'id', false));
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
     * A zip that is no document.
     *
     * @return string
     */
    private function zip(): string {
        $path = make_request_directory() . '/' . uniqid('zip', true);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('a.txt', 'x');
        $zip->close();

        return (string) file_get_contents($path);
    }

    public function test_a_piece_cited_by_two_situations_counts_once_against_the_shared_quota(): void {
        $manager = new spiece_manager();
        set_config('piecemaxpercandidate', 1048576, 'tool_vae');

        $bytes = $this->pdf(str_repeat('x', 600 * 1024));
        $piece = $manager->add_file((int) $this->first->id, 'writing', '', $this->file($bytes), '');
        $manager->attach((int) $this->second->id, (int) $piece->id, '');

        $this->assertCount(2, $manager->links_of((int) $piece->id));
        $this->assertSame(strlen($bytes), piece_manager::used_bytes((int) $this->vaeiste->id), 'One piece, counted once.');

        // A piece of a declaration of 500 KB would go over the quota both kinds of piece share.
        $this->assert_refused(
            fn() => (new piece_manager())->add_file(
                $this->evidenceid,
                'other',
                '',
                $this->file($this->pdf(str_repeat('y', 500 * 1024))),
                'a.pdf'
            ),
            'errorpiecequota'
        );
        // And so would a second piece of a situation.
        $this->assert_refused(
            fn() => $manager->add_file(
                (int) $this->second->id,
                'other',
                '',
                $this->file($this->pdf(str_repeat('z', 500 * 1024))),
                ''
            ),
            'errorpiecequota'
        );
    }

    public function test_the_part_concerned_is_kept_on_each_link(): void {
        $manager = new spiece_manager();
        $piece = $manager->add_file((int) $this->first->id, 'contract', 'Contrat', $this->file($this->pdf()), '  page 3  ');
        $manager->attach((int) $this->second->id, (int) $piece->id, 'article 4, les horaires');

        $first = $manager->for_situation((int) $this->first->id);
        $second = $manager->for_situation((int) $this->second->id);
        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame('page 3', $first[0]->part);
        $this->assertSame('article 4, les horaires', $second[0]->part);
        $this->assertSame((int) $piece->id, (int) $first[0]->piece->id);
        $this->assertSame((int) $piece->id, (int) $second[0]->piece->id);

        // Within its width, without a control character; empty allowed.
        $this->assertSame('', $manager->add_link((int) $this->first->id, 'other', '', 'https://exemple.test/a', '')->link->part);
        $this->assertSame(
            str_repeat('é', 255),
            $manager->add_link((int) $this->first->id, 'other', '', 'https://exemple.test/b', str_repeat('é', 255))->link->part
        );
        foreach ([str_repeat('a', 256), "page 3\nligne 2", "page\x07"] as $part) {
            $this->assert_refused(
                fn() => $manager->add_link((int) $this->first->id, 'other', '', 'https://exemple.test/c', $part),
                'errorpiecepart'
            );
        }
        $link = $manager->add_link((int) $this->second->id, 'other', '', 'https://exemple.test/d', '');
        $this->assert_refused(
            fn() => $manager->attach((int) $this->first->id, (int) $link->id, str_repeat('a', 256)),
            'errorpiecepart'
        );
    }

    public function test_removing_the_piece_shows_removed_on_both_links_and_keeps_the_trace(): void {
        global $DB;
        $manager = new spiece_manager();
        $bytes = $this->pdf();
        $piece = $manager->add_file((int) $this->first->id, 'contract', 'Titre qui nomme X', $this->file($bytes), 'p. 2');
        $manager->attach((int) $this->second->id, (int) $piece->id, 'p. 5');
        $this->assertSame(1, $this->stored((int) $piece->id));

        $sink = $this->redirectEvents();
        $gone = $manager->remove((int) $piece->id);
        $this->assertSame('', $gone->title);
        $this->assertNotNull($gone->timeremoved);
        $this->assertSame(0, $this->stored((int) $piece->id));

        $row = $DB->get_record('tool_vae_spiece', ['id' => $piece->id]);
        $this->assertSame(hash('sha256', $bytes), $row->sha256, 'The digest stays.');
        $this->assertSame('contract', $row->nature);
        $this->assertSame('pdf', $row->ext);
        $this->assertSame('application/pdf', $row->mimetype);
        $this->assertSame(strlen($bytes), (int) $row->filesize);
        $this->assertSame((int) $piece->timecreated, (int) $row->timecreated);
        $this->assertSame('', $row->title);

        // Both links stay, and say the piece was removed.
        foreach ([$this->first, $this->second] as $situation) {
            $links = $manager->for_situation((int) $situation->id);
            $this->assertCount(1, $links);
            $this->assertNotNull($links[0]->piece->timeremoved);
        }
        $this->assertSame(0, piece_manager::used_bytes((int) $this->vaeiste->id));
        // The list of the situations counts the pieces that stand: the link stays, the removed piece is not counted.
        $listed = (new situation_manager())->list((int) $this->vaeiste->id, 'RNCP100');
        $this->assertSame([0, 0], array_map(fn($one) => $one->pieces, array_values($listed)));

        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof spiece_removed));
        $this->assertCount(1, $events);
        $this->assertSame((int) $piece->id, (int) $events[0]->objectid);
        $this->assertStringNotContainsString('nomme X', json_encode($events[0]->get_data()) . $events[0]->get_description());

        // A link: the address goes too.
        $link = $manager->add_link((int) $this->first->id, 'other', 'Un lien', 'https://exemple.test/nom-de-quelquun', '');
        $manager->remove((int) $link->id);
        $this->assertNull($DB->get_field('tool_vae_spiece', 'url', ['id' => $link->id]));
        $this->assertSame('', $DB->get_field('tool_vae_spiece', 'title', ['id' => $link->id]));

        $this->assert_refused(fn() => $manager->remove((int) $piece->id), 'errorpiecegone');
        $this->assert_refused(fn() => $manager->attach((int) $this->first->id, (int) $link->id, ''), 'errorpiecegone');
    }

    public function test_deleting_a_situation_detaches_but_keeps_the_piece(): void {
        global $DB;
        $manager = new spiece_manager();
        $piece = $manager->add_file((int) $this->first->id, 'other', 'Un titre', $this->file($this->pdf()), '');
        $manager->attach((int) $this->second->id, (int) $piece->id, '');

        (new situation_manager())->delete((int) $this->first->id);

        $links = $manager->links_of((int) $piece->id);
        $this->assertCount(1, $links);
        $this->assertSame((int) $this->second->id, (int) reset($links)->situationid);
        $row = $DB->get_record('tool_vae_spiece', ['id' => $piece->id]);
        $this->assertNull($row->timeremoved);
        $this->assertSame('Un titre', $row->title);
        $this->assertSame(1, $this->stored((int) $piece->id));
        $this->assertSame((int) $piece->filesize, piece_manager::used_bytes((int) $this->vaeiste->id));
        $this->assertSame(
            [(int) $piece->id],
            array_map(fn($one) => (int) $one->id, array_values($manager->for_candidate((int) $this->vaeiste->id, 'RNCP100')))
        );

        // Deleting the other one too leaves a piece that serves none, still there to be attached again.
        (new situation_manager())->delete((int) $this->second->id);
        $this->assertSame([], $manager->links_of((int) $piece->id));
        $this->assertNull($DB->get_field('tool_vae_spiece', 'timeremoved', ['id' => $piece->id]));
        $third = (new situation_manager())->create((int) $this->vaeiste->id, 'RNCP100');
        $this->assertSame((int) $piece->id, (int) $manager->attach((int) $third->id, (int) $piece->id, '')->pieceid);
    }

    public function test_a_sixth_active_piece_on_a_situation_is_refused(): void {
        $manager = new spiece_manager();
        $id = (int) $this->first->id;
        $pieces = [];
        for ($i = 1; $i <= piece_manager::MAX_ACTIVE; $i++) {
            $pieces[] = $manager->add_link($id, 'other', '', 'https://exemple.test/' . $i, '');
        }
        $this->assert_refused(fn() => $manager->add_link($id, 'other', '', 'https://exemple.test/6', ''), 'errorpiecelimit');
        $this->assert_refused(
            fn() => $manager->add_file($id, 'other', '', $this->file($this->pdf()), ''),
            'errorpiecelimit'
        );
        $other = $manager->add_link((int) $this->second->id, 'other', '', 'https://exemple.test/7', '');
        $this->assert_refused(fn() => $manager->attach($id, (int) $other->id, ''), 'errorpiecelimit');

        // A piece detached, or removed, makes room again.
        $manager->detach($id, (int) $pieces[0]->id);
        $this->assertCount(piece_manager::MAX_ACTIVE - 1, $manager->for_situation($id));
        $manager->attach($id, (int) $other->id, '');
        $this->assert_refused(fn() => $manager->add_link($id, 'other', '', 'https://exemple.test/8', ''), 'errorpiecelimit');
        $manager->remove((int) $pieces[1]->id);
        $this->assertSame('link', $manager->add_link($id, 'other', '', 'https://exemple.test/9', '')->kind);

        // A detached link comes back as it was, with the part it is given now.
        $manager->detach($id, (int) $pieces[2]->id);
        $back = $manager->attach($id, (int) $pieces[2]->id, 'de nouveau');
        $this->assertNull($back->timeremoved);
        $this->assertSame('de nouveau', $back->part);
        $this->assertSame(1, $GLOBALS['DB']->count_records('tool_vae_slink', ['situationid' => $id, 'pieceid' => $pieces[2]->id]));
    }

    public function test_a_refused_type_a_kept_name_and_a_visited_link_never_happen(): void {
        global $DB;
        $manager = new spiece_manager();
        $id = (int) $this->first->id;

        $this->assert_refused(
            fn() => $manager->add_file($id, 'other', '', $this->file($this->zip(), 'a.docx'), ''),
            'errorpiecetype'
        );
        $this->assert_refused(
            fn() => $manager->add_file($id, 'other', '', $this->file('<html><script>x</script></html>', 'a.pdf'), ''),
            'errorpiecetype'
        );
        $this->assert_refused(
            fn() => $manager->add_file($id, 'other', '', $this->file($this->pdf('/JavaScript (app.alert(1))'), 'a.pdf'), ''),
            'errorpiecemacro'
        );
        $this->assertSame(0, $DB->count_records('tool_vae_spiece'));

        // The type is read on the content; the name of the file on the disk is nowhere.
        $piece = $manager->add_file($id, 'other', '', $this->file($this->pdf(), 'Projet_Mme_X.pdf'), '');
        $this->assertSame('pdf', $piece->ext);
        $this->assertSame('application/pdf', $piece->mimetype);
        $png = $manager->add_file($id, 'other', '', $this->file($this->png(), 'Photo_Mme_X.exe'), '');
        $this->assertSame('png', $png->ext);
        $this->assertSame(26, strlen($piece->uuid));
        foreach ($DB->get_records('tool_vae_spiece') as $row) {
            $this->assertStringNotContainsString('Mme', json_encode($row));
        }
        $contextid = \context_user::instance($this->vaeiste->id)->id;
        $files = get_file_storage()->get_area_files($contextid, 'tool_vae', 'spiece', false, 'id', false);
        $this->assertCount(2, $files);
        foreach ($files as $file) {
            $this->assertStringNotContainsString('Mme', $file->get_filename());
            $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}\.(pdf|png)$/', $file->get_filename());
        }
        $this->assertSame(0, $DB->count_records_select('files', $DB->sql_like('filename', ':name'), ['name' => '%Mme%']));

        // A link is kept as given, never fetched: an address of a test domain that does not resolve is accepted as it is.
        $link = $manager->add_link($id, 'other', '', 'https://exemple.test/ne-sera-jamais-visite', '');
        $this->assertSame('https://exemple.test/ne-sera-jamais-visite', $link->url);
        $this->assertSame([], $link->warnings);
        $query = $manager->add_link((int) $this->second->id, 'other', '', 'https://exemple.test/p?t=1', '');
        $this->assertSame(['piecewarnquery'], $query->warnings);
        $this->assert_refused(fn() => $manager->add_link($id, 'other', '', 'javascript:alert(1)', ''), 'errorpieceurl');
    }

    public function test_without_antivirus_the_file_is_refused_and_the_link_accepted(): void {
        set_config('piecenoantivirus', 0, 'tool_vae');
        $manager = new spiece_manager();
        $id = (int) $this->first->id;

        $this->assert_refused(fn() => $manager->add_file($id, 'other', '', $this->file($this->pdf()), ''), 'errorpiecenoantivirus');
        $this->assertSame(0, $GLOBALS['DB']->count_records('tool_vae_spiece', ['kind' => 'file']));
        $this->assertSame('link', $manager->add_link($id, 'other', '', 'https://exemple.test/x', '')->kind);

        set_config('piecenoantivirus', 1, 'tool_vae');
        $this->assertSame('file', $manager->add_file($id, 'other', '', $this->file($this->pdf()), '')->kind);
    }

    public function test_the_writing_nature_is_accepted_here_and_refused_on_a_declaration_piece(): void {
        $this->assertSame([...piece_manager::NATURES, 'writing'], spiece_manager::NATURES);
        $this->assertNotContains('writing', piece_manager::NATURES);

        $manager = new spiece_manager();
        $id = (int) $this->first->id;
        $piece = $manager->add_link($id, spiece_manager::NATURE_WRITING, 'Un écrit', 'https://exemple.test/x', '');
        $this->assertSame('writing', $piece->nature);
        $this->assertSame('Professional writing', get_string('piecenature:writing', 'tool_vae'));
        // The French string is read from lang/fr directly, as the other tests of this plugin do: the test site runs in English.
        $french = (function (): array {
            $string = [];
            require(__DIR__ . '/../lang/fr/tool_vae.php');

            return $string;
        })();
        $this->assertSame('Écrit professionnel', $french['piecenature:writing']);

        $this->assert_refused(
            fn() => (new piece_manager())->add_link($this->evidenceid, 'writing', '', 'https://exemple.test/x'),
            'errorpiecenature'
        );
        $this->assert_refused(
            fn() => $manager->add_link((int) $this->first->id, 'roman', '', 'https://exemple.test/x', ''),
            'errorpiecenature'
        );
        $this->assert_refused(
            fn() => $manager->add_link((int) $this->first->id, 'other', str_repeat('a', 61), 'https://exemple.test/x', ''),
            'errorpiecetitle'
        );
    }

    public function test_whoever_may_view_the_dossier_opens_the_file_and_the_other_vaeiste_does_not(): void {
        $piece = (new spiece_manager())->add_file((int) $this->first->id, 'other', 'Un titre', $this->file($this->pdf()), '');
        $context = \context_user::instance($this->vaeiste->id);

        $this->assertTrue(spiece_manager::may_open($piece, (int) $this->vaeiste->id));
        $this->assertTrue(spiece_manager::may_open($piece, (int) $this->manager->id), 'Who follows the vaeiste.');
        $this->assertTrue(spiece_manager::may_open($piece, (int) $this->direction->id), 'Who may view every dossier.');
        $this->assertFalse(
            spiece_manager::may_open($piece, (int) get_admin()->id),
            'The preparation of the validation dossier is closed to the site administrator (D-1b-G).'
        );
        $this->assertFalse(spiece_manager::may_open($piece, (int) $this->othervaeiste->id));
        $this->assertFalse(spiece_manager::may_open($piece, (int) $this->getDataGenerator()->create_user()->id));

        $sink = $this->redirectEvents();
        $this->setUser($this->manager);
        $file = spiece_manager::open((int) $piece->id, (int) $context->id);
        $this->assertNotNull($file);
        $this->assertSame($piece->uuid . '.pdf', $file->get_filename());
        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof spiece_read));
        $this->assertCount(1, $events);
        $this->assertSame((int) $this->manager->id, (int) $events[0]->userid);
        $this->assertSame((int) $this->vaeiste->id, (int) $events[0]->relateduserid);
        $this->assertStringNotContainsString('Un titre', json_encode($events[0]->get_data()) . $events[0]->get_description());

        // Every refusal is the same: nobody learns why.
        $this->setUser($this->othervaeiste);
        $this->assertNull(spiece_manager::open((int) $piece->id, (int) $context->id));
        $this->setAdminUser();
        $this->assertNull(spiece_manager::open((int) $piece->id, (int) $context->id), 'Not the site administrator.');
        $this->setUser($this->direction);
        $this->assertNotNull(spiece_manager::open((int) $piece->id, (int) $context->id));
        $this->assertNull(spiece_manager::open((int) $piece->id, (int) $context->id + 1), 'Not the context of the vaeiste.');
        $this->assertNull(spiece_manager::open(999999, (int) $context->id));
        // A link has no file to serve.
        $this->setUser($this->vaeiste);
        $link = (new spiece_manager())->add_link((int) $this->first->id, 'other', '', 'https://exemple.test/x', '');
        $this->assertNull(spiece_manager::open((int) $link->id, (int) $context->id));

        (new spiece_manager())->remove((int) $piece->id);
        $removed = $GLOBALS['DB']->get_record('tool_vae_spiece', ['id' => $piece->id]);
        $this->assertFalse(spiece_manager::may_open($removed, (int) $this->vaeiste->id), 'A removed piece opens for nobody.');
        $this->assertNull(spiece_manager::open((int) $piece->id, (int) $context->id));

        // The sweep takes a file nobody points at, and is idempotent.
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'tool_vae', 'filearea' => 'spiece', 'itemid' => 987654,
            'filepath' => '/', 'filename' => 'x.pdf',
        ], $this->pdf());
        $this->assertGreaterThanOrEqual(1, spiece_manager::sweep_orphans());
        $this->assertCount(0, get_file_storage()->get_area_files($context->id, 'tool_vae', 'spiece', 987654, 'id', false));
        $this->assertSame(0, spiece_manager::sweep_orphans(), 'Idempotent.');
    }

    public function test_only_the_vaeiste_adds_links_detaches_and_removes(): void {
        global $DB;
        $manager = new spiece_manager();
        $id = (int) $this->first->id;
        $piece = $manager->add_link($id, 'other', 'Titre prive', 'https://exemple.test/nom-de-quelquun', 'partie privee');
        $spare = $manager->add_link((int) $this->second->id, 'other', '', 'https://exemple.test/y', '');

        foreach ([$this->manager, $this->direction, get_admin(), $this->othervaeiste] as $other) {
            $this->setUser($other);
            $this->assert_refused(fn() => $manager->add_link($id, 'other', '', 'https://exemple.test/x', ''), 'errorpiecedenied');
            $this->assert_refused(fn() => $manager->add_file($id, 'other', '', $this->file($this->pdf()), ''), 'errorpiecedenied');
            $this->assert_refused(fn() => $manager->attach($id, (int) $spare->id, ''), 'errorpiecedenied');
            $this->assert_refused(fn() => $manager->detach($id, (int) $piece->id), 'errorpiecedenied');
            $this->assert_refused(fn() => $manager->remove((int) $piece->id), 'errorpiecedenied');
        }
        // The other vaeiste may not attach their own piece to this situation, nor this piece to their situation.
        $this->setUser($this->othervaeiste);
        $theirs = (new situation_manager())->create((int) $this->othervaeiste->id, 'RNCP100');
        $this->assert_refused(fn() => $manager->attach((int) $theirs->id, (int) $piece->id, ''), 'errorpiecedenied');
        $this->assertSame(1, $DB->count_records('tool_vae_slink', ['pieceid' => $piece->id, 'timeremoved' => null]));

        // Whoever may view reads the links; the other vaeiste does not.
        $this->setUser($this->manager);
        $this->assertCount(1, $manager->for_situation($id));
        $this->assertCount(2, $manager->for_candidate((int) $this->vaeiste->id, 'RNCP100'));
        $this->setUser($this->othervaeiste);
        $this->assert_refused(fn() => $manager->for_situation($id), 'errordraftrefused');
        $this->assert_refused(fn() => $manager->for_candidate((int) $this->vaeiste->id, 'RNCP100'), 'errordraftrefused');
        $this->assert_refused(fn() => $manager->links_of((int) $piece->id), 'errordraftrefused');

        // Closed: nothing is added, but detaching and removing still work.
        $this->setUser($this->vaeiste);
        set_config('piecesopen', 0, 'tool_vae');
        $sink = $this->redirectEvents();
        $this->assert_refused(fn() => $manager->add_link($id, 'other', '', 'https://exemple.test/x', ''), 'errorpiececlosed');
        $this->assert_refused(fn() => $manager->add_file($id, 'other', '', $this->file($this->pdf()), ''), 'errorpiececlosed');
        $this->assert_refused(fn() => $manager->attach($id, (int) $spare->id, ''), 'errorpiececlosed');
        $manager->detach($id, (int) $piece->id);
        $this->assertSame([], $manager->for_situation($id));
        $this->assertNotNull($manager->remove((int) $piece->id)->timeremoved);
        $this->assert_refused(fn() => $manager->remove(999999), 'errorpiecedenied');
        $this->assert_refused(fn() => $manager->detach($id, (int) $piece->id), 'errorpiecedenied');

        // The events carry identifiers only.
        set_config('piecesopen', 1, 'tool_vae');
        $manager->add_link($id, 'other', 'Titre prive', 'https://exemple.test/nom-de-quelquun', 'partie privee');
        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof spiece_added || $event instanceof spiece_removed
        ));
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame((int) $this->vaeiste->id, (int) $event->relateduserid);
            $text = json_encode($event->get_data()) . $event->get_description();
            foreach (['Titre prive', 'nom-de-quelquun', 'partie privee'] as $free) {
                $this->assertStringNotContainsString($free, $text);
            }
        }
    }
}
