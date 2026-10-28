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
 * Rebuild a candidate's dossier from a normative archive (lot 8, second half).
 *
 * Reached from the dossier of the account the archive goes to: choosing that account is choosing
 * whose dossier to open. The archive is checked whole before anything is written - see
 * \tool_vae\local\dossier_importer.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\form\dossier_import_form;
use tool_vae\local\competency_gateway;
use tool_vae\local\dossier_importer;

require_login();

$candidateid = required_param('userid', PARAM_INT);

// THE RIGHT IS CHECKED BEFORE THE ACCOUNT IS LOOKED UP. The other way round, an id nobody holds
// answered `record not found` where an existing one answered `nopermissions`, and anyone could
// tell which ids are accounts. require_can_import() asks the site-wide right first.
$context = dossier_importer::require_can_import($candidateid);
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);

$url = new moodle_url('/admin/tool/vae/import_dossier.php', ['userid' => $candidateid]);
$dossierurl = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('importdossier', 'tool_vae'));
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

$form = new dossier_import_form($url->out(false));
$form->set_data(['userid' => $candidateid]);

if ($form->is_cancelled()) {
    redirect($dossierurl);
}

if ($form->get_data()) {
    $path = $form->save_temp_file('archive');
    if ($path === false) {
        redirect($url, get_string('errorarchiveunreadable', 'tool_vae'), null, \core\output\notification::NOTIFY_ERROR);
    }

    // The temporary copy goes before any redirect: redirect() ends the script, and a finally
    // block would never run. import_upload() takes it away on every way out, and registers
    // the removal with the shutdown functions of the core for the one way out no block sees:
    // a fatal error. Left behind, it holds the whole dossier, pieces included, for a week.
    $error = null;
    try {
        $result = (new dossier_importer())->import_upload($candidateid, $path);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }

    if ($error !== null) {
        // Shown as HTML. What the message carries from the archive was escaped where it was
        // thrown, or is held to an identifier's pattern; escaping it again would show entities.
        // The refusal of an archive that numbers competencies differently carries a list (<ul>),
        // built and escaped where it is thrown, for the same reason: it is the one message here
        // that names several things, and it names them all.
        redirect($url, $error, null, \core\output\notification::NOTIFY_ERROR);
    }

    // An archive this platform did not issue was accepted, as decided with the project owner, but
    // nothing here vouches for it: the administrator is told so on the page that follows.
    if ($result->foreign) {
        \core\notification::warning(get_string('importdossierforeign', 'tool_vae', $result->archive));
    }

    // And what no archive brings back is said as plainly as what came back: the count above is of what was written, and
    // says nothing of the deposits, the opinions and the questions that stayed behind (or were lost with a reinstall).
    \core\notification::info(get_string('importdossierabsent', 'tool_vae'));

    redirect(
        $dossierurl,
        get_string('importdossierdone', 'tool_vae', $result),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('importdossier', 'tool_vae'));
echo html_writer::tag('p', s(get_string('importdossierintro', 'tool_vae')));
echo $OUTPUT->notification(
    get_string('importdossierwithdrawnotice', 'tool_vae'),
    \core\output\notification::NOTIFY_WARNING
);
$form->display();
echo $OUTPUT->footer();
