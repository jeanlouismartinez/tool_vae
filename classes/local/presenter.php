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
 * Rendering a claim and its attestations.
 *
 * Three real callers - the dossier, the attestation screen and the withdrawal screen - all
 * needing the same block, all needing it escaped the same way. That is the threshold the
 * project rules set for pulling something out into its own place: two real uses, not one
 * anticipated one.
 *
 * ESCAPING IS THE POINT OF THIS FILE. Two fields here are free text written by a user and
 * stored outside the cleaning core applies to its own columns: the narrative and the detail
 * about the setting. They are rendered in the session of a manager who, by definition, can
 * read other people's dossiers - the single most valuable account on the site to attack.
 * The narrative goes through format_text() with the format the candidate chose, which is
 * restricted to plain text and Markdown, HTML having been excluded at the column. Every
 * other field goes through s(). Nothing is concatenated raw.
 *
 * Presentation and accessibility are lot 9's subject. What is here is sober on purpose: a
 * definition list, no colour carrying meaning on its own, no layout that breaks at 320 px.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class presenter {
    /**
     * The certification records this site holds, and where each one comes from.
     *
     * THE PROVENANCE LINE IS AN OBLIGATION, NOT A COURTESY. The Licence Ouverte 2.0 requires the
     * source, the attribution and the publication date to be stated wherever the data is
     * presented, and forbids misleading anyone about them. It is on the administration screen,
     * and it is on the dossier - the two places a human reads a record.
     *
     * The count of declarations is there for one reason: it tells an administrator what a
     * re-import will and will not touch. A record with claims against it is not a line one
     * deletes lightly.
     *
     * @param \stdClass[] $sources Rows of tool_vae_source.
     * @return string HTML.
     */
    public static function sources(array $sources): string {
        global $DB, $OUTPUT;

        $gateway = new competency_gateway();

        $table = new \html_table();
        // THE NAME OF THE CERTIFICATION, THEN ITS REGISTER. The column headed "name of the certification" used to
        // hold the register ("RNCP - national register..."), and the name typed at the import was shown nowhere
        // on this screen: with six records of the same register, the administrator had nothing but the code to
        // tell one from another. The name is the one the import gave the framework, read through the gateway.
        $table->head = [
            get_string('sourcecode', 'tool_vae'),
            get_string('importname', 'tool_vae'),
            get_string('importfichetype', 'tool_vae'),
            get_string('sourcecompetencies', 'tool_vae'),
            get_string('sourcedeclarations', 'tool_vae'),
            get_string('sourcelastimport', 'tool_vae'),
        ];
        $table->attributes['class'] = 'generaltable tool-vae-sources';

        $dateformat = get_string('strftimedate', 'langconfig');

        foreach ($sources as $source) {
            $competencies = $gateway->count_framework_competencies((int) $source->frameworkid);
            $claims = $DB->count_records('tool_vae_evidence', ['fichecode' => $source->fichecode]);

            // WHAT THE IMPORT RECORDED HAS TO BE READ SOMEWHERE. Divergences were written to
            // tool_vae_obsolete and displayed nowhere: a competency that had left the
            // record was faithfully noted and no human ever saw the note. Decision 3 keeps the
            // evidence; it does not excuse keeping quiet about it.
            $gone = $DB->count_records('tool_vae_obsolete', [
                'sourceid' => (int) $source->id,
                'kind' => importer::GONE,
            ]);
            $diverged = $DB->count_records('tool_vae_obsolete', [
                'sourceid' => (int) $source->id,
                'kind' => importer::DIVERGED,
            ]);

            $status = $source->lastimportstatus === 'ok'
                ? get_string('sourcestatusok', 'tool_vae')
                : get_string('sourcestatuserror', 'tool_vae');

            $provenance = self::provenance($source);

            $divergences = '';
            if ($gone || $diverged) {
                $divergences = \html_writer::div(
                    s(get_string('sourcedivergences', 'tool_vae', (object) [
                        'gone' => $gone,
                        'diverged' => $diverged,
                    ])),
                    'small tool-vae-divergence'
                );
            }

            $table->data[] = [
                \html_writer::tag('strong', s($source->fichecode)) . $provenance,
                s((string) $gateway->framework_name((int) $source->frameworkid)),
                s($source->fichetype === 'rs'
                    ? get_string('typers', 'tool_vae')
                    : get_string('typerncp', 'tool_vae')),
                $competencies . $divergences,
                $claims,
                s(display_date::format((int) $source->lastimport, $dateformat)) . ' — ' . s($status),
            ];
        }

        return $OUTPUT->heading(get_string('sourcetable', 'tool_vae'), 3)
            . \html_writer::table($table);
    }

    /**
     * The provenance line of a certification record: attribution, licence, publication date.
     *
     * One place for it, because the Licence Ouverte 2.0 requires it wherever the record's text is
     * shown - the administration screen, and the coverage, which prints the published labels.
     *
     * @param \stdClass $source A row of tool_vae_source.
     * @return string HTML.
     */
    private static function provenance(\stdClass $source): string {
        // A record whose first import failed holds no published version yet, and a date of
        // zero would announce 1 January 1970 as the day France competences published it.
        return \html_writer::div(
            s(empty($source->sourceupdated)
                ? get_string('sourceprovenancepending', 'tool_vae', (object) [
                    'attribution' => $source->attribution,
                    'licence' => $source->licence,
                ])
                : get_string('sourceprovenance', 'tool_vae', (object) [
                    'attribution' => $source->attribution,
                    'licence' => $source->licence,
                    'updated' => display_date::format((int) $source->sourceupdated, get_string('strftimedate', 'langconfig')),
                ])),
            'small text-muted'
        );
    }

    /**
     * Where the certifier publishes its rule of validation (T-41): addresses, and a sentence that says what the plugin does not do.
     *
     * ONE box for the whole dossier, ahead of the section of the figures and not between a record's heading and its table:
     * set beside a count, a rule becomes a target (D-1). In no colour, never on the line of a bloc. Nothing is quoted, so
     * nothing can be read as the rule of the VAE when it is not. The plugin never opens an address; the click is the reader's,
     * in a new tab, with the address in full under the pointer.
     *
     * @param \stdClass[][] $refs The addresses by record code, as ref_manager::for_fiches() gives them.
     * @return string HTML, empty when no record has an address.
     */
    private static function rule_refs(array $refs): string {
        $lines = [];
        foreach ($refs as $code => $list) {
            $lines[] = \html_writer::tag('li', \html_writer::tag('strong', s($code)) . ' : ' . self::rule_links($list));
        }
        if (!$lines) {
            return '';
        }

        return \html_writer::div(
            \html_writer::tag('strong', s(get_string('refrulelabel', 'tool_vae')))
                . \html_writer::tag('ul', implode('', $lines), ['class' => 'mb-1'])
                . \html_writer::tag('p', s(get_string('refsentence', 'tool_vae')), ['class' => 'mb-0']),
            'small border rounded p-2 my-2 tool-vae-rule-refs'
        );
    }

    /**
     * The links of one record, with the day each page was checked.
     *
     * @param \stdClass[] $refs The addresses of one record.
     * @return string HTML.
     */
    private static function rule_links(array $refs): string {
        $dateformat = get_string('strftimedate', 'langconfig');
        $links = [];
        foreach ($refs as $ref) {
            // The address in full, and the host as the network reads it when it is not plain ASCII: a look-alike letter shows.
            $host = piece_manager::display_host($ref->url);
            $tip = strpos($ref->url, $host) === false ? $ref->url . ' (' . $host . ')' : $ref->url;
            $link = \html_writer::link($ref->url, s($ref->title), [
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
                'title' => $tip,
            ]);
            if ($ref->checkedat !== null) {
                // A day, stored at noon UTC and shown in UTC: the same day for a reader in Paris and one in Guadeloupe.
                $link .= ' ' . s(get_string('refcheckedon', 'tool_vae', display_date::format($ref->checkedat, $dateformat, 'UTC')));
            }
            $links[] = $link;
        }

        return implode(' ; ', $links);
    }

    /**
     * What a candidate says is outside their activity (T-42): a list of their own marks, and the sentence that says it exempts
     * from nothing.
     *
     * A section of its own, apart from the coverage: the figures of a record are not set beside it, and nothing is counted from
     * it. The reader is the candidate, a person who follows them, or someone who reads every dossier: the right to read the
     * dossier is the caller's to check.
     *
     * @param \stdClass[] $marks As scope_manager::for_candidate() gives them.
     * @param string[] $labels As scope_manager::labels() gives them.
     * @param bool $isown Whether the reader is the candidate, who is given the way to change it.
     * @param bool $open Whether the site lets a candidate add a mark.
     * @return string HTML, empty when there is nothing to show.
     */
    public static function scope_box(array $marks, array $labels, bool $isown, bool $open): string {
        global $OUTPUT;

        if (!$marks && !($isown && $open)) {
            return '';
        }

        $dateformat = get_string('strftimedate', 'langconfig');
        $out = \html_writer::start_tag('section', ['class' => 'tool-vae-scope mb-4']);
        $out .= $OUTPUT->heading(get_string('scopedossierheading', 'tool_vae'), 3);
        if ($marks) {
            $items = [];
            foreach ($marks as $key => $mark) {
                $line = \html_writer::tag('strong', s($key)) . (($labels[$key] ?? '') === '' ? '' : ' — ' . s($labels[$key]));
                $said = display_date::format((int) $mark->timecreated, $dateformat);
                $line .= ' ' . s(get_string('scopesaidonshort', 'tool_vae', $said));
                if ($mark->note !== null && $mark->note !== '') {
                    $line .= \html_writer::div(s($mark->note), 'small');
                }
                $items[] = \html_writer::tag('li', $line);
            }
            $out .= \html_writer::tag('ul', implode('', $items));
            $out .= \html_writer::tag('p', s(get_string('scopesentence', 'tool_vae')), ['class' => 'small text-muted']);
        } else {
            $out .= \html_writer::tag('p', s(get_string('scopenone', 'tool_vae')));
        }
        if ($isown) {
            $out .= \html_writer::div(\html_writer::link(
                new \moodle_url('/admin/tool/vae/scope.php'),
                get_string('scopemanage', 'tool_vae')
            ));
        }

        return $out . \html_writer::end_tag('section');
    }

    /**
     * The coverage of a dossier, one table per certification record (R3).
     *
     * Plain counts and a date, nothing else: no percentage, no total, no colour that ranks one
     * line above another. A competency with nothing declared shows its zeros like any other line,
     * with no mark drawing the eye to it - the candidate reads the gap, nobody points at it.
     *
     * The competency cell is the row header, so a screen reader announces which competency a
     * number belongs to; the caption names the record for whoever navigates by table.
     *
     * @param \stdClass[] $fiches What coverage::for_candidate() returns.
     * @return string HTML, empty when there is nothing to show.
     */
    public static function coverage(array $fiches): string {
        global $OUTPUT;

        if (!$fiches) {
            return '';
        }

        $dateformat = get_string('strftimedate', 'langconfig');

        // The addresses where the certifier publishes its rule of validation (T-41): shown only once the site has opened them.
        $refs = empty(get_config('tool_vae', 'refopen')) ? [] : ref_manager::for_fiches(array_column($fiches, 'fichecode'));

        // Ahead of the section, so that the figures of no record sit under it (T-41).
        $out = self::rule_refs($refs);
        $out .= \html_writer::start_tag('section', ['class' => 'tool-vae-coverage mb-4']);
        $out .= $OUTPUT->heading(get_string('coverage', 'tool_vae'), 3);
        $out .= \html_writer::tag('p', s(get_string('coverageintro', 'tool_vae')));

        foreach ($fiches as $fiche) {
            $title = $fiche->fichecode;
            if ($fiche->source) {
                $title .= ' — ' . get_string($fiche->source->fichetype === 'rs' ? 'typers' : 'typerncp', 'tool_vae');
            }
            $out .= $OUTPUT->heading(s($title), 4);
            if ($fiche->source) {
                $out .= self::provenance($fiche->source);
            }

            if (!empty($fiche->hasblocs)) {
                $out .= \html_writer::tag('p', s(get_string('coverageblocnote', 'tool_vae')), ['class' => 'small text-muted']);
            }

            $table = new \html_table();
            $table->caption = get_string('coveragecaption', 'tool_vae', $fiche->fichecode);
            $table->captionhide = true;
            $table->head = [
                get_string('coveragecompetency', 'tool_vae'),
                get_string('coveragedeclarations', 'tool_vae'),
                get_string('coverageattested', 'tool_vae'),
                get_string('coveragelastattested', 'tool_vae'),
            ];
            $table->attributes['class'] = 'generaltable tool-vae-coverage-table';

            foreach ($fiche->competencies as $row) {
                $label = s($row->idnumber);
                if ($row->shortname !== null) {
                    $label .= ' — ' . s($row->shortname);
                }
                if ($row->gone) {
                    $label .= \html_writer::div(s(get_string('coveragegone', 'tool_vae')), 'small');
                }

                // A bloc line says so in words and adds up its competencies (T-33): counts, nothing else. A
                // competency under a bloc is set in.
                $declarations = $row->declarations;
                $attested = $row->attested;
                $last = $row->lastattested;
                if (!empty($row->isbloc)) {
                    $label = \html_writer::tag('strong', $label) . ' '
                        . \html_writer::tag('span', s(get_string('coveragebloctag', 'tool_vae')), ['class' => 'small text-muted']);
                    $declarations = $row->blocdeclarations;
                    $attested = $row->blocattested;
                    $last = $row->bloclastattested;
                }

                $competency = new \html_table_cell($label);
                $competency->header = true;
                $competency->scope = 'row';
                if (!empty($row->bloc)) {
                    $competency->attributes['class'] = 'ps-4';
                }

                $table->data[] = [
                    $competency,
                    (string) $declarations,
                    (string) $attested,
                    $last === null ? '' : s(display_date::format($last, $dateformat)),
                ];
            }

            $out .= \html_writer::table($table);
        }

        return $out . \html_writer::end_tag('section');
    }

    /**
     * The warning a claim carries when its anchor no longer says what this plugin wrote.
     *
     * Rendered as a notification of the core, so it inherits the core's markup, its colours in
     * every theme, and its behaviour with a screen reader - a warning nobody can hear is not a
     * warning. Placed BEFORE the claim rather than after it: a reader who has already taken the
     * certification record at face value has read the wrong thing.
     *
     * @param \stdClass $evidence A tool_vae_evidence row.
     * @param string|null $divergence One of the anchor constants, or null.
     * @return string HTML, empty when there is nothing to say.
     */
    public static function anchor_warning(\stdClass $evidence, ?string $divergence): string {
        global $OUTPUT;

        if ($divergence === null) {
            return '';
        }

        $message = get_string('anchor' . $divergence, 'tool_vae', (object) [
            'record' => s($evidence->fichecode . '-' . $evidence->ficherang),
        ]);

        return $OUTPUT->notification($message, \core\output\notification::NOTIFY_WARNING);
    }

    /**
     * What a declaration says of its carries to the record that replaces its own (T-30, lot 3).
     *
     * Two sentences at most, never colour alone: where it was carried to, and where it comes from - with the
     * reminder that the carried one has to be attested again and that the old attestation stays where it is.
     *
     * @param \stdClass $notes What carry_manager::notes() returns.
     * @return string HTML, empty when the declaration has no carry.
     */
    public static function carry_notes(\stdClass $notes): string {
        $dateformat = get_string('strftimedate', 'langconfig');
        $describe = function (\stdClass $row) use ($dateformat): \stdClass {
            $decision = '';
            if ((int) $row->decidedat > 0) {
                $decision = get_string('carrydecision', 'tool_vae', (object) [
                    'who' => $row->decidername === '' ? get_string('carrywhoadmin', 'tool_vae') : s($row->decidername),
                    'when' => s(display_date::format((int) $row->decidedat, $dateformat)),
                ]);
            }

            return (object) [
                'record' => s($row->record),
                'when' => s(display_date::format((int) $row->carriedat, $dateformat)),
                'decision' => $decision,
            ];
        };

        $out = '';
        if ($notes->carriedto) {
            $out .= \html_writer::tag('p', get_string('carriedto', 'tool_vae', $describe($notes->carriedto)), [
                'class' => 'tool-vae-carry small text-muted',
            ]);
        }
        if ($notes->carriedfrom) {
            $out .= \html_writer::tag('p', get_string('carriedfrom', 'tool_vae', $describe($notes->carriedfrom)), [
                'class' => 'tool-vae-carry small text-muted',
            ]);
        }

        return $out;
    }

    /**
     * One claim, rendered for reading.
     *
     * @param \stdClass $evidence A tool_vae_evidence row.
     * @param \context $context The candidate's user context, for format_text().
     * @return string HTML.
     */
    public static function declaration(\stdClass $evidence, \context $context): string {
        $rows = [
            get_string('fichesource', 'tool_vae')
                => s($evidence->fichecode . '-' . $evidence->ficherang),
        ];

        // What the code stands for. The page used to give the code alone - the PDF gave the
        // name - and a manager signing, or a candidate reading, had to know the framework by heart.
        // Absent when the name cannot be read: the code above stays.
        $name = ($evidence->competencyid ?? null) === null
            ? null
            : (new competency_gateway())->competency_name((int) $evidence->competencyid);
        if ($name !== null && trim($name) !== '') {
            $rows[get_string('competency', 'tool_vae')] = s($name);
        }

        // The bloc it sits under, when the record has blocs (T-33).
        $bloc = ($evidence->competencyid ?? null) === null
            ? null
            : (new competency_gateway())->bloc_of((int) $evidence->competencyid);
        if ($bloc !== null) {
            $rows[get_string('declarationbloc', 'tool_vae')] = s($bloc->idnumber . ' — ' . $bloc->label);
        }

        $rows += [
            get_string('ficheupdated', 'tool_vae')
                => s(display_date::format((int) $evidence->ficheupdated, get_string('strftimedate', 'langconfig'))),
            get_string('period', 'tool_vae') => s(get_string(
                'periodfromto',
                'tool_vae',
                (object) [
                    'from' => display_date::format((int) $evidence->periodstart, get_string('strftimedate', 'langconfig')),
                    'to' => display_date::format((int) $evidence->periodend, get_string('strftimedate', 'langconfig')),
                ]
            )),
            get_string('settingtype', 'tool_vae')
                => s(get_string('setting:' . $evidence->settingtype, 'tool_vae')),
        ];

        if (trim((string) $evidence->settingdetail) !== '') {
            $rows[get_string('settingdetail', 'tool_vae')] = s($evidence->settingdetail);
        }

        $out = \html_writer::start_tag('dl', ['class' => 'tool-vae-claim']);
        foreach ($rows as $label => $value) {
            $out .= \html_writer::tag('dt', s($label));
            $out .= \html_writer::tag('dd', $value);
        }
        $out .= \html_writer::end_tag('dl');

        $out .= \html_writer::tag('h3', s(get_string('situation', 'tool_vae')));
        $out .= \html_writer::div(
            format_text(
                $evidence->situation,
                (int) $evidence->situationformat,
                ['context' => $context, 'para' => true]
            ),
            'tool-vae-situation'
        );

        return $out;
    }

    /**
     * The supporting pieces of a declaration, as a list (T-13, lot 2).
     *
     * One renderer for the candidate, for the manager who attests and for whoever only reads the dossier. Everything free is
     * escaped; an address is shown whole, as text, and opened only by a click that leaves the platform. A piece the reader
     * may not open is listed and not offered.
     *
     * Against the signature standing on the declaration: a piece the signature did not cover is marked as added after it, and a
     * piece it covered that was removed since is said so. For a reader who is not the candidate, whether they opened a piece
     * is said - opening is not reading, and the line says so once.
     *
     * @param \stdClass[] $pieces Rows of tool_vae_piece, removed ones included.
     * @param \context_user $context The candidate's context.
     * @param \stdClass|null $standing The attestation in force, if any.
     * @param callable|null $actions Given a piece, returns the HTML of what its author may do to it (a button); candidate only.
     * @return string
     */
    public static function pieces(
        array $pieces,
        \context_user $context,
        ?\stdClass $standing = null,
        ?callable $actions = null
    ): string {
        global $USER;

        if (!$pieces) {
            return \html_writer::tag('p', s(get_string('piecenone', 'tool_vae')));
        }

        $covered = $standing ? piece_manager::covered_by((int) $standing->id) : null;
        $reader = (int) $USER->id !== (int) $context->instanceid;
        $opened = $reader ? piece_manager::opened_by((int) $USER->id, array_map(fn($piece) => (int) $piece->id, $pieces)) : [];
        $date = get_string('strftimedatetime', 'langconfig');

        $items = '';
        foreach ($pieces as $piece) {
            $line = \html_writer::tag('strong', s(get_string('piecenature:' . $piece->nature, 'tool_vae')));
            // The title is a free field: shown to whoever may open the piece, and not to a reader who may only see that it exists.
            if ($piece->title !== '' && piece_manager::may_open($piece)) {
                $line .= ' - ' . s($piece->title);
            }
            $details = [];
            if ($piece->kind === piece_manager::KIND_FILE) {
                $details[] = s(strtoupper((string) $piece->ext) . ', ' . display_size((int) $piece->filesize));
                $details[] = s(get_string('piecedigest', 'tool_vae') . ' ' . $piece->sha256);
            }
            $details[] = s(userdate($piece->timecreated, $date));
            $line .= \html_writer::tag('div', implode(' - ', $details), ['class' => 'text-muted small']);

            if ($piece->timeremoved !== null) {
                $removed = get_string('pieceremovedon', 'tool_vae', userdate($piece->timeremoved, $date));
                $line .= \html_writer::tag('div', s($removed), ['class' => 'text-muted']);
                if ($covered !== null && array_key_exists((int) $piece->id, $covered)) {
                    $after = get_string('pieceremovedaftersignature', 'tool_vae');
                    $line .= \html_writer::tag('div', s($after), ['class' => 'text-muted']);
                }
            } else {
                if ($covered !== null && !array_key_exists((int) $piece->id, $covered)) {
                    $line .= \html_writer::tag('div', s(get_string(
                        'piecesaftersignature',
                        'tool_vae',
                        userdate((int) $standing->attestedat, $date)
                    )), ['class' => 'alert alert-warning py-1']);
                }
                $line .= self::piece_access($piece, $context, $reader, $opened);
                if ($actions) {
                    $line .= $actions($piece);
                }
            }
            $items .= \html_writer::tag('li', $line, ['class' => 'mb-3 tool-vae-piece']);
        }

        $out = \html_writer::tag('ul', $items, ['class' => 'list-unstyled tool-vae-pieces']);
        if ($reader) {
            $active = array_filter($pieces, fn($piece) => $piece->timeremoved === null
                && $piece->kind === piece_manager::KIND_FILE && piece_manager::may_open($piece));
            $count = count(array_filter($active, fn($piece) => isset($opened[(int) $piece->id])));
            $out .= \html_writer::tag('p', s(get_string('piecesopenedcount', 'tool_vae', (object) [
                'opened' => $count,
                'total' => count($active),
            ])), ['class' => 'text-muted small']);
        }

        return $out;
    }

    /**
     * How a reader gets at a piece: the link or the file to open, or the word that they may not.
     *
     * @param \stdClass $piece
     * @param \context_user $context
     * @param bool $reader Whether the reader is somebody else than the candidate.
     * @param bool[] $opened Piece id => true for those this reader opened.
     * @return string
     */
    private static function piece_access(\stdClass $piece, \context_user $context, bool $reader, array $opened): string {
        if (!piece_manager::may_open($piece)) {
            return \html_writer::tag('div', s(get_string('piecelistonly', 'tool_vae')), ['class' => 'text-muted']);
        }

        if ($piece->kind === piece_manager::KIND_LINK) {
            // The whole address, as text, and its host as the network reads it; the click is the reader's.
            $out = \html_writer::tag('div', s($piece->url))
                . \html_writer::tag('div', s(piece_manager::display_host($piece->url)), ['class' => 'text-muted small'])
                . \html_writer::tag('div', \html_writer::link(
                    $piece->url,
                    get_string('piecelinkopen', 'tool_vae'),
                    ['rel' => 'noopener noreferrer', 'target' => '_blank']
                ));
        } else {
            $out = \html_writer::tag('div', \html_writer::link(
                \moodle_url::make_pluginfile_url(
                    $context->id,
                    'tool_vae',
                    piece_manager::FILEAREA,
                    (int) $piece->id,
                    '/',
                    $piece->uuid . '.' . $piece->ext,
                    true
                ),
                get_string('pieceopenfile', 'tool_vae')
            ));
        }
        // A link is not fetched by the server: its opening is not known, and is not claimed.
        if ($reader && $piece->kind === piece_manager::KIND_FILE) {
            $out .= \html_writer::tag(
                'div',
                s(get_string(isset($opened[(int) $piece->id]) ? 'pieceopenedyes' : 'pieceopenedno', 'tool_vae')),
                ['class' => 'text-muted small']
            );
        }

        return $out;
    }

    /**
     * The label of the button that records an attestation on a declaration.
     *
     * T-60. A declaration whose attestation was withdrawn offered the same button as one never
     * attested, and the reader could not tell that this would be a new attestation, not the
     * old one restored. Called only when no attestation stands.
     *
     * @param \stdClass[] $attestations Rows of tool_vae_attest, none of them in force.
     * @return string
     */
    public static function attest_label(array $attestations): string {
        return get_string($attestations ? 'attestagain' : 'attest', 'tool_vae');
    }

    /** @var string[] The states a declaration reads as, in the order the legend gives them. */
    public const STATES = ['declared', 'attested', 'withdrawn'];

    /**
     * The state a declaration is in, as one word of the legend (T-40).
     *
     * DESCRIBES, NEVER JUDGES. The state says what was done to the declaration: declared and not yet
     * attested, attested, or attested then withdrawn. It says nothing of whether it is good enough.
     *
     * @param \stdClass[] $attestations Rows of tool_vae_attest, oldest first.
     * @return string One of self::STATES.
     */
    public static function state(array $attestations): string {
        foreach ($attestations as $attestation) {
            if ($attestation->withdrawnat === null) {
                return 'attested';
            }
        }

        return $attestations ? 'withdrawn' : 'declared';
    }

    /**
     * The state of a declaration, written out, with a question waiting when there is one.
     *
     * Text, never colour alone.
     *
     * @param \stdClass[] $attestations
     * @param int $openrequests How many questions still wait for an answer.
     * @return string HTML.
     */
    public static function state_label(array $attestations, int $openrequests = 0): string {
        $out = \html_writer::tag(
            'span',
            s(get_string('state:' . self::state($attestations), 'tool_vae')),
            ['class' => 'badge bg-secondary text-white tool-vae-state']
        );
        if ($openrequests > 0) {
            $out .= ' ' . \html_writer::tag(
                'span',
                s(get_string('state:questionopen', 'tool_vae')),
                ['class' => 'badge bg-info text-dark tool-vae-state']
            );
        }

        return $out;
    }

    /**
     * The legend of the states, once, above the declarations (T-40).
     *
     * @return string HTML.
     */
    public static function state_legend(): string {
        $items = [];
        foreach (array_merge(self::STATES, ['questionopen']) as $state) {
            $items[] = \html_writer::tag('strong', s(get_string('state:' . $state, 'tool_vae')))
                . ' - ' . s(get_string('state:' . $state . '_help', 'tool_vae'));
        }

        return \html_writer::tag(
            'details',
            \html_writer::tag('summary', s(get_string('statelegendheading', 'tool_vae')))
            . \html_writer::alist($items),
            ['class' => 'tool-vae-legend mb-3']
        );
    }

    /**
     * The acts done on a declaration, in order of date (T-40).
     *
     * ACTS AND DATES ONLY. No name, no text: the dates are what the dossier already shows, put in
     * order. A withdrawn attestation appears twice, signed and withdrawn.
     *
     * @param \stdClass $evidence A row of tool_vae_evidence.
     * @param \stdClass[] $attestations Rows of tool_vae_attest.
     * @param \stdClass[] $requests Rows of tool_vae_request.
     * @param array[] $carries Acts of a carry (T-30, lot 3), each ['time' => int, 'act' => 'carriedto' or
     *        'carriedfrom'].
     * @return array[] Each ['time' => int, 'act' => string], sorted by time.
     */
    public static function chronology(\stdClass $evidence, array $attestations, array $requests, array $carries = []): array {
        $acts = [['time' => (int) $evidence->timecreated, 'act' => 'declared']];
        foreach ($carries as $carry) {
            $acts[] = ['time' => (int) $carry['time'], 'act' => $carry['act']];
        }
        foreach ($requests as $request) {
            $acts[] = ['time' => (int) $request->timecreated, 'act' => 'questionasked'];
            if ($request->timeanswered !== null) {
                $acts[] = ['time' => (int) $request->timeanswered, 'act' => 'questionanswered'];
            }
        }
        foreach ($attestations as $attestation) {
            $acts[] = [
                'time' => (int) ($attestation->attestedat ?? $attestation->timecreated),
                'act' => 'attested',
            ];
            if ($attestation->withdrawnat !== null) {
                $acts[] = ['time' => (int) $attestation->withdrawnat, 'act' => 'withdrawn'];
            }
        }
        // Stable: acts of the same second keep the order they were added in.
        foreach ($acts as $i => &$act) {
            $act['i'] = $i;
        }
        unset($act);
        usort($acts, fn($a, $b) => [$a['time'], $a['i']] <=> [$b['time'], $b['i']]);

        return array_map(fn($a) => ['time' => $a['time'], 'act' => $a['act']], $acts);
    }

    /**
     * The chronology of a declaration, written out (T-40).
     *
     * @param \stdClass $evidence
     * @param \stdClass[] $attestations
     * @param \stdClass[] $requests
     * @param array[] $carries As for chronology().
     * @return string HTML.
     */
    public static function chronology_html(
        \stdClass $evidence,
        array $attestations,
        array $requests,
        array $carries = []
    ): string {
        $items = [];
        foreach (self::chronology($evidence, $attestations, $requests, $carries) as $act) {
            $items[] = \html_writer::tag('span', s(display_date::format(
                $act['time'],
                get_string('strftimedate', 'langconfig')
            )), ['class' => 'text-muted']) . ' - ' . s(get_string('chrono:' . $act['act'], 'tool_vae'));
        }

        return \html_writer::tag(
            'details',
            \html_writer::tag('summary', s(get_string('chronologyheading', 'tool_vae')))
            . \html_writer::alist($items, ['class' => 'tool-vae-chronology']),
            ['class' => 'mb-2']
        );
    }

    /**
     * The attestation history of a claim.
     *
     * A withdrawn attestation still appears, saying only that it existed and when it was
     * withdrawn. That sentence is what the dossier is left with, and it is the point of
     * holding the signature in this plugin rather than on the core record.
     *
     * The way out (the vaeiste can ask a manager to attest again) is said only when no attestation
     * stands, and once, after the last one withdrawn: next to an attestation in force it would
     * contradict the dossier. The PDF and the copies say it the same way.
     *
     * @param \stdClass[] $attestations Rows of tool_vae_attest, oldest first.
     * @return string HTML.
     */
    public static function attestations(array $attestations): string {
        if (!$attestations) {
            return \html_writer::div(s(get_string('noattestationyet', 'tool_vae')), 'text-muted');
        }

        $standing = (bool) array_filter($attestations, fn($attestation) => $attestation->withdrawnat === null);
        $last = array_key_last($attestations);

        $items = [];
        foreach ($attestations as $key => $attestation) {
            if ($attestation->withdrawnat !== null) {
                $sentence = get_string('attestationwithdrawnon', 'tool_vae', (object) [
                    'date' => display_date::format((int) $attestation->withdrawnat, get_string('strftimedate', 'langconfig')),
                    'reason' => get_string('withdrawreason:' . $attestation->withdrawnreason, 'tool_vae'),
                ]);
                if (!$standing && $key === $last) {
                    $sentence .= ' ' . get_string('attestationwithdrawnagain', 'tool_vae');
                }
                $items[] = s($sentence);
                continue;
            }

            $item = \html_writer::tag('strong', s($attestation->attestantrole))
                . \html_writer::empty_tag('br')
                . nl2br(s($attestation->statement))
                . \html_writer::empty_tag('br')
                . \html_writer::tag('span', s(get_string('attestedon', 'tool_vae', display_date::format(
                    (int) $attestation->attestedat,
                    get_string('strftimedate', 'langconfig')
                ))), ['class' => 'text-muted']);

            // A signature given elsewhere says so: nobody on this site can vouch for it.
            if (!empty($attestation->importedat)) {
                $item .= \html_writer::empty_tag('br')
                    . \html_writer::tag('span', s(get_string('attestationimportedon', 'tool_vae', display_date::format(
                        (int) $attestation->importedat,
                        get_string('strftimedate', 'langconfig')
                    ))), ['class' => 'text-muted']);
            }

            $items[] = $item;
        }

        return \html_writer::alist($items, ['class' => 'tool-vae-attestations']);
    }
}
