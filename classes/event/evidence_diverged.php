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
 * The core record a claim hangs off was changed outside this tool.
 *
 * Not an alarm and not a refusal: the candidate owns that record and may change it. A trace, so
 * that the day a dossier and the platform disagree, there is a date and a name for when it
 * started. The dossier says the same thing to whoever reads it.
 *
 * The kind of divergence is NOT in `other`, and that is the same rule as everywhere else here:
 * integers only. It is recomputed on sight, which is more truthful anyway - a log line says what
 * was true once, the dossier says what is true now.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence_diverged extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        // A reading, not a write: this plugin changed nothing.
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'tool_vae_evidence';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventevidencediverged', 'tool_vae');
    }

    /**
     * Description for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The declaration with id '{$this->objectid}' belonging to the user with id "
            . "'{$this->relateduserid}' no longer matches the core evidence record with id "
            . "'{$this->other['userevidenceid']}', which was changed outside this plugin.";
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
        if (!isset($this->other['userevidenceid'])) {
            throw new \coding_exception("The 'userevidenceid' value must be set in other.");
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
