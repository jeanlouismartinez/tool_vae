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
 * A candidate's whole dossier was destroyed because their account was deleted.
 *
 * THIS EVENT EXISTS BECAUSE THE CORE GIVES NO OTHER WARNING. delete_user() calls the
 * competency subsystem's own user hook, which removes the evidence records with a bulk delete
 * and triggers nothing. Observing the deletion of a single evidence record therefore does not cover the
 * deletion of an account, and a dossier would survive its owner - unreachable, and outside the
 * reach of any later erasure. Measured on a running site, 28 August 2026.
 *
 * The event carries counts, never the identifier of a claim: after the purge those identifiers
 * point at nothing, and a log line that names them only invites a search that will fail.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dossier_purged extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventdossierpurged', 'tool_vae');
    }

    /**
     * Description for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The dossier of the user with id '{$this->relateduserid}' was destroyed after their "
            . "account was deleted: {$this->other['declarations']} declaration(s) and "
            . "{$this->other['attestations']} attestation(s).";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the candidate.');
        }
        foreach (['declarations', 'attestations'] as $key) {
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
}
