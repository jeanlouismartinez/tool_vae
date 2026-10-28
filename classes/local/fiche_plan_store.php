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
 * The plan kept between the preview and the confirmation of an import of records (0.47.0).
 *
 * WHAT IT IS. A small JSON file of the temporary directory of Moodle, named after its user and a token nobody can guess,
 * holding what the preview showed (the records converted, a few kilobytes) and, once the records are written, what became of
 * each. It is NEVER the archive, nor the files of the export: the archive is read once, in the request of the preview, and
 * forgotten. The confirmation writes what the preview showed, not what a second reading of a file that changed in the
 * meantime would give.
 *
 * WHY NOT THE SESSION. It is stored in the database and read on every page; ten kilobytes of plan would travel with every
 * request of the administrator for as long as the session lives. And a file lets the preview be an address that can be
 * reloaded, and the result another one: a double click, or a reload, shows what happened and imports nothing again.
 *
 * WHAT PROTECTS IT. The token is 128 bits from random_bytes() and is checked by its pattern before it touches a path; the file
 * name carries the id of the user and the file carries it again, and a plan is read back for that user only; it lives two
 * hours; it is written aside and renamed, so that nobody reads half of it; its size is capped; it holds JSON and never a
 * serialised object. The temporary directory of Moodle is shared by the servers of a cluster (the local request directory is
 * not); where it were not, the preview would expire with a word and nothing would be written.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fiche_plan_store {
    /** @var int How long a plan lives, in seconds: two hours. */
    public const TTL = 7200;

    /** @var int The largest plan kept: 2 MiB. A plan of twenty records is a few tens of kilobytes. */
    public const MAX_BYTES = 2097152;

    /** @var string What a token looks like: 32 hexadecimal digits. */
    public const TOKEN_PATTERN = '/^[0-9a-f]{32}$/D';

    /** @var string The directory of the plans, in the temporary directory of Moodle. */
    private const DIRECTORY = 'tool_vae/fiche_import';

    /**
     * Keep a plan.
     *
     * The plans of the same user that have expired are cleared first: nothing else would, short of the cleaning of the
     * temporary directory a week later.
     *
     * @param int $userid
     * @param \stdClass $plan Whatever the preview holds; userid, created and token are added to it.
     * @return string The token.
     * @throws fiche_refusal PLAN_TOO_LARGE.
     */
    public static function create(int $userid, \stdClass $plan): string {
        self::purge($userid);

        $token = bin2hex(random_bytes(16));
        $plan->userid = $userid;
        $plan->created = \core\di::get(\core\clock::class)->time();
        $plan->token = $token;
        self::write(self::path($userid, $token), $plan);

        return $token;
    }

    /**
     * Read a plan back.
     *
     * @param int $userid
     * @param string $token
     * @return \stdClass|null Null when the token is malformed, unknown, expired, or not this user's.
     */
    public static function load(int $userid, string $token): ?\stdClass {
        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            return null;
        }

        $path = self::path($userid, $token);
        if (!is_file($path)) {
            return null;
        }
        $plan = self::read($path);
        if ($plan === null) {
            return null;
        }
        if (self::expired($plan)) {
            unlink($path);

            return null;
        }
        // The user is read again inside the file: a file copied under the name of another user is not theirs.
        if ((int) ($plan->userid ?? 0) !== $userid || ($plan->token ?? '') !== $token) {
            return null;
        }

        return $plan;
    }

    /**
     * Write the plan again, with what became of the records.
     *
     * The plan lives two hours from NOW: the result of an import is read again after the preview that led to it has aged.
     *
     * @param int $userid
     * @param string $token
     * @param \stdClass $plan
     * @throws fiche_refusal PLAN_TOO_LARGE.
     */
    public static function save(int $userid, string $token, \stdClass $plan): void {
        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            return;
        }
        $plan->userid = $userid;
        $plan->token = $token;
        $plan->created = \core\di::get(\core\clock::class)->time();
        self::write(self::path($userid, $token), $plan);
    }

    /**
     * Forget a plan.
     *
     * @param int $userid
     * @param string $token
     */
    public static function discard(int $userid, string $token): void {
        if (!preg_match(self::TOKEN_PATTERN, $token)) {
            return;
        }
        $path = self::path($userid, $token);
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Where a plan is. Public for the tests; nothing else needs to know.
     *
     * @param int $userid
     * @param string $token
     * @return string
     */
    public static function path(int $userid, string $token): string {
        return make_temp_directory(self::DIRECTORY) . '/' . $userid . '-' . $token . '.json';
    }

    /**
     * Whether a plan has lived longer than it may.
     *
     * @param \stdClass $plan
     * @return bool
     */
    private static function expired(\stdClass $plan): bool {
        return \core\di::get(\core\clock::class)->time() - (int) ($plan->created ?? 0) > self::TTL;
    }

    /**
     * A plan from its file.
     *
     * @param string $path
     * @return \stdClass|null Null when the file cannot be read as a plan.
     */
    private static function read(string $path): ?\stdClass {
        $content = file_get_contents($path);
        if ($content === false || strlen($content) > self::MAX_BYTES) {
            return null;
        }
        try {
            $plan = json_decode($content, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return $plan instanceof \stdClass ? $plan : null;
    }

    /**
     * Write a plan beside its place, then rename it onto it.
     *
     * @param string $path
     * @param \stdClass $plan
     * @throws fiche_refusal PLAN_TOO_LARGE.
     */
    private static function write(string $path, \stdClass $plan): void {
        global $CFG;

        try {
            $json = json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new fiche_refusal(fiche_refusal::PLAN_TOO_LARGE, [], 'The plan cannot be kept.');
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new fiche_refusal(fiche_refusal::PLAN_TOO_LARGE, [], 'The plan is too large to be kept.');
        }

        // What the disk says is read: a plan that was not written is not announced as kept.
        $aside = $path . '.tmp';
        if (file_put_contents($aside, $json, LOCK_EX) !== strlen($json)) {
            self::forget($aside);
            throw new fiche_refusal(fiche_refusal::UNEXPECTED, [], 'The plan cannot be written.');
        }
        if (!empty($CFG->filepermissions)) {
            chmod($aside, $CFG->filepermissions);
        }
        if (!rename($aside, $path)) {
            self::forget($aside);
            throw new fiche_refusal(fiche_refusal::UNEXPECTED, [], 'The plan cannot be written.');
        }
    }

    /**
     * Delete a file that was not finished.
     *
     * @param string $path
     */
    private static function forget(string $path): void {
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * Clear the plans of a user that have expired.
     *
     * @param int $userid
     */
    private static function purge(int $userid): void {
        $directory = make_temp_directory(self::DIRECTORY);
        foreach (glob($directory . '/' . $userid . '-*.json') ?: [] as $path) {
            $plan = self::read($path);
            if ($plan === null || self::expired($plan)) {
                unlink($path);
            }
        }
    }
}
