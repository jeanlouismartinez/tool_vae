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

use tool_vae\event\passage_changed;
use tool_vae\local\competency_gateway;
use tool_vae\local\identity_map;
use tool_vae\local\passage_manager;
use tool_vae\local\supersession;

/**
 * The correspondences between a replaced record and its successor (T-30, lot 2, D-14).
 *
 * WHAT IS ASSERTED. That the plugin suggests the competency of the same rank, else the one with a nearly identical
 * label, and records nothing by itself; that only a decision is recorded, one per competency, with who and when and
 * a note; that a decision points only at the record that replaces; that it can be replaced and taken back; that
 * only someone who may import decides; that nothing is moved by a decision; that the log carries identifiers only.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\passage_manager::class)]
final class passage_manager_test extends \advanced_testcase {
    /** @var \stdClass[] Competencies of the old record (RNCP100), by rank. */
    private $old = [];

    /** @var \stdClass[] Competencies of the new record (RNCP200), by rank. */
    private $new = [];

    /** @var \stdClass[] Competencies of an unrelated record (RNCP300), by rank. */
    private $other = [];

    /**
     * An old record replaced by a new one, and an unrelated one, with their competencies.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $now = time();
        $plan = [
            'RNCP100' => ['old', [
                'C01' => 'Accompagner la personne',
                'C02' => 'Assurer les soins',
                'C03' => 'Travailler en equipe',
            ]],
            'RNCP200' => ['new', [
                'C01' => 'Accompagner la personne',
                'C05' => 'Assurer les soins',
                'C09' => 'Evaluer les risques',
            ]],
            'RNCP300' => ['other', ['C01' => 'Autre chose']],
        ];
        foreach ($plan as $code => [$property, $competencies]) {
            $framework = $generator->create_framework(['idnumber' => $code, 'shortname' => "Fiche $code"]);
            $DB->insert_record('tool_vae_source', (object) [
                'frameworkid' => (int) $framework->get('id'),
                'fichecode' => $code,
                'fichetype' => 'rncp',
                'sourceupdated' => $now,
                'licence' => 'Licence Ouverte 2.0',
                'attribution' => 'France competences',
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => (int) get_admin()->id,
            ]);
            foreach ($competencies as $rank => $label) {
                $this->{$property}[$rank] = $generator->create_competency([
                    'competencyframeworkid' => $framework->get('id'),
                    'idnumber' => "$code-$rank",
                    'shortname' => $label,
                ]);
            }
        }
        supersession::declare_successor('RNCP100', 'RNCP200');
    }

    /**
     * Refuse, and say with which error code.
     *
     * @param callable $call
     * @param string $errorcode
     */
    private function assert_refused(callable $call, string $errorcode): void {
        try {
            $call();
            $this->fail("Expected $errorcode.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    public function test_the_same_rank_is_suggested_first_then_a_nearly_identical_label(): void {
        $rows = (new passage_manager())->overview('RNCP100');

        $first = $rows[(int) $this->old['C01']->get('id')];
        $this->assertSame((int) $this->new['C01']->get('id'), (int) $first->proposed->id);
        $this->assertSame(passage_manager::REASON_RANK, $first->reason);

        // C02 has no rank C02 in the new record, but a competency whose label is the same.
        $second = $rows[(int) $this->old['C02']->get('id')];
        $this->assertSame((int) $this->new['C05']->get('id'), (int) $second->proposed->id);
        $this->assertSame(passage_manager::REASON_LABEL, $second->reason);

        // C03 has neither: no suggestion, and not the nearest guess either.
        $third = $rows[(int) $this->old['C03']->get('id')];
        $this->assertNull($third->proposed);
        $this->assertSame('', $third->reason);
    }

    public function test_a_suggestion_is_recorded_nowhere(): void {
        global $DB;

        (new passage_manager())->overview('RNCP100');

        $this->assertSame(0, $DB->count_records('tool_vae_passage'));
    }

    public function test_a_decision_is_recorded_with_who_when_and_why(): void {
        global $DB;
        $manager = new passage_manager();
        $from = (int) $this->old['C03']->get('id');
        $to = (int) $this->new['C09']->get('id');

        $row = $manager->decide($from, $to, "  Meme champ,\r\nautre intitule.  ");

        $this->assertSame("Meme champ,\nautre intitule.", $row->note);
        $stored = $DB->get_record('tool_vae_passage', ['fromcompetencyid' => $from], '*', MUST_EXIST);
        $this->assertSame($to, (int) $stored->tocompetencyid);
        $this->assertSame((int) get_admin()->id, (int) $stored->decidedby);
        $this->assertEqualsWithDelta(time(), (int) $stored->decidedat, 5);

        $seen = $manager->overview('RNCP100')[$from];
        $this->assertSame($to, (int) $seen->target->id);
        $this->assertSame(fullname(get_admin()), $seen->decidername);
    }

    public function test_a_new_decision_replaces_the_earlier_one_and_it_can_be_taken_back(): void {
        global $DB;
        $manager = new passage_manager();
        $from = (int) $this->old['C01']->get('id');

        $manager->decide($from, (int) $this->new['C01']->get('id'));
        $manager->decide($from, (int) $this->new['C09']->get('id'), 'Changement d avis.');

        $this->assertSame(1, $DB->count_records('tool_vae_passage', ['fromcompetencyid' => $from]));
        $this->assertSame(
            (int) $this->new['C09']->get('id'),
            (int) $DB->get_field('tool_vae_passage', 'tocompetencyid', ['fromcompetencyid' => $from])
        );

        $manager->undo($from);

        $this->assertSame(0, $DB->count_records('tool_vae_passage'));
        $manager->undo($from);
    }

    public function test_a_decision_points_only_at_the_record_that_replaces(): void {
        $manager = new passage_manager();
        $from = (int) $this->old['C01']->get('id');

        // A competency of an unrelated record, one of the same record, and one that does not exist.
        foreach ([(int) $this->other['C01']->get('id'), (int) $this->old['C02']->get('id'), 999999] as $to) {
            $this->assert_refused(function () use ($manager, $from, $to) {
                $manager->decide($from, $to);
            }, 'errorpassagenotmatching');
        }
        // A competency of a record that nothing replaces.
        $this->assert_refused(function () use ($manager) {
            $manager->decide((int) $this->other['C01']->get('id'), (int) $this->new['C01']->get('id'));
        }, 'errorpassagenosuccessor');
        $this->assert_refused(function () use ($manager) {
            $manager->overview('RNCP300');
        }, 'errorpassagenosuccessor');
    }

    public function test_the_note_is_bounded(): void {
        $this->assert_refused(function () {
            (new passage_manager())->decide(
                (int) $this->old['C01']->get('id'),
                (int) $this->new['C01']->get('id'),
                str_repeat('a', passage_manager::NOTE_MAXLENGTH + 1)
            );
        }, 'errordrafttoolong');
    }

    public function test_only_someone_who_may_import_decides_or_takes_back(): void {
        $manager = new passage_manager();
        $from = (int) $this->old['C01']->get('id');
        $manager->decide($from, (int) $this->new['C01']->get('id'));
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            $manager->decide($from, (int) $this->new['C09']->get('id'));
            $this->fail('Expected a refusal.');
        } catch (\required_capability_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->expectException(\required_capability_exception::class);
        $manager->undo($from);
    }

    public function test_deciding_moves_and_changes_nothing(): void {
        global $DB;
        $candidate = $this->getDataGenerator()->create_user();
        $now = time();
        $id = (int) $DB->insert_record('tool_vae_evidence', (object) [
            'uuid' => identity_map::generate_ulid(),
            'userevidenceid' => 1,
            'candidateid' => (int) $candidate->id,
            'periodstart' => $now - 1000,
            'periodend' => $now,
            'settingtype' => 'ehpad',
            'situationformat' => FORMAT_PLAIN,
            'situation' => 'Une situation.',
            'fichecode' => 'RNCP100',
            'ficherang' => 'C01',
            'ficheupdated' => $now,
            'competencyid' => (int) $this->old['C01']->get('id'),
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int) $candidate->id,
        ]);
        $before = $DB->get_record('tool_vae_evidence', ['id' => $id]);
        $manager = new passage_manager();

        $this->assertSame(1, $manager->overview('RNCP100')[(int) $this->old['C01']->get('id')]->declarations);
        $manager->decide((int) $this->old['C01']->get('id'), (int) $this->new['C01']->get('id'));

        $this->assertEquals($before, $DB->get_record('tool_vae_evidence', ['id' => $id]));
    }

    public function test_the_log_carries_identifiers_and_nothing_else(): void {
        $sink = $this->redirectEvents();
        $manager = new passage_manager();
        $from = (int) $this->old['C01']->get('id');
        $to = (int) $this->new['C01']->get('id');

        $manager->decide($from, $to, 'Texte-Secret de la note.');
        $manager->undo($from);

        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof passage_changed
        ));
        $this->assertCount(2, $events);
        $this->assertSame(['fromcompetencyid' => $from, 'tocompetencyid' => $to], $events[0]->other);
        $this->assertSame(['fromcompetencyid' => $from, 'tocompetencyid' => 0], $events[1]->other);
        $this->assertStringNotContainsString('Texte-Secret', $events[0]->get_description());
    }
}
