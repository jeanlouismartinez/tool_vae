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
 * The states of a declaration, their legend, and the chronology of its acts (T-40).
 *
 * WHAT IS ASSERTED. That the state is read from the attestations alone and says what was done, never
 * how good it is; that the chronology puts the acts in order of date, carries no name and no text; and
 * that every state and act has a string in both languages the plugin ships.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\presenter::class)]
final class presenter_state_test extends \advanced_testcase {
    /**
     * An attestation row, as much of it as the presenter reads.
     *
     * @param int|null $attestedat
     * @param int|null $withdrawnat
     * @return \stdClass
     */
    private static function attestation(?int $attestedat, ?int $withdrawnat = null): \stdClass {
        return (object) [
            'attestedat' => $attestedat,
            'timecreated' => $attestedat ?? 0,
            'withdrawnat' => $withdrawnat,
            'attestantrole' => 'Directrice',
            'statement' => 'Texte-Secret',
        ];
    }

    public function test_the_state_is_read_from_the_attestations(): void {
        $this->assertSame('declared', presenter::state([]));
        $this->assertSame('attested', presenter::state([self::attestation(100)]));
        $this->assertSame('withdrawn', presenter::state([self::attestation(100, 200)]));
        // Withdrawn, then attested again: the one in force decides.
        $this->assertSame('attested', presenter::state([self::attestation(100, 200), self::attestation(300)]));
    }

    public function test_every_state_and_act_has_a_string_in_both_languages(): void {
        $this->resetAfterTest();
        $strings = get_string_manager();
        $keys = ['statelegendheading', 'chronologyheading', 'state:questionopen', 'state:questionopen_help'];
        foreach (presenter::STATES as $state) {
            $keys[] = 'state:' . $state;
            $keys[] = 'state:' . $state . '_help';
        }
        foreach (['declared', 'questionasked', 'questionanswered', 'attested', 'withdrawn'] as $act) {
            $keys[] = 'chrono:' . $act;
        }
        foreach (['en', 'fr'] as $lang) {
            foreach ($keys as $key) {
                $this->assertTrue($strings->string_exists($key, 'tool_vae'), "Missing string $key");
                $this->assertNotSame('[[' . $key . ']]', $strings->get_string($key, 'tool_vae', null, $lang));
            }
        }
    }

    public function test_the_chronology_is_in_order_and_carries_no_name_and_no_text(): void {
        $evidence = (object) ['timecreated' => 100];
        $attestations = [self::attestation(400, 600), self::attestation(700)];
        $requests = [
            (object) ['timecreated' => 200, 'timeanswered' => 300, 'question' => 'Texte-Secret'],
            (object) ['timecreated' => 500, 'timeanswered' => null, 'question' => 'Texte-Secret'],
        ];

        $acts = presenter::chronology($evidence, $attestations, $requests);

        $this->assertSame(
            ['declared', 'questionasked', 'questionanswered', 'attested', 'questionasked', 'withdrawn', 'attested'],
            array_column($acts, 'act')
        );
        $this->assertSame([100, 200, 300, 400, 500, 600, 700], array_column($acts, 'time'));

        $this->resetAfterTest();
        $html = presenter::chronology_html($evidence, $attestations, $requests);
        $this->assertStringNotContainsString('Texte-Secret', $html);
        $this->assertStringNotContainsString('Directrice', $html);
    }

    public function test_a_carry_is_an_act_of_the_chronology_in_its_place(): void {
        $this->resetAfterTest();
        $evidence = (object) ['timecreated' => 100];
        $carries = [['time' => 300, 'act' => 'carriedto']];

        $acts = presenter::chronology($evidence, [self::attestation(200), self::attestation(400)], [], $carries);

        $this->assertSame(['declared', 'attested', 'carriedto', 'attested'], array_column($acts, 'act'));
        $this->assertStringContainsString(
            get_string('chrono:carriedto', 'tool_vae'),
            presenter::chronology_html($evidence, [], [], $carries)
        );
        foreach (['en', 'fr'] as $lang) {
            foreach (['chrono:carriedto', 'chrono:carriedfrom'] as $key) {
                $this->assertNotSame(
                    '[[' . $key . ']]',
                    get_string_manager()->get_string($key, 'tool_vae', null, $lang)
                );
            }
        }
    }

    public function test_an_open_question_is_said_next_to_the_state_and_not_in_its_place(): void {
        $this->resetAfterTest();
        $without = presenter::state_label([], 0);
        $with = presenter::state_label([], 2);

        $this->assertStringContainsString(get_string('state:declared', 'tool_vae'), $with);
        $this->assertStringContainsString(get_string('state:questionopen', 'tool_vae'), $with);
        $this->assertStringNotContainsString(get_string('state:questionopen', 'tool_vae'), $without);
    }
}
