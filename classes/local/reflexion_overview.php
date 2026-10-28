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
 * What the candidate has SAVED of the reflective journal, as the archive says it (T-14, lot 2).
 *
 * THE LATEST SAVED VERSION OF EACH ENTRY, never the draft in progress. Read straight from the tables with no right check:
 * the caller makes its own.
 *
 * THE JOURNAL IS THE AUTHOR'S ALONE. It enters an archive only when the author exports it themself: a backup made by the
 * administrator, or by anyone else, does not carry it (MC-22). There is no scheduled copy of it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reflexion_overview {
    /** @var string The version of reflexion.schema.json. */
    public const SCHEMA_VERSION = '0.1.0';

    /**
     * The latest saved version of every entry the candidate has written, by number.
     *
     * @param int $candidateid
     * @return \stdClass[] Each: slot, opened (when the entry was first opened), number, content, created.
     */
    public static function saved_entries(int $candidateid): array {
        global $DB;

        return array_values($DB->get_records_sql(
            'SELECT d.id, d.slot, d.timecreated AS opened, v.number, v.content, v.timecreated AS created
               FROM {tool_vae_draft} d
               JOIN {tool_vae_version} v ON v.draftid = d.id AND v.number = d.currentversion
              WHERE d.candidateid = :candidateid AND d.doctype = :doctype AND d.currentversion > 0
           ORDER BY d.slot ASC',
            ['candidateid' => $candidateid, 'doctype' => draft_manager::DOCTYPE_REFLEXION]
        ));
    }

    /**
     * The document of reflexion.schema.json, or null when nothing is saved.
     *
     * WHETHER IT MAY BE CARRIED IS THE CALLER'S DECISION, NEVER READ FROM THE SESSION: exporter::store() puts it in the archive
     * the author takes away and nowhere else, with an explicit flag. The administrator who backs a dossier up, the direction
     * who exports it, get none (T-14, MC-22), and neither does a backup rewritten in the author's own request - when they
     * remove a piece - which this used to carry into the private files of the administrator, because it asked $USER.
     * Not even a task without a user: the journal has no scheduled copy.
     *
     * @param int $candidateid
     * @return array|null
     */
    public static function document(int $candidateid): ?array {
        $entries = [];
        foreach (self::saved_entries($candidateid) as $row) {
            $entries[] = [
                'numero' => (int) $row->slot,
                'creee_le' => gmdate('Y-m-d\TH:i:s\Z', (int) $row->opened),
                'version' => (int) $row->number,
                'enregistree_le' => gmdate('Y-m-d\TH:i:s\Z', (int) $row->created),
                'texte' => (string) $row->content,
            ];
        }
        if (!$entries) {
            return null;
        }

        return [
            'officiel' => false,
            'avertissement' => get_string('reflexionarchivenote', 'tool_vae'),
            'entrees' => $entries,
        ];
    }
}
