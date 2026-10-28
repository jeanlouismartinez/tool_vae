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

use tool_vae\event\competency_left_record;

/**
 * Importing a certification record, and doing it twice without damage.
 *
 * CRITERION 5 IS THE WHOLE POINT: the open data of France compétences is republished often, and
 * an administrator re-imports the same record several times a year. A second pass must update
 * the labels and leave every claim exactly where it was.
 *
 * MATCHING IS ON THE IDNUMBER, `<code>-<rank>`. The rank is required and must not be empty:
 * without it every rankless competency of the same record collapses onto `RNCP36832-`, and a
 * re-import would overwrite one competency with another - silently, and taking the meaning of
 * every claim attached to them with it.
 *
 * WHAT AN IMPORT NEVER DOES:
 *   - it never deletes a competency. One that has left the source is RECORDED as gone, in
 *     tool_vae_obsolete, because a claim made against it is still a claim. That is the
 *     rule of decision 3: an orphaned piece of evidence remains a piece of evidence.
 *   - it never touches a claim, an attestation, or a link between the two.
 *   - it never rewords a label. The Licence Ouverte 2.0 forbids misleading anyone about the
 *     content, the source or the date; the label is stored exactly as published.
 *
 * AND ANY PASS THAT BEGINS TO WRITE LEAVES A DATE. `lastimport` and `lastimportstatus` are
 * written before the first change and completed only on success: an import that dies while
 * leaving an old date reads as a record that is up to date, which is worse than one that fails
 * loudly. A file refused by validation is a different case and writes nothing at all - it never
 * touched the record, the administrator is told why on the spot, and the date on screen stays
 * true. `sourceupdated` moves only on success, for the same reason.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {
    /** @var string A rank as the pivot schema defines it. */
    public const RANK_PATTERN = '/^[A-Z0-9]{1,8}$/';

    /** @var string The competency has left the source. */
    public const GONE = 'missing';

    /** @var string The label or the description was changed locally, outside any import. */
    public const DIVERGED = 'renamed';

    /**
     * @var int Longest label the core can hold.
     *
     * competency.shortname and competency_framework.shortname are both CHAR(100), and the
     * persistent of the core validates neither. Over that length PostgreSQL raises `value too
     * long`, halfway through a file, naming a column the administrator has never heard of;
     * MariaDB in a lax mode would truncate a published label instead, which is worse. Checked
     * here, before anything is written, and named in the message - the lesson of criterion 3.
     */
    public const LABEL_MAXLENGTH = 100;

    /** @var competency_gateway The only door to the core. */
    private $gateway;

    /**
     * The gateway is injectable so that a test can watch what reaches the core.
     *
     * @param competency_gateway|null $gateway
     */
    public function __construct(?competency_gateway $gateway = null) {
        $this->gateway = $gateway ?? new competency_gateway();
    }

    /**
     * Read a deposited file into rows this class understands.
     *
     * THE SEPARATOR IS NOT ASSUMED. A file downloaded from a French open data portal is very
     * often semicolon-separated - that is what a French Excel writes - and a reader hardcoded on
     * the comma turns every line into a single column and then reports a missing header. Each
     * candidate separator is tried, and the first that yields both required columns wins.
     *
     * The core's csv_import_reader does the reading rather than a hand-rolled parser: it handles
     * the encodings and the quoting a real file contains, and getting either wrong silently
     * mangles a label the licence forbids us to alter.
     *
     * THE ENCODING IS NOT GUESSED, IT IS ASKED. core_text::convert() is told what the file is;
     * declaring UTF-8 for a file that is not makes fix_utf8() DELETE every byte it cannot read,
     * and `Accompagner la personne agee` reaches the database with its accents gone - silently,
     * with a green import and a mangled label the licence forbids us to alter. The core's own
     * tool_lpimportcsv asks the same question for the same reason.
     *
     * @param string $content
     * @param string $encoding Encoding of the deposited file, as the administrator declared it.
     * @return array Rows with rang, intitule and description.
     */
    public static function rows_from_csv(string $content, string $encoding = 'UTF-8'): array {
        global $CFG;

        require_once($CFG->libdir . '/csvlib.class.php');

        // An empty file is empty, not unreadable. Said here rather than left to the reader,
        // whose own complaint would send the administrator looking for a malformed row.
        if (trim($content) === '') {
            throw new \moodle_exception('errorimportempty', 'tool_vae');
        }

        // What the reader complained about, kept so the administrator hears the real reason.
        $complaints = [];

        foreach (['comma', 'semicolon', 'tab'] as $separator) {
            $rows = self::read_with($content, $separator, $encoding, $complaints);
            if ($rows !== null) {
                return $rows;
            }
        }

        // A FILE THE READER REJECTED IS NOT A FILE WITHOUT A HEADER. csv_import_reader refuses
        // the whole file when ONE row has the wrong number of columns, and the old code turned
        // that into `no readable header row`, sending an administrator to inspect a first line
        // that was perfectly correct while the fault sat on line 37. Its own message names the
        // offending row; it is passed through, escaped like everything read from the file - see
        // validate_rows().
        if ($complaints) {
            throw new \moodle_exception(
                'errorimportmalformed',
                'tool_vae',
                '',
                s(reset($complaints))
            );
        }

        throw new \moodle_exception('errorimportnoheader', 'tool_vae');
    }

    /**
     * One attempt at reading, with one separator.
     *
     * @param string $content
     * @param string $separator
     * @param string $encoding
     * @param array $complaints Collects what the reader said, by reference.
     * @return array|null Null when this separator does not yield the required columns.
     */
    private static function read_with(
        string $content,
        string $separator,
        string $encoding,
        array &$complaints
    ): ?array {
        $reader = new \csv_import_reader(
            \csv_import_reader::get_new_iid('tool_vae'),
            'tool_vae'
        );

        try {
            $count = $reader->load_csv_content($content, $encoding, $separator);
            if (empty($count)) {
                $error = (string) $reader->get_error();
                if ($error !== '') {
                    $complaints[] = $error;
                }

                return null;
            }

            $columns = array_map(function ($name) {
                return \core_text::strtolower(trim((string) $name));
            }, (array) $reader->get_columns());

            $rang = array_search('rang', $columns, true);
            $intitule = array_search('intitule', $columns, true);
            $description = array_search('description', $columns, true);
            // T-33: the rank of the bloc a line sits under. Absent from a flat file, and then absent from the
            // rows, which is what tells an empty cell (top of the record) from no column at all (nothing said).
            $bloc = array_search('bloc', $columns, true);

            if ($rang === false || $intitule === false) {
                return null;
            }

            $rows = [];
            $reader->init();
            while ($line = $reader->next()) {
                $row = (object) [
                    'rang' => $line[$rang] ?? '',
                    'intitule' => $line[$intitule] ?? '',
                    'description' => $description === false ? '' : ($line[$description] ?? ''),
                ];
                if ($bloc !== false) {
                    $row->bloc = $line[$bloc] ?? '';
                }
                $rows[] = $row;
            }

            return $rows;
        } finally {
            $reader->close();
            $reader->cleanup();
        }
    }

    /**
     * Everything import() decides BEFORE it writes, and nothing written (0.47.0).
     *
     * The preview of an import of records from the export of France compétences has to refuse what the import would refuse -
     * a framework that is not the plugin's, a renamed one, faulty rows, a bloc that cannot go down a level, no competence scale
     * - before the first record of a batch is written, and to say what the import is going to change. This is the first half
     * of import(), unchanged, which calls it: the same checks in the same order, the same exceptions, and not one write.
     *
     * The metadata of the caller are left alone: the code is read in capitals on a copy, and given back in `meta`.
     *
     * @param \stdClass $meta fichecode, fichetype, name, sourceurl, sourceupdated, licence, attribution.
     * @param array $rows As import() takes them.
     * @return \stdClass meta (the copy that was checked), rows (the rows that were checked), previous (the provenance row
     *         the site holds for this code, or null), sourcemoved (whether the date of the source differs from the one
     *         held), frameworkid (the framework this record is written into, null when there is none yet), adopted
     *         (whether that framework is taken over rather than recorded) and forecast: the figures import() will give
     *         (created, updated, gone, diverged), read from what the site holds with the rule of apply(), or null when
     *         a row carries a bloc (T-33: the places are not forecast).
     * @throws \moodle_exception What import() throws before it writes: errorimportmetamissing and the other refusals of the
     *         metadata, errorimportempty, errorimportrows, errorimportframeworkrenamed, errorimportframeworkforeign,
     *         errornocompetencescale.
     */
    public function check(\stdClass $meta, array $rows): \stdClass {
        global $DB;

        $meta = $this->validate_meta(clone $meta);
        $rows = $this->validate_rows($meta, $rows);

        // HAS THE SOURCE ITSELF MOVED SINCE THE LAST PASS? Read BEFORE the attempt overwrites
        // it. A label that differs while the publication date has not changed was not changed
        // by France compétences - it was changed on this site, in tool_lp. The two cases look
        // identical row by row, and only this date tells them apart.
        // Read before the framework is looked for: the row says which framework is the record's.
        $previous = $DB->get_record('tool_vae_source', ['fichecode' => $meta->fichecode]);
        $sourcemoved = !$previous || (int) $previous->sourceupdated !== (int) $meta->sourceupdated;

        [$frameworkid, $adopted] = $this->resolve_framework($meta, $previous ?: null);
        $this->check_demotions($frameworkid, $meta, $rows);

        // A FRAMEWORK THAT CANNOT BE CREATED IS FOUND OUT BEFORE ANYTHING IS WRITTEN. The attempt of import() is written first
        // and outside the transaction, so that a pass that dies leaves its trace; and a first import with no competence scale
        // to give the framework died just after it, leaving a provenance row with frameworkid 0 for a record nobody could ever
        // declare on. That one is a refusal like a malformed file, which writes nothing: the administrator is told why on the
        // spot.
        if ($frameworkid === null) {
            competency_gateway::default_competence_scale();
        }

        return (object) [
            'meta' => $meta,
            'rows' => $rows,
            'previous' => $previous ?: null,
            'sourcemoved' => $sourcemoved,
            'frameworkid' => $frameworkid,
            'adopted' => $adopted,
            'forecast' => $this->forecast($frameworkid, $meta, $rows, $sourcemoved),
        ];
    }

    /**
     * Import one certification record.
     *
     * @param \stdClass $meta fichecode, fichetype, sourceurl, sourceupdated, licence, attribution, name
     * @param array $rows Each with a `rang` and an `intitule`, and optionally a `description` and a `bloc` (the
     *        rank of the bloc it sits under, empty for a bloc or for the top of the record; T-33).
     * @return \stdClass created, updated, moved (competencies put under another bloc), gone, diverged, the
     *         framework id, adopted (whether the framework already existed on the site with no source
     *         row of this plugin to say it was its own: left by an earlier install, and taken over) and sourceid
     *         (the provenance row).
     * @throws \moodle_exception errorimportframeworkrenamed, errorimportframeworkforeign, errornocompetencescale
     */
    public function import(\stdClass $meta, array $rows): \stdClass {
        global $DB;

        // Everything that can be refused is refused here, before the first write (see check()).
        $check = $this->check($meta, $rows);
        $meta = $check->meta;
        $rows = $check->rows;
        $sourcemoved = $check->sourcemoved;
        $frameworkid = $check->frameworkid;
        $adopted = $check->adopted;

        // The provenance row is written FIRST, with a failed status, and OUTSIDE the transaction
        // below: it is the only thing a pass that dies must leave behind. Written inside, it
        // would roll back with everything else and the failure would vanish.
        $sourceid = $this->open_attempt($meta, $frameworkid);

        // EVERYTHING THAT TOUCHES THE RECORD IS ONE ACT. Without this, a file whose 40th row is
        // refused by the database leaves 39 competencies created and 11 missing, with nothing
        // saying which - and a candidate who claims against that half-built record during the
        // window anchors a piece of evidence to a framework that was never finished.
        // The subsystem buffers its events until commit, so wrapping its api is legitimate;
        // the only thing forbidden in here is a redirect, and import.php redirects on return.
        //
        // THE ROLLBACK IS EXPLICIT AND IT HAS TO BE. Letting the exception escape a delegated
        // transaction does NOT undo what it holds: it leaves the transaction dangling, and the
        // measured result was two competencies of four sitting in the database after a failure.
        // $transaction->rollback() re-throws the exception it is given, so nothing is swallowed.
        $transaction = $DB->start_delegated_transaction();

        try {
            if ($frameworkid === null) {
                $frameworkid = $this->gateway->create_framework($meta->fichecode, $meta->name);
                $DB->set_field('tool_vae_source', 'frameworkid', $frameworkid, ['id' => $sourceid]);
            }

            $result = $this->apply($frameworkid, $meta, $rows, $sourcemoved, $sourceid);

            // THE PUBLICATION DATE MOVES ONLY ON SUCCESS. Advancing it in open_attempt made the
            // next pass believe the source had not moved, so every label France competences had
            // really changed was recorded as a local divergence - and, until the retry, the
            // provenance line announced a version of the record the site did not hold.
            $DB->update_record('tool_vae_source', (object) [
                'id' => $sourceid,
                'lastimportstatus' => 'ok',
                'sourceupdated' => (int) $meta->sourceupdated,
                'timemodified' => time(),
            ]);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        $result->frameworkid = $frameworkid;
        $result->adopted = $adopted;
        $result->sourceid = $sourceid;

        return $result;
    }

    /**
     * The framework this record is written into: the one the plugin recorded for it, else one carrying its code.
     *
     * THE SOURCE ROW COMES FIRST, NOT THE IDENTIFIER. tool_vae_source.frameworkid is what ties a record to its
     * framework, and everything else - the declarations, the dossiers, the course screen, the correspondences -
     * follows it. The identifier of the framework is a free text field of the core's form: an administrator who
     * changed it made find_framework() answer null, the import created a SECOND framework, the row moved to it, and
     * the declarations already made stayed on a first one that no source claimed any more - silent, green, and
     * permanent, since the first one cannot be deleted once somebody has declared on it. Now the identifier is
     * looked at only to be sure it still is the record's, and a framework that no longer carries it is REFUSED by
     * name rather than put back: undoing an administrator's act in silence is not this tool's to do.
     *
     * A framework looked for by its identifier alone - no source row, a source row that never got a framework, or
     * one whose framework has been deleted - is adopted only if it holds nothing this tool did not write
     * (adopt_check()). Re-importing after an uninstall is the case that must keep working: the framework is left
     * behind by db/uninstall.php, and import_dossier.php needs it again.
     *
     * @param \stdClass $meta Validated.
     * @param \stdClass|null $previous The row of tool_vae_source for this code, if any.
     * @return array [framework id or null when there is none to write into, whether it was adopted]
     * @throws \moodle_exception errorimportframeworkrenamed, errorimportframeworkforeign
     */
    private function resolve_framework(\stdClass $meta, ?\stdClass $previous): array {
        if ($previous && (int) $previous->frameworkid > 0) {
            $idnumber = $this->gateway->framework_idnumber((int) $previous->frameworkid);

            if ($idnumber !== null) {
                // Case apart: MySQL finds a record by an identifier written in another case, PostgreSQL does not,
                // and a change of case alone is not the act this refusal is about.
                if (\core_text::strtolower($idnumber) !== \core_text::strtolower($meta->fichecode)) {
                    throw new \moodle_exception('errorimportframeworkrenamed', 'tool_vae', '', (object) [
                        'code' => s($meta->fichecode),
                        'id' => (int) $previous->frameworkid,
                        'idnumber' => s($idnumber),
                    ]);
                }

                return [(int) $previous->frameworkid, false];
            }
        }

        $frameworkid = $this->gateway->find_framework($meta->fichecode);
        if ($frameworkid === null) {
            return [null, false];
        }

        $this->adopt_check($meta, $frameworkid);

        return [$frameworkid, true];
    }

    /**
     * Refuse to adopt a framework that holds competencies this tool did not write.
     *
     * A first import took over ANY framework whose identifier was the code, whoever had made it: tool_lpimportcsv
     * or a hand-built framework of forty competencies "C1.1", "C1.2"... used in an organisation's training plans.
     * The import then wrote `<code>-<rank>` blocs into it, marked the forty as gone from the record, announced a
     * departure for each, and tied the record to that framework for good. What this tool writes always has the
     * identifier `<code>-<rank>`; a competency that does not is somebody else's, and the framework is refused,
     * before anything is written, with the number found.
     *
     * Not refused: a framework holding nothing, or only `<code>-...` competencies. That is what an uninstall leaves
     * behind (db/uninstall.php keeps the frameworks), and refusing it would make a reinstall unable to rebuild
     * the dossiers.
     *
     * @param \stdClass $meta Validated.
     * @param int $frameworkid
     * @throws \moodle_exception errorimportframeworkforeign
     */
    private function adopt_check(\stdClass $meta, int $frameworkid): void {
        $prefix = $meta->fichecode . '-';
        $foreign = 0;
        foreach ($this->gateway->list_framework_competencies($frameworkid) as $competency) {
            if (strncmp((string) $competency->idnumber, $prefix, strlen($prefix)) !== 0) {
                $foreign++;
            }
        }

        if ($foreign > 0) {
            throw new \moodle_exception('errorimportframeworkforeign', 'tool_vae', '', (object) [
                'code' => s($meta->fichecode),
                'count' => $foreign,
            ]);
        }
    }

    /**
     * Refuse a file that would leave a third level behind (T-33).
     *
     * The two-level rule of validate_rows() reads the file alone. The record on this site may hold more: a bloc that
     * still has competencies under it, from an earlier import or from tool_lp. A file that puts that bloc under
     * another one would hang those competencies at the third level, and what the presentation reads would no
     * longer be true. Refused before anything is written.
     *
     * @param int|null $frameworkid The record on this site, null on a first import.
     * @param \stdClass $meta
     * @param \stdClass[] $rows Validated rows.
     * @throws \moodle_exception errorimportrows
     */
    private function check_demotions(?int $frameworkid, \stdClass $meta, array $rows): void {
        if ($frameworkid === null) {
            return;
        }

        $existing = $this->gateway->list_framework_competencies($frameworkid);
        $withchildren = [];
        foreach ($existing as $competency) {
            if ($competency->parentid > 0) {
                $withchildren[$competency->parentid] = true;
            }
        }

        $byidnumber = array_column($existing, null, 'idnumber');
        $faults = [];
        foreach ($rows as $number => $row) {
            if (($row->bloc ?? '') === '') {
                continue;
            }
            $competency = $byidnumber[$meta->fichecode . '-' . $row->rang] ?? null;
            if ($competency && isset($withchildren[$competency->id])) {
                $faults[] = get_string('errorimportblocdemote', 'tool_vae', (object) [
                    'line' => $number + 1,
                    'rank' => $row->rang,
                ]);
            }
        }
        if ($faults) {
            throw new \moodle_exception('errorimportrows', 'tool_vae', '', implode(' ', $faults));
        }
    }

    /**
     * Compare the record with what the site already holds, and reconcile.
     *
     * @param int $frameworkid
     * @param \stdClass $meta
     * @param array $rows
     * @param bool $sourcemoved Whether the record itself was republished since the last pass.
     * @param int $sourceid
     * @return \stdClass
     */
    private function apply(
        int $frameworkid,
        \stdClass $meta,
        array $rows,
        bool $sourcemoved,
        int $sourceid
    ): \stdClass {
        global $DB;

        $existing = $this->gateway->list_framework_competencies($frameworkid);
        $byidnumber = [];
        foreach ($existing as $competency) {
            $byidnumber[$competency->idnumber] = $competency;
        }

        // MARKS THAT NAME NOTHING GO. tool_vae_obsolete points at competencies by an id the database does not
        // enforce, and the core lets an administrator delete a framework, or a competency, that nobody has
        // declared on. The marks stayed: the import that rebuilt the framework found every competency new, so
        // the code below never met their ids, and two screens counted competencies "gone from the record" that
        // no longer exist, for ever, whatever the next files said. A mark on a competency the site does not hold
        // marks nothing; the first import to see it is the one that cleans it.
        $stale = [];
        foreach ($DB->get_records('tool_vae_obsolete', ['sourceid' => $sourceid], '', 'id, competencyid') as $mark) {
            if (!isset($existing[(int) $mark->competencyid])) {
                $stale[] = (int) $mark->id;
            }
        }
        if ($stale) {
            $DB->delete_records_list('tool_vae_obsolete', 'id', $stale);
        }

        // Departures recorded by earlier passes, keyed by competency.
        $gone = $DB->get_records_menu(
            'tool_vae_obsolete',
            ['sourceid' => $sourceid, 'kind' => self::GONE],
            '',
            'competencyid, id'
        );

        $result = (object) ['created' => 0, 'updated' => 0, 'moved' => 0, 'gone' => 0, 'diverged' => 0];
        $seen = [];

        // THE BLOCS FIRST (T-33): a competency is created under a bloc that already exists. A file without the
        // column has no bloc and keeps its order. Ids are kept by idnumber, for the ones created and the ones
        // already there.
        $top = array_filter($rows, fn($row) => ($row->bloc ?? '') === '');
        $under = array_filter($rows, fn($row) => ($row->bloc ?? '') !== '');
        $ids = [];

        foreach (array_merge($top, $under) as $row) {
            $idnumber = $meta->fichecode . '-' . $row->rang;
            $seen[$idnumber] = true;

            // Where the file puts it: null when the file says nothing about blocs, 0 for the top, else the bloc.
            $parentid = null;
            if (isset($row->bloc)) {
                $parentid = $row->bloc === '' ? 0 : $ids[$meta->fichecode . '-' . $row->bloc];
            }

            if (!isset($byidnumber[$idnumber])) {
                $ids[$idnumber] = $this->gateway->create_competency(
                    $frameworkid,
                    $idnumber,
                    $row->intitule,
                    $row->description,
                    (int) $parentid
                );
                $result->created++;

                continue;
            }

            $competency = $byidnumber[$idnumber];
            $ids[$idnumber] = (int) $competency->id;

            // Under another bloc than the one it sat under: only its place changes (T-33). The declarations made
            // against it are untouched, they hold the competency and not its place.
            if ($parentid !== null && $parentid !== (int) $competency->parentid) {
                $this->gateway->set_parent_competency((int) $competency->id, $parentid);
                $result->moved++;
            }

            // BACK IN THE RECORD: a departure recorded by an earlier pass no longer holds, and its
            // mark goes. The screens stop counting the competency as gone, a departure still
            // waiting to be announced is not announced (notifier::DEPARTURE_GRACE), and a later
            // departure is announced as the new fact it is. Until lot 7 ter the mark stayed for
            // ever - harmless while nobody was told, a false and lasting message once they were.
            if (isset($gone[(int) $competency->id])) {
                $DB->delete_records('tool_vae_obsolete', ['id' => $gone[(int) $competency->id]]);
            }

            // THE DESCRIPTION IS PUBLISHED TEXT TOO. Comparing the label alone left a description
            // France compétences had rewritten on its old wording for ever, under an import that
            // said ok and a publication date that had moved. The format counts: the importer
            // writes plain text, and a description saved through tool_lp's editor is no longer it.
            if (!self::differs($competency, $row)) {
                continue;
            }

            // The label or the description differs. If the record was not republished, the
            // change came from this site - somebody edited the competency in tool_lp. Recorded as
            // a divergence, and the published text put back: the licence forbids presenting
            // anything else as the source's wording.
            if (!$sourcemoved) {
                $this->mark((int) $competency->id, $sourceid, self::DIVERGED);
                $result->diverged++;
            }

            $this->gateway->update_competency((int) $competency->id, $row->intitule, $row->description);
            $result->updated++;
        }

        // What the site holds and the record no longer does. Recorded, never deleted.
        foreach ($byidnumber as $idnumber => $competency) {
            if (isset($seen[$idnumber])) {
                continue;
            }

            $markid = $this->mark((int) $competency->id, $sourceid, self::GONE);
            $result->gone++;

            // ANNOUNCED ONCE, BY THE PASS THAT SEES THE DEPARTURE - not by every later pass that
            // finds it still gone. The candidates who declared against it are told (lot 7 ter),
            // after the commit and not before: the observer is external, so a pass that rolls
            // back takes this announcement with it. And a day later, if it still stands.
            if ($markid !== null) {
                competency_left_record::create([
                    'context' => \context_system::instance(),
                    'objectid' => $markid,
                    'other' => [
                        'competencyid' => (int) $competency->id,
                        'sourceid' => $sourceid,
                    ],
                ])->trigger();
            }
        }

        return $result;
    }

    /**
     * Whether the site holds a competency that says other than the row: the label, the description or its format.
     *
     * THE ONE COPY OF THE RULE. apply() updates what this says differs, and forecast() counts it: the preview of an import
     * and the import cannot disagree about what a pass is going to change.
     *
     * @param \stdClass $competency As competency_gateway::list_framework_competencies() gives it.
     * @param \stdClass $row A validated row.
     * @return bool
     */
    private static function differs(\stdClass $competency, \stdClass $row): bool {
        return $competency->shortname !== $row->intitule
            || (string) $competency->descriptionformat !== FORMAT_PLAIN
            || !self::same_text($competency->description, $row->description);
    }

    /**
     * What a pass will give, read from what the site holds, without writing: the figures of apply().
     *
     * @param int|null $frameworkid The record on this site, null on a first import.
     * @param \stdClass $meta Validated.
     * @param \stdClass[] $rows Validated rows.
     * @param bool $sourcemoved Whether the record itself was republished since the last pass.
     * @return \stdClass|null created, updated, gone and diverged; null when a row carries a bloc.
     */
    private function forecast(?int $frameworkid, \stdClass $meta, array $rows, bool $sourcemoved): ?\stdClass {
        foreach ($rows as $row) {
            if (isset($row->bloc)) {
                return null;
            }
        }

        $byidnumber = [];
        foreach ($frameworkid === null ? [] : $this->gateway->list_framework_competencies($frameworkid) as $competency) {
            $byidnumber[$competency->idnumber] = $competency;
        }

        $forecast = (object) ['created' => 0, 'updated' => 0, 'gone' => 0, 'diverged' => 0];
        $seen = [];
        foreach ($rows as $row) {
            $idnumber = $meta->fichecode . '-' . $row->rang;
            $seen[$idnumber] = true;
            if (!isset($byidnumber[$idnumber])) {
                $forecast->created++;
            } else if (self::differs($byidnumber[$idnumber], $row)) {
                // Not republished: the change came from this site, and is recorded as a divergence as it is put back.
                if (!$sourcemoved) {
                    $forecast->diverged++;
                }
                $forecast->updated++;
            }
        }
        foreach (array_keys($byidnumber) as $idnumber) {
            if (!isset($seen[$idnumber])) {
                $forecast->gone++;
            }
        }

        return $forecast;
    }

    /**
     * Record the state a competency is in with respect to its source.
     *
     * ONE ROW PER COMPETENCY AND SOURCE, WHICH IS WHAT THE UNIQUE INDEX SAYS. The kind is not
     * part of that key: a competency that was renamed locally and has since left the record is
     * in one state, the second, not in two at once. Keying the existence test on the kind while
     * the index ignored it made that exact sequence - renamed, then gone - die on a duplicate
     * key, halfway through an import.
     *
     * The date moves with the kind, because a change of state is a new fact, dated.
     *
     * @param int $competencyid
     * @param int $sourceid
     * @param string $kind
     * @return int|null The row id when the state changed; null when it already stood, which is
     *                  what tells the caller there is nothing new to announce.
     */
    private function mark(int $competencyid, int $sourceid, string $kind): ?int {
        global $DB;

        $key = ['competencyid' => $competencyid, 'sourceid' => $sourceid];
        $existing = $DB->get_record('tool_vae_obsolete', $key);

        if ($existing) {
            if ($existing->kind === $kind) {
                return null;
            }

            $DB->update_record('tool_vae_obsolete', (object) [
                'id' => $existing->id,
                'kind' => $kind,
                'detectedat' => time(),
            ]);

            return (int) $existing->id;
        }

        return (int) $DB->insert_record(
            'tool_vae_obsolete',
            (object) ($key + ['kind' => $kind, 'detectedat' => time()])
        );
    }

    /**
     * Write the attempt down before doing anything that can fail.
     *
     * @param \stdClass $meta
     * @param int|null $frameworkid
     * @return int The provenance row id.
     */
    private function open_attempt(\stdClass $meta, ?int $frameworkid): int {
        global $DB, $USER;

        $now = time();
        $record = (object) [
            'frameworkid' => $frameworkid ?? 0,
            'fichecode' => $meta->fichecode,
            'fichetype' => $meta->fichetype,
            'sourceurl' => $meta->sourceurl,
            'licence' => $meta->licence,
            'attribution' => $meta->attribution,
            'lastimport' => $now,
            'lastimportstatus' => 'error',
            'timemodified' => $now,
            'usermodified' => (int) $USER->id,
        ];

        // The publication date is deliberately absent from the update: it belongs to the version
        // of the record the site actually holds, and this attempt has not yet delivered one.
        $existing = $DB->get_record('tool_vae_source', ['fichecode' => $meta->fichecode]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('tool_vae_source', $record);

            return (int) $existing->id;
        }

        $record->timecreated = $now;
        $record->sourceupdated = 0;

        return (int) $DB->insert_record('tool_vae_source', $record);
    }

    /**
     * Check what the administrator typed, before anything is written.
     *
     * @param \stdClass $meta
     * @return \stdClass
     */
    private function validate_meta(\stdClass $meta): \stdClass {
        foreach (['fichecode', 'fichetype', 'licence', 'attribution', 'name'] as $field) {
            if (trim((string) ($meta->$field ?? '')) === '') {
                throw new \moodle_exception('errorimportmetamissing', 'tool_vae', '', $field);
            }
        }

        if (!in_array($meta->fichetype, ['rncp', 'rs'], true)) {
            throw new \moodle_exception('errorimportfichetype', 'tool_vae');
        }

        // THE CODE IS FOLDED TO UPPERCASE. `rncp36832` and `RNCP36832` are the same record, and
        // find_framework() compares in SQL: MySQL retrieves one with the other, PostgreSQL does
        // not. The same gesture would give a clean re-import on one site and a duplicated
        // framework on the next.
        $meta->fichecode = \core_text::strtoupper(trim($meta->fichecode));

        if (\core_text::strlen($meta->name) > self::LABEL_MAXLENGTH) {
            throw new \moodle_exception('errorimportnametoolong', 'tool_vae', '', (object) [
                'max' => self::LABEL_MAXLENGTH,
                'length' => \core_text::strlen($meta->name),
            ]);
        }

        if (\core_text::strlen($meta->fichecode) > evidence_manager::CODE_MAXLENGTH) {
            throw new \moodle_exception('errorfichecodetoolong', 'tool_vae', '', (object) [
                'code' => $meta->fichecode,
                'max' => evidence_manager::CODE_MAXLENGTH,
            ]);
        }

        if (empty($meta->sourceupdated)) {
            throw new \moodle_exception('errorimportnodate', 'tool_vae');
        }

        return $meta;
    }

    /**
     * Check every row, before anything is written.
     *
     * All of them, not the first bad one: an administrator who has to re-run an import once per
     * mistake in a file of ninety competencies will stop using the tool.
     *
     * @param \stdClass $meta
     * @param array $rows
     * @return array
     */
    private function validate_rows(\stdClass $meta, array $rows): array {
        if (!$rows) {
            throw new \moodle_exception('errorimportempty', 'tool_vae');
        }

        $faults = [];
        $seen = [];
        $clean = [];

        foreach ($rows as $number => $row) {
            // The row's rank in the file, header excluded. NOT a line number: the core's reader
            // drops blank lines while loading, so counting lines would name row 12 for a fault
            // that sits on line 13. errorimportrows says which counting this is.
            $line = $number + 1;
            $rang = \core_text::strtoupper(self::tidy((string) ($row->rang ?? '')));
            $intitule = self::tidy((string) ($row->intitule ?? ''));

            if ($rang === '') {
                $faults[] = get_string('errorimportnorank', 'tool_vae', $line);
            } else if (!preg_match(self::RANK_PATTERN, $rang)) {
                // ESCAPED HERE, WHERE THE CELL ENTERS THE MESSAGE. import.php shows the message in
                // a notification that renders HTML, and a cell reading `<img src=x onerror=...>`
                // would run in the administrator's session. Escaping at the page instead would
                // double-escape the messages of dossier_importer, which escape at the source too.
                // The repeated rank below has matched RANK_PATTERN, and holds nothing to escape.
                $faults[] = get_string('errorimportbadrank', 'tool_vae', (object) [
                    'line' => $line,
                    'rank' => s($rang),
                ]);
            } else if (isset($seen[$rang])) {
                $faults[] = get_string('errorimportduplicaterank', 'tool_vae', (object) [
                    'line' => $line,
                    'rank' => $rang,
                ]);
            }

            if ($intitule === '') {
                $faults[] = get_string('errorimportnolabel', 'tool_vae', $line);
            } else if (\core_text::strlen($intitule) > self::LABEL_MAXLENGTH) {
                $faults[] = get_string('errorimportlabeltoolong', 'tool_vae', (object) [
                    'line' => $line,
                    'max' => self::LABEL_MAXLENGTH,
                    'length' => \core_text::strlen($intitule),
                ]);
            }

            $seen[$rang] = true;
            $entry = (object) [
                'rang' => $rang,
                'intitule' => $intitule,
                'description' => self::tidy((string) ($row->description ?? '')),
            ];

            // T-33: the bloc this line sits under. Present only when the file has the column.
            if (isset($row->bloc)) {
                $bloc = \core_text::strtoupper(self::tidy((string) $row->bloc));
                if ($bloc !== '' && !preg_match(self::RANK_PATTERN, $bloc)) {
                    $faults[] = get_string('errorimportbadrank', 'tool_vae', (object) [
                        'line' => $line,
                        'rank' => s($bloc),
                    ]);
                    $bloc = '';
                }
                $entry->bloc = $bloc;
            }
            $clean[] = $entry;
        }

        // What one line cannot say alone: the bloc is a line of the same file, it is not under another bloc itself
        // (two levels at most), and it is not the line itself.
        $blocof = [];
        foreach ($clean as $entry) {
            $blocof[$entry->rang] = $entry->bloc ?? '';
        }
        foreach ($clean as $number => $entry) {
            $bloc = $entry->bloc ?? '';
            if ($bloc === '') {
                continue;
            }
            $line = $number + 1;
            if ($bloc === $entry->rang) {
                $faults[] = get_string('errorimportblocself', 'tool_vae', (object) ['line' => $line, 'rank' => $bloc]);
            } else if (!isset($blocof[$bloc])) {
                $faults[] = get_string('errorimportblocunknown', 'tool_vae', (object) ['line' => $line, 'rank' => $bloc]);
            } else if ($blocof[$bloc] !== '') {
                $faults[] = get_string('errorimportblocdepth', 'tool_vae', (object) ['line' => $line, 'rank' => $bloc]);
            }
        }

        if ($faults) {
            throw new \moodle_exception('errorimportrows', 'tool_vae', '', implode(' ', $faults));
        }

        return $clean;
    }

    /**
     * Whether two descriptions say the same thing, as far as an import can tell.
     *
     * Line endings and the whitespace tidy() removes are not wording: a file re-exported on
     * another system, or a database that hands back what it stored with its own line endings,
     * would otherwise report every description as updated on every pass.
     *
     * @param string $stored
     * @param string $published
     * @return bool
     */
    private static function same_text(string $stored, string $published): bool {
        $normalise = function (string $text): string {
            return self::tidy(str_replace(["\r\n", "\r"], "\n", $text));
        };

        return $normalise($stored) === $normalise($published);
    }

    /**
     * Strip the whitespace a spreadsheet leaves behind, including the kind trim() cannot see.
     *
     * A NO-BREAK SPACE IS INVISIBLE AND CHANGES EVERYTHING. PHP's trim() does not remove U+00A0,
     * which a French word processor inserts before every colon and semicolon and which pasting
     * into a spreadsheet carries along. A label that keeps a trailing one differs, byte for
     * byte, from the same label re-exported cleanly - and the next import records that as a
     * local divergence nobody made.
     *
     * @param string $value
     * @return string
     */
    private static function tidy(string $value): string {
        // U+00A0 no-break space, U+202F narrow no-break space, U+FEFF zero-width no-break space.
        $value = preg_replace('/^[\s\x{00A0}\x{202F}\x{FEFF}]+|[\s\x{00A0}\x{202F}\x{FEFF}]+$/u', '', $value);

        return $value === null ? '' : $value;
    }
}
