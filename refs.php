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
 * The addresses where a certifier publishes its rule of validation, record by record (T-41, lot 1).
 *
 * admin_externalpage_setup() protects this page; the same right is asked again where an address is written
 * (ref_manager). The page names nobody: the addresses are public.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\form\ref_form;
use tool_vae\local\display_date;
use tool_vae\local\ref_manager;
use tool_vae\local\supersession;

admin_externalpage_setup('toolvae_refs');

$self = new moodle_url('/admin/tool/vae/refs.php');
$rows = supersession::overview();
$options = [];
foreach ($rows as $row) {
    $options[$row->fichecode] = $row->fichecode . ' — ' . format_string($row->label);
}

$error = null;

$remove = optional_param('remove', 0, PARAM_INT);
if ($remove > 0) {
    require_sesskey();
    try {
        ref_manager::remove($remove);
        redirect($self, get_string('refremoved', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

$form = new ref_form($self, ['options' => $options]);
if ($data = $form->get_data()) {
    try {
        // The picker gives midnight in the administrator's timezone. What is kept is the calendar day they picked, at noon
        // UTC, and it is shown in UTC: a reader in another timezone sees the same day.
        $checked = null;
        if (!empty($data->checkedat)) {
            $day = usergetdate((int) $data->checkedat);
            $checked = gmmktime(12, 0, 0, $day['mon'], $day['mday'], $day['year']);
        }
        [, $warnings] = ref_manager::add(
            (string) $data->fichecode,
            (string) $data->title,
            (string) $data->url,
            $checked
        );
        $message = get_string('refsaved', 'tool_vae');
        foreach ($warnings as $warning) {
            $message .= ' ' . get_string($warning, 'tool_vae');
        }
        redirect($self, $message, null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('refheading', 'tool_vae'));
echo html_writer::tag('p', get_string('refintro', 'tool_vae'));

if (empty(get_config('tool_vae', 'refopen'))) {
    echo $OUTPUT->notification(get_string('refclosed', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
}
if ($error !== null) {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}

if (!$rows) {
    echo $OUTPUT->notification(get_string('supersessionnone', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
} else {
    $refs = ref_manager::for_fiches(array_keys($rows));
    $table = new html_table();
    $table->head = [
        get_string('supersessioncolfiche', 'tool_vae'),
        get_string('reftitle', 'tool_vae'),
        get_string('refurl', 'tool_vae'),
        get_string('refchecked', 'tool_vae'),
        '',
    ];
    foreach ($rows as $row) {
        $name = s($row->fichecode) . ' — ' . format_string($row->label);
        if (empty($refs[$row->fichecode])) {
            $table->data[] = [$name, get_string('refnone', 'tool_vae'), '', '', ''];
            continue;
        }
        foreach ($refs[$row->fichecode] as $ref) {
            $table->data[] = [
                $name,
                s($ref->title),
                // The address in full, as text: nothing hides where a click goes. Not a link here, the administrator
                // checks it where it is shown to the candidates.
                s($ref->url),
                $ref->checkedat === null ? get_string('refnotchecked', 'tool_vae') : s(display_date::format(
                    $ref->checkedat,
                    get_string('strftimedate', 'langconfig'),
                    'UTC'
                )),
                $OUTPUT->single_button(
                    new moodle_url($self, ['remove' => $ref->id]),
                    get_string('refremove', 'tool_vae'),
                    'post'
                ),
            ];
            $name = '';
        }
    }
    echo html_writer::table($table);

    echo $OUTPUT->heading(get_string('refaddheading', 'tool_vae'), 3);
    $form->display();
}

echo $OUTPUT->footer();
