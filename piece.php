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
 * The supporting pieces of one declaration: attach a file or a link, read the list, remove one (T-13, lot 1).
 *
 * Like precision.php, the page takes the declaration's id and derives the candidate from it. The candidate adds and removes;
 * whoever may read the dossier reads the list, and opens a piece only with the right to (lot 2). A declaration that does not
 * exist is refused word for word as one the reader may not read.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\form\piece_file_form;
use tool_vae\form\piece_link_form;
use tool_vae\local\access;
use tool_vae\local\attest_screen;
use tool_vae\local\attestation_manager;
use tool_vae\local\competency_gateway;
use tool_vae\local\piece_manager;
use tool_vae\local\presenter;

require_login();

$evidenceid = required_param('evidenceid', PARAM_INT);

$evidence = $DB->get_record('tool_vae_evidence', ['id' => $evidenceid], '*', IGNORE_MISSING);
// The candidate, who adds and removes; or whoever may read the dossier, who only reads the list (T-13, lot 2). A
// declaration that does not exist is refused as one that is not theirs.
if (!$evidence) {
    throw new moodle_exception('errorpiecedenied', 'tool_vae');
}
$candidateid = (int) $evidence->candidateid;
$iscandidate = $candidateid === (int) $USER->id;
if (!$iscandidate && !access::can_view($candidateid)) {
    throw new moodle_exception('errorpiecedenied', 'tool_vae');
}
$context = context_user::instance($candidateid);
if ($iscandidate) {
    require_capability('tool/vae:viewown', $context);
}
if (!competency_gateway::is_subsystem_enabled()) {
    throw new moodle_exception('errorcompetenciesdisabled', 'tool_vae');
}

$url = new moodle_url('/admin/tool/vae/piece.php', ['evidenceid' => $evidenceid]);
$dossier = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('pieceheading', 'tool_vae'));
$PAGE->set_heading(fullname($iscandidate ? $USER : core_user::get_user($candidateid, '*', MUST_EXIST)));

$manager = new piece_manager();
$settings = piece_manager::settings();
// What PHP lets through is the other limit: the smaller of the two is the one said and applied.
$maxbytes = min($settings->maxbytes, get_max_upload_file_size());
$notify = \core\output\notification::class;

// Removing: a POST with the session key, always possible, open or closed. The declaration is checked before anything goes.
$remove = optional_param('remove', 0, PARAM_INT);
if ($remove && $iscandidate && data_submitted()) {
    require_sesskey();
    $error = null;
    try {
        $manager->remove($remove, $evidenceid);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
    redirect(
        $url,
        $error ?? get_string('pieceremoved', 'tool_vae'),
        null,
        $error === null ? $notify::NOTIFY_SUCCESS : $notify::NOTIFY_ERROR
    );
}

// The upload is taken BEFORE the forms are built: the form library empties $_FILES when it processes a submission, and
// its own file element is not used here (see piece_file_form).
$upload = $_FILES['piecefile'] ?? null;
// A file larger than post_max_size arrives as an empty POST: the form would simply come back, with no word.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$upload && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    redirect(
        $url,
        get_string('errorpiecetoolarge', 'tool_vae', display_size($maxbytes)),
        null,
        $notify::NOTIFY_ERROR
    );
}

$canadd = $settings->open && $iscandidate;
$fileform = $linkform = null;
if ($canadd) {
    $fileform = new piece_file_form($url->out(false), ['evidenceid' => $evidenceid, 'maxbytes' => $maxbytes]);
    $linkform = new piece_link_form($url->out(false), ['evidenceid' => $evidenceid]);

    if ($fileform->is_cancelled() || $linkform->is_cancelled()) {
        redirect($dossier);
    }
    if ($data = $fileform->get_data()) {
        $error = null;
        try {
            if (!$upload || !is_array($upload) || is_array($upload['error'])) {
                throw new moodle_exception('errorpiecenofile', 'tool_vae');
            }
            if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
                throw new moodle_exception('errorpiecenofile', 'tool_vae');
            }
            if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new moodle_exception('errorpiecetoolarge', 'tool_vae', '', display_size($maxbytes));
            }
            if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
                throw new moodle_exception('errorpiecefile', 'tool_vae');
            }
            $manager->add_file(
                $evidenceid,
                (string) $data->filenature,
                (string) $data->filetitle,
                $upload['tmp_name'],
                clean_param((string) $upload['name'], PARAM_FILE)
            );
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
        redirect(
            $url,
            $error ?? get_string('pieceaddedfile', 'tool_vae'),
            null,
            $error === null ? $notify::NOTIFY_SUCCESS : $notify::NOTIFY_ERROR
        );
    }
    if ($data = $linkform->get_data()) {
        $error = null;
        $warnings = [];
        try {
            $row = $manager->add_link($evidenceid, (string) $data->linknature, (string) $data->linktitle, (string) $data->url);
            $warnings = array_map(fn($key) => get_string($key, 'tool_vae'), $row->warnings);
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
        $message = $error ?? trim(get_string('pieceaddedlink', 'tool_vae') . ' ' . implode(' ', $warnings));
        $level = $error !== null ? $notify::NOTIFY_ERROR : ($warnings ? $notify::NOTIFY_WARNING : $notify::NOTIFY_SUCCESS);
        redirect($url, $message, null, $level);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pieceheading', 'tool_vae'));
echo html_writer::tag('p', s(get_string('pieceintro', 'tool_vae')));
echo html_writer::div(s(get_string('piecelimits', 'tool_vae')), 'alert alert-warning');

echo attest_screen::declaration($evidence, $context);

// The list: one renderer for the candidate, for the manager who attests and for whoever only reads (presenter::pieces()).
// Only the candidate gets a button to remove, in the same place as always.
$pieces = piece_manager::for_evidence($evidenceid);
$standing = attestation_manager::active_attestation($evidenceid);
echo presenter::pieces(
    $pieces,
    $context,
    $standing,
    $iscandidate
        ? fn($piece) => $OUTPUT->single_button(
            new moodle_url($url, ['remove' => (int) $piece->id]),
            get_string('pieceremove', 'tool_vae'),
            'post'
        )
        : null
);

if ($canadd) {
    $fileform->display();
    $linkform->display();
} else if ($iscandidate) {
    echo $OUTPUT->notification(get_string('piececlosed', 'tool_vae'), $notify::NOTIFY_INFO);
}

echo html_writer::tag('p', html_writer::link($dossier, get_string('piecebacktodossier', 'tool_vae')));
echo $OUTPUT->footer();
