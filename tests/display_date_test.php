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

use tool_vae\local\display_date;

/**
 * T-57: the first of the month takes "er" in French and nowhere else.
 *
 * Found on a real Moodle 5.3 on 13 October 2026: "1 septembre" where a French reader writes
 * "1er septembre". The rule is tested on the pure string function, so it needs neither a
 * database nor an installed language pack.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(display_date::class)]
final class display_date_test extends \basic_testcase {
    /**
     * Dates a French reader sees, and what they should become.
     *
     * @return array
     */
    public static function french_provider(): array {
        return [
            'first of the month' => ['1 septembre 2026', '1er septembre 2026'],
            'first, zero padded' => ['01 septembre 2026', '1er septembre 2026'],
            'first with a time' => ['1 septembre 2026 à 10:00', '1er septembre 2026 à 10:00'],
            'first of a month with an accent' => ['1 août 2026', '1er août 2026'],
            'second is left alone' => ['2 septembre 2026', '2 septembre 2026'],
            'eleventh is not a first' => ['11 septembre 2026', '11 septembre 2026'],
            'twenty-first is not a first' => ['21 septembre 2026', '21 septembre 2026'],
            'a numeric date is left alone' => ['01/09/2026', '01/09/2026'],
            'a year alone is left alone' => ['2026', '2026'],
            'already written' => ['1er septembre 2026', '1er septembre 2026'],
        ];
    }

    /**
     * The first of the month takes "er" in French, no other day does.
     *
     * @param string $formatted
     * @param string $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('french_provider')]
    public function test_french_dates_take_the_ordinal_on_the_first_only(string $formatted, string $expected): void {
        $this->assertSame($expected, display_date::ordinal($formatted, 'fr'));
    }

    /**
     * Regional French packs begin with the same two letters and read the same way.
     */
    public function test_regional_french_is_french(): void {
        $this->assertSame('1er mars 2026', display_date::ordinal('1 mars 2026', 'fr_ca'));
    }

    /**
     * Other languages are left to their language pack.
     */
    public function test_other_languages_are_left_alone(): void {
        $this->assertSame('1 September 2026', display_date::ordinal('1 September 2026', 'en'));
        $this->assertSame('1 septiembre 2026', display_date::ordinal('1 septiembre 2026', 'es'));
    }
}
