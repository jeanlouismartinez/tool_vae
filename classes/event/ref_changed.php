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
 * An administrator gave, or took back, an address where a certifier publishes its rule of validation (T-41).
 *
 * The code of a public record and the action only in $data['other']: neither the address nor its title, nothing here
 * concerns a person.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ref_changed extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'tool_vae_ref';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventrefchanged', 'tool_vae');
    }

    /**
     * Description for the log report. A code of record and an action only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' {$this->other['action']} an address of reference for the record "
            . "'{$this->other['fichecode']}'.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        foreach (['fichecode', 'action'] as $key) {
            if (!isset($this->other[$key]) || !is_string($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other, as a string.");
            }
        }
        if (!in_array($this->other['action'], ['added', 'removed'], true)) {
            throw new \coding_exception("The 'action' value must be added or removed.");
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
