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

use tool_vae\notifier;

/**
 * Destruction of a candidate's dossier - the one place that knows what a dossier is made of.
 *
 * WHY THIS CLASS EXISTS. Three unrelated paths destroy the same rows: a GDPR erasure through
 * the privacy provider, the core deleting the evidence record this plugin hangs off, and the
 * deletion of the candidate's account. Written three times, they drift: a table added to the
 * schema gets purged on one path and survives on the two others, and nothing fails. Written
 * once, a forgotten table is forgotten everywhere at once - which a test can catch.
 *
 * WHAT IT NEVER TOUCHES. No core record is deleted here. The competency subsystem has its own
 * privacy provider and its own deletion paths; removing another component's rows from this one would
 * double-delete on some routes and leave orphans on others. This class deletes rows of this
 * plugin, and nothing else.
 *
 * WHAT IT SAYS ON THE WAY OUT. A declaration destroyed because its core record was deleted takes the signatures of third
 * parties with it, and nothing else in the request tells them. by_userevidence() reads who signed BEFORE the rows go -
 * afterwards nobody could say - and, once the purge has stood, hands each purged declaration to the notifier, which queues
 * one message per signatory. It is here and not in the observer so that the scheduled task that catches up with a purge that
 * did not finish says it too: both reach this method.
 *
 * AND WHAT IT SAYS WHEN IT WITHDRAWS A SIGNATURE. dossier() strips at once the attestations that an archive attributes to the
 * deleted account by its ULID. The candidates whose declarations lost one are handed to the notifier right after the strip is
 * recorded and before the backups of their dossiers are redone (see notifier::attestations_erased()).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge {
    /**
     * Remove every claim hanging off one core evidence record, with its attestations.
     *
     * @param int $userevidenceid The core record this plugin's metadata was anchored to.
     * @return \stdClass[] The rows that were removed, each carrying an `attestations` count and the `signatories` it had.
     */
    public static function by_userevidence(int $userevidenceid): array {
        global $DB;

        $records = $DB->get_records('tool_vae_evidence', ['userevidenceid' => $userevidenceid]);
        if (!$records) {
            return [];
        }

        $records = self::name_signatories(self::count_signatures($records));

        $removed = self::in_transaction(function () use ($records) {
            self::remove($records);

            return $records;
        });

        // After the purge has stood, never before: a message about a signature that is still there would be untrue.
        foreach ($removed as $record) {
            notifier::declaration_purged($record);
        }

        return $removed;
    }

    /**
     * Remove every claim whose core record no longer exists (T-78).
     *
     * The observers purge a claim when the core deletes its record, and an observer cannot make a
     * failure loud: the event manager turns what it throws into a debugging() call. When a purge
     * fails, or the process dies between the core's deletion and ours, the narrative stays behind,
     * anchored to a record that is gone and invisible in every interface. The scheduled task
     * calls this to catch up, one core record at a time, each in its own transaction. Run twice,
     * it removes nothing the second time.
     *
     * @param competency_gateway|null $gateway Only passed by tests.
     * @return \stdClass[] The rows removed, each carrying an `attestations` count.
     */
    public static function orphans(?competency_gateway $gateway = null): array {
        global $DB;

        $gateway = $gateway ?? new competency_gateway();

        // Own table only: the core is asked through the gateway, which is the one place that names it.
        $anchored = array_map('intval', $DB->get_fieldset_sql(
            'SELECT DISTINCT userevidenceid FROM {tool_vae_evidence} ORDER BY userevidenceid'
        ));
        $existing = $gateway->existing_evidence_ids($anchored);

        $removed = [];
        foreach ($anchored as $userevidenceid) {
            if (isset($existing[$userevidenceid])) {
                continue;
            }
            foreach (self::by_userevidence($userevidenceid) as $record) {
                $removed[] = $record;
            }
        }

        return $removed;
    }

    /**
     * Remove the sections of a document whose candidate is gone, and the versions without a section
     * (T-81, lot 4).
     *
     * An erasure or an account deletion removes a candidate's sections in its own transaction. When
     * that did not finish, the text a candidate wrote stays behind, owned by an account that no
     * longer exists, in a table nobody opens. The scheduled task calls this beside orphans() to
     * catch up. Versions are removed with their section, and any version whose section is missing
     * goes too. Run twice, it removes nothing the second time.
     *
     * @return int How many sections were removed.
     */
    public static function orphan_sections(): int {
        global $DB;

        $draftids = $DB->get_fieldset_sql(
            "SELECT d.id
               FROM {tool_vae_draft} d
          LEFT JOIN {user} u ON u.id = d.candidateid
              WHERE u.id IS NULL OR u.deleted = 1"
        );

        self::in_transaction(function () use ($draftids) {
            global $DB;

            if ($draftids) {
                [$insql, $params] = $DB->get_in_or_equal($draftids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('tool_vae_version', "draftid $insql", $params);
                $DB->delete_records_select('tool_vae_draft', "id $insql", $params);
            }
            $DB->execute(
                "DELETE FROM {tool_vae_version}
                       WHERE draftid NOT IN (SELECT id FROM {tool_vae_draft})"
            );
            // The deposits of a candidate who is gone (T-21), and the opinions on them (T-10, lot 5b).
            $DB->execute(
                "DELETE FROM {tool_vae_opinion}
                       WHERE depositid IN (SELECT id FROM {tool_vae_deposit}
                                            WHERE candidateid NOT IN (SELECT id FROM {user} WHERE deleted = 0))"
            );
            $DB->execute(
                "DELETE FROM {tool_vae_deposit}
                       WHERE candidateid NOT IN (SELECT id FROM {user} WHERE deleted = 0)"
            );
            // What a candidate who is gone said was outside their activity (T-42).
            $DB->execute(
                "DELETE FROM {tool_vae_scope}
                       WHERE candidateid NOT IN (SELECT id FROM {user} WHERE deleted = 0)"
            );
            // The calls of an account that is gone to the reformulation aid (T-70).
            $DB->execute(
                "DELETE FROM {tool_vae_aicall}
                       WHERE userid NOT IN (SELECT id FROM {user} WHERE deleted = 0)"
            );

            return [];
        });

        return count($draftids);
    }

    /**
     * Remove a candidate's whole dossier, and their entry in the identity map.
     *
     * The imported signatures naming this person by that entry's ULID, in other dossiers, are
     * stripped first, and the register's rows for their archives deleted: past this point,
     * nothing could find them any more. Whoever PRODUCED an archive is not handled here - that
     * is the privacy provider's, which keeps the row and empties its userid. The candidates
     * whose declarations lost a signature are told, once each, as soon as the strip is recorded.
     *
     * @param int $candidateid
     * @return \stdClass[] The rows that were removed, each carrying an `attestations` count.
     */
    public static function dossier(int $candidateid): array {
        global $DB;

        $records = self::count_signatures($DB->get_records('tool_vae_evidence', [
            'candidateid' => $candidateid,
        ]));

        // Read before the map entry goes: the backups are found by this ULID (lot 8 bis).
        $personne = $DB->get_field('tool_vae_identity', 'uuid', ['itemtype' => 'user', 'itemid' => $candidateid]);

        // The identity map entry is INSIDE the transaction, with the rows it names. Deleted
        // after it, an interruption in between leaves a dossier destroyed and a ULID still
        // pointing at its owner - the one trace the purge exists to remove.
        $signedfor = [];
        $removed = self::in_transaction(function () use ($records, $candidateid, $personne, &$signedfor) {
            global $DB;

            self::remove($records);

            // The sections of the document the candidate wrote and every version they kept (T-81).
            // Versions first: they hold the foreign key.
            $draftids = $DB->get_fieldset_select('tool_vae_draft', 'id', 'candidateid = :c', ['c' => $candidateid]);
            if ($draftids) {
                [$insql, $inparams] = $DB->get_in_or_equal($draftids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('tool_vae_version', "draftid $insql", $inparams);
                $DB->delete_records_select('tool_vae_draft', "id $insql", $inparams);
            }

            // The deposits of the dossier de faisabilité (T-21), and the opinions written on them
            // (T-10, lot 5b), which go first: they hold the foreign key.
            $depositids = $DB->get_fieldset_select('tool_vae_deposit', 'id', 'candidateid = :c', ['c' => $candidateid]);
            if ($depositids) {
                [$insql, $inparams] = $DB->get_in_or_equal($depositids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('tool_vae_opinion', "depositid $insql", $inparams);
            }
            $DB->delete_records('tool_vae_deposit', ['candidateid' => $candidateid]);
            // What the candidate said was outside their activity (T-42).
            $DB->delete_records('tool_vae_scope', ['candidateid' => $candidateid]);
            // Their calls to the reformulation aid (T-70): who and when, no text.
            $DB->delete_records('tool_vae_aicall', ['userid' => $candidateid]);

            // A signature carried in from an archive names its signatory by ULID alone. Once the
            // map entry below is gone, nothing leads from this person to it any more: an erasure
            // reaching the other dossiers later could not find it, and every refresh of their
            // backups would copy it again. So it goes now, while it can still be found.
            if ($personne !== false) {
                $signedfor = self::strip_signatory($personne);

                // The register's rows for this person's archives (decision of 12 October 2026) are
                // found by the same ULID, so they go now too. Left behind, they would name a
                // candidate nothing leads to any more and keep vouching for archives of a dossier
                // this site destroyed. A copy a jury still holds then comes back foreign.
                $DB->delete_records(exporter::REGISTER, ['candidateuuid' => $personne]);
            }

            // Moodle never recycles a user id, so removing the mapping cannot make a later
            // record inherit this ULID. The rule against reassigning an identifier holds.
            // itemtype is part of the condition, not decoration: itemid alone would also
            // match the competency or the structure that happens to carry this number.
            $DB->delete_records('tool_vae_identity', ['itemtype' => 'user', 'itemid' => $candidateid]);

            return $records;
        });

        // The candidates whose declarations lost a signature are told NOW: the strip is recorded, and nothing has been
        // redone yet. Queued after the backups were replaced, the message would depend on that replacement succeeding; a
        // purge that failed there and is played again finds nothing left to strip, and nobody would ever be told. The
        // notifier never throws: an account is not worth failing for a message.
        foreach ($signedfor as $otherid => $evidenceids) {
            notifier::attestations_erased((int) $otherid, $evidenceids, $candidateid);
        }

        // The archives waiting for download go too (lot 8). After the transaction, not inside
        // it: a file deletion cannot be rolled back, and a purge that failed must leave them.
        exporter::discard($candidateid);

        // And the lasting backups, in whoever's private files they sit: an erasure that left
        // them would leave the whole dossier.
        if ($personne !== false) {
            backup::discard($personne);
            // The dated copies in the candidate's own files (T-83): the same erasure.
            document_copies::discard($personne);
        }

        // The other dossiers this person signed lose the signature in their archives too, as
        // on the erasure path. Only those: a refresh replaces every backup, and a dossier this
        // person never signed has nothing to gain from it.
        foreach (array_keys($signedfor) as $otherid) {
            exporter::discard($otherid);
            backup::refresh($otherid);
            document_copies::refresh($otherid);
        }

        return $removed;
    }

    /**
     * Anonymise the attestations matching a condition, the way an erasure does.
     *
     * The single list of what an erased signature loses: who signed, as what, what they wrote
     * and when. What remains is a withdrawn attestation, dated and marked 'rgpd', so the
     * dossier still shows that a signature stood there. The caller holds the transaction.
     *
     * @param string $select WHERE clause on tool_vae_attest, named parameters only.
     * @param array $params Its parameters; the names stripat, stripat2 and stripreason are taken.
     */
    public static function anonymise_signatures(string $select, array $params): void {
        global $DB;

        $now = time();
        // The pieces a signature covered, and the note of having taken note of them, go with what it said (T-13).
        $DB->execute(
            "DELETE FROM {tool_vae_attest_piece}
                   WHERE attestid IN (SELECT id FROM {tool_vae_attest} WHERE $select)",
            $params
        );
        $DB->execute(
            "UPDATE {tool_vae_attest}
                SET attestantid = NULL,
                    attestantuuid = NULL,
                    usermodified = 0,
                    attestantrole = NULL,
                    statement = NULL,
                    attestedat = NULL,
                    piecesread = NULL,
                    withdrawnat = :stripat,
                    withdrawnreason = :stripreason,
                    timemodified = :stripat2
              WHERE $select",
            $params + ['stripat' => $now, 'stripat2' => $now, 'stripreason' => 'rgpd']
        );
    }

    /**
     * Strip every attestation that names a person by ULID, in whatever dossier.
     *
     * Only an imported signature carries attestantuuid - one given here carries attestantid and
     * leaves that column null - so this reaches the archives' signatures and nothing else. The
     * candidates, and the declarations that lose a signature in force, are read BEFORE the update,
     * which empties the column they are found by: they are what the caller needs to tell each
     * candidate (see notifier::attestations_erased()).
     *
     * @param string $personne The person's ULID.
     * @return int[][] The candidates whose dossier held such a signature, each with the declarations (rows of
     *         tool_vae_evidence) that lose a signature that stood: candidate id => evidence ids, which may be none.
     */
    private static function strip_signatory(string $personne): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT a.id, e.candidateid, a.evidenceid, a.withdrawnat
               FROM {tool_vae_attest} a
               JOIN {tool_vae_evidence} e ON e.id = a.evidenceid
              WHERE a.attestantuuid = :personne
           ORDER BY a.id",
            ['personne' => $personne]
        );
        if (!$rows) {
            return [];
        }

        $stripped = [];
        foreach ($rows as $row) {
            $stripped[(int) $row->candidateid] ??= [];
            if ($row->withdrawnat === null) {
                $stripped[(int) $row->candidateid][] = (int) $row->evidenceid;
            }
        }

        self::anonymise_signatures('attestantuuid = :personne', ['personne' => $personne]);

        return $stripped;
    }

    /**
     * Attach the number of attestations each claim carries.
     *
     * Counted BEFORE the deletion. Once the rows are gone the number is unrecoverable, and the
     * event that reports the purge has to say how much disappeared; a count taken afterwards
     * would always read zero and always look right.
     *
     * @param \stdClass[] $records
     * @return \stdClass[] The same rows, each carrying an `attestations` count.
     */
    private static function count_signatures(array $records): array {
        global $DB;

        if (!$records) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($records), SQL_PARAMS_NAMED);
        $counts = $DB->get_records_sql(
            "SELECT evidenceid, COUNT(id) AS total
               FROM {tool_vae_attest}
              WHERE evidenceid $insql
           GROUP BY evidenceid",
            $params
        );

        foreach ($records as $id => $record) {
            $record->attestations = isset($counts[$id]) ? (int) $counts[$id]->total : 0;
        }

        return $records;
    }

    /**
     * Attach to each claim the accounts that hold a signature on it, as it stands.
     *
     * Read BEFORE the deletion, like the count: the attestations are the only place that says who signed, and they are
     * gone once the purge has run. Only a signature in force and given by an account - a withdrawn one has lost its
     * signatory already, and an imported one names a ULID nobody here can write to.
     *
     * @param \stdClass[] $records Rows of tool_vae_evidence, keyed by id.
     * @return \stdClass[] The same rows, each carrying `signatories`, a list of user ids without duplicates.
     */
    private static function name_signatories(array $records): array {
        global $DB;

        if (!$records) {
            return [];
        }

        foreach ($records as $record) {
            $record->signatories = [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($records), SQL_PARAMS_NAMED);
        $signatures = $DB->get_records_sql(
            "SELECT id, evidenceid, attestantid
               FROM {tool_vae_attest}
              WHERE evidenceid $insql AND withdrawnat IS NULL AND attestantid IS NOT NULL
           ORDER BY id",
            $params
        );
        foreach ($signatures as $signature) {
            $signatory = (int) $signature->attestantid;
            if (!in_array($signatory, $records[$signature->evidenceid]->signatories, true)) {
                $records[$signature->evidenceid]->signatories[] = $signatory;
            }
        }

        return $records;
    }

    /**
     * Delete the given claims and their attestations.
     *
     * The attestations go first: an interruption then leaves a claim with no signature,
     * which the interface can still show, rather than a signature pointing at a claim that no
     * longer exists, which nothing can. The transaction is opened by the caller, so that it
     * covers everything one purge is made of and not just this half of it.
     *
     * @param \stdClass[] $records Rows of tool_vae_evidence, already read.
     */
    private static function remove(array $records): void {
        global $DB;

        if (!$records) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($records), SQL_PARAMS_NAMED);

        // The carries of a declaration (T-30, lot 3), from either end, hold the foreign keys.
        $DB->delete_records_select('tool_vae_carry', "oldevidenceid $insql", $params);
        $DB->delete_records_select('tool_vae_carry', "newevidenceid $insql", $params);
        // The requests for precision and their answers (T-20) hold the foreign key.
        $DB->delete_records_select('tool_vae_request', "evidenceid $insql", $params);
        // The supporting pieces (T-13) hold the foreign key, and their files are in the candidate's context: the rows and the
        // files go together, the files by the file API. What the file API cannot reach, the sweep of the nightly task
        // (piece_manager::sweep_orphans) catches.
        $pieces = $DB->get_records_select('tool_vae_piece', "evidenceid $insql", $params, '', 'id, candidateid');
        foreach ($pieces as $piece) {
            $context = \context_user::instance((int) $piece->candidateid, IGNORE_MISSING);
            if ($context) {
                get_file_storage()->delete_area_files($context->id, 'tool_vae', piece_manager::FILEAREA, (int) $piece->id);
            }
        }
        $DB->delete_records_select(
            'tool_vae_attest_piece',
            "attestid IN (SELECT id FROM {tool_vae_attest} WHERE evidenceid $insql)",
            $params
        );
        $DB->delete_records_select(
            'tool_vae_piece_open',
            "pieceid IN (SELECT id FROM {tool_vae_piece} WHERE evidenceid $insql)",
            $params
        );
        $DB->delete_records_select('tool_vae_piece', "evidenceid $insql", $params);
        $DB->delete_records_select('tool_vae_attest', "evidenceid $insql", $params);
        $DB->delete_records_select('tool_vae_evidence', "id $insql", $params);
    }

    /**
     * Run one purge inside a transaction of its own.
     *
     * WHAT THIS TRANSACTION IS WORTH, EXACTLY. On the two paths that reach this class through
     * an observer, the core holds no transaction of its own - verified in the source: neither
     * delete_user_evidence() nor delete_user() opens one - so this is the outermost level and
     * a failure really does undo the half-finished purge. Called from inside someone else's
     * transaction it is a nested level, and rolling back here dooms THEIR transaction too.
     * That is the intended behaviour rather than an accident: a purge that cannot finish must
     * not let the deletion that caused it stand.
     *
     * @param callable $work
     * @return \stdClass[]
     */
    private static function in_transaction(callable $work): array {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $result = $work();
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The rollback() call rethrows; the line after it is never reached.
            $transaction->rollback($e);
            throw $e;
        }

        return $result;
    }
}
