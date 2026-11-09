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

use tool_vae\event\certification_recorded;

/**
 * The certification of ONE bloc of a candidate, by the direction (T-90, lot 3).
 *
 * THE POSITIONING OF THE STRUCTURE, NEVER THE VALIDATION. The direction says that the structure stands behind the bloc, on
 * a date, with a written reason. It is not a validation, which only the jury gives, outside this plugin, and which alone
 * opens the way to the qualification of the official diploma. Added to and never changed: a correction or a withdrawal is a
 * new dated line, the latest line stands.
 *
 * AN ALERT, NOT A LOCK. When a competency of the bloc is not sufficient - never evaluated, still to build or in the course of
 * proof, or evaluation withdrawn - the bloc may be certified all the same, and the direction says why: the reason of the
 * alert is required then, and refused when nothing lacks. The server works it out at the moment of writing.
 *
 * WHO WRITES. Whoever holds tool/vae:viewany AND tool/vae:certify at the site, as the export of another person's dossier
 * goes with both. Nobody certifies themselves, the administrator included.
 *
 * WHO READS. Whoever may read the dossier (access::can_view()).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class certification_manager {
    /** @var string The table. */
    public const TABLE = 'tool_vae_certif';

    /** @var string The status of a certification. */
    public const CERTIFIED = 'certified';

    /** @var string[] The statuses. */
    public const STATUSES = [self::CERTIFIED, positioning_rules::WITHDRAWN];

    /**
     * Whether the current user may certify a bloc of this candidate.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_write(int $candidateid): bool {
        global $USER;

        if (!isloggedin() || isguestuser() || (int) $USER->id === $candidateid) {
            return false;
        }
        if (!\context_user::instance($candidateid, IGNORE_MISSING)) {
            return false;
        }
        $system = \context_system::instance();

        return has_capability('tool/vae:viewany', $system) && has_capability('tool/vae:certify', $system);
    }

    /**
     * Today, as YYYY-MM-DD, in the time zone of the person who reads or writes.
     *
     * @param int|null $time The moment; now when null.
     * @return string
     */
    public static function today(?int $time = null): string {
        return userdate($time ?? time(), '%Y-%m-%d', 99, false);
    }

    /**
     * Whether a certification that stands is due to be renewed: it has a last day and that day is past.
     *
     * The last day itself is still valid, to its end. Computed on reading and never written; a line with no day, a withdrawal
     * and an empty string are never due. The day is compared to the day of the reader.
     *
     * @param \stdClass|null $standing The line that stands, if any.
     * @param string $today The day of the reader, YYYY-MM-DD.
     * @return bool
     */
    public static function is_expired(?\stdClass $standing, string $today): bool {
        return $standing !== null
            && $standing->status === self::CERTIFIED
            && !empty($standing->validuntil)
            && strcmp((string) $standing->validuntil, $today) < 0;
    }

    /**
     * How many days since a last day, counted on dates and not on seconds (a day of 25 hours is one day).
     *
     * @param string $validuntil The last day, YYYY-MM-DD.
     * @param string $today The day of the reader, YYYY-MM-DD.
     * @return int Zero when the last day is today or not yet.
     */
    public static function days_overdue(string $validuntil, string $today): int {
        $last = new \DateTimeImmutable($validuntil, new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable($today, new \DateTimeZone('UTC'));

        return $now > $last ? (int) $last->diff($now)->days : 0;
    }

    /**
     * The competencies of a bloc that are not sufficient: the lacks the certification must be explained by.
     *
     * A competency never evaluated, or whose latest line is not sufficient (a withdrawal included), is a lack. The bloc
     * itself is not one, and a competency the source has dropped is left out.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param string $ficherang The rank of the bloc; empty for a record with no bloc.
     * @return string[] Rank => label of the competency, in the order of the record.
     * @throws \moodle_exception errorpositioningdenied
     */
    public function alert(int $candidateid, string $fichecode, string $ficherang): array {
        access::require_can_view($candidateid);

        return $this->lacking($candidateid, $fichecode, $ficherang);
    }

    /**
     * The lacks of a bloc, without asking who reads.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param string $ficherang
     * @return string[]
     */
    private function lacking(int $candidateid, string $fichecode, string $ficherang): array {
        $layout = positioning_rules::layout($fichecode);
        if (!$layout || !isset($layout->blocs[$ficherang])) {
            throw new \moodle_exception('errorpositioningdenied', 'tool_vae');
        }
        $latest = positioning_rules::latest(evaluation_manager::TABLE, $candidateid, $fichecode);
        $lacking = [];
        foreach ($layout->blocs[$ficherang]->leaves as $rank => $leaf) {
            if ($leaf->gone) {
                continue;
            }
            $line = $latest[(string) $rank] ?? null;
            if ($line === null || $line->status !== evaluation_manager::SUFFICIENT) {
                $lacking[(string) $rank] = $leaf->label;
            }
        }

        return $lacking;
    }

    /**
     * Certify a bloc: add a line.
     *
     * @param int $candidateid
     * @param string $fichecode The record.
     * @param string $ficherang The rank of a bloc of the record; empty for a record with no bloc, which is its own bloc.
     * @param string $status certified or withdrawn.
     * @param string $reason The written reason.
     * @param string $decisiondate YYYY-MM-DD, from 2000-01-01 to today.
     * @param string $alertreason Why the bloc is certified although a competency lacks; empty when none does.
     * @param string $validuntil The last day the certification stands, YYYY-MM-DD, from the day of the decision to 2100-01-01;
     *        empty when it does not lapse. Refused on a withdrawal.
     * @return \stdClass The row of tool_vae_certif.
     * @throws \moodle_exception errorpositioningdenied, errorpositioningstatus, errorpositioningempty,
     *         errorpositioningtoolong, errorpositioningcontrol, errorcertificationdate, errorcertificationalert,
     *         errorcertificationalertnone, errorcertificationvaliduntil, errorpositioningduplicate, errorpositioningnothing,
     *         errorpositioningbusy
     */
    public function write(
        int $candidateid,
        string $fichecode,
        string $ficherang,
        string $status,
        string $reason,
        string $decisiondate,
        string $alertreason = '',
        string $validuntil = ''
    ): \stdClass {
        global $DB, $USER;

        if (!self::can_write($candidateid)) {
            throw new \moodle_exception('errorpositioningdenied', 'tool_vae');
        }
        $layout = positioning_rules::layout($fichecode);
        if (
            !$layout || !isset($layout->blocs[$ficherang])
            || ($ficherang !== '' && !preg_match(importer::RANK_PATTERN, $ficherang))
            || !positioning_rules::declared_on($candidateid, $fichecode)
        ) {
            throw new \moodle_exception('errorpositioningdenied', 'tool_vae');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new \moodle_exception('errorpositioningstatus', 'tool_vae');
        }
        $reason = positioning_rules::clean_reason($reason);
        $this->check_date($decisiondate);
        $validuntil = trim($validuntil);
        if (
            $validuntil !== '' && (
                $status !== self::CERTIFIED
                || !positioning_rules::is_day($validuntil)
                || strcmp($validuntil, $decisiondate) < 0
                || strcmp($validuntil, positioning_rules::LATEST_DAY) > 0
            )
        ) {
            throw new \moodle_exception('errorcertificationvaliduntil', 'tool_vae');
        }
        $alertreason = trim(str_replace(["\r\n", "\r"], "\n", $alertreason));
        if ($alertreason !== '') {
            $alertreason = positioning_rules::clean_reason($alertreason);
        }

        $lock = positioning_rules::lock($candidateid, $fichecode);
        try {
            $latest = positioning_rules::latest(self::TABLE, $candidateid, $fichecode)[$ficherang] ?? null;
            if ($status === positioning_rules::WITHDRAWN) {
                // A withdrawal explains nothing else and raises no alert.
                if (!positioning_rules::has_standing($latest)) {
                    throw new \moodle_exception('errorpositioningnothing', 'tool_vae');
                }
                if ($alertreason !== '') {
                    throw new \moodle_exception('errorcertificationalertnone', 'tool_vae');
                }
            } else {
                $lacking = $this->lacking($candidateid, $fichecode, $ficherang);
                if ($lacking && $alertreason === '') {
                    throw new \moodle_exception('errorcertificationalert', 'tool_vae');
                }
                if (!$lacking && $alertreason !== '') {
                    throw new \moodle_exception('errorcertificationalertnone', 'tool_vae');
                }
            }
            $hash = positioning_rules::hash(array_merge(
                [$status, $fichecode, $ficherang, $reason, $decisiondate, $alertreason],
                $validuntil === '' ? [] : [$validuntil]
            ));
            if (positioning_rules::is_duplicate($latest, $hash)) {
                throw new \moodle_exception('errorpositioningduplicate', 'tool_vae');
            }

            $row = (object) [
                'uuid' => identity_map::generate_ulid(),
                'candidateid' => $candidateid,
                'fichecode' => $fichecode,
                'ficherang' => $ficherang,
                'status' => $status,
                'reason' => $reason,
                'reasonhash' => $hash,
                'decisiondate' => $decisiondate,
                'alertreason' => $alertreason === '' ? null : $alertreason,
                'validuntil' => $validuntil === '' ? null : $validuntil,
                'authorid' => (int) $USER->id,
                'authoruuid' => null,
                'importedat' => null,
                'timecreated' => time(),
            ];
            $row->id = $DB->insert_record(self::TABLE, $row);
        } finally {
            $lock->release();
        }

        certification_recorded::create([
            'context' => \context_user::instance($candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => $candidateid,
            'other' => ['fichecode' => $fichecode, 'ficherang' => $ficherang, 'status' => $status],
        ])->trigger();

        return $row;
    }

    /**
     * The date of a decision is a real day, from 2000-01-01 to today.
     *
     * @param string $date
     * @throws \moodle_exception errorcertificationdate
     */
    private function check_date(string $date): void {
        if (!positioning_rules::is_day($date) || strcmp($date, self::today()) > 0) {
            throw new \moodle_exception('errorcertificationdate', 'tool_vae');
        }
    }

    /**
     * The line that stands for each bloc of a record.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @return \stdClass[] Rank => the latest row (a withdrawal is a row too).
     */
    public function current(int $candidateid, string $fichecode): array {
        access::require_can_view($candidateid);

        return positioning_rules::latest(self::TABLE, $candidateid, $fichecode);
    }

    /**
     * Every line of one bloc, the earliest first.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param string $ficherang
     * @return \stdClass[] Rows.
     */
    public function lines(int $candidateid, string $fichecode, string $ficherang): array {
        access::require_can_view($candidateid);

        return positioning_rules::history(self::TABLE, $candidateid, $fichecode, $ficherang);
    }
}
