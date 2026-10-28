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
 * The preparation of the validation dossier, record by record, bloc by bloc (T-11, lot 1, D-15).
 *
 * NOT THE OFFICIAL DOSSIER, and the page says so first. Like section.php, the page never takes the candidate from the
 * request: the writer is the candidate. It creates nothing - a section is created when it is opened to be written - and
 * it says what is there, never whether it is enough (D-1): no count, no bar, no "complete".
 *
 * Beside the "describe" section of a bloc, the candidate's own declarations on that bloc, read only: what they have
 * already said and had attested. They are referred to, never copied (D-15).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\attestation_manager;
use tool_vae\local\draft_manager;
use tool_vae\local\presenter;
use tool_vae\local\validation_catalog;
use tool_vae\local\validation_status;

require_login();

$candidateid = (int) $USER->id;
draft_manager::require_can_write($candidateid);
if (!draft_manager::validation_open()) {
    throw new moodle_exception('errorvalidationclosed', 'tool_vae');
}
$context = context_user::instance($candidateid);

$fichecode = optional_param('fiche', '', PARAM_ALPHANUMEXT);

$url = new moodle_url('/admin/tool/vae/validation.php', $fichecode === '' ? [] : ['fiche' => $fichecode]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('validationtitle', 'tool_vae'));
$PAGE->set_heading(fullname($USER));

// The records this candidate declared on: the preparation follows them.
$codes = $DB->get_fieldset_sql(
    'SELECT DISTINCT fichecode FROM {tool_vae_evidence} WHERE candidateid = :c ORDER BY fichecode',
    ['c' => $candidateid]
);

$dossier = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('validationtitle', 'tool_vae'));
// The same words on every screen, the PDF, the copy and the archive (MC-17).
echo html_writer::div(s(get_string('validationlabelling', 'tool_vae')), 'alert alert-info tool-vae-labelling', ['role' => 'note']);
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/admin/tool/vae/deposit.php', ['doctype' => draft_manager::DOCTYPE_VALIDATION]),
    get_string('validationdepositheading', 'tool_vae')
));

if ($fichecode === '') {
    echo html_writer::tag('p', s(get_string('validationintro', 'tool_vae')));
    if (!$codes) {
        echo $OUTPUT->notification(get_string('validationnofiche', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
    } else {
        echo html_writer::tag('p', s(get_string('validationchoose', 'tool_vae')));
        $items = [];
        foreach ($codes as $code) {
            $items[] = html_writer::link(new moodle_url('/admin/tool/vae/validation.php', ['fiche' => $code]), s($code));
        }
        echo html_writer::alist($items);
    }
    echo html_writer::tag('p', html_writer::link($dossier, get_string('validationbacktodossier', 'tool_vae')));
    echo $OUTPUT->footer();
    die();
}

// A record the candidate did not declare on, or one this site does not hold, is not offered.
$structure = in_array($fichecode, $codes, true) ? validation_catalog::structure($fichecode) : null;
if ($structure === null) {
    throw new moodle_exception('errordraftsection', 'tool_vae');
}

echo $OUTPUT->heading(s($fichecode), 3);
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/admin/tool/vae/validation.php'),
    get_string('validationbacktolist', 'tool_vae')
));

if ($structure->overflow) {
    echo $OUTPUT->notification(
        get_string('validationoverflow', 'tool_vae', validation_catalog::MAX_BLOCS),
        \core\output\notification::NOTIFY_INFO
    );
} else if (!$structure->byblocs) {
    echo $OUTPUT->notification(get_string('validationflat', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
}

// The sections already written, by section code.
$written = [];
foreach (
    $DB->get_records('tool_vae_draft', [
        'candidateid' => $candidateid, 'doctype' => draft_manager::DOCTYPE_VALIDATION, 'fichecode' => $fichecode,
    ]) as $row
) {
    $written[$row->section] = $row;
}

// Where each section stands, in words: never a verdict, only whether something is there. The rule - what a draft in progress
// is, whose date the version carries - is validation_status's; the list of the dossier de faisabilité follows it in its own words.
$status = validation_status::for_record($candidateid, $fichecode);
$state = function (string $section) use ($status): string {
    return validation_status::words($status[$section] ?? validation_status::place(null));
};

// A section as a line: its name, where it stands, and the ways in.
$line = function (string $section) use ($written, $fichecode, $state): string {
    $row = $written[$section] ?? null;
    $write = new moodle_url('/admin/tool/vae/section.php', [
        'doctype' => draft_manager::DOCTYPE_VALIDATION, 'fiche' => $fichecode, 'section' => $section,
    ]);
    $out = html_writer::link($write, s(validation_catalog::label($fichecode, $section)))
        . ' - ' . html_writer::span(s($state($section)), 'text-muted');
    if ($row && (int) $row->currentversion > 0) {
        $out .= ' - ' . html_writer::link(
            new moodle_url('/admin/tool/vae/history.php', ['draftid' => $row->id]),
            get_string('historylink', 'tool_vae')
        );
    }

    return html_writer::tag('li', $out);
};

// The declarations of the candidate on some competencies, read only, as a fold.
$declarations = function (array $competencyids) use ($DB, $candidateid, $fichecode, $context): string {
    $params = ['c' => $candidateid, 'f' => $fichecode];
    $where = 'candidateid = :c AND fichecode = :f';
    if ($competencyids) {
        [$insql, $inparams] = $DB->get_in_or_equal($competencyids, SQL_PARAMS_NAMED);
        $where .= " AND competencyid $insql";
        $params += $inparams;
    }
    $claims = $DB->get_records_select('tool_vae_evidence', $where, $params, 'timecreated ASC, id ASC');
    if (!$claims) {
        return html_writer::tag('p', s(get_string('validationnodeclaration', 'tool_vae')), ['class' => 'text-muted']);
    }

    $out = '';
    foreach ($claims as $claim) {
        $summary = userdate((int) $claim->periodstart, get_string('strftimedate', 'langconfig')) . ' - '
            . userdate((int) $claim->periodend, get_string('strftimedate', 'langconfig')) . ' '
            . presenter::state_label(attestation_manager::attestations((int) $claim->id));
        $out .= html_writer::tag(
            'details',
            html_writer::tag('summary', $summary) . presenter::declaration($claim, $context),
            ['class' => 'mb-2']
        );
    }

    return $out;
};

echo html_writer::tag('p', s(get_string('validationintro', 'tool_vae')));

if ($structure->byblocs) {
    $ranks = array_keys($structure->blocs);
    sort($ranks, SORT_NATURAL);
    foreach ($ranks as $rank) {
        $bloc = $structure->blocs[$rank];
        echo $OUTPUT->heading(s(strtoupper((string) $rank) . ' - ' . $bloc->label), 4);
        echo html_writer::tag('h5', s(get_string('validationdeclarations', 'tool_vae')), ['class' => 'h6']);
        echo $declarations($bloc->competencyids);
        $items = '';
        foreach (validation_catalog::PARTS as $part) {
            $items .= $line($rank . '_' . $part);
        }
        echo html_writer::tag('ul', $items);
    }
} else {
    echo html_writer::tag('h5', s(get_string('validationdeclarations', 'tool_vae')), ['class' => 'h6']);
    echo $declarations([]);
    $items = '';
    foreach (validation_catalog::PARTS as $part) {
        $items .= $line('_' . $part);
    }
    echo html_writer::tag('ul', $items);
}

echo $OUTPUT->heading(s(get_string('validpart:synthese', 'tool_vae')), 4);
echo html_writer::tag('ul', $line('_' . validation_catalog::SYNTHESIS));

// What was written for a bloc that has since left the record stays here, readable and exported, never hidden.
$known = array_column(validation_catalog::sections($fichecode), 'section');
$retired = array_diff_key($written, array_flip($known));
if ($retired) {
    echo $OUTPUT->heading(s(get_string('validationretired', 'tool_vae')), 4);
    echo html_writer::tag('p', s(get_string('validationretiredhelp', 'tool_vae')));
    $items = '';
    foreach ($retired as $section => $row) {
        $items .= html_writer::tag('li', s(validation_catalog::label($fichecode, (string) $section))
            . ' - ' . html_writer::span(s($state((string) $section)), 'text-muted')
            . ((int) $row->currentversion > 0 ? ' - ' . html_writer::link(
                new moodle_url('/admin/tool/vae/history.php', ['draftid' => $row->id]),
                get_string('historylink', 'tool_vae')
            ) : ''));
    }
    echo html_writer::tag('ul', $items);
}

echo html_writer::tag('p', html_writer::link($dossier, get_string('validationbacktodossier', 'tool_vae')));
echo $OUTPUT->footer();
