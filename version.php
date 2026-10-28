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
 * Plugin version and metadata.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'tool_vae';
// Lot 7 ter adds db/messages.php, four observers and an ad hoc task: without a new number, an
// installed site would register none of them, and the first declaration would find no provider
// to send through. Raised a second time when the sending moved to the task, after review.
// Lot 8 adds the export capability, db/tasks.php and a pluginfile callback, none of which an
// installed site registers without a new number.
// Lot 8, second half: the importdossier capability, two columns on tool_vae_attest, and
// the import capability taken back from the manager archetype - all three need the upgrade step.
// Lot 8 bis: the linkcourse capability and a course navigation callback, registered on upgrade.
//
// THE MOODLE_405 PACKAGE'S NUMBER STAYS BELOW THE LOWEST UPGRADE STEP THAT ONLY THIS PACKAGE HAS,
// 2026101311. A site moving from 4.5 to 5.3 replaces the one package with the other, and Moodle
// only runs a step whose number is above the installed one: a 4.5 package numbered 2026101311 or
// more would make that site skip the step and never get the columns it creates. The 5.3 number
// is also always above the 4.5 one. A step added to the 4.5 branch is copied here under another
// number, and every step checks before it acts, so that a site which already ran it on 4.5 runs it
// again here to no effect. See docs/BRANCHES.md; the `migration` job of the continuous
// integration proves the passage, against the tip of MOODLE_405.
// Lot 8 ter: security fixes only, no schema change; the number moves so that sites purge their
// string caches and pick up the two new refusal messages of the archive reimport.
// Lot 8 quater: the register of issued archives, tool_vae_archive, created by the
// upgrade step 2026101302 (2026101203 on the 4.5 package).
// Lot 9 (R3): the coverage table on the candidate's own dossier; no schema change, new strings only.
// R4: dossier.pdf inside every archive; no schema change, new strings only.
// Lot 8 quinquies: fixes only (one refusal message, register purged with the account), no schema change.
// 0.13.0: wording and presentation (one word for the manager's act, 1er, year lists, button after a
// withdrawal, made-up first names); no schema change, new strings only.
// 0.13.1: the anonymisation reminder stays attached to a field that comes back with an error
// (T-72); no schema change, no new string.
// 0.13.2: attest() holds a lock per claim (T-76), the export refuses what the import would refuse
// (T-77), backups carry a mark (T-75), a daily task reconciles unfinished purges (T-78), and a
// second, idempotent step creates the withdrawal-request fields so that a 4.5 package can use
// numbers above 2026101311 (T-80). No schema change for a site already at 0.13.1.
// 0.14.0: two tables for the sections of a document and their versions (T-81): drafts saved
// automatically, versions kept and compared word by word, restoration as a new version, and a daily
// reconciliation of what an unfinished erasure leaves. Pages exist but no menu leads to them yet
// (T-10). 5.3 only: the 4.5 package receives corrections and nothing else.
// 0.15.0: editable copies of the dossier as .docx and .odt (T-83): a download button on the dossier
// page, and a weekly task (off until the administrator turns it on) that writes a dated copy into the
// candidate's own private files, two kept. A new scheduled task and a new event, so the number moves;
// no schema change. 5.3 only.
// 0.15.1: the list of the sections of the dossier de faisabilité (T-10, lot 1): a catalogue, a page that
// says where each stands, a button on the dossier; new strings, no schema change.
// 0.15.2: the sections of the dossier de faisabilité say what they are (T-10, lot 2): name and help in
// the writing, history and comparison screens, a date column on the list; new strings, no schema change.
// 0.16.0: identity and status as separate fields, the contact details read from the account, and a
// capability of its own to read the identity (T-10, lot 3, MC-10 and MC-11). A new capability: the
// number moves so that sites register it. No schema change.
// 0.17.0: the editable copy carries the sections of the dossier de faisabilité (T-10, lot 4a); new
// strings, no schema change.
// 0.18.0: the archive carries the text sections of the dossier de faisabilite (T-10, lot 4b): a file
// faisabilite.json with its schema, a section in the printed copy, and a restoration at reimport that
// never overwrites a section already written. Never identity (MC-13). New strings, no schema change.
// 0.19.0: the deposit of the dossier de faisabilite (T-21, lot 1): one table, a capability to read a
// deposit, a manager and an event. No page yet. 5.3 only.
// 0.20.0: the commitment of the candidate (T-10, lot 5a): a tenth section, confirmed with a deposit.
// New strings, no schema change.
// 0.21.0: the opinion of a manager on a deposit (T-10, lot 5b): one table, a capability, an event and
// a message to the candidate. 5.3 only.
// 0.22.0: the request for precision on a declaration before it is attested, and the candidate's answer
// (T-20, D-13): one table, two events and a message to the candidate. 5.3 only.
// 0.23.0: a reminder, once, of the declarations that waited for an attestation for the number of days the
// administrator chose, and a report of them (T-22): a scheduled task, a message provider and a page.
// No schema change. 5.3 only.
// 0.46.0: « candidat » becomes « vaéiste » in every French string, the strings of two message
// providers that had none, and the fixes of the audit of 26 October 2026: a twelfth message provider
// (declarationpurged, registered on upgrade) and the mark of the old backups (an upgrade step).
// No schema change.
// 0.46.1: decisions of 27 October 2026 (a new page and a new event, new strings, a changed message
// text): the version rises so that installed sites purge their caches. No schema change, no new
// message provider, no new capability.
// 0.47.0: the records of France compétences imported from its daily export, on one screen with two ways
// to get it - upload the zip, or have the server fetch it from data.gouv.fr (the one request the plugin
// makes of its own, on a click, never scheduled) - with a preview of what the export holds for each number
// and a confirmation. A new page, two new classes of events, new strings. No schema change, no new
// capability, no new message provider, no scheduled task: the number rises so that installed sites
// purge their caches and know the events.
// 01.5.3: renumbering, no change of code. The release label now reads "generation . Moodle branch":
// 01 is the first version meant for the Moodle plugins directory, 5.3 is the one branch it supports.
// The 0.47.1 code is unchanged; the number rises by one rank so that installed sites register the new
// label. No schema change, no new string, no new capability.
// Build 2026102803, same release label: the Moodle Marketplace refuses a new upload of a plugin under a build
// number it already holds (2026102802). The package now also carries scripts/ and the Makefile, which the
// tests read, and an English README. No code change, no schema change, no new string.
$plugin->version   = 2026102803;

// 2026100500.00 is Moodle 5.3.0, released on 5 October 2026.
$plugin->requires  = 2026100500.00;

// THIS PLUGIN RUNS ON MOODLE LTS RELEASES ONLY, AND ON ONE OF THEM AT A TIME.
//
// Decision of 28 August 2026. A dossier of prior learning is built over months and read by a
// panel years later; the platform under it has to be one that still receives security fixes
// when that day comes. An LTS carries three years of them, an intermediate release eighteen
// months - which is shorter than a VAE course followed by the wait for its panel.
//
// `supported` is where that policy is DECLARED: [503, 503]. It is not a lock - on another release
// Moodle shows a warning on the plugins page and still installs (core_plugin_manager only uses
// `supported` to flag it). What refuses is `requires` (below, 5.3.0) and `incompatible` (above):
// this package sets `incompatible = 504`, so Moodle 5.4 and later - intermediate releases - refuse
// it, and an administrator who upgrades the core finds the plugin blocked rather than silently
// running where nobody tested it. Nothing in Moodle expresses "LTS only" as a rule; this is the
// closest thing to it. Decided with the project owner on 13 October 2026. When the next LTS is
// tested, raise this number to the first branch after it.
//
// TWO PACKAGES, OPENED ON 12 OCTOBER 2026 AFTER LOT 8, WITH THE PROJECT OWNER'S AGREEMENT.
// This is the MOODLE_503 package. The MOODLE_405 package, supported = [405, 405], is maintained
// until Moodle 4.5 LTS stops receiving security fixes on 4 October 2027, and not one day longer.
// Neither widens its range to swallow the other: announcing a compatibility nobody tests is worse
// than announcing none, and the continuous integration only ever builds the branch it is on - plus,
// here, the passage from the one to the other.
$plugin->supported = [503, 503];

// The first Moodle branch that REFUSES this package: 5.4. core: branch >= incompatible is refused.
$plugin->incompatible = 504;

// LICENCE. Decision of the project owner, 28 October 2026: this plugin is licensed under the GNU GPL v3 or
// later, as every file header and the LICENSE file say. That is the licence Moodle itself uses, so the plugin
// is GPL compatible. The holder named in the headers is Jean-Louis Martinez <jeanlouis@martinez.pro>,
// confirmed by him on the same day. The GNU AGPL v3 was considered the same day and not retained: the plugin
// stays under the GNU GPL v3 or later.
// PO-2 IS NOT CLOSED BY THAT CHOICE, because it is not about the licence of the plugin: it asks whether the
// data of France competences, published under the Licence Ouverte 2.0, may be used with GPL code. The plugin
// never ships that data - the administrator loads it at run time, into the database - so none of it is in
// this package. The question stays open until a legal opinion settles it (README, "Licence").

// Not MATURITY_STABLE. The tests do run now - green in CI, PostgreSQL and MariaDB; the count is in the README - but the
// plugin has never been used by anyone, several lots are missing, and the data side of PO-2 (see above) is not
// settled. Maturity describes the plugin, not the test report.
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '01.5.3 (Moodle 5.3)';
