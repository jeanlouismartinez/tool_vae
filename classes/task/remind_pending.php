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

namespace tool_vae\task;

use tool_vae\local\pending_report;
use tool_vae\notifier;

/**
 * Reminds the managers of the declarations that have waited for an attestation too long (T-22).
 *
 * OFF UNTIL THE ADMINISTRATOR CHOOSES A NUMBER OF DAYS (setting `overduedays`, zero by default). Once a
 * day it finds the declarations that reached that many days since its last run, and tells each manager
 * who follows the candidate and may attest - once per declaration, with no column to remember it.
 *
 * A reminder is a message, not a state: nothing is written, and the declaration is not changed.
 *
 * THE TASK KEEPS ITS OWN BOUND (`remindlastrun`, the instant it took as "now" at its last run). The core's last run time is
 * written when a run ENDS (\core\task\manager::scheduled_task_complete()), while the window of that run closed at the instant
 * it STARTED: the next window, opened from the end, left out the declarations that crossed the threshold during the seconds
 * the run lasted - in no window at all, so never reminded. A setting, not a column: it holds an instant and no person, and
 * the report still has no memory of what was announced. Absent before the first run after the upgrade, the core's time
 * stands in for it, once.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remind_pending extends \core\task\scheduled_task {
    /**
     * Name shown in the task list.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskremindpending', 'tool_vae');
    }

    /**
     * Queue one reminder per candidate and manager.
     */
    public function execute() {
        $days = (int) get_config('tool_vae', 'overduedays');
        if ($days < 1 || $days > pending_report::MAX_DAYS) {
            mtrace('tool_vae: reminders of pending declarations are off.');
            return;
        }

        $now = time();
        $since = (int) get_config('tool_vae', 'remindlastrun');
        if ($since < 1) {
            $since = (int) $this->get_last_run_time();
        }

        $groups = pending_report::by_candidate(pending_report::crossing($days, $now, $since));
        $queued = 0;
        foreach ($groups as $candidateid => $group) {
            $queued += notifier::declarations_overdue((int) $candidateid, $days);
        }
        // The window of the next run opens where this one closed, to the second.
        set_config('remindlastrun', $now, 'tool_vae');

        mtrace('tool_vae: ' . $queued . ' reminder(s) queued for ' . count($groups)
            . ' candidate(s) with a declaration awaiting for ' . $days . ' days or more.');
    }
}
