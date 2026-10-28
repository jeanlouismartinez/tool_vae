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
 * Administration page declaration.
 *
 * The parent category is 'competencies', not 'tools': 'tools' is only created when
 * $hassiteconfig is true, so adding to it makes the entry vanish silently - with a
 * debugging notice, nothing more - for any administrator who is not a site administrator.
 * 'competencies' is created unconditionally in admin/settings/top.php.
 *
 * The external page name must be unique across the entire admin tree, hence the prefix.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('tool_vae_settings', new lang_string('settingspage', 'tool_vae'));
    $settings->add(new admin_setting_configcheckbox(
        'tool_vae/managerwithdrawown',
        new lang_string('managerwithdrawown', 'tool_vae'),
        new lang_string('managerwithdrawown_desc', 'tool_vae'),
        0
    ));
    // The weekly copies of each dossier in its candidate's private files (T-83).
    $settings->add(new admin_setting_configcheckbox(
        'tool_vae/copyenabled',
        new lang_string('copyenabled', 'tool_vae'),
        new lang_string('copyenabled_desc', 'tool_vae'),
        0
    ));
    $settings->add(new admin_setting_configselect(
        'tool_vae/copyformat',
        new lang_string('copyformat', 'tool_vae'),
        new lang_string('copyformat_desc', 'tool_vae'),
        'docx',
        [
            'docx' => new lang_string('documentdocx', 'tool_vae'),
            'odt' => new lang_string('documentodt', 'tool_vae'),
        ]
    ));
    // The reminder of the declarations that wait for an attestation (T-22). Zero: off.
    $settings->add(new admin_setting_configtext(
        'tool_vae/overduedays',
        new lang_string('overduedays', 'tool_vae'),
        new lang_string('overduedays_desc', 'tool_vae'),
        0,
        PARAM_INT
    ));
    // The preparation of the validation dossier (T-11): closed until the administrator opens it. Its titles and helps
    // are provisional until a companion has read them, and the manager's reading of what is deposited comes with the
    // deposit (D-15, MC-14, MC-15).
    $settings->add(new admin_setting_configcheckbox(
        'tool_vae/validationopen',
        new lang_string('validationopen', 'tool_vae'),
        new lang_string('validationopen_desc', 'tool_vae'),
        0
    ));
    // The reflective journal (T-14): closed until the preparation has been read by a companion and the retention of such a
    // text is written (T-84).
    $settings->add(new admin_setting_configcheckbox(
        'tool_vae/reflectionopen',
        new lang_string('reflectionopen', 'tool_vae'),
        new lang_string('reflectionopen_desc', 'tool_vae'),
        0
    ));
    // The label "Outside my activity" (T-42): closed until an accompanying person in VAE has read it and its sentence.
    // A mark already made stays readable and can be taken back.
    $settings->add(new admin_setting_configcheckbox(
        'tool_vae/scopeopen',
        new lang_string('scopeopen', 'tool_vae'),
        new lang_string('scopeopen_desc', 'tool_vae'),
        0
    ));
    // The reformulation aid (T-70): closed until the legal basis, the register, the retention and the contract with the provider
    // are written (T-84), and the policy and the instruction sent have been read. Even open, it takes a capability given by hand.
    $settings->add(new admin_setting_configcheckbox(
        'tool_vae/aiopen',
        new lang_string('aiopen', 'tool_vae'),
        new lang_string('aiopen_desc', 'tool_vae'),
        0
    ));
    // The ceilings of the aid. The code keeps them between bounds whatever is typed here.
    $settings->add(new admin_setting_configtext(
        'tool_vae/aimaxchars',
        new lang_string('aimaxchars', 'tool_vae'),
        new lang_string('aimaxchars_desc', 'tool_vae'),
        \tool_vae\local\ai_assistant::CHARS_DEFAULT,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'tool_vae/aidaily',
        new lang_string('aidaily', 'tool_vae'),
        new lang_string('aidaily_desc', 'tool_vae'),
        \tool_vae\local\ai_assistant::DAILY_DEFAULT,
        PARAM_INT
    ));
    // The addresses of the rules of validation (T-41): closed until an accompanying person in VAE has read the sentence that
    // frames them and its place on the dossier. The addresses can be entered before; nothing is shown to a candidate.
    $settings->add(new admin_setting_configcheckbox(
        'tool_vae/refopen',
        new lang_string('refopen', 'tool_vae'),
        new lang_string('refopen_desc', 'tool_vae'),
        0
    ));
    // The supporting pieces (T-13): closed until amendment 4 of the derogation is validated and the retention of such files
    // is written (T-84). The limits are held by the code: a file no larger than 30 MB in all for a candidate, because an
    // archive holds 50 MiB.
    $settings->add(new admin_setting_configcheckbox(
        'tool_vae/piecesopen',
        new lang_string('piecesopen', 'tool_vae'),
        new lang_string('piecesopen_desc', 'tool_vae'),
        0
    ));
    $settings->add(new \tool_vae\admin\setting_piecebytes(
        'tool_vae/piecemaxbytes',
        new lang_string('piecemaxbytes', 'tool_vae'),
        new lang_string('piecemaxbytes_desc', 'tool_vae'),
        5 * 1048576
    ));
    $settings->add(new \tool_vae\admin\setting_piecebytes(
        'tool_vae/piecemaxpercandidate',
        new lang_string('piecemaxpercandidate', 'tool_vae'),
        new lang_string('piecemaxpercandidate_desc', 'tool_vae'),
        30 * 1048576
    ));
    $settings->add(new admin_setting_configcheckbox(
        'tool_vae/piecenoantivirus',
        new lang_string('piecenoantivirus', 'tool_vae'),
        new lang_string('piecenoantivirus_desc', 'tool_vae'),
        0
    ));
    $ADMIN->add('competencies', $settings);
}

if ($hassiteconfig || has_capability('tool/vae:viewany', context_system::instance())) {
    $ADMIN->add('competencies', new admin_externalpage(
        'toolvae_pending',
        new lang_string('pendingheading', 'tool_vae'),
        new moodle_url('/admin/tool/vae/pending.php'),
        'tool/vae:viewany'
    ));
}

if ($hassiteconfig || has_capability('tool/vae:import', context_system::instance())) {
    $ADMIN->add('competencies', new admin_externalpage(
        'toolvae_supersession',
        new lang_string('supersessionheading', 'tool_vae'),
        new moodle_url('/admin/tool/vae/supersession.php'),
        'tool/vae:import'
    ));
    $ADMIN->add('competencies', new admin_externalpage(
        'toolvae_referents',
        new lang_string('referentheading', 'tool_vae'),
        new moodle_url('/admin/tool/vae/referents.php'),
        'tool/vae:import'
    ));
    $ADMIN->add('competencies', new admin_externalpage(
        'toolvae_refs',
        new lang_string('refheading', 'tool_vae'),
        new moodle_url('/admin/tool/vae/refs.php'),
        'tool/vae:import'
    ));
    $ADMIN->add('competencies', new admin_externalpage(
        'toolvae',
        new lang_string('pluginname', 'tool_vae'),
        new moodle_url('/admin/tool/vae/index.php'),
        'tool/vae:import'
    ));
}
