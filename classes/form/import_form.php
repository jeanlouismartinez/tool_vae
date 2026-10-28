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


// The parent class is not autoloaded - see declare_form.php for what that cost once.
require_once($CFG->libdir . '/formslib.php');

/**
 * Deposit a certification record, and say where it comes from.
 *
 * THE PROVENANCE FIELDS ARE NOT ADMINISTRATIVE DECORATION. The Licence Ouverte 2.0 requires the
 * source, the attribution and the publication date to be stated wherever the data is presented,
 * and forbids misleading anyone about them. They are therefore required, and they are displayed
 * on every screen that shows the record.
 *
 * The publication date is required for a second reason, this one about proof: a claim has to be
 * able to say WHICH VERSION of a record it was made against, years later, in front of a panel.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {
    /**
     * @var array The encodings offered, as key => language string identifier.
     *
     * A CONSTANT SO THAT A TEST CAN CHECK THE KEYS. They have to be spelt exactly as
     * core_text knows them: posting `Windows-1252` where the core says `WINDOWS-1252` is
     * rejected in silence, the form falls back to UTF-8, and the import strips the accents
     * from every label - a green import and a mutilated record. Measured on 30 August 2026 by
     * submitting the real form over HTTP; no unit test could see it, because they all call
     * the reader directly.
     */
    public const ENCODINGS = [
        'UTF-8' => 'encodingutf8',
        'WINDOWS-1252' => 'encodingwindows1252',
        'ISO-8859-15' => 'encodingiso885915',
    ];

    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('filepicker', 'csvfile', get_string('importfile', 'tool_vae'), null, [
            'accepted_types' => ['.csv', '.txt'],
        ]);
        $mform->addHelpButton('csvfile', 'importfile', 'tool_vae');
        $mform->addRule('csvfile', null, 'required', null, 'client');

        // ASKED, NOT ASSUMED. A file declared UTF-8 while it is not loses every accented
        // character on the way in - silently, on a green import - and the label the licence
        // forbids us to alter reaches the dossier mutilated. A French spreadsheet still writes
        // Windows-1252 by default.
        //
        // THREE CHOICES, NOT SEVENTY-EIGHT. core_text::get_encodings(), which the core's own
        // tool_lpimportcsv hands straight to a select, is mbstring's raw alias list: it offers
        // BASE64, UUENCODE, HTML-ENTITIES and QUOTED-PRINTABLE to somebody who is trying to
        // import a spreadsheet. A menu that long is not a choice, it is a way of losing.
        // These are the encodings a certification record actually arrives in.
        $encodings = [];
        foreach (self::ENCODINGS as $key => $identifier) {
            $encodings[$key] = get_string($identifier, 'tool_vae');
        }

        $mform->addElement('select', 'encoding', get_string('importencoding', 'tool_vae'), $encodings);
        $mform->setType('encoding', PARAM_ALPHANUMEXT);
        $mform->setDefault('encoding', 'UTF-8');
        $mform->addHelpButton('encoding', 'importencoding', 'tool_vae');

        $mform->addElement('text', 'fichecode', get_string('importfichecode', 'tool_vae'), [
            'maxlength' => \tool_vae\local\evidence_manager::CODE_MAXLENGTH,
            'size' => 20,
        ]);
        $mform->setType('fichecode', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('fichecode', 'importfichecode', 'tool_vae');
        $mform->addRule('fichecode', null, 'required', null, 'client');

        $mform->addElement('select', 'fichetype', get_string('importfichetype', 'tool_vae'), [
            'rncp' => get_string('typerncp', 'tool_vae'),
            'rs' => get_string('typers', 'tool_vae'),
        ]);

        $mform->addElement('text', 'name', get_string('importname', 'tool_vae'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('header', 'provenance', get_string('importprovenance', 'tool_vae'));
        $mform->setExpanded('provenance');
        $mform->addElement('static', 'provenancehelp', '', get_string('importprovenance_help', 'tool_vae'));

        // Date the source sheet was last updated: certification sheets go back a few decades,
        // none is dated in the future.
        $mform->addElement(
            'date_selector',
            'sourceupdated',
            get_string('importsourceupdated', 'tool_vae'),
            ['startyear' => 1980, 'stopyear' => (int) userdate(time(), '%Y')]
        );

        $mform->addElement('text', 'sourceurl', get_string('importsourceurl', 'tool_vae'), ['size' => 60]);
        $mform->setType('sourceurl', PARAM_URL);

        $mform->addElement('text', 'licence', get_string('importlicence', 'tool_vae'), ['size' => 40]);
        $mform->setType('licence', PARAM_TEXT);
        $mform->setDefault('licence', \tool_vae\local\fiche_import::LICENCE);
        $mform->addRule('licence', null, 'required', null, 'client');

        $mform->addElement('text', 'attribution', get_string('importattribution', 'tool_vae'), ['size' => 40]);
        $mform->setType('attribution', PARAM_TEXT);
        $mform->setDefault('attribution', \tool_vae\local\fiche_import::ATTRIBUTION);
        $mform->addRule('attribution', null, 'required', null, 'client');

        $this->add_action_buttons(true, get_string('importsubmit', 'tool_vae'));
    }

    /**
     * Server side checks. The client side ones are a convenience, never a guarantee.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        foreach (['fichecode', 'name', 'licence', 'attribution'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $errors[$field] = get_string('errorimportmetamissing', 'tool_vae', $field);
            }
        }

        if (!in_array($data['fichetype'] ?? '', ['rncp', 'rs'], true)) {
            $errors['fichetype'] = get_string('errorimportfichetype', 'tool_vae');
        }

        if (\core_text::strlen($data['fichecode'] ?? '') > \tool_vae\local\evidence_manager::CODE_MAXLENGTH) {
            $errors['fichecode'] = get_string('errorfichecodetoolong', 'tool_vae', (object) [
                'code' => $data['fichecode'],
                'max' => \tool_vae\local\evidence_manager::CODE_MAXLENGTH,
            ]);
        }

        return $errors;
    }
}
