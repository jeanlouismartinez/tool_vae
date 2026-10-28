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
 * The separate fields of the dossier de faisabilité - identity, status - and what is read from the
 * account (T-10, lot 3).
 *
 * WHERE THE FIELDS COME FROM. Annexe II of the arrêté of 3 July 2025 (candidat accompagné),
 * rubriques 3 to 5, as read on Légifrance on 15 October 2026 through a page reader, NOT copied
 * from the raw text: the lists of choices below are to be compared with the Journal officiel
 * before any production use. The asterisks of the annex are kept as `required`: they say what the
 * OFFICIAL form demands, and nothing here refuses to save a partial section - a candidate fills
 * the section over several sittings, and the plugin does not judge a dossier complete (D-1).
 *
 * WHAT IS HELD AND WHAT IS READ (MC-10, decided 15 October 2026). The plugin keeps what the
 * account does not have: birth name, date of birth, nationality, the two levels and the titles of
 * the certifications held, the status and the collective agreement. What the account has - the
 * first names, the surname in use, the e-mail, the address, the town, the country, the telephone -
 * is READ when a page or a copy of the document is built, never copied. It is therefore not
 * versioned: a changed profile shows the value of the day.
 *
 * NOT TAKEN: the gender and the "complément d'adresse" as a separate field. The annex makes the
 * gender optional and MC-10 leaves it out until a use justifies it. The account has one address
 * field; whoever needs the postal code and a complement writes them in it.
 *
 * STORAGE. One JSON object per version, keys in the order below, empty values left out, so that
 * saving the same values twice is the same text and creates no version. A date is Y-m-d, a country
 * its ISO code, a choice its code, several choices a list of codes.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class faisabilite_fields {
    /** @var string The status values of rubrique 5, as codes. */
    public const STATUSES = [
        'salarie_prive', 'agent_public', 'benevole', 'aidant_familial', 'aidant_familial_agricole',
        'demandeur_emploi', 'non_salarie', 'retraite', 'mandat_electif',
    ];

    /** @var int Longest short text. */
    public const TEXT_MAX = 100;

    /** @var int Longest long text. */
    public const LONGTEXT_MAX = 2000;

    /**
     * The fields held by the plugin, per section, in order.
     *
     * `type` is text, longtext, date, country, select or multi; `required` is the annex's asterisk;
     * `options` lists the codes of a select or a multi.
     *
     * @return array[] Section => list of ['key', 'type', 'required', 'options'?]
     */
    public static function definitions(): array {
        return [
            'identite' => [
                ['key' => 'nom_naissance', 'type' => 'text', 'required' => true],
                ['key' => 'date_naissance', 'type' => 'date', 'required' => true],
                ['key' => 'nationalite', 'type' => 'country', 'required' => true],
                [
                    'key' => 'niveau_formation', 'type' => 'select', 'required' => true,
                    'options' => ['2', '3', '4', '5', '6', '7', '8'],
                ],
                [
                    'key' => 'niveau_certification', 'type' => 'select', 'required' => true,
                    'options' => ['3', '4', '5', '6', '7', '8'],
                ],
                ['key' => 'certifications', 'type' => 'longtext', 'required' => true],
            ],
            'statut' => [
                ['key' => 'statut', 'type' => 'multi', 'required' => true, 'options' => self::STATUSES],
                ['key' => 'convention', 'type' => 'text', 'required' => true],
            ],
        ];
    }

    /**
     * What is read from the account for a section, as [key => account property or computed].
     *
     * @return string[][] Section => list of keys, in order
     */
    public static function account_keys(): array {
        return [
            'identite' => ['prenoms', 'nom_usage'],
            'contact' => ['email', 'address', 'city', 'country', 'phone'],
        ];
    }

    /**
     * Whether a section holds separate fields.
     *
     * @param string $section
     * @return bool
     */
    public static function has_fields(string $section): bool {
        return isset(self::definitions()[$section]);
    }

    /**
     * The fields of a section.
     *
     * @param string $section
     * @return array[]
     */
    public static function fields(string $section): array {
        return self::definitions()[$section] ?? [];
    }

    /**
     * The values read from the account, as [key => text], empty ones left out.
     *
     * @param string $section identite or contact
     * @param \stdClass $user
     * @return string[]
     */
    public static function account_values(string $section, \stdClass $user): array {
        $countries = get_string_manager()->get_list_of_countries();
        $values = [];
        foreach (self::account_keys()[$section] ?? [] as $key) {
            switch ($key) {
                case 'prenoms':
                    $value = trim((string) $user->firstname . ' ' . (string) ($user->middlename ?? ''));
                    break;
                case 'nom_usage':
                    $value = (string) $user->lastname;
                    break;
                case 'country':
                    $value = $countries[$user->country] ?? '';
                    break;
                case 'phone':
                    $value = trim((string) ($user->phone2 ?: $user->phone1));
                    break;
                default:
                    $value = trim((string) ($user->{$key} ?? ''));
            }
            if ($value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * The canonical values from what a form or a decoded JSON gave.
     *
     * Unknown keys are dropped, texts trimmed, empty values left out; a value that is not valid
     * for its field is an error, not a silent clean-up.
     *
     * @param string $section
     * @param array $input
     * @return array The values, keys in the order of the definition.
     * @throws \moodle_exception errordraftfields
     */
    public static function clean(string $section, array $input): array {
        $countries = get_string_manager()->get_list_of_countries();
        $clean = [];
        foreach (self::fields($section) as $field) {
            $key = $field['key'];
            $value = $input[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            switch ($field['type']) {
                case 'text':
                case 'longtext':
                    if (!is_string($value)) {
                        throw new \moodle_exception('errordraftfields', 'tool_vae');
                    }
                    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
                    $max = $field['type'] === 'text' ? self::TEXT_MAX : self::LONGTEXT_MAX;
                    if ($value === '') {
                        continue 2;
                    }
                    if (\core_text::strlen($value) > $max || ($field['type'] === 'text' && str_contains($value, "\n"))) {
                        throw new \moodle_exception('errordraftfields', 'tool_vae');
                    }
                    break;
                case 'date':
                    $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                    if (
                        !$date || $date->format('Y-m-d') !== $value
                            || (int) $date->format('Y') < 1900 || $date > new \DateTimeImmutable('today')
                    ) {
                        throw new \moodle_exception('errordraftfields', 'tool_vae');
                    }
                    break;
                case 'country':
                    if (!is_string($value) || !isset($countries[$value])) {
                        throw new \moodle_exception('errordraftfields', 'tool_vae');
                    }
                    break;
                case 'select':
                    $value = (string) $value;
                    if (!in_array($value, $field['options'], true)) {
                        throw new \moodle_exception('errordraftfields', 'tool_vae');
                    }
                    break;
                case 'multi':
                    if (!is_array($value)) {
                        throw new \moodle_exception('errordraftfields', 'tool_vae');
                    }
                    $kept = [];
                    foreach ($field['options'] as $option) {
                        if (in_array($option, $value, true)) {
                            $kept[] = $option;
                        }
                    }
                    if (count($kept) !== count(array_unique($value))) {
                        throw new \moodle_exception('errordraftfields', 'tool_vae');
                    }
                    $value = $kept;
                    break;
            }
            $clean[$key] = $value;
        }

        // A key the section does not have is refused, not ignored: a content from elsewhere is wrong.
        $known = array_column(self::fields($section), 'key');
        foreach (array_keys($input) as $key) {
            if (!in_array($key, $known, true)) {
                throw new \moodle_exception('errordraftfields', 'tool_vae');
            }
        }

        return $clean;
    }

    /**
     * The stored text of values.
     *
     * @param string $section
     * @param array $values
     * @return string JSON.
     */
    public static function encode(string $section, array $values): string {
        $json = json_encode(self::clean($section, $values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === '[]' ? '{}' : $json;
    }

    /**
     * The values of a stored text.
     *
     * @param string $section
     * @param string $content
     * @return array
     * @throws \moodle_exception errordraftfields
     */
    public static function decode(string $section, string $content): array {
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new \moodle_exception('errordraftfields', 'tool_vae');
        }

        return self::clean($section, $data);
    }

    /**
     * Values as lines of "label: value", the way a reader and the comparison see them.
     *
     * @param string $section
     * @param array $values
     * @return string
     */
    public static function readable_values(string $section, array $values): string {
        $countries = get_string_manager()->get_list_of_countries();
        $lines = [];
        foreach (self::fields($section) as $field) {
            $key = $field['key'];
            if (!isset($values[$key])) {
                continue;
            }
            $value = $values[$key];
            switch ($field['type']) {
                case 'date':
                    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    $value = display_date::format($date->getTimestamp(), get_string('strftimedate', 'langconfig'));
                    break;
                case 'country':
                    $value = $countries[$value] ?? $value;
                    break;
                case 'select':
                    $value = get_string('faisabfieldlevel', 'tool_vae', $value);
                    break;
                case 'multi':
                    $value = implode(', ', array_map(function ($option) use ($key) {
                        return get_string('faisabopt:' . $key . '_' . $option, 'tool_vae');
                    }, $value));
                    break;
            }
            $lines[] = get_string('faisabfield:' . $key, 'tool_vae') . ' : ' . $value;
        }

        return implode("\n", $lines);
    }

    /**
     * What a stored text of a draft says, for a reader or the comparison.
     *
     * A section of free text is returned as it is. A section of fields is decoded and set as lines;
     * a text that cannot be decoded - it cannot be stored, but an old row is not trusted - is
     * returned as it is, so that a page shows it rather than failing.
     *
     * @param string $section
     * @param string $content
     * @return string
     */
    public static function readable(string $section, string $content): string {
        if (!self::has_fields($section)) {
            return $content;
        }

        try {
            return self::readable_values($section, self::decode($section, $content));
        } catch (\moodle_exception $e) {
            return $content;
        }
    }
}
