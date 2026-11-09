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

use tool_vae\event\consent_changed;

/**
 * The consent of the vaeiste to the annotation of a manager who follows them (lot 1b).
 *
 * GIVEN BY NAME, BY THE VAEISTE ALONE, AND TAKEN BACK AT ANY TIME. The consent lets one person annotate the work situations
 * and the self-positioning of the vaeiste; it never lets anyone change the text of the vaeiste, and an annotation is never a
 * validation. It is given to a person who follows the vaeiste (access::followers()) and holds tool/vae:annotate in the
 * vaeiste's context (D-1b-B): the direction, which reads every dossier, receives it only if it also follows the vaeiste. The
 * administrator follows nobody (access::follows()), so receives none.
 *
 * Lines added and never changed: the latest line of a couple (vaeiste, manager) WRITTEN ON THIS SITE says whether the consent
 * stands. A line restored from an archive names no account of the site and never opens the annotation (D-1b-L); it stays in the
 * history and in the export. Withdrawing erases no annotation: the past ones stay read. Whoever may read the dossier
 * (access::can_view()) reads the consent; nothing here is computed.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class consent_manager {
    /** @var string The table of the lines. */
    public const TABLE = 'tool_vae_consent';

    /** @var string The consent stands. */
    public const GIVEN = 'given';

    /** @var string The consent was taken back. */
    public const WITHDRAWN = 'withdrawn';

    /** @var int How many lines GIVEN one vaeiste may hold, restored ones included, so that the dossier stays restorable
     *  (D-1b-J). The bound counts what opens, never what closes: a withdrawal is never refused by it (T15), and the storage
     *  stays bounded all the same, since a withdrawal written here follows a line given of the same couple. */
    public const MAX_LINES = 400;

    /** @var string The name of the lock that serialises the consent of one vaeiste (positioning_rules::lock()). */
    public const LOCK_KEY = 'consent';

    /**
     * Whether the current user may give or take back consent on this dossier: it is theirs, and they may read it.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_manage(int $candidateid): bool {
        global $USER;

        if (!isloggedin() || isguestuser() || (int) $USER->id !== $candidateid) {
            return false;
        }
        $context = \context_user::instance($candidateid, IGNORE_MISSING);

        return $context && has_capability('tool/vae:viewown', $context);
    }

    /**
     * The people the vaeiste may give consent to: those who follow them and hold annotate in their context (D-1b-B).
     *
     * @param int $candidateid
     * @return int[] User ids, sorted.
     */
    public function eligible(int $candidateid): array {
        $context = access::require_can_view($candidateid);

        return array_values(array_filter(
            access::followers($candidateid),
            fn(int $id) => has_capability('tool/vae:annotate', $context, $id)
        ));
    }

    /**
     * Give consent to a person who may receive it: add a line.
     *
     * @param int $candidateid The vaeiste, who is the current user.
     * @param int $managerid
     * @return \stdClass The row of tool_vae_consent.
     * @throws \moodle_exception errorconsentdenied, errorconsentsame, errorconsentfull, errorpositioningbusy
     */
    public function give(int $candidateid, int $managerid): \stdClass {
        if (!self::can_manage($candidateid) || !in_array($managerid, $this->eligible($candidateid), true)) {
            throw new \moodle_exception('errorconsentdenied', 'tool_vae');
        }

        return $this->add($candidateid, $managerid, self::GIVEN);
    }

    /**
     * Take back consent: add a line. Allowed whoever the person has become, as long as the consent stands.
     *
     * @param int $candidateid The vaeiste, who is the current user.
     * @param int $managerid
     * @return \stdClass The row of tool_vae_consent.
     * @throws \moodle_exception errorconsentdenied, errorconsentsame, errorconsentfull, errorpositioningbusy
     */
    public function withdraw(int $candidateid, int $managerid): \stdClass {
        if (!self::can_manage($candidateid)) {
            throw new \moodle_exception('errorconsentdenied', 'tool_vae');
        }

        return $this->add($candidateid, $managerid, self::WITHDRAWN);
    }

    /**
     * Whether the consent of the vaeiste to this person stands: the latest line of the couple written on this site is given.
     *
     * A line restored from an archive never counts (D-1b-L). No check of reading: this is the question an annotation asks of
     * the person who writes it, on top of their right and of following the vaeiste.
     *
     * @param int $candidateid
     * @param int $managerid
     * @return bool
     */
    public static function stands(int $candidateid, int $managerid): bool {
        $latest = self::latest_local($candidateid, $managerid);

        return $latest !== null && $latest->state === self::GIVEN;
    }

    /**
     * The line that stands for each person, among the lines written on this site.
     *
     * @param int $candidateid
     * @return \stdClass[] Manager id => the latest row (a withdrawal is a row too), by manager id.
     */
    public function current(int $candidateid): array {
        global $DB;
        access::require_can_view($candidateid);

        $out = [];
        $rows = $DB->get_records_select(
            self::TABLE,
            'candidateid = :candidateid AND importedat IS NULL AND managerid IS NOT NULL',
            ['candidateid' => $candidateid],
            'timecreated ASC, id ASC'
        );
        foreach ($rows as $row) {
            $out[(int) $row->managerid] = $row;
        }
        ksort($out);

        return $out;
    }

    /**
     * Every line of the vaeiste, restored ones included, in the order written.
     *
     * @param int $candidateid
     * @return \stdClass[] Rows of tool_vae_consent, each with restored (bool): whether it came from an archive.
     */
    public function history(int $candidateid): array {
        global $DB;
        access::require_can_view($candidateid);

        $rows = $DB->get_records(self::TABLE, ['candidateid' => $candidateid], 'timecreated ASC, id ASC');
        foreach ($rows as $row) {
            $row->restored = $row->importedat !== null;
        }

        return array_values($rows);
    }

    /**
     * Add a line under the lock of the vaeiste, after the checks that read the lines already there.
     *
     * The bound (MAX_LINES) counts the lines GIVEN of the vaeiste, whatever their origin, and refuses a line given only: a
     * withdrawal closes, it is never refused (consentintro promises "at any time").
     *
     * @param int $candidateid
     * @param int $managerid
     * @param string $state
     * @return \stdClass
     * @throws \moodle_exception errorconsentsame, errorconsentfull, errorpositioningbusy
     */
    private function add(int $candidateid, int $managerid, string $state): \stdClass {
        global $DB;

        $lock = positioning_rules::lock($candidateid, self::LOCK_KEY);
        try {
            // No line yet counts as no consent: withdrawing it is the same state twice.
            $latest = self::latest_local($candidateid, $managerid);
            if (($latest === null ? self::WITHDRAWN : $latest->state) === $state) {
                throw new \moodle_exception('errorconsentsame', 'tool_vae');
            }
            $given = $DB->count_records(self::TABLE, ['candidateid' => $candidateid, 'state' => self::GIVEN]);
            if ($state === self::GIVEN && $given >= self::MAX_LINES) {
                throw new \moodle_exception('errorconsentfull', 'tool_vae', '', self::MAX_LINES);
            }
            $row = (object) [
                'uuid' => identity_map::generate_ulid(),
                'candidateid' => $candidateid,
                'managerid' => $managerid,
                'manageruuid' => null,
                'state' => $state,
                'importedat' => null,
                'timecreated' => time(),
            ];
            $row->id = $DB->insert_record(self::TABLE, $row);
        } finally {
            $lock->release();
        }

        // The vaeiste acts, in their own context; the manager is the person the event is about (T15, point D).
        consent_changed::create([
            'context' => \context_user::instance($candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => $managerid,
            'other' => ['state' => $state],
        ])->trigger();

        return $row;
    }

    /**
     * The latest line of a couple written on this site, if any.
     *
     * @param int $candidateid
     * @param int $managerid
     * @return \stdClass|null
     */
    private static function latest_local(int $candidateid, int $managerid): ?\stdClass {
        global $DB;

        $rows = $DB->get_records_select(
            self::TABLE,
            'candidateid = :candidateid AND managerid = :managerid AND importedat IS NULL',
            ['candidateid' => $candidateid, 'managerid' => $managerid],
            'timecreated DESC, id DESC',
            '*',
            0,
            1
        );

        return $rows ? reset($rows) : null;
    }
}
