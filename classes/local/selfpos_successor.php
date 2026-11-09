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
 * What the vaeiste positioned on a record that another replaces, read on the record that replaces it (lot 1a).
 *
 * READ, NEVER COPIED, CARRIED OR DEDUCED - the rule of positioning_successor, applied to the vaeiste's own lines. The lines
 * stay on the old record; the new record shows them, in reading, where an administrator decided in tool_vae_passage that a
 * node of the old record has an equivalent in the new one. Nothing is written: a new line, if one is wanted, is written by hand
 * on the new record.
 *
 * THE RULES, each one a test:
 * - ONE HOP. The old records of B are those whose declared successor is B; a record A replaced by B is not read on C.
 * - THE TWO ENDS ARE REAL. The decision counts when both nodes exist and are positionable, in the two records.
 * - NODE TO NODE, AT ANY LEVEL. A domain is read on a domain, a competency on a competency, a skill on a skill, as decided.
 *   The record itself (rank '') is not read: no decision names it.
 * - ONLY WHAT STANDS. The line that stands for the key on the old record, and only when it is not a withdrawal.
 * - ONE LINE PER OLD RECORD AND KEY, the old records by increasing code.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selfpos_successor {
    /**
     * The lines of the old records that a record shows.
     *
     * The number of reads depends on the number of old records, not on the number of nodes decided: the decisions are read in
     * one query, then the tree and the lines of each old record once.
     *
     * @param int $candidateid
     * @param string $code The record read on, the new one.
     * @param \stdClass $tree Its tree, as selfpos_tree::for_record() gives it.
     * @return array Rank of the new record => entries (fromcode, fromrank, level, levellabel, time), the old records by
     *         increasing code.
     */
    public static function readings(int $candidateid, string $code, \stdClass $tree): array {
        global $DB;

        $out = [];
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
            $fromnode = selfpos_tree::node($old[$from]->tree, $fromrank);
            $tonode = selfpos_tree::node($tree, $torank);
            if ($fromnode === null || $tonode === null || !$fromnode->positionable || !$tonode->positionable) {
                continue;
            }
            $line = $old[$from]->lines[$fromrank] ?? null;
            if (!selfpos_manager::standing($line)) {
                continue;
            }
            $out[$torank][] = (object) [
                'fromcode' => $from,
                'fromrank' => $fromrank,
                'level' => (int) $line->level,
                'levellabel' => (string) $line->levellabel,
                'time' => (int) $line->timecreated,
            ];
        }

        return $out;
    }

    /**
     * An old record: its tree and the lines that stand for the vaeiste, read once.
     *
     * @param int $candidateid
     * @param string $code
     * @return \stdClass|null tree and lines (rank => the line that stands); null when the record cannot be read.
     */
    private static function old_record(int $candidateid, string $code): ?\stdClass {
        try {
            $tree = selfpos_tree::for_record($code);
        } catch (\moodle_exception $e) {
            $tree = null;
        }
        if ($tree === null) {
            return null;
        }

        return (object) [
            'tree' => $tree,
            'lines' => positioning_rules::latest(selfpos_manager::TABLE, $candidateid, $code),
        ];
    }
}
