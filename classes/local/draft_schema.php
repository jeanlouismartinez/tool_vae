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
 * The address of a section of a document in tool_vae_draft, as a step of schema (T-11, lot 1).
 *
 * The record the section depends on becomes part of the address: the column `fichecode` is NOT NULL (empty for the
 * dossier de faisabilite) and the unique index holds it. A class and not lines of db/upgrade.php, so that a test can play
 * the step on the two states it meets:
 *
 *  - a site that already had the table (versions 0.9 to 0.31): `fichecode` nullable, the index without it;
 *  - a site coming from the 4.5 package, whose table is created by an earlier step FROM THE CURRENT install.xml: the
 *    column already NOT NULL and the index already whole. The core refuses to change a column under an index
 *    (ddl_dependency_exception), so the step changes the column only when it still needs it.
 *
 * NO DEFAULT. The core drops an empty default of a char column: the column is NOT NULL without default, and every insert
 * writes it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class draft_schema {
    /**
     * Put the record in the address of a section, whatever the state the table is in; running it twice changes nothing.
     *
     * @param \database_manager $dbman
     */
    public static function address_with_record(\database_manager $dbman): void {
        global $DB;

        $table = new \xmldb_table('tool_vae_draft');
        $old = new \xmldb_index('section', XMLDB_INDEX_UNIQUE, ['candidateid', 'doctype', 'section', 'slot']);
        $new = new \xmldb_index('section', XMLDB_INDEX_UNIQUE, ['candidateid', 'doctype', 'fichecode', 'section', 'slot']);

        $columns = $DB->get_columns('tool_vae_draft');
        if (!$columns['fichecode']->not_null) {
            $DB->execute("UPDATE {tool_vae_draft} SET fichecode = '' WHERE fichecode IS NULL");

            // An attempt that stopped halfway may have left the whole index: the column cannot change under it.
            if ($dbman->index_exists($table, $new)) {
                $dbman->drop_index($table, $new);
            }
            $field = new \xmldb_field('fichecode', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, null, 'slot');
            $dbman->change_field_notnull($table, $field);
        }

        if ($dbman->index_exists($table, $old)) {
            $dbman->drop_index($table, $old);
        }
        if (!$dbman->index_exists($table, $new)) {
            $dbman->add_index($table, $new);
        }
    }
}
