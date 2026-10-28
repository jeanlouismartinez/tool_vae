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

use tool_vae\event\request_answered;
use tool_vae\event\request_created;

/**
 * A request for precision on a declaration, before it is attested, and its answer (T-20, D-13).
 *
 * A QUESTION, NOT A GATE, NOT A VERDICT (D-1). A manager who follows the candidate asks, in words, for
 * what they need to understand a declaration before they attest it; the candidate answers in words. The
 * plugin does not hold the attestation back while a question is open, does not say whether the answer
 * is enough, and does not change the declaration: the answer is a text beside it.
 *
 * WHO ASKS. Whoever follows the candidate and may attest (access::can_attest()). The candidate does not
 * ask themselves a question, and the administrator, who follows nobody, does not ask in a manager's
 * place. One question at a time on a declaration: the next one waits for the answer to the last.
 *
 * WHO ANSWERS. The candidate, on their own declaration, once per question. The question and the answer
 * are never rewritten.
 *
 * WHEN. Before attestation: nothing is asked of a declaration that stands attested - a withdrawn
 * attestation reopens it. An open question stays readable after the attestation, with its answer.
 *
 * WHO READS. Whoever may read the dossier (access::can_view()).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request_manager {
    /** @var int The longest question or answer, in characters. */
    public const MAX_LENGTH = 5000;

    /**
     * Whether the current user may ask for a precision in this candidate's dossier.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_ask(int $candidateid): bool {
        global $USER;

        if (!isloggedin() || isguestuser() || (int) $USER->id === $candidateid) {
            return false;
        }

        return access::follows($candidateid) && access::can_attest($candidateid);
    }

    /**
     * Whether the current user may answer the requests on this candidate's declarations: the candidate.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_answer(int $candidateid): bool {
        global $USER;

        if (!isloggedin() || isguestuser() || (int) $USER->id !== $candidateid) {
            return false;
        }

        return has_capability('tool/vae:declare', \context_user::instance($candidateid))
            && has_capability('tool/vae:viewown', \context_user::instance($candidateid));
    }

    /**
     * Ask for a precision on a declaration.
     *
     * @param int $evidenceid
     * @param string $text
     * @return \stdClass The row of tool_vae_request.
     * @throws \moodle_exception errorrequestdenied, errorrequestempty, errordrafttoolong,
     *         errorrequestattested, errorrequestopen
     */
    public function ask(int $evidenceid, string $text): \stdClass {
        global $DB, $USER;

        $evidence = $DB->get_record('tool_vae_evidence', ['id' => $evidenceid]);
        if (!$evidence || !self::can_ask((int) $evidence->candidateid)) {
            // The same refusal for a declaration that is not there and one that may not be asked about.
            throw new \moodle_exception('errorrequestdenied', 'tool_vae');
        }

        $text = self::clean($text);
        if ($text === '') {
            throw new \moodle_exception('errorrequestempty', 'tool_vae');
        }
        if (\core_text::strlen($text) > self::MAX_LENGTH) {
            throw new \moodle_exception('errordrafttoolong', 'tool_vae', '', self::MAX_LENGTH);
        }
        if (attestation_manager::active_attestation($evidenceid)) {
            throw new \moodle_exception('errorrequestattested', 'tool_vae');
        }

        // One open question at a time (D-13): check and insert under a lock, or two managers sending at the same
        // moment would both pass the check.
        $lock = self::lock('ask' . $evidenceid);
        try {
            if ($DB->record_exists_select('tool_vae_request', 'evidenceid = :e AND answer IS NULL', ['e' => $evidenceid])) {
                throw new \moodle_exception('errorrequestopen', 'tool_vae');
            }

            $row = (object) [
                'uuid' => identity_map::generate_ulid(),
                'evidenceid' => $evidenceid,
                'authorid' => (int) $USER->id,
                'question' => $text,
                'questionhash' => hash('sha256', $text),
                'timecreated' => time(),
                'answer' => null,
                'answerhash' => null,
                'timeanswered' => null,
            ];
            $row->id = $DB->insert_record('tool_vae_request', $row);
        } finally {
            $lock->release();
        }

        request_created::create([
            'context' => \context_user::instance((int) $evidence->candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => (int) $evidence->candidateid,
            'other' => ['evidenceid' => $evidenceid],
        ])->trigger();

        return $row;
    }

    /**
     * Answer a request. Once: an answered request is closed.
     *
     * @param int $requestid
     * @param string $text
     * @return \stdClass The row of tool_vae_request.
     * @throws \moodle_exception errorrequestdenied, errorrequestempty, errordrafttoolong,
     *         errorrequestanswered
     */
    public function answer(int $requestid, string $text): \stdClass {
        global $DB;

        $row = $DB->get_record('tool_vae_request', ['id' => $requestid]);
        $evidence = $row ? $DB->get_record('tool_vae_evidence', ['id' => $row->evidenceid]) : false;
        if (!$row || !$evidence || !self::can_answer((int) $evidence->candidateid)) {
            throw new \moodle_exception('errorrequestdenied', 'tool_vae');
        }
        if ($row->answer !== null) {
            throw new \moodle_exception('errorrequestanswered', 'tool_vae');
        }

        $text = self::clean($text);
        if ($text === '') {
            throw new \moodle_exception('errorrequestempty', 'tool_vae');
        }
        if (\core_text::strlen($text) > self::MAX_LENGTH) {
            throw new \moodle_exception('errordrafttoolong', 'tool_vae', '', self::MAX_LENGTH);
        }

        // An answer is never rewritten: read the row again under a lock, so that a second send at the same moment
        // finds it answered instead of writing over the first.
        $lock = self::lock('answer' . $requestid);
        try {
            $row = $DB->get_record('tool_vae_request', ['id' => $requestid], '*', MUST_EXIST);
            if ($row->answer !== null) {
                throw new \moodle_exception('errorrequestanswered', 'tool_vae');
            }
            $row->answer = $text;
            $row->answerhash = hash('sha256', $text);
            $row->timeanswered = time();
            $DB->update_record('tool_vae_request', $row);
        } finally {
            $lock->release();
        }

        request_answered::create([
            'context' => \context_user::instance((int) $evidence->candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => (int) $evidence->candidateid,
            'other' => ['evidenceid' => (int) $evidence->id],
        ])->trigger();

        return $row;
    }

    /**
     * The requests on a declaration, the earliest first.
     *
     * @param \stdClass $evidence A row of tool_vae_evidence.
     * @return \stdClass[] Rows of tool_vae_request, each with `authorname` (empty when the author is gone).
     */
    public function for_evidence(\stdClass $evidence): array {
        global $DB;

        if (!access::can_view((int) $evidence->candidateid)) {
            throw new \moodle_exception('errorrequestdenied', 'tool_vae');
        }

        $rows = $DB->get_records('tool_vae_request', ['evidenceid' => (int) $evidence->id], 'timecreated ASC, id ASC');
        foreach ($rows as $row) {
            $author = $row->authorid ? \core_user::get_user((int) $row->authorid) : null;
            $row->authorname = $author && empty($author->deleted) ? fullname($author) : '';
        }

        return $rows;
    }

    /**
     * How many requests of a declaration still wait for an answer.
     *
     * @param int $evidenceid
     * @return int
     */
    public static function count_open(int $evidenceid): int {
        global $DB;

        return $DB->count_records_select('tool_vae_request', 'evidenceid = :e AND answer IS NULL', ['e' => $evidenceid]);
    }

    /**
     * Normalise a text: line endings and surrounding blanks.
     *
     * @param string $text
     * @return string
     */
    private static function clean(string $text): string {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * Take the lock that serialises the writes to one request or one declaration.
     *
     * @param string $key
     * @return \core\lock\lock
     * @throws \moodle_exception errorrequestbusy when the lock is not free within ten seconds
     */
    private static function lock(string $key): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('tool_vae_request')->get_lock($key, 10);
        if (!$lock) {
            throw new \moodle_exception('errorrequestbusy', 'tool_vae');
        }

        return $lock;
    }
}
