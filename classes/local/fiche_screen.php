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
 * What the screens of the import of records from an export of France compétences show: the preview and the result (0.47.0).
 *
 * ALL THE HTML IN ONE PLACE, SO THAT THE ESCAPING CAN BE TESTED. Everything that comes from the export or from the server - the
 * titles, the labels, the state, the end of registration, the names of the files, the sentences of a refusal - goes through s()
 * or has already been made safe by fiche_messages; nothing is passed to the page as it was read. This class renders a plan; it
 * decides nothing, and it never reads the archive again.
 *
 * WHAT THE PREVIEW SAYS THAT THE FORM OF THE CSV FILE NEVER COULD. The title of each record as the source publishes it, whole: a
 * number that is wrong but exists imports another certification with no error, and the only protection is to read the title
 * before confirming. What the export holds for each number, what Moodle holds, and what the import will do, side by side.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fiche_screen {
    /**
     * The preview of a plan: source, date, warnings, and a table with a line for each number asked for.
     *
     * @param \stdClass $plan As fiche_import builds it.
     * @param int|null $now The time, for a test; else the clock of the core.
     * @return string HTML.
     */
    public static function preview(\stdClass $plan, ?int $now = null): string {
        $now ??= \core\di::get(\core\clock::class)->time();
        $out = '';

        $out .= \html_writer::tag('p', self::source($plan));
        $out .= \html_writer::tag('p', get_string('fcimportexportdate', 'tool_vae', self::day((int) $plan->exportts))
            . ' ' . get_string('fcimportdatenote', 'tool_vae', self::day(fiche_import::to_timestamp($plan->innerdates->blocs))));
        foreach (self::warnings($plan, $now) as $warning) {
            $out .= \html_writer::div($warning, 'alert alert-warning');
        }

        $out .= \html_writer::div(get_string('fcimportcheck', 'tool_vae'), 'alert alert-info');

        $ready = 0;
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable tool-vae-fcimport';
        $table->caption = get_string('fcimportcaption', 'tool_vae');
        $table->head = [
            get_string('fcimportcolfiche', 'tool_vae'),
            get_string('fcimportcolexport', 'tool_vae'),
            get_string('fcimportcolmoodle', 'tool_vae'),
            get_string('fcimportcolaction', 'tool_vae'),
        ];
        $anyheld = false;
        foreach ($plan->records as $record) {
            $table->data[] = self::line($record, $now, (int) $plan->exportts);
            $ready += $record->status === 'ready' ? 1 : 0;
            $anyheld = $anyheld || ($record->status === 'ready' && $record->state !== 'new');
        }
        $out .= \html_writer::table($table);

        if (!$ready) {
            $out .= \html_writer::div(get_string('fcimportnone', 'tool_vae'), 'alert alert-danger');
        }

        $out .= \html_writer::tag('p', get_string('fcimportprovenance', 'tool_vae', (object) [
            'source' => \html_writer::link(fiche_import::SOURCE_URL, 'data.gouv.fr'),
            'attribution' => s(fiche_import::ATTRIBUTION),
            'licence' => s(fiche_import::LICENCE),
            'date' => self::day((int) $plan->exportts),
        ]), ['class' => 'small']);
        if ($anyheld) {
            $out .= \html_writer::tag('p', get_string('fcimportgonenote', 'tool_vae'), ['class' => 'small']);
        }
        $out .= \html_writer::tag('p', get_string('fcimportvisible', 'tool_vae'), ['class' => 'small']);

        return $out;
    }

    /**
     * The warnings of a plan: things worth a look that stop nothing.
     *
     * @param \stdClass $plan
     * @param int|null $now The time, for a test; else the clock of the core.
     * @return string[] HTML that is safe.
     */
    public static function warnings(\stdClass $plan, ?int $now = null): array {
        $now ??= \core\di::get(\core\clock::class)->time();
        $warnings = [];

        $age = intdiv($now - (int) $plan->exportts, DAYSECS);
        if ($age >= fiche_import::STALE_DAYS) {
            // The export that was just fetched is the latest there is: the advice to fetch it would be circular.
            $warnings[] = get_string(
                $plan->origin === fiche_import::ORIGIN_FETCH ? 'fcimportwarnstalefetch' : 'fcimportwarnstale',
                'tool_vae',
                $age
            );
        }

        $first = fiche_import::to_timestamp($plan->innerdates->blocs);
        $second = fiche_import::to_timestamp($plan->innerdates->standard);
        if (abs((int) $plan->exportts - $first) > fiche_import::GAP_DAYS * DAYSECS) {
            $warnings[] = get_string('fcimportwarngap', 'tool_vae', (object) [
                'export' => self::day((int) $plan->exportts),
                'inner' => self::day($first),
            ]);
        }
        if ($first !== $second) {
            $warnings[] = get_string('fcimportwarndates', 'tool_vae', (object) [
                'first' => self::day($first),
                'second' => self::day($second),
            ]);
        }
        foreach ($plan->repeated as $number) {
            $warnings[] = get_string('fcimportwarnrepeated', 'tool_vae', s($number));
        }

        return $warnings;
    }

    /**
     * The result of the confirmation: what became of each record, and the way to say that one replaces another.
     *
     * @param \stdClass $plan A plan with its results.
     * @return string HTML.
     */
    public static function results(\stdClass $plan): string {
        $items = '';
        $imported = false;
        foreach ($plan->results as $result) {
            $code = s($result->number);
            switch ($result->status) {
                case 'imported':
                    $imported = true;
                    $text = get_string('fcimportresultline', 'tool_vae', (object) ['code' => $code, 'summary' => $result->html]);
                    break;
                case 'failed':
                    $text = get_string('fcimportresultfailed', 'tool_vae', (object) ['code' => $code, 'reason' => $result->html]);
                    break;
                case 'older':
                    $text = get_string('fcimportresultolder', 'tool_vae', $code);
                    break;
                case 'refused':
                    $text = get_string('fcimportresultrefused', 'tool_vae', (object) ['code' => $code, 'reason' => $result->html]);
                    break;
                default:
                    $text = get_string('fcimportresultnotrun', 'tool_vae', $code);
                    break;
            }
            $items .= \html_writer::tag('li', $text);
        }
        $out = \html_writer::tag('ul', $items, ['class' => 'tool-vae-fcimport-results']);

        if ($imported) {
            $link = \html_writer::link(
                new \moodle_url('/admin/tool/vae/supersession.php'),
                get_string('supersessionheading', 'tool_vae')
            );
            $out .= \html_writer::tag('p', get_string('fcimportnextreplace', 'tool_vae', $link));
        }

        return $out;
    }

    /**
     * The line of a record in the table.
     *
     * @param \stdClass $record
     * @param int $now
     * @param int $exportts The day of the export, which a refusal may name.
     * @return array The four cells.
     */
    private static function line(\stdClass $record, int $now, int $exportts): array {
        $number = \html_writer::tag('strong', s($record->number));
        $title = $record->title === '' ? '' : \html_writer::div(s(trim((string) preg_replace('/\s+/u', ' ', $record->title))));

        // The first cell is the header of its row: a reader of a screen reads "RNCP36832, ..." before each of the others.
        $first = new \html_table_cell($number . $title);
        $first->header = true;
        $first->scope = 'row';

        if ($record->status !== 'ready') {
            // The sentence is made safe by fiche_messages::html; the plan keeps the reason and the parameters, not the text.
            $why = fiche_messages::html(fiche_refusal::from_array((array) $record->refusal), $exportts);

            // One cell for the three others: a refusal has nothing to say about the export, Moodle or the action, only why.
            $reason = new \html_table_cell(
                \html_writer::div($why) . \html_writer::div(get_string('fcimportrefusedfoot', 'tool_vae'), 'fw-bold')
            );
            $reason->colspan = 3;

            return [$first, $reason];
        }

        return [
            $first,
            self::export_cell($record, $now),
            self::moodle_cell($record),
            self::action_cell($record, $exportts),
        ];
    }

    /**
     * What the export holds for a record: its blocs (folded away), its state, the end of its registration, what was shortened.
     *
     * @param \stdClass $record
     * @param int $now
     * @return string
     */
    private static function export_cell(\stdClass $record, int $now): string {
        $blocs = '';
        foreach ($record->rows as $row) {
            // The published label, whole: a bloc whose title was shortened carries it in the description.
            $label = (string) $row->description !== '' ? $row->description : $row->intitule;
            $blocs .= \html_writer::tag('li', \html_writer::tag('strong', s($row->rang)) . ' ' . s($label));
        }
        $out = \html_writer::tag(
            'details',
            \html_writer::tag('summary', get_string('fcimportblocs', 'tool_vae', (int) $record->blocs))
                . \html_writer::tag('ul', $blocs)
        );

        if ($record->active !== null && $record->active !== '') {
            $state = $record->active === 'ACTIVE'
                ? get_string('fcimportstateactive', 'tool_vae')
                : ($record->active === 'INACTIVE'
                    ? get_string('fcimportstateinactive', 'tool_vae')
                    : get_string('fcimportstateother', 'tool_vae', s($record->active)));
            $out .= \html_writer::div($state, 'small');
        }
        if ($record->enddate !== null && $record->enddate !== '') {
            $end = s($record->enddate);
            $past = preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $record->enddate, $parts)
                && checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])
                // The whole of the last day counts: a registration that ends today has not ended yet.
                && make_timestamp((int) $parts[3], (int) $parts[2], (int) $parts[1], 23, 59, 59, 99) < $now;
            $out .= \html_writer::div(
                get_string($past ? 'fcimportendregpast' : 'fcimportendreg', 'tool_vae', $end),
                'small'
            );
        }

        if ($record->long) {
            $out .= \html_writer::div(get_string('fcimportnotelong', 'tool_vae', (object) [
                'count' => count($record->long), 'ranks' => s(implode(', ', $record->long)),
            ]), 'small');
        }
        if ($record->markup) {
            $out .= \html_writer::div(get_string('fcimportnotemarkup', 'tool_vae', (object) [
                'count' => count($record->markup), 'ranks' => s(implode(', ', $record->markup)),
            ]), 'small');
        }
        // Said where the framework is created: the name of one that Moodle already holds is never changed by an import.
        if ($record->name !== $record->title && in_array($record->state, ['new', 'rebuild'], true)) {
            $out .= \html_writer::div(get_string('fcimportnotetitle', 'tool_vae'), 'small');
        }

        return $out;
    }

    /**
     * What Moodle holds for a record.
     *
     * @param \stdClass $record
     * @return string
     */
    private static function moodle_cell(\stdClass $record): string {
        switch ($record->state) {
            case 'held':
                $out = \html_writer::div(get_string('fcimportmoodleheld', 'tool_vae', self::day((int) $record->heldts)));
                if ((int) $record->declared > 0) {
                    $out .= \html_writer::div(get_string('fcimportdeclared', 'tool_vae', (int) $record->declared), 'small');
                }

                return $out;
            case 'rebuild':
                return \html_writer::div(get_string('fcimportmoodlerebuild', 'tool_vae'));
            case 'adopted':
                return \html_writer::div(get_string('fcimportmoodleadopt', 'tool_vae'));
            default:
                return \html_writer::div(get_string('fcimportmoodlenew', 'tool_vae'));
        }
    }

    /**
     * What the import will do for a record.
     *
     * @param \stdClass $record
     * @param int $exportts The day of the export.
     * @return string
     */
    private static function action_cell(\stdClass $record, int $exportts): string {
        // Older than what the site holds: said, and left to a gesture - the plugin informs, it does not forbid. What it would
        // change is not announced: nothing is changed unless that gesture is made.
        if (!empty($record->older)) {
            return \html_writer::div(get_string('fcimportolderskip', 'tool_vae'))
                . \html_writer::div(get_string('fcimportolder', 'tool_vae', (object) [
                    'held' => self::day((int) $record->heldts),
                    'export' => self::day($exportts),
                ]), 'small');
        }

        $out = \html_writer::div(get_string('fcimportready', 'tool_vae'));

        $forecast = $record->forecast;
        if ($forecast !== null) {
            if (in_array($record->state, ['new', 'rebuild'], true)) {
                $out .= \html_writer::div(get_string('fcimportdonenew', 'tool_vae', (int) $forecast->created), 'small');
            } else if (!$forecast->created && !$forecast->updated && !$forecast->gone && !$forecast->diverged) {
                $out .= \html_writer::div(get_string('fcimportdonesame', 'tool_vae'), 'small');
            } else {
                $out .= \html_writer::div(get_string('fcimportdoneheld', 'tool_vae', (object) [
                    'created' => (int) $forecast->created, 'updated' => (int) $forecast->updated,
                    'gone' => (int) $forecast->gone, 'diverged' => (int) $forecast->diverged,
                ]), 'small');
            }
        }

        return $out;
    }

    /**
     * The line that says where the export comes from.
     *
     * @param \stdClass $plan
     * @return string HTML that is safe.
     */
    private static function source(\stdClass $plan): string {
        return get_string(
            $plan->origin === fiche_import::ORIGIN_FETCH ? 'fcimportsourcefetch' : 'fcimportsourcezip',
            'tool_vae',
            (object) ['name' => s($plan->originname), 'size' => display_size((int) $plan->bytes)]
        );
    }

    /**
     * A day, as a person reads it.
     *
     * @param int $timestamp
     * @return string
     */
    private static function day(int $timestamp): string {
        return display_date::format($timestamp, get_string('strftimedaydate', 'langconfig'));
    }
}
