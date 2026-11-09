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

use tool_vae\local\positioning_rules;
use tool_vae\local\selfpos_manager;
use tool_vae\local\selfpos_scale;

/**
 * The self-positioning of the vaeiste on the nodes of a record (lot 1a).
 *
 * THE POSITIONING OF THE VAEISTE, NEVER A VALIDATION. Lines added and never changed, the latest stands; the vaeiste alone
 * writes, whoever may read the dossier reads; nothing is computed.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(selfpos_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(positioning_rules::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\selfpositioning_recorded::class)]
final class selfpos_manager_test extends \advanced_testcase {
    /** @var \stdClass The vaeiste: viewown and positionself on their own context. */
    private $vaeiste;

    /** @var \stdClass Another vaeiste, with the same rights on their own context. */
    private $othervaeiste;

    /** @var \stdClass Follows the vaeiste (viewassigned in their context). */
    private $manager;

    /** @var \stdClass Follows somebody else only. */
    private $outsider;

    /** @var \stdClass Holds viewany at the site. */
    private $direction;

    /** @var \tool_vae_generator */
    private $generator;

    /**
     * RNCP100 with two blocs (BC01 holding C01 and C02, BC02 holding C03); RNCP400 with four levels (D1 holding C11, which
     * holds H111 and H112, and C12; D2 alone); the people.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $datagenerator = $this->getDataGenerator();
        $this->generator = $datagenerator->get_plugin_generator('tool_vae');
        $system = \context_system::instance()->id;

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

        $this->vaeiste = $datagenerator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->othervaeiste = $datagenerator->create_user(['firstname' => 'Sacha', 'lastname' => 'Autre']);
        $this->manager = $datagenerator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->outsider = $datagenerator->create_user();
        $this->direction = $datagenerator->create_user(['firstname' => 'Dana', 'lastname' => 'Direction']);

        $own = $datagenerator->create_role();
        assign_capability('tool/vae:viewown', CAP_ALLOW, $own, $system, true);
        assign_capability('tool/vae:positionself', CAP_ALLOW, $own, $system, true);
        set_role_contextlevels($own, [CONTEXT_USER]);
        foreach ([$this->vaeiste, $this->othervaeiste] as $person) {
            role_assign($own, $person->id, \context_user::instance($person->id)->id);
        }

        $follow = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, \context_user::instance($this->vaeiste->id)->id);
        role_assign($follow, $this->outsider->id, \context_user::instance($this->othervaeiste->id)->id);

        $director = $datagenerator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $director, $system, true);
        role_assign($director, $this->direction->id, $system);
        accesslib_clear_all_caches_for_unit_testing();
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
            $this->fail('A refusal "' . $errorcode . '" was expected.');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * The vaeiste writes one line.
     *
     * @param int $level
     * @param string $justification
     * @param string $rank
     * @param string $code
     * @param int $format
     * @return \stdClass
     */
    private function position(
        int $level = 2,
        string $justification = '',
        string $rank = 'C01',
        string $code = 'RNCP100',
        int $format = FORMAT_PLAIN
    ): \stdClass {
        $this->setUser($this->vaeiste);

        return (new selfpos_manager())->write((int) $this->vaeiste->id, $code, $rank, $level, $justification, $format);
    }

    /**
     * How many rows the table holds.
     *
     * @return int
     */
    private function rows(): int {
        global $DB;

        return $DB->count_records(selfpos_manager::TABLE);
    }

    public function test_a_line_is_added_then_another_and_the_latest_stands(): void {
        global $DB;
        $first = $this->position(1, "  Je commence.\r\nAvec aide.  ");
        $second = $this->position(3, 'Je fais seul maintenant.');

        $manager = new selfpos_manager();
        $current = $manager->current((int) $this->vaeiste->id, 'RNCP100');
        $lines = $manager->lines((int) $this->vaeiste->id, 'RNCP100', 'C01');

        $this->assertSame((int) $second->id, (int) $current['C01']->id);
        $this->assertSame(3, (int) $current['C01']->level);
        $this->assertSame([(int) $first->id, (int) $second->id], array_map('intval', array_column($lines, 'id')));
        $this->assertSame("Je commence.\nAvec aide.", $first->justification, 'Line ends normalised, trimmed.');
        $this->assertSame(selfpos_scale::label(1), $first->levellabel);
        $this->assertSame(26, strlen($first->uuid));
        $this->assertSame(64, strlen($first->linehash));
        $this->assertSame(2, (int) $first->justificationformat, 'FORMAT_PLAIN.');
        $this->assertNull($first->importedat);
        $this->assertEquals($first, $DB->get_record(selfpos_manager::TABLE, ['id' => $first->id]), 'Nothing was rewritten.');
        $this->assertTrue(selfpos_manager::standing($current['C01']));
    }

    public function test_the_same_line_twice_is_refused(): void {
        $this->position(2, 'Pareil.');

        $this->assert_refused(fn() => $this->position(2, 'Pareil.'), 'errorselfposduplicate');
        $this->assertSame(1, $this->rows());
        // The same level with another text, or another level with the same text, is a line.
        $this->position(2, 'Pareil, en mieux.');
        $this->position(3, 'Pareil, en mieux.');
        $this->assertSame(3, $this->rows());
    }

    public function test_withdrawing_adds_a_line_and_keeps_the_previous_one_readable(): void {
        $this->assert_refused(fn() => $this->position(0), 'errorselfposnothing');
        $this->position(2, 'Un niveau.');

        $withdrawn = $this->position(0, 'Je me suis trompe de ligne.');

        $this->assertSame(0, (int) $withdrawn->level);
        $this->assertSame(selfpos_scale::label(0), $withdrawn->levellabel);
        $manager = new selfpos_manager();
        $current = $manager->current((int) $this->vaeiste->id, 'RNCP100');
        $this->assertFalse(selfpos_manager::standing($current['C01']));
        $this->assertCount(2, $manager->lines((int) $this->vaeiste->id, 'RNCP100', 'C01'));
        $this->assert_refused(fn() => $this->position(0), 'errorselfposnothing');
        $this->assertSame(2, $this->rows());
    }

    public function test_the_record_itself_is_positioned_with_the_empty_rank(): void {
        $row = $this->position(1, '', '');

        $this->assertSame('', $row->ficherang);
        $this->assertArrayHasKey('', (new selfpos_manager())->current((int) $this->vaeiste->id, 'RNCP100'));
    }

    public function test_every_level_of_a_four_level_record_can_be_positioned(): void {
        foreach (['' => 1, 'D1' => 2, 'C11' => 3, 'H112' => 4, 'D2' => 2] as $rank => $level) {
            $this->assertSame((string) $rank, $this->position($level, '', (string) $rank, 'RNCP400')->ficherang);
        }
        $current = (new selfpos_manager())->current((int) $this->vaeiste->id, 'RNCP400');
        $this->assertSame(['', 'C11', 'D1', 'D2', 'H112'], array_keys($current));
    }

    public function test_an_unknown_record_an_unknown_rank_and_a_non_positionable_rank_get_the_same_refusal(): void {
        $this->setAdminUser();
        $gateway = new \tool_vae\local\competency_gateway();
        $gateway->create_competency((int) $gateway->find_framework('RNCP100'), 'AS-1-1', 'Un code a tirets');

        $this->assert_refused(fn() => $this->position(2, '', 'C01', 'RNCP999'), 'errorselfposdenied');
        $this->assert_refused(fn() => $this->position(2, '', 'C01', 'rncp100'), 'errorselfposdenied');
        $this->assert_refused(fn() => $this->position(2, '', 'C99'), 'errorselfposdenied');
        $this->assert_refused(fn() => $this->position(2, '', 'AS-1-1'), 'errorselfposdenied');
        $this->assertSame(0, $this->rows());
    }

    public function test_the_justification_may_be_empty_and_at_most_ten_thousand_characters(): void {
        $this->assertSame('', $this->position(1, "  \n ")->justification);
        $this->assertSame(10000, \core_text::strlen($this->position(2, str_repeat('é', 10000))->justification));
        $this->assert_refused(fn() => $this->position(3, str_repeat('a', 10001)), 'errorpositioningtoolong');
        $this->assertSame(2, $this->rows());
    }

    public function test_a_control_character_in_the_justification_is_refused(): void {
        $this->assert_refused(fn() => $this->position(1, "Texte\x07"), 'errorpositioningcontrol');
        $this->assert_refused(fn() => $this->position(1, "Texte\u{202E}"), 'errorpositioningcontrol');
        $this->assertSame("Tab\tet\nsaut", $this->position(1, "Tab\tet\nsaut")->justification);
    }

    public function test_html_in_the_justification_is_kept_as_text_never_as_markup(): void {
        $row = $this->position(1, '<b>x</b> & <script>alert(1)</script>');
        $this->assertSame('<b>x</b> & <script>alert(1)</script>', $row->justification);

        $markdown = $this->position(2, '**fort**', 'C01', 'RNCP100', FORMAT_MARKDOWN);
        $this->assertSame(4, (int) $markdown->justificationformat, 'FORMAT_MARKDOWN.');
        $this->assert_refused(fn() => $this->position(3, 'x', 'C01', 'RNCP100', FORMAT_HTML), 'errorselfposformat');
        $this->assert_refused(fn() => $this->position(3, 'x', 'C01', 'RNCP100', FORMAT_MOODLE), 'errorselfposformat');
    }

    public function test_the_level_must_be_on_the_scale(): void {
        $this->assert_refused(fn() => $this->position(5), 'errorselfposlevel');
        $this->assert_refused(fn() => $this->position(-1), 'errorselfposlevel');
        $this->assertSame(0, $this->rows());
    }

    public function test_a_line_keeps_the_label_it_was_written_with(): void {
        $before = $this->position(2);
        set_config('selfposlabel2', 'Je pratique en etant accompagne', 'tool_vae');
        $after = $this->position(2, 'Le meme palier, renomme.');

        $this->assertSame(get_string('selfposlabeldefault2', 'tool_vae'), $before->levellabel);
        $this->assertSame('Je pratique en etant accompagne', $after->levellabel);
        $lines = (new selfpos_manager())->lines((int) $this->vaeiste->id, 'RNCP100', 'C01');
        $this->assertSame(get_string('selfposlabeldefault2', 'tool_vae'), reset($lines)->levellabel, 'Kept as written.');
    }

    public function test_more_than_two_hundred_lines_on_one_node_are_refused(): void {
        global $DB;
        $this->position(1, 'Depart.');
        $row = $DB->get_record(selfpos_manager::TABLE, [], '*', MUST_EXIST);
        for ($i = 2; $i <= selfpos_manager::MAX_PER_NODE; $i++) {
            unset($row->id);
            $row->uuid = \tool_vae\local\identity_map::generate_ulid();
            $row->linehash = hash('sha256', 'ligne ' . $i);
            $DB->insert_record(selfpos_manager::TABLE, $row);
        }
        $this->assertSame(200, $this->rows());

        $this->assert_refused(fn() => $this->position(3, 'La 201e.'), 'errorselfposfull');
        $this->assertSame(200, $this->rows());
        $this->assertSame('C02', $this->position(3, 'Un autre noeud.', 'C02')->ficherang, 'The bound is per node.');
    }

    public function test_only_the_vaeiste_writes_his_own_dossier(): void {
        $id = (int) $this->vaeiste->id;
        foreach ([$this->manager, $this->direction, get_admin(), $this->othervaeiste, $this->outsider] as $user) {
            $this->setUser($user);
            $this->assertFalse(selfpos_manager::can_write($id), fullname($user));
            $this->assert_refused(fn() => (new selfpos_manager())->write($id, 'RNCP100', 'C01', 2, 'x'), 'errorselfposdenied');
        }
        $this->setUser(null);
        $this->assertFalse(selfpos_manager::can_write($id));
        $this->setGuestUser();
        $this->assertFalse(selfpos_manager::can_write($id));
        $this->setUser($this->vaeiste);
        $this->assertTrue(selfpos_manager::can_write($id));
        $this->assertFalse(selfpos_manager::can_write((int) $this->othervaeiste->id), 'Not on somebody else\'s dossier.');
        $this->assertSame(0, $this->rows());

        // Without the right, the vaeiste themselves may not.
        $this->setUser($this->manager);
        $this->assertFalse(selfpos_manager::can_write((int) $this->manager->id), 'No positionself: no self-positioning.');
    }

    /**
     * Two sends at the same moment: the write takes the lock of positioning_rules (one resource per vaeiste and record, shared
     * with the structure's writes), reads the latest line under it and inserts under it. The lock of the database is held by
     * the SESSION and is reentrant within it, so one PHPUnit process cannot make a second send wait: what is shown here is that
     * the write goes through the lock (held by this very process, it is taken again and released) and that the check of the
     * duplicate is made under it, so that the second of two identical sends is refused and one line stands.
     */
    public function test_two_identical_sends_leave_one_line_and_the_write_goes_through_the_shared_lock(): void {
        $held = positioning_rules::lock((int) $this->vaeiste->id, 'RNCP100');
        try {
            $this->position(2, 'Pendant le verrou.');
            $this->assertSame(1, $this->rows(), 'Reentrant in one session: the write went through the same lock.');
        } finally {
            $held->release();
        }
        $this->assert_refused(fn() => $this->position(2, 'Pendant le verrou.'), 'errorselfposduplicate');
        $this->assertSame(1, $this->rows());
    }

    public function test_the_event_carries_identifiers_and_the_level_never_the_text(): void {
        $sink = $this->redirectEvents();

        $this->position(3, 'Une justification tres personnelle.', 'H111', 'RNCP400');

        $this->assertSame(1, $sink->count());
        $event = $sink->get_events()[0];
        $this->assertInstanceOf(\tool_vae\event\selfpositioning_recorded::class, $event);
        $data = $event->get_data();
        $this->assertSame((int) $this->vaeiste->id, (int) $data['relateduserid']);
        $this->assertSame(['fichecode' => 'RNCP400', 'ficherang' => 'H111', 'level' => 3], $data['other']);
        $encoded = json_encode($data) . $event->get_description();
        $this->assertStringNotContainsString('personnelle', $encoded);
        $this->assertStringNotContainsString(selfpos_scale::label(3), $encoded);
    }

    public function test_reading_follows_can_view(): void {
        $this->position(2, 'Lisible.');
        $manager = new selfpos_manager();
        $id = (int) $this->vaeiste->id;

        foreach ([$this->vaeiste, $this->manager, $this->direction] as $user) {
            $this->setUser($user);
            $this->assertCount(1, $manager->lines($id, 'RNCP100', 'C01'), fullname($user));
            $this->assertArrayHasKey('C01', $manager->current($id, 'RNCP100'));
        }
        foreach ([$this->othervaeiste, $this->outsider] as $user) {
            $this->setUser($user);
            try {
                $manager->current($id, 'RNCP100');
                $this->fail('Refused for ' . fullname($user));
            } catch (\required_capability_exception $e) {
                $this->assertSame('nopermissions', $e->errorcode);
            }
        }
    }

    public function test_the_generator_writes_in_the_name_of_the_vaeiste(): void {
        $this->setAdminUser();
        $row = $this->generator->create_selfpos([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP400', 'ficherang' => 'C12', 'level' => 4,
            'justification' => 'Par le generateur.',
        ]);

        $this->assertSame((int) $this->vaeiste->id, (int) $row->candidateid);
        $this->assertSame(4, (int) $row->level);
        $this->assertSame((int) get_admin()->id, (int) $GLOBALS['USER']->id, 'The previous user is restored.');
    }

    public function test_the_reason_of_the_structure_still_refuses_the_empty_text(): void {
        $this->assert_refused(fn() => positioning_rules::clean_reason("  \n "), 'errorpositioningempty');
        $this->assertSame('', positioning_rules::clean_text("  \n ", 10));
        $this->assertSame("a\nb", positioning_rules::clean_text("a\r\nb", 10));
    }
}
