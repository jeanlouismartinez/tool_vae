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

use tool_vae\event\deposit_created;

/**
 * The deposit of the dossier de faisabilité: a dated, hashed milestone (T-21, lot 1).
 *
 * WHAT IS DEPOSITED. The latest SAVED version of every section the candidate has written, text and
 * separate fields alike, as one JSON snapshot. Never a draft in progress. The contact details are
 * read from the account and kept nowhere (MC-10): a deposit does not hold them either.
 *
 * A DEPOSIT IS A MILESTONE, NOT A LOCK. The candidate goes on writing after it (decision of
 * 15 October 2026); a new deposit is a new milestone with the next number. The earlier ones stay,
 * with their date and their SHA-256, and are never changed. A deposit identical to the latest one
 * is refused: two milestones that say the same thing would only hide the one that matters.
 *
 * WHO READS. The candidate; the site administrator; whoever holds tool/vae:readdeposit in the
 * candidate's context AND may read the dossier - viewany, or following the candidate (access::can_view()):
 * the direction reads every deposit with both at the site level, the manager who follows a candidate with
 * viewassigned and readdeposit in the candidate's context. Reading a deposit never opens the drafts or the
 * versions (D-10), and the separate fields - identity, status - stay behind tool/vae:identityview on top (MC-11).
 *
 * The plugin records the milestone; it does not judge it, and it is not the filing with the
 * certifier, which stays with the AAP or France VAE (D-7 f).
 *
 * TWO DOCUMENTS (D-15, which amends D-11 (1)). The dossier de faisabilité, and the preparation of the validation dossier
 * (T-11, lot 3): the saved versions of every record together, as one snapshot, read by whoever may read deposits - the
 * deposited version only, never a draft, never the history. It is the third control of regime 2: without it nobody would
 * read that text before it leaves the plugin. Each document has its own numbers. The commitment is the faisabilité's alone,
 * and nobody writes an opinion on the preparation: the manager reads, never evaluates (D-1, D-4).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class deposit_manager {
    /** @var int The longest note, in characters. */
    public const NOTE_MAXLENGTH = 255;

    /** @var int Seconds to wait for the lock of a candidate's deposits. */
    private const LOCK_TIMEOUT = 10;

    /**
     * Whether the current user may deposit this candidate's dossier: the candidate, for their own.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_deposit(int $candidateid): bool {
        return draft_manager::can_write($candidateid);
    }

    /**
     * Whether the current user may read this candidate's deposits.
     *
     * WHY readdeposit ALONE IS NOT ENOUGH: the trap access::follows() guards against, met again. The capability is read in the
     * candidate's user context, and a role carrying it assigned at system level is inherited by every user context on the
     * site: deposit.php?userid=N would then open the deposits of any N, the name of the candidate and - with identityview -
     * the identity. Reading a deposit therefore first requires reading the dossier, by the very rule of can_view() - viewany,
     * or following the candidate - as attesting does (access::can_attest()). Nobody reads what they may not consult.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_read(int $candidateid): bool {
        global $USER;

        if (!isloggedin() || isguestuser()) {
            return false;
        }
        // An id that is no account has no context: it is refused as a dossier one may not read is, so that the answer never
        // tells which ids are accounts (access::can_view() says the same), and deposit.php keeps what it promises.
        $context = \context_user::instance($candidateid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        if ((int) $USER->id === $candidateid) {
            return has_capability('tool/vae:draftown', $context);
        }

        return is_siteadmin() || (access::can_view($candidateid) && has_capability('tool/vae:readdeposit', $context));
    }

    /**
     * Whether the current user may read the separate fields of a deposit (identity, status).
     *
     * The candidate, for their own; anyone else needs tool/vae:identityview on top of the right to
     * read the deposit (MC-11). The site administrator holds it as a matter of course.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_read_fields(int $candidateid): bool {
        global $USER;

        if (!self::can_read($candidateid)) {
            return false;
        }

        return (int) $USER->id === $candidateid
            || has_capability('tool/vae:identityview', \context_user::instance($candidateid));
    }

    /**
     * Refuse unless the current user may deposit.
     *
     * @param int $candidateid
     * @throws \moodle_exception errordepositrefused
     */
    public static function require_can_deposit(int $candidateid): void {
        if (!self::can_deposit($candidateid)) {
            throw new \moodle_exception('errordepositrefused', 'tool_vae');
        }
    }

    /**
     * Refuse unless the current user may read.
     *
     * @param int $candidateid
     * @throws \moodle_exception errordepositrefused
     */
    public static function require_can_read(int $candidateid): void {
        if (!self::can_read($candidateid)) {
            throw new \moodle_exception('errordepositrefused', 'tool_vae');
        }
    }

    /**
     * Deposit the dossier de faisabilité as it stands: a new milestone.
     *
     * @param int $candidateid
     * @param string|null $note An optional sentence, 255 characters at most.
     * @param bool $confirmed Whether the candidate confirms their commitment with this deposit. The
     *        confirmation is part of the snapshot, so it changes the digest; it needs the section
     *        'engagement' to have a saved version.
     * @param string $doctype The document: the dossier de faisabilité, or the preparation of the validation dossier
     *        (which has no commitment).
     * @return \stdClass The row of tool_vae_deposit.
     * @throws \moodle_exception errordepositempty when no section has a saved version;
     *         errordepositengagement when the commitment is confirmed and not written;
     *         errordepositunchanged when nothing changed since the latest deposit.
     */
    public function deposit(
        int $candidateid,
        ?string $note = null,
        bool $confirmed = false,
        string $doctype = draft_manager::DOCTYPE_FAISABILITE
    ): \stdClass {
        global $DB, $USER;

        self::require_can_deposit($candidateid);
        $validation = self::known_doctype($doctype) === draft_manager::DOCTYPE_VALIDATION;
        if ($validation && (!draft_manager::validation_open() || $confirmed)) {
            // The commitment belongs to the dossier de faisabilité; and the feature is closed until it is opened.
            throw new \moodle_exception('errordepositrefused', 'tool_vae');
        }

        $note = trim((string) $note);
        if (\core_text::strlen($note) > self::NOTE_MAXLENGTH) {
            throw new \moodle_exception('errordrafttoolong', 'tool_vae', '', self::NOTE_MAXLENGTH);
        }

        $content = $validation ? self::validation_snapshot($candidateid) : self::snapshot($candidateid, $confirmed);
        $hash = hash('sha256', $content);

        $lock = \core\lock\lock_config::get_lock_factory('tool_vae_deposit')
            ->get_lock('candidate-' . $candidateid, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \moodle_exception('errordepositbusy', 'tool_vae');
        }
        try {
            $latest = $this->latest($candidateid, $doctype);
            if ($latest && $latest->contenthash === $hash) {
                throw new \moodle_exception('errordepositunchanged', 'tool_vae', '', (int) $latest->number);
            }

            $row = (object) [
                'uuid' => identity_map::generate_ulid(),
                'candidateid' => $candidateid,
                'doctype' => $doctype,
                'number' => $latest ? (int) $latest->number + 1 : 1,
                'content' => $content,
                'contenthash' => $hash,
                'note' => $note === '' ? null : $note,
                'timecreated' => time(),
            ];
            $row->id = $DB->insert_record('tool_vae_deposit', $row);
        } finally {
            $lock->release();
        }

        deposit_created::create([
            'context' => \context_user::instance($candidateid),
            'objectid' => (int) $row->id,
            'relateduserid' => $candidateid,
            'userid' => (int) $USER->id,
            'other' => ['number' => (int) $row->number, 'doctype' => $doctype],
        ])->trigger();

        return $row;
    }

    /**
     * The deposits of a candidate, the latest first.
     *
     * @param int $candidateid
     * @param string $doctype The document: the dossier de faisabilité, or the preparation of the validation dossier.
     * @return \stdClass[] Rows of tool_vae_deposit, keyed by number.
     */
    public function deposits(int $candidateid, string $doctype = draft_manager::DOCTYPE_FAISABILITE): array {
        global $DB;

        self::require_can_read($candidateid);

        $rows = $DB->get_records('tool_vae_deposit', [
            'candidateid' => $candidateid,
            'doctype' => self::known_doctype($doctype),
        ], 'number DESC');

        $bynumber = [];
        foreach ($rows as $row) {
            $bynumber[(int) $row->number] = $row;
        }

        return $bynumber;
    }

    /**
     * One deposit.
     *
     * @param int $candidateid
     * @param int $number
     * @param string $doctype
     * @return \stdClass
     * @throws \moodle_exception errordepositmissing
     */
    public function get(int $candidateid, int $number, string $doctype = draft_manager::DOCTYPE_FAISABILITE): \stdClass {
        global $DB;

        self::require_can_read($candidateid);

        $row = $DB->get_record('tool_vae_deposit', [
            'candidateid' => $candidateid,
            'doctype' => self::known_doctype($doctype),
            'number' => $number,
        ]);
        if (!$row) {
            throw new \moodle_exception('errordepositmissing', 'tool_vae');
        }

        return $row;
    }

    /**
     * The sections of a deposit, in the order of the catalogue.
     *
     * The separate fields are given only to a reader who may see them (MC-11): the candidate, and
     * whoever holds tool/vae:identityview on top of the right to read the deposit.
     *
     * @param \stdClass $deposit A row of tool_vae_deposit.
     * @param bool $withfields Whether the reader may see the sections of separate fields.
     * @return array[] Each ['code', 'slot', 'kind', 'version', 'saved' (int), 'content'].
     */
    public static function sections(\stdClass $deposit, bool $withfields): array {
        $data = json_decode((string) $deposit->content, true);
        $out = [];
        foreach ($data['rubriques'] ?? [] as $rubrique) {
            $code = (string) $rubrique['code'];
            $kind = faisabilite_catalog::kind($code);
            if ($kind === null || ($kind === faisabilite_catalog::KIND_FIELDS && !$withfields)) {
                continue;
            }
            $out[] = [
                'code' => $code,
                'slot' => (int) $rubrique['emplacement'],
                'kind' => $kind,
                'version' => (int) $rubrique['version'],
                'saved' => (int) strtotime((string) $rubrique['enregistree_le']),
                'content' => (string) $rubrique['contenu'],
            ];
        }

        return $out;
    }

    /**
     * The rubrics of a deposit of the preparation of the validation dossier, as they were deposited (T-11, lot 3).
     *
     * @param \stdClass $deposit A row of tool_vae_deposit.
     * @return array[] Each as in validation.json: fiche, code, bloc, partie, version, enregistree_le, texte.
     */
    public static function validation_rubriques(\stdClass $deposit): array {
        if ($deposit->doctype !== draft_manager::DOCTYPE_VALIDATION) {
            return [];
        }
        $data = json_decode((string) $deposit->content, true);

        return is_array($data['rubriques'] ?? null) ? $data['rubriques'] : [];
    }

    /**
     * The document of a deposit, refused when this plugin does not know it.
     *
     * @param string $doctype
     * @return string
     * @throws \moodle_exception errordepositmissing
     */
    private static function known_doctype(string $doctype): string {
        // The reflective journal is never deposited (T-14): it is the author's alone.
        if (!in_array($doctype, [draft_manager::DOCTYPE_FAISABILITE, draft_manager::DOCTYPE_VALIDATION], true)) {
            throw new \moodle_exception('errordepositmissing', 'tool_vae');
        }

        return $doctype;
    }

    /**
     * The snapshot of the preparation of the validation dossier: every section saved, whatever the record, as validation.json.
     *
     * Without the sentence of labelling: it is shown in the language of whoever reads, not frozen in the hash.
     *
     * @param int $candidateid
     * @return string
     * @throws \moodle_exception errordepositempty
     */
    private static function validation_snapshot(int $candidateid): string {
        $document = validation_overview::document($candidateid);
        if ($document === null) {
            throw new \moodle_exception('errordepositempty', 'tool_vae');
        }
        unset($document['avertissement']);

        return json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Whether the candidate confirmed their commitment with this deposit.
     *
     * @param \stdClass $deposit A row of tool_vae_deposit.
     * @return bool
     */
    public static function engagement_confirmed(\stdClass $deposit): bool {
        $data = json_decode((string) $deposit->content, true);

        return !empty($data['engagement_confirme']);
    }

    /**
     * The snapshot of what the candidate has saved, as the JSON a deposit keeps.
     *
     * @param int $candidateid
     * @param bool $confirmed The commitment is confirmed with this deposit.
     * @return string
     * @throws \moodle_exception errordepositempty, errordepositengagement
     */
    private static function snapshot(int $candidateid, bool $confirmed = false): string {
        $saved = faisabilite_overview::saved_versions($candidateid);

        $rubriques = [];
        foreach (faisabilite_catalog::sections() as $code => $definition) {
            if ($definition['kind'] === faisabilite_catalog::KIND_ACCOUNT || empty($saved[$code])) {
                continue;
            }
            $places = $saved[$code];
            ksort($places);
            foreach ($places as $slot => $version) {
                $rubriques[] = [
                    'code' => (string) $code,
                    'emplacement' => (int) $slot,
                    'version' => $version['number'],
                    'enregistree_le' => gmdate('Y-m-d\TH:i:s\Z', $version['created']),
                    'contenu' => $version['content'],
                ];
            }
        }
        if (!$rubriques) {
            throw new \moodle_exception('errordepositempty', 'tool_vae');
        }
        if ($confirmed && empty($saved['engagement'][0])) {
            throw new \moodle_exception('errordepositengagement', 'tool_vae');
        }

        $document = ['rubriques' => $rubriques];
        if ($confirmed) {
            // The date is the deposit's own: nothing else is written, so nothing can disagree with it.
            $document['engagement_confirme'] = true;
        }

        return json_encode(
            $document,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * The latest deposit of a candidate, or null.
     *
     * @param int $candidateid
     * @param string $doctype
     * @return \stdClass|null
     */
    private function latest(int $candidateid, string $doctype = draft_manager::DOCTYPE_FAISABILITE): ?\stdClass {
        global $DB;

        $rows = $DB->get_records('tool_vae_deposit', [
            'candidateid' => $candidateid,
            'doctype' => $doctype,
        ], 'number DESC', '*', 0, 1);

        return $rows ? reset($rows) : null;
    }
}
