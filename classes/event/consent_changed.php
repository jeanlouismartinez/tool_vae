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
 * The vaeiste gave or took back their consent to the annotation of a manager who follows them (lot 1b).
 *
 * THE STATE ONLY IN $data['other'], never a name nor a text - anti-pattern 16: the log is read outside this plugin's
 * capabilities and outlives its retention rule. The accounts are where the log's privacy provider looks for them (T15, point
 * D): the vaeiste acts (userid), in their own context, and the manager is the person the event is about (relateduserid) -
 * so that the erasure of either one reaches this line.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_changed extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'tool_vae_consent';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventconsentchanged', 'tool_vae');
    }

    /**
     * Description for the log report. Identifiers only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' set their consent to the annotation of the user with id "
            . "'{$this->relateduserid}' to '{$this->other['state']}'.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the manager the consent is about.');
        }
        if (!isset($this->other['state']) || !in_array($this->other['state'], ['given', 'withdrawn'], true)) {
            throw new \coding_exception("The 'state' value must be set in other: given or withdrawn.");
        }
        if (count($this->other) !== 1) {
            throw new \coding_exception('Nothing but state goes in other: no account id, no text.');
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
     * Map the other fields for restore from a backup: nothing in other names a record (the manager is relateduserid).
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }
}
