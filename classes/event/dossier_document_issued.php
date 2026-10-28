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
 * An editable copy of a candidate's dossier was issued, as a .docx or an .odt (T-83).
 *
 * Either a person asked for it (the button on the dossier page) or the weekly task wrote it into
 * the candidate's private files. The log holds a count and a digest, never content: the copy
 * itself leaves the platform, or sits in the candidate's own files, and the log is no place for a
 * narrative. The digest is that of the data the copy was written from, so that two copies of an
 * unchanged dossier show the same one.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dossier_document_issued extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventdossierdocumentissued', 'tool_vae');
    }

    /**
     * Description for the log report. Identifiers and counts only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        $how = !empty($this->other['planned']) ? 'by the scheduled task' : "at the request of the user with id '{$this->userid}'";

        return "An editable copy ({$this->other['format']}) of the dossier of the user with id "
            . "'{$this->relateduserid}' ({$this->other['declarations']} declaration(s)) was issued $how, "
            . "data SHA-256 '{$this->other['sha256']}'.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the candidate.');
        }
        if (!isset($this->other['declarations']) || !is_int($this->other['declarations'])) {
            throw new \coding_exception("The 'declarations' value must be set in other, as an integer.");
        }
        if (!isset($this->other['planned']) || !is_int($this->other['planned'])) {
            throw new \coding_exception("The 'planned' value must be set in other, as 0 or 1.");
        }
        if (!isset($this->other['format']) || !in_array($this->other['format'], ['docx', 'odt'], true)) {
            throw new \coding_exception("The 'format' value must be set in other: docx or odt.");
        }
        if (!isset($this->other['sha256']) || !preg_match('/^[0-9a-f]{64}$/D', (string) $this->other['sha256'])) {
            throw new \coding_exception("The 'sha256' value must be set in other: the digest of the data.");
        }
    }

    /**
     * Map the object id for restore from a backup. There is no object.
     *
     * @return bool
     */
    public static function get_objectid_mapping() {
        return false;
    }
}
