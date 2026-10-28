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
 * An attempt to attest was refused. Nothing was written.
 *
 * Two refusals reach this event, and both are worth a trace:
 *
 *   - the person does not hold tool/vae:attest on the candidate's context;
 *   - the person is the candidate. Nobody attests their own declaration, whatever
 *     capabilities they hold elsewhere.
 *
 * The event does not say which of the two it was. That distinction is worth less than the
 * rule it would cost: `other` carries identifiers, and a discriminator would be a string.
 * The refusal, its author, its target and its moment are all recorded, which is what an
 * audit needs.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attestation_denied extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        // Nothing was created: 'r' is what core uses for refused attempts.
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventattestationdenied', 'tool_vae');
    }

    /**
     * Description for the log report.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' attempted to attest the declaration with id "
            . "'{$this->other['evidenceid']}' belonging to the user with id '{$this->relateduserid}'. "
            . "The attempt was refused and nothing was written.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the intended candidate.');
        }
        if (!isset($this->other['evidenceid'])) {
            throw new \coding_exception("The 'evidenceid' value must be set in other, 0 when unknown.");
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
