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
 * A date as a person reads it, in the language of the screen or of the archive (T-57).
 *
 * Moodle's French date format writes "1 septembre 2026"; a French reader writes "1er septembre".
 * Only the first of the month takes an ordinal, and only in French - an English "1 September" is
 * left to the language pack, which knows its own conventions better than this plugin does.
 *
 * The language tested is the current one, so an archive built under force_current_language()
 * (T-52) is dated in the candidate's language, ordinal included.
 *
 * THE TIMEZONE FOLLOWS THE LANGUAGE. A period is stored at midnight of the timezone of the person who
 * typed it (a date selector), so the day it names is that person's day: read in a timezone further west
 * - the one of whoever presses the button, or of the site for the weekly copy - it falls on the day
 * before. An archive and a copy are the candidate's, and say the same thing to everyone: built under
 * force_timezone(), the candidate's own, exactly as under force_current_language(). A date asked for
 * with a timezone of its own (a day stored at noon UTC) keeps it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class display_date {
    /** @var string|null The timezone every date asked for with the reader's own is written in, while one is forced. */
    private static $forced = null;

    /**
     * Write the dates asked for with the reader's own timezone in another one, until it is given back.
     *
     * Used the way force_current_language() is: the caller keeps what this returns and gives it back in a
     * finally block, so that the reader's own timezone is never left behind.
     *
     * @param string|null $timezone A timezone name, such as core_date::get_user_timezone() gives; null to stop forcing.
     * @return string|null The timezone that was forced before, null when none was.
     */
    public static function force_timezone(?string $timezone): ?string {
        $previous = self::$forced;
        self::$forced = $timezone === '' ? null : $timezone;

        return $previous;
    }

    /**
     * Same arguments as userdate(), with the French ordinal for the first of the month.
     *
     * @param int $time A timestamp.
     * @param string $format A strftime format, usually a langconfig string.
     * @param string|float $timezone A Moodle timezone; 99, the default, is the reader's own - or the one force_timezone() set.
     *        A day with no time of day (the day an administrator checked a page) is shown in UTC, where it was stored, so
     *        that it reads the same everywhere.
     * @return string
     */
    public static function format(int $time, string $format, $timezone = 99): string {
        if ((string) $timezone === '99' && self::$forced !== null) {
            $timezone = self::$forced;
        }

        return self::ordinal(userdate($time, $format, $timezone), current_language());
    }

    /**
     * Put "1er" in place of a leading "1" (or "01") that is followed by a month name.
     *
     * Pure, so it can be tested without a Moodle instance.
     *
     * @param string $formatted A date already formatted by userdate().
     * @param string $language The language code the date was formatted in.
     * @return string
     */
    public static function ordinal(string $formatted, string $language): string {
        if (strncasecmp($language, 'fr', 2) !== 0) {
            return $formatted;
        }
        return preg_replace('/^0?1(?= \p{L})/u', '1er', $formatted, 1) ?? $formatted;
    }
}
