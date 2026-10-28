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

use tool_vae\local\ref_manager;

/**
 * Giving an address for an imported certification record, where the certifier publishes its rule of validation (T-41).
 *
 * The choices are checked again on the server (ref_manager::add()), which is where they count.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ref_form extends \moodleform {
    /**
     * Build the form. Custom data: `options` (record code => label to show).
     */
    protected function definition() {
        $mform = $this->_form;
        $options = (array) ($this->_customdata['options'] ?? []);

        $mform->addElement('select', 'fichecode', get_string('reffiche', 'tool_vae'), $options);
        $mform->addRule('fichecode', null, 'required', null, 'client');
        $mform->setType('fichecode', PARAM_ALPHANUMEXT);

        $mform->addElement('text', 'title', get_string('reftitle', 'tool_vae'), [
            'maxlength' => ref_manager::TITLE_MAXLENGTH,
            'size' => 40,
        ]);
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required', null, 'client');
        $mform->addHelpButton('title', 'reftitle', 'tool_vae');

        $mform->addElement('text', 'url', get_string('refurl', 'tool_vae'), ['maxlength' => 255, 'size' => 60]);
        $mform->setType('url', PARAM_RAW_TRIMMED);
        $mform->addRule('url', null, 'required', null, 'client');

        $mform->addElement('date_selector', 'checkedat', get_string('refchecked', 'tool_vae'), ['optional' => true]);

        $this->add_action_buttons(false, get_string('refsubmit', 'tool_vae'));
    }
}
