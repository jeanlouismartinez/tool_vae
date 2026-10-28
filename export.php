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
 * Export a candidate's dossier as the normative archive (lot 8).
 *
 * A confirmation comes first, and carries the notice MC-9 requires BEFORE the archive exists:
 * withdrawing an attestation afterwards has no effect on it. The archive is produced on a
 * POST carrying the session key, then served by tool_vae_pluginfile() from a page that
 * shows the digest this platform registered for it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\access;
use tool_vae\local\competency_gateway;
use tool_vae\local\exporter;

require_login();

// Cast: the default is the session's own id, a string, and the page compares identities with ===.
$candidateid = (int) optional_param('userid', $USER->id, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

// THE RIGHT TO EXPORT IS CHECKED BEFORE THE ACCOUNT IS LOOKED UP, for the reason dossier.php gives.
$context = access::require_can_export($candidateid);
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);

$url = new moodle_url('/admin/tool/vae/export.php', ['userid' => $candidateid]);
$dossierurl = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('exportdossier', 'tool_vae'));
$PAGE->set_heading(fullname($candidate));

if (!competency_gateway::is_subsystem_enabled()) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        get_string('errorcompetenciesdisabled', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    die();
}

if ($confirm) {
    require_sesskey();

    $exporter = new exporter();
    $file = $exporter->export($candidateid);
    $registered = exporter::registered($file);
    $warnings = $exporter->warnings();

    // A page, not a redirect to the file (lot 8 quater): the person who exports is shown the
    // digest this platform registered, so that they can quote it, or the archive's ULID, to
    // whoever later asks whether a copy is the one issued here.
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('exportdossier', 'tool_vae'));
    echo $OUTPUT->notification(
        get_string('exportdone', 'tool_vae', (object) [
            'archive' => $registered->archiveuuid,
            'sha256' => $registered->sha256,
        ]),
        \core\output\notification::NOTIFY_SUCCESS
    );
    // A line the schema refuses does not refuse the archive (decision of 27 October 2026): it is produced, and the person is
    // told here, after the production, what the reimport will do with it, that Moodle cannot correct the line and the site
    // administrator is to be told, and that the editable copy (Word or OpenDocument) remains. The lines name the declaration,
    // the file and the value - of this dossier only. exporter::warnings_html() escapes them: the value is whatever an
    // administrator typed, and the one helper is what the tests hold to it.
    if ($warnings) {
        echo $OUTPUT->notification(
            exporter::warnings_html($warnings, get_string('exportwarning', 'tool_vae')),
            \core\output\notification::NOTIFY_WARNING
        );
    }
    echo html_writer::link(
        moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'tool_vae',
            exporter::FILEAREA,
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            true
        ),
        get_string('exportdownload', 'tool_vae'),
        ['class' => 'btn btn-primary']
    );
    echo $OUTPUT->continue_button($dossierurl);
    echo $OUTPUT->footer();
    die();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('exportdossier', 'tool_vae'));

if (!$DB->record_exists('tool_vae_evidence', ['candidateid' => $candidateid])) {
    echo $OUTPUT->notification(
        get_string('errorexportempty', 'tool_vae'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->continue_button($dossierurl);
    echo $OUTPUT->footer();
    die();
}

// The text speaks to the vaeiste (your declarations, your profile). The direction and the administrator export other people's
// dossiers too: they read the same text in the third person.
$intro = $candidateid === (int) $USER->id
    ? get_string('exportintro', 'tool_vae')
    : get_string('exportintroother', 'tool_vae');
echo html_writer::tag('p', s($intro));
echo $OUTPUT->notification(
    get_string('exportwithdrawalnotice', 'tool_vae'),
    \core\output\notification::NOTIFY_WARNING
);
echo $OUTPUT->confirm(
    get_string('exportconfirm', 'tool_vae'),
    new moodle_url($url, ['confirm' => 1, 'sesskey' => sesskey()]),
    $dossierurl
);
echo $OUTPUT->footer();
