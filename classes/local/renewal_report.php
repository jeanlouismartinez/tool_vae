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
 * The certifications whose last day has passed (T-90, plan 7).
 *
 * A DATE THAT HAS PASSED, NEVER A VERDICT ON A DOSSIER. The report puts side by side what whoever holds both viewany and
 * certify may already read one by one in the dossiers; it creates no datum, stores nothing and sends nothing. Nothing is
 * written when a date passes: it is told on reading, on the day of the reader.
 *
 * WHAT IS LISTED. For each key (candidate, record, rank) the line that stands, by the rule of
 * positioning_rules::latest() - the greater (time, id) - when it is a certification with a last day before the reader's day.
 * A newer line (a renewal, a withdrawal) takes the key off the list. A deleted account is left out; a suspended one is kept,
 * as in pending_report.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class renewal_report {
    /**
     * The certifications that stand and whose last day is before a day, the earliest last day first.
     *
     * One query, whatever the number of certifications.
     *
     * @param string $today The day of the reader, YYYY-MM-DD.
     * @return \stdClass[] Rows with id, candidateid, fichecode, ficherang, validuntil, decisiondate and the fields of the name.
     */
    public static function expired(string $today): array {
        global $DB;

        $names = ltrim(\core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects, ', ');

        return array_values($DB->get_records_sql(
            "SELECT c.id, c.candidateid, c.fichecode, c.ficherang, c.validuntil, c.decisiondate, $names
               FROM {tool_vae_certif} c
               JOIN {user} u ON u.id = c.candidateid AND u.deleted = 0
              WHERE c.status = :certified
                AND c.validuntil IS NOT NULL
                AND c.validuntil <> :empty
                AND c.validuntil < :today
                AND NOT EXISTS (SELECT 1
                                  FROM {tool_vae_certif} c2
                                 WHERE c2.candidateid = c.candidateid
                                   AND c2.fichecode = c.fichecode
                                   AND c2.ficherang = c.ficherang
                                   AND (c2.timecreated > c.timecreated
                                        OR (c2.timecreated = c.timecreated AND c2.id > c.id)))
           ORDER BY c.validuntil ASC, c.id ASC",
            ['certified' => certification_manager::CERTIFIED, 'empty' => '', 'today' => $today]
        ));
    }

    /**
     * The same, with the title of the bloc and the days since the last day.
     *
     * The title of a bloc is read once for a record. A record the site no longer holds, or a rank it no longer has, is
     * named by its rank alone.
     *
     * @param string $today The day of the reader, YYYY-MM-DD.
     * @return \stdClass[] Rows of expired() with label, days, held (whether the line can be renewed from here) and reason
     *         (why not: disabled, gone or undeclared; an empty string when it can).
     */
    public static function rows(string $today): array {
        global $DB;

        $layouts = [];
        $rows = self::expired($today);
        $enabled = competency_gateway::is_subsystem_enabled();

        // The declarations in one read, whatever the number of lines: certification_manager::write() refuses a candidate with
        // none on the record, so the link would lead to a page that always refuses.
        $declared = [];
        if ($rows) {
            [$insql, $params] = $DB->get_in_or_equal(array_unique(array_map(fn($row) => (int) $row->candidateid, $rows)));
            $pairs = $DB->get_records_sql(
                "SELECT MIN(id) AS id, candidateid, fichecode
                   FROM {tool_vae_evidence}
                  WHERE candidateid $insql
               GROUP BY candidateid, fichecode",
                $params
            );
            foreach ($pairs as $pair) {
                $declared[$pair->candidateid . '/' . $pair->fichecode] = true;
            }
        }

        foreach ($rows as $row) {
            $code = (string) $row->fichecode;
            if ($enabled && !array_key_exists($code, $layouts)) {
                $layouts[$code] = positioning_rules::layout($code);
            }
            $rank = (string) $row->ficherang;
            $bloc = $layouts[$code]->blocs[$rank] ?? null;
            // A record or a rank the site no longer holds cannot be renewed from here: the page of the line would refuse it.
            // Competencies switched off is told as such: the record is not gone, the sub-system is.
            if (!$enabled) {
                $row->reason = 'disabled';
            } else if ($bloc === null) {
                $row->reason = 'gone';
            } else if (!isset($declared[$row->candidateid . '/' . $code])) {
                $row->reason = 'undeclared';
            } else {
                $row->reason = '';
            }
            $row->held = $row->reason === '';
            if ($rank === '') {
                $row->label = get_string('positioningwhole', 'tool_vae');
            } else {
                $row->label = $bloc ? $bloc->label : $rank;
            }
            $row->days = certification_manager::days_overdue((string) $row->validuntil, $today);
        }

        return $rows;
    }
}
