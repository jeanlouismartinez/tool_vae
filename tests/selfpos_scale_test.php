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

use tool_vae\local\selfpos_scale;

/**
 * The scale of the self-positioning of the vaeiste (lot 1a): four steps, the same for the whole site, renamable.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(selfpos_scale::class)]
final class selfpos_scale_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_the_default_labels_come_from_the_language_pack(): void {
        $expected = [];
        for ($level = 1; $level <= 4; $level++) {
            $expected[$level] = get_string('selfposlabeldefault' . $level, 'tool_vae');
        }

        $this->assertSame(4, selfpos_scale::COUNT);
        $this->assertSame($expected, selfpos_scale::labels());
        $this->assertSame($expected[3], selfpos_scale::label(3));
        $this->assertNotSame($expected[1], $expected[4], 'Four different labels.');
    }

    public function test_a_label_set_by_the_administrator_replaces_the_default(): void {
        set_config('selfposlabel2', 'Je pratique en etant accompagne', 'tool_vae');

        $labels = selfpos_scale::labels();

        $this->assertSame('Je pratique en etant accompagne', $labels[2]);
        $this->assertSame(get_string('selfposlabeldefault1', 'tool_vae'), $labels[1]);
        $this->assertSame(get_string('selfposlabeldefault4', 'tool_vae'), $labels[4]);
    }

    public function test_an_empty_setting_falls_back_to_the_default(): void {
        set_config('selfposlabel3', '   ', 'tool_vae');

        $this->assertSame(get_string('selfposlabeldefault3', 'tool_vae'), selfpos_scale::label(3));
    }

    public function test_a_label_longer_than_one_hundred_characters_is_cut(): void {
        set_config('selfposlabel1', str_repeat('é', 150), 'tool_vae');

        $this->assertSame(str_repeat('é', 100), selfpos_scale::label(1));
        $this->assertSame(100, \core_text::strlen(selfpos_scale::labels()[1]));
    }

    public function test_the_withdrawal_has_its_own_label_and_no_other_index_exists(): void {
        $this->assertSame(get_string('selfposwithdrawn', 'tool_vae'), selfpos_scale::label(0));
        $this->assertArrayNotHasKey(0, selfpos_scale::labels(), 'The withdrawal is not a step of the scale.');

        $this->expectException(\coding_exception::class);
        selfpos_scale::label(5);
    }
}
