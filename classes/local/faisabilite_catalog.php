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
 * The sections of the dossier de faisabilité a candidate writes (T-10, lot 1).
 *
 * ONE PLACE says which sections exist, in what order, of what kind and how many times each may be
 * repeated. The pages list from it, the draft manager refuses an address it does not hold, and the
 * document written for the candidate (T-83) will read it again. A section added here appears
 * everywhere; one not added here cannot be written.
 *
 * THE ORDER AND THE NAMES are those of annexe II of the arrêté of 3 July 2025 (candidat
 * accompagné), read on Légifrance on 15 October 2026: certified record aimed at, prerequisites,
 * identity, contact, status, objectives, experiences, experiences reported to each block, planned
 * path. The wording of the labels is this plugin's, not the arrêté's. Which fields are compulsory
 * is NOT recorded: that needs the annex read word for word, and the plugin does not judge a
 * dossier admissible anyway (D-1).
 *
 * KINDS. 'text' is a free narrative, written in a section of tool_vae_draft with its versions
 * (lot 2). 'fields' is a set of separate fields - identity, status - kept in the same tables as
 * a JSON object (lot 3, see faisabilite_fields). 'account' is read from the candidate's account
 * and holds nothing here - the contact details: it has no row and no version.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class faisabilite_catalog {
    /** @var string A free narrative. */
    public const KIND_TEXT = 'text';

    /** @var string A set of separate fields, kept as a JSON object (lot 3). */
    public const KIND_FIELDS = 'fields';

    /** @var string Read from the candidate's account, nothing kept (lot 3). */
    public const KIND_ACCOUNT = 'account';

    /** @var int How many experiences a candidate may describe (decided with the project owner, 15 October 2026). */
    public const MAX_EXPERIENCES = 10;

    /** @var int How many blocks of competencies a candidate may report experiences against. */
    public const MAX_BLOCKS = 12;

    /**
     * The sections, in the order of the annex.
     *
     * `slots` is 0 for a section written once, and the highest slot of a repeated one (slots
     * start at 1). `open` says whether the candidate can write it now.
     *
     * @return array[] Code => ['kind' => string, 'slots' => int, 'open' => bool]
     */
    public static function sections(): array {
        return [
            'certification' => ['kind' => self::KIND_TEXT, 'slots' => 0, 'open' => true],
            'prerequis' => ['kind' => self::KIND_TEXT, 'slots' => 0, 'open' => true],
            'identite' => ['kind' => self::KIND_FIELDS, 'slots' => 0, 'open' => true],
            'contact' => ['kind' => self::KIND_ACCOUNT, 'slots' => 0, 'open' => true],
            'statut' => ['kind' => self::KIND_FIELDS, 'slots' => 0, 'open' => true],
            'objectifs' => ['kind' => self::KIND_TEXT, 'slots' => 0, 'open' => true],
            'experiences' => ['kind' => self::KIND_TEXT, 'slots' => self::MAX_EXPERIENCES, 'open' => true],
            'blocs' => ['kind' => self::KIND_TEXT, 'slots' => self::MAX_BLOCKS, 'open' => true],
            'parcours' => ['kind' => self::KIND_TEXT, 'slots' => 0, 'open' => true],
            // The commitment of the candidate (annexe II, last part). A text section like the others; its
            // confirmation is given with a deposit (T-21, D-12). The opinion of the companion is NOT a
            // section: it is written by someone else, about a deposit.
            'engagement' => ['kind' => self::KIND_TEXT, 'slots' => 0, 'open' => true],
        ];
    }

    /**
     * Whether a section code and a slot name a place in the document.
     *
     * @param string $section
     * @param int $slot
     * @return bool
     */
    public static function valid_address(string $section, int $slot): bool {
        $sections = self::sections();
        if (!isset($sections[$section])) {
            return false;
        }

        $max = $sections[$section]['slots'];

        return $max === 0 ? $slot === 0 : ($slot >= 1 && $slot <= $max);
    }

    /**
     * The name of a place, as the candidate reads it: "Objectives of the approach", "Experiences no. 2".
     *
     * A code the catalogue does not hold gives the code itself: the data of an older version of the
     * plugin is shown, never hidden.
     *
     * @param string $section
     * @param int $slot
     * @return string
     */
    public static function label(string $section, int $slot): string {
        $sections = self::sections();
        if (!isset($sections[$section])) {
            return $section;
        }

        $name = get_string('faisabsection:' . $section, 'tool_vae');

        return $sections[$section]['slots'] > 0
            ? get_string('faisabslot', 'tool_vae', (object) ['section' => $name, 'slot' => $slot])
            : $name;
    }

    /**
     * The sentence that helps write a section, or null when the section has none (fields, lot 3).
     *
     * @param string $section
     * @return string|null
     */
    public static function help(string $section): ?string {
        $key = 'faisabhelp:' . $section;

        return self::is_open($section) && get_string_manager()->string_exists($key, 'tool_vae')
            ? get_string($key, 'tool_vae')
            : null;
    }

    /**
     * The kind of a section, or null when the catalogue does not hold it.
     *
     * @param string $section
     * @return string|null
     */
    public static function kind(string $section): ?string {
        return self::sections()[$section]['kind'] ?? null;
    }

    /**
     * Whether a candidate can write this section now.
     *
     * @param string $section
     * @return bool
     */
    public static function is_open(string $section): bool {
        return self::sections()[$section]['open'] ?? false;
    }
}
