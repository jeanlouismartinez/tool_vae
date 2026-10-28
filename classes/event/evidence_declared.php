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
 * A candidate declared having carried out an activity.
 *
 * NOTHING BUT IDENTIFIERS GOES IN $data['other'] - anti-pattern 16. That array is serialised
 * into logstore_standard_log, readable under moodle/site:viewreports, outside this plugin's
 * capabilities, outside its retention rule and inside every database backup. Putting the
 * narrative there "to help debugging" would copy the dossier into a place nobody thinks to
 * look at when answering a subject access request.
 *
 * Core keeps the same discipline: competency_evidence_created carries only ids in `other`
 * and passes text through add_record_snapshot(), which is not persisted.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence_declared extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'tool_vae_evidence';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventevidencedeclared', 'tool_vae');
    }

    /**
     * Description for the log report.
     *
     * Identifiers only, for the same reason as `other`: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' declared an activity for the user with id "
            . "'{$this->relateduserid}', recorded as evidence with id '{$this->objectid}', "
            . "against the competency with id '{$this->other['competencyid']}'.";
    }

    /**
     * URL of the dossier the declaration belongs to.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/admin/tool/vae/dossier.php', ['userid' => $this->relateduserid]);
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the candidate.');
        }
        foreach (['competencyid', 'userevidenceid'] as $key) {
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
