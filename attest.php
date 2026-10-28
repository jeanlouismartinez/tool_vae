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
 * Attest a declaration.
 *
 * The claim is displayed in full above the form, and the form will not submit without the
 * signatory stating they have read it. That is the third leg of the anonymisation control:
 * the only reading of the narrative performed by someone who did not write it.
 *
 * The page takes an evidence id from the request and derives the candidate from it, never
 * the reverse. A user id in the query string would be a second thing to check, and the one
 * that gets forgotten.
 *
 * The declaration is drawn with the warning of its anchor when the platform no longer says what
 * the declaration says (attest_screen): the dossier shows it, and so must the screen where a
 * manager decides to sign - which a favourite or an open tab reaches without passing by the dossier.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\form\attest_form;
use tool_vae\local\attest_screen;
use tool_vae\local\attestation_manager;
use tool_vae\local\piece_manager;
use tool_vae\local\presenter;

require_login();

$evidenceid = required_param('evidenceid', PARAM_INT);

// A declaration that does not exist is refused word for word as one the reader may not attest,
// the exception attestation_manager::require_can_attest() throws. Any difference would tell anyone
// which ids are declarations.
$evidence = $DB->get_record('tool_vae_evidence', ['id' => $evidenceid], '*', IGNORE_MISSING);
if (!$evidence) {
    throw new moodle_exception('errorattestrefused', 'tool_vae');
}
$candidateid = (int) $evidence->candidateid;

// Refusals are logged before they are thrown - see attestation_manager::require_can_attest().
$context = attestation_manager::require_can_attest($candidateid, $evidenceid);
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);

$url = new moodle_url('/admin/tool/vae/attest.php', ['evidenceid' => $evidenceid]);
$dossier = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('attestheading', 'tool_vae'));
$PAGE->set_heading(fullname($candidate));

// Nobody attests their own declaration. A candidate holding the capability for other
// people's dossiers would otherwise reach this page for their own.
if ($candidateid === (int) $USER->id) {
    throw new moodle_exception('errorattestown', 'tool_vae', $dossier);
}

$standing = attestation_manager::active_attestation($evidenceid);

// The supporting pieces (T-13): shown above the form, and the list shown is the list signed. All the rows, removed
// ones included, for the page; the token is of those that are there.
// One read: what is shown and what is signed cannot differ.
$pieces = piece_manager::for_evidence($evidenceid);
$active = array_values(array_filter($pieces, fn($piece) => $piece->timeremoved === null));

$form = new attest_form($url->out(false), ['haspieces' => (bool) $active]);
$form->set_data(['evidenceid' => $evidenceid, 'piecesseen' => piece_manager::list_token($active)]);

if ($form->is_cancelled()) {
    redirect($dossier);
}

if (!$standing && ($data = $form->get_data())) {
    try {
        (new attestation_manager())->attest($evidenceid, $data);
    } catch (moodle_exception $e) {
        // A piece added or removed since the screen was drawn: the screen is drawn again, with the list as it is now.
        if (in_array($e->errorcode, ['errorattestpieceschanged', 'errorattestpiecesconfirm'], true)) {
            redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
        }
        // Another operation on the same declaration or on the candidate's pieces holds a lock: said to the signatory, in
        // their own words, not in those of the candidate's screen.
        if (in_array($e->errorcode, ['errorpiecebusy', 'locktimeout'], true)) {
            redirect($url, get_string('errorattestbusy', 'tool_vae'), null, \core\output\notification::NOTIFY_ERROR);
        }
        throw $e;
    }
    redirect(
        $dossier,
        get_string('attestationrecorded', 'tool_vae'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('attestheading', 'tool_vae'));

echo attest_screen::declaration($evidence, $context);

if ($pieces) {
    echo $OUTPUT->heading(get_string('attestpiecesheading', 'tool_vae'), 3);
    echo html_writer::tag('p', s(get_string('attestpiecesintro', 'tool_vae')));
    echo presenter::pieces($pieces, $context, $standing ?: null);
}

if ($standing) {
    // Already signed. Say so and show it: rendering the form here would invite a submission
    // attestation_manager refuses anyway. The withdrawal button is drawn for whoever may withdraw
    // this attestation - see attestation_manager::require_can_withdraw().
    echo $OUTPUT->notification(
        get_string('errorattestexists', 'tool_vae'),
        \core\output\notification::NOTIFY_INFO
    );
    // The whole history, not just the signature standing: a manager arriving here has to
    // see that an earlier one was withdrawn. That sentence is what the dossier is left with.
    echo presenter::attestations(attestation_manager::attestations($evidenceid));
    if (attestation_manager::may_withdraw($standing)) {
        echo $OUTPUT->single_button(
            new moodle_url('/admin/tool/vae/withdraw.php', ['attestationid' => $standing->id]),
            get_string('withdraw', 'tool_vae'),
            'get'
        );
    } else if (attestation_manager::may_request_withdrawal($standing, $candidateid)) {
        // The same recourse as the dossier offers: a manager who may not withdraw asks the signatory to.
        echo $OUTPUT->single_button(
            new moodle_url('/admin/tool/vae/withdrawrequest.php', [
                'attestationid' => $standing->id,
                'action' => 'request',
                'sesskey' => sesskey(),
            ]),
            get_string('requestwithdrawal', 'tool_vae'),
            'post'
        );
    } else if (
        $standing->attestantid !== null && (int) $standing->attestantid !== (int) $USER->id
            && !attestation_manager::signatory_reachable($standing)
    ) {
        // As on the dossier: the signatory is gone (deleted, or suspended), nobody can answer a request, and the reader is
        // sent to the one person who can still withdraw.
        echo $OUTPUT->notification(
            get_string('requestwithdrawalnosignatory', 'tool_vae'),
            \core\output\notification::NOTIFY_INFO
        );
    }
} else {
    $form->display();
}

echo $OUTPUT->footer();
