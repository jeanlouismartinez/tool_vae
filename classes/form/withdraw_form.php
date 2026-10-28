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
 * Withdrawing an attestation.
 *
 * A separate, deliberate screen rather than a link with a confirmation dialogue. Withdrawal
 * is irreversible in a precise sense: it erases the signatory's identity, their job title,
 * the wording they wrote and the date they wrote it. Nothing brings those back. A screen
 * that says so, and asks for a reason from a closed list, is the smallest honest interface
 * for that act.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class withdraw_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'attestationid');
        $mform->setType('attestationid', PARAM_INT);

        $mform->addElement('html', \html_writer::div(
            get_string('withdrawwarning', 'tool_vae'),
            'alert alert-warning'
        ));

        // An empty first option, deliberately. Without it the select opens on the first
        // entry of the closed list - 'erasure requested by the signatory' - and a manager
        // withdrawing a signature posted in error records, irreversibly and in someone
        // else's dossier, that the signatory asked to be erased.
        $reasons = ['' => get_string('choosedots')];
        foreach (attestation_manager::WITHDRAWAL_REASONS as $reason) {
            $reasons[$reason] = get_string('withdrawreason:' . $reason, 'tool_vae');
        }
        $mform->addElement('select', 'reason', get_string('withdrawreason', 'tool_vae'), $reasons);
        $mform->addRule('reason', get_string('errorwithdrawreasonunknown', 'tool_vae'), 'required', null, 'client');
        $mform->addHelpButton('reason', 'withdrawreason', 'tool_vae');

        $this->add_action_buttons(true, get_string('withdrawsubmit', 'tool_vae'));
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

        if (!in_array($data['reason'] ?? '', attestation_manager::WITHDRAWAL_REASONS, true)) {
            $errors['reason'] = get_string('errorwithdrawreasonunknown', 'tool_vae');
        }

        return $errors;
    }
}
