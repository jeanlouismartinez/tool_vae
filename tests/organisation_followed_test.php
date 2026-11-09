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

use tool_vae\local\access;
use tool_vae\local\candidate_overview;
use tool_vae\local\competency_gateway;
use tool_vae\local\organisation;
use tool_vae\local\referent_manager;

/**
 * The candidates a manager follows, with counts of work and plans under the core's right (T-90 lot 3).
 *
 * COUNTS OF WORK, NEVER A PORTRAIT. The table says how many declarations each followed candidate made, how many await
 * an attestation, on which records, and - only where the core lets the manager read them - which learning plans they
 * have. Nothing per competency or per bloc, no date, no total: the tests hold the shape by a white list of keys.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\organisation::class)]
final class organisation_followed_test extends \advanced_testcase {
    /** @var \stdClass The manager who follows three candidates. */
    private $manager;

    /** @var \stdClass[] The three followed candidates. */
    private $followed = [];

    /** @var \stdClass A candidate nobody follows. */
    private $stranger;

    /** @var \tool_vae_generator */
    private $plugingenerator;

    /** @var \component_generator_base The generator of the competency subsystem, got by the gateway's constant. */
    private $coregenerator;

    /** @var int The role of a candidate who declares and reads their own dossier. */
    private $ownrole;

    /** @var int The role that makes the manager follow a candidate. */
    private $followrole;

    /**
     * RNCP100 with two blocs (BC01 holding C01, BC02 holding C02) and RS200; a manager following three candidates.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->plugingenerator = $generator->get_plugin_generator('tool_vae');
        $this->coregenerator = $generator->get_plugin_generator(competency_gateway::SUBSYSTEM);

        $this->plugingenerator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        $this->plugingenerator->create_competency(['fichecode' => 'RNCP100', 'ficherang' => 'BC02', 'shortname' => 'Bloc 2']);
        $this->plugingenerator->create_competency([
            'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'shortname' => 'Aider au lever', 'bloc' => 'BC01',
        ]);
        $this->plugingenerator->create_competency([
            'fichecode' => 'RNCP100', 'ficherang' => 'C02', 'shortname' => 'Aider au repas', 'bloc' => 'BC02',
        ]);
        $this->plugingenerator->create_source([
            'fichecode' => 'RS200', 'ficherang' => 'C01', 'shortname' => 'Utiliser un lève-personne',
        ]);

        $this->manager = $generator->create_user(['lastname' => 'Responsable']);
        $this->stranger = $generator->create_user(['lastname' => 'Etranger']);
        $this->ownrole = $own = $generator->create_role();
        foreach (['tool/vae:declare', 'tool/vae:viewown'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $own, \context_system::instance()->id, true);
        }
        $this->followrole = $generator->create_role();
        foreach (['tool/vae:viewassigned', 'tool/vae:attest'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $this->followrole, \context_system::instance()->id, true);
        }
        foreach (['Camille', 'Denis', 'Eloi'] as $firstname) {
            $candidate = $generator->create_user(['firstname' => $firstname, 'lastname' => 'Vaeiste']);
            role_assign($own, $candidate->id, \context_user::instance($candidate->id)->id);
            role_assign($this->followrole, $this->manager->id, \context_user::instance($candidate->id)->id);
            $this->followed[$firstname] = $candidate;
        }
        role_assign($own, $this->stranger->id, \context_user::instance($this->stranger->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * A declaration of a candidate on a competency of a record.
     *
     * @param \stdClass $candidate
     * @param string $rank
     * @param string $fichecode
     * @return \stdClass
     */
    private function declare(\stdClass $candidate, string $rank, string $fichecode = 'RNCP100'): \stdClass {
        return $this->plugingenerator->create_declaration([
            'userid' => $candidate->id,
            'fichecode' => $fichecode,
            'ficherang' => $rank,
            'situation' => 'Une situation vécue en établissement.',
        ]);
    }

    /**
     * A learning plan of the core.
     *
     * @param \stdClass $candidate
     * @param string $name
     * @param int $status One of competency_gateway::PLAN_*.
     */
    private function plan(\stdClass $candidate, string $name, int $status = competency_gateway::PLAN_ACTIVE): void {
        $this->setAdminUser();
        $this->coregenerator->create_plan(['userid' => $candidate->id, 'name' => $name, 'status' => $status]);
    }

    /**
     * Give the manager a capability of the core in the context of one candidate.
     *
     * @param \stdClass $candidate
     * @param string $capability
     */
    private function grant(\stdClass $candidate, string $capability): void {
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $this->manager->id, \context_user::instance($candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * What the manager reads.
     *
     * @param string $bloc
     * @return \stdClass
     */
    private function followed(string $bloc = ''): \stdClass {
        $this->setUser($this->manager);

        return organisation::followed((int) $this->manager->id, $bloc);
    }

    public function test_followed_lists_the_followed_candidates_and_nobody_else(): void {
        $result = $this->followed();

        $expected = array_map(fn($candidate) => (int) $candidate->id, array_values($this->followed));
        $this->assertEqualsCanonicalizing($expected, array_keys($result->candidates));
        $this->assertArrayNotHasKey((int) $this->stranger->id, $result->candidates);
        $camille = $result->candidates[(int) $this->followed['Camille']->id];
        $this->assertSame(fullname($this->followed['Camille']), $camille->name);

        // Somebody who follows nobody reads nothing, without an exception.
        $this->setUser($this->stranger);
        $nobody = organisation::followed((int) $this->stranger->id);
        $this->assertSame([], $nobody->candidates);
    }

    public function test_followed_counts_are_those_of_my_candidates(): void {
        [$camille, $denis, $eloi] = array_values($this->followed);
        $this->declare($denis, 'C01');
        foreach (['C01', 'C02', 'BC01'] as $rank) {
            $declaration = $this->declare($eloi, $rank);
            if ($rank === 'C01') {
                $this->plugingenerator->create_attestation([
                    'evidenceid' => $declaration->id, 'userid' => $this->manager->id,
                ]);
            }
        }

        $result = $this->followed();

        $counts = fn(\stdClass $candidate) => [
            $result->candidates[(int) $candidate->id]->declarations,
            $result->candidates[(int) $candidate->id]->pending,
        ];
        $this->assertSame([0, 0], $counts($camille));
        $this->assertSame([1, 1], $counts($denis));
        $this->assertSame([3, 2], $counts($eloi));

        // Complementary, and circular on its own: followed() calls counts().
        $ids = array_keys($result->candidates);
        $this->assertSame(3, $result->candidates[(int) $eloi->id]->declarations);
        $this->assertSame(3, candidate_overview::counts($ids)[(int) $eloi->id]->declarations);

        // The number of followed candidates, counted in the database, independently of the code under test.
        global $DB;
        $contexts = $DB->get_fieldset_sql(
            "SELECT DISTINCT ctx.instanceid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :level
              WHERE ra.userid = :userid AND ra.roleid = :roleid",
            ['level' => CONTEXT_USER, 'userid' => $this->manager->id, 'roleid' => $this->followrole]
        );
        $this->assertCount(count($contexts), $result->candidates);
    }

    public function test_followed_lists_a_candidate_once_when_two_roles_link_the_same_manager(): void {
        global $DB;
        $candidate = $this->followed['Camille'];
        $contextid = \context_user::instance($candidate->id)->id;
        // A second role that also carries viewassigned: the manager is linked twice to the same person (the cohort tool
        // would add no second row for the same role, so it cannot make this case).
        $second = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $second, \context_system::instance()->id, true);
        role_assign($second, $this->manager->id, $contextid);
        accesslib_clear_all_caches_for_unit_testing();
        // The fixture holds: two rows of the table of assignments for one manager and one person, which a join without
        // DISTINCT would list twice.
        $this->assertSame(2, $DB->count_records('role_assignments', [
            'userid' => $this->manager->id, 'contextid' => $contextid,
        ]));

        $result = $this->followed();

        $this->assertArrayHasKey((int) $candidate->id, $result->candidates);
        // The array of the result is keyed by id and could not show a duplicate: the list it is built from is what is
        // counted, and the two assignments must give one entry there.
        $list = access::followed_candidates();
        $this->assertSame(1, count(array_keys($list, (int) $candidate->id)));
        $this->assertCount(count(array_unique($list)), $list);
    }

    public function test_followed_names_the_records_each_candidate_declared_on(): void {
        [$camille, $denis] = array_values($this->followed);
        $this->declare($camille, 'C01', 'RS200');
        $this->declare($camille, 'C01');
        $this->declare($camille, 'C02');
        // A plan names records too, and is not a declaration.
        $this->plan($denis, 'Plan AES');

        $result = $this->followed();

        $fiches = $result->candidates[(int) $camille->id]->fiches;
        $this->assertSame(['RNCP100', 'RS200'], array_column($fiches, 'fichecode'));
        $this->assertSame(['fichecode', 'name'], array_keys((array) $fiches[0]));
        $this->assertSame([], $result->candidates[(int) $denis->id]->fiches);

        // The name is the framework's own, read here by a query of the test and not through the code under test.
        global $DB;
        $names = [];
        foreach (['RNCP100', 'RS200'] as $code) {
            $frameworkid = $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => $code], MUST_EXIST);
            $names[$code] = $DB->get_field('competency_framework', 'shortname', ['id' => $frameworkid], MUST_EXIST);
        }
        $this->assertSame(array_values($names), array_column($fiches, 'name'));
    }

    public function test_followed_keeps_the_code_alone_of_a_record_whose_source_is_gone(): void {
        global $DB;
        $camille = $this->followed['Camille'];
        $this->declare($camille, 'C01', 'RS200');
        $DB->delete_records('tool_vae_source', ['fichecode' => 'RS200']);

        $fiches = $this->followed()->candidates[(int) $camille->id]->fiches;

        $this->assertSame(['RS200'], array_column($fiches, 'fichecode'));
        $this->assertSame(['RS200'], array_column($fiches, 'name'));
    }

    public function test_followed_has_no_key_per_competency_per_bloc_nor_any_date(): void {
        [$camille, $denis] = array_values($this->followed);
        $this->declare($camille, 'C01');
        $this->declare($camille, 'C02');
        $this->plan($camille, 'Plan AES');
        $this->grant($camille, 'moodle/competency:planview');
        $this->setAdminUser();
        referent_manager::add('RNCP100', 'BC01', (int) $this->manager->id);

        $result = $this->followed('RNCP100|BC01');

        $this->assertSame(['candidates', 'choices', 'chosen'], array_keys((array) $result));
        $this->assertNotSame([], $result->choices);
        foreach ($result->choices as $choice) {
            $this->assertSame(['fichecode', 'ficherang'], array_keys((array) $choice));
        }
        foreach ($result->candidates as $candidate) {
            $this->assertSame(
                [
                    'id', 'name', 'sortname', 'fiches', 'declarations', 'pending',
                    'plansreadable', 'draftsreadable', 'plans',
                ],
                array_keys((array) $candidate)
            );
            foreach ($candidate->fiches as $fiche) {
                $this->assertSame(['fichecode', 'name'], array_keys((array) $fiche));
            }
            foreach ($candidate->plans as $plan) {
                $this->assertSame(['name', 'status', 'statuslabel'], array_keys((array) $plan));
            }
        }
        $this->assertNotSame([], $result->candidates[(int) $camille->id]->plans);

        // The whole structure, however deep: no key that would carry a count per unit, no date.
        $forbidden = ['competencies', 'blocs', 'lastact', 'lastattested', 'pieces', 'timecreated', 'timemodified'];
        $walk = function ($value) use (&$walk, $forbidden): void {
            foreach ((array) $value as $key => $item) {
                $this->assertNotContains($key, $forbidden);
                if (is_array($item) || is_object($item)) {
                    $walk($item);
                } else if (is_int($item)) {
                    $this->assertLessThan(1000000000, $item, "A date at $key");
                }
            }
        };
        $walk($result);
    }

    public function test_a_referent_narrows_the_counts_and_not_the_list(): void {
        [$camille, $denis] = array_values($this->followed);
        $this->declare($camille, 'C01');
        $this->declare($camille, 'C02');
        $this->declare($denis, 'C02');
        $this->setAdminUser();
        referent_manager::add('RNCP100', 'BC01', (int) $this->manager->id);

        $all = $this->followed();
        $narrow = $this->followed('RNCP100|BC01');

        $this->assertSame(array_keys($all->candidates), array_keys($narrow->candidates));
        $this->assertSame('RNCP100|BC01', $narrow->chosen);
        $this->assertSame(1, $narrow->candidates[(int) $camille->id]->declarations);
        $this->assertSame(0, $narrow->candidates[(int) $denis->id]->declarations);
        $this->assertSame(2, $all->candidates[(int) $camille->id]->declarations);
        $this->assertSame(
            candidate_overview::counts(
                [(int) $camille->id],
                'RNCP100',
                referent_manager::ranks_under('RNCP100', 'BC01')
            )[(int) $camille->id]->declarations,
            $narrow->candidates[(int) $camille->id]->declarations
        );

        // A bloc the manager is not referent of is not a restriction: no counts are hidden by a value from the address.
        $other = $this->followed('RNCP100|BC02');
        $this->assertSame('', $other->chosen);
        $this->assertSame(2, $other->candidates[(int) $camille->id]->declarations);
    }

    public function test_followed_reads_the_plans_only_under_planview_candidate_by_candidate(): void {
        foreach ($this->followed as $candidate) {
            $this->plan($candidate, 'Plan AES');
        }

        $closed = $this->followed();
        foreach ($closed->candidates as $candidate) {
            $this->assertFalse($candidate->plansreadable);
            $this->assertSame([], $candidate->plans);
        }
        $this->assertStringNotContainsString('Plan AES', json_encode($closed));

        $camille = $this->followed['Camille'];
        $this->grant($camille, 'moodle/competency:planview');
        $open = $this->followed();
        $this->assertTrue($open->candidates[(int) $camille->id]->plansreadable);
        $this->assertSame(['Plan AES'], array_column($open->candidates[(int) $camille->id]->plans, 'name'));
        $this->assertSame(
            get_string('planstatusactive', competency_gateway::SUBSYSTEM),
            $open->candidates[(int) $camille->id]->plans[0]->statuslabel
        );
        foreach (['Denis', 'Eloi'] as $name) {
            $this->assertFalse($open->candidates[(int) $this->followed[$name]->id]->plansreadable);
            $this->assertSame([], $open->candidates[(int) $this->followed[$name]->id]->plans);
        }
    }

    public function test_followed_leaves_out_the_drafts_until_the_reader_may_see_them(): void {
        $camille = $this->followed['Camille'];
        $this->plan($camille, 'Actif');
        $this->plan($camille, 'Brouillon', competency_gateway::PLAN_DRAFT);
        $id = (int) $camille->id;

        $none = $this->followed()->candidates[$id];
        $this->assertSame([false, false, []], [$none->plansreadable, $none->draftsreadable, $none->plans]);

        $this->grant($camille, 'moodle/competency:planview');
        $view = $this->followed()->candidates[$id];
        $this->assertSame(
            [true, false, ['Actif']],
            [$view->plansreadable, $view->draftsreadable, array_column($view->plans, 'name')]
        );

        $this->grant($camille, 'moodle/competency:planviewdraft');
        $both = $this->followed()->candidates[$id];
        $this->assertSame(
            [true, true, ['Actif', 'Brouillon']],
            [$both->plansreadable, $both->draftsreadable, array_column($both->plans, 'name')]
        );
    }

    public function test_followed_with_the_right_on_drafts_alone_lists_the_drafts_alone(): void {
        $camille = $this->followed['Camille'];
        $this->plan($camille, 'Actif');
        $this->plan($camille, 'Brouillon', competency_gateway::PLAN_DRAFT);
        $this->grant($camille, 'moodle/competency:planviewdraft');

        $result = $this->followed()->candidates[(int) $camille->id];

        $this->assertSame([false, true, ['Brouillon']], [
            $result->plansreadable, $result->draftsreadable, array_column($result->plans, 'name'),
        ]);
    }

    public function test_followed_in_a_logged_in_as_session(): void {
        // Parity with the page "My candidates", which refuses nothing under "log in as" (decision D5, 3 November
        // 2026): the list is the one of the user of the session, and the plans follow that user's rights.
        $this->setAdminUser();
        \core\session\manager::loginas((int) $this->manager->id, \context_system::instance());
        $this->assertSame((int) $this->manager->id, (int) $GLOBALS['USER']->id);

        $result = organisation::followed((int) $this->manager->id);

        $this->assertCount(3, $result->candidates);

        // The plans follow the rights of the user of the session: given to the manager, they are read; the real user's
        // site-wide rights (the administrator passes every check) are not the ones asked.
        $camille = $this->followed['Camille'];
        $this->plan($camille, 'Plan AES');
        $this->setAdminUser();
        $this->grant($camille, 'moodle/competency:planview');
        \core\session\manager::loginas((int) $this->manager->id, \context_system::instance());
        $session = organisation::followed((int) $this->manager->id);
        $this->assertTrue($session->candidates[(int) $camille->id]->plansreadable);
        $this->assertSame(['Plan AES'], array_column($session->candidates[(int) $camille->id]->plans, 'name'));
        $this->assertFalse($session->candidates[(int) $this->followed['Denis']->id]->plansreadable);
    }

    public function test_followed_plans_are_in_the_order_of_the_words(): void {
        $camille = $this->followed['Camille'];
        foreach (['Zèbre', 'Abeille', 'Éléphant'] as $name) {
            $this->plan($camille, $name);
        }
        $this->grant($camille, 'moodle/competency:planview');

        $result = $this->followed();

        $this->assertSame(
            ['Abeille', 'Éléphant', 'Zèbre'],
            array_column($result->candidates[(int) $camille->id]->plans, 'name')
        );
    }

    public function test_followed_candidates_are_in_the_order_of_the_words_of_their_names(): void {
        // An accented capital sorts with its letter for the collator and after "Z" for the bytes; the expected order is
        // written out, not computed by the code under test.
        $generator = $this->getDataGenerator();
        foreach ([['Zoé', 'Zola'], ['Ana', 'Élan']] as [$firstname, $lastname]) {
            $candidate = $generator->create_user(['firstname' => $firstname, 'lastname' => $lastname]);
            role_assign($this->followrole, $this->manager->id, \context_user::instance($candidate->id)->id);
        }
        accesslib_clear_all_caches_for_unit_testing();

        $result = $this->followed();

        $this->assertSame(
            ['Ana Élan', 'Camille Vaeiste', 'Denis Vaeiste', 'Eloi Vaeiste', 'Zoé Zola'],
            array_column((array) $result->candidates, 'name')
        );
    }

    /**
     * Database reads of one call to followed(), beyond those of the list of the followed (accepted at O(N), decision
     * D9, as the page "My candidates" pays them), from cold contexts: production contexts are not in the cache.
     *
     * @return int
     */
    private function reads_of_followed(): int {
        global $DB;
        $this->setUser($this->manager);

        \context_helper::reset_caches();
        accesslib_clear_all_caches_for_unit_testing();
        $before = $DB->perf_get_reads();
        access::followed_candidates();
        $list = $DB->perf_get_reads() - $before;

        \context_helper::reset_caches();
        accesslib_clear_all_caches_for_unit_testing();
        $before = $DB->perf_get_reads();
        organisation::followed((int) $this->manager->id);

        return $DB->perf_get_reads() - $before - $list;
    }

    public function test_followed_reads_do_not_grow_with_the_number_of_candidates(): void {
        foreach ($this->followed as $candidate) {
            $this->declare($candidate, 'C01');
            $this->declare($candidate, 'C01', 'RS200');
            $this->plan($candidate, 'Plan AES');
            $this->grant($candidate, 'moodle/competency:planview');
        }
        $this->reads_of_followed();
        $small = $this->reads_of_followed();

        for ($i = 0; $i < 9; $i++) {
            $candidate = $this->getDataGenerator()->create_user();
            $this->followed['extra' . $i] = $candidate;
            role_assign($this->ownrole, $candidate->id, \context_user::instance($candidate->id)->id);
            role_assign($this->followrole, $this->manager->id, \context_user::instance($candidate->id)->id);
            $this->declare($candidate, 'C01');
            $this->declare($candidate, 'C01', 'RS200');
            $this->plan($candidate, 'Plan AES');
            $this->grant($candidate, 'moodle/competency:planview');
        }
        $this->reads_of_followed();
        $large = $this->reads_of_followed();

        $this->assertSame($small, $large);
    }
}
