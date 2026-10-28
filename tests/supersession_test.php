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

use tool_vae\event\supersession_changed;
use tool_vae\local\competency_gateway;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\supersession;

/**
 * Which record replaces which, and what still holds the old one (T-30, lot 1, D-14).
 *
 * WHAT IS ASSERTED. That an administrator declares a replacement and removes it; that a record cannot replace
 * itself nor close a loop, however long; that an unknown record is refused; that only someone who may import can
 * write it; that the overview counts declarations, candidates and competencies gone from the source and names
 * nobody; that declaring moves and changes nothing; that the log carries codes of records and nothing else.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\supersession::class)]
final class supersession_test extends \advanced_testcase {
    /** @var int[] Source ids by record code. */
    private $sources = [];

    /**
     * Three imported records, as rows: the rules under test do not depend on how a record is imported.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $now = time();
        foreach (['RNCP100', 'RNCP200', 'RNCP300'] as $code) {
            $framework = $generator->create_framework(['idnumber' => $code, 'shortname' => "Fiche $code"]);
            $this->sources[$code] = (int) $DB->insert_record('tool_vae_source', (object) [
                'frameworkid' => (int) $framework->get('id'),
                'fichecode' => $code,
                'fichetype' => 'rncp',
                'sourceupdated' => $now - DAYSECS,
                'licence' => 'Licence Ouverte 2.0',
                'attribution' => 'France competences',
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => (int) get_admin()->id,
            ]);
        }
    }

    /**
     * A declaration of a candidate against a record, as a row.
     *
     * @param int $candidateid
     * @param string $fichecode
     * @return int The id.
     */
    private function declare_on(int $candidateid, string $fichecode): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('tool_vae_evidence', (object) [
            'uuid' => identity_map::generate_ulid(),
            'userevidenceid' => $DB->count_records('tool_vae_evidence') + 1,
            'candidateid' => $candidateid,
            'periodstart' => $now - 1000,
            'periodend' => $now,
            'settingtype' => 'ehpad',
            'situationformat' => FORMAT_PLAIN,
            'situation' => 'Une situation.',
            'fichecode' => $fichecode,
            'ficherang' => 'C01',
            'ficheupdated' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $candidateid,
        ]);
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

    public function test_a_replacement_is_declared_read_and_removed(): void {
        supersession::declare_successor('RNCP100', 'RNCP200');

        $rows = supersession::overview();
        $this->assertSame('RNCP200', $rows['RNCP100']->successorcode);
        $this->assertSame('Fiche RNCP200', $rows['RNCP100']->successorlabel);
        $this->assertSame('', $rows['RNCP200']->successorcode);

        supersession::clear_successor('RNCP100');

        $this->assertSame('', supersession::overview()['RNCP100']->successorcode);
    }

    public function test_a_second_declaration_replaces_the_first(): void {
        supersession::declare_successor('RNCP100', 'RNCP200');
        supersession::declare_successor('RNCP100', 'RNCP300');

        $this->assertSame('RNCP300', supersession::overview()['RNCP100']->successorcode);
    }

    public function test_a_record_does_not_replace_itself_nor_one_that_is_not_there(): void {
        $this->assert_refused(function () {
            supersession::declare_successor('RNCP100', 'RNCP100');
        }, 'errorsupersessionsame');
        $this->assert_refused(function () {
            supersession::declare_successor('RNCP100', 'RNCP999');
        }, 'errorsupersessionunknown');
        $this->assert_refused(function () {
            supersession::declare_successor('RNCP999', 'RNCP100');
        }, 'errorsupersessionunknown');
        $this->assert_refused(function () {
            supersession::clear_successor('RNCP999');
        }, 'errorsupersessionunknown');
    }

    public function test_the_chain_never_loops(): void {
        supersession::declare_successor('RNCP100', 'RNCP200');
        supersession::declare_successor('RNCP200', 'RNCP300');

        $this->assert_refused(function () {
            supersession::declare_successor('RNCP200', 'RNCP100');
        }, 'errorsupersessioncycle');
        $this->assert_refused(function () {
            supersession::declare_successor('RNCP300', 'RNCP100');
        }, 'errorsupersessioncycle');
        $this->assertSame('', supersession::overview()['RNCP300']->successorcode);
    }

    public function test_only_someone_who_may_import_writes_it(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        supersession::declare_successor('RNCP100', 'RNCP200');
    }

    public function test_the_overview_counts_and_names_nobody(): void {
        global $DB;
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $this->declare_on((int) $first->id, 'RNCP100');
        $this->declare_on((int) $first->id, 'RNCP100');
        $this->declare_on((int) $second->id, 'RNCP100');
        $this->declare_on((int) $second->id, 'RNCP200');

        $competency = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM)
            ->create_competency(['competencyframeworkid' => $DB->get_field('tool_vae_source', 'frameworkid', [
                'fichecode' => 'RNCP100',
            ])]);
        $DB->insert_record('tool_vae_obsolete', (object) [
            'competencyid' => (int) $competency->get('id'),
            'sourceid' => $this->sources['RNCP100'],
            'detectedat' => time(),
            'kind' => importer::GONE,
        ]);

        $rows = supersession::overview();

        $this->assertSame(3, $rows['RNCP100']->declarations);
        $this->assertSame(2, $rows['RNCP100']->candidates, 'Two people, not three declarations.');
        $this->assertSame(1, $rows['RNCP100']->gone);
        $this->assertSame(1, $rows['RNCP200']->declarations);
        $this->assertSame(0, $rows['RNCP300']->declarations);
        $this->assertSame(0, $rows['RNCP300']->gone);
        $this->assertSame(
            [
                'fichecode', 'label', 'sourceupdated', 'successorcode', 'successorlabel', 'declarations', 'candidates',
                'carried', 'gone',
            ],
            array_keys((array) $rows['RNCP100']),
            'Numbers and codes of records: no name, no id of a person.'
        );
    }

    /**
     * A record whose framework is gone is still listed, with its code and what still holds it.
     *
     * The name came from an inner JOIN on the core's table: a framework deleted in tool_lp - the core allows it
     * while nobody has declared on it - took its record off the one screen that counts what holds a record, without
     * a word, while the administration screen went on showing it.
     */
    public function test_a_record_whose_framework_is_gone_is_still_listed(): void {
        global $DB;
        $candidate = $this->getDataGenerator()->create_user();
        $this->declare_on((int) $candidate->id, 'RNCP100');
        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP100']);
        $DB->delete_records('competency', ['competencyframeworkid' => $frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $frameworkid]);

        $rows = supersession::overview();

        $this->assertSame(['RNCP100', 'RNCP200', 'RNCP300'], array_keys($rows));
        $this->assertSame('', $rows['RNCP100']->label);
        $this->assertSame(1, $rows['RNCP100']->declarations);
        $this->assertSame('Fiche RNCP200', $rows['RNCP200']->label);

        // A record that is replaced by one whose framework is gone names it by its code alone.
        supersession::declare_successor('RNCP200', 'RNCP100');
        $this->assertSame('', supersession::overview()['RNCP200']->successorlabel);
        $this->assertSame('RNCP100', supersession::overview()['RNCP200']->successorcode);
    }

    public function test_a_candidate_with_only_a_preparation_on_the_record_is_counted(): void {
        global $DB;
        $declarer = $this->getDataGenerator()->create_user();
        $writer = $this->getDataGenerator()->create_user();
        $this->declare_on((int) $declarer->id, 'RNCP100');
        $this->declare_on((int) $writer->id, 'RNCP200');
        $now = time();
        // The writer declared on another record and wrote the preparation of this one; the declarer wrote it too.
        foreach ([$writer, $declarer] as $user) {
            $DB->insert_record('tool_vae_draft', (object) [
                'uuid' => identity_map::generate_ulid(), 'candidateid' => (int) $user->id, 'doctype' => 'validation',
                'section' => '_synthese', 'slot' => 0, 'fichecode' => 'RNCP100', 'currentversion' => 0,
                'timecreated' => $now, 'timemodified' => $now, 'usermodified' => (int) $user->id,
            ]);
        }

        $rows = supersession::overview();

        $this->assertSame(1, $rows['RNCP100']->declarations);
        $this->assertSame(2, $rows['RNCP100']->candidates, 'The declarer once, and the writer who only wrote.');
        $this->assertSame(1, $rows['RNCP200']->candidates);
    }

    public function test_declaring_moves_and_changes_nothing(): void {
        global $DB;
        $candidate = $this->getDataGenerator()->create_user();
        $id = $this->declare_on((int) $candidate->id, 'RNCP100');
        $before = $DB->get_record('tool_vae_evidence', ['id' => $id]);

        supersession::declare_successor('RNCP100', 'RNCP200');

        $this->assertEquals($before, $DB->get_record('tool_vae_evidence', ['id' => $id]));
        $this->assertSame(0, $DB->count_records('tool_vae_passage'));
    }

    public function test_the_log_carries_codes_of_records_and_nothing_else(): void {
        $sink = $this->redirectEvents();

        supersession::declare_successor('RNCP100', 'RNCP200');
        supersession::clear_successor('RNCP100');

        $events = array_values(array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof supersession_changed
        ));
        $this->assertCount(2, $events);
        $this->assertSame(['fichecode' => 'RNCP100', 'successor' => 'RNCP200'], $events[0]->other);
        $this->assertSame(['fichecode' => 'RNCP100', 'successor' => ''], $events[1]->other);
        $this->assertStringContainsString('RNCP100', $events[0]->get_description());
    }
}
