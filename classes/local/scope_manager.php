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

use tool_vae\event\scope_marked;
use tool_vae\event\scope_unmarked;

/**
 * The competencies a candidate says are outside their activity (T-42).
 *
 * DECLARATIVE AND WITHOUT EFFECT. A mark is the candidate's word about their own activity: it is not an attestation, nobody
 * signs it, and it exempts from nothing. The jury decides whether a competency that was not exercised counts (D-1, D-4);
 * for some diplomas it does. So nothing is counted, reminded or reported because of a mark, and the coverage keeps its
 * figures as they are.
 *
 * The candidate alone writes it and takes it back (access::require_can_declare()). It is kept as a record code and a rank,
 * the way a declaration is, so that it survives a competency removed from the core. A mark taken back is deleted: the event
 * says that it happened, and nothing else of it is kept.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scope_manager {
    /** @var int The longest note, the width of the column. */
    public const NOTE_MAXLENGTH = 255;

    /** @var string Where the marks sit in an archive (T-42, lot 3). */
    public const PATH = 'hors_activite.json';

    /** @var string The version of its schema. */
    public const SCHEMA_VERSION = '0.1.0';

    /**
     * Say that a competency is outside the candidate's activity.
     *
     * @param int $candidateid The candidate; it must be the current user.
     * @param string $fichecode An imported record.
     * @param string $ficherang The rank of a competency or a bloc of that record.
     * @param string $note Optional, one line.
     * @return \stdClass The row.
     * @throws \moodle_exception errorscopeclosed, errorscopeunknown, errorscopenote, errorscopeduplicate, errorficherankinvalid
     */
    public static function mark(int $candidateid, string $fichecode, string $ficherang, string $note = ''): \stdClass {
        global $DB;

        $context = access::require_can_declare($candidateid);
        // Closed: nothing new. What is already there stays readable and can be taken back (the rule of the other functions).
        if (empty(get_config('tool_vae', 'scopeopen'))) {
            throw new \moodle_exception('errorscopeclosed', 'tool_vae');
        }
        // ASKED HERE NOW, because the reading below no longer asks it for us: the core's listing refused a read
        // with the subsystem off (competenciesarenotenabled), and the record is read without that listing since
        // the backup of the uninstall has to read it where nobody is logged in. Without this a mark could be
        // added while the competencies are off, when everything else of the plugin says they have to be on.
        if (!competency_gateway::is_subsystem_enabled()) {
            throw new \moodle_exception('errorcompetenciesdisabled', 'tool_vae');
        }

        $note = trim($note);
        if (\core_text::strlen($note) > self::NOTE_MAXLENGTH || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $note)) {
            throw new \moodle_exception('errorscopenote', 'tool_vae', '', self::NOTE_MAXLENGTH);
        }
        // THE FORM OF A RANK IS THE ONE OF THE IMPORT, asked at this door as evidence_manager::validate() asks it at its own. The
        // competency may be in the record: tool_lp lets an administrator type `RNCP36832-BC 1` or `-c7` into an imported
        // framework. The mark would be written, exported in hors_activite.json and refused on reimport (errorarchivescope) with the
        // valid marks beside it. Refused here by name, with the words the declaration uses for the same value.
        if (!preg_match(importer::RANK_PATTERN, $ficherang)) {
            throw new \moodle_exception('errorficherankinvalid', 'tool_vae', '', (object) [
                // Escaped: by construction this value is one the pattern refuses, whatever it holds.
                'rank' => s($ficherang),
                'code' => s($fichecode),
                'max' => evidence_manager::RANK_MAXLENGTH,
            ]);
        }
        if (!self::exists_in_record($fichecode, $ficherang)) {
            throw new \moodle_exception('errorscopeunknown', 'tool_vae');
        }
        $where = ['candidateid' => $candidateid, 'fichecode' => $fichecode, 'ficherang' => $ficherang];
        if ($DB->record_exists('tool_vae_scope', $where)) {
            throw new \moodle_exception('errorscopeduplicate', 'tool_vae');
        }

        $row = (object) [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => $candidateid,
            'fichecode' => $fichecode,
            'ficherang' => $ficherang,
            'note' => $note === '' ? null : $note,
            'timecreated' => time(),
        ];
        try {
            $row->id = $DB->insert_record('tool_vae_scope', $row);
        } catch (\dml_write_exception $e) {
            // Two submits at once: the unique index answers for the check above.
            throw new \moodle_exception('errorscopeduplicate', 'tool_vae');
        }

        scope_marked::create([
            'context' => $context,
            'objectid' => (int) $row->id,
            'relateduserid' => $candidateid,
            'other' => ['fichecode' => $fichecode, 'ficherang' => $ficherang],
        ])->trigger();

        return $row;
    }

    /**
     * Take a mark back. Always possible to the candidate, open or closed.
     *
     * @param int $candidateid The candidate; it must be the current user.
     * @param int $id The mark.
     * @throws \moodle_exception errorscopeunknownmark
     */
    public static function unmark(int $candidateid, int $id): void {
        global $DB;

        $context = access::require_can_declare($candidateid);
        $row = $DB->get_record('tool_vae_scope', ['id' => $id, 'candidateid' => $candidateid]);
        if (!$row) {
            throw new \moodle_exception('errorscopeunknownmark', 'tool_vae');
        }

        $DB->delete_records('tool_vae_scope', ['id' => $id]);

        scope_unmarked::create([
            'context' => $context,
            'objectid' => $id,
            'relateduserid' => $candidateid,
            'other' => ['fichecode' => $row->fichecode, 'ficherang' => $row->ficherang],
        ])->trigger();
    }

    /**
     * A candidate's marks. No capability test: the caller has decided who may read this candidate (access::can_view()).
     *
     * @param int $candidateid
     * @return \stdClass[] Keyed by "code-rank", in that order: id, uuid, fichecode, ficherang, note (or null), timecreated.
     */
    public static function for_candidate(int $candidateid): array {
        global $DB;

        $out = [];
        foreach ($DB->get_records('tool_vae_scope', ['candidateid' => $candidateid], 'fichecode ASC, ficherang ASC') as $row) {
            $out[$row->fichecode . '-' . $row->ficherang] = $row;
        }

        return $out;
    }

    /**
     * The document an archive carries (T-42, lot 3): the marks, with the sentence that says what they are.
     *
     * In every archive that holds a mark, whoever exports it: it is the candidate's word about their activity, meant to be
     * read by the panel, like the sections of the dossier. Present whether the site lets a candidate add one or not.
     *
     * @param int $candidateid
     * @return array|null The document of hors_activite.schema.json, or null when the candidate has said nothing.
     */
    public static function document(int $candidateid): ?array {
        $marks = self::for_candidate($candidateid);
        if (!$marks) {
            return null;
        }

        $labels = self::labels($marks);
        $entries = [];
        foreach ($marks as $key => $mark) {
            $entry = ['fiche' => $mark->fichecode, 'rang' => $mark->ficherang];
            if (($labels[$key] ?? '') !== '') {
                $entry['intitule'] = \core_text::substr($labels[$key], 0, 1000);
            }
            if ($mark->note !== null && $mark->note !== '') {
                $entry['note'] = $mark->note;
            }
            $entry['dit_le'] = exporter::iso((int) $mark->timecreated);
            $entries[] = $entry;
        }

        return ['avertissement' => get_string('scopesentence', 'tool_vae'), 'competences' => $entries];
    }

    /**
     * Bring back a mark an archive carries, for the candidate it is imported for.
     *
     * Called by the import, which has already checked who may import: no capability test here. Never over a mark that is there:
     * what the candidate holds on this site stays as it is. A competency this site does not hold is left, not made up. Whether
     * the site lets a candidate add a mark is not asked: it is the candidate's own earlier word, and losing it is worse.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param string $ficherang
     * @param string|null $note Taken as it was said, if the plugin would have accepted it from the candidate.
     * @param int $time When it was said.
     * @param string[]|null $held The ranks of the record as ranks_of() gave them, for an import that restores many marks and
     *        reads the record once; null to ask the record.
     * @return bool Whether a mark was written.
     */
    public static function restore(
        int $candidateid,
        string $fichecode,
        string $ficherang,
        ?string $note,
        int $time,
        ?array $held = null
    ): bool {
        global $DB;

        $where = ['candidateid' => $candidateid, 'fichecode' => $fichecode, 'ficherang' => $ficherang];
        if ($DB->record_exists('tool_vae_scope', $where)) {
            return false;
        }
        $known = $held === null ? self::exists_in_record($fichecode, $ficherang) : in_array($ficherang, $held, true);
        if (!$known) {
            return false;
        }
        $note = $note === null ? '' : trim($note);
        if (\core_text::strlen($note) > self::NOTE_MAXLENGTH || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $note)) {
            $note = '';
        }

        try {
            $DB->insert_record('tool_vae_scope', (object) ($where + [
                'uuid' => identity_map::generate_ulid(),
                'note' => $note === '' ? null : $note,
                'timecreated' => $time,
            ]));
        } catch (\dml_write_exception $e) {
            // The candidate said the same thing here at the same moment: what is there stays.
            return false;
        }

        return true;
    }

    /**
     * The published label of each competency marked, as the dossier shows it.
     *
     * READ THROUGH framework_tree(), NOT THROUGH THE LISTING OF THE CORE: this is called by document(), which
     * every archive and every backup carries, and a backup is made where nobody is logged in - the command
     * line, the uninstall. The core's listing asks moodle/competency:competencyview of $USER, and user 0 does
     * not hold it: the uninstall of the plugin stopped on a refusal as soon as one candidate had a mark.
     *
     * @param \stdClass[] $marks As for_candidate() gives them.
     * @return string[] Keyed like $marks; an empty string for a competency the core no longer holds.
     */
    public static function labels(array $marks): array {
        global $DB;

        // With the competency subsystem off the core answers nothing: the marks are still there, to read and to take back.
        if (!competency_gateway::is_subsystem_enabled()) {
            return array_fill_keys(array_keys($marks), '');
        }

        $gateway = new competency_gateway();
        $byrecord = [];
        $out = [];
        foreach ($marks as $key => $mark) {
            if (!array_key_exists($mark->fichecode, $byrecord)) {
                $source = $DB->get_record('tool_vae_source', ['fichecode' => $mark->fichecode]);
                $byrecord[$mark->fichecode] = [];
                if ($source) {
                    foreach ($gateway->framework_tree((int) $source->frameworkid) as $competency) {
                        $byrecord[$mark->fichecode][$competency->idnumber] = (string) $competency->label;
                    }
                }
            }
            $out[$key] = $byrecord[$mark->fichecode][$key] ?? '';
        }

        return $out;
    }

    /**
     * The ranks a record holds here, a competency or a bloc each, from one reading of its tree.
     *
     * @param string $fichecode
     * @return string[] Empty when the record is not imported here.
     */
    public static function ranks_of(string $fichecode): array {
        global $DB;

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        if (!$source) {
            return [];
        }
        $out = [];
        foreach ((new competency_gateway())->framework_tree((int) $source->frameworkid) as $competency) {
            $rank = passage_manager::rank_of((string) $competency->idnumber, $fichecode);
            if ($rank !== '') {
                $out[] = $rank;
            }
        }

        return $out;
    }

    /**
     * Whether the record is imported here and holds this rank (a competency or a bloc).
     *
     * @param string $fichecode
     * @param string $ficherang
     * @return bool
     */
    private static function exists_in_record(string $fichecode, string $ficherang): bool {
        global $DB;

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        if (!$source) {
            return false;
        }
        foreach ((new competency_gateway())->framework_tree((int) $source->frameworkid) as $competency) {
            if ($competency->idnumber === $fichecode . '-' . $ficherang) {
                return true;
            }
        }

        return false;
    }
}
