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
 * Back up a candidate's dossier into one's own private files (lot 8 bis).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\backup;
use tool_vae\local\competency_gateway;
use tool_vae\local\exporter;

require_login();

$candidateid = required_param('userid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

// THE RIGHT TO BACK UP IS THE RIGHT TO REIMPORT, for THIS dossier: the capability of this plugin AND the core's right to
// write evidence on the candidate's behalf, as import_dossier.php asks. The first alone read the whole dossier of any
// candidate into the private files of the holder, who could not even open it on the platform (dossier.php refuses them).
// Asked before the account is looked up, for the reason dossier.php gives: an id that is no account gets the very refusal
// an account the holder may not back up gets. A deleted account has lost its context, and is refused the same way.
$context = backup::require_can_backup($candidateid);
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);

$url = new moodle_url('/admin/tool/vae/backup.php', ['userid' => $candidateid]);
$dossierurl = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('backupdossier', 'tool_vae'));
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
    $file = backup::save($candidateid, (int) $USER->id, true, $exporter);
    $warnings = $exporter->warnings();

    if (!$warnings) {
        redirect(
            $dossierurl,
            get_string('backupdossierdone', 'tool_vae', $file->get_filename()),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // A line the schema refuses does not refuse the backup (decision of 27 October 2026), as it never refused the one an
    // uninstall takes. The backup is made, but it will not be reimported as it stands, so the "done" message of the other
    // path, which says to reimport it, would be wrong: the two messages of this one travel as notifications of the session,
    // and the page answers the POST with a redirect, as the other path does. A page shown here would be the answer to a POST,
    // and reloading it would make another backup. exporter::warnings_html() escapes the lines: the value is whatever an
    // administrator typed.
    \core\notification::success(get_string('backupdossierdonewarned', 'tool_vae', $file->get_filename()));
    \core\notification::warning(exporter::warnings_html($warnings, get_string('backupdossierwarning', 'tool_vae')));
    redirect($dossierurl);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('backupdossier', 'tool_vae'));

// Said here, as export.php does, rather than offering a confirmation that exporter::store()
// would answer with a bare exception.
if (!$DB->record_exists('tool_vae_evidence', ['candidateid' => $candidateid])) {
    echo $OUTPUT->notification(
        get_string('errorexportempty', 'tool_vae'),
        \core\output\notification::NOTIFY_INFO
    );
    echo $OUTPUT->continue_button($dossierurl);
    echo $OUTPUT->footer();
    die();
}

echo html_writer::tag('p', s(get_string('backupdossierintro', 'tool_vae')));
echo $OUTPUT->confirm(
    get_string('backupdossierconfirm', 'tool_vae'),
    new moodle_url($url, ['confirm' => 1, 'sesskey' => sesskey()]),
    $dossierurl
);
echo $OUTPUT->footer();
