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

use tool_vae\event\situation_created;
use tool_vae\event\situation_deleted;

/**
 * The work situations of the vaeiste: their text, their versions, their tags (lot 1b).
 *
 * THE VAEISTE'S OWN MATERIAL, NEVER A VALIDATION. A situation is what the vaeiste tells of their work, as the Livret 2 asks
 * it; its tags say which elements of the record the vaeiste claims it shows. Nothing here judges, counts up or classes:
 * counts(), the one number given, counts situations per rank and adds nothing (spec 1b § 2, "en comptes").
 *
 * THE TEXT IS A DRAFT WITH VERSIONS, the one of a section of the preparation of the validation dossier: tool_vae_draft of
 * type validation, section `_situation`, slot 1..30, held by draft_manager - draft in progress, version at the click,
 * comparison, restoration as a new version - through the doors draft_manager reserves to this class (section_of_situation(),
 * save_version_of_situation(), restore_of_situation()): its generic doors refuse such a draft, so that no page writes a
 * version of a situation without its snapshot. The row of tool_vae_situation carries the CURRENT title, period, setting and
 * tags (saved by their own button, D-1b-I); each version of the text takes a snapshot of them in tool_vae_sitversion, so
 * that a version shows what it was written with. Restoring a version restores the text alone: the snapshot of the new
 * version is the current state.
 *
 * A VERSION NEVER STANDS WITHOUT ITS SNAPSHOT. save_version() and restore() open a transaction before draft_manager writes
 * the version, and the snapshot is written inside it: the delegated transactions nest, the inner allow_commit() writes only
 * at the outer commit, and a failure of the snapshot takes the version away with it.
 *
 * WHO. The vaeiste alone writes - the text, the details, the tags, the deletion - on their own dossier
 * (draft_manager::can_write(): the writer is the vaeiste AND holds tool/vae:draftown in their own context); neither the
 * manager nor the administrator does. Whoever may read the preparation of the validation dossier reads
 * (draft_manager::can_read_doctype(), D-1b-G): the author, whoever follows them, the direction; never the site administrator.
 * Writing needs the preparation open (validationopen, D-1b-E) and the record offered - not hidden in Moodle - but NO
 * declaration first (D-1b-F).
 *
 * THIS IS A COMPONENT API: every public operation checks permission and its parameters.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class situation_manager {
    /** @var string The table of the situations. */
    public const TABLE = 'tool_vae_situation';

    /** @var int The longest title, in characters (the width of the column, the one of a piece). */
    public const TITLE_MAXLENGTH = 60;

    /** @var int The longest setting, in characters (the width of the column). */
    public const SETTING_MAXLENGTH = 100;

    /** @var int The lowest level of the tree a tag may point at: the record itself (level 1) is never a tag. */
    public const MIN_TAG_LEVEL = 2;

    /** @var int The most versions one situation keeps (D-1b-J): a bound against a script, so that the dossier stays restorable. */
    public const MAX_VERSIONS = 200;

    /** @var string The name of the lock that serialises the opening of the situations of one vaeiste (positioning_rules::lock());
     *  situation_archive takes it too, so that a restored situation never lands on a slot being opened. */
    public const LOCK_KEY = 'situation';

    /**
     * Whether the current user may write the situations of this vaeiste: it is their own dossier, and they hold the right.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_write(int $candidateid): bool {
        return draft_manager::can_write($candidateid);
    }

    /**
     * Open a new situation on a record: its draft, and its row with an empty title.
     *
     * The slot is the first one free from 1 to validation_catalog::MAX_SITUATIONS, so that the number of a deleted situation
     * comes back; it is chosen under the lock of the vaeiste, so that two requests at once cannot take the same one.
     *
     * @param int $candidateid The vaeiste, who is the current user.
     * @param string $fichecode The record, offered to the vaeiste.
     * @return \stdClass The row of tool_vae_situation, with tags (empty).
     * @throws \moodle_exception errordraftrefused, errorvalidationclosed, errorsituationfull, errordraftsection,
     *         errorframeworkhidden
     */
    public function create(int $candidateid, string $fichecode): \stdClass {
        global $DB;

        draft_manager::require_can_write($candidateid);
        if (!draft_manager::validation_open()) {
            throw new \moodle_exception('errorvalidationclosed', 'tool_vae');
        }

        $lock = positioning_rules::lock($candidateid, self::LOCK_KEY);
        try {
            $taken = $DB->get_fieldset_select(
                self::TABLE,
                'slot',
                'candidateid = :candidateid AND fichecode = :fichecode',
                ['candidateid' => $candidateid, 'fichecode' => $fichecode]
            );
            $taken = array_map('intval', $taken);
            $slot = 0;
            for ($candidate = 1; $candidate <= validation_catalog::MAX_SITUATIONS; $candidate++) {
                if (!in_array($candidate, $taken, true)) {
                    $slot = $candidate;
                    break;
                }
            }
            if ($slot === 0) {
                throw new \moodle_exception('errorsituationfull', 'tool_vae');
            }

            // The draft first, through the door draft_manager reserves to this class: it makes every check of the address
            // and of the record (D-1b-F) and refuses before anything is written. No transaction binds the two writes: a
            // draft left without its row - the row's insert failed - is no slot nobody can open, since the slot reads as
            // free and the door gives that very draft back next time.
            $draft = (new draft_manager())->section_of_situation($candidateid, $slot, $fichecode);
            $now = time();
            $row = (object) [
                'uuid' => identity_map::generate_ulid(),
                'candidateid' => $candidateid,
                'fichecode' => $fichecode,
                'slot' => $slot,
                'draftid' => (int) $draft->id,
                'title' => '',
                'periodstart' => null,
                'periodend' => null,
                'setting' => '',
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $row->id = $DB->insert_record(self::TABLE, $row);
        } finally {
            $lock->release();
        }

        situation_created::create([
            'context' => \context_user::instance($candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => $candidateid,
            'other' => ['fichecode' => $fichecode, 'slot' => $slot],
        ])->trigger();

        $row->tags = [];

        return $row;
    }

    /**
     * One situation, for whoever may read the dossier.
     *
     * @param int $situationid
     * @return \stdClass The row, with tags (the ranks, in natural order).
     * @throws \moodle_exception errordraftrefused Also for an id that is no situation: nothing says which ids exist.
     */
    public function get(int $situationid): \stdClass {
        $row = $this->for_read($situationid);
        $this->attach_tags([$row]);

        return $row;
    }

    /**
     * The situation at one slot of a record, for whoever may read the dossier.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param int $slot
     * @return \stdClass|null The row, with tags; null when the slot is free.
     * @throws \moodle_exception errordraftrefused
     */
    public function find(int $candidateid, string $fichecode, int $slot): ?\stdClass {
        global $DB;

        self::require_can_read($candidateid);
        $row = $DB->get_record(self::TABLE, ['candidateid' => $candidateid, 'fichecode' => $fichecode, 'slot' => $slot]);
        if (!$row) {
            return null;
        }
        $this->attach_tags([$row]);

        return $row;
    }

    /**
     * The situation whose text a draft holds, for whoever may read the dossier: how history.php finds its way back.
     *
     * @param int $draftid
     * @return \stdClass|null The row, with tags; null when no situation holds this draft (a draft that is no situation, or
     *         a draft left without its row).
     * @throws \moodle_exception errordraftrefused
     */
    public function find_by_draft(int $draftid): ?\stdClass {
        global $DB;

        $row = $DB->get_record(self::TABLE, ['draftid' => $draftid]);
        if (!$row) {
            return null;
        }
        self::require_can_read((int) $row->candidateid);
        $this->attach_tags([$row]);

        return $row;
    }

    /**
     * The situations of a vaeiste on a record, in the order of their slots.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @return \stdClass[] Slot => the row, with tags (the ranks), pieces (the number of pieces linked and not removed) and
     *         status (where its text stands, as validation_status::place() says it).
     * @throws \moodle_exception errordraftrefused
     */
    public function list(int $candidateid, string $fichecode): array {
        global $DB;

        self::require_can_read($candidateid);
        $rows = $DB->get_records(self::TABLE, ['candidateid' => $candidateid, 'fichecode' => $fichecode], 'slot ASC');
        $this->attach_tags($rows);

        $pieces = $DB->get_records_sql_menu(
            'SELECT l.situationid, COUNT(1)
               FROM {tool_vae_slink} l
               JOIN {tool_vae_situation} s ON s.id = l.situationid
               JOIN {tool_vae_spiece} p ON p.id = l.pieceid
              WHERE s.candidateid = :candidateid AND s.fichecode = :fichecode AND l.timeremoved IS NULL
                    AND p.timeremoved IS NULL
           GROUP BY l.situationid',
            ['candidateid' => $candidateid, 'fichecode' => $fichecode]
        );
        $drafts = $DB->get_records_sql(
            'SELECT d.id, d.currentversion, d.working, v.timecreated AS versiontime
               FROM {tool_vae_draft} d
          LEFT JOIN {tool_vae_version} v ON v.draftid = d.id AND v.number = d.currentversion
              WHERE d.candidateid = :candidateid AND d.doctype = :doctype AND d.fichecode = :fichecode
                    AND d.section = :section',
            [
                'candidateid' => $candidateid,
                'doctype' => draft_manager::DOCTYPE_VALIDATION,
                'fichecode' => $fichecode,
                'section' => validation_catalog::SITUATION_SECTION,
            ]
        );

        $out = [];
        foreach ($rows as $row) {
            $draft = $drafts[(int) $row->draftid] ?? null;
            $row->pieces = (int) ($pieces[(int) $row->id] ?? 0);
            $row->status = validation_status::place(
                $draft,
                $draft === null || $draft->versiontime === null ? null : (int) $draft->versiontime
            );
            $out[(int) $row->slot] = $row;
        }

        return $out;
    }

    /**
     * Save the title, the period, the setting and the tags of a situation: the current state, no version of the text.
     *
     * @param int $situationid
     * @param string $title Required, TITLE_MAXLENGTH characters at most, plain text on one line.
     * @param int|null $periodstart The first day of the period, or null.
     * @param int|null $periodend The last day of the period, or null; never before the first.
     * @param string $setting SETTING_MAXLENGTH characters at most, plain text on one line; empty allowed.
     * @param string[] $ranks The ranks of the tags: nodes of level MIN_TAG_LEVEL or more of the record, positionable; none
     *        allowed; the same rank twice is one tag.
     * @return \stdClass The row, updated, with tags.
     * @throws \moodle_exception errordraftrefused, errorvalidationclosed, errorsituationtitle, errorsituationperiod,
     *         errorsituationsetting, errorsituationtag
     */
    public function save_details(
        int $situationid,
        string $title,
        ?int $periodstart,
        ?int $periodend,
        string $setting,
        array $ranks
    ): \stdClass {
        global $DB;

        $row = $this->for_write($situationid);
        $title = self::clean_title($title);
        if ($periodstart !== null && $periodend !== null && $periodend < $periodstart) {
            throw new \moodle_exception('errorsituationperiod', 'tool_vae');
        }
        $setting = self::clean_setting($setting);
        $ranks = self::clean_ranks((string) $row->fichecode, $ranks);

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->update_record(self::TABLE, (object) [
                'id' => (int) $row->id,
                'title' => $title,
                'periodstart' => $periodstart,
                'periodend' => $periodend,
                'setting' => $setting,
                'timemodified' => $now,
            ]);
            $DB->delete_records('tool_vae_sitetag', ['situationid' => (int) $row->id]);
            foreach ($ranks as $rank) {
                $DB->insert_record('tool_vae_sitetag', (object) [
                    'situationid' => (int) $row->id,
                    'candidateid' => (int) $row->candidateid,
                    'fichecode' => (string) $row->fichecode,
                    'ficherang' => $rank,
                    'timecreated' => $now,
                ]);
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        return $this->get($situationid);
    }

    /**
     * Save a version of the text, with the snapshot of the current title, period, setting and tags.
     *
     * @param int $situationid
     * @param string $text
     * @param int|null $knownworkingat What the page knew of the draft in progress (draft_manager::save_version()); null for
     *        a caller that has no page.
     * @return \stdClass|null The version, with snapshot (the row of tool_vae_sitversion); null when the text is the one of
     *         the latest version already, and nothing is written.
     * @throws \moodle_exception errordraftrefused, errorvalidationclosed, errorsituationversions, errordraftconflict
     */
    public function save_version(int $situationid, string $text, ?int $knownworkingat = null): ?\stdClass {
        $row = $this->for_write($situationid);

        return $this->write_version(
            $row,
            fn(draft_manager $drafts) => $drafts->save_version_of_situation((int) $row->draftid, $text, $knownworkingat)
        );
    }

    /**
     * Restore an earlier version of the text: a NEW version with that text, and the snapshot of the CURRENT details
     * (D-1b-I: the details are not restored).
     *
     * @param int $situationid
     * @param int $number The version to restore.
     * @param int|null $knownworkingat As for save_version().
     * @return \stdClass|null The new version, with snapshot; null when the latest version already has that text.
     * @throws \moodle_exception errordraftrefused, errorvalidationclosed, errorsituationversions, errordraftnoversion,
     *         errordraftconflict
     */
    public function restore(int $situationid, int $number, ?int $knownworkingat = null): ?\stdClass {
        $row = $this->for_write($situationid);

        return $this->write_version(
            $row,
            fn(draft_manager $drafts) => $drafts->restore_of_situation((int) $row->draftid, $number, $knownworkingat)
        );
    }

    /**
     * The versions of the text, the latest first, each with its snapshot.
     *
     * @param int $situationid
     * @return \stdClass[] Number => the row of tool_vae_version, with snapshot (the row of tool_vae_sitversion, or null for
     *         a version that has none).
     * @throws \moodle_exception errordraftrefused
     */
    public function versions(int $situationid): array {
        global $DB;

        $row = $this->for_read($situationid);
        $versions = (new draft_manager())->versions((int) $row->draftid);
        $snapshots = [];
        foreach ($DB->get_records('tool_vae_sitversion', ['situationid' => (int) $row->id]) as $snapshot) {
            $snapshots[(int) $snapshot->number] = $snapshot;
        }
        foreach ($versions as $number => $version) {
            $version->snapshot = $snapshots[(int) $number] ?? null;
        }

        return $versions;
    }

    /**
     * Delete a situation: its tags, its links to pieces, its snapshots, its versions, its draft, then the row (D-1b-K).
     *
     * The pieces stay: one piece may serve several situations, and a piece is the vaeiste's to remove. The annotations on
     * the situation stay too, and are read as the annotations of a situation that was deleted.
     *
     * @param int $situationid
     * @throws \moodle_exception errordraftrefused, errorvalidationclosed
     */
    public function delete(int $situationid): void {
        global $DB;

        $row = $this->for_write($situationid);

        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->delete_records('tool_vae_sitetag', ['situationid' => (int) $row->id]);
            $DB->delete_records('tool_vae_slink', ['situationid' => (int) $row->id]);
            $DB->delete_records('tool_vae_sitversion', ['situationid' => (int) $row->id]);
            $DB->delete_records('tool_vae_version', ['draftid' => (int) $row->draftid]);
            $DB->delete_records('tool_vae_draft', ['id' => (int) $row->draftid]);
            $DB->delete_records(self::TABLE, ['id' => (int) $row->id]);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        situation_deleted::create([
            'context' => \context_user::instance((int) $row->candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => (int) $row->candidateid,
            'other' => ['fichecode' => (string) $row->fichecode, 'slot' => (int) $row->slot],
        ])->trigger();
    }

    /**
     * How many situations carry each rank of a record: a count per rank, in one query, and NOTHING added up - a rank with no
     * situation is absent, a parent gets nothing from its children.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @return int[] Rank => the number of situations tagged with it.
     * @throws \moodle_exception errordraftrefused
     */
    public function counts(int $candidateid, string $fichecode): array {
        global $DB;

        self::require_can_read($candidateid);
        $rows = $DB->get_records_sql(
            'SELECT ficherang, COUNT(1) AS situations
               FROM {tool_vae_sitetag}
              WHERE candidateid = :candidateid AND fichecode = :fichecode
           GROUP BY ficherang',
            ['candidateid' => $candidateid, 'fichecode' => $fichecode]
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->ficherang] = (int) $row->situations;
        }
        uksort($out, 'strnatcmp');

        return $out;
    }

    /**
     * The situations that carry one of the given ranks, in the order of their slots: the reading aid of the part "lier".
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param string[] $ranks
     * @return \stdClass[] Slot => the row, with tags.
     * @throws \moodle_exception errordraftrefused
     */
    public function tagged(int $candidateid, string $fichecode, array $ranks): array {
        global $DB;

        self::require_can_read($candidateid);
        $ranks = array_values(array_unique(array_map('strval', $ranks)));
        if (!$ranks) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ranks, SQL_PARAMS_NAMED, 'rank');
        $rows = $DB->get_records_sql(
            "SELECT s.*
               FROM {tool_vae_situation} s
              WHERE s.candidateid = :candidateid AND s.fichecode = :fichecode
                    AND EXISTS (SELECT 1 FROM {tool_vae_sitetag} t WHERE t.situationid = s.id AND t.ficherang $in)
           ORDER BY s.slot ASC",
            $params + ['candidateid' => $candidateid, 'fichecode' => $fichecode]
        );
        $this->attach_tags($rows);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->slot] = $row;
        }

        return $out;
    }

    /**
     * Write a version through draft_manager and its snapshot in one transaction.
     *
     * @param \stdClass $row The situation, after the write check.
     * @param callable $write draft_manager => the version it wrote, or null when nothing was new.
     * @return \stdClass|null The version with snapshot, or null.
     * @throws \moodle_exception errorsituationversions
     */
    private function write_version(\stdClass $row, callable $write): ?\stdClass {
        global $DB;

        if ($DB->count_records('tool_vae_version', ['draftid' => (int) $row->draftid]) >= self::MAX_VERSIONS) {
            throw new \moodle_exception('errorsituationversions', 'tool_vae');
        }
        $this->attach_tags([$row]);

        $transaction = $DB->start_delegated_transaction();
        try {
            $version = $write(new draft_manager());
            if ($version === null) {
                $transaction->allow_commit();
                return null;
            }
            $snapshot = (object) [
                'situationid' => (int) $row->id,
                'number' => (int) $version->number,
                'title' => (string) $row->title,
                'periodstart' => $row->periodstart === null ? null : (int) $row->periodstart,
                'periodend' => $row->periodend === null ? null : (int) $row->periodend,
                'setting' => (string) $row->setting,
                'tags' => implode(',', $row->tags),
                'timecreated' => time(),
            ];
            $snapshot->id = $DB->insert_record('tool_vae_sitversion', $snapshot);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The version goes away with its snapshot: the rollback() call rethrows.
            $transaction->rollback($e);
            throw $e;
        }
        $version->snapshot = $snapshot;

        return $version;
    }

    /**
     * Add to each row its tags: the ranks, in natural order, read in one query.
     *
     * @param \stdClass[] $rows Rows of tool_vae_situation, changed in place.
     */
    private function attach_tags(array $rows): void {
        global $DB;

        $byid = [];
        foreach ($rows as $row) {
            $row->tags = [];
            $byid[(int) $row->id] = $row;
        }
        if (!$byid) {
            return;
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($byid), SQL_PARAMS_NAMED, 'sit');
        $tags = $DB->get_records_select('tool_vae_sitetag', "situationid $in", $params, 'id ASC', 'id, situationid, ficherang');
        foreach ($tags as $tag) {
            $byid[(int) $tag->situationid]->tags[] = (string) $tag->ficherang;
        }
        foreach ($byid as $row) {
            usort($row->tags, 'strnatcmp');
        }
    }

    /**
     * The situation, after the read check.
     *
     * @param int $situationid
     * @return \stdClass
     * @throws \moodle_exception errordraftrefused
     */
    private function for_read(int $situationid): \stdClass {
        global $DB;

        $row = $DB->get_record(self::TABLE, ['id' => $situationid]);
        if (!$row) {
            // The same refusal as a situation one may not read: nothing says whether the id exists.
            throw new \moodle_exception('errordraftrefused', 'tool_vae');
        }
        self::require_can_read((int) $row->candidateid);

        return $row;
    }

    /**
     * The situation, after the write check: the author, the preparation open (closed is closed for every door, as
     * draft_manager has it), and the record still offered (D-1b-F: hidden since, the situation is read, never written - the
     * same refusal as the page gives, T15 point F).
     *
     * @param int $situationid
     * @return \stdClass
     * @throws \moodle_exception errordraftrefused, errorvalidationclosed, errordraftsection, errorframeworkhidden
     */
    private function for_write(int $situationid): \stdClass {
        global $DB;

        $row = $DB->get_record(self::TABLE, ['id' => $situationid]);
        if (!$row) {
            throw new \moodle_exception('errordraftrefused', 'tool_vae');
        }
        draft_manager::require_can_write((int) $row->candidateid);
        if (!draft_manager::validation_open()) {
            throw new \moodle_exception('errorvalidationclosed', 'tool_vae');
        }
        draft_manager::require_offered((string) $row->fichecode);

        return $row;
    }

    /**
     * Refuse unless the current user may read the preparation of this vaeiste's validation dossier (D-1b-G).
     *
     * @param int $candidateid
     * @throws \moodle_exception errordraftrefused
     */
    private static function require_can_read(int $candidateid): void {
        if (!draft_manager::can_read_doctype($candidateid, draft_manager::DOCTYPE_VALIDATION)) {
            throw new \moodle_exception('errordraftrefused', 'tool_vae');
        }
    }

    /**
     * The title as it is kept: plain text on one line, required, within its width (the rule of the title of a piece).
     *
     * @param string $title
     * @return string
     * @throws \moodle_exception errorsituationtitle
     */
    private static function clean_title(string $title): string {
        try {
            $title = piece_manager::clean_title($title);
        } catch (\moodle_exception $e) {
            throw new \moodle_exception('errorsituationtitle', 'tool_vae');
        }
        if ($title === '') {
            throw new \moodle_exception('errorsituationtitle', 'tool_vae');
        }

        return $title;
    }

    /**
     * The setting as it is kept: plain text on one line, within its width; empty allowed.
     *
     * @param string $setting
     * @return string
     * @throws \moodle_exception errorsituationsetting
     */
    private static function clean_setting(string $setting): string {
        $setting = trim(preg_replace('/\s+/u', ' ', clean_param($setting, PARAM_TEXT)) ?? '');
        if (\core_text::strlen($setting) > self::SETTING_MAXLENGTH) {
            throw new \moodle_exception('errorsituationsetting', 'tool_vae');
        }

        return $setting;
    }

    /**
     * The ranks of the tags, checked against the tree of the record: each one a positionable node of level MIN_TAG_LEVEL or
     * more. The record itself (level 1, the empty rank), an unknown rank, a rank that is not of the shape, are refused.
     *
     * @param string $fichecode
     * @param array $ranks What the request carries.
     * @return string[] The ranks, each once, in natural order.
     * @throws \moodle_exception errorsituationtag
     */
    private static function clean_ranks(string $fichecode, array $ranks): array {
        $out = [];
        $tree = null;
        foreach ($ranks as $rank) {
            if (!is_string($rank) || !preg_match(importer::RANK_PATTERN, $rank)) {
                throw new \moodle_exception('errorsituationtag', 'tool_vae');
            }
            $tree = $tree ?? selfpos_tree::for_record($fichecode);
            $node = $tree === null ? null : selfpos_tree::node($tree, $rank);
            if ($node === null || !$node->positionable || (int) $node->level < self::MIN_TAG_LEVEL) {
                throw new \moodle_exception('errorsituationtag', 'tool_vae');
            }
            $out[$rank] = $rank;
        }
        $out = array_values($out);
        usort($out, 'strnatcmp');

        return $out;
    }
}
