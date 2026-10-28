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
 * Where a candidate stands in the sections of the dossier de faisabilité (T-10, lot 1).
 *
 * Reads and creates nothing: a section row is only created when the candidate opens the section
 * to write, so that looking at the list leaves no trace and no empty row.
 *
 * It says what is there - nothing, a draft, a version, a version with a more recent draft - and
 * never whether it is enough (D-1).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class faisabilite_overview {
    /** @var string Nothing written. */
    public const STATE_EMPTY = 'empty';

    /** @var string A draft in progress, no version yet. */
    public const STATE_DRAFT = 'draft';

    /** @var string A version is kept, nothing more recent. */
    public const STATE_VERSION = 'version';

    /** @var string A version is kept and the draft in progress is more recent. */
    public const STATE_VERSION_DRAFT = 'versiondraft';

    /** @var string Read from the candidate's account: nothing is kept here. */
    public const STATE_ACCOUNT = 'account';

    /**
     * The sections of the catalogue, each with what the candidate has written in it.
     *
     * @param int $candidateid
     * @return array[] Code => ['kind', 'open', 'repeated' => bool, 'max' => int,
     *                 'places' => [slot => ['draftid' => int, 'state' => string, 'version' => int,
     *                              'modified' => int]],
     *                 'nextslot' => int|null]. A single section has the place 0, written or not.
     */
    public static function for_candidate(int $candidateid): array {
        global $DB;

        $rows = $DB->get_records('tool_vae_draft', [
            'candidateid' => $candidateid,
            'doctype' => draft_manager::DOCTYPE_FAISABILITE,
        ], 'section ASC, slot ASC');

        $written = [];
        foreach ($rows as $row) {
            $written[$row->section][(int) $row->slot] = $row;
        }

        $overview = [];
        foreach (faisabilite_catalog::sections() as $code => $definition) {
            $repeated = $definition['slots'] > 0;
            $places = [];
            foreach ($written[$code] ?? [] as $slot => $row) {
                if (faisabilite_catalog::valid_address($code, $slot)) {
                    $places[$slot] = self::place($row);
                }
            }
            if ($definition['kind'] === faisabilite_catalog::KIND_ACCOUNT) {
                $places = [0 => ['draftid' => 0, 'state' => self::STATE_ACCOUNT, 'version' => 0, 'modified' => 0]];
            }
            if (!$repeated && !isset($places[0])) {
                $places[0] = ['draftid' => 0, 'state' => self::STATE_EMPTY, 'version' => 0, 'modified' => 0];
            }

            $next = null;
            for ($slot = 1; $repeated && $slot <= $definition['slots']; $slot++) {
                if (!isset($places[$slot])) {
                    $next = $slot;
                    break;
                }
            }

            $overview[$code] = [
                'kind' => $definition['kind'],
                'open' => $definition['open'],
                'repeated' => $repeated,
                'max' => $definition['slots'],
                'places' => $places,
                'nextslot' => $next,
            ];
        }

        return $overview;
    }

    /**
     * The latest SAVED version of every section a candidate has written.
     *
     * What a copy of the document and the archive are written from: never the draft in progress.
     * Read straight from the tables, with no right check - the caller has made its own, and a
     * scheduled task has no logged-in user.
     *
     * @param int $candidateid
     * @return array[] Section => slot => ['number' => int, 'content' => string, 'created' => int]
     */
    public static function saved_versions(int $candidateid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT d.id, d.section, d.slot, v.number, v.content, v.timecreated
               FROM {tool_vae_draft} d
               JOIN {tool_vae_version} v ON v.draftid = d.id AND v.number = d.currentversion
              WHERE d.candidateid = :candidateid AND d.doctype = :doctype AND d.currentversion > 0
           ORDER BY d.section ASC, d.slot ASC',
            ['candidateid' => $candidateid, 'doctype' => draft_manager::DOCTYPE_FAISABILITE]
        );

        $saved = [];
        foreach ($rows as $row) {
            if (!faisabilite_catalog::valid_address((string) $row->section, (int) $row->slot)) {
                continue;
            }
            $saved[$row->section][(int) $row->slot] = [
                'number' => (int) $row->number,
                'content' => (string) $row->content,
                'created' => (int) $row->timecreated,
            ];
        }

        return $saved;
    }

    /**
     * The state of one section row.
     *
     * @param \stdClass $row Row of tool_vae_draft.
     * @return array ['draftid' => int, 'state' => string, 'version' => int, 'modified' => int]
     */
    private static function place(\stdClass $row): array {
        $version = (int) $row->currentversion;
        $working = $row->working !== null && trim((string) $row->working) !== '';

        if ($version > 0) {
            $state = $working ? self::STATE_VERSION_DRAFT : self::STATE_VERSION;
        } else {
            $state = $working ? self::STATE_DRAFT : self::STATE_EMPTY;
        }

        return [
            'draftid' => (int) $row->id,
            'state' => $state,
            'version' => $version,
            'modified' => $state === self::STATE_EMPTY ? 0 : (int) $row->timemodified,
        ];
    }
}
