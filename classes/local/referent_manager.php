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

use tool_vae\event\referent_added;
use tool_vae\event\referent_removed;

/**
 * Who is the referent of a bloc of a record, and who is told first of a declaration (T-23).
 *
 * A POINTER, NOT A RIGHT. A referent is a member of the staff an administrator names for a bloc of a certification record
 * (or for the whole record). The rule is the site's, written once, not once per candidate. It does one thing: among the
 * managers who follow a candidate, it decides who is told of a declaration on that bloc. It gives and takes away no right:
 * whoever follows a candidate may still read their dossier and attest, a declaration nobody is referent of is told to every
 * follower as before, and nothing is counted or ranked per referent.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class referent_manager {
    /**
     * Name a referent for a bloc of a record, or for the whole record.
     *
     * @param string $fichecode An imported record.
     * @param string $ficherang The rank of a bloc of that record; empty for the whole record.
     * @param int $userid The member of the staff.
     * @return \stdClass The row.
     * @throws \moodle_exception errorsupersessionunknown, errorreferentbloc, errorreferentuser, errorreferentduplicate
     */
    public static function add(string $fichecode, string $ficherang, int $userid): \stdClass {
        global $DB, $USER;

        require_capability('tool/vae:import', \context_system::instance());

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        if (!$source) {
            throw new \moodle_exception('errorsupersessionunknown', 'tool_vae');
        }
        if ($ficherang !== '' && !in_array($ficherang, self::blocs($source), true)) {
            throw new \moodle_exception('errorreferentbloc', 'tool_vae');
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0])) {
            throw new \moodle_exception('errorreferentuser', 'tool_vae');
        }
        $where = ['fichecode' => $fichecode, 'ficherang' => $ficherang, 'userid' => $userid];
        if ($DB->record_exists('tool_vae_referent', $where)) {
            throw new \moodle_exception('errorreferentduplicate', 'tool_vae');
        }

        $row = (object) ($where + ['timecreated' => time(), 'createdby' => (int) $USER->id]);
        try {
            $row->id = $DB->insert_record('tool_vae_referent', $row);
        } catch (\dml_write_exception $e) {
            // Two submits at once: the unique index answers for the check above.
            throw new \moodle_exception('errorreferentduplicate', 'tool_vae');
        }

        referent_added::create([
            'context' => \context_system::instance(),
            'objectid' => (int) $row->id,
            'relateduserid' => $userid,
            'other' => ['fichecode' => $fichecode, 'ficherang' => $ficherang],
        ])->trigger();

        return $row;
    }

    /**
     * Take a referent back.
     *
     * @param int $id
     * @throws \moodle_exception errorreferentunknown
     */
    public static function remove(int $id): void {
        global $DB;

        require_capability('tool/vae:import', \context_system::instance());

        $row = $DB->get_record('tool_vae_referent', ['id' => $id]);
        if (!$row) {
            throw new \moodle_exception('errorreferentunknown', 'tool_vae');
        }
        $DB->delete_records('tool_vae_referent', ['id' => $id]);

        referent_removed::create([
            'context' => \context_system::instance(),
            'objectid' => $id,
            'relateduserid' => (int) $row->userid,
            'other' => ['fichecode' => $row->fichecode, 'ficherang' => $row->ficherang],
        ])->trigger();
    }

    /**
     * The referents of some records, for the page that shows them.
     *
     * @param string[] $fichecodes
     * @return \stdClass[][] Keyed by record code, each a list of id, ficherang, userid, in rank then id order.
     */
    public static function for_fiches(array $fichecodes): array {
        global $DB;

        if (!$fichecodes) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($fichecodes), SQL_PARAMS_NAMED);
        $out = [];
        $rows = $DB->get_records_select('tool_vae_referent', "fichecode $insql", $params, 'fichecode ASC, ficherang ASC, id ASC');
        foreach ($rows as $row) {
            $out[$row->fichecode][] = (object) [
                'id' => (int) $row->id,
                'ficherang' => $row->ficherang,
                'userid' => (int) $row->userid,
            ];
        }

        return $out;
    }

    /**
     * Among the managers who follow a candidate, those to tell of a declaration on a competency.
     *
     * The referents of the bloc the competency sits under (or is), and of the whole record, if any of them is among the
     * followers; otherwise every follower, so that nothing is lost in silence when a bloc has no referent or its referent
     * does not follow this candidate. Only the order of who is told changes: nobody is made able or unable to read or attest.
     *
     * @param int[] $followers The user ids access::followers() gave.
     * @param string $fichecode The record of the declaration.
     * @param string $ficherang The rank of its competency.
     * @return int[] A subset of $followers, never empty when $followers is not.
     */
    public static function route(array $followers, string $fichecode, string $ficherang): array {
        global $DB;

        if (count($followers) < 2) {
            return $followers;
        }

        $ranks = [''];
        $bloc = self::bloc_of($fichecode, $ficherang);
        if ($bloc !== '') {
            $ranks[] = $bloc;
        }
        [$insql, $params] = $DB->get_in_or_equal($ranks, SQL_PARAMS_NAMED, 'rk');
        $params['fichecode'] = $fichecode;
        $referents = array_map('intval', $DB->get_fieldset_select(
            'tool_vae_referent',
            'DISTINCT userid',
            "fichecode = :fichecode AND ficherang $insql",
            $params
        ));
        $told = array_values(array_intersect($followers, $referents));

        return $told ?: $followers;
    }

    /**
     * Among some user ids, those whose account is neither deleted nor suspended.
     *
     * @param int[] $userids
     * @return int[] In the order given.
     */
    public static function active(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($userids), SQL_PARAMS_NAMED);
        $found = $DB->get_fieldset_select('user', 'id', "id $insql AND deleted = 0 AND suspended = 0", $params);
        $active = array_map('intval', $found);

        return array_values(array_filter($userids, fn($id) => in_array((int) $id, $active, true)));
    }

    /**
     * The rank of the bloc a competency sits under, or its own when it is a bloc.
     *
     * @param string $fichecode
     * @param string $ficherang
     * @return string Empty when the record has no bloc for it (a flat record, or a competency under none).
     */
    public static function bloc_of(string $fichecode, string $ficherang): string {
        return self::bloc_map($fichecode)[$ficherang] ?? '';
    }

    /**
     * The bloc of every competency of a record, from one reading of its tree.
     *
     * @param string $fichecode
     * @return string[] Rank of a competency => rank of its bloc (its own when it is a bloc, empty when it sits under
     *         none); empty for a record that is not imported.
     */
    public static function bloc_map(string $fichecode): array {
        global $DB;

        $source = $DB->get_record('tool_vae_source', ['fichecode' => $fichecode]);
        if (!$source) {
            return [];
        }
        $tree = (new competency_gateway())->framework_tree((int) $source->frameworkid);
        $parents = [];
        foreach ($tree as $competency) {
            if ($competency->parentid > 0) {
                $parents[$competency->parentid] = true;
            }
        }
        $out = [];
        foreach ($tree as $competency) {
            $rank = passage_manager::rank_of($competency->idnumber, $fichecode);
            if (!self::is_rank($rank)) {
                continue;
            }
            if ($competency->parentid > 0 && isset($tree[$competency->parentid])) {
                $parentrank = passage_manager::rank_of($tree[$competency->parentid]->idnumber, $fichecode);
                $out[$rank] = self::is_rank($parentrank) ? $parentrank : '';
            } else {
                $out[$rank] = isset($parents[$competency->id]) ? $rank : '';
            }
        }

        return $out;
    }

    /**
     * The blocs and records a member of the staff is referent of.
     *
     * @param int $userid
     * @return \stdClass[] Each with fichecode and ficherang (empty for the whole record), in record then rank order.
     */
    public static function for_user(int $userid): array {
        global $DB;

        $out = [];
        $rows = $DB->get_records('tool_vae_referent', ['userid' => $userid], 'fichecode ASC, ficherang ASC, id ASC');
        foreach ($rows as $row) {
            $out[] = (object) ['fichecode' => $row->fichecode, 'ficherang' => $row->ficherang];
        }

        return $out;
    }

    /**
     * The ranks of the competencies a referent answers for: the bloc itself and those under it, or every one for the whole
     * record.
     *
     * @param string $fichecode
     * @param string $ficherang The rank of a bloc; empty for the whole record.
     * @return string[]|null The ranks; null for the whole record (no restriction).
     */
    public static function ranks_under(string $fichecode, string $ficherang): ?array {
        if ($ficherang === '') {
            return null;
        }

        return array_map('strval', array_keys(array_filter(
            self::bloc_map($fichecode),
            fn($bloc) => $bloc === $ficherang
        )));
    }

    /**
     * The ranks of the blocs of a record: the competencies other competencies sit under.
     *
     * @param \stdClass $source A row of tool_vae_source.
     * @return string[]
     */
    public static function blocs(\stdClass $source): array {
        $tree = (new competency_gateway())->framework_tree((int) $source->frameworkid);
        $parents = [];
        foreach ($tree as $competency) {
            if ($competency->parentid > 0) {
                $parents[$competency->parentid] = true;
            }
        }
        $out = [];
        foreach (array_keys($parents) as $id) {
            if (isset($tree[$id])) {
                $rank = passage_manager::rank_of($tree[$id]->idnumber, $source->fichecode);
                if (self::is_rank($rank)) {
                    $out[] = $rank;
                }
            }
        }
        sort($out, SORT_NATURAL);

        return $out;
    }

    /**
     * The choices of the form that names a referent: the whole of each imported record, and each bloc of it.
     *
     * Every label is ready to be written as HTML: the select of the form library writes its labels as they are.
     *
     * @param iterable $rows Rows with fichecode and label, as supersession::overview() gives them.
     * @return array [labels, parts]: the label to show by key, and [record code, rank] by key. The key is the record code
     *         for the whole record, and the code, a pipe and the rank for a bloc; a code is never split to be read back.
     */
    public static function choices(iterable $rows): array {
        global $DB;

        $options = [];
        $parts = [];
        foreach ($rows as $row) {
            $source = $DB->get_record('tool_vae_source', ['fichecode' => $row->fichecode]);
            $name = s($row->fichecode) . ' — ' . format_string($row->label, true, ['context' => \context_system::instance()]);
            $options[$row->fichecode] = $name . ' — ' . get_string('referentwhole', 'tool_vae');
            $parts[$row->fichecode] = [$row->fichecode, ''];
            foreach ($source ? self::blocs($source) : [] as $rank) {
                $key = $row->fichecode . '|' . $rank;
                $options[$key] = $name . ' — ' . get_string('referentblocrank', 'tool_vae', s($rank));
                $parts[$key] = [$row->fichecode, $rank];
            }
        }

        return [$options, $parts];
    }

    /**
     * Whether a rank can be the rank of a bloc.
     *
     * The rank is the part of a competency's code that follows the record's code, and that code is text a manager may
     * rewrite by hand in the competencies screens of the core: whatever the import would have refused is no bloc.
     *
     * @param string $rank
     * @return bool
     */
    private static function is_rank(string $rank): bool {
        return preg_match(importer::RANK_PATTERN, $rank) === 1;
    }
}
