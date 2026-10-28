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

/**
 * Database upgrade steps.
 *
 * The function name is checked by moodle-plugin-ci validate and must be exactly
 * xmldb_tool_vae_upgrade. Every step ends with upgrade_plugin_savepoint(), which
 * is what lets a failed upgrade resume rather than restart.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin database.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_tool_vae_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026082101) {
        // Lot 2 - the six metadata tables. Version 0.1.0 shipped no tables at all, so an
        // existing install has nothing to migrate; the tables are simply created.
        $tables = [
            'tool_vae_identity',
            'tool_vae_evidence',
            'tool_vae_attest',
            'tool_vae_source',
            'tool_vae_obsolete',
            'tool_vae_passage',
        ];

        foreach ($tables as $tablename) {
            $table = new xmldb_table($tablename);
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file(
                    __DIR__ . '/install.xml',
                    $tablename
                );
            }
        }

        upgrade_plugin_savepoint(true, 2026082101, 'tool', 'vae');
    }

    if ($oldversion < 2026082902) {
        // The label written on the core record, kept so that a rename made elsewhere can be
        // seen. Nullable on purpose: a claim created before this field existed has no
        // reference to compare against, and the plugin says nothing rather than guessing.
        $table = new xmldb_table('tool_vae_evidence');
        $field = new xmldb_field('corelabel', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'ficheupdated');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026082902, 'tool', 'vae');
    }

    if ($oldversion < 2026082903) {
        // The competency the claim was made against. Its own savepoint rather than a second
        // field in the block above: that block has already run on sites upgraded between the
        // two, and a block that has run never runs again.
        $table = new xmldb_table('tool_vae_evidence');
        $field = new xmldb_field('competencyid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'ficheupdated');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026082903, 'tool', 'vae');
    }

    if ($oldversion < 2026082909) {
        // ONE PROVENANCE ROW PER RECORD, ENFORCED BY THE DATABASE. The code was read back with
        // get_record() on every import while the index allowed duplicates: two administrators
        // importing the same record at the same moment created two rows, and from then on every
        // import of that record died on dml_multiple_records_exception, with no screen saying
        // why and no way for an administrator to get out of it alone.
        $table = new xmldb_table('tool_vae_source');
        $index = new xmldb_index('fichecode', XMLDB_INDEX_NOTUNIQUE, ['fichecode']);

        if ($dbman->index_exists($table, $index)) {
            $dbman->drop_index($table, $index);
        }

        // Any duplicate already there has to go before the unique index can be created. The
        // most recently imported row is kept - it is the one the screens have been showing -
        // and the divergences of the others are moved onto it rather than dropped: decision 3
        // keeps what an import recorded, whatever the accident that produced the extra row.
        $codes = $DB->get_fieldset_sql(
            'SELECT fichecode FROM {tool_vae_source} GROUP BY fichecode HAVING COUNT(*) > 1'
        );

        foreach ($codes as $code) {
            $rows = $DB->get_records('tool_vae_source', ['fichecode' => $code], 'lastimport DESC, id DESC');
            $keep = array_shift($rows);

            foreach ($rows as $row) {
                $DB->set_field('tool_vae_obsolete', 'sourceid', $keep->id, ['sourceid' => $row->id]);
                $DB->delete_records('tool_vae_source', ['id' => $row->id]);
            }
        }

        $index = new xmldb_index('fichecode', XMLDB_INDEX_UNIQUE, ['fichecode']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026082909, 'tool', 'vae');
    }

    if ($oldversion < 2026101200) {
        // Lot 8, second half: an attestation carried in from an archive names its signatory by
        // the archive's ULID, and says when it was carried in.
        $table = new xmldb_table('tool_vae_attest');
        $fields = [
            new xmldb_field('attestantuuid', XMLDB_TYPE_CHAR, '26', null, null, null, null, 'withdrawnreason'),
            new xmldb_field('importedat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'attestantuuid'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // IMPORTING A CERTIFICATION RECORD IS THE SITE ADMINISTRATOR'S ALONE. Decided with the
        // project owner on 12 October 2026: the manager archetype loses it. Changing the archetype
        // in db/access.php only reaches roles reset afterwards, so the grant the manager roles
        // received at installation is taken back here.
        $system = context_system::instance();
        foreach (get_archetype_roles('manager') as $role) {
            unassign_capability('tool/vae:import', $role->id, $system->id);
        }

        upgrade_plugin_savepoint(true, 2026101200, 'tool', 'vae');
    }

    if ($oldversion < 2026101302) {
        // Lot 8 quater: the register of the archives this platform issued. The digests of the
        // manifest detect damage, not forgery - a forger rewrites them - so the digest of the
        // manifest itself is kept here. Archives produced before this step have no row and are
        // reimported as coming from elsewhere, with the warning that goes with it.
        $table = new xmldb_table('tool_vae_archive');
        if (!$dbman->table_exists($table)) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'tool_vae_archive');
        }

        upgrade_plugin_savepoint(true, 2026101302, 'tool', 'vae');
    }

    if ($oldversion < 2026101311) {
        // A manager asks the signatory of an attestation to withdraw it; the signatory
        // accepts or refuses. The request is two nullable fields on the attestation,
        // open while it waits and cleared when it is answered - no history is kept.
        $table = new xmldb_table('tool_vae_attest');

        $field = new xmldb_field('withdrawreqby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'withdrawnreason');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('withdrawreqat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'withdrawreqby');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026101311, 'tool', 'vae');
    }

    if ($oldversion < 2026101403) {
        // The same two fields again, on purpose and harmless where they exist (each is tested).
        //
        // WHY (T-80). The step above only runs for a site installed below 2026101311. A 4.5
        // package numbered at or above it would be moved to 5.3 with that step skipped, and
        // the fields never created: the 4.5 package carries no such step of its own. Every 4.5
        // number was therefore held under 2026101311, and the 4.5 line ran out of numbers.
        // This step, numbered above every 4.5 package, does the same work for such a site, so
        // the rule is now "a 4.5 number stays below 2026101403" - and the CI migration checks the
        // two fields after the passage (scripts/migration/verify.php).
        $table = new xmldb_table('tool_vae_attest');

        $field = new xmldb_field('withdrawreqby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'withdrawnreason');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('withdrawreqat', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'withdrawreqby');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026101403, 'tool', 'vae');
    }

    if ($oldversion < 2026101404) {
        // T-81: the sections of a document a candidate writes, and their versions. Two new tables,
        // 5.3 only. The 4.5 package receives corrections and nothing else (docs/BRANCHES.md).
        foreach (['tool_vae_draft', 'tool_vae_version'] as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $name);
            }
        }

        upgrade_plugin_savepoint(true, 2026101404, 'tool', 'vae');
    }

    if ($oldversion < 2026101411) {
        // T-21: the deposit of the dossier de faisabilite. One new table, 5.3 only.
        if (!$dbman->table_exists('tool_vae_deposit')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'tool_vae_deposit');
        }

        upgrade_plugin_savepoint(true, 2026101411, 'tool', 'vae');
    }

    if ($oldversion < 2026101414) {
        // T-10, lot 5b: the opinion of a manager on a deposit. One new table, 5.3 only.
        if (!$dbman->table_exists('tool_vae_opinion')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'tool_vae_opinion');
        }

        upgrade_plugin_savepoint(true, 2026101414, 'tool', 'vae');
    }

    if ($oldversion < 2026101500) {
        // T-20: the request for precision on a declaration, and its answer. One new table, 5.3 only.
        if (!$dbman->table_exists('tool_vae_request')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'tool_vae_request');
        }

        upgrade_plugin_savepoint(true, 2026101500, 'tool', 'vae');
    }

    if ($oldversion < 2026101503) {
        // T-30, lot 1: the record that replaces an imported record, declared by an administrator. One new
        // column, 5.3 only.
        $table = new xmldb_table('tool_vae_source');
        $field = new xmldb_field('successorcode', XMLDB_TYPE_CHAR, '32', null, null, null, null, 'sourceupdated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026101503, 'tool', 'vae');
    }

    if ($oldversion < 2026101505) {
        // T-30, lot 3: the declarations a candidate carried over to the record that replaces theirs. One new
        // table, 5.3 only.
        if (!$dbman->table_exists('tool_vae_carry')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'tool_vae_carry');
        }

        upgrade_plugin_savepoint(true, 2026101505, 'tool', 'vae');
    }

    if ($oldversion < 2026101511) {
        // T-11: the record becomes part of the address of a section, so that the preparation of the validation
        // dossier can hold the same section codes for two records. The column was nullable and only written for a
        // section that depends on a record: the dossier de faisabilite wrote none. Empty, not null, from now on.
        \tool_vae\local\draft_schema::address_with_record($dbman);

        upgrade_plugin_savepoint(true, 2026101511, 'tool', 'vae');
    }

    if ($oldversion < 2026101517) {
        // T-13, lot 1: the supporting pieces of a declaration. One new table, 5.3 only.
        if (!$dbman->table_exists('tool_vae_piece')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'tool_vae_piece');
        }

        upgrade_plugin_savepoint(true, 2026101517, 'tool', 'vae');
    }

    if ($oldversion < 2026101518) {
        // T-13, lot 2: the pieces an attestation covered, who opened a piece, and the signatory's note of having taken
        // note of them. Two new tables and one new column, 5.3 only.
        foreach (['tool_vae_attest_piece', 'tool_vae_piece_open'] as $name) {
            if (!$dbman->table_exists($name)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $name);
            }
        }
        $table = new xmldb_table('tool_vae_attest');
        $field = new xmldb_field('piecesread', XMLDB_TYPE_INTEGER, '1', null, null, null, null, 'importedat');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026101518, 'tool', 'vae');
    }

    if ($oldversion < 2026101520) {
        // T-41: the addresses where a certifier publishes its rule of validation. One new table, 5.3 only.
        if (!$dbman->table_exists('tool_vae_ref')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'tool_vae_ref');
        }

        upgrade_plugin_savepoint(true, 2026101520, 'tool', 'vae');
    }

    if ($oldversion < 2026101521) {
        // T-42: the competencies a candidate says are outside their activity. One new table, 5.3 only.
        if (!$dbman->table_exists('tool_vae_scope')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'tool_vae_scope');
        }

        upgrade_plugin_savepoint(true, 2026101521, 'tool', 'vae');
    }

    if ($oldversion < 2026101522) {
        // T-23: the referents of a bloc of a record, a rule of the site. One new table, 5.3 only.
        if (!$dbman->table_exists('tool_vae_referent')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'tool_vae_referent');
        }

        upgrade_plugin_savepoint(true, 2026101522, 'tool', 'vae');
    }

    if ($oldversion < 2026101524) {
        // T-70: the calls to the reformulation aid, who and when, to hold its limits. One new table, 5.3 only.
        if (!$dbman->table_exists('tool_vae_aicall')) {
            $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', 'tool_vae_aicall');
        }

        upgrade_plugin_savepoint(true, 2026101524, 'tool', 'vae');
    }

    if ($oldversion < 2026102601) {
        // Audit of 26 October 2026: a backup is found by the mark in its `source` and by nothing else. Until now the folder
        // and the name were a second way of finding it, for the copies taken before the mark existed (T-75) - and any
        // account names a file in its own private files as it likes, so a stranger could receive a dossier by it. The copies
        // of before the mark get it once here, in the files of the accounts entitled to hold a backup; a file of that name
        // in the files of anyone else was never ours, and is left where it is.
        \tool_vae\local\backup::mark_unmarked();

        upgrade_plugin_savepoint(true, 2026102601, 'tool', 'vae');
    }

    return true;
}
