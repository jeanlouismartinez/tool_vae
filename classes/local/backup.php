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
 * A lasting backup of a dossier, kept on the platform and reimportable (lot 8 bis).
 *
 * WHAT THE PROJECT OWNER ASKED FOR, 12 OCTOBER 2026: a backup of the claims on Moodle itself, that
 * can be used again - taken on demand, and taken automatically before the plugin is uninstalled.
 *
 * WHERE. In the private files of a site administrator, under /vae/. Not in this plugin's
 * own file area: Moodle deletes that area with the plugin, which is the very moment the backup
 * exists for. A private file survives the uninstall, and the file picker of import_dossier.php
 * offers it as it is - the backup IS the normative archive, so reimporting it needs nothing new.
 *
 * REIMPORTABLE UNLESS IT SAYS OTHERWISE. A dossier holding a line the schema refuses (a rank written `c7`) is backed up all
 * the same, by the page like by the uninstall (decision of the project owner, 27 October 2026): the page then shows the
 * administrator the warning the exporter gives - see save() - and the notice inside the backup carries the mention. Such a
 * backup is not reimported as it stands: the import stays strict.
 *
 * WHICH CANDIDATE. The file is named dossier-<candidate's ULID>-<archive's ULID>.zip. The ULID,
 * not the name: it is what the reimport gives back to the account, and what an erasure request
 * looks for - see discard(). The candidate's name is written inside, in LISEZMOI.txt.
 *
 * AN ERASURE REMOVES THE BACKUPS TOO. A backup holds the whole dossier; keeping it after the
 * candidate asked to be forgotten would make the request a formality. purge::dossier() calls
 * discard() for that reason. A signatory's erasure reaches them as well: the privacy provider
 * calls refresh(), which replaces them with backups of the stripped dossier - in the files of the
 * accounts that may still hold one.
 *
 * A BACKUP IS NEVER FOR THE CANDIDATE, whoever triggers it: no reflective journal and no preparation
 * of the validation dossier in it, and the account that holds it is the one that made it - see save().
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup {
    /** @var string Folder of the private files holding the backups. */
    public const FILEPATH = '/vae/';

    /**
     * @var string Start of the mark written in the `source` of every backup (T-75).
     *
     * A copy lives in somebody's private files, where the owner can rename it or move it to another
     * folder. A search by folder and by name then lost it, and an erasure no longer reached it. The
     * `source` of a file record is not shown in that interface and is not touched by a rename or a
     * move: the candidate's ULID is written there, and that is what the search reads. The mark ALONE:
     * the folder and the name are what any account chooses freely in its own private files, so a
     * search by them took a file planted by a stranger for a backup (audit of 26 October 2026). The
     * copies taken before the mark existed are marked once by the upgrade - see mark_unmarked().
     *
     * NOT A SECRET, AND NOT A PROOF. The core copies the name the browser announced for an uploaded file into
     * `source` (file_save_draft_area_files()), so an account that sends a file under this name carries the mark. What
     * makes a backup is therefore not the mark but the right of its owner to hold one - see may_hold().
     */
    public const SOURCE_PREFIX = 'tool_vae:backup:';

    /**
     * Whether the current user may take backups, of one dossier when it is named.
     *
     * The capability that rebuilds a dossier from an archive: whoever may reimport may keep what
     * they would reimport. By default the site administrator alone. Named, a dossier asks the right
     * to rebuild THAT dossier, which is also the core's right to write evidence on the candidate's behalf.
     *
     * @param int|null $candidateid The dossier, or null for the right in general (the button of a screen that has none).
     * @return bool
     */
    public static function can_backup(?int $candidateid = null): bool {
        if ($candidateid === null) {
            return dossier_importer::can_import();
        }

        try {
            dossier_importer::require_can_import($candidateid);
        } catch (\required_capability_exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Require the right to back up a candidate's dossier, as backup.php does.
     *
     * The very rule of the page that reimports - dossier_importer::require_can_import() - for the reason this class gives:
     * whoever may reimport may keep what they would reimport. The capability of this plugin alone is not enough: a backup
     * reads the whole dossier, and writes it into files that are kept without time limit, in someone else's name.
     *
     * @param int $candidateid
     * @return \context_user The candidate's user context.
     */
    public static function require_can_backup(int $candidateid): \context_user {
        return dossier_importer::require_can_import($candidateid);
    }

    /**
     * Whether an account may still hold a backup of a dossier: the right to take one, as of now, evaluated for that account.
     *
     * refresh() asks it of every account that held one, before writing a fresh one there. The page that takes a backup
     * asks the right of the person at the keyboard; the rewriting that follows a signatory's erasure or a piece removed
     * runs in the session of someone else - the candidate, a manager, the cron - and must not write a whole dossier into
     * the private files of an account that was never entitled to one. The administrator of the site holds the right.
     *
     * THE SAME TWO RIGHTS AS THE PAGE, WHEN A DOSSIER IS NAMED (require_can_backup()): the capability of this plugin AND the
     * core's right to write evidence on the candidate's behalf. The mark of a backup is no proof (see SOURCE_PREFIX): an
     * account holding importdossier alone, which the page refuses for that candidate, could plant a file carrying the mark
     * and be written the dossier at the next refresh - the very gap the page's rule closes. The right is evaluated for the
     * owner, on the candidate's context, as dossier_importer::require_can_import() does for the person at the keyboard.
     *
     * @param int $ownerid
     * @param int|null $candidateid The dossier about to be written there; null asks the right in general (the upgrade, which
     *        marks a file before it knows which candidate it will be refreshed for).
     * @return bool
     */
    public static function may_hold(int $ownerid, ?int $candidateid = null): bool {
        if ($ownerid <= 0 || !has_capability('tool/vae:importdossier', \context_system::instance(), $ownerid)) {
            return false;
        }
        if ($candidateid === null) {
            return true;
        }

        $context = \context_user::instance($candidateid, IGNORE_MISSING);

        return $context && has_capability('moodle/competency:userevidencemanage', $context, $ownerid);
    }

    /**
     * Back up one dossier into the private files of a user.
     *
     * A backup is NEVER for the candidate, whoever triggers it: it carries neither the reflective journal nor the
     * preparation of the validation dossier (MC-22, D-15 (3)), and the person who made it is its owner. Both used to be
     * read from the session, so the backup rewritten when a candidate removed a piece carried their journal into the
     * private files of the administrator, and signed the archive with the candidate's name (audit of 26 October 2026).
     *
     * @param int $candidateid
     * @param int $ownerid Whose private files receive it.
     * @param bool $enforcelimits Refuse a dossier too large to be read back by the import (T-77), and a piece whose file is
     *        lost or altered. The page asks it - the person is promised a copy that holds what it vouches for. The uninstall
     *        and the refresh after an erasure do not: refusing there would block a removal, and an oversized copy is still
     *        better kept than lost. A line the schema refuses is never refused, whatever this says: it is reported (below).
     * @param exporter|null $exporter The exporter that makes the copy, when the caller wants to read what it reports after
     *        it - exporter::warnings(), the lines the schema refuses, which the page shows the administrator. Null: a new
     *        one, and the warnings are not read (the uninstall, the refresh of an erasure).
     * @return \stored_file
     */
    public static function save(
        int $candidateid,
        int $ownerid,
        bool $enforcelimits = true,
        ?exporter $exporter = null
    ): \stored_file {
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, $candidateid);

        $file = ($exporter ?? new exporter())->store(
            $candidateid,
            \context_user::instance($ownerid)->id,
            'user',
            'private',
            self::FILEPATH,
            'dossier-' . $personne . '-',
            $enforcelimits,
            // The backup of an administrator stays on the platform, where they already open the pieces, and it is the safety net
            // of an uninstall: the bytes and the addresses go in (T-13, MC-28). Another account that was given the right to back
            // up gets the list, not the files.
            is_siteadmin($ownerid),
            // Not for the author: no journal, no preparation of the validation dossier, in any session.
            false,
            // Made by the account that holds it.
            $ownerid,
            // The safety net of an uninstall keeps every saved section.
            true
        );
        $file->set_source(self::SOURCE_PREFIX . $personne);

        return $file;
    }

    /**
     * Back up every dossier of the site.
     *
     * Used before the plugin is uninstalled. A failure is not swallowed: it stops the uninstall
     * while the tables still exist, which loses nothing, where going on would lose a dossier.
     *
     * @param int $ownerid
     * @return int How many dossiers were backed up.
     */
    public static function save_all(int $ownerid): int {
        global $DB;

        $candidates = $DB->get_fieldset_sql('SELECT DISTINCT candidateid FROM {tool_vae_evidence} ORDER BY candidateid');
        foreach ($candidates as $candidateid) {
            self::save((int) $candidateid, $ownerid, false);
        }

        return count($candidates);
    }

    /**
     * Delete every backup of a candidate, in whoever's private files it sits.
     *
     * @param string $personne The candidate's ULID.
     * @return int How many backups were deleted.
     */
    public static function discard(string $personne): int {
        $records = self::files_of($personne);

        $fs = get_file_storage();
        foreach ($records as $record) {
            $fs->get_file_instance($record)->delete();
        }

        return count($records);
    }

    /**
     * Delete the backups of a candidate this platform no longer knows (T-78).
     *
     * An erasure removes the candidate's identity entry inside its transaction and deletes the
     * backups after it, from the ULID read beforehand. If the process stops in between, the files
     * stay and nothing names their owner any more: they are found here, by the mark and by the
     * absence of an identity entry carrying that ULID. A copy without the mark - taken before it
     * existed - cannot be told from its owner's own file and is left alone.
     *
     * @return int How many backups were deleted.
     */
    public static function discard_orphans(): int {
        global $DB;

        $rows = $DB->get_records_select(
            'files',
            'component = :component AND filearea = :filearea AND ' . $DB->sql_like('source', ':source'),
            [
                'component' => 'user',
                'filearea' => 'private',
                'source' => $DB->sql_like_escape(self::SOURCE_PREFIX) . '%',
            ]
        );

        $fs = get_file_storage();
        $count = 0;
        foreach ($rows as $row) {
            $personne = substr((string) $row->source, strlen(self::SOURCE_PREFIX));
            $known = $DB->record_exists('tool_vae_identity', [
                'itemtype' => identity_map::TYPE_USER,
                'uuid' => $personne,
            ]);
            if (!$known) {
                $fs->get_file_instance($row)->delete();
                $count++;
            }
        }

        return $count;
    }

    /**
     * Replace every backup of a candidate by one taken now, in the private files of the same owners.
     *
     * Called when a signatory is stripped from the dossier (lot 8 ter). A backup is kept without
     * time limit, and the old ones still carry the signature just removed: the account in
     * correspondances[], the ULID, the job title and the statement. A fresh archive of the stripped
     * dossier says "withdrawn on such a date" instead, and the owner keeps a backup to reimport.
     *
     * One fresh backup per owner, however many they held: they would all describe the dossier as it
     * now stands. The old ones go BEFORE the new one is taken: should taking it fail, the request
     * fails with the old copies already gone, rather than leaving the erased signature in them for
     * good - the dossier itself is still in the tables.
     *
     * ONLY WHERE A BACKUP MAY BE HELD (audit of 26 October 2026). Every file found is erased, wherever it sits: an
     * erasure must reach it. A fresh one is written only for an owner who holds the right to back up that dossier
     * (may_hold(), the two rights of the page): the account that has lost it, or that never had it and carries the mark
     * because it sent a file under that name, is left with nothing, and the dossier is not written into its private
     * files, to be downloaded without time limit.
     *
     * @param int $candidateid
     * @return int How many owners received a fresh backup.
     */
    public static function refresh(int $candidateid): int {
        global $DB;

        // Read, not uuid_for(): a candidate without a ULID was never backed up, and needs no ULID now.
        $personne = $DB->get_field('tool_vae_identity', 'uuid', [
            'itemtype' => identity_map::TYPE_USER,
            'itemid' => $candidateid,
        ]);
        if ($personne === false) {
            return 0;
        }

        $owners = [];
        foreach (self::files_of($personne) as $record) {
            $owner = \context::instance_by_id($record->contextid, IGNORE_MISSING);
            if ($owner && (int) $owner->contextlevel === CONTEXT_USER) {
                $owners[(int) $owner->instanceid] = true;
            }
        }
        self::discard($personne);

        // A dossier emptied since the request was approved has nothing left to back up; its old
        // copies still had to go.
        if (!$owners || !$DB->record_exists('tool_vae_evidence', ['candidateid' => $candidateid])) {
            return 0;
        }

        $served = 0;
        foreach (array_keys($owners) as $ownerid) {
            if (!self::may_hold($ownerid, $candidateid)) {
                continue;
            }
            self::save($candidateid, $ownerid, false);
            $served++;
        }

        return $served;
    }

    /**
     * Put the mark on the backups taken before it existed, once.
     *
     * The upgrade that stops looking for a backup by its folder and its name calls it. Until then a copy without the mark
     * could only be found that way; after it, nothing finds it, and an erasure would miss it. What is marked is a file
     * named as a backup is - dossier-<ULID of a candidate this platform knows>-<ULID>.zip, in the folder of the backups, with
     * no source - in the private files of an account that may hold one (may_hold()): a file of that name in the files of
     * anyone else is not a backup this plugin made, and is left alone. The file is written by the core's own API.
     *
     * @return int How many backups were marked.
     */
    public static function mark_unmarked(): int {
        global $DB;

        $rows = $DB->get_records_select(
            'files',
            'component = :component AND filearea = :filearea AND filepath = :filepath AND ' .
                $DB->sql_like('filename', ':filename') .
                ' AND (source IS NULL OR ' . $DB->sql_isempty('files', 'source', true, true) . ')',
            [
                'component' => 'user',
                'filearea' => 'private',
                'filepath' => self::FILEPATH,
                'filename' => $DB->sql_like_escape('dossier-') . '%.zip',
            ]
        );

        $fs = get_file_storage();
        $marked = 0;
        foreach ($rows as $row) {
            if (!preg_match('/^dossier-([0-9A-Z]{26})-[0-9A-Z]{26}\.zip$/D', (string) $row->filename, $found)) {
                continue;
            }
            $personne = $found[1];
            $known = identity_map::is_valid_ulid($personne) && $DB->record_exists('tool_vae_identity', [
                'itemtype' => identity_map::TYPE_USER,
                'uuid' => $personne,
            ]);
            $owner = $known ? \context::instance_by_id($row->contextid, IGNORE_MISSING) : false;
            if (!$owner || (int) $owner->contextlevel !== CONTEXT_USER || !self::may_hold((int) $owner->instanceid)) {
                continue;
            }

            $fs->get_file_instance($row)->set_source(self::SOURCE_PREFIX . $personne);
            $marked++;
        }

        return $marked;
    }

    /**
     * The file records of every backup of a candidate, in whoever's private files they sit.
     *
     * BY THE MARK ONLY. It follows a rename and a move. The folder and the name, which this also read until the audit of
     * 26 October 2026 for the copies that predate the mark, are what anyone chooses in their own private files: a stranger
     * who had read a candidate's ULID in an archive handed to a jury named a file after it, and the next refresh wrote the
     * whole dossier there. The copies that predate the mark were marked by the upgrade - see mark_unmarked().
     *
     * @param string $personne The candidate's ULID.
     * @return \stdClass[] Rows of the files table.
     */
    private static function files_of(string $personne): array {
        global $DB;

        return $DB->get_records_select(
            'files',
            'component = :component AND filearea = :filearea AND ' .
                $DB->sql_compare_text('source', 255) . ' = ' . $DB->sql_compare_text(':source', 255),
            [
                'component' => 'user',
                'filearea' => 'private',
                'source' => self::SOURCE_PREFIX . $personne,
            ]
        );
    }
}
