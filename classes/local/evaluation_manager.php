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

use tool_vae\event\evaluation_recorded;

/**
 * The evaluation of ONE competency of a candidate, by the manager who follows them (T-90, lot 3).
 *
 * A POSITIONING OF THE STRUCTURE, NEVER A VALIDATION. The manager says where the competency stands - to build, in the course
 * of proof, sufficient for certification - and why, in writing. The jury alone validates, outside this plugin. Added to and
 * never changed: a correction or a withdrawal is a new dated line, the latest line stands.
 *
 * WHO WRITES. Whoever follows the candidate and holds tool/vae:evaluate in their context. Nobody evaluates themselves, and
 * the site administrator, who follows nobody, does not evaluate in the manager's place.
 *
 * WHO READS. Whoever may read the dossier (access::can_view()): the candidate, the managers who follow them, the direction.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class evaluation_manager {
    /** @var string The table. */
    public const TABLE = 'tool_vae_eval';

    /** @var int The longest reason, in characters. */
    public const MAX_LENGTH = positioning_rules::MAX_LENGTH;

    /** @var string[] The statuses, in the order of the screen. */
    public const STATUSES = ['toconstruct', 'inproof', 'sufficient', positioning_rules::WITHDRAWN];

    /** @var string The status that counts as sufficient for the certification of a bloc. */
    public const SUFFICIENT = 'sufficient';

    /**
     * Whether the current user may evaluate a competency of this candidate.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_write(int $candidateid): bool {
        global $USER;

        if (!isloggedin() || isguestuser() || (int) $USER->id === $candidateid) {
            return false;
        }
        // An id that is no account has no context: refused as a dossier one may not read is.
        $context = \context_user::instance($candidateid, IGNORE_MISSING);
        if (!$context || !access::follows($candidateid)) {
            return false;
        }

        return has_capability('tool/vae:evaluate', $context) && access::can_view($candidateid);
    }

    /**
     * Evaluate a competency: add a line.
     *
     * @param int $candidateid
     * @param string $fichecode The record.
     * @param string $ficherang The rank of a competency of the record (a leaf, not a bloc).
     * @param string $status One of STATUSES.
     * @param string $reason The written reason.
     * @return \stdClass The row of tool_vae_eval.
     * @throws \moodle_exception errorpositioningdenied, errorpositioningstatus, errorpositioningempty,
     *         errorpositioningtoolong, errorpositioningcontrol, errorpositioningduplicate, errorpositioningnothing,
     *         errorpositioningbusy, errorcompetenciesdisabled
     */
    public function write(int $candidateid, string $fichecode, string $ficherang, string $status, string $reason): \stdClass {
        global $DB, $USER;

        if (!self::can_write($candidateid)) {
            throw new \moodle_exception('errorpositioningdenied', 'tool_vae');
        }
        // The same refusal for a record that is not there, a rank that is not a competency of it, and a record the
        // candidate never declared on: nothing here tells which of the three it was.
        $layout = positioning_rules::layout($fichecode);
        if (
            !$layout || !isset($layout->leaves[$ficherang]) || !preg_match(importer::RANK_PATTERN, $ficherang)
            || !positioning_rules::declared_on($candidateid, $fichecode)
        ) {
            throw new \moodle_exception('errorpositioningdenied', 'tool_vae');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new \moodle_exception('errorpositioningstatus', 'tool_vae');
        }
        $reason = positioning_rules::clean_reason($reason);
        $hash = positioning_rules::hash([$status, $fichecode, $ficherang, $reason]);

        $lock = positioning_rules::lock($candidateid, $fichecode);
        try {
            $latest = positioning_rules::latest(self::TABLE, $candidateid, $fichecode)[$ficherang] ?? null;
            if ($status === positioning_rules::WITHDRAWN && !positioning_rules::has_standing($latest)) {
                throw new \moodle_exception('errorpositioningnothing', 'tool_vae');
            }
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
                'authorid' => (int) $USER->id,
                'authoruuid' => null,
                'importedat' => null,
                'timecreated' => time(),
            ];
            $row->id = $DB->insert_record(self::TABLE, $row);
        } finally {
            $lock->release();
        }

        evaluation_recorded::create([
            'context' => \context_user::instance($candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => $candidateid,
            'other' => ['fichecode' => $fichecode, 'ficherang' => $ficherang, 'status' => $status],
        ])->trigger();

        return $row;
    }

    /**
     * The line that stands for each competency of a record.
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
     * Every line of one competency, the earliest first.
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
