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
 * The competencies of a record, laid out by bloc (T-33).
 *
 * ONE PLACE FOR THE REGROUPING. The declaration list, the coverage and the archive all present a record by bloc;
 * each reads it from here instead of rebuilding the tree its own way.
 *
 * TWO LEVELS, SORTED BY RANK. A bloc is a competency that others sit under; a competency with no parent and no
 * children is a node on its own, so a flat record gives one node per competency. Order is the natural order of
 * the idnumber, as the coverage and the archive sort - never the core's `sortorder`: a move made by an import
 * puts a competency last in its new bloc, and the file's order is not kept there.
 *
 * NOTHING IS COMPUTED. No count, no state: this class says where a competency stands, not how it fares.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class outline {
    /**
     * Lay competencies out as blocs and the competencies under them.
     *
     * @param \stdClass[] $competencies As competency_gateway::list_framework_competencies() gives them, each with
     *        id, idnumber and parentid.
     * @return \stdClass[] In order, each with `competency` and `children` (competencies, in order; empty for a
     *         competency that is not a bloc). A competency whose parent is not in the list stands as a node.
     */
    public static function group(array $competencies): array {
        $byid = [];
        foreach ($competencies as $competency) {
            $byid[(int) $competency->id] = $competency;
        }

        $nodes = [];
        $children = [];
        foreach ($competencies as $competency) {
            $parentid = (int) ($competency->parentid ?? 0);
            if ($parentid > 0 && isset($byid[$parentid])) {
                $children[$parentid][] = $competency;
            } else {
                $nodes[] = $competency;
            }
        }

        $compare = fn($a, $b) => strnatcmp((string) $a->idnumber, (string) $b->idnumber);
        usort($nodes, $compare);

        $out = [];
        foreach ($nodes as $competency) {
            // A tree made by hand in tool_lp can go deeper than two levels: what hangs lower is shown with the
            // competencies of its bloc, so that none is lost from the list to declare in.
            $under = [];
            $queue = $children[(int) $competency->id] ?? [];
            while ($queue) {
                $next = array_shift($queue);
                $under[] = $next;
                array_push($queue, ...($children[(int) $next->id] ?? []));
            }
            usort($under, $compare);
            $out[] = (object) ['competency' => $competency, 'children' => $under];
        }

        return $out;
    }

    /**
     * Whether any competency of an outline is a bloc.
     *
     * @param \stdClass[] $nodes What group() returns.
     * @return bool
     */
    public static function has_blocs(array $nodes): bool {
        foreach ($nodes as $node) {
            if ($node->children) {
                return true;
            }
        }

        return false;
    }
}
