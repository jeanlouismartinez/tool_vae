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

/**
 * Withdraw an attestation.
 *
 * Criterion 1 bis ends here: after this page has run, the attestation is gone as a
 * signature and the candidate's claim is exactly as it was. The claim is displayed above
 * the form so the person withdrawing sees what survives.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\form\withdraw_form;
use tool_vae\local\access;
use tool_vae\local\attest_screen;
use tool_vae\local\attestation_manager;
use tool_vae\local\presenter;

require_login();

$attestationid = required_param('attestationid', PARAM_INT);

// An attestation that does not exist is refused to anyone but the administrator exactly as
// one that does, so that its id tells nothing - the reason attest.php gives. The administrator,
// who may withdraw any of them, is told the plain truth.
$attestation = $DB->get_record('tool_vae_attest', ['id' => $attestationid], '*', IGNORE_MISSING);
if (!$attestation) {
    throw new moodle_exception(is_siteadmin() ? 'errorunknownattestation' : 'errorwithdraw', 'tool_vae');
}
$evidence = $DB->get_record('tool_vae_evidence', ['id' => $attestation->evidenceid], '*', MUST_EXIST);
$candidateid = (int) $evidence->candidateid;

$dossier = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);

// An attestation already withdrawn is said so BEFORE the right to withdraw is asked: the withdrawal erased the signatory, who is
// then no longer recognised as one, and a page left open would refuse them with "you may not withdraw" and log a refusal for
// nothing (see attestation_manager::withdraw()). Said to whoever reads the dossier, which draws the withdrawal anyway; anybody
// else meets the one refusal below.
if ($attestation->withdrawnat !== null && $candidateid !== (int) $USER->id && access::can_view($candidateid)) {
    redirect(
        $dossier,
        get_string('errorattestalreadywithdrawn', 'tool_vae'),
        null,
        \core\output\notification::NOTIFY_INFO
    );
}

// Refused before the form, not on submission: nobody is shown a screen for an act that belongs to
// the people the rule names (see attestation_manager::require_can_withdraw()). The refusal is
// logged; attestation_manager::withdraw() refuses it again.
$context = attestation_manager::require_can_withdraw($attestation, $candidateid);
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);

$url = new moodle_url('/admin/tool/vae/withdraw.php', ['attestationid' => $attestationid]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('withdrawheading', 'tool_vae'));
$PAGE->set_heading(fullname($candidate));

$form = new withdraw_form($url->out(false));
$form->set_data(['attestationid' => $attestationid]);

if ($form->is_cancelled()) {
    redirect($dossier);
}

if ($data = $form->get_data()) {
    (new attestation_manager())->withdraw($attestationid, $data->reason);
    redirect(
        $dossier,
        get_string('attestationwithdrawn', 'tool_vae'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('withdrawheading', 'tool_vae'));

echo attest_screen::declaration($evidence, $context);
echo presenter::attestations([$attestation->id => $attestation]);

$form->display();

echo $OUTPUT->footer();
