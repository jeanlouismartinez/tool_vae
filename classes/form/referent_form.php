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
 * Naming a referent for a bloc of an imported record (T-23).
 *
 * The choices are checked again on the server (referent_manager::add()), which is where they count.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class referent_form extends \moodleform {
    /**
     * Build the form. Custom data: `options` (the record code and the rank of a bloc, joined by a pipe, or the code alone for
     * the whole record => label to show).
     */
    protected function definition() {
        $mform = $this->_form;
        $options = (array) ($this->_customdata['options'] ?? []);

        $mform->addElement('select', 'bloc', get_string('referentbloc', 'tool_vae'), $options);
        $mform->addRule('bloc', null, 'required', null, 'client');
        $mform->setType('bloc', PARAM_RAW_TRIMMED);

        // By username: a name that is typed is checked by the server, and nobody is searched for in a list of the whole site.
        $mform->addElement('text', 'username', get_string('referentusername', 'tool_vae'), ['size' => 30]);
        $mform->setType('username', PARAM_USERNAME);
        $mform->addRule('username', null, 'required', null, 'client');

        $this->add_action_buttons(false, get_string('referentadd', 'tool_vae'));
    }
}
