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
 * A record laid out at EVERY level, for the self-positioning of the vaeiste (lot 1a).
 *
 * WHY NOT outline OR positioning_rules::layout(). Both see two levels - a bloc and the competencies under it - because the
 * declaration and the positioning of the structure stop there. The vaeiste positions themselves on every node of the record,
 * the record itself included: a domain, a competency, a skill under it, whatever the depth the record has. So this class
 * walks the whole tree the core holds, and names each level as the framework names it (its taxonomy).
 *
 * THE FIRST NODE IS THE RECORD (rank '', level 1). A competency at the top of the record has level 2, one under it level 3,
 * and so on. A node whose idnumber does not read `<code>-<rank>` with a rank the import accepts is LISTED BUT NOT POSITIONABLE,
 * so that nothing of the record is hidden and no line can point at a node that no other screen can name.
 *
 * NOTHING IS COMPUTED. No count, no state, no level of anyone: where a node stands in the record, nothing more.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selfpos_tree {
    /**
     * The tree of an imported record, or null when the record is not imported.
     *
     * @param string $fichecode
     * @return \stdClass|null nodes: the list, in the order of the tree, each with rank, idnumber, label, level (from 1),
     *         levelname, parentrank, positionable and id (the competency id, 0 for the record itself); levels: level =>
     *         levelname, for the levels the tree has.
     * @throws \moodle_exception errorcompetenciesdisabled
     */
    public static function for_record(string $fichecode): ?\stdClass {
        global $DB;

        if (!competency_gateway::is_subsystem_enabled()) {
            throw new \moodle_exception('errorcompetenciesdisabled', 'tool_vae');
        }
        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        // A database that folds the case would find "rs00000" for "RS00000": the code is compared exactly.
        if (!$source || $source->fichecode !== $fichecode) {
            return null;
        }
        $gateway = new competency_gateway();
        $listed = $gateway->framework_tree((int) $source->frameworkid);
        $taxonomies = $gateway->framework_taxonomies((int) $source->frameworkid);

        // A competency whose parent is not in the list stands at the top, as outline::group() has it.
        $children = [];
        foreach ($listed as $competency) {
            $parentid = isset($listed[(int) $competency->parentid]) ? (int) $competency->parentid : 0;
            $children[$parentid][] = $competency;
        }
        $compare = fn($a, $b) => strnatcmp((string) $a->idnumber, (string) $b->idnumber);
        foreach ($children as &$siblings) {
            usort($siblings, $compare);
        }
        unset($siblings);

        $levels = [1 => get_string('selfposrecordlevel', 'tool_vae')];
        // The record is told by the name its framework carries (the import screen names it so); the code when it has none.
        $name = trim((string) $gateway->framework_name((int) $source->frameworkid));
        $nodes = [(object) [
            'rank' => '',
            'idnumber' => $fichecode,
            'label' => $name !== '' ? $name : $fichecode,
            'level' => 1,
            'levelname' => $levels[1],
            'parentrank' => '',
            'positionable' => true,
            'id' => 0,
        ]];
        self::walk($children, $taxonomies, $fichecode, 0, 2, '', $nodes, $levels);

        return (object) ['nodes' => $nodes, 'levels' => $levels];
    }

    /**
     * Add the nodes under one parent, then under each of them, in the order of the tree.
     *
     * @param array $children Parent competency id (0 for the top) => its competencies, sorted.
     * @param string[] $taxonomies Level of competency (from 1) => the name the framework gives it.
     * @param string $fichecode
     * @param int $parentid
     * @param int $level The level of the nodes under this parent, the record being 1.
     * @param string $parentrank The rank of the parent, '' for the record.
     * @param \stdClass[] $nodes The list being built.
     * @param string[] $levels Level => name, completed as levels are met.
     */
    private static function walk(
        array $children,
        array $taxonomies,
        string $fichecode,
        int $parentid,
        int $level,
        string $parentrank,
        array &$nodes,
        array &$levels
    ): void {
        foreach ($children[$parentid] ?? [] as $competency) {
            if (!isset($levels[$level])) {
                // The core numbers the top level of competency 1; here the record is level 1 and that level is 2.
                $levels[$level] = $taxonomies[$level - 1] ?? get_string('selfposlevelname', 'tool_vae', $level);
            }
            $rank = passage_manager::rank_of((string) $competency->idnumber, $fichecode);
            $positionable = $rank !== '' && preg_match(importer::RANK_PATTERN, $rank) === 1;
            // A node with no rank is told by its idnumber, so that two of them are not told apart by nothing.
            $told = $positionable ? $rank : (string) $competency->idnumber;
            $nodes[] = (object) [
                'rank' => $told,
                'idnumber' => (string) $competency->idnumber,
                'label' => (string) $competency->label,
                'level' => $level,
                'levelname' => $levels[$level],
                'parentrank' => $parentrank,
                'positionable' => $positionable,
                'id' => (int) $competency->id,
            ];
            self::walk($children, $taxonomies, $fichecode, (int) $competency->id, $level + 1, $told, $nodes, $levels);
        }
    }

    /**
     * One node of a tree, by its rank; null when the tree has none of that rank.
     *
     * A positionable node wins: a node told by its idnumber (no rank of its own) may bear the same word as the rank of a
     * positionable node (an idnumber "C01" added by hand next to "RNCP100-C01"), and only the positionable one can be the
     * target of a line, a form or a restored line.
     *
     * @param \stdClass $tree As for_record() gives it.
     * @param string $rank
     * @return \stdClass|null
     */
    public static function node(\stdClass $tree, string $rank): ?\stdClass {
        $told = null;
        foreach ($tree->nodes as $node) {
            if ($node->rank !== $rank) {
                continue;
            }
            if ($node->positionable) {
                return $node;
            }
            $told = $told ?? $node;
        }

        return $told;
    }
}
