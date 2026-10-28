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

use tool_vae\event\ref_added;
use tool_vae\event\ref_removed;

/**
 * Where the certifier publishes its rule of validation: the addresses an administrator gives for a record (T-41).
 *
 * AN ADDRESS, NEVER A RULE. The plugin does not copy the text of a rule, does not read it and does not apply it: it points
 * at the page that publishes it (D-1, D-4). The export of the national register carries no rule of validation for the VAE
 * (checked on 22 October 2026), and a copied sentence such as "validation of all the blocs" would read as the rule of the VAE
 * when nothing establishes that it is. An address cannot go out of date in a dossier, only on the page it names, and the
 * administrator says the day they checked it.
 *
 * Nothing here is computed from the addresses: the plugin never visits one (no request goes to these addresses; the one
 * request the plugin makes by itself, when an administrator clicks, is the fetch of the export of France compétences from
 * data.gouv.fr - the other requests that leave the server, the mails and the calls of the AI subsystem, are the core's), and
 * the click on an address is the reader's own. The table holds no data about a person; the event says who acted.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ref_manager {
    /** @var int Addresses a record can carry: two or three are the usual, and a list is not a library. */
    public const MAX_PER_FICHE = 5;

    /** @var int The longest title, the width of the column. */
    public const TITLE_MAXLENGTH = 60;

    /**
     * Give an address for a record.
     *
     * @param string $fichecode The imported record.
     * @param string $title Short title, shown as the text of the link.
     * @param string $url An http or https address.
     * @param int|null $checkedat The day the administrator checked the page, or null.
     * @return array [the row, language string identifiers of the warnings about the address]
     * @throws \moodle_exception errorrefunknownfiche, errorrefbusy, errorreftitle, errorpieceurl, errorrefdate,
     *         errorrefduplicate, errorreflimit
     */
    public static function add(string $fichecode, string $title, string $url, ?int $checkedat = null): array {
        global $DB;

        require_capability('tool/vae:import', \context_system::instance());

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        if (!$source) {
            throw new \moodle_exception('errorrefunknownfiche', 'tool_vae');
        }

        // No control character, and no format character either (a right-to-left override would turn the title round).
        $title = trim($title);
        if (
            $title === '' || \core_text::strlen($title) > self::TITLE_MAXLENGTH
            || preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $title)
        ) {
            throw new \moodle_exception('errorreftitle', 'tool_vae', '', self::TITLE_MAXLENGTH);
        }
        [$url, $warnings] = piece_manager::validate_url($url);
        // The name of the host does not tell capitals apart, the rest of the address does: lower-case the host only, so that
        // the same page cannot be given twice under two spellings of its host (the path is left as it is).
        $url = preg_replace_callback('~^(https?://)([^/?#]*)~', fn($m) => $m[1] . strtolower($m[2]), $url);

        // A day, not a moment, and not one that has not come yet: the date says "I checked", and nobody checks tomorrow.
        if ($checkedat !== null && ($checkedat <= 0 || $checkedat > time() + DAYSECS)) {
            throw new \moodle_exception('errorrefdate', 'tool_vae');
        }

        // The count, the check and the insert under one lock: two submits at once (a double click) neither pass the check
        // together nor go over the limit.
        $lock = \core\lock\lock_config::get_lock_factory('tool_vae_ref')->get_lock('source' . $source->id, 10);
        if (!$lock) {
            throw new \moodle_exception('errorrefbusy', 'tool_vae');
        }
        try {
            if ($DB->record_exists('tool_vae_ref', ['sourceid' => $source->id, 'url' => $url])) {
                throw new \moodle_exception('errorrefduplicate', 'tool_vae');
            }
            if ($DB->count_records('tool_vae_ref', ['sourceid' => $source->id]) >= self::MAX_PER_FICHE) {
                throw new \moodle_exception('errorreflimit', 'tool_vae', '', self::MAX_PER_FICHE);
            }

            $row = (object) [
                'sourceid' => (int) $source->id,
                'title' => $title,
                'url' => $url,
                'checkedat' => $checkedat,
                'timecreated' => time(),
            ];
            try {
                $row->id = $DB->insert_record('tool_vae_ref', $row);
            } catch (\dml_write_exception $e) {
                // The unique index, when the database reads two addresses as one (a collation that ignores case).
                throw new \moodle_exception('errorrefduplicate', 'tool_vae');
            }
        } finally {
            $lock->release();
        }
        self::trigger($source, 'added', (int) $row->id);

        return [$row, $warnings];
    }

    /**
     * Take an address back.
     *
     * @param int $id
     * @throws \moodle_exception errorrefunknown
     */
    public static function remove(int $id): void {
        global $DB;

        require_capability('tool/vae:import', \context_system::instance());

        $row = $DB->get_record('tool_vae_ref', ['id' => $id]);
        if (!$row) {
            throw new \moodle_exception('errorrefunknown', 'tool_vae');
        }
        $source = $DB->get_record('tool_vae_source', ['id' => $row->sourceid], '*', MUST_EXIST);

        $DB->delete_records('tool_vae_ref', ['id' => $id]);
        self::trigger($source, 'removed', $id);
    }

    /**
     * The addresses of some records, for a screen that shows them. No capability test: the addresses are public.
     *
     * @param string[] $fichecodes Record codes.
     * @return array[] Keyed by record code, each a list in the order they were given of objects with id, title, url,
     *         checkedat (int or null). A record with none has no key.
     */
    public static function for_fiches(array $fichecodes): array {
        global $DB;

        if (!$fichecodes) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($fichecodes), SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql(
            "SELECT r.id, r.title, r.url, r.checkedat, s.fichecode
               FROM {tool_vae_ref} r
               JOIN {tool_vae_source} s ON s.id = r.sourceid
              WHERE s.fichecode $insql
           ORDER BY s.fichecode ASC, r.id ASC",
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row->fichecode][] = (object) [
                'id' => (int) $row->id,
                'title' => $row->title,
                'url' => $row->url,
                'checkedat' => $row->checkedat === null ? null : (int) $row->checkedat,
            ];
        }

        return $out;
    }

    /**
     * The addresses of a record as one line of plain text, for the documents a candidate shows (the PDF and the editable copy).
     *
     * The address is written out, never active: a printed page or a word-processor file does not send anyone anywhere, and the
     * reader sees where the page is.
     *
     * @param array[] $entries The `regle_validation` of an entry of referentiel.json: intitule, adresse, verifie_le (or null).
     * @return string
     */
    public static function plain_line(array $entries): string {
        $dateformat = get_string('strftimedate', 'langconfig');
        $parts = [];
        foreach ($entries as $entry) {
            $part = $entry['intitule'] . ' (' . $entry['adresse'] . ')';
            if ($entry['verifie_le'] !== null) {
                $part .= ' ' . get_string('refcheckedon', 'tool_vae', display_date::format(
                    (new \DateTimeImmutable($entry['verifie_le']))->getTimestamp(),
                    $dateformat,
                    'UTC'
                ));
            }
            $parts[] = $part;
        }

        return get_string('refrulelabel', 'tool_vae') . ' ' . implode(' ; ', $parts);
    }

    /**
     * Say in the log that an address was given or taken back. The record's code and nothing else: no address, no title.
     *
     * @param \stdClass $source A row of tool_vae_source.
     * @param string $action added or removed.
     * @param int $refid
     */
    private static function trigger(\stdClass $source, string $action, int $refid): void {
        $class = $action === 'added' ? ref_added::class : ref_removed::class;
        $class::create([
            'context' => \context_system::instance(),
            'objectid' => $refid,
            'other' => ['fichecode' => (string) $source->fichecode, 'action' => $action],
        ])->trigger();
    }
}
