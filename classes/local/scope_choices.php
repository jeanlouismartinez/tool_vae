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
 * The choices of the page that says a competency is outside one's activity (T-42).
 *
 * WHY A CLASS OF ITS OWN. The labels of a select are written as they are by the form library, and what they are made of - a
 * competency's code, which a manager may rewrite by hand in the competencies screens of the core - is not HTML. Built in the
 * page, the line that escapes them could not be read by a test; built here, it can.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scope_choices {
    /**
     * The competencies of the imported records, by "code-rank".
     *
     * @return array [labels, parts]: the label to show by key (ready to be written as HTML), and [record code, rank] by key.
     *         What each key stands for is kept while the choices are built: a record code may hold a hyphen, so the key is
     *         never split.
     */
    public static function build(): array {
        global $DB;

        $options = [];
        $parts = [];
        if (!competency_gateway::is_subsystem_enabled()) {
            return [$options, $parts];
        }
        $gateway = new competency_gateway();
        foreach ($DB->get_records('tool_vae_source', null, 'fichecode ASC') as $source) {
            foreach ($gateway->list_framework_competencies((int) $source->frameworkid) as $competency) {
                $options[$competency->idnumber] = s($competency->idnumber . ' — ' . $competency->label);
                $parts[$competency->idnumber] = [$source->fichecode, substr($competency->idnumber, strlen($source->fichecode) + 1)];
            }
        }

        return [$options, $parts];
    }
}
