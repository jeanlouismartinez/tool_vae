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
 * Capability definitions.
 *
 * WHY NO DEFAULT ARCHETYPE ON THE DATA CAPABILITIES:
 * the "user" archetype is the Authenticated user role, which is assigned at SYSTEM
 * context. A user context is a direct child of the system context, so a capability
 * granted to that archetype would be held by every authenticated user in *every other
 * user's* context. Combined with a check written as
 *     require_capability('tool/vae:viewown', context_user::instance($candidateid))
 * where $candidateid comes from the request, that turns dossier.php?userid=<someone else>
 * into a working data leak. Core avoids this the same way: user_evidence::can_manage_user()
 * tests `$context->instanceid == $USER->id` in PHP before adding the "own" capability.
 *
 * Ownership is therefore proved in PHP — see \tool_vae\local\access — never by an
 * inherited capability.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Declare one's own experience of an activity. Creates a core user_evidence.
    // RISK_XSS: this capability writes the free-text narrative, which is stored outside
    // the core PARAM_CLEANHTML cleaning and rendered on the attestation screen.
    'tool/vae:declare' => [
        'riskbitmask'  => RISK_PERSONAL | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

    // Attest a declaration made by someone else. Never calls the core API.
    'tool/vae:attest' => [
        'riskbitmask'  => RISK_PERSONAL | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

    // Withdraw any attestation standing on a dossier one may read - a colleague's included
    // (13 October 2026). By default nobody holds it: the site administrator grants it to a role or
    // to a person. A signatory who only withdraws their own attestation needs no capability:
    // the site setting managerwithdrawown covers it. See attestation_manager::require_can_withdraw().
    'tool/vae:withdrawany' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

    // Read one's own dossier. Granted explicitly, never by archetype - see file header.
    'tool/vae:viewown' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

    // Read anyone's dossier. The most dangerous capability of this plugin: site-wide read
    // access to every candidate's narrative. Explicit assignment only.
    'tool/vae:viewany' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // Read the dossier of a candidate one follows (lot 7 bis). Granted through a role assigned
    // in the candidate's own user context - by hand, or by tool_cohortroles for a whole cohort.
    // Held at SYSTEM level it would reach every user context, so \tool_vae\local\access
    // refuses it outright there: see access::follows().
    'tool/vae:viewassigned' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

    // Export a dossier as the normative archive (lot 8, MC-1). The archive leaves the platform for
    // good, so holding this is never enough on its own: on one's own dossier it goes with viewown,
    // on anyone else's with viewany at site level. Following a candidate does not let one export
    // their dossier - see access::can_export(). Decided with the project owner, 11 October 2026.
    'tool/vae:export' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

    // Import a certification record into a competency framework.
    // RISK_XSS: imported labels are third-party content rendered in the UI.
    // The site administrator's alone since 12 October 2026, by decision of the project owner: the
    // manager archetype lost it, and db/upgrade.php took it back from the roles already installed.
    'tool/vae:import' => [
        'riskbitmask'  => RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // Rebuild a candidate's dossier from a normative archive (lot 8, second half). Writes claims
    // and attestations nobody on this site made, in someone else's name. Explicit assignment
    // only, like viewany: by default the site administrator alone. Decided with the project owner,
    // 12 October 2026.
    // It is also the right to back up any candidate's dossier into one's own private files (backup.php), which asks
    // moodle/competency:userevidencemanage on the candidate as well, as the reimport does.
    'tool/vae:importdossier' => [
        'riskbitmask'  => RISK_PERSONAL | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],

    // Attach an imported certification record to a course, all its competencies at once (lot 8
    // bis). The managers hold it by default; the direction receives it with its role. Decided with
    // the project owner, 12 October 2026. The core's moodle/competency:coursecompetencymanage is
    // asked as well - see course_linker::require_can_link().
    'tool/vae:linkcourse' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => ['manager' => CAP_ALLOW],
    ],

    // Write the sections of one's own document and their versions (T-81). Held in the candidate's
    // own user context, like declare, and given the same way: by role, never by archetype. Nobody
    // writes another person's document - an administrator reads it, they do not write it.
    'tool/vae:draftown' => [
        'riskbitmask'  => RISK_PERSONAL | RISK_XSS,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

    // Read the sections and versions of someone else's document (T-81). Not given to the managers
    // who follow a candidate: they see nothing before the deposit (T-21, decision of 15 October 2026).
    // By default nobody holds it; the site administrator reads as a matter of course.
    // The dossier must also be one the reader may consult (viewany, or following the candidate): held at site level
    // without viewany, it opens nothing.
    'tool/vae:draftview' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

    // Read the deposits of someone else's dossier de faisabilité (T-21). What a candidate has deposited,
    // never the drafts or the versions. Held by role in the candidate's user context, like draftview, and
    // given to the managers who follow them by the site, not by archetype. The identity and the status
    // inside a deposit still need tool/vae:identityview. The site administrator reads as a matter of course.
    // The dossier must also be one the reader may consult (viewany, or following the candidate): held at site level
    // without viewany, it opens nothing.
    'tool/vae:readdeposit' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

    // Ask an automatic tool to reformulate a text one wrote oneself (T-70, D-6). Given by hand, to the persons the site
    // chooses, and by no archetype. A site administrator passes every capability check, so the real lock is the setting
    // `aiopen`, closed by default; the candidate's own acceptance of the policy comes on top. Checked in the candidate's
    // own user context, for the candidate alone.
    'tool/vae:useai' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

    // Write an opinion on a deposit of a candidate one follows (T-10, lot 5b, D-12). Held by role in the
    // candidate's user context, given by the site and by no archetype; it also takes tool/vae:readdeposit,
    // since an opinion is written on a deposit one has read. The administrator follows nobody and does
    // not write one in the manager's place.
    'tool/vae:writeopinion' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

    // Read the identity and the status of someone else's dossier de faisabilité (T-10, lot 3, MC-11):
    // date of birth, nationality, levels and titles of certifications, status. On top of draftview, never
    // instead of it. By default nobody holds it; the site administrator reads as a matter of course.
    // The dossier must also be one the reader may consult (viewany, or following the candidate): held at site level
    // without viewany, it opens nothing.
    'tool/vae:identityview' => [
        'riskbitmask'  => RISK_PERSONAL,
        'captype'      => 'read',
        'contextlevel' => CONTEXT_USER,
        'archetypes'   => [],
    ],

];

// TWO CAPABILITIES WERE REMOVED HERE ON 28 AUGUST 2026, AND THEY COME BACK WITH THEIR LOTS.
// `export` came back with lot 8, on 11 October 2026, together with the code that reads it.
// `managepassage` still waits for lot 7.
//
// `tool/vae:export` (the portable archive, lot 8) and `tool/vae:managepassage`
// (mappings between an expired certification record and its successor, lot 7) were declared
// ahead of the code that reads them. Grep found zero readers. An administrator could grant them
// from the roles screen, where they are described in full, and nothing whatsoever happened -
// a right that reads as granted and is not.
//
// They are declared again with the lot that reads them, never before. Moodle removes a
// capability that leaves this file at the next upgrade, so nothing has to be cleaned up by
// hand; and re-declaring them later is one block each. Their language strings went with them,
// in both languages: `make langue` fails on a capability without a string, so the pair cannot
// come back by halves.
