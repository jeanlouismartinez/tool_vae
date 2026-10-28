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
 * The declarations that still await an attestation, and for how long (T-22).
 *
 * A COUNT OF WAITING, NOT A GAUGE ON A PERSON. The report says that a declaration has waited for an
 * attestation; it says nothing of the candidate's worth, nor of the manager's. It reads rows that
 * whoever holds tool/vae:viewany may already read one by one: it puts them side by side, it creates no
 * new datum and stores nothing.
 *
 * AWAITING means: no attestation in force. A declaration whose attestation was withdrawn awaits again;
 * its age is that of the declaration.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pending_report {
    /** @var int The longest delay the setting accepts, in days. */
    public const MAX_DAYS = 365;

    /** @var int How many days of missed runs crossing() makes up for at most. */
    public const CATCH_UP_DAYS = 7;

    /**
     * The declarations awaiting an attestation for at least this many days.
     *
     * @param int $days Zero lists every declaration awaiting.
     * @param int|null $now The time to count from; the present when null.
     * @return \stdClass[] Rows with id, candidateid and timecreated, the oldest first.
     */
    public static function awaiting(int $days = 0, ?int $now = null): array {
        $now = $now ?? time();

        return self::query('e.timecreated <= :limit', ['limit' => $now - max(0, $days) * DAYSECS]);
    }

    /**
     * The declarations that reached this many days of waiting since the last run.
     *
     * Each declaration is found once as it crosses the threshold, and no column remembers which one was
     * announced: the window runs from the last run to now, so two runs never share a declaration and a run that
     * came late loses none. Without a last run (the first one, or none known) the window is the last day. A
     * stop longer than a week is not made up for: the reminders of those days are not sent, instead of
     * arriving all at once.
     *
     * @param int $days The threshold, one or more.
     * @param int|null $now
     * @param int|null $since The instant the previous window closed - the "now" the previous run counted from, not the instant
     *        it ended; null or zero when there was none. A run in the same second as the last has an empty window.
     * @return \stdClass[]
     */
    public static function crossing(int $days, ?int $now = null, ?int $since = null): array {
        $now = $now ?? time();
        $upper = $now - $days * DAYSECS;
        $lower = $upper - DAYSECS;
        if ($since !== null && $since > 0 && $since <= $now) {
            $lower = max($since - $days * DAYSECS, $upper - self::CATCH_UP_DAYS * DAYSECS);
        }

        return self::query('e.timecreated <= :upper AND e.timecreated > :lower', [
            'upper' => $upper,
            'lower' => $lower,
        ]);
    }

    /**
     * The same rows, grouped by candidate.
     *
     * @param \stdClass[] $rows As returned by awaiting() or crossing().
     * @return array[] candidateid => ['count' => int, 'oldest' => int (time)]
     */
    public static function by_candidate(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row->candidateid;
            if (!isset($out[$id])) {
                $out[$id] = ['count' => 0, 'oldest' => (int) $row->timecreated];
            }
            $out[$id]['count']++;
            $out[$id]['oldest'] = min($out[$id]['oldest'], (int) $row->timecreated);
        }

        return $out;
    }

    /**
     * Run the query: declarations with no attestation in force, of an account that still exists.
     *
     * @param string $where A condition on e.
     * @param array $params
     * @return \stdClass[]
     */
    private static function query(string $where, array $params): array {
        global $DB;

        return $DB->get_records_sql(
            "SELECT e.id, e.candidateid, e.timecreated
               FROM {tool_vae_evidence} e
               JOIN {user} u ON u.id = e.candidateid AND u.deleted = 0
              WHERE $where
                AND NOT EXISTS (SELECT 1 FROM {tool_vae_attest} a
                                 WHERE a.evidenceid = e.id AND a.withdrawnat IS NULL)
           ORDER BY e.timecreated ASC, e.id ASC",
            $params
        );
    }
}
