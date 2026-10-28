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

use tool_vae\local\identity_map;
use tool_vae\local\pending_report;
use tool_vae\task\remind_pending;
use tool_vae\task\send_notification;

/**
 * The declarations awaiting an attestation, their reminder and their report (T-22).
 *
 * WHAT IS ASSERTED. That a declaration waits while no attestation stands, and waits again once it is
 * withdrawn; that the daily task finds each declaration once, as it crosses the threshold, and does
 * nothing while the setting is zero; that only a manager who follows the candidate and may attest is
 * told; that nothing of the narrative is in the message; and that a reminder no longer true is not sent.
 *
 * The declarations are inserted as rows: what is under test is the waiting, not how a declaration is made.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\pending_report::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\task\remind_pending::class)]
final class pending_report_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Follows the candidate and may attest. */
    private $manager;

    /** @var \stdClass Follows the candidate, may not attest. */
    private $reader;

    /** @var int The time every age is counted from. */
    private $now;

    /**
     * A candidate, a manager who attests, a reader who does not.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $this->candidate = $generator->create_user(['firstname' => 'Camille', 'lastname' => 'Durand']);
        $this->manager = $generator->create_user();
        $this->reader = $generator->create_user();
        $context = \context_user::instance($this->candidate->id)->id;

        $read = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $read, $system, true);
        set_role_contextlevels($read, [CONTEXT_USER]);
        role_assign($read, $this->reader->id, $context);

        $attest = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $attest, $system, true);
        assign_capability('tool/vae:attest', CAP_ALLOW, $attest, $system, true);
        set_role_contextlevels($attest, [CONTEXT_USER]);
        role_assign($attest, $this->manager->id, $context);

        $own = $generator->create_role();
        assign_capability('tool/vae:viewown', CAP_ALLOW, $own, $system, true);
        role_assign($own, $this->candidate->id, $context);

        accesslib_clear_all_caches_for_unit_testing();
        $this->now = time();
    }

    /**
     * Insert a declaration of the candidate, this many days old.
     *
     * @param int $daysold
     * @param string $situation The narrative, which no message may carry.
     * @return int The id.
     */
    private function declare_aged(int $daysold, string $situation = 'Une situation.'): int {
        return $this->declare_at($this->now - $daysold * DAYSECS - 60, $situation);
    }

    /**
     * Insert a declaration of the candidate, made at this instant.
     *
     * @param int $at
     * @param string $situation The narrative, which no message may carry.
     * @return int The id.
     */
    private function declare_at(int $at, string $situation = 'Une situation.'): int {
        global $DB;

        return (int) $DB->insert_record('tool_vae_evidence', (object) [
            'uuid' => identity_map::generate_ulid(),
            'userevidenceid' => $DB->count_records('tool_vae_evidence') + 1,
            'candidateid' => (int) $this->candidate->id,
            'periodstart' => $at - 1000,
            'periodend' => $at,
            'settingtype' => 'ehpad',
            'situationformat' => FORMAT_PLAIN,
            'situation' => $situation,
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
            'ficheupdated' => $at,
            'timecreated' => $at,
            'timemodified' => $at,
            'usermodified' => (int) $this->candidate->id,
        ]);
    }

    /**
     * Attest a declaration, optionally withdrawn.
     *
     * @param int $evidenceid
     * @param bool $withdrawn
     */
    private function attest_row(int $evidenceid, bool $withdrawn = false): void {
        global $DB;

        $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => identity_map::generate_ulid(),
            'evidenceid' => $evidenceid,
            'attestantid' => (int) $this->manager->id,
            'attestantrole' => 'Responsable',
            'statement' => 'Vu.',
            'attestedat' => $this->now,
            'withdrawnat' => $withdrawn ? $this->now : null,
            'withdrawnreason' => $withdrawn ? 'erreur' : null,
            'timecreated' => $this->now,
            'timemodified' => $this->now,
            'usermodified' => (int) $this->manager->id,
        ]);
    }

    /**
     * Run the task and return the reminders it queued.
     *
     * @return \core\task\adhoc_task[]
     */
    private function run_task(): array {
        ob_start();
        (new remind_pending())->execute();
        ob_end_clean();

        return $this->queued_reminders();
    }

    /**
     * The reminders waiting in the queue, whichever run queued them.
     *
     * @return \core\task\adhoc_task[]
     */
    private function queued_reminders(): array {
        return array_values(array_filter(
            \core\task\manager::get_adhoc_tasks(send_notification::class),
            fn($task) => $task->get_custom_data()->provider === notifier::DECLARATION_OVERDUE
        ));
    }

    public function test_a_declaration_waits_while_no_attestation_stands_and_again_once_it_is_withdrawn(): void {
        $waiting = $this->declare_aged(5);
        $attested = $this->declare_aged(5);
        $withdrawn = $this->declare_aged(5);
        $this->attest_row($attested);
        $this->attest_row($withdrawn, true);

        $ids = array_map('intval', array_keys(pending_report::awaiting(0, $this->now)));

        sort($ids);
        $this->assertSame([$waiting, $withdrawn], $ids);
    }

    public function test_the_age_filters_and_the_rows_group_by_candidate(): void {
        $this->declare_aged(2);
        $this->declare_aged(10);
        $this->declare_aged(20);

        $this->assertCount(3, pending_report::awaiting(0, $this->now));
        $this->assertCount(2, pending_report::awaiting(5, $this->now));
        $this->assertCount(0, pending_report::awaiting(30, $this->now));

        $groups = pending_report::by_candidate(pending_report::awaiting(0, $this->now));
        $this->assertSame(3, $groups[(int) $this->candidate->id]['count']);
        $this->assertSame($this->now - 20 * DAYSECS - 60, $groups[(int) $this->candidate->id]['oldest']);
    }

    public function test_a_declaration_of_a_deleted_account_is_not_counted(): void {
        $this->declare_aged(10);
        delete_user($this->candidate);

        $this->assertCount(0, pending_report::awaiting(0, $this->now));
    }

    public function test_a_declaration_crosses_the_threshold_once(): void {
        // 14 days and 60 s old: inside the last day of waiting past 13 days, and past 14 only since a minute.
        $this->declare_aged(14);
        $this->declare_aged(3);
        $this->declare_aged(20);

        $this->assertCount(1, pending_report::crossing(14, $this->now));
        $this->assertCount(0, pending_report::crossing(14, $this->now + DAYSECS), 'Found again the day after.');
    }

    public function test_two_runs_less_than_a_day_apart_do_not_share_a_declaration(): void {
        $this->declare_aged(14);

        // The first run, six hours before "now" in the clock of the second.
        $first = $this->now;
        $second = $this->now + 6 * HOURSECS;

        $this->assertCount(1, pending_report::crossing(14, $first, $first - 20 * HOURSECS));
        $this->assertCount(0, pending_report::crossing(14, $second, $first), 'Announced twice, six hours apart.');
    }

    public function test_a_run_that_came_late_loses_no_declaration(): void {
        // 15 days and a minute old: it crossed 14 days a day ago, between a run two days ago and now.
        $this->declare_aged(15);

        $this->assertCount(0, pending_report::crossing(14, $this->now), 'The window of one day does not reach it.');
        $this->assertCount(1, pending_report::crossing(14, $this->now, $this->now - 2 * DAYSECS), 'Lost by a late run.');
    }

    public function test_without_a_last_run_only_the_last_day_is_looked_at(): void {
        $this->declare_aged(14);
        $this->declare_aged(15);

        $this->assertCount(1, pending_report::crossing(14, $this->now, null));
        $this->assertCount(1, pending_report::crossing(14, $this->now, 0), 'Zero is "never ran", not the year 1970.');
    }

    public function test_a_long_stop_is_made_up_for_a_week_at_most(): void {
        $this->declare_aged(20);
        $this->declare_aged(30);

        // Last ran 40 days ago: the declaration that crossed 14 days 6 days ago is announced, the one that crossed
        // 16 days ago is not - the reminders do not all arrive at once.
        $found = pending_report::crossing(14, $this->now, $this->now - 40 * DAYSECS);

        $this->assertCount(1, $found);
    }

    /**
     * A declaration that crosses the threshold while the previous run is still running is found by the next one.
     *
     * The core notes the END of a run as its last run time, and the run counted from its START: the window opened from the end
     * left out what crossed in between, in no window at all. The task keeps the bound it counted from, and the next window opens
     * there. The previous run here began a day ago, and ended eight seconds later; the declaration was made fourteen days before
     * a moment inside those eight seconds.
     */
    public function test_a_declaration_made_while_the_previous_run_was_running_is_not_lost(): void {
        set_config('overduedays', 14, 'tool_vae');
        $before = time();
        $start = $before - DAYSECS;
        set_config('remindlastrun', $start, 'tool_vae');
        $task = new remind_pending();
        $task->set_last_run_time($start + 8);
        $this->declare_at($start - 14 * DAYSECS + 3);

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertCount(1, $this->queued_reminders(), 'Lost between the start and the end of the previous run.');
        $this->assertGreaterThanOrEqual($before, (int) get_config('tool_vae', 'remindlastrun'), 'The task keeps its own bound.');
    }

    /**
     * Two runs in a row do not queue the reminder of one declaration twice.
     *
     * Each run is a new object, as the scheduler makes them: what links the second to the first is what the first kept.
     */
    public function test_two_runs_in_a_row_do_not_share_a_declaration(): void {
        $this->declare_aged(14);
        set_config('overduedays', 14, 'tool_vae');

        $this->assertCount(1, $this->run_task());
        $this->assertCount(1, $this->run_task(), 'The second run queued the same reminder again.');
    }

    /**
     * A window that opens at the instant the previous one closed is empty when it closes at that same instant.
     */
    public function test_a_run_in_the_same_second_as_the_last_finds_nothing(): void {
        $this->declare_aged(14);

        $this->assertCount(1, pending_report::crossing(14, $this->now, null), 'The premise: the declaration is in the last day.');
        $this->assertCount(0, pending_report::crossing(14, $this->now, $this->now), 'The last day again, in the same second.');
    }

    public function test_the_task_does_nothing_while_the_setting_is_zero(): void {
        $this->declare_aged(14);
        set_config('overduedays', 0, 'tool_vae');

        $this->assertCount(0, $this->run_task());
    }

    public function test_only_the_manager_who_follows_and_may_attest_is_reminded(): void {
        $this->declare_aged(14);
        $this->declare_aged(14);
        set_config('overduedays', 14, 'tool_vae');

        $tasks = $this->run_task();

        $this->assertCount(1, $tasks, 'One message per manager and candidate, not one per declaration.');
        $this->assertSame((int) $this->manager->id, (int) $tasks[0]->get_custom_data()->recipientid);
    }

    public function test_the_message_names_the_candidate_and_carries_no_narrative(): void {
        $this->declare_aged(14, 'Texte-Secret du recit.');
        $this->declare_aged(15, 'Autre Texte-Secret.');
        set_config('overduedays', 14, 'tool_vae');
        $sink = $this->redirectMessages();

        $tasks = $this->run_task();
        $this->assertCount(1, $tasks);
        $this->assertTrue(notifier::deliver($tasks[0]->get_custom_data()));

        $messages = $sink->get_messages_by_component_and_type('tool_vae', notifier::DECLARATION_OVERDUE);
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertStringContainsString('Camille Durand', $message->fullmessage);
        $this->assertStringContainsString('14', $message->fullmessage);
        foreach ([$message->subject, $message->fullmessage, $message->fullmessagehtml, $message->smallmessage] as $text) {
            $this->assertStringNotContainsString('Texte-Secret', $text);
        }
    }

    public function test_a_reminder_no_longer_true_is_not_sent(): void {
        $id = $this->declare_aged(14);
        set_config('overduedays', 14, 'tool_vae');
        $tasks = $this->run_task();
        $this->assertCount(1, $tasks);
        $sink = $this->redirectMessages();

        // Attested in the meantime: nothing waits any more.
        $this->attest_row($id);

        $this->assertFalse(notifier::deliver($tasks[0]->get_custom_data()));
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_a_reminder_to_someone_who_no_longer_follows_is_not_sent(): void {
        $this->declare_aged(14);
        set_config('overduedays', 14, 'tool_vae');
        $tasks = $this->run_task();
        $sink = $this->redirectMessages();

        $context = \context_user::instance((int) $this->candidate->id);
        role_unassign_all(['userid' => $this->manager->id, 'contextid' => $context->id]);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(notifier::deliver($tasks[0]->get_custom_data()));
        $this->assertCount(0, $sink->get_messages());
    }
}
