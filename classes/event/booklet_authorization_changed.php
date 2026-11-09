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
 * The direction authorized a manager to produce the archive of the structure, or took the authorization back.
 *
 * THE STATUS ONLY IN $data['other'] (granted or revoked), never a name nor a text. The accounts are where the log's privacy
 * provider looks for them (T15, point Y, as consent_changed): the member of the direction who wrote the line acts (userid),
 * and the manager is the person the line is about (relateduserid) - so that the erasure of either one reaches this line. An
 * account id written in other would survive that erasure.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class booklet_authorization_changed extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'tool_vae_booklet_auth';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventbookletauthchanged', 'tool_vae');
    }

    /**
     * Description for the log report. Identifiers only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' recorded '{$this->other['status']}' for the authorization of the user with id "
            . "'{$this->relateduserid}' to produce the archive of the structure.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the manager the authorization is about.');
        }
        if (!isset($this->other['status']) || !in_array($this->other['status'], ['granted', 'revoked'], true)) {
            throw new \coding_exception("The 'status' value must be set in other, granted or revoked.");
        }
        if (count($this->other) !== 1) {
            throw new \coding_exception('Nothing but status goes in other: no account id, no text.');
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
