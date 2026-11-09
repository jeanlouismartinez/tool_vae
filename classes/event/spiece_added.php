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
 * A vaeiste added a supporting piece to one of their work situations (lot 1b).
 *
 * IDENTIFIERS ONLY IN $data['other'], never the title, the part concerned, a name or an address - anti-pattern 16: the log is
 * read outside this plugin's capabilities and outlives its retention rule.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spiece_added extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'tool_vae_spiece';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventspieceadded', 'tool_vae');
    }

    /**
     * Description for the log report. Identifiers only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->relateduserid}' added the piece with id '{$this->objectid}' to their "
            . "work situation with id '{$this->other['situationid']}'.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the vaeiste.');
        }
        if (!isset($this->other['situationid']) || !is_int($this->other['situationid'])) {
            throw new \coding_exception("The 'situationid' value must be set in other, as an integer.");
        }
        if (!isset($this->other['kind']) || !in_array($this->other['kind'], ['file', 'link'], true)) {
            throw new \coding_exception("The 'kind' value must be set in other, as file or link.");
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
