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
 * Produce the archive of the accompanying structure of a vaeiste: their positionings, in a document of their own.
 *
 * Asked by the direction, or by a manager the direction authorized, for a vaeiste they follow. The page confirms, then produces;
 * the new archive replaces the one in place. The right is checked before the account is looked up, so that a refusal names no one.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\competency_gateway;
use tool_vae\local\structure_archive;

require_login();

$candidateid = (int) required_param('userid', PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

if (!structure_archive::can_issue($candidateid)) {
    throw new \required_capability_exception(
        context_system::instance(),
        'tool/vae:issuestructure',
        'nopermissions',
        ''
    );
}
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);

$url = new moodle_url('/admin/tool/vae/structure.php', ['userid' => $candidateid]);
$dossierurl = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($candidateid));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('structureheading', 'tool_vae'));
$PAGE->set_heading(fullname($candidate));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('structureheading', 'tool_vae'));

if (!competency_gateway::is_subsystem_enabled()) {
    echo $OUTPUT->notification(
        get_string('errorcompetenciesdisabled', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->continue_button($dossierurl);
    echo $OUTPUT->footer();
    die();
}

if ($confirm) {
    require_sesskey();

    try {
        $file = structure_archive::issue($candidateid);
    } catch (\moodle_exception $e) {
        echo $OUTPUT->notification(s($e->getMessage()), \core\output\notification::NOTIFY_ERROR);
        echo $OUTPUT->continue_button($dossierurl);
        echo $OUTPUT->footer();
        die();
    }
    $registered = \tool_vae\local\exporter::registered($file);
    echo $OUTPUT->notification(
        get_string('structuredone', 'tool_vae', (object) ['archive' => $registered->archiveuuid, 'sha256' => $registered->sha256]),
        \core\output\notification::NOTIFY_SUCCESS
    );
    echo html_writer::link(
        moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            'tool_vae',
            structure_archive::FILEAREA,
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            true
        ),
        get_string('structuredownload', 'tool_vae'),
        ['class' => 'btn btn-primary']
    );
    echo $OUTPUT->continue_button($dossierurl);
    echo $OUTPUT->footer();
    die();
}

echo html_writer::tag('p', s(get_string('structureintro', 'tool_vae')));
echo $OUTPUT->notification(get_string('structurenotice', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
if (structure_archive::current($candidateid)) {
    echo $OUTPUT->notification(get_string('structurereplaces', 'tool_vae'), \core\output\notification::NOTIFY_WARNING);
}
echo $OUTPUT->confirm(
    get_string('structureconfirm', 'tool_vae'),
    new moodle_url($url, ['confirm' => 1, 'sesskey' => sesskey()]),
    $dossierurl
);
echo $OUTPUT->footer();
