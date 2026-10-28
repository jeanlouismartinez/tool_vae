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
 * The reformulation aid: the policy, the confirmation, the proposal (T-70, lot 2).
 *
 * THREE SCREENS, ONE PAGE, ALWAYS POSTED. The text comes from the field of the page the candidate was writing on (a section of
 * their document, the situation of a declaration) and never rides in an address. Whatever the step, nothing is sent to a provider
 * but by the last one, after the candidate has seen the exact text and the name of the provider and pressed the button that says
 * so; this confirmation cannot be turned off. Nothing is saved: the proposal is shown next to the original, and it is up to the
 * candidate to copy it and to save their text.
 *
 * Like declare.php and section.php, the page never takes the candidate from the request: the writer is $USER.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\access;
use tool_vae\local\ai_assistant;
use tool_vae\local\ai_screens;
use tool_vae\local\draft_manager;
use tool_vae\local\faisabilite_catalog;

require_login();
// Everything here is posted by a form of the plugin: a link or an address typed by hand leads nowhere.
require_sesskey();

$candidateid = (int) $USER->id;
$source = required_param('source', PARAM_ALPHA);
$step = optional_param('step', 'ask', PARAM_ALPHA);

// Where the text was written: this decides the field it comes from and where "back" goes. The place is checked as the page of
// writing checks it, so that the aid is no way into a section the candidate could not write.
$params = ['source' => $source];
if ($source === 'declare') {
    access::require_can_declare($candidateid);
    $field = 'situation';
    $back = new moodle_url('/admin/tool/vae/declare.php');
    $backwords = get_string('aibacktodeclare', 'tool_vae');
} else if ($source === 'section') {
    $doctype = required_param('doctype', PARAM_ALPHANUMEXT);
    $sectioncode = required_param('section', PARAM_ALPHANUMEXT);
    $slot = optional_param('slot', 0, PARAM_INT);
    $fichecode = optional_param('fiche', '', PARAM_ALPHANUMEXT);
    // The private journal (T-14) is nobody's to read and is not sent anywhere; the fields of the identity and the account are not
    // text of the candidate's own words.
    $isdocument = in_array($doctype, [draft_manager::DOCTYPE_FAISABILITE, draft_manager::DOCTYPE_VALIDATION], true);
    if (
        !$isdocument
            || ($doctype === draft_manager::DOCTYPE_FAISABILITE
                && faisabilite_catalog::kind($sectioncode) !== faisabilite_catalog::KIND_TEXT)
    ) {
        throw new moodle_exception('errorairight', 'tool_vae');
    }
    // Refuses an address that does not exist or that is closed, as section.php does.
    (new draft_manager())->section($candidateid, $doctype, $sectioncode, $slot, $fichecode);
    $field = 'text';
    $params += ['doctype' => $doctype, 'section' => $sectioncode, 'slot' => $slot];
    $backparams = ['doctype' => $doctype, 'section' => $sectioncode, 'slot' => $slot];
    if ($doctype === draft_manager::DOCTYPE_VALIDATION) {
        $params['fiche'] = $fichecode;
        $backparams['fiche'] = $fichecode;
    }
    $back = new moodle_url('/admin/tool/vae/section.php', $backparams);
    $backwords = get_string('aibacktosection', 'tool_vae');
} else {
    throw new moodle_exception('errorairight', 'tool_vae');
}

$PAGE->set_url(new moodle_url('/admin/tool/vae/ai.php', $params));
$PAGE->set_context(context_user::instance($candidateid));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('aiheading', 'tool_vae'));
$PAGE->set_heading(fullname($USER));

$assistant = new ai_assistant();
$state = $assistant->state($candidateid);
if ($state !== ai_assistant::READY && $state !== ai_assistant::NO_POLICY) {
    // Closed, not theirs, no provider or several: the pages of writing do not offer the aid, and a request that comes anyway is
    // told why in a word, and nothing is sent.
    throw $assistant->refusal($state);
}

$text = ai_assistant::clean((string) optional_param($step === 'ask' ? $field : 'text', '', PARAM_RAW));
$provider = (string) $assistant->provider_name();
$error = null;
$proposal = null;
$again = null;

if ($step === 'accept' && $state === ai_assistant::NO_POLICY) {
    // The candidate accepts, for themselves, and goes on to the confirmation.
    $assistant->accept_policy($candidateid);
    $state = $assistant->state($candidateid);
} else if ($step === 'send' && $state === ai_assistant::READY) {
    try {
        // What was confirmed must still be what happens: the token of that very confirmation, not spent yet, and the provider that
        // was named. Otherwise nothing is sent and the confirmation is shown again, with the provider as it is now.
        $assistant->check_confirmation(optional_param('confirm', '', PARAM_ALPHANUM), optional_param('mark', '', PARAM_ALPHANUM));
        try {
            $proposal = $assistant->reformulate($candidateid, $text);
        } catch (moodle_exception $e) {
            // Our own messages only (the length, the pause, the ceiling, no proposal): never the provider's.
            $error = $e->getMessage();
        }
    } catch (moodle_exception $e) {
        $again = $e->getMessage();
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('aiheading', 'tool_vae'));

if ($proposal !== null || $error !== null) {
    echo ai_screens::result($proposal, $error, $text, $back, $backwords);
} else if ($state === ai_assistant::NO_POLICY) {
    echo ai_screens::policy($params, $text, $back, $backwords);
} else {
    if ($again !== null) {
        echo $OUTPUT->notification(s($again), \core\output\notification::NOTIFY_WARNING);
    }
    // The confirmation carries a token that is spent by the send, and the mark of the provider it names.
    echo ai_screens::confirm($params, $text, $provider, $back, $backwords, [
        'confirm' => ai_assistant::issue_confirmation(),
        'mark' => $assistant->provider_mark(),
    ]);
    // A second click on the same button must not send twice: the button is disabled as the form goes.
    $PAGE->requires->js_init_code(
        'document.querySelectorAll("form[action$=\'ai.php\']").forEach(function(f){'
        . 'f.addEventListener("submit",function(){var b=f.querySelector("input[type=submit]");'
        . 'if(b){setTimeout(function(){b.disabled=true;},0);}});});',
        true
    );
}

echo $OUTPUT->footer();
