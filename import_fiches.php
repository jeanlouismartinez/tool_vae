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
 * Import certification records from the export of France compétences: upload it, or have the server fetch it (0.47.0).
 *
 * THREE STATES OF ONE ADDRESS. Without a token: the numbers, and the two ways to get the export. With the token of a plan that
 * has no result yet: the preview, and the confirmation. With the token of a plan that has its results: what became of each
 * record. A GET never makes a request and never writes; the preview is an address that can be reloaded, and so is the result:
 * a second click, or a reload, imports nothing again.
 *
 * The rights are asked here, and again by fiche_import at every entry that reads, requests or writes: admin_externalpage_setup,
 * the capability to import, the subsystem of the competencies and the right to manage the frameworks - said on the page, before a
 * file is read or a request goes out.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\form\fiche_confirm_form;
use tool_vae\form\fiche_import_form;
use tool_vae\local\fiche_import;
use tool_vae\local\fiche_messages;
use tool_vae\local\fiche_plan_store;
use tool_vae\local\fiche_refusal;
use tool_vae\local\fiche_screen;

$self = new moodle_url('/admin/tool/vae/import_fiches.php');
admin_externalpage_setup('toolvae', '', null, $self);
$context = context_system::instance();
require_capability('tool/vae:import', $context);

$index = new moodle_url('/admin/tool/vae/index.php');
$notify = \core\output\notification::class;
$PAGE->set_title(get_string('fcimportheading', 'tool_vae'));

// The refusals that can be said before anything is read or requested.
$unavailable = fiche_import::unavailable($context);
if ($unavailable !== null) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('fcimportheading', 'tool_vae'));
    echo $OUTPUT->notification(get_string($unavailable, 'tool_vae'), $notify::NOTIFY_ERROR);
    echo $OUTPUT->footer();
    die();
}

$import = new fiche_import();
$token = optional_param('token', '', PARAM_ALPHANUM);

if ($token !== '') {
    $plan = preg_match(fiche_plan_store::TOKEN_PATTERN, $token) ? $import->load($token) : null;
    if ($plan === null) {
        redirect(
            $self,
            fiche_messages::html(new fiche_refusal(fiche_refusal::EXPIRED)),
            null,
            $notify::NOTIFY_ERROR
        );
    }
    $here = new moodle_url($self, ['token' => $token]);
    $PAGE->set_url($here);

    if ($plan->results !== null) {
        // THE RESULT: what became of each record. The same address, rechargeable; nothing here writes.
        $PAGE->set_title(get_string('fcimportresultheading', 'tool_vae'));
        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('fcimportresultheading', 'tool_vae'));
        echo fiche_screen::results($plan);
        $untouched = array_filter($plan->results, fn($result) => $result->status !== 'imported');
        if ($untouched) {
            // Some records were not written: the numbers come back to the form, ready for another try.
            echo html_writer::tag('p', html_writer::link(
                new moodle_url($self, ['redo' => $token]),
                get_string('fcimportredo', 'tool_vae')
            ));
        }
        echo $OUTPUT->continue_button($index);
        echo $OUTPUT->footer();
        die();
    }

    // THE PREVIEW AND ITS CONFIRMATION.
    $counts = fiche_import::counts($plan);
    $form = new fiche_confirm_form($here->out(false), [
        'token' => $token, 'importable' => $counts->importable, 'older' => $counts->older,
    ]);
    if ($form->is_cancelled()) {
        $import->discard($token);
        redirect($index);
    }
    if ($data = $form->get_data()) {
        $failure = null;
        $back = $here;
        try {
            $applied = $import->apply($token, fiche_import::allow_older($plan, !empty($data->allowolder)));
            if (($applied->resultkept ?? true) === false) {
                // The records are written but the result could not be put beside the plan: it is shown now, once.
                $PAGE->set_title(get_string('fcimportresultheading', 'tool_vae'));
                echo $OUTPUT->header();
                echo $OUTPUT->heading(get_string('fcimportresultheading', 'tool_vae'));
                echo $OUTPUT->notification(get_string('fcimportresultunsaved', 'tool_vae'), $notify::NOTIFY_WARNING);
                echo fiche_screen::results($applied);
                echo $OUTPUT->continue_button($index);
                echo $OUTPUT->footer();
                die();
            }
        } catch (fiche_refusal $refusal) {
            $failure = fiche_messages::html($refusal);
            $back = $refusal->reason() === fiche_refusal::EXPIRED ? $self : $here;
        } catch (Throwable $e) {
            // What the person may read, and nothing else: the detail of a failure goes to the debugging messages of the site.
            $failure = fiche_import::exception_html($e);
        }
        redirect($failure === null ? $here : $back, (string) $failure, null, $notify::NOTIFY_ERROR);
    }

    $PAGE->set_title(get_string('fcimportpreviewheading', 'tool_vae'));
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('fcimportpreviewheading', 'tool_vae'));
    echo fiche_screen::preview($plan);
    if ($counts->importable || $counts->older) {
        echo html_writer::tag('p', get_string('fcimportwaitapply', 'tool_vae'), ['class' => 'small']);
    }
    $form->display();
    echo $OUTPUT->footer();
    die();
}

// THE NUMBERS, AND THE TWO WAYS. The upload is taken BEFORE the form is built: the form library empties $_FILES when it processes a
// submission, and its own file element is not used here (see fiche_import_form).
$upload = $_FILES['exportzip'] ?? null;
$maxbytes = get_max_upload_file_size();
$PAGE->set_url($self);

// A file larger than post_max_size arrives as an empty POST: the form would simply come back, with no word.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$upload && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    redirect(
        $self,
        fiche_messages::html(new fiche_refusal(fiche_refusal::UPLOAD)),
        null,
        $notify::NOTIFY_ERROR
    );
}

$form = new fiche_import_form($self->out(false), ['maxbytes' => $maxbytes]);
if ($form->is_cancelled()) {
    redirect($index);
}

$failure = null;
if ($data = $form->get_data()) {
    $newtoken = null;
    $fetch = !empty($data->fetchbutton);
    $zip = !empty($data->zipbutton);
    try {
        if (!$fetch && !$zip) {
            // Enter pressed in a field: the hidden button of the form took it, and neither way was chosen. Nothing goes out.
            $failure = get_string('fcimportchoose', 'tool_vae');
        } else {
            $parsed = fiche_import::parse_numbers((string) $data->numbers);
            if ($fetch) {
                $newtoken = $import->from_data_gouv($parsed->numbers, $parsed->repeated);
            } else {
                $path = fiche_import::upload_path(is_array($upload) ? $upload : null);
                $name = clean_param((string) ($upload['name'] ?? ''), PARAM_FILE);
                $typed = empty($data->exportdate) ? null : (int) $data->exportdate;
                $newtoken = $import->from_upload($path, $name, $parsed->numbers, $typed, $parsed->repeated);
            }
        }
    } catch (fiche_refusal $refusal) {
        $failure = fiche_messages::html($refusal);
    } catch (Throwable $e) {
        // The detail goes to the debugging messages of the site, never to the screen.
        $failure = fiche_import::exception_html($e);
    }
    if ($newtoken !== null) {
        redirect(new moodle_url($self, ['token' => $newtoken]));
    }
}

// The numbers of the records already imported, once: a link to take them back, and what the field then holds.
$known = fiche_import::known_numbers();
$knowntotal = fiche_import::known_total();

// Numbers put back in the field: those of a plan one comes back to change, or those of the records already imported.
if (!$form->is_submitted()) {
    $redo = optional_param('redo', '', PARAM_ALPHANUM);
    $plan = $redo !== '' && preg_match(fiche_plan_store::TOKEN_PATTERN, $redo) ? $import->load($redo) : null;
    if ($plan !== null) {
        $form->set_data(['numbers' => implode("\n", $plan->numbers)]);
    } else if (optional_param('known', 0, PARAM_BOOL)) {
        $form->set_data(['numbers' => implode("\n", $known)]);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('fcimportheading', 'tool_vae'));
if ($failure !== null) {
    echo $OUTPUT->notification($failure, $notify::NOTIFY_ERROR);
}
echo html_writer::tag('p', get_string('fcimportintro', 'tool_vae'));
echo html_writer::tag('p', get_string('fcimportneutral', 'tool_vae'), ['class' => 'small']);
if ($known) {
    // More records than the form takes at a time: the link says how many it takes back, and of how many.
    $label = $knowntotal > count($known)
        ? get_string('fcimportexistingmany', 'tool_vae', (object) ['count' => count($known), 'total' => $knowntotal])
        : get_string('fcimportexisting', 'tool_vae');
    echo html_writer::tag('p', html_writer::link(new moodle_url($self, ['known' => 1]), $label));
}
$form->display();
echo $OUTPUT->footer();
