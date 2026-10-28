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
 * Which imported records have lost their competency framework.
 *
 * tool_vae_source.frameworkid names a framework of the core by a foreign key the database never enforces. Two
 * things leave it pointing at nothing: an administrator who deletes the framework in tool_lp - the core allows
 * it as long as nobody has declared on it - and a first import that died after its provenance row was written,
 * which leaves frameworkid at 0. The screens that read the competencies of a record now answer an empty list
 * for both (competency_gateway::list_framework_competencies()), which keeps every other record usable; this
 * class is what lets the administration screen SAY so, once, in words, with the way out: import the record again.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class source_frameworks {
    /**
     * The records whose framework the core no longer holds.
     *
     * @param \stdClass[] $sources Rows of tool_vae_source.
     * @param competency_gateway|null $gateway Only passed by tests.
     * @return string[] Their codes, in the order of $sources.
     */
    public static function missing(array $sources, ?competency_gateway $gateway = null): array {
        $gateway = $gateway ?? new competency_gateway();

        $codes = [];
        foreach ($sources as $source) {
            if (!$gateway->framework_exists((int) $source->frameworkid)) {
                $codes[] = (string) $source->fichecode;
            }
        }

        return $codes;
    }
}
