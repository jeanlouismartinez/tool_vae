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
 * The certifications whose date has passed (T-90, plan 7).
 *
 * For whoever may read every dossier AND certify: it puts side by side what they can already read one by one. It says that
 * a date given by the management has passed, counted on the day of the reading - never whether a dossier is good: no total,
 * no colour, no ranking of people, the earliest last day first. Nothing is written, sent or stored.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\local\certification_manager;
use tool_vae\local\positioning_screen;
use tool_vae\local\renewal_report;

admin_externalpage_setup('toolvae_renewals');
$system = context_system::instance();
require_capability('tool/vae:viewany', $system);
require_capability('tool/vae:certify', $system);

$today = certification_manager::today();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('renewalsheading', 'tool_vae'));
echo html_writer::tag('p', s(get_string('positioningdisclaimer', 'tool_vae')), ['class' => 'text-muted small']);
echo html_writer::tag('p', s(get_string('renewalsintro', 'tool_vae')));
echo html_writer::tag('p', s(get_string('renewalsasof', 'tool_vae', $today)), ['class' => 'text-muted small']);

$rows = renewal_report::rows($today);
if (!$rows) {
    echo html_writer::tag('p', s(get_string('renewalsnone', 'tool_vae')));
    echo $OUTPUT->footer();
    die();
}

$table = new html_table();
$table->attributes['class'] = 'generaltable tool-vae-renewals';
$table->head = [
    get_string('renewalscolcandidate', 'tool_vae'),
    get_string('renewalscolrecord', 'tool_vae'),
    get_string('renewalscolbloc', 'tool_vae'),
    get_string('renewalscoluntil', 'tool_vae'),
    get_string('renewalscoldays', 'tool_vae'),
    get_string('renewalscolaction', 'tool_vae'),
];
foreach ($rows as $row) {
    $candidateid = (int) $row->candidateid;
    $dossier = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);
    $page = positioning_screen::url('certify', $candidateid, (string) $row->fichecode, (string) $row->ficherang);
    // Nobody certifies themselves, and reading one's own dossier is another right: no link is offered for one's own line.
    $own = (int) $USER->id === $candidateid;
    if ($own) {
        $action = html_writer::tag('span', s(get_string('renewalsreadonly', 'tool_vae')), ['class' => 'small text-muted']);
    } else if (!$row->held) {
        // One sentence per cause: the record gone from the site, the sub-system off, no declaration left on the record.
        $action = html_writer::tag('span', s(get_string('renewals' . $row->reason, 'tool_vae')), ['class' => 'small text-muted']);
    } else {
        $action = html_writer::link($page, get_string('positioningrenewlink', 'tool_vae'));
    }
    $table->data[] = [
        $own ? s(fullname($row)) : html_writer::link($dossier, s(fullname($row))),
        s($row->fichecode),
        s($row->label),
        s($row->validuntil),
        (int) $row->days,
        $action,
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
