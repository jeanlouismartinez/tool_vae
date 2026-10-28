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

use tool_vae\local\draft_manager;

// The parent class is not autoloaded - see declare_form for the story. The require goes here.
require_once($CFG->libdir . '/formslib.php');

/**
 * The form of one section of a document (T-81, lot 3).
 *
 * The text is plain text, as a declaration's narrative is: no editor, no format to choose. The
 * anonymisation reminder is the one of the declaration, shown above the field and pointed at by
 * aria-describedby, and it is not dismissible - this is free text where a name gets written.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class section_form extends \moodleform {
    /** @var string The id the text field points at. */
    private const REMINDER_ID = 'tool_vae_anonymise_section';

    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'draftid');
        $mform->setType('draftid', PARAM_INT);
        $mform->addElement('hidden', 'workingat');
        $mform->setType('workingat', PARAM_INT);

        $mform->addElement('html', \html_writer::div(
            get_string('anonymisereminder', 'tool_vae'),
            'alert alert-info tool-vae-anonymise',
            ['id' => self::REMINDER_ID]
        ));

        $mform->addElement(
            'textarea',
            'text',
            get_string('sectiontext', 'tool_vae'),
            ['rows' => 18, 'cols' => 80, 'aria-describedby' => self::REMINDER_ID]
        );
        $mform->setType('text', PARAM_RAW);

        $mform->addElement(
            'text',
            'note',
            get_string('draftnote', 'tool_vae'),
            ['maxlength' => draft_manager::NOTE_MAXLENGTH, 'size' => 60]
        );
        $mform->setType('note', PARAM_TEXT);

        $this->add_action_buttons(false, get_string('draftsubmit', 'tool_vae'));
        // The aid (T-70): after the buttons of the form, and only when the page gives its address, that is when it is offered.
        ai_button::add($mform, $this->_customdata['aiurl'] ?? null);
    }

    /**
     * Server side checks.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (\core_text::strlen((string) ($data['text'] ?? '')) > draft_manager::CONTENT_MAXLENGTH) {
            $errors['text'] = get_string('errordrafttoolong', 'tool_vae', draft_manager::CONTENT_MAXLENGTH);
        }

        return $errors;
    }
}
