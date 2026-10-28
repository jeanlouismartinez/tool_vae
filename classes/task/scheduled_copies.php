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

use tool_vae\local\document_copies;

/**
 * Writes the weekly copy of each dossier into its candidate's private files (T-83).
 *
 * Nothing happens until the administrator turns the copies on. A dossier that did not change
 * since its last copy gets none. See document_copies for the rules.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduled_copies extends \core\task\scheduled_task {
    /**
     * Name shown in the task list.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskscheduledcopies', 'tool_vae');
    }

    /**
     * Write the copies.
     */
    public function execute() {
        if (!document_copies::enabled()) {
            mtrace('tool_vae: the weekly copies are turned off.');
            return;
        }

        $result = document_copies::run(time());
        mtrace('tool_vae: ' . $result['written'] . ' copy(ies) written, ' . $result['unchanged']
            . ' dossier(s) unchanged, ' . $result['failed'] . ' failure(s).');
    }
}
