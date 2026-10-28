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
 * A candidate asked the reformulation aid for a proposal (T-70).
 *
 * WITHOUT CONTENT. Who asked, which provider answered, how many characters were sent and whether it worked; never the text
 * sent nor the answer, because this log is read outside the plugin's capabilities. The core keeps the text and the answer in its
 * own tables, where its privacy provider treats them.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_used extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventaiused', 'tool_vae');
    }

    /**
     * Description for the log report. Numbers and a provider name only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        $outcome = !empty($this->other['success']) ? 'and got a proposal' : 'and got no proposal';

        return "The user with id '{$this->userid}' asked the reformulation aid of '{$this->other['provider']}' "
            . "about {$this->other['characters']} characters {$outcome}.";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The relateduserid must be set: it is the candidate.');
        }
        if (!isset($this->other['provider']) || !is_string($this->other['provider'])) {
            throw new \coding_exception("The 'provider' value must be set in other, as a string.");
        }
        if (!isset($this->other['characters']) || !is_int($this->other['characters'])) {
            throw new \coding_exception("The 'characters' value must be set in other, as an integer.");
        }
        if (!isset($this->other['success']) || !is_bool($this->other['success'])) {
            throw new \coding_exception("The 'success' value must be set in other, as a boolean.");
        }
    }

    /**
     * Map the object id for restore from a backup. Not restorable.
     *
     * @return bool
     */
    public static function get_objectid_mapping() {
        return false;
    }
}
