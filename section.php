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
 * Write one section of one's own document (T-81, lot 3).
 *
 * Like declare.php, the page never takes the candidate from the request: the writer is the
 * candidate, and the subject is $USER. What the request names is the section.
 *
 * The draft in progress is saved on its own every few seconds by autosave.php; a version is only
 * written when the candidate presses the button.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\form\fields_form;
use tool_vae\form\section_form;
use tool_vae\local\ai_assistant;
use tool_vae\local\draft_manager;
use tool_vae\local\faisabilite_catalog;
use tool_vae\local\faisabilite_fields;
use tool_vae\local\validation_catalog;

require_login();

$doctype = optional_param('doctype', draft_manager::DOCTYPE_FAISABILITE, PARAM_ALPHANUMEXT);
$sectioncode = required_param('section', PARAM_ALPHANUMEXT);
$slot = optional_param('slot', 0, PARAM_INT);
// The record, for the preparation of the validation dossier (T-11); empty for the dossier de faisabilité.
$fichecode = optional_param('fiche', '', PARAM_ALPHANUMEXT);

$candidateid = (int) $USER->id;
draft_manager::require_can_write($candidateid);

// A section of the dossier de faisabilité that the catalogue lists but does not open (none today).
if (
    $doctype === draft_manager::DOCTYPE_FAISABILITE
        && faisabilite_catalog::valid_address($sectioncode, $slot)
        && !faisabilite_catalog::is_open($sectioncode)
) {
    throw new moodle_exception('errordraftsection', 'tool_vae');
}

$isfaisabilite = $doctype === draft_manager::DOCTYPE_FAISABILITE;
$kind = $isfaisabilite ? faisabilite_catalog::kind($sectioncode) : null;

// The contact details are read from the account, never kept here: no section row, no version
// (T-10, lot 3, MC-10). The page shows them and says where to change them.
if ($kind === faisabilite_catalog::KIND_ACCOUNT) {
    $PAGE->set_url(new moodle_url('/admin/tool/vae/section.php', ['section' => $sectioncode]));
    $PAGE->set_context(context_user::instance($candidateid));
    $PAGE->set_pagelayout('standard');
    $PAGE->set_title(faisabilite_catalog::label($sectioncode, 0));
    $PAGE->set_heading(fullname($USER));

    echo $OUTPUT->header();
    echo $OUTPUT->heading(faisabilite_catalog::label($sectioncode, 0));
    $help = faisabilite_catalog::help($sectioncode);
    if ($help !== null) {
        echo html_writer::tag('p', s($help), ['class' => 'tool-vae-section-help']);
    }
    $shown = faisabilite_fields::account_values($sectioncode, $USER);
    echo html_writer::start_tag('dl', ['class' => 'row']);
    foreach (faisabilite_fields::account_keys()[$sectioncode] as $key) {
        echo html_writer::tag('dt', s(get_string('faisabaccount:' . $key, 'tool_vae')), ['class' => 'col-sm-3']);
        // The account's values are text the user typed into their profile: escaped like everything else drawn here.
        echo html_writer::tag(
            'dd',
            s($shown[$key] ?? get_string('faisabnotfilled', 'tool_vae')),
            ['class' => 'col-sm-9']
        );
    }
    echo html_writer::end_tag('dl');
    echo html_writer::tag('p', html_writer::link(
        new moodle_url('/user/edit.php', ['id' => $USER->id]),
        get_string('faisabeditprofile', 'tool_vae')
    ));
    echo html_writer::tag('p', html_writer::link(
        new moodle_url('/admin/tool/vae/faisabilite.php'),
        get_string('faisabbacktolist', 'tool_vae')
    ));
    echo $OUTPUT->footer();
    die();
}

$manager = new draft_manager();
$draft = $manager->section($candidateid, $doctype, $sectioncode, $slot, $fichecode);

$isvalidation = $doctype === draft_manager::DOCTYPE_VALIDATION;
$isreflexion = $doctype === draft_manager::DOCTYPE_REFLEXION;
$urlparams = ['doctype' => $doctype, 'section' => $sectioncode, 'slot' => $slot];
if ($isvalidation) {
    $urlparams['fiche'] = $fichecode;
}
$url = new moodle_url('/admin/tool/vae/section.php', $urlparams);
// Where "back" goes: the list of the document this section belongs to.
if ($isreflexion) {
    $backurl = new moodle_url('/admin/tool/vae/journal.php');
    $backwords = get_string('reflexionbacktojournal', 'tool_vae');
} else {
    $backurl = $isvalidation
        ? new moodle_url('/admin/tool/vae/validation.php', ['fiche' => $fichecode])
        : new moodle_url('/admin/tool/vae/faisabilite.php');
    $backwords = get_string($isvalidation ? 'validationbacktofiche' : 'faisabbacktolist', 'tool_vae');
}
$PAGE->set_url($url);
$PAGE->set_context(context_user::instance($candidateid));
$PAGE->set_pagelayout('standard');
$sectionlabel = $doctype === draft_manager::DOCTYPE_FAISABILITE
    ? faisabilite_catalog::label($sectioncode, $slot)
    : ($isvalidation ? validation_catalog::label($fichecode, $sectioncode) : draft_manager::label($draft));
$PAGE->set_title($sectionlabel);
$PAGE->set_heading(fullname($USER));

$history = new moodle_url('/admin/tool/vae/history.php', ['draftid' => $draft->id]);

// Separate fields (identity, status): a form of typed fields, saved whole as a version. There is
// no draft in progress for them - the form is short, and half a form is not a text to keep.
if ($kind === faisabilite_catalog::KIND_FIELDS) {
    $fieldsform = new fields_form($url->out(false), ['section' => $sectioncode]);

    if ($fieldsform->is_cancelled()) {
        redirect(new moodle_url('/admin/tool/vae/faisabilite.php'));
    }

    if ($data = $fieldsform->get_data()) {
        $content = faisabilite_fields::encode($sectioncode, fields_form::values($sectioncode, $data));
        $version = $manager->save_version((int) $draft->id, $content, $data->note ?? null);
        if ($version === null) {
            redirect($url, get_string('draftunchanged', 'tool_vae'), null, \core\output\notification::NOTIFY_INFO);
        }
        redirect(
            $url,
            get_string('draftsaved', 'tool_vae', (int) $version->number),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    $kept = [];
    if ((int) $draft->currentversion > 0) {
        $kept = faisabilite_fields::decode(
            $sectioncode,
            (string) $manager->version((int) $draft->id, (int) $draft->currentversion)->content
        );
    }
    $fieldsform->set_data(['draftid' => (int) $draft->id] + fields_form::data_from_values($sectioncode, $kept));

    echo $OUTPUT->header();
    echo $OUTPUT->heading($sectionlabel);
    $help = faisabilite_catalog::help($sectioncode);
    if ($help !== null) {
        echo html_writer::tag('p', s($help), ['class' => 'tool-vae-section-help']);
    }
    echo html_writer::tag('p', s((int) $draft->currentversion > 0
        ? get_string('sectioncurrentversion', 'tool_vae', (int) $draft->currentversion)
        : get_string('sectionnoversionfields', 'tool_vae')));
    echo html_writer::tag('p', html_writer::link($history, get_string('historylink', 'tool_vae')));
    echo html_writer::tag('p', html_writer::link(
        new moodle_url('/admin/tool/vae/faisabilite.php'),
        get_string('faisabbacktolist', 'tool_vae')
    ));
    $fieldsform->display();
    echo $OUTPUT->footer();
    die();
}

// The aid to reword (T-70): offered only when it is open, theirs to use and a provider can answer, and never in the private
// journal. For anyone else the form is as it was.
$aiurl = null;
if (!$isreflexion && (new ai_assistant())->offered($candidateid)) {
    $aiurl = new moodle_url('/admin/tool/vae/ai.php', ['source' => 'section'] + $urlparams);
}
$form = new section_form($url->out(false), ['aiurl' => $aiurl]);

if ($form->is_cancelled()) {
    redirect($history);
}

// A version written from a page that shows an older text than the draft another tab kept: refused, and said (the same check as
// the autosave's, with the `workingat` the page was loaded with or last received). The page is drawn again with the text the
// writer was saving still in the form, and without the autosave, which would otherwise start again from the fresh time.
$conflict = false;
if ($data = $form->get_data()) {
    $version = null;
    try {
        $version = $manager->save_version(
            (int) $draft->id,
            (string) $data->text,
            $data->note ?? null,
            (int) ($data->workingat ?? 0)
        );
    } catch (moodle_exception $e) {
        if ($e->errorcode !== 'errordraftconflict') {
            throw $e;
        }
        $conflict = true;
    }
    if (!$conflict) {
        if ($version === null) {
            redirect($url, get_string('draftunchanged', 'tool_vae'), null, \core\output\notification::NOTIFY_INFO);
        }
        redirect(
            $url,
            get_string('draftsaved', 'tool_vae', (int) $version->number),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// What the field starts with: the draft in progress when there is one, else the latest version.
$text = (string) $draft->working;
if ($draft->working === null && (int) $draft->currentversion > 0) {
    $text = (string) $manager->version((int) $draft->id, (int) $draft->currentversion)->content;
}
$form->set_data([
    'draftid' => (int) $draft->id,
    'workingat' => (int) ($draft->workingat ?? 0),
    'text' => $text,
]);

// Autosave: every twenty seconds, when the text has changed. Without JavaScript the button remains.
// WHAT THE SCRIPT KEEPS TRUE. The form carries the time of the draft the page knows (the field workingat), and the button that
// saves a version sends it: the script writes there each time the autosave is answered, or the writer's own autosave would look
// like another tab to the button. It stops at the submission of the form - a tick that left just before the click lands after
// the version, and draft_manager::save_working() is what makes that harmless - except for the button of the aid, which opens
// another tab and leaves this page to go on. Nothing starts on a page drawn after a conflict: it must be reloaded.
if (!$conflict) {
    $PAGE->requires->js_init_code(
        '(function(cfg){'
        . 'var area=document.getElementById("id_text");var live=document.getElementById("tool-vae-autosave");'
        . 'if(!area||!live||!window.fetch){return;}'
        . 'var form=area.form;var field=form?form.elements.workingat:null;'
        . 'var last=area.value;var known=cfg.workingat;var submitting=false;var timer=null;'
        . 'function say(t){live.textContent=t;}'
        . 'function tick(){'
        . 'if(submitting||area.value===last){return;}'
        . 'var sent=area.value;var body=new FormData();'
        . 'body.append("sesskey",cfg.sesskey);body.append("draftid",cfg.draftid);'
        . 'body.append("text",sent);body.append("workingat",known);'
        . 'fetch(cfg.url,{method:"POST",body:body,credentials:"same-origin"})'
        . '.then(function(r){return r.json();})'
        . '.then(function(j){'
        . 'if(submitting){return;}'
        . 'if(j.error){say(j.error);return;}'
        . 'last=sent;known=j.workingat;if(field){field.value=known;}say(j.message);})'
        . '.catch(function(){if(!submitting){say(cfg.failed);}});'
        . '}'
        . 'function start(){timer=setInterval(tick,20000);}'
        . 'start();'
        . 'if(form){form.addEventListener("submit",function(e){'
        . 'if(e.submitter&&e.submitter.formTarget==="_blank"){return;}'
        . 'submitting=true;clearInterval(timer);});}'
        . 'window.addEventListener("pageshow",function(e){if(e.persisted&&submitting){submitting=false;start();}});'
        . '})(' . json_encode([
            'url' => (new moodle_url('/admin/tool/vae/autosave.php'))->out(false),
            'sesskey' => sesskey(),
            'draftid' => (int) $draft->id,
            'workingat' => (int) ($draft->workingat ?? 0),
            'failed' => get_string('draftautosaveerror', 'tool_vae'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ');',
        true
    );
}

echo $OUTPUT->header();
// Escaped: the name of a bloc of the preparation comes from the record, whose labels are not ours (T-31).
echo $OUTPUT->heading(s($sectionlabel));

if ($conflict) {
    echo $OUTPUT->notification(get_string('errordraftconflict', 'tool_vae'), \core\output\notification::NOTIFY_ERROR);
    echo html_writer::tag('p', s(get_string('draftconflicttextkept', 'tool_vae')));
    echo html_writer::tag('p', html_writer::link($url, get_string('draftconflictreload', 'tool_vae'))
        . ' - ' . html_writer::link($history, get_string('historylink', 'tool_vae')));
}

if ($isvalidation) {
    // The preparation is not the official dossier, and says so on every screen (MC-17).
    echo html_writer::div(
        s(get_string('validationlabelling', 'tool_vae')),
        'alert alert-info tool-vae-labelling',
        ['role' => 'note']
    );
}
if ($isreflexion) {
    // Said on every entry, and not to be closed: nobody else reads the journal, and a situation told here can still
    // identify a person without naming them (T-14, MC-14).
    echo html_writer::div(s(get_string('reflexionprivate', 'tool_vae')), 'alert alert-info tool-vae-labelling', [
        'role' => 'note',
    ]);
    echo html_writer::div(s(get_string('reflexionconsigne', 'tool_vae')), 'alert alert-warning tool-vae-anonymise', [
        'role' => 'note',
    ]);
    echo html_writer::tag('p', s(get_string('reflexionprompts', 'tool_vae')), ['class' => 'tool-vae-section-help']);
}
$help = $doctype === draft_manager::DOCTYPE_FAISABILITE
    ? faisabilite_catalog::help($sectioncode)
    : ($isvalidation ? validation_catalog::help($sectioncode) : null);
if ($help !== null) {
    echo html_writer::tag('p', s($help), ['class' => 'tool-vae-section-help']);
}
if ($isvalidation && validation_catalog::describes_situations($sectioncode)) {
    // The situations told here can identify a person without naming them (MC-14).
    echo html_writer::div(
        s(get_string('validationconsigne', 'tool_vae')),
        'alert alert-warning tool-vae-anonymise',
        ['role' => 'note']
    );
}

$status = (int) $draft->currentversion > 0
    ? get_string('sectioncurrentversion', 'tool_vae', (int) $draft->currentversion)
    : get_string('sectionnoversion', 'tool_vae');
echo html_writer::tag('p', s($status));
echo html_writer::tag('p', html_writer::link($history, get_string('historylink', 'tool_vae')));
echo html_writer::tag('p', html_writer::link($backurl, $backwords));

$form->display();

// A live region, not a toast: a screen reader announces it, and it asks nothing of the writer. Empty after a conflict: the draft
// saved at that time is another tab's, and the text in this form is not saved.
echo html_writer::div(
    $draft->workingat && !$conflict ? s(get_string('draftautosaved', 'tool_vae', userdate((int) $draft->workingat))) : '',
    'text-muted small',
    ['id' => 'tool-vae-autosave', 'role' => 'status', 'aria-live' => 'polite']
);

echo $OUTPUT->footer();
