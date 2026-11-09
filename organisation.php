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
 * The pathway of the current user, the candidates they follow, and the configuration of the scheme (T-90).
 *
 * THIS PAGE TAKES NO USER ID FROM THE REQUEST. "My pathway" is the pathway of the person who is logged in, and of nobody
 * else; "Followed candidates" lists the candidates that person follows, as the page "My candidates" does; "Organisation"
 * is the configuration of the scheme on this site, read by who may view any dossier, and names no candidate. An id in the
 * address bar is not read, so there is none to forge. The refusals are in \tool_vae\local\organisation, not here:
 * for_candidate() for anyone but the candidate, require_tab(), followed() and site() for a tab one may not open - a page
 * that filtered what it draws would leave the rule untestable and the structure readable by any other caller. The calls
 * come before anything of the page is set, so that a refusal is the core's own error page and not a page with a hole in it.
 *
 * THREE TABS, EACH UNDER ITS OWN RIGHT, the same test for what is drawn and for the address
 * (organisation::can_open_tab()): "My pathway" for the candidate, "Followed candidates" for a manager who follows at least
 * one, "Organisation" for who may view any dossier. The address of a tab one may not open is refused. Without a tab in the
 * address the page opens on organisation::default_tab(). The tab of the site is a page of the administration
 * (admin_externalpage_setup), after the refusal; the other two are pages of the user.
 *
 * COUNTS, NEVER A VERDICT: plain counts and dates, no total, no rate, no colour.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\competency_gateway;
use tool_vae\local\draft_manager;
use tool_vae\local\organisation;
use tool_vae\local\organisation_screen;
use tool_vae\local\presenter;
use tool_vae\local\scope_manager;

require_login();

$candidateid = (int) $USER->id;

// A tab the page does not know, or none, falls back to the tab the reader opens on (a closed tab is never replaced by
// another: the refusal below is for a known tab whose right is missing). Nothing of the page is set before the refusal,
// so that it is the core's own error page and not a page with a hole in it.
$tab = optional_param('tab', '', PARAM_ALPHA);
if (!in_array($tab, [organisation::TAB_MINE, organisation::TAB_FOLLOWED, organisation::TAB_SITE], true)) {
    $tab = organisation::default_tab();
}
organisation::require_tab($tab);

$pathway = null;
$followed = null;
$site = null;
if ($tab === organisation::TAB_SITE) {
    // The right is asked again by the class; the records are null when the competency subsystem is off, and the rights,
    // the cohorts and the settings are drawn all the same.
    $site = organisation::site();
} else if (competency_gateway::is_subsystem_enabled()) {
    if ($tab === organisation::TAB_MINE) {
        $pathway = organisation::for_candidate($candidateid);
    } else {
        // The bloc is checked against the reader's own list by the class: a value from the address names nothing else.
        $followed = organisation::followed($candidateid, optional_param('bloc', '', PARAM_RAW));
    }
}

if ($tab === organisation::TAB_SITE) {
    // The page of the site is a page of the administration, for who may view any dossier and not only for the managers of the
    // site: the core sets its context, its title and its address, and the tab is kept in the address. It comes after the
    // refusal above, so that a refusal is never the page of the administration.
    require_once($CFG->libdir . '/adminlib.php');
    admin_externalpage_setup('toolvae_organisation', '', ['tab' => organisation::TAB_SITE], '', ['pagelayout' => 'standard']);
} else {
    $context = context_user::instance($candidateid);
    $params = ['tab' => $tab];
    if ($followed !== null && $followed->chosen !== '') {
        $params['bloc'] = $followed->chosen;
    }
    $url = new moodle_url('/admin/tool/vae/organisation.php', $params);
    $PAGE->set_url($url);
    $PAGE->set_context($context);
    $PAGE->set_pagelayout('standard');
    $PAGE->set_title(get_string('orgtab' . $tab, 'tool_vae'));
    $PAGE->set_heading(fullname($USER));
}

echo $OUTPUT->header();
// The heading is the label of the tab that is open (conception T-90, § 4.4).
echo $OUTPUT->heading(get_string('orgtab' . $tab, 'tool_vae'));
echo $OUTPUT->tabtree(organisation_screen::tabs(organisation::tabs()), $tab);

if ($site !== null) {
    echo organisation_screen::notice(get_string('orgintrosite', 'tool_vae'));
    echo organisation_screen::site($site);
    echo $OUTPUT->footer();
    die();
}

if ($pathway === null && $followed === null) {
    echo $OUTPUT->notification(
        get_string('errorcompetenciesdisabled', 'tool_vae'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->footer();
    die();
}

if ($followed !== null) {
    // No button of action on this tab: the names lead to the dossiers, which carry their own rights.
    echo organisation_screen::notice(get_string('orgintrofollowed', 'tool_vae'));
    echo organisation_screen::followed($followed, $url);
    echo $OUTPUT->footer();
    die();
}

// The preparation of the validation dossier: one's own, offered on the same conditions as on the dossier.
$mayvalidate = $pathway->declared && draft_manager::can_write($candidateid) && draft_manager::validation_open();

if (!$pathway->declared && !$pathway->planned) {
    echo organisation_screen::nothing($pathway);
} else {
    // The introduction speaks of the tables: a pathway of records only a plan names has none.
    if ($pathway->declared) {
        $intro = get_string('orgintromine', 'tool_vae');
        if ($mayvalidate) {
            $intro .= ' ' . get_string('orgintrovalidation', 'tool_vae');
        }
        echo organisation_screen::notice($intro);
    }
    echo organisation_screen::mine($pathway);
}

// What the candidate says is outside their activity (T-42), apart from the counts.
$maydeclare = has_capability('tool/vae:declare', $context);
echo presenter::scope_box(
    $pathway->marks,
    scope_manager::labels($pathway->marks),
    $maydeclare,
    !empty(get_config('tool_vae', 'scopeopen'))
);

if ($maydeclare) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/declare.php'),
        get_string('declareheading', 'tool_vae'),
        'get'
    );
}
// Positioning oneself on the nodes of a record (lot 1a): the vaeiste's own word, open at any time, with or without a declaration.
if (\tool_vae\local\selfpos_manager::can_write($candidateid)) {
    echo $OUTPUT->single_button(
        \tool_vae\local\selfpos_screen::url($candidateid),
        get_string('selfposlink', 'tool_vae'),
        'get'
    );
}
// The work situations of the vaeiste (lot 1b): their own account of their work, with or without a declaration.
if (\tool_vae\local\situation_manager::can_write($candidateid)) {
    echo $OUTPUT->single_button(
        \tool_vae\local\situation_screen::url($candidateid),
        get_string('situationslink', 'tool_vae'),
        'get'
    );
}
if ($pathway->declared || $pathway->planned) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]),
        get_string('mydossier', 'tool_vae'),
        'get'
    );
}
if ($mayvalidate) {
    echo $OUTPUT->single_button(
        new moodle_url('/admin/tool/vae/validation.php'),
        get_string('validationtitle', 'tool_vae'),
        'get'
    );
}

echo $OUTPUT->footer();
