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
 * The work situations of the vaeiste, their pieces, the annotations and the consents in the archive of the dossier, and back
 * (lot 1b, D-1b-A).
 *
 * THE WORD OF THE VAEISTE, AND APART FROM IT THE WORD OF THE MANAGERS THEY CONSENTED TO; NEVER A VALIDATION. situations.json
 * holds every situation with its versions, its tags, its draft in progress and its pieces, the list of those pieces, every
 * annotation (withdrawals included) and every line of consent. The file says in its first lines that nothing in it is attested
 * or validated, and `officiel` is always false. It goes in the archive the vaeiste takes away and in the backup, always; in the
 * export a third party makes only when that person may read the preparation of the validation dossier, whose part the
 * situations are (the direction and the managers who follow, never the site administrator: D-1b-G, decision of the project
 * owner, 9 November 2026). The bytes of the files, the addresses of the links and the titles of the pieces only in the first
 * and the last (MC-28, as pieces.json). It never enters validation.json, the PDF nor the copies (D-1b-D).
 *
 * NO USER, NO RIGHT. The document is read by the backup, the uninstall, the refresh after an erasure and the scheduled copy,
 * which run with nobody logged in: it asks the database, never has_capability() nor $USER. Who may carry the file out of the
 * platform is the business of the archive that holds it.
 *
 * NO ACCOUNT OF THIS SITE IS EVER NAMED BY A RESTORED LINE (D-1b-L). An author or a manager is written by the opaque
 * identifier of identity_map (author()), as positioning_archive does; a restored annotation or consent keeps that identifier
 * (authoruuid, manageruuid) and names no account (authorid, managerid null): identity_map::resolve() is never called. A
 * restored consent therefore never opens the annotation (consent_manager::stands() reads the local lines alone), and a restored
 * withdrawal never takes back an annotation written here: it comes with the annotation it names, or against one already
 * restored under the same author, or not at all (restore_notes()); note_manager reads a withdrawal by the same rule.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class situation_archive {
    /** @var string The file, in the archive. */
    public const PATH = 'situations.json';

    /** @var string The folder of the bytes of the files of the pieces, in an archive that carries them. */
    public const FOLDER = 'pieces-situations/';

    /** @var string The version of the schema of the file. */
    public const SCHEMA_VERSION = '0.1.0';

    /** @var int How many versions one situation may hold: over it, the situation is left (D-1b-J). */
    public const MAX_VERSIONS = situation_manager::MAX_VERSIONS;

    /** @var int How many annotations (lines ACTIVE, restored lines counted) one target may hold: over it, the target is left.
     *  The withdrawals are not counted (T15: the bound counts what opens). */
    public const MAX_NOTES = note_manager::MAX_PER_TARGET;

    /** @var int How many lines GIVEN a vaeiste may hold, restored lines counted: over it, every line of the file is left; the
     *  withdrawals are not counted (T15). */
    public const MAX_CONSENTS = consent_manager::MAX_LINES;

    /** @var int How many lines of consent the file may hold (the importer refuses), and how many lines a vaeiste may hold in
     *  all once a file is restored, every origin and state counted (over it, every line of the file is left): twice the
     *  consents given, since a withdrawal follows a consent given to the same manager and nothing else (T15 tour 2). The
     *  local writes bound the consents given (consent_manager::MAX_LINES) and a withdrawal only follows one of them. */
    public const MAX_CONSENT_LINES = 2 * consent_manager::MAX_LINES;

    /** @var int How many lines of annotation the file may hold, all targets together (the importer refuses): a bound against
     *  a flood, as selfpos_archive::MAX_LINES, not a count of what a dossier may hold. */
    public const MAX_NOTE_LINES = 20000;

    /** @var int The earliest day a period may start: 1 January 1800 UTC, a century before the first year the date selector of
     *  the site offers, which gives midnight LOCAL time (the schema holds the same bound, situations.schema.json). */
    public const PERIOD_EARLIEST = -5364662400;

    /** @var int The latest day a period may end: 1 January 2200 UTC (the schema holds the same bound). */
    public const PERIOD_LATEST = 7258118400;

    /** @var string[] How the format of a text is written in the archive, by the core's format number. */
    public const FORMATS = selfpos_archive::FORMATS;

    /** @var string[] How the target of an annotation is written in the archive, by note_manager's word. */
    public const TARGETS = [note_manager::TARGET_SITUATION => 'situation', note_manager::TARGET_SELFPOS => 'autopositionnement'];

    /** @var string[] How the state of an annotation is written in the archive. */
    public const NOTE_STATES = [note_manager::ACTIVE => 'active', note_manager::WITHDRAWN => 'retiree'];

    /** @var string[] How the state of a line of consent is written in the archive. */
    public const CONSENT_STATES = [consent_manager::GIVEN => 'donne', consent_manager::WITHDRAWN => 'retire'];

    /**
     * The document of the file, or null when the vaeiste has no situation, no piece, no annotation and no consent.
     *
     * @param int $candidateid
     * @param bool $fullpieces Whether the paths of the files, the addresses of the links and the titles of the pieces are
     *        given: the archive the vaeiste exports themself and the backup, never the export of a third party.
     * @param string[]|null $available The paths of the files really in the archive, when it is built without refusing a file
     *        that went missing: a piece whose file is not among them is listed without its path.
     * @return array|null See situations.schema.json.
     */
    public static function document(int $candidateid, bool $fullpieces, ?array $available = null): ?array {
        global $DB;

        $situations = $DB->get_records(situation_manager::TABLE, ['candidateid' => $candidateid], 'fichecode ASC, slot ASC');
        $pieces = $DB->get_records(spiece_manager::TABLE, ['candidateid' => $candidateid], 'id ASC');
        $notes = $DB->get_records(note_manager::TABLE, ['candidateid' => $candidateid], 'timecreated ASC, id ASC');
        $consents = $DB->get_records(consent_manager::TABLE, ['candidateid' => $candidateid], 'timecreated ASC, id ASC');
        if (!$situations && !$pieces && !$notes && !$consents) {
            return null;
        }

        // The authors whose account is deleted are gone: no identifier is minted for them.
        $accounts = array_unique(array_filter(array_merge(
            array_map(fn($row) => (int) $row->authorid, $notes),
            array_map(fn($row) => (int) $row->managerid, $consents)
        )));
        $gone = [];
        if ($accounts) {
            [$insql, $inparams] = $DB->get_in_or_equal($accounts, SQL_PARAMS_NAMED);
            $present = $DB->get_fieldset_select('user', 'id', "id $insql AND deleted = 0", $inparams);
            $gone = array_diff($accounts, array_map('intval', $present));
        }
        $pieceuuids = [];
        foreach ($pieces as $piece) {
            $pieceuuids[(int) $piece->id] = (string) $piece->uuid;
        }

        return [
            'avertissement' => get_string('situationarchivesentence', 'tool_vae'),
            'officiel' => false,
            'situations' => self::situation_entries($situations, $pieceuuids),
            'pieces' => array_values(array_map(fn($row) => self::piece_entry($row, $fullpieces, $available), $pieces)),
            'annotations' => array_values(array_map(fn($row) => self::note_entry($row, $gone), $notes)),
            'accords' => array_values(array_map(fn($row) => self::consent_entry($row, $gone), $consents)),
        ];
    }

    /**
     * The bytes of the files the document names, path => content, each read back and compared to its digest.
     *
     * @param int $candidateid
     * @param bool $strict Refuse on the first file that is missing or altered (the archive the vaeiste exports); when false
     *        (the refresh after an erasure, the backup before an uninstall) the file is left out and the piece listed without
     *        it.
     * @return string[]
     * @throws \moodle_exception errorpiecealtered
     */
    public static function files(int $candidateid, bool $strict = true): array {
        global $DB;

        $context = \context_user::instance($candidateid);
        $fs = get_file_storage();
        $files = [];
        $rows = $DB->get_records_select(
            spiece_manager::TABLE,
            'candidateid = :candidateid AND kind = :kind AND timeremoved IS NULL',
            ['candidateid' => $candidateid, 'kind' => piece_manager::KIND_FILE],
            'id'
        );
        foreach ($rows as $row) {
            $file = $fs->get_file(
                $context->id,
                'tool_vae',
                spiece_manager::FILEAREA,
                (int) $row->id,
                '/',
                $row->uuid . '.' . $row->ext
            );
            $content = $file ? $file->get_content() : null;
            if ($content === null || !hash_equals((string) $row->sha256, hash('sha256', $content))) {
                if ($strict) {
                    throw new \moodle_exception('errorpiecealtered', 'tool_vae', '', $row->uuid);
                }
                debugging(
                    'tool_vae: the file of the piece ' . $row->uuid . ' is missing or altered; left out of the archive.',
                    DEBUG_DEVELOPER
                );
                continue;
            }
            $files[self::path($row)] = $content;
        }

        return $files;
    }

    /**
     * Bring an archive back: the pieces, then the situations whole or not at all, then the annotations and the consents line
     * by line (lot 1b).
     *
     * A piece whose identifier is already here is left, and so is every piece when attaching is closed here. A situation is
     * written whole - its draft in progress, its versions with their snapshots, its tags, its links - or left: when its slot is
     * taken (a situation or a draft already at that address), its identifier known, its record not imported here, a tag no
     * positionable node of level two or more, a piece it cites neither restored nor here, or more versions than one situation
     * keeps (D-1b-J). The draft and the versions are written here, signed by the vaeiste and dated as the archive says, with
     * new identifiers; draft_manager::restore_from_archive() refuses a situation. An annotation whose target is unknown - a
     * situation neither in the archive nor here, a node the record does not hold - is left; the lines of one target go in all
     * or none, so that the target never comes out full without the archive having said so; an identifier already here is left.
     * The lines of consent likewise, for the bound of the vaeiste. A restored annotation or consent names no account of this
     * site (D-1b-L): authorid and managerid stay null, authoruuid and manageruuid carry the identifier of the archive.
     *
     * The content hashes of the files written are given back in $plan->hashes, for the caller to remove them should its
     * transaction fail.
     *
     * @param int $candidateid The vaeiste the archive is imported for.
     * @param \stdClass $plan As dossier_importer::situations() gives it: situations, pieces, notes, consents.
     * @param int $importedat When.
     * @return int[] [elements restored, elements left]: a situation whole, a piece, an annotation, a line of consent.
     */
    public static function restore(int $candidateid, \stdClass $plan, int $importedat): array {
        $plan->hashes = [];
        $restored = 0;
        $left = 0;

        $pieceids = self::restore_pieces($candidateid, $plan, $importedat, $restored, $left);
        self::restore_situations($candidateid, $plan->situations, $pieceids, $importedat, $restored, $left);
        self::restore_notes($candidateid, $plan->notes, $importedat, $restored, $left);
        self::restore_consents($candidateid, $plan->consents, $importedat, $restored, $left);

        return [$restored, $left];
    }

    /**
     * The entries of the situations, each with its tags, its versions and their snapshots, its draft and its links.
     *
     * @param \stdClass[] $situations Rows of tool_vae_situation.
     * @param string[] $pieceuuids Piece id => uuid.
     * @return array[]
     */
    private static function situation_entries(array $situations, array $pieceuuids): array {
        global $DB;

        if (!$situations) {
            return [];
        }
        $ids = array_map(fn($row) => (int) $row->id, $situations);
        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $tags = [];
        foreach ($DB->get_records_select('tool_vae_sitetag', "situationid $insql", $inparams, 'id ASC') as $tag) {
            $tags[(int) $tag->situationid][] = (string) $tag->ficherang;
        }
        $snapshots = [];
        foreach ($DB->get_records_select('tool_vae_sitversion', "situationid $insql", $inparams, 'id ASC') as $snapshot) {
            $snapshots[(int) $snapshot->situationid][(int) $snapshot->number] = $snapshot;
        }
        $links = [];
        foreach ($DB->get_records_select(spiece_manager::LINKS, "situationid $insql", $inparams, 'id ASC') as $link) {
            $links[(int) $link->situationid][] = $link;
        }
        $draftids = array_map(fn($row) => (int) $row->draftid, $situations);
        [$draftsql, $draftparams] = $DB->get_in_or_equal($draftids, SQL_PARAMS_NAMED);
        $drafts = $DB->get_records_select('tool_vae_draft', "id $draftsql", $draftparams, '', 'id, working');
        $versions = [];
        foreach ($DB->get_records_select('tool_vae_version', "draftid $draftsql", $draftparams, 'number ASC') as $version) {
            $versions[(int) $version->draftid][] = $version;
        }

        $out = [];
        foreach ($situations as $row) {
            $id = (int) $row->id;
            $ranks = $tags[$id] ?? [];
            usort($ranks, 'strnatcmp');
            $entry = [
                'identifiant' => (string) $row->uuid,
                'fiche' => (string) $row->fichecode,
                'emplacement' => (int) $row->slot,
            ] + self::details((string) $row->title, $row->periodstart, $row->periodend, (string) $row->setting, $ranks);
            $entry['versions'] = [];
            foreach ($versions[(int) $row->draftid] ?? [] as $version) {
                $entry['versions'][] = self::version_entry($version, $snapshots[$id][(int) $version->number] ?? null);
            }
            $draft = $drafts[(int) $row->draftid] ?? null;
            if ($draft !== null && $draft->working !== null) {
                $entry['brouillon'] = (string) $draft->working;
            }
            $entry['pieces'] = [];
            foreach ($links[$id] ?? [] as $link) {
                if (!isset($pieceuuids[(int) $link->pieceid])) {
                    continue;
                }
                $lien = [
                    'piece' => $pieceuuids[(int) $link->pieceid],
                    'partie' => (string) $link->part,
                    'ajoutee_le' => exporter::iso((int) $link->timecreated),
                ];
                if ($link->timeremoved !== null) {
                    $lien['retiree_le'] = exporter::iso((int) $link->timeremoved);
                }
                $entry['pieces'][] = $lien;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * The title, the period, the setting and the tags, as the situation or a snapshot carries them.
     *
     * @param string $title
     * @param mixed $periodstart Null or a timestamp.
     * @param mixed $periodend Null or a timestamp.
     * @param string $setting
     * @param string[] $ranks Sorted.
     * @return array intitule, periode_debut and periode_fin when given, cadre, etiquettes.
     */
    private static function details(string $title, $periodstart, $periodend, string $setting, array $ranks): array {
        $out = ['intitule' => $title];
        if ($periodstart !== null) {
            $out['periode_debut'] = exporter::iso((int) $periodstart);
        }
        if ($periodend !== null) {
            $out['periode_fin'] = exporter::iso((int) $periodend);
        }
        $out['cadre'] = $setting;
        $out['etiquettes'] = array_values($ranks);

        return $out;
    }

    /**
     * One version of the text, with the snapshot it took.
     *
     * @param \stdClass $version A row of tool_vae_version.
     * @param \stdClass|null $snapshot The row of tool_vae_sitversion, or null for a version that has none.
     * @return array
     */
    private static function version_entry(\stdClass $version, ?\stdClass $snapshot): array {
        $entry = [
            'numero' => (int) $version->number,
            'contenu' => (string) $version->content,
            'empreinte' => (string) $version->contenthash,
        ];
        if ($version->note !== null && $version->note !== '') {
            $entry['note'] = (string) $version->note;
        }
        if ($version->restoredfrom !== null) {
            $entry['restauree_de'] = (int) $version->restoredfrom;
        }
        $entry['ecrit_le'] = exporter::iso((int) $version->timecreated);
        if ($snapshot !== null) {
            $ranks = array_values(array_filter(explode(',', (string) $snapshot->tags), 'strlen'));
            $entry['instantane'] = self::details(
                (string) $snapshot->title,
                $snapshot->periodstart,
                $snapshot->periodend,
                (string) $snapshot->setting,
                $ranks
            );
        }

        return $entry;
    }

    /**
     * One piece, as pieces.json says a piece: what it is, never what it contains, unless the archive carries the bytes.
     *
     * @param \stdClass $row A row of tool_vae_spiece.
     * @param bool $full See document().
     * @param string[]|null $available See document().
     * @return array
     */
    private static function piece_entry(\stdClass $row, bool $full, ?array $available): array {
        $removed = $row->timeremoved !== null;
        $isfile = $row->kind === piece_manager::KIND_FILE;
        $entry = [
            'identifiant' => (string) $row->uuid,
            'fiche' => (string) $row->fichecode,
            'genre' => $isfile ? 'fichier' : 'lien',
            'nature' => (string) $row->nature,
        ];
        if ($full && !$removed && (string) $row->title !== '') {
            $entry['intitule'] = (string) $row->title;
        }
        $entry['ajoutee_le'] = exporter::iso((int) $row->timecreated);
        if ($removed) {
            $entry['retiree_le'] = exporter::iso((int) $row->timeremoved);
        }
        if ($isfile) {
            $entry['type'] = (string) $row->mimetype;
            $entry['taille'] = (int) $row->filesize;
            $entry['sha256'] = (string) $row->sha256;
            if ($full && !$removed && ($available === null || in_array(self::path($row), $available, true))) {
                $entry['fichier'] = self::path($row);
            }
        } else if ($full && !$removed) {
            $entry['adresse'] = (string) $row->url;
        }

        return $entry;
    }

    /**
     * One annotation, or the withdrawal of one: the author by an opaque identifier, the text, the fingerprint.
     *
     * @param \stdClass $row A row of tool_vae_note.
     * @param int[] $gone The accounts that are deleted.
     * @return array
     */
    private static function note_entry(\stdClass $row, array $gone): array {
        $entry = [
            'identifiant' => (string) $row->uuid,
            'cible' => self::word(self::TARGETS, (string) $row->target, 'target'),
        ];
        if ((string) $row->targetuuid !== '') {
            $entry['cible_identifiant'] = (string) $row->targetuuid;
        }
        $entry['fiche'] = (string) $row->fichecode;
        $entry['emplacement'] = (int) $row->slot;
        $entry['rang'] = (string) $row->ficherang;
        $author = self::author($row->authorid, $row->authoruuid, $gone);
        if ($author !== null) {
            $entry['auteur'] = $author;
        }
        $entry['texte'] = (string) $row->text;
        $entry['format'] = self::FORMATS[(int) $row->textformat] ?? 'texte';
        $entry['etat'] = self::word(self::NOTE_STATES, (string) $row->state, 'state');
        if ($row->withdraws !== null && $row->withdraws !== '') {
            $entry['retire'] = (string) $row->withdraws;
        }
        $entry['empreinte'] = (string) $row->notehash;
        $entry['ecrit_le'] = exporter::iso((int) $row->timecreated);

        return $entry;
    }

    /**
     * One line of consent: the manager by an opaque identifier, the state, the date.
     *
     * @param \stdClass $row A row of tool_vae_consent.
     * @param int[] $gone The accounts that are deleted.
     * @return array
     */
    private static function consent_entry(\stdClass $row, array $gone): array {
        $entry = ['identifiant' => (string) $row->uuid];
        $manager = self::author($row->managerid, $row->manageruuid, $gone);
        if ($manager !== null) {
            $entry['responsable'] = $manager;
        }
        $entry['etat'] = self::word(self::CONSENT_STATES, (string) $row->state, 'state');
        $entry['date'] = exporter::iso((int) $row->timecreated);

        return $entry;
    }

    /**
     * The word of the archive for a value of a table, and a coding error for a value the archive has no word for: a false
     * state must never be written in silence.
     *
     * @param string[] $words Value of the table => word of the archive.
     * @param string $value
     * @param string $what The column, for the message.
     * @return string
     * @throws \coding_exception
     */
    private static function word(array $words, string $value, string $what): string {
        if (!isset($words[$value])) {
            throw new \coding_exception("situation_archive: no word of the archive for the $what '$value'.");
        }

        return $words[$value];
    }

    /**
     * The opaque identifier of the author of a line, or null when the author is gone.
     *
     * A line restored from an archive names its author by the identifier the archive gave; one written here by the identifier
     * this site gave the account (identity_map::uuid_for()). An author erased from the platform has neither. Nothing here
     * resolves an identifier into an account (positioning_archive::author()).
     *
     * @param mixed $id The account, or null.
     * @param mixed $uuid The identifier of the archive, or null.
     * @param int[] $gone The accounts that are deleted.
     * @return string|null
     */
    private static function author($id, $uuid, array $gone): ?string {
        if (!empty($id)) {
            return in_array((int) $id, $gone, true) ? null : identity_map::uuid_for(identity_map::TYPE_USER, (int) $id);
        }

        return empty($uuid) ? null : (string) $uuid;
    }

    /**
     * The path of the bytes of a file in an archive: the identifier of the piece and the extension of its type.
     *
     * @param \stdClass $row A row of tool_vae_spiece.
     * @return string
     */
    private static function path(\stdClass $row): string {
        return self::FOLDER . $row->uuid . '.' . $row->ext;
    }

    /**
     * Write the pieces: a piece already here, every piece when attaching is closed, and a piece the archive lists without
     * anything to restore are left.
     *
     * Only what has something to restore is written, as dossier_importer writes the pieces of the declarations: a file with
     * its bytes, a link with its address, a removed piece (its trace). A piece listed without them - the export of a third
     * party - is counted and left, never written as an empty shell; the situations that cite it are left with it.
     *
     * @param int $candidateid
     * @param \stdClass $plan See restore(); its hashes receive the content hashes of the files written.
     * @param int $importedat
     * @param int $restored Counted up.
     * @param int $left Counted up.
     * @return \stdClass[] Piece uuid => id, removed, fichecode of its row here, restored now or already here.
     */
    private static function restore_pieces(int $candidateid, \stdClass $plan, int $importedat, int &$restored, int &$left): array {
        global $DB;

        $open = piece_manager::is_open();
        $context = \context_user::instance($candidateid);
        $fs = get_file_storage();
        $pieces = [];
        foreach ($plan->pieces as $item) {
            $entry = $item->entry;
            $here = $DB->get_record(
                spiece_manager::TABLE,
                ['uuid' => $entry->identifiant],
                'id, candidateid, fichecode, timeremoved'
            );
            if ($here) {
                if ((int) $here->candidateid === $candidateid) {
                    $pieces[(string) $entry->identifiant] = self::piece_here($here);
                }
                $left++;
                continue;
            }
            $removed = isset($entry->retiree_le);
            if (!$open || ($item->bytes === null && !isset($entry->adresse) && !$removed)) {
                $left++;
                continue;
            }
            $isfile = $entry->genre === 'fichier';
            $id = $DB->insert_record(spiece_manager::TABLE, (object) [
                'uuid' => $entry->identifiant,
                'candidateid' => $candidateid,
                'fichecode' => $entry->fiche,
                'kind' => $isfile ? piece_manager::KIND_FILE : piece_manager::KIND_LINK,
                'nature' => $entry->nature,
                'title' => $entry->intitule ?? '',
                'ext' => $item->ext,
                'mimetype' => $isfile ? $entry->type : null,
                'filesize' => $isfile ? (int) $entry->taille : null,
                'sha256' => $isfile ? $entry->sha256 : null,
                'url' => $entry->adresse ?? null,
                'timecreated' => $item->addedat,
                'timeremoved' => $removed ? $item->removedat : null,
            ]);
            if ($item->bytes !== null) {
                $stored = $fs->create_file_from_string([
                    'contextid' => $context->id,
                    'component' => 'tool_vae',
                    'filearea' => spiece_manager::FILEAREA,
                    'itemid' => (int) $id,
                    'filepath' => '/',
                    'filename' => $entry->identifiant . '.' . $item->ext,
                    'userid' => $candidateid,
                ], $item->bytes);
                $plan->hashes[] = $stored->get_contenthash();
            }
            $pieces[(string) $entry->identifiant] = (object) [
                'id' => (int) $id, 'removed' => $removed, 'fichecode' => (string) $entry->fiche,
            ];
            $restored++;
        }

        return $pieces;
    }

    /**
     * What a situation needs to know of a piece already here.
     *
     * @param \stdClass $row id, fichecode, timeremoved of tool_vae_spiece.
     * @return \stdClass id, removed, fichecode.
     */
    private static function piece_here(\stdClass $row): \stdClass {
        return (object) [
            'id' => (int) $row->id, 'removed' => $row->timeremoved !== null, 'fichecode' => (string) $row->fichecode,
        ];
    }

    /**
     * Write the situations: each comes with its text, its versions and its details, or is left whole when nothing of it can
     * be placed (a record this site does not hold, a slot or an identifier taken, more versions than the bound).
     *
     * What the situation carries and cannot be restored is left ONE BY ONE and counted, the situation comes without it
     * (T15, point C, as a piece left does not take its declaration): a tag on a node this record does not hold or that is not
     * positionable; a link to a piece that is neither restored nor here, or of another record; a link that stands beyond
     * piece_manager::MAX_ACTIVE standing ones (a link that stands on a piece not removed), in the order of the archive - the
     * rules of the keyboard (spiece_manager::attach()). The annotations that name the situation follow it (restore_notes()).
     *
     * @param int $candidateid
     * @param \stdClass[] $situations As dossier_importer::situations() gives them.
     * @param \stdClass[] $pieces Piece uuid => id, removed, fichecode: the pieces restored or already here.
     * @param int $importedat
     * @param int $restored Counted up.
     * @param int $left Counted up.
     */
    private static function restore_situations(
        int $candidateid,
        array $situations,
        array $pieces,
        int $importedat,
        int &$restored,
        int &$left
    ): void {
        global $DB;

        $trees = [];
        foreach ($situations as $situation) {
            $tree = self::tree($trees, (string) $situation->fichecode);
            if ($tree === null || count($situation->versions) > self::MAX_VERSIONS) {
                $left++;
                continue;
            }
            // The tags and the links the situation comes with; $dropped counts those left, once the situation is written.
            $dropped = 0;
            $tags = [];
            foreach ($situation->tags as $rank) {
                $node = selfpos_tree::node($tree, $rank);
                if ($node === null || !$node->positionable || (int) $node->level < situation_manager::MIN_TAG_LEVEL) {
                    $dropped++;
                    continue;
                }
                $tags[] = $rank;
            }
            $links = [];
            $active = 0;
            foreach ($situation->links as $link) {
                if (!isset($pieces[$link->pieceuuid])) {
                    $row = $DB->get_record(
                        spiece_manager::TABLE,
                        ['uuid' => $link->pieceuuid, 'candidateid' => $candidateid],
                        'id, fichecode, timeremoved'
                    );
                    if ($row) {
                        $pieces[$link->pieceuuid] = self::piece_here($row);
                    }
                }
                $piece = $pieces[$link->pieceuuid] ?? null;
                if ($piece === null || $piece->fichecode !== (string) $situation->fichecode) {
                    $dropped++;
                    continue;
                }
                if ($link->removedat === null && !$piece->removed) {
                    if ($active >= piece_manager::MAX_ACTIVE) {
                        $dropped++;
                        continue;
                    }
                    $active++;
                }
                $links[] = $link;
            }

            // The lock under which a situation is opened here: a slot read free cannot be taken in between.
            $lock = positioning_rules::lock($candidateid, situation_manager::LOCK_KEY);
            try {
                $address = ['candidateid' => $candidateid, 'fichecode' => $situation->fichecode, 'slot' => $situation->slot];
                $taken = $DB->record_exists(situation_manager::TABLE, $address)
                    || $DB->record_exists(situation_manager::TABLE, ['uuid' => $situation->uuid])
                    || $DB->record_exists('tool_vae_draft', [
                        'candidateid' => $candidateid,
                        'doctype' => draft_manager::DOCTYPE_VALIDATION,
                        'fichecode' => $situation->fichecode,
                        'section' => validation_catalog::SITUATION_SECTION,
                        'slot' => $situation->slot,
                    ]);
                if ($taken) {
                    $left++;
                    continue;
                }
                self::insert_situation($candidateid, $situation, $tags, $links, $pieces, $importedat);
                $restored++;
                $left += $dropped;
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * Write one situation: its draft and versions (new identifiers, the vaeiste as author, the dates of the archive), its
     * row, its tags, the snapshot of each version, its links.
     *
     * The versions are the vaeiste's own words and carry them as author; the draft keeps the importer in usermodified, the
     * trace of the import, as draft_manager::restore_from_archive() does.
     *
     * @param int $candidateid
     * @param \stdClass $situation
     * @param string[] $tags The ranks of the tags that are restored (restore_situations() left the others).
     * @param \stdClass[] $links The links that are restored (pieceuuid, part, addedat, removedat), likewise.
     * @param \stdClass[] $pieces Piece uuid => id, removed, fichecode.
     * @param int $importedat
     */
    private static function insert_situation(
        int $candidateid,
        \stdClass $situation,
        array $tags,
        array $links,
        array $pieces,
        int $importedat
    ): void {
        global $DB, $USER;

        $opened = $situation->versions ? (int) $situation->versions[0]->writtenat : $importedat;
        $draftid = $DB->insert_record('tool_vae_draft', (object) [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => $candidateid,
            'doctype' => draft_manager::DOCTYPE_VALIDATION,
            'section' => validation_catalog::SITUATION_SECTION,
            'slot' => $situation->slot,
            'fichecode' => $situation->fichecode,
            'working' => $situation->working,
            'workingat' => $situation->working === null ? null : $importedat,
            'currentversion' => count($situation->versions),
            'timecreated' => $opened,
            'timemodified' => $importedat,
            'usermodified' => (int) $USER->id,
        ]);
        $situationid = $DB->insert_record(situation_manager::TABLE, (object) [
            'uuid' => $situation->uuid,
            'candidateid' => $candidateid,
            'fichecode' => $situation->fichecode,
            'slot' => $situation->slot,
            'draftid' => (int) $draftid,
            'title' => $situation->title,
            'periodstart' => $situation->periodstart,
            'periodend' => $situation->periodend,
            'setting' => $situation->setting,
            'timecreated' => $opened,
            'timemodified' => $importedat,
        ]);
        foreach ($tags as $rank) {
            $DB->insert_record('tool_vae_sitetag', (object) [
                'situationid' => (int) $situationid,
                'candidateid' => $candidateid,
                'fichecode' => $situation->fichecode,
                'ficherang' => $rank,
                'timecreated' => $importedat,
            ]);
        }
        foreach ($situation->versions as $version) {
            $DB->insert_record('tool_vae_version', (object) [
                'uuid' => identity_map::generate_ulid(),
                'draftid' => (int) $draftid,
                'number' => $version->number,
                'content' => $version->content,
                'contenthash' => $version->hash,
                'authorid' => $candidateid,
                'note' => $version->note,
                'restoredfrom' => $version->restoredfrom,
                'timecreated' => $version->writtenat,
            ]);
            if ($version->snapshot !== null) {
                $DB->insert_record('tool_vae_sitversion', (object) [
                    'situationid' => (int) $situationid,
                    'number' => $version->number,
                    'title' => $version->snapshot->title,
                    'periodstart' => $version->snapshot->periodstart,
                    'periodend' => $version->snapshot->periodend,
                    'setting' => $version->snapshot->setting,
                    'tags' => implode(',', $version->snapshot->tags),
                    'timecreated' => $version->writtenat,
                ]);
            }
        }
        foreach ($links as $link) {
            $DB->insert_record(spiece_manager::LINKS, (object) [
                'situationid' => (int) $situationid,
                'pieceid' => $pieces[$link->pieceuuid]->id,
                'part' => $link->part,
                'timecreated' => $link->addedat,
                'timeremoved' => $link->removedat,
            ]);
        }
    }

    /**
     * Write the annotations, target by target, all the lines of a target or none.
     *
     * @param int $candidateid
     * @param \stdClass[] $notes As dossier_importer::situations() gives them, in the order of the archive.
     * @param int $importedat
     * @param int $restored Counted up.
     * @param int $left Counted up.
     */
    private static function restore_notes(int $candidateid, array $notes, int $importedat, int &$restored, int &$left): void {
        global $DB;

        if (!$notes) {
            return;
        }
        $groups = [];
        foreach ($notes as $index => $note) {
            $key = implode("\x1f", [$note->target, $note->targetuuid, $note->fichecode, $note->slot, $note->ficherang]);
            $groups[$key][] = $index;
        }

        // One lock for every target - the one the consent and the annotations take - held from the decisions to the writes;
        // the lines accepted are then written in the order of the archive, so that two lines of one second read back as
        // they were.
        $trees = [];
        $accepted = [];
        $lock = positioning_rules::lock($candidateid, consent_manager::LOCK_KEY);
        try {
            foreach ($groups as $indexes) {
                $first = $notes[$indexes[0]];
                if ($first->target === note_manager::TARGET_SITUATION) {
                    $situation = $DB->get_record(
                        situation_manager::TABLE,
                        ['uuid' => $first->targetuuid, 'candidateid' => $candidateid]
                    );
                    $known = $situation && (string) $situation->fichecode === $first->fichecode
                        && (int) $situation->slot === (int) $first->slot;
                } else {
                    $tree = self::tree($trees, (string) $first->fichecode);
                    $node = $tree === null ? null : selfpos_tree::node($tree, (string) $first->ficherang);
                    $known = $node !== null && $node->positionable;
                }
                if (!$known) {
                    $left += count($indexes);
                    continue;
                }
                // The bound counts the annotations that stand open (lines ACTIVE), never the withdrawals (T15).
                $held = $DB->count_records(note_manager::TABLE, [
                    'candidateid' => $candidateid,
                    'target' => $first->target,
                    'targetuuid' => $first->targetuuid,
                    'fichecode' => $first->fichecode,
                    'slot' => $first->slot,
                    'ficherang' => $first->ficherang,
                    'state' => note_manager::ACTIVE,
                ]);
                $fresh = array_filter(
                    $indexes,
                    fn(int $index) => !$DB->record_exists(note_manager::TABLE, ['uuid' => $notes[$index]->uuid])
                );
                $opening = count(array_filter($fresh, fn(int $index) => $notes[$index]->state === note_manager::ACTIVE));
                // Every line of the target, or none: a target that cannot hold them all would otherwise come out full, or
                // keep as standing an annotation the archive says was withdrawn. And a withdrawal only with the annotation it
                // names (withdrawals_agree()): never against the word of a manager written here.
                if ($held + $opening > self::MAX_NOTES || !self::withdrawals_agree($candidateid, $notes, $indexes, $fresh)) {
                    $left += count($indexes);
                    continue;
                }
                $left += count($indexes) - count($fresh);
                foreach ($fresh as $index) {
                    $accepted[$index] = true;
                }
            }
            foreach ($notes as $index => $note) {
                if (!isset($accepted[$index])) {
                    continue;
                }
                $DB->insert_record(note_manager::TABLE, (object) [
                    'uuid' => $note->uuid,
                    'candidateid' => $candidateid,
                    'target' => $note->target,
                    'targetuuid' => $note->targetuuid,
                    'fichecode' => $note->fichecode,
                    'slot' => $note->slot,
                    'ficherang' => $note->ficherang,
                    'authorid' => null,
                    'authoruuid' => $note->authoruuid,
                    'text' => $note->text,
                    'textformat' => $note->format,
                    'state' => $note->state,
                    'withdraws' => $note->withdraws,
                    'notehash' => $note->hash,
                    'importedat' => $importedat,
                    'timecreated' => $note->writtenat,
                ]);
                $restored++;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * Whether every withdrawal of a target comes with the annotation it names, so that nothing restored takes back or leaves
     * standing what the archive does not say (D-1b-L, tour 1 of T12).
     *
     * A withdrawal new here takes back an annotation new here in the same batch, or one already restored from an archive
     * under the same author (authoruuid, never null on either side), an ANNOTATION (not a withdrawal), on the SAME target as
     * the withdrawal, and not withdrawn here yet; never an annotation written here by a manager, whatever identifier a forged
     * archive gives it - note_manager would otherwise hold that word for withdrawn, and its author could not take it back. A
     * withdrawal already here while the annotation it names is new would leave that annotation standing against the
     * archive's word. Either case leaves the whole target.
     *
     * @param int $candidateid
     * @param \stdClass[] $notes As dossier_importer::situations() gives them.
     * @param int[] $indexes The lines of the target.
     * @param int[] $fresh The lines of the target whose identifier is not here yet.
     * @return bool
     */
    private static function withdrawals_agree(int $candidateid, array $notes, array $indexes, array $fresh): bool {
        global $DB;

        $new = [];
        foreach ($fresh as $index) {
            $new[(string) $notes[$index]->uuid] = true;
        }
        foreach ($indexes as $index) {
            $note = $notes[$index];
            if ($note->state !== note_manager::WITHDRAWN) {
                continue;
            }
            $namednew = isset($new[(string) $note->withdraws]);
            if (!isset($new[(string) $note->uuid])) {
                if ($namednew) {
                    return false;
                }
                continue;
            }
            if ($namednew) {
                continue;
            }
            $named = $DB->get_record(note_manager::TABLE, ['uuid' => $note->withdraws, 'candidateid' => $candidateid]);
            if (
                !$named || $named->importedat === null || $named->state !== note_manager::ACTIVE
                || $named->authoruuid === null || $note->authoruuid === null
                || (string) $named->authoruuid !== (string) $note->authoruuid
                || self::target_key($named) !== self::target_key($note)
                || $DB->record_exists(note_manager::TABLE, ['candidateid' => $candidateid, 'withdraws' => $named->uuid])
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * The target of a line, as one string: the kind, the situation, the record, the slot, the rank.
     *
     * @param \stdClass $line A row of tool_vae_note, or a line of the plan (the same five fields).
     * @return string
     */
    private static function target_key(\stdClass $line): string {
        return implode("\x1f", [
            (string) $line->target,
            (string) $line->targetuuid,
            (string) $line->fichecode,
            (int) $line->slot,
            (string) $line->ficherang,
        ]);
    }

    /**
     * Write the lines of consent, all or none: a vaeiste that cannot hold them all would otherwise come out full. The bound
     * counts the lines GIVEN, here and in the file, never the withdrawals (T15); and the lines in all, every origin and state,
     * never exceed MAX_CONSENT_LINES - a guard the importer's pairing of each withdrawal with its consent makes unreachable
     * from a file the plugin wrote, kept against raw lines (T15 tour 2).
     *
     * @param int $candidateid
     * @param \stdClass[] $consents As dossier_importer::situations() gives them, in the order of the archive.
     * @param int $importedat
     * @param int $restored Counted up.
     * @param int $left Counted up.
     */
    private static function restore_consents(int $candidateid, array $consents, int $importedat, int &$restored, int &$left): void {
        global $DB;

        if (!$consents) {
            return;
        }
        $lock = positioning_rules::lock($candidateid, consent_manager::LOCK_KEY);
        try {
            $held = $DB->count_records(consent_manager::TABLE, ['candidateid' => $candidateid, 'state' => consent_manager::GIVEN]);
            $all = $DB->count_records(consent_manager::TABLE, ['candidateid' => $candidateid]);
            $fresh = array_filter($consents, fn($line) => !$DB->record_exists(consent_manager::TABLE, ['uuid' => $line->uuid]));
            $opening = count(array_filter($fresh, fn($line) => $line->state === consent_manager::GIVEN));
            if ($held + $opening > self::MAX_CONSENTS || $all + count($fresh) > self::MAX_CONSENT_LINES) {
                $left += count($consents);
                return;
            }
            foreach ($consents as $line) {
                if (!in_array($line, $fresh, true)) {
                    $left++;
                    continue;
                }
                $DB->insert_record(consent_manager::TABLE, (object) [
                    'uuid' => $line->uuid,
                    'candidateid' => $candidateid,
                    'managerid' => null,
                    'manageruuid' => $line->manageruuid,
                    'state' => $line->state,
                    'importedat' => $importedat,
                    'timecreated' => $line->writtenat,
                ]);
                $restored++;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * The tree of a record, read once per record; null when the record is not imported or the core cannot be read.
     *
     * @param array $cache Trees already read, by record code.
     * @param string $fichecode
     * @return \stdClass|null
     */
    private static function tree(array &$cache, string $fichecode): ?\stdClass {
        if (!array_key_exists($fichecode, $cache)) {
            try {
                $cache[$fichecode] = selfpos_tree::for_record($fichecode);
            } catch (\moodle_exception $e) {
                // The competency subsystem is off: no record can be named, the lines are left.
                $cache[$fichecode] = null;
            }
        }

        return $cache[$fichecode];
    }
}
