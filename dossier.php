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
 * A candidate's dossier.
 *
 * This page takes a user id from the request, so it is exactly the shape that leaks when
 * ownership is inferred from an inherited capability. \tool_vae\local\access
 * compares identities in PHP before deciding which capability applies.
 *
 * WHAT THIS PAGE IS AT LOT 4, AND WHAT IT IS NOT. It lists the claims and their
 * attestations, and it carries the links without which attesting and withdrawal would
 * exist in the code and nowhere a person can reach. That is all it is meant to be. The
 * restitution screen proper - grouping by competency, the accessible presentation, the
 * keyboard and screen-reader pass - is lot 9, and nothing here should be mistaken for it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\access;
use tool_vae\local\anchor;
use tool_vae\local\attestation_manager;
use tool_vae\local\backup;
use tool_vae\local\carry_manager;
use tool_vae\local\competency_gateway;
use tool_vae\local\coverage;
use tool_vae\local\deposit_manager;
use tool_vae\local\dossier_importer;
use tool_vae\local\draft_manager;
use tool_vae\local\presenter;
use tool_vae\local\request_manager;
use tool_vae\local\scope_manager;

require_login();

// Cast: the default is the session's own id, a string, and the page compares identities with ===.
$candidateid = (int) optional_param('userid', $USER->id, PARAM_INT);

// THE RIGHT TO READ IS CHECKED BEFORE THE ACCOUNT IS LOOKED UP. The other way round, an id nobody
// holds answered `record not found` where an existing one answered `nopermissions`, and anyone
// could tell which ids are accounts. access refuses an id that is no account exactly as a dossier
// one may not read, so past the check the account exists.
$context = access::require_can_view($candidateid);
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);

$url = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mydossier', 'tool_vae'));
$PAGE->set_heading(fullname($candidate));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('mydossier', 'tool_vae'));

if (!competency_gateway::is_subsystem_enabled()) {
    echo $OUTPUT->notification(
        get_string('errorcompetenciesdisabled', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    die();
}

$isown = ($candidateid === (int) $USER->id);

// Whether this reader may attest. Never true on one's own dossier, whatever the roles
// say: the check that matters is in attestation_manager, this one only decides what to draw.
$mayattest = !$isown && access::can_attest($candidateid);

$claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $candidateid], 'timecreated ASC, id ASC');

// The coverage is the candidate's alone, on their own dossier - decided with the project owner on
// 12 October 2026. Read by a manager or the direction, the same counts become a gauge on a person,
// which is the verdict this plugin refuses to give.
if ($isown && $claims) {
    echo presenter::coverage(coverage::for_candidate($candidateid));
}

// What the candidate says is outside their activity (T-42): read by whoever reads the dossier, apart from the coverage.
$marks = scope_manager::for_candidate($candidateid);
$mayscope = $isown && has_capability('tool/vae:declare', $context);
echo presenter::scope_box($marks, scope_manager::labels($marks), $mayscope, !empty(get_config('tool_vae', 'scopeopen')));

if (!$claims) {
    echo $OUTPUT->notification(
        get_string('nodeclarationyet', 'tool_vae'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    echo presenter::state_legend();
    $requests = new request_manager();
    $carries = new carry_manager();
    foreach ($claims as $claim) {
        echo html_writer::start_tag('section', ['class' => 'tool-vae-claim-block mb-4']);

        // Computed on sight, not read from a flag: the candidate can change the core record
        // at any moment through tool_lp, unlinking raises no event at all, and a dossier that
        // asserted a link the platform no longer holds would mislead the one person it exists
        // to inform. Three queries per claim - measured, not estimated; it was five before the
        // core record stopped being read twice - on a page that shows a handful.
        echo presenter::anchor_warning($claim, anchor::divergence($claim));

        $attestations = attestation_manager::attestations((int) $claim->id);
        $asked = $requests->for_evidence($claim);
        echo html_writer::tag('p', presenter::state_label($attestations, request_manager::count_open((int) $claim->id)));

        $carrynotes = $carries->notes($claim);
        echo presenter::carry_notes($carrynotes);
        echo presenter::declaration($claim, $context);

        echo html_writer::tag('h3', s(get_string('attestation', 'tool_vae')));

        echo presenter::attestations($attestations);
        $carryacts = [];
        foreach (['carriedto', 'carriedfrom'] as $direction) {
            if ($carrynotes->$direction) {
                $carryacts[] = ['time' => (int) $carrynotes->$direction->carriedat, 'act' => $direction];
            }
        }
        echo presenter::chronology_html($claim, $attestations, $asked, $carryacts);

        $standing = attestation_manager::active_attestation((int) $claim->id);

        if ($standing) {
            // Drawn for whoever the rule of attestation_manager::require_can_withdraw() names: the
            // site administrator, a holder of tool/vae:withdrawany, or - when the site setting is on
            // - the signatory for their own attestation. Not tied to the right to attest:
            // a coordinator may withdraw without ever signing.
            $requestopen = ($standing->withdrawreqat ?? null) !== null;
            $issignatory = $standing->attestantid !== null && (int) $standing->attestantid === (int) $USER->id;

            if (!$isown && $issignatory && $requestopen) {
                // A colleague asked this signatory to withdraw: two buttons, accept or refuse.
                echo $OUTPUT->notification(
                    get_string('requestwithdrawalyours', 'tool_vae'),
                    \core\output\notification::NOTIFY_WARNING
                );
                echo $OUTPUT->single_button(
                    new moodle_url('/admin/tool/vae/withdraw.php', ['attestationid' => $standing->id]),
                    get_string('acceptwithdrawal', 'tool_vae'),
                    'get'
                );
                echo $OUTPUT->single_button(
                    new moodle_url('/admin/tool/vae/withdrawrequest.php', [
                        'attestationid' => $standing->id,
                        'action' => 'refuse',
                        'sesskey' => sesskey(),
                    ]),
                    get_string('refusewithdrawal', 'tool_vae'),
                    'post'
                );
            } else if (!$isown && attestation_manager::may_withdraw($standing, $candidateid)) {
                echo $OUTPUT->single_button(
                    new moodle_url('/admin/tool/vae/withdraw.php', ['attestationid' => $standing->id]),
                    get_string('withdraw', 'tool_vae'),
                    'get'
                );
            } else if (
                !$isown && $mayattest && !$issignatory && $standing->attestantid !== null
                    && !attestation_manager::signatory_reachable($standing)
            ) {
                // WHY BEFORE THE PENDING SENTENCE: a request to a signatory who is gone (deleted, or suspended after the
                // request was made) waits for nobody. The reader is sent to the one person who can still withdraw, the
                // administrator, instead of a button that is no longer offered or a sentence that promises an answer.
                echo $OUTPUT->notification(
                    get_string('requestwithdrawalnosignatory', 'tool_vae'),
                    \core\output\notification::NOTIFY_INFO
                );
            } else if (!$isown && $requestopen && $mayattest) {
                echo $OUTPUT->notification(
                    get_string('requestwithdrawalpending', 'tool_vae'),
                    \core\output\notification::NOTIFY_INFO
                );
            } else if (!$isown && attestation_manager::may_request_withdrawal($standing, $candidateid)) {
                echo $OUTPUT->single_button(
                    new moodle_url('/admin/tool/vae/withdrawrequest.php', [
                        'attestationid' => $standing->id,
                        'action' => 'request',
                        'sesskey' => sesskey(),
                    ]),
                    get_string('requestwithdrawal', 'tool_vae'),
                    'post'
                );
            }
        } else if ($mayattest) {
            echo $OUTPUT->single_button(
                new moodle_url('/admin/tool/vae/attest.php', ['evidenceid' => $claim->id]),
                presenter::attest_label($attestations),
                'get'
            );
        }

        // A declaration on a replaced record, with a correspondence decided: its owner may carry it over (T-30,
        // lot 3). Never offered to anyone else, never done by itself.
        if ($isown && $carries->offer($claim)) {
            echo $OUTPUT->single_button(
                new moodle_url('/admin/tool/vae/carry.php', ['evidenceid' => $claim->id]),
                get_string('carrylink', 'tool_vae'),
                'get'
            );
        }

        // The questions and answers on this declaration (T-20): a link for whoever reads, with how many
        // still wait. Never a state of the declaration and never a reason it may not be attested.
        $waiting = request_manager::count_open((int) $claim->id);
        echo html_writer::tag('p', html_writer::link(
            new moodle_url('/admin/tool/vae/precision.php', ['evidenceid' => $claim->id]),
            get_string($waiting ? 'precisionlinkwaiting' : 'precisionlink', 'tool_vae', $waiting)
        ));

        // The supporting pieces (T-13): a link for whoever reads the dossier when a piece is there, and for the candidate
        // also when adding is open.
        $active = \tool_vae\local\piece_manager::active_for_evidence((int) $claim->id);
        $pieces = count($active);
        if ($pieces || ($isown && \tool_vae\local\piece_manager::is_open())) {
            // Those the signature standing does not cover: said where the reader works, whether or not the message arrived.
            $standing = attestation_manager::active_attestation((int) $claim->id);
            $late = 0;
            if ($standing !== null && $pieces) {
                $covered = \tool_vae\local\piece_manager::covered_by((int) $standing->id);
                $late = count(array_filter($active, fn($piece) => !array_key_exists((int) $piece->id, $covered)));
            }
            $line = html_writer::link(
                new moodle_url('/admin/tool/vae/piece.php', ['evidenceid' => $claim->id]),
                get_string($pieces ? 'pieceslinkcount' : 'pieceslink', 'tool_vae', $pieces)
            );
            if ($late) {
                $line .= ' ' . html_writer::tag(
                    'span',
                    s(get_string('pieceslate', 'tool_vae', $late)),
                    ['class' => 'badge bg-warning text-dark']
                );
            }
            echo html_writer::tag('p', $line);
        }

        echo html_writer::end_tag('section');
    }
}

if ($isown) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/declare.php'),
        get_string('declareheading', 'tool_vae'),
        'get'
    );
}

if ($claims && access::can_export($candidateid)) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/export.php', ['userid' => $candidateid]),
        get_string('exportdossier', 'tool_vae'),
        'get'
    );
}

// The sections of the dossier de faisabilité (T-10): one's own, written by the candidate alone.
if ($candidateid === (int) $USER->id && draft_manager::can_write($candidateid)) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/faisabilite.php'),
        get_string('faisabheading', 'tool_vae'),
        'get'
    );
}

// The preparation of the validation dossier (T-11): one's own, not the official dossier of the certifier.
if (
    $candidateid === (int) $USER->id && $claims && draft_manager::can_write($candidateid)
        && draft_manager::validation_open()
) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/validation.php'),
        get_string('validationtitle', 'tool_vae'),
        'get'
    );
}

// The deposits of the dossier de faisabilité (T-21): the candidate's own, and whoever may read them.
if (deposit_manager::can_read($candidateid)) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/deposit.php', ['userid' => $candidateid]),
        get_string('depositheading', 'tool_vae'),
        'get'
    );
}

// The reflective journal (T-14): the candidate's own, nobody else's.
$journalkept = ['candidateid' => $candidateid, 'doctype' => draft_manager::DOCTYPE_REFLEXION];
if (
    $candidateid === (int) $USER->id && draft_manager::can_write($candidateid)
        && (draft_manager::reflection_open() || $DB->record_exists('tool_vae_draft', $journalkept))
) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/journal.php'),
        get_string('reflexiontitle', 'tool_vae'),
        'get'
    );
}

// The deposits of the preparation of the validation dossier (T-11, lot 3): read as deposited, by whoever may read deposits.
$validationdeposits = ['candidateid' => $candidateid, 'doctype' => draft_manager::DOCTYPE_VALIDATION];
if (deposit_manager::can_read($candidateid) && $DB->record_exists('tool_vae_deposit', $validationdeposits)) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/deposit.php', [
            'userid' => $candidateid,
            'doctype' => draft_manager::DOCTYPE_VALIDATION,
        ]),
        get_string('validationdepositheading', 'tool_vae'),
        'get'
    );
}

// The editable copies (T-83): the archive's right, since they leave the platform just as it does.
if ($claims && access::can_export($candidateid)) {
    foreach (['docx' => 'documentdocx', 'odt' => 'documentodt'] as $format => $label) {
        echo $OUTPUT->single_button(
            new moodle_url('/admin/tool/vae/document.php', ['userid' => $candidateid, 'format' => $format]),
            get_string($label, 'tool_vae'),
            'get'
        );
    }
}

if ($claims && backup::can_backup($candidateid)) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/backup.php', ['userid' => $candidateid]),
        get_string('backupdossier', 'tool_vae'),
        'get'
    );
}

if (dossier_importer::can_import()) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/import_dossier.php', ['userid' => $candidateid]),
        get_string('importdossier', 'tool_vae'),
        'get'
    );
}

echo $OUTPUT->footer();
