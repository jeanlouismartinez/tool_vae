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
 * The referents of the blocs of the imported records (T-23, lots 1 and 2).
 *
 * admin_externalpage_setup() protects this page; the same right is asked again where a referent is written
 * (referent_manager). A referent is a pointer for the messages, not a right: the page says so.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\form\referent_form;
use tool_vae\local\referent_manager;
use tool_vae\local\supersession;

admin_externalpage_setup('toolvae_referents');

$self = new moodle_url('/admin/tool/vae/referents.php');
$rows = supersession::overview();
$error = null;

// What each choice stands for: a record, and a bloc of it or the whole record.
[$options, $parts] = referent_manager::choices($rows);

$remove = optional_param('remove', 0, PARAM_INT);
if ($remove > 0) {
    require_sesskey();
    try {
        referent_manager::remove($remove);
        redirect($self, get_string('referentremoved', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

$form = new referent_form($self, ['options' => $options]);
if ($data = $form->get_data()) {
    try {
        [$fichecode, $ficherang] = $parts[(string) $data->bloc] ?? ['', ''];
        $user = $DB->get_record('user', ['username' => (string) $data->username, 'mnethostid' => $CFG->mnet_localhost_id]);
        if (!$user) {
            throw new moodle_exception('errorreferentuser', 'tool_vae');
        }
        referent_manager::add($fichecode, $ficherang, (int) $user->id);
        redirect($self, get_string('referentsaved', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('referentheading', 'tool_vae'));
echo html_writer::tag('p', get_string('referentintro', 'tool_vae'));
if ($error !== null) {
    echo $OUTPUT->notification(s($error), \core\output\notification::NOTIFY_ERROR);
}

if (!$rows) {
    echo $OUTPUT->notification(get_string('supersessionnone', 'tool_vae'), \core\output\notification::NOTIFY_INFO);
} else {
    $referents = referent_manager::for_fiches(array_keys($rows));
    $userids = [];
    foreach ($referents as $list) {
        $userids = array_merge($userids, array_column($list, 'userid'));
    }
    $users = $userids ? $DB->get_records_list('user', 'id', array_unique($userids)) : [];
    $table = new html_table();
    $table->head = [
        get_string('supersessioncolfiche', 'tool_vae'),
        get_string('referentbloc', 'tool_vae'),
        get_string('referentuser', 'tool_vae'),
        '',
    ];
    foreach ($rows as $row) {
        $name = s($row->fichecode) . ' — ' . format_string($row->label);
        if (empty($referents[$row->fichecode])) {
            $table->data[] = [$name, '', get_string('referentnone', 'tool_vae'), ''];
            continue;
        }
        foreach ($referents[$row->fichecode] as $referent) {
            $person = $users[$referent->userid] ?? null;
            $table->data[] = [
                $name,
                $referent->ficherang === ''
                    ? get_string('referentwhole', 'tool_vae')
                    : get_string('referentblocrank', 'tool_vae', s($referent->ficherang)),
                $person ? s(fullname($person)) . ' (' . s($person->username) . ')' : '',
                $OUTPUT->single_button(
                    new moodle_url($self, ['remove' => $referent->id]),
                    get_string('referentremove', 'tool_vae'),
                    'post'
                ),
            ];
            $name = '';
        }
    }
    echo html_writer::table($table);

    echo $OUTPUT->heading(get_string('referentaddheading', 'tool_vae'), 3);
    $form->display();
}

echo $OUTPUT->footer();
