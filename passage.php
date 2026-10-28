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
 * The correspondences between the competencies of a replaced record and those of its successor (T-30, lot 2).
 *
 * The plugin suggests, a person decides one competency at a time, with a note. Nothing is moved by a decision.
 * admin_externalpage_setup() protects this page; passage_manager asks the right again where it writes.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\local\passage_manager;

$fiche = required_param('fiche', PARAM_ALPHANUMEXT);
$self = new moodle_url('/admin/tool/vae/passage.php', ['fiche' => $fiche]);

admin_externalpage_setup('toolvae_supersession', '', null, $self);

$manager = new passage_manager();
$back = new moodle_url('/admin/tool/vae/supersession.php');

try {
    $rows = $manager->overview($fiche);
    $targets = $manager->targets($fiche);
} catch (moodle_exception $e) {
    redirect($back, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
}

$error = null;
$action = optional_param('action', '', PARAM_ALPHA);
if ($action !== '') {
    require_sesskey();
    $fromid = required_param('fromid', PARAM_INT);
    try {
        $toid = optional_param('toid', 0, PARAM_INT);
        if ($action === 'decide' && $toid > 0) {
            $manager->decide($fromid, $toid, optional_param('note', '', PARAM_TEXT));
            redirect($self, get_string('passagesaved', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
        } else if ($action === 'undo' || $action === 'decide') {
            // The list entry "No equivalent decided" is the same as taking a decision back.
            $manager->undo($fromid);
            redirect($self, get_string('passageundone', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
        }
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

// A competency as shown: its code and its label.
$show = function (stdClass $competency): string {
    return s($competency->idnumber) . ' — ' . format_string($competency->label ?? $competency->shortname);
};

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('passageheading', 'tool_vae', s($fiche)));
echo html_writer::tag('p', get_string('passageintro', 'tool_vae'));
echo html_writer::link($back, get_string('passageback', 'tool_vae'));

if ($error !== null) {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}

$options = ['' => get_string('passagenoequivalent', 'tool_vae')];
foreach ($targets as $id => $target) {
    $options[$id] = $show($target);
}

$table = new html_table();
$table->head = [
    get_string('passagecolfrom', 'tool_vae'),
    get_string('passagecoldeclarations', 'tool_vae'),
    get_string('passagecolproposed', 'tool_vae'),
    get_string('passagecoldecision', 'tool_vae'),
    get_string('passagecoldecide', 'tool_vae'),
];
foreach ($rows as $id => $row) {
    if ($row->proposed === null) {
        $proposed = get_string('passagenoproposal', 'tool_vae');
    } else {
        $proposed = $show($row->proposed) . ' (' . get_string(
            $row->reason === passage_manager::REASON_RANK ? 'passagereasonrank' : 'passagereasonlabel',
            'tool_vae'
        ) . ')';
    }

    if ($row->decision === null) {
        $decision = get_string('passagenodecision', 'tool_vae');
    } else {
        $decision = ($row->target ? $show($row->target) : get_string('passagetargetgone', 'tool_vae'))
            . html_writer::tag('div', get_string('passagedecidedby', 'tool_vae', (object) [
                'who' => $row->decidername === '' ? get_string('passagenobody', 'tool_vae') : s($row->decidername),
                'when' => userdate((int) $row->decision->decidedat, get_string('strftimedatefullshort', 'langconfig')),
            ]), ['class' => 'small text-muted'])
            . ($row->decision->note === '' ? '' : html_writer::tag('div', s($row->decision->note), ['class' => 'small']));
    }

    $selected = $row->decision ? (int) $row->decision->tocompetencyid : ($row->proposed ? (int) $row->proposed->id : '');
    $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $self->out_omit_querystring()])
        . html_writer::input_hidden_params($self)
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'fromid', 'value' => $id])
        // Each field has its own label, hidden to the eye and read by a screen reader: the same words for the
        // list and the note on every row would not say which competency they are about.
        . html_writer::label(
            get_string('passagecoldecide', 'tool_vae') . ' ' . s($row->competency->idnumber),
            'tool_vae_to_' . $id,
            true,
            ['class' => 'visually-hidden']
        )
        . html_writer::select($options, 'toid', $selected, false, ['id' => 'tool_vae_to_' . $id])
        . ' ' . html_writer::label(
            get_string('passagenote', 'tool_vae') . ' ' . s($row->competency->idnumber),
            'tool_vae_note_' . $id,
            true,
            ['class' => 'visually-hidden']
        )
        . html_writer::empty_tag('input', [
            'type' => 'text', 'name' => 'note', 'id' => 'tool_vae_note_' . $id,
            'maxlength' => passage_manager::NOTE_MAXLENGTH, 'size' => 30,
            'placeholder' => get_string('passagenote', 'tool_vae'),
        ])
        . ' ' . html_writer::tag('button', get_string('passagedecide', 'tool_vae'), [
            'type' => 'submit', 'name' => 'action', 'value' => 'decide', 'class' => 'btn btn-primary btn-sm',
        ]);
    if ($row->decision !== null) {
        $form .= ' ' . html_writer::tag('button', get_string('passageundo', 'tool_vae'), [
            'type' => 'submit', 'name' => 'action', 'value' => 'undo', 'class' => 'btn btn-secondary btn-sm',
        ]);
    }
    $form .= html_writer::end_tag('form');

    $table->data[] = [$show($row->competency), $row->declarations, $proposed, $decision, $form];
}
echo html_writer::table($table);

echo $OUTPUT->footer();
