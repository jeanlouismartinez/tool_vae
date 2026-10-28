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

use tool_vae\event\record_removed;
use tool_vae\event\supersession_changed;

/**
 * Removing the row of a record imported by mistake, once Moodle no longer holds its competency framework.
 *
 * THE CASE (decision of the project owner, 27 October 2026). A manager imports the file of one diploma under the code of
 * another. Nothing compares the file with the code, so a false record is created, visible to every candidate. The manager
 * deletes its framework in Moodle (the core allows it as long as nothing uses a competency of it), and the row of this plugin
 * stays: status failed, no competency, and a warning that advises importing the record again. This is the gesture that takes
 * that row away.
 *
 * WHAT THIS CLASS NEVER DOES: delete a framework, or touch a table of the competency subsystem (MC-2). Moodle decides whether a
 * framework can go, with its own lock and its own confirmation; this class only removes what THIS PLUGIN kept about the record.
 * That is why the row is removable only once Moodle holds no framework for it.
 *
 * WHEN THE ROW CAN GO. All three at once:
 *   - the row exists;
 *   - Moodle holds no framework for it: neither the one the row points at, nor any other carrying the code (a framework made by
 *     hand, which a new import would take over);
 *   - no candidate has left a trace on the code: a declaration, a mark "Outside my activity", or a section of the preparation
 *     of the validation dossier. Refused outright, with the counts: what a candidate wrote is theirs, and a row is not worth a
 *     dossier. The other tables that carry a declaration (attestations, questions, pieces, carries) hang from a declaration,
 *     and the versions hang from a section, so they need no look of their own.
 *
 * WHAT GOES WITH THE ROW. Five tables and one column, which is what the review counted as six tables: the provenance row itself
 * (tool_vae_source, with the licence, the attribution, the dates and the replacing record it held), the addresses of the rules
 * of validation (tool_vae_ref), the referents of the blocs (tool_vae_referent), the marks of departure (tool_vae_obsolete) and
 * the correspondences between competencies (tool_vae_passage); and, in the same table as the provenance, the link of OTHER
 * records that name this one as their replacing record (successorcode), which is cleared.
 *
 * THE CORRESPONDENCES ARE FOUND WITHOUT THE COMPETENCIES OF THE RECORD, WHICH ARE GONE. A row of tool_vae_passage names
 * competencies by their id, and the framework took its competencies with it, so no join leads from the record to its rows.
 * They are found by the other end, which still exists, and by the competency that no longer does:
 *   - a decision FROM a record that names this one as its replacement, towards a competency that is gone;
 *   - a decision TOWARDS a competency of the record that replaces this one, from a competency that is gone.
 *
 * WHAT THIS TAKES, AND WHAT IT LEAVES. It takes every decision of those two kinds that has a competency gone at one end,
 * WHICHEVER RECORD THAT END CAME FROM. Nothing records the framework of a competency once it is deleted (the table keeps two
 * ids), so a decision of a record that is still there, left with no competency at one end because that competency was deleted
 * by itself in Moodle (the core allows it while nothing uses the competency), goes with this record as soon as its other end is
 * a competency of the replacing record above, or its record names this one as its replacement. Such a decision was out of
 * reach already: no screen lists it, and Moodle refuses to delete a competency on which a declaration stands, so nothing can
 * be carried by it. The screen of confirmation counts it among the correspondences it announces, and says so. Likewise, when
 * two records whose frameworks are both gone share the same replacement, the decisions of the second are taken with the first:
 * neither can be used again, since the competencies they name no longer exist.
 * What it leaves: every decision whose two ends are both still there, whichever record holds it (among them the old decision of
 * a record towards a replacement it had before it was changed: the screens of correspondences already read it as "a competency
 * that is no longer in the record"), and any decision with a competency gone that neither of the two kinds above leads to.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class record_remover {
    /** @var competency_gateway The only door to the competency subsystem. */
    private $gateway;

    /**
     * The gateway is injectable, as in the importer, so that a test can watch what is asked of the core.
     *
     * @param competency_gateway|null $gateway
     */
    public function __construct(?competency_gateway $gateway = null) {
        $this->gateway = $gateway ?? new competency_gateway();
    }

    /**
     * Why a row cannot be removed, or null when it can.
     *
     * No capability test: the screens ask who may remove before they ask whether the row can go.
     *
     * @param string $fichecode The code of the record.
     * @return \stdClass|null The language string identifier of the refusal (`errorcode`) and what it is written with (`a`), or
     *         null. errorrefunknownfiche (no such row), errorremoverecordframework (Moodle still holds a framework for the
     *         record: `a` is its code) or errorremoverecordtraces (a candidate left a trace: `a` carries code, declarations,
     *         marks and sections).
     */
    public function refusal(string $fichecode): ?\stdClass {
        global $DB;

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        if (!$source) {
            return (object) ['errorcode' => 'errorrefunknownfiche', 'a' => null];
        }
        $code = (string) $source->fichecode;

        // Both questions: the row may point at a framework that is there, or at nothing while another framework carries the
        // code, which the next import of the record would take over.
        if ($this->gateway->framework_exists((int) $source->frameworkid) || $this->gateway->find_framework($code) !== null) {
            return (object) ['errorcode' => 'errorremoverecordframework', 'a' => $code];
        }

        $traces = $this->traces($code);
        if ($traces->declarations || $traces->marks || $traces->sections) {
            $traces->code = $code;

            return (object) ['errorcode' => 'errorremoverecordtraces', 'a' => $traces];
        }

        return null;
    }

    /**
     * Which of these records can have their row removed.
     *
     * @param string[] $fichecodes Codes of records, usually the ones whose framework Moodle no longer holds.
     * @return string[] The codes that can, in the order given.
     */
    public function removable(array $fichecodes): array {
        return $this->sort_by_refusal($fichecodes)->removable;
    }

    /**
     * Which of these records can have their row removed, and which of the two causes keeps the button from the others.
     *
     * Asked once for each record. The page of the records says why a row has no button, and says it for the cause that applies:
     * a framework of Moodle that carries the code is not a trace of a candidate, and a manager told the one for the other looks
     * for what is not there.
     *
     * @param string[] $fichecodes Codes of records, usually the ones whose framework Moodle no longer holds.
     * @return \stdClass Lists of codes, each in the order given: `removable` (nothing stands in the way), `framework` (Moodle holds
     *         a framework that carries the code) and `traces` (a candidate has left a trace on the record). A code that has no row
     *         any more, which the page can meet if a row goes between two questions, is in none of them.
     */
    public function sort_by_refusal(array $fichecodes): \stdClass {
        $sorted = (object) ['removable' => [], 'framework' => [], 'traces' => []];
        foreach ($fichecodes as $fichecode) {
            $refusal = $this->refusal((string) $fichecode);
            if ($refusal === null) {
                $sorted->removable[] = (string) $fichecode;
            } else if ($refusal->errorcode === 'errorremoverecordframework') {
                $sorted->framework[] = (string) $fichecode;
            } else if ($refusal->errorcode === 'errorremoverecordtraces') {
                $sorted->traces[] = (string) $fichecode;
            }
        }

        return $sorted;
    }

    /**
     * What candidates have left on a record.
     *
     * @param string $fichecode The code of the record.
     * @return \stdClass The counts: declarations, marks ("Outside my activity") and sections (of the preparation of the
     *         validation dossier). All integers.
     */
    public function traces(string $fichecode): \stdClass {
        global $DB;

        return (object) [
            'declarations' => $DB->count_records('tool_vae_evidence', ['fichecode' => $fichecode]),
            'marks' => $DB->count_records('tool_vae_scope', ['fichecode' => $fichecode]),
            // The sections of the dossier de faisabilite and of the journal carry an empty code: never matched against one.
            'sections' => $fichecode === '' ? 0 : $DB->count_records('tool_vae_draft', ['fichecode' => $fichecode]),
        ];
    }

    /**
     * What the plugin kept for a record, and what leaves with its row.
     *
     * Read-only, and valid for a row that cannot be removed too: whether it can is refusal()'s to say.
     *
     * @param string $fichecode The code of the record.
     * @return \stdClass|null Null when there is no such row. Otherwise: fichecode, source (the row of tool_vae_source), refs
     *         (id, title, url, checkedat of each address), referents (how many), marks (how many marks of departure),
     *         passageids (the rows of tool_vae_passage that go), successor (the code of the replacing record declared, or ''),
     *         replaced (the codes of the records that name this one as their replacing record).
     */
    public function inventory(string $fichecode): ?\stdClass {
        global $DB;

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        if (!$source) {
            return null;
        }
        $code = (string) $source->fichecode;

        return (object) [
            'fichecode' => $code,
            'source' => $source,
            'refs' => array_values($DB->get_records(
                'tool_vae_ref',
                ['sourceid' => (int) $source->id],
                'id ASC',
                'id, title, url, checkedat'
            )),
            'referents' => $DB->count_records('tool_vae_referent', ['fichecode' => $code]),
            'marks' => $DB->count_records('tool_vae_obsolete', ['sourceid' => (int) $source->id]),
            'passageids' => $this->passage_ids($source),
            'successor' => (string) $source->successorcode,
            'replaced' => $DB->get_fieldset_sql(
                'SELECT fichecode FROM {tool_vae_source} WHERE successorcode = :code ORDER BY fichecode ASC',
                ['code' => $code]
            ),
        ];
    }

    /**
     * Remove the row of a record, and what the plugin kept for it, in one act.
     *
     * @param string $fichecode The code of the record.
     * @return \stdClass What was removed, as inventory() gave it just before.
     * @throws \required_capability_exception Without the right to import.
     * @throws \moodle_exception errorrefunknownfiche, errorremoverecordframework, errorremoverecordtraces
     */
    public function remove(string $fichecode): \stdClass {
        global $DB, $USER;

        // The right of the import itself: the removal touches only what an import wrote, and Moodle's own right to manage
        // frameworks is not asked, since no framework is touched.
        $context = \context_system::instance();
        require_capability('tool/vae:import', $context);

        // Said before any transaction: the common refusal leaves nothing to roll back.
        $this->require_removable($fichecode);

        $transaction = $DB->start_delegated_transaction();
        try {
            // And again inside, narrowing the time in which a candidate can write between the check and the deletions.
            $this->require_removable($fichecode);
            $inventory = $this->inventory($fichecode);
            $sourceid = (int) $inventory->source->id;
            $code = $inventory->fichecode;
            $others = $DB->get_records('tool_vae_source', ['successorcode' => $code], 'fichecode ASC', 'id, fichecode');

            $DB->delete_records('tool_vae_ref', ['sourceid' => $sourceid]);
            $DB->delete_records('tool_vae_referent', ['fichecode' => $code]);
            $DB->delete_records('tool_vae_obsolete', ['sourceid' => $sourceid]);
            if ($inventory->passageids) {
                $DB->delete_records_list('tool_vae_passage', 'id', $inventory->passageids);
            }
            // A record that was said to be replaced by this one is no longer said to be replaced by anything.
            foreach ($others as $other) {
                $DB->update_record('tool_vae_source', (object) [
                    'id' => (int) $other->id,
                    'successorcode' => null,
                    'timemodified' => time(),
                    'usermodified' => (int) $USER->id,
                ]);
            }
            $DB->delete_records('tool_vae_source', ['id' => $sourceid]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        // What the preparation of the validation dossier read of the record in this request is no longer true.
        validation_catalog::reset();

        // After the commit: a line of the log about an act that did not stand would be untrue.
        foreach ($others as $other) {
            supersession_changed::create([
                'context' => $context,
                'objectid' => (int) $other->id,
                'other' => ['fichecode' => (string) $other->fichecode, 'successor' => ''],
            ])->trigger();
        }
        record_removed::create([
            'context' => $context,
            'objectid' => $sourceid,
            'other' => [
                'fichecode' => $code,
                'refs' => count($inventory->refs),
                'referents' => (int) $inventory->referents,
                'marks' => (int) $inventory->marks,
                'passages' => count($inventory->passageids),
                'successor' => $inventory->successor,
                'links' => count($others),
            ],
        ])->trigger();

        return $inventory;
    }

    /**
     * Refuse, as an exception, when the row cannot be removed.
     *
     * @param string $fichecode The code of the record.
     * @throws \moodle_exception The identifier refusal() gives.
     */
    private function require_removable(string $fichecode): void {
        $refusal = $this->refusal($fichecode);
        if ($refusal !== null) {
            throw new \moodle_exception($refusal->errorcode, 'tool_vae', '', $refusal->a);
        }
    }

    /**
     * The correspondences that went with a record, found from the competencies that still exist.
     *
     * See the class comment: the competencies of the record are gone, so the decisions are found by the other end.
     *
     * @param \stdClass $source The row of tool_vae_source.
     * @return int[] Ids of tool_vae_passage.
     */
    private function passage_ids(\stdClass $source): array {
        global $DB;

        $ids = [];

        // From a record that names this one as its replacement, towards a competency this one no longer holds.
        foreach ($DB->get_records('tool_vae_source', ['successorcode' => $source->fichecode]) as $other) {
            $from = array_keys($this->gateway->list_framework_competencies((int) $other->frameworkid));
            if (!$from) {
                continue;
            }
            [$insql, $params] = $DB->get_in_or_equal($from, SQL_PARAMS_NAMED);
            foreach ($DB->get_records_select('tool_vae_passage', "fromcompetencyid $insql", $params) as $decision) {
                if ($this->gateway->framework_of_competency((int) $decision->tocompetencyid) === 0) {
                    $ids[(int) $decision->id] = (int) $decision->id;
                }
            }
        }

        // Towards the record that replaces this one, from a competency this one no longer holds.
        $successor = (string) $source->successorcode === ''
            ? null
            : $DB->get_record('tool_vae_source', ['fichecode' => $source->successorcode]);
        $to = $successor ? array_keys($this->gateway->list_framework_competencies((int) $successor->frameworkid)) : [];
        if ($to) {
            [$insql, $params] = $DB->get_in_or_equal($to, SQL_PARAMS_NAMED);
            foreach ($DB->get_records_select('tool_vae_passage', "tocompetencyid $insql", $params) as $decision) {
                if ($this->gateway->framework_of_competency((int) $decision->fromcompetencyid) === 0) {
                    $ids[(int) $decision->id] = (int) $decision->id;
                }
            }
        }

        return array_values($ids);
    }
}
