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
 * An administrator removed the row of an imported certification record whose competency framework Moodle no longer holds.
 *
 * The code of a public record and counts only in $data['other']: nothing here concerns a person, and no candidate had left a
 * trace on the record, which is what allowed the removal.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_removed extends \core\event\base {
    /** @var string[] The counts the event carries in $data['other'], besides the code of the record and of its replacement. */
    private const COUNTS = ['refs', 'referents', 'marks', 'passages', 'links'];

    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'tool_vae_source';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventrecordremoved', 'tool_vae');
    }

    /**
     * Description for the log report. A code of record and counts only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' removed the row of the record '{$this->other['fichecode']}' from the list "
            . "of imported records: {$this->other['refs']} address(es), {$this->other['referents']} referent(s), "
            . "{$this->other['marks']} mark(s) of departure and {$this->other['passages']} correspondence(s) went with it, "
            . "and {$this->other['links']} record(s) lost it as their replacement.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        foreach (['fichecode', 'successor'] as $key) {
            if (!isset($this->other[$key]) || !is_string($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other, as a string.");
            }
        }
        foreach (self::COUNTS as $key) {
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
