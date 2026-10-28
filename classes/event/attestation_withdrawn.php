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
 * An attestation was withdrawn. The declaration it stood on is untouched.
 *
 * THE REASON FOR THE WITHDRAWAL IS NOT IN `other`, AND THAT IS DELIBERATE. It is a closed
 * enumeration, it carries no name, and it would be convenient to have in the log. But the
 * rule this plugin applies is mechanical - only integers, only identifiers - and a rule
 * with one reasonable exception is a rule with a precedent. The reason lives in the
 * attestation row, where an erasure can still reach it; a copy in logstore_standard_log
 * would outlive every request made about it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attestation_withdrawn extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        // An update, not a deletion: the row stays, stripped of its four identifying fields.
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'tool_vae_attest';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattestationwithdrawn', 'tool_vae');
    }

    /**
     * Description for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' withdrew the attestation with id '{$this->objectid}' "
            . "from the declaration with id '{$this->other['evidenceid']}' belonging to the user with id "
            . "'{$this->relateduserid}'. The declaration itself was not modified.";
    }

    /**
     * URL of the dossier the attestation belonged to.
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
        if (!isset($this->other['evidenceid'])) {
            throw new \coding_exception("The 'evidenceid' value must be set in other.");
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
