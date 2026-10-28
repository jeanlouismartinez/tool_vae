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
 * An administrator decided that a competency of a replaced record has an equivalent in the record that replaces it,
 * or took that decision back (T-30, lot 2).
 *
 * Identifiers of competencies only in $data['other'] (zero for the target when the decision was taken back):
 * nothing here concerns a person but the administrator, who is the event's user.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class passage_changed extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'tool_vae_passage';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventpassagechanged', 'tool_vae');
    }

    /**
     * Description for the log report. Identifiers only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        $to = (int) $this->other['tocompetencyid'] === 0 ? 'nothing' : "the competency with id '{$this->other['tocompetencyid']}'";

        return "The user with id '{$this->userid}' set the competency with id '{$this->other['fromcompetencyid']}' "
            . "to correspond to $to.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        foreach (['fromcompetencyid', 'tocompetencyid'] as $key) {
            if (!isset($this->other[$key]) || !is_int($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other, as an integer.");
            }
        }
    }

    /**
     * Map the object id for restore from a backup. Not restorable.
     *
     * @return bool
     */
    public static function get_objectid_mapping() {
        return false;
    }
}
