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

namespace tool_vae\fixtures;

use tool_vae\local\competency_gateway;

/**
 * A gateway whose second core call fails.
 *
 * The first call runs for real, so core genuinely writes a user evidence record; the second
 * throws. That is the exact shape of the risk the adversarial review raised: a failure after
 * the core write, leaving an evidence record with no metadata, no period and no possible
 * attestation - invisible to this plugin and orphaned in tool_lp.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failing_gateway extends competency_gateway {
    /**
     * Fail after the core record exists.
     *
     * @param int $userevidenceid
     * @param int $competencyid
     */
    public function link_to_competency(int $userevidenceid, int $competencyid): void {
        throw new \moodle_exception('error', 'moodle', '', null, 'Deliberate failure after the core write.');
    }
}
