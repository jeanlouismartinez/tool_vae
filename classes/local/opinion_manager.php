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

use tool_vae\event\opinion_created;

/**
 * The opinion of the manager who follows a candidate, on one deposit (T-10, lot 5b, D-12).
 *
 * WRITTEN BY THE MANAGER, ON ONE DEPOSIT. The opinion is attached to the milestone the manager read,
 * dated and hashed, and never rewritten: a candidate who deposits again has a deposit with no opinion
 * until a manager writes one on it.
 *
 * FREE TEXT, NEVER A VERDICT. The plugin does not say whether the opinion is favourable, nor whether
 * the dossier is admissible (D-1, D-4): it records what the manager wrote. It is not the signature of
 * the official form either (D-7 f).
 *
 * WHO WRITES. Whoever follows the candidate and holds tool/vae:writeopinion in their context, and may
 * read the deposit. The candidate does not write their own opinion, and the site administrator - who
 * follows nobody - does not write one for them.
 *
 * WHO READS. Whoever may read the deposit (deposit_manager::can_read()).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class opinion_manager {
    /** @var int The longest opinion, in characters. */
    public const MAX_LENGTH = 20000;

    /**
     * Whether the current user may write an opinion on this candidate's deposits.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_write(int $candidateid): bool {
        global $USER;

        if (!isloggedin() || isguestuser() || (int) $USER->id === $candidateid) {
            return false;
        }
        if (!access::follows($candidateid)) {
            return false;
        }
        $context = \context_user::instance($candidateid);

        return has_capability('tool/vae:writeopinion', $context)
            && has_capability('tool/vae:readdeposit', $context);
    }

    /**
     * Write an opinion on a deposit.
     *
     * @param int $depositid
     * @param string $text
     * @return \stdClass The row of tool_vae_opinion.
     * @throws \moodle_exception erroropiniondenied, erroropinionempty, errordrafttoolong,
     *         erroropinionduplicate
     */
    public function write(int $depositid, string $text): \stdClass {
        global $DB, $USER;

        $deposit = $DB->get_record('tool_vae_deposit', ['id' => $depositid]);
        // No opinion on the preparation of the validation dossier: the manager reads, never evaluates (D-15, D-1, D-4).
        if (
            !$deposit || $deposit->doctype !== draft_manager::DOCTYPE_FAISABILITE
            || !self::can_write((int) $deposit->candidateid)
        ) {
            // The same refusal for a deposit that is not there and one that may not be written on.
            throw new \moodle_exception('erroropiniondenied', 'tool_vae');
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            throw new \moodle_exception('erroropinionempty', 'tool_vae');
        }
        if (\core_text::strlen($text) > self::MAX_LENGTH) {
            throw new \moodle_exception('errordrafttoolong', 'tool_vae', '', self::MAX_LENGTH);
        }

        $hash = hash('sha256', $text);
        $same = ['depositid' => $depositid, 'authorid' => (int) $USER->id, 'contenthash' => $hash];
        if ($DB->record_exists('tool_vae_opinion', $same)) {
            throw new \moodle_exception('erroropinionduplicate', 'tool_vae');
        }

        $row = (object) [
            'uuid' => identity_map::generate_ulid(),
            'depositid' => $depositid,
            'authorid' => (int) $USER->id,
            'content' => $text,
            'contenthash' => $hash,
            'timecreated' => time(),
        ];
        $row->id = $DB->insert_record('tool_vae_opinion', $row);

        opinion_created::create([
            'context' => \context_user::instance((int) $deposit->candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => (int) $deposit->candidateid,
            'other' => ['number' => (int) $deposit->number],
        ])->trigger();

        return $row;
    }

    /**
     * The opinions on a deposit, the earliest first.
     *
     * @param \stdClass $deposit A row of tool_vae_deposit.
     * @return \stdClass[] Rows of tool_vae_opinion, each with `authorname` (empty when the author is gone).
     */
    public function for_deposit(\stdClass $deposit): array {
        global $DB;

        deposit_manager::require_can_read((int) $deposit->candidateid);

        $rows = $DB->get_records('tool_vae_opinion', ['depositid' => (int) $deposit->id], 'timecreated ASC, id ASC');
        foreach ($rows as $row) {
            $author = $row->authorid ? \core_user::get_user((int) $row->authorid) : null;
            $row->authorname = $author && empty($author->deleted) ? fullname($author) : '';
        }

        return $rows;
    }
}
