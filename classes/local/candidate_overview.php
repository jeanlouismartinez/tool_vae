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
 * What the page "My candidates" counts, candidate by candidate (lot 7 bis, T-23 lot 3).
 *
 * COUNTS OF WORK, NOT A MEASURE OF A PERSON. How many declarations a candidate made and how many of them await an
 * attestation; nothing else, no rate, no threshold, no name of an attester. The counts may be limited to the declarations
 * on one bloc of one record (the blocs a manager is referent of): the limit narrows what is counted, never what the manager
 * may read, and the reader's rights are asked before, by access::followed_candidates().
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class candidate_overview {
    /**
     * The declarations of some candidates, and how many of them await an attestation.
     *
     * "Awaiting" means no attestation currently standing: never signed, or every signature withdrawn, as
     * attestation_manager::active_attestation() reads it.
     *
     * @param int[] $candidateids The candidates to count for; the caller has checked the reader may read them.
     * @param string $fichecode Limit the count to this record; empty for every record.
     * @param string[]|null $ranks With a record, limit the count to these ranks (the competencies of a bloc); null for
     *        the whole record. An empty list counts nothing.
     * @return \stdClass[] Keyed by candidate id, each with declarations and pending (integers).
     */
    public static function counts(array $candidateids, string $fichecode = '', ?array $ranks = null): array {
        global $DB;

        if (!$candidateids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($candidateids), SQL_PARAMS_NAMED, 'cd');
        $where = "e.candidateid $insql";
        if ($fichecode !== '') {
            $where .= ' AND e.fichecode = :fichecode';
            $params['fichecode'] = $fichecode;
            if ($ranks !== null) {
                if (!$ranks) {
                    return [];
                }
                [$ranksql, $rankparams] = $DB->get_in_or_equal(array_values($ranks), SQL_PARAMS_NAMED, 'rk');
                $where .= " AND e.ficherang $ranksql";
                $params += $rankparams;
            }
        }

        $rows = $DB->get_records_sql(
            "SELECT e.candidateid,
                    COUNT(e.id) AS declarations,
                    SUM(CASE WHEN NOT EXISTS (
                            SELECT 1
                              FROM {tool_vae_attest} a
                             WHERE a.evidenceid = e.id
                               AND a.withdrawnat IS NULL
                        ) THEN 1 ELSE 0 END) AS pending
               FROM {tool_vae_evidence} e
              WHERE $where
           GROUP BY e.candidateid",
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->candidateid] = (object) [
                'declarations' => (int) $row->declarations,
                'pending' => (int) $row->pending,
            ];
        }

        return $out;
    }
}
