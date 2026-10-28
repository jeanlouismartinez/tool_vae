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
 * A competency is absent from the latest import of its certification record.
 *
 * Nothing was deleted. The import never deletes a competency - decision 3: a claim made against
 * it is still a claim - it records the departure in tool_vae_obsolete, and this event is
 * that record becoming a fact others can hear. Until lot 7 ter the departure was written down and
 * told to nobody: the administrator saw a counter on the import screen, the candidate who had
 * declared against the competency saw nothing at all.
 *
 * RAISED ONCE, BY THE PASS THAT SEES THE DEPARTURE. An import republished every few months
 * finds the same competency absent every time; announcing it every time would teach a candidate
 * to ignore the one message that matters. The importer raises this event only when the state of
 * the competency changes to "gone".
 *
 * RAISED INSIDE THE IMPORT'S TRANSACTION, HEARD AFTER IT. The observer that turns it into
 * messages is external - see db/events.php - so an import that rolls back takes its departures
 * with it, and nobody is told of a change the site does not hold. And the messages wait a day,
 * sent only if the departure still stands: see \tool_vae\notifier::DEPARTURE_GRACE.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class competency_left_record extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        // A row of tool_vae_obsolete was written, created or moved to this state.
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'tool_vae_obsolete';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventcompetencyleftrecord', 'tool_vae');
    }

    /**
     * Description for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' imported the certification record with id "
            . "'{$this->other['sourceid']}', from which the competency with id "
            . "'{$this->other['competencyid']}' is now absent. The competency was kept, "
            . "and every declaration made against it.";
    }

    /**
     * URL of the list of imported records.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/admin/tool/vae/index.php');
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        foreach (['competencyid', 'sourceid'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other.");
            }
        }

        foreach ($this->other as $key => $value) {
            if (!is_int($value)) {
                throw new \coding_exception(
                    "Only identifiers belong in other; '$key' is not an integer. See anti-pattern 16."
                );
            }
        }
    }

    /**
     * Mapping for backup and restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
    }
}
