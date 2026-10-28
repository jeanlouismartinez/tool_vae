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
 * A gateway that cannot read the core at all.
 *
 * Not "the record is gone" - "I could not find out". The difference is the whole point: a
 * detector that renders a timeout as `missing` tells a candidate, in front of a panel, that
 * their evidence no longer exists. Adversarial review proved the plugin did exactly that
 * before the two cases were separated.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class unreadable_gateway extends competency_gateway {
    /**
     * Fail the way a database does, not the way a missing row does.
     *
     * @param int $userevidenceid
     * @return \stdClass|null
     */
    public function read_anchor(int $userevidenceid): ?\stdClass {
        throw new \dml_read_exception('Deliberate read failure, not a missing record.');
    }
}
