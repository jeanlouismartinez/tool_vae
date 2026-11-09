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
use tool_vae\local\display_date;
use tool_vae\local\validation_catalog;
use tool_vae\local\whereami_panel;

/**
 * The panel "where am I" (lot 1b, task 7): the tree of a record in reading, with the vaeiste's own level and the counts of
 * situations and declarations of each node, on the declaration page, the preparation and the part "lier".
 *
 * WHAT IS ASSERTED. Every node in the order of the tree, its level name, the vaeiste's level and two counts of ITS OWN - never a
 * sum, never a rate, nothing carried up to a parent; boxes only on the nodes a situation may carry; every label escaped; the way
 * to the self-positioning said to the vaeiste and to a reader in their words; a rank that left the record shown apart, never
 * folded into a parent; and the three pages that show it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(whereami_panel::class)]
final class whereami_panel_test extends \advanced_testcase {
    /** @var \stdClass Declares, positions themselves and writes their preparation. */
    private $vaeiste;

    /** @var \stdClass Another vaeiste, with the same rights, who declared nothing. */
    private $othervaeiste;

    /** @var \stdClass Follows the vaeiste. */
    private $manager;

    /** @var \stdClass Declares and views their own dossier, but neither positions themselves nor writes a preparation. */
    private $limited;

    /** @var \tool_vae_generator */
    private $generator;

    /**
     * RNCP100 (BC01 holding C01 and C02, BC02 holding C03) and RNCP400 (four levels: D1 holding C11, which holds H111 and
     * H112, and C12; D2 alone); the people; the preparation open; one declaration of the vaeiste on RNCP100 C01.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        validation_catalog::reset();
        set_config('validationopen', 1, 'tool_vae');

        $datagenerator = $this->getDataGenerator();
        $this->generator = $datagenerator->get_plugin_generator('tool_vae');
        $this->generator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        $this->generator->create_competency(['fichecode' => 'RNCP100', 'ficherang' => 'BC02', 'shortname' => 'Bloc 2']);
        foreach ([['C01', 'BC01'], ['C02', 'BC01'], ['C03', 'BC02']] as [$rank, $bloc]) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP100', 'ficherang' => $rank, 'shortname' => 'Competence ' . $rank, 'bloc' => $bloc,
            ]);
        }
        $this->generator->create_source(['fichecode' => 'RNCP400', 'ficherang' => 'D1', 'shortname' => 'Domaine 1']);
        foreach ([['C11', 'D1'], ['H111', 'C11'], ['H112', 'C11'], ['C12', 'D1'], ['D2', '']] as [$rank, $parent]) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP400', 'ficherang' => $rank, 'shortname' => 'Noeud ' . $rank, 'parent' => $parent,
            ]);
        }

        $system = \context_system::instance()->id;
        $this->vaeiste = $datagenerator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->othervaeiste = $datagenerator->create_user(['firstname' => 'Sacha', 'lastname' => 'Autre']);
        $this->manager = $datagenerator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);

        $own = $datagenerator->create_role();
        foreach (['declare', 'viewown', 'positionself', 'draftown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, $system, true);
        }
        set_role_contextlevels($own, [CONTEXT_USER]);
        foreach ([$this->vaeiste, $this->othervaeiste] as $person) {
            role_assign($own, $person->id, \context_user::instance($person->id)->id);
        }
        $follow = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, \context_user::instance($this->vaeiste->id)->id);
        $this->limited = $datagenerator->create_user(['firstname' => 'Lou', 'lastname' => 'Limite']);
        $declareonly = $datagenerator->create_role();
        foreach (['declare', 'viewown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $declareonly, $system, true);
        }
        set_role_contextlevels($declareonly, [CONTEXT_USER]);
        role_assign($declareonly, $this->limited->id, \context_user::instance($this->limited->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Une situation.',
        ]);
    }

    /**
     * A situation of the vaeiste.
     *
     * @param string $fichecode
     * @param string $tags Ranks separated by a comma.
     * @param string $title
     * @param array $more Other fields for the generator.
     * @return \stdClass
     */
    private function situation(
        string $fichecode,
        string $tags,
        string $title = 'Situation de travail',
        array $more = []
    ): \stdClass {
        return $this->generator->create_situation([
            'userid' => $this->vaeiste->id, 'fichecode' => $fichecode, 'tags' => $tags, 'title' => $title,
        ] + $more);
    }

    /**
     * The panel of a record, as a reader sees it.
     *
     * @param string $fichecode
     * @param array $options
     * @param \stdClass|null $reader The vaeiste when none is given.
     * @return string
     */
    private function panel(string $fichecode, array $options = [], ?\stdClass $reader = null): string {
        $this->setUser($reader ?? $this->vaeiste);

        return whereami_panel::render((int) $this->vaeiste->id, $fichecode, $options);
    }

    /**
     * The line of one node of the panel.
     *
     * @param string $html
     * @param string $rank
     * @return string
     */
    private function node(string $html, string $rank): string {
        $found = preg_match('~<li[^>]*data-rank="' . preg_quote($rank, '~') . '"[^>]*>(.*?)</li>~s', $html, $matches);
        $this->assertSame(1, $found, 'The node ' . $rank . ' is listed.');

        return $matches[1];
    }

    /**
     * The words of the two counts.
     *
     * @param int $situations
     * @param int $declarations
     * @return string
     */
    private function counts(int $situations, int $declarations): string {
        return s(get_string('whereamicounts', 'tool_vae', (object) [
            'situations' => $situations, 'declarations' => $declarations,
        ]));
    }

    /**
     * A page of the plugin, played as the site plays it.
     *
     * @param array $get
     * @param string $script
     * @return string
     */
    private function page(array $get, string $script): string {
        // The page is a script of the site: it reads these as globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SITE, $USER;

        $_GET = $get;
        $PAGE = new \moodle_page();
        ob_start();
        try {
            include(__DIR__ . '/../' . $script);
        } finally {
            $out = ob_get_clean();
            $_GET = [];
        }

        return $out;
    }

    public function test_the_panel_shows_every_node_with_level_words_and_counts_on_a_two_and_a_four_level_record(): void {
        $line = $this->generator->create_selfpos([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP400', 'ficherang' => 'H111', 'level' => 3,
        ]);
        $this->situation('RNCP400', 'H111');
        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP400', 'ficherang' => 'H111', 'situation' => 'Sur H111.',
        ]);

        $html = $this->panel('RNCP400');

        $this->assertStringContainsString('tool-vae-whereami', $html);
        $this->assertStringContainsString(s(get_string('whereamititle', 'tool_vae')), $html);
        $this->assertSame(7, substr_count($html, 'tool-vae-whereami-node'), 'The record and its six nodes.');
        $order = [strpos($html, 'data-rank=""')];
        $this->assertStringContainsString(s(get_string('selfposrecordlevel', 'tool_vae')), $this->node($html, ''));
        foreach (['Domaine 1', 'Noeud C11', 'Noeud H111', 'Noeud H112', 'Noeud C12', 'Noeud D2'] as $label) {
            $this->assertStringContainsString($label, $html);
            $order[] = strpos($html, $label);
        }
        $sorted = $order;
        sort($sorted);
        $this->assertSame($sorted, $order, 'In the order of the tree.');
        $this->assertMatchesRegularExpression('~<li[^>]*data-level="4"[^>]*data-rank="H111"~', $html, 'Set in by its depth.');
        $this->assertStringContainsString(s(get_string('selfposlevelname', 'tool_vae', 4)), $this->node($html, 'H111'));
        $this->assertStringContainsString(s(get_string('selfposcurrent', 'tool_vae', (object) [
            'label' => get_string('selfposlabeldefault3', 'tool_vae'),
            'when' => userdate((int) $line->timecreated, get_string('strftimedate', 'langconfig')),
        ])), $this->node($html, 'H111'));
        $this->assertSame(6, substr_count($html, s(get_string('selfposnone', 'tool_vae'))), 'The six other nodes have no level.');
        $this->assertStringContainsString($this->counts(1, 1), $this->node($html, 'H111'));
        $this->assertStringContainsString($this->counts(0, 0), $this->node($html, 'C11'));
        $this->assertStringContainsString(
            'situations.php?userid=' . $this->vaeiste->id . '&amp;fiche=RNCP400&amp;rank=H111',
            $this->node($html, 'H111'),
            'The way to the situations and declarations of the node.'
        );
        $this->assertStringNotContainsString('situations.php', $this->node($html, 'C11'), 'Nothing to see, no way.');

        // A record of blocs and competencies: three levels, the same reading.
        $html = $this->panel('RNCP100');
        $this->assertSame(6, substr_count($html, 'tool-vae-whereami-node'));
        $this->assertStringContainsString($this->counts(0, 1), $this->node($html, 'C01'));
        $this->assertStringContainsString($this->counts(0, 0), $this->node($html, 'BC01'), 'The bloc keeps its own count.');
        $this->assertStringContainsString('rank=C01', $html);
        $this->assertDoesNotMatchRegularExpression('/\d+ ?%/', $html, 'No rate.');
    }

    public function test_no_count_is_summed_to_a_parent(): void {
        global $DB;

        $this->situation('RNCP400', 'C11', 'Premiere');
        $this->situation('RNCP400', 'C11,H111', 'Deuxieme');
        $this->situation('RNCP400', 'D1', 'Troisieme');

        $html = $this->panel('RNCP400');

        $this->assertStringContainsString($this->counts(1, 0), $this->node($html, 'D1'), 'Its own situation, not three.');
        $this->assertStringContainsString($this->counts(2, 0), $this->node($html, 'C11'), 'Its own two, not three.');
        $this->assertStringContainsString($this->counts(1, 0), $this->node($html, 'H111'));
        $this->assertStringContainsString($this->counts(0, 0), $this->node($html, ''), 'The record gets nothing from its nodes.');
        $this->assertStringNotContainsString($this->counts(3, 0), $html, 'Nothing added up anywhere.');
        $this->assertStringNotContainsString($this->counts(4, 0), $html);

        $counts = whereami_panel::counts((int) $this->vaeiste->id, 'RNCP400');
        $this->assertEquals((object) ['situations' => 1, 'declarations' => 0], $counts['D1']);
        $this->assertEquals((object) ['situations' => 2, 'declarations' => 0], $counts['C11']);
        $this->assertEquals((object) ['situations' => 1, 'declarations' => 0], $counts['H111']);
        $this->assertArrayNotHasKey('', $counts, 'A rank with nothing is absent: no parent is filled from its children.');
        $this->assertArrayNotHasKey('C12', $counts);

        // Two grouped queries, never one per node.
        $before = $DB->perf_get_reads();
        whereami_panel::counts((int) $this->vaeiste->id, 'RNCP400');
        $this->assertSame(2, $DB->perf_get_reads() - $before);
    }

    public function test_boxes_appear_only_on_taggable_nodes_and_checked_ranks_are_checked(): void {
        $this->setAdminUser();
        $gateway = new competency_gateway();
        $gateway->create_competency((int) $gateway->find_framework('RNCP400'), 'AS-1-1', 'Un code a tirets');

        $html = $this->panel('RNCP400', ['boxes' => true, 'checked' => ['C11', 'H112']]);

        $this->assertSame(6, substr_count($html, 'type="checkbox"'), 'D1, C11, H111, H112, C12, D2: never the record.');
        $this->assertSame(6, substr_count($html, 'name="tags[]"'));
        $this->assertStringNotContainsString('type="checkbox"', $this->node($html, ''), 'The record carries no tag.');
        $this->assertStringNotContainsString('type="checkbox"', $this->node($html, 'AS-1-1'), 'Not positionable, no box.');
        $this->assertStringContainsString(
            s(get_string('selfposexplainnotpositionable', 'tool_vae')),
            $this->node($html, 'AS-1-1')
        );
        $this->assertStringNotContainsString($this->counts(0, 0), $this->node($html, 'AS-1-1'), 'Not positionable, no count.');
        $this->assertSame(2, substr_count($html, 'checked="checked"'));
        $this->assertMatchesRegularExpression('/value="C11"[^>]*checked="checked"|checked="checked"[^>]*value="C11"/', $html);
        $this->assertMatchesRegularExpression('/value="H112"[^>]*checked="checked"|checked="checked"[^>]*value="H112"/', $html);
        $this->assertDoesNotMatchRegularExpression('/value="D1"[^>]*checked="checked"/', $html);

        $this->assertStringNotContainsString('type="checkbox"', $this->panel('RNCP400'), 'No box unless asked for.');
    }

    public function test_every_label_is_escaped(): void {
        // The core refuses a tag in a shortname; the characters HTML would read are enough to show the escaping.
        $this->generator->create_competency([
            'fichecode' => 'RNCP400', 'ficherang' => 'X1', 'shortname' => 'Soins & "aide" < 3', 'parent' => 'D1',
        ]);
        $this->situation('RNCP400', 'X1');

        $html = $this->panel('RNCP400', ['boxes' => true, 'link' => true, 'title' => '<script>alert(1)</script>Titre']);

        $node = $this->node($html, 'X1');
        $this->assertStringContainsString('Soins &amp; ', $node);
        $this->assertStringContainsString(' &lt; 3', $node);
        $this->assertStringNotContainsString('Soins & ', $html);
        $this->assertStringNotContainsString(' < 3', $html);
        $this->assertStringNotContainsString('<script', $html, 'The title is escaped too.');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;Titre', $html);
    }

    public function test_the_link_says_position_myself_to_the_vaeiste_and_read_to_a_reader(): void {
        $address = 'selfposition.php?userid=' . $this->vaeiste->id . '&amp;fiche=RNCP400';

        $html = $this->panel('RNCP400', ['link' => true]);
        $this->assertStringContainsString($address, $html);
        $this->assertStringContainsString('>' . s(get_string('selfposlink', 'tool_vae')) . '</a>', $html);
        $this->assertStringNotContainsString(s(get_string('selfposreadall', 'tool_vae')), $html);

        $html = $this->panel('RNCP400', ['link' => true], $this->manager);
        $this->assertStringContainsString($address, $html);
        $this->assertStringContainsString('>' . s(get_string('selfposreadall', 'tool_vae')) . '</a>', $html);
        $this->assertStringNotContainsString('>' . s(get_string('selfposlink', 'tool_vae')) . '</a>', $html);

        $this->assertStringNotContainsString('selfposition.php', $this->panel('RNCP400'), 'No link unless asked for.');
    }

    public function test_a_tag_on_a_node_that_left_the_record_is_shown_as_gone_without_error(): void {
        global $DB;

        $situation = $this->situation('RNCP400', 'H112');
        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP400', 'ficherang' => 'C12', 'situation' => 'Sur C12.',
        ]);
        // The nodes H112 and C12 left the record at a later import: what was written on them keeps its rank.
        $DB->set_field('tool_vae_sitetag', 'ficherang', 'H199', ['situationid' => (int) $situation->id]);
        $DB->set_field('tool_vae_evidence', 'ficherang', 'C99', [
            'candidateid' => (int) $this->vaeiste->id, 'fichecode' => 'RNCP400',
        ]);

        $html = $this->panel('RNCP400');

        $gone = strpos($html, s(get_string('whereamigone', 'tool_vae')));
        $this->assertNotFalse($gone, 'Listed apart.');
        $this->assertGreaterThan(strpos($html, 'Noeud D2'), $gone, 'After the tree.');
        $this->assertStringContainsString($this->counts(1, 0), $this->node($html, 'H199'));
        $this->assertStringContainsString($this->counts(0, 1), $this->node($html, 'C99'));
        $this->assertStringContainsString('rank=H199', $html);
        $this->assertStringContainsString('rank=C99', $html);
        $this->assertStringContainsString($this->counts(0, 0), $this->node($html, 'C11'), 'Never folded into a parent.');
        $this->assertStringContainsString($this->counts(0, 0), $this->node($html, 'D1'));
        $this->assertStringContainsString($this->counts(0, 0), $this->node($html, 'H112'));
    }

    public function test_the_declaration_page_shows_the_panel_of_each_declared_record(): void {
        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP400', 'ficherang' => 'C11', 'situation' => 'Sur C11.',
        ]);

        $this->setUser($this->vaeiste);
        $out = $this->page([], 'declare.php');

        $this->assertSame(2, substr_count($out, '<details'), 'One fold per record declared on.');
        $this->assertStringContainsString(s(get_string('whereamideclare', 'tool_vae', 'RNCP100')), $out);
        $this->assertStringContainsString(s(get_string('whereamideclare', 'tool_vae', 'RNCP400')), $out);
        $this->assertStringContainsString('Noeud H111', $out);
        $this->assertStringContainsString('selfposition.php?userid=' . $this->vaeiste->id . '&amp;fiche=RNCP400', $out);
        $this->assertStringContainsString('name="situation"', $out, 'The form stays as it was.');
        $this->assertLessThan(strpos($out, 'name="situation"'), strpos($out, 'tool-vae-whereami'), 'Before the form.');

        // Nothing declared: one way to position oneself, and no panel.
        $this->setUser($this->othervaeiste);
        $out = $this->page([], 'declare.php');
        $this->assertStringNotContainsString('<details', $out);
        $this->assertStringNotContainsString('tool-vae-whereami', $out);
        $this->assertStringContainsString('selfposition.php?userid=' . $this->othervaeiste->id . '"', $out);
        $this->assertStringContainsString('>' . s(get_string('selfposlink', 'tool_vae')) . '</a>', $out);
    }

    public function test_the_preparation_page_shows_the_panel_above_the_blocs(): void {
        $this->situation('RNCP100', 'C02');

        $this->setUser($this->vaeiste);
        $out = $this->page(['fiche' => 'RNCP100'], 'validation.php');
        $panel = strpos($out, 'tool-vae-whereami');
        $this->assertNotFalse($panel);
        $this->assertLessThan(strpos($out, 'BC01 - Bloc 1'), $panel, 'Above the blocs.');
        $this->assertStringContainsString($this->counts(1, 0), $this->node($out, 'C02'));
        $this->assertStringContainsString('>' . s(get_string('selfposlink', 'tool_vae')) . '</a>', $out);

        // A reader reads it too, and is told to read.
        $this->setUser($this->manager);
        $out = $this->page(['userid' => (int) $this->vaeiste->id, 'fiche' => 'RNCP100'], 'validation.php');
        $panel = strpos($out, 'tool-vae-whereami');
        $this->assertNotFalse($panel);
        $this->assertLessThan(strpos($out, 'BC01 - Bloc 1'), $panel);
        $this->assertStringContainsString($this->counts(1, 0), $this->node($out, 'C02'));
        $this->assertStringContainsString('>' . s(get_string('selfposreadall', 'tool_vae')) . '</a>', $out);
    }

    /**
     * T15, point Z1 (decision of 9 November 2026): the title of the panel speaks in the first person to the vaeiste alone;
     * a reader - the manager who follows, the direction - reads "where they stand".
     */
    public function test_the_title_speaks_in_the_first_person_to_the_vaeiste_and_in_the_third_to_a_reader(): void {
        $this->situation('RNCP100', 'C02');
        $own = s(get_string('whereamititle', 'tool_vae'));
        $read = s(get_string('whereamititlereader', 'tool_vae'));
        $this->assertNotSame($own, $read);
        $this->assertStringNotContainsString('[[', $read, 'The string exists.');

        $html = $this->panel('RNCP100');
        $this->assertStringContainsString('<h4 class="h5">' . $own . '</h4>', $html);
        $this->assertStringNotContainsString($read, $html);

        $html = $this->panel('RNCP100', [], $this->manager);
        $this->assertStringContainsString('<h4 class="h5">' . $read . '</h4>', $html);
        $this->assertStringNotContainsString($own, $html);

        // A title the caller gives is kept as it is, for either reader.
        $this->assertStringContainsString('<h4 class="h5">Un titre</h4>', $this->panel('RNCP100', ['title' => 'Un titre']));
        $this->assertStringNotContainsString('<h4', $this->panel('RNCP100', ['title' => ''], $this->manager));

        // The pages that leave the title to the panel follow: the preparation and the list of the situations read by a manager.
        $this->setUser($this->manager);
        foreach (['validation.php', 'situations.php'] as $script) {
            $out = $this->page(['userid' => (int) $this->vaeiste->id, 'fiche' => 'RNCP100'], $script);
            $this->assertStringContainsString($read, $out, $script);
            $this->assertStringNotContainsString($own, $out, $script);
        }
        $this->setUser($this->vaeiste);
        foreach (['validation.php', 'situations.php'] as $script) {
            $out = $this->page(['fiche' => 'RNCP100'], $script);
            $this->assertStringContainsString($own, $out, $script);
            $this->assertStringNotContainsString($read, $out, $script);
        }
    }

    public function test_the_link_part_lists_the_situations_tagged_on_the_bloc_and_its_descendants(): void {
        $start = strtotime('2026-01-05 00:00:00 UTC');
        $end = strtotime('2026-03-20 00:00:00 UTC');
        $onbloc = $this->situation('RNCP100', 'BC01', 'Sur le bloc', ['periodstart' => $start, 'periodend' => $end]);
        $onchild = $this->situation('RNCP100', 'C02', 'Sur "une" competence & plus');
        $this->situation('RNCP100', '', 'Sans etiquette');

        // A bloc no situation carries says so.
        $this->setUser($this->vaeiste);
        $out = $this->page(['doctype' => 'validation', 'fiche' => 'RNCP100', 'section' => 'bc02_lier'], 'section.php');
        $this->assertStringContainsString(s(get_string('validlinksituationsnone', 'tool_vae')), $out);
        $this->assertStringNotContainsString('Sur le bloc', $out);
        $this->assertStringNotContainsString('Sans etiquette', $out);

        $this->situation('RNCP100', 'C03', 'Sur l autre bloc');
        $this->setUser($this->vaeiste);
        $out = $this->page(['doctype' => 'validation', 'fiche' => 'RNCP100', 'section' => 'bc01_lier'], 'section.php');

        $this->assertStringContainsString(s(get_string('validlinksituations', 'tool_vae')), $out);
        $this->assertStringNotContainsString(s(get_string('validlinksituationsnone', 'tool_vae')), $out);
        $this->assertStringContainsString('Sur le bloc', $out);
        $this->assertStringContainsString('Sur &quot;une&quot; competence &amp; plus', $out, 'The title is escaped.');
        $this->assertStringNotContainsString('Sur l autre bloc', $out, 'Another bloc.');
        $this->assertStringNotContainsString('Sans etiquette', $out);
        $this->assertStringContainsString(
            s(display_date::format($start, get_string('strftimedate', 'langconfig'))),
            $out,
            'The period.'
        );
        $this->assertStringContainsString(s(display_date::format($end, get_string('strftimedate', 'langconfig'))), $out);
        foreach ([$onbloc, $onchild] as $situation) {
            $this->assertStringContainsString(
                'situation.php?userid=' . $this->vaeiste->id . '&amp;fiche=RNCP100&amp;slot=' . $situation->slot,
                $out
            );
        }
        $this->assertLessThan(strpos($out, 'name="text"'), strpos($out, 'Sur le bloc'), 'Above the form.');

        $out = $this->page(['doctype' => 'validation', 'fiche' => 'RNCP100', 'section' => 'bc02_lier'], 'section.php');
        $this->assertStringContainsString('Sur l autre bloc', $out);
        $this->assertStringNotContainsString('Sur le bloc', $out);

        // Another part says nothing of the situations.
        $out = $this->page(['doctype' => 'validation', 'fiche' => 'RNCP100', 'section' => 'bc01_decrire'], 'section.php');
        $this->assertStringNotContainsString(s(get_string('validlinksituations', 'tool_vae')), $out);
        $this->assertStringNotContainsString('Sur le bloc', $out);
    }

    public function test_a_reader_who_may_not_read_the_situations_is_given_the_declarations_only(): void {
        global $DB;

        $this->generator->create_declaration([
            'userid' => $this->limited->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Sur C01.',
        ]);
        $DB->insert_record('tool_vae_evidence', (object) [
            'uuid' => \tool_vae\local\identity_map::generate_ulid(), 'userevidenceid' => 99001,
            'candidateid' => (int) $this->limited->id, 'periodstart' => 1, 'periodend' => 2, 'settingtype' => 'ehpad',
            'situationformat' => FORMAT_PLAIN, 'situation' => 'Sur un rang parti.', 'fichecode' => 'RNCP100',
            'ficherang' => 'C99', 'ficheupdated' => 1, 'timecreated' => 1, 'timemodified' => 1,
            'usermodified' => (int) $this->limited->id,
        ]);
        $declarations = fn(int $n) => s(get_string('whereamideclarationsonly', 'tool_vae', (object) ['declarations' => $n]));

        // The site administrator views every dossier but never reads the preparation (D-1b-G). The vaeiste without draftown
        // does read it, as one who may view their own dossier: not a case of this rule.
        $this->setUser($this->limited);
        $this->assertSame(0, whereami_panel::counts((int) $this->limited->id, 'RNCP100')['C01']->situations);
        foreach ([get_admin()] as $reader) {
            $this->setUser($reader);
            $html = whereami_panel::render((int) $this->limited->id, 'RNCP100');
            $text = strip_tags($html);
            $this->assertStringNotContainsString('situation', $text, 'Nothing said of what is not read.');
            $this->assertStringNotContainsString('0 situation', $text);
            $this->assertStringContainsString($declarations(1), $this->node($html, 'C01'));
            $this->assertStringContainsString($declarations(1), $this->node($html, 'C99'), 'Gone: its declarations only.');
            $this->assertNull(whereami_panel::counts((int) $this->limited->id, 'RNCP100')['C01']->situations);
            // No way to the list of the situations either: situations.php would refuse this reader (T15, point G1).
            $this->assertStringNotContainsString('situations.php', $html, 'No link to what is not read.');
            $this->assertStringNotContainsString(s(get_string('whereamiinverse', 'tool_vae')), $html);
        }
    }

    public function test_a_node_that_is_not_positionable_keeps_its_counts_in_the_tree(): void {
        global $DB;

        $this->setAdminUser();
        $gateway = new competency_gateway();
        $gateway->create_competency((int) $gateway->find_framework('RNCP400'), 'AS-1-1', 'Un code a tirets');
        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP400', 'ficherang' => 'C12', 'situation' => 'Sur C12.',
        ]);
        $DB->set_field('tool_vae_evidence', 'ficherang', 'AS-1-1', [
            'candidateid' => (int) $this->vaeiste->id, 'fichecode' => 'RNCP400',
        ]);

        $html = $this->panel('RNCP400', ['boxes' => true]);

        $this->assertStringNotContainsString(s(get_string('whereamigone', 'tool_vae')), $html, 'It is in the record.');
        $node = $this->node($html, 'AS-1-1');
        $this->assertStringContainsString($this->counts(0, 1), $node);
        $this->assertStringContainsString('rank=AS-1-1', $node);
        $this->assertStringNotContainsString('type="checkbox"', $node);
        $this->assertSame(1, substr_count($html, 'data-rank="AS-1-1"'), 'Once, in the tree.');
    }

    public function test_the_declaration_page_offers_no_way_to_position_oneself_to_who_may_not(): void {
        $this->setUser($this->limited);
        $out = $this->page([], 'declare.php');

        $this->assertStringNotContainsString('selfposition.php', $out);
        $this->assertStringNotContainsString('tool-vae-whereami', $out);
    }
}
