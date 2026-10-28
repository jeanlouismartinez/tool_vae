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

use tool_vae\event\export_fetched;
use tool_vae\event\record_imported;

/**
 * Importing records from the export of France compétences, uploaded or fetched (T-31, 0.47.0).
 *
 * THE ONE ORCHESTRATOR OF THE TWO WAYS. Fetching the export is only uploading it preceded by a download: both end in a path to
 * a zip file, and from there they are the same - read the archive, convert the records asked for, check each against what the
 * site holds WITHOUT WRITING, keep the plan, show it; and, on confirmation, write the records one by one with importer::import,
 * the same door as the CSV file. The provenance (source, licence, attribution) is written by the plugin, from constants: nothing
 * to type, nothing to mistype.
 *
 *   - RIGHTS FIRST, AT EVERY ENTRY: capability to import, subsystem of the competencies enabled, and the right to manage the
 *     frameworks, before a file is read or a request goes out. A person who cannot import never makes the server download.
 *   - NOTHING IS WRITTEN BEFORE THE CONFIRMATION: from_upload() and from_data_gouv() write no record, no provenance row and no
 *     event of the core; the plan they keep is a file of a few kilobytes, never the archive.
 *   - RECORD BY RECORD, NEVER IN ONE TRANSACTION: importer::import() has its own, and writes the provenance row of an attempt
 *     OUTSIDE it, so that a record that fails leaves its trace. A transaction around the batch would erase it. A record that
 *     the importer refuses (a framework that is not the plugin's, a renamed one) is noted and the others go on; an unexpected
 *     failure (the database) stops the loop and the records not tried are said so.
 *   - ONE AT A TIME: a lock that is not waited for protects the preparation and the writing ("another import is running"), and
 *     the day of the export is read, never guessed.
 *   - AN EXPORT OLDER THAN THE VERSION THE SITE HOLDS IS SAID, and imported only when asked: the plugin informs and asks for a
 *     gesture, it does not forbid.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fiche_import {
    /** @var string The licence written with every record: the one of the dataset (Licence Ouverte 2.0). */
    public const LICENCE = 'Licence Ouverte 2.0';

    /** @var string The attribution written with every record. */
    public const ATTRIBUTION = 'France compétences';

    /** @var string The page of the dataset: the same for both ways, a page a person can open (the link of a file changes daily). */
    public const SOURCE_URL = 'https://www.data.gouv.fr/datasets/'
        . 'repertoire-national-des-certifications-professionnelles-et-repertoire-specifique';

    /** @var string The export was uploaded. */
    public const ORIGIN_UPLOAD = 'upload';

    /** @var string The export was fetched from data.gouv.fr. */
    public const ORIGIN_FETCH = 'fetch';

    /** @var string A record number. */
    public const NUMBER_PATTERN = '/^(?:RNCP|RS)[0-9]{1,8}$/D';

    /** @var int The most numbers at a time. */
    public const MAX_NUMBERS = 20;

    /** @var int The longest text of numbers read. */
    public const MAX_NUMBERS_TEXT = 2000;

    /** @var int An export older than this many days is said to be. */
    public const STALE_DAYS = 7;

    /** @var int A gap larger than this many days between the date of the export and that of its files is said to be. */
    public const GAP_DAYS = 3;

    /** @var string The lock of the import. */
    private const LOCKS = 'tool_vae_fiche_import';

    /** @var importer The door to the core. */
    private importer $importer;

    /** @var fiche_fetcher The door to the Internet. */
    private fiche_fetcher $fetcher;

    /**
     * The orchestrator.
     *
     * @param importer|null $importer Injectable, so that a test can watch what reaches the core.
     * @param fiche_fetcher|null $fetcher Injectable.
     */
    public function __construct(?importer $importer = null, ?fiche_fetcher $fetcher = null) {
        $this->importer = $importer ?? new importer();
        $this->fetcher = $fetcher ?? new fiche_fetcher();
    }

    /**
     * Why an administrator cannot import here, as the identifier of a sentence of the plugin.
     *
     * @param \context $context
     * @return string|null errorcompetenciesdisabled, errorimportnocompetencymanage, or null when nothing prevents it.
     */
    public static function unavailable(\context $context): ?string {
        if (!competency_gateway::is_subsystem_enabled()) {
            return 'errorcompetenciesdisabled';
        }
        if (!competency_gateway::can_manage_frameworks($context)) {
            return 'errorimportnocompetencymanage';
        }

        return null;
    }

    /**
     * The rights, asked before anything is read or requested.
     *
     * @throws \required_capability_exception tool/vae:import is missing.
     * @throws \moodle_exception The competencies are disabled, or the right to manage the frameworks is missing.
     */
    public static function guard(): void {
        $context = \context_system::instance();
        require_capability('tool/vae:import', $context);
        $key = self::unavailable($context);
        if ($key !== null) {
            throw new \moodle_exception($key, 'tool_vae');
        }
    }

    /**
     * The numbers a person typed.
     *
     * One per line, or separated by commas or semicolons; `RNCP 40692` is read as `RNCP40692`; the case does not matter; a number
     * typed twice is read once and said. They filter the lines of the export and build no address.
     *
     * @param string $text
     * @return \stdClass numbers (in capitals, once each, in the order typed) and repeated.
     * @throws fiche_refusal NO_NUMBER, BAD_NUMBER (the first token that is not one), TOO_MANY.
     */
    public static function parse_numbers(string $text): \stdClass {
        if (mb_strlen($text, 'UTF-8') > self::MAX_NUMBERS_TEXT) {
            throw new fiche_refusal(fiche_refusal::TOO_MANY, ['max' => self::MAX_NUMBERS], 'Too many numbers.');
        }

        $text = preg_replace('/\b(RNCP|RS)\s+(\d)/i', '$1$2', $text) ?? '';
        $tokens = preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) {
            throw new fiche_refusal(fiche_refusal::NO_NUMBER, [], 'Enter at least one record number.');
        }

        $numbers = [];
        $repeated = [];
        foreach ($tokens as $typed) {
            $token = mb_strtoupper($typed, 'UTF-8');
            if (!preg_match(self::NUMBER_PATTERN, $token)) {
                // Named as it was typed: that is what the person looks for in their own text.
                throw new fiche_refusal(
                    fiche_refusal::BAD_NUMBER,
                    ['token' => fiche_messages::shorten($typed)],
                    'Not a record number.'
                );
            }
            if (isset($numbers[$token])) {
                $repeated[$token] = $token;
            }
            $numbers[$token] = $token;
        }
        if (count($numbers) > self::MAX_NUMBERS) {
            throw new fiche_refusal(fiche_refusal::TOO_MANY, ['max' => self::MAX_NUMBERS], 'Too many numbers.');
        }

        return (object) ['numbers' => array_values($numbers), 'repeated' => array_values($repeated)];
    }

    /**
     * The codes of the records already imported, in order, as many as the form takes at a time.
     *
     * @return string[]
     */
    public static function known_numbers(): array {
        return array_slice(self::known_codes(), 0, self::MAX_NUMBERS);
    }

    /**
     * How many records were imported, in all: more than the form takes back at a time, and the link says so.
     *
     * @return int
     */
    public static function known_total(): int {
        return count(self::known_codes());
    }

    /**
     * The codes of the records that were imported once, and that the form can read.
     *
     * A row of provenance with no date of source is the trace of an attempt that failed: nothing was imported. A code that is not
     * a number of a record (the CSV screen takes any code) is left out, since the form would refuse it.
     *
     * @return string[]
     */
    private static function known_codes(): array {
        global $DB;

        $codes = $DB->get_fieldset_select('tool_vae_source', 'fichecode', 'sourceupdated > 0 ORDER BY fichecode');

        return array_values(array_filter($codes, fn($code) => preg_match(self::NUMBER_PATTERN, (string) $code) === 1));
    }

    /**
     * The day of an export, read from the name of the file it came in.
     *
     * @param string $name Such as export-fiches-csv-2026-10-27.zip; a browser may add " (1)", and a person may add a prefix.
     * @return string|null YYYY-MM-DD, or null when the name gives no day that exists.
     */
    public static function date_from_name(string $name): ?string {
        if (!preg_match('/export-fiches-csv-(\d{4})-(\d{2})-(\d{2})/', $name, $parts)) {
            return null;
        }
        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return "$parts[1]-$parts[2]-$parts[3]";
    }

    /**
     * A day, as the date selector of the CSV import writes it: midnight in the timezone of the person.
     *
     * THE SAME INTEGER AS THE CSV SCREEN, ON PURPOSE. importer::import() compares the date of the source with the one it holds by
     * equality of integers; another convention would make the first import by zip of a record imported by CSV count as a
     * republication, and a label changed on this site as a change of the source.
     *
     * @param string $ymd YYYY-MM-DD
     * @return int
     */
    public static function to_timestamp(string $ymd): int {
        [$year, $month, $day] = array_map('intval', explode('-', $ymd));

        return make_timestamp($year, $month, $day, 0, 0, 0, 99);
    }

    /**
     * Whether the day of an export is before the day of the version the site holds, read in the timezone of the reader.
     *
     * By day, not by second: two administrators in different timezones write the same day as two different instants.
     *
     * @param int $heldts The date held for the record.
     * @param int $exportts The date of the export.
     * @return bool
     */
    public static function is_older(int $heldts, int $exportts): bool {
        // The last argument is false: userdate() takes the zero off a day of one digit otherwise, and 2026113 is then
        // smaller than 20261027.
        return (int) userdate($exportts, '%Y%m%d', 99, false) < (int) userdate($heldts, '%Y%m%d', 99, false);
    }

    /**
     * The path of an uploaded file, once PHP has said it arrived whole.
     *
     * @param array|null $upload An entry of $_FILES.
     * @param bool $checkuploaded Whether to ask PHP that the file is one that was uploaded (not in a test).
     * @return string
     * @throws fiche_refusal ZIP_MISSING, UPLOAD.
     */
    public static function upload_path(?array $upload, bool $checkuploaded = true): string {
        if ($upload === null) {
            throw new fiche_refusal(fiche_refusal::ZIP_MISSING, [], 'No file was chosen.');
        }
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if (is_array($error)) {
            throw new fiche_refusal(fiche_refusal::UPLOAD, [], 'The file did not arrive whole.');
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new fiche_refusal(fiche_refusal::ZIP_MISSING, [], 'No file was chosen.');
        }
        $path = $upload['tmp_name'] ?? '';
        if ($error !== UPLOAD_ERR_OK || !is_string($path) || $path === '' || ($checkuploaded && !is_uploaded_file($path))) {
            throw new fiche_refusal(fiche_refusal::UPLOAD, [], 'The file did not arrive whole.');
        }

        return $path;
    }

    /**
     * Read an export that was uploaded, and keep a plan of what it holds for the numbers asked for. Nothing is written.
     *
     * @param string $path The archive.
     * @param string $name The name it had on the machine of the person.
     * @param string[] $numbers From parse_numbers().
     * @param int|null $typedts A day typed by the person, which wins over the name; null when none.
     * @param string[] $repeated The numbers typed more than once, from parse_numbers().
     * @return string The token of the plan.
     * @throws fiche_refusal NO_DATE, DATE_FUTURE, BUSY, and those of fiche_archive.
     * @throws \moodle_exception The rights.
     */
    public function from_upload(string $path, string $name, array $numbers, ?int $typedts = null, array $repeated = []): string {
        self::guard();
        $lock = $this->lock('prepare');
        try {
            return $this->prepare($path, $name, $numbers, $typedts, self::ORIGIN_UPLOAD, null, $repeated);
        } finally {
            $lock->release();
        }
    }

    /**
     * Fetch the latest export from data.gouv.fr, and keep a plan of what it holds for the numbers asked for. Nothing is written.
     *
     * @param string[] $numbers From parse_numbers().
     * @param string[] $repeated The numbers typed more than once, from parse_numbers().
     * @return string The token of the plan.
     * @throws fiche_refusal The refusals of fiche_fetcher, BUSY, and those of the reading of the archive.
     * @throws \moodle_exception The rights.
     */
    public function from_data_gouv(array $numbers, array $repeated = []): string {
        self::guard();
        $lock = $this->lock('prepare');
        $path = '';
        try {
            // Up to five questions of twenty seconds and four transfers of two minutes: the script is given the time, so that no
            // limit of execution stops it with a file half written and the lock still held.
            raise_memory_limit(MEMORY_EXTRA);
            \core_php_time_limit::raise(900);

            $resource = null;
            try {
                $resource = $this->fetcher->latest();
                $path = $this->fetcher->download($resource, make_request_directory());
            } catch (fiche_refusal $refusal) {
                // Traced as well when it fails: a request went out, whatever came of it.
                self::trace_fetch($resource, '', 0, $refusal->reason(), (int) ($refusal->params()['status'] ?? 0));
                throw $refusal;
            }
            self::trace_fetch($resource, hash_file('sha256', $path), (int) filesize($path), 'ok', 200);

            return $this->prepare($path, $resource->title, $numbers, null, self::ORIGIN_FETCH, $resource, $repeated);
        } finally {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
            $lock->release();
        }
    }

    /**
     * A plan kept for the person who is signed in.
     *
     * @param string $token
     * @return \stdClass|null
     */
    public function load(string $token): ?\stdClass {
        global $USER;

        return fiche_plan_store::load((int) $USER->id, $token);
    }

    /**
     * What a plan comes to, by the kind of its records.
     *
     * @param \stdClass $plan
     * @return \stdClass importable (ready, and not older than what the site holds), older (ready, but older) and refused.
     */
    public static function counts(\stdClass $plan): \stdClass {
        $counts = (object) ['importable' => 0, 'older' => 0, 'refused' => 0];
        foreach ($plan->records as $record) {
            if ($record->status !== 'ready') {
                $counts->refused++;
            } else if (!empty($record->older)) {
                $counts->older++;
            } else {
                $counts->importable++;
            }
        }

        return $counts;
    }

    /**
     * Whether the records older than what the site holds are to be written.
     *
     * Yes when the box was ticked; and yes when there is nothing else to write, since the button then says so itself.
     *
     * @param \stdClass $plan
     * @param bool $ticked Whether the box was ticked.
     * @return bool
     */
    public static function allow_older(\stdClass $plan, bool $ticked): bool {
        $counts = self::counts($plan);

        return $ticked || ($counts->importable === 0 && $counts->older > 0);
    }

    /**
     * Write the records of a plan, one by one, and keep what became of each.
     *
     * Asked twice for the same plan, it gives the results it kept and imports nothing again: a double click, or a reload of the
     * page of the result, is harmless.
     *
     * @param string $token
     * @param bool $allowolder Whether to write the records the site holds in a more recent version.
     * @return \stdClass The plan, with its results: number, status (imported, failed, older, refused, notrun) and html; and
     *         resultkept, whether they could be written beside the plan (when not, the page shows them at once).
     * @throws fiche_refusal EXPIRED, BUSY.
     * @throws \moodle_exception The rights.
     */
    public function apply(string $token, bool $allowolder = false): \stdClass {
        self::guard();
        $plan = $this->load($token);
        if ($plan === null) {
            throw new fiche_refusal(fiche_refusal::EXPIRED, [], 'The preview is gone.');
        }
        if (($plan->results ?? null) !== null) {
            return $plan;
        }

        $lock = $this->lock('apply');
        try {
            // Read again under the lock: a second click may have written it while this one waited at the door.
            $plan = $this->load($token);
            if ($plan === null) {
                throw new fiche_refusal(fiche_refusal::EXPIRED, [], 'The preview is gone.');
            }
            if (($plan->results ?? null) !== null) {
                return $plan;
            }
            // THE PLAN IS A FILE: what it says is checked again before it is written to the site.
            self::assert_plan($plan);
            raise_memory_limit(MEMORY_EXTRA);
            \core_php_time_limit::raise(300);

            $results = [];
            $stopped = false;
            foreach ($plan->records as $index => $record) {
                if ($record->status !== 'ready') {
                    // A refusal stays a refusal, even after the loop has stopped: it would be refused again.
                    $results[] = self::refused_result($record, $plan);
                    continue;
                }
                if ($stopped) {
                    $results[] = (object) ['number' => $record->number, 'status' => 'notrun', 'html' => ''];
                    continue;
                }

                $meta = (object) [
                    'fichecode' => $record->number, 'fichetype' => $record->type, 'name' => $record->name,
                    'sourceurl' => self::SOURCE_URL, 'sourceupdated' => (int) $plan->exportts,
                    'licence' => self::LICENCE, 'attribution' => self::ATTRIBUTION,
                ];
                $rows = array_map(fn($row) => (object) (array) $row, $record->rows);

                try {
                    // The comparison is made again where the write is, on what the site holds now.
                    $check = $this->importer->check($meta, $rows);
                    $held = $check->previous && $check->frameworkid !== null && !$check->adopted
                        ? (int) $check->previous->sourceupdated
                        : 0;
                    if ($held && self::is_older($held, (int) $plan->exportts) && !$allowolder) {
                        $results[] = (object) ['number' => $record->number, 'status' => 'older', 'html' => ''];
                        continue;
                    }

                    $done = $this->importer->import($meta, $rows);
                } catch (\moodle_exception $e) {
                    // A refusal of the importer or of the core is said for this record and the next is tried; the database or the
                    // code failing would fail every record after it in the same way, each leaving a line of provenance in error:
                    // the loop stops.
                    $results[] = (object) ['number' => $record->number, 'status' => 'failed', 'html' => self::exception_html($e)];
                    $stopped = $e instanceof \dml_exception || $e instanceof \coding_exception;
                    $this->checkpoint($token, $plan, $results, (int) $index);
                    continue;
                } catch (\Throwable $e) {
                    // Something nobody foresaw: the loop stops, the records not tried are said so.
                    $results[] = (object) ['number' => $record->number, 'status' => 'failed', 'html' => self::exception_html($e)];
                    $stopped = true;
                    $this->checkpoint($token, $plan, $results, (int) $index);
                    continue;
                }

                // THE RECORD IS WRITTEN. What follows is a trace and a sentence: if the trace fails, the record is still imported.
                $results[] = (object) ['number' => $record->number, 'status' => 'imported', 'html' => self::summary($done)];
                try {
                    self::trace_import($plan, $record->number, $done);
                } catch (\Throwable $e) {
                    debugging(
                        'tool_vae fiche_import: the event of ' . $record->number . ' was not written: ' . $e->getMessage(),
                        DEBUG_NORMAL
                    );
                }
                $this->checkpoint($token, $plan, $results, (int) $index);
            }

            $plan->results = $results;
            // Whether the results could be kept is told to the caller, which shows them itself when they could not.
            $plan->resultkept = $this->keep($token, $plan);

            return $plan;
        } finally {
            $lock->release();
        }
    }

    /**
     * What an exception says, as HTML that is safe, for a person who is not a developer.
     *
     * The message of an exception of this plugin was escaped where it was raised; that of another module is escaped here; what
     * is not meant for a reader - the database, a coding error, anything that is not a moodle_exception - is not shown: the
     * sentence of the plugin takes its place, and the detail goes to the debugging messages of the site.
     *
     * @param \Throwable $e
     * @return string
     */
    public static function exception_html(\Throwable $e): string {
        if ($e instanceof \moodle_exception && !($e instanceof \dml_exception) && !($e instanceof \coding_exception)) {
            return $e->module === 'tool_vae' ? $e->getMessage() : s($e->getMessage());
        }

        $where = basename($e->getFile()) . ':' . $e->getLine();
        debugging('tool_vae fiche_import: ' . get_class($e) . ': ' . $e->getMessage() . " ($where)", DEBUG_NORMAL);

        return fiche_messages::html(new fiche_refusal(fiche_refusal::UNEXPECTED));
    }

    /**
     * Keep the results with the plan: the records are written, and the results must outlive a plan that cannot be saved.
     *
     * @param string $token
     * @param \stdClass $plan With its results.
     * @return bool Whether the results were kept (with the blocs of the plan, or, failing that, without).
     */
    private function keep(string $token, \stdClass $plan): bool {
        global $USER;

        try {
            fiche_plan_store::save((int) $USER->id, $token, $plan);

            return true;
        } catch (\Throwable $e) {
            debugging('tool_vae fiche_import: the plan was not kept whole: ' . $e->getMessage(), DEBUG_NORMAL);
        }

        // Once more without the blocs, which are the bulk of it: the results are what a reload must find.
        foreach ($plan->records as $record) {
            $record->rows = [];
        }
        try {
            fiche_plan_store::save((int) $USER->id, $token, $plan);

            return true;
        } catch (\Throwable $e) {
            debugging('tool_vae fiche_import: the results were not kept: ' . $e->getMessage(), DEBUG_NORMAL);
        }

        return false;
    }

    /**
     * Keep what has been done so far, after a record that was written or that failed.
     *
     * If the script is killed in the middle of the loop (the limit of the host, the memory), the person who reloads the address
     * finds the records that were written, and the others said not to have been run - not an old preview that offers to import
     * what is already there.
     *
     * @param string $token
     * @param \stdClass $plan
     * @param array $results The results so far.
     * @param int $index The position of the record that has just been handled.
     */
    private function checkpoint(string $token, \stdClass $plan, array $results, int $index): void {
        global $USER;

        foreach (array_slice($plan->records, $index + 1) as $record) {
            $results[] = $record->status === 'ready'
                ? (object) ['number' => $record->number, 'status' => 'notrun', 'html' => '']
                : self::refused_result($record, $plan);
        }

        $plan->results = $results;
        try {
            fiche_plan_store::save((int) $USER->id, $token, $plan);
        } catch (\Throwable $e) {
            debugging('tool_vae fiche_import: the results so far were not kept: ' . $e->getMessage(), DEBUG_NORMAL);
        }
        // The loop goes on: the plan has no result until it has gone through all its records.
        $plan->results = null;
    }

    /**
     * The result of a record that was refused at the preview: it stays refused.
     *
     * @param \stdClass $record
     * @param \stdClass $plan
     * @return \stdClass
     */
    private static function refused_result(\stdClass $record, \stdClass $plan): \stdClass {
        return (object) [
            'number' => $record->number, 'status' => 'refused',
            'html' => fiche_messages::html(fiche_refusal::from_array((array) $record->refusal), (int) $plan->exportts),
        ];
    }

    /**
     * Check a plan, read from a file, before it is written to the site.
     *
     * The importer checks the rows, the names and the dates again; what it does not check is what the plan says around them: the
     * way, the day, the digest, the form of a number and its type.
     *
     * @param \stdClass $plan
     * @throws fiche_refusal UNEXPECTED when the plan is not one the preview could have kept.
     */
    private static function assert_plan(\stdClass $plan): void {
        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';
        $day = (string) ($plan->exportdate ?? '');
        $valid = in_array($plan->origin ?? '', [self::ORIGIN_UPLOAD, self::ORIGIN_FETCH], true)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $day, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            && is_int($plan->exportts ?? null)
            && abs($plan->exportts - self::to_timestamp($day)) <= 2 * DAYSECS
            && preg_match('/^[0-9a-f]{64}$/D', (string) ($plan->sha256 ?? '')) === 1
            && is_int($plan->bytes ?? null)
            && ($plan->resource === null || preg_match($uuid, (string) ($plan->resource->id ?? '')) === 1)
            && is_array($plan->records ?? null);

        foreach ($valid ? $plan->records : [] as $record) {
            $number = (string) ($record->number ?? '');
            $valid = $valid
                && preg_match(self::NUMBER_PATTERN, $number) === 1
                && ($record->type ?? '') === (strncmp($number, 'RS', 2) === 0 ? 'rs' : 'rncp')
                && in_array($record->status ?? '', ['ready', 'refused'], true);
            if ($valid && $record->status === 'ready') {
                $valid = is_string($record->name ?? null) && is_array($record->rows ?? null) && $record->rows;
                foreach ($valid ? $record->rows : [] as $row) {
                    $valid = $valid && is_object($row) && is_string($row->rang ?? null) && is_string($row->intitule ?? null)
                        && is_string($row->description ?? null);
                }
            }
        }

        if (!$valid) {
            throw new fiche_refusal(fiche_refusal::UNEXPECTED, [], 'The plan is not one that a preview could have kept.');
        }
    }

    /**
     * The event of a record written.
     *
     * @param \stdClass $plan
     * @param string $number
     * @param \stdClass $done What importer::import() gives.
     */
    private static function trace_import(\stdClass $plan, string $number, \stdClass $done): void {
        record_imported::create([
            'context' => \context_system::instance(),
            'objectid' => $done->sourceid,
            'other' => [
                'fichecode' => $number,
                'origin' => $plan->origin,
                'exportdate' => $plan->exportdate,
                'resourceid' => $plan->resource->id ?? '',
                'sha256' => $plan->sha256,
                'bytes' => (int) $plan->bytes,
                'created' => (int) $done->created,
                'updated' => (int) $done->updated,
                'moved' => (int) $done->moved,
                'gone' => (int) $done->gone,
                'diverged' => (int) $done->diverged,
                'adopted' => empty($done->adopted) ? 0 : 1,
            ],
        ])->trigger();
    }

    /**
     * Forget a plan.
     *
     * @param string $token
     */
    public function discard(string $token): void {
        global $USER;

        fiche_plan_store::discard((int) $USER->id, $token);
    }

    /**
     * Take the lock of a step, without waiting for it.
     *
     * @param string $resource prepare, apply.
     * @return \core\lock\lock
     * @throws fiche_refusal BUSY.
     */
    private function lock(string $resource): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory(self::LOCKS)->get_lock($resource, 0, 600);
        if (!$lock) {
            throw new fiche_refusal(fiche_refusal::BUSY, [], 'Another import is running.');
        }

        return $lock;
    }

    /**
     * Read the archive, convert the numbers, check each record against the site: the plan, kept.
     *
     * @param string $path
     * @param string $name
     * @param string[] $numbers
     * @param int|null $typedts
     * @param string $origin
     * @param \stdClass|null $resource What fiche_fetcher::latest() gave, for a fetch.
     * @param string[] $repeated
     * @return string The token.
     */
    private function prepare(
        string $path,
        string $name,
        array $numbers,
        ?int $typedts,
        string $origin,
        ?\stdClass $resource,
        array $repeated
    ): string {
        global $USER;

        raise_memory_limit(MEMORY_EXTRA);
        \core_php_time_limit::raise(300);

        // What is not an export is told first: a file that is not a zip is not blamed for its name.
        $archive = fiche_archive::read($path);

        // The day of the export is READ: typed, or in the name of the file. Never guessed.
        $ymd = $typedts ? userdate($typedts, '%Y-%m-%d', 99, false) : self::date_from_name($name);
        if ($ymd === null) {
            throw new fiche_refusal(fiche_refusal::NO_DATE, ['name' => $name], 'The name of the file gives no day.');
        }
        $exportts = self::to_timestamp($ymd);
        $tomorrow = self::to_timestamp(userdate(\core\di::get(\core\clock::class)->time() + DAYSECS, '%Y-%m-%d', 99, false));
        if ($exportts > $tomorrow) {
            throw new fiche_refusal(fiche_refusal::DATE_FUTURE, [], 'The date of the export is in the future.');
        }

        $converted = fiche_converter::convert_many($archive->blocs, $archive->standard, $numbers);
        unset($archive->blocs, $archive->standard);

        $records = [];
        foreach ($converted as $number => $result) {
            $records[] = $result instanceof fiche_refusal
                ? self::refused_record((string) $number, $result)
                : $this->ready_record((string) $number, $result, $exportts);
        }

        $plan = (object) [
            'version' => 1,
            'origin' => $origin,
            'originname' => mb_substr($name, 0, 100, 'UTF-8'),
            'bytes' => $archive->bytes,
            'sha256' => $archive->sha256,
            'exportdate' => $ymd,
            'exportts' => $exportts,
            'innerdates' => (object) ['blocs' => $archive->blocsdate, 'standard' => $archive->standarddate],
            'resource' => $resource === null
                ? null
                : (object) ['id' => $resource->id, 'title' => $resource->title, 'sha1' => $resource->sha1],
            'numbers' => array_map('strval', array_keys($converted)),
            'repeated' => array_values($repeated),
            'records' => $records,
            'results' => null,
        ];

        return fiche_plan_store::create((int) $USER->id, $plan);
    }

    /**
     * The entry of the plan for a record that cannot be imported.
     *
     * @param string $number
     * @param fiche_refusal $refusal
     * @return \stdClass
     */
    private static function refused_record(string $number, fiche_refusal $refusal): \stdClass {
        return (object) [
            'number' => $number,
            'type' => strncmp($number, 'RS', 2) === 0 ? 'rs' : 'rncp',
            'status' => 'refused',
            'refusal' => (object) ['reason' => $refusal->reason(), 'params' => (object) $refusal->params()],
            'title' => '', 'name' => '', 'active' => null, 'enddate' => null, 'blocs' => 0, 'rows' => [],
            'long' => [], 'markup' => [], 'state' => 'new', 'heldts' => null, 'declared' => 0, 'forecast' => null,
            'older' => false,
        ];
    }

    /**
     * The entry of the plan for a record that was converted: what the site holds for it, and what the import will do.
     *
     * @param string $number
     * @param \stdClass $result What fiche_converter gives.
     * @param int $exportts
     * @return \stdClass
     */
    private function ready_record(string $number, \stdClass $result, int $exportts): \stdClass {
        global $DB;

        $rows = array_map(fn($row) => (object) $row, $result->rows);
        $meta = (object) [
            'fichecode' => $number, 'fichetype' => $result->meta->fichetype, 'name' => $result->meta->name,
            'sourceurl' => self::SOURCE_URL, 'sourceupdated' => $exportts,
            'licence' => self::LICENCE, 'attribution' => self::ATTRIBUTION,
        ];

        // What the importer would refuse is refused here, by its own sentence, before a single record is written.
        try {
            $check = $this->importer->check($meta, $rows);
        } catch (\moodle_exception $e) {
            $record = self::refused_record($number, new fiche_refusal(fiche_refusal::MOODLE, ['html' => self::exception_html($e)]));
            $record->title = $result->meta->title;

            return $record;
        }

        // A row of provenance with no date of source is the trace of a first attempt that failed: no version was ever delivered.
        $delivered = $check->previous && (int) $check->previous->sourceupdated > 0;
        if ($check->frameworkid === null) {
            $state = $delivered ? 'rebuild' : 'new';
        } else if ($check->adopted || !$delivered) {
            $state = 'adopted';
        } else {
            $state = 'held';
        }
        $heldts = $delivered ? (int) $check->previous->sourceupdated : null;

        return (object) [
            'number' => $number,
            'type' => $result->meta->fichetype,
            'status' => 'ready',
            'refusal' => null,
            'title' => $result->meta->title,
            'name' => $result->meta->name,
            'active' => $result->meta->active,
            'enddate' => $result->meta->enddate,
            'blocs' => count($rows),
            'rows' => array_map(fn($row) => (object) $row, $result->rows),
            'long' => $result->long,
            'markup' => $result->markup,
            'state' => $state,
            'heldts' => $heldts,
            'declared' => $DB->count_records('tool_vae_evidence', ['fichecode' => $number]),
            'forecast' => $check->forecast,
            'older' => $state === 'held' && $heldts && self::is_older($heldts, $exportts),
        ];
    }

    /**
     * The sentence for a record written: the one of the CSV screen.
     *
     * @param \stdClass $done What importer::import() gives.
     * @return string
     */
    private static function summary(\stdClass $done): string {
        return get_string('fcimportsummary', 'tool_vae', $done)
            . (!empty($done->adopted) ? ' ' . get_string('importadopted', 'tool_vae') : '')
            . ($done->moved ? ' ' . get_string('importmoved', 'tool_vae', $done->moved) : '');
    }

    /**
     * Trace the request of the fetch.
     *
     * @param \stdClass|null $resource
     * @param string $sha256
     * @param int $bytes
     * @param string $outcome ok, or the reason of the refusal.
     * @param int $status
     */
    private static function trace_fetch(?\stdClass $resource, string $sha256, int $bytes, string $outcome, int $status): void {
        export_fetched::create([
            'context' => \context_system::instance(),
            'other' => [
                'resourceid' => $resource->id ?? '',
                'title' => $resource->title ?? '',
                'bytes' => $bytes,
                'sha256' => $sha256,
                'outcome' => $outcome,
                'status' => $status,
            ],
        ])->trigger();
    }
}
