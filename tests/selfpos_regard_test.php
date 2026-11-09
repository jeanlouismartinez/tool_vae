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

use tool_vae\local\selfpos_regard;
use tool_vae\local\selfpos_tree;

/**
 * What stands next to the vaeiste's own level on a node: the word of the structure and the counts, never a gap (lot 1a).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(selfpos_regard::class)]
final class selfpos_regard_test extends \advanced_testcase {
    /** @var \stdClass */
    private $vaeiste;

    /** @var \stdClass Follows the vaeiste and evaluates. */
    private $manager;

    /** @var \stdClass Certifies. */
    private $direction;

    /** @var \tool_vae_generator */
    private $generator;

    /** @var int Sequence for the ULIDs of the attestations written here. */
    private $uuids = 0;

    /**
     * RNCP100 (BC01 holding C01 and C02); the vaeiste declared on C01; a manager who evaluates; a direction who certifies.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $datagenerator = $this->getDataGenerator();
        $this->generator = $datagenerator->get_plugin_generator('tool_vae');
        $this->generator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        foreach (['C01', 'C02'] as $rank) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP100', 'ficherang' => $rank, 'shortname' => 'Competence ' . $rank, 'bloc' => 'BC01',
            ]);
        }
        $system = \context_system::instance()->id;
        $this->vaeiste = $datagenerator->create_user();
        $this->manager = $datagenerator->create_user();
        $this->direction = $datagenerator->create_user();
        $own = $datagenerator->create_role();
        foreach (['declare', 'viewown', 'positionself'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, $system, true);
        }
        role_assign($own, $this->vaeiste->id, \context_user::instance($this->vaeiste->id)->id);
        $follow = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        assign_capability('tool/vae:evaluate', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, \context_user::instance($this->vaeiste->id)->id);
        $director = $datagenerator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $director, $system, true);
        assign_capability('tool/vae:certify', CAP_ALLOW, $director, $system, true);
        role_assign($director, $this->direction->id, $system);
        accesslib_clear_all_caches_for_unit_testing();

        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Une situation.',
        ]);
    }

    /**
     * The regard on a node.
     *
     * @param string $rank
     * @return \stdClass
     */
    private function regard(string $rank): \stdClass {
        $tree = selfpos_tree::for_record('RNCP100');

        return selfpos_regard::for_node((int) $this->vaeiste->id, 'RNCP100', selfpos_tree::node($tree, $rank));
    }

    /**
     * An attestation that stands on a declaration, written straight to the table as coverage_test does: the API refuses a
     * second one on the same declaration, and the count must hold even so.
     *
     * @param int $evidenceid
     */
    private function attest(int $evidenceid): void {
        global $DB;

        $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => str_pad((string) ++$this->uuids, 26, '0', STR_PAD_LEFT),
            'evidenceid' => $evidenceid,
            'attestantid' => $this->manager->id,
            'attestantrole' => 'Care manager',
            'statement' => 'I supervised this person.',
            'attestedat' => 1760000000,
            'timecreated' => 1760000000,
            'timemodified' => 1760000000,
            'usermodified' => $this->manager->id,
        ]);
    }

    public function test_the_regard_gives_the_structure_line_next_to_the_vaeiste_line_without_any_gap(): void {
        $this->generator->create_selfpos([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'level' => 2,
        ]);
        $this->generator->create_evaluation([
            'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'authorid' => $this->manager->id,
            'status' => 'sufficient',
        ]);

        $regard = $this->regard('C01');

        $this->assertSame('eval', $regard->structure->kind);
        $this->assertSame('sufficient', $regard->structure->status);
        $this->assertIsInt($regard->structure->time);
        $this->assertSame(
            ['structure', 'declarations', 'attested', 'pieces'],
            array_keys((array) $regard),
            'No gap, no comparison, no score.'
        );
        $this->assertSame(['kind', 'status', 'time'], array_keys((array) $regard->structure));
        $this->assertSame(1, $regard->declarations);
        $this->assertSame(0, $regard->pieces);
    }

    public function test_the_regard_has_no_structure_line_where_the_structure_wrote_nothing(): void {
        $this->assertNull($this->regard('C02')->structure);
        $this->assertSame(0, $this->regard('C02')->declarations);
        $this->assertNull($this->regard('')->structure, 'Nothing on the record itself either.');
    }

    public function test_a_withdrawn_evaluation_leaves_no_structure_line(): void {
        $this->generator->create_evaluation([
            'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'authorid' => $this->manager->id,
            'status' => 'inproof',
        ]);
        $this->generator->create_evaluation([
            'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'authorid' => $this->manager->id,
            'status' => 'withdrawn', 'reason' => 'Erreur.',
        ]);

        $this->assertNull($this->regard('C01')->structure);
    }

    public function test_the_regard_counts_the_attested_declarations_of_the_node(): void {
        global $DB;

        $second = $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Une autre situation.',
        ]);
        $this->assertSame(0, $this->regard('C01')->attested, 'Nothing attested yet.');

        $this->attest((int) $second->id);
        $regard = $this->regard('C01');
        $this->assertSame(2, $regard->declarations);
        $this->assertSame(1, $regard->attested, 'Two declarations, one attested.');
        $this->assertSame(0, $this->regard('C02')->attested, 'An attestation counts on its own node only.');

        // Two attestations that stand on one declaration still make one attested declaration: a count of declarations.
        $this->attest((int) $second->id);
        $this->assertSame(1, $this->regard('C01')->attested);

        // A withdrawn attestation vouches for nothing.
        $DB->execute(
            "UPDATE {tool_vae_attest} SET withdrawnat = :now, withdrawnreason = 'erreur' WHERE evidenceid = :evidenceid",
            ['now' => time(), 'evidenceid' => $second->id]
        );
        $regard = $this->regard('C01');
        $this->assertSame(2, $regard->declarations);
        $this->assertSame(0, $regard->attested);
    }

    public function test_a_certification_stands_next_to_the_bloc(): void {
        $this->generator->create_certification([
            'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'BC01',
            'authorid' => $this->direction->id, 'decisiondate' => '2026-11-05',
            'alertreason' => 'Les competences seront vues la semaine prochaine.',
        ]);

        $regard = $this->regard('BC01');

        $this->assertSame('certif', $regard->structure->kind);
        $this->assertSame('certified', $regard->structure->status);
        $this->assertSame('2026-11-05', $regard->structure->decisiondate);
    }
}
