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
 * What a candidate's declarations reach, competency by competency (R3).
 *
 * COUNTS, NEVER A VERDICT. mod_referentiel turned the same figures into a gauge and the gauge
 * into "validable"; this class stops at the counts on purpose. No percentage, no total, no
 * threshold, no weight: the moment a figure is set against a target it becomes an opinion on
 * the outcome, and the outcome belongs to the panel (README, docs/INVENTAIRE-mod_referentiel.md).
 *
 * THE COMPETENCIES WITHOUT A DECLARATION ARE THE POINT. A list of what was declared repeats the
 * dossier; what the candidate cannot see anywhere else is what the record holds and they have
 * not yet described. So every competency of a record they declared against at least once is
 * listed, zeros included.
 *
 * The record's competencies come through competency_gateway (MC-5); everything else is read
 * from this plugin's own tables. One query for all the counts, one per record for its
 * competencies - never one per competency.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class coverage {
    /**
     * The coverage of one candidate's dossier, record by record.
     *
     * Declarations are matched to competencies by idnumber, record code and rank joined by a
     * hyphen, rather than by competencyid: that column is null on claims made before it existed,
     * and the idnumber is what the import itself matches on.
     *
     * @param int $candidateid
     * @return \stdClass[] Keyed by record code, in code order. Each has fichecode, source (the
     *                     tool_vae_source row, or null) and competencies: a list of
     *                     idnumber, shortname (null when the core no longer holds it),
     *                     declarations, attested, lastattested (null when none) and gone.
     */
    public static function for_candidate(int $candidateid): array {
        global $DB;

        // The join keeps withdrawn attestations out of both the count and the date: a
        // withdrawn one still shows in the dossier, as having existed, but it vouches for nothing.
        $sql = "SELECT e.fichecode, e.ficherang,
                       COUNT(DISTINCT e.id) AS declarations,
                       COUNT(a.id) AS attested,
                       MAX(a.attestedat) AS lastattested
                  FROM {tool_vae_evidence} e
             LEFT JOIN {tool_vae_attest} a ON a.evidenceid = e.id AND a.withdrawnat IS NULL
                 WHERE e.candidateid = :candidateid
              GROUP BY e.fichecode, e.ficherang";

        // A recordset, not get_records_sql(): the first column is not unique.
        $counts = [];
        $rs = $DB->get_recordset_sql($sql, ['candidateid' => $candidateid]);
        foreach ($rs as $row) {
            $counts[$row->fichecode][$row->fichecode . '-' . $row->ficherang] = $row;
        }
        $rs->close();

        if (!$counts) {
            return [];
        }
        ksort($counts, SORT_STRING);

        $sources = [];
        foreach ($DB->get_records_list('tool_vae_source', 'fichecode', array_keys($counts)) as $source) {
            $sources[$source->fichecode] = $source;
        }

        // Competencies the import found missing from their record. Not deleted - orphaned
        // evidence is still evidence - so they are still in the framework, and only this mark
        // tells them apart.
        $departed = [];
        if ($sources) {
            [$insql, $params] = $DB->get_in_or_equal(array_column($sources, 'id'), SQL_PARAMS_NAMED);
            $params['kind'] = importer::GONE;
            $departed = array_flip(array_map('intval', $DB->get_fieldset_select(
                'tool_vae_obsolete',
                'competencyid',
                "kind = :kind AND sourceid $insql",
                $params
            )));
        }

        $gateway = new competency_gateway();
        $out = [];
        foreach ($counts as $code => $declared) {
            $source = $sources[$code] ?? null;
            $competencies = $source ? $gateway->list_framework_competencies((int) $source->frameworkid) : [];

            // T-33: which competency is a bloc, and which sits under one.
            $idnumberof = [];
            $isbloc = [];
            foreach ($competencies as $competency) {
                $idnumberof[$competency->id] = $competency->idnumber;
                if ($competency->parentid > 0) {
                    $isbloc[$competency->parentid] = true;
                }
            }

            $rows = [];
            foreach ($competencies as $competency) {
                $gone = isset($departed[$competency->id]);
                $count = $declared[$competency->idnumber] ?? null;

                // A competency that left the record and was never declared is no longer the
                // candidate's concern; one that carries declarations stays, with its note.
                if ($gone && !$count) {
                    continue;
                }
                $rows[$competency->idnumber] = self::row($competency->idnumber, $competency->label, $gone, $count);
                $rows[$competency->idnumber]->bloc = $competency->parentid > 0
                    ? ($idnumberof[$competency->parentid] ?? null) : null;
                $rows[$competency->idnumber]->isbloc = isset($isbloc[$competency->id]);
            }

            // Declarations whose competency the framework no longer holds at all, removed through
            // tool_lp: still the candidate's evidence, so still listed, under the rank they gave.
            foreach ($declared as $idnumber => $count) {
                if (!isset($rows[$idnumber])) {
                    $rows[$idnumber] = self::row($idnumber, null, true, $count);
                }
            }

            // By bloc when the record has blocs: a bloc, then the competencies under it, in rank order; never in the
            // core's sortorder. A flat record keeps the plain order of its ranks.
            $rows = self::by_bloc($rows);
            $out[$code] = (object) [
                'fichecode' => $code,
                'source' => $source,
                'hasblocs' => (bool) array_filter($rows, fn($row) => $row->isbloc),
                'competencies' => $rows,
            ];
        }

        return $out;
    }

    /**
     * Put the lines in the order of the blocs, and give each bloc the counts of its own line and of its competencies.
     *
     * COUNTS ONLY. A bloc line adds up declarations and attestations, as the lines under it do: no percentage, no
     * "complete", no state.
     *
     * @param \stdClass[] $rows Keyed by idnumber, each with bloc (idnumber of its bloc or null) and isbloc.
     * @return \stdClass[] A list. Each bloc has blocdeclarations, blocattested and bloclastattested (null when
     *         none).
     */
    private static function by_bloc(array $rows): array {
        $blocof = fn($row) => $row->bloc ?? $row->idnumber;
        $rows = array_values($rows);
        usort($rows, function ($a, $b) use ($blocof) {
            return strnatcmp($blocof($a), $blocof($b))
                ?: (($a->isbloc ? 0 : 1) <=> ($b->isbloc ? 0 : 1))
                ?: strnatcmp($a->idnumber, $b->idnumber);
        });

        $index = [];
        foreach ($rows as $position => $row) {
            $index[$row->idnumber] = $position;
            if ($row->isbloc) {
                $row->blocdeclarations = $row->declarations;
                $row->blocattested = $row->attested;
                $row->bloclastattested = $row->lastattested;
            }
        }
        foreach ($rows as $row) {
            if ($row->bloc !== null && isset($index[$row->bloc])) {
                $parent = $rows[$index[$row->bloc]];
                $parent->blocdeclarations += $row->declarations;
                $parent->blocattested += $row->attested;
                $parent->bloclastattested = max($parent->bloclastattested, $row->lastattested);
            }
        }

        return $rows;
    }

    /**
     * One line of the coverage.
     *
     * @param string $idnumber
     * @param string|null $shortname
     * @param bool $gone Whether the competency no longer appears in the record.
     * @param \stdClass|null $count The aggregate row, or null when nothing was declared.
     * @return \stdClass
     */
    private static function row(string $idnumber, ?string $shortname, bool $gone, ?\stdClass $count): \stdClass {
        return (object) [
            'idnumber' => $idnumber,
            'shortname' => $shortname,
            'declarations' => $count ? (int) $count->declarations : 0,
            'attested' => $count ? (int) $count->attested : 0,
            'lastattested' => $count && $count->lastattested !== null ? (int) $count->lastattested : null,
            'gone' => $gone,
            'bloc' => null,
            'isbloc' => false,
        ];
    }
}
