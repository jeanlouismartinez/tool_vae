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
 * The declarations that still await an attestation, by candidate (T-22).
 *
 * For whoever may read every dossier (tool/vae:viewany): it puts side by side what they can already read
 * one by one. It says that declarations wait and for how long, never whether a dossier is good.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\local\display_date;
use tool_vae\local\pending_report;

admin_externalpage_setup('toolvae_pending');
require_capability('tool/vae:viewany', context_system::instance());

$days = min(pending_report::MAX_DAYS, max(0, optional_param('days', 0, PARAM_INT)));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pendingheading', 'tool_vae'));
echo html_writer::tag('p', s(get_string('pendingintro', 'tool_vae')));

$form = html_writer::tag('label', s(get_string('pendingdays', 'tool_vae')), ['for' => 'pendingdays'])
    . ' ' . html_writer::empty_tag('input', [
        'type' => 'number', 'name' => 'days', 'id' => 'pendingdays', 'min' => 0,
        'max' => pending_report::MAX_DAYS, 'value' => $days,
    ])
    . ' ' . html_writer::empty_tag('input', [
        'type' => 'submit', 'class' => 'btn btn-secondary', 'value' => get_string('pendingshow', 'tool_vae'),
    ]);
echo html_writer::tag('form', $form, ['method' => 'get', 'action' => $PAGE->url->out_omit_querystring()]);

$rows = pending_report::awaiting($days);
$groups = pending_report::by_candidate($rows);
if (!$groups) {
    echo html_writer::tag('p', s(get_string('pendingnone', 'tool_vae')));
    echo $OUTPUT->footer();
    die();
}

$users = $DB->get_records_list('user', 'id', array_keys($groups));
$table = new html_table();
$table->attributes['class'] = 'generaltable tool-vae-pending';
$table->head = [
    get_string('pendingcolcandidate', 'tool_vae'),
    get_string('pendingcolcount', 'tool_vae'),
    get_string('pendingcololdest', 'tool_vae'),
    get_string('pendingcoldays', 'tool_vae'),
];
$now = time();
// The oldest first: what has waited longest comes first.
uasort($groups, fn($a, $b) => $a['oldest'] <=> $b['oldest']);
foreach ($groups as $candidateid => $group) {
    $user = $users[$candidateid] ?? null;
    $name = $user ? fullname($user) : (string) $candidateid;
    $table->data[] = [
        html_writer::link(new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]), s($name)),
        (int) $group['count'],
        s(display_date::format((int) $group['oldest'], get_string('strftimedate', 'langconfig'))),
        (int) floor(($now - $group['oldest']) / DAYSECS),
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
