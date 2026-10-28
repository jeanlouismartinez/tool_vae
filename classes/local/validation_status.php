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
 * Where each section of the preparation of the validation dossier stands, in words (T-11).
 *
 * The counterpart of faisabilite_overview for the preparation, with the same four states and the same rule: it says what is
 * there - nothing, a draft, a version, a version with a more recent draft - and never whether it is enough (D-1).
 *
 * ONE DEFINITION OF "A DRAFT IN PROGRESS". A draft is there when it holds something: a text emptied by the writer is not a
 * draft, here as in the dossier de faisabilité. The page used to say "draft in progress" for any draft, empty ones included,
 * and glued it behind the version in a sentence that contradicted itself ("Version 3 ... - Draft in progress, no version
 * saved").
 *
 * THE DATE OF A VERSION IS THE DATE IT WAS SAVED, read on the version itself. It used to be the date of the last change of the
 * section, which every autosave moves: the line said "saved on" the day of the last draft, and an import from an archive
 * showed the day of the import instead of the day the archive gives.
 *
 * Reads and creates nothing, and makes no right check: the page that draws it is the candidate's own.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class validation_status {
    /** @var string Nothing written. */
    public const STATE_EMPTY = 'empty';

    /** @var string A draft in progress, no version yet. */
    public const STATE_DRAFT = 'draft';

    /** @var string A version is kept, nothing more recent. */
    public const STATE_VERSION = 'version';

    /** @var string A version is kept and the draft in progress is more recent. */
    public const STATE_VERSION_DRAFT = 'versiondraft';

    /**
     * The sections of one record that have a row, each with where it stands.
     *
     * @param int $candidateid
     * @param string $fichecode The record.
     * @return array[] Section code => ['state' => string, 'version' => int, 'date' => int]. A section nobody has opened has
     *         no row and is not here: place(null) says what it is.
     */
    public static function for_record(int $candidateid, string $fichecode): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT d.id, d.section, d.currentversion, d.working, v.timecreated AS versiontime
               FROM {tool_vae_draft} d
          LEFT JOIN {tool_vae_version} v ON v.draftid = d.id AND v.number = d.currentversion
              WHERE d.candidateid = :candidateid AND d.doctype = :doctype AND d.fichecode = :fichecode',
            [
                'candidateid' => $candidateid,
                'doctype' => draft_manager::DOCTYPE_VALIDATION,
                'fichecode' => $fichecode,
            ]
        );

        $places = [];
        foreach ($rows as $row) {
            $places[(string) $row->section] = self::place($row, $row->versiontime === null ? null : (int) $row->versiontime);
        }

        return $places;
    }

    /**
     * The state of one section.
     *
     * @param \stdClass|null $row A row of tool_vae_draft (currentversion and working are read); null for a section with no row.
     * @param int|null $versiontime When the current version was saved; null when there is none.
     * @return array ['state' => string, 'version' => int, 'date' => int] The date is 0 when there is no version.
     */
    public static function place(?\stdClass $row, ?int $versiontime = null): array {
        if ($row === null) {
            return ['state' => self::STATE_EMPTY, 'version' => 0, 'date' => 0];
        }

        $version = (int) $row->currentversion;
        $working = $row->working !== null && trim((string) $row->working) !== '';

        if ($version > 0) {
            $state = $working ? self::STATE_VERSION_DRAFT : self::STATE_VERSION;
        } else {
            $state = $working ? self::STATE_DRAFT : self::STATE_EMPTY;
        }

        return [
            'state' => $state,
            'version' => $version,
            'date' => $version > 0 ? (int) $versiontime : 0,
        ];
    }

    /**
     * What a place says, in the language of the screen.
     *
     * @param array $place What place() returns.
     * @return string Plain text: the caller escapes it.
     */
    public static function words(array $place): string {
        if ($place['state'] === self::STATE_DRAFT) {
            return get_string('faisabstatedraft', 'tool_vae');
        }
        if ($place['state'] === self::STATE_VERSION || $place['state'] === self::STATE_VERSION_DRAFT) {
            return get_string(
                $place['state'] === self::STATE_VERSION ? 'validationversion' : 'validationversiondraft',
                'tool_vae',
                (object) [
                    'number' => (int) $place['version'],
                    'date' => userdate((int) $place['date'], get_string('strftimedate', 'langconfig')),
                ]
            );
        }

        return get_string('validationnotwritten', 'tool_vae');
    }
}
