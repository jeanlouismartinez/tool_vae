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
 * The managers the direction authorized to produce the archive of the structure: authorize one, take the authorization back.
 *
 * For who holds tool/vae:issuestructure and tool/vae:viewany (an admin_externalpage asks for one capability and the page needs two,
 * so the entry is filed only for who holds both, and the page asks for both again). The authorization is once for the manager and
 * holds for every vaeiste they follow; lines are added and never changed.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\local\booklet_authorization;

admin_externalpage_setup('toolvae_bookletauth');
$system = context_system::instance();
require_capability('tool/vae:viewany', $system);
require_capability('tool/vae:issuestructure', $system);

$url = new moodle_url('/admin/tool/vae/booklet_authorize.php');
$action = optional_param('action', '', PARAM_ALPHA);
$managerid = optional_param('managerid', 0, PARAM_INT);

if ($action !== '' && $managerid) {
    require_sesskey();
    try {
        if ($action === 'grant') {
            booklet_authorization::grant($managerid);
        } else if ($action === 'revoke') {
            booklet_authorization::revoke($managerid);
        }
        redirect($url, get_string('bookletauthdone', 'tool_vae'), null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (\moodle_exception $e) {
        redirect($url, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('bookletauthheading', 'tool_vae'));
echo html_writer::tag('p', s(get_string('bookletauthintro', 'tool_vae')));

$managers = booklet_authorization::managers();
if (!$managers) {
    echo html_writer::tag('p', s(get_string('bookletauthnone', 'tool_vae')));
    echo $OUTPUT->footer();
    die();
}

$table = new html_table();
$table->attributes['class'] = 'generaltable tool-vae-bookletauth';
$table->head = [
    get_string('bookletauthcolmanager', 'tool_vae'),
    get_string('bookletauthcolstatus', 'tool_vae'),
    get_string('bookletauthcolaction', 'tool_vae'),
];
foreach ($managers as $manager) {
    $stands = booklet_authorization::stands((int) $manager->id);
    $form = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline'])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'managerid', 'value' => (int) $manager->id])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $stands ? 'revoke' : 'grant'])
        . html_writer::tag(
            'button',
            s(get_string($stands ? 'bookletauthrevoke' : 'bookletauthgrant', 'tool_vae')),
            ['type' => 'submit', 'class' => 'btn btn-secondary btn-sm']
        )
        . html_writer::end_tag('form');
    $table->data[] = [
        s(fullname($manager)),
        s(get_string($stands ? 'bookletauthstands' : 'bookletauthnotyet', 'tool_vae')),
        $form,
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
