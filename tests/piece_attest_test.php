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
use tool_vae\local\attestation_manager;
use tool_vae\local\piece_manager;
use tool_vae\local\presenter;
use tool_vae\local\purge;
use tool_vae\privacy\provider;
use tool_vae\task\send_notification;

/**
 * The supporting pieces and the attestation (T-13, lot 2).
 *
 * WHAT IS ASSERTED. That a signature is refused when the list of pieces the signatory was shown is not the list there is, in
 * either direction, and when the box is not ticked; that the list signed is kept with the digest of each file, and the box with
 * it; that a piece added after a signature is marked as such and tells the managers who follow, without a word of it; that
 * opening is recorded for whoever is not the candidate; that a withdrawn or erased signature loses the list and the box, and an
 * erased person loses what they opened; that a purge takes it all; and that the lock of the candidate's pieces is shared.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\attestation_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\piece_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\presenter::class)]
final class piece_attest_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Follows the candidate and may attest. */
    private $manager;

    /** @var \stdClass Follows the candidate and may only read. */
    private $reader;

    /** @var int The declaration. */
    private $evidenceid;

    /** @var \phpunit_message_sink */
    private $sink;

    /**
     * A candidate with a declaration, a manager who attests and a reader who may only view.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;

        $this->candidate = $generator->create_user(['firstname' => 'Camille', 'lastname' => 'Durand']);
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
        $this->sink = $this->redirectMessages();
        $this->setUser($this->candidate);
    }

    /**
     * A link on the declaration, as the candidate.
     *
     * @param string $url
     * @return \stdClass
     */
    private function link(string $url = 'https://exemple.test/a'): \stdClass {
        $this->setUser($this->candidate);

        return (new piece_manager())->add_link($this->evidenceid, 'diploma', '', $url);
    }

    /**
     * A file on the declaration, as the candidate.
     *
     * @return \stdClass
     */
    private function file(): \stdClass {
        $this->setUser($this->candidate);
        $path = make_request_directory() . '/upload';
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return (new piece_manager())->add_file($this->evidenceid, 'contract', '', $path, 'x.pdf');
    }

    /**
     * What the form of the attestation carries.
     *
     * @param string|null $token The list as shown; the list there is when null.
     * @param bool $ticked
     * @return \stdClass
     */
    private function data(?string $token = null, bool $ticked = true): \stdClass {
        return (object) [
            'attestantrole' => 'Directeur',
            'statement' => 'Je confirme.',
            'piecesseen' => $token ?? piece_manager::list_token(piece_manager::active_for_evidence($this->evidenceid)),
            'piecesread' => $ticked ? 1 : 0,
        ];
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

    public function test_a_declaration_with_no_piece_is_attested_as_before(): void {
        $this->setUser($this->manager);

        $record = (new attestation_manager())->attest($this->evidenceid, (object) [
            'attestantrole' => 'Directeur',
            'statement' => 'Je confirme.',
        ]);

        $this->assertNull($record->piecesread);
        $this->assertSame([], piece_manager::covered_by((int) $record->id));
    }

    public function test_the_list_signed_is_kept_with_the_digest_of_each_file_and_the_box(): void {
        $file = $this->file();
        $link = $this->link();
        $this->setUser($this->manager);

        $record = (new attestation_manager())->attest($this->evidenceid, $this->data());

        $this->assertSame(1, (int) $record->piecesread);
        $this->assertSame(
            [(int) $file->id => $file->sha256, (int) $link->id => ''],
            piece_manager::covered_by((int) $record->id)
        );
    }

    public function test_the_box_is_required_when_there_are_pieces(): void {
        $this->link();
        $this->setUser($this->manager);

        $this->assert_refused(
            fn() => (new attestation_manager())->attest($this->evidenceid, $this->data(null, false)),
            'errorattestpiecesconfirm'
        );
        $this->assertNull(attestation_manager::active_attestation($this->evidenceid));
    }

    public function test_a_piece_added_since_the_screen_was_drawn_refuses_the_signature(): void {
        $this->link('https://exemple.test/a');
        $shown = piece_manager::list_token(piece_manager::active_for_evidence($this->evidenceid));
        $this->link('https://exemple.test/b');
        $this->setUser($this->manager);

        $this->assert_refused(
            fn() => (new attestation_manager())->attest($this->evidenceid, $this->data($shown)),
            'errorattestpieceschanged'
        );
        $this->assertNull(attestation_manager::active_attestation($this->evidenceid), 'Nothing was signed.');
    }

    public function test_a_piece_removed_since_the_screen_was_drawn_refuses_the_signature(): void {
        $first = $this->link('https://exemple.test/a');
        $this->link('https://exemple.test/b');
        $shown = piece_manager::list_token(piece_manager::active_for_evidence($this->evidenceid));
        $this->setUser($this->candidate);
        (new piece_manager())->remove((int) $first->id);
        $this->setUser($this->manager);

        $this->assert_refused(
            fn() => (new attestation_manager())->attest($this->evidenceid, $this->data($shown)),
            'errorattestpieceschanged'
        );
    }

    public function test_a_list_shown_for_no_piece_does_not_cover_a_piece_that_came(): void {
        $this->link();
        $this->setUser($this->manager);

        $this->assert_refused(
            fn() => (new attestation_manager())->attest($this->evidenceid, $this->data('')),
            'errorattestpieceschanged'
        );
    }

    public function test_a_refused_signature_leaves_both_locks_free(): void {
        // The two locks (the claim's, the candidate's pieces) are taken for the whole act and released by finally{} even when
        // the act is refused: the same signature, corrected, goes through at once. That two processes exclude each other is not
        // provable from one process (the database locks are re-entrant for a session) and is played by hand, see TRAVAIL.md.
        $this->link();
        $this->setUser($this->manager);

        $this->assert_refused(
            fn() => (new attestation_manager())->attest($this->evidenceid, $this->data('')),
            'errorattestpieceschanged'
        );
        $this->assertNull(attestation_manager::active_attestation($this->evidenceid));

        $this->assertNotNull((new attestation_manager())->attest($this->evidenceid, $this->data()));
    }

    public function test_a_piece_added_after_the_signature_is_marked_and_tells_the_managers_who_follow(): void {
        $this->link('https://exemple.test/a');
        $this->setUser($this->manager);
        (new attestation_manager())->attest($this->evidenceid, $this->data());
        \core\task\manager::clear_static_caches();
        $this->link('https://exemple.test/titre-prive');

        $standing = attestation_manager::active_attestation($this->evidenceid);
        $context = \context_user::instance($this->candidate->id);
        $this->setUser($this->manager);
        $html = presenter::pieces(piece_manager::for_evidence($this->evidenceid), $context, $standing);
        $this->assertStringContainsString('Added after the signature of', $html);
        $this->assertSame(1, substr_count($html, 'Added after the signature of'), 'Only the later piece.');

        $tasks = array_values(array_filter(
            \core\task\manager::get_adhoc_tasks(send_notification::class),
            fn($task) => ($task->get_custom_data()->provider ?? '') === 'pieceafterattest'
        ));
        $this->assertCount(1, $tasks);
        $data = $tasks[0]->get_custom_data();
        $this->assertSame(
            (int) $this->manager->id,
            (int) $data->recipientid,
            'The one who follows and may attest, not the reader.'
        );

        // Delivered, it says who and which declaration, and nothing of the piece.
        $this->assertTrue(\tool_vae\notifier::deliver($data));
        $messages = $this->sink->get_messages();
        $this->assertCount(1, $messages);
        $text = $messages[0]->subject . $messages[0]->fullmessage;
        $this->assertStringContainsString('Camille', $text);
        $this->assertStringNotContainsString('titre-prive', $text);
        $this->assertStringNotContainsString('exemple.test', $text);
    }

    public function test_no_message_when_the_declaration_has_no_signature_yet(): void {
        \core\task\manager::clear_static_caches();
        $this->link();

        $tasks = array_filter(
            \core\task\manager::get_adhoc_tasks(send_notification::class),
            fn($task) => ($task->get_custom_data()->provider ?? '') === 'pieceafterattest'
        );
        $this->assertCount(0, $tasks);
    }

    public function test_a_piece_removed_after_the_signature_is_said_to_have_been_covered(): void {
        $piece = $this->link();
        $this->setUser($this->manager);
        (new attestation_manager())->attest($this->evidenceid, $this->data());
        $this->setUser($this->candidate);
        (new piece_manager())->remove((int) $piece->id);

        $html = presenter::pieces(
            piece_manager::for_evidence($this->evidenceid),
            \context_user::instance($this->candidate->id),
            attestation_manager::active_attestation($this->evidenceid)
        );

        $this->assertStringContainsString('removed since', $html);
    }

    public function test_opening_is_recorded_for_a_manager_and_not_for_the_candidate(): void {
        $file = $this->file();
        $context = \context_user::instance($this->candidate->id);

        $this->setUser($this->candidate);
        $this->assertNotNull(piece_manager::open((int) $file->id, (int) $context->id));
        $this->assertSame(0, $GLOBALS['DB']->count_records('tool_vae_piece_open'));

        $this->setUser($this->manager);
        $this->assertSame([], piece_manager::opened_by((int) $this->manager->id, [(int) $file->id]));
        $this->assertNotNull(piece_manager::open((int) $file->id, (int) $context->id));
        $this->assertNotNull(piece_manager::open((int) $file->id, (int) $context->id), 'Twice: one row.');

        $this->assertSame(1, $GLOBALS['DB']->count_records('tool_vae_piece_open'));
        $this->assertSame([(int) $file->id => true], piece_manager::opened_by((int) $this->manager->id, [(int) $file->id]));

        $html = presenter::pieces(piece_manager::for_evidence($this->evidenceid), $context);
        $this->assertStringContainsString('You opened this file.', $html);
        $this->assertStringContainsString('1 of 1 files you may open were opened by you. Opening is not reading.', $html);
    }

    public function test_a_reader_who_may_only_view_gets_the_list_and_not_the_file(): void {
        $this->file();
        $context = \context_user::instance($this->candidate->id);

        $this->setUser($this->reader);
        $html = presenter::pieces(piece_manager::for_evidence($this->evidenceid), $context);

        $this->assertStringContainsString('Listed here. You may not open it.', $html);
        $this->assertStringNotContainsString('pluginfile.php', $html);
    }

    public function test_a_withdrawn_signature_loses_the_list_and_the_box(): void {
        $this->link();
        $this->setUser($this->manager);
        $record = (new attestation_manager())->attest($this->evidenceid, $this->data());
        $this->assertNotSame([], piece_manager::covered_by((int) $record->id));

        // Only the site administrator, or a holder of the right, withdraws a signature.
        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $record->id, 'erreur');

        $this->assertSame([], piece_manager::covered_by((int) $record->id));
        $this->assertNull($GLOBALS['DB']->get_field('tool_vae_attest', 'piecesread', ['id' => $record->id]));
    }

    public function test_an_erased_signature_loses_the_list_and_the_box(): void {
        $this->link();
        $this->setUser($this->manager);
        $record = (new attestation_manager())->attest($this->evidenceid, $this->data());

        purge::anonymise_signatures('id = :id', ['id' => $record->id]);

        $this->assertSame([], piece_manager::covered_by((int) $record->id));
        $this->assertNull($GLOBALS['DB']->get_field('tool_vae_attest', 'piecesread', ['id' => $record->id]));
    }

    public function test_an_erased_person_loses_what_they_opened_and_the_piece_stays(): void {
        $file = $this->file();
        $context = \context_user::instance($this->candidate->id);
        $this->setUser($this->manager);
        piece_manager::open((int) $file->id, (int) $context->id);
        $this->assertSame(1, $GLOBALS['DB']->count_records('tool_vae_piece_open'));

        provider::delete_data_for_user(new approved_contextlist(
            $this->manager,
            'tool_vae',
            [\context_system::instance()->id]
        ));

        $this->assertSame(0, $GLOBALS['DB']->count_records('tool_vae_piece_open'));
        $this->assertSame(1, $GLOBALS['DB']->count_records('tool_vae_piece', ['id' => $file->id]));
    }

    public function test_a_purge_of_the_dossier_takes_the_lists_and_the_openings(): void {
        $file = $this->file();
        $context = \context_user::instance($this->candidate->id);
        $this->setUser($this->manager);
        piece_manager::open((int) $file->id, (int) $context->id);
        (new attestation_manager())->attest($this->evidenceid, $this->data());

        purge::dossier((int) $this->candidate->id);

        foreach (['tool_vae_attest_piece', 'tool_vae_piece_open', 'tool_vae_piece', 'tool_vae_attest'] as $table) {
            $this->assertSame(0, $GLOBALS['DB']->count_records($table), $table);
        }
    }

    public function test_the_erasure_of_a_signatory_takes_the_list_the_box_and_the_openings_through_the_dossier_door(): void {
        global $DB;
        $file = $this->file();
        $this->link();
        $context = \context_user::instance($this->candidate->id);
        $this->setUser($this->manager);
        piece_manager::open((int) $file->id, (int) $context->id);
        $record = (new attestation_manager())->attest($this->evidenceid, $this->data());
        $this->assertCount(2, piece_manager::covered_by((int) $record->id));

        // The door of the privacy API for a signatory: the candidate's context, where they signed.
        provider::delete_data_for_user(new approved_contextlist($this->manager, 'tool_vae', [$context->id]));

        $row = $DB->get_record('tool_vae_attest', ['id' => $record->id]);
        $this->assertNull($row->attestantid);
        $this->assertSame('rgpd', $row->withdrawnreason);
        $this->assertNull($row->piecesread);
        $this->assertSame([], piece_manager::covered_by((int) $record->id));
        $this->assertSame(0, $DB->count_records('tool_vae_piece_open'));
        $this->assertSame(2, $DB->count_records('tool_vae_piece'), 'The pieces are the candidate\'s and stay.');
    }

    public function test_someone_who_only_opened_a_piece_is_found_and_erased_by_the_privacy_api(): void {
        global $DB;
        $file = $this->file();
        $context = \context_user::instance($this->candidate->id);
        $this->setUser($this->manager);
        piece_manager::open((int) $file->id, (int) $context->id);

        $found = provider::get_contexts_for_userid((int) $this->manager->id);
        $this->assertContains((int) $context->id, array_map('intval', $found->get_contextids()));

        $users = new \core_privacy\local\request\userlist($context, 'tool_vae');
        provider::get_users_in_context($users);
        $this->assertContains((int) $this->manager->id, array_map('intval', $users->get_userids()));

        provider::delete_data_for_user(new approved_contextlist($this->manager, 'tool_vae', [$context->id]));
        $this->assertSame(0, $DB->count_records('tool_vae_piece_open'));
        $this->assertCount(0, provider::get_contexts_for_userid((int) $this->manager->id)->get_contextids());
    }

    public function test_a_deleted_account_loses_what_it_opened(): void {
        global $DB;
        $file = $this->file();
        $context = \context_user::instance($this->candidate->id);
        $this->setUser($this->manager);
        piece_manager::open((int) $file->id, (int) $context->id);
        $this->assertSame(1, $DB->count_records('tool_vae_piece_open'));

        delete_user($this->manager);

        $this->assertSame(0, $DB->count_records('tool_vae_piece_open'));
    }

    public function test_the_export_gives_the_note_the_list_and_the_openings(): void {
        $file = $this->file();
        $context = \context_user::instance($this->candidate->id);
        $this->setUser($this->manager);
        piece_manager::open((int) $file->id, (int) $context->id);
        (new attestation_manager())->attest($this->evidenceid, $this->data());
        $path = [get_string('pluginname', 'tool_vae')];

        // The candidate reads what each signature covered.
        writer::reset();
        provider::export_user_data(new approved_contextlist($this->candidate, 'tool_vae', [$context->id]));
        $attestation = writer::with_context($context)->get_data($path)->claims[0]['attestations'][0];
        $this->assertTrue($attestation['piecesread']);
        $this->assertSame([['reference' => $file->uuid, 'sha256' => $file->sha256]], $attestation['pieces_covered']);

        // The manager gets what they opened.
        writer::reset();
        provider::export_user_data(new approved_contextlist($this->manager, 'tool_vae', [$context->id]));
        $openings = writer::with_context($context)->get_data(array_merge($path, ['piece openings']));
        $this->assertSame($file->uuid, $openings->openings[0]['reference']);
    }

    public function test_the_title_is_shown_to_whoever_may_open_the_piece_and_not_to_a_reader(): void {
        $this->setUser($this->candidate);
        (new piece_manager())->add_link($this->evidenceid, 'diploma', 'Titre libre', 'https://exemple.test/a');
        $context = \context_user::instance($this->candidate->id);
        $pieces = piece_manager::for_evidence($this->evidenceid);

        $this->setUser($this->manager);
        $this->assertStringContainsString('Titre libre', presenter::pieces($pieces, $context));

        $this->setUser($this->reader);
        $this->assertStringNotContainsString('Titre libre', presenter::pieces($pieces, $context));

        $this->setUser($this->candidate);
        $this->assertStringContainsString('Titre libre', presenter::pieces($pieces, $context));
    }

    public function test_the_sweep_takes_the_list_and_the_openings_that_hang_from_an_orphan_piece(): void {
        global $DB;
        $file = $this->file();
        $context = \context_user::instance($this->candidate->id);
        $this->setUser($this->manager);
        piece_manager::open((int) $file->id, (int) $context->id);
        $record = (new attestation_manager())->attest($this->evidenceid, $this->data());
        $this->assertSame(1, $DB->count_records('tool_vae_attest_piece'));

        // The declaration and its signature go, the pieces' rows and what hangs from them are left behind.
        $DB->delete_records('tool_vae_attest', ['id' => $record->id]);
        $DB->delete_records('tool_vae_evidence', ['id' => $this->evidenceid]);
        piece_manager::sweep_orphans();

        foreach (['tool_vae_attest_piece', 'tool_vae_piece_open', 'tool_vae_piece'] as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
    }

    public function test_the_dossier_says_how_many_pieces_the_signature_does_not_cover(): void {
        $this->link('https://exemple.test/a');
        $this->setUser($this->manager);
        $record = (new attestation_manager())->attest($this->evidenceid, $this->data());
        $this->link('https://exemple.test/b');
        $this->link('https://exemple.test/c');

        $covered = piece_manager::covered_by((int) $record->id);
        $active = piece_manager::active_for_evidence($this->evidenceid);
        $late = array_filter($active, fn($piece) => !array_key_exists((int) $piece->id, $covered));
        $this->assertCount(3, $active);
        $this->assertCount(2, $late);
    }
}
