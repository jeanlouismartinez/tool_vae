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

use tool_vae\local\text_diff;

/**
 * The comparison of two versions of a text (T-81, lot 2).
 *
 * THE INVARIANT, ASSERTED ON EVERY CASE: keeping the equal and the deleted text gives back the old
 * text, keeping the equal and the inserted gives the new one. A diff that shows plausible marks
 * and loses a word fails here, not on a reader's screen.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\text_diff::class)]
final class text_diff_test extends \advanced_testcase {
    /**
     * Rebuild both texts from the operations and compare them to the originals.
     *
     * @param string $old
     * @param string $new
     * @return array[] The operations.
     */
    private function check(string $old, string $new): array {
        $ops = text_diff::diff($old, $new);

        $rebuiltold = '';
        $rebuiltnew = '';
        foreach ($ops as $op) {
            if ($op['op'] !== text_diff::INSERT) {
                $rebuiltold .= $op['text'];
            }
            if ($op['op'] !== text_diff::DELETE) {
                $rebuiltnew .= $op['text'];
            }
        }
        $lf = fn(string $t) => str_replace(["\r\n", "\r"], "\n", $t);
        $this->assertSame($lf($old), $rebuiltold, 'The old text is rebuilt from the operations.');
        $this->assertSame($lf($new), $rebuiltnew, 'The new text is rebuilt from the operations.');

        return $ops;
    }

    public function test_identical_texts_have_no_change(): void {
        $ops = $this->check('Le même texte.', 'Le même texte.');

        $this->assertSame([['op' => text_diff::EQUAL, 'text' => 'Le même texte.']], $ops);
        $this->assertSame(['added' => 0, 'removed' => 0], text_diff::counts($ops));
    }

    public function test_two_empty_texts_have_no_operation(): void {
        $this->assertSame([], text_diff::diff('', ''));
    }

    public function test_a_word_added_at_the_start(): void {
        $ops = $this->check('accompagne les résidents', 'Je accompagne les résidents');

        $this->assertSame(['added' => 1, 'removed' => 0], text_diff::counts($ops), 'Only Je is added.');
    }

    public function test_a_word_removed_at_the_end(): void {
        $ops = $this->check('Je transmets à l\'infirmière chaque soir', 'Je transmets à l\'infirmière');

        $this->assertSame(['added' => 0, 'removed' => 2], text_diff::counts($ops));
    }

    public function test_a_word_replaced_in_the_middle(): void {
        $ops = $this->check('Je prépare le matériel avant la toilette', 'Je range le matériel avant la toilette');

        $this->assertSame(['added' => 1, 'removed' => 1], text_diff::counts($ops));
        $this->assertSame(text_diff::EQUAL, $ops[0]['op']);
    }

    public function test_an_empty_old_text_is_all_insertion(): void {
        $ops = $this->check('', 'Premier jet.');

        $this->assertSame([['op' => text_diff::INSERT, 'text' => 'Premier jet.']], $ops);
        $this->assertSame(['added' => 2, 'removed' => 0], text_diff::counts($ops));
    }

    public function test_an_empty_new_text_is_all_deletion(): void {
        $ops = $this->check('Premier jet.', '');

        $this->assertSame([['op' => text_diff::DELETE, 'text' => 'Premier jet.']], $ops);
    }

    public function test_accents_and_apostrophes_are_words(): void {
        $this->check('l\'équipe de nuit étaient présentes', 'l\'équipe du matin était présente');
    }

    public function test_line_breaks_are_kept(): void {
        $ops = $this->check("Première ligne\nDeuxième ligne", "Première ligne\nTroisième ligne\nDeuxième ligne");

        $this->assertGreaterThan(0, text_diff::counts($ops)['added']);
    }

    public function test_crlf_and_lf_are_the_same_text(): void {
        $ops = $this->check("a\r\nb", "a\nb");

        $this->assertSame([['op' => text_diff::EQUAL, 'text' => "a\nb"]], $ops);
    }

    public function test_a_moved_sentence_is_a_deletion_and_an_insertion(): void {
        $ops = $this->check('Un. Deux. Trois.', 'Trois. Un. Deux.');

        $this->assertNotSame([], array_filter($ops, fn($o) => $o['op'] !== text_diff::EQUAL));
    }

    public function test_a_long_text_with_one_change_stays_cheap_and_exact(): void {
        $base = implode(' ', array_map(fn($i) => 'mot' . $i, range(1, 6000)));
        $changed = str_replace('mot3000', 'autre', $base);

        $ops = $this->check($base, $changed);

        $this->assertSame(['added' => 1, 'removed' => 1], text_diff::counts($ops));
    }

    public function test_beyond_the_bound_the_comparison_falls_back_and_stays_exact(): void {
        // Every line differs and every line is long: the word table is over the bound, the line table
        // is not. The invariant still holds.
        $old = [];
        $new = [];
        for ($i = 0; $i < 60; $i++) {
            $old[] = implode(' ', array_map(fn($j) => 'a' . $i . '_' . $j, range(1, 80)));
            $new[] = implode(' ', array_map(fn($j) => 'b' . $i . '_' . $j, range(1, 80)));
        }

        $this->check(implode("\n", $old), implode("\n", $new));
    }

    public function test_the_html_marks_additions_and_removals_without_colour(): void {
        $ops = text_diff::diff('Je prépare', 'Je range');
        $html = text_diff::html($ops);

        $this->assertStringContainsString('<ins class="tool-vae-ins">', $html);
        $this->assertStringContainsString('<del class="tool-vae-del">', $html);
        $this->assertStringContainsString('sr-only', $html, 'A screen reader is told what was added and removed.');
        $this->assertStringContainsString(get_string('diffadded', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('diffremoved', 'tool_vae'), $html);
    }

    public function test_the_html_escapes_the_text(): void {
        $html = text_diff::html(text_diff::diff('a', 'a <script>alert(1)</script>'));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
