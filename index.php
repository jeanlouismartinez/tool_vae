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
 * Administration overview.
 *
 * admin_externalpage_setup() is what actually protects this page: declaring it in
 * settings.php only governs the menu entry. Omitting the call leaves the page reachable
 * by anyone who guesses the URL.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\local\competency_gateway;
use tool_vae\local\presenter;
use tool_vae\local\record_remover;
use tool_vae\local\source_frameworks;

admin_externalpage_setup('toolvae');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'tool_vae'));

if (!competency_gateway::is_subsystem_enabled()) {
    // Acceptance criterion 10: an explanation the administrator can act on, never a core
    // exception surfacing in the interface.
    echo $OUTPUT->notification(
        get_string('errorcompetenciesdisabled', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    die();
}

$sources = $DB->get_records('tool_vae_source', null, 'fichecode ASC');

if (!$sources) {
    // The empty state is the one most often forgotten, and the one most visible in production.
    echo $OUTPUT->notification(
        get_string('noframeworkimported', 'tool_vae'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    // A record whose framework is gone from the core - deleted in tool_lp, or never created because its first
    // import failed - keeps its row and lists no competency anywhere. Said here, with the ways out: import it again,
    // or, when the file was imported by mistake and no candidate has left a trace on it, remove its row.
    $missing = source_frameworks::missing($sources);
    if ($missing) {
        $canremove = has_capability('tool/vae:import', context_system::instance());
        // Without the right to import there is no button and nothing to explain about one: every row is simply kept.
        $sorted = (new record_remover())->sort_by_refusal($canremove ? $missing : []);
        $removable = $sorted->removable;
        $kept = array_values(array_diff($missing, $removable));

        if ($kept) {
            $warning = get_string('sourcenoframework', 'tool_vae', s(implode(', ', $kept)));
            // Two causes keep a button away, and each is said for the rows it applies to: a framework of Moodle that carries the
            // code, and a trace of a candidate. Told one for the other, the manager looks for what is not there.
            if ($sorted->framework) {
                $warning .= ' ' . get_string('sourcenoframeworkheld', 'tool_vae', s(implode(', ', $sorted->framework)));
            }
            if ($sorted->traces) {
                $warning .= ' ' . get_string('sourcenoframeworktraced', 'tool_vae', s(implode(', ', $sorted->traces)));
            }
            echo $OUTPUT->notification($warning, \core\output\notification::NOTIFY_WARNING);
        }

        if ($removable) {
            echo $OUTPUT->notification(
                get_string('sourcenoframeworkremovable', 'tool_vae', s(implode(', ', $removable))),
                \core\output\notification::NOTIFY_WARNING
            );

            // One row and one button per record, above the table of the records. A GET: the screen it opens asks the
            // confirmation, with the key of the session.
            $table = new html_table();
            $table->attributes['class'] = 'generaltable tool-vae-removable';
            $table->head = [get_string('sourcecode', 'tool_vae'), get_string('actions')];
            foreach ($removable as $code) {
                $table->data[] = [
                    html_writer::tag('strong', s($code)),
                    $OUTPUT->single_button(
                        new moodle_url('/admin/tool/vae/remove_record.php', ['fichecode' => $code]),
                        get_string('removerecordbutton', 'tool_vae'),
                        'get'
                    ),
                ];
            }
            echo html_writer::table($table);
        }
    }

    echo presenter::sources($sources);
}

if (has_capability('tool/vae:import', context_system::instance())) {
    // Two ways in, and the first is the one to use: the records of France compétences from its daily export, with nothing to
    // type but the numbers. The file of four columns stays, as a special case (a record prepared by hand). A GET opens a
    // screen: no request goes out and nothing is written from here.
    echo html_writer::div(
        $OUTPUT->render(new \core\output\single_button(
            new moodle_url('/admin/tool/vae/import_fiches.php'),
            get_string('fcimportheading', 'tool_vae'),
            'get',
            \core\output\single_button::BUTTON_PRIMARY
        ))
        . $OUTPUT->single_button(
            new moodle_url('/admin/tool/vae/import.php'),
            get_string('import', 'tool_vae'),
            'get'
        ),
        'd-flex flex-wrap gap-2 tool-vae-import-buttons'
    );
}

echo $OUTPUT->footer();
