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
 * A manager asked the signatory of an attestation to withdraw it. Nothing is withdrawn.
 *
 * WHO IS WHERE, AND WHY. The actor (userid) is the manager who asked; the user the event is
 * "related" to (relateduserid) is the signatory, who is asked - NOT the candidate. The candidate
 * is in `other`. A candidate's own request for their data reaches the log rows that name them as
 * actor or as related user: with the candidate in relateduserid, the log of their access request
 * would have told them which manager asked, when the interface and the exports do not. The
 * requester's own erasure reaches the rows they authored.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class withdrawal_requested extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        // An update of the row: the request is written on it, or cleared from it.
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
        return get_string('eventwithdrawalrequested', 'tool_vae');
    }

    /**
     * Description for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' asked the user with id '{$this->relateduserid}', who signed the "
            . "attestation with id '{$this->objectid}' on the declaration with id '{$this->other['evidenceid']}' "
            . "in the dossier of the user with id '{$this->other['candidateid']}', to withdraw it.";
    }

    /**
     * URL of the dossier the attestation belongs to.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/admin/tool/vae/dossier.php', ['userid' => $this->other['candidateid']]);
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the signatory.');
        }
        foreach (['evidenceid', 'candidateid'] as $key) {
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
