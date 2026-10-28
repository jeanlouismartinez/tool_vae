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
use tool_vae\event\request_answered;
use tool_vae\event\request_created;
use tool_vae\local\purge;
use tool_vae\local\request_manager;
use tool_vae\privacy\provider;
use tool_vae\task\send_notification;

/**
 * The request for precision on a declaration, and its answer (T-20, D-13).
 *
 * WHAT IS ASSERTED. That a manager who follows the candidate and may attest asks, once at a time, on a
 * declaration not yet attested; that only the candidate answers, once; that neither text is rewritten;
 * that the readers of the dossier read both and a stranger neither; that the events carry no text; that
 * the dossier's erasure takes the requests and the author's erasure takes their name, not the text.
 *
 * The declaration is inserted as a row: the rules under test are about who may write on it, not about
 * how a declaration is made (the competency record is not needed).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\request_manager::class)]
final class request_manager_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Follows the candidate and may attest. */
    private $manager;

    /** @var \stdClass Follows the candidate, may not attest. */
    private $reader;

    /** @var \stdClass Holds the rights in another person's context, follows nobody here. */
    private $outsider;

    /** @var int The declaration. */
    private $evidenceid;

    /**
     * A candidate with a declaration, a manager, a reader and an outsider.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;

        $this->candidate = $generator->create_user();
        $this->manager = $generator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->reader = $generator->create_user();
        $this->outsider = $generator->create_user();
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

        $other = $generator->create_user();
        role_assign($attest, $this->outsider->id, \context_user::instance($other->id)->id);

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

    public function test_the_manager_who_follows_asks_and_the_text_is_kept_as_written(): void {
        $this->setUser($this->manager);

        $request = (new request_manager())->ask($this->evidenceid, "  Quel etait votre role ?\r\nPrecisez.  ");

        $this->assertSame("Quel etait votre role ?\nPrecisez.", $request->question);
        $this->assertSame(hash('sha256', $request->question), $request->questionhash);
        $this->assertSame((int) $this->manager->id, (int) $request->authorid);
        $this->assertNull($request->answer);
        $this->assertSame(1, request_manager::count_open($this->evidenceid));
    }

    public function test_who_may_not_ask(): void {
        $manager = new request_manager();

        foreach ([$this->candidate, $this->reader, $this->outsider] as $user) {
            $this->setUser($user);
            $this->assertFalse(request_manager::can_ask((int) $this->candidate->id));
            $this->assert_refused(function () use ($manager) {
                $manager->ask($this->evidenceid, 'Une question.');
            }, 'errorrequestdenied');
        }

        $this->setAdminUser();
        $this->assertFalse(request_manager::can_ask((int) $this->candidate->id));
        $this->assert_refused(function () use ($manager) {
            $manager->ask(999999, 'Une question.');
        }, 'errorrequestdenied');
    }

    public function test_an_empty_too_long_or_second_open_question_is_refused(): void {
        $this->setUser($this->manager);
        $manager = new request_manager();

        $this->assert_refused(function () use ($manager) {
            $manager->ask($this->evidenceid, " \n ");
        }, 'errorrequestempty');
        $this->assert_refused(function () use ($manager) {
            $manager->ask($this->evidenceid, str_repeat('a', request_manager::MAX_LENGTH + 1));
        }, 'errordrafttoolong');

        $manager->ask($this->evidenceid, 'Premiere question.');
        $this->assert_refused(function () use ($manager) {
            $manager->ask($this->evidenceid, 'Seconde question.');
        }, 'errorrequestopen');
    }

    public function test_nothing_is_asked_of_an_attested_declaration_until_it_is_withdrawn(): void {
        global $DB;

        $now = time();
        $attestationid = $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => local\identity_map::generate_ulid(),
            'evidenceid' => $this->evidenceid,
            'attestantid' => (int) $this->manager->id,
            'attestantrole' => 'Responsable',
            'statement' => 'Vu.',
            'attestedat' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int) $this->manager->id,
        ]);

        $this->setUser($this->manager);
        $manager = new request_manager();
        $this->assert_refused(function () use ($manager) {
            $manager->ask($this->evidenceid, 'Une question.');
        }, 'errorrequestattested');

        $DB->set_field('tool_vae_attest', 'withdrawnat', $now, ['id' => $attestationid]);
        $this->assertSame('Une question.', $manager->ask($this->evidenceid, 'Une question.')->question);
    }

    public function test_only_the_candidate_answers_and_only_once(): void {
        $this->setUser($this->manager);
        $request = (new request_manager())->ask($this->evidenceid, 'Une question.');
        $manager = new request_manager();

        foreach ([$this->manager, $this->reader, $this->outsider] as $user) {
            $this->setUser($user);
            $this->assertFalse(request_manager::can_answer((int) $this->candidate->id));
            $this->assert_refused(function () use ($manager, $request) {
                $manager->answer((int) $request->id, 'Une reponse.');
            }, 'errorrequestdenied');
        }

        $this->setUser($this->candidate);
        $this->assert_refused(function () use ($manager, $request) {
            $manager->answer((int) $request->id, " \n ");
        }, 'errorrequestempty');

        $answered = $manager->answer((int) $request->id, "  J'etais referente.\r\n  ");
        $this->assertSame("J'etais referente.", $answered->answer);
        $this->assertSame(hash('sha256', $answered->answer), $answered->answerhash);
        $this->assertNotNull($answered->timeanswered);
        $this->assertSame(0, request_manager::count_open($this->evidenceid));

        $this->assert_refused(function () use ($manager, $request) {
            $manager->answer((int) $request->id, 'Une autre reponse.');
        }, 'errorrequestanswered');

        // The next question may then be asked.
        $this->setUser($this->manager);
        $this->assertSame('Suite.', $manager->ask($this->evidenceid, 'Suite.')->question);
    }

    public function test_the_candidate_and_the_readers_read_both_texts_and_a_stranger_does_not(): void {
        $this->setUser($this->manager);
        $manager = new request_manager();
        $request = $manager->ask($this->evidenceid, 'Une question.');
        $this->setUser($this->candidate);
        $manager->answer((int) $request->id, 'Une reponse.');
        $evidence = $GLOBALS['DB']->get_record('tool_vae_evidence', ['id' => $this->evidenceid], '*', MUST_EXIST);

        foreach ([$this->candidate, $this->reader, $this->manager] as $user) {
            $this->setUser($user);
            $rows = $manager->for_evidence($evidence);
            $this->assertCount(1, $rows);
            $row = reset($rows);
            $this->assertSame('Nadia Benali', $row->authorname);
            $this->assertSame('Une reponse.', $row->answer);
        }

        $this->setUser($this->outsider);
        $this->assert_refused(function () use ($manager, $evidence) {
            $manager->for_evidence($evidence);
        }, 'errorrequestdenied');
    }

    public function test_the_events_name_no_text(): void {
        $this->setUser($this->manager);
        $sink = $this->redirectEvents();
        $manager = new request_manager();

        $request = $manager->ask($this->evidenceid, 'Texte-Secret-Question.');
        $this->setUser($this->candidate);
        $manager->answer((int) $request->id, 'Texte-Secret-Reponse.');

        $events = $sink->get_events();
        $sink->close();
        $created = array_values(array_filter($events, fn($e) => $e instanceof request_created));
        $answered = array_values(array_filter($events, fn($e) => $e instanceof request_answered));
        $this->assertCount(1, $created);
        $this->assertCount(1, $answered);
        $this->assertSame((int) $this->candidate->id, (int) $created[0]->relateduserid);
        $this->assertSame(['evidenceid' => $this->evidenceid], $created[0]->other);
        foreach (array_merge($created, $answered) as $event) {
            $this->assertStringNotContainsString('Texte-Secret', $event->get_description());
            $this->assertStringNotContainsString('Texte-Secret', json_encode($event->other));
        }
    }

    /**
     * The messages queued for the precision requests, as the delivery receives them.
     *
     * @return \stdClass[] The custom data of each queued message.
     */
    private function queued_precision_messages(): array {
        $out = [];
        foreach (\core\task\manager::get_adhoc_tasks(send_notification::class) as $task) {
            if ($task->get_custom_data()->provider === notifier::PRECISION_ASKED) {
                $out[] = $task->get_custom_data();
            }
        }

        return $out;
    }

    public function test_the_message_to_the_candidate_names_neither_the_author_nor_the_text(): void {
        $this->preventResetByRollback();
        $this->setUser($this->manager);
        (new request_manager())->ask($this->evidenceid, 'Texte-Secret de la question.');

        $queued = $this->queued_precision_messages();
        $this->assertCount(1, $queued);
        $this->assertSame((int) $this->candidate->id, (int) $queued[0]->recipientid);

        $sink = $this->redirectMessages();
        $this->assertTrue(notifier::deliver($queued[0]));
        $messages = $sink->get_messages_by_component_and_type('tool_vae', notifier::PRECISION_ASKED);
        $this->assertCount(1, $messages);
        $message = reset($messages);
        foreach ([$message->subject, $message->fullmessage, $message->fullmessagehtml, $message->smallmessage] as $text) {
            $this->assertStringNotContainsString('Texte-Secret', $text);
            $this->assertStringNotContainsString('Nadia', $text);
            $this->assertStringNotContainsString('Benali', $text);
        }
    }

    public function test_the_message_is_not_sent_once_it_is_no_longer_true(): void {
        $this->preventResetByRollback();
        $this->setUser($this->manager);
        $request = (new request_manager())->ask($this->evidenceid, 'Une question.');
        $queued = $this->queued_precision_messages();
        $this->assertCount(1, $queued);
        $sink = $this->redirectMessages();

        // Not to anyone but the candidate.
        $elsewhere = clone $queued[0];
        $elsewhere->recipientid = (int) $this->manager->id;
        $this->assertFalse(notifier::deliver($elsewhere));

        // Not once the candidate has answered.
        $this->setUser($this->candidate);
        (new request_manager())->answer((int) $request->id, 'La reponse.');
        $this->assertFalse(notifier::deliver($queued[0]));

        // Not once the request is gone.
        $this->setUser($this->manager);
        $second = (new request_manager())->ask($this->evidenceid, 'Une autre question.');
        $queued = $this->queued_precision_messages();
        $this->assertCount(2, $queued);
        local\purge::dossier((int) $this->candidate->id);
        $this->assertFalse(notifier::deliver(end($queued)));
        $this->assertNotEmpty($second->id);

        $this->assertCount(0, $sink->get_messages());
    }

    public function test_the_message_asks_for_the_right_to_answer_not_only_to_draft(): void {
        $this->preventResetByRollback();
        $this->setUser($this->manager);
        (new request_manager())->ask($this->evidenceid, 'Une question.');
        $queued = $this->queued_precision_messages();
        $sink = $this->redirectMessages();

        // The candidate can still read but may no longer declare: an answer would be refused, so no message.
        $context = \context_user::instance((int) $this->candidate->id);
        role_unassign_all(['userid' => $this->candidate->id, 'contextid' => $context->id]);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(notifier::deliver($queued[0]));
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_the_erasure_of_the_dossier_takes_the_requests(): void {
        global $DB;

        $this->setUser($this->manager);
        (new request_manager())->ask($this->evidenceid, 'Une question.');

        purge::dossier((int) $this->candidate->id);

        $this->assertSame(0, $DB->count_records('tool_vae_request'));
        $this->assertSame(0, $DB->count_records('tool_vae_evidence'));
    }

    public function test_the_erasure_of_the_author_takes_their_name_and_keeps_the_text(): void {
        global $DB;

        $this->setUser($this->manager);
        $request = (new request_manager())->ask($this->evidenceid, 'Une question.');

        $context = \context_user::instance((int) $this->candidate->id);
        provider::delete_data_for_user(new approved_contextlist($this->manager, 'tool_vae', [$context->id]));

        $row = $DB->get_record('tool_vae_request', ['id' => $request->id], '*', MUST_EXIST);
        $this->assertNull($row->authorid);
        $this->assertSame('Une question.', $row->question);
    }

    public function test_the_export_gives_the_candidate_everything_and_the_author_their_own(): void {
        $this->setUser($this->manager);
        $manager = new request_manager();
        $request = $manager->ask($this->evidenceid, 'Une question.');
        $this->setUser($this->candidate);
        $manager->answer((int) $request->id, 'Une reponse.');

        $context = \context_user::instance((int) $this->candidate->id);
        $path = [get_string('pluginname', 'tool_vae'), get_string('privacy:path:requests', 'tool_vae')];

        provider::export_user_data(new approved_contextlist($this->candidate, 'tool_vae', [$context->id]));
        $data = writer::with_context($context)->get_data($path);
        $this->assertSame('Une question.', $data->requests[0]['question']);
        $this->assertSame('Une reponse.', $data->requests[0]['answer']);

        writer::reset();
        provider::export_user_data(new approved_contextlist($this->reader, 'tool_vae', [$context->id]));
        $this->assertEmpty(writer::with_context($context)->get_data($path));

        writer::reset();
        provider::export_user_data(new approved_contextlist($this->manager, 'tool_vae', [$context->id]));
        $this->assertNotEmpty(writer::with_context($context)->get_data($path));
    }
}
