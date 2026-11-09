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

use tool_vae\local\certification_manager;
use tool_vae\local\evaluation_manager;

/**
 * The cycle inside the structure: the evaluation of a competency and the certification of a bloc (T-90, lot 3).
 *
 * POSITIONINGS OF THE STRUCTURE, NEVER VALIDATIONS. The manager who follows the candidate evaluates a competency; the
 * direction certifies a bloc; the jury, outside the plugin, alone validates. Both are added to and never changed: the
 * latest line stands, a correction or a withdrawal is a new line.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\evaluation_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\certification_manager::class)]
final class positioning_test extends \advanced_testcase {
    /** @var \stdClass The candidate, who declared on RNCP100 and on RS200. */
    private $candidate;

    /** @var \stdClass Follows the candidate and holds evaluate. */
    private $manager;

    /** @var \stdClass Holds evaluate in another person's context only, and follows nobody here. */
    private $outsider;

    /** @var \stdClass Holds viewany and certify at the site. */
    private $direction;

    /** @var \stdClass Holds viewany and not certify. */
    private $reader;

    /** @var \stdClass Holds certify and not viewany. */
    private $certifier;

    /** @var \stdClass A candidate with no declaration at all. */
    private $stranger;

    /** @var \tool_vae_generator */
    private $plugingenerator;

    /**
     * RNCP100 with two blocs (BC01 holding C01 and C02, BC02 holding C03), RS200 of one level (C01, C02), the people.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->plugingenerator = $generator->get_plugin_generator('tool_vae');
        $system = \context_system::instance()->id;

        $this->plugingenerator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        $this->plugingenerator->create_competency(['fichecode' => 'RNCP100', 'ficherang' => 'BC02', 'shortname' => 'Bloc 2']);
        foreach ([['C01', 'BC01'], ['C02', 'BC01'], ['C03', 'BC02']] as [$rank, $bloc]) {
            $this->plugingenerator->create_competency([
                'fichecode' => 'RNCP100', 'ficherang' => $rank, 'shortname' => 'Competence ' . $rank, 'bloc' => $bloc,
            ]);
        }
        $this->plugingenerator->create_source(['fichecode' => 'RS200', 'ficherang' => 'C01', 'shortname' => 'Une competence']);
        $this->plugingenerator->create_competency(['fichecode' => 'RS200', 'ficherang' => 'C02', 'shortname' => 'Une autre']);

        $this->candidate = $generator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->manager = $generator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->outsider = $generator->create_user();
        $this->direction = $generator->create_user(['firstname' => 'Dana', 'lastname' => 'Direction']);
        $this->reader = $generator->create_user();
        $this->certifier = $generator->create_user();
        $this->stranger = $generator->create_user();
        $context = \context_user::instance($this->candidate->id)->id;

        $follow = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        assign_capability('tool/vae:evaluate', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, $context);
        $other = $generator->create_user();
        role_assign($follow, $this->outsider->id, \context_user::instance($other->id)->id);

        $own = $generator->create_role();
        assign_capability('tool/vae:viewown', CAP_ALLOW, $own, $system, true);
        assign_capability('tool/vae:declare', CAP_ALLOW, $own, $system, true);
        role_assign($own, $this->candidate->id, $context);
        role_assign($own, $this->direction->id, \context_user::instance($this->direction->id)->id);

        $director = $generator->create_role();
        foreach (['tool/vae:viewany', 'tool/vae:certify'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $director, $system, true);
        }
        role_assign($director, $this->direction->id, $system);
        $viewer = $generator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $viewer, $system, true);
        role_assign($viewer, $this->reader->id, $system);
        $certify = $generator->create_role();
        assign_capability('tool/vae:certify', CAP_ALLOW, $certify, $system, true);
        role_assign($certify, $this->certifier->id, $system);
        accesslib_clear_all_caches_for_unit_testing();

        foreach (['RNCP100' => 'C01', 'RS200' => 'C01'] as $code => $rank) {
            $this->plugingenerator->create_declaration([
                'userid' => $this->candidate->id, 'fichecode' => $code, 'ficherang' => $rank,
                'situation' => 'Une situation vecue en etablissement.',
            ]);
        }
        // The direction is also a candidate of the scheme, with a declaration: nobody certifies themselves.
        $this->plugingenerator->create_declaration([
            'userid' => $this->direction->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01',
            'situation' => 'Une situation vecue par la direction.',
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
            $this->fail('A refusal "' . $errorcode . '" was expected.');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * The evaluator writes one line.
     *
     * @param string $status
     * @param string $reason
     * @param string $rank
     * @param string $code
     * @return \stdClass
     */
    private function evaluate(
        string $status = 'sufficient',
        string $reason = 'Constate sur le terrain.',
        string $rank = 'C01',
        string $code = 'RNCP100'
    ): \stdClass {
        $this->setUser($this->manager);

        return (new evaluation_manager())->write((int) $this->candidate->id, $code, $rank, $status, $reason);
    }

    /**
     * The direction certifies a bloc.
     *
     * @param string $rank
     * @param string $reason
     * @param string $alert
     * @param string $status
     * @param string $code
     * @return \stdClass
     */
    private function certify(
        string $rank = 'BC01',
        string $reason = 'La structure se positionne.',
        string $alert = '',
        string $status = 'certified',
        string $code = 'RNCP100'
    ): \stdClass {
        $this->setUser($this->direction);

        return (new certification_manager())->write(
            (int) $this->candidate->id,
            $code,
            $rank,
            $status,
            $reason,
            userdate(time(), '%Y-%m-%d', 99, false),
            $alert
        );
    }

    public function test_the_manager_who_follows_evaluates_and_the_line_is_kept_as_written(): void {
        $sink = $this->redirectEvents();

        $row = $this->evaluate('inproof', "  Deux situations\r\nobservees.  ");

        $this->assertSame((int) $this->candidate->id, (int) $row->candidateid);
        $this->assertSame('RNCP100', $row->fichecode);
        $this->assertSame('C01', $row->ficherang);
        $this->assertSame('inproof', $row->status);
        $this->assertSame("Deux situations\nobservees.", $row->reason);
        $this->assertSame(26, strlen($row->uuid));
        $this->assertSame((int) $this->manager->id, (int) $row->authorid);
        $this->assertSame(64, strlen($row->reasonhash));
        $this->assertSame(1, $sink->count(), 'One event, with identifiers only.');
        $event = $sink->get_events()[0];
        $this->assertInstanceOf(\tool_vae\event\evaluation_recorded::class, $event);
        $this->assertStringNotContainsString('Deux situations', json_encode($event->get_data()));
    }

    public function test_only_who_follows_and_holds_evaluate_may_evaluate(): void {
        $refused = function (\stdClass $user): void {
            $this->setUser($user);
            $this->assertFalse(evaluation_manager::can_write((int) $this->candidate->id), fullname($user));
            $this->assert_refused(
                fn() => (new evaluation_manager())->write((int) $this->candidate->id, 'RNCP100', 'C01', 'sufficient', 'Motif.'),
                'errorpositioningdenied'
            );
        };

        foreach ([$this->candidate, $this->outsider, $this->direction, $this->reader, get_admin(), $this->stranger] as $user) {
            $refused($user);
        }
        $this->setUser($this->manager);
        $this->assertTrue(evaluation_manager::can_write((int) $this->candidate->id));
        $this->setUser(null);
        $this->assertFalse(evaluation_manager::can_write((int) $this->candidate->id));
        $this->setGuestUser();
        $this->assertFalse(evaluation_manager::can_write((int) $this->candidate->id));
    }

    public function test_nobody_evaluates_themselves_even_when_they_follow_and_hold_the_right(): void {
        $system = \context_system::instance()->id;
        $role = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $role, $system, true);
        assign_capability('tool/vae:evaluate', CAP_ALLOW, $role, $system, true);
        set_role_contextlevels($role, [CONTEXT_USER]);
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->candidate);

        $this->assertFalse(evaluation_manager::can_write((int) $this->candidate->id));
    }

    public function test_the_rank_must_be_a_competency_of_the_record_the_candidate_declared_on(): void {
        $this->setUser($this->manager);
        $manager = new evaluation_manager();
        $id = (int) $this->candidate->id;

        // A bloc is not evaluated: it is certified.
        $this->assert_refused(fn() => $manager->write($id, 'RNCP100', 'BC01', 'sufficient', 'Motif.'), 'errorpositioningdenied');
        // The whole record (rank '') is the bloc of a record with no bloc: not a competency either.
        $this->assert_refused(fn() => $manager->write($id, 'RNCP100', '', 'sufficient', 'Motif.'), 'errorpositioningdenied');
        // Unknown, malformed, other record's rank, unknown record.
        $this->assert_refused(fn() => $manager->write($id, 'RNCP100', 'C99', 'sufficient', 'Motif.'), 'errorpositioningdenied');
        $this->assert_refused(fn() => $manager->write($id, 'RNCP100', 'c 1', 'sufficient', 'Motif.'), 'errorpositioningdenied');
        $this->assert_refused(fn() => $manager->write($id, 'RNCP999', 'C01', 'sufficient', 'Motif.'), 'errorpositioningdenied');
        $this->assert_refused(fn() => $manager->write($id, 'rncp100', 'C01', 'sufficient', 'Motif.'), 'errorpositioningdenied');
        // A leaf of a record the candidate never declared on: C03 is in the record, the candidate declared on C01 only.
        $this->plugingenerator->create_source(['fichecode' => 'RNCP300', 'ficherang' => 'C01', 'shortname' => 'Autre fiche']);
        $this->assert_refused(fn() => $manager->write($id, 'RNCP300', 'C01', 'sufficient', 'Motif.'), 'errorpositioningdenied');
        $this->assertSame(0, $this->count_rows('tool_vae_eval'));
        // A competency the candidate did not declare on, in a record they did declare on, is evaluable.
        $this->assertSame('C03', $manager->write($id, 'RNCP100', 'C03', 'toconstruct', 'Pas encore de preuve.')->ficherang);
    }

    /**
     * How many rows a table holds.
     *
     * @param string $table
     * @return int
     */
    private function count_rows(string $table): int {
        global $DB;

        return $DB->count_records($table);
    }

    public function test_status_and_reason_are_checked_at_the_door(): void {
        $this->setUser($this->manager);
        $manager = new evaluation_manager();
        $id = (int) $this->candidate->id;

        $this->assert_refused(fn() => $manager->write($id, 'RNCP100', 'C01', 'validated', 'Motif.'), 'errorpositioningstatus');
        $this->assert_refused(fn() => $manager->write($id, 'RNCP100', 'C01', 'sufficient', "   \n "), 'errorpositioningempty');
        $this->assert_refused(
            fn() => $manager->write($id, 'RNCP100', 'C01', 'sufficient', str_repeat('a', evaluation_manager::MAX_LENGTH + 1)),
            'errorpositioningtoolong'
        );
        $this->assert_refused(fn() => $manager->write($id, 'RNCP100', 'C01', 'sufficient', "Motif\x07"), 'errorpositioningcontrol');
        $this->assert_refused(
            fn() => $manager->write($id, 'RNCP100', 'C01', 'sufficient', "Motif\u{202E}"),
            'errorpositioningcontrol'
        );
        $this->assertSame(0, $this->count_rows('tool_vae_eval'));
        $this->assertSame(
            evaluation_manager::MAX_LENGTH,
            \core_text::strlen(
                $manager->write($id, 'RNCP100', 'C01', 'sufficient', str_repeat('a', evaluation_manager::MAX_LENGTH))->reason
            )
        );
    }

    public function test_a_correction_is_a_new_line_the_latest_stands_and_the_first_is_unchanged(): void {
        global $DB;
        $first = $this->evaluate('toconstruct', 'Premier constat.');
        $second = $this->evaluate('inproof', 'Deuxieme constat.');
        $third = $this->evaluate('sufficient', 'Troisieme constat.');

        $manager = new evaluation_manager();
        $lines = $manager->lines((int) $this->candidate->id, 'RNCP100', 'C01');
        $this->assertSame(
            [(int) $first->id, (int) $second->id, (int) $third->id],
            array_map('intval', array_column($lines, 'id')),
            'The history is the whole of it, the earliest first.'
        );
        $current = $manager->current((int) $this->candidate->id, 'RNCP100');
        $this->assertSame('sufficient', $current['C01']->status);
        $this->assertSame((int) $third->id, (int) $current['C01']->id);
        $this->assertEquals($first, $DB->get_record('tool_vae_eval', ['id' => $first->id]), 'Nothing was rewritten.');
    }

    public function test_two_lines_in_the_same_second_the_later_id_stands(): void {
        global $DB;
        $first = $this->evaluate('toconstruct', 'Premier.');
        $second = $this->evaluate('inproof', 'Second.');
        $DB->set_field('tool_vae_eval', 'timecreated', 1700000000, ['id' => $first->id]);
        $DB->set_field('tool_vae_eval', 'timecreated', 1700000000, ['id' => $second->id]);

        $current = (new evaluation_manager())->current((int) $this->candidate->id, 'RNCP100');

        $this->assertSame((int) $second->id, (int) $current['C01']->id);
    }

    public function test_the_same_status_with_the_same_reason_is_not_a_new_line(): void {
        $this->evaluate('inproof', 'Constat.');

        $this->assert_refused(fn() => $this->evaluate('inproof', 'Constat.'), 'errorpositioningduplicate');
        $this->assertSame(1, $this->count_rows('tool_vae_eval'));
        // The same reason with another status, or the same status with another reason, is a line.
        $this->evaluate('sufficient', 'Constat.');
        $this->evaluate('sufficient', 'Constat complete.');
        $this->assertSame(3, $this->count_rows('tool_vae_eval'));
    }

    public function test_a_withdrawal_is_a_line_and_needs_something_to_withdraw(): void {
        $this->assert_refused(fn() => $this->evaluate('withdrawn', 'Rien a retirer.'), 'errorpositioningnothing');
        $this->evaluate('sufficient', 'Constat.');
        $withdrawn = $this->evaluate('withdrawn', 'Erreur de saisie.');
        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertSame(2, $this->count_rows('tool_vae_eval'));
        // A second withdrawal has nothing left to withdraw.
        $this->assert_refused(fn() => $this->evaluate('withdrawn', 'Encore.'), 'errorpositioningnothing');
        $current = (new evaluation_manager())->current((int) $this->candidate->id, 'RNCP100');
        $this->assertSame('withdrawn', $current['C01']->status);
    }

    public function test_who_may_read_reads_and_nobody_else(): void {
        $this->evaluate('sufficient', 'Constat.');
        $manager = new evaluation_manager();
        $id = (int) $this->candidate->id;

        foreach ([$this->candidate, $this->manager, $this->reader, $this->direction] as $user) {
            $this->setUser($user);
            $this->assertCount(1, $manager->lines($id, 'RNCP100', 'C01'), fullname($user));
            $this->assertArrayHasKey('C01', $manager->current($id, 'RNCP100'));
        }
        foreach ([$this->outsider, $this->stranger, $this->certifier] as $user) {
            $this->setUser($user);
            $this->expectException(\moodle_exception::class);
            $manager->lines($id, 'RNCP100', 'C01');
        }
    }

    public function test_the_direction_certifies_a_bloc_and_the_line_is_kept_as_written(): void {
        $sink = $this->redirectEvents();

        $row = $this->certify('BC02', 'Le bloc est positionne.', 'Une competence reste a construire.');

        $this->assertSame('BC02', $row->ficherang);
        $this->assertSame('certified', $row->status);
        $this->assertSame('Le bloc est positionne.', $row->reason);
        $this->assertSame('Une competence reste a construire.', $row->alertreason);
        $this->assertSame(userdate(time(), '%Y-%m-%d', 99, false), $row->decisiondate);
        $this->assertSame((int) $this->direction->id, (int) $row->authorid);
        $this->assertSame(1, $sink->count());
        $this->assertInstanceOf(\tool_vae\event\certification_recorded::class, $sink->get_events()[0]);
        $this->assertStringNotContainsString('positionne', json_encode($sink->get_events()[0]->get_data()));
    }

    public function test_certify_takes_viewany_and_certify_together_and_never_oneself(): void {
        $id = (int) $this->candidate->id;
        $write = fn() => (new certification_manager())->write(
            $id,
            'RNCP100',
            'BC02',
            'certified',
            'Motif.',
            userdate(time(), '%Y-%m-%d', 99, false),
            'Alerte.'
        );

        foreach ([$this->reader, $this->certifier, $this->manager, $this->candidate, $this->outsider, $this->stranger] as $user) {
            $this->setUser($user);
            $this->assertFalse(certification_manager::can_write($id), fullname($user));
            $this->assert_refused($write, 'errorpositioningdenied');
        }
        $this->setUser($this->direction);
        $this->assertTrue(certification_manager::can_write($id));
        $this->setAdminUser();
        $this->assertTrue(certification_manager::can_write($id), 'The administrator certifies: they pass every check.');
        // Nobody certifies themselves: the direction, who is also a candidate, and the administrator.
        $this->setUser($this->direction);
        $this->assertFalse(certification_manager::can_write((int) $this->direction->id));
        $this->setAdminUser();
        $this->assertFalse(certification_manager::can_write((int) get_admin()->id));
        // An id that is no account: no right, no line.
        $this->setUser($this->direction);
        $this->assertFalse(certification_manager::can_write(987654));
        $this->setUser(null);
        $this->assertFalse(certification_manager::can_write($id));
        $this->assertSame(0, $this->count_rows('tool_vae_certif'));
    }

    public function test_the_direction_does_not_certify_a_candidate_who_never_declared_on_the_record(): void {
        $this->setUser($this->direction);
        $this->assert_refused(
            fn() => (new certification_manager())->write(
                (int) $this->stranger->id,
                'RNCP100',
                'BC01',
                'certified',
                'Motif.',
                userdate(time(), '%Y-%m-%d', 99, false),
                'Alerte.'
            ),
            'errorpositioningdenied'
        );
    }

    public function test_the_rank_of_a_certification_is_a_bloc_or_the_whole_of_a_record_with_no_bloc(): void {
        $this->setUser($this->direction);
        $id = (int) $this->candidate->id;
        $date = userdate(time(), '%Y-%m-%d', 99, false);
        $manager = new certification_manager();

        // A leaf is not certified.
        $this->assert_refused(
            fn() => $manager->write($id, 'RNCP100', 'C01', 'certified', 'Motif.', $date, 'Alerte.'),
            'errorpositioningdenied'
        );
        // Nor withdrawn: a withdrawal of a leaf is refused as a bloc that is not there, not as "nothing to withdraw".
        $this->assert_refused(
            fn() => $manager->write($id, 'RNCP100', 'C01', 'withdrawn', 'Retrait.', $date),
            'errorpositioningdenied'
        );
        // A record with blocs has no "whole" bloc.
        $this->assert_refused(
            fn() => $manager->write($id, 'RNCP100', '', 'certified', 'Motif.', $date, 'Alerte.'),
            'errorpositioningdenied'
        );
        $this->assert_refused(
            fn() => $manager->write($id, 'RNCP100', 'BC99', 'certified', 'Motif.', $date, 'Alerte.'),
            'errorpositioningdenied'
        );
        // A record with no bloc is its own bloc, rank ''; a rank of a leaf is still refused.
        $this->assert_refused(
            fn() => $manager->write($id, 'RS200', 'C01', 'certified', 'Motif.', $date, 'Alerte.'),
            'errorpositioningdenied'
        );
        $row = $manager->write($id, 'RS200', '', 'certified', 'La fiche entiere.', $date, 'Alerte.');
        $this->assertSame('', $row->ficherang);
    }

    public function test_the_alert_lists_the_competencies_of_the_bloc_that_are_not_sufficient(): void {
        $this->setUser($this->direction);
        $manager = new certification_manager();
        $id = (int) $this->candidate->id;

        // Nothing evaluated: every leaf of the bloc is lacking, the bloc itself is not one.
        $this->assertSame(['C01', 'C02'], array_keys($manager->alert($id, 'RNCP100', 'BC01')));
        $this->assertSame(['C03'], array_keys($manager->alert($id, 'RNCP100', 'BC02')));
        // The whole record, for a record with no bloc.
        $this->assertSame(['C01', 'C02'], array_keys($manager->alert($id, 'RS200', '')));

        $this->evaluate('sufficient', 'Constat.', 'C01');
        $this->evaluate('inproof', 'Constat.', 'C02');
        $this->setUser($this->direction);
        $this->assertSame(['C02'], array_keys($manager->alert($id, 'RNCP100', 'BC01')));

        $this->evaluate('sufficient', 'Constat.', 'C02');
        $this->setUser($this->direction);
        $this->assertSame([], $manager->alert($id, 'RNCP100', 'BC01'));
        // A withdrawn evaluation is not sufficient.
        $this->evaluate('withdrawn', 'Erreur.', 'C02');
        $this->setUser($this->direction);
        $this->assertSame(['C02'], array_keys($manager->alert($id, 'RNCP100', 'BC01')));
    }

    public function test_a_competency_gone_from_the_record_is_not_a_lack(): void {
        global $DB;
        $competency = $DB->get_record('competency', ['idnumber' => 'RNCP100-C02'], '*', MUST_EXIST);
        $source = $DB->get_record('tool_vae_source', ['fichecode' => 'RNCP100'], '*', MUST_EXIST);
        $DB->insert_record('tool_vae_obsolete', (object) [
            'competencyid' => $competency->id, 'sourceid' => $source->id, 'detectedat' => time(), 'kind' => 'missing',
        ]);
        $this->evaluate('sufficient', 'Constat.', 'C01');
        $this->setUser($this->direction);

        $this->assertSame([], (new certification_manager())->alert((int) $this->candidate->id, 'RNCP100', 'BC01'));
    }

    public function test_the_reason_of_the_alert_is_required_when_a_competency_lacks_and_refused_when_none_does(): void {
        $id = (int) $this->candidate->id;
        $this->assert_refused(fn() => $this->certify('BC01', 'Motif.', ''), 'errorcertificationalert');
        $this->assert_refused(fn() => $this->certify('BC01', 'Motif.', "   "), 'errorcertificationalert');
        $this->assertSame(0, $this->count_rows('tool_vae_certif'));
        $this->assertSame('Exception motivee.', $this->certify('BC01', 'Motif.', 'Exception motivee.')->alertreason);

        $this->evaluate('sufficient', 'Constat.', 'C03');
        $this->assert_refused(fn() => $this->certify('BC02', 'Motif.', 'Rien ne manque.'), 'errorcertificationalertnone');
        $this->assertNull($this->certify('BC02', 'Motif.', '')->alertreason);
        $this->assertGreaterThan(0, $this->count_rows('tool_vae_certif'));
        $this->assertSame($id, (int) $this->candidate->id);
    }

    public function test_a_withdrawal_of_a_certification_needs_a_standing_one_and_raises_no_alert(): void {
        $this->assert_refused(fn() => $this->certify('BC01', 'Retrait.', '', 'withdrawn'), 'errorpositioningnothing');
        $this->certify('BC01', 'Motif.', 'Exception motivee.');
        $row = $this->certify('BC01', 'Retrait.', '', 'withdrawn');
        $this->assertSame('withdrawn', $row->status);
        $this->assertNull($row->alertreason);
        $this->assert_refused(
            fn() => $this->certify('BC01', 'Retrait.', 'Alerte inutile.', 'withdrawn'),
            'errorpositioningnothing'
        );
        // After a withdrawal the bloc can be certified again: a new line.
        $this->assertSame('certified', $this->certify('BC01', 'Reprise.', 'Exception motivee.')->status);
        $manager = new certification_manager();
        $this->assertSame(3, count($manager->lines((int) $this->candidate->id, 'RNCP100', 'BC01')));
        $this->assertSame('certified', $manager->current((int) $this->candidate->id, 'RNCP100')['BC01']->status);
    }

    public function test_the_alert_reason_belongs_to_a_certification_only(): void {
        $this->certify('BC01', 'Motif.', 'Exception motivee.');

        $this->assert_refused(fn() => $this->certify('BC01', 'Retrait.', 'Alerte.', 'withdrawn'), 'errorcertificationalertnone');
    }

    public function test_the_date_of_the_decision_is_a_day_up_to_today(): void {
        $this->setUser($this->direction);
        $manager = new certification_manager();
        $id = (int) $this->candidate->id;
        $today = userdate(time(), '%Y-%m-%d', 99, false);

        foreach (['2026-13-01', '2026-02-30', '31/10/2026', '', '2026-1-5', '1999-12-31'] as $bad) {
            $this->assert_refused(
                fn() => $manager->write($id, 'RNCP100', 'BC01', 'certified', 'Motif.', $bad, 'Alerte.'),
                'errorcertificationdate'
            );
        }
        $future = userdate(time() + 3 * DAYSECS, '%Y-%m-%d', 99, false);
        $this->assert_refused(
            fn() => $manager->write($id, 'RNCP100', 'BC01', 'certified', 'Motif.', $future, 'Alerte.'),
            'errorcertificationdate'
        );
        $this->assertSame(
            '2026-01-05',
            $manager->write($id, 'RNCP100', 'BC01', 'certified', 'Motif.', '2026-01-05', 'Alerte.')->decisiondate
        );
        $this->assertSame($today, $manager->write($id, 'RNCP100', 'BC02', 'certified', 'Motif.', $today, 'Alerte.')->decisiondate);
    }

    public function test_the_same_certification_is_not_written_twice(): void {
        $this->certify('BC01', 'Motif.', 'Exception motivee.');

        $this->assert_refused(fn() => $this->certify('BC01', 'Motif.', 'Exception motivee.'), 'errorpositioningduplicate');
        $this->assertSame(1, $this->count_rows('tool_vae_certif'));
        // Another reason, another alert reason, another date are other lines.
        $this->certify('BC01', 'Motif change.', 'Exception motivee.');
        $this->certify('BC01', 'Motif change.', 'Autre exception.');
        $this->assertSame(3, $this->count_rows('tool_vae_certif'));
    }

    public function test_the_candidate_and_who_follows_read_the_certifications_and_a_stranger_does_not(): void {
        $this->certify('BC01', 'Motif.', 'Exception motivee.');
        $manager = new certification_manager();
        $id = (int) $this->candidate->id;

        foreach ([$this->candidate, $this->manager, $this->reader, $this->direction] as $user) {
            $this->setUser($user);
            $this->assertCount(1, $manager->lines($id, 'RNCP100', 'BC01'), fullname($user));
            $this->assertArrayHasKey('BC01', $manager->current($id, 'RNCP100'));
        }
        $this->setUser($this->outsider);
        $this->expectException(\moodle_exception::class);
        $manager->lines($id, 'RNCP100', 'BC01');
    }

    public function test_the_two_forms_of_the_cycle_carry_the_reminder_not_to_name_anyone_next_to_each_free_field(): void {
        global $CFG;
        require_once($CFG->libdir . '/formslib.php');
        $reminder = get_string('anonymiseshort', 'tool_vae');

        $evaluation = (new \tool_vae\form\evaluation_form('/x'))->render();
        $this->assertSame(1, substr_count($evaluation, $reminder), 'One free field, one reminder.');

        $certification = (new \tool_vae\form\certification_form('/x'))->render();
        $this->assertSame(2, substr_count($certification, $reminder), 'Two free fields, the reason and the alert, one each.');
    }

    public function test_no_word_of_validation_in_the_new_strings(): void {
        global $CFG;
        $checked = 0;
        foreach (['en', 'fr'] as $lang) {
            // The word "candidate" is the English word of the plugin; "candidat" is never written in French.
            $forbidden = $lang === 'fr' ? '~\bvalid|acquis|candidat|\btaux\b|\bnote\b|\bscore\b~iu' : '~\bvalid|\bscore\b~iu';
            $string = [];
            require($CFG->dirroot . '/admin/tool/vae/lang/' . $lang . '/tool_vae.php');
            foreach ($string as $key => $text) {
                if (
                    !preg_match(
                        '~^(vae:evaluate|vae:certify|eval|certif|eventevaluation|eventcertification'
                            . '|errorpositioning|errorcertification|positioninguntil|positioningvaliduntil|positioningexpired'
                            . '|positioningrenew|positioningtorenew|positioningonold|renewals|privacy:metadata:certif:validuntil)~',
                        $key
                    )
                ) {
                    continue;
                }
                $checked++;
                $this->assertDoesNotMatchRegularExpression($forbidden, $text, $lang . ':' . $key);
            }
        }
        $this->assertGreaterThan(40, $checked, 'The new strings were read.');
    }

    public function test_a_competency_renamed_locally_is_still_in_the_record_and_a_lack(): void {
        global $DB;
        $competency = $DB->get_record('competency', ['idnumber' => 'RNCP100-C02'], '*', MUST_EXIST);
        $source = $DB->get_record('tool_vae_source', ['fichecode' => 'RNCP100'], '*', MUST_EXIST);
        $DB->insert_record('tool_vae_obsolete', (object) [
            'competencyid' => $competency->id, 'sourceid' => $source->id, 'detectedat' => time(), 'kind' => 'renamed',
        ]);
        $this->evaluate('sufficient', 'Constat.', 'C01');
        $this->setUser($this->direction);

        $this->assertSame(['C02'], array_keys((new certification_manager())->alert((int) $this->candidate->id, 'RNCP100', 'BC01')));
    }

    /**
     * Certify with a validity date, as the direction.
     *
     * @param string $validuntil
     * @param string $rank
     * @param string $reason
     * @param string $alert
     * @param string $decision
     * @return \stdClass
     */
    private function certify_until(
        string $validuntil,
        string $rank = 'BC01',
        string $reason = 'La structure se positionne.',
        string $alert = 'Exception motivee.',
        string $decision = '2026-11-04'
    ): \stdClass {
        $this->setUser($this->direction);

        return (new certification_manager())->write(
            (int) $this->candidate->id,
            'RNCP100',
            $rank,
            'certified',
            $reason,
            $decision,
            $alert,
            $validuntil
        );
    }

    public function test_a_certification_without_a_date_keeps_the_fingerprint_it_always_had_and_stores_null(): void {
        global $DB;
        $row = $this->certify_until('', 'BC01', 'La structure se positionne.', 'Exception motivee.', '2026-11-04');

        $this->assertNull($row->validuntil);
        $this->assertNull($DB->get_field('tool_vae_certif', 'validuntil', ['id' => $row->id]));
        // The fingerprint of a line with no validity date is the one computed before the date existed.
        $this->assertSame(
            hash(
                'sha256',
                implode("\x1f", ['certified', 'RNCP100', 'BC01', 'La structure se positionne.', '2026-11-04', 'Exception motivee.'])
            ),
            $row->reasonhash
        );
        $this->assertSame(
            '7c32b63b75d6cae8b06ac54c7c67e372f29300db78f6e8c19f3ede06b7e1923e',
            \tool_vae\local\positioning_rules::hash(
                ['certified', 'RNCP100', 'BC01', 'La structure se positionne.', '2026-11-04', '']
            )
        );
    }

    public function test_a_validity_date_is_stored_hashed_and_makes_another_line(): void {
        $first = $this->certify_until('', 'BC01');
        $second = $this->certify_until('2027-11-04', 'BC01');

        $this->assertSame('2027-11-04', $second->validuntil);
        $this->assertNotSame($first->reasonhash, $second->reasonhash, 'The date is part of what the line says.');
        $this->assertSame(
            hash('sha256', implode("\x1f", [
                'certified', 'RNCP100', 'BC01', 'La structure se positionne.', '2026-11-04', 'Exception motivee.', '2027-11-04',
            ])),
            $second->reasonhash
        );
        $this->assert_refused(fn() => $this->certify_until('2027-11-04', 'BC01'), 'errorpositioningduplicate');
        $this->assertSame(2, $this->count_rows('tool_vae_certif'));
    }

    public function test_the_validity_date_is_a_real_day_from_the_decision_to_the_year_2100(): void {
        foreach (['2026-13-01', '2027-02-30', '04/11/2027', '2027-11-04T10:00:00Z', '2027-1-5'] as $bad) {
            $this->assert_refused(fn() => $this->certify_until($bad), 'errorcertificationvaliduntil');
        }
        // Before the day of the decision.
        $this->assert_refused(fn() => $this->certify_until('2026-11-03'), 'errorcertificationvaliduntil');
        // After the last day allowed.
        $this->assert_refused(fn() => $this->certify_until('2100-01-02'), 'errorcertificationvaliduntil');
        $this->assertSame(0, $this->count_rows('tool_vae_certif'));

        // The bounds are included: the day of the decision, and the first day of 2100.
        $this->assertSame('2026-11-04', $this->certify_until('2026-11-04', 'BC01', 'Un.')->validuntil);
        $this->assertSame('2100-01-01', $this->certify_until('2100-01-01', 'BC01', 'Deux.')->validuntil);
        // The past is allowed: a certification written for a decision long ago can be due already.
        $row = $this->certify_until('2020-06-30', 'BC01', 'Trois.', 'Exception motivee.', '2020-01-15');
        $this->assertSame('2020-06-30', $row->validuntil);
    }

    public function test_a_withdrawal_carries_no_validity_date(): void {
        $this->certify_until('2027-11-04', 'BC01');
        $this->setUser($this->direction);

        $this->assert_refused(
            fn() => (new certification_manager())->write(
                (int) $this->candidate->id,
                'RNCP100',
                'BC01',
                'withdrawn',
                'Retrait.',
                '2026-11-04',
                '',
                '2027-11-04'
            ),
            'errorcertificationvaliduntil'
        );
    }

    public function test_a_certification_is_due_the_day_after_its_date_and_not_before(): void {
        $standing = $this->certify_until('2026-11-10', 'BC01');

        $this->assertFalse(certification_manager::is_expired($standing, '2026-11-09'), 'The day before.');
        $this->assertFalse(
            certification_manager::is_expired($standing, '2026-11-10'),
            'The day itself: still valid until the end of it.'
        );
        $this->assertTrue(certification_manager::is_expired($standing, '2026-11-11'), 'The day after.');
        $this->assertFalse(certification_manager::is_expired(null, '2026-11-11'));
        $undated = $this->certify_until('', 'BC02', 'Sans date.', 'Exception motivee.');
        $this->assertFalse(certification_manager::is_expired($undated, '2099-01-01'), 'No date, no expiry.');

        $withdrawn = clone $standing;
        $withdrawn->status = 'withdrawn';
        $this->assertFalse(certification_manager::is_expired($withdrawn, '2026-11-11'), 'A withdrawal is not due.');
        $empty = clone $standing;
        $empty->validuntil = '';
        $this->assertFalse(certification_manager::is_expired($empty, '2026-11-11'), 'An empty string is no date.');
    }

    public function test_the_days_since_the_due_date_are_counted_on_dates_not_on_seconds(): void {
        $this->assertSame(1, certification_manager::days_overdue('2026-11-10', '2026-11-11'));
        $this->assertSame(0, certification_manager::days_overdue('2026-11-10', '2026-11-10'));
        $this->assertSame(0, certification_manager::days_overdue('2026-11-10', '2026-11-01'));
        // Across the change to winter time (25 hours in that day): still one day.
        $this->assertSame(1, certification_manager::days_overdue('2026-10-24', '2026-10-25'));
        $this->assertSame(366, certification_manager::days_overdue('2026-01-01', '2027-01-02'));
    }

    public function test_the_due_day_is_read_in_the_time_zone_of_the_reader(): void {
        global $USER;
        $now = 1792000000;
        $this->setUser($this->direction);
        $USER->timezone = 'Pacific/Kiritimati';
        $early = certification_manager::today($now);
        $USER->timezone = 'Pacific/Pago_Pago';
        $late = certification_manager::today($now);

        // 17:46 UTC on 14 October 2026: already the 15th at UTC+14, still the 14th at UTC-11.
        $this->assertSame('2026-10-15', $early);
        $this->assertSame('2026-10-14', $late);
    }

    /**
     * The section of the dossier of the candidate, as the current user reads it.
     *
     * @return string HTML.
     */
    private function section(): string {
        return \tool_vae\local\positioning_screen::section(
            (int) $this->candidate->id,
            \context_user::instance($this->candidate->id)
        );
    }

    public function test_a_certification_says_until_when_it_stands_and_one_with_no_date_says_nothing_of_it(): void {
        $dated = $this->certify_until('2027-11-04', 'BC01', 'Un.', 'Exception motivee.', '2026-11-04');
        $undated = $this->certify_until('', 'BC02', 'Deux.', 'Exception motivee.', '2026-11-04');
        $context = \context_user::instance($this->candidate->id);

        $with = \tool_vae\local\positioning_screen::line($dated, $context, 'certif');
        $without = \tool_vae\local\positioning_screen::line($undated, $context, 'certif');

        $this->assertStringContainsString(s(get_string('positioninguntil', 'tool_vae', '2027-11-04')), $with);
        $this->assertStringNotContainsString('stands until', $without);
    }

    public function test_an_expired_certification_is_told_with_its_days_and_only_the_direction_gets_the_renewal_link(): void {
        $this->certify_until('2020-06-30', 'BC01', 'Trois.', 'Exception motivee.', '2020-01-15');
        $days = certification_manager::days_overdue('2020-06-30', certification_manager::today());
        $text = s(get_string('positioningexpired', 'tool_vae', (object) ['until' => '2020-06-30', 'days' => $days]));
        $renew = get_string('positioningrenewlink', 'tool_vae');

        $this->setUser($this->direction);
        $direction = $this->section();
        $this->assertStringContainsString($text, $direction);
        $this->assertStringContainsString(get_string('positioningtorenew', 'tool_vae'), $direction);
        $this->assertSame(1, substr_count($direction, $renew), 'The expired bloc only.');

        foreach ([$this->reader, $this->candidate] as $reader) {
            $this->setUser($reader);
            $html = $this->section();
            $this->assertStringContainsString($text, $html, 'They read the same words.');
            $this->assertStringNotContainsString($renew, $html, 'They do not certify.');
        }
    }

    public function test_a_certification_that_has_not_expired_or_has_no_date_or_was_withdrawn_is_not_to_renew(): void {
        $this->certify_until('2099-01-01', 'BC01', 'Un.', 'Exception motivee.', '2026-11-04');
        $this->certify_until('', 'BC02', 'Deux.', 'Exception motivee.', '2026-11-04');
        $this->setUser($this->direction);
        $html = $this->section();
        $this->assertStringNotContainsString(get_string('positioningtorenew', 'tool_vae'), $html);
        $this->assertStringNotContainsString(get_string('positioningrenewlink', 'tool_vae'), $html);

        $this->certify_until('2020-06-30', 'BC02', 'Trois.', 'Exception motivee.', '2020-01-15');
        $this->assertStringContainsString(get_string('positioningtorenew', 'tool_vae'), $this->section());
        (new certification_manager())->write((int) $this->candidate->id, 'RNCP100', 'BC02', 'withdrawn', 'Retire.', '2026-11-04');
        $this->assertStringNotContainsString(get_string('positioningtorenew', 'tool_vae'), $this->section());
    }

    public function test_nobody_is_offered_to_renew_their_own_certification(): void {
        // The direction is also a candidate: the certification of their own bloc is read, not renewed.
        global $DB;
        $DB->insert_record('tool_vae_certif', (object) [
            'uuid' => \tool_vae\local\identity_map::generate_ulid(), 'candidateid' => $this->direction->id,
            'fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'status' => 'certified', 'reason' => 'Motif.',
            'reasonhash' => hash('sha256', 'mine'), 'decisiondate' => '2020-01-15', 'validuntil' => '2020-06-30',
            'timecreated' => 1762000000,
        ]);
        $this->setUser($this->direction);

        $html = \tool_vae\local\positioning_screen::section(
            (int) $this->direction->id,
            \context_user::instance($this->direction->id)
        );

        $this->assertStringContainsString(get_string('positioningtorenew', 'tool_vae'), $html, 'The date is told.');
        $this->assertStringNotContainsString(get_string('positioningrenewlink', 'tool_vae'), $html);
        $this->assertStringNotContainsString(get_string('positioningcertifylink', 'tool_vae'), $html);
    }

    public function test_a_day_followed_by_a_line_break_is_not_a_day(): void {
        $this->assertTrue(\tool_vae\local\positioning_rules::is_day('2026-11-03'));
        $this->assertFalse(\tool_vae\local\positioning_rules::is_day("2026-11-03\n"));
        $this->setUser($this->direction);
        $this->assert_refused(
            fn() => (new certification_manager())->write(
                (int) $this->candidate->id,
                'RNCP100',
                'BC01',
                'certified',
                'Motif.',
                "2026-11-03\n",
                'Exception motivee.'
            ),
            'errorcertificationdate'
        );
    }
}
