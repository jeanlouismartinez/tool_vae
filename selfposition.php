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
 * Position oneself on the nodes of a record (lot 1a): the records, the tree of one, one node with its form and its history.
 *
 * THE RIGHT TO READ IS CHECKED FIRST, ONCE, at the door (access::require_can_view()): the vaeiste reads and writes their own
 * dossier, the manager who follows them and the direction read it. Only the vaeiste writes (selfpos_manager::can_write()):
 * another reader gets the same screens without a form. The positioning of the vaeiste, never a validation; nothing is computed.
 * Under the history of a node, the annotations of the managers the vaeiste consented to (lot 1b, note_manager), apart.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\form\note_form;
use tool_vae\form\selfpos_form;
use tool_vae\local\access;
use tool_vae\local\competency_gateway;
use tool_vae\local\hidden_frameworks;
use tool_vae\local\note_manager;
use tool_vae\local\note_screen;
use tool_vae\local\selfpos_manager;
use tool_vae\local\selfpos_regard;
use tool_vae\local\selfpos_screen;
use tool_vae\local\selfpos_successor;
use tool_vae\local\selfpos_tree;

require_login();

$candidateid = optional_param('userid', (int) $USER->id, PARAM_INT);
$fichecode = optional_param('fiche', '', PARAM_RAW_TRIMMED);
$rank = optional_param('rank', null, PARAM_RAW_TRIMMED);

$context = access::require_can_view($candidateid);
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);
$canwrite = selfpos_manager::can_write($candidateid);
$own = (int) $USER->id === $candidateid;

$url = selfpos_screen::url($candidateid, $fichecode, $rank);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$heading = $own ? get_string('selfposheading', 'tool_vae') : get_string('selfposreadheading', 'tool_vae', fullname($candidate));
$PAGE->set_title($heading);
$PAGE->set_heading(fullname($candidate));

if (!competency_gateway::is_subsystem_enabled()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading($heading);
    echo $OUTPUT->notification(get_string('errorcompetenciesdisabled', 'tool_vae'), \core\output\notification::NOTIFY_ERROR);
    echo $OUTPUT->footer();
    die();
}

$frameworks = new hidden_frameworks();
$manager = new selfpos_manager();

// Without a record: the records offered, the hidden ones said why. The same list as the declaration offers.
if ($fichecode === '') {
    $sources = [];
    $hidden = [];
    foreach ($DB->get_records('tool_vae_source', null, 'fichecode ASC') as $source) {
        if ($frameworks->is_hidden($source)) {
            $hidden[(string) $source->fichecode] = $frameworks->message($source);
        } else {
            $sources[(string) $source->fichecode] = $source;
        }
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading($heading);
    echo selfpos_screen::records($candidateid, $sources, $hidden);
    echo $OUTPUT->footer();
    die();
}

// A record that is not imported, or whose code differs in case, is refused as a dossier one may not read is.
$source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
if (!$source || $source->fichecode !== $fichecode) {
    throw new moodle_exception('errorselfposdenied', 'tool_vae');
}
// A record hidden since the page was opened: the message of the record, and the text typed given back, never lost.
if ($frameworks->is_hidden($source)) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading($heading);
    echo selfpos_screen::hidden($frameworks->message($source), selfpos_screen::kept_text());
    echo $OUTPUT->footer();
    die();
}

$tree = selfpos_tree::for_record($fichecode);
$current = $manager->current($candidateid, $fichecode);

if ($rank === null) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading($heading);
    echo html_writer::tag('p', html_writer::tag('strong', s($fichecode)));
    echo selfpos_screen::tree($candidateid, $fichecode, $tree, $current, $canwrite);
    // The vaeiste goes back to their pathway; a reader goes back to the dossier they came from.
    echo html_writer::tag('p', $own
        ? html_writer::link(
            new moodle_url('/admin/tool/vae/organisation.php', ['tab' => 'mine']),
            get_string('selfposbackpathway', 'tool_vae')
        )
        : html_writer::link(
            new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]),
            get_string('selfposbackdossier', 'tool_vae')
        ));
    echo $OUTPUT->footer();
    die();
}

$node = selfpos_tree::node($tree, $rank);
if ($node === null) {
    throw new moodle_exception('errorselfposdenied', 'tool_vae');
}

$form = null;
$error = null;
if ($canwrite && $node->positionable) {
    $form = new selfpos_form($url->out(false), ['canwithdraw' => selfpos_manager::standing($current[$rank] ?? null)]);
    if ($form->is_cancelled()) {
        redirect(selfpos_screen::url($candidateid, $fichecode));
    }
    if ($data = $form->get_data()) {
        try {
            $manager->write(
                $candidateid,
                $fichecode,
                $rank,
                (int) $data->level,
                (string) ($data->justification ?? ''),
                empty($data->markdown) ? FORMAT_PLAIN : FORMAT_MARKDOWN
            );
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
        if ($error === null) {
            redirect($url, get_string('selfposrecorded', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    }
}

// The annotations of a positionable node (lot 1b): a manager the vaeiste consented to annotates (note_manager::can_write(),
// read again at each send), never in the vaeiste's line; the author takes back their own annotation. POST with the session key.
$notes = new note_manager();
$noteform = null;
$noteerror = null;
$canannotate = $node->positionable && note_manager::can_write($candidateid);
if ($node->positionable && data_submitted() && optional_param('action', '', PARAM_ALPHA) === 'withdrawnote') {
    require_sesskey();
    try {
        // Only an annotation of this node of this vaeiste, from this page.
        $notes->withdraw(required_param('note', PARAM_INT), [
            'candidateid' => $candidateid,
            'target' => note_manager::TARGET_SELFPOS,
            'targetuuid' => '',
            'fichecode' => $fichecode,
            'slot' => 0,
            'ficherang' => $rank,
        ]);
    } catch (moodle_exception $e) {
        $noteerror = $e->getMessage();
    }
    redirect($url, $noteerror, null, $noteerror !== null
        ? \core\output\notification::NOTIFY_ERROR : \core\output\notification::NOTIFY_SUCCESS);
}
if ($canannotate) {
    $noteform = new note_form($url->out(false));
    if ($data = $noteform->get_data()) {
        try {
            $notes->write(
                $candidateid,
                note_manager::TARGET_SELFPOS,
                $fichecode,
                0,
                $rank,
                (string) ($data->notetext ?? ''),
                empty($data->markdown) ? FORMAT_PLAIN : FORMAT_MARKDOWN
            );
        } catch (moodle_exception $e) {
            $noteerror = $e->getMessage();
        }
        if ($noteerror === null) {
            redirect($url);
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
if ($error !== null) {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}
if ($noteerror !== null) {
    echo $OUTPUT->notification(s($noteerror), \core\output\notification::NOTIFY_ERROR);
}
echo selfpos_screen::node(
    $candidateid,
    $fichecode,
    $node,
    $manager->lines($candidateid, $fichecode, $rank),
    selfpos_regard::for_node($candidateid, $fichecode, $node),
    selfpos_successor::readings($candidateid, $fichecode, $tree)[$rank] ?? [],
    $canwrite,
    $form ? $form->render() : ''
);
// After the history of the node: the annotations, apart from the vaeiste's own lines. A node no screen can name holds none.
if ($node->positionable) {
    echo note_screen::block(
        $candidateid,
        note_manager::TARGET_SELFPOS,
        $fichecode,
        0,
        $rank,
        $notes->for_target($candidateid, note_manager::TARGET_SELFPOS, $fichecode, 0, $rank),
        $canannotate,
        $noteform ? $noteform->render() : ''
    );
}
echo $OUTPUT->footer();
