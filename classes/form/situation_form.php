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

use tool_vae\local\draft_manager;

/**
 * The text of a work situation (lot 1b): free text with the reminder of the eight prompts, a version at the click.
 *
 * Plain text, as the text of a section is: no editor. The draft in progress is kept by the autosave of the page (autosave.php,
 * by draftid); the button writes a version, through situation_manager, with the snapshot of the details. The anonymisation
 * reminder is above the field, pointed at by aria-describedby, and not dismissible.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class situation_form extends \moodleform {
    /** The id the text field points at. */
    private const REMINDER_ID = 'tool_vae_anonymise_situation';

    /**
     * Build the form. The situation is in the address, not in the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'workingat');
        $mform->setType('workingat', PARAM_INT);

        $mform->addElement('html', \html_writer::div(
            get_string('anonymisereminder', 'tool_vae'),
            'alert alert-info tool-vae-anonymise',
            ['id' => self::REMINDER_ID]
        ));
        // The eight prompts of the Livret 2: a reminder, in the language pack, never separate fields.
        $mform->addElement('html', \html_writer::tag(
            'p',
            s(get_string('situationmemo', 'tool_vae')),
            ['class' => 'tool-vae-section-help', 'id' => 'tool_vae_situationmemo']
        ));
        $mform->addElement('textarea', 'content', get_string('situationtext', 'tool_vae'), [
            'rows' => 18, 'cols' => 80, 'aria-describedby' => self::REMINDER_ID . ' tool_vae_situationmemo',
        ]);
        $mform->setType('content', PARAM_RAW);

        $this->add_action_buttons(false, get_string('situationsaveversion', 'tool_vae'));
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

        if (\core_text::strlen((string) ($data['content'] ?? '')) > draft_manager::CONTENT_MAXLENGTH) {
            $errors['content'] = get_string('errordrafttoolong', 'tool_vae', draft_manager::CONTENT_MAXLENGTH);
        }

        return $errors;
    }
}
