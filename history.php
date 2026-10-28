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
 * The versions of a section, and the way to compare or restore them (T-81, lot 3).
 *
 * Read by the candidate, the site administrator and whoever holds tool/vae:draftview; written
 * (restoring) by the candidate alone. The table never offers to delete a version: the history is
 * the point (D-9).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\draft_manager;
use tool_vae\local\faisabilite_fields;
use tool_vae\local\text_diff;

require_login();

$draftid = required_param('draftid', PARAM_INT);

$draft = $DB->get_record('tool_vae_draft', ['id' => $draftid]);
if (!$draft) {
    throw new moodle_exception('errordraftrefused', 'tool_vae');
}
$candidateid = (int) $draft->candidateid;
draft_manager::require_can_read_draft($draft);
$canwrite = draft_manager::can_write($candidateid);

$manager = new draft_manager();
$url = new moodle_url('/admin/tool/vae/history.php', ['draftid' => $draftid]);

// Restoring: POST, session key, and the candidate alone. A restoration empties the draft in progress, as a version does: the
// form carries the `workingat` this page was drawn with, and a draft written since from another tab is not wiped by it.
if (optional_param('action', '', PARAM_ALPHA) === 'restore') {
    require_sesskey();
    $number = required_param('number', PARAM_INT);
    try {
        $new = $manager->restore($draftid, $number, optional_param('workingat', 0, PARAM_INT));
    } catch (moodle_exception $e) {
        if ($e->errorcode !== 'errordraftconflict') {
            throw $e;
        }
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    if ($new === null) {
        redirect($url, get_string('draftunchanged', 'tool_vae'), null, \core\output\notification::NOTIFY_INFO);
    }
    redirect(
        $url,
        get_string('historyrestored', 'tool_vae', (object) ['new' => (int) $new->number, 'old' => $number]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($candidateid));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('historyheading', 'tool_vae'));
$PAGE->set_heading(fullname(core_user::get_user($candidateid)));

$versions = $manager->versions($draftid);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('historyheading', 'tool_vae'));
echo html_writer::tag('p', s(get_string(
    'faisabsectionline',
    'tool_vae',
    draft_manager::label($draft)
)));

if ($canwrite) {
    $edit = new moodle_url('/admin/tool/vae/section.php', [
        'doctype' => $draft->doctype, 'section' => $draft->section, 'slot' => (int) $draft->slot,
    ] + ($draft->doctype === draft_manager::DOCTYPE_VALIDATION ? ['fiche' => $draft->fichecode] : []));
    echo html_writer::tag('p', html_writer::link($edit, get_string('historyback', 'tool_vae')));
}

if (!$versions) {
    echo $OUTPUT->notification(get_string('historyempty', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    die();
}

$numbers = array_keys($versions);
$latest = max($numbers);

// The comparison form: two versions, one request, nothing written.
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => (new moodle_url('/admin/tool/vae/compare.php'))->out(false),
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'draftid', 'value' => $draftid]);

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    get_string('historyfrom', 'tool_vae'),
    get_string('historyto', 'tool_vae'),
    get_string('historyversion', 'tool_vae'),
    get_string('historydate', 'tool_vae'),
    get_string('historyauthor', 'tool_vae'),
    get_string('historynote', 'tool_vae'),
    get_string('historysize', 'tool_vae'),
    get_string('historychanges', 'tool_vae'),
    '',
];

$sorted = $versions;
krsort($sorted);
foreach ($sorted as $number => $version) {
    $previous = $versions[$number - 1] ?? null;
    $changes = '';
    if ($previous !== null) {
        $counts = text_diff::counts(text_diff::diff(
            faisabilite_fields::readable((string) $draft->section, (string) $previous->content),
            faisabilite_fields::readable((string) $draft->section, (string) $version->content)
        ));
        $changes = get_string('diffsummary', 'tool_vae', (object) $counts);
    }

    $author = '';
    if ($version->authorid !== null && $user = core_user::get_user((int) $version->authorid)) {
        $author = fullname($user);
    } else {
        $author = get_string('historyauthorerased', 'tool_vae');
    }

    $label = get_string('historyversion', 'tool_vae') . ' ' . $number;
    $restore = '';
    if ($canwrite && $number !== $latest) {
        $restore = html_writer::tag('button', get_string('historyrestore', 'tool_vae'), [
            'type' => 'submit',
            'form' => 'tool-vae-restore-' . $number,
            'class' => 'btn btn-secondary btn-sm',
        ]);
    }

    $note = s((string) $version->note);
    if ($version->restoredfrom !== null) {
        $note = trim(s(get_string('historyrestoredfrom', 'tool_vae', (int) $version->restoredfrom)) . ' ' . $note);
    }

    $table->data[] = [
        html_writer::empty_tag('input', [
            'type' => 'radio', 'name' => 'from', 'value' => $number,
            'aria-label' => get_string('historyfrom', 'tool_vae') . ': ' . $label,
            'checked' => $number === ($latest - 1 > 0 ? $latest - 1 : $latest) ? 'checked' : null,
        ]),
        html_writer::empty_tag('input', [
            'type' => 'radio', 'name' => 'to', 'value' => $number,
            'aria-label' => get_string('historyto', 'tool_vae') . ': ' . $label,
            'checked' => $number === $latest ? 'checked' : null,
        ]),
        html_writer::link(
            new moodle_url('/admin/tool/vae/compare.php', ['draftid' => $draftid, 'from' => $number, 'to' => $number]),
            $label
        ),
        userdate((int) $version->timecreated),
        s($author),
        $note,
        \core_text::strlen(faisabilite_fields::readable((string) $draft->section, (string) $version->content)),
        s($changes),
        $restore,
    ];
}
echo html_writer::table($table);
echo html_writer::tag('button', get_string('historycompare', 'tool_vae'), [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

// One small form per restoration, outside the comparison form: a form cannot hold another.
if ($canwrite) {
    foreach ($numbers as $number) {
        if ($number === $latest) {
            continue;
        }
        echo html_writer::start_tag('form', [
            'method' => 'post', 'action' => $url->out(false), 'id' => 'tool-vae-restore-' . $number,
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'restore']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'number', 'value' => $number]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'workingat', 'value' => (int) ($draft->workingat ?? 0),
        ]);
        echo html_writer::end_tag('form');
    }
}

echo $OUTPUT->footer();
