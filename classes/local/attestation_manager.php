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

namespace tool_vae\local;

use tool_vae\event\attestation_denied;
use tool_vae\event\attestation_withdrawn;
use tool_vae\event\evidence_attested;

/**
 * Attesting a declaration, and withdrawing that attestation.
 *
 * ACCEPTANCE CRITERION 1 BIS, IN ONE SENTENCE: an attestation is a row of this plugin, and
 * withdrawing it leaves the candidate's evidence untouched.
 *
 * This class calls no core competency API at all. Not "as little as possible" - none. (The lock
 * and the backup refresh that a withdrawal uses touch no competency table.) That is the
 * whole reason the attestation was moved off the core record in correction C2 of the
 * blueprint: the core subsystem offers no way to attest a piece of prior-learning
 * evidence and then un-attest it without destroying the evidence itself. Holding the
 * signature here is what makes INITIAL.md's requirement - withdrawable without loss -
 * expressible at all.
 *
 * WITHDRAWAL ERASES FOUR FIELDS, NOT ONE. attestantid, attestantrole, statement and
 * attestedat all go. A withdrawn attestation that keeps "Nursing manager, Les Tilleuls care
 * home" and "I, the undersigned Claire Dupont..." believes itself anonymised and spells the
 * person's name out in full. preuve.schema.json refuses that shape: a `retiree` attestation
 * may carry only a date and a reason, and sixteen counter-examples in the schema's own test
 * set prove it. The privacy provider does the same thing for the same reason - see
 * \tool_vae\privacy\provider::strip_attestant().
 *
 * usermodified goes with them - to 0, not to the person withdrawing. It held the signatory's
 * id from the moment they signed, and rewriting it with the withdrawer's id puts a name back
 * on the row in the very case where the reason is 'rgpd' and the signatory is the one asking.
 *
 * The consequence is deliberate and worth stating plainly: once withdrawn, THE PLUGIN CAN
 * NO LONGER SAY WHO SIGNED. The withdrawal is traced in the log store, by the event, under
 * the site's own retention rules. The dossier keeps "attested, then withdrawn on this date
 * for this reason", which is what a panel needs, and nothing more, which is what the
 * signatory is owed.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attestation_manager {
    /**
     * Why an attestation may be withdrawn.
     *
     * Closed, and identical to the enumeration of preuve.schema.json. A free-text reason
     * would be a fourth place to write a person's name, on a record whose whole purpose at
     * that point is to no longer carry one.
     *
     * @var string[]
     */
    public const WITHDRAWAL_REASONS = ['rgpd', 'erreur', 'contestation'];

    /** Longest accepted job title and establishment. Matches the column. */
    public const ROLE_MAXLENGTH = 255;

    /**
     * Require permission to attest for a candidate, logging the refusal first.
     *
     * has_capability() then an explicit throw, never require_capability(): the latter throws
     * on the spot, and no line written after it ever runs. Criterion 2 asks for the refused
     * attempt to be recorded, so the record has to come first. The same reasoning as
     * evidence_manager::declare_activity(), and the same trap the first blueprint fell into
     * by prescribing require_capability() AND an event before the exception.
     *
     * ONE REFUSAL, WHATEVER THE CAUSE: no capability, no reading of the dossier, or - in attest()
     * and attest.php - an id that names no declaration. Naming the capability told a manager who
     * could not read the dossier that they could not attest, and any difference between the
     * causes tells a stranger which ids are declarations (M-3, lot 8 ter).
     *
     * @param int $candidateid The dossier's owner.
     * @param int $evidenceid The claim the attempt was aimed at, 0 when not yet known.
     * @return \context_user The candidate's user context.
     */
    public static function require_can_attest(int $candidateid, int $evidenceid = 0): \context_user {
        $context = \context_user::instance($candidateid);

        if (access::can_attest($candidateid)) {
            return $context;
        }

        self::log_refusal($context, $candidateid, $evidenceid);

        // No link: the candidate's context would put their profile on the error page, telling a
        // stranger whose declaration that id was.
        throw new \moodle_exception('errorattestrefused', 'tool_vae');
    }

    /** @var int Seconds a second attest() on the same claim waits for the first (T-76). */
    private const LOCK_TIMEOUT = 10;

    /**
     * Attest a declaration.
     *
     * @param int $evidenceid Row of tool_vae_evidence being attested.
     * @param \stdClass $data attestantrole and statement; and, for a declaration that has supporting pieces (T-13),
     *        piecesseen (the list the signatory was shown, as piece_manager::list_token() writes it) and piecesread
     *        (the box ticked). The list is compared, under the lock, with the list there is: a piece added or removed
     *        since the screen was drawn refuses the signature, and the list signed is kept with it.
     * @return \stdClass The stored attestation.
     * @throws \moodle_exception errorattestpieceschanged, errorattestpiecesconfirm, and the others of the attestation
     */
    public function attest(int $evidenceid, \stdClass $data): \stdClass {
        global $DB, $USER;

        // An id that names no declaration is refused as one the caller may not attest - see
        // require_can_attest(). Nothing to log: there is no candidate to log it against.
        $evidence = $DB->get_record('tool_vae_evidence', ['id' => $evidenceid]);
        if (!$evidence) {
            throw new \moodle_exception('errorattestrefused', 'tool_vae');
        }
        $candidateid = (int) $evidence->candidateid;

        // Nobody attests their own declaration. The signature is worth exactly the
        // distance between the two people; without that distance it is a second copy of the
        // claim wearing the clothes of a proof. Checked before the capability, because a
        // candidate may legitimately hold tool/vae:attest for other people's
        // dossiers and would otherwise pass.
        if ($candidateid === (int) $USER->id) {
            self::log_refusal(\context_user::instance($candidateid), $candidateid, $evidenceid);

            throw new \moodle_exception('errorattestown', 'tool_vae');
        }

        $context = self::require_can_attest($candidateid, $evidenceid);

        $role = trim((string) ($data->attestantrole ?? ''));
        $statement = trim((string) ($data->statement ?? ''));

        if ($role === '') {
            throw new \moodle_exception('errorattestantrolemissing', 'tool_vae');
        }
        if (\core_text::strlen($role) > self::ROLE_MAXLENGTH) {
            throw new \moodle_exception('errorattestantroletoolong', 'tool_vae');
        }
        if ($statement === '') {
            throw new \moodle_exception('errorstatementempty', 'tool_vae');
        }

        // The read and the write are one act. The table carries no unique index able to
        // express "at most one attestation not yet withdrawn per claim" - no portable index
        // can - so a lock per claim is what keeps the pair consistent (T-76).
        //
        // The transaction alone narrowed the window and did not close it: two managers pressing
        // the button in the same instant could both read "none" and both insert. The duplicate was
        // not only visible - the export wrote both, and the import refuses a claim carrying two
        // attestations in force, so the archive came out and could not come back. The lock
        // serialises attest() per claim and the state is read again under it, as withdraw() does.
        // The release is in finally: a refusal, an exception or a rollback never leaves it held.
        $lock = \core\lock\lock_config::get_lock_factory('tool_vae_attest')
            ->get_lock('evidence-' . $evidenceid, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }

        // The lock of the candidate's pieces too (T-13): the list is read and the signature written with nobody adding or
        // removing a piece between the two. Taken after the lock of the claim, and nobody takes them the other way round.
        try {
            $piecelock = piece_manager::lock($candidateid);
        } catch (\Throwable $e) {
            $lock->release();
            throw $e;
        }

        try {
            $transaction = $DB->start_delegated_transaction();

            try {
                if (self::active_attestation($evidenceid) !== null) {
                    throw new \moodle_exception('errorattestexists', 'tool_vae');
                }

                // What the signatory saw is what there is, or the signature does not stand.
                $pieces = piece_manager::active_for_evidence($evidenceid);
                if ((string) ($data->piecesseen ?? '') !== piece_manager::list_token($pieces)) {
                    throw new \moodle_exception('errorattestpieceschanged', 'tool_vae');
                }
                if ($pieces && empty($data->piecesread)) {
                    throw new \moodle_exception('errorattestpiecesconfirm', 'tool_vae');
                }

                $now = time();
                $record = (object) [
                    'uuid' => identity_map::generate_ulid(),
                    'evidenceid' => $evidenceid,
                    'attestantid' => (int) $USER->id,
                    'attestantrole' => $role,
                    'statement' => $statement,
                    'attestedat' => $now,
                    'withdrawnat' => null,
                    'withdrawnreason' => null,
                    'piecesread' => $pieces ? 1 : null,
                    'timecreated' => $now,
                    'timemodified' => $now,
                    'usermodified' => (int) $USER->id,
                ];
                $record->id = $DB->insert_record('tool_vae_attest', $record);
                foreach ($pieces as $piece) {
                    $DB->insert_record('tool_vae_attest_piece', (object) [
                        'attestid' => (int) $record->id,
                        'pieceid' => (int) $piece->id,
                        'sha256' => $piece->kind === piece_manager::KIND_FILE ? $piece->sha256 : null,
                    ]);
                }

                $transaction->allow_commit();
            } catch (\Throwable $e) {
                // The rollback() call rethrows; the line after it is never reached.
                $transaction->rollback($e);
                throw $e;
            }
        } finally {
            $piecelock->release();
            $lock->release();
        }

        evidence_attested::create([
            'context' => $context,
            'objectid' => (int) $record->id,
            'relateduserid' => $candidateid,
            'other' => ['evidenceid' => $evidenceid],
        ])->trigger();

        return $record;
    }

    /**
     * Withdraw an attestation, keeping the claim it was attached to.
     *
     * Who may is decided by require_can_withdraw(). Once the row is written,
     * the candidate's backups are replaced by fresh ones (backup::refresh()); an archive already
     * handed out by the exporter is not, and cannot be, called back (MC-9).
     *
     * @param int $attestationid
     * @param string $reason One of WITHDRAWAL_REASONS.
     * @return \stdClass The attestation as it now stands.
     */
    public function withdraw(int $attestationid, string $reason): \stdClass {
        global $DB, $USER;

        $attestation = $DB->get_record('tool_vae_attest', ['id' => $attestationid]);
        if (!$attestation) {
            throw new \moodle_exception('errorunknownattestation', 'tool_vae');
        }

        $evidence = self::evidence_or_fail((int) $attestation->evidenceid);
        $candidateid = (int) $evidence->candidateid;

        // WHY BEFORE THE RIGHT TO WITHDRAW. A withdrawal erases the signatory (attestantid goes to NULL), so a former signatory
        // who comes back to a page left open - the setting that lets them withdraw their own attestation on, or a request
        // opened by a colleague - is no longer recognised by may_withdraw(): asked in the other order, they were refused,
        // told they may not withdraw, and a refusal was logged against them, for an attestation that is already withdrawn.
        // The truth is said to whoever reads the dossier, which draws the withdrawal and its date anyway; anybody else still
        // meets the one refusal, so that an id tells a stranger nothing (M-3).
        if ($attestation->withdrawnat !== null && $candidateid !== (int) $USER->id && access::can_view($candidateid)) {
            throw new \moodle_exception('errorattestalreadywithdrawn', 'tool_vae');
        }

        $context = self::require_can_withdraw($attestation, $candidateid);

        if (!in_array($reason, self::WITHDRAWAL_REASONS, true)) {
            throw new \moodle_exception('errorwithdrawreasonunknown', 'tool_vae');
        }

        // Read, test, write was a race (B5, 12 October 2026): two withdrawals of the same row both
        // found it standing, the second overwrote the reason and date of the first, and the
        // candidate was told twice. The lock serialises withdraw() and the row is read again under
        // it. The condition on withdrawnat in the UPDATE covers the one writer outside the lock,
        // the privacy provider, and the read after it tells whether this call's write landed.
        $lock = \core\lock\lock_config::get_lock_factory('tool_vae_withdraw')
            ->get_lock('attestation-' . $attestationid, 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }

        try {
            $current = $DB->get_record('tool_vae_attest', ['id' => $attestationid], 'id, withdrawnat', MUST_EXIST);
            if ($current->withdrawnat !== null) {
                throw new \moodle_exception('errorattestalreadywithdrawn', 'tool_vae');
            }

            // The four fields. See the class header for why it is four and not one.
            //
            // usermodified goes to 0 - NOT to the person who withdrew. It held the signatory's id
            // from the moment they signed, and rewriting it with the withdrawer's id puts a name
            // back on the row in the very case the reason is called 'rgpd'. The withdrawal does
            // have an author; the event log carries it, under the site's retention rule rather
            // than in the dossier.
            $now = time();
            $transaction = $DB->start_delegated_transaction();
            try {
                $DB->execute(
                    "UPDATE {tool_vae_attest}
                        SET attestantid = NULL,
                            attestantuuid = NULL,
                            attestantrole = NULL,
                            statement = NULL,
                            attestedat = NULL,
                            piecesread = NULL,
                            withdrawnat = :now,
                            withdrawnreason = :reason,
                            withdrawreqby = NULL,
                            withdrawreqat = NULL,
                            usermodified = 0,
                            timemodified = :now2
                      WHERE id = :id AND withdrawnat IS NULL",
                    ['now' => $now, 'reason' => $reason, 'now2' => $now, 'id' => $attestationid]
                );
                // The list of pieces it covered goes with what it said (T-13), in the same transaction.
                $DB->delete_records('tool_vae_attest_piece', ['attestid' => $attestationid]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }

            // Another writer got there first: no second event, no second message, exactly as for
            // a row found withdrawn above. Shortcut: a privacy erasure landing in the same second
            // with the same reason ('rgpd') cannot be told apart and is announced once more -
            // harmless, the row is withdrawn either way; a row version column would close it.
            $withdrawn = $DB->get_record('tool_vae_attest', ['id' => $attestationid], '*', MUST_EXIST);
            if ((int) $withdrawn->withdrawnat !== $now || $withdrawn->withdrawnreason !== $reason) {
                throw new \moodle_exception('errorattestalreadywithdrawn', 'tool_vae');
            }
        } finally {
            $lock->release();
        }

        attestation_withdrawn::create([
            'context' => $context,
            'objectid' => $attestationid,
            'relateduserid' => $candidateid,
            'other' => ['evidenceid' => (int) $attestation->evidenceid],
        ])->trigger();

        // The old backups still carry the signature just erased, and a backup is kept without time
        // limit. Refreshed AFTER the write - a single statement, committed as it ran, since no
        // transaction is opened here - and after the event: should the refresh fail, the
        // withdrawal stands whole and announced, the exception reaches the administrator, and
        // refresh() has discarded the old copies before taking the new one (see its docblock).
        // Exports already handed out are not touched: they are frozen by design (MC-9).
        backup::refresh($candidateid);
        document_copies::refresh($candidateid);

        return $withdrawn;
    }

    /**
     * Require the right to withdraw an attestation, logging the refusal first.
     *
     * THREE WAYS TO HOLD IT, in the order they widen. Decided with the project owner, 12 October
     * 2026 for the first, and opened on 13 October 2026 for the other two, so that the site
     * administrator is not called every morning for an attestation signed in error:
     *
     *   1. the site administrator, any attestation - as before;
     *   2. a holder of tool/vae:withdrawany over this candidate, any attestation on their
     *      dossier, a colleague's included. Nobody holds it by default: the administrator grants
     *      it to a role or to a person;
     *   3. the signatory themselves, their own attestation only, once the administrator has
     *      switched the site setting on. Off by default.
     *
     * With the setting off and the capability granted to nobody, the rule is exactly the one of
     * 12 October. An imported attestation has no signatory on this site, so only (1) and (2)
     * reach it.
     *
     * The candidate's own dossier stays refused, administrator or not: the rule that guards the
     * signature guards its destruction too.
     *
     * Logged before the throw, for the reason require_can_attest() gives.
     *
     * @param \stdClass $attestation A row of tool_vae_attest.
     * @param int $candidateid The owner of the dossier it stands on.
     * @return \context_user The candidate's user context.
     */
    public static function require_can_withdraw(\stdClass $attestation, int $candidateid): \context_user {
        global $USER;

        $context = \context_user::instance($candidateid);

        if ($candidateid === (int) $USER->id) {
            self::log_refusal($context, $candidateid, (int) $attestation->evidenceid);

            throw new \moodle_exception('errorattestown', 'tool_vae');
        }

        if (!self::may_withdraw($attestation, $candidateid)) {
            self::log_refusal($context, $candidateid, (int) $attestation->evidenceid);

            throw new \moodle_exception('errorwithdraw', 'tool_vae');
        }

        return $context;
    }

    /**
     * Whether the current user may withdraw this attestation.
     *
     * See require_can_withdraw() for the three ways. Reading the dossier comes first for the two
     * that are not the administrator's: nobody withdraws a signature on a dossier they may not
     * read, and a capability held at system level is inherited by every user context on the
     * site - access::can_view() is what keeps it to the candidates the person follows.
     *
     * Used by withdraw() to refuse, and by the pages to decide what to draw.
     *
     * @param \stdClass $attestation A row of tool_vae_attest.
     * @param int|null $candidateid The owner of the dossier; looked up from the claim when null.
     * @return bool
     */
    public static function may_withdraw(\stdClass $attestation, ?int $candidateid = null): bool {
        global $DB, $USER;

        if (is_siteadmin()) {
            return true;
        }

        $candidateid = $candidateid ?? (int) $DB->get_field(
            'tool_vae_evidence',
            'candidateid',
            ['id' => $attestation->evidenceid],
            IGNORE_MISSING
        );
        if ($candidateid <= 0 || $candidateid === (int) $USER->id || !access::can_view($candidateid)) {
            return false;
        }

        $context = \context_user::instance($candidateid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        if (has_capability('tool/vae:withdrawany', $context)) {
            return true;
        }

        return $attestation->attestantid !== null
            && (int) $attestation->attestantid === (int) $USER->id
            && (get_config('tool_vae', 'managerwithdrawown') || ($attestation->withdrawreqat ?? null) !== null);
    }

    /**
     * Whether the current user may ask the signatory to withdraw this attestation.
     *
     * A manager who follows the candidate and may attest, for an attestation a COLLEAGUE
     * gave on this site, standing, with no request open. Not for one's own signature, nor on one's
     * own dossier, nor for one that may be withdrawn directly - a request is for those who cannot.
     * An imported attestation has no signatory to ask: the administrator is. So is a signatory
     * whose account is gone or suspended (signatory_reachable()).
     *
     * @param \stdClass $attestation A row of tool_vae_attest.
     * @param int $candidateid The owner of the dossier it stands on.
     * @return bool
     */
    public static function may_request_withdrawal(\stdClass $attestation, int $candidateid): bool {
        global $USER;

        return $attestation->withdrawnat === null
            && ($attestation->withdrawreqat ?? null) === null
            && $attestation->attestantid !== null
            && (int) $attestation->attestantid !== (int) $USER->id
            && $candidateid !== (int) $USER->id
            && self::signatory_reachable($attestation)
            && access::can_attest($candidateid)
            && !self::may_withdraw($attestation, $candidateid);
    }

    /**
     * Whether the signatory of an attestation can still answer a request: their account exists and is not suspended.
     *
     * WHY: a request to a deleted or suspended account is never delivered (notifier::deliver() drops it, as it drops every
     * message to someone who cannot be reached) and nobody can answer it: it would stay open on the row and block the next one.
     * The administrator is who withdraws such an attestation, directly.
     *
     * @param \stdClass $attestation A row of tool_vae_attest.
     * @return bool False for an attestation with no signatory (an imported one) as well.
     */
    public static function signatory_reachable(\stdClass $attestation): bool {
        if ($attestation->attestantid === null) {
            return false;
        }

        $user = \core_user::get_user((int) $attestation->attestantid);

        return $user && empty($user->deleted) && empty($user->suspended);
    }

    /**
     * Ask the signatory to withdraw an attestation.
     *
     * Nothing is withdrawn: the signatory decides. The request waits on the row; the signatory is
     * told, and the dossier shows them two buttons.
     *
     * @param int $attestationid
     * @return \stdClass The attestation, with the request open.
     */
    public function request_withdrawal(int $attestationid): \stdClass {
        global $DB, $USER;

        // An id that is no attestation is refused word for word as a request one may not make: nothing is logged, there is no
        // dossier to log it against, and the answer never tells which ids are attestations (M-3, as attest() does).
        $attestation = $DB->get_record('tool_vae_attest', ['id' => $attestationid], '*', IGNORE_MISSING);
        $evidence = $attestation
            ? $DB->get_record('tool_vae_evidence', ['id' => $attestation->evidenceid], '*', IGNORE_MISSING)
            : false;
        if (!$attestation || !$evidence) {
            throw new \moodle_exception('errorrequestrefused', 'tool_vae');
        }

        $candidateid = (int) $evidence->candidateid;
        $context = \context_user::instance($candidateid);

        if (!self::may_request_withdrawal($attestation, $candidateid)) {
            self::log_refusal($context, $candidateid, (int) $attestation->evidenceid);

            throw new \moodle_exception('errorrequestrefused', 'tool_vae');
        }

        $now = time();
        $DB->execute(
            "UPDATE {tool_vae_attest}
                SET withdrawreqby = :by, withdrawreqat = :now, timemodified = :now2
              WHERE id = :id AND withdrawnat IS NULL AND withdrawreqat IS NULL",
            ['by' => (int) $USER->id, 'now' => $now, 'now2' => $now, 'id' => $attestationid]
        );

        $stored = $DB->get_record('tool_vae_attest', ['id' => $attestationid], '*', MUST_EXIST);
        if ((int) ($stored->withdrawreqby ?? 0) !== (int) $USER->id || (int) $stored->withdrawreqat !== $now) {
            throw new \moodle_exception('errorrequestrefused', 'tool_vae');
        }

        \tool_vae\event\withdrawal_requested::create([
            'context' => $context,
            'objectid' => $attestationid,
            'relateduserid' => (int) $attestation->attestantid,
            'other' => ['evidenceid' => (int) $attestation->evidenceid, 'candidateid' => $candidateid],
        ])->trigger();

        return $stored;
    }

    /**
     * Refuse a request to withdraw one's own attestation.
     *
     * The signatory only. The request is cleared and the person who made it is told.
     *
     * @param int $attestationid
     */
    public function refuse_withdrawal(int $attestationid): void {
        global $DB, $USER;

        // The same single refusal as request_withdrawal(): an id that is no attestation tells nothing.
        $attestation = $DB->get_record('tool_vae_attest', ['id' => $attestationid], '*', IGNORE_MISSING);
        $evidence = $attestation
            ? $DB->get_record('tool_vae_evidence', ['id' => $attestation->evidenceid], '*', IGNORE_MISSING)
            : false;
        if (!$attestation || !$evidence) {
            throw new \moodle_exception('errorrequestrefused', 'tool_vae');
        }

        $candidateid = (int) $evidence->candidateid;
        $context = \context_user::instance($candidateid);

        if (
            $attestation->withdrawnat !== null
            || ($attestation->withdrawreqat ?? null) === null
            || $attestation->attestantid === null
            || (int) $attestation->attestantid !== (int) $USER->id
        ) {
            self::log_refusal($context, $candidateid, (int) $attestation->evidenceid);

            throw new \moodle_exception('errorrequestrefused', 'tool_vae');
        }

        $requesterid = (int) $attestation->withdrawreqby;
        $DB->execute(
            "UPDATE {tool_vae_attest}
                SET withdrawreqby = NULL, withdrawreqat = NULL, timemodified = :now
              WHERE id = :id",
            ['now' => time(), 'id' => $attestationid]
        );

        \tool_vae\event\withdrawal_refused::create([
            'context' => $context,
            'objectid' => $attestationid,
            'relateduserid' => $requesterid,
            'other' => ['evidenceid' => (int) $attestation->evidenceid, 'candidateid' => $candidateid],
        ])->trigger();
    }

    /**
     * The attestation currently standing on a claim, if there is one.
     *
     * @param int $evidenceid
     * @return \stdClass|null
     */
    public static function active_attestation(int $evidenceid): ?\stdClass {
        global $DB;

        $records = $DB->get_records_select(
            'tool_vae_attest',
            'evidenceid = :evidenceid AND withdrawnat IS NULL',
            ['evidenceid' => $evidenceid],
            'timecreated ASC, id ASC'
        );

        return $records ? reset($records) : null;
    }

    /**
     * Every attestation ever recorded on a claim, withdrawn ones included.
     *
     * A withdrawn attestation is not deleted: the dossier has to be able to say that a
     * signature existed and was withdrawn on a date. That sentence is the evidence.
     *
     * @param int $evidenceid
     * @return \stdClass[] Keyed by attestation id, oldest first.
     */
    public static function attestations(int $evidenceid): array {
        global $DB;

        return $DB->get_records(
            'tool_vae_attest',
            ['evidenceid' => $evidenceid],
            'timecreated ASC, id ASC'
        );
    }

    /**
     * Load a claim, or refuse in this plugin's language rather than the database layer's.
     *
     * @param int $evidenceid
     * @return \stdClass
     */
    private static function evidence_or_fail(int $evidenceid): \stdClass {
        global $DB;

        $evidence = $DB->get_record('tool_vae_evidence', ['id' => $evidenceid]);
        if (!$evidence) {
            throw new \moodle_exception('errorunknownevidence', 'tool_vae');
        }

        return $evidence;
    }

    /**
     * Record a refused attempt.
     *
     * @param \context_user $context
     * @param int $candidateid
     * @param int $evidenceid
     */
    private static function log_refusal(\context_user $context, int $candidateid, int $evidenceid): void {
        attestation_denied::create([
            'context' => $context,
            'relateduserid' => $candidateid,
            'other' => ['evidenceid' => $evidenceid],
        ])->trigger();
    }
}
