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
 * The consent of the vaeiste to the annotation of a manager who follows them (lot 1b).
 *
 * THE RIGHT TO READ IS CHECKED FIRST, ONCE, at the door (access::require_can_view()). The vaeiste sees the people who may
 * receive their consent, the state of each, a button to give or take it back (sent, with the key of the session), and the
 * dated history; another reader sees the state and the history, without a button. The consent allows annotation only, never
 * a change to the text of the vaeiste. Nothing is computed here: no total, no rate.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\access;
use tool_vae\local\consent_manager;

require_login();

$candidateid = optional_param('userid', (int) $USER->id, PARAM_INT);

$context = access::require_can_view($candidateid);
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);
$canmanage = consent_manager::can_manage($candidateid);
$consent = new consent_manager();

$url = new moodle_url('/admin/tool/vae/consent.php', ['userid' => $candidateid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$heading = get_string('consentheading', 'tool_vae');
$PAGE->set_title($heading);
$PAGE->set_heading(fullname($candidate));

// A send, and only a send (POST): an address followed, with whatever parameters, writes nothing. The key of the session
// first, then the vaeiste alone; anybody else is refused, and nothing is written.
$action = optional_param('action', '', PARAM_ALPHA);
if ($action !== '' && data_submitted()) {
    require_sesskey();
    if (!$canmanage || !in_array($action, ['give', 'withdraw'], true)) {
        throw new moodle_exception('errorconsentdenied', 'tool_vae');
    }
    $managerid = required_param('managerid', PARAM_INT);
    try {
        if ($action === 'give') {
            $consent->give($candidateid, $managerid);
            $done = get_string('consentgiven', 'tool_vae');
        } else {
            $consent->withdraw($candidateid, $managerid);
            $done = get_string('consentwithdrawn', 'tool_vae');
        }
    } catch (moodle_exception $e) {
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($url, $done, null, \core\output\notification::NOTIFY_SUCCESS);
}

// The name of a person, as text; an account that is gone is said so. A closure: the page may be played more than once.
$nameof = function (?int $userid): string {
    $user = $userid ? core_user::get_user($userid) : false;

    return ($user && empty($user->deleted)) ? fullname($user) : get_string('unknownuser');
};

$current = $consent->current($candidateid);
$eligible = $canmanage ? $consent->eligible($candidateid) : [];
// The vaeiste sees whoever may receive it, and whoever holds it still: a consent that stands is always taken back.
$people = array_unique(array_merge($eligible, array_keys($current)));
sort($people);

$body = html_writer::tag('p', s(get_string('consentintro', 'tool_vae')));
if (!$people) {
    $body .= html_writer::tag('p', s(get_string('consentnone', 'tool_vae')));
} else {
    $table = new html_table();
    $table->head = [get_string('fullnameuser'), get_string('status')];
    if ($canmanage) {
        $table->head[] = '';
    }
    foreach ($people as $personid) {
        $state = isset($current[$personid]) ? (string) $current[$personid]->state : '';
        $label = $state === consent_manager::GIVEN ? 'consentgiven'
            : ($state === consent_manager::WITHDRAWN ? 'consentwithdrawn' : 'consentnone');
        $row = [s($nameof($personid)), s(get_string($label, 'tool_vae'))];
        if ($canmanage) {
            $button = '';
            if ($state === consent_manager::GIVEN) {
                $button = $OUTPUT->single_button(
                    new moodle_url($url, ['action' => 'withdraw', 'managerid' => $personid]),
                    get_string('consentwithdraw', 'tool_vae'),
                    'post'
                );
            } else if (in_array($personid, $eligible, true)) {
                $button = $OUTPUT->single_button(
                    new moodle_url($url, ['action' => 'give', 'managerid' => $personid]),
                    get_string('consentgive', 'tool_vae'),
                    'post'
                );
            }
            $row[] = $button;
        }
        $table->data[] = $row;
    }
    $body .= html_writer::table($table);
}

$history = $consent->history($candidateid);
if ($history) {
    $body .= html_writer::tag('h3', s(get_string('consenthistory', 'tool_vae')));
    $table = new html_table();
    $table->head = [get_string('date'), get_string('fullnameuser'), get_string('status')];
    foreach ($history as $line) {
        $table->data[] = [
            s(userdate((int) $line->timecreated, get_string('strftimedatetime', 'langconfig'))),
            s($line->restored
                ? get_string('consentrestored', 'tool_vae')
                : $nameof($line->managerid ? (int) $line->managerid : null)),
            s(get_string($line->state === consent_manager::GIVEN ? 'consentgiven' : 'consentwithdrawn', 'tool_vae')),
        ];
    }
    $body .= html_writer::table($table);
}

$body .= html_writer::tag('p', html_writer::link(
    new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]),
    get_string('selfposbackdossier', 'tool_vae')
));

echo $OUTPUT->header();
echo $OUTPUT->heading(s($heading));
echo $body;
echo $OUTPUT->footer();
