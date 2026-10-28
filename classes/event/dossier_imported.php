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
 * A dossier was rebuilt from a normative archive (lot 8, second half).
 *
 * Like the export, the event carries counts and the archive's ULID, never content. `foreign` is 1
 * when this platform's register does not know the archive (lot 8 quater): it was accepted without
 * its authenticity being verifiable here, and the log keeps that.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dossier_imported extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventdossierimported', 'tool_vae');
    }

    /**
     * Description for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' rebuilt the dossier of the user with id "
            . "'{$this->relateduserid}' from the archive '{$this->other['archive']}' "
            . "({$this->other['declarations']} declaration(s), {$this->other['attestations']} attestation(s)"
            . ", " . (int) ($this->other['rubriques'] ?? 0) . " section(s) of the feasibility file)"
            . ($this->other['foreign'] ? ', an archive this platform did not issue and could not verify.' : ', verified.');
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the candidate.');
        }
        foreach (['declarations', 'attestations', 'foreign'] as $count) {
            if (!isset($this->other[$count]) || !is_int($this->other[$count])) {
                throw new \coding_exception("The '$count' value must be set in other, as an integer.");
            }
        }
        if (!isset($this->other['archive']) || !is_string($this->other['archive'])) {
            throw new \coding_exception("The 'archive' value must be set in other: it is the archive's ULID.");
        }
    }
}
