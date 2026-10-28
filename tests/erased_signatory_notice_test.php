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
use tool_vae\local\attestation_manager;
use tool_vae\local\backup;
use tool_vae\local\dossier_document;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\pdf_renderer;
use tool_vae\local\presenter;
use tool_vae\local\purge;
use tool_vae\privacy\provider;
use tool_vae\task\send_notification;

/**
 * A manager's data is erased, their attestations are withdrawn: the vaeiste is told, once, and so are the others who follow.
 *
 * THE CASE, AS THE FILE OF 27 OCTOBER 2026 DESCRIBES IT. A nursing manager attested three declarations of two vaeistes. Her
 * account is deleted; Moodle files a request to delete her data, which the data protection officer approves; at the next
 * cron the plugin strips her attestations. Nobody was told. The decision: ONE message to each vaeiste, in the words of the
 * message that already exists for an ordinary withdrawal and in the plural when it must be; one message, per vaeiste, to the
 * other managers who follow them; and a clearer sentence in the dossier, the PDF and the Word or ODT copies.
 *
 * THE WORLD OF THESE TESTS. Two vaeistes (Camille Martin, Lea Garnier), the manager who is erased (Nadia Benali), two who
 * follow and remain (Hugo Marchand follows both vaeistes, Lucie Bernard follows Lea only). Nadia attested three
 * declarations: two of Camille, one of Lea. The tests that go through Moodle do it the way the site does: the deletion of the
 * account, the request Moodle files, its approval, the ad hoc task of the data protection tool.
 *
 * WHAT IS ASSERTED ABOUT A MESSAGE. The whole text, compared with what the language strings give for these values: nothing
 * else is in it. And, field by field, that no word of the signatory, of the declaration or of the cause is.
 *
 * THE PROVIDERS ARE WRITTEN OUT. 'attestationserased' and 'declarationsawaitagain' are what the task carries; the tests read
 * them as strings so that a test fails on what is missing, not on a constant that does not exist yet.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\notifier::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\purge::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\privacy\provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\presenter::class)]
final class erased_signatory_notice_test extends \advanced_testcase {
    /** @var string What the task of the message to the vaeiste carries. */
    private const ERASED = 'attestationserased';

    /** @var string What the task of the message to the other managers carries. */
    private const AWAIT_AGAIN = 'declarationsawaitagain';

    /** @var string The message type of the vaeiste's message: the one of an ordinary withdrawal. */
    private const WITHDRAWN = 'attestationwithdrawn';

    /** @var string The message type of the managers' message: the one of a declaration that awaits. */
    private const PENDING = 'declarationpending';

    /** @var int How long a message waits after the LAST erasure that concerns its vaeiste: fifteen minutes, the decision. */
    private const GRACE = 900;

    /** @var string A job title no message may carry. */
    private const ROLE = 'Cadre de santé, EHPAD Les Tilleuls';

    /** @var string A statement no message may carry. */
    private const STATEMENT = 'Je soussignée Nadia Benali atteste avoir encadré cette personne.';

    /** @var string A narrative no message may carry. */
    private const SITUATION = 'Toilette de Mme Lambert, chambre 12, au lever.';

    /** @var string A setting detail no message may carry. */
    private const SETTINGDETAIL = 'Unité Les Glycines, équipe de nuit';

    /** @var \stdClass The first vaeiste: two declarations, both attested by Nadia. */
    private $camille;

    /** @var \stdClass The second vaeiste: one declaration, attested by Nadia. */
    private $lea;

    /** @var \stdClass The manager whose data is erased. */
    private $nadia;

    /** @var \stdClass A manager who follows both vaeistes. */
    private $hugo;

    /** @var \stdClass A manager who follows Lea only. */
    private $lucie;

    /** @var int The role of the vaeistes. */
    private $candidaterole;

    /** @var int The role of the managers: follow, and attest. */
    private $managerrole;

    /** @var int The competency of the record seeded in setUp(). */
    private $competencyid;

    /** @var \stdClass[] The declarations, by vaeiste: 'camille' (two) and 'lea' (one). */
    private $claims = [];

    /** @var \phpunit_message_sink */
    private $sink;

    /**
     * A record, two vaeistes, four managers and three attested declarations; an empty queue.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG, $DB;

        $this->resetAfterTest();
        // The notification observers are external: they wait for a commit. See notifier_test.
        $this->preventResetByRollback();
        require_once($CFG->libdir . '/formslib.php');

        $generator = $this->getDataGenerator();
        $generator->get_plugin_generator('tool_vae')->create_source([
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
        ]);
        $this->competencyid = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RNCP36832-C03'], MUST_EXIST);

        $system = \context_system::instance()->id;
        $this->candidaterole = $generator->create_role();
        foreach (['declare', 'viewown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $this->candidaterole, $system, true);
        }
        $this->managerrole = $generator->create_role();
        foreach (['viewassigned', 'attest'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $this->managerrole, $system, true);
        }
        set_role_contextlevels($this->managerrole, [CONTEXT_USER]);

        // Named, not drawn at random: a name that happened to match a word of a message would make the tests lie.
        $this->camille = $generator->create_user(['firstname' => 'Camille', 'lastname' => 'Martin']);
        $this->lea = $generator->create_user(['firstname' => 'Léa', 'lastname' => 'Garnier']);
        $this->nadia = $generator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->hugo = $generator->create_user(['firstname' => 'Hugo', 'lastname' => 'Marchand']);
        $this->lucie = $generator->create_user(['firstname' => 'Lucie', 'lastname' => 'Bernard']);

        foreach ([$this->camille, $this->lea] as $vaeiste) {
            role_assign($this->candidaterole, $vaeiste->id, \context_user::instance($vaeiste->id)->id);
            foreach ([$this->nadia, $this->hugo] as $manager) {
                role_assign($this->managerrole, $manager->id, \context_user::instance($vaeiste->id)->id);
            }
        }
        role_assign($this->managerrole, $this->lucie->id, \context_user::instance($this->lea->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->claims = [
            'camille' => [$this->declare_as($this->camille), $this->declare_as($this->camille)],
            'lea' => [$this->declare_as($this->lea)],
        ];
        foreach (array_merge($this->claims['camille'], $this->claims['lea']) as $claim) {
            $this->attest_as($this->nadia, $claim);
        }

        // The messages of the declarations and of the attestations above are not the subject: an empty queue.
        $DB->delete_records('task_adhoc');
        $this->sink = $this->redirectMessages();
    }

    /**
     * One declaration, in the first person, carrying the text no message may carry.
     *
     * @param \stdClass $user The vaeiste.
     * @return \stdClass The stored declaration.
     */
    private function declare_as(\stdClass $user): \stdClass {
        $this->setUser($user);

        return (new evidence_manager())->declare_activity((int) $user->id, (object) [
            'competencyid' => $this->competencyid,
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'settingdetail' => self::SETTINGDETAIL,
            'situation' => self::SITUATION,
            'situationformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * Attest a declaration as a manager.
     *
     * @param \stdClass $manager
     * @param \stdClass $claim
     * @return \stdClass The attestation.
     */
    private function attest_as(\stdClass $manager, \stdClass $claim): \stdClass {
        $this->setUser($manager);

        return (new attestation_manager())->attest((int) $claim->id, (object) [
            'attestantrole' => self::ROLE,
            'statement' => self::STATEMENT,
        ]);
    }

    /**
     * Delete an account the way an administrator does, with Moodle left at its defaults: the request is filed, awaiting.
     *
     * @param \stdClass $user
     */
    private function delete_account(\stdClass $user): void {
        global $DB;

        set_config('automaticdeletionrequests', 1, 'tool_dataprivacy');
        set_config('automaticdatadeletionapproval', 0, 'tool_dataprivacy');
        $this->setAdminUser();
        delete_user($DB->get_record('user', ['id' => $user->id], '*', MUST_EXIST));
    }

    /**
     * The data protection officer approves the requests Moodle filed for these accounts; the cron runs the tasks.
     *
     * @param \stdClass[] $users Accounts deleted with delete_account().
     */
    private function approve_the_requests_of(array $users): void {
        $ids = [];
        foreach ($users as $user) {
            $requests = \tool_dataprivacy\api::get_data_requests(
                (int) $user->id,
                [\tool_dataprivacy\api::DATAREQUEST_STATUS_AWAITING_APPROVAL],
                [\tool_dataprivacy\api::DATAREQUEST_TYPE_DELETE]
            );
            $this->assertCount(1, $requests, 'Moodle files an erasure request when an account is deleted.');
            $ids[] = (int) reset($requests)->get('id');
        }

        $this->setAdminUser();
        ob_start();
        foreach ($ids as $id) {
            \tool_dataprivacy\api::approve_data_request($id);
        }
        $this->runAdhocTasks('\tool_dataprivacy\task\process_data_request_task');
        ob_end_clean();
    }

    /**
     * What the approval of a request for erasure hands to the provider of this plugin for a manager, in these dossiers.
     *
     * @param \stdClass $user The manager.
     * @param \stdClass[] $candidates The vaeistes whose user contexts it names.
     * @return approved_contextlist
     */
    private function approval(\stdClass $user, array $candidates): approved_contextlist {
        return new approved_contextlist(
            \core_user::get_user($user->id),
            'tool_vae',
            array_map(fn($candidate) => \context_user::instance($candidate->id)->id, $candidates)
        );
    }

    /**
     * The messages of one provider waiting in the queue.
     *
     * @param string $provider What the task carries.
     * @return \core\task\adhoc_task[]
     */
    private function queued(string $provider): array {
        return array_values(array_filter(
            \core\task\manager::get_adhoc_tasks(send_notification::class),
            fn($task) => $task->get_custom_data()->provider === $provider
        ));
    }

    /**
     * Who the waiting tasks are for, and about whom, in an order that does not depend on the queue's.
     *
     * @param \core\task\adhoc_task[] $tasks
     * @return string[] 'recipient id/vaeiste id'
     */
    private static function pairs(array $tasks): array {
        $pairs = array_map(function ($task) {
            $data = $task->get_custom_data();

            return (int) $data->recipientid . '/' . (int) $data->candidateid;
        }, $tasks);
        sort($pairs);

        return $pairs;
    }

    /**
     * The same, for the pairs a test expects.
     *
     * @param \stdClass[][] $pairs [recipient, vaeiste]
     * @return string[]
     */
    private static function expected_pairs(array $pairs): array {
        $expected = array_map(fn($pair) => (int) $pair[0]->id . '/' . (int) $pair[1]->id, $pairs);
        sort($expected);

        return $expected;
    }

    /**
     * Run every queued notification, as cron would, whatever its scheduled time.
     */
    private function deliver_all(): void {
        $this->runAdhocTasks(send_notification::class);
    }

    /**
     * Assert that a queued message waits fifteen minutes after the erasure, neither less nor more.
     *
     * The hour is bracketed by the instants taken around the erasure (the clock counts in seconds), and the fifteen minutes are
     * written out here, not read from the class: a wait of nothing, of a minute or of a day would pass a test that compared the
     * task to the constant it was built from. cron is not asked: deliver_all() plays every task whatever its hour, so the wait
     * is only ever read from the task.
     *
     * @param \core\task\adhoc_task $task
     * @param int $from The instant before the erasure that queued, or renewed, it.
     * @param int $to The instant after.
     */
    private function assert_waits_fifteen_minutes(\core\task\adhoc_task $task, int $from, int $to): void {
        $this->assertGreaterThanOrEqual(
            $from + self::GRACE,
            $task->get_next_run_time(),
            'The message goes out before the fifteen minutes are over.'
        );
        $this->assertLessThanOrEqual(
            $to + self::GRACE,
            $task->get_next_run_time(),
            'The message waits more than fifteen minutes after the erasure.'
        );
    }

    /**
     * A third manager, Paul Roux, attests a third declaration of Camille: the erasure of Paul adds to that of Nadia.
     *
     * The messages of that declaration and of its attestation are not the subject: the queue is emptied.
     *
     * @return array [Paul Roux, the declaration he attested]
     */
    private function paul_attests_a_third_declaration_of_camille(): array {
        global $DB;

        $paul = $this->getDataGenerator()->create_user(['firstname' => 'Paul', 'lastname' => 'Roux']);
        role_assign($this->managerrole, $paul->id, \context_user::instance($this->camille->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $third = $this->declare_as($this->camille);
        $this->attest_as($paul, $third);
        $DB->delete_records('task_adhoc');
        $this->setAdminUser();

        return [$paul, $third];
    }

    /**
     * The messages caught so far for one type of this plugin, to one person.
     *
     * @param string $type
     * @param \stdClass $to
     * @return \stdClass[]
     */
    private function caught(string $type, \stdClass $to): array {
        return array_values(array_filter(
            $this->sink->get_messages_by_component_and_type('tool_vae', $type),
            fn($message) => (int) $message->useridto === (int) $to->id
        ));
    }

    /**
     * What a message says: the string, filled in, then the link to the dossier of the vaeiste.
     *
     * @param string $string The key of the body.
     * @param array $a Its values.
     * @param \stdClass $vaeiste Whose dossier the link opens.
     * @return string
     */
    private function body(string $string, array $a, \stdClass $vaeiste): string {
        $url = new \moodle_url('/admin/tool/vae/dossier.php', ['userid' => $vaeiste->id]);

        return get_string($string, 'tool_vae', (object) $a) . "\n\n" . $url->out(false);
    }

    /**
     * Every field of a message a person can read.
     *
     * @param \stdClass $message
     * @return string[] Keyed by field name.
     */
    private static function text_of(\stdClass $message): array {
        return [
            'subject' => (string) $message->subject,
            'fullmessage' => (string) $message->fullmessage,
            'fullmessagehtml' => (string) $message->fullmessagehtml,
            'smallmessage' => (string) $message->smallmessage,
            'contexturlname' => (string) $message->contexturlname,
        ];
    }

    /**
     * The words that no message of this file may carry: who signed, what was written, and why the attestation was withdrawn.
     *
     * @return string[]
     */
    private function forbidden(): array {
        return [
            fullname($this->nadia), 'Benali', self::ROLE, self::STATEMENT, self::SITUATION, self::SETTINGDETAIL, 'Lambert',
            'rgpd', get_string('withdrawreason:rgpd', 'tool_vae'), 'erasure', 'erased', 'deleted', 'data protection',
        ];
    }

    /**
     * Assert that a message names no signatory, quotes no declaration and gives no cause.
     *
     * @param \stdClass $message
     */
    private function assert_says_only_the_fact(\stdClass $message): void {
        $this->assertEquals(\core_user::NOREPLY_USER, $message->useridfrom, 'Sent by the site, in nobody\'s name.');
        foreach (self::text_of($message) as $field => $text) {
            foreach ($this->forbidden() as $words) {
                $this->assertStringNotContainsString($words, $text, "The message carries '$words' in $field.");
            }
        }
    }

    /**
     * The approval of the request tells each vaeiste once, with the number of declarations, and says only the fact.
     *
     * The real chain: the account is deleted (nothing is said, the signatures still stand), the officer approves, the cron
     * strips. Camille lost two attestations: the plural words. Lea lost one: the very words of an ordinary withdrawal, with
     * the reference. The managers who remain are named as who can give a new attestation; the erased one is not, nor
     * her title, nor her words, nor the cause.
     */
    public function test_the_approval_of_the_erasure_tells_each_vaeiste_once_and_only_what_happened(): void {
        $this->delete_account($this->nadia);
        $this->assertSame([], $this->queued(self::ERASED), 'Told at the deletion of the account, while the signatures stand.');

        $this->approve_the_requests_of([$this->nadia]);

        $tasks = $this->queued(self::ERASED);
        $this->assertSame(
            self::expected_pairs([[$this->camille, $this->camille], [$this->lea, $this->lea]]),
            self::pairs($tasks),
            'One task for each vaeiste who lost an attestation, and for nobody else.'
        );

        $this->deliver_all();

        $camille = $this->caught(self::WITHDRAWN, $this->camille);
        $this->assertCount(1, $camille, 'Two declarations of Camille lost their attestation and she is told once.');
        $this->assertSame(get_string('notifyattestationswithdrawn_subject', 'tool_vae'), $camille[0]->subject);
        $this->assertSame($this->body(
            'notifyattestationswithdrawn_body',
            ['count' => 2, 'relay' => get_string('notifyrelaymany_some', 'tool_vae', fullname($this->hugo))],
            $this->camille
        ), $camille[0]->fullmessage);
        $this->assert_says_only_the_fact($camille[0]);

        $lea = $this->caught(self::WITHDRAWN, $this->lea);
        $this->assertCount(1, $lea);
        $this->assertSame(get_string('notifyattestationwithdrawn_subject', 'tool_vae'), $lea[0]->subject);
        $this->assertSame($this->body(
            'notifyattestationwithdrawn_body',
            [
                'record' => 'RNCP36832-C03',
                'relay' => get_string('notifyrelay_some', 'tool_vae', fullname($this->hugo) . ', ' . fullname($this->lucie)),
            ],
            $this->lea
        ), $lea[0]->fullmessage);
        $this->assert_says_only_the_fact($lea[0]);

        $this->assertCount(2, $this->sink->get_messages_by_component_and_type('tool_vae', self::WITHDRAWN));
    }

    /**
     * The other managers who follow are told once for each vaeiste, never about the erased one.
     *
     * Hugo follows both vaeistes and hears twice, once for each: the words of the plural for Camille, whose two declarations
     * await again, and those of an ordinary declaration that awaits for Lea, which is what the ordinary withdrawal tells.
     * Lucie follows Lea only. Nadia's account is deleted: nobody to tell.
     */
    public function test_the_other_managers_are_told_once_per_vaeiste_that_declarations_await_again(): void {
        $this->delete_account($this->nadia);
        $this->approve_the_requests_of([$this->nadia]);

        $this->assertSame(
            self::expected_pairs([
                [$this->hugo, $this->camille],
                [$this->hugo, $this->lea],
                [$this->lucie, $this->lea],
            ]),
            self::pairs($this->queued(self::AWAIT_AGAIN)),
            'One task for each manager who follows a vaeiste who lost an attestation, once for each vaeiste.'
        );

        $this->deliver_all();

        $hugo = $this->caught(self::PENDING, $this->hugo);
        $this->assertCount(2, $hugo);
        $texts = array_column($hugo, 'fullmessage');
        $this->assertContains($this->body(
            'notifydeclarationsawaitagain_body',
            ['count' => 2, 'candidate' => fullname($this->camille)],
            $this->camille
        ), $texts);
        $this->assertContains($this->body(
            'notifydeclarationpending_body',
            ['candidate' => fullname($this->lea), 'record' => 'RNCP36832-C03'],
            $this->lea
        ), $texts);

        $lucie = $this->caught(self::PENDING, $this->lucie);
        $this->assertCount(1, $lucie);
        $this->assertSame($this->body(
            'notifydeclarationpending_body',
            ['candidate' => fullname($this->lea), 'record' => 'RNCP36832-C03'],
            $this->lea
        ), $lucie[0]->fullmessage);

        $this->assertCount(3, $this->sink->get_messages_by_component_and_type('tool_vae', self::PENDING));
        foreach ($this->sink->get_messages_by_component_and_type('tool_vae', self::PENDING) as $message) {
            $this->assert_says_only_the_fact($message);
        }
    }

    /**
     * Approving several managers at once sends one message to each vaeiste, not one for each manager.
     *
     * The officer approves in bulk: one task of the data protection tool for each request, each stripping its own manager.
     * Paul Roux attested a third declaration of Camille. Camille is told ONCE, of three declarations; the managers who follow
     * are told once for each vaeiste, not once for each erasure. The wait is renewed by the later erasure: the message goes out
     * a while after the last of the batch.
     */
    public function test_a_batch_approval_sends_one_message_per_vaeiste_and_not_one_per_manager(): void {
        [$paul, $third] = $this->paul_attests_a_third_declaration_of_camille();

        $this->delete_account($this->nadia);
        $this->delete_account($paul);
        $before = time();
        $this->approve_the_requests_of([$this->nadia, $paul]);
        $after = time();

        $erased = $this->queued(self::ERASED);
        $this->assertSame(
            self::expected_pairs([[$this->camille, $this->camille], [$this->lea, $this->lea]]),
            self::pairs($erased),
            'One message for each vaeiste, whatever the number of managers erased.'
        );
        foreach ($erased as $task) {
            if ((int) $task->get_custom_data()->recipientid === (int) $this->camille->id) {
                $ids = array_map('intval', (array) $task->get_custom_data()->evidenceids);
                sort($ids);
                $expected = array_map(fn($claim) => (int) $claim->id, array_merge($this->claims['camille'], [$third]));
                sort($expected);
                $this->assertSame($expected, $ids, 'The declarations of both erasures are in the one task.');
            }
            $this->assert_waits_fifteen_minutes($task, $before, $after);
        }
        $this->assertSame(
            self::expected_pairs([
                [$this->hugo, $this->camille],
                [$this->hugo, $this->lea],
                [$this->lucie, $this->lea],
            ]),
            self::pairs($this->queued(self::AWAIT_AGAIN)),
            'The managers who follow are told once for each vaeiste, not once for each erasure.'
        );
        foreach ($this->queued(self::AWAIT_AGAIN) as $task) {
            $this->assert_waits_fifteen_minutes($task, $before, $after);
        }

        $this->deliver_all();

        $camille = $this->caught(self::WITHDRAWN, $this->camille);
        $this->assertCount(1, $camille, 'Camille lost attestations to two erasures and is told once.');
        $this->assertStringContainsString(
            get_string('notifyattestationswithdrawn_body', 'tool_vae', (object) [
                'count' => 3,
                'relay' => get_string('notifyrelaymany_some', 'tool_vae', fullname($this->hugo)),
            ]),
            $camille[0]->fullmessage
        );
        $this->assertCount(2, $this->caught(self::PENDING, $this->hugo));
    }

    /**
     * The message is in the queue before the backups are redone: a regeneration that fails does not lose it.
     *
     * THE FAILURE THE ORDER GUARDS AGAINST. The privacy request strips the attestations, then replaces the backups and the
     * copies of the dossier. If the replacement fails the request fails and is played again; the second time there is nothing
     * left to strip, and a message queued after the replacement would never have been queued. Camille has a backup kept by
     * the administrator; a third declaration carries a signature in force that names nobody, the state the exporter refuses to
     * write. The erasure of Nadia strips, then the replacement fails: the message to Camille is in the queue all the same,
     * and the replay neither fails nor sends it twice.
     */
    public function test_the_message_is_queued_before_the_backups_are_redone_and_survives_their_failure(): void {
        global $DB;

        $this->setAdminUser();
        backup::save((int) $this->camille->id, (int) get_admin()->id);
        $this->break_the_next_export_of($this->camille);
        $this->setAdminUser();

        try {
            provider::delete_data_for_user($this->approval($this->nadia, [$this->camille]));
            $this->fail('The regeneration of the backup was meant to fail.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('names no signatory', $e->getMessage());
        }

        $this->assertSame(
            0,
            $DB->count_records_sql(
                'SELECT COUNT(a.id)
                   FROM {tool_vae_attest} a
                   JOIN {tool_vae_evidence} e ON e.id = a.evidenceid
                  WHERE e.candidateid = :candidate AND a.attestantid = :signatory',
                ['candidate' => $this->camille->id, 'signatory' => $this->nadia->id]
            ),
            'The attestations of Camille were stripped before the regeneration failed.'
        );
        $tasks = $this->queued(self::ERASED);
        $this->assertCount(1, $tasks, 'The regeneration failed and the message was lost.');
        $this->assertSame((int) $this->camille->id, (int) $tasks[0]->get_custom_data()->recipientid);

        // The request is played again: nothing is left to strip, so nothing fails and nothing is queued a second time.
        provider::delete_data_for_user($this->approval($this->nadia, [$this->camille]));
        $this->assertCount(1, $this->queued(self::ERASED), 'The replay queued the message again.');
    }

    /**
     * A message that cannot be queued never makes the erasure fail.
     *
     * The queue is down: the ad hoc task cannot be written. The erasure of Nadia goes to its end all the same - her
     * attestations are stripped, the replacement of the backups is not interrupted - and the failure is said where a developer
     * reads it, once for each message that could not be queued.
     */
    public function test_a_message_that_cannot_be_queued_never_fails_the_erasure(): void {
        global $DB;

        \core\di::set(\core\clock::class, new class implements \core\clock {
            /**
             * The instant, as a date object.
             *
             * @return \DateTimeImmutable
             */
            public function now(): \DateTimeImmutable {
                return new \DateTimeImmutable();
            }

            /**
             * The instant. The queue of ad hoc tasks asks for it when it writes a task: that is where it fails here.
             *
             * @return int
             */
            public function time(): int {
                foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                    if (($frame['function'] ?? '') === 'queue_adhoc_task') {
                        throw new \RuntimeException('The queue is down.');
                    }
                }

                return time();
            }
        });

        try {
            provider::delete_data_for_user($this->approval($this->nadia, [$this->camille]));
        } finally {
            \core\di::reset_container();
        }

        $withdrawn = $DB->get_records_sql(
            'SELECT a.id, a.attestantid, a.statement, a.withdrawnreason
               FROM {tool_vae_attest} a
               JOIN {tool_vae_evidence} e ON e.id = a.evidenceid
              WHERE e.candidateid = :candidate AND a.withdrawnat IS NOT NULL',
            ['candidate' => $this->camille->id]
        );
        $this->assertCount(2, $withdrawn, 'The erasure did not go to its end.');
        foreach ($withdrawn as $attestation) {
            $this->assertNull($attestation->attestantid);
            $this->assertNull($attestation->statement);
            $this->assertSame('rgpd', $attestation->withdrawnreason);
        }
        $this->assertSame([], $this->queued(self::ERASED));
        $this->assertDebuggingCalledCount(2);
    }

    /**
     * Circuit (b): deleting the account of a signatory named by an archive withdraws their attestations at once, and tells.
     *
     * A signature carried in from an archive names its signatory by the identifier the archive gave, and the deletion of the
     * account withdraws it on the spot, without the officer. Paul Roux signed a declaration of Camille in a dossier that was
     * exported from this site and imported again. His account is deleted: Camille is told in the same instant, and the
     * managers who follow her.
     */
    public function test_deleting_the_account_of_an_archive_signatory_tells_the_vaeiste_at_once(): void {
        global $DB;

        $paul = $this->getDataGenerator()->create_user(['firstname' => 'Paul', 'lastname' => 'Roux']);
        $claim = $this->imported_signature_of($paul);

        $this->delete_account($paul);

        $attestation = $DB->get_record('tool_vae_attest', ['evidenceid' => $claim->id], '*', MUST_EXIST);
        $this->assertNull($attestation->attestantuuid, 'The signature of the archive is withdrawn by the deletion itself.');
        $this->assertSame('rgpd', $attestation->withdrawnreason);

        $erased = $this->queued(self::ERASED);
        $this->assertSame(self::expected_pairs([[$this->camille, $this->camille]]), self::pairs($erased));
        $this->assertSame([(int) $claim->id], array_map('intval', (array) $erased[0]->get_custom_data()->evidenceids));
        $this->assertSame(
            self::expected_pairs([[$this->nadia, $this->camille], [$this->hugo, $this->camille]]),
            self::pairs($this->queued(self::AWAIT_AGAIN)),
            'The managers who follow Camille, and may attest, are told too.'
        );

        $this->deliver_all();

        $camille = $this->caught(self::WITHDRAWN, $this->camille);
        $this->assertCount(1, $camille);
        $this->assertSame($this->body(
            'notifyattestationwithdrawn_body',
            [
                'record' => 'RNCP36832-C03',
                'relay' => get_string('notifyrelay_some', 'tool_vae', fullname($this->nadia) . ', ' . fullname($this->hugo)),
            ],
            $this->camille
        ), $camille[0]->fullmessage);
        foreach ([fullname($paul), 'Roux', 'Anne Martin', 'Infirmière coordinatrice', 'rgpd'] as $words) {
            foreach (self::text_of($camille[0]) as $field => $text) {
                $this->assertStringNotContainsString($words, $text, "The message carries '$words' in $field.");
            }
        }
    }

    /**
     * Circuit (b) too is queued before the backups of the dossiers are redone.
     *
     * The account is purged directly, as the observer does; the backup of Camille is replaced after the strip, and fails.
     */
    public function test_the_deletion_of_an_account_queues_the_message_before_the_backups_are_redone(): void {
        $paul = $this->getDataGenerator()->create_user(['firstname' => 'Paul', 'lastname' => 'Roux']);
        $claim = $this->imported_signature_of($paul);
        $this->setAdminUser();
        backup::save((int) $this->camille->id, (int) get_admin()->id);
        $this->break_the_next_export_of($this->camille);
        $this->setAdminUser();

        try {
            purge::dossier((int) $paul->id);
            $this->fail('The regeneration of the backup was meant to fail.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('names no signatory', $e->getMessage());
        }

        $tasks = $this->queued(self::ERASED);
        $this->assertCount(1, $tasks, 'The regeneration failed and the message was lost.');
        $this->assertSame([(int) $claim->id], array_map('intval', (array) $tasks[0]->get_custom_data()->evidenceids));
    }

    /**
     * The sentence of a withdrawn attestation says it is no longer valid and that the vaeiste may ask again: everywhere.
     *
     * The same two strings (the sentence, and the way out that follows it when nothing stands) feed the screen of the dossier,
     * the PDF of the archive and the Word or ODT copies. After the erasure of Nadia, Camille's two declarations are shown
     * withdrawn, with nothing in force, in the three places. The file a machine reads keeps its coded reason: the closed list
     * of the published schema is unchanged.
     */
    public function test_the_dossier_says_the_attestation_is_no_longer_valid_on_screen_in_the_pdf_and_in_the_copy(): void {
        provider::delete_data_for_user($this->approval($this->nadia, [$this->camille]));
        $claim = $this->claims['camille'][0];

        $screen = html_entity_decode(strip_tags(presenter::attestations(attestation_manager::attestations((int) $claim->id))));
        $files = $this->archive_of($this->camille);
        $pdf = html_entity_decode(strip_tags(pdf_renderer::html(
            json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR),
            $this->preuves($files),
            json_decode($files['referentiel.json'], true, 512, JSON_THROW_ON_ERROR)
        )));
        $this->setUser($this->camille);
        $copy = self::docx_text(dossier_document::for_candidate((int) $this->camille->id, time())['writer']->docx());

        foreach (['screen' => $screen, 'PDF' => $pdf, 'copy' => $copy] as $where => $text) {
            $this->assertStringContainsString('This attestation is no longer valid.', $text, "Not said on the $where.");
            $this->assertStringContainsString(
                'the candidate can ask a manager to attest it again',
                $text,
                "The way out is not said on the $where."
            );
            $this->assertStringContainsString(get_string('withdrawreason:rgpd', 'tool_vae'), $text);
        }

        foreach ($this->preuves($files) as $preuve) {
            foreach ($preuve['attestations'] as $attestation) {
                $this->assertSame(['statut', 'retiree_le', 'motif'], array_keys($attestation));
                $this->assertSame('rgpd', $attestation['motif'], 'The coded reason of the machine-readable file is unchanged.');
            }
        }
    }

    /**
     * The sentence is true whatever withdrew the attestation, and says nothing of why beyond the reason in brackets.
     *
     * An erasure after the deletion of an account, the manager's own request and an ordinary withdrawal in which the
     * administrator picks the same reason from the list read the same: the sentence claims no cause the plugin does not
     * know. It is word for word the same, the date aside, and none of the three says who asked.
     */
    public function test_the_sentence_is_the_same_for_an_erasure_and_an_ordinary_withdrawal_with_the_same_reason(): void {
        $this->delete_account($this->nadia);
        $this->approve_the_requests_of([$this->nadia]);
        $erased = $this->claims['camille'][0];

        $other = $this->declare_as($this->camille);
        $attestation = $this->attest_as($this->hugo, $other);
        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $attestation->id, 'rgpd');

        $sentence = function (\stdClass $claim): string {
            $rows = attestation_manager::attestations((int) $claim->id);

            return trim(html_entity_decode(strip_tags(presenter::attestations($rows))));
        };
        $date = '/ on [^(]+\(/';
        $this->assertSame(
            preg_replace($date, ' on DATE (', $sentence($erased)),
            preg_replace($date, ' on DATE (', $sentence($other))
        );
        foreach ([$erased, $other] as $claim) {
            $this->assertStringContainsString('This attestation is no longer valid.', $sentence($claim));
        }
        $this->assertStringContainsString('(' . get_string('withdrawreason:rgpd', 'tool_vae') . ')', $sentence($erased));
        $this->assertDoesNotMatchRegularExpression(
            '/requested by the signatory|asked by the signatory|demandé par le signataire/u',
            $sentence($erased)
        );
        foreach (['en', 'fr'] as $lang) {
            // Read from the plugin's own file: the French pack is not installed in the test site, and the string manager
            // then answers in English whatever language is asked.
            $string = [];
            include(__DIR__ . '/../lang/' . $lang . '/tool_vae.php');
            $shown = $string['attestationwithdrawnon'];
            $this->assertDoesNotMatchRegularExpression(
                '/requested by the signatory|asked by the signatory|demandé par le signataire|demandée par le signataire/u',
                $shown,
                "lang/$lang says that the signatory asked, and the erasure may be Moodle's own."
            );
            $this->assertStringContainsString('{$a->reason}', $shown, "lang/$lang lost the reason.");
            $this->assertStringNotContainsString('{$a->who}', $shown);

            // The way out is a sentence of its own: it is said only to a declaration that has no attestation in force.
            $this->assertArrayHasKey('attestationwithdrawnagain', $string, "lang/$lang has no sentence for the way out.");
            $this->assertDoesNotMatchRegularExpression(
                '/requested by the signatory|asked by the signatory|demandé par le signataire|demandée par le signataire/u',
                $string['attestationwithdrawnagain'],
                "lang/$lang says that the signatory asked."
            );
            $this->assertStringNotContainsString(
                '{$a',
                $string['attestationwithdrawnagain'],
                "lang/$lang: the way out takes no value, it is the same sentence whatever withdrew the attestation."
            );
            $this->assertStringNotContainsString(
                $string['attestationwithdrawnagain'],
                $shown,
                "lang/$lang says the way out in the sentence of every withdrawn attestation."
            );
        }
    }

    /**
     * The way out is offered only to a declaration that has no attestation in force: the dossier does not contradict itself.
     *
     * THE CASE. Nadia's attestation of a declaration of Camille is withdrawn by the erasure; the message that tells the managers
     * who follow her invites them to attest it again, and Hugo does. The dossier then holds, for that declaration, an attestation
     * that was withdrawn AND the one that stands. Both are shown, and the withdrawn one still says that it is no longer valid;
     * but it must not go on to invite the vaeiste to ask a manager for an attestation the declaration already has. The other
     * declaration, still without one, keeps its invitation. Said in the three places that write the dossier: the screen, the
     * PDF of the archive and the Word copy.
     *
     * AND ONCE ONLY. When the attestation of Hugo is withdrawn in its turn nothing stands and the declaration carries two
     * withdrawn attestations: the way out is said once, after the last, not after each.
     */
    public function test_the_way_out_is_offered_only_to_a_declaration_that_has_no_attestation_in_force(): void {
        $invitation = 'the candidate can ask a manager to attest it again';
        $nolonger = 'This attestation is no longer valid.';
        [$first, $second] = $this->claims['camille'];
        provider::delete_data_for_user($this->approval($this->nadia, [$this->camille]));

        // Nothing stands anywhere: each declaration says the attestation is no longer valid, and what the vaeiste can do.
        foreach ([$first, $second] as $claim) {
            $screen = $this->screen_of($claim);
            $this->assertSame(1, substr_count($screen, $nolonger));
            $this->assertSame(1, substr_count($screen, $invitation));
        }

        // Hugo attests the first again.
        $hugos = $this->attest_as($this->hugo, $first);
        $screen = $this->screen_of($first);
        $this->assertSame(1, substr_count($screen, $nolonger), 'The withdrawn attestation no longer says it is not valid.');
        $this->assertSame(
            0,
            substr_count($screen, $invitation),
            'The declaration has an attestation in force and the screen invites to ask for one.'
        );
        $this->assertStringContainsString(self::ROLE, $screen, 'The attestation in force is not shown.');
        $this->assertSame(
            1,
            substr_count($this->screen_of($second), $invitation),
            'The declaration that has no attestation lost its way out.'
        );
        foreach ($this->pdf_and_copy_of_camille() as $where => $text) {
            $this->assertSame(
                2,
                substr_count($text, $nolonger),
                "The $where does not say, twice, that an attestation is not valid."
            );
            $this->assertSame(
                1,
                substr_count($text, $invitation),
                "The $where invites to ask again once, for the declaration with no attestation in force, not for both."
            );
            $this->assertSame(1, substr_count($text, self::ROLE), "The $where does not show the attestation in force.");
        }

        // Hugo's attestation is withdrawn in the ordinary way: nothing stands on the first, which now has two withdrawn.
        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $hugos->id, 'erreur');
        $screen = $this->screen_of($first);
        $this->assertSame(2, substr_count($screen, $nolonger));
        $this->assertSame(1, substr_count($screen, $invitation), 'The way out is said after each withdrawn attestation.');
        foreach ($this->pdf_and_copy_of_camille() as $where => $text) {
            $this->assertSame(
                3,
                substr_count($text, $nolonger),
                "The $where does not say it for the three withdrawn attestations."
            );
            $this->assertSame(
                2,
                substr_count($text, $invitation),
                "The $where does not say the way out once for each declaration, and no more."
            );
        }
    }

    /**
     * The sentence that names who can give a new attestation agrees with one name as with several.
     *
     * The names are joined with commas and the sentence is built around them. A verb that follows the list in the singular
     * ("Hugo Marchand, Lucie Bernard peut la donner") is wrong as soon as two managers follow the vaeiste, which is the usual
     * case. In French the list therefore ends the sentence, and no verb can be made to agree with it; in English "can give it"
     * is the same for one and for several. Read from the plugin's own files: the French pack is not installed in the test site.
     */
    public function test_the_sentence_that_names_the_managers_agrees_with_one_name_as_with_several(): void {
        $string = [];
        include(__DIR__ . '/../lang/fr/tool_vae.php');

        foreach (['notifyrelay_some', 'notifyrelaymany_some'] as $key) {
            $this->assertMatchesRegularExpression(
                '/: \{\$a\}\.$/u',
                $string[$key],
                "lang/fr, $key: the names must end the sentence, or a verb agrees with the last of them."
            );
            $this->assertDoesNotMatchRegularExpression('/\{\$a\}\s+\p{L}/u', $string[$key]);
        }
    }

    /**
     * Nothing is said when no attestation was withdrawn, and nothing is said twice.
     *
     * Hugo attested nothing: his erasure withdraws nothing, queues nothing. Lea's attestation was withdrawn earlier in the
     * ordinary way (and told in the ordinary way): the later erasure of Nadia finds nothing of hers to withdraw there. Camille
     * is the control: her two attestations are Nadia's, and she is told. Lea's own erasure destroys her dossier: the message
     * that was waiting for her, and the one for Hugo about her, are not sent.
     */
    public function test_nothing_is_said_when_no_attestation_was_withdrawn(): void {
        // The ordinary withdrawal below leaves messages that the destroyed dossier makes untrue: the task log says so.
        $this->expectOutputRegex('/not sent: no longer due/');
        $this->setAdminUser();
        provider::delete_data_for_user($this->approval($this->hugo, [$this->camille, $this->lea]));
        $this->assertSame([], $this->queued(self::ERASED), 'A manager who attested nothing queued a message.');
        $this->assertSame([], $this->queued(self::AWAIT_AGAIN));

        $ordinary = attestation_manager::active_attestation((int) $this->claims['lea'][0]->id);
        (new attestation_manager())->withdraw((int) $ordinary->id, 'erreur');
        provider::delete_data_for_user($this->approval($this->nadia, [$this->camille, $this->lea]));

        $this->assertSame(
            self::expected_pairs([[$this->camille, $this->camille]]),
            self::pairs($this->queued(self::ERASED)),
            'Lea was told of an attestation the erasure did not withdraw.'
        );

        // The messages above and the ordinary withdrawal's own are in the queue; the erasure of Lea ends her dossier.
        $this->setAdminUser();
        provider::delete_data_for_user($this->approval($this->lea, [$this->lea]));
        $this->deliver_all();
        $this->assertSame([], $this->caught(self::WITHDRAWN, $this->lea), 'A destroyed dossier was told about.');
        $this->assertCount(1, $this->caught(self::WITHDRAWN, $this->camille));
    }

    /**
     * A message says only what is still true when it leaves: a declaration attested again meanwhile is not counted.
     *
     * Camille lost two attestations. Before the cron, Hugo attests one again: the message is the one of a single declaration,
     * with its reference, not a count of two. When he attests the other too, Camille is told nothing, and Hugo neither.
     */
    public function test_a_vaeiste_whose_declarations_were_attested_again_is_told_only_what_is_still_true(): void {
        provider::delete_data_for_user($this->approval($this->nadia, [$this->camille]));
        $erased = $this->queued(self::ERASED);
        $this->assertCount(1, $erased);
        $again = $this->queued(self::AWAIT_AGAIN);
        $this->assertCount(1, $again);

        $this->attest_as($this->hugo, $this->claims['camille'][0]);
        $this->assertTrue(notifier::deliver($erased[0]->get_custom_data()));
        $messages = $this->caught(self::WITHDRAWN, $this->camille);
        $this->assertCount(1, $messages);
        $this->assertSame(get_string('notifyattestationwithdrawn_subject', 'tool_vae'), $messages[0]->subject);
        $this->assertStringContainsString('RNCP36832-C03', $messages[0]->fullmessage);
        $this->assertTrue(notifier::deliver($again[0]->get_custom_data()));

        $this->attest_as($this->hugo, $this->claims['camille'][1]);
        $this->assertFalse(notifier::deliver($erased[0]->get_custom_data()), 'Camille was told of attestations she has again.');
        $this->assertFalse(notifier::deliver($again[0]->get_custom_data()), 'Hugo was told of declarations that are attested.');
        $this->assertCount(1, $this->caught(self::WITHDRAWN, $this->camille));
        $this->assertCount(1, $this->caught(self::PENDING, $this->hugo));
    }

    /**
     * The manager who asks for their own erasure is not asked to attest what they have just had withdrawn.
     *
     * The request is the manager's own and their account still stands, still follows both vaeistes, may still attest: they are
     * left out of the second message. The others are in it.
     */
    public function test_a_personal_request_does_not_relaunch_the_manager_who_made_it(): void {
        $this->assertTrue(\tool_vae\local\access::can_attest((int) $this->camille->id, (int) $this->nadia->id), 'The premise.');

        provider::delete_data_for_user($this->approval($this->nadia, [$this->camille, $this->lea]));

        $this->assertSame(
            self::expected_pairs([
                [$this->hugo, $this->camille],
                [$this->hugo, $this->lea],
                [$this->lucie, $this->lea],
            ]),
            self::pairs($this->queued(self::AWAIT_AGAIN))
        );
        $this->assertCount(2, $this->queued(self::ERASED));
    }

    /**
     * A task carries identifiers only, and waits before it goes out.
     *
     * Identifiers: the kind of message, who is told, whose dossier, which declarations. No text, no name, no date. And the
     * wait: fifteen minutes, so that a later erasure of the same batch can still join the message. The decision says fifteen
     * minutes, not "a while": the length is pinned to the second, and both ends of it.
     */
    public function test_the_tasks_carry_identifiers_only_and_wait_before_they_go_out(): void {
        $this->assertSame(self::GRACE, notifier::ERASURE_GRACE, 'The decision is fifteen minutes.');

        $before = time();
        provider::delete_data_for_user($this->approval($this->nadia, [$this->camille]));
        $after = time();

        $tasks = array_merge($this->queued(self::ERASED), $this->queued(self::AWAIT_AGAIN));
        $this->assertCount(2, $tasks);
        foreach ($tasks as $task) {
            $data = (array) $task->get_custom_data();
            ksort($data);
            $this->assertSame(['candidateid', 'evidenceids', 'provider', 'recipientid'], array_keys($data));
            $this->assertIsInt($data['candidateid']);
            $this->assertIsInt($data['recipientid']);
            $this->assertIsString($data['provider']);
            $this->assertNotEmpty($data['evidenceids']);
            foreach ($data['evidenceids'] as $id) {
                $this->assertIsInt($id);
            }
            $this->assert_waits_fifteen_minutes($task, $before, $after);
            $this->assertStringNotContainsString('Benali', json_encode($data));
        }
    }

    /**
     * Each erasure starts the fifteen minutes again, from the LAST erasure that concerns the vaeiste.
     *
     * The case the wait exists for: the officer approves the requests one by one, a few minutes apart. Nadia's erasure is
     * approved; her messages are queued. Long after, as far as the queue is concerned (the tasks are put back an hour), Paul's
     * erasure is approved: it concerns Camille, not Lea. Camille's two messages, and the managers' who follow her, are
     * still ONE task each, now carrying the three declarations, and wait fifteen minutes from Paul's erasure, not from
     * Nadia's: a wait counted from the first erasure would let the message out in the middle of the batch. Lea's are not
     * concerned and keep their hour: the wait is not pushed back for everyone.
     */
    public function test_a_later_erasure_starts_the_fifteen_minutes_again_for_the_vaeiste_it_concerns(): void {
        global $DB;

        [$paul, $third] = $this->paul_attests_a_third_declaration_of_camille();
        $this->delete_account($this->nadia);
        $this->delete_account($paul);

        $this->approve_the_requests_of([$this->nadia]);
        $firstwave = array_merge($this->queued(self::ERASED), $this->queued(self::AWAIT_AGAIN));
        $this->assertCount(5, $firstwave, 'Two messages to the vaeistes, three to the managers who follow them.');
        // Nadia's erasure was an hour ago, as the queue sees it: the tasks are put back, in the base, as it would have it.
        $anhourago = time() - HOURSECS;
        foreach ($firstwave as $task) {
            $DB->set_field('task_adhoc', 'nextruntime', $anhourago, ['id' => $task->get_id()]);
        }

        $before = time();
        $this->approve_the_requests_of([$paul]);
        $after = time();

        $erased = $this->queued(self::ERASED);
        $again = $this->queued(self::AWAIT_AGAIN);
        $this->assertSame(
            self::expected_pairs([[$this->camille, $this->camille], [$this->lea, $this->lea]]),
            self::pairs($erased),
            'The later erasure queued a second message instead of renewing the first.'
        );
        $this->assertSame(
            self::expected_pairs([
                [$this->hugo, $this->camille],
                [$this->hugo, $this->lea],
                [$this->lucie, $this->lea],
            ]),
            self::pairs($again),
            'The later erasure queued a second message to a manager instead of renewing the first.'
        );

        foreach (array_merge($erased, $again) as $task) {
            $data = $task->get_custom_data();
            if ((int) $data->candidateid === (int) $this->camille->id) {
                $this->assert_waits_fifteen_minutes($task, $before, $after);
                $ids = array_map('intval', (array) $data->evidenceids);
                sort($ids);
                $expected = array_map(fn($claim) => (int) $claim->id, array_merge($this->claims['camille'], [$third]));
                sort($expected);
                $this->assertSame($expected, $ids, 'The declarations of both erasures are in the one task.');
            } else {
                $this->assertSame(
                    $anhourago,
                    (int) $task->get_next_run_time(),
                    'An erasure that does not concern Lea pushed back what she is waiting for.'
                );
            }
        }
    }

    /**
     * A vaeiste whose account is suspended by the time the cron runs is not sent the message.
     */
    public function test_a_suspended_vaeiste_is_not_told(): void {
        global $DB;

        provider::delete_data_for_user($this->approval($this->nadia, [$this->camille]));
        $erased = $this->queued(self::ERASED);
        $this->assertCount(1, $erased);

        $DB->set_field('user', 'suspended', 1, ['id' => $this->camille->id]);

        $this->assertFalse(notifier::deliver($erased[0]->get_custom_data()));
        $this->assertSame([], $this->caught(self::WITHDRAWN, $this->camille));
    }

    /**
     * A declaration of Camille's dossier signed by an account of this site, named by the identifier an archive gave.
     *
     * What dossier_importer writes for a signature in force: no account, the ULID of the archive. Here the ULID is the one
     * this site gave the account, a backup restored in place. The pending messages of the declaration are dropped from the
     * queue: they are not the subject.
     *
     * @param \stdClass $signatory
     * @return \stdClass The declaration.
     */
    private function imported_signature_of(\stdClass $signatory): \stdClass {
        global $DB;

        $claim = $this->declare_as($this->camille);
        $now = time();
        $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => identity_map::generate_ulid(),
            'evidenceid' => $claim->id,
            'attestantid' => null,
            'attestantuuid' => identity_map::uuid_for(identity_map::TYPE_USER, (int) $signatory->id),
            'attestantrole' => 'Infirmière coordinatrice, EHPAD Les Tilleuls',
            'statement' => 'Je soussignée Anne Martin atteste…',
            'attestedat' => $now,
            'importedat' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
        $DB->delete_records('task_adhoc');

        return $claim;
    }

    /**
     * Leave the next export of a vaeiste's dossier unable to be written.
     *
     * A declaration with a signature in force that names nobody: the state the exporter refuses, as it must (a signature
     * without a signatory is never written). It is how the replacement of a backup, after an erasure, fails.
     *
     * @param \stdClass $vaeiste
     */
    private function break_the_next_export_of(\stdClass $vaeiste): void {
        global $DB;

        $claim = $this->declare_as($vaeiste);
        $now = time();
        $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => identity_map::generate_ulid(),
            'evidenceid' => $claim->id,
            'attestantid' => null,
            'attestantuuid' => null,
            'attestantrole' => 'Rôle',
            'statement' => 'Énoncé',
            'attestedat' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
        $DB->delete_records('task_adhoc');
    }

    /**
     * The files of a vaeiste's archive, built as the vaeiste would export it.
     *
     * @param \stdClass $vaeiste
     * @return string[] Path => content.
     */
    private function archive_of(\stdClass $vaeiste): array {
        global $DB;

        $this->setUser($vaeiste);
        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $vaeiste->id], 'timecreated ASC, id ASC');

        return (new exporter())->build((int) $vaeiste->id, $claims, identity_map::generate_ulid(), time());
    }

    /**
     * What the screen of the dossier says, as text, of the attestations of one declaration.
     *
     * @param \stdClass $claim
     * @return string
     */
    private function screen_of(\stdClass $claim): string {
        return html_entity_decode(strip_tags(presenter::attestations(attestation_manager::attestations((int) $claim->id))));
    }

    /**
     * What the PDF of Camille's archive and the Word copy of her dossier say, as text.
     *
     * @return string[] 'PDF' and 'copy', each the whole dossier: every declaration of Camille.
     */
    private function pdf_and_copy_of_camille(): array {
        $files = $this->archive_of($this->camille);
        $pdf = html_entity_decode(strip_tags(pdf_renderer::html(
            json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR),
            $this->preuves($files),
            json_decode($files['referentiel.json'], true, 512, JSON_THROW_ON_ERROR)
        )));
        $this->setUser($this->camille);
        $copy = self::docx_text(dossier_document::for_candidate((int) $this->camille->id, time())['writer']->docx());

        return ['PDF' => $pdf, 'copy' => $copy];
    }

    /**
     * The claim files of an archive, decoded.
     *
     * @param string[] $files
     * @return array[]
     */
    private function preuves(array $files): array {
        $out = [];
        foreach ($files as $path => $content) {
            if (str_starts_with($path, 'preuves/')) {
                $out[] = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $out;
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
}
