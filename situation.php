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
 * One work situation of the vaeiste (lot 1b): its title, period, setting and tags, its text with versions, its pieces.
 *
 * WHO. The right to read is checked at the door (access::require_can_view(), then situation_manager::find(), which refuses
 * whoever may not read the preparation - the site administrator included, MC-16). The vaeiste alone writes, through
 * situation_manager and spiece_manager, each send a POST with the session key. A reader gets no form: the CURRENT title,
 * period, setting and tags (D-1b-I), the latest version, then the draft in progress said to be one (D-1b-H), the pieces.
 * Under them, the annotations: a manager the vaeiste consented to annotates (note_manager::can_write()), never in the text.
 *
 * WHEN. The preparation closed (validationopen) closes every door of the situation - details, version, deletion - and the
 * page says so and gives back the text that was being sent; the situation stays readable. Adding a piece follows piecesopen
 * alone, as every piece does; detaching and removing a piece work closed.
 *
 * THE UPLOAD is read before the forms are built, as piece.php does it: the form library empties $_FILES, and a file larger
 * than post_max_size arrives as an empty POST that must be said.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\form\note_form;
use tool_vae\form\situation_details_form;
use tool_vae\form\situation_form;
use tool_vae\local\access;
use tool_vae\local\draft_manager;
use tool_vae\local\hidden_frameworks;
use tool_vae\local\note_manager;
use tool_vae\local\note_screen;
use tool_vae\local\piece_manager;
use tool_vae\local\situation_manager;
use tool_vae\local\situation_screen;
use tool_vae\local\spiece_manager;
use tool_vae\local\whereami_panel;

require_login();

$candidateid = optional_param('userid', (int) $USER->id, PARAM_INT);
$fichecode = required_param('fiche', PARAM_RAW_TRIMMED);
$slot = required_param('slot', PARAM_INT);

$context = access::require_can_view($candidateid);
$manager = new situation_manager();
$situation = $manager->find($candidateid, $fichecode, $slot);
// A free slot, or a record whose code differs in case, is refused as a situation one may not read is: nothing says which
// slots are taken.
if ($situation === null || (string) $situation->fichecode !== $fichecode) {
    throw new moodle_exception('errordraftrefused', 'tool_vae');
}
$situationid = (int) $situation->id;
$candidate = core_user::get_user($candidateid, '*', MUST_EXIST);
$canwrite = situation_manager::can_write($candidateid);
$open = draft_manager::validation_open();
// A record hidden in Moodle since the situation was written: the page is in reading, with the message of the record, as
// situations.php has it (D-1b-F: what is not offered is not written on from here).
$frameworks = new hidden_frameworks();
$source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
$hiddenmessage = $source && $frameworks->is_hidden($source) ? $frameworks->message($source) : null;
$mayact = $canwrite && $hiddenmessage === null;
$editing = $mayact && $open;
$settings = piece_manager::settings();
// What PHP lets through is the other limit: the smaller of the two is the one said and applied.
$maxbytes = min($settings->maxbytes, get_max_upload_file_size());
$canadd = $mayact && $settings->open;
$notify = \core\output\notification::class;

$url = situation_screen::url($candidateid, $fichecode, $slot);
$listurl = situation_screen::url($candidateid, $fichecode);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('situationheading', 'tool_vae', situation_screen::title($situation)));
$PAGE->set_heading(fullname($candidate));

$pieces = new spiece_manager();

// The upload is taken BEFORE the forms are built (see piece.php).
$upload = $_FILES['piecefile'] ?? null;
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$upload && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0
) {
    redirect($url, get_string('errorpiecetoolarge', 'tool_vae', display_size($maxbytes)), null, $notify::NOTIFY_ERROR);
}

// The annotations (lot 1b): the author takes back their own annotation, even once the consent is withdrawn; a POST with the
// session key, the manager checking the authorship again. Whoever may read the page may send it: the manager refuses another,
// and an annotation of another vaeiste or of another target than this situation.
$notes = new note_manager();
if (data_submitted() && optional_param('action', '', PARAM_ALPHA) === 'withdrawnote') {
    require_sesskey();
    $error = null;
    try {
        $notes->withdraw(required_param('note', PARAM_INT), [
            'candidateid' => $candidateid,
            'target' => note_manager::TARGET_SITUATION,
            'targetuuid' => (string) $situation->uuid,
            'fichecode' => $fichecode,
            'slot' => $slot,
            'ficherang' => '',
        ]);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
    redirect($url, $error, null, $error !== null ? $notify::NOTIFY_ERROR : $notify::NOTIFY_SUCCESS);
}

// The acts of the vaeiste on the situation and its pieces: a POST with the session key, each one through its manager, which
// checks the right and the opening again.
$action = $mayact && data_submitted() ? optional_param('action', '', PARAM_ALPHA) : '';
$confirmdelete = false;
if ($action !== '') {
    require_sesskey();
    $error = null;
    $message = null;
    $level = $notify::NOTIFY_SUCCESS;
    $target = $url;
    try {
        switch ($action) {
            case 'delete':
                // Closed, the deletion is not even asked: its button is not drawn, and the manager would refuse it.
                if (!$open) {
                    throw new moodle_exception('errorvalidationclosed', 'tool_vae');
                }
                if (!optional_param('confirm', 0, PARAM_BOOL)) {
                    $confirmdelete = true;
                    break;
                }
                $manager->delete($situationid);
                $message = get_string('situationdeleted', 'tool_vae');
                $target = $listurl;
                break;
            case 'detach':
                $pieces->detach($situationid, required_param('piece', PARAM_INT));
                break;
            case 'remove':
                $pieces->remove(required_param('piece', PARAM_INT));
                $message = get_string('pieceremoved', 'tool_vae');
                break;
            case 'attach':
                $pieces->attach($situationid, required_param('piece', PARAM_INT), optional_param('part', '', PARAM_RAW));
                break;
            case 'link':
                $row = $pieces->add_link(
                    $situationid,
                    optional_param('nature', '', PARAM_ALPHA),
                    optional_param('title', '', PARAM_TEXT),
                    optional_param('url', '', PARAM_RAW_TRIMMED),
                    optional_param('part', '', PARAM_RAW)
                );
                $warnings = array_map(fn($key) => get_string($key, 'tool_vae'), $row->warnings);
                $message = trim(get_string('pieceaddedlink', 'tool_vae') . ' ' . implode(' ', $warnings));
                $level = $warnings ? $notify::NOTIFY_WARNING : $notify::NOTIFY_SUCCESS;
                break;
            case 'file':
                if (!$upload || !is_array($upload) || is_array($upload['error']) || $upload['error'] === UPLOAD_ERR_NO_FILE) {
                    throw new moodle_exception('errorpiecenofile', 'tool_vae');
                }
                if (in_array($upload['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                    throw new moodle_exception('errorpiecetoolarge', 'tool_vae', '', display_size($maxbytes));
                }
                if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
                    throw new moodle_exception('errorpiecefile', 'tool_vae');
                }
                // The name of the file is never asked for: the piece is stored under an identifier.
                $pieces->add_file(
                    $situationid,
                    optional_param('nature', '', PARAM_ALPHA),
                    optional_param('title', '', PARAM_TEXT),
                    $upload['tmp_name'],
                    optional_param('part', '', PARAM_RAW)
                );
                $message = get_string('pieceaddedfile', 'tool_vae');
                break;
        }
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
    if (!$confirmdelete && in_array($action, ['delete', 'detach', 'remove', 'attach', 'link', 'file'], true)) {
        redirect($error !== null ? $url : $target, $error ?? $message, null, $error !== null ? $notify::NOTIFY_ERROR : $level);
    }
}

// Annotating: whoever may annotate now - following, the right, the consent, read again at each send (note_manager::can_write());
// never the vaeiste, never the site administrator. The form library checks the session key.
$canannotate = note_manager::can_write($candidateid);
$noteform = null;
$noteerror = null;
if ($canannotate && !$confirmdelete) {
    $noteform = new note_form($url->out(false));
    if ($data = $noteform->get_data()) {
        try {
            $notes->write(
                $candidateid,
                note_manager::TARGET_SITUATION,
                $fichecode,
                $slot,
                '',
                (string) ($data->notetext ?? ''),
                empty($data->markdown) ? FORMAT_PLAIN : FORMAT_MARKDOWN
            );
        } catch (moodle_exception $e) {
            $noteerror = $e->getMessage();
        }
        if ($noteerror === null) {
            redirect($url);
        }
    }
}

$draft = $DB->get_record('tool_vae_draft', ['id' => (int) $situation->draftid], '*', MUST_EXIST);
$versions = $manager->versions($situationid);
$latest = $versions ? reset($versions) : null;

$error = null;
$conflict = false;
$detailsform = null;
$textform = null;
if ($editing && !$confirmdelete) {
    // The boxes of the panel say the tags as they were sent when the form comes back with an error, else as they stand.
    $sentdetails = optional_param('_qf__tool_vae_form_situation_details_form', 0, PARAM_INT) && data_submitted();
    $checked = $sentdetails ? optional_param_array('tags', [], PARAM_RAW_TRIMMED) : $situation->tags;
    $detailsform = new situation_details_form($url->out(false), [
        'panel' => whereami_panel::render($candidateid, $fichecode, ['boxes' => true, 'checked' => $checked, 'title' => '']),
    ]);
    if ($data = $detailsform->get_data()) {
        try {
            $manager->save_details(
                $situationid,
                (string) $data->title,
                empty($data->periodstart) ? null : (int) $data->periodstart,
                empty($data->periodend) ? null : (int) $data->periodend,
                (string) ($data->setting ?? ''),
                optional_param_array('tags', [], PARAM_RAW_TRIMMED)
            );
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
        if ($error === null) {
            redirect($url, get_string('situationdetailssaved', 'tool_vae'), null, $notify::NOTIFY_SUCCESS);
        }
    }
    $detailsform->set_data([
        'title' => (string) $situation->title,
        'periodstart' => (int) ($situation->periodstart ?? 0),
        'periodend' => (int) ($situation->periodend ?? 0),
        'setting' => (string) $situation->setting,
    ]);

    // A version from a page that shows an older text than the draft another tab kept is refused and said, as in section.php.
    $textform = new situation_form($url->out(false));
    if ($data = $textform->get_data()) {
        $version = null;
        try {
            $version = $manager->save_version($situationid, (string) $data->content, (int) ($data->workingat ?? 0));
        } catch (moodle_exception $e) {
            if ($e->errorcode === 'errordraftconflict') {
                $conflict = true;
            } else {
                $error = $e->getMessage();
            }
        }
        if (!$conflict && $error === null) {
            if ($version === null) {
                redirect($url, get_string('draftunchanged', 'tool_vae'), null, $notify::NOTIFY_INFO);
            }
            redirect($url, get_string('draftsaved', 'tool_vae', (int) $version->number), null, $notify::NOTIFY_SUCCESS);
        }
    }
    // What the field starts with: the draft in progress when there is one, else the latest version.
    $text = $draft->working !== null ? (string) $draft->working : ($latest !== null ? (string) $latest->content : '');
    $textform->set_data(['workingat' => (int) ($draft->workingat ?? 0), 'content' => $text]);
    if (!$conflict) {
        situation_screen::autosave((int) $draft->id, (int) ($draft->workingat ?? 0));
    }
}

$history = new moodle_url('/admin/tool/vae/history.php', ['draftid' => (int) $draft->id]);

echo $OUTPUT->header();
echo $OUTPUT->heading(s(situation_screen::title($situation)));

if ($confirmdelete) {
    echo $OUTPUT->confirm(
        get_string('situationdeleteconfirm', 'tool_vae'),
        new single_button(
            new moodle_url($url, ['action' => 'delete', 'confirm' => 1]),
            get_string('situationdelete', 'tool_vae'),
            'post'
        ),
        new single_button($url, get_string('cancel'), 'get')
    );
    echo $OUTPUT->footer();
} else {
    echo html_writer::tag('p', html_writer::tag('strong', s($fichecode)));
    echo situation_screen::disclaimer($candidateid);
    if ($error !== null) {
        echo $OUTPUT->notification(s($error), $notify::NOTIFY_ERROR);
    }
    if ($conflict) {
        echo $OUTPUT->notification(get_string('errordraftconflict', 'tool_vae'), $notify::NOTIFY_ERROR);
        echo html_writer::tag('p', s(get_string('draftconflicttextkept', 'tool_vae')));
        echo html_writer::tag('p', html_writer::link($url, get_string('draftconflictreload', 'tool_vae')));
    }
    if ($hiddenmessage !== null) {
        // Hidden under the vaeiste: said to whoever reads, and what the vaeiste was sending given back, never lost.
        echo situation_screen::refused($hiddenmessage, $canwrite ? situation_screen::kept_text() : []);
    } else if ($canwrite && !$open) {
        // Closed under the vaeiste: said, and what they were sending given back, never lost.
        echo situation_screen::closed(situation_screen::kept_text());
    }

    if ($editing) {
        $detailsform->display();
        echo html_writer::tag('h3', s(get_string('situationtext', 'tool_vae')), ['class' => 'h5 mt-4']);
        echo html_writer::tag('p', s($latest !== null
            ? get_string('sectioncurrentversion', 'tool_vae', (int) $latest->number)
            : get_string('sectionnoversion', 'tool_vae')));
        $textform->display();
        // A live region, not a toast: a screen reader announces it (section.php).
        echo html_writer::div(
            $draft->workingat && !$conflict ? s(get_string('draftautosaved', 'tool_vae', userdate((int) $draft->workingat))) : '',
            'text-muted small',
            ['id' => 'tool-vae-autosave', 'role' => 'status', 'aria-live' => 'polite']
        );
    } else {
        echo situation_screen::details($situation);
        echo situation_screen::text($latest, $draft, $context);
    }
    echo html_writer::tag('p', html_writer::link($history, get_string('situationversions', 'tool_vae')));

    // The pieces: the vaeiste detaches a piece from this situation, or removes it everywhere; open or closed.
    $actions = null;
    if ($mayact) {
        $actions = function (\stdClass $link) use ($OUTPUT, $url): string {
            $out = $OUTPUT->single_button(
                new moodle_url($url, ['action' => 'detach', 'piece' => (int) $link->pieceid]),
                get_string('spiecedetach', 'tool_vae'),
                'post'
            );
            if ($link->piece->timeremoved === null) {
                $out .= $OUTPUT->single_button(
                    new moodle_url($url, ['action' => 'remove', 'piece' => (int) $link->pieceid]),
                    get_string('pieceremove', 'tool_vae'),
                    'post'
                );
            }

            return $out;
        };
    }
    $links = $pieces->for_situation($situationid);
    echo situation_screen::pieces($links, $context, $actions);
    if ($canadd) {
        $linked = array_map(fn($link) => (int) $link->pieceid, $links);
        $attachable = array_filter(
            $pieces->for_candidate($candidateid, $fichecode),
            fn($piece) => $piece->timeremoved === null && !in_array((int) $piece->id, $linked, true)
        );
        echo situation_screen::piece_forms($url, $attachable, $maxbytes);
    } else if ($mayact) {
        echo $OUTPUT->notification(get_string('piececlosed', 'tool_vae'), $notify::NOTIFY_INFO);
    }

    // The annotations on the situation: under the text, signed and dated, apart from the vaeiste's own voice.
    if ($noteerror !== null) {
        echo $OUTPUT->notification(s($noteerror), $notify::NOTIFY_ERROR);
    }
    echo note_screen::block(
        $candidateid,
        note_manager::TARGET_SITUATION,
        $fichecode,
        $slot,
        '',
        $notes->for_target($candidateid, note_manager::TARGET_SITUATION, $fichecode, $slot, ''),
        $canannotate,
        $noteform ? $noteform->render() : ''
    );

    if ($editing) {
        echo $OUTPUT->single_button(
            new moodle_url($url, ['action' => 'delete']),
            get_string('situationdelete', 'tool_vae'),
            'post'
        );
    }
    echo html_writer::tag('p', html_writer::link($listurl, get_string('situationbacklist', 'tool_vae')));
    echo $OUTPUT->footer();
}
