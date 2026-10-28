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

namespace tool_vae\event;

/**
 * A declaration was refused for want of permission.
 *
 * Acceptance criterion 2 requires the attempt to be logged. That is why the plugin checks
 * with has_capability() and throws the exception itself, instead of calling
 * require_capability(): the latter throws on the spot, and no line after it ever runs.
 *
 * The first version of the blueprint prescribed require_capability() AND an event
 * "triggered before the exception" - two instructions that cannot both be followed.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class declaration_denied extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        // Nothing was created: 'r' is what core uses for refused attempts.
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventdeclarationdenied', 'tool_vae');
    }

    /**
     * Description for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' attempted to declare an activity for the user "
            . "with id '{$this->relateduserid}' without the required permission. Nothing was written.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the intended candidate.');
        }
        foreach ($this->other ?? [] as $key => $value) {
            if (!is_int($value)) {
                throw new \coding_exception(
                    "Only identifiers belong in other; '$key' is not an integer. See anti-pattern 16."
                );
            }
        }
    }
}
