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
 * The competencies a candidate says are outside their activity (T-42, lot 1 and 2).
 *
 * The candidate's own page: nobody else says it for them, and the same right is asked again where a mark is written
 * (scope_manager). A mark is declarative and exempts from nothing.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\form\scope_form;
use tool_vae\local\access;
use tool_vae\local\display_date;
use tool_vae\local\scope_choices;
use tool_vae\local\scope_manager;

require_login();

$candidateid = (int) $USER->id;
$context = access::require_can_declare($candidateid);

$url = new moodle_url('/admin/tool/vae/scope.php');
$dossier = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('scopeheading', 'tool_vae'));
$PAGE->set_heading(fullname($USER));

$open = !empty(get_config('tool_vae', 'scopeopen'));
$error = null;

$remove = optional_param('remove', 0, PARAM_INT);
if ($remove > 0) {
    require_sesskey();
    try {
        scope_manager::unmark($candidateid, $remove);
        redirect($url, get_string('scoperemoved', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

// The competencies of the imported records, by "code-rank", and what each key stands for.
[$options, $parts] = scope_choices::build();

$form = new scope_form($url, ['options' => $options]);
if ($open && ($data = $form->get_data())) {
    try {
        [$fichecode, $ficherang] = $parts[(string) $data->competency] ?? ['', ''];
        scope_manager::mark($candidateid, $fichecode, $ficherang, (string) $data->note);
        redirect($url, get_string('scopesaved', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

$marks = scope_manager::for_candidate($candidateid);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('scopeheading', 'tool_vae'));
echo html_writer::tag('p', get_string('scopeintro', 'tool_vae'));
echo html_writer::tag('p', get_string('scopesentence', 'tool_vae'), ['class' => 'font-italic']);
if (!$open) {
    echo $OUTPUT->notification(get_string('scopeclosed', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
}
if ($error !== null) {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}

if (!$marks) {
    echo html_writer::tag('p', get_string('scopenone', 'tool_vae'));
} else {
    $table = new html_table();
    $table->head = [
        get_string('scopecompetency', 'tool_vae'),
        get_string('scopenote', 'tool_vae'),
        get_string('scopesaidon', 'tool_vae'),
        '',
    ];
    $labels = scope_manager::labels($marks);
    foreach ($marks as $key => $mark) {
        $table->data[] = [
            s($key . (($labels[$key] ?? '') === '' ? '' : ' — ' . $labels[$key])),
            $mark->note === null ? '' : s($mark->note),
            s(display_date::format((int) $mark->timecreated, get_string('strftimedate', 'langconfig'))),
            $OUTPUT->single_button(
                new moodle_url($url, ['remove' => $mark->id]),
                get_string('scoperemove', 'tool_vae'),
                'post'
            ),
        ];
    }
    echo html_writer::table($table);
}

if ($open) {
    if (!$options) {
        echo $OUTPUT->notification(get_string('noframeworkimported', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
    } else {
        echo $OUTPUT->heading(get_string('scopeaddheading', 'tool_vae'), 3);
        $form->display();
    }
}
echo html_writer::div(html_writer::link($dossier, get_string('faisabbacktodossier', 'tool_vae')), 'mt-3');
echo $OUTPUT->footer();
