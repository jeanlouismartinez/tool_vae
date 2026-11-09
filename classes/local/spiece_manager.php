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

use tool_vae\event\spiece_added;
use tool_vae\event\spiece_read;
use tool_vae\event\spiece_removed;

/**
 * The supporting pieces of the work situations (lot 1b): a file or a link, linked to one or more situations of the same
 * record, each link with the part of the piece the situation is about.
 *
 * THE RULES OF A PIECE OF A DECLARATION (piece_manager), applied by the same code: the type read on the content, the
 * antivirus, the name of the file never kept, a link never visited (piece_manager::check_file(), store_file(),
 * validate_url()). One more nature, `writing` (a professional writing), for these pieces only. Removing erases the file, the
 * title and the address and keeps the nature, the type, the size, the digest and the dates; the links stay and show the piece
 * as removed.
 *
 * ONE QUOTA. A piece of a situation counts against the quota of the pieces of the declarations
 * (piece_manager::used_bytes()), once however many situations cite it, and is added under the same lock
 * (piece_manager::lock()), so that the quota is read with nobody else's form between.
 *
 * WHO. The vaeiste alone writes - adds, attaches, detaches, removes - on their own situations
 * (situation_manager::can_write()); neither the manager nor the administrator does. Adding needs adding open
 * (piece_manager::is_open()); detaching and removing always work. Whoever may read the preparation of the validation dossier
 * reads the lists (draft_manager::can_read_doctype(), D-1b-G); whoever may view the dossier (access::can_view()) opens a
 * file, the site administrator excepted (D-1b-G). Deleting a situation takes its links and leaves the pieces (D-1b-K).
 *
 * THIS IS A COMPONENT API: every public operation checks permission and its parameters.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class spiece_manager {
    /** @var string The table of the pieces. */
    public const TABLE = 'tool_vae_spiece';

    /** @var string The table of the links between a situation and a piece. */
    public const LINKS = 'tool_vae_slink';

    /** @var string The file area, in the vaeiste's user context. */
    public const FILEAREA = 'spiece';

    /** @var string A professional writing: the nature these pieces have and the pieces of a declaration do not. */
    public const NATURE_WRITING = 'writing';

    /** @var string[] What a piece is, from a closed list: those of a declaration, and a professional writing. */
    public const NATURES = [...piece_manager::NATURES, self::NATURE_WRITING];

    /** @var int The longest part concerned, in characters (the width of the column). */
    public const PART_MAXLENGTH = 255;

    /**
     * Add a link to a situation.
     *
     * @param int $situationid
     * @param string $nature One of NATURES.
     * @param string $title Optional, plain text.
     * @param string $url Never visited.
     * @param string $part The part of the piece the situation is about; empty allowed.
     * @return \stdClass The row of the piece, with `link` (the row of the link) and `warnings` (language string identifiers).
     * @throws \moodle_exception errorpiecedenied, errorpiececlosed, errorpiecelimit, errorpiecepart, errorpiecetitle,
     *         errorpiecenature, errorpieceurl, errorpiecebusy
     */
    public function add_link(int $situationid, string $nature, string $title, string $url, string $part): \stdClass {
        return $this->locked(function () use ($situationid, $nature, $title, $url, $part) {
            global $DB;

            $situation = $this->require_may_add($situationid);
            $part = self::clean_part($part);
            $title = piece_manager::clean_title($title);
            self::require_nature($nature);
            [$url, $warnings] = piece_manager::validate_url($url);

            $row = $this->new_row($situation, piece_manager::KIND_LINK, $nature, $title);
            $row->url = $url;

            $transaction = $DB->start_delegated_transaction();
            try {
                $row->id = $DB->insert_record(self::TABLE, $row);
                $row->link = $this->insert_link((int) $situation->id, (int) $row->id, $part);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }

            $this->added($row, (int) $situation->id);
            $row->warnings = $warnings;

            return $row;
        });
    }

    /**
     * Add a file to a situation.
     *
     * Everything is checked before anything is stored; the row, the file and the link are written together, and the file is
     * read back and its digest compared before the piece counts. The name the browser gave is not even asked for.
     *
     * @param int $situationid
     * @param string $nature One of NATURES.
     * @param string $title Optional, plain text.
     * @param string $path Where the upload is now.
     * @param string $part The part of the piece the situation is about; empty allowed.
     * @return \stdClass The row of the piece, with `link` (the row of the link).
     * @throws \moodle_exception errorpiecedenied, errorpiececlosed, errorpiecelimit, errorpiecepart, errorpiecetitle,
     *         errorpiecenature, errorpiecefile, errorpiecetoolarge, errorpiecequota, errorpiecetype, errorpiecemacro,
     *         errorpiecevirus, errorpiecenoantivirus, errorpiecebusy
     */
    public function add_file(int $situationid, string $nature, string $title, string $path, string $part): \stdClass {
        return $this->locked(function () use ($situationid, $nature, $title, $path, $part) {
            global $DB;

            $situation = $this->require_may_add($situationid);
            $part = self::clean_part($part);
            $title = piece_manager::clean_title($title);
            self::require_nature($nature);

            [$ext, $mime, $size, $sha256] = piece_manager::check_file($path, (int) $situation->candidateid);

            $row = $this->new_row($situation, piece_manager::KIND_FILE, $nature, $title);
            $row->ext = $ext;
            $row->mimetype = $mime;
            $row->filesize = $size;
            $row->sha256 = $sha256;

            // The link inside the transaction of the row: a piece is never left without the situation it was added to.
            // Should the link fail, the rollback takes the row and the record of the file together (store_file() opens its
            // transaction inside this one).
            $transaction = $DB->start_delegated_transaction();
            try {
                piece_manager::store_file(self::TABLE, self::FILEAREA, $row, $path);
                $row->link = $this->insert_link((int) $situation->id, (int) $row->id, $part);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }

            $this->added($row, (int) $situation->id);

            return $row;
        });
    }

    /**
     * Link a piece the vaeiste already has to one more situation: a piece of the same vaeiste and the same record, not
     * removed, not linked to this situation now. A link detached before comes back, with the part it is given now.
     *
     * @param int $situationid
     * @param int $pieceid
     * @param string $part The part of the piece this situation is about; empty allowed.
     * @return \stdClass The row of the link.
     * @throws \moodle_exception errorpiecedenied, errorpiececlosed, errorpiecelimit, errorpiecepart, errorpiecegone,
     *         errorpiecebusy
     */
    public function attach(int $situationid, int $pieceid, string $part): \stdClass {
        return $this->locked(function () use ($situationid, $pieceid, $part) {
            global $DB;

            $situation = $this->require_may_add($situationid);
            $part = self::clean_part($part);

            $piece = $DB->get_record(self::TABLE, ['id' => $pieceid]);
            if (
                !$piece || (int) $piece->candidateid !== (int) $situation->candidateid
                || (string) $piece->fichecode !== (string) $situation->fichecode
            ) {
                throw new \moodle_exception('errorpiecedenied', 'tool_vae');
            }
            if ($piece->timeremoved !== null) {
                throw new \moodle_exception('errorpiecegone', 'tool_vae');
            }

            $link = $DB->get_record(self::LINKS, ['situationid' => (int) $situation->id, 'pieceid' => (int) $piece->id]);
            if (!$link) {
                return $this->insert_link((int) $situation->id, (int) $piece->id, $part);
            }
            if ($link->timeremoved === null) {
                // Already there: changing its part is not what attaching does.
                throw new \moodle_exception('errorpiecedenied', 'tool_vae');
            }
            $link->part = $part;
            $link->timeremoved = null;
            $DB->update_record(self::LINKS, $link);

            return $link;
        });
    }

    /**
     * Take a piece off a situation: the link is marked removed, the piece stays. Works open or closed.
     *
     * @param int $situationid
     * @param int $pieceid
     * @throws \moodle_exception errorpiecedenied, errorpiecebusy
     */
    public function detach(int $situationid, int $pieceid): void {
        $this->locked(function () use ($situationid, $pieceid) {
            global $DB;

            $this->require_may_write($situationid);
            $link = $DB->get_record(self::LINKS, ['situationid' => $situationid, 'pieceid' => $pieceid, 'timeremoved' => null]);
            if (!$link) {
                throw new \moodle_exception('errorpiecedenied', 'tool_vae');
            }
            $DB->set_field(self::LINKS, 'timeremoved', time(), ['id' => $link->id]);
        });
    }

    /**
     * Remove a piece: the file, the title and the address go; the nature, the type, the size, the digest and the dates stay.
     * The links stay, and show the piece as removed. Works open or closed, as for a piece of a declaration.
     *
     * What the archive in progress, the backup and the editable copies already hold is refreshed, as piece_manager::remove()
     * does it.
     *
     * @param int $pieceid
     * @return \stdClass The row as it is now.
     * @throws \moodle_exception errorpiecedenied, errorpiecegone, errorpiecebusy
     */
    public function remove(int $pieceid): \stdClass {
        $piece = $this->locked(fn() => $this->delete_piece($pieceid));

        spiece_removed::create([
            'context' => \context_user::instance((int) $piece->candidateid),
            'objectid' => (int) $piece->id,
            'relateduserid' => (int) $piece->candidateid,
        ])->trigger();

        // What was already built from the dossier still carries the piece. Exports handed out are frozen by design (MC-9).
        exporter::discard((int) $piece->candidateid);
        backup::refresh((int) $piece->candidateid);
        document_copies::refresh((int) $piece->candidateid);

        return $piece;
    }

    /**
     * The links of a situation that stand, oldest first, each with its piece - a removed piece included, to be shown as
     * removed.
     *
     * @param int $situationid
     * @return \stdClass[] Rows of tool_vae_slink, each with `piece` (the row of tool_vae_spiece).
     * @throws \moodle_exception errordraftrefused
     */
    public function for_situation(int $situationid): array {
        global $DB;

        // The read check of the situation itself: whoever may read the preparation of the validation dossier.
        (new situation_manager())->get($situationid);

        $links = $DB->get_records(self::LINKS, ['situationid' => $situationid, 'timeremoved' => null], 'id ASC');
        if (!$links) {
            return [];
        }
        $pieces = $DB->get_records_list(self::TABLE, 'id', array_map(fn($link) => (int) $link->pieceid, $links));
        $out = [];
        foreach ($links as $link) {
            if (isset($pieces[$link->pieceid])) {
                $link->piece = $pieces[$link->pieceid];
                $out[] = $link;
            }
        }

        return $out;
    }

    /**
     * Every piece of a vaeiste on a record, the removed ones included, oldest first: what "attach a piece one already has"
     * chooses from.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @return \stdClass[] Rows of tool_vae_spiece.
     * @throws \moodle_exception errordraftrefused
     */
    public function for_candidate(int $candidateid, string $fichecode): array {
        global $DB;

        self::require_can_read($candidateid);

        return array_values($DB->get_records(self::TABLE, ['candidateid' => $candidateid, 'fichecode' => $fichecode], 'id ASC'));
    }

    /**
     * The links of a piece that stand, oldest first: the situations that cite it now.
     *
     * @param int $pieceid
     * @return \stdClass[] Rows of tool_vae_slink.
     * @throws \moodle_exception errordraftrefused Also for an id that is no piece.
     */
    public function links_of(int $pieceid): array {
        global $DB;

        $piece = $DB->get_record(self::TABLE, ['id' => $pieceid]);
        if (!$piece) {
            throw new \moodle_exception('errordraftrefused', 'tool_vae');
        }
        self::require_can_read((int) $piece->candidateid);

        return array_values($DB->get_records(self::LINKS, ['pieceid' => $pieceid, 'timeremoved' => null], 'id ASC'));
    }

    /**
     * Who may open a piece: whoever may view the dossier of the vaeiste (access::can_view()) - the vaeiste, whoever follows
     * them, the direction - and NOT the administrator of the site. A removed piece opens for nobody.
     *
     * The pieces of a situation belong to the preparation of the validation dossier, which D-1b-G (MC-16, D-15 of the DER)
     * closes to the site administrator: they would pass can_view() through viewany, so they are the one reader set aside by
     * hand, as draft_manager::can_read_validation() does. This is the opposite of piece_manager::may_open(), whose pieces
     * belong to the declarations.
     *
     * @param \stdClass $piece A row of tool_vae_spiece.
     * @param int|null $readerid The reader; the current user when null.
     * @return bool
     */
    public static function may_open(\stdClass $piece, ?int $readerid = null): bool {
        global $USER;

        $readerid = $readerid ?? (int) $USER->id;
        if ($readerid <= 0 || ($piece->timeremoved ?? null) !== null) {
            return false;
        }

        return !is_siteadmin($readerid) && access::can_view((int) $piece->candidateid, $readerid);
    }

    /**
     * The stored file of a piece, for whoever may open it, the opening logged.
     *
     * The decision starts from the row read by its id: the piece exists, is a file, is not removed, belongs to the vaeiste of
     * the context it is asked in, and the reader has the right. The context of the URL is never trusted by itself.
     *
     * @param int $pieceid
     * @param int $contextid The context the file was asked in.
     * @return \stored_file|null Null for every refusal, so that none says why.
     */
    public static function open(int $pieceid, int $contextid): ?\stored_file {
        global $DB;

        $piece = $DB->get_record(self::TABLE, ['id' => $pieceid]);
        if (!$piece || $piece->kind !== piece_manager::KIND_FILE || !self::may_open($piece)) {
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

        spiece_read::create([
            'context' => $context,
            'objectid' => (int) $piece->id,
            'relateduserid' => (int) $piece->candidateid,
        ])->trigger();

        return $file;
    }

    /**
     * Catch up with what a removal or a purge did not finish: files of the area that no piece points at, and the file of a
     * piece that was removed (a removed piece keeps none).
     *
     * Idempotent: a second run finds nothing the first did not take.
     *
     * @return int How many files were removed.
     */
    public static function sweep_orphans(): int {
        global $DB;

        $fs = get_file_storage();
        $removed = 0;
        $files = $DB->get_recordset_sql(
            "SELECT DISTINCT f.contextid, f.itemid
               FROM {files} f
          LEFT JOIN {tool_vae_spiece} p ON p.id = f.itemid
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
     * Run something while holding the lock of the current user's pieces: the lock of the pieces of the declarations, since the
     * quota is one. The writer is the vaeiste (every door refuses anyone else), so their id is the key.
     *
     * @param callable $work
     * @return mixed
     * @throws \moodle_exception errorpiecebusy
     */
    private function locked(callable $work) {
        global $USER;

        $lock = piece_manager::lock((int) $USER->id);
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    /**
     * The situation, for its vaeiste alone.
     *
     * @param int $situationid
     * @return \stdClass The row of tool_vae_situation.
     * @throws \moodle_exception errorpiecedenied The same refusal for a situation that is not there and one that is not theirs.
     */
    private function require_may_write(int $situationid): \stdClass {
        global $DB;

        $situation = $DB->get_record(situation_manager::TABLE, ['id' => $situationid]);
        if (!$situation || !situation_manager::can_write((int) $situation->candidateid)) {
            throw new \moodle_exception('errorpiecedenied', 'tool_vae');
        }

        return $situation;
    }

    /**
     * What the situation may receive now, and from whom: its vaeiste, adding open, and room for one more active piece.
     *
     * @param int $situationid
     * @return \stdClass The row of tool_vae_situation.
     * @throws \moodle_exception errorpiecedenied, errorpiececlosed, errorpiecelimit
     */
    private function require_may_add(int $situationid): \stdClass {
        global $DB;

        $situation = $this->require_may_write($situationid);
        if (!piece_manager::is_open()) {
            throw new \moodle_exception('errorpiececlosed', 'tool_vae');
        }
        // Active: the link stands and its piece is not removed.
        $active = $DB->count_records_sql(
            'SELECT COUNT(1)
               FROM {tool_vae_slink} l
               JOIN {tool_vae_spiece} p ON p.id = l.pieceid
              WHERE l.situationid = :situationid AND l.timeremoved IS NULL AND p.timeremoved IS NULL',
            ['situationid' => (int) $situation->id]
        );
        if ($active >= piece_manager::MAX_ACTIVE) {
            throw new \moodle_exception('errorpiecelimit', 'tool_vae', '', piece_manager::MAX_ACTIVE);
        }

        return $situation;
    }

    /**
     * Remove a piece, under the lock.
     *
     * @param int $pieceid
     * @return \stdClass
     * @throws \moodle_exception errorpiecedenied, errorpiecegone
     */
    private function delete_piece(int $pieceid): \stdClass {
        global $DB;

        $piece = $DB->get_record(self::TABLE, ['id' => $pieceid]);
        // The same refusal for a piece that is not there and one that is not theirs.
        if (!$piece || !situation_manager::can_write((int) $piece->candidateid)) {
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
            $DB->update_record(self::TABLE, $piece);
            get_file_storage()->delete_area_files($context->id, 'tool_vae', self::FILEAREA, (int) $piece->id);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return $piece;
    }

    /**
     * A row of a piece, before its kind's own fields.
     *
     * @param \stdClass $situation
     * @param string $kind
     * @param string $nature
     * @param string $title
     * @return \stdClass
     */
    private function new_row(\stdClass $situation, string $kind, string $nature, string $title): \stdClass {
        return (object) [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => (int) $situation->candidateid,
            'fichecode' => (string) $situation->fichecode,
            'kind' => $kind,
            'nature' => $nature,
            'title' => $title,
            'timecreated' => time(),
        ];
    }

    /**
     * Write a link.
     *
     * @param int $situationid
     * @param int $pieceid
     * @param string $part Already cleaned.
     * @return \stdClass The row of the link.
     */
    private function insert_link(int $situationid, int $pieceid, string $part): \stdClass {
        global $DB;

        $link = (object) [
            'situationid' => $situationid,
            'pieceid' => $pieceid,
            'part' => $part,
            'timecreated' => time(),
            'timeremoved' => null,
        ];
        $link->id = $DB->insert_record(self::LINKS, $link);

        return $link;
    }

    /**
     * The part concerned as it is kept: trimmed, within its width, without a control character (a line break included).
     *
     * @param string $part
     * @return string
     * @throws \moodle_exception errorpiecepart
     */
    private static function clean_part(string $part): string {
        $part = trim($part);
        // Invalid UTF-8 makes preg_match() answer false: refused as well.
        if (preg_match('/\p{Cc}/u', $part) !== 0 || \core_text::strlen($part) > self::PART_MAXLENGTH) {
            throw new \moodle_exception('errorpiecepart', 'tool_vae');
        }

        return $part;
    }

    /**
     * The nature must be one of the closed list of these pieces.
     *
     * @param string $nature
     * @throws \moodle_exception errorpiecenature
     */
    private static function require_nature(string $nature): void {
        if (!in_array($nature, self::NATURES, true)) {
            throw new \moodle_exception('errorpiecenature', 'tool_vae');
        }
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
     * Tell the log a piece was added: identifiers only, never the title, the part, a name or an address.
     *
     * @param \stdClass $row
     * @param int $situationid
     */
    private function added(\stdClass $row, int $situationid): void {
        spiece_added::create([
            'context' => \context_user::instance((int) $row->candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => (int) $row->candidateid,
            'other' => ['situationid' => $situationid, 'kind' => $row->kind],
        ])->trigger();
    }
}
