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

use tool_vae\local\selfpos_manager;
use tool_vae\local\selfpos_scale;

/**
 * Positioning oneself on a node of a record (lot 1a): a step of the scale and an optional justification.
 *
 * The positioning of the vaeiste, never a validation. An explicit button records; nothing is saved by itself.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class selfpos_form extends \moodleform {
    /**
     * Build the form. The target - vaeiste, record, rank - is in the address, not in the form.
     *
     * Custom data: `canwithdraw` (bool), whether a level stands and may be withdrawn.
     */
    protected function definition() {
        $mform = $this->_form;

        $options = selfpos_scale::labels();
        if (!empty($this->_customdata['canwithdraw'])) {
            $options[selfpos_manager::WITHDRAWN] = get_string('selfposwithdrawoption', 'tool_vae');
        }
        $mform->addElement('select', 'level', get_string('selfposlevellabel', 'tool_vae'), $options);
        $mform->setType('level', PARAM_INT);

        $mform->addElement('html', \html_writer::div(s(get_string('anonymiseshort', 'tool_vae')), 'small text-muted mb-2'));
        $mform->addElement(
            'textarea',
            'justification',
            get_string('selfposjustificationlabel', 'tool_vae'),
            ['rows' => 8, 'cols' => 70, 'maxlength' => selfpos_manager::MAX_LENGTH]
        );
        $mform->setType('justification', PARAM_RAW);

        $mform->addElement('advcheckbox', 'markdown', get_string('selfposformatmarkdown', 'tool_vae'));
        $mform->setType('markdown', PARAM_BOOL);

        $this->add_action_buttons(false, get_string('selfpossubmit', 'tool_vae'));
    }
}
