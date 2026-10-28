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
 * The supporting pieces of a candidate, as the archive and the printed copies say them (T-13, lot 3).
 *
 * ONE DOCUMENT, TWO SCOPES. `pieces.json` says that a piece exists: its nature, its type, its digest, its dates, and the
 * signature in force that covered it. It never says what the piece contains. What a piece IS - the bytes of a file, the
 * address of a link - leaves the platform in two cases only (DER-referentiel-001, amendment 4, MC-28): the archive the
 * candidate exports themself, and the backup kept on the platform, where an administrator who already opens the pieces keeps
 * the safety net of an uninstall. Everywhere else - the export a third party makes, the printed copy, the editable copy - the
 * list is given without them.
 *
 * THE ORIGINAL NAME OF A FILE IS NOWHERE: it is not kept (piece_manager), so no property is made for it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class piece_overview {
    /** @var string Version of docs/schema/pieces.schema.json the document conforms to. */
    public const SCHEMA_VERSION = '0.1.0';

    /** @var string Where the document sits in an archive. */
    public const PATH = 'pieces.json';

    /** @var string The folder of the bytes of the files, in an archive that carries them. */
    public const FOLDER = 'pieces/';

    /**
     * The document of a candidate's pieces.
     *
     * @param int $candidateid
     * @param bool $full Whether the addresses of links and the paths of files are given (the scope above).
     * @param bool|null $withtitles Whether the title of a piece - a free field, shown on screen only to who may open the piece -
     *        is given. By default as $full: the export of a third party lists nature, type, size and digest, not the title.
     * @param string[]|null $available The paths of the files that are really in the archive, when it is built without
     *        refusing a file that went missing: a piece whose file is not among them is listed without its path.
     * @return array|null The document of pieces.schema.json, or null when the candidate has no piece.
     */
    public static function document(int $candidateid, bool $full, ?bool $withtitles = null, ?array $available = null): ?array {
        $withtitles ??= $full;

        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT p.*, e.uuid AS evidenceuuid
               FROM {tool_vae_piece} p
               JOIN {tool_vae_evidence} e ON e.id = p.evidenceid
              WHERE p.candidateid = :candidateid
           ORDER BY p.id",
            ['candidateid' => $candidateid]
        );
        if (!$rows) {
            return null;
        }

        // The signature in force on each declaration, and what it covered.
        $standing = [];
        $pieces = [];
        foreach ($rows as $row) {
            $evidenceid = (int) $row->evidenceid;
            if (!array_key_exists($evidenceid, $standing)) {
                $attestation = attestation_manager::active_attestation($evidenceid);
                $standing[$evidenceid] = $attestation === null ? null : (object) [
                    'uuid' => $attestation->uuid,
                    'covered' => piece_manager::covered_by((int) $attestation->id),
                ];
            }

            $removed = $row->timeremoved !== null;
            $entry = [
                'id' => $row->uuid,
                'preuve' => $row->evidenceuuid,
                'genre' => $row->kind === piece_manager::KIND_FILE ? 'fichier' : 'lien',
                'nature' => $row->nature,
            ];
            if ($withtitles && $row->title !== '' && !$removed) {
                $entry['intitule'] = $row->title;
            }
            $entry['ajoutee_le'] = exporter::iso((int) $row->timecreated);
            if ($removed) {
                $entry['retiree_le'] = exporter::iso((int) $row->timeremoved);
            }
            if ($row->kind === piece_manager::KIND_FILE) {
                $entry['type'] = $row->mimetype;
                $entry['taille'] = (int) $row->filesize;
                $entry['sha256'] = $row->sha256;
                if ($full && !$removed && ($available === null || in_array(self::path($row), $available, true))) {
                    $entry['fichier'] = self::path($row);
                }
            } else if ($full && !$removed) {
                $entry['adresse'] = $row->url;
            }
            $signature = $standing[$evidenceid];
            if ($signature !== null && array_key_exists((int) $row->id, $signature->covered)) {
                $entry['couverte_par'] = $signature->uuid;
            }
            $pieces[] = $entry;
        }

        return [
            'officiel' => false,
            'avertissement' => get_string('exportpiecesnotice', 'tool_vae'),
            'pieces' => $pieces,
        ];
    }

    /**
     * The bytes of the files the document names, path => content, each read back and compared to its digest.
     *
     * A file that is not there, or that is not the one that was received, refuses the archive by the name of the piece: an
     * archive that vouched for a file it does not hold, or holds altered, would be worse than none.
     *
     * @param int $candidateid
     * @param bool $strict Refuse on the first file that is missing or altered (the archive the candidate exports, the copy the
     *        page promises). When false - the refresh after a withdrawal, an erasure, the backup before an uninstall - the file
     *        is left out and the piece listed without it: those acts must not be stopped by one lost file.
     * @return string[]
     * @throws \moodle_exception errorpiecealtered
     */
    public static function files(int $candidateid, bool $strict = true): array {
        global $DB;

        $context = \context_user::instance($candidateid);
        $fs = get_file_storage();
        $files = [];
        $rows = $DB->get_records_select(
            'tool_vae_piece',
            'candidateid = :candidateid AND kind = :kind AND timeremoved IS NULL',
            ['candidateid' => $candidateid, 'kind' => piece_manager::KIND_FILE],
            'id'
        );
        foreach ($rows as $row) {
            $file = $fs->get_file(
                $context->id,
                'tool_vae',
                piece_manager::FILEAREA,
                (int) $row->id,
                '/',
                $row->uuid . '.' . $row->ext
            );
            $content = $file ? $file->get_content() : null;
            if ($content === null || !hash_equals((string) $row->sha256, hash('sha256', $content))) {
                if ($strict) {
                    throw new \moodle_exception('errorpiecealtered', 'tool_vae', '', $row->uuid);
                }
                debugging(
                    'tool_vae: the file of the piece ' . $row->uuid . ' is missing or altered; left out of the archive.',
                    DEBUG_DEVELOPER
                );
                continue;
            }
            $files[self::path($row)] = $content;
        }

        return $files;
    }

    /**
     * The entries of a document grouped by the declaration they back.
     *
     * @param array|null $document As document() returns it.
     * @return array[] Declaration identifier => entries.
     */
    public static function by_declaration(?array $document): array {
        $grouped = [];
        foreach ($document['pieces'] ?? [] as $entry) {
            $grouped[$entry['preuve']][] = $entry;
        }

        return $grouped;
    }

    /**
     * What a printed or editable copy says of one piece: the nature, the title, the type and the size, the digest or the
     * word that a link has none, the date, and that it was removed. Never an address.
     *
     * @param array $entry An entry of the document.
     * @return string One line, plain text.
     */
    public static function line(array $entry): string {
        $date = get_string('strftimedate', 'langconfig');
        $parts = [get_string('piecenature:' . $entry['nature'], 'tool_vae')];
        if (isset($entry['intitule'])) {
            $parts[] = $entry['intitule'];
        }
        if ($entry['genre'] === 'fichier') {
            $parts[] = strtoupper(self::extension($entry['type'])) . ', ' . display_size((int) $entry['taille']);
            $parts[] = get_string('piecedigest', 'tool_vae') . ' ' . $entry['sha256'];
        } else {
            $parts[] = get_string('exportpiecelink', 'tool_vae');
        }
        $parts[] = display_date::format(self::time($entry['ajoutee_le']), $date);
        if (isset($entry['retiree_le'])) {
            $parts[] = get_string('pieceremovedon', 'tool_vae', display_date::format(self::time($entry['retiree_le']), $date));
        }

        return implode(' - ', $parts);
    }

    /**
     * The path of the bytes of a file in an archive: the identifier of the piece and the extension of its type.
     *
     * @param \stdClass $row A row of tool_vae_piece.
     * @return string
     */
    private static function path(\stdClass $row): string {
        return self::FOLDER . $row->uuid . '.' . $row->ext;
    }

    /**
     * The extension of a type of the closed list.
     *
     * @param string $mime
     * @return string
     */
    private static function extension(string $mime): string {
        return (string) (array_search($mime, piece_manager::TYPES, true) ?: '');
    }

    /**
     * A date-time of the document as a Unix timestamp.
     *
     * @param string $value ISO 8601.
     * @return int
     */
    private static function time(string $value): int {
        return (new \DateTimeImmutable($value))->getTimestamp();
    }
}
