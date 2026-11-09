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
 * The screens of the work situations of the vaeiste (lot 1b): the records, the list of a record, one situation, the inverse view
 * of a node.
 *
 * ALL THE HTML IS HERE, and every free text - title, setting, part concerned, text, title of a piece - goes through s() or
 * format_text() as plain text. Every screen says who reads what is written (selfposvisible to the vaeiste, selfposvisibleread to
 * anyone else) and that nothing here is attested or validated. NO TOTAL, NO RATE, NO RANKING: a situation is listed by its slot,
 * with its own counts and dates, and nothing is added up.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class situation_screen {
    /**
     * The two sentences every screen carries: who reads it, and that nothing here is attested.
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
                . s(get_string('situationnotattested', 'tool_vae')),
            ['class' => 'text-muted small tool-vae-situation-disclaimer']
        );
    }

    /**
     * The records a vaeiste has situations on or may open one on, each leading to its list.
     *
     * @param int $candidateid
     * @param array $records Code => null for a record offered, or the message of a record hidden (already escaped by
     *        hidden_frameworks::message()) that still holds situations.
     * @return string HTML.
     */
    public static function records(int $candidateid, array $records): string {
        $out = self::disclaimer($candidateid);
        if (!$records) {
            return $out . \html_writer::tag('p', s(get_string('selfposnorecord', 'tool_vae')), ['class' => 'text-muted']);
        }
        $items = [];
        foreach ($records as $code => $message) {
            $item = \html_writer::link(self::url($candidateid, (string) $code), s((string) $code));
            if ($message !== null) {
                // Already escaped by hidden_frameworks::message(): a second pass would show entities in the title.
                $item .= \html_writer::div($message, 'alert alert-info mt-1');
            }
            $items[] = \html_writer::tag('li', $item);
        }

        return $out . \html_writer::tag('p', s(get_string('selfposchoose', 'tool_vae')))
            . \html_writer::tag('ul', implode('', $items));
    }

    /**
     * The situations of a record, in the order of their slots: title, period, setting, tags, pieces, where the text stands.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param \stdClass[] $rows As situation_manager::list() gives them.
     * @return string HTML.
     */
    public static function list(int $candidateid, string $fichecode, array $rows): string {
        if (!$rows) {
            return \html_writer::tag('p', s(get_string('situationnone', 'tool_vae')), ['class' => 'text-muted']);
        }
        $tree = selfpos_tree::for_record($fichecode);
        $items = [];
        foreach ($rows as $row) {
            $lines = self::facts($row, $tree);
            $lines[] = s(get_string('situationpieces', 'tool_vae', (int) $row->pieces));
            $lines[] = s(validation_status::words($row->status));
            $items[] = \html_writer::tag(
                'li',
                \html_writer::tag('strong', \html_writer::link(
                    self::url($candidateid, $fichecode, (int) $row->slot),
                    s(self::title($row))
                )) . \html_writer::div(implode('<br>', $lines), 'small'),
                ['class' => 'mb-3 tool-vae-situation-item', 'data-slot' => (string) (int) $row->slot]
            );
        }

        return \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled tool-vae-situations']);
    }

    /**
     * The details of one situation, in reading: its CURRENT title, period, setting and tags (D-1b-I).
     *
     * @param \stdClass $situation The row, with tags.
     * @return string HTML.
     */
    public static function details(\stdClass $situation): string {
        $lines = self::facts($situation, selfpos_tree::for_record((string) $situation->fichecode));

        return \html_writer::div(implode('<br>', $lines), 'tool-vae-situation-details mb-3');
    }

    /**
     * The text of a situation for a reader: the latest version saved, then, apart, the draft in progress said to be one
     * (D-1b-H).
     *
     * @param \stdClass|null $latest The latest version (a row of tool_vae_version), or null.
     * @param \stdClass $draft The row of tool_vae_draft that holds the text.
     * @param \context $context The vaeiste's context.
     * @return string HTML.
     */
    public static function text(?\stdClass $latest, \stdClass $draft, \context $context): string {
        $plain = fn(string $text): string => format_text($text, FORMAT_PLAIN, ['context' => $context, 'filter' => false]);
        if ($latest === null) {
            $out = \html_writer::tag('p', s(get_string('situationnoversion', 'tool_vae')), ['class' => 'text-muted']);
        } else {
            $out = \html_writer::tag(
                'p',
                s(get_string('sectioncurrentversion', 'tool_vae', (int) $latest->number)),
                ['class' => 'text-muted small mb-1']
            ) . \html_writer::div($plain((string) $latest->content), 'mb-2 tool-vae-situation-text');
        }
        if ($draft->working !== null && trim((string) $draft->working) !== '') {
            $out .= \html_writer::div(
                \html_writer::tag('p', s(get_string('situationworkingnotice', 'tool_vae')), ['class' => 'text-muted small mb-1'])
                    . $plain((string) $draft->working),
                'border-start ps-2 mb-2 tool-vae-situation-working'
            );
        }

        return $out;
    }

    /**
     * The pieces of a situation: one line per link that stands, with the part concerned; the piece opened by whoever may.
     *
     * @param \stdClass[] $links As spiece_manager::for_situation() gives them, each with `piece`.
     * @param \context_user $context The vaeiste's context.
     * @param callable|null $actions Given a link, the HTML of what the vaeiste may do with it (detach, remove); null for a reader.
     * @return string HTML.
     */
    public static function pieces(array $links, \context_user $context, ?callable $actions = null): string {
        $out = \html_writer::tag('h3', s(get_string('spiecesheading', 'tool_vae')), ['class' => 'h5 mt-4']);
        if (!$links) {
            return $out . \html_writer::tag('p', s(get_string('piecenone', 'tool_vae')), ['class' => 'text-muted']);
        }
        $date = get_string('strftimedatetime', 'langconfig');
        $items = '';
        foreach ($links as $link) {
            $piece = $link->piece;
            $mayopen = spiece_manager::may_open($piece);
            $line = \html_writer::tag('strong', s(get_string('piecenature:' . $piece->nature, 'tool_vae')));
            if ((string) $piece->title !== '' && $mayopen) {
                $line .= ' - ' . s((string) $piece->title);
            }
            if ((string) $link->part !== '') {
                $line .= \html_writer::div(
                    \html_writer::span(s(get_string('spiecepart', 'tool_vae')), 'text-muted me-2') . s((string) $link->part),
                    'small'
                );
            }
            $details = [];
            if ($piece->kind === piece_manager::KIND_FILE && $piece->timeremoved === null) {
                $details[] = s(strtoupper((string) $piece->ext) . ', ' . display_size((int) $piece->filesize));
            }
            $details[] = s(userdate((int) $piece->timecreated, $date));
            $line .= \html_writer::div(implode(' - ', $details), 'text-muted small');
            if ($piece->timeremoved !== null) {
                $line .= \html_writer::div(s(get_string('spieceremoved', 'tool_vae')), 'text-muted');
            } else if ($mayopen) {
                $line .= self::piece_access($piece, $context);
            }
            if ($actions !== null) {
                $line .= $actions($link);
            }
            $items .= \html_writer::tag('li', $line, ['class' => 'mb-3 tool-vae-piece']);
        }

        return $out . \html_writer::tag('ul', $items, ['class' => 'list-unstyled tool-vae-pieces']);
    }

    /**
     * The three ways the vaeiste adds a piece to a situation: a file, a link, a piece they already have. Plain forms of the
     * page, sent with the session key; the file field is written by hand (see piece_file_form for why).
     *
     * @param \moodle_url $url The page of the situation.
     * @param \stdClass[] $attachable The pieces of the vaeiste on the record that may be attached here (not removed, not
     *        linked to this situation already).
     * @param int $maxbytes The largest file, as the page applies it.
     * @return string HTML.
     */
    public static function piece_forms(\moodle_url $url, array $attachable, int $maxbytes): string {
        $natures = ['' => get_string('piecenaturechoose', 'tool_vae')];
        foreach (spiece_manager::NATURES as $nature) {
            $natures[$nature] = get_string('piecenature:' . $nature, 'tool_vae');
        }
        $reminder = \html_writer::div(get_string('anonymisepiece', 'tool_vae'), 'alert alert-info tool-vae-anonymise', [
            'id' => 'tool_vae_anonymise_spiece',
        ]);
        $described = ['aria-describedby' => 'tool_vae_anonymise_spiece', 'class' => 'form-control'];
        $field = function (string $label, string $id, string $input): string {
            return \html_writer::div(\html_writer::label($label, $id) . $input, 'mb-2');
        };
        $common = function (string $kind) use ($natures, $described, $field): string {
            return \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $kind])
                . $field(
                    get_string('piecenature', 'tool_vae'),
                    'id_' . $kind . 'nature',
                    \html_writer::select($natures, 'nature', '', false, [
                        'id' => 'id_' . $kind . 'nature', 'required' => 'required', 'class' => 'form-select',
                    ])
                )
                . $field(get_string('piecestitle', 'tool_vae'), 'id_' . $kind . 'title', \html_writer::empty_tag('input', [
                    'type' => 'text', 'name' => 'title', 'id' => 'id_' . $kind . 'title', 'size' => 60,
                    'maxlength' => piece_manager::TITLE_MAXLENGTH,
                ] + $described));
        };
        $part = function (string $kind) use ($described, $field): string {
            return $field(get_string('spiecepart', 'tool_vae'), 'id_' . $kind . 'part', \html_writer::empty_tag('input', [
                'type' => 'text', 'name' => 'part', 'id' => 'id_' . $kind . 'part', 'size' => 60,
                'maxlength' => spiece_manager::PART_MAXLENGTH,
            ] + $described));
        };
        $submit = fn(string $label): string => \html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => $label, 'class' => 'btn btn-secondary',
        ]);

        $out = $reminder;
        $out .= \html_writer::tag('form', \html_writer::tag('h4', s(get_string('piecefilesheading', 'tool_vae')), [
            'class' => 'h6',
        ])
            . \html_writer::tag('p', s(get_string('piecefilehelp', 'tool_vae', display_size($maxbytes))), ['class' => 'small'])
            . $common('file')
            . $field(get_string('piecefilelabel', 'tool_vae'), 'id_piecefile', \html_writer::empty_tag('input', [
                'type' => 'file', 'name' => 'piecefile', 'id' => 'id_piecefile', 'accept' => '.pdf,.odt,.docx,.jpg,.jpeg,.png',
                'class' => 'form-control-file d-block', 'required' => 'required',
                'aria-describedby' => 'tool_vae_anonymise_spiece',
            ]))
            . $part('file')
            . $submit(get_string('pieceaddfilesubmit', 'tool_vae')), [
                'method' => 'post', 'action' => $url->out(false), 'enctype' => 'multipart/form-data',
                'class' => 'mb-4 tool-vae-spiece-file',
            ]);

        $out .= \html_writer::tag('form', \html_writer::tag('h4', s(get_string('piecelinkheading', 'tool_vae')), [
            'class' => 'h6',
        ])
            . \html_writer::tag('p', s(get_string('piecelinkhelp', 'tool_vae')), ['class' => 'small'])
            . $common('link')
            . $field(get_string('pieceurllabel', 'tool_vae'), 'id_url', \html_writer::empty_tag('input', [
                'type' => 'text', 'name' => 'url', 'id' => 'id_url', 'size' => 60, 'required' => 'required',
                'maxlength' => piece_manager::URL_MAXLENGTH,
            ] + $described))
            . $part('link')
            . $submit(get_string('pieceaddlinksubmit', 'tool_vae')), [
                'method' => 'post', 'action' => $url->out(false), 'class' => 'mb-4 tool-vae-spiece-link',
            ]);

        if ($attachable) {
            $choices = [];
            foreach ($attachable as $piece) {
                $words = get_string('piecenature:' . $piece->nature, 'tool_vae');
                if ((string) $piece->title !== '') {
                    $words .= ' - ' . $piece->title;
                }
                // Escaped here: the core writes the label of an option as it is given, and the title is the vaeiste's text.
                $choices[(int) $piece->id] = s($words . ' (' . userdate((int) $piece->timecreated, get_string(
                    'strftimedate',
                    'langconfig'
                )) . ')');
            }
            $out .= \html_writer::tag('form', \html_writer::tag('h4', s(get_string('spieceattach', 'tool_vae')), [
                'class' => 'h6',
            ])
                . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()])
                . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'attach'])
                . $field(
                    get_string('spieceattach', 'tool_vae'),
                    'id_attachpiece',
                    \html_writer::select($choices, 'piece', '', false, ['id' => 'id_attachpiece', 'class' => 'form-select'])
                )
                . $part('attach')
                . $submit(get_string('spieceattach', 'tool_vae')), [
                    'method' => 'post', 'action' => $url->out(false), 'class' => 'mb-4 tool-vae-spiece-attach',
                ]);
        }

        return $out;
    }

    /**
     * The inverse view of a node: the situations tagged on it and the declarations that carry it, in reading.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @param string $rank
     * @param \stdClass[] $situations As situation_manager::tagged() gives them.
     * @param \stdClass[] $declarations Rows of tool_vae_evidence on the node.
     * @param \context_user $context The vaeiste's context.
     * @return string HTML.
     */
    public static function inverse(
        int $candidateid,
        string $fichecode,
        string $rank,
        array $situations,
        array $declarations,
        \context_user $context
    ): string {
        $tree = selfpos_tree::for_record($fichecode);
        $out = self::disclaimer($candidateid);
        $out .= \html_writer::tag('h3', s(get_string('situationsonnode', 'tool_vae', self::node_words($tree, $rank))), [
            'class' => 'h4',
        ]);

        if (!$situations) {
            $out .= \html_writer::tag('p', s(get_string('situationnone', 'tool_vae')), ['class' => 'text-muted']);
        } else {
            $items = [];
            foreach ($situations as $row) {
                $items[] = \html_writer::tag(
                    'li',
                    \html_writer::tag('strong', \html_writer::link(
                        self::url($candidateid, $fichecode, (int) $row->slot),
                        s(self::title($row))
                    )) . \html_writer::div(implode('<br>', self::facts($row, $tree)), 'small'),
                    ['class' => 'mb-3 tool-vae-situation-item', 'data-slot' => (string) (int) $row->slot]
                );
            }
            $out .= \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled tool-vae-situations']);
        }

        $out .= \html_writer::tag('h3', s(get_string('situationdeclarations', 'tool_vae')), ['class' => 'h5']);
        if (!$declarations) {
            $out .= \html_writer::tag('p', s(get_string('situationnodeclaration', 'tool_vae')), ['class' => 'text-muted']);
        }
        foreach ($declarations as $evidence) {
            $out .= \html_writer::div(presenter::declaration($evidence, $context), 'border-start ps-3 mb-3 tool-vae-inverse-decl');
        }

        return $out;
    }

    /**
     * The text the vaeiste had typed when the preparation was closed under them: read from THEIR send only.
     *
     * A text that arrives in the address, or in a send without the session key, is nothing (the rule of
     * selfpos_screen::kept_text()).
     *
     * @return string[] Field => what was typed in it (title, setting, content), only the fields that hold something; empty
     *         unless a form was sent with a valid session key.
     */
    public static function kept_text(): array {
        $sesskey = (string) optional_param('sesskey', '', PARAM_RAW);
        if (!data_submitted() || $sesskey === '' || !confirm_sesskey($sesskey)) {
            return [];
        }
        $kept = [];
        foreach (['title', 'setting', 'content'] as $field) {
            $value = optional_param($field, '', PARAM_RAW);
            if (is_string($value) && trim($value) !== '') {
                $kept[$field] = $value;
            }
        }

        return $kept;
    }

    /**
     * The closed preparation: its message, and what the vaeiste had typed, given back.
     *
     * @param string[] $kept As kept_text() gives it.
     * @return string HTML.
     */
    public static function closed(array $kept): string {
        return self::refused(s(get_string('errorvalidationclosed', 'tool_vae')), $kept);
    }

    /**
     * A situation that may not be written now - the preparation closed, the record hidden: the message, and what the vaeiste
     * had typed, given back, never lost.
     *
     * @param string $message HTML, already escaped (hidden_frameworks::message() escapes its title).
     * @param string[] $kept As kept_text() gives it.
     * @return string HTML.
     */
    public static function refused(string $message, array $kept): string {
        $out = \html_writer::div($message, 'alert alert-' . ($kept ? 'danger' : 'info'));
        if (!$kept) {
            return $out;
        }
        $out .= \html_writer::tag('p', s(get_string('selfposhiddenkept', 'tool_vae')));
        $labels = ['title' => 'situationtitle', 'setting' => 'situationsetting', 'content' => 'situationtext'];
        foreach ($kept as $field => $text) {
            $out .= \html_writer::tag('textarea', s($text), [
                'readonly' => 'readonly', 'rows' => $field === 'content' ? 8 : 1, 'cols' => 70, 'class' => 'form-control mb-3',
                'aria-label' => get_string($labels[$field], 'tool_vae'),
            ]);
        }

        return $out;
    }

    /**
     * The autosave of the text: the script of section.php, on the field `content`, to autosave.php by draftid.
     *
     * WHAT THE SCRIPT KEEPS TRUE is said in section.php: the form carries the time of the draft the page knows, the script
     * writes there each time the autosave is answered, and it stops at the submission of the form.
     *
     * @param int $draftid The draft that holds the text.
     * @param int $workingat The time of the draft in progress the page was drawn with, 0 for none.
     */
    public static function autosave(int $draftid, int $workingat): void {
        global $PAGE;

        $PAGE->requires->js_init_code(
            '(function(cfg){'
            . 'var area=document.getElementById("id_content");var live=document.getElementById("tool-vae-autosave");'
            . 'if(!area||!live||!window.fetch){return;}'
            . 'var form=area.form;var field=form?form.elements.workingat:null;'
            . 'var last=area.value;var known=cfg.workingat;var submitting=false;var timer=null;'
            . 'function say(t){live.textContent=t;}'
            . 'function tick(){'
            . 'if(submitting||area.value===last){return;}'
            . 'var sent=area.value;var body=new FormData();'
            . 'body.append("sesskey",cfg.sesskey);body.append("draftid",cfg.draftid);'
            . 'body.append("text",sent);body.append("workingat",known);'
            . 'fetch(cfg.url,{method:"POST",body:body,credentials:"same-origin"})'
            . '.then(function(r){return r.json();})'
            . '.then(function(j){'
            . 'if(submitting){return;}'
            . 'if(j.error){say(j.error);return;}'
            . 'last=sent;known=j.workingat;if(field){field.value=known;}say(j.message);})'
            . '.catch(function(){if(!submitting){say(cfg.failed);}});'
            . '}'
            . 'function start(){timer=setInterval(tick,20000);}'
            . 'start();'
            . 'if(form){form.addEventListener("submit",function(){submitting=true;clearInterval(timer);});}'
            . 'window.addEventListener("pageshow",function(e){if(e.persisted&&submitting){submitting=false;start();}});'
            . '})(' . json_encode([
                'url' => (new \moodle_url('/admin/tool/vae/autosave.php'))->out(false),
                'sesskey' => sesskey(),
                'draftid' => $draftid,
                'workingat' => $workingat,
                'failed' => get_string('draftautosaveerror', 'tool_vae'),
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) . ');',
            true
        );
    }

    /**
     * The title of a situation as it is read: its own, or "Work situation n" while it has none.
     *
     * @param \stdClass $situation A row of tool_vae_situation.
     * @return string Plain text: the caller escapes it.
     */
    public static function title(\stdClass $situation): string {
        $title = trim((string) $situation->title);

        return $title !== '' ? $title : get_string('validpart:situation', 'tool_vae', (int) $situation->slot);
    }

    /**
     * A period in words: "start - end", a bound that is not given left out.
     *
     * @param int|null $start
     * @param int|null $end
     * @return string Plain text, '' when neither bound is given.
     */
    public static function period(?int $start, ?int $end): string {
        $format = get_string('strftimedate', 'langconfig');
        $bounds = [];
        foreach ([$start, $end] as $bound) {
            if ($bound !== null) {
                $bounds[] = display_date::format($bound, $format);
            }
        }

        return implode(' - ', $bounds);
    }

    /**
     * The address of the pages: the records, the list of a record, the inverse view of a node, or one situation.
     *
     * @param int $candidateid
     * @param string $fichecode Empty for the list of records.
     * @param int|null $slot A situation; null for a list.
     * @param string|null $rank A node, for the inverse view; ignored with a slot.
     * @return \moodle_url
     */
    public static function url(int $candidateid, string $fichecode = '', ?int $slot = null, ?string $rank = null): \moodle_url {
        $params = ['userid' => $candidateid];
        if ($fichecode !== '') {
            $params['fiche'] = $fichecode;
        }
        if ($slot !== null) {
            $params['slot'] = $slot;

            return new \moodle_url('/admin/tool/vae/situation.php', $params);
        }
        if ($rank !== null) {
            $params['rank'] = $rank;
        }

        return new \moodle_url('/admin/tool/vae/situations.php', $params);
    }

    /**
     * The lines that say a situation: period, setting, tags - each only when it has something to say.
     *
     * @param \stdClass $row A row of tool_vae_situation, with tags.
     * @param \stdClass|null $tree The tree of its record, to name the tags.
     * @return string[] HTML lines.
     */
    private static function facts(\stdClass $row, ?\stdClass $tree): array {
        $lines = [];
        $period = self::period(
            $row->periodstart === null ? null : (int) $row->periodstart,
            $row->periodend === null ? null : (int) $row->periodend
        );
        if ($period !== '') {
            $lines[] = \html_writer::span(s(get_string('situationperiod', 'tool_vae')), 'text-muted me-2') . s($period);
        }
        if ((string) $row->setting !== '') {
            $lines[] = \html_writer::span(s(get_string('situationsetting', 'tool_vae')), 'text-muted me-2')
                . s((string) $row->setting);
        }
        if (!empty($row->tags)) {
            $tags = array_map(fn(string $rank) => s(self::node_words($tree, $rank)), $row->tags);
            $lines[] = \html_writer::span(s(get_string('situationtags', 'tool_vae')), 'text-muted me-2') . implode(' ; ', $tags);
        }

        return $lines;
    }

    /**
     * A node in words: its rank and its label, or the rank alone when the record no longer holds it.
     *
     * @param \stdClass|null $tree
     * @param string $rank
     * @return string Plain text.
     */
    private static function node_words(?\stdClass $tree, string $rank): string {
        $node = $tree === null ? null : selfpos_tree::node($tree, $rank);
        if ($node === null) {
            return $rank;
        }

        return $rank . ' - ' . format_string($node->label, true, ['context' => \context_system::instance(), 'escape' => false]);
    }

    /**
     * How a reader gets at a piece of a situation: the link, or the file, to open.
     *
     * @param \stdClass $piece A row of tool_vae_spiece, not removed, that the reader may open.
     * @param \context_user $context
     * @return string HTML.
     */
    private static function piece_access(\stdClass $piece, \context_user $context): string {
        if ($piece->kind === piece_manager::KIND_LINK) {
            // The whole address, as text, and its host as the network reads it; the click is the reader's.
            return \html_writer::div(s((string) $piece->url))
                . \html_writer::div(s(piece_manager::display_host((string) $piece->url)), 'text-muted small')
                . \html_writer::div(\html_writer::link(
                    (string) $piece->url,
                    get_string('piecelinkopen', 'tool_vae'),
                    ['rel' => 'noopener noreferrer', 'target' => '_blank']
                ));
        }

        return \html_writer::div(\html_writer::link(
            \moodle_url::make_pluginfile_url(
                $context->id,
                'tool_vae',
                spiece_manager::FILEAREA,
                (int) $piece->id,
                '/',
                $piece->uuid . '.' . $piece->ext,
                true
            ),
            get_string('pieceopenfile', 'tool_vae')
        ));
    }
}
