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

use tool_vae\local\note_manager;

/**
 * Annotating a work situation or a node of the self-positioning of a vaeiste (lot 1b): one text, plain or Markdown.
 *
 * The word of a manager the vaeiste consented to, never a validation. The anonymisation reminder stands by the field, always
 * visible: an annotation can describe a person receiving care as easily as the vaeiste's own text. An explicit button records.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class note_form extends \moodleform {
    /** The id the text field points at with aria-describedby. */
    private const REMINDER_ID = 'tool_vae_anonymise_note';

    /**
     * Build the form. The target - vaeiste, record, slot or rank - is in the address, not in the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('html', \html_writer::div(
            s(get_string('anonymiseshort', 'tool_vae')),
            'small text-muted mb-2 tool-vae-anonymise',
            ['id' => self::REMINDER_ID]
        ));
        $mform->addElement(
            'textarea',
            'notetext',
            get_string('notetext', 'tool_vae'),
            ['rows' => 5, 'cols' => 70, 'maxlength' => note_manager::MAX_LENGTH, 'aria-describedby' => self::REMINDER_ID]
        );
        $mform->setType('notetext', PARAM_RAW);

        $mform->addElement('advcheckbox', 'markdown', get_string('noteformatmarkdown', 'tool_vae'));
        $mform->setType('markdown', PARAM_BOOL);

        $this->add_action_buttons(false, get_string('notesubmit', 'tool_vae'));
    }

    /**
     * Keep the reminder attached to the field when the form comes back with an error.
     */
    public function definition_after_data() {
        parent::definition_after_data();
        reminder_pointers::apply(['id_notetext' => [self::REMINDER_ID]]);
    }
}
