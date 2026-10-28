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
 * A gateway that fails once the import has already begun.
 *
 * The framework is created for real, then a competency throws. That is the shape of the risk:
 * an import that dies halfway must leave the provenance row saying it FAILED, with its date -
 * not an old date that reads as a record still up to date - and must leave NOTHING half written
 * behind it.
 *
 * `$after` is what lets the second question be asked at all. Failing on the very first
 * competency proves nothing about atomicity: there is nothing to roll back. Set it to 2 and the
 * gateway creates two competencies for real before throwing, which is exactly the state a test
 * has to be able to look for - and not find.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class failing_import_gateway extends competency_gateway {
    /** @var int How many competencies to create for real before failing. */
    private $after;

    /** @var int How many have been created so far. */
    private $done = 0;

    /**
     * Build a gateway that writes for real a given number of times, then fails.
     *
     * @param int $after Number of successful creations before the failure.
     */
    public function __construct(int $after = 0) {
        $this->after = $after;
    }

    /**
     * Fail after the framework exists, and after $after competencies have really been written.
     *
     * @param int $frameworkid
     * @param string $idnumber
     * @param string $shortname
     * @param string $description
     * @param int $parentid
     * @return int
     */
    public function create_competency(
        int $frameworkid,
        string $idnumber,
        string $shortname,
        string $description = '',
        int $parentid = 0
    ): int {
        if ($this->done < $this->after) {
            $this->done++;

            return parent::create_competency($frameworkid, $idnumber, $shortname, $description, $parentid);
        }

        throw new \moodle_exception('error', 'moodle', '', null, 'Deliberate failure mid-import.');
    }
}
