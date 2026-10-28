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

use tool_vae\event\dossier_document_issued;

/**
 * The dated copies of a dossier kept in the candidate's own private files (T-83, D-8).
 *
 * WHAT THE PROJECT OWNER DECIDED, 15 OCTOBER 2026. A copy a week, written in the night between
 * Sunday and Monday; only when the dossier changed since the last copy; the last two kept; .docx by
 * default, the administrator's setting; off until the administrator turns it on.
 *
 * WHERE. In /vae/copies/ of the CANDIDATE'S private files, not an administrator's: the copy is the
 * candidate's to open, correct and take away. It is not the backup of backup.php, which is the
 * normative archive in an administrator's files and can be read back by the import.
 *
 * THE MARK. Every copy carries in its `source` "tool_vae:copy:<candidate's ULID>:<digest>" (the
 * same device as T-75, under a prefix of its own). A rename or a move does not touch it. It is how
 * the copies are found again for an erasure, and the digest after the ULID is how a run knows that
 * nothing changed. The prefix is NOT backup::SOURCE_PREFIX on purpose: backup::refresh() replaces
 * every file it finds under that one by an archive, which would have turned these into zips.
 *
 * AN ERASURE REMOVES THEM TOO, as it removes the backups: purge::dossier() calls discard(), and
 * the daily reconciliation calls discard_orphans().
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class document_copies {
    /** @var string Folder of the candidate's private files holding the copies. */
    public const FILEPATH = '/vae/copies/';

    /** @var string Start of the mark written in the `source` of every copy. */
    public const SOURCE_PREFIX = 'tool_vae:copy:';

    /** @var int How many copies of a candidate are kept: the newest ones. */
    public const KEEP = 2;

    /**
     * Whether the weekly task is turned on.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool) get_config('tool_vae', 'copyenabled');
    }

    /**
     * The format the administrator chose.
     *
     * @return string One of dossier_document::FORMATS.
     */
    public static function format(): string {
        $format = (string) get_config('tool_vae', 'copyformat');

        return in_array($format, dossier_document::FORMATS, true) ? $format : 'docx';
    }

    /**
     * Take a copy of every dossier whose content changed since its last copy.
     *
     * One failure does not stop the run: it is said in the cron log, and the dossier is tried
     * again the next week.
     *
     * @param int $now
     * @return array ['written' => int, 'unchanged' => int, 'failed' => int]
     */
    public static function run(int $now): array {
        global $DB;

        $result = ['written' => 0, 'unchanged' => 0, 'failed' => 0];
        if (!self::enabled() || !competency_gateway::is_subsystem_enabled()) {
            return $result;
        }

        $ids = $DB->get_fieldset_sql(
            'SELECT DISTINCT e.candidateid
               FROM {tool_vae_evidence} e
               JOIN {user} u ON u.id = e.candidateid
              WHERE u.deleted = 0
           ORDER BY e.candidateid'
        );
        foreach ($ids as $candidateid) {
            try {
                $file = self::take((int) $candidateid, self::format(), $now, true);
                $result[$file === null ? 'unchanged' : 'written']++;
            } catch (\Throwable $e) {
                $result['failed']++;
                mtrace('tool_vae: no copy for candidate ' . (int) $candidateid . ': ' . $e->getMessage());
            }
        }

        return $result;
    }

    /**
     * Take a copy of one dossier, unless nothing changed since the last one.
     *
     * @param int $candidateid
     * @param string $format One of dossier_document::FORMATS.
     * @param int $now
     * @param bool $planned Written by the scheduled task, not at someone's request (for the log).
     * @return \stored_file|null Null when the last copy already says the same.
     */
    public static function take(int $candidateid, string $format, int $now, bool $planned = false): ?\stored_file {
        if (!in_array($format, dossier_document::FORMATS, true)) {
            throw new \coding_exception('Unknown copy format: ' . $format);
        }

        // THE COPY IS THE CANDIDATE'S, whoever runs this: it lands in their own files, so it holds their identity too (MC-11),
        // the preparation of the validation dossier that nobody else reads (D-15 (3)), the titles of the pieces they open, and
        // every saved section - said as such by the arguments below, and not read from the session. The task runs as the main
        // administrator (\core\cron::setup_user()) and a refresh runs as a manager or as the candidate: the same dossier gave
        // a copy with the preparation or without it, and a weekly copy that never changed when a section was saved again.
        $document = dossier_document::for_candidate($candidateid, $now, true, null, true, true, true);
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, $candidateid);

        foreach (self::files_of($personne) as $record) {
            if (self::digest_of($record) === $document['digest']) {
                // A copy in the other format does not stand for this one.
                if (self::extension($record->filename) === $format) {
                    return null;
                }
            }
        }

        $writer = $document['writer'];
        $bytes = $format === 'odt' ? $writer->odt() : $writer->docx();

        $fs = get_file_storage();
        $record = [
            'contextid' => \context_user::instance($candidateid)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => self::FILEPATH,
            'filename' => 'dossier-' . gmdate('Y-m-d', $now) . '.' . $format,
            'userid' => $candidateid,
            'source' => self::SOURCE_PREFIX . $personne . ':' . $document['digest'],
            'author' => fullname(\core_user::get_user($candidateid)),
            'license' => 'unknown',
        ];
        // Two copies the same day (the dossier changed between two runs): the later one replaces.
        $same = $fs->get_file(
            $record['contextid'],
            'user',
            'private',
            0,
            $record['filepath'],
            $record['filename']
        );
        if ($same) {
            $same->delete();
        }
        $file = $fs->create_file_from_string($record, $bytes);

        self::rotate($personne);

        dossier_document_issued::create([
            'context' => \context_user::instance($candidateid),
            'relateduserid' => $candidateid,
            'other' => [
                'declarations' => $document['declarations'],
                'planned' => $planned ? 1 : 0,
                'format' => $format,
                'sha256' => $document['digest'],
            ],
        ])->trigger();

        return $file;
    }

    /**
     * Delete every copy of a candidate.
     *
     * @param string $personne The candidate's ULID.
     * @return int How many copies were deleted.
     */
    public static function discard(string $personne): int {
        $fs = get_file_storage();
        $records = self::files_of($personne);
        foreach ($records as $record) {
            $fs->get_file_instance($record)->delete();
        }

        return count($records);
    }

    /**
     * Replace the copies of a candidate by one taken now, when they had any.
     *
     * Called with backup::refresh(): a signatory was stripped from the dossier, and an old copy
     * still carries the signed statement. The old copies go BEFORE the new one is taken, for the
     * reason backup::refresh() gives.
     *
     * @param int $candidateid
     * @return int How many copies were written: 0 or 1.
     */
    public static function refresh(int $candidateid): int {
        global $DB;

        $personne = $DB->get_field('tool_vae_identity', 'uuid', [
            'itemtype' => identity_map::TYPE_USER,
            'itemid' => $candidateid,
        ]);
        $held = $personne === false ? [] : array_values(self::files_of($personne));
        if (!$held) {
            return 0;
        }

        $format = self::extension($held[0]->filename);
        self::discard($personne);
        if (!$DB->record_exists('tool_vae_evidence', ['candidateid' => $candidateid])) {
            return 0;
        }
        self::take($candidateid, in_array($format, dossier_document::FORMATS, true) ? $format : 'docx', time());

        return 1;
    }

    /**
     * Delete the copies of a candidate this platform no longer knows.
     *
     * @return int How many copies were deleted.
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
            $personne = self::personne_of($row);
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
     * Keep the newest copies of a candidate and delete the others.
     *
     * @param string $personne The candidate's ULID.
     */
    private static function rotate(string $personne): void {
        $records = self::files_of($personne);
        usort($records, function ($a, $b) {
            return [(int) $b->timecreated, (int) $b->id] <=> [(int) $a->timecreated, (int) $a->id];
        });

        $fs = get_file_storage();
        foreach (array_slice($records, self::KEEP) as $old) {
            $fs->get_file_instance($old)->delete();
        }
    }

    /**
     * The file records of every copy of a candidate.
     *
     * By the mark only, wherever the owner moved them. The directory entries Moodle keeps for a
     * folder have no source and are not matched.
     *
     * @param string $personne The candidate's ULID.
     * @return \stdClass[] Rows of the files table.
     */
    private static function files_of(string $personne): array {
        global $DB;

        return $DB->get_records_select(
            'files',
            'component = :component AND filearea = :filearea AND ' . $DB->sql_like('source', ':source'),
            [
                'component' => 'user',
                'filearea' => 'private',
                'source' => $DB->sql_like_escape(self::SOURCE_PREFIX . $personne . ':') . '%',
            ]
        );
    }

    /**
     * The candidate's ULID written in the mark of a copy.
     *
     * @param \stdClass $record Row of the files table.
     * @return string
     */
    private static function personne_of(\stdClass $record): string {
        $rest = substr((string) $record->source, strlen(self::SOURCE_PREFIX));

        return explode(':', $rest, 2)[0];
    }

    /**
     * The digest written in the mark of a copy.
     *
     * @param \stdClass $record Row of the files table.
     * @return string
     */
    private static function digest_of(\stdClass $record): string {
        $rest = substr((string) $record->source, strlen(self::SOURCE_PREFIX));

        return explode(':', $rest, 2)[1] ?? '';
    }

    /**
     * The extension of a file name, in lower case.
     *
     * @param string $filename
     * @return string
     */
    private static function extension(string $filename): string {
        return strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    }
}
