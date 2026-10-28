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
 * Carry a declaration over to the record that replaces its own (T-30, lot 3, D-14).
 *
 * The page never takes a candidate from the request: it is the connected user's own declaration, or nothing. The
 * form starts from the old declaration; whatever the candidate confirms or corrects is made again on the new record
 * by carry_manager, through the same checks as any declaration. The old declaration and its attestation stay.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\form\declare_form;
use tool_vae\local\access;
use tool_vae\local\carry_manager;
use tool_vae\local\competency_gateway;
use tool_vae\local\display_date;
use tool_vae\local\hidden_frameworks;
use tool_vae\local\presenter;

require_login();

$evidenceid = required_param('evidenceid', PARAM_INT);
$evidence = $DB->get_record('tool_vae_evidence', ['id' => $evidenceid]);
if (!$evidence) {
    // The same refusal for a declaration that is not there and one that is not theirs.
    throw new moodle_exception('errorcarrydenied', 'tool_vae');
}

$candidateid = (int) $USER->id;
// A declaration is first person: someone else's evidence is refused, and so is a right that was withdrawn.
if ((int) $evidence->candidateid !== $candidateid) {
    throw new moodle_exception('errorcarrydenied', 'tool_vae');
}
$context = access::require_can_declare($candidateid);

$url = new moodle_url('/admin/tool/vae/carry.php', ['evidenceid' => $evidenceid]);
$dossier = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('carryheading', 'tool_vae'));
$PAGE->set_heading(fullname($USER));

$manager = new carry_manager();
try {
    // A replacing record hidden in Moodle ("Visible: No") does not stop the page from being read: a form opened before it was
    // hidden must give back what was typed, and not lose it in a redirect. It is said below, in words for the candidate.
    $offer = $manager->require_offer($evidence, true);
} catch (moodle_exception $e) {
    redirect($dossier, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
}
$refusal = $offer->hidden ? (new hidden_frameworks())->message($offer->successor) : null;

$form = new declare_form($url->out(false), [
    'competencies' => [(int) $offer->target->id => $offer->target],
    'submitlabel' => get_string('carrysubmit', 'tool_vae'),
]);
$defaults = carry_manager::defaults($evidence);
$defaults->userid = $candidateid;
$form->set_data($defaults);

if ($form->is_cancelled()) {
    redirect($dossier);
}

// Nothing was typed yet (the dossier drew the button before the record was hidden, or the address was kept): the candidate is
// told on the dossier, where the button was, and no form is opened for a carry that cannot be made.
if ($refusal !== null && !$form->is_submitted()) {
    redirect($dossier, $refusal, null, \core\output\notification::NOTIFY_ERROR);
}

if ($refusal === null && ($data = $form->get_data())) {
    try {
        $manager->carry($evidenceid, $data);
    } catch (moodle_exception $e) {
        // The record was hidden between the page and the send: the form is given back with its text, and the message.
        if ($e->errorcode !== 'errorframeworkhidden') {
            redirect($dossier, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
        }
        $refusal = $e->getMessage();
    }
    if ($refusal === null) {
        redirect(
            $dossier,
            get_string('carrydone', 'tool_vae'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$show = function (string $idnumber, string $label): string {
    return s($idnumber) . ' — ' . format_string($label);
};
$from = (new competency_gateway())->get_competency((int) $evidence->competencyid);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('carryheading', 'tool_vae'));
if ($refusal !== null) {
    echo $OUTPUT->notification($refusal, \core\output\notification::NOTIFY_ERROR);
    echo html_writer::tag('p', get_string('carryhiddenkept', 'tool_vae'));
}
echo html_writer::tag('p', get_string('carryintro', 'tool_vae'));

echo $OUTPUT->heading(get_string('carryoriginal', 'tool_vae', s($evidence->fichecode)), 3);
echo presenter::declaration($evidence, $context);

echo $OUTPUT->heading(get_string('carrycorrespondence', 'tool_vae'), 3);
$fromcode = (string) ($from->idnumber ?? $evidence->fichecode . '-' . $evidence->ficherang);
echo html_writer::tag('p', get_string('carrycorrespondencetext', 'tool_vae', (object) [
    'from' => $show($fromcode, (string) ($from->label ?? '')),
    'to' => $show((string) $offer->target->idnumber, (string) $offer->target->label),
    'when' => display_date::format((int) $offer->passage->decidedat, get_string('strftimedate', 'langconfig')),
]));
if (trim((string) $offer->passage->note) !== '') {
    echo html_writer::tag('p', get_string('carrynote', 'tool_vae', s($offer->passage->note)));
}

echo $OUTPUT->heading(get_string('carryformheading', 'tool_vae', s($offer->successor->fichecode)), 3);
$form->display();
echo $OUTPUT->footer();
