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
 * The scale the vaeiste positions themselves on (lot 1a): four steps, the same for the whole site.
 *
 * NOT A MARK. The steps are words, not numbers, and nothing is ever added, averaged or compared across them: the index is
 * kept only to tell the steps apart and to keep the line readable when the administrator renames one. The label a line was
 * written with is kept on the line (levellabel); this class gives the labels as they read NOW.
 *
 * The four default labels were PROPOSED BY THE DEVELOPMENT, not given by the holder of the scheme; the administrator renames
 * them in the settings. The index 0 is not a step: it withdraws the level the vaeiste had given.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selfpos_scale {
    /** @var int How many steps the scale has. */
    public const COUNT = 4;

    /** @var int The longest label, in characters: the column of the line holds that many. */
    public const LABEL_LENGTH = 100;

    /**
     * The labels of the steps as they read now: the administrator's, or the default of the language pack.
     *
     * @return string[] Index (1 to COUNT) => label.
     */
    public static function labels(): array {
        $out = [];
        for ($level = 1; $level <= self::COUNT; $level++) {
            $set = trim((string) get_config('tool_vae', 'selfposlabel' . $level));
            $label = $set === '' ? get_string('selfposlabeldefault' . $level, 'tool_vae') : $set;
            $out[$level] = \core_text::substr($label, 0, self::LABEL_LENGTH);
        }

        return $out;
    }

    /**
     * The label of one index: a step of the scale, or the withdrawal (0).
     *
     * @param int $level 0 to COUNT.
     * @return string
     * @throws \coding_exception For an index the scale does not have.
     */
    public static function label(int $level): string {
        if ($level === 0) {
            return \core_text::substr(get_string('selfposwithdrawn', 'tool_vae'), 0, self::LABEL_LENGTH);
        }
        $labels = self::labels();
        if (!isset($labels[$level])) {
            throw new \coding_exception('The scale has no step ' . $level . '.');
        }

        return $labels[$level];
    }
}
