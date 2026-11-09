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

/**
 * What the positionings of a record that another replaces say on the record that replaces it (T-90, plan 7).
 *
 * READ, NEVER COPIED, CARRIED OR DEDUCED. The lines stay where they were written, on the old record. The new record shows
 * them, in reading, where an administrator decided in tool_vae_passage that a competency of the old record has an equivalent
 * in the new one. Nothing is written: a new line, if one is wanted, is written by hand on the new record, as the attestation
 * of a declaration is not carried (D-14).
 *
 * THE RULES, each one a test:
 * - ONE HOP. The old records of B are those whose declared successor is B. A record that A replaced and that B in turn
 *   replaced is read from B on C, never from A on C.
 * - THE TWO ENDS ARE REAL. The decision counts when the competency of the old record and the one of the new record both exist,
 *   in the framework of the old record and in the one of the new record that the old record declares as its successor.
 * - LEAF TO LEAF, BLOC TO BLOC. An evaluation is read from a competency to a competency. A certification is read from a bloc
 *   to a bloc only when the bloc of the old record has a decision of its own towards a bloc of the new one: it is never
 *   deduced from its competencies. A decision between a competency and a bloc is not read. A record with no bloc (rank '')
 *   is not read on the new one.
 * - ONLY WHAT STANDS. The line that stands for the key on the old record, and only when it is not a withdrawal.
 * - ONE LINE PER OLD RECORD AND KEY, the old records by increasing code.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class positioning_successor {
    /**
     * The lines of the old records that a record shows.
     *
     * The number of reads depends on the number of old records, not on the number of competencies decided: the decisions are
     * read in one query, then the lines of each old record in one query per table.
     *
     * @param int $candidateid
     * @param string $code The record read on, the new one.
     * @param \stdClass $layout Its layout, as positioning_rules::layout() gives it.
     * @return array eval and certif, each rank of the new record => entries (fromcode, fromrank, status, time and, for a
     *         certification, decisiondate and validuntil), the old records by increasing code.
     */
    public static function readings(int $candidateid, string $code, \stdClass $layout): array {
        global $DB;

        $out = ['eval' => [], 'certif' => []];
        $decisions = $DB->get_records_sql(
            "SELECT p.id, sa.fichecode AS fromcode, sa.successorcode, cf.idnumber AS fromidnumber, ct.idnumber AS toidnumber
               FROM {tool_vae_passage} p
               JOIN {competency} cf ON cf.id = p.fromcompetencyid
               JOIN {tool_vae_source} sa ON sa.frameworkid = cf.competencyframeworkid
               JOIN {competency} ct ON ct.id = p.tocompetencyid
               JOIN {tool_vae_source} sb ON sb.frameworkid = ct.competencyframeworkid AND sb.fichecode = sa.successorcode
              WHERE sb.fichecode = :code
           ORDER BY sa.fichecode ASC, cf.idnumber ASC, p.id ASC",
            ['code' => $code]
        );
        $old = [];
        foreach ($decisions as $decision) {
            // The database may fold the case; a record is told by its exact code.
            if ((string) $decision->successorcode !== $code) {
                continue;
            }
            $from = (string) $decision->fromcode;
            if (!array_key_exists($from, $old)) {
                $old[$from] = self::old_record($candidateid, $from);
            }
            if ($old[$from] === null) {
                continue;
            }
            $fromrank = passage_manager::rank_of((string) $decision->fromidnumber, $from);
            $torank = passage_manager::rank_of((string) $decision->toidnumber, $code);
            if ($fromrank === '' || $torank === '') {
                continue;
            }
            $kind = null;
            if (isset($old[$from]->layout->leaves[$fromrank]) && isset($layout->leaves[$torank])) {
                $kind = 'eval';
            } else if (isset($old[$from]->layout->blocs[$fromrank]) && isset($layout->blocs[$torank])) {
                $kind = 'certif';
            }
            $line = $kind === null ? null : ($old[$from]->lines[$kind][$fromrank] ?? null);
            if (!positioning_rules::has_standing($line)) {
                continue;
            }
            $entry = (object) [
                'fromcode' => $from,
                'fromrank' => $fromrank,
                'status' => (string) $line->status,
                'time' => (int) $line->timecreated,
            ];
            if ($kind === 'certif') {
                $entry->decisiondate = (string) $line->decisiondate;
                $entry->validuntil = empty($line->validuntil) ? null : (string) $line->validuntil;
            }
            $out[$kind][$torank][] = $entry;
        }

        return $out;
    }

    /**
     * An old record: its layout and the lines that stand for the candidate, read once.
     *
     * @param int $candidateid
     * @param string $code
     * @return \stdClass|null layout and lines (eval and certif, rank => the line that stands); null when the record cannot be
     *         laid out.
     */
    private static function old_record(int $candidateid, string $code): ?\stdClass {
        try {
            $layout = positioning_rules::layout($code);
        } catch (\moodle_exception $e) {
            $layout = null;
        }
        if (!$layout) {
            return null;
        }

        return (object) [
            'layout' => $layout,
            'lines' => [
                'eval' => positioning_rules::latest(evaluation_manager::TABLE, $candidateid, $code),
                'certif' => positioning_rules::latest(certification_manager::TABLE, $candidateid, $code),
            ],
        ];
    }
}
