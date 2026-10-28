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
 * The difference between two texts, word by word (T-81, lot 2).
 *
 * NO DEPENDENCY, ON PURPOSE. `sebastian/diff` is a dependency of the test tools and is absent from
 * a production install, and `mod_wiki`'s difflib lives in a module a site may not have. A page that
 * compares two versions of a candidate's text cannot depend on either.
 *
 * HOW. The two texts are cut into tokens - words, runs of white space, one punctuation mark each -
 * and the longest common subsequence of the tokens gives what stayed, what was added and what was
 * removed. The common beginning and end are taken off first, which is nearly the whole text in the
 * ordinary case, a few edits in a long document.
 *
 * BOUNDED. The table of the subsequence is the product of the two lengths. Past MAXCELLS cells the
 * comparison falls back to lines, then to the two texts as wholes: coarser, never absent, and never
 * a request that exhausts the server's memory because someone pasted a book.
 *
 * PURE. Nothing here reads the database or a session, so every case is a test on a string.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class text_diff {
    /** @var string An operation: the text is in both. */
    public const EQUAL = 'equal';

    /** @var string An operation: the text is only in the new one. */
    public const INSERT = 'insert';

    /** @var string An operation: the text is only in the old one. */
    public const DELETE = 'delete';

    /** @var int Cells of the table of the subsequence, beyond which the comparison is coarser. */
    public const MAXCELLS = 4000000;

    /**
     * Compare two texts.
     *
     * @param string $old The earlier text.
     * @param string $new The later text.
     * @return array[] A list of ['op' => EQUAL|INSERT|DELETE, 'text' => string], adjacent operations
     *         of one kind merged. Concatenating EQUAL and DELETE gives $old, EQUAL and INSERT $new.
     */
    public static function diff(string $old, string $new): array {
        $old = self::lf($old);
        $new = self::lf($new);

        if ($old === $new) {
            return $old === '' ? [] : [['op' => self::EQUAL, 'text' => $old]];
        }

        $a = self::words($old);
        $b = self::words($new);
        $ops = self::compare($a, $b);

        if ($ops === null) {
            $ops = self::compare(self::lines($old), self::lines($new));
        }
        if ($ops === null) {
            $ops = [];
            if ($old !== '') {
                $ops[] = [self::DELETE, $old];
            }
            if ($new !== '') {
                $ops[] = [self::INSERT, $new];
            }
        }

        return self::merge($ops);
    }

    /**
     * How many words went in and out.
     *
     * @param array[] $ops As diff() returns them.
     * @return array{added: int, removed: int}
     */
    public static function counts(array $ops): array {
        $added = 0;
        $removed = 0;
        foreach ($ops as $op) {
            if ($op['op'] === self::EQUAL) {
                continue;
            }
            $n = preg_match_all('/[\p{L}\p{N}]+/u', $op['text']);
            if ($op['op'] === self::INSERT) {
                $added += $n;
            } else {
                $removed += $n;
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * The comparison as HTML: what was added underlined, what was removed struck through.
     *
     * NEVER COLOUR ALONE. Each run carries its own words for a screen reader - "added:" and
     * "removed:" - and a mark a sighted reader sees without any colour. The text is escaped.
     *
     * @param array[] $ops As diff() returns them.
     * @return string
     */
    public static function html(array $ops): string {
        $html = '';
        foreach ($ops as $op) {
            $text = s($op['text']);
            if ($op['op'] === self::EQUAL) {
                $html .= $text;
            } else if ($op['op'] === self::INSERT) {
                $html .= '<ins class="tool-vae-ins"><span class="sr-only">'
                    . s(get_string('diffadded', 'tool_vae')) . '</span>' . $text . '</ins>';
            } else {
                $html .= '<del class="tool-vae-del"><span class="sr-only">'
                    . s(get_string('diffremoved', 'tool_vae')) . '</span>' . $text . '</del>';
            }
        }

        return $html;
    }

    /**
     * Line endings as one.
     *
     * @param string $text
     * @return string
     */
    private static function lf(string $text): string {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * Cut a text into words, runs of white space and single punctuation marks.
     *
     * @param string $text
     * @return string[]
     */
    private static function words(string $text): array {
        preg_match_all('/\s+|[\p{L}\p{N}_]+|[^\s\p{L}\p{N}_]/u', $text, $m);

        return $m[0];
    }

    /**
     * Cut a text into lines, each keeping its line break.
     *
     * @param string $text
     * @return string[]
     */
    private static function lines(string $text): array {
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [$text] : $parts;
    }

    /**
     * The operations between two lists of tokens, or null when the table would be too large.
     *
     * @param string[] $a
     * @param string[] $b
     * @return array[]|null Pairs [op, text].
     */
    private static function compare(array $a, array $b): ?array {
        $na = count($a);
        $nb = count($b);

        $start = 0;
        while ($start < $na && $start < $nb && $a[$start] === $b[$start]) {
            $start++;
        }
        $end = 0;
        while ($end < $na - $start && $end < $nb - $start && $a[$na - 1 - $end] === $b[$nb - 1 - $end]) {
            $end++;
        }

        $ma = $na - $start - $end;
        $mb = $nb - $start - $end;
        if (($ma + 1) * ($mb + 1) > self::MAXCELLS) {
            return null;
        }

        $ops = [];
        for ($i = 0; $i < $start; $i++) {
            $ops[] = [self::EQUAL, $a[$i]];
        }

        // Table of the longest common subsequence of the middles, built from the end so that the
        // walk below goes forward and the output is in reading order.
        $width = $mb + 1;
        $table = new \SplFixedArray(($ma + 1) * $width);
        for ($i = $ma - 1; $i >= 0; $i--) {
            for ($j = $mb - 1; $j >= 0; $j--) {
                if ($a[$start + $i] === $b[$start + $j]) {
                    $table[$i * $width + $j] = $table[($i + 1) * $width + $j + 1] + 1;
                } else {
                    $down = $table[($i + 1) * $width + $j];
                    $right = $table[$i * $width + $j + 1];
                    $table[$i * $width + $j] = $down >= $right ? $down : $right;
                }
            }
        }

        $i = 0;
        $j = 0;
        while ($i < $ma && $j < $mb) {
            if ($a[$start + $i] === $b[$start + $j]) {
                $ops[] = [self::EQUAL, $a[$start + $i]];
                $i++;
                $j++;
            } else if ($table[($i + 1) * $width + $j] >= $table[$i * $width + $j + 1]) {
                $ops[] = [self::DELETE, $a[$start + $i]];
                $i++;
            } else {
                $ops[] = [self::INSERT, $b[$start + $j]];
                $j++;
            }
        }
        for (; $i < $ma; $i++) {
            $ops[] = [self::DELETE, $a[$start + $i]];
        }
        for (; $j < $mb; $j++) {
            $ops[] = [self::INSERT, $b[$start + $j]];
        }

        for ($k = $na - $end; $k < $na; $k++) {
            $ops[] = [self::EQUAL, $a[$k]];
        }

        return $ops;
    }

    /**
     * Join adjacent operations of the same kind, and put each deletion before its insertion.
     *
     * @param array[] $ops Pairs [op, text].
     * @return array[]
     */
    private static function merge(array $ops): array {
        $runs = [];
        foreach ($ops as [$op, $text]) {
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['op'] === $op) {
                $runs[$last]['text'] .= $text;
            } else {
                $runs[] = ['op' => $op, 'text' => $text];
            }
        }

        // An insertion followed by a deletion reads better the other way round: what went, then
        // what came. Swap them when they sit together.
        $count = count($runs);
        for ($k = 0; $k < $count - 1; $k++) {
            if ($runs[$k]['op'] === self::INSERT && $runs[$k + 1]['op'] === self::DELETE) {
                [$runs[$k], $runs[$k + 1]] = [$runs[$k + 1], $runs[$k]];
            }
        }

        return $runs;
    }
}
