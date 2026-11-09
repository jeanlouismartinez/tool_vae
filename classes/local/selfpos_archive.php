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
 * The self-positioning of the vaeiste in the archive of the dossier, and back (lot 1a).
 *
 * THE POSITIONING OF THE VAEISTE, NEVER A VALIDATION. autopositionnement.json holds every line the vaeiste wrote about
 * themselves on the nodes of their records, the current state AND the history: each line says whether it is the one that
 * stands. The file says in its first lines what it is, and `officiel` is always false. It is DISTINCT from
 * positionnement.json, the word of the structure, which goes in other archives.
 *
 * NO USER, NO RIGHT. The document is read by the backup, the uninstall, the refresh after an erasure and the scheduled copy,
 * which run with nobody logged in: it asks the database and the core's reading of the record, never has_capability() nor
 * $USER. Who may carry the file out of the platform is the business of the archive that holds it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selfpos_archive {
    /** @var string The file, in the archive. */
    public const PATH = 'autopositionnement.json';

    /** @var string The version of the schema of the file. */
    public const SCHEMA_VERSION = '0.1.0';

    /** @var int How many lines the file may hold. The importer holds the bound. */
    public const MAX_LINES = 20000;

    /** @var string[] How the format of a justification is written in the archive, by the core's format number. */
    public const FORMATS = [2 => 'texte', 4 => 'markdown'];

    /**
     * The document of the file, or null when the vaeiste never wrote a line.
     *
     * @param int $candidateid
     * @return array|null See autopositionnement.schema.json.
     */
    public static function document(int $candidateid): ?array {
        global $DB;

        $rows = $DB->get_records(selfpos_manager::TABLE, ['candidateid' => $candidateid], 'timecreated ASC, id ASC');
        if (!$rows) {
            return null;
        }

        // The line that stands is the last of its key, in the order the rows were read.
        $standing = [];
        foreach ($rows as $row) {
            $standing[$row->fichecode . "\x1f" . $row->ficherang] = (int) $row->id;
        }
        $trees = [];
        $lines = [];
        foreach ($rows as $row) {
            $entry = [
                'identifiant' => $row->uuid,
                'fiche' => $row->fichecode,
                'rang' => (string) $row->ficherang,
            ];
            $label = self::label($trees, (string) $row->fichecode, (string) $row->ficherang);
            if ($label !== '') {
                $entry['intitule'] = \core_text::substr($label, 0, 1000);
            }
            $entry['palier'] = (int) $row->level;
            $entry['libelle'] = (string) $row->levellabel;
            $entry['justification'] = (string) $row->justification;
            $entry['format'] = self::FORMATS[(int) $row->justificationformat] ?? 'texte';
            $entry['empreinte'] = $row->linehash;
            $entry['ecrit_le'] = exporter::iso((int) $row->timecreated);
            $entry['fait_foi'] = $standing[$row->fichecode . "\x1f" . $row->ficherang] === (int) $row->id;
            $lines[] = $entry;
        }

        return [
            'avertissement' => get_string('selfposarchivesentence', 'tool_vae'),
            'officiel' => false,
            'lignes' => $lines,
        ];
    }

    /**
     * Bring the lines of an archive back, line by line (lot 1a).
     *
     * A line is added and never changed, so a restoration adds. A line is LEFT, not written, when its identifier is already in
     * the table (a restoration never writes a row twice), when its record is not imported here or its rank is no positionable
     * node of it, or when its node already holds as many lines as one node may. The lines of a key go in under the lock every
     * other write on this vaeiste and this record takes, in the order of the archive, dated as they were written, and saying
     * when they came.
     *
     * @param int $candidateid The vaeiste the archive is imported for.
     * @param \stdClass[] $lines As dossier_importer::selfpositioning() gives them: uuid, fichecode, ficherang, level,
     *        levellabel, justification, format, hash, writtenat; in the order of the archive.
     * @param int $importedat When.
     * @return int[] [lines written, lines left].
     */
    public static function restore(int $candidateid, array $lines, int $importedat): array {
        global $DB;

        $groups = [];
        foreach ($lines as $line) {
            $groups[$line->fichecode . "\x1f" . $line->ficherang][] = $line;
        }

        $trees = [];
        $written = 0;
        $left = 0;
        foreach ($groups as $group) {
            $first = $group[0];
            $node = self::node($trees, (string) $first->fichecode, (string) $first->ficherang);
            if ($node === null || !$node->positionable) {
                $left += count($group);
                continue;
            }

            $lock = positioning_rules::lock($candidateid, $first->fichecode);
            try {
                $held = count(positioning_rules::history(
                    selfpos_manager::TABLE,
                    $candidateid,
                    (string) $first->fichecode,
                    (string) $first->ficherang
                ));
                // Every line of the key, or none: a node that cannot hold them all would otherwise keep, as the line that
                // stands, one the archive says was followed by another.
                if ($held + count($group) > selfpos_manager::MAX_PER_NODE) {
                    $left += count($group);
                    continue;
                }
                foreach ($group as $line) {
                    $twice = $DB->record_exists(selfpos_manager::TABLE, ['uuid' => $line->uuid]);
                    if ($held >= selfpos_manager::MAX_PER_NODE || $twice) {
                        $left++;
                        continue;
                    }
                    $DB->insert_record(selfpos_manager::TABLE, (object) [
                        'uuid' => $line->uuid,
                        'candidateid' => $candidateid,
                        'fichecode' => $line->fichecode,
                        'ficherang' => $line->ficherang,
                        'level' => (int) $line->level,
                        'levellabel' => \core_text::substr((string) $line->levellabel, 0, selfpos_scale::LABEL_LENGTH),
                        'justification' => (string) $line->justification,
                        'justificationformat' => (int) $line->format,
                        'linehash' => $line->hash,
                        'importedat' => $importedat,
                        'timecreated' => (int) $line->writtenat,
                    ]);
                    $held++;
                    $written++;
                }
            } finally {
                $lock->release();
            }
        }

        return [$written, $left];
    }

    /**
     * The published label of a node, for a reader; empty for the record itself and when the record or the core is not there.
     *
     * @param array $cache Trees already read, by record code.
     * @param string $fichecode
     * @param string $ficherang
     * @return string
     */
    private static function label(array &$cache, string $fichecode, string $ficherang): string {
        if ($ficherang === '') {
            return '';
        }
        $node = self::node($cache, $fichecode, $ficherang);

        return $node === null ? '' : (string) $node->label;
    }

    /**
     * A node of a record, the tree read once per record; null when the record cannot be read or has no such rank.
     *
     * @param array $cache Trees already read, by record code (null for a record that cannot be read).
     * @param string $fichecode
     * @param string $ficherang
     * @return \stdClass|null
     */
    private static function node(array &$cache, string $fichecode, string $ficherang): ?\stdClass {
        if (!array_key_exists($fichecode, $cache)) {
            try {
                $cache[$fichecode] = selfpos_tree::for_record($fichecode);
            } catch (\moodle_exception $e) {
                // The competency subsystem is off, as it can be during an uninstall: no node, the file stays whole.
                $cache[$fichecode] = null;
            }
        }

        return $cache[$fichecode] === null ? null : selfpos_tree::node($cache[$fichecode], $ficherang);
    }
}
