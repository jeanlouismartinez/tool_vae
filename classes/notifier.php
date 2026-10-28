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

use tool_vae\event\attestation_withdrawn;
use tool_vae\event\competency_left_record;
use tool_vae\event\deposit_created;
use tool_vae\event\evidence_attested;
use tool_vae\event\evidence_declared;
use tool_vae\event\opinion_created;
use tool_vae\event\piece_added;
use tool_vae\event\request_created;
use tool_vae\event\withdrawal_refused;
use tool_vae\event\withdrawal_requested;
use tool_vae\local\access;
use tool_vae\local\attestation_manager;
use tool_vae\local\draft_manager;
use tool_vae\local\importer;
use tool_vae\local\piece_manager;
use tool_vae\local\referent_manager;
use tool_vae\task\send_notification;

/**
 * Who is told what, and how little.
 *
 * Lot 7 ter, reprise R2 of the inventory. Until now nobody was told anything: a candidate
 * declared and their manager did not know; a competency left its certification record and the
 * candidate who had declared against it did not know either. Four messages answer that, each
 * hanging off an event this plugin raises:
 *
 *   - declarationpending    to the managers who follow the candidate and may attest;
 *   - evidenceattested      to the candidate;
 *   - attestationwithdrawn  to the candidate;
 *   - competencyleftrecord  to every candidate who declared against the competency, once each.
 *
 * NOT ONE WORD OF THE NARRATIVE LEAVES THE DOSSIER. No situation, no setting, no period, no
 * statement, no job title, no reason for a withdrawal. A message is copied into the
 * notifications table, into an email, into mail servers nobody here administers and onto
 * whatever screen it is read on; the narrative is what the anonymisation reminder exists to
 * protect, and the dossier is the one place built to hold it. A message says THAT something
 * happened, gives the reference of the competency - public data, published by France
 * compétences - and links to the dossier, where the rights guarding the rest are checked again.
 * notifier_test reads every field of every message for the text of the dossier.
 *
 * TWO MOMENTS, TWO PLACES. The observers run in the request that raised the event, and only
 * decide WHO is to be told: one ad hoc task per recipient, carrying identifiers and nothing
 * else. The message is composed and sent from cron, by deliver(). The first version sent from
 * the request, and adversarial review measured the cost: a record that loses a block can concern
 * a hundred candidates, that was a hundred synchronous emails in the import request, and a
 * request killed half way had told half of them - for good, since a departure already committed
 * is never announced again. One task per recipient also means that one failure costs one
 * message, retried by the task manager and visible in the task logs.
 *
 * DELIVERY ASKS AGAIN. Between the event and cron, a right can be removed, an account suspended,
 * a declaration purged or already attested, a departure undone. deliver() asks every
 * question again and sends nothing that is no longer true.
 *
 * A DEPARTURE WAITS A DAY. Importing a truncated file makes competencies "leave" that have not
 * left; importing the right file brings them back. Announced at once, the false departure would
 * have reached every candidate concerned with no way to call it back. The task waits
 * DEPARTURE_GRACE, and sends only if the competency is still absent by then.
 *
 * AN ERASURE WITHDRAWS ATTESTATIONS, AND IS ANNOUNCED LIKE ANY OTHER WITHDRAWAL (decision of 27 October 2026). When the data of
 * a manager is erased - the account deleted, then the request of the data protection tool approved, or the manager's own request
 * - or when the account of a person named by an archive's signature is deleted, the privacy provider and the purge withdraw
 * attestations without raising attestation_withdrawn: the event would be filed under the account that runs the task, the main
 * administrator, who withdrew nothing. They call attestations_erased() instead, which queues, for the candidate, ONE message
 * in the words of the ordinary withdrawal (in the plural when several declarations lost theirs) and, for each manager who
 * follows the candidate and may attest, ONE message that declarations await an attestation again. No name of the signatory, no
 * word of a declaration, no cause: the message states the fact, like the ordinary one, and the dossier carries the reason.
 * THREE PROTECTIONS: it is queued as soon as the erasure is recorded and before the backups are redone - a request that fails
 * there is played again and finds nothing left to withdraw; it never makes the erasure fail (caught, said to a developer); and
 * the tasks of one candidate are merged, so that a batch of approvals sends one message, not one per manager erased.
 *
 * WHO SENDS: THE SITE'S NO-REPLY USER, ALWAYS - never the candidate, never the manager who signed
 * or withdrew. A withdrawal erases who signed, on purpose - see attestation_manager - and a
 * notification "from" them would keep in the candidate's inbox the very name the withdrawal removes.
 * The first versions sent three messages to managers (declaration pending, deposit made, piece after
 * a signature) in the candidate's name, so that the privacy subsystem's erasure, which removes the
 * notifications a person sent as well as those they received, would take them too. The price was
 * paid elsewhere: the core's email output sends a message "from" a user with that user's own address
 * as From and as Reply-To as soon as the site lists their domain in allowedemaildomains and their
 * profile shows their address (email_to_user() in lib/moodlelib.php) - an address this plugin reads
 * from the account and never copies, travelling in the headers to mail servers nobody here
 * administers, with a "Reply" that left the platform for the candidate. The notifications the core
 * keeps, it keeps and erases on its own schedule, as for every plugin; declarationoverdue always
 * named the candidate and was always sent by the no-reply user.
 *
 * WHO HEARS OF A DECLARATION. Those who follow the candidate (lot 7 bis) AND may attest for
 * them. Following without the right to sign is reading, and "awaits your attestation" would
 * be untrue. Signing without following is a right held across the site, and would turn every
 * declaration into a circular; holders of viewany are not told either, for the same reason.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifier {
    /** @var string Provider: a declaration awaits the attestation of a manager who follows the candidate. */
    public const DECLARATION_PENDING = 'declarationpending';

    /** @var string Provider: one of the candidate's declarations was attested. */
    public const EVIDENCE_ATTESTED = 'evidenceattested';

    /** @var string Provider: an attestation was withdrawn from the candidate's dossier. */
    public const ATTESTATION_WITHDRAWN = 'attestationwithdrawn';

    /** @var string Provider: a competency the candidate declared against left its certification record. */
    public const COMPETENCY_LEFT_RECORD = 'competencyleftrecord';

    /** To a signatory: a colleague asks them to withdraw their attestation. */
    public const WITHDRAWAL_REQUESTED = 'withdrawalrequested';

    /** To the manager who asked: the signatory refused. */
    public const WITHDRAWAL_REFUSED = 'withdrawalrefused';

    /** @var string A candidate deposited their dossier de faisabilité (T-21). */
    public const DEPOSIT_MADE = 'depositmade';

    /** @var string A supporting piece was added to a declaration after its signature (T-13, lot 2). */
    public const PIECE_AFTER_ATTEST = 'pieceafterattest';

    /** @var string A manager wrote an opinion on a deposit: told to the candidate (T-10, lot 5b). */
    public const OPINION_MADE = 'opinionmade';

    /** @var string A declaration waited for an attestation for the chosen number of days: told to the managers (T-22). */
    public const DECLARATION_OVERDUE = 'declarationoverdue';

    /** @var string A manager asks the candidate for a precision on a declaration: told to the candidate (T-20). */
    public const PRECISION_ASKED = 'precisionasked';

    /** @var string A declaration was destroyed with the signatures it carried: told to each signatory. */
    public const DECLARATION_PURGED = 'declarationpurged';

    /**
     * @var string Kind of task, not a message type: attestations withdrawn by an erasure, told to the candidate.
     *      Sent under ATTESTATION_WITHDRAWN: the type that exists, and the preferences that come with it.
     */
    public const ATTESTATIONS_ERASED = 'attestationserased';

    /**
     * @var string Kind of task, not a message type: declarations await an attestation again after an erasure, told to a
     *      manager. Sent under DECLARATION_PENDING, the type the ordinary withdrawal uses for the same purpose.
     */
    public const DECLARATIONS_AWAIT_AGAIN = 'declarationsawaitagain';

    /** @var int How long a departure waits before it is announced, in seconds. See the class header. */
    public const DEPARTURE_GRACE = DAYSECS;

    /**
     * @var int How long a message about an erasure waits, in seconds, from the LAST erasure that concerns the candidate.
     *      A bulk approval runs one task of the data protection tool for each request, one after the other: the wait is what
     *      lets the later ones join the message of the first. Renewed by each erasure that joins.
     */
    public const ERASURE_GRACE = 15 * MINSECS;

    /**
     * A candidate declared: queue a message for each manager who follows them and may attest.
     *
     * @param evidence_declared $event
     */
    public static function evidence_declared(evidence_declared $event): void {
        try {
            $candidateid = (int) $event->relateduserid;

            $eligible = fn(int $readerid): bool => access::can_attest($candidateid, $readerid);
            foreach (self::routed_followers($candidateid, (int) $event->objectid, $eligible) as $readerid) {
                if (access::can_attest($candidateid, $readerid)) {
                    self::queue(self::DECLARATION_PENDING, $readerid, $candidateid, [
                        'evidenceid' => (int) $event->objectid,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            self::not_queued(self::DECLARATION_PENDING, $e);
        }
    }

    /**
     * The managers who follow a candidate, those to tell first of a declaration (T-23).
     *
     * The referents of the bloc of the declaration among the followers, when there are any; every follower otherwise. Only the
     * order of who is told changes - the rights of reading and of attesting are asked as before - and a declaration the plugin
     * cannot place is told to every follower, as it always was.
     *
     * THE POINTER IS APPLIED AMONG THE PEOPLE WHO CAN BE TOLD. A referent who follows the candidate but cannot attest, or whose
     * account is suspended, would be chosen and then dropped by the checks that follow, and the managers who can have been told
     * of nothing: a declaration announced to nobody. So the followers are first kept to those the message is for, and only then
     * narrowed to the referents among them.
     *
     * @param int $candidateid
     * @param int $evidenceid A row of tool_vae_evidence.
     * @param callable $eligible Whether a follower is one the message is for (int user id => bool).
     * @return int[]
     */
    private static function routed_followers(int $candidateid, int $evidenceid, callable $eligible): array {
        global $DB;

        $followers = referent_manager::active(array_values(array_filter(access::followers($candidateid), $eligible)));
        $evidence = $DB->get_record('tool_vae_evidence', ['id' => $evidenceid], 'fichecode, ficherang');
        if (!$evidence) {
            return $followers;
        }
        try {
            return referent_manager::route($followers, $evidence->fichecode, $evidence->ficherang);
        } catch (\Throwable $e) {
            // The pointer is a courtesy: when it fails, everyone who follows the candidate is told, as before.
            debugging('tool_vae: the referents could not be read, every follower is told.', DEBUG_DEVELOPER);

            return $followers;
        }
    }

    /**
     * A candidate deposited their dossier de faisabilité: queue a message for each manager who
     * follows them and may read their deposits (T-21).
     *
     * The message carries the candidate's name and the number of the deposit, nothing of it.
     *
     * @param deposit_created $event
     */
    public static function deposit_created(deposit_created $event): void {
        try {
            $candidateid = (int) $event->relateduserid;
            $context = \context_user::instance($candidateid);

            foreach (access::followers($candidateid) as $readerid) {
                if (has_capability('tool/vae:readdeposit', $context, $readerid)) {
                    self::queue(self::DEPOSIT_MADE, $readerid, $candidateid, [
                        'number' => (int) $event->other['number'],
                        'doctype' => (string) ($event->other['doctype'] ?? draft_manager::DOCTYPE_FAISABILITE),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            self::not_queued(self::DEPOSIT_MADE, $e);
        }
    }

    /**
     * A supporting piece was added: when the declaration already carries a signature that did not cover it, tell the managers
     * who follow the candidate and may attest (T-13, lot 2).
     *
     * Nobody reads a piece added after a signature, and it leaves in the archive: this message is what remains of the third
     * control for it. Candidate's name and the reference of the declaration, nothing of the piece.
     *
     * @param piece_added $event
     */
    public static function piece_added(piece_added $event): void {
        try {
            $candidateid = (int) $event->relateduserid;
            $evidenceid = (int) $event->other['evidenceid'];
            $context = \context_user::instance($candidateid);

            if (attestation_manager::active_attestation($evidenceid) === null) {
                return;
            }
            $eligible = fn(int $readerid): bool => access::can_attest($candidateid, $readerid)
                && has_capability('tool/vae:attest', $context, $readerid);
            foreach (self::routed_followers($candidateid, $evidenceid, $eligible) as $readerid) {
                if (access::can_attest($candidateid, $readerid) && has_capability('tool/vae:attest', $context, $readerid)) {
                    self::queue(self::PIECE_AFTER_ATTEST, $readerid, $candidateid, [
                        'evidenceid' => $evidenceid,
                        'pieceid' => (int) $event->objectid,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            self::not_queued(self::PIECE_AFTER_ATTEST, $e);
        }
    }

    /**
     * A manager wrote an opinion on a deposit: queue a message for the candidate.
     *
     * Neither the author nor the text: the number of the deposit. The candidate reads the opinion,
     * and who wrote it, in the dossier.
     *
     * @param opinion_created $event
     */
    public static function opinion_created(opinion_created $event): void {
        try {
            $candidateid = (int) $event->relateduserid;
            self::queue(self::OPINION_MADE, $candidateid, $candidateid, [
                'number' => (int) $event->other['number'],
            ]);
        } catch (\Throwable $e) {
            self::not_queued(self::OPINION_MADE, $e);
        }
    }

    /**
     * Declarations of a candidate have waited for an attestation for this many days: queue a message for
     * each manager who follows them and may attest (T-22). Called by the daily task, not by an event.
     *
     * @param int $candidateid
     * @param int $days
     * @return int How many messages were queued.
     */
    public static function declarations_overdue(int $candidateid, int $days): int {
        $queued = 0;
        try {
            foreach (access::followers($candidateid) as $readerid) {
                if (access::can_attest($candidateid, $readerid)) {
                    self::queue(self::DECLARATION_OVERDUE, $readerid, $candidateid, ['days' => $days]);
                    $queued++;
                }
            }
        } catch (\Throwable $e) {
            self::not_queued(self::DECLARATION_OVERDUE, $e);
            // Said where the administrator reads the task, not only to a developer.
            mtrace('tool_vae: a reminder could not be queued: ' . $e->getMessage());
        }

        return $queued;
    }

    /**
     * A manager asked for a precision on a declaration: queue a message for the candidate.
     *
     * Neither the author nor the question: the candidate reads both in the dossier.
     *
     * @param request_created $event
     */
    public static function request_created(request_created $event): void {
        try {
            $candidateid = (int) $event->relateduserid;
            self::queue(self::PRECISION_ASKED, $candidateid, $candidateid, [
                'requestid' => (int) $event->objectid,
            ]);
        } catch (\Throwable $e) {
            self::not_queued(self::PRECISION_ASKED, $e);
        }
    }

    /**
     * A declaration was attested: queue a message for its candidate.
     *
     * @param evidence_attested $event
     */
    public static function evidence_attested(evidence_attested $event): void {
        try {
            $candidateid = (int) $event->relateduserid;
            self::queue(self::EVIDENCE_ATTESTED, $candidateid, $candidateid, [
                'evidenceid' => (int) $event->other['evidenceid'],
            ]);
        } catch (\Throwable $e) {
            self::not_queued(self::EVIDENCE_ATTESTED, $e);
        }
    }

    /**
     * An attestation was withdrawn: queue a message for the candidate.
     *
     * The message will not say who withdrew it, nor why. The reason is a closed list and names
     * nobody, but it is shown in the dossier, next to the declaration it concerns; the message
     * has no need to carry it outside.
     *
     * @param attestation_withdrawn $event
     */
    public static function attestation_withdrawn(attestation_withdrawn $event): void {
        try {
            $candidateid = (int) $event->relateduserid;
            $evidenceid = (int) $event->other['evidenceid'];

            self::queue(self::ATTESTATION_WITHDRAWN, $candidateid, $candidateid, [
                'evidenceid' => $evidenceid,
            ]);

            // The declaration awaits an attestation again: those who may give it are told, as
            // when it was made. Whoever withdrew is among them if they follow the candidate - a
            // signature given in error is given again. deliver() looks again at delivery and
            // sends nothing if the declaration has been attested meanwhile.
            foreach (access::followers($candidateid) as $readerid) {
                if (access::can_attest($candidateid, $readerid)) {
                    self::queue(self::DECLARATION_PENDING, $readerid, $candidateid, [
                        'evidenceid' => $evidenceid,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            self::not_queued(self::ATTESTATION_WITHDRAWN, $e);
        }
    }

    /**
     * A declaration was destroyed with the signatures it carried: queue a message for each person who signed.
     *
     * The candidate can delete the evidence record of the core in the core's own screen (learning plans, evidence of prior
     * learning), which knows nothing of this plugin and asks nothing of the signatories; the purge that follows (observer)
     * destroys the declaration and every attestation on it. The signature of a third party, which carries weight before a
     * jury, would otherwise disappear without them being told.
     *
     * THE SIGNATORIES COME FROM THE CALLER, read before the purge: once it has run the attestations are gone, and so is
     * the only place that said who signed. The message says THAT it happened and the reference of the competency - the
     * candidate's name, no word of the declaration - like every other.
     *
     * @param \stdClass $record A purged declaration: candidateid, fichecode, ficherang and signatories (user ids).
     */
    public static function declaration_purged(\stdClass $record): void {
        try {
            $candidateid = (int) $record->candidateid;
            $candidate = \core_user::get_user($candidateid);
            // An account that is gone has no one to be told about: its dossier goes with it, signatures included.
            if (!$candidate || !empty($candidate->deleted)) {
                return;
            }

            foreach ($record->signatories ?? [] as $recipientid) {
                self::queue(self::DECLARATION_PURGED, (int) $recipientid, $candidateid, [
                    'fichecode' => (string) $record->fichecode,
                    'ficherang' => (string) $record->ficherang,
                ]);
            }
        } catch (\Throwable $e) {
            self::not_queued(self::DECLARATION_PURGED, $e);
        }
    }

    /**
     * An erasure withdrew attestations from a candidate's declarations: tell the candidate, once, and the managers who follow.
     *
     * CALLED BY THE CODE THAT WITHDREW, not by an event - see the class header. Two circuits reach it: the privacy provider,
     * for a manager whose data is erased (privacy\provider::strip_attestant()), and the purge of an account, which withdraws at
     * once the signatures an archive attributes to it (local\purge::dossier()). Both call it as soon as the withdrawal is
     * recorded and BEFORE they redo the backups: a request that fails there is played again and finds nothing left to withdraw,
     * and a message queued after would be lost for ever.
     *
     * IT NEVER THROWS. The erasure is what matters and a message is not worth failing it: whatever goes wrong is caught and said
     * to a developer, once for the candidate's message and once for the managers' - the one does not depend on the other.
     *
     * ONE TASK FOR EACH RECIPIENT AND CANDIDATE, WHATEVER THE NUMBER OF ERASURES. A task already waiting for the same recipient
     * and candidate takes the declarations of this one, and waits again (queue_merged()). The text is composed at delivery,
     * from the declarations that, by then, still have no attestation.
     *
     * @param int $candidateid Whose dossier lost attestations.
     * @param int[] $evidenceids The declarations that lost an attestation that stood: rows of tool_vae_evidence.
     * @param int $exceptid The account whose data is erased, when it still stands: not asked to attest what it just had
     *        withdrawn. 0 when there is none.
     */
    public static function attestations_erased(int $candidateid, array $evidenceids, int $exceptid = 0): void {
        $evidenceids = self::ids($evidenceids);
        if ($candidateid <= 0 || !$evidenceids) {
            return;
        }
        $runat = time() + self::ERASURE_GRACE;

        try {
            $waiting = self::waiting(self::ATTESTATIONS_ERASED, $candidateid);
            self::queue_merged(self::ATTESTATIONS_ERASED, $candidateid, $candidateid, $evidenceids, $waiting, $runat);
        } catch (\Throwable $e) {
            self::not_queued(self::ATTESTATIONS_ERASED, $e);
        }

        try {
            $waiting = self::waiting(self::DECLARATIONS_AWAIT_AGAIN, $candidateid);
            foreach (access::followers($candidateid) as $readerid) {
                if ($readerid !== $exceptid && access::can_attest($candidateid, $readerid)) {
                    self::queue_merged(
                        self::DECLARATIONS_AWAIT_AGAIN,
                        $readerid,
                        $candidateid,
                        $evidenceids,
                        $waiting,
                        $runat
                    );
                }
            }
        } catch (\Throwable $e) {
            self::not_queued(self::DECLARATIONS_AWAIT_AGAIN, $e);
        }
    }

    /**
     * A manager asked a signatory to withdraw their attestation: the signatory is told.
     *
     * @param withdrawal_requested $event
     */
    public static function withdrawal_requested(withdrawal_requested $event): void {
        global $DB;

        try {
            $attestation = $DB->get_record('tool_vae_attest', ['id' => (int) $event->objectid], '*', MUST_EXIST);
            if ($attestation->attestantid === null) {
                return;
            }

            // Tied to THIS request - who asked and when - so that a message still waiting in the queue
            // after a refusal is not delivered under the next person's request.
            self::queue(self::WITHDRAWAL_REQUESTED, (int) $attestation->attestantid, (int) $event->other['candidateid'], [
                'evidenceid' => (int) $event->other['evidenceid'],
                'requesterid' => (int) $event->userid,
                'requestedat' => (int) $attestation->withdrawreqat,
            ]);
        } catch (\Throwable $e) {
            self::not_queued(self::WITHDRAWAL_REQUESTED, $e);
        }
    }

    /**
     * A signatory refused a request to withdraw: the manager who asked is told.
     *
     * @param withdrawal_refused $event
     */
    public static function withdrawal_refused(withdrawal_refused $event): void {
        try {
            // The related user is the manager who asked; the candidate is in other.
            self::queue(self::WITHDRAWAL_REFUSED, (int) $event->relateduserid, (int) $event->other['candidateid'], [
                'evidenceid' => (int) $event->other['evidenceid'],
            ]);
        } catch (\Throwable $e) {
            self::not_queued(self::WITHDRAWAL_REFUSED, $e);
        }
    }

    /**
     * A competency left its certification record: queue one message per candidate who declared
     * against it, to be sent after the grace period if the departure still stands.
     *
     * ONE MESSAGE PER CANDIDATE, not one per declaration. Someone who declared five activities
     * against the competency has one thing to learn, not five.
     *
     * The claims are found by the competency they were made against, which every declaration has
     * recorded since upgrade step 2026082903. A row older than that column names no competency
     * and is not reached; the plugin has never been used by real candidates, so no such row
     * exists outside a test bench. The column carries no index: a departure is a rare event, and
     * the table holds the declarations of one site.
     *
     * @param competency_left_record $event
     */
    public static function competency_left_record(competency_left_record $event): void {
        global $DB;

        try {
            $competencyid = (int) $event->other['competencyid'];
            $candidateids = $DB->get_fieldset_sql(
                'SELECT DISTINCT candidateid FROM {tool_vae_evidence} WHERE competencyid = :competencyid',
                ['competencyid' => $competencyid]
            );

            foreach ($candidateids as $candidateid) {
                self::queue(self::COMPETENCY_LEFT_RECORD, (int) $candidateid, (int) $candidateid, [
                    'competencyid' => $competencyid,
                    'sourceid' => (int) $event->other['sourceid'],
                    // The mark of THIS departure, so that still_gone() can tell it from a later one.
                    'markid' => (int) $event->objectid,
                ], time() + self::DEPARTURE_GRACE);
            }
        } catch (\Throwable $e) {
            self::not_queued(self::COMPETENCY_LEFT_RECORD, $e);
        }
    }

    /**
     * Send one notification, if it is still true. Called from cron by send_notification.
     *
     * Every question asked when the task was queued is asked again here: the recipient can be
     * reached, the declaration still exists and is the candidate's, the right that made them a
     * recipient still holds, and a departure has not been undone by a later import.
     *
     * @param \stdClass $data provider, recipientid, candidateid; then evidenceid, or competencyid,
     *                        sourceid and markid for a departure, or fichecode and ficherang for a
     *                        declaration that no longer exists, or evidenceids (the declarations)
     *                        for an erasure.
     * @return bool Whether a message was handed to the messaging subsystem and accepted.
     */
    public static function deliver(\stdClass $data): bool {
        $provider = (string) $data->provider;
        $candidateid = (int) $data->candidateid;

        $providers = [
            self::DECLARATION_PENDING,
            self::EVIDENCE_ATTESTED,
            self::ATTESTATION_WITHDRAWN,
            self::COMPETENCY_LEFT_RECORD,
            self::WITHDRAWAL_REQUESTED,
            self::WITHDRAWAL_REFUSED,
            self::DEPOSIT_MADE,
            self::OPINION_MADE,
            self::PRECISION_ASKED,
            self::DECLARATION_OVERDUE,
            self::PIECE_AFTER_ATTEST,
            self::DECLARATION_PURGED,
            self::ATTESTATIONS_ERASED,
            self::DECLARATIONS_AWAIT_AGAIN,
        ];
        if (!in_array($provider, $providers, true)) {
            throw new \coding_exception("Unknown notification '$provider'.");
        }

        $recipient = self::reachable_user((int) $data->recipientid);
        if ($recipient === null) {
            return false;
        }

        // Asked BEFORE the declaration is looked for: it is the one message about a declaration that is gone.
        if ($provider === self::DECLARATION_PURGED) {
            return self::deliver_purged(
                $recipient,
                $candidateid,
                (string) ($data->fichecode ?? ''),
                (string) ($data->ficherang ?? '')
            );
        }

        // Two kinds of task about an erasure: the declarations they carry are looked at again, as they stand now.
        if ($provider === self::ATTESTATIONS_ERASED) {
            return self::deliver_erased($recipient, $candidateid, self::ids((array) ($data->evidenceids ?? [])));
        }

        if ($provider === self::DECLARATIONS_AWAIT_AGAIN) {
            return self::deliver_await_again($recipient, $candidateid, self::ids((array) ($data->evidenceids ?? [])));
        }

        if ($provider === self::DECLARATION_OVERDUE) {
            return self::deliver_overdue($recipient, $candidateid, (int) ($data->days ?? 0));
        }

        if ($provider === self::PRECISION_ASKED) {
            return self::deliver_precision($recipient, $candidateid, (int) ($data->requestid ?? 0));
        }

        if ($provider === self::OPINION_MADE) {
            return self::deliver_opinion($recipient, $candidateid, (int) ($data->number ?? 0));
        }

        if ($provider === self::DEPOSIT_MADE) {
            return self::deliver_deposit(
                $recipient,
                $candidateid,
                (int) ($data->number ?? 0),
                (string) ($data->doctype ?? draft_manager::DOCTYPE_FAISABILITE)
            );
        }

        if ($provider === self::COMPETENCY_LEFT_RECORD) {
            if (!self::still_gone((int) $data->competencyid, (int) $data->sourceid, (int) ($data->markid ?? 0))) {
                return false;
            }
            $claim = self::claim_on($candidateid, (int) $data->competencyid);
        } else {
            $claim = self::claim((int) $data->evidenceid);
        }

        if ($claim === null || (int) $claim->candidateid !== $candidateid) {
            return false;
        }

        if ($provider === self::WITHDRAWAL_REQUESTED || $provider === self::WITHDRAWAL_REFUSED) {
            return self::deliver_withdrawal_exchange($provider, $recipient, $claim, $candidateid, $data);
        }

        if ($provider === self::PIECE_AFTER_ATTEST) {
            return self::deliver_piece_after($recipient, $claim, $candidateid, (int) ($data->pieceid ?? 0));
        }

        if ($provider === self::DECLARATION_PENDING) {
            $readerid = (int) $recipient->id;
            if (!access::follows($candidateid, $readerid) || !access::can_attest($candidateid, $readerid)) {
                return false;
            }

            // Attested in the meantime: it no longer awaits anybody.
            if (attestation_manager::active_attestation((int) $claim->id) !== null) {
                return false;
            }

            $candidate = \core_user::get_user($candidateid);
            if (!$candidate) {
                return false;
            }

            return self::send($provider, $recipient, $candidateid, [
                'candidate' => fullname($candidate),
                'record' => self::reference($claim),
            ]);
        }

        // Every other message goes to the candidate, about their own dossier. A candidate who may not open their
        // dossier is not sent a link to it. The provider's own filter is not enough for this: it requires viewown -
        // see db/messages.php - but looks for it anywhere it is allowed, and a prohibition on the candidate's own
        // context escapes it. The question is therefore asked here, on that context.
        if ((int) $recipient->id !== $candidateid) {
            return false;
        }
        if (!has_capability('tool/vae:viewown', \context_user::instance($candidateid), $candidateid)) {
            return false;
        }

        $a = ['record' => self::reference($claim)];
        if ($provider === self::ATTESTATION_WITHDRAWN) {
            $a['relay'] = self::relay($candidateid, $recipient);
        }

        return self::send($provider, $recipient, $candidateid, $a);
    }

    /**
     * The reminder of the declarations that wait, to a manager, if it is still true: they still follow the
     * candidate and may attest, and at least one declaration still waits for that long. The count is
     * taken again at sending, not carried in the task.
     *
     * @param \stdClass $recipient
     * @param int $candidateid
     * @param int $days
     * @return bool
     */
    private static function deliver_overdue(\stdClass $recipient, int $candidateid, int $days): bool {
        $readerid = (int) $recipient->id;
        if ($days < 1 || !access::follows($candidateid, $readerid) || !access::can_attest($candidateid, $readerid)) {
            return false;
        }
        $waiting = array_filter(
            local\pending_report::awaiting($days),
            fn($row) => (int) $row->candidateid === $candidateid
        );
        $candidate = \core_user::get_user($candidateid);
        if (!$waiting || !$candidate || !empty($candidate->deleted)) {
            return false;
        }

        return self::send(self::DECLARATION_OVERDUE, $recipient, $candidateid, [
            'candidate' => fullname($candidate),
            'count' => count($waiting),
            'days' => $days,
        ]);
    }

    /**
     * The notification of a request for precision, to the candidate, if it is still true: the request
     * still exists, is about one of their declarations, and still awaits an answer.
     *
     * @param \stdClass $recipient
     * @param int $candidateid
     * @param int $requestid
     * @return bool
     */
    private static function deliver_precision(\stdClass $recipient, int $candidateid, int $requestid): bool {
        global $DB;

        if ((int) $recipient->id !== $candidateid) {
            return false;
        }
        // The right to answer (request_manager::can_answer()), not only to draft: a message that asks for an answer the
        // candidate may not give would lead nowhere.
        $context = \context_user::instance($candidateid);
        if (
            !has_capability('tool/vae:declare', $context, $candidateid)
            || !has_capability('tool/vae:viewown', $context, $candidateid)
        ) {
            return false;
        }
        $request = $DB->get_record('tool_vae_request', ['id' => $requestid]);
        if (!$request || $request->answer !== null) {
            return false;
        }
        if (!$DB->record_exists('tool_vae_evidence', ['id' => $request->evidenceid, 'candidateid' => $candidateid])) {
            return false;
        }

        return self::send(self::PRECISION_ASKED, $recipient, $candidateid, []);
    }

    /**
     * The notification of an opinion, to the candidate, if it is still true: the deposit still exists,
     * is theirs, and carries an opinion, and they may still open their dossier.
     *
     * @param \stdClass $recipient
     * @param int $candidateid
     * @param int $number The number of the deposit.
     * @return bool
     */
    private static function deliver_opinion(\stdClass $recipient, int $candidateid, int $number): bool {
        global $DB;

        if ((int) $recipient->id !== $candidateid) {
            return false;
        }
        if (!has_capability('tool/vae:draftown', \context_user::instance($candidateid), $candidateid)) {
            return false;
        }
        $deposit = $DB->get_record('tool_vae_deposit', [
            'candidateid' => $candidateid,
            'doctype' => draft_manager::DOCTYPE_FAISABILITE,
            'number' => $number,
        ]);
        if (!$deposit || !$DB->record_exists('tool_vae_opinion', ['depositid' => $deposit->id])) {
            return false;
        }

        return self::send(self::OPINION_MADE, $recipient, $candidateid, [
            'number' => $number,
        ]);
    }

    /**
     * The notification of a deposit, if it is still true: the reader still follows the candidate,
     * still holds the right to read deposits, and the deposit still exists.
     *
     * @param \stdClass $recipient
     * @param int $candidateid
     * @param int $number The number of the deposit.
     * @param string $doctype The document deposited.
     * @return bool
     */
    private static function deliver_deposit(
        \stdClass $recipient,
        int $candidateid,
        int $number,
        string $doctype = draft_manager::DOCTYPE_FAISABILITE
    ): bool {
        global $DB;

        if (!in_array($doctype, [draft_manager::DOCTYPE_FAISABILITE, draft_manager::DOCTYPE_VALIDATION], true)) {
            return false;
        }

        $readerid = (int) $recipient->id;
        if (
            !access::follows($candidateid, $readerid)
            || !has_capability('tool/vae:readdeposit', \context_user::instance($candidateid), $readerid)
        ) {
            return false;
        }
        $where = ['candidateid' => $candidateid, 'doctype' => $doctype, 'number' => $number];
        if (!$DB->record_exists('tool_vae_deposit', $where)) {
            return false;
        }

        $candidate = \core_user::get_user($candidateid);
        if (!$candidate || !empty($candidate->deleted)) {
            return false;
        }

        // The preparation of the validation dossier has its own words: it is not the dossier de faisabilité.
        $words = $doctype === draft_manager::DOCTYPE_VALIDATION ? 'validationdepositmade' : self::DEPOSIT_MADE;

        return self::send(self::DEPOSIT_MADE, $recipient, $candidateid, [
            'candidate' => fullname($candidate),
            'number' => $number,
        ], $words);
    }

    /**
     * The message of a piece added after a signature, looked at again when it is sent.
     *
     * Still true for the manager to be told: they still follow the candidate and may attest, the piece is still there, and
     * the signature standing does not cover it.
     *
     * @param \stdClass $recipient
     * @param \stdClass $claim The declaration.
     * @param int $candidateid
     * @param int $pieceid
     * @return bool
     */
    private static function deliver_piece_after(\stdClass $recipient, \stdClass $claim, int $candidateid, int $pieceid): bool {
        global $DB;

        $readerid = (int) $recipient->id;
        if (!access::follows($candidateid, $readerid) || !access::can_attest($candidateid, $readerid)) {
            return false;
        }
        $piece = $DB->get_record('tool_vae_piece', ['id' => $pieceid, 'evidenceid' => (int) $claim->id]);
        $standing = attestation_manager::active_attestation((int) $claim->id);
        if (!$piece || $piece->timeremoved !== null || $standing === null) {
            return false;
        }
        if (array_key_exists($pieceid, piece_manager::covered_by((int) $standing->id))) {
            return false;
        }
        $candidate = \core_user::get_user($candidateid);
        if (!$candidate || !empty($candidate->deleted)) {
            return false;
        }

        return self::send(self::PIECE_AFTER_ATTEST, $recipient, $candidateid, [
            'candidate' => fullname($candidate),
            'record' => self::reference($claim),
        ]);
    }

    /**
     * The message of a declaration destroyed with its signatures, looked at again when it is sent.
     *
     * There is no declaration to look at: that is the message. What is asked again is what can still be: the signatory can be
     * reached (the caller has checked), the candidate's account is still there, and the right that made the signatory a
     * recipient - to attest - still holds on the candidate's own context. The reference comes from the task, public data
     * published by France compétences, and no other word of the declaration.
     *
     * @param \stdClass $recipient The signatory.
     * @param int $candidateid
     * @param string $fichecode
     * @param string $ficherang
     * @return bool
     */
    private static function deliver_purged(\stdClass $recipient, int $candidateid, string $fichecode, string $ficherang): bool {
        if ($fichecode === '' || $ficherang === '') {
            return false;
        }
        $candidate = \core_user::get_user($candidateid);
        if (!$candidate || !empty($candidate->deleted)) {
            return false;
        }
        if (!access::can_attest($candidateid, (int) $recipient->id)) {
            return false;
        }

        return self::send(self::DECLARATION_PURGED, $recipient, $candidateid, [
            'candidate' => fullname($candidate),
            'record' => $fichecode . '-' . $ficherang,
        ]);
    }

    /**
     * The message to the candidate about attestations an erasure withdrew, looked at again when it is sent.
     *
     * THE WORDS ARE THOSE OF THE ORDINARY WITHDRAWAL, in the plural when several declarations lost their attestation: the
     * number of declarations that, now, still have none among those the erasure concerned, that their declarations are
     * unchanged, that the dossier shows the date and the reason, and who can give a new attestation. Sent under the type that
     * exists for a withdrawal, so that the candidate's preferences apply. No name of the signatory, no word of a declaration, and
     * no cause: the erasure may be the manager's own request or the routine request Moodle files for a deleted account, and
     * this message does not say which. A declaration attested again since, or destroyed, is not counted; when none is left,
     * nothing is sent. The reference of the one declaration is public data, like in every other message.
     *
     * @param \stdClass $recipient The candidate.
     * @param int $candidateid
     * @param int[] $evidenceids The declarations that lost a standing attestation when the erasure was recorded.
     * @return bool
     */
    private static function deliver_erased(\stdClass $recipient, int $candidateid, array $evidenceids): bool {
        if ((int) $recipient->id !== $candidateid) {
            return false;
        }
        // The question every message to a candidate asks again, on their own context: see deliver().
        if (!has_capability('tool/vae:viewown', \context_user::instance($candidateid), $candidateid)) {
            return false;
        }

        $claims = array_values(self::claims_without_attestation($candidateid, $evidenceids));
        if (!$claims) {
            return false;
        }

        if (count($claims) === 1) {
            return self::send(self::ATTESTATION_WITHDRAWN, $recipient, $candidateid, [
                'record' => self::reference($claims[0]),
                'relay' => self::relay($candidateid, $recipient),
            ]);
        }

        return self::send(self::ATTESTATION_WITHDRAWN, $recipient, $candidateid, [
            'count' => count($claims),
            'relay' => self::relay($candidateid, $recipient, true),
        ], 'attestationswithdrawn');
    }

    /**
     * The message to a manager that declarations of a candidate await an attestation again, looked at again when it is sent.
     *
     * What the ordinary withdrawal tells those who follow, once for the candidate rather than once for each declaration: the
     * manager still follows the candidate and may attest, the candidate's account stands, and at least one of the declarations
     * the erasure concerned still has no attestation. Sent under the type of a declaration that awaits. No name of the person
     * whose data was erased.
     *
     * @param \stdClass $recipient The manager.
     * @param int $candidateid
     * @param int[] $evidenceids The declarations that lost a standing attestation when the erasure was recorded.
     * @return bool
     */
    private static function deliver_await_again(\stdClass $recipient, int $candidateid, array $evidenceids): bool {
        $readerid = (int) $recipient->id;
        if (!access::follows($candidateid, $readerid) || !access::can_attest($candidateid, $readerid)) {
            return false;
        }
        $candidate = \core_user::get_user($candidateid);
        if (!$candidate || !empty($candidate->deleted)) {
            return false;
        }

        $claims = array_values(self::claims_without_attestation($candidateid, $evidenceids));
        if (!$claims) {
            return false;
        }

        if (count($claims) === 1) {
            return self::send(self::DECLARATION_PENDING, $recipient, $candidateid, [
                'candidate' => fullname($candidate),
                'record' => self::reference($claims[0]),
            ]);
        }

        return self::send(self::DECLARATION_PENDING, $recipient, $candidateid, [
            'candidate' => fullname($candidate),
            'count' => count($claims),
        ], 'declarationsawaitagain');
    }

    /**
     * Those of the given declarations that exist, are the candidate's, and have no attestation standing now.
     *
     * Four columns, as claim() reads: the narrative is not loaded. Asked at delivery, never carried in the task: it is what
     * makes a message say only what is still true.
     *
     * @param int $candidateid
     * @param int[] $evidenceids
     * @return \stdClass[] id, candidateid, fichecode and ficherang, keyed by id.
     */
    private static function claims_without_attestation(int $candidateid, array $evidenceids): array {
        global $DB;

        if (!$evidenceids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($evidenceids, SQL_PARAMS_NAMED);

        return $DB->get_records_sql(
            "SELECT e.id, e.candidateid, e.fichecode, e.ficherang
               FROM {tool_vae_evidence} e
              WHERE e.candidateid = :candidateid
                AND e.id $insql
                AND NOT EXISTS (
                    SELECT 1 FROM {tool_vae_attest} a WHERE a.evidenceid = e.id AND a.withdrawnat IS NULL
                )
           ORDER BY e.id",
            ['candidateid' => $candidateid] + $params
        );
    }

    /**
     * The sentence that tells the candidate who takes over after a withdrawal.
     *
     * Those who follow the candidate and may attest - the people the declaration awaits now.
     * Read when the message is sent, not when the withdrawal was made: it says who can act today.
     * Named by their full name, which the candidate already knows them by - they were attached to
     * the candidate by the administrator. Nobody to name is said plainly, and the candidate is
     * sent to the administrator rather than left waiting for a signature nobody can give.
     *
     * @param int $candidateid
     * @param \stdClass $recipient The candidate, for the language of the sentence.
     * @param bool $several Whether the message is about several declarations: the sentence is then in the plural.
     * @return string
     */
    private static function relay(int $candidateid, \stdClass $recipient, bool $several = false): string {
        $strings = get_string_manager();
        $lang = !empty($recipient->lang) && $strings->translation_exists($recipient->lang, false) ? $recipient->lang : null;

        $names = [];
        foreach (access::followers($candidateid) as $readerid) {
            if (!access::can_attest($candidateid, $readerid)) {
                continue;
            }
            $user = \core_user::get_user($readerid);
            if ($user && !$user->deleted && !$user->suspended) {
                $names[] = fullname($user);
            }
        }

        if (!$names) {
            return $strings->get_string('notifyrelay_none', 'tool_vae', null, $lang);
        }

        $key = $several ? 'notifyrelaymany_some' : 'notifyrelay_some';

        return $strings->get_string($key, 'tool_vae', implode(', ', $names), $lang);
    }

    /**
     * The two messages of a request to withdraw, both between managers.
     *
     * Looked at again at delivery, like every message of this plugin: the request must still be
     * open for the signatory to be told of it, and the recipient must still be able to act on the dossier.
     *
     * THE TWO MESSAGES DO NOT ASK THE SAME THING OF THEIR RECIPIENT. The request goes to a signatory who must still follow the
     * candidate, as every message to a manager does. The refusal is the answer to the one person who asked: they asked because
     * the dossier let them - may_request_withdrawal() asks for the right to attest, which a reader of every dossier holds
     * without following anybody - and the answer follows the question. Told only to those who follow, it was lost on exactly
     * the person who could ask without following; a reply to one person is not a circular.
     *
     * @param string $provider WITHDRAWAL_REQUESTED or WITHDRAWAL_REFUSED.
     * @param \stdClass $recipient
     * @param \stdClass $claim The declaration.
     * @param int $candidateid
     * @param \stdClass $data The task's custom data.
     * @return bool
     */
    private static function deliver_withdrawal_exchange(
        string $provider,
        \stdClass $recipient,
        \stdClass $claim,
        int $candidateid,
        \stdClass $data
    ): bool {
        if (!access::can_attest($candidateid, (int) $recipient->id)) {
            return false;
        }
        if ($provider === self::WITHDRAWAL_REQUESTED && !access::follows($candidateid, (int) $recipient->id)) {
            return false;
        }

        $candidate = \core_user::get_user($candidateid);
        if (!$candidate) {
            return false;
        }

        $a = ['candidate' => fullname($candidate), 'record' => self::reference($claim)];

        if ($provider === self::WITHDRAWAL_REQUESTED) {
            $standing = attestation_manager::active_attestation((int) $claim->id);
            if (
                $standing === null
                || (int) $standing->attestantid !== (int) $recipient->id
                || ($standing->withdrawreqat ?? null) === null
                || (int) $standing->withdrawreqby !== (int) $data->requesterid
                || (int) $standing->withdrawreqat !== (int) ($data->requestedat ?? 0)
            ) {
                return false;
            }

            $requester = \core_user::get_user((int) $data->requesterid);
            if (!$requester || $requester->deleted) {
                return false;
            }
            $a['requester'] = fullname($requester);
        }

        return self::send($provider, $recipient, $candidateid, $a);
    }

    /**
     * Queue one notification for one recipient.
     *
     * @param string $provider
     * @param int $recipientid
     * @param int $candidateid Whose dossier the message is about.
     * @param array $about Identifiers of what the message is about. Identifiers only.
     * @param int $runat When to send it; as soon as cron runs when 0.
     */
    private static function queue(string $provider, int $recipientid, int $candidateid, array $about, int $runat = 0): void {
        $task = new send_notification();
        $task->set_custom_data((object) ([
            'provider' => $provider,
            'recipientid' => $recipientid,
            'candidateid' => $candidateid,
        ] + $about));

        if ($runat > 0) {
            $task->set_next_run_time($runat);
        }

        \core\task\manager::queue_adhoc_task($task);
    }

    /**
     * The identifiers of declarations, cleaned: integers above zero, each once, in order.
     *
     * @param array $values
     * @return int[]
     */
    private static function ids(array $values): array {
        $ids = array_unique(array_filter(array_map('intval', $values), fn($id) => $id > 0));
        sort($ids);

        return array_values($ids);
    }

    /**
     * The messages about an erasure of one kind that wait for a candidate, by recipient.
     *
     * Only the tasks that have not started: one that is running is being delivered and cannot take more.
     *
     * @param string $provider ATTESTATIONS_ERASED or DECLARATIONS_AWAIT_AGAIN.
     * @param int $candidateid
     * @return array[] Recipient id => ['taskids' => int[], 'evidenceids' => int[]].
     */
    private static function waiting(string $provider, int $candidateid): array {
        $waiting = [];
        foreach (\core\task\manager::get_adhoc_tasks(send_notification::class, false, true) as $task) {
            $data = $task->get_custom_data();
            if (
                !is_object($data)
                || ($data->provider ?? null) !== $provider
                || (int) ($data->candidateid ?? 0) !== $candidateid
            ) {
                continue;
            }

            $recipientid = (int) ($data->recipientid ?? 0);
            $waiting[$recipientid] ??= ['taskids' => [], 'evidenceids' => []];
            $waiting[$recipientid]['taskids'][] = (int) $task->get_id();
            $waiting[$recipientid]['evidenceids'] = array_merge(
                $waiting[$recipientid]['evidenceids'],
                self::ids((array) ($data->evidenceids ?? []))
            );
        }

        return $waiting;
    }

    /**
     * Queue a message about an erasure, taking in the declarations of the one that already waits for the same recipient.
     *
     * THE NEW TASK IS WRITTEN BEFORE THE OLD ONE IS DELETED: a failure between the two leaves the message twice, never none.
     * The wait starts again from now, so that the erasures of a batch, which are run one after the other, end in one message.
     *
     * @param string $provider ATTESTATIONS_ERASED or DECLARATIONS_AWAIT_AGAIN.
     * @param int $recipientid
     * @param int $candidateid
     * @param int[] $evidenceids The declarations of this erasure.
     * @param array[] $waiting As waiting() answers.
     * @param int $runat When to send it.
     */
    private static function queue_merged(
        string $provider,
        int $recipientid,
        int $candidateid,
        array $evidenceids,
        array $waiting,
        int $runat
    ): void {
        $known = $waiting[$recipientid] ?? ['taskids' => [], 'evidenceids' => []];

        self::queue(
            $provider,
            $recipientid,
            $candidateid,
            ['evidenceids' => self::ids(array_merge($known['evidenceids'], $evidenceids))],
            $runat
        );
        foreach ($known['taskids'] as $taskid) {
            \core\task\manager::delete_adhoc_task($taskid);
        }
    }

    /**
     * Leave a trace of a notification that could not even be queued.
     *
     * Caught here rather than left to the event manager, which catches an \Exception but lets an
     * \Error through - into the request that raised the event, after its act has been committed:
     * the person would see an error page for something that was in fact recorded, and might do it
     * again. Queuing is one insert; a failure there is not expected, and is not worth an act.
     *
     * @param string $provider
     * @param \Throwable $e
     */
    private static function not_queued(string $provider, \Throwable $e): void {
        debugging("tool_vae: the '$provider' notification could not be queued: " . $e->getMessage(), DEBUG_DEVELOPER);
    }

    /**
     * Compose a message in the recipient's language, and send it.
     *
     * THE TEXT IS BUILT FROM SIX THINGS AND NOTHING ELSE: a fixed sentence, the candidate's name
     * (to their managers only), the reference of a competency, a link to the dossier, the name of
     * that link, and - in two messages only - a manager's name: the requester's, to the signatory
     * they ask; the managers who take over, to the candidate. Those names are declared in
     * privacy:metadata:coremessage. A seventh would be added here, under this comment. (A number - how many declarations -
     * is not text of the dossier, and the messages about several declarations carry it, as the reminder of the pending ones.)
     *
     * The language is passed to the string manager, never forced on the session: the first
     * version forced it, and a request that died while sending would have left the recipient's
     * language on whoever caused the message.
     *
     * The sender is not a parameter: it is the site's no-reply user, for every message (see the class header).
     *
     * @param string $provider One of this class's constants.
     * @param \stdClass $recipient
     * @param int $candidateid Whose dossier the message is about.
     * @param array $a Values for the strings.
     * @param string|null $words The strings to use when they are not those of the provider.
     * @return bool Whether the messaging subsystem accepted the message.
     */
    private static function send(
        string $provider,
        \stdClass $recipient,
        int $candidateid,
        array $a,
        ?string $words = null
    ): bool {
        $strings = get_string_manager();
        $lang = !empty($recipient->lang) && $strings->translation_exists($recipient->lang, false) ? $recipient->lang : null;

        // The strings are those of the provider, unless the caller names others under the same provider.
        $words ??= $provider;
        $a = (object) $a;
        $subject = $strings->get_string("notify{$words}_subject", 'tool_vae', $a, $lang);
        $body = $strings->get_string("notify{$words}_body", 'tool_vae', $a, $lang);
        $linkname = $strings->get_string('notifyopendossier', 'tool_vae', null, $lang);
        $url = new \moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);

        $message = new \core\message\message();
        $message->component = 'tool_vae';
        $message->name = $provider;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $recipient;
        $message->subject = $subject;
        $message->fullmessage = $body . "\n\n" . $url->out(false);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = \html_writer::tag('p', s($body))
            . \html_writer::tag('p', \html_writer::link($url, s($linkname)));
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $url->out(false);
        $message->contexturlname = $linkname;
        $message->courseid = SITEID;

        return message_send($message) !== false;
    }

    /**
     * A declaration of this plugin, reduced to what a message may use of it.
     *
     * Four columns, read by name. The narrative is not even loaded: what is not in memory cannot
     * end up in a message by mistake.
     *
     * @param int $evidenceid
     * @return \stdClass|null
     */
    private static function claim(int $evidenceid): ?\stdClass {
        global $DB;

        $claim = $DB->get_record(
            'tool_vae_evidence',
            ['id' => $evidenceid],
            'id, candidateid, fichecode, ficherang'
        );

        return $claim ?: null;
    }

    /**
     * One declaration of a candidate against a competency, the same four columns.
     *
     * @param int $candidateid
     * @param int $competencyid
     * @return \stdClass|null
     */
    private static function claim_on(int $candidateid, int $competencyid): ?\stdClass {
        global $DB;

        $claims = $DB->get_records(
            'tool_vae_evidence',
            ['candidateid' => $candidateid, 'competencyid' => $competencyid],
            'id ASC',
            'id, candidateid, fichecode, ficherang',
            0,
            1
        );

        return $claims ? reset($claims) : null;
    }

    /**
     * Whether the departure a task was queued for still stands.
     *
     * ASKED OF THE MARK, NOT OF THE COMPETENCY. Gone, back, then gone again within the grace
     * period queues two tasks; asked of (competency, record, kind), both found the second mark
     * and the candidate was told twice of one departure. A return deletes the mark - see
     * importer::apply() - so the first task, holding the id of that deleted mark, falls silent.
     *
     * A task queued before lot 8 quater carries no mark id, and is asked the old question.
     *
     * @param int $competencyid
     * @param int $sourceid
     * @param int $markid The tool_vae_obsolete row the departure created; 0 when unknown.
     * @return bool
     */
    private static function still_gone(int $competencyid, int $sourceid, int $markid = 0): bool {
        global $DB;

        $conditions = [
            'competencyid' => $competencyid,
            'sourceid' => $sourceid,
            'kind' => importer::GONE,
        ];
        if ($markid > 0) {
            $conditions['id'] = $markid;
        }

        return $DB->record_exists('tool_vae_obsolete', $conditions);
    }

    /**
     * The reference of the competency a claim was made against, such as `RNCP36832-C03`.
     *
     * Public data, published by France compétences, and what tells the reader WHICH declaration
     * a message is about without quoting it. Read from this plugin's own row, as written when the
     * claim was made: no call to the core (MC-5), and a reference that stays the same whatever
     * later happened to the competency.
     *
     * @param \stdClass $claim
     * @return string
     */
    private static function reference(\stdClass $claim): string {
        return $claim->fichecode . '-' . $claim->ficherang;
    }

    /**
     * A recipient, or null when there is nobody to reach: unknown, deleted or suspended.
     *
     * @param int $userid
     * @return \stdClass|null
     */
    private static function reachable_user(int $userid): ?\stdClass {
        $user = \core_user::get_user($userid);

        if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
            return null;
        }

        return $user;
    }
}
