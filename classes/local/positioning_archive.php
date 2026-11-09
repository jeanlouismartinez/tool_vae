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
 * The positionings of the structure in the archive of the dossier, and back (T-90, lot 3).
 *
 * THE POSITIONING OF THE STRUCTURE, NEVER THE VALIDATION. positionnement.json holds every line of evaluation (a competency, by
 * the manager who follows) and of certification (a bloc, by the direction), the current state AND the history: each line says
 * whether it is the one that stands. The file says in its first lines that it is not a validation - only the jury validates,
 * outside this platform - and `officiel` is always false.
 *
 * NO USER, NO RIGHT. The document is read by the backup, the uninstall, the refresh after an erasure and the scheduled copy,
 * which run with nobody logged in: it asks the database and the core's reading of the record, never has_capability() or $USER.
 * Who may carry the file out of the platform is the business of the archive that holds it. An author erased from the platform
 * has no identifier in it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class positioning_archive {
    /** @var string The file, in the archive. */
    public const PATH = 'positionnement.json';

    /** @var string The version of the schema of the file. */
    public const SCHEMA_VERSION = '0.1.0';

    /** @var int How many lines the file may hold, evaluations and certifications together. The importer holds the bound. */
    public const MAX_LINES = 5000;

    /** @var string[] How the status of an evaluation is written in the archive. */
    public const EVAL_STATUS = [
        'toconstruct' => 'a_construire',
        'inproof' => 'en_cours_de_preuve',
        'sufficient' => 'suffisante_pour_certification',
        'withdrawn' => 'retiree',
    ];

    /** @var string[] How the status of a certification is written in the archive. */
    public const CERTIF_STATUS = [
        'certified' => 'certifie',
        'withdrawn' => 'retire',
    ];

    /**
     * The document of the file, or null when nothing was ever recorded about the candidate.
     *
     * @param int $candidateid
     * @return array|null See positionnement.schema.json.
     */
    public static function document(int $candidateid): ?array {
        global $DB;

        $evaluations = $DB->get_records('tool_vae_eval', ['candidateid' => $candidateid], 'timecreated ASC, id ASC');
        $certifications = $DB->get_records('tool_vae_certif', ['candidateid' => $candidateid], 'timecreated ASC, id ASC');
        if (!$evaluations && !$certifications) {
            return null;
        }

        // The authors whose account is deleted are gone as the screen counts them: no identifier is minted for them.
        $authorids = array_filter(array_unique(array_merge(
            array_map(fn($row) => (int) $row->authorid, $evaluations),
            array_map(fn($row) => (int) $row->authorid, $certifications)
        )));
        $gone = [];
        if ($authorids) {
            [$insql, $inparams] = $DB->get_in_or_equal($authorids, SQL_PARAMS_NAMED);
            $present = $DB->get_fieldset_select('user', 'id', "id $insql AND deleted = 0", $inparams);
            $gone = array_diff($authorids, array_map('intval', $present));
        }

        $labels = [];
        $lines = function (array $rows, array $statuses, bool $certif) use (&$labels, $gone): array {
            // The line that stands is the last of its key, in the order the rows were read.
            $standing = [];
            foreach ($rows as $row) {
                $standing[$row->fichecode . "\x1f" . $row->ficherang] = (int) $row->id;
            }
            $out = [];
            foreach ($rows as $row) {
                $entry = [
                    'identifiant' => $row->uuid,
                    'fiche' => $row->fichecode,
                    'rang' => (string) $row->ficherang,
                ];
                $label = self::label($labels, (string) $row->fichecode, (string) $row->ficherang, $certif);
                if ($label !== '') {
                    $entry['intitule'] = \core_text::substr($label, 0, 1000);
                }
                $entry['statut'] = $statuses[$row->status];
                if ($certif) {
                    $entry['date_decision'] = (string) $row->decisiondate;
                }
                $entry['motif'] = $row->reason;
                if ($certif && $row->alertreason !== null && $row->alertreason !== '') {
                    $entry['motif_alerte'] = $row->alertreason;
                }
                if ($certif && !empty($row->validuntil)) {
                    $entry['valable_jusqu_au'] = (string) $row->validuntil;
                }
                $entry['empreinte'] = $row->reasonhash;
                $entry['ecrit_le'] = exporter::iso((int) $row->timecreated);
                $entry['fait_foi'] = $standing[$row->fichecode . "\x1f" . $row->ficherang] === (int) $row->id;
                $author = self::author($row, $gone);
                if ($author !== null) {
                    $entry['auteur'] = $author;
                }
                $out[] = $entry;
            }

            return $out;
        };

        return [
            'avertissement' => get_string('positioningarchivesentence', 'tool_vae'),
            'officiel' => false,
            'evaluations' => $lines($evaluations, self::EVAL_STATUS, false),
            'certifications' => $lines($certifications, self::CERTIF_STATUS, true),
        ];
    }

    /**
     * Bring the lines of an archive back, key by key, all or nothing (T-90, lot 3).
     *
     * A line is added to a table and never changed, so a restoration adds: a key (record, rank) of a candidate that already holds
     * a line here is left as it is - the history of this site is not interleaved with another's - and so is a key whose record or
     * rank this site does not hold in the shape the line needs (a competency for an evaluation, a bloc for a certification), and
     * a line whose identifier is already in the table. The lines of a key go in, in the order of the archive, with the author
     * named by the ULID the archive gives: they hold no account here, so authorid stays empty, and the line says when it came.
     *
     * @param int $candidateid The candidate the archive is imported for.
     * @param \stdClass[] $lines As dossier_importer::positioning() gives them, in the order of the archive.
     * @param int $importedat When.
     * @return int[] [lines written, lines left].
     */
    public static function restore(int $candidateid, array $lines, int $importedat): array {
        global $DB;

        $groups = [];
        foreach ($lines as $line) {
            $groups[$line->table . "\x1f" . $line->fichecode . "\x1f" . $line->ficherang][] = $line;
        }

        $layouts = [];
        $written = 0;
        $left = 0;
        foreach ($groups as $group) {
            $first = $group[0];
            if (!array_key_exists($first->fichecode, $layouts)) {
                try {
                    $layouts[$first->fichecode] = positioning_rules::layout($first->fichecode);
                } catch (\moodle_exception $e) {
                    $layouts[$first->fichecode] = null;
                }
            }
            $layout = $layouts[$first->fichecode];
            $known = $layout !== null && ($first->table === certification_manager::TABLE
                ? isset($layout->blocs[$first->ficherang])
                : isset($layout->leaves[$first->ficherang]));
            if (!$known) {
                $left += count($group);
                continue;
            }

            // The lock every other write on this candidate and this record takes: a line written here at this very moment
            // cannot slip in between the check of the key and the insertion.
            $lock = positioning_rules::lock($candidateid, $first->fichecode);
            try {
                $here = $DB->record_exists($first->table, [
                    'candidateid' => $candidateid, 'fichecode' => $first->fichecode, 'ficherang' => $first->ficherang,
                ]);
                $twice = false;
                foreach ($group as $line) {
                    $twice = $twice || $DB->record_exists('tool_vae_eval', ['uuid' => $line->uuid])
                        || $DB->record_exists('tool_vae_certif', ['uuid' => $line->uuid]);
                }
                if ($here || $twice) {
                    $left += count($group);
                    continue;
                }
                foreach ($group as $line) {
                    self::insert_restored($candidateid, $line, $importedat);
                    $written++;
                }
            } finally {
                $lock->release();
            }
        }

        return [$written, $left];
    }

    /**
     * Write one restored line.
     *
     * @param int $candidateid
     * @param \stdClass $line As dossier_importer::positioning() gives it.
     * @param int $importedat
     */
    private static function insert_restored(int $candidateid, \stdClass $line, int $importedat): void {
        global $DB;

        $row = (object) [
            'uuid' => $line->uuid,
            'candidateid' => $candidateid,
            'fichecode' => $line->fichecode,
            'ficherang' => $line->ficherang,
            'status' => $line->status,
            'reason' => $line->reason,
            'reasonhash' => $line->hash,
            'authorid' => null,
            'authoruuid' => $line->authoruuid,
            'importedat' => $importedat,
            'timecreated' => $line->writtenat,
        ];
        if ($line->table === certification_manager::TABLE) {
            $row->decisiondate = $line->decisiondate;
            $row->alertreason = $line->alertreason;
            $row->validuntil = $line->validuntil === '' ? null : $line->validuntil;
        }
        $DB->insert_record($line->table, $row);
    }

    /**
     * The lines of a document as plain text, for a reader of the editable copy: where it stands, when, why.
     *
     * @param array $positioning As document() builds it.
     * @return string[] One text per line of evaluation and of certification, the earliest first, the line that stands marked.
     */
    public static function reading_lines(array $positioning): array {
        $kinds = [
            'evaluations' => ['eval', array_flip(self::EVAL_STATUS)],
            'certifications' => ['certif', array_flip(self::CERTIF_STATUS)],
        ];
        $out = [];
        foreach ($kinds as $key => [$prefix, $statuses]) {
            foreach ($positioning[$key] ?? [] as $line) {
                $title = $line['fiche'] . ($line['rang'] === '' ? '' : '-' . $line['rang'])
                    . (isset($line['intitule']) ? ' - ' . $line['intitule'] : '');
                $when = isset($line['date_decision']) ? ' (' . $line['date_decision'] . ')' : '';
                if (isset($line['valable_jusqu_au'])) {
                    $when .= ' - ' . get_string('positioninguntil', 'tool_vae', $line['valable_jusqu_au']);
                }
                $text = $title . ' : ' . get_string($prefix . 'status' . $statuses[$line['statut']], 'tool_vae') . $when
                    . ' - ' . $line['ecrit_le'] . ' - '
                    . get_string($line['fait_foi'] ? 'positioningpdfstands' : 'positioningpdfearlier', 'tool_vae')
                    . ' - ' . $line['motif'];
                if (isset($line['motif_alerte'])) {
                    $text .= ' - ' . $line['motif_alerte'];
                }
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * The opaque identifier of the author of a line, or null when the author is gone.
     *
     * A line restored from an archive names its author by the ULID the archive gave; one written here by the identifier this
     * site gave the account. An author erased from the platform has neither.
     *
     * @param \stdClass $row
     * @param int[] $gone The accounts that are deleted.
     * @return string|null
     */
    private static function author(\stdClass $row, array $gone = []): ?string {
        if (!empty($row->authorid)) {
            return in_array((int) $row->authorid, $gone, true)
                ? null
                : identity_map::uuid_for(identity_map::TYPE_USER, (int) $row->authorid);
        }

        return empty($row->authoruuid) ? null : (string) $row->authoruuid;
    }

    /**
     * The published label of a competency or a bloc, for a reader; empty when the record or the core is not there.
     *
     * Read as the core reads the record, with no user and no right: the label is the certifier's, public in the record.
     *
     * @param array $cache Layouts already read, by record code.
     * @param string $fichecode
     * @param string $ficherang
     * @param bool $bloc Whether the rank is that of a bloc.
     * @return string
     */
    private static function label(array &$cache, string $fichecode, string $ficherang, bool $bloc): string {
        if (!array_key_exists($fichecode, $cache)) {
            try {
                $cache[$fichecode] = positioning_rules::layout($fichecode);
            } catch (\moodle_exception $e) {
                // The competency subsystem is off, as it can be during an uninstall: no label, the file stays whole.
                $cache[$fichecode] = null;
            }
        }
        $layout = $cache[$fichecode];
        if ($layout === null) {
            return '';
        }
        if ($bloc) {
            return $ficherang === '' ? '' : (string) ($layout->blocs[$ficherang]->label ?? '');
        }

        return (string) ($layout->leaves[$ficherang]->label ?? '');
    }
}
