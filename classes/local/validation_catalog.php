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
 * The sections of the preparation of the validation dossier (T-11, lot 1, D-15).
 *
 * NOT THE OFFICIAL DOSSIER. The validation dossier is a document of the certifier, whose layout the certifier fixes
 * (D-2). What this class lists is a GENERIC AID TO WRITING: for each bloc of the record the candidate aims at, three
 * text sections - describe the activities, analyse the practice, make the link with the bloc - and one synthesis for
 * the record. The candidate will carry the text over into the certifier's own layout. Every screen says so.
 *
 * AN ADDRESS IS (record, section). The section code is `<rank>_<part>`: the rank of the bloc in lower case, empty for
 * the whole record, then one of PARTS; `_synthese` is the synthesis. Ranks are letters and digits (importer::
 * RANK_PATTERN) and never contain an underscore, so the code reads one way only. A record without blocs has the three
 * parts once, for the record as a whole (`_decrire`...): never one set per competency, which would give a record of ninety
 * lines two hundred and seventy sections. A record with more than MAX_BLOCS blocs is treated the same way.
 *
 * NOTHING IS JUDGED. This class says where a text can be written, never whether it is written well, long enough or
 * complete (D-1).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class validation_catalog {
    /** @var string[] The three parts written for a bloc, or for the record as a whole when it has none. */
    public const PARTS = ['decrire', 'analyser', 'lier'];

    /** @var string The part written once for the record as a whole. */
    public const SYNTHESIS = 'synthese';

    /** @var int The most blocs a record may have for the preparation to go bloc by bloc (as faisabilite_catalog). */
    public const MAX_BLOCS = 12;

    /** @var array[] Structures already read in this request, by record code. */
    private static $structures = [];

    /**
     * How the preparation of a record is laid out.
     *
     * @param string $fichecode An imported record.
     * @return \stdClass|null Null when the record is not imported. Otherwise: blocs (rank in lower case => rank,
     *         idnumber, label, competencyid, competencyids), byblocs (whether the preparation goes bloc by bloc) and overflow
     *         (whether the record has too many blocs for that).
     */
    public static function structure(string $fichecode): ?\stdClass {
        global $DB;

        if (array_key_exists($fichecode, self::$structures)) {
            return self::$structures[$fichecode];
        }

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        // A database that folds the case would find "rs00000" for "RS00000", and then read no rank of it.
        if (!$source || $source->fichecode !== $fichecode) {
            return self::$structures[$fichecode] = null;
        }

        $blocs = [];
        $listed = (new competency_gateway())->framework_tree((int) $source->frameworkid);
        foreach (outline::group($listed) as $node) {
            if (!$node->children) {
                continue;
            }
            $rank = passage_manager::rank_of((string) $node->competency->idnumber, $fichecode);
            if (!preg_match(importer::RANK_PATTERN, $rank)) {
                continue;
            }
            $blocs[strtolower($rank)] = (object) [
                'rank' => $rank,
                'idnumber' => (string) $node->competency->idnumber,
                'label' => (string) $node->competency->label,
                'competencyid' => (int) $node->competency->id,
                // The bloc and the competencies under it: where the declarations of this bloc are found.
                'competencyids' => array_merge(
                    [(int) $node->competency->id],
                    array_map(fn($child) => (int) $child->id, $node->children)
                ),
            ];
        }

        $overflow = count($blocs) > self::MAX_BLOCS;

        return self::$structures[$fichecode] = (object) [
            'blocs' => $blocs,
            'byblocs' => $blocs && !$overflow,
            'overflow' => $overflow,
        ];
    }

    /**
     * Forget what was read (tests change the records between two calls).
     */
    public static function reset(): void {
        self::$structures = [];
    }

    /**
     * The sections of the preparation of a record, in the order they are written.
     *
     * @param string $fichecode
     * @return array[] Each: section, rank ('' for the record as a whole), part.
     */
    public static function sections(string $fichecode): array {
        $structure = self::structure($fichecode);
        if ($structure === null) {
            return [];
        }

        $out = [];
        if ($structure->byblocs) {
            $ranks = array_keys($structure->blocs);
            sort($ranks, SORT_NATURAL);
            foreach ($ranks as $rank) {
                foreach (self::PARTS as $part) {
                    $out[] = ['section' => $rank . '_' . $part, 'rank' => $rank, 'part' => $part];
                }
            }
        } else {
            foreach (self::PARTS as $part) {
                $out[] = ['section' => '_' . $part, 'rank' => '', 'part' => $part];
            }
        }
        $out[] = ['section' => '_' . self::SYNTHESIS, 'rank' => '', 'part' => self::SYNTHESIS];

        return $out;
    }

    /**
     * What a section code says.
     *
     * @param string $section
     * @return array|null [rank in lower case ('' for the record as a whole), part]; null when it is not of the shape.
     */
    public static function parse(string $section): ?array {
        if (!preg_match('/^([a-z0-9]{0,8})_([a-z]+)$/D', $section, $matches)) {
            return null;
        }
        [, $rank, $part] = $matches;
        if (!in_array($part, array_merge(self::PARTS, [self::SYNTHESIS]), true)) {
            return null;
        }
        if ($part === self::SYNTHESIS && $rank !== '') {
            return null;
        }

        return [$rank, $part];
    }

    /**
     * Whether a place can be written.
     *
     * @param string $fichecode
     * @param string $section
     * @param int $slot
     * @return bool
     */
    public static function valid_address(string $fichecode, string $section, int $slot): bool {
        $parsed = self::parse($section);
        $structure = self::structure($fichecode);
        if ($slot !== 0 || $parsed === null || $structure === null) {
            return false;
        }
        [$rank, $part] = $parsed;
        if ($part === self::SYNTHESIS) {
            return true;
        }

        return $structure->byblocs ? isset($structure->blocs[$rank]) : $rank === '';
    }

    /**
     * The name of a place, as the candidate reads it: "BC01 - Describe my activities".
     *
     * A place the record no longer holds - a bloc withdrawn from it - is named by its rank, with the words that say it
     * has gone: what was written is shown, never hidden.
     *
     * @param string $fichecode
     * @param string $section
     * @return string
     */
    public static function label(string $fichecode, string $section): string {
        $parsed = self::parse($section);
        if ($parsed === null) {
            return $section;
        }
        [$rank, $part] = $parsed;

        $name = get_string('validpart:' . $part, 'tool_vae');
        if ($rank === '') {
            return $name;
        }

        $structure = self::structure($fichecode);
        $upper = strtoupper($rank);
        if ($structure !== null && isset($structure->blocs[$rank])) {
            return get_string('validblocline', 'tool_vae', (object) [
                'bloc' => $upper . ' - ' . $structure->blocs[$rank]->label,
                'part' => $name,
            ]);
        }

        return get_string('validblocgoneline', 'tool_vae', (object) ['bloc' => $upper, 'part' => $name]);
    }

    /**
     * The help text of a part.
     *
     * @param string $section
     * @return string|null
     */
    public static function help(string $section): ?string {
        $parsed = self::parse($section);

        return $parsed === null ? null : get_string('validpart:' . $parsed[1] . '_help', 'tool_vae');
    }

    /**
     * Whether a part describes a situation of work, so that the anonymisation advice is given with it.
     *
     * @param string $section
     * @return bool
     */
    public static function describes_situations(string $section): bool {
        $parsed = self::parse($section);

        return $parsed !== null && in_array($parsed[1], ['decrire', 'analyser'], true);
    }
}
