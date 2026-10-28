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
 * Depositing the dossier de faisabilité, with an optional sentence (T-21).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deposit_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'note', get_string('depositnote', 'tool_vae'), [
            'size' => 60,
            'maxlength' => \tool_vae\local\deposit_manager::NOTE_MAXLENGTH,
        ]);
        $mform->setType('note', PARAM_TEXT);

        // The commitment belongs to the dossier de faisabilite alone (T-11, lot 3).
        if (empty($this->_customdata['validation'])) {
            $mform->addElement('advcheckbox', 'engagement', '', get_string('depositengagement', 'tool_vae'));
            $mform->setType('engagement', PARAM_BOOL);
        }

        $this->add_action_buttons(false, get_string('depositsubmit', 'tool_vae'));
    }
}
