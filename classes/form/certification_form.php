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

use tool_vae\local\certification_manager;

/**
 * Certifying a bloc (T-90, lot 3): a status, the date of the decision, a written reason and, when a competency of the bloc
 * is not sufficient, why the bloc is certified all the same. The positioning of the structure, never a validation.
 *
 * The field of the alert is always on the page, whatever the competencies say: the server alone decides whether it is
 * required, and the page works without a script.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class certification_form extends \moodleform {
    /**
     * Build the form. The target - candidate, record, rank - is in the address, not in the form.
     */
    protected function definition() {
        $mform = $this->_form;

        $options = [];
        foreach (certification_manager::STATUSES as $status) {
            $options[$status] = get_string('certifstatus' . $status, 'tool_vae');
        }
        $mform->addElement('select', 'status', get_string('positioningstatuslabel', 'tool_vae'), $options);
        $mform->setType('status', PARAM_ALPHA);

        $mform->addElement(
            'text',
            'decisiondate',
            get_string('positioningdatelabel', 'tool_vae'),
            ['size' => 12, 'maxlength' => 10]
        );
        $mform->setType('decisiondate', PARAM_RAW_TRIMMED);
        $mform->setDefault('decisiondate', certification_manager::today());
        $mform->addElement('static', 'datehelp', '', get_string('positioningdatehelp', 'tool_vae'));
        $mform->addRule('decisiondate', null, 'required', null, 'client');

        // Optional: a certification with no date never lapses. The server alone says whether the day is allowed.
        $mform->addElement(
            'text',
            'validuntil',
            get_string('positioningvaliduntillabel', 'tool_vae'),
            ['size' => 12, 'maxlength' => 10]
        );
        $mform->setType('validuntil', PARAM_RAW_TRIMMED);
        $mform->addElement('static', 'validuntilhelp', '', get_string('positioningvaliduntilhelp', 'tool_vae'));

        $mform->addElement('html', \html_writer::div(s(get_string('anonymiseshort', 'tool_vae')), 'small text-muted mb-2'));
        $mform->addElement('textarea', 'reason', get_string('positioningreasonlabel', 'tool_vae'), ['rows' => 6, 'cols' => 70]);
        $mform->setType('reason', PARAM_RAW);
        $mform->addRule('reason', null, 'required', null, 'client');

        $mform->addElement('html', \html_writer::div(s(get_string('anonymiseshort', 'tool_vae')), 'small text-muted mb-2'));
        $mform->addElement(
            'textarea',
            'alertreason',
            get_string('positioningalertreasonlabel', 'tool_vae'),
            ['rows' => 4, 'cols' => 70]
        );
        $mform->setType('alertreason', PARAM_RAW);
        $mform->addElement('static', 'alerthelp', '', get_string('positioningalertreasonhelp', 'tool_vae'));

        $this->add_action_buttons(false, get_string('positioningsubmit', 'tool_vae'));
    }
}
