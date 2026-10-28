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

use tool_vae\local\attestation_manager;
use tool_vae\local\evidence_manager;
use tool_vae\local\importer;
use tool_vae\task\send_notification;

/**
 * Lot 7 ter: who is told what - and that nobody is told what the dossier says.
 *
 * TWO MOMENTS, AS IN PRODUCTION. The observers queue one ad hoc task per recipient; deliver_all()
 * runs them, as cron would. A test that reads the messages before running the tasks reads
 * nothing, and several below do exactly that on purpose.
 *
 * THE MESSAGES ARE CAUGHT, NOT SENT. redirectMessages() takes each one where message_send()
 * would hand it to the processors, AFTER the checks that decide whether it may go at all: the
 * provider exists, and the recipient may receive it. What is asserted here is what would have
 * left the site.
 *
 * preventResetByRollback() MAKES EVERY DATABASE BEHAVE ALIKE. On PostgreSQL, PHPUnit wraps each
 * test in a transaction it never commits, and the notification observers are external: they
 * wait for a commit. Without this call the tests below would pass on silence on PostgreSQL -
 * including those asserting that somebody WAS told - while running for real on MariaDB, where
 * PHPUnit opens no such transaction.
 *
 * A task that does not send says so through mtrace(), and PHPUnit is strict about output: the
 * tests that expect a message to be withheld expect that line too, which makes it an assertion.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\notifier::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\task\send_notification::class)]
final class notifier_test extends \advanced_testcase {
    /** @var string A narrative no message may carry. The name in it is the point. */
    private const SITUATION = 'Toilette de Mme Lambert, chambre 12, au lever.';

    /** @var string A setting detail no message may carry. */
    private const SETTINGDETAIL = 'Unité Les Glycines, équipe de nuit';

    /** @var string A job title no message may carry. */
    private const ROLE = 'Cadre de santé, EHPAD Les Tilleuls';

    /** @var string A statement no message may carry. */
    private const STATEMENT = 'Je soussignée Claire Dupont atteste avoir encadré cette personne.';

    /** @var int Start of the declared period. */
    private const PERIODSTART = 1756684800;

    /** @var int End of the declared period. */
    private const PERIODEND = 1782172800;

    /** @var string What a withheld message leaves in the task log. */
    private const NOT_SENT = '/not sent: no longer due/';

    /** @var \stdClass The candidate whose dossier the messages are about. */
    private $candidate;

    /** @var \stdClass Follows the candidate and may attest: the one to be told. */
    private $manager;

    /** @var int The "Responsable VAE" role the manager holds in the candidate's context. */
    private $managerrole;

    /** @var int The competency of the record seeded in setUp(). */
    private $competencyid;

    /** @var int|null The role every candidate of these tests holds on their own context. */
    private $candidaterole = null;

    /** @var \phpunit_message_sink */
    private $sink;

    /**
     * A record, a candidate, and a manager who follows them the way the README sets it up.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        require_once($CFG->libdir . '/formslib.php');

        $generator = $this->getDataGenerator();
        $generator->get_plugin_generator('tool_vae')->create_source([
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
        ]);
        $this->competencyid = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RNCP36832-C03'], MUST_EXIST);

        // Named, not drawn at random: the generator picks names from a list, and a name that
        // happened to match one written in the narrative would make the leak tests lie.
        $this->candidate = $generator->create_user(['firstname' => 'Camille', 'lastname' => 'Martin']);
        $this->make_candidate($this->candidate);

        // The "Responsable VAE" role of the README: follow, and attest, in the candidate's
        // own user context - what tool_cohortroles assigns for every member of a cohort.
        $this->managerrole = $generator->create_role();
        $system = \context_system::instance()->id;
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $this->managerrole, $system, true);
        assign_capability('tool/vae:attest', CAP_ALLOW, $this->managerrole, $system, true);
        set_role_contextlevels($this->managerrole, [CONTEXT_USER]);

        $this->manager = $generator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        role_assign($this->managerrole, $this->manager->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->sink = $this->redirectMessages();
    }

    /**
     * Give a user what a candidate holds: declare, and read their own dossier.
     *
     * @param \stdClass $user
     */
    private function make_candidate(\stdClass $user): void {
        if ($this->candidaterole === null) {
            $this->candidaterole = $this->getDataGenerator()->create_role();
            $system = \context_system::instance()->id;
            assign_capability('tool/vae:declare', CAP_ALLOW, $this->candidaterole, $system, true);
            assign_capability('tool/vae:viewown', CAP_ALLOW, $this->candidaterole, $system, true);
        }

        role_assign($this->candidaterole, $user->id, \context_user::instance($user->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * One declaration, in the first person, carrying the text no message may carry.
     *
     * @param \stdClass $user
     * @param int $competencyid
     * @return \stdClass
     */
    private function declare_as(\stdClass $user, int $competencyid): \stdClass {
        $this->setUser($user);

        return (new evidence_manager())->declare_activity((int) $user->id, (object) [
            'competencyid' => $competencyid,
            'periodstart' => self::PERIODSTART,
            'periodend' => self::PERIODEND,
            'settingtype' => 'ehpad',
            'settingdetail' => self::SETTINGDETAIL,
            'situation' => self::SITUATION,
            'situationformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * Attest a declaration as the manager.
     *
     * @param \stdClass $claim
     * @return \stdClass The attestation.
     */
    private function attest(\stdClass $claim): \stdClass {
        $this->setUser($this->manager);

        return (new attestation_manager())->attest((int) $claim->id, (object) [
            'attestantrole' => self::ROLE,
            'statement' => self::STATEMENT,
        ]);
    }

    /**
     * Run every queued notification, as cron would - whatever its scheduled time.
     */
    private function deliver_all(): void {
        $this->runAdhocTasks(send_notification::class);
    }

    /**
     * The notifications waiting in the queue for one provider.
     *
     * @param string $provider
     * @return \core\task\adhoc_task[]
     */
    private function queued(string $provider): array {
        return array_values(array_filter(
            \core\task\manager::get_adhoc_tasks(send_notification::class),
            function ($task) use ($provider) {
                return $task->get_custom_data()->provider === $provider;
            }
        ));
    }

    /**
     * The messages caught so far for one provider of this plugin.
     *
     * @param string $provider
     * @return \stdClass[]
     */
    private function caught(string $provider): array {
        return array_values($this->sink->get_messages_by_component_and_type('tool_vae', $provider));
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
     * An import of an invented record, as importer_test makes them.
     *
     * @param array $rows rank => label
     */
    private function record(array $rows): void {
        $this->setAdminUser();

        $lines = [];
        foreach ($rows as $rang => $intitule) {
            $lines[] = (object) ['rang' => $rang, 'intitule' => $intitule, 'description' => ''];
        }

        (new importer())->import((object) [
            'fichecode' => 'RS00000',
            'fichetype' => 'rs',
            'name' => 'Certification inventée pour les tests',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'Personne — jeu d\'essai',
        ], $lines);
    }

    /**
     * The point of R2 on the manager's side: a declaration is announced to the manager who
     * follows the candidate and may attest - and to nobody else who could read it.
     */
    public function test_a_declaration_is_announced_to_the_managers_who_follow_and_may_sign(): void {
        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $candidatecontext = \context_user::instance($this->candidate->id)->id;

        // Follows, but may not sign: reading is not attesting.
        $reader = $generator->create_user();
        $readerrole = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $readerrole, $system, true);
        role_assign($readerrole, $reader->id, $candidatecontext);

        // May sign anywhere, follows nobody.
        $signer = $generator->create_user();
        $signerrole = $generator->create_role();
        assign_capability('tool/vae:attest', CAP_ALLOW, $signerrole, $system, true);
        role_assign($signerrole, $signer->id, $system);

        // Reads every dossier: management, audit. Not a mailing list.
        $director = $generator->create_user();
        $directorrole = $generator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $directorrole, $system, true);
        role_assign($directorrole, $director->id, $system);

        // The manager role in the candidate's context, AND viewassigned held at system level: the
        // guard of access::follows() refuses it here exactly as it does on the dossier page.
        $misconfigured = $generator->create_user();
        role_assign($this->managerrole, $misconfigured->id, $candidatecontext);
        $sitewide = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $sitewide, $system, true);
        role_assign($sitewide, $misconfigured->id, $system);

        accesslib_clear_all_caches_for_unit_testing();

        $this->declare_as($this->candidate, $this->competencyid);
        $this->assertSame([], $this->caught(notifier::DECLARATION_PENDING), 'Sent from the request, not from cron.');

        $this->deliver_all();

        $messages = $this->caught(notifier::DECLARATION_PENDING);
        $this->assertCount(1, $messages, 'Exactly one person should hear of the declaration.');

        $message = reset($messages);
        $this->assertEquals($this->manager->id, $message->useridto);
        $this->assertEquals(
            \core_user::NOREPLY_USER,
            $message->useridfrom,
            'Sent by the site: in the candidate\'s name, the core\'s email would carry their address.'
        );
        $this->assertStringContainsString(fullname($this->candidate), $message->fullmessage);
        $this->assertStringContainsString('RNCP36832-C03', $message->fullmessage);
        $this->assertSame(
            (new \moodle_url('/admin/tool/vae/dossier.php', ['userid' => $this->candidate->id]))->out(false),
            $message->contexturl
        );
    }

    /**
     * The three messages to managers that used to go out in the candidate's name are sent by the site, like the others.
     *
     * A declaration pending, a deposit made, a piece added after a signature. Sent "from" the candidate they carried their
     * address in the headers of the email as soon as the site lists their domain (see the next test); the tasks are delivered
     * one by one, the pending one before the signature that would make it untrue.
     */
    public function test_the_three_messages_to_managers_are_sent_by_the_site_not_by_the_candidate(): void {
        set_config('piecesopen', 1, 'tool_vae');
        set_config('piecenoantivirus', 1, 'tool_vae');

        $claim = $this->declare_as($this->candidate, $this->competencyid);
        $pending = $this->queued(notifier::DECLARATION_PENDING);
        $this->assertCount(1, $pending);
        $this->assertTrue(notifier::deliver($pending[0]->get_custom_data()));

        $this->attest($claim);
        $this->setUser($this->candidate);
        (new \tool_vae\local\piece_manager())->add_link((int) $claim->id, 'diploma', '', 'https://exemple.test/a');
        $this->deposit_rights();
        $this->deposit_as_candidate();

        foreach ([notifier::PIECE_AFTER_ATTEST, notifier::DEPOSIT_MADE] as $provider) {
            $tasks = $this->queued($provider);
            $this->assertCount(1, $tasks, "Nothing was queued for $provider.");
            $this->assertTrue(notifier::deliver($tasks[0]->get_custom_data()));
        }

        foreach ([notifier::DECLARATION_PENDING, notifier::PIECE_AFTER_ATTEST, notifier::DEPOSIT_MADE] as $provider) {
            $messages = $this->caught($provider);
            $this->assertCount(1, $messages, "$provider was not sent.");
            $this->assertEquals($this->manager->id, $messages[0]->useridto);
            $this->assertEquals(
                \core_user::NOREPLY_USER,
                $messages[0]->useridfrom,
                "$provider was sent in the candidate's name."
            );
            $this->assertStringContainsString(fullname($this->candidate), $messages[0]->fullmessage);
        }
    }

    /**
     * The email a manager receives about a declaration does not carry the candidate's address, whatever the site allows.
     *
     * The core's email output sends a message "from" a user with that user's own address as From, and as Reply-To, when the
     * site lists their domain in allowedemaildomains and their profile shows their address - the setting a site makes so that
     * forum emails come from the real name. The candidate here has both. The manager's reply would have left the platform for
     * the candidate, and the address - which this plugin reads from the account and never copies - would have travelled in
     * the headers to servers nobody here administers.
     */
    public function test_a_manager_email_never_carries_the_candidate_address(): void {
        global $DB;

        set_config('allowedemaildomains', 'example.com');
        $DB->set_field('user', 'email', 'camille@example.com', ['id' => $this->candidate->id]);
        $DB->set_field('user', 'maildisplay', \core_user::MAILDISPLAY_EVERYONE, ['id' => $this->candidate->id]);
        set_user_preference('message_provider_tool_vae_declarationpending_enabled', 'popup,email', $this->manager);

        $this->declare_as($this->candidate, $this->competencyid);

        // The messages must reach the processors for an email to be written: the sink of setUp() catches them before.
        $this->sink->close();
        $emails = $this->redirectEmails();
        $this->deliver_all();

        $sent = $emails->get_messages();
        $this->assertNotEmpty($sent, 'The premise: the manager was sent an email.');
        foreach ($sent as $email) {
            $this->assertNotSame('camille@example.com', $email->from, 'The candidate\'s address sends the email.');
            $this->assertStringNotContainsString('camille@example.com', $email->header, 'The address is in the headers.');
        }
    }

    /**
     * Rights are asked again at delivery: a manager detached from the candidate between the
     * declaration and cron - a cohort changed, a role removed - is not told.
     */
    public function test_a_manager_no_longer_following_by_delivery_is_not_told(): void {
        $this->declare_as($this->candidate, $this->competencyid);
        $this->assertCount(1, $this->queued(notifier::DECLARATION_PENDING));

        role_unassign($this->managerrole, $this->manager->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectOutputRegex(self::NOT_SENT);
        $this->deliver_all();

        $this->assertSame([], $this->caught(notifier::DECLARATION_PENDING));
    }

    /**
     * A suspended account is reached by nothing, whatever it held when the task was queued.
     */
    public function test_a_suspended_manager_is_not_told(): void {
        global $DB;

        $this->declare_as($this->candidate, $this->competencyid);
        $DB->set_field('user', 'suspended', 1, ['id' => $this->manager->id]);

        $this->expectOutputRegex(self::NOT_SENT);
        $this->deliver_all();

        $this->assertSame([], $this->caught(notifier::DECLARATION_PENDING));
    }

    /**
     * A declaration attested before cron runs no longer awaits anybody, and is not
     * announced as if it did.
     */
    public function test_a_declaration_attested_before_delivery_is_not_announced_as_pending(): void {
        $this->attest($this->declare_as($this->candidate, $this->competencyid));

        $this->expectOutputRegex(self::NOT_SENT);
        $this->deliver_all();

        $this->assertSame([], $this->caught(notifier::DECLARATION_PENDING));
        $this->assertCount(1, $this->caught(notifier::EVIDENCE_ATTESTED));
    }

    public function test_an_attestation_is_announced_to_the_candidate_from_nobody_in_particular(): void {
        $claim = $this->declare_as($this->candidate, $this->competencyid);
        $this->deliver_all();

        $this->attest($claim);
        $this->deliver_all();

        $messages = $this->caught(notifier::EVIDENCE_ATTESTED);
        $this->assertCount(1, $messages);

        $message = reset($messages);
        $this->assertEquals($this->candidate->id, $message->useridto);
        $this->assertEquals(\core_user::NOREPLY_USER, $message->useridfrom, 'The signatory must not be the sender.');
        $this->assertStringContainsString('RNCP36832-C03', $message->fullmessage);
    }

    /**
     * A withdrawal erases who signed. The message about it names who takes over - the people the
     * declaration awaits now, asked for on 13 October 2026 - but never who withdrew, nor why,
     * its code or its label.
     */
    public function test_a_withdrawal_is_announced_to_the_candidate_with_who_takes_over(): void {
        $attestation = $this->attest($this->declare_as($this->candidate, $this->competencyid));
        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $attestation->id, 'rgpd');
        $this->deliver_all();

        $messages = $this->caught(notifier::ATTESTATION_WITHDRAWN);
        $this->assertCount(1, $messages);

        $message = reset($messages);
        $this->assertEquals($this->candidate->id, $message->useridto);
        $this->assertEquals(\core_user::NOREPLY_USER, $message->useridfrom, 'The one who withdrew must not be the sender.');
        $this->assertStringContainsString(fullname($this->manager), $message->fullmessage, 'Who takes over is told.');

        $forbidden = [
            fullname(get_admin()),
            'rgpd',
            get_string('withdrawreason:rgpd', 'tool_vae'),
        ];
        foreach (self::text_of($message) as $field => $text) {
            foreach ($forbidden as $words) {
                $this->assertStringNotContainsString($words, $text, "$field names who withdrew, or why.");
            }
        }
    }

    /**
     * After a withdrawal the declaration awaits an attestation again: those who may give it
     * are told, as when it was made.
     */
    public function test_a_withdrawal_tells_the_managers_the_declaration_awaits_again(): void {
        $this->expectOutputRegex(self::NOT_SENT);
        $attestation = $this->attest($this->declare_as($this->candidate, $this->competencyid));
        $this->deliver_all();
        $before = count($this->caught(notifier::DECLARATION_PENDING));

        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
        $this->deliver_all();

        $pending = $this->caught(notifier::DECLARATION_PENDING);
        $this->assertCount($before + 1, $pending);
        $this->assertEquals($this->manager->id, end($pending)->useridto);
    }

    /**
     * A request to withdraw is told to the signatory, and a refusal to the manager who asked.
     */
    public function test_a_request_to_withdraw_and_its_refusal_are_each_told_to_the_right_manager(): void {
        $this->expectOutputRegex(self::NOT_SENT);
        $generator = $this->getDataGenerator();
        $colleague = $generator->create_user(['firstname' => 'Hugo', 'lastname' => 'Marchand']);
        role_assign($this->managerrole, $colleague->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        // The manager signs; the colleague asks them to withdraw.
        $attestation = $this->attest($this->declare_as($this->candidate, $this->competencyid));
        $this->deliver_all();

        $this->setUser($colleague);
        (new attestation_manager())->request_withdrawal((int) $attestation->id);
        $this->deliver_all();

        $requests = $this->caught(notifier::WITHDRAWAL_REQUESTED);
        $this->assertCount(1, $requests);
        $this->assertEquals($this->manager->id, $requests[0]->useridto);
        $this->assertStringContainsString(fullname($colleague), $requests[0]->fullmessage);

        // The signatory refuses; the colleague who asked is told.
        $this->setUser($this->manager);
        (new attestation_manager())->refuse_withdrawal((int) $attestation->id);
        $this->deliver_all();

        $refusals = $this->caught(notifier::WITHDRAWAL_REFUSED);
        $this->assertCount(1, $refusals);
        $this->assertEquals($colleague->id, $refusals[0]->useridto);
    }

    /**
     * A refusal answers the one person who asked: told to them even when they read every dossier and follow nobody.
     *
     * The button to ask is drawn for anyone who may attest on a dossier they can read, which includes a person who holds
     * viewany and attest at the level of the site and follows no one. The answer was told only to those who follow, so it
     * was lost on exactly that person, who saw nothing but the request vanish from the page.
     */
    public function test_a_refusal_is_told_to_a_requester_who_reads_every_dossier_without_following(): void {
        $this->expectOutputRegex(self::NOT_SENT);
        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $director = $generator->create_user(['firstname' => 'Inès', 'lastname' => 'Garnier']);
        $role = $generator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $role, $system, true);
        assign_capability('tool/vae:attest', CAP_ALLOW, $role, $system, true);
        role_assign($role, $director->id, $system);
        accesslib_clear_all_caches_for_unit_testing();

        $attestation = $this->attest($this->declare_as($this->candidate, $this->competencyid));
        $this->deliver_all();

        // The premise: they may ask, and they do not follow.
        $this->assertFalse(\tool_vae\local\access::follows((int) $this->candidate->id, (int) $director->id));
        $this->setUser($director);
        $this->assertTrue(attestation_manager::may_request_withdrawal($attestation, (int) $this->candidate->id));
        (new attestation_manager())->request_withdrawal((int) $attestation->id);
        $this->deliver_all();
        $this->assertCount(1, $this->caught(notifier::WITHDRAWAL_REQUESTED), 'The signatory was not asked.');

        $this->setUser($this->manager);
        (new attestation_manager())->refuse_withdrawal((int) $attestation->id);
        $this->deliver_all();

        $refusals = $this->caught(notifier::WITHDRAWAL_REFUSED);
        $this->assertCount(1, $refusals, 'The person who asked did not hear of the refusal.');
        $this->assertEquals($director->id, $refusals[0]->useridto);
    }

    /**
     * A message still waiting after a refusal is not delivered under the next person's request.
     */
    public function test_a_queued_request_message_does_not_survive_its_refusal(): void {
        $this->expectOutputRegex(self::NOT_SENT);
        $generator = $this->getDataGenerator();
        $first = $generator->create_user(['firstname' => 'Hugo', 'lastname' => 'Marchand']);
        $second = $generator->create_user(['firstname' => 'Lucie', 'lastname' => 'Bernard']);
        foreach ([$first, $second] as $colleague) {
            role_assign($this->managerrole, $colleague->id, \context_user::instance($this->candidate->id)->id);
        }
        accesslib_clear_all_caches_for_unit_testing();

        $attestation = $this->attest($this->declare_as($this->candidate, $this->competencyid));
        $this->deliver_all();

        // The first asks; cron has not run. The signatory refuses; the second asks.
        $this->setUser($first);
        (new attestation_manager())->request_withdrawal((int) $attestation->id);
        $this->setUser($this->manager);
        (new attestation_manager())->refuse_withdrawal((int) $attestation->id);
        $this->setUser($second);
        (new attestation_manager())->request_withdrawal((int) $attestation->id);

        $this->deliver_all();

        $requests = $this->caught(notifier::WITHDRAWAL_REQUESTED);
        $this->assertCount(1, $requests, 'Only the request that stands is told.');
        $this->assertStringContainsString(fullname($second), $requests[0]->fullmessage);
        $this->assertStringNotContainsString(fullname($first), $requests[0]->fullmessage);
    }

    /**
     * THE RULE OF R2: no message carries a word of what the candidate or the signatory wrote.
     *
     * Every message of a whole cycle - declared, attested, withdrawn - and every field of
     * each. A message that quoted the narrative "to give some context" fails here first.
     */
    public function test_no_message_carries_what_was_written_in_the_dossier(): void {
        $claim = $this->declare_as($this->candidate, $this->competencyid);
        $attestation = $this->attest($claim);
        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
        $this->deliver_all();

        $messages = $this->sink->get_messages_by_component('tool_vae');
        // Declared, attested, withdrawn - and the managers told again that it awaits.
        $this->assertCount(4, $messages, 'One message per step of the cycle.');

        // The period too, in the form the dossier writes it, and the reason in both its forms.
        $timezone = \core_date::get_server_timezone();
        $forbidden = [
            self::SITUATION, 'Lambert', self::SETTINGDETAIL, self::ROLE, self::STATEMENT, 'Dupont', $claim->corelabel,
            userdate(self::PERIODSTART, '%Y-%m', $timezone, false),
            userdate(self::PERIODEND, '%Y-%m', $timezone, false),
            'erreur',
            get_string('withdrawreason:erreur', 'tool_vae'),
        ];

        foreach ($messages as $message) {
            foreach (self::text_of($message) as $field => $text) {
                foreach ($forbidden as $words) {
                    $this->assertStringNotContainsString(
                        $words,
                        $text,
                        "The {$message->eventtype} message carries text from the dossier in $field."
                    );
                }
            }
        }
    }

    /**
     * THE SAME RULE FOR THE MESSAGE ABOUT A DECLARATION THAT IS GONE: the candidate deletes the evidence record in the core's
     * screen, the signatory is told, and the message carries no word of what the dossier held.
     *
     * The whole way, from the deletion to the message that would leave the site: the observer, the purge that names the
     * signatories before it destroys their signatures, the task, the delivery. Every field of the message is read for the
     * narrative, the detail of the setting, the job title and the statement of the signatory, and the label of the record.
     */
    public function test_a_destroyed_declaration_is_told_to_its_signatory_without_a_word_of_the_dossier(): void {
        $this->expectOutputRegex(self::NOT_SENT);
        $claim = $this->declare_as($this->candidate, $this->competencyid);
        $this->attest($claim);

        $this->setUser($this->candidate);
        (new \tool_vae\local\competency_gateway())->delete_evidence((int) $claim->userevidenceid);
        $this->deliver_all();

        $messages = $this->caught(notifier::DECLARATION_PURGED);
        $this->assertCount(1, $messages, 'The signatory was not told that their signature was destroyed.');
        $this->assertEquals($this->manager->id, $messages[0]->useridto);
        $this->assertEquals(\core_user::NOREPLY_USER, $messages[0]->useridfrom);
        $this->assertStringContainsString(fullname($this->candidate), $messages[0]->fullmessage);
        $this->assertStringContainsString('RNCP36832-C03', $messages[0]->fullmessage);

        $timezone = \core_date::get_server_timezone();
        $forbidden = [
            self::SITUATION, 'Lambert', self::SETTINGDETAIL, self::ROLE, self::STATEMENT, 'Dupont', $claim->corelabel,
            userdate(self::PERIODSTART, '%Y-%m', $timezone, false),
            userdate(self::PERIODEND, '%Y-%m', $timezone, false),
        ];
        foreach (self::text_of($messages[0]) as $field => $text) {
            foreach ($forbidden as $words) {
                $this->assertStringNotContainsString($words, $text, "The message carries text from the dossier in $field.");
            }
        }
    }

    /**
     * The message about a declaration that is gone asks again what every message to a manager asks: to READ the dossier, and
     * to attest - not the right to attest alone ("nobody signs what they may not read").
     *
     * The signatory keeps a role of the whole site that carries tool/vae:attest, which every user context inherits, but they
     * no longer follow the candidate and hold no viewany. The message names the candidate and the competency, with a link
     * they could not open: it is withheld, as the others are.
     */
    public function test_a_destroyed_declaration_is_not_told_to_a_signatory_who_can_no_longer_read_the_dossier(): void {
        $this->expectOutputRegex(self::NOT_SENT);
        $claim = $this->declare_as($this->candidate, $this->competencyid);
        $this->attest($claim);

        $this->setUser($this->candidate);
        (new \tool_vae\local\competency_gateway())->delete_evidence((int) $claim->userevidenceid);
        $this->assertCount(1, $this->queued(notifier::DECLARATION_PURGED), 'The signatory was not queued to be told.');

        // Detached from the candidate between the deletion and cron, with the right to attest left in a role of the site.
        $candidatecontext = \context_user::instance($this->candidate->id);
        role_unassign($this->managerrole, $this->manager->id, $candidatecontext->id);
        $sitewide = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:attest', CAP_ALLOW, $sitewide, \context_system::instance()->id, true);
        role_assign($sitewide, $this->manager->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(
            has_capability('tool/vae:attest', $candidatecontext, $this->manager->id),
            'The premise of the test: the right to attest is still held.'
        );

        $this->deliver_all();

        $this->assertSame([], $this->caught(notifier::DECLARATION_PURGED), 'A signatory who cannot read the dossier was told.');
    }

    /**
     * A competency that leaves its record is announced to every candidate who declared against
     * it - once each, however many declarations, after the grace period, and once only, however
     * many imports.
     */
    public function test_a_competency_leaving_its_record_is_announced_once_to_each_candidate(): void {
        global $DB;

        $this->record(['BC01' => 'Accompagner la personne', 'BC02' => 'Repérer les évolutions']);
        $bc01 = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-BC01'], MUST_EXIST);
        $bc02 = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-BC02'], MUST_EXIST);

        $second = $this->getDataGenerator()->create_user();
        $this->make_candidate($second);
        $bystander = $this->getDataGenerator()->create_user();
        $this->make_candidate($bystander);

        $this->declare_as($this->candidate, $bc01);
        $this->declare_as($this->candidate, $bc01);
        $this->declare_as($second, $bc01);
        $this->declare_as($bystander, $bc02);

        // France compétences republishes the record without BC01.
        $before = time();
        $this->record(['BC02' => 'Repérer les évolutions']);

        $tasks = $this->queued(notifier::COMPETENCY_LEFT_RECORD);
        $this->assertCount(2, $tasks, 'One task per candidate who declared against it.');
        foreach ($tasks as $task) {
            $this->assertGreaterThanOrEqual(
                $before + notifier::DEPARTURE_GRACE,
                $task->get_next_run_time(),
                'A departure was scheduled before its grace period.'
            );
        }

        $this->deliver_all();

        $messages = $this->caught(notifier::COMPETENCY_LEFT_RECORD);
        $recipients = array_map(fn($message) => (int) $message->useridto, $messages);
        sort($recipients);
        $expected = [(int) $this->candidate->id, (int) $second->id];
        sort($expected);
        $this->assertSame($expected, $recipients, 'One message per candidate who declared against it, and to them only.');

        foreach ($messages as $message) {
            $this->assertEquals(\core_user::NOREPLY_USER, $message->useridfrom);
            $this->assertStringContainsString('RS00000-BC01', $message->fullmessage);
        }

        // The same file again: the competency is still gone, and nobody is told twice.
        $this->record(['BC02' => 'Repérer les évolutions']);
        $this->assertSame([], $this->queued(notifier::COMPETENCY_LEFT_RECORD), 'A departure was announced twice.');
    }

    /**
     * A truncated file, then the right one, within the grace period: nobody is told.
     *
     * The case the grace period exists for. Without it, every candidate concerned would have
     * been told that their competency had left the record, by a file that was simply wrong.
     */
    public function test_a_departure_undone_before_the_grace_period_ends_is_never_announced(): void {
        global $DB;

        $this->record(['BC01' => 'Accompagner la personne', 'BC02' => 'Repérer les évolutions']);
        $bc01 = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-BC01'], MUST_EXIST);
        $this->declare_as($this->candidate, $bc01);

        $this->record(['BC02' => 'Repérer les évolutions']);
        $this->assertCount(1, $this->queued(notifier::COMPETENCY_LEFT_RECORD));

        $this->record(['BC01' => 'Accompagner la personne', 'BC02' => 'Repérer les évolutions']);

        $this->expectOutputRegex(self::NOT_SENT);
        $this->deliver_all();

        $this->assertSame([], $this->caught(notifier::COMPETENCY_LEFT_RECORD));
    }

    /**
     * Announced after the commit, and never after a rollback.
     *
     * The observers are external, so a departure raised inside the import's transaction waits
     * for it. Wrapped here in one more transaction, then rolled back: the site does not hold the
     * change, and nothing may even be queued. Declared internal, the observer would have queued
     * the task inside the transaction - the first assertion is what tells the two apart.
     */
    public function test_a_departure_that_is_rolled_back_is_never_announced(): void {
        global $DB;

        $this->record(['BC01' => 'Accompagner la personne', 'BC02' => 'Repérer les évolutions']);
        $bc01 = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-BC01'], MUST_EXIST);
        $this->declare_as($this->candidate, $bc01);

        $transaction = $DB->start_delegated_transaction();
        $this->record(['BC02' => 'Repérer les évolutions']);
        $this->assertSame([], $this->queued(notifier::COMPETENCY_LEFT_RECORD), 'Queued before the commit.');

        try {
            $transaction->rollback(new \moodle_exception('error'));
        } catch (\moodle_exception $e) {
            // The rollback rethrows what it is given; that is all this catch is for.
            $this->assertSame('error', $e->errorcode);
        }

        $this->assertSame([], $this->queued(notifier::COMPETENCY_LEFT_RECORD), 'Queued although rolled back.');
        $this->assertFalse(
            $DB->record_exists('tool_vae_obsolete', ['competencyid' => $bc01]),
            'The rollback did not undo the departure, so this test proves nothing.'
        );
    }

    /**
     * A candidate who may not open their own dossier is not sent a link to it.
     *
     * The provider's own filter does NOT catch this case. It requires viewown, but looks for it
     * anywhere it is allowed, and a prohibition on the candidate's own context - the one context
     * that matters - escapes it. Remove the check in deliver() and the message goes out: this
     * test is what holds that check in place.
     */
    public function test_a_candidate_who_cannot_open_their_dossier_is_not_sent_a_link_to_it(): void {
        $claim = $this->declare_as($this->candidate, $this->competencyid);

        assign_capability(
            'tool/vae:viewown',
            CAP_PROHIBIT,
            $this->candidaterole,
            \context_user::instance($this->candidate->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $this->attest($claim);

        $this->expectOutputRegex(self::NOT_SENT);
        $this->deliver_all();

        $this->assertSame([], $this->caught(notifier::EVIDENCE_ATTESTED));
    }

    /**
     * Gone, back, then gone again within the grace period: one departure stands, one message.
     *
     * Lot 8 quater, B6. Two tasks are queued, one per departure. Asked of the competency, both
     * found the second mark and the candidate was told twice; asked of their own mark, the first
     * finds it deleted by the return and falls silent.
     */
    public function test_a_departure_undone_then_repeated_is_announced_once(): void {
        global $DB;

        $this->record(['BC01' => 'Accompagner la personne', 'BC02' => 'Repérer les évolutions']);
        $bc01 = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-BC01'], MUST_EXIST);
        $this->declare_as($this->candidate, $bc01);

        $this->record(['BC02' => 'Repérer les évolutions']);
        $this->record(['BC01' => 'Accompagner la personne', 'BC02' => 'Repérer les évolutions']);
        $this->record(['BC02' => 'Repérer les évolutions']);
        $this->assertCount(2, $this->queued(notifier::COMPETENCY_LEFT_RECORD), 'This test needs both tasks queued.');

        $this->expectOutputRegex(self::NOT_SENT);
        $this->deliver_all();

        $this->assertCount(1, $this->caught(notifier::COMPETENCY_LEFT_RECORD), 'One departure was announced twice.');
    }

    /**
     * A departure task queued before lot 8 quater carries no mark id, and is still delivered.
     */
    public function test_a_departure_queued_without_a_mark_id_is_still_delivered(): void {
        global $DB;

        $this->record(['BC01' => 'Accompagner la personne', 'BC02' => 'Repérer les évolutions']);
        $bc01 = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-BC01'], MUST_EXIST);
        $this->declare_as($this->candidate, $bc01);
        $this->record(['BC02' => 'Repérer les évolutions']);

        $tasks = $this->queued(notifier::COMPETENCY_LEFT_RECORD);
        $this->assertCount(1, $tasks);
        $data = $tasks[0]->get_custom_data();
        $this->assertNotEmpty($data->markid, 'The task does not carry the mark of its departure.');
        unset($data->markid);

        $this->assertTrue(notifier::deliver($data));
        $this->assertCount(1, $this->caught(notifier::COMPETENCY_LEFT_RECORD));
    }

    /**
     * The candidate may write and deposit; the manager who follows them may also read deposits.
     *
     * @return \stdClass A second manager, who follows the candidate but holds no right to read deposits.
     */
    private function deposit_rights(): \stdClass {
        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $candidatecontext = \context_user::instance($this->candidate->id)->id;

        $writer = $generator->create_role();
        assign_capability('tool/vae:draftown', CAP_ALLOW, $writer, $system, true);
        role_assign($writer, $this->candidate->id, $candidatecontext);

        $reader = $generator->create_role();
        assign_capability('tool/vae:readdeposit', CAP_ALLOW, $reader, $system, true);
        set_role_contextlevels($reader, [CONTEXT_USER]);
        role_assign($reader, $this->manager->id, $candidatecontext);

        $other = $generator->create_user(['firstname' => 'Paul', 'lastname' => 'Roux']);
        role_assign($this->managerrole, $other->id, $candidatecontext);

        accesslib_clear_all_caches_for_unit_testing();

        return $other;
    }

    /**
     * Write one section and deposit, as the candidate.
     *
     * @return \stdClass The deposit.
     */
    private function deposit_as_candidate(): \stdClass {
        $this->setUser($this->candidate);
        $drafts = new \tool_vae\local\draft_manager();
        $draft = $drafts->section((int) $this->candidate->id, \tool_vae\local\draft_manager::DOCTYPE_FAISABILITE, 'objectifs');
        $drafts->save_version((int) $draft->id, self::SITUATION);

        return (new \tool_vae\local\deposit_manager())->deposit((int) $this->candidate->id);
    }

    /**
     * A deposit is announced to the manager who follows the candidate AND may read deposits, with
     * the candidate's name and the number, and nothing of the sections.
     */
    public function test_a_deposit_is_announced_without_its_content_to_who_may_read_it(): void {
        $other = $this->deposit_rights();

        $this->deposit_as_candidate();
        $this->assertSame([], $this->caught(notifier::DEPOSIT_MADE), 'Sent from the request, not from cron.');
        $this->assertCount(1, $this->queued(notifier::DEPOSIT_MADE));

        $this->deliver_all();

        $messages = $this->caught(notifier::DEPOSIT_MADE);
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertEquals($this->manager->id, $message->useridto);
        $this->assertNotEquals($other->id, $message->useridto);
        $this->assertStringContainsString(fullname($this->candidate), $message->fullmessage);
        $this->assertStringContainsString('1', $message->fullmessage);
        foreach (self::text_of($message) as $field => $text) {
            $this->assertStringNotContainsString('Lambert', $text, $field);
            $this->assertStringNotContainsString('chambre', $text, $field);
        }
    }

    /**
     * Rights are asked again at delivery: a manager who lost the right to read deposits is not told.
     */
    public function test_a_manager_who_lost_the_right_to_read_deposits_is_not_told(): void {
        global $DB;

        $this->deposit_rights();
        $this->deposit_as_candidate();
        $this->assertCount(1, $this->queued(notifier::DEPOSIT_MADE));

        $DB->delete_records('role_capabilities', ['capability' => 'tool/vae:readdeposit']);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectOutputRegex(self::NOT_SENT);
        $this->deliver_all();

        $this->assertSame([], $this->caught(notifier::DEPOSIT_MADE));
    }

    /**
     * An opinion is announced to the candidate: the number of the deposit, not the text, not the author.
     */
    public function test_an_opinion_is_announced_to_the_candidate_without_text_or_author(): void {
        $this->deposit_rights();
        $system = \context_system::instance()->id;
        $write = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:writeopinion', CAP_ALLOW, $write, $system, true);
        set_role_contextlevels($write, [CONTEXT_USER]);
        role_assign($write, $this->manager->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->deposit_as_candidate();
        $this->setUser($this->manager);
        $deposit = (new \tool_vae\local\deposit_manager())->get((int) $this->candidate->id, 1);
        (new \tool_vae\local\opinion_manager())->write((int) $deposit->id, 'Avis-Secret sur Mme Lambert.');

        $this->deliver_all();

        $messages = $this->caught(notifier::OPINION_MADE);
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertEquals($this->candidate->id, $message->useridto);
        foreach (self::text_of($message) as $field => $text) {
            $this->assertStringNotContainsString('Avis-Secret', $text, $field);
            $this->assertStringNotContainsString('Lambert', $text, $field);
            $this->assertStringNotContainsString(fullname($this->manager), $text, $field);
        }
    }
}
