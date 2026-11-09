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
use tool_vae\local\selfpos_tree;

/**
 * The tree of a record at every level, for the self-positioning of the vaeiste (lot 1a).
 *
 * The record itself is the first node (rank '', level 1); a competency at the top of the record has level 2, one under it
 * level 3, and so on with no limit. The name of each level is the taxonomy of the framework, as the core names it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(selfpos_tree::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(competency_gateway::class)]
final class selfpos_tree_test extends \advanced_testcase {
    /** @var \tool_vae_generator */
    private $generator;

    /**
     * RNCP100 with two blocs (BC01 holding C01 and C02, BC02 holding C03); RNCP400 with four levels (D1 holding C11, which
     * holds H111 and H112, and C12; D2 alone).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('tool_vae');

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
    }

    /**
     * The ranks of the nodes of a tree, in its order.
     *
     * @param \stdClass $tree
     * @return string[]
     */
    private function ranks(\stdClass $tree): array {
        return array_map(fn($node) => $node->rank, $tree->nodes);
    }

    /**
     * One node, by its rank.
     *
     * @param \stdClass $tree
     * @param string $rank
     * @return \stdClass
     */
    private function node(\stdClass $tree, string $rank): \stdClass {
        foreach ($tree->nodes as $node) {
            if ($node->rank === $rank) {
                return $node;
            }
        }
        $this->fail('No node of rank ' . $rank);
    }

    public function test_a_two_level_record_gives_the_record_then_blocs_then_competencies(): void {
        $tree = selfpos_tree::for_record('RNCP100');

        $this->assertSame(['', 'BC01', 'C01', 'C02', 'BC02', 'C03'], $this->ranks($tree));
        $this->assertSame([1, 2, 3, 3, 2, 3], array_map(fn($node) => $node->level, $tree->nodes));
        $this->assertSame('', $this->node($tree, 'BC01')->parentrank);
        $this->assertSame('BC01', $this->node($tree, 'C02')->parentrank);
        $this->assertSame('Competence C03', $this->node($tree, 'C03')->label);
        $this->assertSame('RNCP100-C03', $this->node($tree, 'C03')->idnumber);
        foreach ($tree->nodes as $node) {
            $this->assertTrue($node->positionable, $node->rank);
        }
    }

    public function test_a_four_level_record_gives_levels_one_to_four_with_their_parent(): void {
        $tree = selfpos_tree::for_record('RNCP400');

        $this->assertSame(['', 'D1', 'C11', 'H111', 'H112', 'C12', 'D2'], $this->ranks($tree));
        $this->assertSame(4, $this->node($tree, 'H111')->level);
        $this->assertSame('C11', $this->node($tree, 'H111')->parentrank);
        $this->assertSame(3, $this->node($tree, 'C12')->level);
        $this->assertSame('D1', $this->node($tree, 'C12')->parentrank);
        $this->assertSame(2, $this->node($tree, 'D2')->level);
        $this->assertSame(1, $this->node($tree, '')->level);
        // The record is told by the name the framework carries, as the import screen names it, not by its code twice.
        $this->assertSame('Referentiel importe', $this->node($tree, '')->label);
        $this->assertSame('RNCP400', $this->node($tree, '')->idnumber);
    }

    public function test_the_level_name_comes_from_the_framework_taxonomy(): void {
        global $DB;
        $DB->set_field('competency_framework', 'taxonomies', 'domain,competency,skill', ['idnumber' => 'RNCP400']);
        // The names the core displays for its taxonomies, read by their strings (MC-5: the core's class stays behind the
        // gateway, tests included).
        $names = [
            'domain' => get_string('taxonomy_domain', competency_gateway::SUBSYSTEM),
            'competency' => get_string('taxonomy_competency', competency_gateway::SUBSYSTEM),
            'skill' => get_string('taxonomy_skill', competency_gateway::SUBSYSTEM),
        ];

        $taxonomies = (new competency_gateway())->framework_taxonomies((int) (new competency_gateway())->find_framework('RNCP400'));
        $tree = selfpos_tree::for_record('RNCP400');

        $this->assertSame(
            [1 => (string) $names['domain'], 2 => (string) $names['competency'], 3 => (string) $names['skill']],
            $taxonomies
        );
        $this->assertSame(get_string('selfposrecordlevel', 'tool_vae'), $this->node($tree, '')->levelname);
        $this->assertSame((string) $names['domain'], $this->node($tree, 'D1')->levelname);
        $this->assertSame((string) $names['competency'], $this->node($tree, 'C11')->levelname);
        $this->assertSame((string) $names['skill'], $this->node($tree, 'H112')->levelname);
    }

    public function test_a_level_deeper_than_the_taxonomy_is_named_by_its_number(): void {
        global $DB;
        $DB->set_field('competency_framework', 'taxonomies', 'domain', ['idnumber' => 'RNCP400']);

        $tree = selfpos_tree::for_record('RNCP400');

        $this->assertSame(get_string('selfposlevelname', 'tool_vae', 3), $this->node($tree, 'C11')->levelname);
        $this->assertSame(get_string('selfposlevelname', 'tool_vae', 4), $this->node($tree, 'H111')->levelname);
    }

    public function test_a_node_told_by_its_idnumber_never_hides_the_positionable_node_of_the_same_rank(): void {
        $this->setAdminUser();
        $gateway = new competency_gateway();
        $frameworkid = (int) $gateway->find_framework('RNCP100');
        // Added by hand in the core, at the top of the record: its idnumber is the bare word "C01", unique in the framework.
        $gateway->create_competency($frameworkid, 'C01', 'Un code nu, ajoute a la main');

        $tree = selfpos_tree::for_record('RNCP100');
        $same = array_values(array_filter($tree->nodes, fn($node) => $node->rank === 'C01'));

        $this->assertCount(2, $same, 'Both are listed: nothing of the record is hidden.');
        $this->assertFalse($same[0]->positionable, 'The bare word sits at the top of the record, so it is listed first.');
        $node = selfpos_tree::node($tree, 'C01');
        $this->assertTrue($node->positionable);
        $this->assertSame('RNCP100-C01', $node->idnumber, 'The positionable node is the one a line, a form or an archive names.');
    }

    public function test_a_node_whose_idnumber_does_not_follow_the_code_and_rank_shape_is_listed_but_not_positionable(): void {
        $gateway = new competency_gateway();
        $frameworkid = (int) $gateway->find_framework('RNCP100');
        $this->setAdminUser();
        $gateway->create_competency($frameworkid, 'AS-1-1', 'Un code a tirets');
        $gateway->create_competency($frameworkid, 'RNCP100-TROPLONGRANG', 'Un rang trop long');
        $gateway->create_competency($frameworkid, 'RNCP100-', 'Un rang vide');

        $tree = selfpos_tree::for_record('RNCP100');

        $odd = array_filter($tree->nodes, fn($node) => !$node->positionable);
        $this->assertSame(
            ['AS-1-1', 'RNCP100-', 'RNCP100-TROPLONGRANG'],
            array_values(array_map(fn($node) => $node->idnumber, $odd))
        );
        $this->assertSame('Un code a tirets', $this->node($tree, 'AS-1-1')->label, 'Listed by its idnumber, since it has no rank.');
        $this->assertTrue($this->node($tree, '')->positionable, 'The record itself has the empty rank and is positionable.');
        $this->assertCount(9, $tree->nodes);
    }

    public function test_an_unknown_record_gives_null(): void {
        $this->assertNull(selfpos_tree::for_record('RNCP999'));
        $this->assertNull(selfpos_tree::for_record('rncp100'), 'The code is compared exactly.');
    }

    public function test_the_existing_layout_and_outline_are_untouched_by_the_four_level_record(): void {
        $layout = \tool_vae\local\positioning_rules::layout('RNCP400');

        $this->assertTrue($layout->hasblocs);
        $this->assertSame(['D1'], array_keys($layout->blocs), 'D2 holds nothing: a leaf. The rest hangs under D1.');
        $this->assertEqualsCanonicalizing(['C11', 'H111', 'H112', 'C12', 'D2'], array_keys($layout->leaves));
    }
}
