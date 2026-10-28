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
 * Import a certification record from a file.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\form\import_form;
use tool_vae\local\competency_gateway;
use tool_vae\local\importer;

admin_externalpage_setup('toolvae');
require_capability('tool/vae:import', context_system::instance());

$PAGE->set_url(new moodle_url('/admin/tool/vae/import.php'));
$PAGE->set_title(get_string('importheading', 'tool_vae'));

$index = new moodle_url('/admin/tool/vae/index.php');

$context = context_system::instance();

if (!competency_gateway::is_subsystem_enabled()) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('importheading', 'tool_vae'));
    echo $OUTPUT->notification(
        get_string('errorcompetenciesdisabled', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    die();
}

// THE PERMISSION IS CHECKED BEFORE THE FORM, NOT INSIDE THE IMPORT. Creating a framework needs
// a capability of the core that a custom role holding tool/vae:import need not have;
// finding that out mid-import leaves a ghost record on the administration screen - failed, zero
// competencies - for a permission problem nobody named.
if (!competency_gateway::can_manage_frameworks($context)) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('importheading', 'tool_vae'));
    echo $OUTPUT->notification(
        get_string('errorimportnocompetencymanage', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    die();
}

$form = new import_form($PAGE->url->out(false));

if ($form->is_cancelled()) {
    redirect($index);
}

if ($data = $form->get_data()) {
    $content = $form->get_file_content('csvfile');

    if ($content === false || trim((string) $content) === '') {
        redirect(
            $PAGE->url,
            get_string('errorimportnofile', 'tool_vae'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    try {
        $rows = importer::rows_from_csv($content, $data->encoding ?? 'UTF-8');

        $result = (new importer())->import((object) [
            'fichecode' => $data->fichecode,
            'fichetype' => $data->fichetype,
            'name' => $data->name,
            'sourceurl' => $data->sourceurl ?? '',
            'sourceupdated' => $data->sourceupdated,
            'licence' => $data->licence,
            'attribution' => $data->attribution,
        ], $rows);

        redirect(
            $index,
            get_string('importdone', 'tool_vae', $result)
                // A framework that was already there is taken over, not made: the figures count what this import changed in it.
                . (!empty($result->adopted) ? ' ' . get_string('importadopted', 'tool_vae') : '')
                . ($result->moved ? ' ' . get_string('importmoved', 'tool_vae', $result->moved) : ''),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        // The notification renders the message as HTML. What it carries from the file was escaped
        // where the exception was thrown; escaping it again here would show the entities.
        redirect($PAGE->url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('importheading', 'tool_vae'));
$form->display();
echo $OUTPUT->footer();
