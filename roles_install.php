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
 * Create the VAE roles from the presets shipped with the plugin.
 *
 * For whoever may manage roles. Nothing is created by looking at this page: each role is asked for, one by one, and
 * confirmed. A role that already exists is shown as such and never touched. Who holds a role, and where, is still decided
 * in Moodle (a cohort role for the managers, the role of the vaeiste, the role of the management).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\local\role_installer;

admin_externalpage_setup('toolvae_roles');
require_capability('moodle/role:manage', context_system::instance());

$url = new moodle_url('/admin/tool/vae/roles_install.php');
$presets = role_installer::presets();
$shortname = optional_param('shortname', '', PARAM_ALPHANUMEXT);

if ($shortname !== '' && isset($presets[$shortname]) && optional_param('confirm', 0, PARAM_BOOL)) {
    require_sesskey();
    try {
        role_installer::create($shortname);
        redirect(
            $url,
            get_string('rolescreated', 'tool_vae', $presets[$shortname]->name),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (moodle_exception $e) {
        redirect($url, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('rolesheading', 'tool_vae'));

if ($shortname !== '' && isset($presets[$shortname])) {
    // The confirmation: what will be created, with which rights, and that nothing existing is changed.
    $preset = $presets[$shortname];
    $rights = array_map(
        fn($capability) => html_writer::tag('li', s(get_capability_string($capability)) . ' (' . s($capability) . ')'),
        $preset->rights
    );
    echo html_writer::tag('p', s(get_string('rolesconfirmwhat', 'tool_vae', $preset->name)));
    echo html_writer::tag('ul', implode('', $rights));
    echo $OUTPUT->confirm(
        get_string('rolesconfirm', 'tool_vae', $preset->name),
        new single_button(
            new moodle_url($url, ['shortname' => $shortname, 'confirm' => 1, 'sesskey' => sesskey()]),
            get_string('rolescreate', 'tool_vae'),
            'post',
            single_button::BUTTON_PRIMARY
        ),
        $url
    );
    echo $OUTPUT->footer();
    die();
}

echo html_writer::tag('p', s(get_string('rolesintro', 'tool_vae')));

foreach ([false, true] as $optional) {
    $subset = array_filter($presets, fn($preset) => $preset->optional === $optional);
    if (!$subset) {
        continue;
    }
    echo $OUTPUT->heading(get_string($optional ? 'rolesoptional' : 'rolesbase', 'tool_vae'), 3);
    if ($optional) {
        echo html_writer::tag('p', s(get_string('rolesoptionalwarning', 'tool_vae')), ['class' => 'text-muted small']);
    }
    $table = new html_table();
    $table->attributes['class'] = 'generaltable tool-vae-roles';
    $table->head = [
        get_string('rolescolrole', 'tool_vae'),
        get_string('rolescolrights', 'tool_vae'),
        get_string('rolescolstate', 'tool_vae'),
    ];
    foreach ($subset as $preset) {
        $rights = implode(', ', array_map(fn($capability) => s(get_capability_string($capability)), $preset->rights));
        if (role_installer::exists($preset->shortname)) {
            $state = html_writer::tag('span', s(get_string('rolesexists', 'tool_vae')), ['class' => 'small text-muted']);
        } else {
            $state = $OUTPUT->single_button(
                new moodle_url($url, ['shortname' => $preset->shortname]),
                get_string('rolescreatenamed', 'tool_vae', $preset->name),
                'get'
            );
        }
        $table->data[] = [
            s($preset->name) . html_writer::div(s($preset->description), 'small text-muted'),
            $rights,
            $state,
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
