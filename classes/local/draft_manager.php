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

use tool_vae\event\draft_version_restored;
use tool_vae\event\draft_version_saved;

/**
 * The sections of a document a candidate writes, their drafts and their versions (T-81, lot 1).
 *
 * TWO LAYERS. The draft in progress (`working`) is rewritten as often as the candidate types and
 * is not history. A version is written when the candidate asks, and is never changed again; a
 * restoration is a new version, so the history is never rewound (D-9).
 *
 * WHO. The candidate alone writes their own sections (decision of 15 October 2026): the capability
 * is held in their own user context AND the writer is the candidate - an administrator who holds
 * every capability does not write a candidate's document. Reading is the candidate, the
 * site administrator, and whoever holds tool/vae:draftview in their context AND may read the
 * dossier - viewany, or following the candidate, the rule of access::can_view(). The managers who
 * follow the candidate see nothing here before the deposit (T-21).
 *
 * THIS IS A COMPONENT API: every public operation checks permission and its parameters, so that a
 * task, a web service or another plugin can call it without re-deriving the rules.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class draft_manager {
    /** @var string The dossier de faisabilite (T-10). */
    public const DOCTYPE_FAISABILITE = 'faisabilite';

    /** @var string The preparation of the validation dossier (T-11, D-15). */
    public const DOCTYPE_VALIDATION = 'validation';

    /** @var string The reflective journal (T-14): the candidate's alone, entry by entry. */
    public const DOCTYPE_REFLEXION = 'reflexion';

    /** @var string[] The closed enumeration of document kinds. */
    public const DOCTYPES = [self::DOCTYPE_FAISABILITE, self::DOCTYPE_VALIDATION, self::DOCTYPE_REFLEXION];

    /** @var string The one section code of the journal: an entry is told apart by its slot. */
    public const REFLEXION_SECTION = 'entree';

    /** @var int Most entries of a journal. */
    public const REFLEXION_MAX = 200;

    /** @var int Longest section or version text, in characters. */
    public const CONTENT_MAXLENGTH = 100000;

    /** @var int Longest note written with a version. */
    public const NOTE_MAXLENGTH = 255;

    /** @var int Highest slot of a repeated section. */
    public const SLOT_MAX = 99;

    /** @var int Seconds a second write to the same section waits for the first. */
    private const LOCK_TIMEOUT = 10;

    /**
     * Whether the administrator has opened the preparation of the validation dossier to candidates.
     *
     * Closed by default (T-11, D-15): its titles and helps are provisional until a companion has read them, and the
     * reading of what is deposited by the manager - the third control of the regime 2 - comes with the deposit.
     *
     * @return bool
     */
    public static function validation_open(): bool {
        return (bool) get_config('tool_vae', 'validationopen');
    }

    /**
     * Whether the administrator has opened the reflective journal to candidates.
     *
     * Closed by default (T-14): it is opened once the preparation of the validation dossier has been read by a companion,
     * and once the retention of such a text is written (T-84).
     *
     * @return bool
     */
    public static function reflection_open(): bool {
        return (bool) get_config('tool_vae', 'reflectionopen');
    }

    /**
     * Whether the current user may write the sections of this candidate's document.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_write(int $candidateid): bool {
        global $USER;

        if (!isloggedin() || isguestuser() || (int) $USER->id !== $candidateid) {
            return false;
        }

        return has_capability('tool/vae:draftown', \context_user::instance($candidateid));
    }

    /**
     * Whether the current user may read the sections of this candidate's document.
     *
     * WHY draftview ALONE IS NOT ENOUGH: the trap access::follows() guards against, met again. The capability is read in
     * the candidate's user context, and a role carrying it assigned at system level is inherited by every user context on
     * the site: history.php?draftid=1..N would then give the versions of every candidate. Reading the versions therefore
     * first requires reading the dossier, by the very rule of can_view() - viewany, or following the candidate - as
     * attesting does (access::can_attest()). The direction, which holds viewany at the site level, reads everything; a
     * manager who follows the candidate reads with draftview in their context; draftview held anywhere else opens nothing.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_read(int $candidateid): bool {
        global $USER;

        if (!isloggedin() || isguestuser()) {
            return false;
        }
        if ((int) $USER->id === $candidateid) {
            return has_capability('tool/vae:draftown', \context_user::instance($candidateid));
        }

        // An id that is no account has no context: it is refused as a dossier one may not read is, so that the answer never
        // tells which ids are accounts (access::can_view() says the same).
        $context = \context_user::instance($candidateid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        return is_siteadmin() || (access::can_view($candidateid) && has_capability('tool/vae:draftview', $context));
    }

    /**
     * Whether the current user may read a section of a given kind of document.
     *
     * THE PREPARATION OF THE VALIDATION DOSSIER IS THE CANDIDATE'S ALONE (MC-16, D-15). Its text is the most intimate of
     * the dossier - analyses of practice - and the site administrator, who reads the drafts of the dossier de
     * faisabilite, does not read these: the administrator and the managers read the VERSIONS THE CANDIDATE
     * DEPOSITED, never the drafts or the history.
     *
     * @param int $candidateid
     * @param string $doctype
     * @return bool
     */
    public static function can_read_doctype(int $candidateid, string $doctype): bool {
        global $USER;

        if ($doctype !== self::DOCTYPE_VALIDATION && $doctype !== self::DOCTYPE_REFLEXION) {
            return self::can_read($candidateid);
        }

        // The journal is the author's alone, even more than the preparation: it is not deposited, nobody else reads it.
        return isloggedin() && !isguestuser() && (int) $USER->id === $candidateid
            && has_capability('tool/vae:draftown', \context_user::instance($candidateid));
    }

    /**
     * Refuse unless the current user may read this section.
     *
     * @param \stdClass $draft A row of tool_vae_draft.
     * @throws \moodle_exception errordraftrefused
     */
    public static function require_can_read_draft(\stdClass $draft): void {
        if (!self::can_read_doctype((int) $draft->candidateid, (string) $draft->doctype)) {
            throw new \moodle_exception('errordraftrefused', 'tool_vae');
        }
    }

    /**
     * The name of a section, as the candidate reads it, whatever the document.
     *
     * @param \stdClass $draft A row of tool_vae_draft.
     * @return string
     */
    public static function label(\stdClass $draft): string {
        if ($draft->doctype === self::DOCTYPE_VALIDATION) {
            return validation_catalog::label((string) $draft->fichecode, (string) $draft->section);
        }
        if ($draft->doctype === self::DOCTYPE_REFLEXION) {
            return get_string('reflexionentry', 'tool_vae', (int) $draft->slot);
        }

        return faisabilite_catalog::label((string) $draft->section, (int) $draft->slot);
    }

    /**
     * Whether the current user may read the sections of separate fields (identity, status).
     *
     * The candidate, for their own; anyone else needs tool/vae:identityview in the candidate's
     * context, on top of the right to read the document. The site administrator holds it as a
     * matter of course.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_read_fields(int $candidateid): bool {
        global $USER;

        if (!self::can_read($candidateid)) {
            return false;
        }

        return (int) $USER->id === $candidateid
            || has_capability('tool/vae:identityview', \context_user::instance($candidateid));
    }

    /**
     * Whether a section row holds separate fields.
     *
     * @param \stdClass $draft Row of tool_vae_draft.
     * @return bool
     */
    private static function has_fields(\stdClass $draft): bool {
        return $draft->doctype === self::DOCTYPE_FAISABILITE
            && faisabilite_catalog::kind((string) $draft->section) === faisabilite_catalog::KIND_FIELDS;
    }

    /**
     * Refuse unless the current user may write.
     *
     * @param int $candidateid
     * @throws \moodle_exception errordraftrefused
     */
    public static function require_can_write(int $candidateid): void {
        if (!self::can_write($candidateid)) {
            throw new \moodle_exception('errordraftrefused', 'tool_vae');
        }
    }

    /**
     * Refuse unless the current user may read.
     *
     * @param int $candidateid
     * @throws \moodle_exception errordraftrefused
     */
    public static function require_can_read(int $candidateid): void {
        if (!self::can_read($candidateid)) {
            throw new \moodle_exception('errordraftrefused', 'tool_vae');
        }
    }

    /**
     * The section of a document, created empty when it does not exist yet.
     *
     * @param int $candidateid
     * @param string $doctype One of DOCTYPES.
     * @param string $section Letters, digits and underscore, 32 at most.
     * @param int $slot 0 for a single section, 1..SLOT_MAX for a repeated one.
     * @param string|null $fichecode The certification record the section depends on: required for the preparation of
     *        the validation dossier, empty for the dossier de faisabilite (it depends on none).
     * @return \stdClass The row of tool_vae_draft.
     */
    public function section(
        int $candidateid,
        string $doctype,
        string $section,
        int $slot = 0,
        ?string $fichecode = null
    ): \stdClass {
        global $DB, $USER;

        $fichecode = (string) $fichecode;
        self::require_can_write($candidateid);
        self::check_address($doctype, $section, $slot);
        if (
            $fichecode !== ''
                && (!preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $fichecode) || $doctype !== self::DOCTYPE_VALIDATION)
        ) {
            throw new \moodle_exception('errordraftsection', 'tool_vae');
        }
        // The journal: closed until opened, one section code, one entry per slot, and no record (T-14).
        if ($doctype === self::DOCTYPE_REFLEXION) {
            if (!self::reflection_open()) {
                throw new \moodle_exception('errorreflexionclosed', 'tool_vae');
            }
            if ($section !== self::REFLEXION_SECTION || $slot < 1 || $slot > self::REFLEXION_MAX) {
                throw new \moodle_exception('errordraftsection', 'tool_vae');
            }
        }
        // The preparation of the validation dossier is closed until the administrator opens it, is written on one record,
        // and a place the catalogue of that record does not hold cannot be written (T-11).
        if ($doctype === self::DOCTYPE_VALIDATION && !self::validation_open()) {
            throw new \moodle_exception('errorvalidationclosed', 'tool_vae');
        }
        // And only on a record the candidate declared on, written exactly as this site holds it.
        if (
            $doctype === self::DOCTYPE_VALIDATION
                && !$DB->record_exists('tool_vae_evidence', ['candidateid' => $candidateid, 'fichecode' => $fichecode])
        ) {
            throw new \moodle_exception('errordraftsection', 'tool_vae');
        }
        if (
            $doctype === self::DOCTYPE_VALIDATION
                && ($fichecode === '' || !validation_catalog::valid_address($fichecode, $section, $slot))
        ) {
            throw new \moodle_exception('errordraftsection', 'tool_vae');
        }
        // The catalogue of the document is the only list of places: a code it does not hold, or a
        // slot beyond its limit, cannot be written, whatever the request carries (T-10).
        // A section read from the account holds nothing here: no row is ever made for it.
        if (
            $doctype === self::DOCTYPE_FAISABILITE
                && (!faisabilite_catalog::valid_address($section, $slot)
                    || faisabilite_catalog::kind($section) === faisabilite_catalog::KIND_ACCOUNT)
        ) {
            throw new \moodle_exception('errordraftsection', 'tool_vae');
        }

        $row = $DB->get_record('tool_vae_draft', [
            'candidateid' => $candidateid,
            'doctype' => $doctype,
            'fichecode' => $fichecode,
            'section' => $section,
            'slot' => $slot,
        ]);
        if ($row) {
            return $row;
        }

        $now = time();
        $row = (object) [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => $candidateid,
            'doctype' => $doctype,
            'section' => $section,
            'slot' => $slot,
            'fichecode' => $fichecode,
            'working' => null,
            'workingat' => null,
            'currentversion' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int) $USER->id,
        ];
        try {
            $row->id = $DB->insert_record('tool_vae_draft', $row);
        } catch (\dml_exception $e) {
            // Two requests creating the same section at once: the unique index let one through.
            $existing = $DB->get_record('tool_vae_draft', [
                'candidateid' => $candidateid,
                'doctype' => $doctype,
                'fichecode' => $fichecode,
                'section' => $section,
                'slot' => $slot,
            ]);
            if (!$existing) {
                throw $e;
            }
            return $existing;
        }

        return $row;
    }

    /**
     * Bring one text section back from an archive, as its version 1 (T-10, lot 4b).
     *
     * Nothing is overwritten: a section that already holds a saved version is left as it is and
     * null comes back - the dossier on this site is the living one (T-44). A section with a draft
     * in progress and no version receives the version and keeps its draft.
     *
     * The caller has already made the check of an import - dossier_importer::require_can_import()
     * - and has checked the address against the catalogue; this method does not repeat the
     * check of the candidate writing, which an administrator importing for them cannot pass.
     * The version carries the date the archive gives, and the candidate as its author, so that the history reads as it was
     * written: the administrator who imports is not named as the author of the candidate's text.
     *
     * @param int $candidateid
     * @param string $section A text section of the catalogue.
     * @param int $slot
     * @param string $content
     * @param int $savedat The time the archive says the version was saved.
     * @param string $note The note of the version.
     * @param string $doctype The document: the dossier de faisabilité, or the preparation of the validation dossier.
     * @param string $fichecode The record, for the preparation of the validation dossier; empty otherwise.
     * @param int|null $createdat For an entry of the journal: when the archive says it was first opened.
     * @return \stdClass|null The version written, or null when the section already had one.
     */
    public function restore_from_archive(
        int $candidateid,
        string $section,
        int $slot,
        string $content,
        int $savedat,
        string $note,
        string $doctype = self::DOCTYPE_FAISABILITE,
        string $fichecode = '',
        ?int $createdat = null
    ): ?\stdClass {
        global $DB, $USER;

        if ($doctype === self::DOCTYPE_REFLEXION) {
            // The journal is the author's: only its shape and its number are checked, and it is brought back even when it
            // is closed here (T-14, lot 2).
            if ($section !== self::REFLEXION_SECTION || $slot < 1 || $slot > self::REFLEXION_MAX || $fichecode !== '') {
                throw new \moodle_exception('errordraftsection', 'tool_vae');
            }
        } else if ($doctype === self::DOCTYPE_VALIDATION) {
            // A section the record no longer holds - a bloc withdrawn from it - is brought back all the same: what a
            // candidate wrote is theirs. Only its shape, its slot and the record are checked (T-11, lot 2).
            if (
                $slot !== 0 || validation_catalog::parse($section) === null
                    || validation_catalog::structure($fichecode) === null
            ) {
                throw new \moodle_exception('errordraftsection', 'tool_vae');
            }
        } else if (
            $doctype !== self::DOCTYPE_FAISABILITE || $fichecode !== ''
            || !faisabilite_catalog::valid_address($section, $slot)
            || faisabilite_catalog::kind($section) !== faisabilite_catalog::KIND_TEXT
        ) {
            throw new \moodle_exception('errordraftsection', 'tool_vae');
        }
        $content = self::normalise($content);
        self::check_length($content);
        $note = self::clean_note($note);

        $key = [
            'candidateid' => $candidateid,
            'doctype' => $doctype,
            'fichecode' => $fichecode,
            'section' => $section,
            'slot' => $slot,
        ];
        $now = time();
        $row = $DB->get_record('tool_vae_draft', $key);
        if (!$row) {
            $row = (object) ($key + [
                'uuid' => identity_map::generate_ulid(),
                'working' => null,
                'workingat' => null,
                'currentversion' => 0,
                'timecreated' => $createdat ?? $now,
                'timemodified' => $now,
                'usermodified' => (int) $USER->id,
            ]);
            $row->id = $DB->insert_record('tool_vae_draft', $row);
        }
        if ((int) $row->currentversion > 0) {
            return null;
        }

        $version = (object) [
            'uuid' => identity_map::generate_ulid(),
            'draftid' => (int) $row->id,
            'number' => 1,
            'content' => $content,
            'contenthash' => hash('sha256', $content),
            // The candidate's own words, as they saved them on the platform the archive comes from: the importer is not
            // their author (the same rule as usercreated on a piece, in dossier_importer::write()). The importer is kept
            // on the row of the section, in usermodified, as the trace of the import.
            'authorid' => $candidateid,
            'note' => $note,
            'restoredfrom' => null,
            'timecreated' => $savedat,
        ];
        $version->id = $DB->insert_record('tool_vae_version', $version);
        $DB->update_record('tool_vae_draft', (object) [
            'id' => (int) $row->id,
            'currentversion' => 1,
            'timemodified' => $now,
            'usermodified' => (int) $USER->id,
        ]);

        return $version;
    }

    /**
     * The entries of the journal, in order of slot.
     *
     * @param int $candidateid
     * @return \stdClass[] Rows of tool_vae_draft, with the number of versions kept, keyed by slot.
     */
    public static function reflection_entries(int $candidateid): array {
        global $DB;

        self::require_can_write($candidateid);

        $rows = $DB->get_records('tool_vae_draft', [
            'candidateid' => $candidateid,
            'doctype' => self::DOCTYPE_REFLEXION,
        ], 'slot ASC');
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->slot] = $row;
        }

        return $out;
    }

    /**
     * The slot a new entry of the journal takes: the first one free, so that a deleted entry's number comes back.
     *
     * @param int $candidateid
     * @return int
     * @throws \moodle_exception errorreflexionfull
     */
    public static function next_reflection_slot(int $candidateid): int {
        $taken = self::reflection_entries($candidateid);
        for ($slot = 1; $slot <= self::REFLEXION_MAX; $slot++) {
            if (!isset($taken[$slot])) {
                return $slot;
            }
        }

        throw new \moodle_exception('errorreflexionfull', 'tool_vae', '', self::REFLEXION_MAX);
    }

    /**
     * Delete one entry of the journal, with all its versions: the author's right over their own words (T-14).
     *
     * The versions of a deposited document are never changed; the journal is never deposited, so nothing here has been
     * shown to anyone. Only the candidate, only the journal.
     *
     * @param int $draftid
     * @throws \moodle_exception errordraftrefused
     */
    public function delete_entry(int $draftid): void {
        global $DB;

        $draft = $DB->get_record('tool_vae_draft', ['id' => $draftid]);
        if (
            !$draft || $draft->doctype !== self::DOCTYPE_REFLEXION
                || !self::can_write((int) $draft->candidateid)
        ) {
            throw new \moodle_exception('errordraftrefused', 'tool_vae');
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('tool_vae_version', ['draftid' => $draftid]);
        $DB->delete_records('tool_vae_draft', ['id' => $draftid]);
        $transaction->allow_commit();
    }

    /**
     * Save the draft in progress. This is not a version.
     *
     * A text that is the one of the latest version is NO draft in progress, and none is kept: the section comes back with
     * working and workingat empty. It is what an autosave carries when the writer has typed their way back to the version,
     * or when it lands just after the button that saved that very text as a version - kept, it would have the list say
     * "more recent draft in progress" about a text that is the version itself. The lock of the section orders this
     * with append(), so the answer does not depend on which of the two requests arrived first.
     *
     * @param int $draftid
     * @param string $text
     * @param int|null $knownworkingat The `workingat` the page was loaded with, 0 when it was loaded with no draft in
     *        progress. When another tab has saved since, nothing is overwritten and errordraftconflict is raised. Null
     *        only for a caller that has no page - a task, a script - and then nothing is compared; a page uses autosave().
     * @return \stdClass The section, updated.
     */
    public function save_working(int $draftid, string $text, ?int $knownworkingat = null): \stdClass {
        global $DB, $USER;

        $draft = $this->draft_for_write($draftid);
        // Separate fields are saved whole, as a version: a half-typed form is not a draft to keep.
        if (self::has_fields($draft)) {
            throw new \moodle_exception('errordraftfields', 'tool_vae');
        }
        $text = self::normalise($text);
        self::check_length($text);

        $lock = $this->lock($draftid);
        try {
            $draft = $DB->get_record('tool_vae_draft', ['id' => $draftid], '*', MUST_EXIST);
            self::check_not_stale($draft, $knownworkingat);

            $now = time();
            $isversion = (int) $draft->currentversion > 0
                && $DB->get_field('tool_vae_version', 'contenthash', [
                    'draftid' => $draftid,
                    'number' => (int) $draft->currentversion,
                ]) === hash('sha256', $text);
            if (!$isversion || $draft->working !== null || $draft->workingat !== null) {
                $DB->update_record('tool_vae_draft', (object) [
                    'id' => $draftid,
                    'working' => $isversion ? null : $text,
                    'workingat' => $isversion ? null : $now,
                    'timemodified' => $now,
                    'usermodified' => (int) $USER->id,
                ]);
            }
        } finally {
            $lock->release();
        }

        return $DB->get_record('tool_vae_draft', ['id' => $draftid], '*', MUST_EXIST);
    }

    /**
     * Save the draft in progress for a page that saves by itself: save_working(), with the check of the other tabs always made.
     *
     * WHY A DOOR OF ITS OWN. save_working() takes null to mean "no page, nothing to compare", and a page opened with no
     * draft in progress knows `workingat` 0 - which autosave.php turned into null. The state the writer is in most often (after
     * every version, and for every section never drafted) was thus the one with no check at all: a second tab opened in that
     * state overwrote the draft of the first without a word, and it was the first that was told, afterwards, that the text had
     * been changed elsewhere. Here the time the page knew is not optional, and 0 is a time like any other: a draft written
     * since is more recent, and is not overwritten.
     *
     * @param int $draftid
     * @param string $text
     * @param int $knownworkingat The `workingat` the page was loaded with, or last received from here; 0 for none.
     * @return \stdClass As save_working(): the section, updated.
     * @throws \moodle_exception errordraftconflict
     */
    public function autosave(int $draftid, string $text, int $knownworkingat): \stdClass {
        return $this->save_working($draftid, $text, max(0, $knownworkingat));
    }

    /**
     * Save a version: a snapshot, never changed afterwards.
     *
     * @param int $draftid
     * @param string $content
     * @param string|null $note An optional sentence, 255 characters at most.
     * @param int|null $knownworkingat The `workingat` the page was loaded with, 0 when it knew no draft in progress. A
     *        version empties the draft in progress, so a draft more recent than the page knew is not wiped: errordraftconflict,
     *        unless the text saved is that very draft. Null for a caller that has no page.
     * @return \stdClass|null The new version, or null when the text is the one of the latest
     *         version already: nothing is created and the caller says so.
     * @throws \moodle_exception errordraftconflict
     */
    public function save_version(int $draftid, string $content, ?string $note = null, ?int $knownworkingat = null): ?\stdClass {
        $draft = $this->draft_for_write($draftid);
        $content = self::normalise($content);
        self::check_length($content);
        // Separate fields: the content is a JSON object of the section's own keys, checked here and
        // stored in its canonical form, so that the same values make the same text.
        if (self::has_fields($draft)) {
            $content = faisabilite_fields::encode(
                (string) $draft->section,
                faisabilite_fields::decode((string) $draft->section, $content)
            );
        }
        $note = self::clean_note($note);

        return $this->append($draft, $content, $note, null, $knownworkingat);
    }

    /**
     * Restore an earlier version: a NEW version with that content.
     *
     * @param int $draftid
     * @param int $number The version to restore.
     * @param int|null $knownworkingat The `workingat` the page was loaded with, 0 when it knew no draft in progress: as for
     *        save_version(), a draft more recent than the page knew is not wiped. Null for a caller that has no page.
     * @return \stdClass|null The new version, or null when the latest version already has that text.
     * @throws \moodle_exception errordraftconflict, errordraftnoversion
     */
    public function restore(int $draftid, int $number, ?int $knownworkingat = null): ?\stdClass {
        global $DB;

        $draft = $this->draft_for_write($draftid);
        $old = $DB->get_record('tool_vae_version', ['draftid' => $draftid, 'number' => $number]);
        if (!$old) {
            throw new \moodle_exception('errordraftnoversion', 'tool_vae');
        }

        return $this->append($draft, (string) $old->content, null, (int) $old->number, $knownworkingat);
    }

    /**
     * The versions of a section, the latest first.
     *
     * @param int $draftid
     * @return \stdClass[] Rows of tool_vae_version, keyed by number.
     */
    public function versions(int $draftid): array {
        global $DB;

        $draft = $this->draft_for_read($draftid);

        $rows = $DB->get_records('tool_vae_version', ['draftid' => (int) $draft->id], 'number DESC');
        $bynumber = [];
        foreach ($rows as $row) {
            $bynumber[(int) $row->number] = $row;
        }

        return $bynumber;
    }

    /**
     * One version of a section.
     *
     * @param int $draftid
     * @param int $number
     * @return \stdClass
     */
    public function version(int $draftid, int $number): \stdClass {
        global $DB;

        $draft = $this->draft_for_read($draftid);
        $row = $DB->get_record('tool_vae_version', ['draftid' => (int) $draft->id, 'number' => $number]);
        if (!$row) {
            throw new \moodle_exception('errordraftnoversion', 'tool_vae');
        }

        return $row;
    }

    /**
     * Refuse when the draft in progress is more recent than the page that is writing knew.
     *
     * THE ONE MECHANISM OF CONFLICT BETWEEN TWO TABS: the autosave and the button that saves a version both give the
     * `workingat` their page was loaded with, or last received from the autosave, and 0 when it knew no draft in progress.
     *
     * WHY A VERSION IS CHECKED AS WELL AS A DRAFT. Saving a version empties the draft in progress: of the two writes it is the
     * one that destroys. A tab that shows an older text - the writer went back to it by mistake - must not turn it into a
     * version and wipe what another tab kept, hours of writing included. The versions are never lost, the draft is the only
     * copy. A page whose text IS the draft in progress loses nothing and is let through; that is what happens when the
     * autosave of the very same page lands a moment before the click, and the page has not yet been told its time.
     *
     * @param \stdClass $draft The row of tool_vae_draft, read under the lock of the section.
     * @param int|null $knownworkingat What the page knew; null for a caller that has no page, and nothing is compared.
     * @param string|null $saving The text about to become a version; null when the write is a draft.
     * @throws \moodle_exception errordraftconflict
     */
    private static function check_not_stale(\stdClass $draft, ?int $knownworkingat, ?string $saving = null): void {
        if ($knownworkingat === null || $draft->workingat === null || (int) $draft->workingat <= $knownworkingat) {
            return;
        }
        if ($saving !== null && (string) $draft->working === $saving) {
            return;
        }

        throw new \moodle_exception('errordraftconflict', 'tool_vae');
    }

    /**
     * Write a version under the lock of the section.
     *
     * @param \stdClass $draft
     * @param string $content
     * @param string|null $note
     * @param int|null $restoredfrom
     * @param int|null $knownworkingat What the page knew of the draft in progress; null for no page.
     * @return \stdClass|null
     * @throws \moodle_exception errordraftconflict
     */
    private function append(
        \stdClass $draft,
        string $content,
        ?string $note,
        ?int $restoredfrom,
        ?int $knownworkingat = null
    ): ?\stdClass {
        global $DB, $USER;

        $lock = $this->lock((int) $draft->id);
        try {
            // The row is read under the lock, and a refusal comes BEFORE the transaction opens: nothing has been written, so
            // there is nothing to roll back. It comes before the comparison with the latest version too: a stale page whose
            // text is the version would otherwise find "nothing changed" below and empty the draft of the other tab.
            $current = $DB->get_record('tool_vae_draft', ['id' => $draft->id], '*', MUST_EXIST);
            self::check_not_stale($current, $knownworkingat, $content);

            $transaction = $DB->start_delegated_transaction();
            try {
                $hash = hash('sha256', $content);

                if ((int) $current->currentversion > 0) {
                    $latest = $DB->get_record('tool_vae_version', [
                        'draftid' => (int) $draft->id,
                        'number' => (int) $current->currentversion,
                    ], '*', MUST_EXIST);
                    if ($latest->contenthash === $hash) {
                        // Nothing changed: the text written IS the latest version, so the draft in progress - whatever it
                        // held, a text typed and then taken back included - is done with. Left alone it kept the list
                        // saying "more recent draft in progress" about a text that is the version.
                        if ($current->working !== null || $current->workingat !== null) {
                            $DB->update_record('tool_vae_draft', (object) [
                                'id' => (int) $draft->id,
                                'working' => null,
                                'workingat' => null,
                                'timemodified' => time(),
                                'usermodified' => (int) $USER->id,
                            ]);
                        }
                        $transaction->allow_commit();
                        return null;
                    }
                }

                $now = time();
                $number = (int) $current->currentversion + 1;
                $version = (object) [
                    'uuid' => identity_map::generate_ulid(),
                    'draftid' => (int) $draft->id,
                    'number' => $number,
                    'content' => $content,
                    'contenthash' => $hash,
                    'authorid' => (int) $USER->id,
                    'note' => $note,
                    'restoredfrom' => $restoredfrom,
                    'timecreated' => $now,
                ];
                $version->id = $DB->insert_record('tool_vae_version', $version);

                $DB->update_record('tool_vae_draft', (object) [
                    'id' => (int) $draft->id,
                    'working' => null,
                    'workingat' => null,
                    'currentversion' => $number,
                    'timemodified' => $now,
                    'usermodified' => (int) $USER->id,
                ]);

                $transaction->allow_commit();
            } catch (\Throwable $e) {
                // The rollback() call rethrows; the line after it is never reached.
                $transaction->rollback($e);
                throw $e;
            }
        } finally {
            $lock->release();
        }

        $context = \context_user::instance((int) $draft->candidateid);
        $other = ['draftid' => (int) $draft->id, 'number' => (int) $version->number];
        if ($restoredfrom === null) {
            draft_version_saved::create([
                'context' => $context,
                'objectid' => (int) $version->id,
                'relateduserid' => (int) $draft->candidateid,
                'other' => $other,
            ])->trigger();
        } else {
            $other['restoredfrom'] = $restoredfrom;
            draft_version_restored::create([
                'context' => $context,
                'objectid' => (int) $version->id,
                'relateduserid' => (int) $draft->candidateid,
                'other' => $other,
            ])->trigger();
        }

        return $version;
    }

    /**
     * The section, after the write check.
     *
     * @param int $draftid
     * @return \stdClass
     */
    private function draft_for_write(int $draftid): \stdClass {
        global $DB;

        $draft = $DB->get_record('tool_vae_draft', ['id' => $draftid]);
        if (!$draft) {
            // The same refusal as a section one may not write: nothing says whether the id exists.
            throw new \moodle_exception('errordraftrefused', 'tool_vae');
        }
        self::require_can_write((int) $draft->candidateid);
        // Closed means closed for every door: an open tab that autosaves, a restoration from the history.
        if ($draft->doctype === self::DOCTYPE_VALIDATION && !self::validation_open()) {
            throw new \moodle_exception('errorvalidationclosed', 'tool_vae');
        }
        if ($draft->doctype === self::DOCTYPE_REFLEXION && !self::reflection_open()) {
            throw new \moodle_exception('errorreflexionclosed', 'tool_vae');
        }

        return $draft;
    }

    /**
     * The section, after the read check.
     *
     * @param int $draftid
     * @return \stdClass
     */
    private function draft_for_read(int $draftid): \stdClass {
        global $DB;

        $draft = $DB->get_record('tool_vae_draft', ['id' => $draftid]);
        if (!$draft) {
            throw new \moodle_exception('errordraftrefused', 'tool_vae');
        }
        self::require_can_read_draft($draft);
        // The identity and the status are read with a capability of their own (MC-11): whoever may
        // read the narratives does not thereby read a date of birth. The candidate reads their own.
        if (self::has_fields($draft) && !self::can_read_fields((int) $draft->candidateid)) {
            throw new \moodle_exception('errordraftrefused', 'tool_vae');
        }

        return $draft;
    }

    /**
     * Take the lock of a section.
     *
     * @param int $draftid
     * @return \core\lock\lock
     */
    private function lock(int $draftid): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('tool_vae_draft')
            ->get_lock('draft-' . $draftid, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }

        return $lock;
    }

    /**
     * Refuse an address this plugin does not know.
     *
     * @param string $doctype
     * @param string $section
     * @param int $slot
     */
    private static function check_address(string $doctype, string $section, int $slot): void {
        if (
            !in_array($doctype, self::DOCTYPES, true)
                || !preg_match('/^[a-z0-9_]{1,32}$/D', $section)
                || $slot < 0 || $slot > ($doctype === self::DOCTYPE_REFLEXION ? self::REFLEXION_MAX : self::SLOT_MAX)
        ) {
            throw new \moodle_exception('errordraftsection', 'tool_vae');
        }
    }

    /**
     * Line endings as one: a browser sends CRLF, and two texts that differ only there are one text.
     *
     * @param string $text
     * @return string
     */
    private static function normalise(string $text): string {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * Refuse a text too long to keep.
     *
     * @param string $text
     */
    private static function check_length(string $text): void {
        if (\core_text::strlen($text) > self::CONTENT_MAXLENGTH) {
            throw new \moodle_exception('errordrafttoolong', 'tool_vae', '', self::CONTENT_MAXLENGTH);
        }
    }

    /**
     * Clean and bound the note of a version.
     *
     * @param string|null $note
     * @return string|null Null when there is none.
     */
    private static function clean_note(?string $note): ?string {
        $note = trim((string) $note);
        if ($note === '') {
            return null;
        }
        if (\core_text::strlen($note) > self::NOTE_MAXLENGTH) {
            throw new \moodle_exception('errordrafttoolong', 'tool_vae', '', self::NOTE_MAXLENGTH);
        }

        return $note;
    }
}
