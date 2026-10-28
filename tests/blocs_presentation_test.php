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

use tool_vae\form\declare_form;
use tool_vae\local\competency_gateway;
use tool_vae\local\coverage;
use tool_vae\local\evidence_manager;
use tool_vae\local\importer;
use tool_vae\local\outline;
use tool_vae\local\presenter;

/**
 * A record presented by bloc: the list to declare in, the coverage, the dossier (T-33, lot 2).
 *
 * WHAT IS ASSERTED. That the outline puts blocs first and their competencies under them, in rank order whatever the
 * core's own order; that a flat record gives one node per competency and the plain list it always did; that the
 * coverage adds up a bloc's counts without ever naming a state; that a flat record's coverage is unchanged; that
 * the declaration list is grouped by bloc, the bloc itself choosable; that the dossier names the bloc of a
 * declaration.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\outline::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\coverage::class)]
final class blocs_presentation_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var int The record RS00000, with blocs. */
    private $frameworkid;

    /**
     * A record with two blocs, a competency under none, and a candidate who may declare.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/formslib.php');
        $this->setAdminUser();

        $result = (new importer())->import((object) [
            'fichecode' => 'RS00000',
            'fichetype' => 'rs',
            'name' => 'Certification inventée pour les tests',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'Personne — jeu d\'essai',
        ], importer::rows_from_csv(
            "rang,intitule,description,bloc\n"
            . "BC02,Travailler en équipe,,\n"
            . "C03,Transmettre,,BC02\n"
            . "BC01,Accompagner la personne,,\n"
            . "C10,Aider au repas,,BC01\n"
            . "C02,Aider au lever,,BC01\n"
            . "Z01,Une compétence hors bloc,,\n"
        ));
        $this->frameworkid = (int) $result->frameworkid;

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The competencies of the record, keyed by idnumber.
     *
     * @return \stdClass[]
     */
    private function competencies(): array {
        $out = [];
        foreach ((new competency_gateway())->list_framework_competencies($this->frameworkid) as $competency) {
            $out[$competency->idnumber] = $competency;
        }

        return $out;
    }

    /**
     * A declaration by the candidate.
     *
     * @param string $idnumber
     * @return \stdClass
     */
    private function declare(string $idnumber): \stdClass {
        $this->setUser($this->candidate);

        return (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => $this->competencies()[$idnumber]->id,
            'periodstart' => 1756684800,
            'periodend' => 1782777600,
            'settingtype' => 'ehpad',
            'situation' => 'Accompagnement au lever.',
            'situationformat' => FORMAT_PLAIN,
        ]);
    }

    public function test_the_outline_puts_blocs_first_and_their_competencies_under_them_in_rank_order(): void {
        $nodes = outline::group($this->competencies());

        $this->assertSame(
            ['RS00000-BC01', 'RS00000-BC02', 'RS00000-Z01'],
            array_map(fn($n) => $n->competency->idnumber, $nodes)
        );
        $this->assertSame(
            ['RS00000-C02', 'RS00000-C10'],
            array_map(fn($c) => $c->idnumber, $nodes[0]->children),
            'Natural order of the ranks: C02 before C10, whatever the order the core holds.'
        );
        $this->assertSame([], $nodes[2]->children);
        $this->assertTrue(outline::has_blocs($nodes));
    }

    public function test_a_flat_record_gives_one_node_per_competency(): void {
        $flat = [
            (object) ['id' => 1, 'idnumber' => 'X-2', 'parentid' => 0],
            (object) ['id' => 2, 'idnumber' => 'X-10', 'parentid' => 0],
            (object) ['id' => 3, 'idnumber' => 'X-1', 'parentid' => 0],
        ];

        $nodes = outline::group($flat);

        $this->assertSame(['X-1', 'X-2', 'X-10'], array_map(fn($n) => $n->competency->idnumber, $nodes));
        $this->assertFalse(outline::has_blocs($nodes));
    }

    public function test_a_competency_whose_bloc_is_not_in_the_list_stands_alone(): void {
        $nodes = outline::group([(object) ['id' => 5, 'idnumber' => 'X-1', 'parentid' => 99]]);

        $this->assertCount(1, $nodes);
        $this->assertSame([], $nodes[0]->children);
    }

    public function test_the_coverage_lists_a_bloc_then_its_competencies_and_adds_up_the_counts(): void {
        $this->declare('RS00000-BC01');
        $this->declare('RS00000-C02');
        $this->declare('RS00000-C02');
        $this->declare('RS00000-C03');

        $fiche = coverage::for_candidate((int) $this->candidate->id)['RS00000'];

        $this->assertTrue($fiche->hasblocs);
        $this->assertSame(
            ['RS00000-BC01', 'RS00000-C02', 'RS00000-C10', 'RS00000-BC02', 'RS00000-C03', 'RS00000-Z01'],
            array_column($fiche->competencies, 'idnumber')
        );
        $rows = array_column($fiche->competencies, null, 'idnumber');
        $this->assertTrue($rows['RS00000-BC01']->isbloc);
        $this->assertSame(1, $rows['RS00000-BC01']->declarations, 'Its own.');
        $this->assertSame(3, $rows['RS00000-BC01']->blocdeclarations, 'Its own and those of its competencies.');
        $this->assertSame(1, $rows['RS00000-BC02']->blocdeclarations);
        $this->assertSame('RS00000-BC01', $rows['RS00000-C02']->bloc);
        $this->assertNull($rows['RS00000-Z01']->bloc);
        $this->assertFalse($rows['RS00000-Z01']->isbloc);
    }

    public function test_a_flat_record_keeps_its_coverage_as_it_was(): void {
        $this->setAdminUser();
        $flat = (new importer())->import((object) [
            'fichecode' => 'RS11111', 'fichetype' => 'rs', 'name' => 'Plate',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv("rang,intitule\nC10,Dix\nC02,Deux\n"));
        $competencies = (new competency_gateway())->list_framework_competencies((int) $flat->frameworkid);
        $this->setUser($this->candidate);
        (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => array_values(array_filter($competencies, fn($c) => $c->idnumber === 'RS11111-C02'))[0]->id,
            'periodstart' => 1756684800, 'periodend' => 1782777600, 'settingtype' => 'ehpad',
            'situation' => 'Une situation.', 'situationformat' => FORMAT_PLAIN,
        ]);

        $fiche = coverage::for_candidate((int) $this->candidate->id)['RS11111'];

        $this->assertFalse($fiche->hasblocs);
        $this->assertSame(['RS11111-C02', 'RS11111-C10'], array_column($fiche->competencies, 'idnumber'));
    }

    public function test_the_coverage_table_says_bloc_in_words_and_never_a_state(): void {
        $this->declare('RS00000-C02');

        $html = presenter::coverage(coverage::for_candidate((int) $this->candidate->id));

        $this->assertStringContainsString(get_string('coverageblocnote', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('coveragebloctag', 'tool_vae'), $html);
        $this->assertStringContainsString('ps-4', $html, 'The competencies under a bloc are set in.');
        foreach (['complet', 'complete', 'validé', 'validated', '%'] as $verdict) {
            $this->assertStringNotContainsStringIgnoringCase($verdict, strip_tags($html));
        }
    }

    public function test_the_sums_of_a_bloc_carry_the_attestations_and_the_latest_date(): void {
        global $DB;
        $first = $this->declare('RS00000-C02');
        $second = $this->declare('RS00000-C10');
        $this->declare('RS00000-BC01');
        foreach ([[$first, 1000], [$second, 2000]] as [$claim, $at]) {
            $DB->insert_record('tool_vae_attest', (object) [
                'uuid' => local\identity_map::generate_ulid(), 'evidenceid' => $claim->id,
                'attestantid' => (int) get_admin()->id, 'attestantrole' => 'Cadre', 'statement' => 'Atteste.',
                'attestedat' => $at, 'timecreated' => $at, 'timemodified' => $at, 'usermodified' => (int) get_admin()->id,
            ]);
        }

        $rows = array_column(coverage::for_candidate((int) $this->candidate->id)['RS00000']->competencies, null, 'idnumber');

        $this->assertSame(2, $rows['RS00000-BC01']->blocattested);
        $this->assertSame(3, $rows['RS00000-BC01']->blocdeclarations);
        $this->assertSame(2000, $rows['RS00000-BC01']->bloclastattested);
        $this->assertSame(0, $rows['RS00000-BC02']->blocattested);
        $this->assertNull($rows['RS00000-BC02']->bloclastattested);
    }

    public function test_a_competency_hanging_lower_than_two_levels_is_not_lost_from_the_list(): void {
        $nodes = outline::group([
            (object) ['id' => 1, 'idnumber' => 'X-A', 'parentid' => 0],
            (object) ['id' => 2, 'idnumber' => 'X-B', 'parentid' => 1],
            (object) ['id' => 3, 'idnumber' => 'X-C', 'parentid' => 2],
        ]);

        $this->assertCount(1, $nodes);
        $this->assertSame(['X-B', 'X-C'], array_map(fn($c) => $c->idnumber, $nodes[0]->children));
    }

    public function test_a_flat_record_beside_a_record_with_blocs_is_not_called_under_no_bloc(): void {
        $with = (new competency_gateway())->list_framework_competencies($this->frameworkid);
        $nodes = [];
        foreach (outline::group($with) as $node) {
            $node->fichecode = 'RS00000';
            $nodes[] = $node;
        }
        $flat = [
            (object) ['id' => 901, 'idnumber' => 'RS11111-C1', 'shortname' => 'Une', 'parentid' => 0],
            (object) ['id' => 902, 'idnumber' => 'RS11111-C2', 'shortname' => 'Deux', 'parentid' => 0],
        ];
        foreach (outline::group($flat) as $node) {
            $node->fichecode = 'RS11111';
            $nodes[] = $node;
        }
        $form = new declare_form('/admin/tool/vae/declare.php', ['competencies' => $with + $flat, 'outline' => $nodes]);

        $html = html_entity_decode($form->render());

        $this->assertStringContainsString('label="RS11111"', $html, 'The flat record is a group of its own.');
        $this->assertStringContainsString(get_string('declaregroupother', 'tool_vae', 'RS00000'), $html);
        $this->assertStringNotContainsString(get_string('declaregroupother', 'tool_vae', 'RS11111'), $html);
        $this->assertGreaterThan(strpos($html, 'RS11111-C1'), strpos($html, 'RS11111-C2'));
    }

    public function test_the_declaration_list_is_grouped_by_bloc_with_the_bloc_choosable(): void {
        $competencies = (new competency_gateway())->list_framework_competencies($this->frameworkid);
        $nodes = outline::group($competencies);
        foreach ($nodes as $node) {
            $node->fichecode = 'RS00000';
        }
        $form = new declare_form('/admin/tool/vae/declare.php', ['competencies' => $competencies, 'outline' => $nodes]);

        $html = $form->render();

        $this->assertSame(3, substr_count($html, '<optgroup'), 'Two blocs, and the competencies under no bloc.');
        $this->assertStringContainsString('RS00000-BC01 — Accompagner la personne', $html);
        $this->assertStringContainsString(
            get_string('declareblocitself', 'tool_vae', 'RS00000-BC01 — Accompagner la personne'),
            html_entity_decode($html)
        );
        $this->assertStringContainsString(get_string('declaregroupother', 'tool_vae', 'RS00000'), $html);
        $this->assertStringContainsString('RS00000-Z01', $html);
    }

    public function test_a_flat_record_gives_the_plain_list_to_declare_in(): void {
        $flat = [
            (object) ['id' => 1, 'idnumber' => 'X-1', 'shortname' => 'Un', 'parentid' => 0],
            (object) ['id' => 2, 'idnumber' => 'X-2', 'shortname' => 'Deux', 'parentid' => 0],
        ];
        $form = new declare_form('/admin/tool/vae/declare.php', [
            'competencies' => $flat,
            'outline' => outline::group($flat),
        ]);

        $html = $form->render();

        $this->assertSame(0, substr_count($html, '<optgroup'));
        $this->assertStringContainsString('X-2 — Deux', $html);
    }

    public function test_the_plain_list_shows_a_code_and_a_label_as_text(): void {
        // The code of a competency is text a manager may rewrite in the competencies screens of the core, and the select
        // writes its labels as they are.
        $flat = [
            (object) ['id' => 1, 'idnumber' => 'X-1</select><img src=x onerror=alert(1)>', 'shortname' => 'Un', 'parentid' => 0],
            (object) ['id' => 2, 'idnumber' => 'X-2', 'shortname' => '<b>Deux</b>', 'parentid' => 0],
        ];
        $form = new declare_form('/admin/tool/vae/declare.php', [
            'competencies' => $flat,
            'outline' => outline::group($flat),
        ]);

        $html = $form->render();

        $this->assertSame(0, substr_count($html, '<optgroup'));
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('<b>Deux</b>', $html);
        $this->assertStringContainsString('X-1&lt;/select&gt;&lt;img src=x onerror=alert(1)&gt; — Un', $html);
        $this->assertStringContainsString('X-2 — &lt;b&gt;Deux&lt;/b&gt;', $html);
    }

    public function test_the_dossier_names_the_bloc_of_a_declaration_that_has_one(): void {
        $underbloc = $this->declare('RS00000-C02');
        $nobloc = $this->declare('RS00000-Z01');
        $context = \context_user::instance((int) $this->candidate->id);

        $with = presenter::declaration($underbloc, $context);
        $without = presenter::declaration($nobloc, $context);

        $this->assertStringContainsString(get_string('declarationbloc', 'tool_vae'), $with);
        $this->assertStringContainsString('RS00000-BC01 — Accompagner la personne', $with);
        $this->assertStringNotContainsString(get_string('declarationbloc', 'tool_vae') . '</dt>', $without);
    }
}
