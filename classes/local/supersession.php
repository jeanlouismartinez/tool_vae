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

use tool_vae\event\supersession_changed;

/**
 * Which imported certification record replaces which (T-30, lot 1, D-14).
 *
 * A DECLARATION, NOT AN ACTION. A record republished under a new number does not move the dossiers that hold
 * declarations against the old one; an administrator says that the new one replaces the old, and this class
 * counts what still holds the old one. It moves nothing, changes no declaration, asks no attestation again:
 * carrying a declaration over to the new record is a later lot, and a human decision (tool_vae_passage).
 *
 * NUMBERS ONLY. The overview counts declarations and candidates per record and names nobody: it is read by
 * whoever may import, which is not the same people as those who may read a dossier.
 *
 * A record has at most one successor, and the chain never loops: A replaced by B replaced by A would leave
 * no current record at all.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class supersession {
    /**
     * Declare that a record is replaced by another. Replaces any earlier declaration for the same record.
     *
     * @param string $fichecode The record that is replaced.
     * @param string $successorcode The record that replaces it.
     * @throws \moodle_exception errorsupersessionunknown, errorsupersessionsame, errorsupersessioncycle
     */
    public static function declare_successor(string $fichecode, string $successorcode): void {
        global $DB;

        require_capability('tool/vae:import', \context_system::instance());

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        if (!$source || !$DB->record_exists('tool_vae_source', ['fichecode' => $successorcode])) {
            throw new \moodle_exception('errorsupersessionunknown', 'tool_vae');
        }
        if ($fichecode === $successorcode) {
            throw new \moodle_exception('errorsupersessionsame', 'tool_vae');
        }
        // Following the successors of the new one must never lead back to this record.
        $seen = [];
        $code = $successorcode;
        while ($code !== null && !isset($seen[$code])) {
            if ($code === $fichecode) {
                throw new \moodle_exception('errorsupersessioncycle', 'tool_vae');
            }
            $seen[$code] = true;
            $code = self::successor_of($code);
        }

        self::write($source, $successorcode);
    }

    /**
     * Remove the declaration that a record is replaced.
     *
     * @param string $fichecode
     * @throws \moodle_exception errorsupersessionunknown
     */
    public static function clear_successor(string $fichecode): void {
        global $DB;

        require_capability('tool/vae:import', \context_system::instance());

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        if (!$source) {
            throw new \moodle_exception('errorsupersessionunknown', 'tool_vae');
        }

        self::write($source, null);
    }

    /**
     * The imported records, each with what still holds it.
     *
     * @return \stdClass[] Keyed by record code, in code order: fichecode, label, sourceupdated, successorcode (or ''),
     *         successorlabel (or ''), declarations, candidates, carried (declarations on this record that candidates
     *         carried over to its successor), gone (competencies that left the source).
     */
    public static function overview(): array {
        global $DB;

        // EVERY RECORD IS LISTED, WHATEVER BECAME OF ITS FRAMEWORK. The name came from a JOIN on the core's
        // table, an inner one: a framework deleted in tool_lp - the core allows it while nobody has declared
        // on it - took its record off this screen without a word, the one screen that counts what still holds
        // a record. And the join named a core table outside the gateway, which the guard script of MC-5 does
        // not see in an SQL string. The name is asked of the gateway; a record without a framework shows its
        // code alone.
        $sources = $DB->get_records_sql(
            "SELECT s.id, s.fichecode, s.frameworkid, s.sourceupdated, s.successorcode
               FROM {tool_vae_source} s
           ORDER BY s.fichecode ASC"
        );
        $gateway = new competency_gateway();
        foreach ($sources as $source) {
            $source->label = (string) $gateway->framework_name((int) $source->frameworkid);
        }
        $held = $DB->get_records_sql(
            "SELECT e.fichecode, COUNT(1) AS declarations
               FROM {tool_vae_evidence} e
           GROUP BY e.fichecode"
        );
        // A candidate counts once per record, whether they declared on it or wrote its preparation of the validation dossier
        // (T-11): the sections stay on the replaced record, and the administrator should know whom that concerns.
        $holders = $DB->get_records_sql(
            "SELECT u.fichecode, COUNT(DISTINCT u.candidateid) AS candidates
               FROM (SELECT e.fichecode, e.candidateid FROM {tool_vae_evidence} e
                      UNION
                     SELECT d.fichecode, d.candidateid FROM {tool_vae_draft} d WHERE d.doctype = :doctype) u
           GROUP BY u.fichecode",
            ['doctype' => draft_manager::DOCTYPE_VALIDATION]
        );
        $carried = $DB->get_records_sql(
            "SELECT e.fichecode, COUNT(1) AS carried
               FROM {tool_vae_carry} c
               JOIN {tool_vae_evidence} e ON e.id = c.oldevidenceid
           GROUP BY e.fichecode"
        );
        $gone = $DB->get_records_sql(
            "SELECT o.sourceid, COUNT(1) AS gone
               FROM {tool_vae_obsolete} o
              WHERE o.kind = :kind
           GROUP BY o.sourceid",
            ['kind' => importer::GONE]
        );

        $labels = [];
        foreach ($sources as $source) {
            $labels[$source->fichecode] = $source->label;
        }

        $out = [];
        foreach ($sources as $source) {
            $successor = (string) $source->successorcode;
            $out[$source->fichecode] = (object) [
                'fichecode' => $source->fichecode,
                'label' => $source->label,
                'sourceupdated' => $source->sourceupdated === null ? null : (int) $source->sourceupdated,
                'successorcode' => $successor,
                'successorlabel' => $labels[$successor] ?? '',
                'declarations' => isset($held[$source->fichecode]) ? (int) $held[$source->fichecode]->declarations : 0,
                'candidates' => isset($holders[$source->fichecode]) ? (int) $holders[$source->fichecode]->candidates : 0,
                'carried' => isset($carried[$source->fichecode]) ? (int) $carried[$source->fichecode]->carried : 0,
                'gone' => isset($gone[$source->id]) ? (int) $gone[$source->id]->gone : 0,
            ];
        }

        return $out;
    }

    /**
     * The record that replaces this one, if one was declared.
     *
     * @param string $fichecode
     * @return string|null
     */
    private static function successor_of(string $fichecode): ?string {
        global $DB;

        $code = $DB->get_field('tool_vae_source', 'successorcode', ['fichecode' => $fichecode]);

        return $code === false || $code === null || $code === '' ? null : (string) $code;
    }

    /**
     * Write the successor of a source, and say so in the log.
     *
     * @param \stdClass $source A row of tool_vae_source.
     * @param string|null $successorcode
     */
    private static function write(\stdClass $source, ?string $successorcode): void {
        global $DB, $USER;

        $DB->update_record('tool_vae_source', (object) [
            'id' => $source->id,
            'successorcode' => $successorcode,
            'timemodified' => time(),
            'usermodified' => (int) $USER->id,
        ]);

        supersession_changed::create([
            'context' => \context_system::instance(),
            'objectid' => (int) $source->id,
            'other' => ['fichecode' => (string) $source->fichecode, 'successor' => (string) $successorcode],
        ])->trigger();
    }
}
