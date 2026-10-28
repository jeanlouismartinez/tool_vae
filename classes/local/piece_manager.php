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

use tool_vae\event\piece_added;
use tool_vae\event\piece_read;
use tool_vae\event\piece_removed;

/**
 * The supporting pieces of a declaration: a file or a link (T-13, D-3, lot 1).
 *
 * WHAT A PIECE IS. One file or one link a candidate attaches to one of their own declarations, with a nature from a closed
 * list and an optional title. The plugin does not read it, judge it, or visit it (D-1, D-4).
 *
 * THE NAME OF A FILE IS NEVER KEPT. "Projet_Mme_X.pdf" is a free field the anonymisation reminder does not cover. The file is
 * stored, served and archived under its identifier and the extension of its DETECTED type; the type is read from the content,
 * because the core would otherwise take it from the name and serve a PDF named x.html as HTML.
 *
 * WHAT IT CHECKS, AND WHAT IT CANNOT. A closed list of types read on the content; macros and remote relations refused in DOCX
 * and ODT; a partial look at the raw bytes of a PDF (what is in compressed objects is not seen); the antivirus of the core,
 * called here because the plain upload field goes round the core's own. It cannot know that a document names nobody.
 *
 * A LINK IS NEVER VISITED. The server makes no request to it. It is shown whole, with a warning when it carries a query or
 * points at an address or a name of a private network, since the click is made by a person on their own computer.
 *
 * REMOVING ALWAYS WORKS, open or closed: the file, the title and the address are erased; the nature, type, size, digest and dates
 * stay. The reading side (the manager who attests) and the archives are lots 2 and 3.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class piece_manager {
    /** @var string A file kept in the plugin's file area. */
    public const KIND_FILE = 'file';

    /** @var string An address, never visited. */
    public const KIND_LINK = 'link';

    /** @var string The file area, in the candidate's user context. */
    public const FILEAREA = 'piece';

    /** @var string[] What a piece is, from a closed list. */
    public const NATURES = ['payslip', 'contract', 'employer', 'diploma', 'jobdescription', 'other'];

    /** @var string[] The types accepted, by the extension they are stored under. */
    public const TYPES = [
        'pdf' => 'application/pdf',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    /** @var int The width of the title. */
    public const TITLE_MAXLENGTH = 60;

    /** @var int The width of an address. */
    public const URL_MAXLENGTH = 255;

    /** @var int Pieces there at once on one declaration. */
    public const MAX_ACTIVE = 5;

    /** @var int Rows on one declaration, removed ones included: adding and removing without end fills nothing. */
    public const MAX_ROWS = 20;

    /** @var int Default size of one file. */
    public const DEFAULT_MAXBYTES = 5 * 1048576;

    /**
     * The most one candidate can keep: what an archive tolerates (dossier_importer::MAX_BYTES, 50 MiB) less room for the
     * JSON, the PDF and the schemas. The setting cannot go above it.
     */
    public const CANDIDATE_CEILING = 30 * 1048576;

    /** @var int Entries a document may hold before it is refused (a bomb, not a document). */
    private const ZIP_MAX_ENTRIES = 2000;

    /** @var int Uncompressed bytes a document may hold before it is refused. */
    private const ZIP_MAX_BYTES = 100 * 1048576;

    /** @var int The largest part of relations, or of content types, read whole. */
    private const RELS_MAX_BYTES = 1048576;

    /** @var int The largest part of a document the plugin reads to look inside it. */
    private const PART_MAX_BYTES = 10 * 1048576;

    /**
     * How much of a PDF is read at a time to look for the actions that start on opening.
     *
     * The memory this takes is a few times this, whatever the size of the file: a PDF of the ceiling (30 MiB) held three
     * times over was most of the 128 MB a request starts with.
     *
     * @var int
     */
    public const PDF_WINDOW_BYTES = 262144;

    /** @var int Decoded characters kept from one window to the next: the longest action, its slash and what follows it. */
    private const PDF_CARRY_CHARS = 32;

    /**
     * What the administrator set, held to the limits the code owns - and to the limit of the site.
     *
     * THE SITE'S LIMIT IS APPLIED WHERE THE LIMIT IS APPLIED. The field of this plugin is a plain one, outside the file
     * manager of the core, which is what carries `Maximum uploaded file size` ($CFG->maxbytes) everywhere else on the site:
     * the setting did not reach this path at all, and a site that capped uploads at 1 MB took 5 MB here. The size is read by
     * insert_file() from this method, so the limit is held at the door and said on the page by the same value, and the two
     * cannot drift apart. The quota of a candidate is not touched: it is a sum, not the size of one upload.
     *
     * @return \stdClass open, maxbytes, maxperevidence, maxpercandidate, noantivirus.
     */
    public static function settings(): \stdClass {
        global $CFG;

        $maxbytes = (int) get_config('tool_vae', 'piecemaxbytes');
        $maxbytes = $maxbytes > 0 ? min($maxbytes, self::CANDIDATE_CEILING) : self::DEFAULT_MAXBYTES;
        // 0 is "no limit of the site": only the limit of PHP, which the page takes into account itself.
        $sitebytes = (int) ($CFG->maxbytes ?? 0);
        if ($sitebytes > 0) {
            $maxbytes = min($maxbytes, $sitebytes);
        }
        $percandidate = (int) get_config('tool_vae', 'piecemaxpercandidate');

        return (object) [
            'open' => !empty(get_config('tool_vae', 'piecesopen')),
            'maxbytes' => $maxbytes,
            'maxperevidence' => self::MAX_ACTIVE,
            'maxpercandidate' => $percandidate > 0 ? min($percandidate, self::CANDIDATE_CEILING) : self::CANDIDATE_CEILING,
            'noantivirus' => !empty(get_config('tool_vae', 'piecenoantivirus')),
        ];
    }

    /**
     * Whether adding is open.
     *
     * @return bool
     */
    public static function is_open(): bool {
        return self::settings()->open;
    }

    /**
     * Whether the core has an antivirus that is configured to scan.
     *
     * The manager of the core keeps its own list private, so the same test is made here: the names the site enabled, those
     * whose scanner says it is configured.
     *
     * @return bool
     */
    public static function antivirus_configured(): bool {
        global $CFG;

        if (empty($CFG->antiviruses)) {
            return false;
        }
        foreach (explode(',', $CFG->antiviruses) as $name) {
            $scanner = \core\antivirus\manager::get_antivirus($name);
            if ($scanner && $scanner->is_configured()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The pieces of a declaration, those removed included, oldest first.
     *
     * @param int $evidenceid
     * @return \stdClass[]
     */
    public static function for_evidence(int $evidenceid): array {
        global $DB;

        return array_values($DB->get_records('tool_vae_piece', ['evidenceid' => $evidenceid], 'id ASC'));
    }

    /**
     * The pieces of a declaration that are there, oldest first: the list a signatory is shown and signs.
     *
     * @param int $evidenceid
     * @return \stdClass[]
     */
    public static function active_for_evidence(int $evidenceid): array {
        return array_values(array_filter(self::for_evidence($evidenceid), fn($piece) => $piece->timeremoved === null));
    }

    /**
     * A list of pieces written in one line, with the digest each file has: what the signature form carries.
     *
     * A link has no digest and is written with a dash. Compared as text, so that one more piece, one less, or a file that is
     * not the same one makes a different line.
     *
     * @param \stdClass[] $pieces
     * @return string For example `4:3f2a...|5:-`; empty for no piece.
     */
    public static function list_token(array $pieces): string {
        return implode('|', array_map(
            fn($piece) => (int) $piece->id . ':' . ($piece->kind === self::KIND_FILE ? $piece->sha256 : '-'),
            array_values($pieces)
        ));
    }

    /**
     * What an attestation covered: the pieces it was given, with the digest of each file at the time.
     *
     * @param int $attestid
     * @return string[] Piece id => digest ('' for a link).
     */
    public static function covered_by(int $attestid): array {
        global $DB;

        $covered = [];
        foreach ($DB->get_records('tool_vae_attest_piece', ['attestid' => $attestid]) as $row) {
            $covered[(int) $row->pieceid] = (string) ($row->sha256 ?? '');
        }

        return $covered;
    }

    /**
     * Which of the pieces a person has opened.
     *
     * @param int $userid
     * @param int[] $pieceids
     * @return bool[] Piece id => true.
     */
    public static function opened_by(int $userid, array $pieceids): array {
        global $DB;

        if (!$pieceids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($pieceids, SQL_PARAMS_NAMED);
        $opened = [];
        $where = "userid = :userid AND pieceid $insql";
        foreach ($DB->get_records_select('tool_vae_piece_open', $where, $params + ['userid' => $userid]) as $row) {
            $opened[(int) $row->pieceid] = true;
        }

        return $opened;
    }

    /**
     * How many bytes of files a candidate keeps, the removed ones not counted.
     *
     * @param int $candidateid
     * @return int
     */
    public static function used_bytes(int $candidateid): int {
        global $DB;

        return (int) $DB->get_field_sql(
            "SELECT COALESCE(SUM(filesize), 0) FROM {tool_vae_piece}
              WHERE candidateid = :candidateid AND kind = :kind AND timeremoved IS NULL",
            ['candidateid' => $candidateid, 'kind' => self::KIND_FILE]
        );
    }

    /**
     * Who may open a piece: the candidate, whoever can attest the candidate (access::can_attest()), the administrator of the
     * site. Whoever may only view the dossier sees the list and not the file. No right of its own: it would not stop the
     * administrator, and would protect by appearance only (DER-referentiel-001, amendment 4, MC-25).
     *
     * @param \stdClass $piece A row of tool_vae_piece.
     * @param int|null $readerid The reader; the current user when null.
     * @return bool
     */
    public static function may_open(\stdClass $piece, ?int $readerid = null): bool {
        global $USER;

        $readerid = $readerid ?? (int) $USER->id;
        if ($readerid <= 0 || ($piece->timeremoved ?? null) !== null) {
            return false;
        }
        $candidateid = (int) $piece->candidateid;
        if ($readerid === $candidateid) {
            return has_capability('tool/vae:viewown', \context_user::instance($candidateid), $readerid);
        }

        return is_siteadmin($readerid) || access::can_attest($candidateid, $readerid);
    }

    /**
     * Run something while holding the lock of the current candidate's pieces.
     *
     * The caller is the candidate (require_may_add() refuses anyone else), so their id is the key. A second request of the
     * same person waits for the first, then reads the counts as the first left them.
     *
     * @param callable $work
     * @return mixed
     * @throws \moodle_exception errorpiecebusy
     */
    private function locked(callable $work) {
        global $USER;

        $lock = self::lock((int) $USER->id);
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * The lock of a candidate's pieces: held to add one, to remove one, and by an attestation that compares the list it was
     * shown with the list there is.
     *
     * One key for all three, so that a piece cannot appear or go between the comparison and the signature.
     *
     * @param int $candidateid
     * @return \core\lock\lock The caller releases it.
     * @throws \moodle_exception errorpiecebusy
     */
    public static function lock(int $candidateid): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('tool_vae_piece')->get_lock('candidate' . $candidateid, 10);
        if (!$lock) {
            throw new \moodle_exception('errorpiecebusy', 'tool_vae');
        }

        return $lock;
    }

    /**
     * Add a link.
     *
     * @param int $evidenceid
     * @param string $nature One of NATURES.
     * @param string $title Optional, plain text.
     * @param string $url
     * @return \stdClass The row, with `warnings` (language string identifiers to show the candidate).
     * @throws \moodle_exception
     */
    public function add_link(int $evidenceid, string $nature, string $title, string $url): \stdClass {
        return $this->locked(fn() => $this->insert_link($evidenceid, $nature, $title, $url));
    }

    /**
     * Add a link, under the lock of the candidate.
     *
     * @param int $evidenceid
     * @param string $nature
     * @param string $title
     * @param string $url
     * @return \stdClass
     */
    private function insert_link(int $evidenceid, string $nature, string $title, string $url): \stdClass {
        global $DB;

        $evidence = $this->require_may_add($evidenceid);
        $title = $this->clean_title($title);
        $this->require_nature($nature);
        [$url, $warnings] = self::validate_url($url);

        $row = (object) [
            'uuid' => identity_map::generate_ulid(),
            'evidenceid' => (int) $evidence->id,
            'candidateid' => (int) $evidence->candidateid,
            'kind' => self::KIND_LINK,
            'nature' => $nature,
            'title' => $title,
            'url' => $url,
            'timecreated' => time(),
            'usercreated' => (int) $evidence->candidateid,
        ];
        $row->id = $DB->insert_record('tool_vae_piece', $row);

        $this->added($row);
        $row->warnings = $warnings;

        return $row;
    }

    /**
     * Add a file.
     *
     * Everything is checked before anything is stored; the row and the file are written together, and the file is read back
     * and its digest compared before the piece counts.
     *
     * @param int $evidenceid
     * @param string $nature One of NATURES.
     * @param string $title Optional, plain text.
     * @param string $path Where the upload is now.
     * @param string $suppliedname The name the browser gave it. NOT USED: never kept, and never given to the antivirus, which
     *        writes it in the log and in the messages to the administrators.
     * @return \stdClass The row.
     * @throws \moodle_exception
     */
    public function add_file(int $evidenceid, string $nature, string $title, string $path, string $suppliedname): \stdClass {
        return $this->locked(fn() => $this->insert_file($evidenceid, $nature, $title, $path));
    }

    /**
     * Add a file, under the lock of the candidate: the counts are read, and the row written, with nobody else's form between.
     *
     * @param int $evidenceid
     * @param string $nature
     * @param string $title
     * @param string $path
     * @return \stdClass
     */
    private function insert_file(int $evidenceid, string $nature, string $title, string $path): \stdClass {
        global $DB;

        $evidence = $this->require_may_add($evidenceid);
        $title = $this->clean_title($title);
        $this->require_nature($nature);

        $settings = self::settings();
        if (!is_file($path) || !is_readable($path)) {
            throw new \moodle_exception('errorpiecefile', 'tool_vae');
        }
        $size = (int) filesize($path);
        if ($size <= 0) {
            throw new \moodle_exception('errorpiecefile', 'tool_vae');
        }
        if ($size > $settings->maxbytes) {
            throw new \moodle_exception('errorpiecetoolarge', 'tool_vae', '', display_size($settings->maxbytes));
        }
        if (self::used_bytes((int) $evidence->candidateid) + $size > $settings->maxpercandidate) {
            throw new \moodle_exception('errorpiecequota', 'tool_vae', '', display_size($settings->maxpercandidate));
        }

        [$ext, $mime] = self::detect_type($path);
        $this->scan($path, 'piece.' . $ext);
        $sha256 = hash_file('sha256', $path);

        $row = (object) [
            'uuid' => identity_map::generate_ulid(),
            'evidenceid' => (int) $evidence->id,
            'candidateid' => (int) $evidence->candidateid,
            'kind' => self::KIND_FILE,
            'nature' => $nature,
            'title' => $title,
            'ext' => $ext,
            'mimetype' => $mime,
            'filesize' => $size,
            'sha256' => $sha256,
            'timecreated' => time(),
            'usercreated' => (int) $evidence->candidateid,
        ];

        $context = \context_user::instance((int) $evidence->candidateid);
        $fs = get_file_storage();
        $hash = null;
        $transaction = $DB->start_delegated_transaction();
        try {
            $row->id = $DB->insert_record('tool_vae_piece', $row);
            $stored = $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => 'tool_vae',
                'filearea' => self::FILEAREA,
                'itemid' => (int) $row->id,
                'filepath' => '/',
                'filename' => $row->uuid . '.' . $ext,
                'userid' => (int) $evidence->candidateid,
            ], $path);
            $hash = $stored->get_contenthash();
            if (hash('sha256', $stored->get_content()) !== $sha256) {
                throw new \moodle_exception('errorpiecefile', 'tool_vae');
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            if (!empty($row->id)) {
                $fs->delete_area_files($context->id, 'tool_vae', self::FILEAREA, (int) $row->id);
            }
            try {
                $transaction->rollback($e);
            } catch (\Throwable $rethrown) {
                // The rows go with the rollback, the content written to the disk does not: taken out if nothing refers to it.
                if ($hash !== null) {
                    try {
                        $fs->get_file_system()->remove_file($hash);
                    } catch (\Throwable $ignored) {
                        debugging('tool_vae: a content could not be removed after a failed attachment.', DEBUG_DEVELOPER);
                    }
                }
                throw $rethrown;
            }
        }

        $this->added($row);

        return $row;
    }

    /**
     * Remove a piece: the file, the title and the address go; the nature, the type, the size, the digest and the dates stay.
     *
     * The candidate can always do it, open or closed, before or after an attestation. What the archive in progress, the backup
     * and the editable copies already hold is refreshed, as a withdrawn attestation does. What a manager downloaded, what the
     * trash of Moodle keeps until it is emptied, and what a jury already holds, this cannot reach: it is written on the screen.
     *
     * The backup is written again as it was made - in the files of whoever holds one, with neither the journal nor the
     * preparation of the validation dossier, whoever is at the keyboard (backup::save()).
     *
     * @param int $pieceid
     * @param int|null $evidenceid When given, the declaration the piece must belong to: refused before anything is removed.
     * @return \stdClass The row as it is now.
     * @throws \moodle_exception errorpiecedenied, errorpiecegone
     */
    public function remove(int $pieceid, ?int $evidenceid = null): \stdClass {
        // The row and the file go under the lock; what was built from the dossier is rebuilt after it, so that a signature
        // never waits for an archive to be written again.
        $piece = $this->locked(fn() => $this->delete_piece($pieceid, $evidenceid));

        piece_removed::create([
            'context' => \context_user::instance((int) $piece->candidateid),
            'objectid' => (int) $piece->id,
            'relateduserid' => (int) $piece->candidateid,
            'other' => ['evidenceid' => (int) $piece->evidenceid],
        ])->trigger();

        // What was already built from the dossier still carries the piece. Exports handed out are frozen by design (MC-9).
        exporter::discard((int) $piece->candidateid);
        backup::refresh((int) $piece->candidateid);
        document_copies::refresh((int) $piece->candidateid);

        return $piece;
    }

    /**
     * Remove a piece, under the lock of the candidate.
     *
     * @param int $pieceid
     * @param int|null $evidenceid
     * @return \stdClass
     */
    private function delete_piece(int $pieceid, ?int $evidenceid): \stdClass {
        global $DB, $USER;

        $piece = $DB->get_record('tool_vae_piece', ['id' => $pieceid]);
        // The same refusal for a piece that is not there and one that is not theirs.
        if (!$piece || !isloggedin() || isguestuser() || (int) $piece->candidateid !== (int) $USER->id) {
            throw new \moodle_exception('errorpiecedenied', 'tool_vae');
        }
        if ($evidenceid !== null && (int) $piece->evidenceid !== $evidenceid) {
            throw new \moodle_exception('errorpiecedenied', 'tool_vae');
        }
        if ($piece->timeremoved !== null) {
            throw new \moodle_exception('errorpiecegone', 'tool_vae');
        }

        $context = \context_user::instance((int) $piece->candidateid);
        $transaction = $DB->start_delegated_transaction();
        try {
            $piece->title = '';
            $piece->url = null;
            $piece->timeremoved = time();
            $piece->removedby = (int) $USER->id;
            $DB->update_record('tool_vae_piece', $piece);
            get_file_storage()->delete_area_files($context->id, 'tool_vae', self::FILEAREA, (int) $piece->id);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return $piece;
    }

    /**
     * The stored file of a piece, for whoever may open it, the opening logged.
     *
     * The decision starts from the row read by its id: the piece exists, is not removed, belongs to the candidate of the
     * context it is asked in, and the reader has the right. The context of the URL is never trusted by itself.
     *
     * @param int $pieceid
     * @param int $contextid The context the file was asked in.
     * @return \stored_file|null Null for every refusal, so that none says why.
     */
    public static function open(int $pieceid, int $contextid): ?\stored_file {
        global $DB, $USER;

        $piece = $DB->get_record('tool_vae_piece', ['id' => $pieceid]);
        if (!$piece || $piece->kind !== self::KIND_FILE || !self::may_open($piece)) {
            return null;
        }
        $context = \context_user::instance((int) $piece->candidateid);
        if ((int) $context->id !== $contextid) {
            return null;
        }
        $file = get_file_storage()->get_file(
            $context->id,
            'tool_vae',
            self::FILEAREA,
            (int) $piece->id,
            '/',
            $piece->uuid . '.' . $piece->ext
        );
        if (!$file) {
            return null;
        }

        // Who opened it, for the attestation screen: a fact, not a reading. The candidate's own opening is not recorded.
        if ((int) $USER->id !== (int) $piece->candidateid) {
            $where = ['pieceid' => (int) $piece->id, 'userid' => (int) $USER->id];
            try {
                $seen = $DB->get_record('tool_vae_piece_open', $where);
                if ($seen) {
                    $DB->set_field('tool_vae_piece_open', 'timeopened', time(), ['id' => $seen->id]);
                } else {
                    $DB->insert_record('tool_vae_piece_open', (object) ($where + ['timeopened' => time()]));
                }
            } catch (\dml_write_exception $e) {
                // The same person's other download got the row first (the index is unique): only the time is left to say.
                $DB->set_field('tool_vae_piece_open', 'timeopened', time(), $where);
            }
        }

        piece_read::create([
            'context' => $context,
            'objectid' => (int) $piece->id,
            'relateduserid' => (int) $piece->candidateid,
            'other' => ['evidenceid' => (int) $piece->evidenceid],
        ])->trigger();

        return $file;
    }

    /**
     * What the declaration may receive now, and from whom: the candidate, on their own declaration, with the right to declare,
     * adding open, and room for one more.
     *
     * @param int $evidenceid
     * @return \stdClass The declaration.
     * @throws \moodle_exception
     */
    public function require_may_add(int $evidenceid): \stdClass {
        global $DB, $USER;

        $evidence = $DB->get_record('tool_vae_evidence', ['id' => $evidenceid]);
        if (!$evidence || !isloggedin() || isguestuser() || (int) $evidence->candidateid !== (int) $USER->id) {
            throw new \moodle_exception('errorpiecedenied', 'tool_vae');
        }
        if (!has_capability('tool/vae:declare', \context_user::instance((int) $evidence->candidateid))) {
            throw new \moodle_exception('errorpiecedenied', 'tool_vae');
        }
        if (!self::is_open()) {
            throw new \moodle_exception('errorpiececlosed', 'tool_vae');
        }

        $rows = self::for_evidence($evidenceid);
        $active = count(array_filter($rows, fn($row) => $row->timeremoved === null));
        if ($active >= self::MAX_ACTIVE) {
            throw new \moodle_exception('errorpiecelimit', 'tool_vae', '', self::MAX_ACTIVE);
        }
        if (count($rows) >= self::MAX_ROWS) {
            throw new \moodle_exception('errorpiecerows', 'tool_vae', '', self::MAX_ROWS);
        }

        return $evidence;
    }

    /**
     * Catch up with what a purge did not finish: pieces whose declaration is gone, files that no piece points at, and the file
     * of a piece that was removed (a removed piece keeps none).
     *
     * Idempotent: a second run finds nothing the first did not take.
     *
     * @return int How many pieces and files were removed.
     */
    public static function sweep_orphans(): int {
        global $DB;

        $fs = get_file_storage();
        $removed = 0;

        $rows = $DB->get_records_sql(
            "SELECT p.id, p.candidateid
               FROM {tool_vae_piece} p
          LEFT JOIN {tool_vae_evidence} e ON e.id = p.evidenceid
              WHERE e.id IS NULL"
        );
        foreach ($rows as $row) {
            $context = \context_user::instance((int) $row->candidateid, IGNORE_MISSING);
            if ($context) {
                $fs->delete_area_files($context->id, 'tool_vae', self::FILEAREA, (int) $row->id);
            }
            $DB->delete_records('tool_vae_attest_piece', ['pieceid' => $row->id]);
            $DB->delete_records('tool_vae_piece_open', ['pieceid' => $row->id]);
            $DB->delete_records('tool_vae_piece', ['id' => $row->id]);
            $removed++;
        }

        $files = $DB->get_recordset_sql(
            "SELECT DISTINCT f.contextid, f.itemid
               FROM {files} f
          LEFT JOIN {tool_vae_piece} p ON p.id = f.itemid
              WHERE f.component = :component AND f.filearea = :area AND (p.id IS NULL OR p.timeremoved IS NOT NULL)",
            ['component' => 'tool_vae', 'area' => self::FILEAREA]
        );
        foreach ($files as $file) {
            $fs->delete_area_files((int) $file->contextid, 'tool_vae', self::FILEAREA, (int) $file->itemid);
            $removed++;
        }
        $files->close();

        return $removed;
    }

    /**
     * Check an address and say what is worth a warning.
     *
     * Accepted: http and https, up to 255 characters, with a host, no identifiers in it. A query, a fragment, an address that is
     * an IP or a name of a private network are accepted WITH a warning: a link of sharing is the access to the content, and the
     * click is made from the reader's own computer.
     *
     * @param string $url
     * @return array [the address, language string identifiers of the warnings]
     * @throws \moodle_exception errorpieceurl
     */
    public static function validate_url(string $url): array {
        $url = trim($url);
        // No space of any kind (a no-break space included), no control character, valid UTF-8: what the schema of the archive
        // also refuses, so that an address accepted here is one the archive can carry and bring back.
        if ($url === '' || strlen($url) > self::URL_MAXLENGTH || preg_match('/[\s\x00-\x20\x7f]/u', $url) !== 0) {
            throw new \moodle_exception('errorpieceurl', 'tool_vae');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new \moodle_exception('errorpieceurl', 'tool_vae');
        }

        // The scheme in lower case, as the schema of the archive reads it: "Https://" typed on a telephone is the same address.
        $url = $scheme . substr($url, strlen((string) $parts['scheme']));

        $warnings = [];
        if (isset($parts['query']) || isset($parts['fragment'])) {
            $warnings[] = 'piecewarnquery';
        }
        $unbracketed = rtrim(trim($host, '[]'), '.');
        $numeric = preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $unbracketed) === 1;
        $isip = $numeric || filter_var($unbracketed, FILTER_VALIDATE_IP) !== false;
        $ranges = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        $private = $isip
            ? $numeric || filter_var($unbracketed, FILTER_VALIDATE_IP, $ranges) === false
            : ($unbracketed === 'localhost' || strpos($unbracketed, '.') === false
                || preg_match('/\.(local|lan|internal|intranet|home|corp)$/', $unbracketed));
        if ($isip || $private) {
            $warnings[] = 'piecewarnprivate';
        }

        return [$url, $warnings];
    }

    /**
     * The host of an address as the network reads it: punycode when the name is not plain ASCII, so that a lookalike letter shows.
     *
     * @param string $url
     * @return string
     */
    public static function display_host(string $url): string {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host !== '' && !preg_match('/^[\x20-\x7e]+$/', $host) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                return $ascii;
            }
        }

        return $host;
    }

    /**
     * Read the type of a file on its content.
     *
     * @param string $path
     * @return array [extension, mime type]
     * @throws \moodle_exception errorpiecetype, errorpiecemacro
     */
    public static function detect_type(string $path): array {
        $head = (string) file_get_contents($path, false, null, 0, 8);

        if (strncmp($head, '%PDF-', 5) === 0) {
            self::require_plain_pdf($path);
            return ['pdf', self::TYPES['pdf']];
        }
        if (bin2hex(substr($head, 0, 3)) === 'ffd8ff' && @getimagesize($path) !== false) {
            return ['jpg', self::TYPES['jpg']];
        }
        if (bin2hex($head) === '89504e470d0a1a0a' && @getimagesize($path) !== false) {
            return ['png', self::TYPES['png']];
        }
        if (bin2hex(substr($head, 0, 4)) === '504b0304') {
            return self::detect_document($path);
        }

        throw new \moodle_exception('errorpiecetype', 'tool_vae');
    }

    /**
     * A PDF, looked at in its raw bytes only.
     *
     * A LIST OF WHAT IS REFUSED, NOT A GUARANTEE, and said so: what sits in a compressed object is not seen. The names of the
     * actions that start something on opening (a launch, a script, a jump to another file, a form sent out, an embedded
     * file) are looked for after the #xx escapes of PDF names are undone, and followed by any delimiter. A link to an address
     * (/URI) is not refused: nothing is fetched until it is clicked, as for a hyperlink of a document.
     *
     * READ BY WINDOWS, NEVER WHOLE. The file was read whole, then its escapes were undone in a second copy: a PDF of the
     * ceiling (30 MiB) took some 80 MiB more on a request that starts at 128 MB, inside an archive already held in memory by
     * the import - a fatal error, and no way to catch it. The decision is the same as if the file were read whole, and the
     * edges of a window are what keeps it so:
     *  - an escape (`#` and two hexadecimal digits) cut in two by the end of a window is held back and decoded with the next
     *    one, so that the decoded stream is exactly the one the whole file gives;
     *  - the last decoded characters of a window are searched again with the next, so that an action that straddles two of
     *    them is found;
     *  - an action that ends where a window ends is not judged until what follows it is known, since the name `/JSX` is not
     *    the action `/JS`: only the end of the file lets it be judged without its next character.
     *
     * @param string $path
     * @throws \moodle_exception errorpiecemacro, errorpiecefile
     */
    private static function require_plain_pdf(string $path): void {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \moodle_exception('errorpiecefile', 'tool_vae');
        }

        $actions = 'Launch|JavaScript|JS|GoToR|GoToE|SubmitForm|ImportData|RichMedia|EmbeddedFile|Sound|Movie';
        $pending = '';
        $tail = '';
        try {
            do {
                $read = fread($handle, self::PDF_WINDOW_BYTES);
                if ($read === false) {
                    throw new \moodle_exception('errorpiecefile', 'tool_vae');
                }
                // A file whose size is a whole number of windows ends on an empty read: feof() only says so then.
                $last = $read === '' || feof($handle);
                $raw = $pending . $read;
                unset($read);

                // An escape the end of the window may have cut: `#`, or `#` and one digit. Held for the next window.
                $cut = strlen($raw);
                if (!$last) {
                    if (substr($raw, -1) === '#') {
                        $cut -= 1;
                    } else if (strlen($raw) >= 2 && substr($raw, -2, 1) === '#' && ctype_xdigit(substr($raw, -1))) {
                        $cut -= 2;
                    }
                }
                $pending = (string) substr($raw, $cut);
                $window = $cut === strlen($raw) ? $raw : substr($raw, 0, $cut);
                unset($raw);

                $decoded = preg_replace_callback(
                    '/#([0-9A-Fa-f]{2})/',
                    fn($match) => chr(hexdec($match[1])),
                    $window
                ) ?? $window;
                unset($window);

                $text = $tail . $decoded;
                unset($decoded);
                $following = $last ? '' : '|\z';
                if (preg_match('#/(' . $actions . ')(?![A-Za-z0-9]' . $following . ')#', $text)) {
                    throw new \moodle_exception('errorpiecemacro', 'tool_vae');
                }
                $tail = (string) substr($text, -self::PDF_CARRY_CHARS);
                unset($text);
            } while (!$last);
        } finally {
            fclose($handle);
        }
    }

    /**
     * A word-processing document: DOCX or ODT, nothing else made of a zip.
     *
     * A LIST OF WHAT IS REFUSED, NOT A GUARANTEE. Refused: a macro or a script part, an embedded or ActiveX object, a relation
     * to something outside the file that the application fetches on opening (a remote template or picture), a field that
     * includes a remote content or runs a command (DOCX), a picture or an object at an outside address and an event bound to
     * an application script (ODT). A hyperlink is not one of them: nothing is fetched until it is clicked. Not seen: a field
     * cut in pieces across the XML, a macro the application finds under another name, what a viewer does that this does not
     * know. Every read is bounded; a part too large to read, or XML that does not parse, refuses the document.
     *
     * @param string $path
     * @return array [extension, mime type]
     * @throws \moodle_exception errorpiecetype, errorpiecemacro
     */
    private static function detect_document(string $path): array {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \moodle_exception('errorpiecetype', 'tool_vae');
        }

        try {
            if ($zip->numFiles > self::ZIP_MAX_ENTRIES) {
                throw new \moodle_exception('errorpiecetype', 'tool_vae');
            }
            $names = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $names[] = (string) $stat['name'];
                $total += (int) $stat['size'];
            }
            if ($total > self::ZIP_MAX_BYTES) {
                throw new \moodle_exception('errorpiecetype', 'tool_vae');
            }

            $isdocx = in_array('[Content_Types].xml', $names, true) && in_array('word/document.xml', $names, true);
            $isodt = trim((string) $zip->getFromName('mimetype', 64)) === self::TYPES['odt'];
            if (!$isdocx && !$isodt) {
                throw new \moodle_exception('errorpiecetype', 'tool_vae');
            }

            foreach ($names as $name) {
                if (self::is_active_part($name)) {
                    throw new \moodle_exception('errorpiecemacro', 'tool_vae');
                }
                if (preg_match('/\.rels$/i', $name)) {
                    self::require_no_remote_relation(self::read_part($zip, $name, self::RELS_MAX_BYTES));
                }
            }

            if ($isdocx) {
                if (stripos(self::read_part($zip, '[Content_Types].xml', self::RELS_MAX_BYTES), 'macroEnabled') !== false) {
                    throw new \moodle_exception('errorpiecemacro', 'tool_vae');
                }
                foreach ($names as $name) {
                    if (preg_match('#^word/[^/]+\.xml$#i', $name)) {
                        $xml = self::read_part($zip, $name, self::PART_MAX_BYTES);
                        if (preg_match('/INCLUDEPICTURE|INCLUDETEXT|\bDDE(?:AUTO)?\b/i', $xml)) {
                            throw new \moodle_exception('errorpiecemacro', 'tool_vae');
                        }
                    }
                }
            } else {
                foreach (['content.xml', 'styles.xml'] as $name) {
                    if (in_array($name, $names, true)) {
                        self::require_no_active_odt(self::read_part($zip, $name, self::PART_MAX_BYTES));
                    }
                }
            }
        } finally {
            $zip->close();
        }

        return $isdocx ? ['docx', self::TYPES['docx']] : ['odt', self::TYPES['odt']];
    }

    /**
     * Whether a part of a document is, by its name, something that runs or embeds.
     *
     * Names of parts do not depend on case.
     *
     * @param string $name
     * @return bool
     */
    private static function is_active_part(string $name): bool {
        $lower = strtolower($name);
        foreach (['scripts/', 'basic/', 'dialogs/', 'word/embeddings/', 'word/activex/'] as $prefix) {
            if (strpos($lower, $prefix) === 0) {
                return true;
            }
        }

        return strpos($lower, 'vbaproject') !== false || preg_match('#^object [0-9]+/#', $lower) === 1;
    }

    /**
     * Read a part of a document, whole or not at all.
     *
     * A part longer than the bound is not cut and read: the document is refused, since what is past the cut would not be seen.
     *
     * @param \ZipArchive $zip
     * @param string $name
     * @param int $limit
     * @return string
     * @throws \moodle_exception errorpiecetype
     */
    private static function read_part(\ZipArchive $zip, string $name, int $limit): string {
        $content = $zip->getFromName($name, $limit + 1);
        if ($content === false) {
            return '';
        }
        if (strlen($content) > $limit) {
            throw new \moodle_exception('errorpiecetype', 'tool_vae');
        }

        return $content;
    }

    /**
     * Refuse a part of relations that points outside the file at something that is not a hyperlink.
     *
     * The XML is read as XML, not searched as text: quotes, spaces and prefixes are the file's own business. It is never
     * fetched and its external entities are never opened.
     *
     * @param string $xml
     * @throws \moodle_exception errorpiecetype, errorpiecemacro
     */
    private static function require_no_remote_relation(string $xml): void {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $parsed = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$parsed) {
            throw new \moodle_exception('errorpiecetype', 'tool_vae');
        }

        foreach ($dom->getElementsByTagNameNS('*', 'Relationship') as $relation) {
            $external = strtolower($relation->getAttribute('TargetMode')) === 'external';
            if ($external && !preg_match('#/hyperlink$#i', $relation->getAttribute('Type'))) {
                throw new \moodle_exception('errorpiecemacro', 'tool_vae');
            }
        }
    }

    /**
     * Refuse an ODT part that loads something from outside the file or binds an event to an application script.
     *
     * A hyperlink (text:a) is not refused; a picture or an object whose address is outside the file is.
     *
     * @param string $xml
     * @throws \moodle_exception errorpiecemacro
     */
    private static function require_no_active_odt(string $xml): void {
        if (stripos($xml, 'script:event-listener') !== false || stripos($xml, 'vnd.sun.star.script:') !== false) {
            throw new \moodle_exception('errorpiecemacro', 'tool_vae');
        }

        $pattern = '/<draw:(?:image|object|object-ole|plugin|applet|floating-frame)\b[^>]*?xlink:href\s*=\s*(["\'])(.*?)\1/is';
        if (preg_match_all($pattern, $xml, $matches)) {
            foreach ($matches[2] as $address) {
                if (preg_match('#^(?:[a-z][a-z0-9+.\-]*:|//|\\\\)#i', trim($address))) {
                    throw new \moodle_exception('errorpiecemacro', 'tool_vae');
                }
            }
        }
    }

    /**
     * Scan a file with the antivirus of the core.
     *
     * The plain upload field goes round the core's own check, so it is made here. With no antivirus configured, files are
     * refused unless the administrator accepted that in writing (MC-24): links need none.
     *
     * @param string $path
     * @param string $name
     * @throws \moodle_exception errorpiecevirus, errorpiecenoantivirus
     */
    public static function scan(string $path, string $name): void {
        if (!self::antivirus_configured()) {
            if (!self::settings()->noantivirus) {
                throw new \moodle_exception('errorpiecenoantivirus', 'tool_vae');
            }
            return;
        }
        try {
            \core\antivirus\manager::scan_file($path, $name, false);
        } catch (\core\antivirus\scanner_exception $e) {
            throw new \moodle_exception('errorpiecevirus', 'tool_vae');
        }
    }

    /**
     * The title, as plain text, within its width.
     *
     * @param string $title
     * @return string
     * @throws \moodle_exception errorpiecetitle
     */
    public static function clean_title(string $title): string {
        $title = trim(preg_replace('/\s+/u', ' ', clean_param($title, PARAM_TEXT)) ?? '');
        if (\core_text::strlen($title) > self::TITLE_MAXLENGTH) {
            throw new \moodle_exception('errorpiecetitle', 'tool_vae', '', self::TITLE_MAXLENGTH);
        }

        return $title;
    }

    /**
     * The nature must be one of the closed list.
     *
     * @param string $nature
     * @throws \moodle_exception errorpiecenature
     */
    private function require_nature(string $nature): void {
        if (!in_array($nature, self::NATURES, true)) {
            throw new \moodle_exception('errorpiecenature', 'tool_vae');
        }
    }

    /**
     * Tell the log a piece was added: identifiers only, never the title, a name or an address.
     *
     * @param \stdClass $row
     */
    private function added(\stdClass $row): void {
        piece_added::create([
            'context' => \context_user::instance((int) $row->candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => (int) $row->candidateid,
            'other' => ['evidenceid' => (int) $row->evidenceid, 'kind' => $row->kind],
        ])->trigger();
    }
}
