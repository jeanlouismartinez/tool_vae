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
 * Declare an activity.
 *
 * The page never takes a candidate from the request: a declaration is first person, so the
 * subject is $USER and nothing else. That removes a whole class of mistake before it can be
 * made - there is no userid here to tamper with.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\form\declare_form;
use tool_vae\local\access;
use tool_vae\local\ai_assistant;
use tool_vae\local\competency_gateway;
use tool_vae\local\evidence_manager;
use tool_vae\local\hidden_frameworks;

require_login();

$candidateid = (int) $USER->id;
$context = access::require_can_declare($candidateid);

$url = new moodle_url('/admin/tool/vae/declare.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('declareheading', 'tool_vae'));
$PAGE->set_heading(fullname($USER));

$dossier = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);

if (!competency_gateway::is_subsystem_enabled()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('declareheading', 'tool_vae'));
    echo $OUTPUT->notification(
        get_string('errorcompetenciesdisabled', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    die();
}

// ASKED BEFORE THE FORM, NOT AFTER IT IS FILLED. The competencies below are listed without the viewing right of the core,
// so the form opens for anyone; but the core asks that right again when the declaration is recorded, to link the evidence to
// the competency, and refuses a role that holds neither competencyview nor competencymanage. Without this the vaeiste writes
// the whole situation, and loses it on an error page.
if (!competency_gateway::can_view_competencies(context_system::instance())) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('declareheading', 'tool_vae'));
    echo $OUTPUT->notification(
        get_string('errordeclarenoview', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    die();
}

// Only competencies from imported certification records may be claimed: a declaration has
// to say which version of a framework it refers to, and only an imported record carries a
// publication date.
// A framework hidden in tool_lp ("Visible: No") is one the core does not offer to be chosen, and the one it refuses to attach
// to a course: nothing new is declared on it. The record is not left to vanish from the list - the vaeiste is told why, in
// words made for the person who declares (hidden_frameworks), and not in those of an administrator.
$frameworks = new hidden_frameworks();
$offered = $frameworks->offered();
$competencies = $offered->competencies;
$outline = $offered->outline;
$hidden = $offered->hidden;

// WHAT A FORM SENT IS READ BEFORE ANYTHING ELSE, BECAUSE IT MAY HAVE TO BE GIVEN BACK. A form opened before a record was hidden
// is sent with a competency the list no longer holds: the form drops a value it does not offer, and the page used to end on
// "this competency does not exist", the text lost. The competency is read from the request here, to be refused by name.
$sent = hidden_frameworks::sent();

if (!$competencies) {
    if ($sent->cancelled) {
        redirect($dossier);
    }
    // Every record hidden since the form was opened leaves nothing to choose, so there is no form: the text is given back as is.
    $kept = hidden_frameworks::kept_text($sent->situation, $sent->settingdetail);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('declareheading', 'tool_vae'));
    // When every record is hidden, saying so is the truth; "no record imported" would not be. A send that arrives here was
    // refused, which is not the same thing as a page that is only read.
    $level = $kept === '' ? \core\output\notification::NOTIFY_INFO : \core\output\notification::NOTIFY_ERROR;
    foreach ($hidden as $message) {
        echo $OUTPUT->notification($message, $level);
    }
    if (!$hidden) {
        echo $OUTPUT->notification(
            get_string('noframeworkimported', 'tool_vae'),
            \core\output\notification::NOTIFY_INFO
        );
    }
    echo $kept;
    echo $OUTPUT->footer();
    die();
}

// The competency a form chose, in a record hidden since: refused with the message of that record, and the form given back.
$refusal = $sent->competencyid ? $frameworks->refusal_for_competency($sent->competencyid) : null;
// A form sent with a competency the list does not offer (the record hidden since, or nothing chosen after a refusal) comes
// back asking for a choice: nothing is chosen for the person, whose second click would declare on another record.
$choose = $sent->submitted && !isset($competencies[$sent->competencyid]);

// The aid to reword the situation (T-70): offered only when it is open, theirs to use and a provider can answer.
$aiurl = (new ai_assistant())->offered($candidateid) ? new moodle_url('/admin/tool/vae/ai.php', ['source' => 'declare']) : null;
$form = new declare_form($url->out(false), [
    'competencies' => $competencies,
    'outline' => $outline,
    'aiurl' => $aiurl,
    'choose' => $choose,
]);
$form->set_data(['userid' => $candidateid]);

if ($form->is_cancelled()) {
    redirect($dossier);
}

if ($refusal === null && ($data = $form->get_data())) {
    // A narrative is plain text. The form offers no format, so none is taken from the request.
    $data->situationformat = FORMAT_PLAIN;
    try {
        (new evidence_manager())->declare_activity($candidateid, $data);
    } catch (moodle_exception $e) {
        // The record was hidden between the list and the write: the same message, and the form given back with its text. Any
        // other refusal is what it was.
        if ($e->errorcode !== 'errorframeworkhidden') {
            throw $e;
        }
        $refusal = $e->getMessage();
    }
    if ($refusal === null) {
        redirect(
            $dossier,
            get_string('declarationrecorded', 'tool_vae'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('declareheading', 'tool_vae'));
if ($refusal !== null) {
    echo $OUTPUT->notification($refusal, \core\output\notification::NOTIFY_ERROR);
    echo html_writer::tag('p', get_string('declarehiddenkept', 'tool_vae'));
}
foreach ($hidden as $message) {
    // The record the form was refused for has just been named.
    if ($message !== $refusal) {
        echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_INFO);
    }
}
$form->display();
echo $OUTPUT->footer();
