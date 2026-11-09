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

use tool_vae\event\note_recorded;

/**
 * The annotations of a manager the vaeiste consented to, on a work situation or on a node of the self-positioning (lot 1b).
 *
 * THE WORD OF A MANAGER, NEVER A VALIDATION. An annotation is free text, signed and dated, written beside the vaeiste's own
 * text and never into it: nothing here writes a draft, a version, a tag or a line of self-positioning. Nothing is computed from
 * it - no total, no rate, no threshold. The jury alone validates, outside this plugin.
 *
 * ADDED TO AND NEVER CHANGED: withdrawing an annotation is a new dated line that names, by its uuid, the annotation it
 * withdraws. At most MAX_PER_TARGET annotations (lines ACTIVE, restored ones included) on one target (D-1b-J); the bound
 * counts what opens, never what closes: a withdrawal is never refused by it (T15).
 *
 * WHO WRITES, READ AGAIN AT EACH ANNOTATION. Whoever is logged in, is not the vaeiste, follows them (access::follows(), which
 * the site administrator never does), holds tool/vae:annotate in their context, and holds their consent
 * (consent_manager::stands(), which reads neither the following nor the right: hence the four checks together here). Taking back
 * one's own annotation stays open to its author after the consent is withdrawn or the following lost: it takes back a word,
 * it adds none. Nobody withdraws the annotation of another.
 *
 * WHO READS. Whoever may read the dossier (access::require_can_view()), as for the consent.
 *
 * THE TARGET. A situation is named by its uuid (targetuuid): an annotation of a deleted situation stays, readable from the
 * list of the situations, and a new situation on the same slot does not inherit it (D-1b-K). A node of the self-positioning
 * is named by its rank, '' for the record itself; its targetuuid is ''.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class note_manager {
    /** @var string The table. */
    public const TABLE = 'tool_vae_note';

    /** @var int The longest annotation, in characters. */
    public const MAX_LENGTH = positioning_rules::MAX_LENGTH;

    /** @var int How many annotations (lines ACTIVE, restored ones included) one target may hold. Withdrawals are not counted
     *  and never refused; the storage stays bounded all the same, a withdrawal following an annotation of the same hand. */
    public const MAX_PER_TARGET = 200;

    /** @var string A work situation. */
    public const TARGET_SITUATION = 'situation';

    /** @var string A node of the self-positioning, or the record itself. */
    public const TARGET_SELFPOS = 'selfpos';

    /** @var string An annotation. */
    public const ACTIVE = 'active';

    /** @var string The withdrawal of an annotation. */
    public const WITHDRAWN = 'withdrawn';

    /** @var int[] FORMAT_PLAIN (2) and FORMAT_MARKDOWN (4), never HTML. */
    public const FORMATS = selfpos_manager::FORMATS;

    /** @var string The key of the lock, per vaeiste (positioning_rules::lock()): the one the consent takes, so that writing an
     *  annotation and giving or taking back the consent never cross. */
    private const LOCK_KEY = consent_manager::LOCK_KEY;

    /**
     * Whether a person may annotate this vaeiste now: all four rules together, read again at each call.
     *
     * @param int $candidateid
     * @param int|null $authorid The person; the current user when null.
     * @return bool
     */
    public static function can_write(int $candidateid, ?int $authorid = null): bool {
        global $USER;

        if ($authorid === null) {
            if (!isloggedin() || isguestuser()) {
                return false;
            }
            $authorid = (int) $USER->id;
        } else {
            $author = \core_user::get_user($authorid);
            if (!$author || !empty($author->deleted) || isguestuser($author)) {
                return false;
            }
        }
        if ($authorid === $candidateid) {
            return false;
        }
        // An id that is no account has no context: refused as a dossier one may not read is.
        $context = \context_user::instance($candidateid, IGNORE_MISSING);
        if (!$context || !access::follows($candidateid, $authorid)) {
            return false;
        }

        return has_capability('tool/vae:annotate', $context, $authorid)
            && consent_manager::stands($candidateid, $authorid);
    }

    /**
     * Annotate a situation or a node of the self-positioning: add a line.
     *
     * @param int $candidateid The vaeiste.
     * @param string $target TARGET_SITUATION or TARGET_SELFPOS.
     * @param string $fichecode The record.
     * @param int $slot The slot of the situation; 0 for a node.
     * @param string $ficherang The rank of the node, '' for the record itself; '' for a situation.
     * @param string $text The annotation, at most MAX_LENGTH characters.
     * @param int $format FORMAT_PLAIN or FORMAT_MARKDOWN.
     * @return \stdClass The row of tool_vae_note.
     * @throws \moodle_exception errornotedenied, errornoteempty, errorpositioningtoolong, errorpositioningcontrol,
     *         errornotefull, errornoteduplicate, errorpositioningbusy
     */
    public function write(
        int $candidateid,
        string $target,
        string $fichecode,
        int $slot,
        string $ficherang,
        string $text,
        int $format = FORMAT_PLAIN
    ): \stdClass {
        global $USER;

        if (!self::can_write($candidateid)) {
            throw new \moodle_exception('errornotedenied', 'tool_vae');
        }
        // The same refusal for every target that is not there: nothing tells which slots are taken or which ranks exist.
        $targetuuid = self::target_uuid($candidateid, $target, $fichecode, $slot, $ficherang);
        if ($targetuuid === null || !in_array($format, self::FORMATS, true)) {
            throw new \moodle_exception('errornotedenied', 'tool_vae');
        }
        $text = positioning_rules::clean_text($text, self::MAX_LENGTH);
        if ($text === '') {
            throw new \moodle_exception('errornoteempty', 'tool_vae');
        }
        $hash = positioning_rules::hash([$target, $fichecode, $slot, $ficherang, $text]);
        $authorid = (int) $USER->id;

        $lock = positioning_rules::lock($candidateid, self::LOCK_KEY);
        try {
            // Read again under the lock the consent shares (LOCK_KEY): a withdrawal of the consent, or a deletion of the
            // situation, that came in between is seen here, and an annotation never lands after the consent was taken back.
            $targetuuid = self::can_write($candidateid)
                ? self::target_uuid($candidateid, $target, $fichecode, $slot, $ficherang)
                : null;
            if ($targetuuid === null) {
                throw new \moodle_exception('errornotedenied', 'tool_vae');
            }
            $rows = $this->target_rows($candidateid, $target, $targetuuid, $fichecode, $slot, $ficherang);
            $active = array_filter($rows, fn($row) => $row->state === self::ACTIVE);
            if (count($active) >= self::MAX_PER_TARGET) {
                throw new \moodle_exception('errornotefull', 'tool_vae', '', self::MAX_PER_TARGET);
            }
            // The latest annotation of this author that still stands: the same text twice is a double click, not a word.
            $mine = array_filter(
                self::with_standing($rows),
                fn($row) => $row->standing && (int) $row->authorid === $authorid && $row->importedat === null
            );
            $latest = $mine ? end($mine) : null;
            if ($latest !== null && hash_equals((string) $latest->notehash, $hash)) {
                throw new \moodle_exception('errornoteduplicate', 'tool_vae');
            }

            $row = $this->insert([
                'candidateid' => $candidateid,
                'target' => $target,
                'targetuuid' => $targetuuid,
                'fichecode' => $fichecode,
                'slot' => $slot,
                'ficherang' => $ficherang,
                'text' => $text,
                'textformat' => $format,
                'state' => self::ACTIVE,
                'withdraws' => null,
                'notehash' => $hash,
            ]);
        } finally {
            $lock->release();
        }

        $this->trigger($row);

        return $row;
    }

    /**
     * Withdraw one's own annotation: add a line that names it. Open to its author alone, even once the consent is withdrawn or
     * the following lost; never to another, never on a restored line, which names no account of this site.
     *
     * A page sends the target it shows in $expected: an annotation of another vaeiste, or of another target, is refused there.
     *
     * @param int $noteid The row of the annotation.
     * @param array $expected Column => value the annotation must carry (candidateid, target, targetuuid, fichecode, slot,
     *        ficherang); compared as strings. Empty: no condition.
     * @return \stdClass The row of the withdrawal.
     * @throws \moodle_exception errornotedenied, errornotegone, errorpositioningbusy
     */
    public function withdraw(int $noteid, array $expected = []): \stdClass {
        global $DB, $USER;

        $note = $DB->get_record(self::TABLE, ['id' => $noteid]);
        if (!$note || $note->state !== self::ACTIVE) {
            throw new \moodle_exception('errornotegone', 'tool_vae');
        }
        foreach ($expected as $column => $value) {
            if (!property_exists($note, $column) || (string) $note->$column !== (string) $value) {
                throw new \moodle_exception('errornotedenied', 'tool_vae');
            }
        }
        if (
            !isloggedin() || isguestuser() || $note->authorid === null || $note->importedat !== null
            || (int) $note->authorid !== (int) $USER->id
        ) {
            throw new \moodle_exception('errornotedenied', 'tool_vae');
        }
        $candidateid = (int) $note->candidateid;
        $slot = (int) $note->slot;
        $hash = positioning_rules::hash([$note->target, $note->fichecode, $slot, $note->ficherang, '', $note->uuid]);

        $lock = positioning_rules::lock($candidateid, self::LOCK_KEY);
        try {
            // No bound here: a withdrawal closes (T15). Once only, though.
            if ($DB->record_exists(self::TABLE, ['candidateid' => $candidateid, 'withdraws' => $note->uuid])) {
                throw new \moodle_exception('errornotegone', 'tool_vae');
            }
            $row = $this->insert([
                'candidateid' => $candidateid,
                'target' => (string) $note->target,
                'targetuuid' => (string) $note->targetuuid,
                'fichecode' => (string) $note->fichecode,
                'slot' => $slot,
                'ficherang' => (string) $note->ficherang,
                'text' => '',
                'textformat' => (int) $note->textformat,
                'state' => self::WITHDRAWN,
                'withdraws' => (string) $note->uuid,
                'notehash' => $hash,
            ]);
        } finally {
            $lock->release();
        }

        $this->trigger($row);

        return $row;
    }

    /**
     * Every line of one target, the earliest first; each line says whether it stands.
     *
     * For a situation, the target is the situation that holds the slot now: the lines of a deleted situation that held it
     * before are not its own (see for_candidate()). A situation is a part of the preparation: whoever may not read it reads
     * none of its annotations, the site administrator included (MC-16, D-1b-G).
     *
     * @param int $candidateid
     * @param string $target
     * @param string $fichecode
     * @param int $slot
     * @param string $ficherang
     * @return \stdClass[] Rows, each with ->standing (true for an annotation no later line withdraws).
     * @throws \moodle_exception errordraftrefused for a situation whoever may not read the preparation asks
     */
    public function for_target(int $candidateid, string $target, string $fichecode, int $slot, string $ficherang): array {
        global $DB;

        access::require_can_view($candidateid);
        if ($target === self::TARGET_SITUATION) {
            if (!draft_manager::can_read_doctype($candidateid, draft_manager::DOCTYPE_VALIDATION)) {
                throw new \moodle_exception('errordraftrefused', 'tool_vae');
            }
            $uuid = $DB->get_field(
                'tool_vae_situation',
                'uuid',
                ['candidateid' => $candidateid, 'fichecode' => $fichecode, 'slot' => $slot]
            );
            if ($uuid === false) {
                return [];
            }
            $targetuuid = (string) $uuid;
        } else {
            $targetuuid = '';
        }

        return self::with_standing($this->target_rows($candidateid, $target, $targetuuid, $fichecode, $slot, $ficherang));
    }

    /**
     * Every line about this vaeiste, the earliest first: for the export, and the annotations of deleted situations.
     *
     * The raw layer of data, WITHOUT THE DOOR OF THE READING OF THE SITUATIONS (draft_manager::can_read_doctype()): the
     * caller keeps it. A page shows these lines only behind that door (situations.php); the archive reads them server side.
     *
     * @param int $candidateid
     * @return \stdClass[] Rows, each with ->standing.
     */
    public function for_candidate(int $candidateid): array {
        global $DB;

        access::require_can_view($candidateid);

        return self::with_standing($DB->get_records(self::TABLE, ['candidateid' => $candidateid], 'timecreated ASC, id ASC'));
    }

    /**
     * The uuid that names the target, or null when there is no such target.
     *
     * @param int $candidateid
     * @param string $target
     * @param string $fichecode
     * @param int $slot
     * @param string $ficherang
     * @return string|null The uuid of the situation; '' for a node; null when refused.
     */
    private static function target_uuid(
        int $candidateid,
        string $target,
        string $fichecode,
        int $slot,
        string $ficherang
    ): ?string {
        if ($target === self::TARGET_SITUATION) {
            if ($ficherang !== '' || $slot < 1) {
                return null;
            }
            $situation = (new situation_manager())->find($candidateid, $fichecode, $slot);
            if ($situation === null || (string) $situation->fichecode !== $fichecode) {
                return null;
            }

            return (string) $situation->uuid;
        }
        if ($target === self::TARGET_SELFPOS) {
            if ($slot !== 0) {
                return null;
            }
            $tree = selfpos_tree::for_record($fichecode);
            $node = $tree === null ? null : selfpos_tree::node($tree, $ficherang);
            if ($node === null || !$node->positionable) {
                return null;
            }

            return '';
        }

        return null;
    }

    /**
     * Every line of one target, the earliest first.
     *
     * @param int $candidateid
     * @param string $target
     * @param string $targetuuid
     * @param string $fichecode
     * @param int $slot
     * @param string $ficherang
     * @return \stdClass[]
     */
    private function target_rows(
        int $candidateid,
        string $target,
        string $targetuuid,
        string $fichecode,
        int $slot,
        string $ficherang
    ): array {
        global $DB;

        return $DB->get_records(self::TABLE, [
            'candidateid' => $candidateid,
            'target' => $target,
            'targetuuid' => $targetuuid,
            'fichecode' => $fichecode,
            'slot' => $slot,
            'ficherang' => $ficherang,
        ], 'timecreated ASC, id ASC');
    }

    /**
     * Say of each line whether it stands: an annotation that no line of the same hand withdraws.
     *
     * A withdrawal counts only when it is by the hand that wrote the annotation (same_hand()): a line restored from an
     * archive, whatever identifier it names, never takes back what a manager wrote here (D-1b-L, T12 tour 1).
     *
     * @param \stdClass[] $rows
     * @return \stdClass[]
     */
    private static function with_standing(array $rows): array {
        $withdrawals = [];
        foreach ($rows as $row) {
            if ($row->state === self::WITHDRAWN && $row->withdraws !== null) {
                $withdrawals[(string) $row->withdraws][] = $row;
            }
        }
        foreach ($rows as $row) {
            $row->standing = $row->state === self::ACTIVE;
            foreach ($withdrawals[(string) $row->uuid] ?? [] as $withdrawal) {
                if (self::same_hand($row, $withdrawal)) {
                    $row->standing = false;
                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * Whether a withdrawal is by the hand that wrote the annotation: from the same place - both written here, or both
     * restored from an archive - and by the same author (authorid for a line written here, authoruuid for a restored one).
     *
     * TWO ERASED AUTHORS READ ALIKE (both null). For a line written here that is always right: withdraw() lets nobody
     * withdraw another's annotation, so an annotation and its withdrawal written here are one hand, erased or not, and the
     * erasure of their author (purge, privacy provider) takes no withdrawal away. For restored lines it is right across
     * batches (situation_archive::withdrawals_agree() requires the same authoruuid of a withdrawal and an annotation
     * already here) but NOT within one batch, where the authors are not compared: an archive may say that X withdrew what Y
     * wrote. Such a withdrawal does not hold while both identifiers stand, and holds once both authors are erased - the
     * reading then moves towards what the archive said, never against a word written here.
     *
     * @param \stdClass $note The annotation.
     * @param \stdClass $withdrawal The line that names it.
     * @return bool
     */
    private static function same_hand(\stdClass $note, \stdClass $withdrawal): bool {
        if (($note->importedat === null) !== ($withdrawal->importedat === null)) {
            return false;
        }

        return $note->importedat === null
            ? (string) $note->authorid === (string) $withdrawal->authorid
            : (string) $note->authoruuid === (string) $withdrawal->authoruuid;
    }

    /**
     * Insert a line written on this site by the current user: authorid is theirs, authoruuid stays null (the archive computes
     * it, D-1b-L), importedat null.
     *
     * @param array $fields candidateid, target, targetuuid, fichecode, slot, ficherang, text, textformat, state, withdraws,
     *        notehash.
     * @return \stdClass The row, with its id.
     */
    private function insert(array $fields): \stdClass {
        global $DB, $USER;

        $row = (object) ([
            'uuid' => identity_map::generate_ulid(),
            'authorid' => (int) $USER->id,
            'authoruuid' => null,
            'importedat' => null,
            'timecreated' => time(),
        ] + $fields);
        $row->id = $DB->insert_record(self::TABLE, $row);

        return $row;
    }

    /**
     * Say that a line was written: identifiers and the state, never the text.
     *
     * @param \stdClass $row
     */
    private function trigger(\stdClass $row): void {
        note_recorded::create([
            'context' => \context_user::instance((int) $row->candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => (int) $row->candidateid,
            'other' => [
                'target' => (string) $row->target,
                'fichecode' => (string) $row->fichecode,
                'slot' => (int) $row->slot,
                'ficherang' => (string) $row->ficherang,
                'state' => (string) $row->state,
            ],
        ])->trigger();
    }
}
