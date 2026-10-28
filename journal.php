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
 * The reflective journal: one's own entries, dated, private (T-14, lot 1).
 *
 * Like section.php, the page never takes the candidate from the request: the journal is $USER's, and nobody else's -
 * not the manager, not the administrator. An entry is written with section.php (document "reflexion"); this page lists
 * them, makes a new one and deletes one after confirmation.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\draft_manager;

require_login();

$candidateid = (int) $USER->id;
draft_manager::require_can_write($candidateid);
// Closed, the journal can no longer be written in, but its author can still look at it and delete from it: what they wrote
// is theirs, and the administrator closing it must not lock their own words in (T-14).
$open = draft_manager::reflection_open();
$kept = ['candidateid' => $candidateid, 'doctype' => draft_manager::DOCTYPE_REFLEXION];
if (!$open && !$DB->record_exists('tool_vae_draft', $kept)) {
    throw new moodle_exception('errorreflexionclosed', 'tool_vae');
}

$url = new moodle_url('/admin/tool/vae/journal.php');
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($candidateid));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('reflexiontitle', 'tool_vae'));
$PAGE->set_heading(fullname($USER));

$manager = new draft_manager();

// A new entry takes the first free number and opens its writing page.
if (optional_param('new', 0, PARAM_BOOL)) {
    require_sesskey();
    if (!$open) {
        throw new moodle_exception('errorreflexionclosed', 'tool_vae');
    }
    $slot = draft_manager::next_reflection_slot($candidateid);
    redirect(new moodle_url('/admin/tool/vae/section.php', [
        'doctype' => draft_manager::DOCTYPE_REFLEXION,
        'section' => draft_manager::REFLEXION_SECTION,
        'slot' => $slot,
    ]));
}

// A deletion asks first, and then removes the entry with all its versions.
$delete = optional_param('delete', 0, PARAM_INT);
if ($delete > 0) {
    $entry = $DB->get_record('tool_vae_draft', [
        'id' => $delete,
        'candidateid' => $candidateid,
        'doctype' => draft_manager::DOCTYPE_REFLEXION,
    ], '*', MUST_EXIST);
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        $manager->delete_entry((int) $entry->id);
        redirect($url, get_string('reflexiondeleted', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('reflexiondeleteconfirm', 'tool_vae', (int) $entry->slot),
        new moodle_url($url, ['delete' => $entry->id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $url
    );
    echo $OUTPUT->footer();
    die();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('reflexiontitle', 'tool_vae'));
echo html_writer::div(s(get_string('reflexionprivate', 'tool_vae')), 'alert alert-info tool-vae-labelling', [
    'role' => 'note',
]);
echo html_writer::div(s(get_string('reflexionconsigne', 'tool_vae')), 'alert alert-warning tool-vae-anonymise', [
    'role' => 'note',
]);
echo html_writer::tag('p', s(get_string('reflexionintro', 'tool_vae')));
if (!$open) {
    echo $OUTPUT->notification(get_string('reflexionclosednote', 'tool_vae'), \core\output\notification::NOTIFY_WARNING);
}

$entries = draft_manager::reflection_entries($candidateid);
if (!$open) {
    // Nothing to start while it is closed.
    echo '';
} else if (count($entries) < draft_manager::REFLEXION_MAX) {
    echo $OUTPUT->single_button(
        new moodle_url($url, ['new' => 1, 'sesskey' => sesskey()]),
        get_string('reflexionnew', 'tool_vae'),
        'post'
    );
} else {
    echo $OUTPUT->notification(
        get_string('errorreflexionfull', 'tool_vae', draft_manager::REFLEXION_MAX),
        \core\output\notification::NOTIFY_INFO
    );
}

if (!$entries) {
    echo html_writer::tag('p', s(get_string('reflexionnone', 'tool_vae')));
} else {
    $table = new html_table();
    $table->attributes['class'] = 'generaltable tool-vae-journal';
    $table->head = [
        get_string('reflexioncolentry', 'tool_vae'),
        get_string('reflexioncolwritten', 'tool_vae'),
        get_string('reflexioncolstate', 'tool_vae'),
        get_string('faisabcolactions', 'tool_vae'),
    ];
    foreach ($entries as $entry) {
        $edit = new moodle_url('/admin/tool/vae/section.php', [
            'doctype' => draft_manager::DOCTYPE_REFLEXION,
            'section' => draft_manager::REFLEXION_SECTION,
            'slot' => (int) $entry->slot,
        ]);
        $state = (int) $entry->currentversion > 0
            ? get_string('sectioncurrentversion', 'tool_vae', (int) $entry->currentversion)
            : get_string('sectionnoversion', 'tool_vae');
        $table->data[] = [
            s(draft_manager::label($entry)),
            s(userdate((int) $entry->timecreated, get_string('strftimedatetime', 'langconfig'))),
            s($state),
            ($open ? html_writer::link($edit, get_string('reflexionopen', 'tool_vae')) . ' ' : '')
                . html_writer::link(
                    new moodle_url($url, ['delete' => (int) $entry->id]),
                    get_string('reflexiondelete', 'tool_vae')
                ),
        ];
    }
    echo html_writer::table($table);
}

echo html_writer::tag('p', html_writer::link(
    new moodle_url('/admin/tool/vae/dossier.php'),
    get_string('reflexionbacktodossier', 'tool_vae')
));
echo $OUTPUT->footer();
