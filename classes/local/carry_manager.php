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

use tool_vae\event\evidence_carried;

/**
 * Carrying a declaration over to the record that replaces its own (T-30, lot 3, D-14).
 *
 * THE CANDIDATE CARRIES, ON A CORRESPONDENCE AN ADMINISTRATOR DECIDED. A declaration is first person: the plugin
 * never writes one in someone's place. The administrator decides which competency of the new record corresponds to
 * which of the old (passage_manager); the candidate, on their own dossier, carries a declaration over on that
 * decision, after reading it and correcting it if they wish.
 *
 * A COPY, NEVER A MOVE. The new declaration is made by evidence_manager::declare_activity(), with its own anchor in
 * the competency subsystem and the same checks as any declaration. The old one stays where it is, on the old
 * record, with its attestation, readable and dated, untouched. The new one has no attestation: it is "to attest".
 * A line of tool_vae_carry says which came from which, with a COPY of the decision (who, when, note), because the
 * decision itself can be replaced or taken back later and the trace must stay.
 *
 * NEVER AUTOMATIC, NEVER IN BULK. One declaration at a time, by the person it belongs to.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class carry_manager {
    /** @var competency_gateway The only door to the core. */
    private competency_gateway $gateway;

    /**
     * The gateway is injectable, as in the other managers.
     *
     * @param competency_gateway|null $gateway
     */
    public function __construct(?competency_gateway $gateway = null) {
        $this->gateway = $gateway ?? new competency_gateway();
    }

    /**
     * What a declaration can be carried over to, if it can.
     *
     * Every condition of the design is read here, so that the dossier decides whether to draw the button and the
     * page decides whether to accept the carry on the same rule: the record has a successor, the competency has a
     * decided correspondence that still points into that successor, the declaration was not carried yet, the
     * reader is the candidate with the right to declare, and the replacing record is not hidden in Moodle (nothing is
     * declared on a hidden record, so nothing is offered towards it).
     *
     * @param \stdClass $evidence A row of tool_vae_evidence.
     * @return \stdClass|null passage (the decision row), target (the competency of the new record), successor
     *         (the source row of the new record); null when nothing can be carried.
     */
    public function offer(\stdClass $evidence): ?\stdClass {
        try {
            return $this->require_offer($evidence);
        } catch (\moodle_exception $e) {
            return null;
        }
    }

    /**
     * The same rule as offer(), saying why it is refused.
     *
     * A REPLACING RECORD HIDDEN SINCE THE BUTTON WAS DRAWN is refused by name, in words for the candidate
     * (hidden_frameworks). The page that gives the form back asks to read the offer all the same ($allowhidden), so that
     * what the candidate typed is not lost with the redirect; every other refusal stays one.
     *
     * @param \stdClass $evidence A row of tool_vae_evidence.
     * @param bool $allowhidden Whether a hidden replacing record is let through, reported in `hidden`. Reading is all it
     *        allows: carry() never passes it.
     * @return \stdClass passage, target, successor, and hidden (whether the replacing record is hidden in Moodle).
     * @throws \moodle_exception errorcarrydenied, errorcarrydone, errorcarrynopassage, errorcarrystale,
     *         errorcompetenciesdisabled, errorframeworkhidden
     */
    public function require_offer(\stdClass $evidence, bool $allowhidden = false): \stdClass {
        global $DB, $USER;

        $candidateid = (int) $evidence->candidateid;
        if (!isloggedin() || isguestuser() || (int) $USER->id !== $candidateid) {
            throw new \moodle_exception('errorcarrydenied', 'tool_vae');
        }
        if (!has_capability('tool/vae:declare', \context_user::instance($candidateid))) {
            throw new \moodle_exception('errorcarrydenied', 'tool_vae');
        }
        if (!competency_gateway::is_subsystem_enabled()) {
            throw new \moodle_exception('errorcompetenciesdisabled', 'tool_vae');
        }
        if ($DB->record_exists('tool_vae_carry', ['oldevidenceid' => (int) $evidence->id])) {
            throw new \moodle_exception('errorcarrydone', 'tool_vae');
        }
        if ($evidence->competencyid === null) {
            throw new \moodle_exception('errorcarrynopassage', 'tool_vae');
        }

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $evidence->fichecode]);
        $successor = $source && (string) $source->successorcode !== ''
            ? $DB->get_record('tool_vae_source', ['fichecode' => $source->successorcode]) : false;
        $passage = $DB->get_record('tool_vae_passage', ['fromcompetencyid' => (int) $evidence->competencyid]);
        if (!$successor || !$passage) {
            throw new \moodle_exception('errorcarrynopassage', 'tool_vae');
        }

        // A decision outlives a change of successor: A replaced by B, decided, then A replaced by C. Read again
        // here, at the moment of the carry.
        $target = $this->gateway->get_competency((int) $passage->tocompetencyid);
        if (!$target || (int) $target->frameworkid !== (int) $successor->frameworkid) {
            throw new \moodle_exception('errorcarrystale', 'tool_vae');
        }

        // HIDDEN IN tool_lp ("Visible: No"): nothing is declared on the record, so nothing is carried to it. The button is not
        // drawn, and a carry sent from a page opened before the record was hidden is refused before anything is written.
        $frameworks = new hidden_frameworks($this->gateway);
        $hidden = $frameworks->is_hidden($successor);
        if ($hidden && !$allowhidden) {
            throw $frameworks->exception($successor);
        }

        return (object) ['passage' => $passage, 'target' => $target, 'successor' => $successor, 'hidden' => $hidden];
    }

    /**
     * The values a form for the carry starts from: the old declaration, unchanged.
     *
     * @param \stdClass $evidence A row of tool_vae_evidence.
     * @return \stdClass
     */
    public static function defaults(\stdClass $evidence): \stdClass {
        return (object) [
            'periodstart' => (int) $evidence->periodstart,
            'periodend' => (int) $evidence->periodend,
            'settingtype' => $evidence->settingtype,
            'settingdetail' => $evidence->settingdetail,
            'situation' => $evidence->situation,
        ];
    }

    /**
     * Carry a declaration over: make it again on the replacing record, and keep the trace.
     *
     * @param int $evidenceid The declaration on the replaced record.
     * @param \stdClass $data periodstart, periodend, settingtype, settingdetail, situation (what the candidate
     *        confirmed or corrected). The competency and the format are not taken from here.
     * @return \stdClass The row of tool_vae_carry.
     * @throws \moodle_exception errorcarrydenied, errorcarrydone, errorcarrynopassage, errorcarrystale,
     *         errorframeworkhidden, errorcarrybusy, and whatever declare_activity() refuses
     */
    public function carry(int $evidenceid, \stdClass $data): \stdClass {
        global $DB;

        $evidence = $DB->get_record('tool_vae_evidence', ['id' => $evidenceid]);
        if (!$evidence) {
            // The same refusal for a declaration that is not there and one that is not theirs.
            throw new \moodle_exception('errorcarrydenied', 'tool_vae');
        }

        // Check and write under a lock: two sends at the same moment must not both pass the check. The unique key on
        // the old declaration is the second line of defence.
        $lock = \core\lock\lock_config::get_lock_factory('tool_vae_carry')->get_lock('carry' . $evidenceid, 10);
        if (!$lock) {
            throw new \moodle_exception('errorcarrybusy', 'tool_vae');
        }

        try {
            $offer = $this->require_offer($evidence);

            $data = clone $data;
            $data->competencyid = (int) $offer->target->id;
            // A narrative is plain text, as on every declaration.
            $data->situationformat = FORMAT_PLAIN;

            $transaction = $DB->start_delegated_transaction();
            try {
                $new = (new evidence_manager($this->gateway))->declare_activity((int) $evidence->candidateid, $data);

                $row = (object) [
                    'oldevidenceid' => (int) $evidence->id,
                    'newevidenceid' => (int) $new->id,
                    'fromcompetencyid' => (int) $evidence->competencyid,
                    'tocompetencyid' => (int) $offer->target->id,
                    'decidedby' => (int) $offer->passage->decidedby,
                    'decidedat' => (int) $offer->passage->decidedat,
                    'note' => (string) $offer->passage->note,
                    'carriedat' => time(),
                ];
                $row->id = $DB->insert_record('tool_vae_carry', $row);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                // The rollback() call rethrows; the line below is never reached.
                $transaction->rollback($e);
                throw $e;
            }
        } finally {
            $lock->release();
        }

        evidence_carried::create([
            'context' => \context_user::instance((int) $evidence->candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => (int) $evidence->candidateid,
            'other' => ['oldevidenceid' => (int) $evidence->id, 'newevidenceid' => (int) $new->id],
        ])->trigger();

        return $row;
    }

    /**
     * What a declaration says of its carries: where it was carried to, and where it comes from.
     *
     * The name of whoever decided the correspondence is given only to someone who may import: a candidate reads
     * "the site administrator" and a date (decision of the project owner, 19 October 2026).
     *
     * @param \stdClass $evidence A row of tool_vae_evidence.
     * @return \stdClass carriedto and carriedfrom, each the row of tool_vae_carry with `record` (code-rank of the
     *         other declaration) and `decidername` (empty unless the reader may import), or null.
     */
    public function notes(\stdClass $evidence): \stdClass {
        global $DB;

        $mayname = has_capability('tool/vae:import', \context_system::instance());
        $describe = function (?\stdClass $row, string $otherfield) use ($DB, $mayname): ?\stdClass {
            if (!$row) {
                return null;
            }
            $other = $DB->get_record('tool_vae_evidence', ['id' => (int) $row->{$otherfield}]);
            $row->record = $other ? $other->fichecode . '-' . $other->ficherang : '';
            $decider = $mayname && (int) $row->decidedby > 0 ? \core_user::get_user((int) $row->decidedby) : null;
            $row->decidername = $decider && empty($decider->deleted) ? fullname($decider) : '';

            return $row;
        };

        return (object) [
            'carriedto' => $describe(
                $DB->get_record('tool_vae_carry', ['oldevidenceid' => (int) $evidence->id]) ?: null,
                'newevidenceid'
            ),
            'carriedfrom' => $describe(
                $DB->get_record('tool_vae_carry', ['newevidenceid' => (int) $evidence->id]) ?: null,
                'oldevidenceid'
            ),
        ];
    }
}
