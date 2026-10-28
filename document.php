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
 * Download an editable copy of a candidate's dossier, as a Word or an OpenDocument file (T-83).
 *
 * The right is the archive's: the copy leaves the platform just as the archive does, so the
 * candidate takes their own and the direction any, and a manager who only follows a candidate
 * takes none. Nothing is stored; the file is built and sent.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php');

use tool_vae\event\dossier_document_issued;
use tool_vae\local\access;
use tool_vae\local\competency_gateway;
use tool_vae\local\document_writer;
use tool_vae\local\dossier_document;
use tool_vae\local\draft_manager;

require_login();

// Cast: the default is the session's own id, a string, and the page compares identities with ===.
$candidateid = (int) optional_param('userid', $USER->id, PARAM_INT);
$format = required_param('format', PARAM_ALPHA);

// THE RIGHT IS CHECKED BEFORE THE ACCOUNT IS LOOKED UP, for the reason dossier.php gives.
$context = access::require_can_export($candidateid);
if (!in_array($format, dossier_document::FORMATS, true)) {
    throw new moodle_exception('invalidparameter', 'debug');
}

$dossierurl = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);

if (!competency_gateway::is_subsystem_enabled()) {
    $PAGE->set_url(new moodle_url('/admin/tool/vae/document.php', ['userid' => $candidateid, 'format' => $format]));
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('standard');
    $PAGE->set_title(get_string('documentdownload', 'tool_vae'));
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('errorcompetenciesdisabled', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    die();
}

if (!$DB->record_exists('tool_vae_evidence', ['candidateid' => $candidateid])) {
    redirect($dossierurl, get_string('errorexportempty', 'tool_vae'), null, \core\output\notification::NOTIFY_INFO);
}

// What the person who downloads may read, said for each part of the copy (MC-11, D-15 (3), MC-25).
// Identity, contact and status: the candidate and whoever holds tool/vae:identityview. The preparation of the validation
// dossier: its author alone, so the candidate and nobody else. The titles of the pieces: whoever may open them, as on
// piece.php - not whoever reads the identity. The saved sections of the dossier de faisabilite: whoever may read them,
// as on history.php.
$document = dossier_document::for_candidate(
    $candidateid,
    time(),
    draft_manager::can_read_fields($candidateid),
    null,
    (int) $USER->id === $candidateid,
    dossier_document::may_open_pieces_of($candidateid),
    draft_manager::can_read($candidateid)
);
$bytes = $format === 'odt' ? $document['writer']->odt() : $document['writer']->docx();

dossier_document_issued::create([
    'context' => $context,
    'relateduserid' => $candidateid,
    'other' => [
        'declarations' => $document['declarations'],
        'planned' => 0,
        'format' => $format,
        'sha256' => $document['digest'],
    ],
])->trigger();

send_file(
    $bytes,
    'dossier-' . gmdate('Y-m-d') . '.' . $format,
    0,
    0,
    true,
    true,
    $format === 'odt' ? document_writer::ODT_MIME : document_writer::DOCX_MIME
);
