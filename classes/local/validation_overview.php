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
 * What the candidate has SAVED of the preparation of the validation dossier, as the archive, the printed copy and the
 * word-processed copy say it (T-11, lot 2).
 *
 * THE LATEST SAVED VERSION OF EACH SECTION, never the draft in progress, as for the dossier de faisabilité. Read straight
 * from the tables with no right check: the caller has made its own. A scheduled task is no exception to its being said: the
 * cron runs as the main administrator (\core\cron::setup_user()), not as nobody.
 *
 * NOTHING IS HIDDEN. A section of a bloc that has left the record is exported all the same, named by its rank: what
 * a candidate wrote is theirs, whatever the record has become.
 *
 * NOT THE OFFICIAL DOSSIER. The document carries `officiel: false`, the name of the aid used, and the sentence every
 * screen carries, in the language that is current when the document is built (the candidate's, as for the archive).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class validation_overview {
    /** @var string The version of validation.schema.json. */
    public const SCHEMA_VERSION = '0.1.0';

    /** @var string The aid used: the generic layout, by bloc. */
    public const GABARIT = 'par-bloc';

    /**
     * The latest saved version of every section the candidate has written.
     *
     * @param int $candidateid
     * @return \stdClass[] Each: fichecode, section, number, content, created. By record, then by bloc, the three parts
     *         in their order, the synthesis last.
     */
    public static function saved_versions(int $candidateid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT d.id, d.fichecode, d.section, v.number, v.content, v.timecreated AS created
               FROM {tool_vae_draft} d
               JOIN {tool_vae_version} v ON v.draftid = d.id AND v.number = d.currentversion
              WHERE d.candidateid = :candidateid AND d.doctype = :doctype AND d.currentversion > 0',
            ['candidateid' => $candidateid, 'doctype' => draft_manager::DOCTYPE_VALIDATION]
        );

        $rows = array_values($rows);
        usort($rows, function ($a, $b) {
            return strcmp((string) $a->fichecode, (string) $b->fichecode)
                ?: self::order_key((string) $a->section) <=> self::order_key((string) $b->section);
        });

        return $rows;
    }

    /**
     * Where a section stands among the others of its record: blocs in natural order, parts in order, synthesis last.
     *
     * @param string $section
     * @return array
     */
    private static function order_key(string $section): array {
        $parsed = validation_catalog::parse($section);
        if ($parsed === null) {
            return [2, $section, 0];
        }
        [$rank, $part] = $parsed;
        $position = array_search($part, validation_catalog::PARTS, true);

        // Natural order of the ranks: BC2 before BC10 (strnatcmp on a padded key).
        $padded = preg_replace_callback('/\d+/', fn($m) => str_pad($m[0], 8, '0', STR_PAD_LEFT), $rank);

        return [$part === validation_catalog::SYNTHESIS ? 1 : 0, $padded, $position === false ? 9 : $position];
    }

    /**
     * The document of validation.schema.json, or null when nothing is written.
     *
     * WHETHER IT MAY BE CARRIED IS THE CALLER'S DECISION, NEVER READ FROM THE SESSION. What is saved and not deposited is
     * read by nobody but its author (D-15 (3)); a deposit is how it is shown to anyone else. So it goes into the archive the
     * author takes away and into the editable copy kept in their own files - exporter::store() and
     * dossier_document::for_candidate() say so with an explicit flag - and into no backup, no export made by the direction.
     * This used to ask $USER, which is the candidate in the request where they remove a piece and the main administrator in
     * the cron's: the same backup came out with the preparation or without it according to who was running.
     *
     * Written in whatever language is current: the sentence of labelling is the one of the candidate's language when the
     * caller has set it (the archive and the copies do).
     *
     * @param int $candidateid
     * @return array|null
     */
    public static function document(int $candidateid): ?array {
        $rubriques = [];
        foreach (self::saved_versions($candidateid) as $row) {
            $parsed = validation_catalog::parse((string) $row->section);
            if ($parsed === null) {
                continue;
            }
            [$rank, $part] = $parsed;
            $rubriques[] = [
                'fiche' => (string) $row->fichecode,
                'code' => (string) $row->section,
                'bloc' => $rank === '' ? null : strtoupper($rank),
                'partie' => $part,
                'version' => (int) $row->number,
                'enregistree_le' => gmdate('Y-m-d\TH:i:s\Z', (int) $row->created),
                'texte' => (string) $row->content,
            ];
        }
        if (!$rubriques) {
            return null;
        }

        return [
            'gabarit' => self::GABARIT,
            'officiel' => false,
            'avertissement' => get_string('validationlabelling', 'tool_vae'),
            'rubriques' => $rubriques,
        ];
    }
}
