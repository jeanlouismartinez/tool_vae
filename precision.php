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
 * The requests for precision on one declaration: ask, answer, read (T-20).
 *
 * Like attest.php, the page takes the declaration's id and derives the candidate from it. A
 * declaration that does not exist is refused word for word as one the reader may not read.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\form\precision_form;
use tool_vae\local\access;
use tool_vae\local\attest_screen;
use tool_vae\local\attestation_manager;
use tool_vae\local\request_manager;

require_login();

$evidenceid = required_param('evidenceid', PARAM_INT);

$evidence = $DB->get_record('tool_vae_evidence', ['id' => $evidenceid], '*', IGNORE_MISSING);
if (!$evidence || !access::can_view((int) $evidence->candidateid)) {
    throw new moodle_exception('errorrequestdenied', 'tool_vae');
}
$candidateid = (int) $evidence->candidateid;
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);
$context = context_user::instance($candidateid);

$url = new moodle_url('/admin/tool/vae/precision.php', ['evidenceid' => $evidenceid]);
$dossier = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('precisionheading', 'tool_vae'));
$PAGE->set_heading(fullname($candidate));

$manager = new request_manager();
$requests = $manager->for_evidence($evidence);
$open = null;
foreach ($requests as $request) {
    if ($request->answer === null) {
        $open = $request;
    }
}

// One form, for whoever may write now: the candidate answers the open question; a manager who follows
// the candidate asks, when nothing is open and the declaration is not attested.
$form = null;
$answering = false;
if ($open && request_manager::can_answer($candidateid)) {
    $answering = true;
} else if (
    !$open
    && request_manager::can_ask($candidateid)
    && !attestation_manager::active_attestation($evidenceid)
) {
    $answering = false;
} else {
    $answering = null;
}
if ($answering !== null) {
    $form = new precision_form($url->out(false), [
        'answering' => $answering,
        'requestid' => $answering ? (int) $open->id : 0,
    ]);
    if ($form->is_cancelled()) {
        redirect($dossier);
    }
    if ($data = $form->get_data()) {
        $error = null;
        try {
            if ($answering) {
                $manager->answer((int) $open->id, (string) $data->text);
            } else {
                $manager->ask($evidenceid, (string) $data->text);
            }
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
        redirect(
            $url,
            $error ?? get_string($answering ? 'precisionanswered' : 'precisionasked', 'tool_vae'),
            null,
            $error === null ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('precisionheading', 'tool_vae'));
echo html_writer::tag('p', s(get_string('precisionintro', 'tool_vae')));

echo attest_screen::declaration($evidence, $context);

echo $OUTPUT->heading(get_string('precisionrequests', 'tool_vae'), 3);
if (!$requests) {
    echo html_writer::tag('p', s(get_string('precisionnone', 'tool_vae')));
}
foreach ($requests as $request) {
    $author = $request->authorname !== '' ? $request->authorname : get_string('opinionformerauthor', 'tool_vae');
    echo html_writer::tag('h4', s(get_string('precisionquestionheading', 'tool_vae')), ['class' => 'h5']);
    echo html_writer::tag('p', s($author . ' - ' . userdate(
        $request->timecreated,
        get_string('strftimedatetime', 'langconfig')
    )), ['class' => 'text-muted small']);
    echo format_text($request->question, FORMAT_PLAIN, ['context' => $context, 'filter' => false]);

    if ($request->answer === null) {
        echo html_writer::tag('p', s(get_string('precisionwaiting', 'tool_vae')), ['class' => 'text-muted']);
        continue;
    }
    echo html_writer::tag('h4', s(get_string('precisionanswerheading', 'tool_vae')), ['class' => 'h5']);
    echo html_writer::tag('p', s(userdate(
        $request->timeanswered,
        get_string('strftimedatetime', 'langconfig')
    )), ['class' => 'text-muted small']);
    echo format_text($request->answer, FORMAT_PLAIN, ['context' => $context, 'filter' => false]);
}

if ($form) {
    $form->display();
} else if (!$open && request_manager::can_ask($candidateid) && attestation_manager::active_attestation($evidenceid)) {
    echo $OUTPUT->notification(get_string('errorrequestattested', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
}

echo html_writer::tag('p', html_writer::link($dossier, get_string('precisionbacktodossier', 'tool_vae')));
echo $OUTPUT->footer();
