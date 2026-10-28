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

use tool_vae\local\piece_manager;

defined('MOODLE_INTERNAL') || die();

// Not autoloaded - see withdraw_form.php for the fatal error this line prevents.
require_once($CFG->libdir . '/formslib.php');

/**
 * Attaching a file to a declaration as a supporting piece (T-13).
 *
 * The plain upload field, not the draft area of the core: it works without JavaScript, and the plugin checks the file itself
 * (type read on the content, antivirus, size, digest). The reminder sits against the title, the only free field: the name of
 * the file is never kept.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class piece_file_form extends \moodleform {
    /** The id the title field points at with aria-describedby. */
    private const REMINDER_ID = 'tool_vae_anonymise_piecefile';

    /**
     * Build the form. Custom data: `evidenceid` (int), `maxbytes` (int).
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'evidenceid', (int) $this->_customdata['evidenceid']);
        $mform->setType('evidenceid', PARAM_INT);

        $mform->addElement('header', 'filehdr', get_string('piecefilesheading', 'tool_vae'));
        $mform->addElement('static', 'filehelp', '', get_string(
            'piecefilehelp',
            'tool_vae',
            display_size((int) $this->_customdata['maxbytes'])
        ));
        $mform->addElement('html', \html_writer::div(
            get_string('anonymisepiece', 'tool_vae'),
            'alert alert-info tool-vae-anonymise',
            ['id' => self::REMINDER_ID]
        ));

        $mform->addElement(
            'select',
            'filenature',
            get_string('piecenature', 'tool_vae'),
            ['' => get_string('piecenaturechoose', 'tool_vae')] + self::natures()
        );
        $mform->setType('filenature', PARAM_ALPHA);
        $mform->addRule('filenature', null, 'required', null, 'client');
        $mform->addElement('text', 'filetitle', get_string('piecestitle', 'tool_vae'), [
            'size' => 60, 'maxlength' => piece_manager::TITLE_MAXLENGTH, 'aria-describedby' => self::REMINDER_ID,
        ]);
        $mform->setType('filetitle', PARAM_TEXT);
        // A plain field of the page, not the `file` element of the form library: that element is a fatal error on this
        // version of the core (HTML_QuickForm_file::_findValue() does not match its parent under PHP 8.3). The form is sent
        // as multipart and the page reads the upload itself. The label is a real one, tied to the field.
        $mform->updateAttributes(['enctype' => 'multipart/form-data']);
        $mform->addElement('html', \html_writer::div(
            \html_writer::label(get_string('piecefilelabel', 'tool_vae'), 'id_piecefile')
            . \html_writer::empty_tag('input', [
                'type' => 'file',
                'name' => 'piecefile',
                'id' => 'id_piecefile',
                'accept' => '.pdf,.odt,.docx,.jpg,.jpeg,.png',
                'class' => 'form-control-file d-block',
            ]),
            'form-group'
        ));

        $this->add_action_buttons(false, get_string('pieceaddfilesubmit', 'tool_vae'));
    }

    /**
     * Keep the reminder attached to the title when the form comes back with an error (T-72).
     */
    public function definition_after_data() {
        parent::definition_after_data();
        reminder_pointers::apply(['id_filetitle' => [self::REMINDER_ID]]);
    }

    /**
     * The natures of a piece, from the closed list, labelled.
     *
     * @return string[]
     */
    public static function natures(): array {
        $natures = [];
        foreach (piece_manager::NATURES as $nature) {
            $natures[$nature] = get_string('piecenature:' . $nature, 'tool_vae');
        }

        return $natures;
    }
}
