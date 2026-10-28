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
 * Attach an imported certification record to a course (lot 8 bis).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\competency_gateway;
use tool_vae\local\course_linker;

$courseid = required_param('courseid', PARAM_INT);
$fichecode = optional_param('fichecode', '', PARAM_ALPHANUMEXT);

$course = get_course($courseid);
require_login($course);

$context = course_linker::require_can_link($courseid);

$url = new moodle_url('/admin/tool/vae/course_link.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('linkrecord', 'tool_vae'));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));

if (!competency_gateway::is_subsystem_enabled()) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('errorcompetenciesdisabled', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    die();
}

$linker = new course_linker();

if ($fichecode !== '') {
    require_sesskey();

    // A refusal the linker names - unknown record, framework gone or hidden in the core - is told on this
    // screen, where the manager is, and not by an error page. Only what this plugin names is: any other
    // exception - a database fault, a refusal of the core, a coding_exception - is a fault, and it is left to
    // say so, with its page, its trace and its line in the log. They are all moodle_exceptions, so it is
    // the module they carry that tells them apart; that of a coding_exception is "debug".
    try {
        $added = $linker->link($courseid, $fichecode);
    } catch (\moodle_exception $e) {
        if ($e->module !== 'tool_vae') {
            throw $e;
        }
        // The message was escaped where it was thrown, like the one of import.php.
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    $message = $added
        ? get_string('linkrecorddone', 'tool_vae', (object) ['added' => $added, 'code' => $fichecode])
        : get_string('linkrecordnothing', 'tool_vae', $fichecode);

    redirect($url, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('linkrecord', 'tool_vae'));

$records = $linker->records($courseid);
if (!$records) {
    echo $OUTPUT->notification(get_string('linkrecordnone', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    die();
}

echo html_writer::tag('p', s(get_string('linkrecordintro', 'tool_vae')));

$table = new html_table();
$table->head = [
    get_string('linkrecordcode', 'tool_vae'),
    get_string('linkrecordupdated', 'tool_vae'),
    get_string('linkrecordincourse', 'tool_vae'),
    '',
];
foreach ($records as $record) {
    $complete = $record->total > 0 && $record->incourse >= $record->total;
    if ($record->hidden) {
        // A framework hidden in tool_lp is one the core will not attach: the reason, instead of a button
        // that ends on an error.
        $action = html_writer::tag('span', s(get_string('linkrecordhidden', 'tool_vae')), ['class' => 'small text-muted']);
    } else {
        $action = $complete ? '' : $OUTPUT->single_button(
            new moodle_url($url, ['fichecode' => $record->fichecode]),
            get_string('linkrecordbutton', 'tool_vae'),
            'post'
        );
    }
    $table->data[] = [
        s($record->fichecode) . ' (' . s(core_text::strtoupper($record->fichetype)) . ')',
        $record->sourceupdated ? \tool_vae\local\display_date::format(
            (int) $record->sourceupdated,
            get_string('strftimedate', 'langconfig')
        ) : '-',
        s(get_string('linkrecordcount', 'tool_vae', $record)),
        $action,
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
