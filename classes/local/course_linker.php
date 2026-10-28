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
 * Attaching an imported certification record to a course (lot 8 bis).
 *
 * ASKED BY THE PROJECT OWNER, 12 OCTOBER 2026: once the administrator has imported a record, the
 * direction and the managers choose it for a course and bring it in - the whole record at once.
 *
 * NOTHING NEW IS STORED. The core already knows "the competencies of a course" (tool_lp), and the
 * record's competencies are added there, one by one, through the gateway. A competency already in
 * the course is left alone; one the source no longer carries - marked `missing` by the import of
 * lot 6 - is not brought in: a course is built on the record as it stands today.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class course_linker {
    /** @var competency_gateway */
    private $gateway;

    /**
     * Constructor.
     *
     * @param competency_gateway|null $gateway Only passed by tests.
     */
    public function __construct(?competency_gateway $gateway = null) {
        $this->gateway = $gateway ?? new competency_gateway();
    }

    /**
     * Whether the current user may attach records to a course.
     *
     * @param \context_course $context
     * @return bool
     */
    public static function can_link(\context_course $context): bool {
        return has_capability('tool/vae:linkcourse', $context)
            && competency_gateway::can_manage_course_competencies($context);
    }

    /**
     * Require permission to attach records to a course.
     *
     * This plugin's capability, and the core's right to change the course's competencies, which
     * the core checks again on every addition: asked first, so that nobody starts an operation the
     * core would stop halfway.
     *
     * @param int $courseid
     * @return \context_course
     */
    public static function require_can_link(int $courseid): \context_course {
        $context = \context_course::instance($courseid);

        require_capability('tool/vae:linkcourse', $context);
        require_capability('moodle/competency:coursecompetencymanage', $context);

        return $context;
    }

    /**
     * The imported records, and how much of each the course already holds.
     *
     * A record whose framework the core no longer holds is left out: nothing of it can be attached, and a
     * button for "0 competencies" would be a promise this screen cannot keep. The administration screen
     * is where that record is reported (source_frameworks).
     *
     * @param int $courseid
     * @return \stdClass[] fichecode, fichetype, sourceupdated, total, incourse and hidden (whether the
     *         framework is hidden in tool_lp, which the core refuses to attach), by record code.
     */
    public function records(int $courseid): array {
        global $DB;

        $incourse = $this->gateway->course_competency_ids($courseid);

        $out = [];
        foreach ($DB->get_records('tool_vae_source', null, 'fichecode ASC') as $source) {
            if (!$this->gateway->framework_exists((int) $source->frameworkid)) {
                continue;
            }

            $ids = $this->current_competencies($source);
            $out[$source->fichecode] = (object) [
                'fichecode' => $source->fichecode,
                'fichetype' => $source->fichetype,
                'sourceupdated' => $source->sourceupdated,
                'total' => count($ids),
                'incourse' => count(array_intersect($ids, $incourse)),
                'hidden' => !$this->gateway->framework_visible((int) $source->frameworkid),
            ];
        }

        return $out;
    }

    /**
     * Attach every current competency of a record to a course.
     *
     * The caller has checked the right to do so - see require_can_link().
     *
     * @param int $courseid
     * @param string $fichecode
     * @return int How many competencies were added; 0 when the course held them all already.
     * @throws \moodle_exception errorlinkunknownrecord, errorlinknoframework, errorlinkhiddenframework
     */
    public function link(int $courseid, string $fichecode): int {
        global $DB;

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        if (!$source) {
            throw new \moodle_exception('errorlinkunknownrecord', 'tool_vae', '', s($fichecode));
        }
        if (!$this->gateway->framework_exists((int) $source->frameworkid)) {
            throw new \moodle_exception('errorlinknoframework', 'tool_vae', '', s($fichecode));
        }

        // A HIDDEN FRAMEWORK IS REFUSED BY NAME, BEFORE THE TRANSACTION. The core refuses to attach a
        // competency of a hidden framework to a course, and does it with a coding_exception, an error
        // meant for a programmer: a manager who pressed the button the screen offered got "Coding error
        // detected" with the message in English. "Visible: No" is the gesture the core's own help
        // recommends while a framework is being set up or updated to a new version - exactly when a
        // record has just been re-imported.
        if (!$this->gateway->framework_visible((int) $source->frameworkid)) {
            throw new \moodle_exception('errorlinkhiddenframework', 'tool_vae', '', s($fichecode));
        }

        $already = $this->gateway->course_competency_ids($courseid);
        $added = 0;

        // All of the record or none of it: a course left with half a record would read as a
        // choice somebody made.
        $transaction = $DB->start_delegated_transaction();

        try {
            foreach ($this->current_competencies($source) as $competencyid) {
                if (in_array($competencyid, $already, true)) {
                    continue;
                }
                if ($this->gateway->add_competency_to_course($courseid, $competencyid)) {
                    $added++;
                }
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The rollback() call rethrows; the line after it is never reached.
            $transaction->rollback($e);
            throw $e;
        }

        return $added;
    }

    /**
     * The competencies of a record that its source still carries.
     *
     * @param \stdClass $source A row of tool_vae_source.
     * @return int[]
     */
    private function current_competencies(\stdClass $source): array {
        global $DB;

        $missing = array_map('intval', $DB->get_fieldset_select(
            'tool_vae_obsolete',
            'competencyid',
            'sourceid = :sourceid AND kind = :kind',
            ['sourceid' => $source->id, 'kind' => 'missing']
        ));

        $ids = [];
        foreach ($this->gateway->list_framework_competencies((int) $source->frameworkid) as $competency) {
            if (!in_array((int) $competency->id, $missing, true)) {
                $ids[] = (int) $competency->id;
            }
        }

        return $ids;
    }
}
