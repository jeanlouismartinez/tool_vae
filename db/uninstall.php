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
 * What this plugin owes the platform when it leaves.
 *
 * Moodle cleans up after a plugin with care: its tables, its settings, its capabilities, its
 * role assignments all go. What it CANNOT do is guess that one component wrote rows in another
 * one's tables - nothing in the core records that. Measured on 28 August 2026: after
 * uninstalling this plugin, every candidate still held, in tool_lp's "Evidence of prior
 * learning", an entry labelled `RNCP36832-C03 · 2026-08 → 2026-08` with an empty description,
 * pointing at nothing, explained by no screen.
 *
 * THE DECISION, TAKEN ON 28 AUGUST 2026: the label is made readable, and the evidence STAYS.
 * Destroying it was the other option and it was refused - that record belongs to the candidate,
 * carries the files they attached to it through tool_lp, and no one should lose it because an
 * administrator removed a tool. What is removed is the part that stops making sense: a code
 * that referred to a certification record this site can no longer resolve.
 *
 * This runs BEFORE the tables are dropped - uninstall_plugin() calls the plugin's own hook
 * first - so the rows it needs are still there.
 *
 * AND IT DOES NOT RUN ON THE MOST COMMON PATH, WHICH HAS TO BE SAID PLAINLY. The ordinary way
 * to remove a contributed plugin is to delete its folder and let Moodle offer to purge what is
 * missing. This file is then gone too, uninstall_plugin() finds nothing to call, and the coded
 * labels survive - now beyond recovery, since the tables that held the correspondence go with
 * them. Proved by executing both paths on a running site in adversarial review. Nothing in a
 * plugin can fix that; it belongs in the operating instructions: REMOVE THIS PLUGIN THROUGH
 * THE ADMINISTRATION SCREEN OR admin/cli/uninstall_plugins.php, NOT BY DELETING ITS FOLDER.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Leave the core records readable on the way out.
 *
 * @return bool
 */
function xmldb_tool_vae_uninstall() {
    global $DB, $USER;

    $dbman = $DB->get_manager();

    if (!$dbman->table_exists('tool_vae_evidence')) {
        return true;
    }

    // EVERY DOSSIER IS BACKED UP FIRST, into the private files of the administrator removing the
    // plugin - or of the main administrator, from the command line. Those files survive the
    // uninstall, and import_dossier.php rebuilds each dossier from them after a reinstall.
    // Asked by the project owner on 12 October 2026. A backup that fails throws, on purpose:
    // the uninstall stops while the tables still exist, unlike the relabelling below, where
    // one stubborn row is not worth keeping the plugin for.
    //
    // A backup is the normative archive, and so it carries what an archive carries: the declarations, their signatures
    // and the saved sections. It does NOT carry the deposits of the dossier de faisabilite, the opinions of the managers, the
    // requests for clarification and their answers, the earlier versions of the sections, nor - by rule, whoever runs
    // this - the reflective journal and the preparation of the validation dossier. They go with the tables: say so to
    // whoever asks, and export the database first if those texts must be kept. Each archive says it in its LISEZMOI.txt.
    //
    // It does not depend on the competencies being switched on: the labels it writes are read from the persistents, not
    // through the core's API that refuses when the subsystem is off (exporter::referentiel()).
    $owner = is_siteadmin() ? (int) $USER->id : (int) get_admin()->id;
    \tool_vae\local\backup::save_all($owner);

    $gateway = new \tool_vae\local\competency_gateway();
    $records = $DB->get_recordset('tool_vae_evidence', null, 'id ASC');

    foreach ($records as $record) {
        // One failure must not stop the uninstall halfway: a plugin that cannot be removed
        // because one row of another component moved is worse than a plugin that leaves one
        // coded label behind. Each record is taken on its own.
        // Built from the label STORED at the time of the claim, not recomputed from the
        // dates: recomputing carries the site's current timezone, and a site whose timezone
        // changed since would hand the candidate a farewell label off by a month. The stored
        // string is a fact about the past. A row from before that field existed has to be
        // rebuilt, and says so by having no better source.
        $label = $record->corelabel ?? \tool_vae\local\evidence_manager::label_for(
            (string) $record->fichecode,
            (string) $record->ficherang,
            (int) $record->periodstart,
            (int) $record->periodend
        );

        try {
            $gateway->relabel_evidence(
                (int) $record->userevidenceid,
                get_string('uninstalledlabel', 'tool_vae', (object) ['label' => $label])
            );

            // The stored reference follows the label, so that an uninstall interrupted before
            // the tables are dropped does not leave every dossier accusing its owner of a
            // rename the plugin itself performed. Measured in adversarial review.
            $DB->set_field('tool_vae_evidence', 'corelabel', get_string(
                'uninstalledlabel',
                'tool_vae',
                (object) ['label' => $label]
            ), ['id' => (int) $record->id]);
        } catch (\Throwable $e) {
            debugging(
                'tool_vae: could not relabel core evidence ' . (int) $record->userevidenceid
                    . ' on uninstall: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }

    $records->close();

    return true;
}
