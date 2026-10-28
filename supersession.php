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
 * Replaced certification records: which record replaces which, and what still holds the old one (T-30, lot 1).
 *
 * admin_externalpage_setup() protects this page; the same right is asked again where the declaration is written
 * (supersession::declare_successor()). The page counts declarations and candidates and names nobody.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\form\supersession_form;
use tool_vae\local\supersession;

admin_externalpage_setup('toolvae_supersession');

$self = new moodle_url('/admin/tool/vae/supersession.php');
$rows = supersession::overview();
$options = [];
foreach ($rows as $row) {
    // No label when the framework of the record is gone from the core: the code alone, not "CODE — ".
    $options[$row->fichecode] = $row->fichecode . ($row->label === '' ? '' : ' — ' . format_string($row->label));
}

$message = null;
$error = null;

$clear = optional_param('clear', '', PARAM_ALPHANUMEXT);
if ($clear !== '') {
    require_sesskey();
    try {
        supersession::clear_successor($clear);
        redirect($self, get_string('supersessioncleared', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

$form = new supersession_form($self, ['options' => $options]);
if ($data = $form->get_data()) {
    try {
        supersession::declare_successor((string) $data->fichecode, (string) $data->successorcode);
        redirect($self, get_string('supersessionsaved', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('supersessionheading', 'tool_vae'));
echo html_writer::tag('p', get_string('supersessionintro', 'tool_vae'));

if ($error !== null) {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}

if (!$rows) {
    echo $OUTPUT->notification(get_string('supersessionnone', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
} else {
    $table = new html_table();
    $table->head = [
        get_string('supersessioncolfiche', 'tool_vae'),
        get_string('supersessioncolupdated', 'tool_vae'),
        get_string('supersessioncolsuccessor', 'tool_vae'),
        get_string('supersessioncoldeclarations', 'tool_vae'),
        get_string('supersessioncolcandidates', 'tool_vae'),
        get_string('supersessioncolcarried', 'tool_vae'),
        get_string('supersessioncolobsolete', 'tool_vae'),
        '',
    ];
    foreach ($rows as $row) {
        $successor = $row->successorcode === ''
            ? get_string('supersessionnosuccessor', 'tool_vae')
            : s($row->successorcode) . ($row->successorlabel === '' ? '' : ' — ' . format_string($row->successorlabel));
        $action = $row->successorcode === '' ? '' : html_writer::link(
            new moodle_url('/admin/tool/vae/passage.php', ['fiche' => $row->fichecode]),
            get_string('supersessionpassages', 'tool_vae')
        ) . $OUTPUT->single_button(
            new moodle_url($self, ['clear' => $row->fichecode]),
            get_string('supersessionclear', 'tool_vae'),
            'post'
        );
        $table->data[] = [
            s($row->fichecode) . ($row->label === '' ? '' : ' — ' . format_string($row->label)),
            $row->sourceupdated ? userdate($row->sourceupdated, get_string('strftimedate', 'langconfig')) : '',
            $successor,
            $row->declarations,
            $row->candidates,
            $row->carried,
            $row->gone,
            $action,
        ];
    }
    echo html_writer::table($table);

    echo $OUTPUT->heading(get_string('supersessiondeclareheading', 'tool_vae'), 3);
    $form->display();
}

echo $OUTPUT->footer();
