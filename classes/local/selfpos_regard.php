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
 * What stands next to the vaeiste's own level on a node: the word of the structure, and the counts of the dossier (lot 1a).
 *
 * TWO READINGS SIDE BY SIDE, NEVER A GAP. The last state the structure wrote on the same node (the evaluation of a competency,
 * the certification of a bloc) is put next to the vaeiste's own line, so that the conversation with the manager has both in
 * front of it. Nothing is subtracted, compared or coloured: the two are words, not marks, and the difference between them is
 * the matter of the conversation, not of the plugin.
 *
 * The counts of the declaration (declarations, attested, pieces) are plain counts: what the vaeiste has written and attached
 * on this node, and how many of the declarations an attestation stands on, nothing about how it fares.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selfpos_regard {
    /**
     * What stands next to the vaeiste's line on one node. The right to read the dossier is the caller's to have asked.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param \stdClass $node A node of selfpos_tree::for_record().
     * @return \stdClass structure (null, or kind 'eval' or 'certif', status, time and, for a certification, decisiondate):
     *         the last state the structure wrote on this node, when one stands; declarations, attested (the declarations on
     *         which an attestation stands, never more than declarations) and pieces: counts.
     */
    public static function for_node(int $candidateid, string $fichecode, \stdClass $node): \stdClass {
        global $DB;

        $rank = (string) $node->rank;
        $structure = null;
        $evaluation = positioning_rules::latest(evaluation_manager::TABLE, $candidateid, $fichecode)[$rank] ?? null;
        $certification = positioning_rules::latest(certification_manager::TABLE, $candidateid, $fichecode)[$rank] ?? null;
        if (positioning_rules::has_standing($evaluation)) {
            $structure = (object) [
                'kind' => 'eval',
                'status' => (string) $evaluation->status,
                'time' => (int) $evaluation->timecreated,
            ];
        } else if (positioning_rules::has_standing($certification)) {
            $structure = (object) [
                'kind' => 'certif',
                'status' => (string) $certification->status,
                'time' => (int) $certification->timecreated,
                'decisiondate' => (string) $certification->decisiondate,
            ];
        }

        // One query for both counts, read as coverage::for_candidate() reads them: the join keeps a withdrawn attestation out,
        // and the DISTINCT counts declarations that are attested, not attestations.
        $counts = $DB->get_record_sql(
            "SELECT COUNT(DISTINCT e.id) AS declarations, COUNT(DISTINCT a.evidenceid) AS attested
               FROM {tool_vae_evidence} e
          LEFT JOIN {tool_vae_attest} a ON a.evidenceid = e.id AND a.withdrawnat IS NULL
              WHERE e.candidateid = :candidateid AND e.fichecode = :fichecode AND e.ficherang = :ficherang",
            ['candidateid' => $candidateid, 'fichecode' => $fichecode, 'ficherang' => $rank]
        );
        $declarations = (int) $counts->declarations;
        $attested = (int) $counts->attested;
        $pieces = $declarations === 0 ? 0 : $DB->count_records_sql(
            "SELECT COUNT(p.id)
               FROM {tool_vae_piece} p
               JOIN {tool_vae_evidence} e ON e.id = p.evidenceid
              WHERE e.candidateid = :candidateid AND e.fichecode = :fichecode AND e.ficherang = :ficherang
                    AND p.timeremoved IS NULL",
            ['candidateid' => $candidateid, 'fichecode' => $fichecode, 'ficherang' => $rank]
        );

        return (object) [
            'structure' => $structure, 'declarations' => $declarations, 'attested' => $attested, 'pieces' => $pieces,
        ];
    }
}
