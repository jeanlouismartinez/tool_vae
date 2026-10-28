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
 * A gateway that writes core evidence for real a given number of times, then fails.
 *
 * The shape of the risk on the reimport of a dossier: the first claim is written whole - its
 * core evidence, its link, this plugin's rows, its attestations - and the second dies. Set
 * `$after` to 1 and a test can look for the first claim, and must not find it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failing_evidence_gateway extends competency_gateway {
    /** @var int How many evidence records to create for real before failing. */
    private $after;

    /** @var int How many have been created so far. */
    private $done = 0;

    /**
     * Build a gateway that writes for real a given number of times, then fails.
     *
     * @param int $after Number of successful creations before the failure.
     */
    public function __construct(int $after) {
        $this->after = $after;
    }

    /**
     * Create the evidence for real $after times, then fail.
     *
     * @param int $candidateid
     * @param string $generatedlabel
     * @return int
     */
    public function create_evidence(int $candidateid, string $generatedlabel): int {
        if ($this->done < $this->after) {
            $this->done++;

            return parent::create_evidence($candidateid, $generatedlabel);
        }

        throw new \moodle_exception('error', 'moodle', '', null, 'Deliberate failure mid-import.');
    }
}
