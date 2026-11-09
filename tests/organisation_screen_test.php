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

use tool_vae\local\competency_gateway;
use tool_vae\local\organisation;
use tool_vae\local\organisation_screen;

/**
 * What the pathway of a candidate shows (T-90).
 *
 * The screen draws what organisation::for_candidate() gives and decides nothing, so most of these tests hand it a pathway
 * of the very shape the class returns. One goes through the class on real rows: a hidden course must not leave its name
 * anywhere in the page, which only the whole chain can show.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\organisation_screen::class)]
final class organisation_screen_test extends \advanced_testcase {
    /**
     * The pieces are open unless a test says otherwise: the column is drawn.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('piecesopen', 1, 'tool_vae');
    }

    /**
     * One competency line, as organisation::for_candidate() gives it, with what a test changes.
     *
     * @param array $with
     * @return \stdClass
     */
    private function row(array $with = []): \stdClass {
        return (object) ($with + [
            'idnumber' => 'RNCP100-C01', 'shortname' => 'Aider au lever', 'declarations' => 2, 'attested' => 1,
            'lastattested' => null, 'gone' => false, 'bloc' => null, 'isbloc' => false, 'pieces' => 3, 'lastact' => null,
            'scoped' => false, 'courses' => [], 'coursesunreadable' => false,
            'activities' => [], 'activitiesunreadable' => false, 'plans' => [],
        ]);
    }

    /**
     * A pathway with one declared record holding the given lines.
     *
     * @param \stdClass[] $rows
     * @param array $with What to change at the top: planned, plansreadable...
     * @return \stdClass
     */
    private function pathway(array $rows, array $with = []): \stdClass {
        $record = (object) [
            'fichecode' => 'RNCP100', 'name' => 'RNCP100', 'source' => null,
            'hasblocs' => (bool) array_filter($rows, fn($row) => $row->isbloc),
            'competencies' => array_column($rows, null, 'idnumber'),
        ];

        return (object) ($with + [
            'declared' => ['RNCP100' => $record], 'planned' => [], 'plans' => [], 'plansreadable' => true, 'marks' => [],
        ]);
    }

    /**
     * The text of the cells of every row of the first table of a page, whitespace folded.
     *
     * @param string $html
     * @return string[][]
     */
    private function cells(string $html): array {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR);
        $rows = [];
        foreach ($document->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement) {
                    $cells[] = trim((string) preg_replace('/\s+/u', ' ', $cell->textContent));
                }
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * A course whose name the core keeps is drawn as a word, without a link, and the others as a link to the course.
     */
    public function test_a_hidden_course_is_drawn_without_a_name_and_without_a_link(): void {
        $this->resetAfterTest();
        $named = $this->getDataGenerator()->create_course(['fullname' => 'Cours visible']);
        $courses = [
            (object) ['courseid' => $named->id, 'fullname' => 'Cours visible', 'visible' => 1],
            (object) ['courseid' => 987654, 'fullname' => null, 'visible' => 0],
        ];

        $html = organisation_screen::mine($this->pathway([$this->row(['courses' => $courses])]));

        $this->assertStringContainsString('/course/view.php?id=' . $named->id, $html);
        $this->assertStringContainsString('Cours visible', $html);
        $this->assertStringContainsString('A hidden course', $html);
        $this->assertStringNotContainsString('987654', $html, 'No link, no address, for a course the reader may not open.');
    }

    /**
     * Everything that comes from a person or from the core goes through the escaping.
     */
    public function test_names_are_escaped(): void {
        $this->resetAfterTest();
        $plan = (object) ['id' => 1, 'name' => '<script>alert(1)</script>', 'status' => 1, 'statuslabel' => '<b>Active</b>'];
        $row = $this->row(['shortname' => '<img src=x onerror=alert(2)>', 'plans' => [$plan]]);
        $planned = (object) [
            'fichecode' => 'RS200', 'name' => '<i>Lève-personne</i>', 'courses' => [], 'coursesunreadable' => false,
            'activities' => [], 'activitiesunreadable' => false,
            'plans' => [$plan],
        ];

        $html = organisation_screen::mine($this->pathway([$row], ['planned' => ['RS200' => $planned]]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('<b>Active', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; (&lt;b&gt;Active&lt;/b&gt;)', $html);
    }

    /**
     * One header and one line per competency: no total, no percentage, no line of conclusion.
     */
    public function test_the_table_holds_the_lines_and_the_counts_only(): void {
        $first = $this->row();
        $second = $this->row(['idnumber' => 'RNCP100-C02', 'shortname' => 'Aider au repas', 'declarations' => 0, 'pieces' => 0,
            'attested' => 0]);

        $html = organisation_screen::mine($this->pathway([$first, $second]));
        $rows = $this->cells($html);

        $this->assertCount(3, $rows);
        $this->assertSame(
            [
                'Competency', 'Declarations', 'Pieces', 'Attestations in force', 'Last act', 'Courses', 'Activities',
                'Learning plans',
            ],
            $rows[0]
        );
        $this->assertSame(['RNCP100-C01 — Aider au lever', '2', '3', '1', '', 'none', 'none', 'none'], $rows[1]);
        $this->assertSame(['RNCP100-C02 — Aider au repas', '0', '0', '0', '', 'none', 'none', 'none'], $rows[2]);
        $this->assertStringNotContainsString('%', $html);
    }

    /**
     * The last act is a date, written in the words of the reader's language.
     */
    public function test_the_last_act_is_a_date(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC');
        $stamp = 1780000000;

        $rows = $this->cells(organisation_screen::mine($this->pathway([$this->row(['lastact' => $stamp])])));

        $this->assertSame(userdate($stamp, get_string('strftimedate', 'langconfig')), $rows[1][4]);
    }

    /**
     * A bloc line adds up its own counts and those of its competencies, in every column that counts, and keeps its own
     * courses and plans.
     */
    public function test_a_bloc_line_shows_the_sums_of_its_competencies(): void {
        $this->resetAfterTest();
        $bloc = $this->row([
            'idnumber' => 'RNCP100-BC01', 'shortname' => 'Bloc 1', 'isbloc' => true, 'declarations' => 0, 'attested' => 0,
            'pieces' => 0, 'lastact' => 1760000000, 'blocdeclarations' => 4, 'blocattested' => 2, 'bloclastattested' => null,
            'blocpieces' => 5, 'bloclastact' => 1780000000,
        ]);
        $under = $this->row(['bloc' => 'RNCP100-BC01']);
        $this->setTimezone('UTC');
        $day = fn(int $stamp): string => userdate($stamp, get_string('strftimedate', 'langconfig'));

        $html = organisation_screen::mine($this->pathway([$bloc, $under]));
        $rows = $this->cells($html);

        $this->assertSame(['RNCP100-BC01 — Bloc 1 (bloc)', '4', '5', '2', $day(1780000000), 'none', 'none', 'none'], $rows[1]);
        $this->assertSame(['RNCP100-C01 — Aider au lever', '2', '3', '1', '', 'none', 'none', 'none'], $rows[2]);
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR);
        $setin = (new \DOMXPath($document))->query('//th[contains(@class, "ps-4")]');
        $this->assertSame(1, $setin->length, 'Only the competency under the bloc is set in.');
        $this->assertStringContainsString('Aider au lever', $setin->item(0)->textContent);
    }

    /**
     * A site that hides the plans from their owner is not told "none": the plans exist, the reader may not read them.
     */
    public function test_plans_the_reader_may_not_read_are_not_drawn_as_none(): void {
        $rows = $this->cells(organisation_screen::mine($this->pathway([$this->row()], ['plansreadable' => false])));

        $this->assertSame('Not readable with your rights', $rows[1][7]);
        $this->assertSame('none', $rows[1][6]);
    }

    /**
     * Courses the reader may not see the competencies of are not drawn as "none" either: they exist, the core keeps them.
     */
    public function test_courses_the_reader_may_not_see_are_not_drawn_as_none(): void {
        $planned = (object) [
            'fichecode' => 'RS200', 'name' => 'RS200', 'courses' => [], 'coursesunreadable' => true,
            'activities' => [], 'activitiesunreadable' => false, 'plans' => [],
        ];

        $html = organisation_screen::mine($this->pathway(
            [$this->row(['coursesunreadable' => true]), $this->row(['idnumber' => 'RNCP100-C02'])],
            ['planned' => ['RS200' => $planned]]
        ));
        $rows = $this->cells($html);

        $this->assertSame('Not readable with your rights', $rows[1][5]);
        $this->assertSame('none', $rows[1][6], 'The plans are readable and there are none: that is still "none".');
        $this->assertSame('none', $rows[2][5], 'No course carries this one: that is "none".');
        $this->assertSame(
            2,
            substr_count($html, 'Not readable with your rights'),
            'Once in the table, once for the record of a plan.'
        );
    }

    /**
     * A record only a plan names: its name, its courses and its plans, and no table of counts.
     */
    public function test_a_planned_record_is_named_with_its_courses_and_plans_and_no_counts(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Cours lève-personne']);
        $plan = (object) ['id' => 1, 'name' => 'Plan AES', 'status' => 1, 'statuslabel' => 'Active'];
        $planned = (object) [
            'fichecode' => 'RS200', 'name' => 'Utiliser un lève-personne',
            'courses' => [(object) ['courseid' => $course->id, 'fullname' => 'Cours lève-personne', 'visible' => 1]],
            'coursesunreadable' => false, 'activities' => [], 'activitiesunreadable' => false, 'plans' => [$plan],
        ];

        $html = organisation_screen::mine($this->pathway([], ['declared' => [], 'planned' => ['RS200' => $planned]]));

        $this->assertStringContainsString('Records of your learning plans, without any declaration yet', $html);
        $this->assertStringContainsString('RS200', $html);
        $this->assertStringContainsString('Utiliser un lève-personne', $html);
        $this->assertStringContainsString('Cours lève-personne', $html);
        $this->assertStringContainsString('Plan AES (Active)', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    /**
     * Nothing to draw: nothing is drawn.
     */
    public function test_a_pathway_with_nothing_draws_nothing(): void {
        $this->assertSame('', organisation_screen::mine($this->pathway([], ['declared' => []])));
    }

    /**
     * The sentence of an empty pathway does not say "no plan names a record" of a reader whose plans are hidden.
     */
    public function test_the_empty_pathway_tells_hidden_plans_from_no_plan(): void {
        $empty = $this->pathway([], ['declared' => []]);

        $readable = organisation_screen::nothing($empty);
        $this->assertStringContainsString('none of the learning plans you can read names a certification record', $readable);
        $this->assertStringNotContainsString('not readable with your rights', $readable);

        $empty->plansreadable = false;
        $hidden = organisation_screen::nothing($empty);
        $this->assertStringContainsString('not readable with your rights', $hidden);
        $this->assertStringNotContainsString('none of the learning plans', $hidden);
    }

    /**
     * The sentences that say what the figures do not say sit in a box that cannot be closed: a notification of the core is
     * dismissed with one click, and "the panel alone decides" would go with it.
     */
    public function test_the_box_of_a_sentence_has_no_button_to_close_it(): void {
        $this->assertSame(
            '<div class="alert alert-info">Only you &amp; nobody else.</div>',
            organisation_screen::notice('Only you & nobody else.')
        );
        $this->assertStringNotContainsString('dismiss', organisation_screen::nothing($this->pathway([], ['declared' => []])));
    }

    /**
     * With records declared and plans hidden, the records only a plan names are missing from the page: the page says why.
     */
    public function test_hidden_plans_are_announced_once_under_the_tables(): void {
        $note = 'Your learning plans are not readable with your rights: the records that only a plan names cannot be shown.';

        $hidden = organisation_screen::mine($this->pathway([$this->row()], ['plansreadable' => false]));
        $readable = organisation_screen::mine($this->pathway([$this->row()]));

        $this->assertSame(1, substr_count($hidden, $note));
        $this->assertStringNotContainsString('only a plan names', $readable);
    }

    /**
     * A declared record is titled with its code and the name its framework was given, when it has one.
     */
    public function test_a_declared_record_is_titled_with_its_code_and_its_name(): void {
        $pathway = $this->pathway([$this->row()]);
        $pathway->declared['RNCP100']->name = 'Assistant de vie aux familles';

        $this->assertStringContainsString('RNCP100 — Assistant de vie aux familles', organisation_screen::mine($pathway));
        $this->assertStringNotContainsString('RNCP100 — RNCP100', organisation_screen::mine($this->pathway([$this->row()])));
    }

    /**
     * A record that comes from a source says which register it is of, and whose text it prints, under which licence.
     */
    public function test_a_record_with_a_source_names_its_register_and_its_licence(): void {
        $source = (object) [
            'fichetype' => 'rs', 'attribution' => 'France compétences', 'licence' => 'Licence Ouverte 2.0',
            'sourceupdated' => 1780000000,
        ];
        $pathway = $this->pathway([$this->row()]);
        $pathway->declared['RNCP100']->source = $source;

        $html = organisation_screen::mine($pathway);
        $this->assertStringContainsString('RNCP100 — RS - specific register', $html);
        $this->assertStringContainsString('France compétences', $html);
        $this->assertStringContainsString('Licence Ouverte 2.0', $html);

        $source->fichetype = 'rncp';
        $this->assertStringContainsString('RNCP100 — RNCP - national register', organisation_screen::mine($pathway));
    }

    /**
     * The title of a record, its name and its source are text from outside: none of it reaches the page as markup.
     */
    public function test_the_title_of_a_record_is_escaped(): void {
        $pathway = $this->pathway([$this->row()]);
        $record = $pathway->declared['RNCP100'];
        $record->fichecode = '<u>RNCP100</u>';
        $record->name = '<i>Assistant</i>';
        $record->source = (object) [
            'fichetype' => 'rs', 'attribution' => '<b>Attribution</b>', 'licence' => '<s>Licence</s>', 'sourceupdated' => null,
        ];
        $planned = (object) [
            'fichecode' => '<em>RS200</em>', 'name' => '<em>Nom</em>', 'courses' => [], 'coursesunreadable' => false,
            'activities' => [], 'activitiesunreadable' => false, 'plans' => [],
        ];
        $pathway->planned = ['RS200' => $planned];

        $html = organisation_screen::mine($pathway);

        foreach (['<u>', '<i>', '<b>', '<s>', '<em>'] as $tag) {
            $this->assertStringNotContainsString($tag, $html);
        }
        $this->assertStringContainsString('&lt;u&gt;RNCP100&lt;/u&gt;', $html);
        $this->assertStringContainsString('&lt;em&gt;RS200&lt;/em&gt;', $html);
    }

    /**
     * The pieces are a function the site opens: closed, the column of zeros is not drawn; held pieces bring it back.
     */
    public function test_the_column_of_pieces_follows_the_setting_and_the_pieces_held(): void {
        set_config('piecesopen', 0, 'tool_vae');
        $nopiece = $this->row(['pieces' => 0]);

        $rows = $this->cells(organisation_screen::mine($this->pathway([$nopiece])));
        $this->assertSame(
            ['Competency', 'Declarations', 'Attestations in force', 'Last act', 'Courses', 'Activities', 'Learning plans'],
            $rows[0]
        );
        $this->assertSame(['RNCP100-C01 — Aider au lever', '2', '1', '', 'none', 'none', 'none'], $rows[1]);

        $held = $this->cells(organisation_screen::mine($this->pathway([$this->row(['pieces' => 3])])));
        $this->assertSame('Pieces', $held[0][2], 'A piece held is shown, whatever the setting is now.');
        $this->assertSame('3', $held[1][2]);

        set_config('piecesopen', 1, 'tool_vae');
        $open = $this->cells(organisation_screen::mine($this->pathway([$nopiece])));
        $this->assertSame('Pieces', $open[0][2]);
        $this->assertSame('0', $open[1][2]);
    }

    /**
     * A reader who may see hidden courses is told which of them is one; a visible course carries no mark.
     */
    public function test_a_hidden_course_whose_name_is_given_is_marked(): void {
        $visible = $this->getDataGenerator()->create_course(['fullname' => 'Cours visible']);
        $hidden = $this->getDataGenerator()->create_course(['fullname' => 'Cours masque', 'visible' => 0]);
        $courses = [
            (object) ['courseid' => $visible->id, 'fullname' => 'Cours visible', 'visible' => 1],
            (object) ['courseid' => $hidden->id, 'fullname' => 'Cours masque', 'visible' => 0],
        ];

        $rows = $this->cells(organisation_screen::mine($this->pathway([$this->row(['courses' => $courses])])));

        $this->assertSame('Cours visibleCours masque hidden', $rows[1][5], 'Only the hidden course carries the mark.');
    }

    /**
     * A bloc line keeps its own courses and plans, and does not take those of the competencies under it.
     */
    public function test_a_bloc_line_keeps_its_own_courses_and_plans(): void {
        $own = $this->getDataGenerator()->create_course(['fullname' => 'Cours du bloc']);
        $under = $this->getDataGenerator()->create_course(['fullname' => 'Cours de la competence']);
        $plan = static fn(string $name): \stdClass => (object) [
            'id' => 1, 'name' => $name, 'status' => 1, 'statuslabel' => 'Active',
        ];
        $bloc = $this->row([
            'idnumber' => 'RNCP100-BC01', 'shortname' => 'Bloc 1', 'isbloc' => true, 'blocdeclarations' => 2, 'blocattested' => 1,
            'blocpieces' => 3, 'bloclastact' => null,
            'courses' => [(object) ['courseid' => $own->id, 'fullname' => 'Cours du bloc', 'visible' => 1]],
            'plans' => [$plan('Plan du bloc')],
        ]);
        $child = $this->row([
            'bloc' => 'RNCP100-BC01',
            'courses' => [(object) ['courseid' => $under->id, 'fullname' => 'Cours de la competence', 'visible' => 1]],
            'plans' => [$plan('Plan de la competence')],
        ]);

        $rows = $this->cells(organisation_screen::mine($this->pathway([$bloc, $child])));

        $this->assertSame(['Cours du bloc', 'none', 'Plan du bloc (Active)'], array_slice($rows[1], 5));
        $this->assertSame(['Cours de la competence', 'none', 'Plan de la competence (Active)'], array_slice($rows[2], 5));
    }

    /**
     * The last act is written in the timezone of the reader: a stamp late in the evening in UTC is the next day in Perth.
     */
    public function test_the_last_act_is_written_in_the_timezone_of_the_reader(): void {
        $this->setTimezone('Australia/Perth');

        $rows = $this->cells(organisation_screen::mine($this->pathway([$this->row(['lastact' => 1780000000])])));

        $this->assertSame('29 May 2026', $rows[1][4], '1780000000 is 28 May 20:26 in UTC and 29 May 04:26 in Perth.');
    }

    /**
     * A competency that left the record keeps its line, with a word that says so; a record with blocs says how a bloc line adds
     * up; a record only a plan names says whose plans do.
     */
    public function test_the_words_that_explain_a_line_are_there(): void {
        $gone = $this->row(['gone' => true]);
        $bloc = $this->row(['idnumber' => 'RNCP100-BC01', 'isbloc' => true, 'blocdeclarations' => 0, 'blocattested' => 0,
            'blocpieces' => 0, 'bloclastact' => null]);
        $planned = (object) [
            'fichecode' => 'RS200', 'name' => 'RS200', 'courses' => [], 'coursesunreadable' => false,
            'activities' => [], 'activitiesunreadable' => false, 'plans' => [],
        ];

        $html = organisation_screen::mine($this->pathway([$bloc, $gone], ['planned' => ['RS200' => $planned]]));

        $this->assertStringContainsString('No longer appears in the certification record.', $html);
        $this->assertStringContainsString('A bloc line adds up the declarations, pieces and attestations', $html);
        $this->assertStringContainsString('Your learning plans name these records.', $html);
        $this->assertStringNotContainsString('RS200 — RS200', $html);
    }

    /**
     * Through the class, on real rows: the name of a hidden course is nowhere in the page of a reader who may not see it,
     * and is in the page of one who may.
     */
    public function test_the_name_of_a_hidden_course_leaves_the_page_only_to_who_may_see_it(): void {
        $this->resetAfterTest();
        set_config('piecesopen', 1, 'tool_vae');
        $generator = $this->getDataGenerator();
        $plugin = $generator->get_plugin_generator('tool_vae');
        $core = $generator->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $plugin->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'C01', 'shortname' => 'Aider au lever']);
        $candidate = $generator->create_user();
        $role = $generator->create_role();
        foreach (['tool/vae:declare', 'tool/vae:viewown'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $role, \context_system::instance()->id, true);
        }
        role_assign($role, $candidate->id, \context_user::instance($candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $plugin->create_declaration([
            'userid' => $candidate->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Nuit avec la soignante',
        ]);
        $gateway = new competency_gateway();
        $competencyid = 0;
        foreach ($gateway->list_framework_competencies((int) $gateway->find_framework('RNCP100')) as $competency) {
            $competencyid = (int) $competency->id;
        }
        $hidden = $generator->create_course(['fullname' => 'Reclassement du service X', 'visible' => 0]);
        $core->create_course_competency(['courseid' => $hidden->id, 'competencyid' => $competencyid]);

        $this->setUser($candidate);
        $html = organisation_screen::mine(organisation::for_candidate((int) $candidate->id));

        $this->assertStringNotContainsString('Reclassement du service X', $html);
        $this->assertStringContainsString('A hidden course', $html);
        $this->assertStringNotContainsString('id=' . $hidden->id, $html);

        $viewer = $generator->create_role();
        assign_capability('moodle/course:viewhiddencourses', CAP_ALLOW, $viewer, \context_system::instance()->id, true);
        role_assign($viewer, $candidate->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $html = organisation_screen::mine(organisation::for_candidate((int) $candidate->id));

        $this->assertStringContainsString('Reclassement du service X', $html);
        $this->assertStringContainsString('/course/view.php?id=' . $hidden->id, $html);
    }

    /**
     * One followed candidate, as organisation::followed() gives it, with what a test changes.
     *
     * @param array $with
     * @return \stdClass
     */
    private function followed_candidate(array $with = []): \stdClass {
        return (object) ($with + [
            'id' => 7, 'name' => 'Camille Vaeiste', 'sortname' => 'Vaeiste Camille',
            'fiches' => [(object) ['fichecode' => 'RNCP100', 'name' => 'Accompagnant éducatif']],
            'declarations' => 2, 'pending' => 1, 'plansreadable' => true, 'draftsreadable' => true, 'plans' => [],
        ]);
    }

    /**
     * What organisation::followed() gives, with these candidates.
     *
     * @param \stdClass[] $candidates
     * @param array $with
     * @return \stdClass
     */
    private function followed_data(array $candidates, array $with = []): \stdClass {
        $keyed = [];
        foreach ($candidates as $candidate) {
            $keyed[$candidate->id] = $candidate;
        }

        return (object) ($with + ['candidates' => $keyed, 'choices' => [], 'chosen' => '']);
    }

    /**
     * The address of the page, as the page gives it to the screen.
     *
     * @return \moodle_url
     */
    private function page_url(): \moodle_url {
        return new \moodle_url('/admin/tool/vae/organisation.php', ['tab' => 'followed']);
    }

    public function test_followed_draws_one_row_per_candidate_with_the_five_columns(): void {
        $plan = (object) ['name' => 'Plan AES', 'status' => 1, 'statuslabel' => 'Active'];
        $data = $this->followed_data([$this->followed_candidate(['plans' => [$plan]])]);

        $html = organisation_screen::followed($data, $this->page_url());
        $rows = $this->cells($html);

        $this->assertCount(2, $rows);
        $this->assertSame(['Candidate', 'Records', 'Learning plans', 'Declarations', 'Awaiting attestation'], $rows[0]);
        $this->assertSame(['Camille Vaeiste', 'RNCP100 — Accompagnant éducatif', 'Plan AES (Active)', '2', '1'], $rows[1]);
        $this->assertStringContainsString('dossier.php?userid=7', $html);
        $this->assertStringContainsString('generaltable tool-vae-organisation tool-vae-followed', $html);
    }

    public function test_followed_says_unreadable_plans_and_never_none(): void {
        $plan = (object) ['name' => 'Plan AES', 'status' => 1, 'statuslabel' => 'Active'];
        $partial = get_string('orgplanspartial', 'tool_vae');
        $cases = [
            'neither right' => [false, false, [], 'Not readable with your rights'],
            'plans only, none' => [true, false, [], $partial],
            'plans only, one' => [true, false, [$plan], 'Plan AES (Active)' . $partial],
            'drafts only, none' => [false, true, [], $partial],
            'both, none' => [true, true, [], 'none'],
        ];
        foreach ($cases as $label => [$plans, $drafts, $list, $expected]) {
            $candidate = $this->followed_candidate(['plansreadable' => $plans, 'draftsreadable' => $drafts, 'plans' => $list]);

            $rows = $this->cells(organisation_screen::followed($this->followed_data([$candidate]), $this->page_url()));

            $this->assertSame($expected, $rows[1][2], $label);
        }
    }

    public function test_followed_escapes_names(): void {
        $plan = (object) ['name' => '<b>Plan</b>', 'status' => 1, 'statuslabel' => 'Active'];
        $candidate = $this->followed_candidate([
            'name' => '<script>alert(1)</script>',
            'fiches' => [(object) ['fichecode' => 'RNCP100', 'name' => '<i>Fiche</i>']],
            'plans' => [$plan],
        ]);

        $html = organisation_screen::followed($this->followed_data([$candidate]), $this->page_url());

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<i>', $html);
        $this->assertStringNotContainsString('<b>Plan', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_followed_has_no_date_no_total_no_action(): void {
        $data = $this->followed_data([
            $this->followed_candidate(),
            $this->followed_candidate(['id' => 8, 'name' => 'Denis Vaeiste', 'declarations' => 0, 'pending' => 0]),
        ]);

        $html = organisation_screen::followed($data, $this->page_url());
        $rows = $this->cells($html);

        $this->assertCount(5, $rows[0]);
        $this->assertCount(3, $rows, 'A header and one row per candidate, no line of total.');
        $this->assertDoesNotMatchRegularExpression('/\b\d{4}\b/', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/January|February|March|April|May|June|July|August|September|October|November|December/',
            $html
        );
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('%', $html);
    }

    public function test_followed_filter_form_is_offered_only_with_choices_and_keeps_the_tab(): void {
        $without = organisation_screen::followed($this->followed_data([$this->followed_candidate()]), $this->page_url());
        $this->assertStringNotContainsString('<select', $without);
        $this->assertStringNotContainsString('<form', $without);

        $choices = [
            'RNCP100|BC01' => (object) ['fichecode' => 'RNCP100', 'ficherang' => 'BC01'],
            'RS200' => (object) ['fichecode' => 'RS200', 'ficherang' => ''],
        ];
        $data = $this->followed_data([$this->followed_candidate()], ['choices' => $choices, 'chosen' => 'RNCP100|BC01']);

        $html = organisation_screen::followed($data, $this->page_url());

        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('name="bloc"', $html);
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="tab"[^>]*value="followed"|<input[^>]*value="followed"[^>]*name="tab"/',
            $html
        );
        $this->assertMatchesRegularExpression('/<option selected="selected" value="RNCP100\|BC01"/', $html);
        $this->assertStringContainsString('Show the counts', $html);
        $this->assertStringContainsString('The counts are limited to the declarations on RNCP100 — bloc BC01.', $html);
        $this->assertStringContainsString('whole record', $html);
    }

    public function test_followed_empty_data_says_nocandidates(): void {
        $html = organisation_screen::followed($this->followed_data([]), $this->page_url());

        $this->assertStringContainsString('alert alert-info', $html);
        $this->assertStringContainsString(get_string('nocandidates', 'tool_vae'), $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function test_tabs_are_the_keys_asked_in_order_and_the_link_stays_when_selected(): void {
        $tabs = organisation_screen::tabs(['mine', 'followed']);

        $this->assertSame(['mine', 'followed'], array_column($tabs, 'id'));
        $this->assertSame(['My pathway', 'Followed candidates'], array_column($tabs, 'text'));
        $this->assertStringEndsWith('/admin/tool/vae/organisation.php?tab=mine', $tabs[0]->link->out(false));
        $this->assertStringEndsWith('/admin/tool/vae/organisation.php?tab=followed', $tabs[1]->link->out(false));
        foreach ($tabs as $tab) {
            $this->assertTrue($tab->linkedwhenselected);
        }
        $this->assertSame([], organisation_screen::tabs([]));
    }

    public function test_the_three_tabs_are_named_and_the_site_one_leads_to_its_tab(): void {
        $tabs = organisation_screen::tabs(['mine', 'followed', 'site']);

        $this->assertSame(['My pathway', 'Followed candidates', 'Organisation'], array_column($tabs, 'text'));
        $this->assertStringEndsWith('/admin/tool/vae/organisation.php?tab=site', $tabs[2]->link->out(false));
    }

    /**
     * What organisation::site() gives, with what a test changes.
     *
     * @param array $fiche What to change on the record.
     * @param array $powers What to change on the powers.
     * @return \stdClass
     */
    private function site_data(array $fiche = [], array $powers = []): \stdClass {
        $record = (object) ($fiche + [
            'fichecode' => 'RNCP100', 'name' => 'Aide-soignant', 'blocs' => 2, 'competencies' => 4, 'courses' => [],
            'coursesunreadable' => false,
            'activities' => [], 'activitiesunreadable' => false, 'models' => [], 'plans' => 3, 'referents' => [], 'rules' => [],
        ]);
        $powerdata = (object) ($powers + [
            'capabilities' => [(object) [
                'name' => 'tool/vae:viewany', 'label' => 'View any candidate\'s dossier',
                'allow' => ['Direction'], 'prevent' => [], 'prohibit' => [],
            ]],
            'cohorts' => [],
            'settings' => [(object) ['name' => 'validationopen', 'value' => 1, 'open' => true]],
            'actors' => [],
        ]);

        return (object) ['fiches' => [$record->fichecode => $record], 'powers' => $powerdata];
    }

    public function test_site_draws_the_record_table_with_the_code_in_a_row_header_and_a_caption(): void {
        $html = organisation_screen::site($this->site_data());

        $this->assertStringContainsString('tool-vae-site-fiches', $html);
        $this->assertStringContainsString('tool-vae-site-powers', $html);
        $this->assertMatchesRegularExpression('~<caption[^>]*>Records of the site</caption>~', $html);
        $this->assertMatchesRegularExpression('~<th[^>]*scope="row"[^>]*>.*RNCP100.*Aide-soignant~s', $html);
        $this->assertStringContainsString('Learning plans (number)', $html);
        $this->assertStringContainsString('drafts included', $html);
    }

    public function test_site_writes_a_dash_for_a_record_of_one_level_or_without_a_framework(): void {
        $dash = get_string('orgnoblocs', 'tool_vae');
        $one = organisation_screen::site($this->site_data(['blocs' => 0, 'competencies' => 5]));
        $gone = organisation_screen::site($this->site_data(['name' => 'RNCP100', 'blocs' => null, 'competencies' => null]));

        $this->assertMatchesRegularExpression('~<td[^>]*>' . $dash . '</td>~', $one);
        $this->assertMatchesRegularExpression('~<td[^>]*class="[^"]*\bc2\b[^"]*"[^>]*>5</td>~', $one);
        $this->assertSame(2, preg_match_all('~<td[^>]*>' . $dash . '</td>~', $gone));
        $this->assertDoesNotMatchRegularExpression('~RNCP100</strong> —~', $gone);
    }

    public function test_site_escapes_every_name(): void {
        $evil = '<script>alert(1)</script>';
        $html = organisation_screen::site($this->site_data(
            [
                'name' => $evil,
                'models' => [(object) ['templateid' => 1, 'name' => $evil, 'visible' => 1]],
                'referents' => [(object) ['ficherang' => 'BC01', 'names' => [$evil]]],
                'rules' => [(object) ['title' => $evil, 'url' => 'https://example.org/regle']],
            ],
            [
                // The names of roles come from the core already escaped (see test_site_escapes_a_name_once()).
                'capabilities' => [(object) [
                    'name' => 'tool/vae:viewany', 'label' => $evil, 'allow' => [], 'prevent' => [], 'prohibit' => [],
                ]],
                'cohorts' => [(object) ['cohortid' => 1, 'name' => $evil, 'roleid' => 1, 'rolename' => 'Suivi']],
            ]
        ));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_site_escapes_a_name_once(): void {
        // A role's name is given as role_get_names() gives it, already through format_string().
        $html = organisation_screen::site($this->site_data(
            ['name' => 'Soins & accompagnement', 'models' => [(object) ['templateid' => 1, 'name' => 'R&D', 'visible' => 1]]],
            [
                'capabilities' => [(object) [
                    'name' => 'tool/vae:viewany', 'label' => 'Droit', 'allow' => ['Suivi &amp; VAE'],
                    'prevent' => [], 'prohibit' => [],
                ]],
                'cohorts' => [(object) [
                    'cohortid' => 1, 'name' => 'Promotion A&B', 'roleid' => 1, 'rolename' => 'Suivi &amp; VAE',
                ]],
            ]
        ));

        foreach (['Soins &amp; accompagnement', 'R&amp;D', 'Promotion A&amp;B', 'Suivi &amp; VAE'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function test_site_draws_a_hidden_template_and_a_hidden_course_as_a_word_without_a_name(): void {
        $html = organisation_screen::site($this->site_data([
            'models' => [(object) ['templateid' => 2, 'name' => null, 'visible' => 0]],
            'courses' => [(object) ['courseid' => 9, 'fullname' => null, 'visible' => 0]],
        ]));

        $this->assertStringContainsString('A hidden template', $html);
        $this->assertStringContainsString('A hidden course', $html);
        $this->assertStringNotContainsString('course/view.php?id=9', $html);
    }

    public function test_site_says_unreadable_courses_and_never_none(): void {
        $html = organisation_screen::site($this->site_data(['coursesunreadable' => true]));

        $this->assertStringContainsString(get_string('orgcoursesunreadable', 'tool_vae'), $html);
    }

    public function test_site_names_the_referents_by_bloc_and_says_when_none_is_active(): void {
        $html = organisation_screen::site($this->site_data(['referents' => [
            (object) ['ficherang' => '', 'names' => ['Alice Referente', 'Zoe Referente']],
            (object) ['ficherang' => 'BC02', 'names' => []],
        ]]));

        $this->assertStringContainsString('whole record: Alice Referente, Zoe Referente', $html);
        $this->assertStringContainsString('bloc BC02: no active referent', $html);
    }

    public function test_site_draws_the_rights_with_a_line_for_a_right_nobody_gives(): void {
        $html = organisation_screen::site($this->site_data([], ['capabilities' => [(object) [
            'name' => 'tool/vae:export', 'label' => 'Export a dossier', 'allow' => [],
            'prevent' => ['Retirant'], 'prohibit' => ['Interdisant'],
        ]]]));

        $this->assertStringContainsString('tool/vae:export', $html);
        $this->assertStringContainsString('no role', $html);
        $this->assertStringContainsString('Retirant', $html);
        $this->assertStringContainsString('Interdisant', $html);
        $this->assertStringContainsString('Site administrators hold every right', $html);
    }

    public function test_site_says_when_the_site_has_no_cohort_roles_and_when_a_cohort_was_deleted(): void {
        $none = organisation_screen::site($this->site_data([], ['cohorts' => null]));
        $this->assertStringContainsString(get_string('orgcohortsunavailable', 'tool_vae'), $none);
        $this->assertStringNotContainsString('tool-vae-site-cohorts', $none);

        $gone = organisation_screen::site($this->site_data([], ['cohorts' => [
            (object) ['cohortid' => 5, 'name' => null, 'roleid' => 3, 'rolename' => 'Suivi VAE'],
        ]]));
        $this->assertStringContainsString('tool-vae-site-cohorts', $gone);
        $this->assertStringContainsString('cohort deleted', $gone);
        $this->assertStringContainsString('Suivi VAE', $gone);
    }

    public function test_site_draws_the_settings_open_or_closed_and_the_reminder_in_days(): void {
        $html = organisation_screen::site($this->site_data([], ['settings' => [
            (object) ['name' => 'validationopen', 'value' => 1, 'open' => true],
            (object) ['name' => 'aiopen', 'value' => 0, 'open' => false],
            (object) ['name' => 'overduedays', 'value' => 14, 'open' => true],
        ]]));

        $this->assertMatchesRegularExpression('~<td[^>]*>open</td>~', $html);
        $this->assertMatchesRegularExpression('~<td[^>]*>closed</td>~', $html);
        $this->assertStringContainsString('14 days', $html);
    }

    public function test_site_without_the_subsystem_keeps_the_rights_and_says_why_the_records_are_missing(): void {
        $data = $this->site_data();
        $data->fiches = null;

        $html = organisation_screen::site($data);

        $this->assertStringNotContainsString('tool-vae-site-fiches', $html);
        $this->assertStringContainsString(get_string('errorcompetenciesdisabled', 'tool_vae'), $html);
        $this->assertStringContainsString('tool-vae-site-powers', $html);
    }

    public function test_site_says_when_no_record_is_imported(): void {
        $data = $this->site_data();
        $data->fiches = [];

        $this->assertStringContainsString(get_string('orgsitenofiche', 'tool_vae'), organisation_screen::site($data));
    }

    public function test_site_has_no_colour_no_total_and_no_action(): void {
        $html = organisation_screen::site($this->site_data(['plans' => 12]));

        $this->assertDoesNotMatchRegularExpression('~style="[^"]*(color|background)~i', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('<input', $html);
        $this->assertDoesNotMatchRegularExpression('~\btotal~i', $html);
    }

    /**
     * The activities of a line: "course > activity (type)", a link where the reader can open the activity, a word where
     * the core keeps the name, and the name already formatted by the core, not formatted twice (lot 1 bis).
     */
    public function test_the_activities_are_listed_with_a_link_and_a_word_for_those_kept_back(): void {
        $activities = [
            (object) [
                'course' => 'Cours A &amp; B', 'name' => 'Quiz &lt;un&gt;', 'type' => 'Quiz',
                'url' => 'https://example.org/mod/quiz/view.php?id=7',
            ],
            (object) ['course' => 'Cours A &amp; B', 'name' => null, 'type' => null, 'url' => null],
            (object) ['course' => null, 'name' => null, 'type' => null, 'url' => null],
        ];

        $html = organisation_screen::mine($this->pathway([$this->row(['activities' => $activities])]));

        $this->assertStringContainsString('<a href="https://example.org/mod/quiz/view.php?id=7">Quiz &lt;un&gt;</a> (Quiz)', $html);
        $this->assertStringContainsString('Cours A &amp; B › An activity that is not visible', $html);
        $this->assertStringContainsString('A hidden course › An activity that is not visible', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * "None" where no activity carries the competency, "not readable" where some do and the reader may see none.
     */
    public function test_no_activity_and_activities_withheld_are_told_apart(): void {
        $none = $this->cells(organisation_screen::mine($this->pathway([$this->row()])));
        $withheld = $this->cells(organisation_screen::mine($this->pathway([$this->row(['activitiesunreadable' => true])])));

        $this->assertSame('none', $none[1][6]);
        $this->assertSame('Not readable with your rights', $withheld[1][6]);
    }

    /**
     * Organisation shows the number of activities of a record, then the list behind it, folded away.
     */
    public function test_the_site_shows_a_count_and_a_folded_list_of_activities(): void {
        $one = [(object) [
            'course' => 'Cours', 'name' => 'Quiz', 'type' => 'Quiz', 'url' => 'https://example.org/mod/quiz/view.php?id=7',
        ]];
        $three = array_merge($one, [
            (object) ['course' => 'Cours', 'name' => null, 'type' => null, 'url' => null],
            (object) ['course' => null, 'name' => null, 'type' => null, 'url' => null],
        ]);

        $html = organisation_screen::site($this->site_data(['activities' => $three]));
        $this->assertStringContainsString('<summary', $html);
        $this->assertStringContainsString('3 activities', $html);
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('Cours › An activity that is not visible', $html);
        $this->assertStringContainsString('1 activity', organisation_screen::site($this->site_data(['activities' => $one])));
        $this->assertStringNotContainsString('<details', organisation_screen::site($this->site_data()));
        $this->assertStringContainsString(
            get_string('orgcoursesunreadable', 'tool_vae'),
            organisation_screen::site($this->site_data(['activitiesunreadable' => true]))
        );
    }

    /**
     * The type of an activity is escaped on the screen, an activity with no address is not a link, and a record with no
     * name for an activity has no empty brackets (lot 1 bis, review).
     */
    public function test_an_activity_type_is_escaped_and_an_activity_without_address_is_not_a_link(): void {
        $activities = [
            (object) ['course' => 'Cours', 'name' => 'Etiquette', 'type' => 'Type <b>gras</b>', 'url' => null],
            (object) ['course' => 'Cours', 'name' => null, 'type' => null, 'url' => null],
        ];

        $html = organisation_screen::mine($this->pathway([$this->row(['activities' => $activities])]));

        $this->assertStringContainsString('Etiquette (Type &lt;b&gt;gras&lt;/b&gt;)', $html);
        $this->assertStringNotContainsString('<b>gras</b>', $html);
        $this->assertStringNotContainsString('<a href="">', $html);
        $this->assertStringNotContainsString('()', $html);
        $this->assertStringNotContainsString('Etiquette</a>', $html);
    }

    /**
     * The number of plans is drawn as it comes, in its column, and the closed rule addresses are not drawn as none.
     */
    public function test_the_plan_count_is_in_its_cell_and_closed_addresses_are_said_closed(): void {
        $rows = $this->cells(organisation_screen::site($this->site_data(['plans' => 1, 'rules' => null])));

        $this->assertSame('1', $rows[1][6]);
        $this->assertSame(get_string('orgrulesclosed', 'tool_vae'), $rows[1][8]);

        $rows = $this->cells(organisation_screen::site($this->site_data(['plans' => 3])));
        $this->assertSame('3', $rows[1][6]);
        $this->assertSame('none', $rows[1][8]);
    }

    /**
     * A day is a day, not "1 days".
     */
    public function test_the_reminder_delay_of_one_day_is_in_the_singular(): void {
        $html = organisation_screen::site($this->site_data([], [
            'settings' => [(object) ['name' => 'overduedays', 'value' => 1, 'open' => true]],
        ]));

        $this->assertStringContainsString(get_string('orgdayone', 'tool_vae'), $html);
        $this->assertStringNotContainsString('1 days', $html);
    }

    /**
     * The grid of the actors: the roles that give a right as the core formats them (once), the word for a gap that says
     * who can still use the right, the neutral word for a right no role is meant to give, the actors without an account
     * as a sentence (lot 2).
     */
    public function test_the_grid_of_the_actors_draws_gaps_roles_and_actors_without_an_account(): void {
        $right = fn(string $name, string $state, string $status, array $roles = [], array $forbidden = [], bool $adminmay = true) =>
            (object) [
                'name' => 'tool/vae:' . $name, 'label' => 'Label ' . $name, 'status' => $status, 'roles' => $roles,
                'forbidden' => $forbidden, 'state' => $state, 'adminmay' => $adminmay,
            ];
        $actors = [
            (object) ['key' => 'vaeiste', 'account' => true, 'rights' => [
                $right('declare', 'given', 'expected', ['Suivi R&amp;D'], ['Bloque']),
                $right('draftown', 'missing', 'expected'),
            ]],
            (object) ['key' => 'responsable', 'account' => true, 'rights' => [
                $right('viewassigned', 'missing', 'expected', [], [], false),
            ]],
            (object) ['key' => 'direction', 'account' => true, 'rights' => [$right('draftview', 'free', 'optional')]],
            (object) ['key' => 'administrateur', 'account' => true, 'rights' => [$right('import', 'free', 'admin')]],
            (object) ['key' => 'jury', 'account' => false, 'rights' => []],
        ];

        $html = organisation_screen::site($this->site_data([], ['actors' => $actors]));
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR);
        $xpath = new \DOMXPath($document);
        $rows = [];
        foreach ($xpath->query('//table[contains(@class, "tool-vae-site-actors")]//tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement) {
                    $cells[] = trim((string) preg_replace('/\s+/u', ' ', $cell->textContent));
                }
            }
            $rows[] = $cells;
        }

        $this->assertSame(['Actor', 'Right', 'Roles that give it'], $rows[0]);
        $this->assertSame('Vaeiste', $rows[1][0]);
        $this->assertSame('Label declare tool/vae:declare', $rows[1][1]);
        $this->assertSame('Suivi R&D (forbidden by Bloque)', $rows[1][2]);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertSame('Missing No role gives this right: only the site administrators can use it', $rows[2][2]);
        $this->assertSame('Missing No role gives this right: nobody can use it', $rows[3][2]);
        $this->assertSame('No role (optional)', $rows[4][2]);
        $this->assertSame('No role: kept for the site administrators', $rows[5][2]);
        $this->assertCount(6, $rows, 'An actor without an account has no row.');
        $this->assertStringContainsString('No account: the jury receives the archive and decides alone.', $html);
    }
}
