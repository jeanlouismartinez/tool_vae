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
 * What the evaluation of a competency and the certification of a bloc have in common (T-90, lot 3).
 *
 * The two are POSITIONINGS OF THE STRUCTURE, NEVER VALIDATIONS: the manager who follows the candidate evaluates a
 * competency, the direction certifies a bloc, and only the jury, outside this plugin, validates. Both are lines added to a
 * table and never changed; the latest line of a (candidate, record, rank) stands; a correction or a withdrawal is a new
 * line. This class holds the rules the two managers share: the written reason, the form of the hash, the layout of a
 * record (which rank is a bloc, which a competency), the lock and the reading of the latest lines.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class positioning_rules {
    /** @var int The longest reason, in characters: a reason, not a narrative. */
    public const MAX_LENGTH = 4000;

    /** @var string The status that takes a positioning back. */
    public const WITHDRAWN = 'withdrawn';

    /** @var string The earliest day a decision or a validity date may carry. */
    public const EARLIEST_DAY = '2000-01-01';

    /** @var string The latest day a validity date may carry. */
    public const LATEST_DAY = '2100-01-01';

    /**
     * Whether a string is a real day, written YYYY-MM-DD, from the earliest day allowed.
     *
     * No time zone is ever applied to it: a day is a day.
     *
     * @param string $day
     * @return bool
     */
    public static function is_day(string $day): bool {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && strcmp($day, self::EARLIEST_DAY) >= 0;
    }

    /**
     * The reason as it is kept: line ends normalised, trimmed, and checked. Never empty: a positioning of the structure
     * is explained in writing.
     *
     * @param string $text
     * @return string
     * @throws \moodle_exception errorpositioningempty, errorpositioningtoolong, errorpositioningcontrol
     */
    public static function clean_reason(string $text): string {
        $text = self::clean_text($text, self::MAX_LENGTH);
        if ($text === '') {
            throw new \moodle_exception('errorpositioningempty', 'tool_vae');
        }

        return $text;
    }

    /**
     * A written text as it is kept: line ends normalised, trimmed, bounded, and free of control characters.
     *
     * An empty text is accepted and given back empty: whether a text may be empty is the caller's rule (the justification
     * of the vaeiste's self-positioning may, the reason of the structure's positioning may not).
     *
     * @param string $text
     * @param int $max The longest text accepted, in characters.
     * @return string
     * @throws \moodle_exception errorpositioningtoolong, errorpositioningcontrol
     */
    public static function clean_text(string $text, int $max): string {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if (\core_text::strlen($text) > $max) {
            throw new \moodle_exception('errorpositioningtoolong', 'tool_vae', '', $max);
        }
        // A line feed and a tab are text; every other control, format or separator character is not.
        if (preg_match('/[^\P{Cc}\n\t]|[\p{Cf}\p{Zl}\p{Zp}]/u', $text)) {
            throw new \moodle_exception('errorpositioningcontrol', 'tool_vae');
        }

        return $text;
    }

    /**
     * The hash of a line: a canonical form of what it says, so that a restoration can check it against the text.
     *
     * @param string[] $parts In this order: status, record, rank, reason, then for a certification the date of the
     *                 decision and the reason of the alert (empty when there is none).
     * @return string
     */
    public static function hash(array $parts): string {
        return hash('sha256', implode("\x1f", array_map('strval', $parts)));
    }

    /**
     * How a record is laid out: which rank is a bloc, which a competency to evaluate.
     *
     * A bloc is a competency under which others stand; a competency with none, standing alone or under a bloc, is a leaf. A
     * record with no bloc at all is its own bloc, whose rank is the empty string. A leaf the source has dropped is `gone`: it
     * is still evaluated and read, and it is not a lack for the certification of its bloc.
     *
     * @param string $fichecode An imported record.
     * @return \stdClass|null Null when the record is not imported. Otherwise hasblocs, blocs (rank => label, leaves (rank
     *         => label, gone)) and leaves (rank => label, gone, bloc), the leaves of every bloc and the standing-alone ones.
     * @throws \moodle_exception errorcompetenciesdisabled
     */
    public static function layout(string $fichecode): ?\stdClass {
        global $DB;

        if (!competency_gateway::is_subsystem_enabled()) {
            throw new \moodle_exception('errorcompetenciesdisabled', 'tool_vae');
        }
        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        // A database that folds the case would find "rs00000" for "RS00000": the code is compared exactly.
        if (!$source || $source->fichecode !== $fichecode) {
            return null;
        }
        $gone = [];
        // A competency the source dropped ("missing"); one renamed or moved locally through tool_lp is still in the record.
        foreach ($DB->get_records('tool_vae_obsolete', ['sourceid' => $source->id], '', 'id, competencyid, kind') as $mark) {
            if ($mark->kind === 'missing') {
                $gone[(int) $mark->competencyid] = true;
            }
        }

        $rank = fn(\stdClass $competency): string => passage_manager::rank_of((string) $competency->idnumber, $fichecode);
        $blocs = [];
        $leaves = [];
        $listed = (new competency_gateway())->framework_tree((int) $source->frameworkid);
        foreach (outline::group($listed) as $node) {
            $noderank = $rank($node->competency);
            if ($node->children) {
                if (!preg_match(importer::RANK_PATTERN, $noderank)) {
                    continue;
                }
                $bloc = (object) ['label' => (string) $node->competency->label, 'leaves' => []];
                foreach ($node->children as $child) {
                    $childrank = $rank($child);
                    if (!preg_match(importer::RANK_PATTERN, $childrank)) {
                        continue;
                    }
                    $leaf = (object) [
                        'label' => (string) $child->label,
                        'gone' => isset($gone[(int) $child->id]),
                        'bloc' => $noderank,
                    ];
                    $bloc->leaves[$childrank] = $leaf;
                    $leaves[$childrank] = $leaf;
                }
                $blocs[$noderank] = $bloc;
            } else if (preg_match(importer::RANK_PATTERN, $noderank)) {
                $leaves[$noderank] = (object) [
                    'label' => (string) $node->competency->label,
                    'gone' => isset($gone[(int) $node->competency->id]),
                    'bloc' => null,
                ];
            }
        }
        $hasblocs = (bool) $blocs;
        if (!$hasblocs && $leaves) {
            // A record with no bloc is its own bloc, rank ''.
            $blocs[''] = (object) ['label' => (string) $source->fichecode, 'leaves' => $leaves];
        }

        return (object) ['hasblocs' => $hasblocs, 'blocs' => $blocs, 'leaves' => $leaves];
    }

    /**
     * Whether the candidate declared on a record at least once.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @return bool
     */
    public static function declared_on(int $candidateid, string $fichecode): bool {
        global $DB;

        return $DB->record_exists('tool_vae_evidence', ['candidateid' => $candidateid, 'fichecode' => $fichecode]);
    }

    /**
     * Take the lock that serialises the writes on one candidate and one record, evaluations and certifications together.
     *
     * The alert of a certification is read from the evaluations and the line is inserted under it: two sends at the same
     * moment cannot both pass a check made before either has written.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @return \core\lock\lock
     * @throws \moodle_exception errorpositioningbusy
     */
    public static function lock(int $candidateid, string $fichecode): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('tool_vae_positioning')
            ->get_lock('positioning' . $candidateid . '-' . $fichecode, 10);
        if (!$lock) {
            throw new \moodle_exception('errorpositioningbusy', 'tool_vae');
        }

        return $lock;
    }

    /**
     * The latest line of each rank of a record: the one that stands.
     *
     * The latest is the one with the greatest (time, id): two lines written in the same second are told apart by the id.
     *
     * @param string $table tool_vae_eval or tool_vae_certif.
     * @param int $candidateid
     * @param string $fichecode
     * @return \stdClass[] Rank => row, in the order of the ranks.
     */
    public static function latest(string $table, int $candidateid, string $fichecode): array {
        global $DB;

        $out = [];
        $rows = $DB->get_records($table, ['candidateid' => $candidateid, 'fichecode' => $fichecode], 'timecreated ASC, id ASC');
        foreach ($rows as $row) {
            $out[(string) $row->ficherang] = $row;
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Every line of one rank, the earliest first.
     *
     * @param string $table tool_vae_eval or tool_vae_certif.
     * @param int $candidateid
     * @param string $fichecode
     * @param string $ficherang
     * @return \stdClass[] Rows.
     */
    public static function history(string $table, int $candidateid, string $fichecode, string $ficherang): array {
        global $DB;

        $rows = $DB->get_records(
            $table,
            ['candidateid' => $candidateid, 'fichecode' => $fichecode, 'ficherang' => $ficherang],
            'timecreated ASC, id ASC'
        );
        return $rows;
    }

    /**
     * Whether a new line says nothing the latest line does not already say.
     *
     * @param \stdClass|null $latest The line that stands, if any.
     * @param string $hash The hash of the new line.
     * @return bool
     */
    public static function is_duplicate(?\stdClass $latest, string $hash): bool {
        return $latest !== null && hash_equals((string) $latest->reasonhash, $hash);
    }

    /**
     * Whether there is something to withdraw: a line that stands and is not itself a withdrawal.
     *
     * @param \stdClass|null $latest
     * @return bool
     */
    public static function has_standing(?\stdClass $latest): bool {
        return $latest !== null && $latest->status !== self::WITHDRAWN;
    }
}
