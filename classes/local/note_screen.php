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
 * The HTML of the annotations (lot 1b): under the vaeiste's text, signed and dated, apart from the vaeiste's own voice.
 *
 * Every screen says that an annotation is the word of a manager, with no value of validation, and that it never changes the
 * vaeiste's text. Nothing is counted, totalled or ranked here: the lines in the order they were written. Every text is
 * escaped: a name by s(), an annotation by format_text() (plain or Markdown, cleaned, no filter).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class note_screen {
    /**
     * The block of the annotations of one target.
     *
     * @param int $candidateid
     * @param string $target note_manager::TARGET_SITUATION or TARGET_SELFPOS.
     * @param string $fichecode
     * @param int $slot
     * @param string $ficherang
     * @param \stdClass[] $rows The lines of note_manager::for_target(), the earliest first, each with ->standing.
     * @param bool $canwrite Whether the current user may annotate (note_manager::can_write()).
     * @param string $formhtml The form, drawn for whoever may annotate only.
     * @return string HTML.
     */
    public static function block(
        int $candidateid,
        string $target,
        string $fichecode,
        int $slot,
        string $ficherang,
        array $rows,
        bool $canwrite,
        string $formhtml = ''
    ): string {
        $url = $target === note_manager::TARGET_SITUATION
            ? situation_screen::url($candidateid, $fichecode, $slot)
            : selfpos_screen::url($candidateid, $fichecode, $ficherang);
        $out = \html_writer::tag('h3', s(get_string('notesheading', 'tool_vae')), ['class' => 'h5']);
        $out .= self::lines($candidateid, $rows, $url);
        if ($canwrite && $formhtml !== '') {
            $out .= $formhtml;
        }

        return \html_writer::tag('section', $out, ['class' => 'tool-vae-notes mt-4']);
    }

    /**
     * The annotations of the situations of a record that were deleted (D-1b-K): each former situation apart, said deleted.
     *
     * @param int $candidateid
     * @param \stdClass[] $rows The lines of note_manager::for_candidate() whose situation is gone, the earliest first.
     * @return string HTML; '' when there is none.
     */
    public static function orphans(int $candidateid, array $rows): string {
        if (!$rows) {
            return '';
        }
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) $row->targetuuid][] = $row;
        }
        $out = \html_writer::tag('h3', s(get_string('notesheading', 'tool_vae')), ['class' => 'h5']);
        foreach ($groups as $group) {
            $first = reset($group);
            $out .= \html_writer::tag('h4', s(get_string('validpart:situation', 'tool_vae', (int) $first->slot)) . ' - '
                . s(get_string('notetargetgone', 'tool_vae')), ['class' => 'h6']);
            // The author takes back their word here too: the list of the situations is where it is still read.
            $out .= self::lines($candidateid, $group, situation_screen::url($candidateid, (string) $first->fichecode));
        }

        return \html_writer::tag('section', $out, ['class' => 'tool-vae-notes mt-4']);
    }

    /**
     * The lines, with the sentence that no annotation validates.
     *
     * @param int $candidateid
     * @param \stdClass[] $rows
     * @param \moodle_url|null $url The page the withdrawal is sent to; null draws no button.
     * @return string HTML.
     */
    private static function lines(int $candidateid, array $rows, ?\moodle_url $url): string {
        global $OUTPUT, $USER;

        $out = \html_writer::tag('p', s(get_string('notesnotvalidation', 'tool_vae')), ['class' => 'small text-muted']);
        if (!$rows) {
            return $out . \html_writer::tag('p', s(get_string('notesnone', 'tool_vae')), ['class' => 'text-muted']);
        }
        $context = \context_user::instance($candidateid);
        foreach ($rows as $row) {
            $signature = s(get_string('noteby', 'tool_vae', (object) [
                'name' => self::author($row),
                'when' => userdate((int) $row->timecreated, get_string('strftimedatetime', 'langconfig')),
            ]));
            if (!empty($row->importedat)) {
                $signature .= ' - ' . s(get_string('selfposrestored', 'tool_vae'));
            }
            if ($row->state === note_manager::WITHDRAWN) {
                $line = \html_writer::div(s(get_string('notewithdrawn', 'tool_vae')) . ' ' . $signature, 'small text-muted');
            } else {
                $format = (int) $row->textformat === (int) FORMAT_MARKDOWN ? FORMAT_MARKDOWN : FORMAT_PLAIN;
                $line = \html_writer::div($signature, 'small')
                    . \html_writer::div(format_text((string) $row->text, $format, ['context' => $context, 'filter' => false]));
                $mine = $row->authorid !== null && empty($row->importedat) && (int) $row->authorid === (int) $USER->id;
                if ($url !== null && !empty($row->standing) && $mine) {
                    $line .= $OUTPUT->single_button(
                        new \moodle_url($url, ['action' => 'withdrawnote', 'note' => (int) $row->id]),
                        get_string('notewithdraw', 'tool_vae'),
                        'post'
                    );
                }
            }
            $class = 'tool-vae-note border-start ps-2 ms-3 mb-2' . (empty($row->standing) ? ' text-muted' : '');
            $out .= \html_writer::div($line, $class);
        }

        return $out;
    }

    /**
     * The name of the author, as text: the account's name; "author of the archive, not named" for a restored line, which
     * names no account of this site (D-1b-L); "author erased" when the line names nobody at all, or an account that is gone.
     *
     * @param \stdClass $row
     * @return string
     */
    private static function author(\stdClass $row): string {
        if ($row->authorid === null) {
            return get_string($row->authoruuid === null ? 'noteauthorgone' : 'noteauthorarchive', 'tool_vae');
        }
        $author = \core_user::get_user((int) $row->authorid);

        return $author && empty($author->deleted) ? fullname($author) : get_string('noteauthorgone', 'tool_vae');
    }
}
