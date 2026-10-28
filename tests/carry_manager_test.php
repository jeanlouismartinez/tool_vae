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

use core_privacy\local\request\approved_contextlist;
use tool_vae\event\evidence_carried;
use tool_vae\fixtures\failing_gateway;
use tool_vae\local\carry_manager;
use tool_vae\local\competency_gateway;
use tool_vae\local\evidence_manager;
use tool_vae\local\passage_manager;
use tool_vae\local\purge;
use tool_vae\local\supersession;
use tool_vae\privacy\provider;

/**
 * Carrying a declaration over to the record that replaces its own (T-30, lot 3, D-14).
 *
 * WHAT IS ASSERTED. That the candidate carries, on a correspondence an administrator decided, and nobody else; that
 * the carry is a COPY: the old declaration and its attestation stay exactly as they were, the new declaration is an
 * ordinary one with no attestation; that the trace keeps a copy of the decision; that a correspondence gone stale
 * (the successor changed) is refused at the moment of the carry; that a failure leaves nothing behind; that a
 * declaration is carried once; that the candidate reads "the site administrator" and not a name; that erasures and
 * purges reach the new table.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\carry_manager::class)]
final class carry_manager_test extends \advanced_testcase {
    /** @var \stdClass[] Competencies of the old record (RNCP100), by rank. */
    private $old = [];

    /** @var \stdClass[] Competencies of the new record (RNCP200), by rank. */
    private $new = [];

    /** @var \stdClass[] Competencies of an unrelated record (RNCP300), by rank. */
    private $other = [];

    /** @var \stdClass The candidate, who may declare. */
    private $candidate;

    /** @var int Publication date of the new record. */
    private $newupdated;

    /**
     * An old record replaced by a new one and an unrelated one, with their competencies, and a candidate.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/formslib.php');
        require_once(__DIR__ . '/fixtures/failing_gateway.php');
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $lpg = $generator->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $now = time();
        $this->newupdated = $now - 86400;
        $plan = [
            'RNCP100' => ['old', $now - 864000, [
                'C01' => 'Accompagner la personne',
                'C02' => 'Assurer les soins',
                'C03' => 'Travailler en equipe',
            ]],
            'RNCP200' => ['new', $this->newupdated, [
                'C01' => 'Accompagner la personne',
                'C05' => 'Assurer les soins',
            ]],
            'RNCP300' => ['other', $now, ['C01' => 'Autre chose']],
        ];
        foreach ($plan as $code => [$property, $updated, $competencies]) {
            $framework = $lpg->create_framework(['idnumber' => $code, 'shortname' => "Fiche $code"]);
            $DB->insert_record('tool_vae_source', (object) [
                'frameworkid' => (int) $framework->get('id'),
                'fichecode' => $code,
                'fichetype' => 'rncp',
                'sourceupdated' => $updated,
                'licence' => 'Licence Ouverte 2.0',
                'attribution' => 'France competences',
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => (int) get_admin()->id,
            ]);
            foreach ($competencies as $rank => $label) {
                $this->{$property}[$rank] = $lpg->create_competency([
                    'competencyframeworkid' => $framework->get('id'),
                    'idnumber' => "$code-$rank",
                    'shortname' => $label,
                ]);
            }
        }
        supersession::declare_successor('RNCP100', 'RNCP200');

        $this->candidate = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * An old declaration of the candidate on a competency of the old record.
     *
     * @param string $rank
     * @return \stdClass The row of tool_vae_evidence.
     */
    private function declare_old(string $rank = 'C01'): \stdClass {
        $this->setUser($this->candidate);

        return (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $this->old[$rank]->get('id'),
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'settingdetail' => 'Unite protegee',
            'situation' => 'Accompagnement au lever et a la toilette, en binome avec une aide-soignante.',
            'situationformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * The administrator decides an equivalent, then the candidate is the connected user again.
     *
     * @param string $fromrank
     * @param string $torank
     * @param string $note
     */
    private function decide(string $fromrank = 'C01', string $torank = 'C01', string $note = 'Meme champ.'): void {
        $this->setAdminUser();
        (new passage_manager())->decide(
            (int) $this->old[$fromrank]->get('id'),
            (int) $this->new[$torank]->get('id'),
            $note
        );
        $this->setUser($this->candidate);
    }

    /**
     * An attestation in force on a declaration, written as the table holds it.
     *
     * @param \stdClass $evidence
     * @return int The attestation row.
     */
    private function attest(\stdClass $evidence): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => local\identity_map::generate_ulid(),
            'evidenceid' => (int) $evidence->id,
            'attestantid' => (int) get_admin()->id,
            'attestantrole' => 'Cadre de sante',
            'statement' => 'J\'atteste.',
            'attestedat' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int) get_admin()->id,
        ]);
    }

    /**
     * What a call must refuse with.
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

    /**
     * What the candidate confirms in the form.
     *
     * @param array $overrides
     * @return \stdClass
     */
    private function confirmed(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'settingdetail' => 'Unite protegee',
            'situation' => 'Accompagnement au lever, relu et precise pour la nouvelle fiche.',
        ]);
    }

    public function test_a_carry_makes_the_declaration_again_on_the_new_record_and_leaves_the_old_one_alone(): void {
        global $DB;
        $old = $this->declare_old();
        $attestationid = $this->attest($old);
        $this->decide();
        $before = $DB->get_record('tool_vae_evidence', ['id' => $old->id]);
        $beforeattest = $DB->get_record('tool_vae_attest', ['id' => $attestationid]);

        $row = (new carry_manager())->carry((int) $old->id, $this->confirmed());

        $new = $DB->get_record('tool_vae_evidence', ['id' => $row->newevidenceid], '*', MUST_EXIST);
        $this->assertSame('RNCP200', $new->fichecode);
        $this->assertSame('C01', $new->ficherang);
        $this->assertSame((int) $this->new['C01']->get('id'), (int) $new->competencyid);
        $this->assertSame($this->newupdated, (int) $new->ficheupdated);
        $this->assertSame('Accompagnement au lever, relu et precise pour la nouvelle fiche.', $new->situation);
        $this->assertSame((int) $this->candidate->id, (int) $new->candidateid);
        $this->assertNotSame((int) $old->userevidenceid, (int) $new->userevidenceid, 'A new anchor in the core.');

        // The old declaration and its attestation are exactly as they were.
        $this->assertEquals($before, $DB->get_record('tool_vae_evidence', ['id' => $old->id]));
        $this->assertEquals($beforeattest, $DB->get_record('tool_vae_attest', ['id' => $attestationid]));
        $this->assertNull(local\attestation_manager::active_attestation((int) $new->id), 'To attest again.');
        $this->assertNotNull(local\attestation_manager::active_attestation((int) $old->id));
        $this->assertSame(2, $DB->count_records('tool_vae_evidence', ['candidateid' => $this->candidate->id]));
    }

    public function test_the_trace_keeps_a_copy_of_the_decision_even_when_the_decision_is_taken_back(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide('C01', 'C01', 'Meme champ.');

        $row = (new carry_manager())->carry((int) $old->id, $this->confirmed());

        $this->assertSame((int) $old->id, (int) $row->oldevidenceid);
        $this->assertSame((int) get_admin()->id, (int) $row->decidedby);
        $this->assertSame('Meme champ.', $row->note);
        $this->assertEqualsWithDelta(time(), (int) $row->decidedat, 5);
        $this->assertEqualsWithDelta(time(), (int) $row->carriedat, 5);

        $this->setAdminUser();
        (new passage_manager())->undo((int) $this->old['C01']->get('id'));
        $stored = $DB->get_record('tool_vae_carry', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertSame((int) get_admin()->id, (int) $stored->decidedby);
        $this->assertSame('Meme champ.', $stored->note);
        $this->assertSame((int) $this->old['C01']->get('id'), (int) $stored->fromcompetencyid);
        $this->assertSame((int) $this->new['C01']->get('id'), (int) $stored->tocompetencyid);
    }

    public function test_nothing_is_offered_without_a_decided_correspondence(): void {
        $old = $this->declare_old('C03');
        $manager = new carry_manager();

        $this->assertNull($manager->offer($old));
        $this->assert_refused(function () use ($manager, $old) {
            $manager->carry((int) $old->id, $this->confirmed());
        }, 'errorcarrynopassage');

        $this->decide('C03', 'C05');
        $offer = $manager->offer($old);
        $this->assertSame((int) $this->new['C05']->get('id'), (int) $offer->target->id);
        $this->assertSame('RNCP200', $offer->successor->fichecode);
    }

    public function test_nothing_is_offered_when_the_record_has_no_successor(): void {
        $old = $this->declare_old();
        $this->decide();
        $this->setAdminUser();
        supersession::clear_successor('RNCP100');
        $this->setUser($this->candidate);

        $this->assertNull((new carry_manager())->offer($old));
    }

    public function test_a_declaration_is_carried_once(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide();
        $manager = new carry_manager();
        $manager->carry((int) $old->id, $this->confirmed());

        $this->assertNull($manager->offer($old));
        $this->assert_refused(function () use ($manager, $old) {
            $manager->carry((int) $old->id, $this->confirmed());
        }, 'errorcarrydone');
        $this->assertSame(2, $DB->count_records('tool_vae_evidence'));
        $this->assertSame(1, $DB->count_records('tool_vae_carry'));
    }

    public function test_two_declarations_on_the_same_competency_are_carried_separately(): void {
        global $DB;
        $first = $this->declare_old();
        $second = $this->declare_old();
        $this->decide();
        $manager = new carry_manager();

        $manager->carry((int) $first->id, $this->confirmed());
        $this->assertNotNull($manager->offer($second));
        $manager->carry((int) $second->id, $this->confirmed());

        $this->assertSame(2, $DB->count_records('tool_vae_carry'));
        $this->assertSame(4, $DB->count_records('tool_vae_evidence'));
    }

    public function test_only_the_candidate_carries_and_only_with_the_right_to_declare(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide();
        $manager = new carry_manager();

        // The administrator, a colleague, nobody.
        foreach ([get_admin(), $this->getDataGenerator()->create_user()] as $other) {
            $this->setUser($other);
            $this->assertNull($manager->offer($old));
            $this->assert_refused(function () use ($manager, $old) {
                $manager->carry((int) $old->id, $this->confirmed());
            }, 'errorcarrydenied');
        }
        $this->setUser(0);
        $this->assertNull($manager->offer($old));

        // The candidate whose right was withdrawn.
        $this->setUser($this->candidate);
        $this->assertNotNull($manager->offer($old));
        unassign_capability('tool/vae:declare', $this->get_role_with_declare());
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertNull($manager->offer($old));
        $this->assert_refused(function () use ($manager, $old) {
            $manager->carry((int) $old->id, $this->confirmed());
        }, 'errorcarrydenied');

        $this->assertSame(0, $DB->count_records('tool_vae_carry'));
        $this->assert_refused(function () use ($manager) {
            $manager->carry(999999, $this->confirmed());
        }, 'errorcarrydenied');
    }

    /**
     * The role this test gave the candidate.
     *
     * @return int
     */
    private function get_role_with_declare(): int {
        global $DB;

        return (int) $DB->get_field_sql(
            "SELECT roleid FROM {role_capabilities} WHERE capability = :cap AND contextid = :ctx AND permission = :perm",
            ['cap' => 'tool/vae:declare', 'ctx' => \context_system::instance()->id, 'perm' => CAP_ALLOW],
            IGNORE_MULTIPLE
        );
    }

    public function test_a_correspondence_that_no_longer_points_into_the_successor_is_refused(): void {
        $old = $this->declare_old();
        $this->decide();
        // A replaced by C instead of B: the decision still points into B.
        $this->setAdminUser();
        supersession::declare_successor('RNCP100', 'RNCP300');
        $this->setUser($this->candidate);

        $manager = new carry_manager();
        $this->assertNull($manager->offer($old));
        $this->assert_refused(function () use ($manager, $old) {
            $manager->carry((int) $old->id, $this->confirmed());
        }, 'errorcarrystale');
    }

    /**
     * The gesture of an administrator in the competency frameworks screen, "Visible: No", by the table.
     *
     * @param string $code The record whose framework is hidden.
     * @param int $visible 0 to hide, 1 to show again.
     */
    private function set_visible(string $code, int $visible = 0): void {
        global $DB;

        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => $code], MUST_EXIST);
        $DB->set_field('competency_framework', 'visible', $visible, ['id' => $frameworkid]);
    }

    /**
     * Nothing is offered towards a record hidden in Moodle, and it is offered again when the record is visible.
     *
     * Decision of the project owner, 27 October 2026. Before: the button was drawn on the dossier, the vaeiste filled the form,
     * and was sent back to the dossier with the message written for the administrator, his text lost.
     */
    public function test_nothing_is_offered_towards_a_hidden_record(): void {
        $old = $this->declare_old();
        $this->decide();
        $manager = new carry_manager();
        $this->assertNotNull($manager->offer($old), 'Offered while the new record is visible.');

        $this->set_visible('RNCP200');
        $this->assertNull($manager->offer($old));
        $this->assert_refused(function () use ($manager, $old) {
            $manager->require_offer($old);
        }, 'errorframeworkhidden');

        $this->set_visible('RNCP200', 1);
        $offer = $manager->offer($old);
        $this->assertNotNull($offer);
        $this->assertSame('RNCP200', $offer->successor->fichecode);
    }

    /**
     * A page opened before the record was hidden can still read the offer, to give the text back; nothing else is let through.
     */
    public function test_the_page_can_read_an_offer_towards_a_hidden_record_and_nothing_else_is_let_through(): void {
        $old = $this->declare_old();
        $manager = new carry_manager();

        // No correspondence decided yet: refused whatever is allowed.
        $this->assert_refused(function () use ($manager, $old) {
            $manager->require_offer($old, true);
        }, 'errorcarrynopassage');

        $this->decide();
        $this->assertFalse($manager->require_offer($old, true)->hidden);

        $this->set_visible('RNCP200');
        $offer = $manager->require_offer($old, true);
        $this->assertTrue($offer->hidden);
        $this->assertSame((int) $this->new['C01']->get('id'), (int) $offer->target->id);
        $this->assertSame('RNCP200', $offer->successor->fichecode);
    }

    /**
     * A carry sent after the record was hidden is refused in words for the vaeiste, before anything is written.
     */
    public function test_a_carry_towards_a_hidden_record_is_refused_for_the_vaeiste_and_writes_nothing(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide();
        $cores = $DB->count_records('competency_userevidence');
        $DB->set_field('competency_framework', 'shortname', 'Nouvelle fiche des soins', [
            'id' => $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP200'], MUST_EXIST),
        ]);
        $this->set_visible('RNCP200');

        try {
            (new carry_manager())->carry((int) $old->id, $this->confirmed());
            $this->fail('A carry towards a hidden record was made.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorframeworkhidden', $e->errorcode);
            $this->assertSame(
                get_string('errorframeworkhidden', 'tool_vae', 'Nouvelle fiche des soins (RNCP200)'),
                $e->getMessage()
            );
        }

        $this->assertSame(0, $DB->count_records('tool_vae_carry'));
        $this->assertSame(1, $DB->count_records('tool_vae_evidence'));
        $this->assertSame($cores, $DB->count_records('competency_userevidence'), 'No orphan in the core.');

        // The record is visible again: the same carry goes through, from the same declaration.
        $this->set_visible('RNCP200', 1);
        $row = (new carry_manager())->carry((int) $old->id, $this->confirmed());
        $this->assertSame(1, $DB->count_records('tool_vae_carry'));
        $this->assertSame(2, $DB->count_records('tool_vae_evidence'));
        $this->assertNotEmpty($row->newevidenceid);
    }

    public function test_the_competency_is_the_one_decided_whatever_the_form_says(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide('C01', 'C01');

        $row = (new carry_manager())->carry((int) $old->id, $this->confirmed([
            'competencyid' => (int) $this->other['C01']->get('id'),
            'situationformat' => FORMAT_HTML,
        ]));

        $new = $DB->get_record('tool_vae_evidence', ['id' => $row->newevidenceid], '*', MUST_EXIST);
        $this->assertSame((int) $this->new['C01']->get('id'), (int) $new->competencyid);
        $this->assertSame(FORMAT_PLAIN, (string) $new->situationformat);
    }

    public function test_a_declaration_that_does_not_pass_the_usual_checks_is_not_carried(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide();
        $manager = new carry_manager();
        $cores = $DB->count_records('competency_userevidence');

        $this->assert_refused(function () use ($manager, $old) {
            $manager->carry((int) $old->id, $this->confirmed(['situation' => '   ']));
        }, 'errorsituationempty');
        $this->assert_refused(function () use ($manager, $old) {
            $manager->carry((int) $old->id, $this->confirmed(['periodend' => 1000]));
        }, 'errorperiodreversed');

        $this->assertSame(0, $DB->count_records('tool_vae_carry'));
        $this->assertSame(1, $DB->count_records('tool_vae_evidence'));
        $this->assertSame($cores, $DB->count_records('competency_userevidence'));
    }

    public function test_a_failure_after_the_core_write_leaves_nothing_behind(): void {
        global $DB;
        $this->preventResetByRollback();
        $old = $this->declare_old();
        $this->decide();
        $cores = $DB->count_records('competency_userevidence');

        try {
            (new carry_manager(new failing_gateway()))->carry((int) $old->id, $this->confirmed());
            $this->fail('Expected the deliberate failure.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error', $e->errorcode, 'The deliberate failure, and no earlier refusal.');
        }

        $this->assertSame(0, $DB->count_records('tool_vae_carry'));
        $this->assertSame(1, $DB->count_records('tool_vae_evidence'));
        $this->assertSame($cores, $DB->count_records('competency_userevidence'), 'No orphan in the core.');
        $this->assertNotNull((new carry_manager())->offer($old), 'It can be tried again.');
    }

    public function test_the_log_carries_identifiers_only(): void {
        $old = $this->declare_old();
        $this->decide();
        $sink = $this->redirectEvents();

        $row = (new carry_manager())->carry((int) $old->id, $this->confirmed());

        $carried = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof evidence_carried));
        $this->assertCount(1, $carried);
        $event = $carried[0];
        $this->assertSame((int) $this->candidate->id, (int) $event->relateduserid);
        $this->assertSame((int) $old->id, $event->other['oldevidenceid']);
        $this->assertSame((int) $row->newevidenceid, $event->other['newevidenceid']);
        $this->assertStringNotContainsString('relu et precise', json_encode($event->get_data()));
        $this->assertStringNotContainsString('Meme champ', json_encode($event->get_data()));
    }

    public function test_the_form_starts_from_the_old_declaration(): void {
        $old = $this->declare_old();

        $defaults = carry_manager::defaults($old);

        $this->assertSame($old->situation, $defaults->situation);
        $this->assertSame((int) $old->periodstart, $defaults->periodstart);
        $this->assertSame('ehpad', $defaults->settingtype);
        $this->assertSame('Unite protegee', $defaults->settingdetail);
    }

    public function test_the_notes_name_the_administrator_to_nobody_but_those_who_may_import(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide();
        $manager = new carry_manager();
        $row = $manager->carry((int) $old->id, $this->confirmed());
        $new = $DB->get_record('tool_vae_evidence', ['id' => $row->newevidenceid], '*', MUST_EXIST);

        // The candidate reads a record and a date, no name.
        $fromold = $manager->notes($old);
        $this->assertNull($fromold->carriedfrom);
        $this->assertSame('RNCP200-C01', $fromold->carriedto->record);
        $this->assertSame('', $fromold->carriedto->decidername);
        $fromnew = $manager->notes($new);
        $this->assertNull($fromnew->carriedto);
        $this->assertSame('RNCP100-C01', $fromnew->carriedfrom->record);
        $this->assertSame('', $fromnew->carriedfrom->decidername);

        // Whoever may import reads the name.
        $this->setAdminUser();
        $this->assertSame(fullname(get_admin()), $manager->notes($old)->carriedto->decidername);
    }

    public function test_the_overview_of_the_replaced_records_counts_the_carries(): void {
        $first = $this->declare_old();
        $this->declare_old();
        $this->decide();
        (new carry_manager())->carry((int) $first->id, $this->confirmed());
        $this->setAdminUser();

        $rows = supersession::overview();

        $this->assertSame(2, $rows['RNCP100']->declarations);
        $this->assertSame(1, $rows['RNCP100']->carried);
        $this->assertSame(0, $rows['RNCP200']->carried);
    }

    public function test_purging_the_new_declaration_removes_the_trace_and_the_old_one_can_be_carried_again(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide();
        $manager = new carry_manager();
        $row = $manager->carry((int) $old->id, $this->confirmed());
        $new = $DB->get_record('tool_vae_evidence', ['id' => $row->newevidenceid], '*', MUST_EXIST);

        purge::by_userevidence((int) $new->userevidenceid);

        $this->assertSame(0, $DB->count_records('tool_vae_carry'));
        $this->assertSame(1, $DB->count_records('tool_vae_evidence'));
        $this->assertNotNull($manager->offer($old));
    }

    public function test_a_declaration_cannot_be_carried_twice_even_past_the_check(): void {
        global $DB;
        // A refused statement dooms the transaction a test runs in, on PostgreSQL.
        $this->preventResetByRollback();
        $old = $this->declare_old();
        $this->decide();
        $row = (new carry_manager())->carry((int) $old->id, $this->confirmed());

        // The second line of defence: the key, whatever the code checked before.
        $copy = clone $row;
        unset($copy->id);
        $copy->newevidenceid = (int) $row->newevidenceid + 1000;
        try {
            $DB->insert_record('tool_vae_carry', $copy);
            $this->fail('A second carry of the same declaration was accepted by the table.');
        } catch (\dml_write_exception $e) {
            $this->assertSame(1, $DB->count_records('tool_vae_carry'));
        }
    }

    public function test_purging_the_old_declaration_alone_removes_the_trace(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide();
        (new carry_manager())->carry((int) $old->id, $this->confirmed());

        purge::by_userevidence((int) $old->userevidenceid);

        $this->assertSame(0, $DB->count_records('tool_vae_carry'));
        $this->assertSame(1, $DB->count_records('tool_vae_evidence'), 'The new declaration stays.');
    }

    public function test_erasing_the_candidate_removes_the_traces(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide();
        (new carry_manager())->carry((int) $old->id, $this->confirmed());

        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($this->candidate->id),
            'tool_vae',
            [\context_user::instance($this->candidate->id)->id]
        ));

        $this->assertSame(0, $DB->count_records('tool_vae_carry'));
        $this->assertSame(0, $DB->count_records('tool_vae_evidence'));
    }

    public function test_erasing_the_administrator_removes_their_name_from_the_traces_and_keeps_the_traces(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide();
        $row = (new carry_manager())->carry((int) $old->id, $this->confirmed());
        $admin = get_admin();

        $this->assertTrue(provider::get_contexts_for_userid((int) $admin->id)->count() > 0);
        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($admin->id),
            'tool_vae',
            [\context_system::instance()->id]
        ));

        $stored = $DB->get_record('tool_vae_carry', ['id' => $row->id], '*', MUST_EXIST);
        $this->assertSame(0, (int) $stored->decidedby);
        $this->assertSame(0, (int) $stored->decidedat);
        $this->assertSame('', (string) $stored->note, 'The note names people and it was declared as such.');
        $this->assertSame((int) $old->id, (int) $stored->oldevidenceid, 'The trace stays.');
    }

    public function test_the_candidate_export_says_where_a_declaration_was_carried_to_and_from(): void {
        global $DB;
        $old = $this->declare_old();
        $this->decide();
        $row = (new carry_manager())->carry((int) $old->id, $this->confirmed());
        $new = $DB->get_record('tool_vae_evidence', ['id' => $row->newevidenceid], '*', MUST_EXIST);

        $context = \context_user::instance($this->candidate->id);
        provider::export_user_data(new approved_contextlist(
            \core_user::get_user($this->candidate->id),
            'tool_vae',
            [$context->id]
        ));

        $data = \core_privacy\local\request\writer::with_context($context)->get_data([get_string('pluginname', 'tool_vae')]);
        $byref = [];
        foreach ($data->claims as $claim) {
            $byref[$claim['reference']] = $claim;
        }
        $this->assertSame($new->uuid, $byref[$old->uuid]['carriedto']);
        $this->assertNull($byref[$old->uuid]['carriedfrom']);
        $this->assertSame($old->uuid, $byref[$new->uuid]['carriedfrom']);
    }
}
