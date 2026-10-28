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

use tool_vae\event\evidence_purged;
use tool_vae\local\ai_assistant;
use tool_vae\local\backup;
use tool_vae\local\document_copies;
use tool_vae\local\piece_manager;
use tool_vae\local\purge;

/**
 * Catches up with the purges that did not finish (T-78).
 *
 * A deletion decided elsewhere is followed by an observer, and an observer cannot make its failure
 * loud (see \tool_vae\observer). Without this task nothing would ever notice a narrative left
 * behind. It removes the claims whose core record is gone, then the backups of candidates this
 * platform no longer knows, and leaves one event per claim removed - the same trace the observer
 * leaves - and a line of output for the cron log.
 *
 * Idempotent: a second run finds nothing the first did not take.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reconcile_orphans extends \core\task\scheduled_task {
    /**
     * Name shown in the task list.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskreconcileorphans', 'tool_vae');
    }

    /**
     * Remove the orphaned claims and backups.
     */
    public function execute() {
        $removed = purge::orphans();
        foreach ($removed as $record) {
            $context = \context_user::instance((int) $record->candidateid, IGNORE_MISSING)
                ?: \context_system::instance();
            evidence_purged::create([
                'context' => $context,
                'objectid' => (int) $record->id,
                'relateduserid' => (int) $record->candidateid,
                'other' => [
                    'userevidenceid' => (int) $record->userevidenceid,
                    'attestations' => (int) ($record->attestations ?? 0),
                ],
            ])->trigger();
        }

        // The sections of a document whose candidate is gone (T-81): no event, they hold no claim,
        // and the count in the cron log is what says it happened.
        $sections = purge::orphan_sections();

        // The supporting pieces (T-13) whose declaration or file is gone.
        $pieces = piece_manager::sweep_orphans();

        $backups = backup::discard_orphans() + document_copies::discard_orphans();

        // The record of the calls to the reformulation aid (T-70) is kept for two days, no more.
        ai_assistant::purge_old();

        mtrace('tool_vae: ' . count($removed) . ' orphaned declaration(s), '
            . $sections . ' orphaned section(s), ' . $pieces . ' orphaned piece(s) and '
            . $backups . ' orphaned backup(s) removed.');
    }
}
