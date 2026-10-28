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

use tool_vae\local\exporter;

/**
 * Deletes exported archives once their day is over (lot 8, MC-9).
 *
 * The download is refused from the first second past the lifetime, by exporter::may_download();
 * this task only reclaims the space. It runs hourly, so an archive stays on disk at most an hour
 * longer than it can be fetched.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_exports extends \core\task\scheduled_task {
    /**
     * Name shown in the task list.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskpurgeexports', 'tool_vae');
    }

    /**
     * Delete the archives past their lifetime.
     */
    public function execute() {
        $count = exporter::purge_expired(time());
        mtrace("tool_vae: {$count} expired export(s) deleted.");
    }
}
