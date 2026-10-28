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
 * The confirmation of an import of records, after the preview (0.47.0).
 *
 * THE BUTTON SAYS HOW MANY RECORDS GO. "Import 4 record(s)", or, when the only records ready are older than the version the
 * site holds, "Import the 2 record(s) older than those Moodle holds": the button itself carries the warning, and there is no
 * box to tick for what is already the only thing to do. The box "also import the older ones" exists only when there is
 * something ready AND something older. When nothing is ready there is no button to import at all, only the way out.
 *
 * The token of the plan is the only thing the form carries; it is checked by its pattern. What is written is what the preview
 * showed - the plan kept on the server - and nothing else.
 *
 * Custom data: `token` (string), `importable` (int, records ready and not older), `older` (int, records ready but older).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fiche_confirm_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;
        $token = (string) $this->_customdata['token'];
        $importable = (int) $this->_customdata['importable'];
        $older = (int) $this->_customdata['older'];

        $mform->addElement('hidden', 'token', $token);
        $mform->setType('token', PARAM_ALPHANUM);

        if ($importable > 0 && $older > 0) {
            $mform->addElement(
                'advcheckbox',
                'allowolder',
                '',
                get_string('fcimportallowolder', 'tool_vae', $older)
            );
            $mform->setType('allowolder', PARAM_BOOL);
        }

        if ($importable > 0 || $older > 0) {
            $label = $importable > 0
                ? get_string('fcimportconfirm', 'tool_vae', $importable)
                : get_string('fcimportconfirmolder', 'tool_vae', $older);
            $this->add_action_buttons(true, $label);
        } else {
            $mform->addElement('cancel');
        }

        $mform->addElement('html', \html_writer::tag('p', \html_writer::link(
            new \moodle_url('/admin/tool/vae/import_fiches.php', ['redo' => $token]),
            get_string('fcimportedit', 'tool_vae')
        )));
    }
}
