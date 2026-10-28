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

use tool_vae\event\dossier_imported;

/**
 * Rebuilding a dossier from the normative archive - the second half of lot 8.
 *
 * CRITERION 6, AS REDEFINED WITH THE PROJECT OWNER ON 12 OCTOBER 2026 (the original wording was
 * lost with the PRP): an archive exported then reimported gives back the same claims, with the
 * same ULIDs, after its SHA-256 digests have been checked; an altered archive is refused whole.
 *
 * EVERYTHING IS CHECKED BEFORE ANYTHING IS WRITTEN, AND THEN EVERYTHING IS WRITTEN AT ONCE.
 * check() reads the whole archive and refuses it at the first fault - a digest, a file the
 * manifest does not list, a claim the schema refuses, a competency this site does not hold, an
 * identifier already in use, an attestation this site would have refused. write() then runs
 * in one transaction: a failure on the last claim leaves no trace of the first.
 *
 * DECIDED WITH THE PROJECT OWNER, 12 OCTOBER 2026:
 *   - the importer chooses the account the dossier goes to; the archive's ULID for the candidate
 *     is then given to that account, and an account that already holds another one refuses it;
 *   - the certification record must have been imported first; one competency missing refuses
 *     the archive;
 *   - attestations come back as they were signed, tied to no account of this site, and only
 *     a site administrator may withdraw them - see attestation_manager::may_withdraw();
 *   - an identifier already present here refuses the whole archive; nothing is merged.
 *
 * DECIDED WITH THE PROJECT OWNER, 27 OCTOBER 2026: A COMPETENCY THIS SITE NUMBERS DIFFERENTLY REFUSES THE ARCHIVE WHOLE, AND EVERY
 * SUCH COMPETENCY IS NAMED. Each site gives a competency an identifier of its own the first time a dossier leaves it, and an
 * identifier is never reassigned: the archive of another site, whose identifier for a competency is not this site's, cannot be
 * filed under it. Nothing is adopted automatically - that a record code and a rank mean the same competency on two sites is a
 * judgement, not a check. So the archive is refused, in ONE refusal that names every competency in conflict (with the name this
 * site gives it) and says what can be done today - see require_adoptable_competencies(). A screen where a person validates the
 * correspondence is for later; this class does not build it.
 *
 * DIGESTS DETECT DAMAGE; THE REGISTER DETECTS FORGERY (lot 8 quater). Whoever alters a claim can
 * recompute its digest in the manifest, and the digests then agree. What they cannot rewrite is
 * the digest of the manifest this platform kept when it issued the archive - see exporter::store().
 * So an archive whose ULID is in the register must carry that very manifest, or it is refused
 * whole; one whose ULID is not there comes from another platform - decided with the project owner
 * on 12 October 2026: it stays reimportable, and the importer is told plainly that its
 * authenticity cannot be verified here.
 *
 * ONLY preuves/ IS IMPORTED. Every other file the manifest lists - referentiel.json, schema/,
 * LISEZMOI.txt, and dossier.pdf since 0.10.0 - is checked against its digest, so that an altered
 * one refuses the archive, and is never read as data. An archive produced before 0.10.0 carries
 * no dossier.pdf and lists none: nothing requires it.
 *
 * ULIDS ONLY. The schema also admits UUID v4 identifiers, which this plugin never produces and
 * its columns cannot hold. An archive carrying one is refused by name, not truncated.
 *
 * The rules of the schema are not restated here: schema_validator reads them from docs/schema.
 * Only what a JSON schema cannot say is checked in this class.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dossier_importer {
    /** @var string The manifest, the one file the archive does not list. */
    public const MANIFEST = 'manifeste.json';

    /** @var int More files than any dossier holds; beyond it the archive is refused before any is read. */
    public const MAX_FILES = 2000;

    /** @var int Uncompressed size beyond which the archive is refused before any file is read. */
    public const MAX_BYTES = 52428800;

    /**
     * A path the archive may hold: segments of letters, digits, dash and underscore, joined by
     * dots or slashes, never two in a row. No `..`, no leading slash, no backslash, and no
     * newline after the last segment: without the D, PHP's $ lets one through.
     *
     * @var string
     */
    private const SAFE_PATH = '/^[A-Za-z0-9_-]+(?:[.\/][A-Za-z0-9_-]+)*$/D';

    /** @var string Where a claim lives in the archive. */
    private const CLAIM_PATH = '/^preuves\/([0-7][0-9A-HJKMNP-TV-Z]{25})\.json$/D';

    /** @var int Competencies named in the refusal of an archive that numbers them differently; the others are counted. */
    private const HELD_LISTED = 10;

    /** @var int Characters of the name this site gives a competency that the same refusal shows; a longer one is cut. */
    private const HELD_LABEL_LENGTH = 100;

    /** @var competency_gateway */
    private $gateway;

    /** @var schema_validator */
    private $validator;

    /**
     * Constructor.
     *
     * @param competency_gateway|null $gateway Only passed by tests.
     */
    public function __construct(?competency_gateway $gateway = null) {
        $this->gateway = $gateway ?? new competency_gateway();
        $this->validator = new schema_validator();
    }

    /**
     * Whether the current user may rebuild dossiers from archives.
     *
     * @return bool
     */
    public static function can_import(): bool {
        return has_capability('tool/vae:importdossier', \context_system::instance());
    }

    /**
     * Require permission to rebuild a candidate's dossier.
     *
     * Two capabilities. This plugin's own, and the core's right to write evidence on someone
     * else's behalf, without which the first core call would fail halfway - asked BEFORE the
     * archive is read, for the reason import.php gives about frameworks.
     *
     * An id no account holds gets the very refusal an account the importer may not write for
     * gets, on the system context in both cases: a `record not found`, or a link to the profile
     * in one answer only, would tell which ids are accounts.
     *
     * @param int $candidateid
     * @return \context_user The candidate's user context.
     */
    public static function require_can_import(int $candidateid): \context_user {
        require_capability('tool/vae:importdossier', \context_system::instance());

        $context = \context_user::instance($candidateid, IGNORE_MISSING);
        if (!$context || !has_capability('moodle/competency:userevidencemanage', $context)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'moodle/competency:userevidencemanage',
                'nopermissions',
                ''
            );
        }

        return $context;
    }

    /**
     * Rebuild a dossier from an archive file.
     *
     * The caller has checked the right to import - see require_can_import().
     *
     * @param int $candidateid The account the dossier goes to.
     * @param string $zippath
     * @return \stdClass declarations and attestations, the counts written.
     */
    public function import(int $candidateid, string $zippath): \stdClass {
        // MORE MEMORY BEFORE THE ARCHIVE IS READ, NOT AFTER IT HAS RUN OUT. read_archive() keeps the whole archive in memory -
        // up to MAX_BYTES - and the checks that follow hold the pieces beside it. The request starts at the site's standard
        // 128 MB, where a fatal error leaves nothing to catch. The import pages of the core ask for the same room.
        raise_memory_limit(MEMORY_EXTRA);

        return $this->import_files($candidateid, self::read_archive($zippath));
    }

    /**
     * Rebuild a dossier from an upload the page has saved to a temporary file, and take that file away whatever happens.
     *
     * WHY HERE, AND NOT IN THE PAGE. The copy of the upload holds the whole archive, pieces included, and nothing else removes
     * it before the core's cleaning of the temporary folder, a week on. A `finally` removes it on every exception, of this
     * plugin or not - but not on a fatal error, and running out of memory is one: no block runs. What does run after a
     * fatal error is the shutdown functions of the core, which raise the memory limit for that very purpose. So the removal
     * is registered there BEFORE the file is read, and again in a `finally` for the ordinary way out: the page redirects,
     * which ends the script, after this method has returned.
     *
     * @param int $candidateid The account the dossier goes to.
     * @param string $path The temporary copy of the upload.
     * @return \stdClass As import() does.
     */
    public function import_upload(int $candidateid, string $path): \stdClass {
        \core\shutdown_manager::register_function(static function () use ($path): void {
            self::discard($path);
        });

        try {
            return $this->import($candidateid, $path);
        } finally {
            self::discard($path);
        }
    }

    /**
     * Take a temporary file away, if it is still there.
     *
     * @param string $path
     */
    private static function discard(string $path): void {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Rebuild a dossier from the files of an archive, path => content.
     *
     * Kept apart from import() so that a test feeds exporter::build() straight in.
     *
     * @param int $candidateid
     * @param string[] $files
     * @return \stdClass declarations and attestations, the counts written; rubriques, the text
     *                   sections of the dossier de faisabilité restored, and rubriquesgardees,
     *                   those left as they were; archive, its ULID; foreign, true when this
     *                   platform did not issue it.
     */
    public function import_files(int $candidateid, array $files): \stdClass {
        if (!competency_gateway::is_subsystem_enabled()) {
            throw new \moodle_exception('errorcompetenciesdisabled', 'tool_vae');
        }

        $plan = $this->check($files);
        $result = $this->write($candidateid, $plan);
        $result->archive = $plan->archive;
        $result->foreign = $plan->foreign;

        dossier_imported::create([
            'context' => \context_user::instance($candidateid),
            'relateduserid' => $candidateid,
            'other' => [
                'archive' => $plan->archive,
                'declarations' => $result->declarations,
                'attestations' => $result->attestations,
                'rubriques' => $result->rubriques,
                'foreign' => (int) $plan->foreign,
            ],
        ])->trigger();

        return $result;
    }

    /**
     * The files of a zip archive, path => content.
     *
     * The listing is read and judged BEFORE any file is: its size, its number of entries, the
     * shape of every path. Each path is judged as the archive spells it, byte for byte - an
     * archive this plugin produced never needs cleaning, so nothing is cleaned. Nothing touches
     * the disk: the files are read into memory, where the checks that follow look at them.
     *
     * @param string $zippath
     * @return string[]
     */
    public static function read_archive(string $zippath): array {
        $zip = new \ZipArchive();
        if (!is_readable($zippath) || $zip->open($zippath, \ZipArchive::RDONLY) !== true) {
            throw new \moodle_exception('errorarchiveunreadable', 'tool_vae');
        }

        $entries = [];
        $bytes = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index, \ZipArchive::FL_ENC_RAW);
            if ($entry === false) {
                throw new \moodle_exception('errorarchiveunreadable', 'tool_vae');
            }
            if (str_ends_with($entry['name'], '/')) {
                // A directory: it holds nothing to read, and nothing is written to disk to need it.
                continue;
            }
            $bytes += $entry['size'];
            if (!preg_match(self::SAFE_PATH, $entry['name']) || isset($entries[$entry['name']])) {
                throw new \moodle_exception('errorarchivefiles', 'tool_vae', '', s($entry['name']));
            }
            $entries[$entry['name']] = $entry;

            // Inside the loop: a listing of a million entries is refused at the 2001st, before
            // the rest is stat'ed or kept here. libzip itself read the whole central directory at
            // open(); only the size of the uploaded file bounds that, and PHP's upload_max_filesize
            // and post_max_size set it, since the form's maxbytes does not apply to a user who may
            // ignore file size limits, a site admin among them.
            // A size of 2^63 or more reaches PHP negative: it would lower the total under the
            // cap, and getFromIndex() below takes a length under 1 as `the whole entry`.
            if (count($entries) > self::MAX_FILES || $entry['size'] < 0 || $bytes > self::MAX_BYTES) {
                throw new \moodle_exception('errorarchivetoolarge', 'tool_vae');
            }
        }

        // The sizes above are what the archive SAYS. Each file is read one byte past its declared
        // size and refused unless it holds exactly that size, so that a file lying about it is
        // caught after one byte, not after filling the memory - and the total stays under the cap.
        $files = [];
        foreach ($entries as $path => $entry) {
            $content = $zip->getFromIndex($entry['index'], $entry['size'] + 1);
            if ($content === false || strlen($content) !== $entry['size']) {
                throw new \moodle_exception('errorarchiveunreadable', 'tool_vae');
            }
            $files[$path] = $content;
        }
        $zip->close();

        return $files;
    }

    /**
     * Check an archive whole, and say what writing it would do.
     *
     * @param string[] $files path => content.
     * @return \stdClass archive, foreign, personne, competencies (ULID => competency id), named (what each competency is
     *         called, for the refusal write() asks again) and claims.
     */
    public function check(array $files): \stdClass {
        global $DB;

        $manifest = $this->manifest($files);

        // Before the digests: an archive this platform issued and someone has since rewritten,
        // digests included, is refused for what it is. A forger who changes the archive's ULID
        // escapes this check, and the archive then reads as foreign - imported with the warning,
        // never as verified. That is the limit of a register, accepted with the project owner.
        // No row is written for a foreign archive: the register would then vouch, on the next
        // reimport, for an archive this platform never produced.
        $issued = $DB->get_field(exporter::REGISTER, 'sha256', ['archiveuuid' => $manifest->archive]);
        if ($issued !== false && !hash_equals($issued, hash('sha256', $files[self::MANIFEST]))) {
            throw new \moodle_exception('errorarchivenotasissued', 'tool_vae', '', $manifest->archive);
        }

        $listed = $this->listed_files($manifest, $files);

        $claimpaths = [];
        foreach ($manifest->preuves as $entry) {
            $path = $entry instanceof \stdClass && is_string($entry->fichier ?? null) ? $entry->fichier : '';
            if (!preg_match(self::CLAIM_PATH, $path) || !isset($listed[$path]) || isset($claimpaths[$path])) {
                throw new \moodle_exception('errorarchivefiles', 'tool_vae', '', s($path));
            }
            $claimpaths[$path] = true;
        }
        foreach (array_keys($listed) as $path) {
            if (strpos($path, 'preuves/') === 0 && !isset($claimpaths[$path])) {
                throw new \moodle_exception('errorarchivefiles', 'tool_vae', '', s($path));
            }
        }
        if (!$claimpaths) {
            throw new \moodle_exception('errorarchiveempty', 'tool_vae');
        }

        $personne = $manifest->candidat->identifiant;
        $competencies = [];
        $named = [];
        $claims = [];
        $attestationids = [];
        foreach (array_keys($claimpaths) as $path) {
            $preuve = $this->preuve($path, $files[$path]);

            if ($preuve->personne !== $personne) {
                throw new \moodle_exception('errorarchiveperson', 'tool_vae', '', $path);
            }
            if ($DB->record_exists('tool_vae_evidence', ['uuid' => $preuve->id])) {
                throw new \moodle_exception('errorarchiveexists', 'tool_vae', '', $preuve->id);
            }

            $inforce = 0;
            foreach ($preuve->attestations as $attestation) {
                if ($attestation->statut !== 'en_vigueur') {
                    continue;
                }

                // What attestation_manager::attest() refuses on this site, an archive cannot bring
                // in: a candidate attesting their own claim, or a claim carrying two
                // attestations in force.
                if ($attestation->attestant === $personne) {
                    throw new \moodle_exception('errorarchiveattestown', 'tool_vae', '', $preuve->id);
                }
                if (++$inforce > 1) {
                    throw new \moodle_exception('errorarchiveattestmany', 'tool_vae', '', $preuve->id);
                }

                if (
                    isset($attestationids[$attestation->id])
                    || $DB->record_exists('tool_vae_attest', ['uuid' => $attestation->id])
                ) {
                    throw new \moodle_exception('errorarchiveexists', 'tool_vae', '', $attestation->id);
                }
                $attestationids[$attestation->id] = true;
            }

            $local = $this->local_competency($preuve->fiche->code, $preuve->fiche->rang);
            $competencyid = (int) $local->id;

            // One ULID, one competency, both ways round. An archive naming two competencies by
            // the same identifier, or one competency by two, cannot be written without
            // choosing which of its statements to believe.
            $known = array_search($competencyid, $competencies, true);
            if (
                (isset($competencies[$preuve->competence]) && $competencies[$preuve->competence] !== $competencyid)
                || ($known !== false && $known !== $preuve->competence)
            ) {
                throw new \moodle_exception('errorarchivecompetencymismatch', 'tool_vae', '', $preuve->competence);
            }

            // What each competency is called here is kept for the refusal below: its code and rank as the archive writes them
            // (the site's own, since that is how it was found) and the name this site gives it.
            $named[$competencyid] ??= (object) [
                'name' => $preuve->fiche->code . '-' . $preuve->fiche->rang,
                'label' => (string) $local->label,
            ];
            $competencies[$preuve->competence] = $competencyid;

            $claims[] = (object) ['preuve' => $preuve, 'competencyid' => $competencyid];
        }

        // WHAT write() WOULD REFUSE, SAID HERE, ONCE FOR EVERY COMPETENCY. write() gives each competency the identifier the
        // archive knows it by, and adopt() refuses when this site already holds another one for it (a first export, backup or
        // copy mints one) or when the identifier is given to something else. Refused there, it came last, inside the
        // transaction, and said `this account is already known` of a competency. Asked after the last claim, so that the one
        // refusal names every competency in conflict and not the first; and before anything is written. Asked again by write(),
        // inside its transaction, with the same words: another request (an export of a dossier, the copy of the night) may
        // number a competency between the two, and adopt() would then say the same of the account.
        self::require_adoptable_competencies($competencies, $named);

        $this->check_filiations($claims);

        return (object) [
            'archive' => $manifest->archive,
            'foreign' => $issued === false,
            'personne' => $personne,
            'competencies' => $competencies,
            'named' => $named,
            'claims' => $claims,
            'rubriques' => $this->rubriques($files),
            'validation' => $this->validation($files),
            'reflexion' => $this->reflexion($files),
            'scope' => $this->scope($files),
            'pieces' => $this->pieces($files, $claims),
        ];
    }

    /**
     * Refuse, by name and before anything is written, the competencies the archive cannot give its identifier to.
     *
     * The decision is identity_map::adoption_conflicts(), the one adopt() takes. What is not decided here is what to do about it:
     * a competency this site already knows under another identifier keeps it - an identifier is never reassigned - and the
     * archive of another site, which cannot know it, is refused all the same. Nothing is adopted automatically (decided with the
     * project owner on 27 October 2026): that a code and a rank mean the same competency on two sites is a judgement, which a
     * person validates, later.
     *
     * ONE REFUSAL FOR ALL. The competencies this site knows under another identifier are collected and refused together, by
     * describe_held(). An identifier this site has given to something else is refused by itself, as it was, and before them.
     *
     * ASKED TWICE: by check(), before anything is written, and again by write() as the first thing in its transaction, from the
     * same plan, since another request may number a competency between the two. The words are the same.
     *
     * @param int[] $competencies The competency of this site each identifier of the archive names, keyed by that identifier.
     * @param \stdClass[] $named Keyed by the id of the competency of this site: name, the competency as the archive writes it
     *        (`code-rank`), and label, the name this site gives it.
     * @throws \moodle_exception errorarchivecompetencyheld, errorarchiveidentitytaken
     */
    private static function require_adoptable_competencies(array $competencies, array $named): void {
        $wanted = array_flip($competencies);

        $held = [];
        foreach (identity_map::adoption_conflicts(identity_map::TYPE_COMPETENCY, $wanted) as $competencyid => $conflict) {
            if ($conflict !== 'errorarchiveidentityheld') {
                throw new \moodle_exception($conflict, 'tool_vae', '', $wanted[$competencyid]);
            }
            $held[] = $named[$competencyid];
        }

        if ($held) {
            throw new \moodle_exception('errorarchivecompetencyheld', 'tool_vae', '', self::describe_held($held));
        }
    }

    /**
     * What the refusal of an archive that numbers competencies differently carries: how many, and which.
     *
     * The list is in the order of the codes (BC01 before BC10), HELD_LISTED of them at most and then how many more, each with
     * the name this site gives it so that the administrator can tell whether it is the same competency. It is HTML, built here:
     * the refusal is shown as HTML, and every text in it is escaped - a record code and a label are what an administrator typed
     * into the framework, or what an import wrote from a file.
     *
     * @param \stdClass[] $held Each with name (`code-rank`) and label (the name this site gives it).
     * @return \stdClass count, how many in all; list, the HTML. Last, so that nothing a label holds is read for a placeholder.
     */
    private static function describe_held(array $held): \stdClass {
        usort($held, fn($first, $second) => strnatcmp($first->name, $second->name));

        $items = [];
        foreach (array_slice($held, 0, self::HELD_LISTED) as $competency) {
            $label = self::tidy_label($competency->label);
            if ($label === '') {
                $items[] = s($competency->name);
                continue;
            }
            $items[] = get_string('errorarchivecompetencyheldone', 'tool_vae', (object) [
                'name' => s($competency->name),
                'label' => s($label),
            ]);
        }
        if (count($held) > self::HELD_LISTED) {
            $items[] = get_string('errorarchivecompetencyheldmore', 'tool_vae', count($held) - self::HELD_LISTED);
        }

        return (object) ['count' => count($held), 'list' => \html_writer::alist($items)];
    }

    /**
     * A label on one line and no longer than HELD_LABEL_LENGTH characters, cut with three dots.
     *
     * @param string $label As this site holds it.
     * @return string Not escaped.
     */
    private static function tidy_label(string $label): string {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        if (\core_text::strlen($label) > self::HELD_LABEL_LENGTH) {
            $label = \core_text::substr($label, 0, self::HELD_LABEL_LENGTH - 3) . '...';
        }

        return $label;
    }

    /**
     * The filiations of an archive: which claim was carried over from which (T-30, lot 3).
     *
     * What one claim cannot say alone. The claim named must be in the same archive, on another record, carried
     * over at most once, and a chain of carries must not come back on itself: a carry is a copy made after the
     * claim it copies, so a loop is a file put together by hand.
     *
     * @param \stdClass[] $claims Each with `preuve` and `competencyid`.
     * @throws \moodle_exception errorarchivefiliation
     */
    private function check_filiations(array $claims): void {
        $byid = [];
        foreach ($claims as $claim) {
            $byid[$claim->preuve->id] = $claim->preuve;
        }

        $sources = [];
        foreach ($byid as $preuve) {
            if (!isset($preuve->reportee_depuis)) {
                continue;
            }
            $from = $preuve->reportee_depuis;
            if (!isset($byid[$from]) || $byid[$from]->fiche->code === $preuve->fiche->code || isset($sources[$from])) {
                throw new \moodle_exception('errorarchivefiliation', 'tool_vae', '', $preuve->id);
            }
            $sources[$from] = true;
        }

        foreach ($byid as $preuve) {
            $seen = [];
            for ($step = $preuve; isset($step->reportee_depuis); $step = $byid[$step->reportee_depuis]) {
                if (isset($seen[$step->id])) {
                    throw new \moodle_exception('errorarchivefiliation', 'tool_vae', '', $preuve->id);
                }
                $seen[$step->id] = true;
            }
        }
    }

    /**
     * The text sections of the dossier de faisabilité an archive carries, checked whole.
     *
     * The file is optional: an archive without it gives nothing. When it is there, it goes by the
     * schema, and then by the catalogue - the schema cannot know which codes are text sections. A
     * code the catalogue holds as fields or as read from the account is refused, and so is an
     * address it does not hold, whatever the archive says: the archive of this plugin never
     * carries identity (MC-13), and a file that does is not one this plugin wrote. One address
     * twice is refused for the same reason as a duplicated claim: no way to choose between them.
     *
     * @param string[] $files path => content, already checked against the manifest.
     * @return \stdClass[] code, slot, content, savedat.
     */
    private function rubriques(array $files): array {
        if (!isset($files[exporter::FAISABILITE_PATH])) {
            return [];
        }

        try {
            $data = json_decode($files[exporter::FAISABILITE_PATH], false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \moodle_exception('errorarchivefaisabilite', 'tool_vae', '', 'JSON');
        }
        $errors = $this->validator->validate($data, 'faisabilite.schema.json');
        if ($errors) {
            throw new \moodle_exception('errorarchivefaisabilite', 'tool_vae', '', s(reset($errors)));
        }

        $rubriques = [];
        $seen = [];
        foreach ($data->rubriques as $index => $rubrique) {
            $code = $rubrique->code;
            $slot = $rubrique->emplacement;
            $address = $code . '/' . $slot;
            if (
                faisabilite_catalog::kind($code) !== faisabilite_catalog::KIND_TEXT
                || !faisabilite_catalog::valid_address($code, $slot)
                || isset($seen[$address])
            ) {
                throw new \moodle_exception(
                    'errorarchivefaisabilite',
                    'tool_vae',
                    '',
                    s("/rubriques/$index $address")
                );
            }
            $seen[$address] = true;

            $rubriques[] = (object) [
                'code' => $code,
                'slot' => $slot,
                'content' => $rubrique->texte,
                'savedat' => self::timestamp($rubrique->enregistree_le),
            ];
        }

        return $rubriques;
    }

    /**
     * The sections of the preparation of the validation dossier an archive carries, checked whole (T-11, lot 2).
     *
     * The file is optional. When it is there it goes by the schema, then by what the schema cannot say: each section
     * names a record this site holds, the code reads as the bloc and the part it claims, and one section is not there
     * twice. A section of a bloc that this site's record does not hold is NOT refused: what a candidate wrote comes
     * back, shown as the section of a withdrawn bloc. A record this site does not hold is: there is no place for the text.
     *
     * @param string[] $files path => content, already checked against the manifest.
     * @return \stdClass[] fiche, code, content, savedat.
     * @throws \moodle_exception errorarchivevalidation
     */
    private function validation(array $files): array {
        global $DB;

        if (!isset($files[exporter::VALIDATION_PATH])) {
            return [];
        }

        try {
            $data = json_decode($files[exporter::VALIDATION_PATH], false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \moodle_exception('errorarchivevalidation', 'tool_vae', '', 'JSON');
        }
        $errors = $this->validator->validate($data, 'validation.schema.json');
        if ($errors) {
            throw new \moodle_exception('errorarchivevalidation', 'tool_vae', '', s(reset($errors)));
        }

        $out = [];
        $seen = [];
        foreach ($data->rubriques as $index => $rubrique) {
            $where = "/rubriques/$index " . $rubrique->fiche . ' ' . $rubrique->code;
            $parsed = validation_catalog::parse($rubrique->code);
            $source = $DB->get_record('tool_vae_source', ['fichecode' => $rubrique->fiche]);
            $blocok = $parsed !== null && ($parsed[0] === ''
                ? $rubrique->bloc === null
                : $rubrique->bloc !== null && strtolower($rubrique->bloc) === $parsed[0]);
            $consistent = $blocok && $parsed[1] === $rubrique->partie;
            $key = $rubrique->fiche . '/' . $rubrique->code;
            if (!$consistent || !$source || $source->fichecode !== $rubrique->fiche || isset($seen[$key])) {
                throw new \moodle_exception('errorarchivevalidation', 'tool_vae', '', $where);
            }
            $seen[$key] = true;

            $out[] = (object) [
                'fiche' => $rubrique->fiche,
                'code' => $rubrique->code,
                'content' => $rubrique->texte,
                'savedat' => self::timestamp($rubrique->enregistree_le),
            ];
        }

        return $out;
    }

    /**
     * The entries of the reflective journal an archive carries, checked whole (T-14, lot 2).
     *
     * The file is optional. When it is there it goes by the schema, then by what the schema cannot say: one number is not
     * there twice. An entry is brought back to its number; it never overwrites one that holds a saved version.
     *
     * @param string[] $files path => content, already checked against the manifest.
     * @return \stdClass[] slot, content, savedat, openedat.
     * @throws \moodle_exception errorarchivereflexion
     */
    private function reflexion(array $files): array {
        if (!isset($files[exporter::REFLEXION_PATH])) {
            return [];
        }

        try {
            $data = json_decode($files[exporter::REFLEXION_PATH], false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \moodle_exception('errorarchivereflexion', 'tool_vae', '', 'JSON');
        }
        $errors = $this->validator->validate($data, 'reflexion.schema.json');
        if ($errors) {
            throw new \moodle_exception('errorarchivereflexion', 'tool_vae', '', s(reset($errors)));
        }

        $out = [];
        $seen = [];
        foreach ($data->entrees as $index => $entry) {
            if (isset($seen[$entry->numero])) {
                throw new \moodle_exception('errorarchivereflexion', 'tool_vae', '', "/entrees/$index " . $entry->numero);
            }
            $seen[$entry->numero] = true;

            $out[] = (object) [
                'slot' => (int) $entry->numero,
                'content' => $entry->texte,
                'savedat' => self::timestamp($entry->enregistree_le),
                'openedat' => self::timestamp($entry->creee_le),
            ];
        }

        return $out;
    }

    /**
     * The competencies an archive says were outside the candidate's activity, checked whole (T-42, lot 3).
     *
     * The file is optional. When it is there it goes by the schema, then by what the schema cannot say: a competency is not
     * there twice, and a note is a line the plugin would have accepted from the candidate. A mark is brought back to the
     * candidate it is imported for; it never overwrites a mark that is there, and a competency this site does not hold is left.
     *
     * @param string[] $files path => content, already checked against the manifest.
     * @return \stdClass[] fichecode, ficherang, note (or null), saidat.
     * @throws \moodle_exception errorarchivescope
     */
    private function scope(array $files): array {
        if (!isset($files[scope_manager::PATH])) {
            return [];
        }

        try {
            $data = json_decode($files[scope_manager::PATH], false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \moodle_exception('errorarchivescope', 'tool_vae', '', 'JSON');
        }
        $errors = $this->validator->validate($data, 'hors_activite.schema.json');
        if ($errors) {
            throw new \moodle_exception('errorarchivescope', 'tool_vae', '', s(reset($errors)));
        }
        // The size the plugin gives to a list of marks: one per competency of a few records, never thousands.
        if (count($data->competences) > 2000) {
            throw new \moodle_exception('errorarchivescope', 'tool_vae', '', '/competences');
        }

        $out = [];
        $seen = [];
        foreach ($data->competences as $index => $entry) {
            $key = $entry->fiche . '-' . $entry->rang;
            $note = $entry->note ?? null;
            if (
                isset($seen[$key])
                || ($note !== null && (\core_text::strlen($note) > scope_manager::NOTE_MAXLENGTH
                    || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $note)))
            ) {
                // Escaped, like the other messages of this class: the record code is the archive's own, and shown as HTML.
                throw new \moodle_exception('errorarchivescope', 'tool_vae', '', s("/competences/$index " . $key));
            }
            $seen[$key] = true;

            $out[] = (object) [
                'fichecode' => $entry->fiche,
                'ficherang' => $entry->rang,
                'note' => $note,
                'saidat' => self::timestamp($entry->dit_le),
            ];
        }

        return $out;
    }

    /**
     * The supporting pieces an archive carries, checked whole (T-13, lot 3).
     *
     * The file is optional. When it is there it goes by the schema, then by what the schema cannot say: an identifier this plugin
     * can store and nobody has, a piece that names a declaration of the same archive, the bounds the code holds for a person (five
     * pieces at once and twenty rows per declaration), dates that agree with the signature said to have covered the piece, a file
     * that is a file and a link that is a link, an address and a title the plugin would have accepted from the candidate. Each
     * file is then read as it would be at the door - its digest, its size, its type read on the content and the type the archive
     * declares, the antivirus - unless attaching is closed here, when nothing will be written and the door is not asked.
     *
     * AN ARCHIVE THAT CARRIES WHAT WOULD BE LOST IS REFUSED, NOT TRIMMED. A declaration cannot be imported twice, so pieces dropped
     * silently now would never come back: when attaching is closed here and the archive has pieces to restore (a file with its
     * bytes, a link with its address), it is refused, and the message says to open attaching and import again.
     *
     * @param string[] $files path => content, already checked against the manifest.
     * @param \stdClass[] $claims Each with `preuve`.
     * @return \stdClass[] entry (the piece as the archive says it), bytes (the file or null), ext.
     * @throws \moodle_exception errorarchivepieces, errorarchivepiecesclosed
     */
    private function pieces(array $files, array $claims): array {
        global $DB;

        $folder = array_filter(array_keys($files), fn($path) => strpos($path, piece_overview::FOLDER) === 0);
        if (!isset($files[piece_overview::PATH])) {
            if ($folder) {
                throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', s(reset($folder)));
            }

            return [];
        }

        try {
            $data = json_decode($files[piece_overview::PATH], false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', 'JSON');
        }
        $errors = $this->validator->validate($data, 'pieces.schema.json');
        if ($errors) {
            // Escaped: the message names a property of the archive, and is shown as HTML.
            throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', s(reset($errors)));
        }

        $open = piece_manager::is_open();

        // The attestations in force each declaration carries, with the date each was given.
        $inforce = [];
        foreach ($claims as $claim) {
            $inforce[$claim->preuve->id] = [];
            foreach ($claim->preuve->attestations as $attestation) {
                if ($attestation->statut === 'en_vigueur') {
                    $inforce[$claim->preuve->id][$attestation->id] = self::timestamp($attestation->attestee_le);
                }
            }
        }

        $plan = [];
        $seen = [];
        $used = [];
        $rows = [];
        $active = [];
        $restorable = false;
        foreach ($data->pieces as $index => $piece) {
            $where = "/pieces/$index";
            if (
                !identity_map::is_valid_ulid($piece->id)
                || isset($seen[$piece->id])
                || $DB->record_exists('tool_vae_piece', ['uuid' => $piece->id])
            ) {
                throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', s("$where " . $piece->id));
            }
            $seen[$piece->id] = true;
            if (!isset($inforce[$piece->preuve])) {
                throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', "$where /preuve");
            }

            $removed = isset($piece->retiree_le);
            $isfile = $piece->genre === 'fichier';

            // The bounds the code holds for a person, not those a site sets.
            $rows[$piece->preuve] = ($rows[$piece->preuve] ?? 0) + 1;
            $active[$piece->preuve] = ($active[$piece->preuve] ?? 0) + ($removed ? 0 : 1);
            if ($rows[$piece->preuve] > piece_manager::MAX_ROWS || $active[$piece->preuve] > piece_manager::MAX_ACTIVE) {
                throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', "$where /preuve");
            }

            // Dates that agree with each other and with the signature that is said to have covered the piece.
            $added = self::timestamp($piece->ajoutee_le);
            $gone = $removed ? self::timestamp($piece->retiree_le) : null;
            if ($removed && $gone < $added) {
                throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', "$where /retiree_le");
            }
            if (isset($piece->couverte_par)) {
                if (!array_key_exists($piece->couverte_par, $inforce[$piece->preuve])) {
                    throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', "$where /couverte_par");
                }
                $signed = $inforce[$piece->preuve][$piece->couverte_par];
                if ($added > $signed || ($removed && $gone < $signed)) {
                    throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', "$where /couverte_par");
                }
            }

            // What the genre allows, and what a removal leaves.
            $hasfileprops = isset($piece->type) || isset($piece->taille) || isset($piece->sha256);
            if (
                ($isfile && (!isset($piece->type, $piece->taille, $piece->sha256) || isset($piece->adresse)))
                || (!$isfile && ($hasfileprops || isset($piece->fichier)))
                || ($removed && (isset($piece->fichier) || isset($piece->adresse) || isset($piece->intitule)))
            ) {
                throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', "$where /genre");
            }

            // An address and a title the plugin would have accepted from the candidate, and no other.
            try {
                if (isset($piece->adresse) && piece_manager::validate_url($piece->adresse)[0] !== $piece->adresse) {
                    throw new \moodle_exception('errorpieceurl', 'tool_vae');
                }
                if (isset($piece->intitule) && piece_manager::clean_title($piece->intitule) !== $piece->intitule) {
                    throw new \moodle_exception('errorpiecetitle', 'tool_vae', '', piece_manager::TITLE_MAXLENGTH);
                }
            } catch (\moodle_exception $e) {
                throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', "$where " . s($e->getMessage()));
            }

            $bytes = null;
            $ext = $isfile ? (string) array_search($piece->type, piece_manager::TYPES, true) : null;
            if (isset($piece->fichier)) {
                $path = piece_overview::FOLDER . $piece->id . '.' . $ext;
                if ($piece->fichier !== $path || !array_key_exists($path, $files) || isset($used[$path])) {
                    throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', "$where /fichier");
                }
                $used[$path] = true;
                $bytes = $files[$path];
                if (
                    strlen($bytes) !== $piece->taille
                    || $piece->taille > piece_manager::CANDIDATE_CEILING
                    || !hash_equals($piece->sha256, hash('sha256', $bytes))
                ) {
                    throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', "$where /sha256");
                }

                // The door a person's file goes through: the type read on the content, the same as the archive says, and the
                // antivirus. Not asked when attaching is closed here: nothing will be written, and the archive is refused below
                // if it had pieces to bring.
                if ($open) {
                    $temp = make_request_directory() . '/piece';
                    file_put_contents($temp, $bytes);
                    try {
                        [$detected] = piece_manager::detect_type($temp);
                        if ($detected !== $ext) {
                            throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', "$where /type");
                        }
                        piece_manager::scan($temp, 'piece.' . $ext);
                    } catch (\moodle_exception $e) {
                        // Refused whole, by the name of the piece, whatever the door says (type, macro, virus, no antivirus).
                        if ($e->errorcode === 'errorarchivepieces') {
                            throw $e;
                        }
                        throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', "$where " . s($e->getMessage()));
                    } finally {
                        @unlink($temp);
                    }
                }
            }
            $restorable = $restorable || $bytes !== null || isset($piece->adresse);

            $plan[] = (object) ['entry' => $piece, 'bytes' => $bytes, 'ext' => $ext];
        }

        foreach ($folder as $path) {
            if (!isset($used[$path])) {
                throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', s($path));
            }
        }

        // Nothing is dropped in silence: a declaration cannot be imported twice, so what is dropped now never comes back.
        if (!$open && $restorable) {
            throw new \moodle_exception('errorarchivepiecesclosed', 'tool_vae');
        }

        return $plan;
    }

    /**
     * The errors of one claim, empty when it may be imported.
     *
     * The schema, then what the schema cannot say: the end of the period after its start, and
     * identifiers this plugin can store.
     *
     * @param mixed $preuve The decoded claim.
     * @return string[]
     */
    public function preuve_errors($preuve): array {
        $errors = $this->validator->validate($preuve);
        if ($errors) {
            return $errors;
        }

        if (self::timestamp($preuve->periode->fin) < self::timestamp($preuve->periode->debut)) {
            $errors[] = '/periode ends before it starts';
        }

        // The filiation (T-30, lot 3) is both fields or neither, and a claim is not carried over from itself.
        if (isset($preuve->reportee_depuis) !== isset($preuve->reportee_le)) {
            $errors[] = '/reportee_depuis and /reportee_le go together';
        } else if (isset($preuve->reportee_depuis) && $preuve->reportee_depuis === $preuve->id) {
            $errors[] = '/reportee_depuis names the claim itself';
        }

        $identifiers = ['/id' => $preuve->id, '/personne' => $preuve->personne, '/competence' => $preuve->competence];
        if (isset($preuve->reportee_depuis)) {
            $identifiers['/reportee_depuis'] = $preuve->reportee_depuis;
        }
        if (isset($preuve->structure)) {
            $identifiers['/structure'] = $preuve->structure;
        }
        foreach ($preuve->attestations as $index => $attestation) {
            if ($attestation->statut === 'en_vigueur') {
                $identifiers["/attestations/$index/id"] = $attestation->id;
                $identifiers["/attestations/$index/attestant"] = $attestation->attestant;
            }
        }
        foreach ($identifiers as $pointer => $identifier) {
            if (!identity_map::is_valid_ulid($identifier)) {
                $errors[] = "$pointer is a UUID v4, which this plugin does not store";
            }
        }

        return $errors;
    }

    /**
     * Write a checked archive, all at once or not at all.
     *
     * @param int $candidateid
     * @param \stdClass $plan From check().
     * @return \stdClass
     */
    private function write(int $candidateid, \stdClass $plan): \stdClass {
        global $DB, $USER;

        $now = time();
        $declarations = 0;
        $attestations = 0;
        $restored = 0;
        $kept = 0;
        $validationrestored = 0;
        $validationkept = 0;
        $reflexionrestored = 0;
        $reflexionkept = 0;
        $scoperestored = 0;
        $scopeleft = 0;
        $piecesrestored = 0;
        $piecesleft = 0;
        $written = [];
        $attestids = [];
        $fs = get_file_storage();
        $hashes = [];

        $transaction = $DB->start_delegated_transaction();

        try {
            // THE REFUSAL OF check(), ASKED AGAIN WHERE THE WRITE BEGINS. The checks and this transaction are two moments: an
            // export of a dossier or the copy of the night may have numbered a competency in between, and adopt() below would
            // refuse it with the words about an account. The same guard, first of the transaction (it is the first read of the
            // identifiers, so what it reads is what the adoptions below decide on), and the same words, naming the competencies.
            self::require_adoptable_competencies($plan->competencies, $plan->named);

            identity_map::adopt(identity_map::TYPE_USER, $candidateid, $plan->personne);
            foreach ($plan->competencies as $uuid => $competencyid) {
                identity_map::adopt(identity_map::TYPE_COMPETENCY, $competencyid, $uuid);
            }

            foreach ($plan->claims as $claim) {
                $preuve = $claim->preuve;
                $periodstart = self::timestamp($preuve->periode->debut);
                $periodend = self::timestamp($preuve->periode->fin);

                // The label is the one this site would write, in this site's time zone: it is a
                // core record of this site. What the panel reads is the claim, carried intact.
                $corelabel = evidence_manager::label_for($preuve->fiche->code, $preuve->fiche->rang, $periodstart, $periodend);

                $userevidenceid = $this->gateway->create_evidence($candidateid, $corelabel);
                $this->gateway->link_to_competency($userevidenceid, $claim->competencyid);

                $evidenceid = $DB->insert_record('tool_vae_evidence', (object) [
                    'uuid' => $preuve->id,
                    'userevidenceid' => $userevidenceid,
                    'candidateid' => $candidateid,
                    'periodstart' => $periodstart,
                    'periodend' => $periodend,
                    'settingtype' => $preuve->cadre->type,
                    'settingdetail' => $preuve->cadre->precision ?? null,
                    'situation' => $preuve->situation->texte,
                    'situationformat' => (int) ($preuve->situation->format === 'markdown' ? FORMAT_MARKDOWN : FORMAT_PLAIN),
                    'fichecode' => $preuve->fiche->code,
                    'ficherang' => $preuve->fiche->rang,
                    // The version of the record AT THE TIME OF THE CLAIM, as the archive states
                    // it - not the version this site imported, which may be later.
                    'ficheupdated' => self::timestamp($preuve->fiche->mise_a_jour),
                    'corelabel' => $corelabel,
                    'competencyid' => $claim->competencyid,
                    'timecreated' => self::timestamp($preuve->declaree_le),
                    'timemodified' => $now,
                    'usermodified' => (int) $USER->id,
                ]);
                $declarations++;
                $written[$preuve->id] = (object) ['id' => $evidenceid, 'competencyid' => $claim->competencyid];

                foreach ($preuve->attestations as $attestation) {
                    $attestid = $DB->insert_record('tool_vae_attest', self::attestation_row($attestation, $evidenceid, $now));
                    if ($attestation->statut === 'en_vigueur') {
                        $attestids[$attestation->id] = (int) $attestid;
                    }
                    $attestations++;
                }
            }

            // The filiations (T-30, lot 3), once every claim is written. Who decided the correspondence and the
            // note never travel in an archive: the trace comes back without them, as after an erasure.
            foreach ($plan->claims as $claim) {
                $preuve = $claim->preuve;
                if (!isset($preuve->reportee_depuis)) {
                    continue;
                }
                $DB->insert_record('tool_vae_carry', (object) [
                    'oldevidenceid' => (int) $written[$preuve->reportee_depuis]->id,
                    'newevidenceid' => (int) $written[$preuve->id]->id,
                    'fromcompetencyid' => (int) $written[$preuve->reportee_depuis]->competencyid,
                    'tocompetencyid' => (int) $written[$preuve->id]->competencyid,
                    'decidedby' => 0,
                    'decidedat' => 0,
                    'note' => '',
                    'carriedat' => self::timestamp($preuve->reportee_le),
                ]);
            }

            // The sections of the dossier de faisabilité: a section already written here stays as
            // it is, the others come back as their version 1 (T-44, T-10 lot 4b).
            $drafts = new draft_manager();
            $note = get_string('faisabimportnote', 'tool_vae', $plan->archive);
            foreach ($plan->rubriques as $rubrique) {
                $version = $drafts->restore_from_archive(
                    $candidateid,
                    $rubrique->code,
                    $rubrique->slot,
                    $rubrique->content,
                    $rubrique->savedat,
                    $note
                );
                if ($version === null) {
                    $kept++;
                } else {
                    $restored++;
                }
            }

            // The preparation of the validation dossier: the same rule, a section already written here stays as it is.
            $preparation = get_string('validationimportnote', 'tool_vae', $plan->archive);
            foreach ($plan->validation as $rubrique) {
                $version = $drafts->restore_from_archive(
                    $candidateid,
                    $rubrique->code,
                    0,
                    $rubrique->content,
                    $rubrique->savedat,
                    $preparation,
                    draft_manager::DOCTYPE_VALIDATION,
                    $rubrique->fiche
                );
                if ($version === null) {
                    $validationkept++;
                } else {
                    $validationrestored++;
                }
            }

            // The reflective journal: the same rule, an entry that holds a saved version stays as it is.
            $journalnote = get_string('reflexionimportnote', 'tool_vae', $plan->archive);
            foreach ($plan->reflexion as $entry) {
                $version = $drafts->restore_from_archive(
                    $candidateid,
                    draft_manager::REFLEXION_SECTION,
                    $entry->slot,
                    $entry->content,
                    $entry->savedat,
                    $journalnote,
                    draft_manager::DOCTYPE_REFLEXION,
                    '',
                    $entry->openedat
                );
                if ($version === null) {
                    $reflexionkept++;
                } else {
                    $reflexionrestored++;
                }
            }

            // What the candidate said was outside their activity (T-42): the same rule, a mark that is there stays as it is.
            // The tree of a record is read once, not once per mark.
            $held = [];
            foreach ($plan->scope as $mark) {
                $held[$mark->fichecode] ??= scope_manager::ranks_of($mark->fichecode);
                if (
                    scope_manager::restore(
                        $candidateid,
                        $mark->fichecode,
                        $mark->ficherang,
                        $mark->note,
                        $mark->saidat,
                        $held[$mark->fichecode]
                    )
                ) {
                    $scoperestored++;
                } else {
                    $scopeleft++;
                }
            }

            // The supporting pieces (T-13, lot 3). Restored only when attaching is open here, and only what has something to
            // restore: a file with its bytes, a link with its address, a removed piece (its trace). A piece the archive lists
            // without its bytes or its address - the export of a third party - is counted and left. Never over an existing
            // piece: the import already refuses a declaration that exists, so a piece comes with a new one.
            $open = piece_manager::is_open();
            $context = \context_user::instance($candidateid);
            // What the candidate keeps, with what comes: the ceiling an archive tolerates, held for a person and for an import.
            $incoming = 0;
            foreach ($plan->pieces as $item) {
                $incoming += $item->bytes === null ? 0 : strlen($item->bytes);
            }
            if ($open && piece_manager::used_bytes($candidateid) + $incoming > piece_manager::CANDIDATE_CEILING) {
                throw new \moodle_exception('errorarchivepieces', 'tool_vae', '', display_size(piece_manager::CANDIDATE_CEILING));
            }
            foreach ($plan->pieces as $item) {
                $piece = $item->entry;
                $removed = isset($piece->retiree_le);
                $content = $item->bytes !== null || isset($piece->adresse) || $removed;
                if (!$open || !$content) {
                    $piecesleft++;
                    continue;
                }

                $isfile = $piece->genre === 'fichier';
                $pieceid = $DB->insert_record('tool_vae_piece', (object) [
                    'uuid' => $piece->id,
                    'evidenceid' => (int) $written[$piece->preuve]->id,
                    'candidateid' => $candidateid,
                    'kind' => $isfile ? piece_manager::KIND_FILE : piece_manager::KIND_LINK,
                    'nature' => $piece->nature,
                    'title' => $piece->intitule ?? '',
                    'ext' => $item->ext,
                    'mimetype' => $isfile ? $piece->type : null,
                    'filesize' => $isfile ? $piece->taille : null,
                    'sha256' => $isfile ? $piece->sha256 : null,
                    'url' => $piece->adresse ?? null,
                    'timecreated' => self::timestamp($piece->ajoutee_le),
                    // The candidate's own piece, as it was: the importer is not the person who attached or removed it.
                    'usercreated' => $candidateid,
                    'timeremoved' => $removed ? self::timestamp($piece->retiree_le) : null,
                    'removedby' => $removed ? $candidateid : null,
                ]);
                if ($item->bytes !== null) {
                    $stored = $fs->create_file_from_string([
                        'contextid' => $context->id,
                        'component' => 'tool_vae',
                        'filearea' => piece_manager::FILEAREA,
                        'itemid' => (int) $pieceid,
                        'filepath' => '/',
                        'filename' => $piece->id . '.' . $item->ext,
                        'userid' => $candidateid,
                    ], $item->bytes);
                    $hashes[] = $stored->get_contenthash();
                }
                // What the signature in force covered: it is what lets the dossier say that a piece came after it.
                if (isset($piece->couverte_par)) {
                    $DB->insert_record('tool_vae_attest_piece', (object) [
                        'attestid' => $attestids[$piece->couverte_par],
                        'pieceid' => (int) $pieceid,
                        'sha256' => $isfile ? $piece->sha256 : null,
                    ]);
                }
                $piecesrestored++;
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The rollback() call rethrows; the line after it is never reached. The rows go with it, the contents written to the
            // disk do not: a content nothing refers to any more is taken out (the file system checks that nothing does).
            try {
                $transaction->rollback($e);
            } catch (\Throwable $rethrown) {
                foreach ($hashes as $hash) {
                    try {
                        $fs->get_file_system()->remove_file($hash);
                    } catch (\Throwable $ignored) {
                        debugging('tool_vae: a content could not be removed after a failed import.', DEBUG_DEVELOPER);
                    }
                }
                throw $rethrown;
            }
            throw $e;
        }

        return (object) [
            'pieces' => $piecesrestored,
            'piecesleft' => $piecesleft,
            'reflexion' => $reflexionrestored,
            'reflexiongardees' => $reflexionkept,
            'horsactivite' => $scoperestored,
            'horsactivitelaissees' => $scopeleft,
            'validation' => $validationrestored,
            'validationgardees' => $validationkept,
            'declarations' => $declarations,
            'attestations' => $attestations,
            'rubriques' => $restored,
            'rubriquesgardees' => $kept,
        ];
    }

    /**
     * The row of tool_vae_attest for one attestation of the archive.
     *
     * In force: the signature as it was given, its ULID kept, the signatory known by the ULID
     * the archive gives them and tied to no account here. Withdrawn: a date and a reason, as on
     * the platform it comes from, and a ULID minted now - the archive carries none, on purpose,
     * and the one minted here holds the time of the import, not that of the signature.
     *
     * @param \stdClass $attestation
     * @param int $evidenceid
     * @param int $now
     * @return \stdClass
     */
    private static function attestation_row(\stdClass $attestation, int $evidenceid, int $now): \stdClass {
        if ($attestation->statut === 'en_vigueur') {
            $attestedat = self::timestamp($attestation->attestee_le);

            return (object) [
                'uuid' => $attestation->id,
                'evidenceid' => $evidenceid,
                'attestantid' => null,
                'attestantuuid' => $attestation->attestant,
                'attestantrole' => $attestation->role,
                'statement' => $attestation->declaration,
                'attestedat' => $attestedat,
                'withdrawnat' => null,
                'withdrawnreason' => null,
                'importedat' => $now,
                'timecreated' => $attestedat,
                'timemodified' => $now,
                'usermodified' => 0,
            ];
        }

        $withdrawnat = self::timestamp($attestation->retiree_le);

        return (object) [
            'uuid' => identity_map::generate_ulid(),
            'evidenceid' => $evidenceid,
            'attestantid' => null,
            'attestantuuid' => null,
            'attestantrole' => null,
            'statement' => null,
            'attestedat' => null,
            'withdrawnat' => $withdrawnat,
            'withdrawnreason' => $attestation->motif,
            'importedat' => $now,
            'timecreated' => $withdrawnat,
            'timemodified' => $now,
            'usermodified' => 0,
        ];
    }

    /**
     * The manifest, checked against what this version of the plugin writes.
     *
     * @param string[] $files
     * @return \stdClass
     */
    private function manifest(array $files): \stdClass {
        if (!isset($files[self::MANIFEST])) {
            throw new \moodle_exception('errorarchivemanifest', 'tool_vae', '', self::MANIFEST);
        }

        $manifest = json_decode($files[self::MANIFEST]);
        if (!$manifest instanceof \stdClass) {
            throw new \moodle_exception('errorarchivemanifest', 'tool_vae', '', self::MANIFEST);
        }

        $expected = [
            'format' => exporter::FORMAT,
            'version_format' => exporter::FORMAT_VERSION,
            'schema_preuve' => exporter::SCHEMA_VERSION,
        ];
        foreach ($expected as $field => $value) {
            if (($manifest->$field ?? null) !== $value) {
                throw new \moodle_exception('errorarchivemanifest', 'tool_vae', '', $field);
            }
        }

        if (!is_string($manifest->archive ?? null) || !identity_map::is_valid_ulid($manifest->archive)) {
            throw new \moodle_exception('errorarchivemanifest', 'tool_vae', '', 'archive');
        }
        $personne = $manifest->candidat->identifiant ?? null;
        if (!is_string($personne) || !identity_map::is_valid_ulid($personne)) {
            throw new \moodle_exception('errorarchivemanifest', 'tool_vae', '', 'candidat');
        }
        foreach (['preuves', 'fichiers'] as $field) {
            if (!is_array($manifest->$field ?? null)) {
                throw new \moodle_exception('errorarchivemanifest', 'tool_vae', '', $field);
            }
        }

        return $manifest;
    }

    /**
     * The files the manifest lists, each checked against its digest.
     *
     * The archive holds exactly these and the manifest: a file it lists and lacks, or holds and
     * does not list, refuses it as surely as a wrong digest.
     *
     * @param \stdClass $manifest
     * @param string[] $files
     * @return true[] Keyed by path.
     */
    private function listed_files(\stdClass $manifest, array $files): array {
        $listed = [];
        foreach ($manifest->fichiers as $entry) {
            $path = $entry instanceof \stdClass && is_string($entry->chemin ?? null) ? $entry->chemin : '';
            if (
                $path === self::MANIFEST
                || !preg_match(self::SAFE_PATH, $path)
                || isset($listed[$path])
                || !array_key_exists($path, $files)
            ) {
                throw new \moodle_exception('errorarchivefiles', 'tool_vae', '', s($path));
            }

            $content = $files[$path];
            if (
                !is_int($entry->octets ?? null)
                || strlen($content) !== $entry->octets
                || !is_string($entry->sha256 ?? null)
                || !hash_equals(strtolower($entry->sha256), hash('sha256', $content))
            ) {
                throw new \moodle_exception('errorarchivedigest', 'tool_vae', '', s($path));
            }

            $listed[$path] = true;
        }

        foreach (array_keys($files) as $path) {
            if ($path !== self::MANIFEST && !isset($listed[$path])) {
                throw new \moodle_exception('errorarchivefiles', 'tool_vae', '', s($path));
            }
        }

        return $listed;
    }

    /**
     * One claim of the archive, decoded and checked.
     *
     * @param string $path
     * @param string $content
     * @return \stdClass
     */
    private function preuve(string $path, string $content): \stdClass {
        try {
            $preuve = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \moodle_exception('errorarchiveschema', 'tool_vae', '', (object) [
                'file' => $path,
                'error' => 'not JSON',
            ]);
        }

        $errors = $this->preuve_errors($preuve);
        if ($errors) {
            throw new \moodle_exception('errorarchiveschema', 'tool_vae', '', (object) [
                'file' => $path,
                'error' => s(reset($errors)),
            ]);
        }

        // The file is named after the claim it holds. A claim filed under another claim's name is
        // a sign of an archive put together by hand.
        preg_match(self::CLAIM_PATH, $path, $matches);
        if ($preuve->id !== $matches[1]) {
            throw new \moodle_exception('errorarchivefiles', 'tool_vae', '', s($path));
        }

        return $preuve;
    }

    /**
     * The competency of this site a claim refers to, by record code and rank.
     *
     * The archive's ULID for the competency means nothing here; the code and the rank are
     * public, printed on the record itself, and are what the import of lot 6 matches on.
     *
     * @param string $code
     * @param string $rank
     * @return \stdClass The competency as competency_gateway::list_framework_competencies() gives it: id, idnumber, label.
     */
    private function local_competency(string $code, string $rank): \stdClass {
        global $DB;

        $frameworkid = $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => $code]);
        if ($frameworkid !== false) {
            foreach ($this->gateway->list_framework_competencies((int) $frameworkid) as $competency) {
                if ($competency->idnumber === $code . '-' . $rank) {
                    return $competency;
                }
            }
        }

        throw new \moodle_exception('errorarchivenocompetency', 'tool_vae', '', s($code . '-' . $rank));
    }

    /**
     * A date-time of the schema as a Unix timestamp.
     *
     * @param string $value Already checked by the schema.
     * @return int
     */
    private static function timestamp(string $value): int {
        return (new \DateTimeImmutable($value))->getTimestamp();
    }
}
