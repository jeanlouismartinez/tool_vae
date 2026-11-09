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
 * The screens of the self-positioning of the vaeiste (lot 1a): the records, the tree of a record, one node.
 *
 * ALL THE HTML IS HERE, and every label, name or text goes through s() or format_text(). Every screen says that what is written
 * here is visible to the manager who follows, and that it is not a validation. NO TOTAL, NO RATE, NO COLOUR OF RANKING: a level
 * and a date on each node, the history on request, the word of the structure next to the vaeiste's own without any gap.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selfpos_screen {
    /**
     * The two sentences every screen carries: visible to the manager, not a validation.
     *
     * Said to the vaeiste on their own screens ("what you write"), and of the vaeiste to anyone else who reads them
     * (the manager who follows, the direction): a reader is never told what to write.
     *
     * @param int $candidateid The vaeiste whose screen it is.
     * @return string HTML.
     */
    public static function disclaimer(int $candidateid): string {
        global $USER;

        $own = (int) $USER->id === $candidateid;

        return \html_writer::tag(
            'p',
            s(get_string($own ? 'selfposvisible' : 'selfposvisibleread', 'tool_vae')) . ' '
                . s(get_string($own ? 'selfposnotvalidation' : 'selfposnotvalidationread', 'tool_vae')),
            ['class' => 'text-muted small tool-vae-selfpos-disclaimer']
        );
    }

    /**
     * The records the vaeiste may position themselves on, and the ones that are hidden, said why.
     *
     * @param int $candidateid
     * @param \stdClass[] $sources The rows of tool_vae_source that are offered, by code.
     * @param string[] $hidden Code => the message of a record that is hidden.
     * @return string HTML.
     */
    public static function records(int $candidateid, array $sources, array $hidden): string {
        $out = self::disclaimer($candidateid);
        foreach ($hidden as $message) {
            // Already escaped by hidden_frameworks::message(): a second pass would show entities in the title.
            $out .= \html_writer::div($message, 'alert alert-info');
        }
        if (!$sources) {
            return $out . \html_writer::tag('p', s(get_string('selfposnorecord', 'tool_vae')), ['class' => 'text-muted']);
        }
        $items = [];
        foreach ($sources as $source) {
            $items[] = \html_writer::tag('li', \html_writer::link(
                self::url($candidateid, (string) $source->fichecode),
                s((string) $source->fichecode)
            ));
        }

        return $out . \html_writer::tag('p', s(get_string('selfposchoose', 'tool_vae')))
            . \html_writer::tag('ul', implode('', $items));
    }

    /**
     * The tree of a record, every node with its level name, the vaeiste's current level and its date, and the way to its page.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param \stdClass $tree As selfpos_tree::for_record() gives it.
     * @param array $current Rank => the line that stands, as selfpos_manager::current() gives it.
     * @param bool $canwrite Whether the reader is the vaeiste and may position themselves.
     * @return string HTML.
     */
    public static function tree(int $candidateid, string $fichecode, \stdClass $tree, array $current, bool $canwrite): string {
        $items = [];
        foreach ($tree->nodes as $node) {
            $text = \html_writer::tag('span', s($node->levelname), ['class' => 'small text-muted me-2'])
                . \html_writer::tag('strong', s($node->label));
            if (!$node->positionable) {
                $text .= ' ' . \html_writer::tag('span', s(get_string('selfposexplainnotpositionable', 'tool_vae')), [
                    'class' => 'small text-muted',
                ]);
            } else {
                $text .= ' - ' . self::level_words($current[(string) $node->rank] ?? null);
                $text .= ' ' . \html_writer::tag('span', \html_writer::link(
                    self::url($candidateid, $fichecode, (string) $node->rank),
                    get_string($canwrite ? 'selfposnodelink' : 'selfposreadlink', 'tool_vae')
                ), ['class' => 'small']);
            }
            $items[] = \html_writer::tag('li', $text, [
                'class' => 'tool-vae-selfpos-node',
                'style' => 'margin-left: ' . (((int) $node->level - 1) * 1.5) . 'rem',
                'data-level' => (string) $node->level,
            ]);
        }

        // The invitation to say where one stands is for the one who writes; a reader gets the tree alone.
        return \html_writer::start_tag('section', ['class' => 'tool-vae-selfpos'])
            . self::disclaimer($candidateid)
            . ($canwrite ? \html_writer::tag('p', s(get_string('selfposintro', 'tool_vae'))) : '')
            . \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled tool-vae-selfpos-tree'])
            . \html_writer::end_tag('section');
    }

    /**
     * One node: the form (given by the page), the word of the structure next to the vaeiste's own, the lines of the record
     * before, and the history.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param \stdClass $node A node of selfpos_tree::for_record().
     * @param \stdClass[] $lines Every line of the node, the earliest first.
     * @param \stdClass $regard As selfpos_regard::for_node() gives it.
     * @param array $successor What the record this one replaces positioned on the equivalent node, as selfpos_successor gives it.
     * @param bool $canwrite Whether the reader is the vaeiste and may position themselves.
     * @param string $formhtml The form, rendered, when there is one to show.
     * @return string HTML.
     */
    public static function node(
        int $candidateid,
        string $fichecode,
        \stdClass $node,
        array $lines,
        \stdClass $regard,
        array $successor,
        bool $canwrite,
        string $formhtml = ''
    ): string {
        $context = \context_user::instance($candidateid);
        $out = self::disclaimer($candidateid);
        $out .= \html_writer::tag(
            'p',
            \html_writer::tag('span', s($node->levelname), ['class' => 'small text-muted me-2'])
            . \html_writer::tag('strong', s($fichecode)) . ' - ' . s($node->label)
        );
        if (!$node->positionable) {
            $out .= \html_writer::tag('p', s(get_string('selfposexplainnotpositionable', 'tool_vae')), ['class' => 'text-muted']);
        } else if ($canwrite && $formhtml !== '') {
            $out .= $formhtml;
        }

        // The word of the structure, next to the vaeiste's own, without any gap.
        $out .= \html_writer::tag('h3', s(get_string('selfposregard', 'tool_vae')), ['class' => 'h5']);
        if ($regard->structure === null) {
            $out .= \html_writer::tag('p', s(get_string('selfposregardnone', 'tool_vae')), ['class' => 'text-muted']);
        } else {
            $out .= \html_writer::tag('p', s(get_string('selfposregardline', 'tool_vae', (object) [
                'status' => get_string($regard->structure->kind . 'status' . $regard->structure->status, 'tool_vae'),
                'when' => userdate($regard->structure->time, get_string('strftimedate', 'langconfig')),
            ])));
        }
        if ($regard->declarations > 0) {
            $out .= \html_writer::tag('p', s(get_string('selfposdeclarations', 'tool_vae', (object) [
                'declarations' => $regard->declarations, 'attested' => $regard->attested, 'pieces' => $regard->pieces,
            ])), ['class' => 'small']);
        }
        foreach ($successor as $entry) {
            $out .= \html_writer::tag('p', s(get_string('selfposonold', 'tool_vae', (object) [
                'code' => $entry->fromcode,
                'rank' => $entry->fromrank,
                'label' => $entry->levellabel,
                'when' => userdate($entry->time, get_string('strftimedate', 'langconfig')),
            ])), ['class' => 'small text-muted']);
        }

        $out .= \html_writer::tag('h3', s(get_string('selfposhistory', 'tool_vae')), ['class' => 'h5']);
        $out .= self::history($lines, $context);
        $out .= \html_writer::tag('p', \html_writer::link(
            self::url($candidateid, $fichecode),
            get_string('selfposback', 'tool_vae')
        ));

        return \html_writer::tag('section', $out, ['class' => 'tool-vae-selfpos']);
    }

    /**
     * The history of a node: the line that stands, then the earlier ones.
     *
     * @param \stdClass[] $rows The rows, the earliest first.
     * @param \context $context
     * @return string HTML.
     */
    public static function history(array $rows, \context $context): string {
        if (!$rows) {
            return \html_writer::tag('p', s(get_string('selfposnoline', 'tool_vae')), ['class' => 'text-muted']);
        }
        $rows = array_values($rows);
        $standing = array_pop($rows);
        $out = \html_writer::tag('h4', s(get_string('selfposstanding', 'tool_vae')), ['class' => 'h6'])
            . self::line($standing, $context);
        if ($rows) {
            $out .= \html_writer::tag('h4', s(get_string('selfposearlier', 'tool_vae')), ['class' => 'h6']);
            foreach (array_reverse($rows) as $row) {
                $out .= self::line($row, $context);
            }
        }

        return \html_writer::div($out, 'tool-vae-history');
    }

    /**
     * One line: its step, its date, and its justification.
     *
     * @param \stdClass $row A row of tool_vae_selfpos.
     * @param \context $context
     * @return string HTML.
     */
    public static function line(\stdClass $row, \context $context): string {
        $when = userdate((int) $row->timecreated, get_string('strftimedatetime', 'langconfig'));
        if (!empty($row->importedat)) {
            $when .= ' - ' . get_string('selfposrestored', 'tool_vae');
        }
        $out = \html_writer::tag(
            'div',
            \html_writer::tag('strong', s((string) $row->levellabel)) . ' ' . s($when),
            ['class' => 'small']
        );
        if ((string) $row->justification !== '') {
            $format = (int) $row->justificationformat === 4 ? FORMAT_MARKDOWN : FORMAT_PLAIN;
            $options = ['context' => $context, 'filter' => false];
            $out .= \html_writer::div(format_text((string) $row->justification, $format, $options));
        }

        return \html_writer::div($out, 'ms-3 mb-2');
    }

    /**
     * The justification the vaeiste had typed when their record was hidden under them: read from THEIR send only.
     *
     * A text that arrives in the address, or in a send without the session key, is nothing: otherwise anyone could make
     * a link that shows a text of their choice on the page (the rule hidden_frameworks::sent() follows for the declaration).
     *
     * @return string '' unless a form was sent with a valid session key.
     */
    public static function kept_text(): string {
        $sesskey = (string) optional_param('sesskey', '', PARAM_RAW);
        if (!data_submitted() || $sesskey === '' || !confirm_sesskey($sesskey)) {
            return '';
        }

        return (string) optional_param('justification', '', PARAM_RAW);
    }

    /**
     * The hidden record: its message, and the text the vaeiste had typed, given back.
     *
     * @param string $message The message of hidden_frameworks for the record, its title already escaped.
     * @param string $text What was typed, if anything.
     * @return string HTML.
     */
    public static function hidden(string $message, string $text): string {
        // The message comes from hidden_frameworks::message(), its title already escaped: shown as it is, once.
        $out = \html_writer::div($message, 'alert alert-' . ($text === '' ? 'info' : 'danger'));
        if (trim($text) !== '') {
            $out .= \html_writer::tag('p', s(get_string('selfposhiddenkept', 'tool_vae')))
                . \html_writer::tag('textarea', s($text), [
                    'readonly' => 'readonly', 'rows' => 8, 'cols' => 70, 'class' => 'form-control mb-3',
                ]);
        }

        return $out;
    }

    /**
     * The vaeiste's current level on a node, in words: the label and the date, or that there is none.
     *
     * @param \stdClass|null $latest The line that stands, if any.
     * @return string HTML.
     */
    public static function level_words(?\stdClass $latest): string {
        if (!selfpos_manager::standing($latest)) {
            return s(get_string('selfposnone', 'tool_vae'));
        }

        return s(get_string('selfposcurrent', 'tool_vae', (object) [
            'label' => $latest->levellabel,
            'when' => userdate((int) $latest->timecreated, get_string('strftimedate', 'langconfig')),
        ]));
    }

    /**
     * The address of the page: the records, the tree of a record, or one node.
     *
     * @param int $candidateid
     * @param string $fichecode Empty for the list of records.
     * @param string|null $rank Null for the tree; '' is the record itself.
     * @return \moodle_url
     */
    public static function url(int $candidateid, string $fichecode = '', ?string $rank = null): \moodle_url {
        $params = ['userid' => $candidateid];
        if ($fichecode !== '') {
            $params['fiche'] = $fichecode;
        }
        if ($rank !== null) {
            $params['rank'] = $rank;
        }

        return new \moodle_url('/admin/tool/vae/selfposition.php', $params);
    }
}
