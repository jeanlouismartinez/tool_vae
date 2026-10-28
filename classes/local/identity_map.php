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
 * Correspondence between Moodle's integer identifiers and opaque ULIDs.
 *
 * WHY THIS CLASS EXISTS - DER-referentiel-001, compensating measure MC-8.
 *
 * The exported dossier must carry objects conforming to preuve.schema.json, whose `id`,
 * `personne`, `competence` and `structure` are internal opaque identifiers - UUID v4 or
 * ULID. Moodle's identifiers are auto-increment integers, which the pivot schema rejects
 * outright: the project wrote a counter-example for exactly that case.
 *
 * Without this map, the export cannot produce a valid object; without a valid export, MC-1
 * is not satisfied; without MC-1, the derogation to the native model has no counterpart.
 * This is not a nicety, it is what makes the derogation hold.
 *
 * The Moodle identifier lives in the pivot's `correspondances[]` array, never as a key -
 * so that changing platform touches this table and nothing else.
 *
 * ON NEVER REASSIGNING AN IDENTIFIER:
 * the rule is that an allocated identifier is never given to something else, even after
 * deletion. Moodle's auto-increment identifiers are never recycled, so deleting a row here
 * when a candidate's dossier is erased cannot make a later record inherit an old ULID. The
 * map is therefore erasable with the rest of the person's data.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class identity_map {
    /** @var string Table holding the correspondence. */
    public const TABLE = 'tool_vae_identity';

    /** @var string A Moodle user. */
    public const TYPE_USER = 'user';

    /** @var string A core competency. */
    public const TYPE_COMPETENCY = 'competency';

    /** @var string An organisation the activity was carried out in. */
    public const TYPE_STRUCTURE = 'structure';

    /**
     * Crockford base 32.
     *
     * I, L, O and U are absent: the first three because they are misread as 1, 1 and 0,
     * the last so that the alphabet cannot spell unfortunate words. A jury member reading
     * an identifier aloud from a printed dossier is a real situation.
     *
     * @var string
     */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * The shape preuve.schema.json accepts. Kept here so tests can assert it.
     *
     * D, because without it PHP's $ also matches before a final newline, which ajv refuses.
     *
     * @var string
     */
    public const ULID_PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D';

    /**
     * The ULID for an item, minted on first request and stable thereafter.
     *
     * @param string $itemtype One of the TYPE_* constants.
     * @param int $itemid The Moodle identifier.
     * @return string A 26 character ULID.
     */
    public static function uuid_for(string $itemtype, int $itemid): string {
        global $DB;

        self::validate_type($itemtype);

        $existing = $DB->get_field(self::TABLE, 'uuid', ['itemtype' => $itemtype, 'itemid' => $itemid]);
        if ($existing !== false) {
            return $existing;
        }

        $record = (object) [
            'itemtype'    => $itemtype,
            'itemid'      => $itemid,
            'uuid'        => self::generate_ulid(),
            'timecreated' => time(),
        ];

        try {
            $DB->insert_record(self::TABLE, $record);
        } catch (\dml_write_exception $e) {
            // Two concurrent requests minting the same item both passed the read above.
            // The unique index rejected the loser, which must return the winner's value
            // rather than fail: the identifier is the same item either way. Re-reading and
            // still finding nothing means a genuine write failure, so the exception stands.
            $winner = $DB->get_field(self::TABLE, 'uuid', ['itemtype' => $itemtype, 'itemid' => $itemid]);
            if ($winner === false) {
                throw $e;
            }
            return $winner;
        }

        return $record->uuid;
    }

    /**
     * The Moodle item behind a ULID, or null when it is unknown here.
     *
     * Used on re-import: an archive produced elsewhere carries ULIDs this instance has
     * never seen, and the importer must be able to tell "unknown" from "malformed".
     *
     * @param string $uuid
     * @return array{itemtype: string, itemid: int}|null
     */
    public static function resolve(string $uuid): ?array {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['uuid' => $uuid], 'itemtype, itemid');
        if (!$record) {
            return null;
        }

        return ['itemtype' => $record->itemtype, 'itemid' => (int) $record->itemid];
    }

    /**
     * What would stop an item from being given the ULID an archive knows it by, said without giving it.
     *
     * THE RULE OF adopt(), ASKED AHEAD OF IT. An archive is checked whole before anything is written, and adopt() runs
     * inside the transaction of the write: a refusal there comes after every other check has passed, and the importer
     * learns of it last. Asked here, in the checks, the refusal comes first and can be named for what it is - a
     * competency is not an account. adopt() takes its decision from the same statement (conflict()), so that there is
     * one statement of the rule and the two cannot drift.
     *
     * @param string $itemtype One of the TYPE_* constants.
     * @param int $itemid The Moodle identifier.
     * @param string $uuid
     * @return string|null The error code adopt() would throw: errorarchiveidentityheld when the item already holds another
     *         ULID, errorarchiveidentitytaken when the ULID is given to another item. Null when the item may take it, or
     *         already holds it.
     */
    public static function adoption_conflict(string $itemtype, int $itemid, string $uuid): ?string {
        global $DB;

        self::validate_type($itemtype);

        return self::conflict($DB->get_field(self::TABLE, 'uuid', ['itemtype' => $itemtype, 'itemid' => $itemid]), $uuid);
    }

    /**
     * The same question for several items at once: what would stop each of them from being given the ULID the archive knows it by.
     *
     * SO THAT AN ARCHIVE IS REFUSED ONCE, NAMING EVERY ITEM IN CONFLICT, NOT THE FIRST (27 October 2026). The rule is the one of
     * adoption_conflict(), asked of each item in turn; nothing is given. Two items asking for the same ULID are not told apart
     * here (neither holds it yet): the importer refuses that earlier, an identifier naming two competencies being its own refusal.
     *
     * @param string $itemtype One of the TYPE_* constants.
     * @param string[] $wanted The ULID the archive gives each item, keyed by the Moodle identifier of the item.
     * @return string[] The error code adoption_conflict() gives, keyed by the Moodle identifier, for the items in conflict only
     *         and in the order they were asked in. Empty when every item may take its ULID.
     */
    public static function adoption_conflicts(string $itemtype, array $wanted): array {
        // Refused even when there is nothing to ask: a typo in the type must not pass for "no conflict".
        self::validate_type($itemtype);

        $conflicts = [];
        foreach ($wanted as $itemid => $uuid) {
            $conflict = self::adoption_conflict($itemtype, (int) $itemid, (string) $uuid);
            if ($conflict !== null) {
                $conflicts[$itemid] = $conflict;
            }
        }

        return $conflicts;
    }

    /**
     * The one statement of the rule, from what an item holds.
     *
     * Given what was read, so that adopt() decides AND acts on a single reading: it used to read the item twice, once to
     * ask the rule and once to learn whether it held the ULID, and a request that inserted another ULID for the same
     * item between the two made it answer "already held this very one" without having adopted anything or refused. A
     * single read cannot be told two stories; a concurrent insert is then stopped by the unique index, as it always was.
     *
     * @param string|false $held The ULID the item holds, false when it holds none (what get_field() gives).
     * @param string $uuid The ULID the archive knows it by.
     * @return string|null The error code, as adoption_conflict() gives it.
     */
    private static function conflict($held, string $uuid): ?string {
        if ($held !== false) {
            return $held === $uuid ? null : 'errorarchiveidentityheld';
        }

        return self::resolve($uuid) === null ? null : 'errorarchiveidentitytaken';
    }

    /**
     * Give an item the ULID an archive knows it by.
     *
     * Used on reimport, so that a dossier carried from one platform to another keeps its
     * identifiers. Allowed only when it changes nothing already said: an item that holds a
     * different ULID keeps it, and a ULID already given to another item is never reassigned -
     * the rule stated in the class header. Either case refuses the archive.
     *
     * @param string $itemtype One of the TYPE_* constants.
     * @param int $itemid The Moodle identifier.
     * @param string $uuid
     * @return bool True when the item already held this ULID, false when it was just given it.
     */
    public static function adopt(string $itemtype, int $itemid, string $uuid): bool {
        global $DB;

        self::validate_type($itemtype);

        // ONE READING, for the rule and for the answer: see conflict().
        $held = $DB->get_field(self::TABLE, 'uuid', ['itemtype' => $itemtype, 'itemid' => $itemid]);
        $conflict = self::conflict($held, $uuid);
        if ($conflict !== null) {
            throw new \moodle_exception($conflict, 'tool_vae', '', $uuid);
        }
        if ($held !== false) {
            // No conflict and a ULID held: it is this very one.
            return true;
        }

        $DB->insert_record(self::TABLE, (object) [
            'itemtype' => $itemtype,
            'itemid' => $itemid,
            'uuid' => $uuid,
            'timecreated' => time(),
        ]);

        return false;
    }

    /**
     * Mint a ULID.
     *
     * 48 bits of millisecond timestamp then 80 bits of randomness, encoded in Crockford
     * base 32 as 10 + 16 characters. The leading character stays within 0-7 until the year
     * 10889, which is what lets the pivot schema anchor its pattern on [0-7].
     *
     * Sorting is by time, which matters for a dossier read in chronological order.
     *
     * @param int|null $timestampms Only passed by tests; production always uses the clock.
     * @return string
     */
    public static function generate_ulid(?int $timestampms = null): string {
        $time = $timestampms ?? (int) (microtime(true) * 1000);

        if ($time < 0) {
            throw new \coding_exception('A ULID timestamp cannot be negative.');
        }

        $ulid = '';
        for ($i = 0; $i < 10; $i++) {
            $ulid = self::ALPHABET[$time % 32] . $ulid;
            $time = intdiv($time, 32);
        }

        for ($i = 0; $i < 16; $i++) {
            $ulid .= self::ALPHABET[random_int(0, 31)];
        }

        return $ulid;
    }

    /**
     * Whether a string is a ULID the pivot schema would accept.
     *
     * @param string $candidate
     * @return bool
     */
    public static function is_valid_ulid(string $candidate): bool {
        return (bool) preg_match(self::ULID_PATTERN, $candidate);
    }

    /**
     * Reject an unknown item type before it reaches the database.
     *
     * A typo would otherwise mint a second ULID for the same item under a slightly
     * different type, and the duplicate would only surface in an exported dossier.
     *
     * @param string $itemtype
     */
    private static function validate_type(string $itemtype): void {
        $known = [self::TYPE_USER, self::TYPE_COMPETENCY, self::TYPE_STRUCTURE];
        if (!in_array($itemtype, $known, true)) {
            throw new \coding_exception("Unknown identity map item type: $itemtype");
        }
    }
}
