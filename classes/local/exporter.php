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

use tool_vae\event\dossier_exported;

/**
 * The normative export of a candidate's dossier - compensating measure MC-1 of
 * DER-referentiel-001, within the frame MC-9 sets.
 *
 * WHAT IT PRODUCES. A zip archive that reads without the platform that produced it:
 *   - manifeste.json   what the archive holds, and the SHA-256 digest of every other file;
 *   - preuves/*.json   one claim per file, conforming to docs/schema/preuve.schema.json;
 *   - referentiel.json the certification records and the labels of the competencies claimed,
 *                      with the licence and the attribution Licence Ouverte 2.0 requires;
 *   - schema/          a copy of the schema the claims conform to;
 *   - LISEZMOI.txt     the same, for a person;
 *   - dossier.pdf      the dossier laid out for a panel to print - see pdf_renderer. Listed and
 *                      digested like the rest; the reimport checks it and ignores it.
 *
 * THIS IS THE FROZEN VERSION OF THE DOSSIER (R4 of the inventory). Withdrawing a
 * attestation afterwards has no effect on an archive already produced, nor on its copies,
 * and the archive says so in its manifest and in its notice, as MC-9 requires.
 *
 * WHERE IT LIVES. In this plugin's `export` file area, in the candidate's user context, served by
 * tool_vae_pluginfile() to the person who produced it and to nobody else, refused after a
 * day and deleted by \tool_vae\task\purge_exports. Never in $CFG->tempdir. The core's own
 * GDPR export, tool_dataprivacy, keeps its archives in exactly this way. What differs is the
 * content: that one is everything Moodle holds about a person, in no stable shape; this one is a
 * dossier in a published schema, meant for a panel and for reimport elsewhere.
 *
 * NO PLATFORM IDENTIFIER OUTSIDE correspondances[]. Every identifier in a claim is a ULID from
 * identity_map. Moodle's own numbers appear in one array of each claim, so that changing platform
 * touches that array and nothing else.
 *
 * WHO AN ARCHIVE IS FOR, AND WHO MADE IT, ARE SAID BY THE CALLER, NEVER READ FROM $USER (audit of 26 October 2026).
 * What an archive carries depends on its recipient: the reflective journal and the preparation of the validation
 * dossier only when the author is the one who takes it away (MC-22, D-15 (3)); the saved sections of the dossier de
 * faisabilite for whoever may read them; the bytes of the pieces for the author and for an administrator's backup. The
 * session that happens to be running is not the recipient: a backup is rewritten in the request of the candidate who
 * removes a piece, and by a cron that runs as the main administrator, and the file lands in somebody else's private
 * files all the same. The same goes for who made it: the register, the file and the event name the producer the caller
 * gives. A caller that says nothing gets the old reading of $USER, which is only for tests and scripts.
 *
 * A LINE THE SCHEMA REFUSES DOES NOT REFUSE THE ARCHIVE (decision of the project owner, 27 October 2026). A rank written
 * `c7` instead of `C7`, in a declaration recorded by an earlier version of the plugin (or written to the database by hand),
 * which the candidate never typed and no screen rewrites, must not keep that candidate from handing the dossier
 * to the panel on the day it is due. The archive is produced as the dossier stands, nothing is cleaned or rewritten, and the
 * exporter says which lines the schema refuses - warnings() - so that the screen warns the person and the notice inside the
 * archive carries the mention. The import stays strict: such an archive cannot be reimported as it stands, and says so. The
 * two other protections are not touched and still refuse, each on its own: a piece whose file is lost or altered
 * (piece_overview::files(), with $strictpieces) and a dossier too large to be read back (require_restorable(), with
 * $enforcelimits). Before this decision one switch ($enforcelimits, passed on as $strictpieces) commanded all three.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class exporter {
    /** @var string File area holding the archives, in the candidate's user context. */
    public const FILEAREA = 'export';

    /** @var int How long an archive may be downloaded, and kept. MC-9. */
    public const LIFETIME = DAYSECS;

    /** @var string The register of the archives this platform issued - see store(). */
    public const REGISTER = 'tool_vae_archive';

    /** @var string What the manifest says the archive is. */
    public const FORMAT = 'tool_vae/dossier';

    /**
     * Version of the archive layout, raised when a file the reimport reads is added, moved or
     * reshaped. dossier.pdf (0.10.0) is read by a person only: the layout stays at 1, and archives
     * produced before it, which lack it, still reimport. faisabilite.json (0.18.0) is optional in
     * the same way: an archive without it reimports as before, and a plugin that predates it
     * finds it listed with its digest and leaves it alone.
     *
     * @var string
     */
    public const FORMAT_VERSION = '1';

    /** @var string Version of docs/schema/preuve.schema.json the claims conform to. */
    public const SCHEMA_VERSION = '0.1.0-reconstruit';

    /** @var string Version of docs/schema/faisabilite.schema.json faisabilite.json conforms to. */
    public const FAISABILITE_SCHEMA_VERSION = '0.1.0';

    /** @var string Path of the text sections of the dossier de faisabilité in the archive. */
    public const FAISABILITE_PATH = 'faisabilite.json';

    /** @var string The preparation of the validation dossier, a facultative file of the archive (T-11, lot 2). */
    public const VALIDATION_PATH = 'validation.json';

    /** @var string Where the reflective journal sits in an archive its author exported (T-14, lot 2). */
    public const REFLEXION_PATH = 'reflexion.json';

    /** @var string Name of this platform in correspondances[]. */
    public const SYSTEM = 'moodle';

    /** @var int How many faulty lines a screen or the notice of an archive lists before saying how many more there are. */
    public const WARNINGS_LISTED = 10;

    /** @var int How many characters of a faulty value a warning shows: enough to recognise it, not to copy a text. */
    private const VALUE_SHOWN = 40;

    /** @var string[] How anchor::divergence() reads in the manifest. */
    private const ANCHOR_STATES = [
        anchor::MISSING => 'ancre_absente',
        anchor::RENAMED => 'ancre_renommee',
        anchor::UNLINKED => 'competence_deliee',
    ];

    /** @var string[] How tool_vae_obsolete.kind reads in referentiel.json. */
    private const RECORD_STATES = [
        'missing' => 'absente_de_la_fiche',
        'renamed' => 'renommee_localement',
        'moved' => 'deplacee_localement',
    ];

    /** @var competency_gateway */
    private $gateway;

    /** @var array[] What the last archive built by this exporter carries that its own schema refuses - see warnings(). */
    private $warnings = [];

    /**
     * Constructor.
     *
     * @param competency_gateway|null $gateway Only passed by tests.
     */
    public function __construct(?competency_gateway $gateway = null) {
        $this->gateway = $gateway ?? new competency_gateway();
    }

    /**
     * The lines of the last archive built by this exporter that the schema it carries refuses.
     *
     * The archive is produced all the same (see the class): this is how the screen that asked for it, and the notice inside
     * it, say what the reimport will refuse. Read after export(), store() or build(); the list is emptied at the start of
     * each of them. Only the dossier that was built is named, never another candidate's.
     *
     * Each entry holds: `file`, the path in the archive; `declaration`, the declaration as `code-rank` as it is written
     * there (empty for a file that is not tied to one declaration); `fiche` and `rang`, its two parts (empty likewise);
     * `field`, the JSON pointer of the faulty value; `value`, that value shortened and as text (null when the field is
     * missing); `error`, the line of schema_validator.
     *
     * @return array[]
     */
    public function warnings(): array {
        return $this->warnings;
    }

    /**
     * The warnings as lines of plain text, in the language of the page that asks: the screen escapes them, the notice of the
     * archive writes them as they are.
     *
     * At most self::WARNINGS_LISTED lines, then one that says how many faulty lines are not listed.
     *
     * A field the file does not hold (a null value) is not told as one that holds nothing (an empty string): the reader would
     * look for a field that is there.
     *
     * @param array[] $warnings As warnings() returns them.
     * @return string[]
     */
    public static function describe_warnings(array $warnings): array {
        $lines = [];
        foreach (array_slice($warnings, 0, self::WARNINGS_LISTED) as $warning) {
            $value = $warning['value'] ?? null;
            if ($value === null) {
                $value = get_string('exportwarningnovalue', 'tool_vae');
            } else if ($value === '') {
                $value = get_string('exportwarningemptyvalue', 'tool_vae');
            }
            $line = (object) [
                'declaration' => $warning['declaration'],
                'fiche' => $warning['fiche'],
                'file' => $warning['file'],
                'field' => $warning['field'] === '' ? '/' : $warning['field'],
                'value' => $value,
            ];
            $string = $warning['declaration'] === '' ? 'exportwarningitemfile' : 'exportwarningitem';
            $lines[] = get_string($string, 'tool_vae', $line);
        }
        if (count($warnings) > self::WARNINGS_LISTED) {
            $lines[] = get_string('exportwarningmore', 'tool_vae', count($warnings) - self::WARNINGS_LISTED);
        }

        return $lines;
    }

    /**
     * The warnings as the HTML of a screen: the sentence that opens the notice, then one item per line.
     *
     * THE ONE PLACE WHERE THE LINES GO INTO HTML, for the export page and the backup page alike. A value is whatever an
     * administrator typed in the screen of the competencies, so the opening sentence and every line are escaped here, and the
     * pages do nothing of the kind themselves: what they would escape on their own account, nothing would test, since the pages
     * are not run by the unit tests. The lines are those of describe_warnings(): plain text, in the language of the page.
     *
     * @param array[] $warnings As warnings() returns them.
     * @param string $lead The sentence that opens the notice (exportwarning, backupdossierwarning).
     * @return string
     */
    public static function warnings_html(array $warnings, string $lead): string {
        return \html_writer::tag('p', s($lead)) . \html_writer::alist(array_map('s', self::describe_warnings($warnings)));
    }

    /**
     * Produce and store the archive of a candidate's dossier.
     *
     * The caller has checked the right to export - see access::require_can_export().
     *
     * The exporter is the person at the keyboard, and this is the one place where that is the recipient: the archive
     * stays in this plugin's area for them to download, and nobody else may (may_download()). It is their own when the
     * candidate exports themself - journal, preparation of the validation dossier, bytes of the pieces - and the direction's
     * when someone else does, who gets what they may read and the list of the pieces. Said to store() in so many words.
     *
     * A line the schema refuses does not refuse the archive: it is produced, and the screen that asks reads warnings() after
     * it to tell the person.
     *
     * @param int $candidateid
     * @return \stored_file The archive, in this plugin's export area.
     */
    public function export(int $candidateid): \stored_file {
        global $USER;

        $context = \context_user::instance($candidateid);
        $exporter = (int) $USER->id;
        $own = $exporter === $candidateid;

        return $this->store(
            $candidateid,
            $context->id,
            'tool_vae',
            self::FILEAREA,
            '/',
            'dossier-',
            true,
            $own,
            $own,
            $exporter,
            draft_manager::can_read($candidateid)
        );
    }

    /**
     * Refuse an archive the import would refuse (T-77).
     *
     * The import bounds what it reads - a number of files and a total size - to protect itself from
     * an archive built to exhaust it. The export used to build whatever the dossier held, so a
     * large enough dossier came out, was registered, and was refused when brought back: a backup
     * that promised a restoration it could not give. Both ends now read the same two constants,
     * the import's own, and the export says so before writing anything. A dossier this large is not
     * split here; saying so is the first step, and nothing is registered or stored.
     *
     * The count and the sum are the import's: every entry of the archive, the manifest included.
     *
     * @param string[] $files Path => content, as build() returns them.
     * @throws \moodle_exception errorexporttoolarge
     */
    public static function require_restorable(array $files): void {
        $bytes = 0;
        foreach ($files as $content) {
            $bytes += strlen($content);
        }
        if (count($files) > dossier_importer::MAX_FILES || $bytes > dossier_importer::MAX_BYTES) {
            throw new \moodle_exception('errorexporttoolarge', 'tool_vae', '', (object) [
                'files' => count($files),
                'size' => display_size($bytes),
                'maxfiles' => dossier_importer::MAX_FILES,
                'maxsize' => display_size(dossier_importer::MAX_BYTES),
            ]);
        }
    }

    /**
     * Produce the archive of a candidate's dossier and store it where the caller says.
     *
     * The export keeps it a day in this plugin's area; the backup keeps it in an administrator's
     * private files, out of this plugin's reach - see backup. Every archive is logged the same way,
     * and registered the same way.
     *
     * THE REGISTER (lot 8 quater). The digests in the manifest show that a file was damaged; they
     * cannot show that it was forged, since whoever changes a claim recomputes its digest in the
     * manifest. The digest of the manifest itself is therefore kept here, under the archive's
     * ULID, where no holder of the archive can reach it. The reimport compares against it, and a
     * jury can ask the platform to confirm an archive by that ULID - see dossier_importer::check().
     *
     * @param int $candidateid
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param string $filepath
     * @param string $prefix Start of the file name; the archive's ULID and .zip follow.
     * @param bool $enforcelimits Refuse a dossier too large for this platform to read back (T-77), and a piece whose file is
     *        lost or altered - the promise of an archive that holds what it vouches for. NOT a line the schema refuses: that one
     *        is never refused, it is reported (see warnings()), whatever this says.
     * @param bool|null $fullpieces Whether the archive carries the bytes of the supporting pieces and the addresses of the
     *        links (T-13, MC-28): true for the backup kept on the platform, and, when null, for the archive the candidate
     *        exports themself. An export a third party makes lists the pieces and carries none of that.
     * @param bool|null $forauthor Whether the archive is for the candidate, its author: only then do the reflective journal
     *        and the preparation of the validation dossier go in (MC-22, D-15 (3)). Never true for a backup, whoever
     *        triggers it. When null, true if the current user is the candidate - for the callers that have not said.
     * @param int|null $producerid Who made the archive, for the register, the file and the event: the exporter for an
     *        export, the holder of the files for a backup. When null, the current user.
     * @param bool|null $withsections Whether the saved sections of the dossier de faisabilite go in: true for a backup, and
     *        for whoever may read them (draft_manager::can_read()). When null, as the current user may.
     * @return \stored_file
     */
    public function store(
        int $candidateid,
        int $contextid,
        string $component,
        string $filearea,
        string $filepath,
        string $prefix,
        bool $enforcelimits = true,
        ?bool $fullpieces = null,
        ?bool $forauthor = null,
        ?int $producerid = null,
        ?bool $withsections = null
    ): \stored_file {
        global $DB, $USER;

        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $candidateid], 'timecreated ASC, id ASC');
        if (!$claims) {
            throw new \moodle_exception('errorexportempty', 'tool_vae');
        }

        $archiveid = identity_map::generate_ulid();
        $now = time();
        $own = (int) $USER->id === $candidateid;
        $full = $fullpieces ?? $own;
        $forauthor = $forauthor ?? $own;
        $producerid = $producerid ?? (int) $USER->id;
        $withsections = $withsections ?? draft_manager::can_read($candidateid);
        // A refusal on a lost file, and on a dossier too large, only where the person is promised an archive that reads back;
        // the acts that must not stop for them do not refuse. A line the schema refuses refuses nowhere: see warnings().
        $files = $this->build($candidateid, $claims, $archiveid, $now, $full, $enforcelimits, $forauthor, $withsections);
        if ($enforcelimits) {
            self::require_restorable($files);
        }
        $zip = [];
        foreach ($files as $path => $content) {
            $zip[$path] = [$content];
        }

        // Registered BEFORE the file is written. The other way round, a failure in between leaves
        // an archive this platform would call foreign; this way it leaves a row vouching for an
        // archive nobody holds, which nothing can be done with.
        $sha256 = hash('sha256', $files['manifeste.json']);
        $DB->insert_record(self::REGISTER, (object) [
            'archiveuuid' => $archiveid,
            'sha256' => $sha256,
            'candidateuuid' => identity_map::uuid_for(identity_map::TYPE_USER, $candidateid),
            'userid' => $producerid,
            'timecreated' => $now,
        ]);

        // The last argument, ignoreinvalidfiles, is false. The default leaves a file out of the
        // archive silently, and the manifest would then carry the digest of something absent.
        $file = get_file_packer('application/zip')->archive_to_storage(
            $zip,
            $contextid,
            $component,
            $filearea,
            0,
            $filepath,
            $prefix . $archiveid . '.zip',
            $producerid,
            false
        );
        if (!$file) {
            throw new \moodle_exception('errorexportfailed', 'tool_vae');
        }

        // The producer signs the event, as it signs the register and the file: a backup rewritten in the request of the
        // candidate who removed a piece is the holder's backup, and the act of the candidate stays traced by its own event.
        dossier_exported::create([
            'context' => \context_user::instance($candidateid),
            'objectid' => (int) $file->get_id(),
            'relateduserid' => $candidateid,
            'userid' => $producerid,
            // The count of the lines the schema refuses that the archive carries. It is produced all the same, and the log is
            // where an administrator finds out afterwards which exports the reimport will refuse: the log report shows the
            // description of the event, which says the count (dossier_exported::get_description()), and nothing of `other`.
            'other' => [
                'declarations' => count($claims),
                'sha256' => $sha256,
                'pieces' => (int) $full,
                'warnings' => count($this->warnings),
            ],
        ])->trigger();

        return $file;
    }

    /**
     * The register's row for an archive this class stored.
     *
     * Found by the archive's ULID, which ends the file name whatever prefix the caller chose.
     *
     * @param \stored_file $file
     * @return \stdClass Row of tool_vae_archive.
     */
    public static function registered(\stored_file $file): \stdClass {
        global $DB;

        $archiveid = substr($file->get_filename(), -30, 26);

        return $DB->get_record(self::REGISTER, ['archiveuuid' => $archiveid], '*', MUST_EXIST);
    }

    /**
     * The files of the archive, path => content.
     *
     * Kept apart from export() so that a test reads what goes into the archive without
     * unpacking one. The manifest comes first, and lists the digest of every other file.
     *
     * THE LANGUAGE (T-52, decided with the project owner on 13 October 2026). The archive is read
     * by a panel in the candidate's country, so every sentence the platform writes into it - the
     * notice, LISEZMOI.txt, dossier.pdf and its dates - is in the language of the candidate's
     * account, not of whoever presses the button. A language the site does not have gives the
     * site's own. The exporter's language is given back before this returns.
     *
     * THE TIMEZONE OF EVERY DATE IT WRITES IS THE CANDIDATE'S TOO (audit of 26 October 2026). A period is stored at
     * midnight of the timezone of the person who typed it; written in the one of whoever exports - the direction, an
     * administrator further west, the site for a weekly copy - it falls on the day before, and the PDF then says what
     * the JSON of the same archive does not. See display_date::force_timezone(), given back with the language.
     *
     * @param int $candidateid
     * @param \stdClass[] $claims Rows of tool_vae_evidence, oldest first.
     * @param string $archiveid The ULID naming this archive.
     * @param int $now
     * @param bool $fullpieces Whether the bytes of the files and the addresses of the links go in (see store()).
     * @param bool $strictpieces Whether an archive that would vouch for a file it does not hold is refused: a file that is
     *        missing or altered (see piece_overview::files()). False for the acts that must not stop - an erasure, a
     *        withdrawal, an uninstall - which keep what they can write. It does NOT command the documents the schema refuses:
     *        those never refuse, they are reported whatever this says (see warnings() and conformity_warnings()).
     * @param bool|null $forauthor Whether the archive is for its author (see store()); null: the current user is the candidate.
     * @param bool|null $withsections Whether the saved sections of the dossier de faisabilite go in (see store()); null: as the
     *        current user may read them.
     * @return string[]
     */
    public function build(
        int $candidateid,
        array $claims,
        string $archiveid,
        int $now,
        bool $fullpieces = false,
        bool $strictpieces = true,
        ?bool $forauthor = null,
        ?bool $withsections = null
    ): array {
        global $CFG, $USER;

        // What an earlier archive of this exporter reported is not this one's: emptied before anything can throw.
        $this->warnings = [];
        $candidate = \core_user::get_user($candidateid, '*', MUST_EXIST);
        $forauthor = $forauthor ?? ((int) $USER->id === $candidateid);
        $withsections = $withsections ?? draft_manager::can_read($candidateid);

        $lang = (string) $candidate->lang;
        $timezone = \core_date::get_user_timezone($candidate);
        $previous = force_current_language(
            get_string_manager()->translation_exists($lang, false) ? $lang : $CFG->lang
        );
        $previoustimezone = display_date::force_timezone($timezone);
        try {
            return $this->assemble(
                $candidate,
                $claims,
                $archiveid,
                $now,
                $fullpieces,
                $strictpieces,
                $forauthor,
                $withsections
            );
        } finally {
            display_date::force_timezone($previoustimezone);
            force_current_language($previous);
        }
    }

    /**
     * The files of the archive, written in whatever language is current - see build().
     *
     * @param \stdClass $candidate
     * @param \stdClass[] $claims Rows of tool_vae_evidence, oldest first.
     * @param string $archiveid The ULID naming this archive.
     * @param int $now
     * @param bool $fullpieces See build().
     * @param bool $strictpieces See build().
     * @param bool $forauthor See build(); false, here, is the closed default.
     * @param bool $withsections See build(); false, here, is the closed default.
     * @return string[]
     */
    private function assemble(
        \stdClass $candidate,
        array $claims,
        string $archiveid,
        int $now,
        bool $fullpieces = false,
        bool $strictpieces = true,
        bool $forauthor = false,
        bool $withsections = false
    ): array {
        global $CFG;

        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $candidate->id);

        $files = [];
        $entries = [];
        $claimed = [];
        $preuves = [];
        // The record and the rank of each declaration as the row holds them, by the file that carries it: what a warning names.
        $labels = [];
        foreach ($claims as $claim) {
            $competencyid = $this->competency_of($claim);
            $claimed[$competencyid] = $claim;

            $path = 'preuves/' . $claim->uuid . '.json';
            $labels[$path] = [(string) $claim->fichecode, (string) $claim->ficherang];
            $preuve = $this->preuve($claim, $personne, $competencyid);
            $preuves[] = $preuve;
            $files[$path] = self::encode($preuve);

            $divergence = anchor::divergence($claim, $this->gateway);
            $entries[] = [
                'fichier' => $path,
                'ancrage' => $divergence === null ? null : self::ANCHOR_STATES[$divergence],
            ];
        }

        $referentiel = $this->referentiel($claimed);
        $files['referentiel.json'] = self::encode($referentiel);

        $schemas = ['preuve.schema.json', 'commun/identifiant.schema.json'];
        // The saved sections of the dossier de faisabilite, for whoever may read them: a reader who sees the dossier and not
        // the sections on the screen does not carry them out of the platform either, and the backup, the safety net of an
        // uninstall, keeps them all. The file, its line in the manifest and the section of the PDF stand or fall together.
        $faisabilite = $withsections ? self::faisabilite((int) $candidate->id) : null;
        if ($faisabilite !== null) {
            $files[self::FAISABILITE_PATH] = self::encode($faisabilite);
            $schemas[] = 'faisabilite.schema.json';
        }
        // The preparation of the validation dossier: not the certifier's dossier, said in the file itself (T-11, lot 2).
        // What is saved and not deposited is read by its author alone (D-15 (3)): only an archive they take away carries it.
        $validation = $forauthor ? validation_overview::document((int) $candidate->id) : null;
        if ($validation !== null) {
            $files[self::VALIDATION_PATH] = self::encode($validation);
            $schemas[] = 'validation.schema.json';
        }
        // The reflective journal: the author's alone, so only an archive they export themself carries it (MC-22).
        $reflexion = $forauthor ? reflexion_overview::document((int) $candidate->id) : null;
        if ($reflexion !== null) {
            $files[self::REFLEXION_PATH] = self::encode($reflexion);
            $schemas[] = 'reflexion.schema.json';
        }
        // What the candidate says is outside their activity (T-42, lot 3): their word, meant for the panel, in every archive
        // that holds a mark. Declarative: it exempts from nothing, and the file says so.
        $scope = scope_manager::document((int) $candidate->id);
        if ($scope !== null) {
            $files[scope_manager::PATH] = self::encode($scope);
            $schemas[] = 'hors_activite.schema.json';
        }
        // The supporting pieces (T-13, lot 3): the list always; the bytes and the addresses only for the archive the
        // candidate exports themself and for the backup kept on the platform (MC-28).
        $bytes = $fullpieces ? piece_overview::files((int) $candidate->id, $strictpieces) : [];
        $pieces = piece_overview::document((int) $candidate->id, $fullpieces, null, $fullpieces ? array_keys($bytes) : null);
        if ($pieces !== null) {
            $files[piece_overview::PATH] = self::encode($pieces);
            $schemas[] = 'pieces.schema.json';
            foreach ($bytes as $path => $content) {
                $files[$path] = $content;
            }
        }
        // Every document is held to the schema the archive carries, before the digests vouch for it - and it is REPORTED, never
        // refused (decision of the project owner, 27 October 2026): the archive is produced as the dossier stands, and the
        // notice below, like the screen that asked for it, says that the reimport will refuse it. Asked of every archive,
        // whatever the caller promised the person: the uninstall and the refresh of an erasure never refuse, and their copy
        // carries the mention all the same, found out the day it is needed rather than the day it is lost.
        $this->warnings = self::conformity_warnings($files, $labels);
        foreach ($schemas as $schema) {
            $files['schema/' . $schema] = self::read_schema($schema);
        }

        $notice = get_string('exportwithdrawalnotice', 'tool_vae');
        $files['LISEZMOI.txt'] = implode("\n\n", [
            get_string('exportreadmeintro', 'tool_vae', (object) [
                'name' => fullname($candidate),
                'date' => display_date::format($now, get_string('strftimedatetime', 'langconfig')),
            ]),
            // Right after the introduction, where a reader of the notice sees it: the lines the schema refuses, said in the
            // language of the archive, by the declaration, the file and the value.
            ...($this->warnings === [] ? [] : [
                implode("\n", [
                    get_string('exportreadmewarning', 'tool_vae'),
                    ...array_map(fn($line) => '- ' . $line, self::describe_warnings($this->warnings)),
                ]),
            ]),
            get_string('exportreadmecontents', 'tool_vae'),
            ...($pieces === null ? [] : [get_string('exportreadmepieces', 'tool_vae')]),
            // What the archive does not carry, so that nobody takes it for a complete copy of the database: a reimport, and the
            // backup taken before an uninstall, give back the declarations, the signatures and the sections, and nothing of this.
            get_string('exportreadmeabsent', 'tool_vae'),
            ...($forauthor ? [] : [get_string('exportreadmeabsentauthor', 'tool_vae')]),
            $notice,
            // The archive's ULID, not the digest of the manifest: this notice is listed in the
            // manifest, so it cannot carry that digest. The platform's register holds it.
            get_string('exportreadmeverify', 'tool_vae', $archiveid),
        ]) . "\n";

        $head = [
            'archive' => $archiveid,
            'exporte_le' => self::iso($now),
            'produit_par' => [
                'systeme' => self::SYSTEM,
                'version' => $CFG->release,
                'composant' => 'tool_vae',
                'version_composant' => \core_plugin_manager::instance()->get_plugin_info('tool_vae')->release,
            ],
            'candidat' => ['identifiant' => $personne, 'nom' => fullname($candidate)],
            'avertissement' => $notice,
        ];

        // Drawn from the very arrays the JSON files above encode, before the digests are taken:
        // the printed copy cannot say anything the archive does not, and the manifest vouches for it.
        $rubriques = $faisabilite['rubriques'] ?? [];
        $files[pdf_renderer::PATH] = pdf_renderer::render(
            $head,
            $preuves,
            $referentiel,
            $rubriques,
            $validation ?? [],
            piece_overview::by_declaration($pieces),
            $scope ?? []
        );

        $digests = [];
        foreach ($files as $path => $content) {
            $digests[] = ['chemin' => $path, 'octets' => strlen($content), 'sha256' => hash('sha256', $content)];
        }

        $manifest = [
            'format' => self::FORMAT,
            'version_format' => self::FORMAT_VERSION,
            'schema_preuve' => self::SCHEMA_VERSION,
        ] + ($faisabilite === null ? [] : ['schema_faisabilite' => self::FAISABILITE_SCHEMA_VERSION])
            + ($validation === null ? [] : ['schema_validation' => validation_overview::SCHEMA_VERSION])
            + ($reflexion === null ? [] : ['schema_reflexion' => reflexion_overview::SCHEMA_VERSION])
            + ($scope === null ? [] : ['schema_hors_activite' => scope_manager::SCHEMA_VERSION])
            + ($pieces === null ? [] : ['schema_pieces' => piece_overview::SCHEMA_VERSION]) + $head + [
            'preuves' => $entries,
            'fichiers' => $digests,
        ];

        return ['manifeste.json' => self::encode($manifest)] + $files;
    }

    /**
     * The text sections of the dossier de faisabilité, each in its latest SAVED version (T-10, lot 4b).
     *
     * Never the draft in progress, and never identity, status or contact details: only sections the
     * catalogue says are text. MC-13 - the archive does not carry the identity of the candidate -
     * is kept by this loop and by nothing else, which is why the importer refuses any other code.
     *
     * @param int $candidateid
     * @return array|null The document of faisabilite.schema.json, or null when nothing is written.
     */
    private static function faisabilite(int $candidateid): ?array {
        $saved = faisabilite_overview::saved_versions($candidateid);

        $rubriques = [];
        foreach (faisabilite_catalog::sections() as $code => $definition) {
            if ($definition['kind'] !== faisabilite_catalog::KIND_TEXT || empty($saved[$code])) {
                continue;
            }
            $places = $saved[$code];
            ksort($places);
            foreach ($places as $slot => $version) {
                $rubriques[] = [
                    'code' => (string) $code,
                    'emplacement' => (int) $slot,
                    'version' => $version['number'],
                    'enregistree_le' => self::iso($version['created']),
                    'texte' => $version['content'],
                ];
            }
        }

        return $rubriques ? ['rubriques' => $rubriques] : null;
    }

    /**
     * What a printed or word-processed copy of the dossier says: the claims and the record.
     *
     * The same two arrays the archive encodes (preuves/*.json and referentiel.json), without the
     * archive: nothing is registered, stored or logged here. T-83 builds an editable copy from
     * them, so that the copy cannot say what the archive does not. The caller sets the language and the timezone.
     *
     * @param int $candidateid
     * @return array ['preuves' => array[], 'referentiel' => array]
     * @throws \moodle_exception errorexportempty when the candidate has no declaration.
     */
    public function snapshot(int $candidateid): array {
        global $DB;

        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $candidateid], 'timecreated ASC, id ASC');
        if (!$claims) {
            throw new \moodle_exception('errorexportempty', 'tool_vae');
        }

        $personne = identity_map::uuid_for(identity_map::TYPE_USER, $candidateid);
        $claimed = [];
        $preuves = [];
        foreach ($claims as $claim) {
            $competencyid = $this->competency_of($claim);
            $claimed[$competencyid] = $claim;
            $preuves[] = $this->preuve($claim, $personne, $competencyid);
        }

        return ['preuves' => $preuves, 'referentiel' => $this->referentiel($claimed)];
    }

    /**
     * Whether the current user may download an archive.
     *
     * Its author only, within its lifetime, and only while they may still export that dossier:
     * a right withdrawn after the archive was produced is withdrawn from the download too.
     *
     * @param \stored_file $file
     * @param int $now
     * @return bool
     */
    public static function may_download(\stored_file $file, int $now): bool {
        global $USER;

        if (
            $file->get_component() !== 'tool_vae'
            || $file->get_filearea() !== self::FILEAREA
            || $file->is_directory()
        ) {
            return false;
        }
        if ((int) $file->get_userid() !== (int) $USER->id || self::expired((int) $file->get_timecreated(), $now)) {
            return false;
        }

        $context = \context::instance_by_id($file->get_contextid(), IGNORE_MISSING);

        return $context && (int) $context->contextlevel === CONTEXT_USER
            && access::can_export((int) $context->instanceid);
    }

    /**
     * Delete every archive past its lifetime.
     *
     * @param int $now
     * @return int How many archives were deleted.
     */
    public static function purge_expired(int $now): int {
        global $DB;

        $records = $DB->get_records_select(
            'files',
            'component = :component AND filearea = :filearea AND filename <> :dot AND timecreated <= :limit',
            [
                'component' => 'tool_vae',
                'filearea' => self::FILEAREA,
                'dot' => '.',
                'limit' => $now - self::LIFETIME,
            ]
        );

        $fs = get_file_storage();
        foreach ($records as $record) {
            $fs->get_file_instance($record)->delete();
        }

        return count($records);
    }

    /**
     * Delete the archives waiting for a candidate, whatever their age.
     *
     * Called when the dossier is erased, and when a signatory is stripped from it: an archive
     * still on the platform would otherwise keep, for up to a day, what the erasure removed.
     * Copies already downloaded are out of reach, which the archive states.
     *
     * @param int $candidateid
     */
    public static function discard(int $candidateid): void {
        $context = \context_user::instance($candidateid, IGNORE_MISSING);
        if ($context) {
            get_file_storage()->delete_area_files($context->id, 'tool_vae', self::FILEAREA);
        }
    }

    /**
     * Whether an archive created at a given time is past its lifetime.
     *
     * @param int $timecreated
     * @param int $now
     * @return bool
     */
    private static function expired(int $timecreated, int $now): bool {
        return $timecreated <= $now - self::LIFETIME;
    }

    /**
     * One claim, in the shape of preuve.schema.json.
     *
     * @param \stdClass $claim
     * @param string $personne The candidate's ULID.
     * @param int $competencyid
     * @return array
     */
    private function preuve(\stdClass $claim, string $personne, int $competencyid): array {
        global $DB;

        $competence = identity_map::uuid_for(identity_map::TYPE_COMPETENCY, $competencyid);
        $correspondances = [
            self::correspondence($personne, 'personne', (int) $claim->candidateid),
            self::correspondence($competence, 'competence', $competencyid),
        ];

        $attestations = [];
        foreach (attestation_manager::attestations((int) $claim->id) as $row) {
            if ($row->withdrawnat !== null) {
                // A date and a reason, nothing else. Not even the identifier: a ULID carries the
                // time it was minted, which is the time of signing, which withdrawal erases.
                $attestations[] = [
                    'statut' => 'retiree',
                    'retiree_le' => self::iso((int) $row->withdrawnat),
                    'motif' => $row->withdrawnreason,
                ];
                continue;
            }

            // A signature given here names an account; one carried in from an archive names the
            // signatory by the ULID the archive gave, and no account of this site.
            if ($row->attestantid !== null) {
                $attestant = identity_map::uuid_for(identity_map::TYPE_USER, (int) $row->attestantid);
                $correspondances[] = self::correspondence($attestant, 'personne', (int) $row->attestantid);
            } else if (!empty($row->attestantuuid)) {
                $attestant = $row->attestantuuid;
            } else {
                throw new \coding_exception("Attestation {$row->id} is in force and names no signatory.");
            }

            $attestations[] = [
                'statut' => 'en_vigueur',
                'id' => $row->uuid,
                'attestant' => $attestant,
                'role' => $row->attestantrole,
                'declaration' => $row->statement,
                'attestee_le' => self::iso((int) $row->attestedat),
            ];
        }

        $cadre = ['type' => $claim->settingtype];
        if ($claim->settingdetail !== null && trim($claim->settingdetail) !== '') {
            $cadre['precision'] = $claim->settingdetail;
        }

        // A claim carried over from another one (T-30, lot 3): which, and when. Never who decided the
        // correspondence nor the note: they stay on this site. Both claims belong to the same dossier,
        // so the archive always holds the one it names.
        $filiation = [];
        $carry = $DB->get_record_sql(
            'SELECT o.uuid, c.carriedat FROM {tool_vae_carry} c JOIN {tool_vae_evidence} o ON o.id = c.oldevidenceid
              WHERE c.newevidenceid = :id',
            ['id' => (int) $claim->id]
        );
        if ($carry) {
            $filiation = ['reportee_depuis' => $carry->uuid, 'reportee_le' => self::iso((int) $carry->carriedat)];
        }

        return $filiation + [
            'id' => $claim->uuid,
            'personne' => $personne,
            'competence' => $competence,
            'fiche' => [
                'code' => $claim->fichecode,
                'rang' => $claim->ficherang,
                'mise_a_jour' => self::iso((int) $claim->ficheupdated),
            ],
            'periode' => [
                'debut' => self::iso((int) $claim->periodstart),
                'fin' => self::iso((int) $claim->periodend),
            ],
            'cadre' => $cadre,
            'situation' => [
                'texte' => $claim->situation,
                // Moodle declares FORMAT_MARKDOWN as the string '4'; the column returns a string too.
                'format' => (string) $claim->situationformat === FORMAT_MARKDOWN ? 'markdown' : 'texte',
            ],
            'declaree_le' => self::iso((int) $claim->timecreated),
            'attestations' => $attestations,
            'correspondances' => array_values(array_unique($correspondances, SORT_REGULAR)),
        ];
    }

    /**
     * The certification records the claims refer to, and the competencies they name.
     *
     * Labels are read as they stand at export time. A label changed locally since the claim
     * shows as such through `etat`, from the marks the import leaves.
     *
     * @param \stdClass[] $claimed One claim per competency, keyed by competency id.
     * @return array
     */
    private function referentiel(array $claimed): array {
        global $DB;

        $fiches = [];
        $sources = [];
        // T-33: the competencies of each record by id, read once, to say which bloc a claimed competency sits under.
        $trees = [];
        $blocs = [];
        foreach ($claimed as $competencyid => $claim) {
            $code = $claim->fichecode;
            if (!array_key_exists($code, $sources)) {
                $source = $DB->get_record('tool_vae_source', ['fichecode' => $code]);
                $sources[$code] = $source ?: null;
                $fiches[$code] = [
                    'code' => $code,
                    'type' => $source ? $source->fichetype : null,
                    'source' => $source ? $source->sourceurl : null,
                    'mise_a_jour_source' => $source && $source->sourceupdated ? self::iso((int) $source->sourceupdated) : null,
                    'licence' => $source ? $source->licence : null,
                    'attribution' => $source ? $source->attribution : null,
                    'competences' => [],
                ];
            }

            $kind = $sources[$code] ? $DB->get_field('tool_vae_obsolete', 'kind', [
                'competencyid' => $competencyid,
                'sourceid' => $sources[$code]->id,
            ]) : false;

            // The label is read from the persistent, NOT through the core's API: that one refuses when the competencies are
            // switched off, which stopped the backup an uninstall takes first, and refuses a reader without the viewing right
            // or nobody at all (the command line), which wrote `intitule: null`. Archives and backups are produced where no
            // one is logged in; what is read is the label of a framework imported here for everyone to declare against - the
            // same that framework_tree() reads below, for the same reason.
            $entry = [
                'identifiant' => identity_map::uuid_for(identity_map::TYPE_COMPETENCY, $competencyid),
                'rang' => $claim->ficherang,
                'intitule' => $this->gateway->competency_name($competencyid),
                'etat' => $kind === false ? null : (self::RECORD_STATES[$kind] ?? $kind),
            ];

            // The bloc (T-33), only when there is one: the rank of the bloc it sits under, and the bloc listed
            // once for the record. A bloc that is itself claimed is listed too, so a reader finds its rank there.
            if (!array_key_exists($code, $trees)) {
                $trees[$code] = $sources[$code]
                    ? $this->gateway->framework_tree((int) $sources[$code]->frameworkid) : [];
            }
            $own = $trees[$code][$competencyid] ?? null;
            $parent = $own && $own->parentid > 0 ? ($trees[$code][$own->parentid] ?? null) : null;
            $isbloc = false;
            foreach ($trees[$code] as $other) {
                if ($other->parentid === $competencyid) {
                    $isbloc = true;
                    break;
                }
            }
            if ($parent) {
                $rank = passage_manager::rank_of((string) $parent->idnumber, $code);
                if ($rank !== '') {
                    $entry['bloc'] = $rank;
                    $blocs[$code][$rank] = ['rang' => $rank, 'intitule' => $parent->label];
                }
            } else if ($isbloc) {
                $blocs[$code][$claim->ficherang] = ['rang' => $claim->ficherang, 'intitule' => $own->label];
            }

            $fiches[$code]['competences'][] = $entry;
        }

        foreach ($blocs as $code => $list) {
            uksort($list, 'strnatcmp');
            $fiches[$code]['blocs'] = array_values($list);
        }

        // Where the certifier publishes its rule of validation (T-41): addresses and short titles, never a rule. Only once the site
        // has opened them: what a candidate may not see on the screen does not leave in the documents they show either.
        $refs = empty(get_config('tool_vae', 'refopen')) ? [] : ref_manager::for_fiches(array_keys($fiches));
        foreach ($refs as $code => $list) {
            $fiches[$code]['regle_validation'] = array_map(fn($ref) => [
                'intitule' => $ref->title,
                'adresse' => $ref->url,
                'verifie_le' => $ref->checkedat === null ? null : self::iso($ref->checkedat),
            ], $list);
        }

        return ['fiches' => array_values($fiches)];
    }

    /**
     * The competency a claim was made against.
     *
     * A row created before competencyid existed falls back on the link the core still holds,
     * when there is exactly one. Otherwise the export stops: a claim exported against a guessed
     * competency is worse than no export.
     *
     * @param \stdClass $claim
     * @return int
     */
    private function competency_of(\stdClass $claim): int {
        if ($claim->competencyid !== null) {
            return (int) $claim->competencyid;
        }

        $anchor = $this->gateway->read_anchor((int) $claim->userevidenceid);
        if ($anchor !== null && count($anchor->competencyids) === 1) {
            return (int) reset($anchor->competencyids);
        }

        throw new \moodle_exception(
            'errorexportnocompetency',
            'tool_vae',
            '',
            $claim->fichecode . '-' . $claim->ficherang
        );
    }

    /**
     * One entry of correspondances[].
     *
     * @param string $identifier
     * @param string $type
     * @param int $value The Moodle identifier, written as a string.
     * @return array
     */
    private static function correspondence(string $identifier, string $type, int $value): array {
        return ['identifiant' => $identifier, 'type' => $type, 'systeme' => self::SYSTEM, 'valeur' => (string) $value];
    }

    /**
     * The lines of the documents of an archive that the schemas it embeds refuse. It reports them; it refuses nothing.
     *
     * THE SECOND LOOK, BEHIND THE ONES AT THE DOOR OF EACH WRITE (evidence_manager::validate(), scope_manager::mark()). A claim
     * recorded by an earlier version, a row written to the database by hand or by a migration, a value a later version of
     * Moodle stores differently: none of them passed through those doors. Built as it stood, the archive is refused whole by
     * the reimport - the valid declarations beside the faulty one with it, and an uninstall backup likewise. It used to be
     * refused here, before anything was written (audit of 26 October 2026). The project owner decided on 27 October 2026 that
     * it is produced all the same, and the person warned: a candidate must never be stuck on the day the dossier is due, and
     * the PDF laid out for the panel does not depend on the schema. Nothing is cleaned and nothing is rewritten: the value
     * stays as the dossier holds it, and the reimport stays as strict as it was.
     *
     * The very rules of the import, read from the same docs/schema by the same validator (schema_validator). Each error is
     * given the value it concerns, shortened, and - for a declaration - the record and the rank as the row holds them. Only
     * the dossier being built is looked at: nothing of another candidate can be named.
     *
     * Asked of every archive, whatever its caller promised the person (assemble()): refusal belongs to the two other
     * protections, each with its own switch.
     *
     * @param string[] $files Path => content, as assemble() holds them.
     * @param array[] $labels Path of a declaration's file => [record code, rank], as the row holds them.
     * @return array[] One entry per error, as warnings() describes them.
     */
    private static function conformity_warnings(array $files, array $labels): array {
        $schemas = [
            self::FAISABILITE_PATH => 'faisabilite.schema.json',
            self::VALIDATION_PATH => 'validation.schema.json',
            self::REFLEXION_PATH => 'reflexion.schema.json',
            scope_manager::PATH => 'hors_activite.schema.json',
            piece_overview::PATH => 'pieces.schema.json',
        ];

        $validator = new schema_validator();
        $warnings = [];
        foreach ($files as $path => $content) {
            if (str_starts_with($path, 'preuves/')) {
                $schema = 'preuve.schema.json';
            } else if (isset($schemas[$path])) {
                $schema = $schemas[$path];
            } else {
                continue;
            }

            $document = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
            foreach ($validator->validate($document, $schema) as $error) {
                // Each line starts with the JSON pointer of the value, up to the first space (schema_validator::validate()).
                $pointer = (string) strstr($error . ' ', ' ', true);
                [$fiche, $rang] = $labels[$path] ?? ['', ''];
                $warnings[] = [
                    'file' => $path,
                    'declaration' => isset($labels[$path]) ? self::shorten($fiche) . '-' . self::shorten($rang) : '',
                    'fiche' => self::shorten($fiche),
                    'rang' => self::shorten($rang),
                    'field' => $pointer,
                    'value' => self::value_at($document, $pointer),
                    'error' => $error,
                ];
            }
        }

        return $warnings;
    }

    /**
     * The value a JSON pointer designates in a decoded document, as a short text.
     *
     * @param mixed $document As json_decode() returns it without its second argument.
     * @param string $pointer As schema_validator writes it: /name/0/name, no escaping.
     * @return string|null Null when the document holds nothing there (a missing field).
     */
    private static function value_at($document, string $pointer): ?string {
        $value = $document;
        foreach (array_filter(explode('/', $pointer), 'strlen') as $part) {
            if ($value instanceof \stdClass && property_exists($value, $part)) {
                $value = $value->$part;
            } else if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } else {
                return null;
            }
        }

        $text = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::shorten($text);
    }

    /**
     * A value for a line of text: on one line, and no longer than self::VALUE_SHOWN characters.
     *
     * @param string $text
     * @return string
     */
    private static function shorten(string $text): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return \core_text::strlen($text) > self::VALUE_SHOWN ? \core_text::substr($text, 0, self::VALUE_SHOWN) . '...' : $text;
    }

    /**
     * A copy of one file of the schema, as shipped with this plugin.
     *
     * @param string $name Path below docs/schema.
     * @return string
     */
    private static function read_schema(string $name): string {
        global $CFG;

        $path = $CFG->dirroot . '/admin/tool/vae/docs/schema/' . $name;
        $content = is_readable($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new \moodle_exception('errorexportfailed', 'tool_vae');
        }

        return $content;
    }

    /**
     * A timestamp as the schema writes it: ISO 8601, UTC, to the second.
     *
     * Never a bare date. A period starting on the first of a month falls on the last day of the
     * previous one a few hours away, which is the defect corelabel was stored to avoid.
     *
     * @param int $timestamp
     * @return string
     */
    public static function iso(int $timestamp): string {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    /**
     * JSON as written into the archive.
     *
     * @param array $data
     * @return string
     */
    private static function encode(array $data): string {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }
}
