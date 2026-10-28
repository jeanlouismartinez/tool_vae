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

namespace tool_vae;

use tool_vae\local\presenter;

/**
 * T-60: the button after a withdrawal says it is a new attestation.
 *
 * Found on a real Moodle 5.3 on 13 October 2026. A declaration whose attestation had been
 * withdrawn offered the same button as one never attested, and a manager could take it for the
 * old attestation being restored. It is not: it is a new one, written afresh.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(presenter::class)]
final class presenter_attest_label_test extends \advanced_testcase {
    /**
     * A declaration nobody attested offers the plain button.
     */
    public function test_a_declaration_never_attested_offers_the_plain_button(): void {
        $this->assertSame(get_string('attest', 'tool_vae'), presenter::attest_label([]));
    }

    /**
     * A declaration whose attestation was withdrawn says "again".
     */
    public function test_a_declaration_whose_attestation_was_withdrawn_offers_a_new_one(): void {
        $withdrawn = (object) ['id' => 1, 'withdrawnat' => 1760000000];

        $this->assertSame(get_string('attestagain', 'tool_vae'), presenter::attest_label([$withdrawn]));
        $this->assertNotSame(get_string('attest', 'tool_vae'), presenter::attest_label([$withdrawn]));
    }
}
