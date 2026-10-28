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
 * A candidate's dossier was exported as the normative archive (lot 8). MC-9 asks for every
 * export to be logged.
 *
 * The object is the stored file of the archive, which the export area keeps for a day. The event
 * carries a count, never content: the log is no place for a narrative, and the archive itself
 * travels to the panel, not to the log store.
 *
 * It also carries the SHA-256 digest of the archive's manifest, the one the register keeps
 * (lot 8 quater): a digest says nothing about the dossier, and the log then holds a second trace
 * of what was issued, beside the register.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dossier_exported extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'files';
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventdossierexported', 'tool_vae');
    }

    /**
     * Description for the log report.
     *
     * The log report shows this text and nothing of `other`, so the count of the lines the schema refuses (`warnings`, which the
     * exporter records) is said here: it is where an administrator looks for the exports a reimport will refuse. An event logged
     * before that count existed does not carry it, and reads as an archive without a fault; so does an archive with none.
     *
     * @return string
     */
    public function get_description() {
        $faulty = (int) ($this->other['warnings'] ?? 0);

        return "The user with id '{$this->userid}' exported the dossier of the user with id "
            . "'{$this->relateduserid}' ({$this->other['declarations']} declaration(s)) as the archive "
            . "with file id '{$this->objectid}', manifest SHA-256 '{$this->other['sha256']}'."
            . ($faulty > 0
                ? " The archive holds {$faulty} line(s) whose format the schema refuses: it cannot be reimported as it stands."
                : '');
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the candidate.');
        }
        if (!isset($this->objectid)) {
            throw new \coding_exception('The objectid must be set: it is the archive.');
        }
        if (!isset($this->other['declarations']) || !is_int($this->other['declarations'])) {
            throw new \coding_exception("The 'declarations' value must be set in other, as an integer.");
        }
        if (!isset($this->other['sha256']) || !preg_match('/^[0-9a-f]{64}$/D', (string) $this->other['sha256'])) {
            throw new \coding_exception("The 'sha256' value must be set in other: the digest of the archive's manifest.");
        }
    }

    /**
     * The archive is not part of a course backup.
     *
     * @return int
     */
    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
    }
}
