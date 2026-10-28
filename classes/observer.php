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

namespace tool_vae;

use tool_vae\event\dossier_purged;
use tool_vae\event\evidence_diverged;
use tool_vae\event\evidence_purged;
use tool_vae\local\anchor;
use tool_vae\local\purge;

/**
 * Two deletions decided outside this plugin, and what it does about them.
 *
 * WHY AN OBSERVER AT ALL. The candidate can destroy their own evidence record from tool_lp,
 * through an AJAX web service this plugin never sees. The core removes its own rows and stops
 * there. The narrative - free text about care work, held in this plugin's table - would survive,
 * anchored to a record that no longer exists: invisible in every interface, and out of reach of
 * any later purge. That is criterion 8.
 *
 * WHY TWO OBSERVERS AND NOT ONE. Deleting an ACCOUNT does not go through the same door.
 * delete_user() calls the competency subsystem's own user hook, which removes the evidence
 * records with a bulk delete and triggers NO event at all - verified against the source and measured on a running
 * site on 28 August 2026: core row gone, plugin row and attestation still there. An observer on
 * the single-record deletion is therefore not enough, and believing otherwise would have left
 * the wider hole open behind a control that looked complete.
 *
 * WHAT AN OBSERVER CANNOT DO. \core\event\manager catches every \Exception an observer throws
 * and turns it into a debugging() call - not every \Throwable: an \Error goes straight through
 * and aborts the core call that was running. So a failure here is either silent or brutal, and
 * never the clean refusal one would want. Nothing in this class may depend on an exception
 * reaching the caller.
 *
 * THE RESIDUAL RISK, NAMED. When no transaction surrounds the deletion - the ordinary case on
 * both core paths - a purge that fails leaves the orphaned narrative behind, and says so only
 * through a debugging() call that nobody reads in production. The transaction in
 * \tool_vae\local\purge keeps that failure from being HALF done; it cannot make it
 * loud. Detecting an orphan after the fact belongs to a scheduled task, and is an open point
 * of lot 7, not something this class can fix from where it stands.
 *
 * ORDER OF OPERATIONS. Both observers are declared internal - see db/events.php for why - so
 * they run inside the caller's transaction when there is one, and the purge is then part of it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * The core deleted one evidence record: destroy the claim that hung off it.
     *
     * @param \core\event\base $event Carries the deleted record's id as objectid.
     */
    public static function user_evidence_deleted(\core\event\base $event): void {
        if (!self::tables_exist()) {
            return;
        }

        $userevidenceid = (int) $event->objectid;
        $records = purge::by_userevidence($userevidenceid);

        foreach ($records as $record) {
            evidence_purged::create([
                'context' => self::context_of($event),
                'objectid' => (int) $record->id,
                'relateduserid' => (int) $record->candidateid,
                'other' => [
                    'userevidenceid' => $userevidenceid,
                    'attestations' => (int) ($record->attestations ?? 0),
                ],
            ])->trigger();
        }
    }

    /**
     * The core record a claim hangs off was changed: say so, and change nothing.
     *
     * The candidate can rename their own evidence record from tool_lp, under a capability the
     * authenticated-user archetype carries natively. Nothing in this plugin grants it and
     * nothing here can take it away - so this method does not try. It compares the label the
     * core now holds with the one this plugin would generate, and leaves a trace when they
     * differ. The dossier tells its reader the same thing, computed on sight.
     *
     * Unlinking a competency raises NO event at all, so it cannot be caught here; the dossier
     * catches it, which is why the dossier - not this method - is where the guarantee lives.
     *
     * THE TRACE REPEATS, AND THAT IS NOT A BUG WORTH FIXING HERE. Once an anchor has diverged,
     * every later edit of that record - a url, a description - logs the divergence again,
     * because this method compares a state and knows nothing of what it logged before.
     * Measured in adversarial review: three unrelated edits, three lines. Suppressing them
     * would mean reading the log to decide whether to write to it. The first line is the date
     * the divergence began, which is what the trace is for; the rest are noise in a log that
     * already carries every core event on the site.
     *
     * @param \core\event\base $event Carries the changed record's id as objectid.
     */
    public static function user_evidence_updated(\core\event\base $event): void {
        global $DB;

        if (!self::tables_exist()) {
            return;
        }

        $userevidenceid = (int) $event->objectid;
        $records = $DB->get_records('tool_vae_evidence', ['userevidenceid' => $userevidenceid]);

        foreach ($records as $record) {
            if (anchor::divergence($record) === null) {
                continue;
            }

            evidence_diverged::create([
                'context' => self::context_of($event),
                'objectid' => (int) $record->id,
                'relateduserid' => (int) $record->candidateid,
                'other' => ['userevidenceid' => $userevidenceid],
            ])->trigger();
        }
    }

    /**
     * An account was deleted: destroy the dossier it owned.
     *
     * Only the candidate's side. A manager whose account is deleted keeps their signature on
     * the dossiers they attested: for THIS observer, deleting an account is not a request for
     * erasure, and emptying those fields would degrade the file of every candidate they ever
     * signed for, none of whom asked for anything. Erasure has its own door, the privacy
     * provider. Decision of 28 August 2026.
     *
     * WHAT THE PLATFORM DOES NEXT, AND IT IS NOT NOTHING. On a Moodle left at its defaults,
     * tool_dataprivacy observes this very event and files an erasure request in the name of the
     * deleted account (automaticdeletionrequests is on), left awaiting approval
     * (automaticdatadeletionapproval is off). When the site's data protection officer approves
     * it, the privacy provider runs for that account and strips every signature it gave: four
     * fields emptied, the attestation marked withdrawn. So the signature outlives the deletion
     * of the account and goes with the approval of the request, and the person who approves
     * should know it - docs/T-84-conformite-rgpd.md says so, and tests/observer_test.php runs
     * the whole chain. Each candidate whose declarations lose an attestation then receives one message, and the managers
     * who follow them one too (tests/erased_signatory_notice_test.php): approving sends emails.
     *
     * ONE EXCEPTION, decided on 12 October 2026 (lot 8 quater). A signature carried in from an
     * archive names this person by their ULID alone, and the purge deletes the map entry that
     * ties the two. Left in place, it could never be erased afterwards: the privacy provider
     * finds such a signature only through that entry. So the purge strips it, and refreshes the
     * backups of the dossiers it stood in. A signature given here, by account, still stays.
     *
     * AND THE CANDIDATES ARE TOLD (decision of 27 October 2026). The attestation withdrawn here is withdrawn at once, without
     * the approval of anybody, so purge::dossier() hands the candidates whose declarations lost one to
     * notifier::attestations_erased() as soon as the strip is recorded - before the backups are redone, and without ever making
     * the deletion fail. The event of an ordinary withdrawal is not raised: this runs under the account that deleted, which
     * withdrew nothing.
     *
     * @param \core\event\base $event Carries the deleted account's id as objectid.
     */
    public static function user_deleted(\core\event\base $event): void {
        if (!self::tables_exist()) {
            return;
        }

        global $DB;

        $candidateid = (int) $event->objectid;

        // A request this person had open is theirs and goes with them: the signatory is asked
        // nothing on behalf of an account that no longer exists.
        $DB->execute(
            'UPDATE {tool_vae_attest} SET withdrawreqby = NULL, withdrawreqat = NULL WHERE withdrawreqby = :userid',
            ['userid' => $candidateid]
        );

        // And the other way round: a request addressed TO this person, whose signature stays. Only the
        // signatory answers a request - accepts it by withdrawing, or refuses - and that signatory is gone.
        // Left in place it would show every colleague, for good, "a request is awaiting the signatory", and
        // forbid a new one. The signature is not touched; the request that nobody can answer falls.
        $DB->execute(
            'UPDATE {tool_vae_attest} SET withdrawreqby = NULL, withdrawreqat = NULL
              WHERE attestantid = :userid AND withdrawreqat IS NOT NULL',
            ['userid' => $candidateid]
        );

        // The pieces this account opened (T-13, lot 2): a trace of a person, not worth keeping for an account that is gone.
        $DB->delete_records('tool_vae_piece_open', ['userid' => $candidateid]);

        // The blocs this account was referent of (T-23), and the trace of who named them: the rule goes with the person.
        $DB->delete_records('tool_vae_referent', ['userid' => $candidateid]);
        $DB->execute('UPDATE {tool_vae_referent} SET createdby = 0 WHERE createdby = :userid', ['userid' => $candidateid]);

        // What this account sent to the reformulation aid (T-70) is NOT erased here: the core keeps the text and the answer in its
        // own tables, and the deletion request Moodle files at each deletion of an account empties them once a person approves
        // it. One door for everything the core keeps, decided on 27 October 2026 (tests/ai_erasure_left_to_moodle_test.php).
        // That door is shut on a site that switched the automatic requests off, and on a site that defined the purpose and the
        // category of the site in the data registry: the core then never empties what is bound to the site. The confirmation
        // screen of the aid says so (ai_assistant::moodle_erases_the_texts()).

        $records = purge::dossier($candidateid);

        if (!$records) {
            return;
        }

        $attestations = 0;
        foreach ($records as $record) {
            $attestations += (int) ($record->attestations ?? 0);
        }

        dossier_purged::create([
            'context' => self::context_of($event),
            'relateduserid' => $candidateid,
            'other' => [
                'declarations' => count($records),
                'attestations' => $attestations,
            ],
        ])->trigger();
    }

    /**
     * A framework of competencies was deleted in tool_lp: the record that was imported into it has none any more.
     *
     * The core allows it as long as nobody has declared on the framework - it refuses once a user competency, a course or a
     * plan holds one of its competencies - so no declaration is lost here, and none is touched. What is left behind is this
     * plugin's own row of provenance, which still pointed at a framework that does not exist: the screens had to guess,
     * from an id that leads nowhere, what had happened.
     *
     * WHY THE ROW IS KEPT, WITH NO FRAMEWORK, AND NOT DELETED. It is the licence, the attribution, the publication date and,
     * when an administrator declared one, the successor of the record (T-30) - and the declarations of a record are found
     * by its code, which this row owns. Its framework id becomes 0, which is what a first import that never got a framework
     * already writes, and every reader of the plugin treats it as "no framework" (competency_gateway answers nothing for it,
     * index.php warns, the import creates a new framework and takes the row back). Its status says why, in a word no code
     * branches on: the table of the records shows anything but `ok` as a failed import, which is acceptable for a record
     * that cannot be used until it is imported again, and index.php says what to do.
     *
     * THE MARKS OF A DEPARTURE GO WITH THE FRAMEWORK. A competency that had left the record, or that tool_lp had altered, is
     * noted in tool_vae_obsolete against the row; the competencies named there were deleted with the framework, and a mark
     * that points at nothing would be counted in the table of the records for good. A re-import writes them again if they
     * are still true.
     *
     * @param \core\event\competency_framework_deleted $event Carries the deleted framework's id as objectid.
     */
    public static function framework_deleted(\core\event\competency_framework_deleted $event): void {
        global $DB;

        if (!self::tables_exist(['tool_vae_source', 'tool_vae_obsolete'])) {
            return;
        }

        // Never "the records with no framework": an id of zero is what a source that never got one holds.
        $frameworkid = (int) $event->objectid;
        if ($frameworkid <= 0) {
            return;
        }

        foreach ($DB->get_records('tool_vae_source', ['frameworkid' => $frameworkid]) as $source) {
            $DB->delete_records('tool_vae_obsolete', ['sourceid' => (int) $source->id]);
            $DB->update_record('tool_vae_source', (object) [
                'id' => (int) $source->id,
                'frameworkid' => 0,
                'lastimportstatus' => 'deleted',
                'timemodified' => time(),
                'usermodified' => (int) $event->userid,
            ]);
        }
    }

    /**
     * The context to file the trace against.
     *
     * The context of the event that caused the purge, which is the candidate's user context on
     * both paths - delete_user() deletes the CONTENT of that context and keeps the context
     * itself, so it is still there when this runs. It is read from the event rather than
     * rebuilt from an id, because context_user::instance() CREATES a context that is missing,
     * and resurrecting the context of a deleted account in order to log its deletion would be
     * a poor way to record it.
     *
     * The fallback is written against `false`, not `null`: get_context() ends in
     * context::instance_by_id(..., IGNORE_MISSING), which returns FALSE when the context has
     * gone. A null coalesce here would look like a guard and catch nothing.
     *
     * @param \core\event\base $event
     * @return \context
     */
    private static function context_of(\core\event\base $event): \context {
        $context = $event->get_context();

        return $context instanceof \context ? $context : \context_system::instance();
    }

    /**
     * Whether every table this purge writes to is there.
     *
     * Observers are notified during installation and upgrade, before the schema exists. All
     * THREE tables are checked, not just the first one read: a half-built schema is exactly
     * the situation this guard is for, and a guard that clears the way on the strength of one
     * table out of three would let the purge fail on the second. The check costs three cached
     * lookups on two events that happen a few times a year.
     *
     * The handler of a deleted framework names the two tables it writes to instead: it touches neither of the three.
     *
     * @param string[]|null $tables The tables to look for; the three of a purge of declarations when null.
     * @return bool
     */
    private static function tables_exist(?array $tables = null): bool {
        global $DB;

        $tables ??= ['tool_vae_evidence', 'tool_vae_attest', 'tool_vae_identity'];

        $dbman = $DB->get_manager();
        foreach ($tables as $table) {
            if (!$dbman->table_exists($table)) {
                return false;
            }
        }

        return true;
    }
}
