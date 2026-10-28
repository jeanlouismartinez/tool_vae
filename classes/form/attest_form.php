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

use tool_vae\local\attestation_manager;

// THE PARENT CLASS IS NOT AUTOLOADED. `moodleform` lives in lib/formslib.php, which Moodle's
// class loader knows nothing about: a page that instantiates this form without having loaded
// that library dies on `Class "moodleform" not found`. It happened - on declare.php, the
// central screen of this plugin - and the 101 unit tests did not see it, because every one of
// them requires formslib in its own setUp(). A harness that loads what the page does not is a
// harness that hides a fatal error.
//
// The require goes HERE rather than in each page, which is what the core does for its own
// autoloaded form base, \core\form\persistent: whoever loads the class gets its parent, and
// a page added later cannot forget. $CFG is in scope - core_component::classloader() declares
// it global before including, precisely for this.
require_once($CFG->libdir . '/formslib.php');


/**
 * The attestation form.
 *
 * THIS FORM CARRIES THE THIRD PART OF THE ANONYMISATION CONTROL. The other two live in
 * declare_form: no field invites the name of a person receiving care, and a visible
 * non-dismissible reminder sits against each free field. The third is here - the manager
 * re-reads the narrative before signing it - and it is the only one performed by a human
 * who did not write the text.
 *
 * That is why the confirmation box exists and why it is required. It is not a disclaimer
 * collecting consent; it is the moment where a second pair of eyes is asked to have passed
 * over the account before a signature is attached to it. A form cannot verify that reading
 * happened. It can refuse to proceed without the claim that it did, and record who claimed
 * it - which is exactly what the attestation row does.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attest_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'evidenceid');
        $mform->setType('evidenceid', PARAM_INT);
        // The list of supporting pieces the signatory was shown (T-13): what is submitted is what was drawn, and the
        // attestation refuses it if the pieces are no longer those.
        $mform->addElement('hidden', 'piecesseen');
        $mform->setType('piecesseen', PARAM_RAW);

        $mform->addElement(
            'text',
            'attestantrole',
            get_string('attestantrole', 'tool_vae'),
            ['maxlength' => attestation_manager::ROLE_MAXLENGTH, 'size' => 60]
        );
        $mform->setType('attestantrole', PARAM_TEXT);
        $mform->addRule('attestantrole', null, 'required', null, 'client');
        $mform->addHelpButton('attestantrole', 'attestantrole', 'tool_vae');

        $mform->addElement('html', self::reminder());
        $mform->addElement(
            'textarea',
            'statement',
            get_string('statement', 'tool_vae'),
            ['rows' => 8, 'cols' => 70, 'aria-describedby' => self::REMINDER_ID]
        );
        $mform->setType('statement', PARAM_TEXT);
        $mform->addRule('statement', null, 'required', null, 'client');
        $mform->addHelpButton('statement', 'statement', 'tool_vae');

        $mform->addElement(
            'advcheckbox',
            'confirmread',
            get_string('confirmread', 'tool_vae'),
            get_string('confirmread_label', 'tool_vae')
        );
        $mform->addRule('confirmread', get_string('errorconfirmread', 'tool_vae'), 'required', null, 'client');

        // Only when the declaration has pieces: a DECLARATION of having taken note of them, which the form cannot check.
        if (!empty($this->_customdata['haspieces'])) {
            $mform->addElement(
                'advcheckbox',
                'piecesread',
                get_string('piecesread', 'tool_vae'),
                get_string('piecesread_label', 'tool_vae')
            );
            $mform->addRule('piecesread', get_string('errorattestpiecesconfirm', 'tool_vae'), 'required', null, 'client');
        }

        $this->add_action_buttons(true, get_string('attestsubmit', 'tool_vae'));
    }

    /**
     * Keep the reminder attached to the statement when it comes back with an error (T-72).
     */
    public function definition_after_data() {
        parent::definition_after_data();
        reminder_pointers::apply(['id_statement' => [self::REMINDER_ID]]);
    }

    /**
     * Server side checks.
     *
     * Errors are returned keyed by field, never thrown: the submission has to come back
     * with the values still in it and the message attached to the field that caused it.
     * Same reasoning as the declaration form - criterion 3.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $role = trim((string) ($data['attestantrole'] ?? ''));
        if ($role === '') {
            $errors['attestantrole'] = get_string('errorattestantrolemissing', 'tool_vae');
        } else if (\core_text::strlen($role) > attestation_manager::ROLE_MAXLENGTH) {
            $errors['attestantrole'] = get_string('errorattestantroletoolong', 'tool_vae');
        }

        if (trim((string) ($data['statement'] ?? '')) === '') {
            $errors['statement'] = get_string('errorstatementempty', 'tool_vae');
        }

        // An unchecked advcheckbox posts '0', which is present and therefore passes the
        // client side "required" rule. The server side is what actually holds the door.
        if (empty($data['confirmread'])) {
            $errors['confirmread'] = get_string('errorconfirmread', 'tool_vae');
        }

        // The box of the supporting pieces (T-13), when there are some: the same, on the server.
        if (!empty($this->_customdata['haspieces']) && empty($data['piecesread'])) {
            $errors['piecesread'] = get_string('errorattestpiecesconfirm', 'tool_vae');
        }

        return $errors;
    }

    /** The id the statement field points at with aria-describedby. */
    private const REMINDER_ID = 'tool_vae_anonymise_statement';

    /**
     * The reminder markup.
     *
     * Always visible, impossible to fold away. The wording of an attestation is free
     * text like the narrative, and free text is where a name gets written.
     *
     * @return string
     */
    private static function reminder(): string {
        return \html_writer::div(
            get_string('anonymisereminder', 'tool_vae'),
            'alert alert-info tool-vae-anonymise',
            ['id' => self::REMINDER_ID]
        );
    }
}
