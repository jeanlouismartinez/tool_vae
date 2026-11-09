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
 * The work situations of the vaeiste (lot 1b): the records, the situations of a record, the inverse view of a node.
 *
 * THE RIGHT TO READ IS CHECKED AT THE DOOR: access::require_can_view(), then the reading of the preparation of the validation
 * dossier the situations belong to (draft_manager::can_read_doctype(), D-1b-G) - the vaeiste, whoever follows them, the
 * direction; never the site administrator (MC-16). Only the vaeiste opens a new situation (situation_manager::can_write()),
 * by a POST with the session key, while the preparation is open. Nothing here is computed: counts and dates, no total.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\access;
use tool_vae\local\competency_gateway;
use tool_vae\local\draft_manager;
use tool_vae\local\hidden_frameworks;
use tool_vae\local\importer;
use tool_vae\local\note_manager;
use tool_vae\local\note_screen;
use tool_vae\local\selfpos_tree;
use tool_vae\local\situation_manager;
use tool_vae\local\situation_screen;
use tool_vae\local\whereami_panel;

require_login();

$candidateid = optional_param('userid', (int) $USER->id, PARAM_INT);
$fichecode = optional_param('fiche', '', PARAM_RAW_TRIMMED);
$rank = optional_param('rank', null, PARAM_RAW_TRIMMED);

$context = access::require_can_view($candidateid);
// The situations are a part of the preparation: whoever may not read it reads none of them, the site administrator included.
if (!draft_manager::can_read_doctype($candidateid, draft_manager::DOCTYPE_VALIDATION)) {
    throw new moodle_exception('errordraftrefused', 'tool_vae');
}
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);
$canwrite = situation_manager::can_write($candidateid);
$own = (int) $USER->id === $candidateid;
$open = draft_manager::validation_open();

$url = situation_screen::url($candidateid, $fichecode, null, $rank);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$heading = $own
    ? get_string('situationsheading', 'tool_vae')
    : get_string('situationsreadheading', 'tool_vae', fullname($candidate));
// The heading is written as HTML by the core, the title of the page as text: the name of the vaeiste is escaped for the first.
$headinghtml = s($heading);
$PAGE->set_title($heading);
$PAGE->set_heading(fullname($candidate));

$frameworks = new hidden_frameworks();
$manager = new situation_manager();
$source = null;
if ($fichecode !== '') {
    // A record that is not imported, or whose code differs in case, is refused as a dossier one may not read is.
    $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
    if (!$source || $source->fichecode !== $fichecode) {
        throw new moodle_exception('errordraftrefused', 'tool_vae');
    }
}
// A node of the inverse view: a rank of the shape of the record, that its tree holds - or that still carries something of the
// vaeiste after it left the record, which the panel shows apart and leads here. Anything else is refused.
if ($rank !== null) {
    $tree = $source === null ? null : selfpos_tree::for_record($fichecode);
    $known = $tree !== null && selfpos_tree::node($tree, $rank) !== null;
    if (
        $source === null || !preg_match(importer::RANK_PATTERN, $rank)
        || (!$known && !array_key_exists($rank, whereami_panel::counts($candidateid, $fichecode)))
    ) {
        throw new moodle_exception('errordraftrefused', 'tool_vae');
    }
}

// Withdrawing one's own annotation of a deleted situation of this record (D-1b-K): its author alone (note_manager), a POST with
// the session key; an annotation of a situation still there is withdrawn from its own page, never from here.
if ($source !== null && $rank === null && optional_param('action', '', PARAM_ALPHA) === 'withdrawnote' && data_submitted()) {
    require_sesskey();
    $error = null;
    try {
        $note = $DB->get_record(note_manager::TABLE, ['id' => required_param('note', PARAM_INT)]);
        $held = $DB->get_fieldset_select(
            'tool_vae_situation',
            'uuid',
            'candidateid = :candidateid AND fichecode = :fichecode',
            ['candidateid' => $candidateid, 'fichecode' => $fichecode]
        );
        if ($note && in_array((string) $note->targetuuid, $held, true)) {
            throw new moodle_exception('errornotedenied', 'tool_vae');
        }
        (new note_manager())->withdraw(required_param('note', PARAM_INT), [
            'candidateid' => $candidateid,
            'target' => note_manager::TARGET_SITUATION,
            'fichecode' => $fichecode,
        ]);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
    redirect($url, $error, null, $error !== null
        ? \core\output\notification::NOTIFY_ERROR : \core\output\notification::NOTIFY_SUCCESS);
}

// Opening a new situation: the vaeiste alone, a POST with the session key; the page of the new situation follows.
if ($source !== null && optional_param('action', '', PARAM_ALPHA) === 'new' && data_submitted()) {
    require_sesskey();
    $error = null;
    try {
        $situation = $manager->create($candidateid, $fichecode);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
    if ($error !== null) {
        redirect($url, $error, null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect(situation_screen::url($candidateid, $fichecode, (int) $situation->slot));
}

// The vaeiste goes back to their pathway; a reader goes back to the dossier they came from.
$back = $own
    ? html_writer::link(
        new moodle_url('/admin/tool/vae/organisation.php', ['tab' => 'mine']),
        get_string('selfposbackpathway', 'tool_vae')
    )
    : html_writer::link(
        new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]),
        get_string('selfposbackdossier', 'tool_vae')
    );
// The consent to annotations (lot 1b), beside the way back, before any line: whoever passes the door of this page reads the
// preparation (can_read_doctype), the rule of dossier.php; consent.php says who may give it, and who only reads it.
$back .= ' ' . html_writer::link(
    new moodle_url('/admin/tool/vae/consent.php', ['userid' => $candidateid]),
    get_string('consentlink', 'tool_vae')
);

$body = '';
if (!competency_gateway::is_subsystem_enabled()) {
    $body .= $OUTPUT->notification(get_string('errorcompetenciesdisabled', 'tool_vae'), \core\output\notification::NOTIFY_ERROR);
} else if ($source === null) {
    // Without a record: the records offered, and the hidden ones that still hold situations of the vaeiste, said why.
    $records = [];
    foreach ($DB->get_records('tool_vae_source', null, 'fichecode ASC') as $row) {
        if (!$frameworks->is_hidden($row)) {
            $records[(string) $row->fichecode] = null;
        }
    }
    $held = $DB->get_fieldset_sql(
        'SELECT DISTINCT fichecode FROM {tool_vae_situation} WHERE candidateid = :candidateid',
        ['candidateid' => $candidateid]
    );
    foreach ($held as $code) {
        if (!array_key_exists((string) $code, $records)) {
            $row = $DB->get_record('tool_vae_source', ['fichecode' => $code]);
            $records[(string) $code] = $row ? $frameworks->message($row) : null;
        }
    }
    ksort($records, SORT_STRING);
    $body .= situation_screen::records($candidateid, $records);
    $body .= html_writer::tag('p', $back);
} else if ($rank !== null) {
    // The inverse view of a node: its situations and its declarations, in reading.
    $declarations = $DB->get_records(
        'tool_vae_evidence',
        ['candidateid' => $candidateid, 'fichecode' => $fichecode, 'ficherang' => $rank],
        'id ASC'
    );
    $body .= html_writer::tag('p', html_writer::tag('strong', s($fichecode)));
    $body .= situation_screen::inverse(
        $candidateid,
        $fichecode,
        $rank,
        $manager->tagged($candidateid, $fichecode, [$rank]),
        $declarations,
        $context
    );
    $body .= html_writer::tag('p', html_writer::link(
        situation_screen::url($candidateid, $fichecode),
        get_string('situationbacklist', 'tool_vae')
    ));
} else {
    $body .= html_writer::tag('p', html_writer::tag('strong', s($fichecode)));
    $body .= situation_screen::disclaimer($candidateid);
    $hidden = $frameworks->is_hidden($source);
    if ($hidden) {
        // Already escaped by hidden_frameworks::message().
        $body .= html_writer::div($frameworks->message($source), 'alert alert-info');
    }
    if ($canwrite && !$open) {
        $body .= html_writer::div(s(get_string('errorvalidationclosed', 'tool_vae')), 'alert alert-info');
    }
    if ($canwrite && $open && !$hidden) {
        $body .= $OUTPUT->single_button(
            new moodle_url($url, ['action' => 'new']),
            get_string('situationnew', 'tool_vae'),
            'post'
        );
    }
    $situations = $manager->list($candidateid, $fichecode);
    $body .= situation_screen::list($candidateid, $fichecode, $situations);
    // The annotations of the situations deleted since (D-1b-K): they stay, read here, each former situation apart; a new
    // situation on the same slot never takes them, the annotation names its situation by its uuid.
    $held = array_map(fn($row) => (string) $row->uuid, $situations);
    $orphans = array_filter(
        (new note_manager())->for_candidate($candidateid),
        fn($row) => $row->target === note_manager::TARGET_SITUATION && $row->fichecode === $fichecode
            && !in_array((string) $row->targetuuid, $held, true)
    );
    $body .= note_screen::orphans($candidateid, $orphans);
    $body .= whereami_panel::render($candidateid, $fichecode, ['link' => true]);
    $body .= html_writer::tag('p', $back);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($headinghtml);
echo $body;
echo $OUTPUT->footer();
