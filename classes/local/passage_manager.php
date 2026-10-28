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

use tool_vae\event\passage_changed;

/**
 * The correspondences between the competencies of a record and those of the record that replaces it (T-30, lot 2).
 *
 * PROPOSED BY THE PLUGIN, DECIDED BY A PERSON (D-14). For each competency of the replaced record the plugin
 * suggests one of the replacing record - the one of the same rank, else the one whose label is nearly the same -
 * and says why. A suggestion is never recorded: only an administrator's decision is, one competency at a time,
 * with a note, in tool_vae_passage, with who decided and when. A competency with no decision has no equivalent
 * yet and stays where it is.
 *
 * NOTHING IS MOVED. A decision is a line in a table. No declaration changes record, no attestation is touched:
 * carrying the declarations over is lot 3, and will read these decisions.
 *
 * ONE TARGET PER COMPETENCY. A new decision for the same competency replaces the earlier one.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class passage_manager {
    /** @var int The longest note, in characters. */
    public const NOTE_MAXLENGTH = 500;

    /** @var float The similarity (percent) from which two labels are proposed as the same competency. */
    public const LABEL_THRESHOLD = 85.0;

    /** @var string A proposal made because the rank is the same. */
    public const REASON_RANK = 'rank';

    /** @var string A proposal made because the labels are nearly the same. */
    public const REASON_LABEL = 'label';

    /** @var competency_gateway The only door to the core. */
    private $gateway;

    /**
     * The gateway is injectable, as in the importer.
     *
     * @param competency_gateway|null $gateway
     */
    public function __construct(?competency_gateway $gateway = null) {
        $this->gateway = $gateway ?? new competency_gateway();
    }

    /**
     * What to decide for a record that another replaces: each of its competencies, with the suggestion and the
     * decision taken, if any.
     *
     * @param string $fichecode The record that is replaced.
     * @return \stdClass[] Keyed by competency id, in rank order: competency (id, idnumber, shortname), declarations,
     *         proposed (the competency object of the replacing record, or null), reason (REASON_*, or ''),
     *         decision (the row of tool_vae_passage, or null), target (the competency of the decision, or null),
     *         decidername ('' when nobody is left to name).
     * @throws \moodle_exception errorpassagenosuccessor, errorpassagenocompetencies
     */
    public function overview(string $fichecode): array {
        global $DB;

        [$source, $successor] = $this->pair($fichecode);

        // A RECORD THAT HOLDS NO COMPETENCY IS REFUSED BY NAME, NOT LISTED AS AN EMPTY TABLE. Its framework was
        // deleted in tool_lp, or never created because its first import died: there is nothing to match, and a
        // screen with no row would read as "all decided".
        $from = $this->gateway->list_framework_competencies((int) $source->frameworkid);
        $to = $this->gateway->list_framework_competencies((int) $successor->frameworkid);
        foreach ([[$source, $from], [$successor, $to]] as [$record, $competencies]) {
            if (!$competencies) {
                throw new \moodle_exception('errorpassagenocompetencies', 'tool_vae', '', s($record->fichecode));
            }
        }
        $decisions = [];
        if ($from) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($from), SQL_PARAMS_NAMED);
            $decisions = $DB->get_records_select('tool_vae_passage', "fromcompetencyid $insql", $params);
        }
        $bysource = [];
        foreach ($decisions as $decision) {
            $bysource[(int) $decision->fromcompetencyid] = $decision;
        }
        $held = $DB->get_records_sql(
            'SELECT e.competencyid, COUNT(1) AS declarations FROM {tool_vae_evidence} e
              WHERE e.fichecode = :code AND e.competencyid IS NOT NULL GROUP BY e.competencyid',
            ['code' => $fichecode]
        );

        $rows = [];
        foreach ($from as $id => $competency) {
            [$proposed, $reason] = $this->propose($fichecode, $competency, $successor->fichecode, $to);
            $decision = $bysource[$id] ?? null;
            $rows[$id] = (object) [
                'competency' => $competency,
                'declarations' => isset($held[$id]) ? (int) $held[$id]->declarations : 0,
                'proposed' => $proposed,
                'reason' => $reason,
                'decision' => $decision,
                'target' => $decision ? ($to[(int) $decision->tocompetencyid] ?? null) : null,
                'decidername' => $decision ? self::name_of((int) $decision->decidedby) : '',
            ];
        }
        uasort($rows, fn($a, $b) => strnatcasecmp($a->competency->idnumber, $b->competency->idnumber));

        return $rows;
    }

    /**
     * The competencies of the record that replaces this one: what a decision can point at.
     *
     * @param string $fichecode The record that is replaced.
     * @return \stdClass[] Keyed by competency id, in rank order: id, idnumber, shortname.
     * @throws \moodle_exception errorpassagenosuccessor, errorpassagenocompetencies
     */
    public function targets(string $fichecode): array {
        [, $successor] = $this->pair($fichecode);

        $targets = $this->gateway->list_framework_competencies((int) $successor->frameworkid);
        if (!$targets) {
            throw new \moodle_exception('errorpassagenocompetencies', 'tool_vae', '', s($successor->fichecode));
        }
        uasort($targets, fn($a, $b) => strnatcasecmp($a->idnumber, $b->idnumber));

        return $targets;
    }

    /**
     * Decide that a competency of the replaced record has this equivalent in the replacing record.
     *
     * @param int $fromid A competency of the record that is replaced.
     * @param int $toid A competency of the record that replaces it.
     * @param string $note Why, in words; may be empty.
     * @return \stdClass The row of tool_vae_passage.
     * @throws \moodle_exception errorpassageunknown, errorpassagenosuccessor, errorpassagenotmatching,
     *         errordrafttoolong
     */
    public function decide(int $fromid, int $toid, string $note = ''): \stdClass {
        global $DB, $USER;

        require_capability('tool/vae:import', \context_system::instance());

        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
        if (\core_text::strlen($note) > self::NOTE_MAXLENGTH) {
            throw new \moodle_exception('errordrafttoolong', 'tool_vae', '', self::NOTE_MAXLENGTH);
        }

        $fromsource = $this->source_of($fromid);
        $successor = $fromsource ? $this->successor_of($fromsource) : null;
        if (!$fromsource || !$successor) {
            throw new \moodle_exception('errorpassagenosuccessor', 'tool_vae');
        }
        if ($this->gateway->framework_of_competency($toid) !== (int) $successor->frameworkid) {
            throw new \moodle_exception('errorpassagenotmatching', 'tool_vae');
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('tool_vae_passage', ['fromcompetencyid' => $fromid]);
        $row = (object) [
            'fromcompetencyid' => $fromid,
            'tocompetencyid' => $toid,
            'decidedby' => (int) $USER->id,
            'decidedat' => time(),
            'note' => $note,
        ];
        $row->id = $DB->insert_record('tool_vae_passage', $row);
        $transaction->allow_commit();

        self::log($row, $fromid, $toid);

        return $row;
    }

    /**
     * Take back a decision: the competency has no equivalent yet.
     *
     * @param int $fromid
     */
    public function undo(int $fromid): void {
        global $DB;

        require_capability('tool/vae:import', \context_system::instance());

        $row = $DB->get_record('tool_vae_passage', ['fromcompetencyid' => $fromid]);
        if (!$row) {
            return;
        }
        $DB->delete_records('tool_vae_passage', ['fromcompetencyid' => $fromid]);

        self::log($row, $fromid, 0);
    }

    /**
     * The suggestion for one competency: the same rank first, else the closest label if it is close enough.
     *
     * @param string $fromcode Code of the replaced record.
     * @param \stdClass $competency A competency of that record.
     * @param string $tocode Code of the replacing record.
     * @param \stdClass[] $candidates The competencies of the replacing record.
     * @return array [competency or null, reason or '']
     */
    private function propose(string $fromcode, \stdClass $competency, string $tocode, array $candidates): array {
        $rank = self::rank_of((string) $competency->idnumber, $fromcode);
        if ($rank !== '') {
            foreach ($candidates as $candidate) {
                if (strcasecmp(self::rank_of((string) $candidate->idnumber, $tocode), $rank) === 0) {
                    return [$candidate, self::REASON_RANK];
                }
            }
        }

        $best = null;
        $bestscore = 0.0;
        $label = \core_text::strtolower(trim((string) $competency->shortname));
        foreach ($candidates as $candidate) {
            $percent = 0.0;
            similar_text($label, \core_text::strtolower(trim((string) $candidate->shortname)), $percent);
            if ($percent > $bestscore) {
                $best = $candidate;
                $bestscore = $percent;
            }
        }

        return $best !== null && $bestscore >= self::LABEL_THRESHOLD ? [$best, self::REASON_LABEL] : [null, ''];
    }

    /**
     * The rank part of an idnumber `<code>-<rank>`.
     *
     * @param string $idnumber
     * @param string $code
     * @return string Empty when the idnumber does not have that shape.
     */
    public static function rank_of(string $idnumber, string $code): string {
        $prefix = $code . '-';

        return strncmp($idnumber, $prefix, strlen($prefix)) === 0 ? substr($idnumber, strlen($prefix)) : '';
    }

    /**
     * The source and the source that replaces it.
     *
     * @param string $fichecode
     * @return \stdClass[] [source, successor]
     * @throws \moodle_exception errorpassagenosuccessor
     */
    private function pair(string $fichecode): array {
        global $DB;

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        $successor = $source ? $this->successor_of($source) : null;
        if (!$source || !$successor) {
            throw new \moodle_exception('errorpassagenosuccessor', 'tool_vae');
        }

        return [$source, $successor];
    }

    /**
     * The source that replaces a source.
     *
     * @param \stdClass $source
     * @return \stdClass|null
     */
    private function successor_of(\stdClass $source): ?\stdClass {
        global $DB;

        if ((string) $source->successorcode === '') {
            return null;
        }
        $successor = $DB->get_record('tool_vae_source', ['fichecode' => $source->successorcode]);

        return $successor ?: null;
    }

    /**
     * The source whose record holds this competency.
     *
     * @param int $competencyid
     * @return \stdClass|null
     */
    private function source_of(int $competencyid): ?\stdClass {
        global $DB;

        $frameworkid = $this->gateway->framework_of_competency($competencyid);
        $source = $frameworkid ? $DB->get_record('tool_vae_source', ['frameworkid' => $frameworkid]) : false;

        return $source ?: null;
    }

    /**
     * The name of the person who decided, or an empty string when the account is gone or was erased.
     *
     * @param int $userid
     * @return string
     */
    private static function name_of(int $userid): string {
        $user = $userid > 0 ? \core_user::get_user($userid) : null;

        return $user && empty($user->deleted) ? fullname($user) : '';
    }

    /**
     * Say it in the log: identifiers of competencies only.
     *
     * @param \stdClass $row The row of tool_vae_passage.
     * @param int $fromid
     * @param int $toid Zero when the decision was taken back.
     */
    private static function log(\stdClass $row, int $fromid, int $toid): void {
        passage_changed::create([
            'context' => \context_system::instance(),
            'objectid' => (int) $row->id,
            'other' => ['fromcompetencyid' => $fromid, 'tocompetencyid' => $toid],
        ])->trigger();
    }
}
