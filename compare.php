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
 * Compare two versions of a section (T-81, lot 3).
 *
 * Read only. The two versions come from the request as numbers of THIS section: both are read
 * through draft_manager, which checks the right to read, so a number of another section cannot
 * be reached from here.
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
$from = required_param('from', PARAM_INT);
$to = required_param('to', PARAM_INT);

$draft = $DB->get_record('tool_vae_draft', ['id' => $draftid]);
if (!$draft) {
    throw new moodle_exception('errordraftrefused', 'tool_vae');
}
$candidateid = (int) $draft->candidateid;
draft_manager::require_can_read_draft($draft);

$manager = new draft_manager();
$old = $manager->version($draftid, $from);
$new = $manager->version($draftid, $to);

$url = new moodle_url('/admin/tool/vae/compare.php', ['draftid' => $draftid, 'from' => $from, 'to' => $to]);
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($candidateid));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('compareheading', 'tool_vae'));
$PAGE->set_heading(fullname(core_user::get_user($candidateid)));

$ops = text_diff::diff(
    faisabilite_fields::readable((string) $draft->section, (string) $old->content),
    faisabilite_fields::readable((string) $draft->section, (string) $new->content)
);
$counts = text_diff::counts($ops);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('compareheading', 'tool_vae'));
echo html_writer::tag('p', s(get_string(
    'faisabsectionline',
    'tool_vae',
    draft_manager::label($draft)
)));

echo html_writer::tag('p', html_writer::link(
    new moodle_url('/admin/tool/vae/history.php', ['draftid' => $draftid]),
    get_string('historylink', 'tool_vae')
));

// One sentence first: which two versions, and how much moved. A reader who cannot see the marks
// still has the answer.
echo html_writer::tag('p', s(get_string('comparesummary', 'tool_vae', (object) [
    'from' => $from,
    'fromdate' => userdate((int) $old->timecreated),
    'to' => $to,
    'todate' => userdate((int) $new->timecreated),
    'summary' => get_string('diffsummary', 'tool_vae', (object) $counts),
])));

if ($from === $to) {
    echo $OUTPUT->notification(get_string('comparesame', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
}

// The text is escaped by text_diff::html(); white space is kept as written.
echo html_writer::div(
    text_diff::html($ops),
    'tool-vae-diff border rounded p-3',
    ['style' => 'white-space: pre-wrap;']
);

echo $OUTPUT->footer();
