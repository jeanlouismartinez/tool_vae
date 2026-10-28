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
 * Asking for a precision on a declaration, or answering the question (T-20).
 *
 * One text field, whose label depends on who writes. The ANSWER carries the anonymisation reminder,
 * always visible: it is the candidate's free text, the field where a name gets written. The
 * question is the manager's, about a text that was itself reminded.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class precision_form extends \moodleform {
    /** The id the answer field points at with aria-describedby. */
    private const REMINDER_ID = 'tool_vae_anonymise_precision';

    /**
     * Build the form. Custom data: `answering` (bool), `requestid` (int, when answering).
     */
    protected function definition() {
        $mform = $this->_form;
        $answering = !empty($this->_customdata['answering']);

        $mform->addElement('hidden', 'requestid', (int) ($this->_customdata['requestid'] ?? 0));
        $mform->setType('requestid', PARAM_INT);

        if ($answering) {
            $mform->addElement('html', \html_writer::div(
                get_string('anonymisereminder', 'tool_vae'),
                'alert alert-info tool-vae-anonymise',
                ['id' => self::REMINDER_ID]
            ));
        }
        $mform->addElement(
            'textarea',
            'text',
            get_string($answering ? 'precisionanswerlabel' : 'precisionquestionlabel', 'tool_vae'),
            ['rows' => 6, 'cols' => 70] + ($answering ? ['aria-describedby' => self::REMINDER_ID] : [])
        );
        $mform->setType('text', PARAM_TEXT);
        $mform->addRule('text', null, 'required', null, 'client');

        $this->add_action_buttons(false, get_string(
            $answering ? 'precisionanswersubmit' : 'precisionquestionsubmit',
            'tool_vae'
        ));
    }

    /**
     * Keep the reminder attached to the answer when it comes back with an error (T-72).
     */
    public function definition_after_data() {
        parent::definition_after_data();
        if (!empty($this->_customdata['answering'])) {
            reminder_pointers::apply(['id_text' => [self::REMINDER_ID]]);
        }
    }
}
