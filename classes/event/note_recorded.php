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
 * A manager annotated a work situation or a node of the self-positioning of the vaeiste, or withdrew their annotation
 * (lot 1b).
 *
 * IDENTIFIERS AND THE STATE ONLY IN $data['other'], never the text - anti-pattern 16: the log is read outside this plugin's
 * capabilities and outlives its retention rule. An annotation is never a validation.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class note_recorded extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'tool_vae_note';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventnoterecorded', 'tool_vae');
    }

    /**
     * Description for the log report. Identifiers only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' wrote a line '{$this->other['state']}' about the user with id "
            . "'{$this->relateduserid}', on the target '{$this->other['target']}' of the record '{$this->other['fichecode']}', "
            . "slot {$this->other['slot']}, rank '{$this->other['ficherang']}'.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the vaeiste.');
        }
        foreach (['target', 'fichecode', 'ficherang', 'state'] as $key) {
            if (!isset($this->other[$key]) || !is_string($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other, as a string.");
            }
        }
        if (!isset($this->other['slot']) || !is_int($this->other['slot'])) {
            throw new \coding_exception("The 'slot' value must be set in other, as an integer.");
        }
        if (count($this->other) !== 5) {
            throw new \coding_exception('Nothing but target, fichecode, slot, ficherang and state goes in other: no text.');
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

    /**
     * Map the other fields for restore from a backup. Nothing to map: no id in other.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
