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

use tool_vae\event\selfpositioning_recorded;

/**
 * The self-positioning of the vaeiste on ONE node of a record, at any level (lot 1a).
 *
 * THE POSITIONING OF THE VAEISTE, NEVER A VALIDATION. The vaeiste says, by a step of the scale (selfpos_scale), where THEY
 * stand on a node of their record - the record itself, a domain, a competency, a skill - with an optional written
 * justification. It is a trace of their development, read by them and by those who may read their dossier, to support the
 * conversation with the manager who follows them. Nothing is ever computed from it: no total, no rate, no threshold, no gap
 * with what the structure wrote. The jury alone validates, outside this plugin.
 *
 * ADDED TO AND NEVER CHANGED: a correction is a new dated line, the withdrawal of the level (step 0) is a new line too, and
 * the latest line of a (vaeiste, record, rank) stands. Each line keeps the label its step read when it was written.
 *
 * WHO WRITES. The vaeiste alone, on their own dossier, with tool/vae:positionself in their own user context. Neither the
 * manager who follows them nor the site administrator writes in their place.
 *
 * WHO READS. Whoever may read the dossier (access::can_view()): the vaeiste, the managers who follow them, the direction.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selfpos_manager {
    /** @var string The table. */
    public const TABLE = 'tool_vae_selfpos';

    /** @var int The longest justification, in characters: room to write at length, then to tighten. */
    public const MAX_LENGTH = 10000;

    /** @var int How many lines one node of one vaeiste may hold: a bound against a script, not against a real use. */
    public const MAX_PER_NODE = 200;

    /** @var int The index that withdraws the level the vaeiste had given. */
    public const WITHDRAWN = 0;

    /** @var int[] The formats a justification may be written in: FORMAT_PLAIN (2) and FORMAT_MARKDOWN (4). Never HTML. The
     *  core defines its FORMAT_ constants as strings, so the numbers are written here and compared as integers. */
    public const FORMATS = [2, 4];

    /**
     * Whether the current user may position themselves on this dossier: it is theirs, and they hold the right.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_write(int $candidateid): bool {
        global $USER;

        if (!isloggedin() || isguestuser() || (int) $USER->id !== $candidateid) {
            return false;
        }
        $context = \context_user::instance($candidateid, IGNORE_MISSING);

        return $context && has_capability('tool/vae:positionself', $context);
    }

    /**
     * Position oneself on a node: add a line.
     *
     * @param int $candidateid The vaeiste, who is the current user.
     * @param string $fichecode The record.
     * @param string $ficherang The rank of a node of the record, at any level; '' for the record itself.
     * @param int $level The index of the step, 1 to selfpos_scale::COUNT; WITHDRAWN (0) to withdraw the level given.
     * @param string $justification Optional; at most MAX_LENGTH characters.
     * @param int $format FORMAT_PLAIN or FORMAT_MARKDOWN.
     * @return \stdClass The row of tool_vae_selfpos.
     * @throws \moodle_exception errorselfposdenied, errorselfposlevel, errorselfposformat, errorpositioningtoolong,
     *         errorpositioningcontrol, errorselfposnothing, errorselfposduplicate, errorselfposfull, errorpositioningbusy,
     *         errorcompetenciesdisabled
     */
    public function write(
        int $candidateid,
        string $fichecode,
        string $ficherang,
        int $level,
        string $justification = '',
        int $format = FORMAT_PLAIN
    ): \stdClass {
        global $DB;

        if (!self::can_write($candidateid)) {
            throw new \moodle_exception('errorselfposdenied', 'tool_vae');
        }
        // The same refusal for a record that is not imported, a rank that is no node of it, and a node whose idnumber no
        // other screen can name (not positionable): nothing here tells which of the three it was.
        $tree = selfpos_tree::for_record($fichecode);
        $node = $tree === null ? null : selfpos_tree::node($tree, $ficherang);
        if ($node === null || !$node->positionable) {
            throw new \moodle_exception('errorselfposdenied', 'tool_vae');
        }
        if ($level < self::WITHDRAWN || $level > selfpos_scale::COUNT) {
            throw new \moodle_exception('errorselfposlevel', 'tool_vae');
        }
        if (!in_array($format, self::FORMATS, true)) {
            throw new \moodle_exception('errorselfposformat', 'tool_vae');
        }
        $justification = positioning_rules::clean_text($justification, self::MAX_LENGTH);
        $levellabel = selfpos_scale::label($level);
        $hash = positioning_rules::hash([$level, $levellabel, $fichecode, $ficherang, $justification]);

        $lock = positioning_rules::lock($candidateid, $fichecode);
        try {
            $history = positioning_rules::history(self::TABLE, $candidateid, $fichecode, $ficherang);
            $latest = $history ? end($history) : null;
            if ($level === self::WITHDRAWN && !self::standing($latest)) {
                throw new \moodle_exception('errorselfposnothing', 'tool_vae');
            }
            if ($latest !== null && hash_equals((string) $latest->linehash, $hash)) {
                throw new \moodle_exception('errorselfposduplicate', 'tool_vae');
            }
            if (count($history) >= self::MAX_PER_NODE) {
                throw new \moodle_exception('errorselfposfull', 'tool_vae', '', self::MAX_PER_NODE);
            }

            $row = (object) [
                'uuid' => identity_map::generate_ulid(),
                'candidateid' => $candidateid,
                'fichecode' => $fichecode,
                'ficherang' => $ficherang,
                'level' => $level,
                'levellabel' => $levellabel,
                'justification' => $justification,
                'justificationformat' => $format,
                'linehash' => $hash,
                'importedat' => null,
                'timecreated' => time(),
            ];
            $row->id = $DB->insert_record(self::TABLE, $row);
        } finally {
            $lock->release();
        }

        selfpositioning_recorded::create([
            'context' => \context_user::instance($candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => $candidateid,
            'other' => ['fichecode' => $fichecode, 'ficherang' => $ficherang, 'level' => $level],
        ])->trigger();

        return $row;
    }

    /**
     * The line that stands for each node of a record the vaeiste wrote on.
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
     * Every line of one node, the earliest first.
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

    /**
     * Whether a line gives a level that stands: it exists and is not a withdrawal.
     *
     * @param \stdClass|null $latest
     * @return bool
     */
    public static function standing(?\stdClass $latest): bool {
        return $latest !== null && (int) $latest->level !== self::WITHDRAWN;
    }
}
