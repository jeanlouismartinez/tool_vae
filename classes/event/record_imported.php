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

use tool_vae\local\fiche_import;

/**
 * An administrator imported a certification record from an export of France compétences, uploaded or fetched (0.47.0).
 *
 * One event for each record written. The row of provenance is overwritten by every pass and keeps no history; this is the
 * trace of who imported what, from which archive (its digest), and how much it changed. A code of a public record, dates,
 * counts and a digest: nothing here concerns a person, and no label, no file name and no path is kept.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_imported extends \core\event\base {
    /** @var string[] The counts the event carries in $data['other']. */
    private const COUNTS = ['bytes', 'created', 'updated', 'moved', 'gone', 'diverged'];

    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'tool_vae_source';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventrecordimported', 'tool_vae');
    }

    /**
     * Description for the log report. A code, a date and counts only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' imported the record '{$this->other['fichecode']}' from an export of France "
            . "competences ({$this->other['origin']}, export of {$this->other['exportdate']}): {$this->other['created']} "
            . "competency(ies) added, {$this->other['updated']} updated, {$this->other['moved']} moved, "
            . "{$this->other['gone']} no longer in the record, {$this->other['diverged']} changed outside the tool.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (empty($this->objectid)) {
            throw new \coding_exception('The objectid must be the provenance row of the record.');
        }
        $other = $this->other;
        // What is stored is a code of a public record, a date, an identifier of a resource, a digest and counts: nothing else.
        $forms = [
            'fichecode' => fiche_import::NUMBER_PATTERN,
            'exportdate' => '/^\d{4}-\d{2}-\d{2}$/D',
            'resourceid' => '/^(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})?$/D',
            'sha256' => '/^[0-9a-f]{64}$/D',
        ];
        foreach ($forms as $key => $pattern) {
            if (!isset($other[$key]) || !is_string($other[$key]) || !preg_match($pattern, $other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other, in the form expected.");
            }
        }
        $origins = [fiche_import::ORIGIN_UPLOAD, fiche_import::ORIGIN_FETCH];
        if (!isset($other['origin']) || !in_array($other['origin'], $origins, true)) {
            throw new \coding_exception("The 'origin' value must be set in other, as 'upload' or 'fetch'.");
        }
        foreach (self::COUNTS as $key) {
            if (!isset($other[$key]) || !is_int($other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other, as an integer.");
            }
        }
        if (!isset($other['adopted']) || !in_array($other['adopted'], [0, 1], true)) {
            throw new \coding_exception("The 'adopted' value must be set in other, as 0 or 1.");
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
