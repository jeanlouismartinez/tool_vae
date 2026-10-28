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
 * Attaching a link to a declaration as a supporting piece (T-13).
 *
 * The title and the address are the free fields; the reminder sits against both. The server never visits the address.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class piece_link_form extends \moodleform {
    /** The id the fields point at with aria-describedby. */
    private const REMINDER_ID = 'tool_vae_anonymise_piecelink';

    /**
     * Build the form. Custom data: `evidenceid` (int).
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'evidenceid', (int) $this->_customdata['evidenceid']);
        $mform->setType('evidenceid', PARAM_INT);

        $mform->addElement('header', 'linkhdr', get_string('piecelinkheading', 'tool_vae'));
        $mform->addElement('static', 'linkhelp', '', get_string('piecelinkhelp', 'tool_vae'));
        $mform->addElement('html', \html_writer::div(
            get_string('anonymisepiece', 'tool_vae'),
            'alert alert-info tool-vae-anonymise',
            ['id' => self::REMINDER_ID]
        ));

        $mform->addElement(
            'select',
            'linknature',
            get_string('piecenature', 'tool_vae'),
            ['' => get_string('piecenaturechoose', 'tool_vae')] + piece_file_form::natures()
        );
        $mform->setType('linknature', PARAM_ALPHA);
        $mform->addRule('linknature', null, 'required', null, 'client');
        $mform->addElement('text', 'linktitle', get_string('piecestitle', 'tool_vae'), [
            'size' => 60, 'maxlength' => piece_manager::TITLE_MAXLENGTH, 'aria-describedby' => self::REMINDER_ID,
        ]);
        $mform->setType('linktitle', PARAM_TEXT);
        $mform->addElement('text', 'url', get_string('pieceurllabel', 'tool_vae'), [
            'size' => 60, 'maxlength' => piece_manager::URL_MAXLENGTH, 'aria-describedby' => self::REMINDER_ID,
        ]);
        $mform->setType('url', PARAM_RAW_TRIMMED);
        $mform->addRule('url', null, 'required', null, 'client');

        $this->add_action_buttons(false, get_string('pieceaddlinksubmit', 'tool_vae'));
    }

    /**
     * Keep the reminder attached to both fields when the form comes back with an error (T-72).
     */
    public function definition_after_data() {
        parent::definition_after_data();
        reminder_pointers::apply(['id_linktitle' => [self::REMINDER_ID], 'id_url' => [self::REMINDER_ID]]);
    }
}
