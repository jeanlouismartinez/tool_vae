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
 * NOT THE OFFICIAL DOSSIER, and the page says so first. For writing, like section.php, the page never takes the candidate
 * from the request: the writer is the candidate. A reader names the vaeiste (userid) and reads, never writes (D-1b-G): who
 * may view the dossier reads the latest version of each section and, apart, the draft in progress (D-1b-H); the site
 * administrator is refused. It creates nothing - a section is created when it is opened to be written - and
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

use tool_vae\local\access;
use tool_vae\local\attestation_manager;
use tool_vae\local\draft_manager;
use tool_vae\local\presenter;
use tool_vae\local\validation_catalog;
use tool_vae\local\validation_status;
use tool_vae\local\whereami_panel;

require_login();

$candidateid = (int) optional_param('userid', $USER->id, PARAM_INT);
// A reader who is not the author (D-1b-G): whoever may view the dossier reads the preparation, open or closed, and writes
// nothing. The author writes, and only while the preparation is open.
$reading = $candidateid !== (int) $USER->id;
if ($reading) {
    access::require_can_view($candidateid);
    if (!draft_manager::can_read_doctype($candidateid, draft_manager::DOCTYPE_VALIDATION)) {
        throw new moodle_exception('errordraftrefused', 'tool_vae');
    }
} else {
    draft_manager::require_can_write($candidateid);
    if (!draft_manager::validation_open()) {
        throw new moodle_exception('errorvalidationclosed', 'tool_vae');
    }
}
$context = context_user::instance($candidateid);
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);
// The parameters every link of this page carries: the reader stays on the dossier they read.
$base = $reading ? ['userid' => $candidateid] : [];

$fichecode = optional_param('fiche', '', PARAM_ALPHANUMEXT);

$url = new moodle_url('/admin/tool/vae/validation.php', $base + ($fichecode === '' ? [] : ['fiche' => $fichecode]));
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('validationtitle', 'tool_vae'));
$PAGE->set_heading(fullname($candidate));

// The records this candidate declared on: the preparation follows them.
$codes = $DB->get_fieldset_sql(
    'SELECT DISTINCT fichecode FROM {tool_vae_evidence} WHERE candidateid = :c ORDER BY fichecode',
    ['c' => $candidateid]
);

$dossier = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);
// The words of the page are the author's own ("my dossier", "my declarations"); a reader reads them in the third person.
$back = $reading ? 'faisabbacktodossier' : 'validationbacktodossier';

echo $OUTPUT->header();
if ($reading) {
    echo $OUTPUT->heading(s(get_string('validationreadheading', 'tool_vae', fullname($candidate))));
    // Who wrote this, for whom, and what it is not: the reader reads it as the vaeiste's own word.
    echo html_writer::div(s(get_string('validationreadnotice', 'tool_vae')), 'alert alert-info', ['role' => 'note']);
} else {
    echo $OUTPUT->heading(get_string('validationtitle', 'tool_vae'));
}
// The same words on every screen, the PDF, the copy and the archive (MC-17).
echo html_writer::div(s(get_string('validationlabelling', 'tool_vae')), 'alert alert-info tool-vae-labelling', ['role' => 'note']);
if (!$reading) {
    echo html_writer::tag('p', html_writer::link(
        new moodle_url('/admin/tool/vae/deposit.php', ['doctype' => draft_manager::DOCTYPE_VALIDATION]),
        get_string('validationdepositheading', 'tool_vae')
    ));
}

if ($fichecode === '') {
    if (!$reading) {
        echo html_writer::tag('p', s(get_string('validationintro', 'tool_vae')));
    }
    if (!$codes) {
        $none = $reading ? 'validationnodeclaration' : 'validationnofiche';
        echo $OUTPUT->notification(get_string($none, 'tool_vae'), \core\output\notification::NOTIFY_INFO);
    } else {
        if (!$reading) {
            echo html_writer::tag('p', s(get_string('validationchoose', 'tool_vae')));
        }
        $items = [];
        foreach ($codes as $code) {
            $items[] = html_writer::link(new moodle_url('/admin/tool/vae/validation.php', $base + ['fiche' => $code]), s($code));
        }
        echo html_writer::alist($items);
    }
    echo html_writer::tag('p', html_writer::link($dossier, get_string($back, 'tool_vae')));
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
    new moodle_url('/admin/tool/vae/validation.php', $base),
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

// Where the vaeiste stands on this record, in reading, above the blocs (lot 1b, spec 1a § 6.6): for the author and for a
// reader, whom the link tells to read.
echo whereami_panel::render($candidateid, $fichecode, ['link' => true]);

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

// For a reader, what a section holds (D-1b-H): the latest version saved and, apart, the draft in progress, said to be one.
// The text is the vaeiste's free text, shown as plain text.
$manager = new draft_manager();
$texts = function (?stdClass $row) use ($manager, $context): string {
    if (!$row) {
        return '';
    }
    $plain = fn(string $text): string => format_text($text, FORMAT_PLAIN, ['context' => $context, 'filter' => false]);
    $out = '';
    if ((int) $row->currentversion > 0) {
        $latest = $manager->version((int) $row->id, (int) $row->currentversion);
        $out .= html_writer::div($plain((string) $latest->content), 'mb-2');
    }
    if ($row->working !== null && trim((string) $row->working) !== '') {
        $out .= html_writer::div(
            html_writer::tag('p', s(get_string('situationworkingnotice', 'tool_vae')), ['class' => 'text-muted small mb-1'])
                . $plain((string) $row->working),
            'border-start ps-2 mb-2'
        );
    }

    return $out;
};

// A section as a line: its name, where it stands, and the ways in; for a reader, no way to write and the text itself.
$line = function (string $section) use ($written, $fichecode, $state, $reading, $texts): string {
    $row = $written[$section] ?? null;
    $label = s(validation_catalog::label($fichecode, $section));
    if (!$reading) {
        $label = html_writer::link(new moodle_url('/admin/tool/vae/section.php', [
            'doctype' => draft_manager::DOCTYPE_VALIDATION, 'fiche' => $fichecode, 'section' => $section,
        ]), $label);
    }
    $out = $label . ' - ' . html_writer::span(s($state($section)), 'text-muted');
    if ($row && (int) $row->currentversion > 0) {
        $out .= ' - ' . html_writer::link(
            new moodle_url('/admin/tool/vae/history.php', ['draftid' => $row->id]),
            get_string('historylink', 'tool_vae')
        );
    }
    if ($reading) {
        $out .= $texts($row);
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

if (!$reading) {
    echo html_writer::tag('p', s(get_string('validationintro', 'tool_vae')));
}
$declared = s(get_string($reading ? 'candidatedeclarations' : 'validationdeclarations', 'tool_vae'));

if ($structure->byblocs) {
    $ranks = array_keys($structure->blocs);
    sort($ranks, SORT_NATURAL);
    foreach ($ranks as $rank) {
        $bloc = $structure->blocs[$rank];
        echo $OUTPUT->heading(s(strtoupper((string) $rank) . ' - ' . $bloc->label), 4);
        echo html_writer::tag('h5', $declared, ['class' => 'h6']);
        echo $declarations($bloc->competencyids);
        $items = '';
        foreach (validation_catalog::PARTS as $part) {
            $items .= $line($rank . '_' . $part);
        }
        echo html_writer::tag('ul', $items);
    }
} else {
    echo html_writer::tag('h5', $declared, ['class' => 'h6']);
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
    if (!$reading) {
        echo html_writer::tag('p', s(get_string('validationretiredhelp', 'tool_vae')));
    }
    $items = '';
    foreach ($retired as $section => $row) {
        $items .= html_writer::tag('li', s(validation_catalog::label($fichecode, (string) $section))
            . ' - ' . html_writer::span(s($state((string) $section)), 'text-muted')
            . ((int) $row->currentversion > 0 ? ' - ' . html_writer::link(
                new moodle_url('/admin/tool/vae/history.php', ['draftid' => $row->id]),
                get_string('historylink', 'tool_vae')
            ) : '')
            . ($reading ? $texts($row) : ''));
    }
    echo html_writer::tag('ul', $items);
}

echo html_writer::tag('p', html_writer::link($dossier, get_string($back, 'tool_vae')));
echo $OUTPUT->footer();
