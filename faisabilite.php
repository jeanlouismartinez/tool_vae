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
 * The sections of one's own dossier de faisabilité, and where each stands (T-10, lot 1).
 *
 * Like section.php, the page never takes the candidate from the request: the writer is the
 * candidate, and the subject is $USER. It creates nothing - a section is created when it is opened
 * to be written - and it says what is there, never whether it is enough (D-1).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\draft_manager;
use tool_vae\local\faisabilite_catalog;
use tool_vae\local\faisabilite_overview;

require_login();

$candidateid = (int) $USER->id;
draft_manager::require_can_write($candidateid);

$url = new moodle_url('/admin/tool/vae/faisabilite.php');
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($candidateid));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('faisabheading', 'tool_vae'));
$PAGE->set_heading(fullname($USER));

$overview = faisabilite_overview::for_candidate($candidateid);

// The words that say where a place stands, from what faisabilite_overview returns for it.
$statewords = function (array $place): string {
    switch ($place['state']) {
        case faisabilite_overview::STATE_DRAFT:
            return get_string('faisabstatedraft', 'tool_vae');
        case faisabilite_overview::STATE_VERSION:
            return get_string('faisabstateversion', 'tool_vae', $place['version']);
        case faisabilite_overview::STATE_VERSION_DRAFT:
            return get_string('faisabstateversiondraft', 'tool_vae', $place['version']);
        case faisabilite_overview::STATE_ACCOUNT:
            return get_string('faisabstateaccount', 'tool_vae');
        default:
            return get_string('faisabstateempty', 'tool_vae');
    }
};

$table = new html_table();
$table->attributes['class'] = 'generaltable tool-vae-faisabilite';
$table->caption = get_string('faisabcaption', 'tool_vae');
$table->captionhide = true;
$table->head = [
    get_string('faisabcolsection', 'tool_vae'),
    get_string('faisabcolstate', 'tool_vae'),
    get_string('faisabcollast', 'tool_vae'),
    get_string('faisabcolactions', 'tool_vae'),
];
$table->colclasses = ['', '', '', ''];

foreach ($overview as $code => $entry) {
    $label = get_string('faisabsection:' . $code, 'tool_vae');

    if (!$entry['open']) {
        $table->data[] = [
            s($label),
            s(get_string('faisabstateupcoming', 'tool_vae')),
            '',
            '',
        ];
        continue;
    }

    foreach ($entry['places'] as $slot => $place) {
        $writeurl = new moodle_url('/admin/tool/vae/section.php', ['section' => $code, 'slot' => $slot]);
        $name = faisabilite_catalog::label($code, $slot);

        $actions = [html_writer::link($writeurl, get_string(
            $place['state'] === faisabilite_overview::STATE_ACCOUNT ? 'faisabview' : 'faisabwrite',
            'tool_vae',
            $name
        ))];
        if ($place['draftid']) {
            $actions[] = html_writer::link(
                new moodle_url('/admin/tool/vae/history.php', ['draftid' => $place['draftid']]),
                get_string('faisabhistory', 'tool_vae', $name)
            );
        }

        $table->data[] = [
            s($name),
            s($statewords($place)),
            $place['modified'] ? s(userdate($place['modified'], get_string('strftimedatetimeshort'))) : '',
            implode(' · ', $actions),
        ];
    }

    if ($entry['repeated']) {
        if ($entry['places'] === []) {
            $table->data[] = [s($label), s(get_string('faisabstateempty', 'tool_vae')), '', ''];
        }
        if ($entry['nextslot'] !== null) {
            $table->data[] = [
                '',
                '',
                '',
                html_writer::link(
                    new moodle_url('/admin/tool/vae/section.php', ['section' => $code, 'slot' => $entry['nextslot']]),
                    get_string('faisabadd', 'tool_vae', $label)
                ),
            ];
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('faisabheading', 'tool_vae'));
echo html_writer::tag('p', s(get_string('faisabintro', 'tool_vae')));
echo html_writer::table($table);
echo $OUTPUT->single_button(
    new moodle_url('/admin/tool/vae/deposit.php'),
    get_string('depositheading', 'tool_vae'),
    'get'
);
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]),
    get_string('faisabbacktodossier', 'tool_vae')
));
echo $OUTPUT->footer();
