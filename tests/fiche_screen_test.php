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

namespace tool_vae;

use tool_vae\local\display_date;
use tool_vae\local\fiche_refusal;
use tool_vae\local\fiche_screen;

/**
 * The preview and the result of an import of records from an export of France compétences (0.47.0).
 *
 * THE PLANS ARE BUILT BY HAND, with the shape the orchestrator gives them: this class renders, it does not decide.
 *
 * WHAT IS ASSERTED. That everything that comes from the export (titles, labels, state, end of registration, names)
 * leaves escaped; that the table has its caption, its column headers and one fold-out detail per record, shows the
 * published label whole for a bloc whose title was shortened, and offers no import when nothing is ready; that each
 * state of a record in Moodle is said in its own words, with the figures the import will give; that the warnings fire
 * at the right thresholds and not before; that the result tells, record by record, what happened.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\fiche_screen::class)]
final class fiche_screen_test extends \advanced_testcase {
    /** @var int The day of the tests: 27 October 2026, noon, in the timezone of the reader. */
    private int $now;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->now = make_timestamp(2026, 10, 27, 12, 0, 0, 99);
    }

    /**
     * A record of a plan, as the orchestrator builds it.
     *
     * @param array $override
     * @return \stdClass
     */
    private static function record(array $override = []): \stdClass {
        return (object) ($override + [
            'number' => 'RNCP99001', 'type' => 'rncp', 'status' => 'ready', 'refusal' => null,
            'title' => 'Diplôme inventé d\'accompagnement', 'name' => 'Diplôme inventé d\'accompagnement',
            'active' => 'ACTIVE', 'enddate' => '31/08/2030', 'blocs' => 2,
            'rows' => [
                (object) ['rang' => 'BC01', 'intitule' => 'Premier bloc', 'description' => ''],
                (object) [
                    'rang' => 'BC02', 'intitule' => 'Bloc BC02',
                    'description' => 'Un libellé publié bien plus long que cent caractères',
                ],
            ],
            'long' => ['BC02'], 'markup' => [], 'state' => 'new', 'heldts' => null, 'declared' => 0,
            'forecast' => (object) ['created' => 2, 'updated' => 0, 'gone' => 0, 'diverged' => 0], 'older' => false,
        ]);
    }

    /**
     * A plan, as the orchestrator builds it.
     *
     * @param \stdClass[] $records
     * @param array $override
     * @return \stdClass
     */
    private function plan(array $records, array $override = []): \stdClass {
        return (object) ($override + [
            'version' => 1, 'userid' => 2, 'created' => $this->now, 'token' => str_repeat('a', 32), 'origin' => 'upload',
            'originname' => 'export-fiches-csv-2026-10-19.zip', 'bytes' => 9704180, 'sha256' => str_repeat('ab', 32),
            'exportdate' => '2026-10-19', 'exportts' => make_timestamp(2026, 10, 19, 0, 0, 0, 99),
            'innerdates' => (object) ['blocs' => '2026-10-18', 'standard' => '2026-10-18'], 'resource' => null,
            'numbers' => array_map(fn($record) => $record->number, $records), 'repeated' => [], 'records' => $records,
            'results' => null,
        ]);
    }

    public function test_what_comes_from_the_export_leaves_escaped(): void {
        $hostile = self::record([
            'title' => '<img src=x onerror=alert(1)>',
            'active' => '<b>x</b>',
            'enddate' => '<i>31/08/2030</i>',
            'rows' => [
                (object) ['rang' => 'BC01', 'intitule' => '<script>alert(1)</script>', 'description' => ''],
                (object) ['rang' => 'BC02', 'intitule' => 'Bloc BC02', 'description' => '<u>publié</u>'],
            ],
            'long' => ['BC02'],
        ]);
        $refused = self::record([
            'number' => 'RNCP99003', 'status' => 'refused',
            'refusal' => (object) ['reason' => fiche_refusal::BAD_BLOC, 'params' => (object) [
                'number' => 'RNCP99003', 'codes' => '<img src=y onerror=alert(2)>',
            ]],
        ]);

        $html = fiche_screen::preview($this->plan([$hostile, $refused], ['originname' => '<b>evil</b>.zip']), $this->now);

        foreach (['<img', '<script', '<b>x', '<i>31', '<u>publi', '<b>evil'] as $raw) {
            $this->assertStringNotContainsString($raw, $html, $raw);
        }
        $this->assertStringContainsString('&lt;img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)', $html);
        $this->assertStringContainsString('&lt;b&gt;evil', $html);
    }

    public function test_the_table_has_a_caption_headers_and_a_detail_per_ready_record(): void {
        $ready = self::record();
        $second = self::record(['number' => 'RNCP99006', 'long' => []]);
        $refused = self::record([
            'number' => 'RNCP99003', 'status' => 'refused',
            'refusal' => (object) ['reason' => fiche_refusal::NO_BLOC, 'params' => (object) ['number' => 'RNCP99003']],
        ]);

        $html = fiche_screen::preview($this->plan([$ready, $second, $refused]), $this->now);

        $this->assertStringContainsString('<caption', $html);
        $this->assertStringContainsString(get_string('fcimportcaption', 'tool_vae'), $html);
        $this->assertSame(4, substr_count($html, 'scope="col"'));
        $this->assertSame(3, substr_count($html, 'scope="row"'), 'The number of each record heads its row.');
        $this->assertSame(1, substr_count($html, 'colspan="3"'), 'A refused record says why in one cell, not in three.');
        foreach (['fcimportcolfiche', 'fcimportcolexport', 'fcimportcolmoodle', 'fcimportcolaction'] as $key) {
            $this->assertStringContainsString(get_string($key, 'tool_vae'), $html, $key);
        }
        $this->assertSame(2, substr_count($html, '<details'), 'One fold-out detail per record that is ready.');
        $this->assertStringContainsString(
            'Un libellé publié bien plus long que cent caractères',
            $html,
            'The published label, whole.'
        );
        $this->assertStringContainsString(get_string('fcimportrefusedfoot', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('fcimporterrornobloc', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('fcimportcheck', 'tool_vae'), $html);
        $this->assertStringContainsString(
            get_string('fcimportvisible', 'tool_vae'),
            $html,
            'Always said: it is open to declarations at once.'
        );
    }

    public function test_nothing_is_offered_when_nothing_is_ready(): void {
        $refused = self::record([
            'status' => 'refused',
            'refusal' => (object) ['reason' => fiche_refusal::NOT_FOUND, 'params' => (object) ['number' => 'RNCP00000']],
        ]);

        $html = fiche_screen::preview($this->plan([$refused]), $this->now);

        $this->assertStringContainsString(get_string('fcimportnone', 'tool_vae'), $html);
        $this->assertStringNotContainsString('<details', $html);
    }

    public function test_each_state_in_moodle_is_said_in_its_own_words_with_the_figures(): void {
        $held = make_timestamp(2026, 10, 1, 0, 0, 0, 99);
        $day = display_date::format($held, get_string('strftimedaydate', 'langconfig'));
        $records = [
            self::record(['number' => 'RNCP90001', 'state' => 'new']),
            self::record([
                'number' => 'RNCP90002', 'state' => 'held', 'heldts' => $held, 'declared' => 3,
                'forecast' => (object) ['created' => 1, 'updated' => 2, 'gone' => 3, 'diverged' => 4],
            ]),
            self::record([
                'number' => 'RNCP90003', 'state' => 'held', 'heldts' => $held,
                'forecast' => (object) ['created' => 0, 'updated' => 0, 'gone' => 0, 'diverged' => 0],
            ]),
            self::record(['number' => 'RNCP90004', 'state' => 'rebuild', 'heldts' => $held]),
            self::record(['number' => 'RNCP90005', 'state' => 'adopted']),
        ];

        $html = fiche_screen::preview($this->plan($records), $this->now);

        $this->assertStringContainsString(get_string('fcimportmoodlenew', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('fcimportdonenew', 'tool_vae', 2), $html);
        $this->assertStringContainsString(get_string('fcimportmoodleheld', 'tool_vae', $day), $html);
        $this->assertStringContainsString(get_string('fcimportdeclared', 'tool_vae', 3), $html);
        $this->assertStringContainsString(get_string('fcimportdoneheld', 'tool_vae', (object) [
            'created' => 1, 'updated' => 2, 'gone' => 3, 'diverged' => 4,
        ]), $html);
        $this->assertStringContainsString(get_string('fcimportdonesame', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('fcimportmoodlerebuild', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('fcimportmoodleadopt', 'tool_vae'), $html);
        $this->assertStringContainsString(
            get_string('fcimportgonenote', 'tool_vae'),
            $html,
            'Said when a record is already imported.'
        );
    }

    public function test_the_state_and_the_end_of_registration_are_shown_as_published(): void {
        $inactive = self::record(['number' => 'RNCP90006', 'active' => 'INACTIVE', 'enddate' => '31/12/2020']);
        $odd = self::record(['number' => 'RNCP90007', 'active' => 'SUSPENDUE', 'enddate' => 'bientôt']);
        $active = self::record(['number' => 'RNCP90008', 'active' => 'ACTIVE', 'enddate' => '31/12/2099']);

        $html = fiche_screen::preview($this->plan([$inactive, $odd, $active]), $this->now);

        $this->assertStringContainsString(get_string('fcimportstateinactive', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('fcimportstateother', 'tool_vae', 'SUSPENDUE'), $html);
        $this->assertStringContainsString(get_string('fcimportstateactive', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('fcimportendregpast', 'tool_vae', '31/12/2020'), $html, 'Past, said as past.');
        $this->assertStringContainsString(get_string('fcimportendreg', 'tool_vae', '31/12/2099'), $html);
        $this->assertStringNotContainsString(get_string('fcimportendregpast', 'tool_vae', '31/12/2099'), $html);
        $this->assertStringContainsString(
            get_string('fcimportendreg', 'tool_vae', 'bientôt'),
            $html,
            'Not a date: shown, not interpreted.'
        );
        $this->assertStringNotContainsString(get_string('fcimportendregpast', 'tool_vae', 'bientôt'), $html);
    }

    public function test_an_older_record_is_not_announced_as_imported(): void {
        $held = make_timestamp(2026, 11, 15, 0, 0, 0, 99);
        $older = self::record([
            'number' => 'RNCP90010', 'state' => 'held', 'heldts' => $held, 'older' => true,
            'forecast' => (object) ['created' => 5, 'updated' => 0, 'gone' => 0, 'diverged' => 0],
        ]);

        $html = fiche_screen::preview($this->plan([$older]), $this->now);

        $this->assertStringContainsString(get_string('fcimportolderskip', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('fcimportolder', 'tool_vae', (object) [
            'held' => display_date::format($held, get_string('strftimedaydate', 'langconfig')),
            'export' => display_date::format(
                make_timestamp(2026, 10, 19, 0, 0, 0, 99),
                get_string('strftimedaydate', 'langconfig')
            ),
        ]), $html);
        $this->assertStringNotContainsString(get_string('fcimportready', 'tool_vae'), $html, 'It is not said to be imported.');
        $this->assertStringNotContainsString('text-warning', $html, 'Plain text: a warning colour is not readable on white.');
        $this->assertStringNotContainsString(get_string('fcimportdoneheld', 'tool_vae', (object) [
            'created' => 5, 'updated' => 0, 'gone' => 0, 'diverged' => 0,
        ]), $html, 'What it would change is not announced: nothing changes without the gesture.');
    }

    public function test_a_registration_that_ends_today_has_not_ended(): void {
        $today = self::record(['number' => 'RNCP90011', 'enddate' => '27/10/2026']);
        $yesterday = self::record(['number' => 'RNCP90012', 'enddate' => '26/10/2026']);

        $html = fiche_screen::preview($this->plan([$today, $yesterday]), $this->now);

        $this->assertStringContainsString(get_string('fcimportendreg', 'tool_vae', '27/10/2026'), $html, 'The whole day counts.');
        $this->assertStringNotContainsString(
            get_string('fcimportendregpast', 'tool_vae', '27/10/2026'),
            $html,
            'The sentence of the past begins like the other: only its end tells them apart.'
        );
        $this->assertStringContainsString(get_string('fcimportendregpast', 'tool_vae', '26/10/2026'), $html);
    }

    public function test_what_was_shortened_is_said(): void {
        $long = self::record(['number' => 'RNCP90009', 'long' => ['BC02', 'BC03'], 'markup' => ['BC01']]);
        $title = self::record(['number' => 'RNCP90010', 'name' => 'RNCP90010', 'title' => str_repeat('Titre très long ', 10)]);

        $html = fiche_screen::preview($this->plan([$long, $title]), $this->now);

        $this->assertStringContainsString(
            get_string('fcimportnotelong', 'tool_vae', (object) ['count' => 2, 'ranks' => 'BC02, BC03']),
            $html
        );
        $this->assertStringContainsString(
            get_string('fcimportnotemarkup', 'tool_vae', (object) ['count' => 1, 'ranks' => 'BC01']),
            $html
        );
        $this->assertStringContainsString(get_string('fcimportnotetitle', 'tool_vae'), $html);
        $this->assertStringContainsString(trim(str_repeat('Titre très long ', 10)), $html, 'The title is shown whole.');
    }

    public function test_the_warnings_fire_at_their_thresholds_and_not_before(): void {
        $record = self::record();
        $at = fn(string $date, array $override = []) => $this->plan([$record], $override + [
            'exportdate' => $date, 'exportts' => self::day($date),
            'innerdates' => (object) [
                'blocs' => date('Y-m-d', strtotime($date) - 86400),
                'standard' => date('Y-m-d', strtotime($date) - 86400),
            ],
        ]);

        // Seven days exactly, and not six.
        $seven = fiche_screen::warnings($at('2026-10-20'), $this->now);
        $this->assertContains(get_string('fcimportwarnstale', 'tool_vae', 7), $seven);
        $six = fiche_screen::warnings($at('2026-10-21'), $this->now);
        $this->assertNotContains(get_string('fcimportwarnstale', 'tool_vae', 6), $six);
        $this->assertSame([], $six, 'An ordinary export, a day old, raises nothing.');

        // The date of the files does not go with the date of the export.
        $gap = fiche_screen::warnings($at('2026-10-26', [
            'innerdates' => (object) ['blocs' => '2026-09-01', 'standard' => '2026-09-01'],
        ]), $this->now);
        $this->assertContains(get_string('fcimportwarngap', 'tool_vae', (object) [
            'export' => display_date::format(self::day('2026-10-26'), get_string('strftimedaydate', 'langconfig')),
            'inner' => display_date::format(self::day('2026-09-01'), get_string('strftimedaydate', 'langconfig')),
        ]), $gap);

        // The two files carry different days.
        $dates = fiche_screen::warnings($at('2026-10-26', [
            'innerdates' => (object) ['blocs' => '2026-10-25', 'standard' => '2026-10-24'],
        ]), $this->now);
        $this->assertContains(get_string('fcimportwarndates', 'tool_vae', (object) [
            'first' => display_date::format(self::day('2026-10-25'), get_string('strftimedaydate', 'langconfig')),
            'second' => display_date::format(self::day('2026-10-24'), get_string('strftimedaydate', 'langconfig')),
        ]), $dates);

        // A number typed several times.
        $repeated = fiche_screen::warnings($at('2026-10-26', ['repeated' => ['RNCP99001']]), $this->now);
        $this->assertContains(get_string('fcimportwarnrepeated', 'tool_vae', 'RNCP99001'), $repeated);
    }

    /**
     * A day, as the screen reads it.
     *
     * @param string $date AAAA-MM-JJ
     * @return int
     */
    public static function day(string $date): int {
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return make_timestamp($year, $month, $day, 0, 0, 0, 99);
    }

    public function test_the_provenance_and_the_source_are_said_without_a_form_to_fill(): void {
        $html = fiche_screen::preview($this->plan([self::record()]), $this->now);

        $this->assertStringContainsString('export-fiches-csv-2026-10-19.zip', $html);
        $this->assertStringContainsString(get_string('fcimportexportdate', 'tool_vae', display_date::format(
            self::day('2026-10-19'),
            get_string('strftimedaydate', 'langconfig')
        )), $html);
        $this->assertStringContainsString('Licence Ouverte 2.0', $html);
        $this->assertStringContainsString('France compétences', $html);

        $fetched = fiche_screen::preview($this->plan([self::record()], [
            'origin' => 'fetch',
            'resource' => (object) [
                'id' => 'c302e089-f5c6-4e52-a685-c877e3a6cdf5',
                'title' => 'export-fiches-csv-2026-10-27.zip',
                'sha1' => str_repeat('a', 40),
            ],
            'originname' => 'export-fiches-csv-2026-10-27.zip',
        ]), $this->now);
        $this->assertStringContainsString(get_string('fcimportsourcefetch', 'tool_vae', (object) [
            'name' => 'export-fiches-csv-2026-10-27.zip', 'size' => display_size(9704180),
        ]), $fetched);
    }

    public function test_the_result_tells_what_happened_to_each_record(): void {
        $plan = $this->plan([self::record(), self::record(['number' => 'RNCP99006'])], ['results' => [
            (object) ['number' => 'RNCP99001', 'status' => 'imported', 'html' => '4 competencies added.'],
            (object) ['number' => 'RNCP99002', 'status' => 'failed', 'html' => 'The framework is not ours.'],
            (object) ['number' => 'RNCP99003', 'status' => 'older', 'html' => ''],
            (object) ['number' => 'RNCP99004', 'status' => 'refused', 'html' => 'No bloc.'],
            (object) ['number' => 'RNCP99005', 'status' => 'notrun', 'html' => ''],
        ]]);

        $html = fiche_screen::results($plan);

        $this->assertStringContainsString(get_string('fcimportresultline', 'tool_vae', (object) [
            'code' => 'RNCP99001', 'summary' => '4 competencies added.',
        ]), $html);
        $this->assertStringContainsString(get_string('fcimportresultfailed', 'tool_vae', (object) [
            'code' => 'RNCP99002', 'reason' => 'The framework is not ours.',
        ]), $html);
        $this->assertStringContainsString(get_string('fcimportresultolder', 'tool_vae', 'RNCP99003'), $html);
        $this->assertStringContainsString(get_string('fcimportresultrefused', 'tool_vae', (object) [
            'code' => 'RNCP99004', 'reason' => 'No bloc.',
        ]), $html);
        $this->assertStringContainsString(get_string('fcimportresultnotrun', 'tool_vae', 'RNCP99005'), $html);
        // A record was imported: the replacement is worth a word, with the way to the page that holds it.
        $this->assertStringContainsString('Does one of these records replace another?', $html);
        $this->assertStringContainsString('/admin/tool/vae/supersession.php', $html);

        $none = fiche_screen::results($this->plan([self::record()], ['results' => [
            (object) ['number' => 'RNCP99004', 'status' => 'refused', 'html' => 'No bloc.'],
        ]]));
        $this->assertStringNotContainsString(
            '/admin/tool/vae/supersession.php',
            $none,
            'Nothing was imported: nothing replaces nothing.'
        );
    }

    public function test_the_name_of_the_framework_is_said_only_where_it_will_be_given(): void {
        $long = str_repeat('a', 101);
        $new = self::record(['number' => 'RNCP90021', 'title' => $long, 'name' => 'RNCP90021', 'state' => 'new']);
        $rebuilt = self::record([
            'number' => 'RNCP90022', 'title' => $long, 'name' => 'RNCP90022', 'state' => 'rebuild', 'heldts' => 1,
        ]);
        $held = self::record(['number' => 'RNCP90023', 'title' => $long, 'name' => 'RNCP90023', 'state' => 'held', 'heldts' => 1]);
        $adopted = self::record(['number' => 'RNCP90024', 'title' => $long, 'name' => 'RNCP90024', 'state' => 'adopted']);
        $note = get_string('fcimportnotetitle', 'tool_vae');

        $count = fn(array $records) => substr_count(fiche_screen::preview($this->plan($records), $this->now), $note);

        $this->assertSame(1, $count([$new]));
        $this->assertSame(1, $count([$rebuilt]));
        $this->assertSame(0, $count([$held]), 'A framework that Moodle holds keeps its name: an import never renames it.');
        $this->assertSame(0, $count([$adopted]));
    }

    public function test_the_warning_of_an_old_export_does_not_send_a_fetched_export_to_fetch_again(): void {
        $old = ['exportts' => make_timestamp(2026, 10, 17, 0, 0, 0, 99)];

        $uploaded = fiche_screen::warnings($this->plan([self::record()], $old + ['origin' => 'upload']), $this->now);
        $fetched = fiche_screen::warnings($this->plan([self::record()], $old + ['origin' => 'fetch']), $this->now);

        $this->assertSame([get_string('fcimportwarnstale', 'tool_vae', 10)], $uploaded);
        $this->assertSame([get_string('fcimportwarnstalefetch', 'tool_vae', 10)], $fetched);
        $this->assertStringNotContainsString('Fetch the latest export', $fetched[0]);
    }

    public function test_a_record_in_force_with_no_bloc_has_its_own_sentence(): void {
        $refused = self::record([
            'number' => 'RNCP90025', 'status' => 'refused',
            'refusal' => (object) ['reason' => fiche_refusal::NO_BLOC_ACTIVE, 'params' => (object) ['number' => 'RNCP90025']],
        ]);

        $html = fiche_screen::preview($this->plan([$refused]), $this->now);

        $this->assertStringContainsString(get_string('fcimporterrornoblocactive', 'tool_vae'), $html);
        $this->assertStringNotContainsString(get_string('fcimporterrornobloc', 'tool_vae'), $html);
        $this->assertStringContainsString('Import a CSV file (special case)', $html, 'It says what to do instead.');
    }
}
