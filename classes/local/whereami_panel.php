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
 * The panel "where am I" (lot 1b, task 7; spec 1a § 6.6): the tree of a record in reading, every node with the vaeiste's own
 * level and how many situations and declarations carry it.
 *
 * COUNTS OF ITS OWN, NOTHING ADDED UP. Each node says what was written on IT: a parent never gets the situations or the
 * declarations of its children, and no total, no rate, no colour of ranking is drawn. A rank that carries something but has
 * left the record is listed after the tree, by its rank, never folded into a parent.
 *
 * ALL THE HTML IS HERE, and every label, name or title goes through s() or format_string(). No JavaScript: the fold of the
 * declaration page is a native <details>.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class whereami_panel {
    /**
     * The panel of one record.
     *
     * @param int $candidateid The vaeiste.
     * @param string $fichecode The record.
     * @param array $options checked (string[]: the ranks whose box is ticked), boxes (bool: a box tags[] on each node a
     *        situation may carry), link (bool: the way to the self-positioning, "position myself" to the vaeiste who may,
     *        "read" to anyone else), title (string, already translated; '' for none; by default "where I stand" to the
     *        vaeiste reading their own, "where they stand" to anyone else - T15, point Z1).
     * @return string HTML; '' when the reader may not view the dossier, the competencies are off or the record is not imported.
     */
    public static function render(int $candidateid, string $fichecode, array $options = []): string {
        global $USER;

        if (!access::can_view($candidateid) || !competency_gateway::is_subsystem_enabled()) {
            return '';
        }
        $tree = selfpos_tree::for_record($fichecode);
        if ($tree === null) {
            return '';
        }
        $boxes = !empty($options['boxes']);
        $checked = array_flip(array_map('strval', (array) ($options['checked'] ?? [])));
        // The first person is the vaeiste's alone: a reader - a manager who follows, the direction - reads the third.
        $words = (int) $USER->id === $candidateid ? 'whereamititle' : 'whereamititlereader';
        $title = array_key_exists('title', $options) ? (string) $options['title'] : get_string($words, 'tool_vae');

        $current = (new selfpos_manager())->current($candidateid, $fichecode);
        $counts = self::counts($candidateid, $fichecode);
        $readable = draft_manager::can_read_doctype($candidateid, draft_manager::DOCTYPE_VALIDATION);

        $items = [];
        $shown = [];
        foreach ($tree->nodes as $node) {
            $rank = (string) $node->rank;
            $text = \html_writer::tag('span', s($node->levelname), ['class' => 'small text-muted me-2'])
                . \html_writer::tag('strong', format_string($node->label, true, ['context' => \context_system::instance()]));
            $shown[$rank] = true;
            if (!$node->positionable) {
                $text .= ' ' . \html_writer::tag('span', s(get_string('selfposexplainnotpositionable', 'tool_vae')), [
                    'class' => 'small text-muted',
                ]);
                // Something written on it before it lost its rank stays told here, where it still is: never "gone".
                if (isset($counts[$rank])) {
                    $text .= ' - ' . self::count_words($candidateid, $fichecode, $rank, $counts[$rank], $readable);
                }
            } else {
                if ($boxes && (int) $node->level >= situation_manager::MIN_TAG_LEVEL) {
                    $id = 'tool-vae-whereami-tag-' . $rank;
                    $box = ['type' => 'checkbox', 'name' => 'tags[]', 'value' => $rank, 'id' => $id, 'class' => 'me-2'];
                    if (isset($checked[$rank])) {
                        $box['checked'] = 'checked';
                    }
                    $text = \html_writer::empty_tag('input', $box) . \html_writer::tag('label', $text, ['for' => $id]);
                }
                $text .= ' - ' . selfpos_screen::level_words($current[$rank] ?? null)
                    . ' - ' . self::count_words($candidateid, $fichecode, $rank, $counts[$rank] ?? null, $readable);
            }
            $items[] = self::item($text, (int) $node->level, $rank);
        }

        $out = \html_writer::start_tag('section', ['class' => 'tool-vae-whereami']);
        if ($title !== '') {
            $out .= \html_writer::tag('h4', s($title), ['class' => 'h5']);
        }
        if (!empty($options['link'])) {
            $words = selfpos_manager::can_write($candidateid) ? 'selfposlink' : 'selfposreadall';
            $out .= \html_writer::tag('p', \html_writer::link(
                selfpos_screen::url($candidateid, $fichecode),
                s(get_string($words, 'tool_vae'))
            ));
        }
        $out .= \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled tool-vae-whereami-tree']);

        // What was written on a node the record no longer holds: by its rank, apart, never given to a parent.
        $gone = array_diff_key($counts, $shown);
        if ($gone) {
            $items = [];
            foreach ($gone as $rank => $count) {
                $items[] = self::item(
                    \html_writer::tag('strong', s((string) $rank)) . ' - '
                        . self::count_words($candidateid, $fichecode, (string) $rank, $count, $readable),
                    1,
                    (string) $rank
                );
            }
            $out .= \html_writer::tag('h5', s(get_string('whereamigone', 'tool_vae')), ['class' => 'h6'])
                . \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled tool-vae-whereami-gone']);
        }

        return $out . \html_writer::end_tag('section');
    }

    /**
     * How many situations and declarations carry each rank of a record: two grouped queries, never one per node, and NOTHING
     * added up - a rank with nothing is absent, a parent gets nothing from its children.
     *
     * The situations are counted only for a reader who may read the preparation (draft_manager::can_read_doctype): for
     * anyone else they are not read at all, and situations is null - never a zero the reader could take for a fact.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @return \stdClass[] Rank => (object) ['situations' => int|null, 'declarations' => int], in natural order of the ranks;
     *         situations is null when the reader may not read them.
     * @throws \moodle_exception When the reader may not view the dossier.
     */
    public static function counts(int $candidateid, string $fichecode): array {
        global $DB;

        access::require_can_view($candidateid);
        $readable = draft_manager::can_read_doctype($candidateid, draft_manager::DOCTYPE_VALIDATION);
        $situations = $readable ? (new situation_manager())->counts($candidateid, $fichecode) : [];
        $declarations = $DB->get_records_sql_menu(
            'SELECT ficherang, COUNT(1) AS declarations
               FROM {tool_vae_evidence}
              WHERE candidateid = :candidateid AND fichecode = :fichecode
           GROUP BY ficherang',
            ['candidateid' => $candidateid, 'fichecode' => $fichecode]
        );

        $out = [];
        foreach ($situations as $rank => $count) {
            $out[(string) $rank] = (object) ['situations' => (int) $count, 'declarations' => 0];
        }
        foreach ($declarations as $rank => $count) {
            $rank = (string) $rank;
            $out[$rank] = $out[$rank] ?? (object) ['situations' => $readable ? 0 : null, 'declarations' => 0];
            $out[$rank]->declarations = (int) $count;
        }
        uksort($out, 'strnatcmp');

        return $out;
    }

    /**
     * The situations tagged on a bloc of the preparation or on a node under it, for the part "lier": each with its title, its
     * period and the way to it.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param string $blocrank The rank of the bloc as validation_catalog::parse() gives it (lower case); '' for the record as
     *        a whole, whose every node counts.
     * @return string HTML; '' when the reader may not read the situations or the record is not imported.
     */
    public static function tagged_on_bloc(int $candidateid, string $fichecode, string $blocrank): string {
        if (
            !draft_manager::can_read_doctype($candidateid, draft_manager::DOCTYPE_VALIDATION)
                || !competency_gateway::is_subsystem_enabled()
        ) {
            return '';
        }
        $tree = selfpos_tree::for_record($fichecode);
        if ($tree === null) {
            return '';
        }
        $ranks = self::ranks_under($tree, $fichecode, $blocrank);
        $situations = $ranks ? (new situation_manager())->tagged($candidateid, $fichecode, $ranks) : [];

        $out = \html_writer::tag('h4', s(get_string('validlinksituations', 'tool_vae')), ['class' => 'h5']);
        if (!$situations) {
            $out .= \html_writer::tag('p', s(get_string('validlinksituationsnone', 'tool_vae')), ['class' => 'text-muted']);

            return \html_writer::div($out, 'tool-vae-linksituations mb-3');
        }
        $format = get_string('strftimedate', 'langconfig');
        $items = [];
        foreach ($situations as $slot => $situation) {
            $period = array_filter([
                $situation->periodstart === null ? '' : display_date::format((int) $situation->periodstart, $format),
                $situation->periodend === null ? '' : display_date::format((int) $situation->periodend, $format),
            ], fn(string $date) => $date !== '');
            $text = \html_writer::link(new \moodle_url('/admin/tool/vae/situation.php', [
                'userid' => $candidateid, 'fiche' => $fichecode, 'slot' => (int) $slot,
            ]), s((string) $situation->title));
            if ($period) {
                $text .= ' - ' . \html_writer::span(s(implode(' - ', $period)), 'text-muted');
            }
            $items[] = \html_writer::tag('li', $text);
        }

        return \html_writer::div($out . \html_writer::tag('ul', implode('', $items)), 'tool-vae-linksituations mb-3');
    }

    /**
     * The ranks of a bloc and of every node under it, in the tree of the record.
     *
     * @param \stdClass $tree As selfpos_tree::for_record() gives it.
     * @param string $fichecode
     * @param string $blocrank In lower case, as validation_catalog::parse() gives it; '' for the whole record.
     * @return string[]
     */
    private static function ranks_under(\stdClass $tree, string $fichecode, string $blocrank): array {
        if ($blocrank === '') {
            $top = '';
        } else {
            $structure = validation_catalog::structure($fichecode);
            $bloc = $structure->blocs[$blocrank] ?? null;
            $node = $bloc === null ? null : selfpos_tree::node($tree, (string) $bloc->rank);
            if ($node === null) {
                return [];
            }
            $top = (string) $node->rank;
        }

        // The nodes come in the order of the tree, a parent before its children: one pass gathers the whole branch.
        $branch = [$top => true];
        $ranks = $top === '' ? [] : [$top];
        foreach ($tree->nodes as $node) {
            if ((int) $node->level > 1 && isset($branch[(string) $node->parentrank])) {
                $branch[(string) $node->rank] = true;
                if ($node->positionable) {
                    $ranks[] = (string) $node->rank;
                }
            }
        }

        return array_values(array_unique($ranks));
    }

    /**
     * The two counts of one node, and the way to what carries it when something does.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param string $rank
     * @param \stdClass|null $count As counts() gives it; null for nothing.
     * @param bool $readable Whether the reader may read the situations: if not, they are never counted, not even as none.
     * @return string HTML.
     */
    private static function count_words(
        int $candidateid,
        string $fichecode,
        string $rank,
        ?\stdClass $count,
        bool $readable = true
    ): string {
        $count = $count ?? (object) ['situations' => $readable ? 0 : null, 'declarations' => 0];
        $readable = $readable && $count->situations !== null;
        $words = $readable ? 'whereamicounts' : 'whereamideclarationsonly';
        $out = \html_writer::span(s(get_string($words, 'tool_vae', $count)), 'small');
        // The way to the list is offered to whoever may read it alone: situations.php refuses the others (T15, point G1).
        if ($readable && ($count->situations > 0 || $count->declarations > 0)) {
            $out .= ' ' . \html_writer::link(
                new \moodle_url('/admin/tool/vae/situations.php', [
                    'userid' => $candidateid, 'fiche' => $fichecode, 'rank' => $rank,
                ]),
                s(get_string('whereamiinverse', 'tool_vae')),
                ['class' => 'small']
            );
        }

        return $out;
    }

    /**
     * One line of the panel, set in by its level.
     *
     * @param string $html
     * @param int $level
     * @param string $rank
     * @return string HTML.
     */
    private static function item(string $html, int $level, string $rank): string {
        return \html_writer::tag('li', $html, [
            'class' => 'tool-vae-whereami-node level-' . $level,
            'style' => 'margin-left: ' . (($level - 1) * 1.5) . 'rem',
            'data-level' => (string) $level,
            'data-rank' => $rank,
        ]);
    }
}
