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
 * What the dossier shows of the positionings of the structure (T-90, lot 3).
 *
 * THE EVALUATIONS OF THE COMPETENCIES AND THE CERTIFICATIONS OF THE BLOCS, NEVER VALIDATIONS. The section is built on the
 * tree of each record the candidate declared on, not on the declarations: a competency nobody has declared on still has a
 * place, and so has a bloc. It says in every case that a positioning is not a validation, which only the jury gives, outside
 * this platform. Free text goes through format_text() as plain text; labels and codes through s().
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class positioning_screen {
    /**
     * The section of the dossier: for each record, each bloc with its certification and its competencies with their evaluation.
     *
     * @param int $candidateid
     * @param \context $context The context of the candidate.
     * @return string HTML; empty when the candidate declared on no record the site still holds.
     */
    public static function section(int $candidateid, \context $context): string {
        global $DB;

        $codes = $DB->get_fieldset_sql(
            'SELECT DISTINCT fichecode FROM {tool_vae_evidence} WHERE candidateid = :c ORDER BY fichecode ASC',
            ['c' => $candidateid]
        );
        $evaluations = new evaluation_manager();
        $certifications = new certification_manager();
        $html = '';
        foreach ($codes as $code) {
            $layout = positioning_rules::layout((string) $code);
            if (!$layout || !$layout->leaves) {
                continue;
            }
            $evaluated = $evaluations->current($candidateid, (string) $code);
            $certified = $certifications->current($candidateid, (string) $code);
            // What the record this one replaces positioned, read and never copied.
            $older = positioning_successor::readings($candidateid, (string) $code, $layout);
            // The vaeiste's own word on each node (lot 1a), put next to the structure's, without any gap. The reader has the
            // right to read the dossier: the caller asked it at the door.
            $selfpos = positioning_rules::latest(selfpos_manager::TABLE, $candidateid, (string) $code);
            $html .= \html_writer::tag('h4', s($code), ['class' => 'h5']);
            foreach ($layout->blocs as $rank => $bloc) {
                $html .= self::bloc(
                    $candidateid,
                    (string) $code,
                    (string) $rank,
                    $bloc,
                    $evaluated,
                    $certified,
                    $older,
                    $context,
                    $selfpos
                );
            }
            $alone = array_filter($layout->leaves, fn($leaf) => $leaf->bloc === null && $layout->hasblocs);
            if ($alone) {
                $html .= self::leaves($candidateid, (string) $code, $alone, $evaluated, $context, $older['eval'], $selfpos);
            }
        }
        if ($html === '') {
            // The records are gone from the site, but the archive of the structure in place is still the vaeiste's to take.
            $block = self::structure_block($candidateid);

            return $block === '' ? '' : \html_writer::tag('section', $block, ['class' => 'tool-vae-positioning mb-4']);
        }

        // The way to the self-positioning of the vaeiste at every level (lot 1a): the vaeiste goes to position themselves,
        // anyone else who reads the dossier goes to read it. The right to read was asked at the door of the dossier.
        $selfposlink = \html_writer::tag('p', \html_writer::link(
            selfpos_screen::url($candidateid),
            get_string(selfpos_manager::can_write($candidateid) ? 'selfposlink' : 'selfposreadall', 'tool_vae')
        ), ['class' => 'small']);

        return \html_writer::start_tag('section', ['class' => 'tool-vae-positioning mb-4'])
            . \html_writer::tag('h3', s(get_string('positioningheading', 'tool_vae')))
            . \html_writer::tag('p', s(get_string('positioningdisclaimer', 'tool_vae')), ['class' => 'text-muted small'])
            . $selfposlink
            . self::structure_block($candidateid)
            . $html
            . \html_writer::end_tag('section');
    }

    /**
     * The archive of the structure: the one in place, for whoever may read it, and the button that produces a new one, for whoever
     * may produce it. A document of its own, apart from the dossiers of the vaeiste.
     *
     * @param int $candidateid
     * @return string HTML; empty when the reader may do neither.
     */
    private static function structure_block(int $candidateid): string {
        $out = '';
        $current = structure_archive::current($candidateid);
        if ($current && structure_archive::can_read($candidateid)) {
            $out .= \html_writer::tag('p', s(get_string('structurecurrent', 'tool_vae', (object) [
                'date' => userdate((int) $current->get_timecreated()),
                'id' => substr($current->get_filename(), -30, 26),
            ])) . ' ' . \html_writer::link(
                \moodle_url::make_pluginfile_url(
                    $current->get_contextid(),
                    'tool_vae',
                    structure_archive::FILEAREA,
                    $current->get_itemid(),
                    $current->get_filepath(),
                    $current->get_filename(),
                    true
                ),
                get_string('structuredownload', 'tool_vae')
            ));
        }
        if (structure_archive::can_issue($candidateid)) {
            $out .= \html_writer::tag('p', \html_writer::link(
                new \moodle_url('/admin/tool/vae/structure.php', ['userid' => $candidateid]),
                get_string('structureissue', 'tool_vae'),
                ['class' => 'btn btn-secondary']
            ));
        }

        return $out;
    }

    /**
     * One bloc: its certification, the lacks, and its competencies.
     *
     * @param int $candidateid
     * @param string $code
     * @param string $rank
     * @param \stdClass $bloc label, leaves.
     * @param \stdClass[] $evaluated Rank => latest evaluation.
     * @param \stdClass[] $certified Rank => latest certification.
     * @param array $older What the record this one replaces positioned, as positioning_successor::readings() gives it.
     * @param \context $context
     * @param \stdClass[] $selfpos Rank => the vaeiste's own line that stands (lot 1a).
     * @return string
     */
    private static function bloc(
        int $candidateid,
        string $code,
        string $rank,
        \stdClass $bloc,
        array $evaluated,
        array $certified,
        array $older,
        \context $context,
        array $selfpos = []
    ): string {
        $title = $rank === '' ? get_string('positioningwhole', 'tool_vae') : $bloc->label;
        $out = \html_writer::tag('h5', s(get_string('positioningbloc', 'tool_vae')) . ' - ' . s($title), ['class' => 'h6']);

        $line = $certified[$rank] ?? null;
        $expired = false;
        if ($line === null || $line->status === positioning_rules::WITHDRAWN) {
            $out .= \html_writer::tag('p', s(get_string('positioningnone', 'tool_vae')), ['class' => 'text-muted']);
        } else {
            $out .= self::line($line, $context, 'certif');
            // Told on reading, on the day of the reader, and written nowhere: the same words for whoever reads the dossier.
            $expired = certification_manager::is_expired($line, certification_manager::today());
            if ($expired) {
                $days = certification_manager::days_overdue((string) $line->validuntil, certification_manager::today());
                $out .= \html_writer::div(
                    \html_writer::tag('strong', s(get_string('positioningtorenew', 'tool_vae'))) . ' : '
                        . s(get_string('positioningexpired', 'tool_vae', (object) [
                            'until' => $line->validuntil, 'days' => $days,
                        ])),
                    'alert alert-secondary small'
                );
            }
            $newest = 0;
            foreach ($bloc->leaves as $leafrank => $leaf) {
                $newest = max($newest, (int) (($evaluated[(string) $leafrank]->timecreated ?? 0)));
            }
            if ($newest > (int) $line->timecreated) {
                $out .= \html_writer::div(s(get_string('positioningstale', 'tool_vae')), 'alert alert-warning small');
            }
        }
        $out .= self::older_lines($older['certif'][$rank] ?? [], 'certif');
        $out .= self::selfpos_line($selfpos[$rank] ?? null);
        $links = [];
        if (certification_manager::can_write($candidateid)) {
            $links[] = \html_writer::link(
                self::url('certify', $candidateid, $code, $rank),
                get_string($expired ? 'positioningrenewlink' : 'positioningcertifylink', 'tool_vae')
            );
        }
        $links[] = \html_writer::link(
            self::url('certify', $candidateid, $code, $rank),
            get_string('positioninghistory', 'tool_vae')
        );
        $out .= \html_writer::tag('p', implode(' | ', array_unique($links)), ['class' => 'small']);

        return $out . self::leaves($candidateid, $code, $bloc->leaves, $evaluated, $context, $older['eval'], $selfpos);
    }

    /**
     * The vaeiste's own word on a node (lot 1a), as a line to read next to the structure's: no gap, no link to write from here.
     *
     * @param \stdClass|null $latest The vaeiste's line that stands on the node, if any.
     * @return string HTML; empty when the vaeiste gave no level, or withdrew it.
     */
    private static function selfpos_line(?\stdClass $latest): string {
        if (!selfpos_manager::standing($latest)) {
            return '';
        }

        return \html_writer::div(s(get_string('selfposonevaluation', 'tool_vae', (object) [
            'label' => $latest->levellabel,
            'when' => userdate((int) $latest->timecreated, get_string('strftimedate', 'langconfig')),
        ])), 'small text-muted ms-3 tool-vae-selfpos-line');
    }

    /**
     * What the record this one replaces positioned, as a line to read: no link, nothing to write on it from here.
     *
     * @param \stdClass[] $entries As positioning_successor::readings() gives them for one rank.
     * @param string $kind eval or certif.
     * @return string HTML.
     */
    private static function older_lines(array $entries, string $kind): string {
        $out = '';
        foreach ($entries as $entry) {
            $when = $kind === 'certif'
                ? (string) $entry->decisiondate
                : userdate((int) $entry->time, get_string('strftimedate', 'langconfig'));
            $text = get_string('positioningonold', 'tool_vae', (object) [
                'code' => $entry->fromcode,
                'rank' => $entry->fromrank,
                'status' => get_string($kind . 'status' . $entry->status, 'tool_vae'),
                'when' => $when,
            ]);
            if ($kind === 'certif' && !empty($entry->validuntil)) {
                $text .= ' - ' . get_string('positioninguntil', 'tool_vae', $entry->validuntil);
            }
            $out .= \html_writer::div(s($text), 'small text-muted ms-3');
        }

        return $out;
    }

    /**
     * The competencies of a bloc (or standing alone), each with the line that stands.
     *
     * @param int $candidateid
     * @param string $code
     * @param \stdClass[] $leaves Rank => label, gone.
     * @param \stdClass[] $evaluated Rank => latest evaluation.
     * @param \context $context
     * @param array $older Rank => what the record this one replaces positioned on the equivalent competency.
     * @param \stdClass[] $selfpos Rank => the vaeiste's own line that stands (lot 1a).
     * @return string
     */
    private static function leaves(
        int $candidateid,
        string $code,
        array $leaves,
        array $evaluated,
        \context $context,
        array $older,
        array $selfpos = []
    ): string {
        $items = [];
        $maywrite = evaluation_manager::can_write($candidateid);
        foreach ($leaves as $rank => $leaf) {
            $line = $evaluated[(string) $rank] ?? null;
            $text = \html_writer::tag('strong', s($leaf->label));
            if ($leaf->gone) {
                $text .= ' ' . \html_writer::tag(
                    'span',
                    s(get_string('coveragegone', 'tool_vae')),
                    ['class' => 'small text-muted']
                );
            }
            if ($line === null || $line->status === positioning_rules::WITHDRAWN) {
                $text .= ' - ' . s(get_string('positioningnone', 'tool_vae'));
            } else {
                $text .= self::line($line, $context, 'eval');
            }
            $text .= self::older_lines($older[(string) $rank] ?? [], 'eval');
            $text .= self::selfpos_line($selfpos[(string) $rank] ?? null);
            $address = self::url('evaluate', $candidateid, $code, (string) $rank);
            $links = [\html_writer::link($address, get_string('positioninghistory', 'tool_vae'))];
            if ($maywrite) {
                array_unshift($links, \html_writer::link(
                    self::url('evaluate', $candidateid, $code, (string) $rank),
                    get_string('positioningevaluatelink', 'tool_vae')
                ));
            }
            $items[] = \html_writer::tag(
                'li',
                $text . \html_writer::tag('span', ' ' . implode(' | ', array_unique($links)), ['class' => 'small'])
            );
        }

        return \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled ms-3']);
    }

    /**
     * One line: its status, who and when, and its reason.
     *
     * @param \stdClass $row A row of tool_vae_eval or tool_vae_certif.
     * @param \context $context
     * @param string $kind eval or certif.
     * @return string HTML.
     */
    public static function line(\stdClass $row, \context $context, string $kind): string {
        $status = s(get_string($kind . 'status' . $row->status, 'tool_vae'));
        if ($kind === 'certif' && !empty($row->decisiondate)) {
            $status .= ' (' . s(get_string('positioningcertifiedon', 'tool_vae', $row->decisiondate)) . ')';
        }
        if ($kind === 'certif' && !empty($row->validuntil)) {
            $status .= ' - ' . s(get_string('positioninguntil', 'tool_vae', $row->validuntil));
        }
        $out = \html_writer::tag(
            'div',
            \html_writer::tag('strong', $status) . ' ' . s(self::signature($row)),
            ['class' => 'small']
        );
        $out .= \html_writer::div(format_text($row->reason, FORMAT_PLAIN, ['context' => $context, 'filter' => false]));
        if (!empty($row->alertreason)) {
            $out .= \html_writer::div(
                \html_writer::tag('em', s(get_string('positioningalertreasonlabel', 'tool_vae')) . ' : ')
                . format_text($row->alertreason, FORMAT_PLAIN, ['context' => $context, 'filter' => false]),
                'small'
            );
        }

        return \html_writer::div($out, 'ms-3 mb-2');
    }

    /**
     * Who wrote a line and when, in words; no identifier.
     *
     * @param \stdClass $row
     * @return string
     */
    private static function signature(\stdClass $row): string {
        $when = userdate((int) $row->timecreated, get_string('strftimedatetime', 'langconfig'));
        if (!empty($row->importedat)) {
            return $when . ' - ' . get_string('positioningrestored', 'tool_vae');
        }
        $who = '';
        if (!empty($row->authorid)) {
            $author = \core_user::get_user((int) $row->authorid);
            $who = $author && empty($author->deleted) ? fullname($author) : get_string('positioningauthorgone', 'tool_vae');
        } else {
            $who = get_string('positioningauthorgone', 'tool_vae');
        }

        return $who . ', ' . $when;
    }

    /**
     * The history of a rank: the line that stands, then the earlier ones.
     *
     * @param \stdClass[] $rows The rows, the earliest first.
     * @param \context $context
     * @param string $kind eval or certif.
     * @return string HTML.
     */
    public static function history(array $rows, \context $context, string $kind): string {
        if (!$rows) {
            return \html_writer::tag('p', s(get_string('positioningnoline', 'tool_vae')), ['class' => 'text-muted']);
        }
        $rows = array_values($rows);
        $standing = array_pop($rows);
        $out = \html_writer::tag('h4', s(get_string('positioningcurrent', 'tool_vae')), ['class' => 'h5'])
            . self::line($standing, $context, $kind);
        if ($rows) {
            $out .= \html_writer::tag('h4', s(get_string('positioningearlier', 'tool_vae')), ['class' => 'h5']);
            foreach (array_reverse($rows) as $row) {
                $out .= self::line($row, $context, $kind);
            }
        }

        return \html_writer::div($out, 'tool-vae-history');
    }

    /**
     * The address of the page of a rank.
     *
     * @param string $page evaluate or certify.
     * @param int $candidateid
     * @param string $code
     * @param string $rank
     * @return \moodle_url
     */
    public static function url(string $page, int $candidateid, string $code, string $rank): \moodle_url {
        return new \moodle_url('/admin/tool/vae/' . $page . '.php', [
            'userid' => $candidateid, 'fichecode' => $code, 'rank' => $rank,
        ]);
    }
}
