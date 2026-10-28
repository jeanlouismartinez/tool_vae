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
 * The candidates the current user follows (lot 7 bis).
 *
 * This page takes no user id from the request: it only ever lists the reader's own followed
 * candidates, as \tool_vae\local\access decides them. Anyone logged in may open it;
 * someone who follows nobody sees an empty state, and nothing about anyone.
 *
 * WHAT IT SHOWS, AND WHAT IT DOES NOT. Per candidate: how many declarations, and how many
 * await an attestation. A count of work to do for the manager, never a measure of the
 * candidate - no coverage rate, no threshold, nothing a jury could read as an opinion.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\access;
use tool_vae\local\candidate_overview;
use tool_vae\local\competency_gateway;
use tool_vae\local\referent_manager;

require_login();

$PAGE->set_url(new moodle_url('/admin/tool/vae/candidates.php'));
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mycandidates', 'tool_vae'));
$PAGE->set_heading(get_string('mycandidates', 'tool_vae'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mycandidates', 'tool_vae'));

if (!competency_gateway::is_subsystem_enabled()) {
    echo $OUTPUT->notification(
        get_string('errorcompetenciesdisabled', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    die();
}

$candidateids = access::followed_candidates();

if (!$candidateids) {
    echo $OUTPUT->notification(
        get_string('nocandidates', 'tool_vae'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->footer();
    die();
}

// The blocs this reader is referent of (T-23): they may narrow the counts to the declarations on one of them. The choice is
// checked against the reader's own list, so a value from the address bar names nothing they are not referent of. Narrowing
// changes what is counted, never who is listed nor what the reader may read.
$choices = [];
foreach (referent_manager::for_user((int) $USER->id) as $referent) {
    $key = $referent->fichecode . ($referent->ficherang === '' ? '' : '|' . $referent->ficherang);
    $choices[$key] = $referent;
}
$chosen = optional_param('bloc', '', PARAM_RAW);
$chosen = isset($choices[$chosen]) ? $chosen : '';
$fichecode = '';
$ranks = null;
if ($chosen !== '') {
    $fichecode = $choices[$chosen]->fichecode;
    $ranks = referent_manager::ranks_under($fichecode, $choices[$chosen]->ficherang);
}

if ($choices) {
    $options = [get_string('candidatesfilterall', 'tool_vae')];
    foreach ($choices as $key => $referent) {
        $options[$key] = $referent->fichecode . ' — ' . ($referent->ficherang === ''
            ? get_string('referentwhole', 'tool_vae')
            : get_string('referentblocrank', 'tool_vae', $referent->ficherang));
    }
    $form = html_writer::tag('label', s(get_string('candidatesfilter', 'tool_vae')), ['for' => 'candidatesbloc'])
        // The select does not escape its labels, and a rank is whatever the competency tree holds.
        . ' ' . html_writer::select(array_map('s', $options), 'bloc', $chosen, false, ['id' => 'candidatesbloc'])
        . ' ' . html_writer::empty_tag('input', [
            'type' => 'submit', 'class' => 'btn btn-secondary', 'value' => get_string('candidatesfilterapply', 'tool_vae'),
        ]);
    echo html_writer::tag('form', $form, ['method' => 'get', 'action' => $PAGE->url->out_omit_querystring()]);
    if ($chosen !== '') {
        echo html_writer::tag('p', s(get_string('candidatesfiltered', 'tool_vae', $options[$chosen])));
    }
}

// A declaration awaits when no attestation currently stands: never signed, or every signature withdrawn.
$counts = candidate_overview::counts($candidateids, $fichecode, $ranks);

$users = $DB->get_records_list('user', 'id', $candidateids, 'lastname ASC, firstname ASC', '*');

$table = new html_table();
$table->head = [
    get_string('candidate', 'tool_vae'),
    get_string('candidatedeclarations', 'tool_vae'),
    get_string('candidatepending', 'tool_vae'),
];
$table->attributes['class'] = 'generaltable tool-vae-candidates';

foreach ($users as $user) {
    $count = $counts[$user->id] ?? null;
    $table->data[] = [
        html_writer::link(
            new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $user->id]),
            fullname($user)
        ),
        (int) ($count->declarations ?? 0),
        (int) ($count->pending ?? 0),
    ];
}

echo html_writer::table($table);

echo $OUTPUT->footer();
