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
 * The archive of the accompanying structure of a vaeiste was produced, in place of the previous one if there was one.
 *
 * The user of the event is the person who produced it (the direction, or a manager the direction authorized); the related user
 * is the vaeiste it is about. The digest of the manifest is the one the register of the archives keeps.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class structure_archive_issued extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'files';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventstructureissued', 'tool_vae');
    }

    /**
     * Description for the log report. Identifiers only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' produced the archive of the structure of the user with id "
            . "'{$this->relateduserid}', with the digest '{$this->other['sha256']}'.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set.');
        }
        if (!isset($this->other['sha256']) || !is_string($this->other['sha256'])) {
            throw new \coding_exception("The 'sha256' value must be set in other, as a string.");
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
