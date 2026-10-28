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

namespace tool_vae\form;

defined('MOODLE_INTERNAL') || die();

// Not autoloaded - see withdraw_form.php for the fatal error this line prevents.
require_once($CFG->libdir . '/formslib.php');

/**
 * Declaring that an imported certification record replaces another (T-30, lot 1).
 *
 * Two lists of the imported records. The choice is checked again on the server, which is where it counts.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class supersession_form extends \moodleform {
    /**
     * Build the form. Custom data: `options` (record code => label to show).
     */
    protected function definition() {
        $mform = $this->_form;
        $options = (array) ($this->_customdata['options'] ?? []);

        $mform->addElement('select', 'fichecode', get_string('supersessionfrom', 'tool_vae'), $options);
        $mform->addElement('select', 'successorcode', get_string('supersessionto', 'tool_vae'), $options);
        $mform->addRule('fichecode', null, 'required', null, 'client');
        $mform->addRule('successorcode', null, 'required', null, 'client');
        $mform->setType('fichecode', PARAM_ALPHANUMEXT);
        $mform->setType('successorcode', PARAM_ALPHANUMEXT);

        $this->add_action_buttons(false, get_string('supersessionsubmit', 'tool_vae'));
    }
}
