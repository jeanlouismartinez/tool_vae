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

use tool_vae\local\attestation_manager;
use tool_vae\local\backup;
use tool_vae\local\competency_gateway;
use tool_vae\local\evidence_manager;
use tool_vae\local\identity_map;

/**
 * Acceptance criterion 1 bis - the attestation is a row of this plugin, and withdrawing it
 * leaves the candidate's evidence intact.
 *
 * HOW "NO CORE CALL" IS TESTED HERE, AND WHAT THAT PROOF IS WORTH. Reading the class and
 * seeing no core name in it proves nothing about what runs; this plugin already shipped one
 * control that looked at text and found the word it was hunting inside a comment explaining
 * its own absence. So the test photographs the four core tables a declaration writes to,
 * before and after, and compares them. That catches any write reaching those tables by any
 * route. It does NOT catch a read-only core call, nor a write to a table outside the four -
 * files attached to a piece of evidence, plans, per-course competencies. The claim this test
 * supports is "the attestation writes nothing where a declaration writes", which is the part
 * criterion 1 bis turns on, and not the wider "no core code ran at all".
 *
 * TWO CANDIDATES, ON PURPOSE. With a single candidate, a capability check written against
 * the CURRENT USER'S context instead of the candidate's - the exact defect the header of
 * db/access.php describes as turning a page into a data leak - passes every test. The
 * second candidate is what makes the check falsifiable.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\attestation_manager::class)]
final class attestation_manager_test extends \advanced_testcase {
    /** @var \stdClass The candidate whose dossier the manager may sign. */
    private $candidate;

    /** @var \stdClass A second candidate, whose dossier the manager may NOT sign. */
    private $stranger;

    /** @var \stdClass The manager who attests. */
    private $attestant;

    /** @var \stdClass The declaration being attested. */
    private $claim;

    /** @var \stdClass A declaration on the second candidate's dossier. */
    private $strangerclaim;

    /** @var int The competency both candidates claim. */
    private $competencyid;

    /**
     * Core tables the competency subsystem writes when a declaration is made.
     *
     * @var string[]
     */
    private const CORE_TABLES = [
        'competency_userevidence',
        'competency_userevidencecomp',
        'competency_usercomp',
        'competency_evidence',
    ];

    /**
     * Two candidates with one declaration each, and a manager entitled to sign only one.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        $this->resetAfterTest();

        // The code under test opens delegated transactions and rolls one back on refusal.
        // advanced_testcase wraps each test in a transaction of its own, and Moodle only
        // undoes the outermost level: without this call, an inner rollback aborts the test's
        // own transaction and every later query in that test fails for a reason that has
        // nothing to do with the plugin. Lesson of lot 3, trap 4 of docs/BANC-ESSAI.md.
        $this->preventResetByRollback();

        require_once($CFG->libdir . '/formslib.php');

        $generator = $this->getDataGenerator();
        $lpg = $generator->get_plugin_generator(competency_gateway::SUBSYSTEM);

        $framework = $lpg->create_framework(['shortname' => 'RNCP36832']);
        $competency = $lpg->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => 'RNCP36832-C03',
            'shortname' => 'Repérer les facteurs de risque de chute',
        ]);
        $this->competencyid = (int) $competency->get('id');

        $this->insert_source((int) $framework->get('id'));

        $this->candidate = $generator->create_user();
        $this->stranger = $generator->create_user();
        $this->attestant = $generator->create_user();

        // Both candidates may declare and read their own dossier. The FIRST one may also attest,
        // on their own context: granted deliberately, so that the self-attestation tests prove
        // the rule fires and not that a capability was missing.
        $declarerole = $generator->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $declarerole, \context_system::instance()->id, true);
        assign_capability('tool/vae:viewown', CAP_ALLOW, $declarerole, \context_system::instance()->id, true);
        role_assign($declarerole, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        role_assign($declarerole, $this->stranger->id, \context_user::instance($this->stranger->id)->id);

        // The manager's role: follow, and attest. Attesting requires reading the dossier,
        // and viewassigned is how a manager reads the dossier of a candidate they follow.
        $attestrole = $generator->create_role();
        assign_capability('tool/vae:attest', CAP_ALLOW, $attestrole, \context_system::instance()->id, true);
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $attestrole, \context_system::instance()->id, true);
        role_assign($attestrole, $this->candidate->id, \context_user::instance($this->candidate->id)->id);

        // The manager is entitled on the FIRST candidate's context and on nothing else.
        role_assign($attestrole, $this->attestant->id, \context_user::instance($this->candidate->id)->id);

        accesslib_clear_all_caches_for_unit_testing();

        $this->claim = $this->declare_for($this->candidate);
        $this->strangerclaim = $this->declare_for($this->stranger);

        $this->setUser($this->attestant);
    }

    /**
     * Record the provenance of the imported framework.
     *
     * @param int $frameworkid
     */
    private function insert_source(int $frameworkid): void {
        global $DB;

        $DB->insert_record('tool_vae_source', (object) [
            'frameworkid' => $frameworkid,
            'fichecode' => 'RNCP36832',
            'fichetype' => 'rncp',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => 1781136000,
            'licence' => 'LO-2.0',
            'attribution' => 'France compétences',
            'lastimport' => time(),
            'lastimportstatus' => 'ok',
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => 2,
        ]);
    }

    /**
     * One declaration, made in the first person as declarations always are.
     *
     * @param \stdClass $user
     * @return \stdClass
     */
    private function declare_for(\stdClass $user): \stdClass {
        $this->setUser($user);

        return (new evidence_manager())->declare_activity((int) $user->id, (object) [
            'competencyid' => $this->competencyid,
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'settingdetail' => 'Unité protégée, équipe de nuit',
            'situation' => 'Accompagnement au lever et à la toilette, en binôme avec une aide-soignante.',
            'situationformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * A well formed attestation.
     *
     * @param array $overrides
     * @return \stdClass
     */
    private function signature(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'attestantrole' => 'Cadre de santé, EHPAD Les Tilleuls',
            'statement' => 'J\'atteste avoir encadré cette personne sur la période déclarée.',
        ]);
    }

    /**
     * Photograph of every core row the subsystem holds.
     *
     * @return array
     */
    private function core_snapshot(): array {
        global $DB;

        $snapshot = [];
        foreach (self::CORE_TABLES as $table) {
            $snapshot[$table] = $DB->get_records($table, null, 'id ASC');
        }

        return $snapshot;
    }

    // ----------------------------------------------------------------------
    // Criterion 1 bis.

    /**
     * Criterion 1 bis - the attestation is a row of this plugin, and the core does not move.
     */
    public function test_an_attestation_is_a_plugin_row_and_the_core_does_not_move(): void {
        global $DB;

        $before = $this->core_snapshot();

        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());

        $this->assertEquals($before, $this->core_snapshot(), 'No core row may change when a declaration is signed.');

        $stored = $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST);
        $this->assertEquals($this->claim->id, $stored->evidenceid);
        $this->assertEquals($this->attestant->id, $stored->attestantid);
        $this->assertSame('Cadre de santé, EHPAD Les Tilleuls', $stored->attestantrole);
        $this->assertNotEmpty($stored->attestedat);
        $this->assertNull($stored->withdrawnat);
        $this->assertTrue(identity_map::is_valid_ulid($stored->uuid));
    }

    /**
     * Criterion 1 bis - withdrawing the signature leaves the declaration exactly as it was.
     */
    public function test_withdrawing_leaves_the_declaration_intact(): void {
        global $DB;

        $manager = new attestation_manager();
        $attestation = $manager->attest((int) $this->claim->id, $this->signature());

        $claimbefore = $DB->get_record('tool_vae_evidence', ['id' => $this->claim->id], '*', MUST_EXIST);
        $corebefore = $this->core_snapshot();

        $this->setAdminUser();
        $manager->withdraw((int) $attestation->id, 'erreur');

        $claimafter = $DB->get_record('tool_vae_evidence', ['id' => $this->claim->id], '*', MUST_EXIST);

        $this->assertEquals($claimbefore, $claimafter, 'The claim itself may not be touched by a withdrawal.');
        $this->assertEquals($corebefore, $this->core_snapshot(), 'No core row may change either.');
        $this->assertSame($claimbefore->situation, $claimafter->situation);
    }

    /**
     * Withdrawal erases every field that names the signatory - usermodified included.
     *
     * FIVE COLUMNS, NOT FOUR. usermodified carries the signatory's id from the moment they
     * sign; leaving it, or rewriting it with the id of whoever withdrew, puts a name back on
     * the row in exactly the case where the reason is 'rgpd' and the signatory is the person
     * asking to be erased. A test that checked only the four obvious fields would return the
     * same verdict on code that left the fifth behind.
     */
    public function test_withdrawing_erases_every_field_that_names_the_signatory(): void {
        global $DB;

        $manager = new attestation_manager();
        $attestation = $manager->attest((int) $this->claim->id, $this->signature());

        $this->setAdminUser();
        $manager->withdraw((int) $attestation->id, 'rgpd');

        $stored = $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST);

        $this->assertNull($stored->attestantid);
        $this->assertNull($stored->attestantrole);
        $this->assertNull($stored->statement);
        $this->assertNull($stored->attestedat);
        $this->assertEquals(0, $stored->usermodified, 'usermodified must not name the signatory either.');

        $this->assertNotEmpty($stored->withdrawnat);
        $this->assertSame('rgpd', $stored->withdrawnreason);

        // And the row must not name them anywhere else, whatever column is added later.
        foreach ((array) $stored as $column => $value) {
            $this->assertNotEquals(
                (int) $this->attestant->id,
                (int) $value,
                "Column '$column' still carries the signatory's id."
            );
        }
    }

    /**
     * The withdrawn attestation is kept: "attested, then withdrawn" is itself the evidence.
     */
    public function test_a_withdrawn_attestation_is_kept_as_a_trace(): void {
        $manager = new attestation_manager();
        $attestation = $manager->attest((int) $this->claim->id, $this->signature());
        $this->setAdminUser();
        $manager->withdraw((int) $attestation->id, 'contestation');

        $all = attestation_manager::attestations((int) $this->claim->id);

        $this->assertCount(1, $all, 'The row stays; only its identifying fields go.');
        $this->assertNull(attestation_manager::active_attestation((int) $this->claim->id));
    }

    // ----------------------------------------------------------------------
    // Refusals.

    /**
     * Nobody attests their own declaration - even holding the capability for it.
     */
    public function test_a_candidate_cannot_attest_their_own_declaration(): void {
        $this->setUser($this->candidate);

        $this->assertTrue(
            has_capability('tool/vae:attest', \context_user::instance($this->candidate->id)),
            'The capability is deliberately granted, so the refusal proves the rule and not a missing right.'
        );

        $sink = $this->redirectEvents();

        try {
            (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
            $this->fail('A candidate must not be able to attest their own declaration.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestown', $e->errorcode);
        }

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(\tool_vae\event\attestation_denied::class, $events[0]);
    }

    /**
     * Nor does anyone withdraw the signature standing on their own dossier.
     *
     * The rule that guards the signature has to guard its destruction too. A manager who is
     * himself a candidate - ordinary in this sector - would otherwise be able to delete the
     * attestation that vouches for him, by reaching withdraw.php directly: he reads his own
     * dossier, and a role carrying the capability assigned at system level holds on his own
     * context too.
     */
    public function test_a_candidate_cannot_withdraw_the_signature_on_their_own_dossier(): void {
        global $DB;

        $manager = new attestation_manager();
        $attestation = $manager->attest((int) $this->claim->id, $this->signature());

        $this->setUser($this->candidate);

        try {
            $manager->withdraw((int) $attestation->id, 'contestation');
            $this->fail('A candidate must not be able to withdraw the signature on their own dossier.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestown', $e->errorcode);
        }

        $stored = $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST);
        $this->assertNull($stored->withdrawnat, 'A refused withdrawal must erase nothing.');
        $this->assertEquals($this->attestant->id, $stored->attestantid);
    }

    /**
     * Without the capability: the attempt is recorded, THEN refused.
     */
    public function test_without_the_capability_the_attempt_is_logged_then_refused(): void {
        global $DB;

        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $sink = $this->redirectEvents();

        try {
            (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
            $this->fail('A user without the capability must not be able to attest.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestrefused', $e->errorcode);
        }

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events, 'The refusal is recorded before the exception is thrown.');
        $this->assertInstanceOf(\tool_vae\event\attestation_denied::class, $events[0]);
        $this->assertEquals($this->candidate->id, $events[0]->relateduserid);

        $this->assertFalse(
            $DB->record_exists('tool_vae_attest', ['evidenceid' => $this->claim->id]),
            'Nothing may be written when the attempt is refused.'
        );
    }

    /**
     * The capability is checked on the CANDIDATE'S context, not on the signatory's own.
     *
     * This is the test the single-candidate fixture could not express. The manager is
     * entitled on the first candidate and on nobody else; signing the second one's dossier
     * must fail. A check written against context_user::instance($USER->id) would pass every
     * other test in this file and fail only here.
     */
    public function test_the_capability_is_checked_on_the_candidates_context(): void {
        global $DB;

        $this->assertTrue(
            has_capability('tool/vae:attest', \context_user::instance($this->candidate->id)),
            'Entitled on the first candidate.'
        );
        $this->assertFalse(
            has_capability('tool/vae:attest', \context_user::instance($this->stranger->id)),
            'And on nobody else.'
        );

        try {
            (new attestation_manager())->attest((int) $this->strangerclaim->id, $this->signature());
            $this->fail('Signing the dossier of a candidate one is not entitled on must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestrefused', $e->errorcode);
        }

        $this->assertFalse(
            $DB->record_exists('tool_vae_attest', ['evidenceid' => $this->strangerclaim->id]),
            'Nothing may be written on a dossier one may not sign.'
        );
    }

    /**
     * Withdrawal is the site administrator's: anyone else is refused, the attempt logged, the row untouched.
     *
     * Without this test, deleting the check from withdraw() leaves the whole file green, while
     * any logged-in user guessing a sequential attestation id could destroy a signature (B7).
     */
    public function test_withdrawal_by_anyone_but_the_administrator_is_logged_then_refused(): void {
        global $DB;

        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());

        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser($outsider);

        $sink = $this->redirectEvents();

        try {
            (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
            $this->fail('A user who is not the site administrator must not be able to withdraw a signature.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorwithdraw', $e->errorcode);
        }

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(\tool_vae\event\attestation_denied::class, $events[0]);

        $stored = $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST);
        $this->assertNull($stored->withdrawnat, 'A refused withdrawal must erase nothing.');
        $this->assertSame('Cadre de santé, EHPAD Les Tilleuls', $stored->attestantrole);
    }

    /**
     * Not even the signatory withdraws their own signature: they ask the site administrator.
     *
     * The manager follows the candidate, holds tool/vae:attest and signed this very row,
     * which is everything the rule before 12 October 2026 asked for. Logged, refused, untouched.
     */
    public function test_the_signatory_cannot_withdraw_their_own_signature(): void {
        global $DB;

        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
        $this->assertFalse(attestation_manager::may_withdraw($attestation));

        $sink = $this->redirectEvents();

        try {
            (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
            $this->fail('The signatory must not be able to withdraw their own signature.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorwithdraw', $e->errorcode);
        }

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events, 'The refusal is recorded, and nothing else happens.');
        $this->assertInstanceOf(\tool_vae\event\attestation_denied::class, $events[0]);
        $this->assertEquals($this->candidate->id, $events[0]->relateduserid);

        $stored = $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST);
        $this->assertNull($stored->withdrawnat, 'A refused withdrawal must erase nothing.');
        $this->assertEquals($this->attestant->id, $stored->attestantid);
        $this->assertSame('Cadre de santé, EHPAD Les Tilleuls', $stored->attestantrole);
    }

    /**
     * With the site setting on, the signatory withdraws their own attestation - and only it.
     *
     * Off by default, which the test above holds; on, the manager who signed in error no longer
     * has to wait for the site administrator. A colleague who follows the same candidate and also
     * holds tool/vae:attest still may not withdraw it: that goes through a request to the signatory.
     */
    public function test_with_the_setting_on_the_signatory_withdraws_their_own_signature_only(): void {
        global $DB;

        set_config('managerwithdrawown', 1, 'tool_vae');
        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
        $this->assertTrue(attestation_manager::may_withdraw($attestation));

        $colleague = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role_assignments', 'roleid', [
            'userid' => $this->attestant->id,
            'contextid' => \context_user::instance($this->candidate->id)->id,
        ], MUST_EXIST);
        role_assign($roleid, $colleague->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($colleague);
        $this->assertFalse(attestation_manager::may_withdraw($attestation), 'A colleague may not, setting or not.');

        $this->setUser($this->attestant);
        $withdrawn = (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
        $this->assertNotNull($withdrawn->withdrawnat);
        $this->assertNull($withdrawn->attestantid);
    }

    /**
     * The capability opens any attestation to whoever holds it over this candidate.
     */
    public function test_a_holder_of_withdrawany_withdraws_any_signature_on_a_dossier_they_read(): void {
        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());

        $coordinator = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        assign_capability('tool/vae:withdrawany', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $coordinator->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($coordinator);
        $this->assertTrue(attestation_manager::may_withdraw($attestation));

        $withdrawn = (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
        $this->assertNotNull($withdrawn->withdrawnat);
    }

    /**
     * A second manager following the same candidate, with the same right to attest.
     *
     * @return \stdClass
     */
    private function colleague(): \stdClass {
        global $DB;

        $colleague = $this->getDataGenerator()->create_user();
        $roleid = $DB->get_field('role_assignments', 'roleid', [
            'userid' => $this->attestant->id,
            'contextid' => \context_user::instance($this->candidate->id)->id,
        ], MUST_EXIST);
        role_assign($roleid, $colleague->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $colleague;
    }

    /**
     * A colleague asks, the signatory answers: nothing is withdrawn until they accept.
     */
    public function test_a_colleague_asks_and_nothing_is_withdrawn_until_the_signatory_accepts(): void {
        global $DB;

        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
        $colleague = $this->colleague();

        $this->setUser($colleague);
        $this->assertFalse(attestation_manager::may_withdraw($attestation), 'A colleague never withdraws directly.');
        $this->assertTrue(attestation_manager::may_request_withdrawal($attestation, (int) $this->candidate->id));

        $requested = (new attestation_manager())->request_withdrawal((int) $attestation->id);
        $this->assertEquals($colleague->id, $requested->withdrawreqby);
        $this->assertNotNull($requested->withdrawreqat);

        $stored = $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST);
        $this->assertNull($stored->withdrawnat, 'Asking withdraws nothing.');
        $this->assertFalse(
            attestation_manager::may_request_withdrawal($stored, (int) $this->candidate->id),
            'One request at a time.'
        );

        // The signatory may now withdraw - accepting is withdrawing - even with the setting off.
        $this->setUser($this->attestant);
        $this->assertEmpty(get_config('tool_vae', 'managerwithdrawown'));
        $this->assertTrue(attestation_manager::may_withdraw($stored));

        $withdrawn = (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
        $this->assertNotNull($withdrawn->withdrawnat);
        $this->assertNull($withdrawn->withdrawreqby, 'The request is gone with the attestation.');
        $this->assertNull($withdrawn->withdrawreqat);
    }

    /**
     * Refusing clears the request and keeps the attestation.
     */
    public function test_the_signatory_refuses_and_the_attestation_stands(): void {
        global $DB;

        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
        $colleague = $this->colleague();

        $this->setUser($colleague);
        (new attestation_manager())->request_withdrawal((int) $attestation->id);

        $this->setUser($this->attestant);
        $sink = $this->redirectEvents();
        (new attestation_manager())->refuse_withdrawal((int) $attestation->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(\tool_vae\event\withdrawal_refused::class, $events[0]);
        $this->assertEquals($colleague->id, $events[0]->relateduserid, 'The manager who asked, not the candidate.');
        $this->assertEquals($this->candidate->id, $events[0]->other['candidateid']);
        $this->assertEquals($this->attestant->id, $events[0]->userid);

        $stored = $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST);
        $this->assertNull($stored->withdrawnat);
        $this->assertNull($stored->withdrawreqby);
        $this->assertNull($stored->withdrawreqat);
        $this->assertEquals($this->attestant->id, $stored->attestantid);
    }

    /**
     * Nobody asks for their own attestation, nobody answers for another's.
     */
    public function test_a_request_is_between_two_managers_and_only_the_signatory_answers(): void {
        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
        $colleague = $this->colleague();

        // The signatory does not ask themselves.
        $this->assertFalse(attestation_manager::may_request_withdrawal($attestation, (int) $this->candidate->id));

        // A stranger to the dossier cannot ask.
        $this->setUser($this->getDataGenerator()->create_user());
        try {
            (new attestation_manager())->request_withdrawal((int) $attestation->id);
            $this->fail('Somebody who does not follow the candidate must not be able to ask.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorrequestrefused', $e->errorcode);
        }

        // The colleague asks; the colleague cannot refuse in the signatory's place.
        $this->setUser($colleague);
        (new attestation_manager())->request_withdrawal((int) $attestation->id);
        try {
            (new attestation_manager())->refuse_withdrawal((int) $attestation->id);
            $this->fail('Only the signatory answers a request.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorrequestrefused', $e->errorcode);
        }
    }

    /**
     * Nobody asks a signatory who can no longer answer: the request would wait for ever and block the next one.
     *
     * A request to an account that is deleted or suspended is dropped when it is sent (notifier::deliver() reaches nobody),
     * and only the signatory answers it. Offered anyway, it stayed open on the row: the colleague read "a request is awaiting
     * the signatory" for good and could not make another, and the only person who could withdraw, the administrator, was
     * never told. Now the request is refused, and the dossier sends the reader to the administrator instead.
     *
     * @param string $state How the account of the signatory stops answering.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreachable_signatories')]
    public function test_nobody_asks_a_signatory_whose_account_cannot_answer(string $state): void {
        global $DB;

        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
        $colleague = $this->colleague();
        $candidateid = (int) $this->candidate->id;

        // While the signatory can answer, the colleague may ask: what is refused below is the account's doing alone.
        $this->setUser($colleague);
        $this->assertTrue(attestation_manager::signatory_reachable($attestation));
        $this->assertTrue(attestation_manager::may_request_withdrawal($attestation, $candidateid));

        $this->setAdminUser();
        if ($state === 'deleted') {
            delete_user($DB->get_record('user', ['id' => $this->attestant->id], '*', MUST_EXIST));
        } else {
            $DB->set_field('user', 'suspended', 1, ['id' => $this->attestant->id]);
        }
        $stored = $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST);
        $this->assertTrue(attestation_manager::may_withdraw($stored, $candidateid), 'The administrator still withdraws it.');

        $this->setUser($colleague);
        $this->assertFalse(attestation_manager::signatory_reachable($stored));
        $this->assertFalse(attestation_manager::may_request_withdrawal($stored, $candidateid));
        try {
            (new attestation_manager())->request_withdrawal((int) $stored->id);
            $this->fail('A request was made to an account that cannot answer it.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorrequestrefused', $e->errorcode);
        }
        $this->assertNull($DB->get_field('tool_vae_attest', 'withdrawreqat', ['id' => $stored->id]), 'The request stayed open.');
    }

    /**
     * The two ways the account of a signatory stops answering.
     *
     * @return array[]
     */
    public static function unreachable_signatories(): array {
        return [
            'the account is deleted' => ['deleted'],
            'the account is suspended' => ['suspended'],
        ];
    }

    /**
     * An attestation with no signatory - one carried in from an archive - has nobody to ask.
     */
    public function test_an_imported_attestation_has_no_signatory_to_reach(): void {
        $this->assertFalse(attestation_manager::signatory_reachable((object) ['attestantid' => null]));
    }

    /**
     * The site administrator withdraws any signature, one given on this site included.
     */
    public function test_the_site_administrator_withdraws_any_signature(): void {
        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
        $this->assertEmpty($attestation->importedat ?? null, 'A signature given here, not carried in from an archive.');

        $this->setAdminUser();
        $this->assertTrue(attestation_manager::may_withdraw($attestation));

        $withdrawn = (new attestation_manager())->withdraw((int) $attestation->id, 'contestation');

        $this->assertNotNull($withdrawn->withdrawnat);
        $this->assertSame('contestation', $withdrawn->withdrawnreason);
        $this->assertNull($withdrawn->attestantid);
    }

    /**
     * A withdrawal refreshes the candidate's backups: none of them names the signatory any more.
     *
     * A backup is kept without time limit. Left as it was, it would hold for good the signature
     * just erased from the dossier. An archive already handed out by the exporter is frozen by
     * design (MC-9) and not this test's business.
     */
    public function test_a_withdrawal_refreshes_the_backups_of_the_dossier(): void {
        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());

        $this->setAdminUser();
        $admin = (int) get_admin()->id;
        backup::save((int) $this->candidate->id, $admin);

        // The premise: the backup does carry the signature. ASCII fragments only, so that the way
        // the archive encodes an accented letter cannot make the absence below pass by accident.
        $signatory = identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->attestant->id);
        $before = $this->backup_texts($admin);
        $this->assertCount(1, $before);
        $this->assertStringContainsString($signatory, $before[0]);
        $this->assertStringContainsString('EHPAD Les Tilleuls', $before[0]);
        $this->assertStringContainsString('atteste avoir', $before[0]);

        (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');

        $after = $this->backup_texts($admin);
        $this->assertCount(1, $after, 'The owner lost their backup, or kept the old one beside the new.');
        $this->assertStringNotContainsString($signatory, $after[0]);
        $this->assertStringNotContainsString('EHPAD Les Tilleuls', $after[0]);
        $this->assertStringNotContainsString('atteste avoir', $after[0]);
        $this->assertStringContainsString('retiree', $after[0], 'The trace of the withdrawn signature remains.');
    }

    /**
     * Every backup in a user's private files, each as the text of all the files it holds.
     *
     * @param int $userid
     * @return string[]
     */
    private function backup_texts(int $userid): array {
        $files = get_file_storage()->get_directory_files(
            \context_user::instance($userid)->id,
            'user',
            'private',
            0,
            backup::FILEPATH,
            false,
            false
        );

        $texts = [];
        foreach ($files as $file) {
            $path = make_request_directory() . '/sauvegarde.zip';
            $file->copy_content_to($path);

            $zip = new \ZipArchive();
            $zip->open($path);
            $text = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $text .= (string) $zip->getFromIndex($i) . "\n";
            }
            $zip->close();
            $texts[] = $text;
        }

        return $texts;
    }

    /**
     * A user holding tool/vae:attest through a role assigned at system level, and nothing else.
     *
     * @return \stdClass
     */
    private function sitewide_signatory(): \stdClass {
        $generator = $this->getDataGenerator();

        $user = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('tool/vae:attest', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $user;
    }

    /**
     * Lot 8 ter: the capability held at system level reaches every candidate's context, and
     * attest.php opens whatever claim its URL names. Without reading the dossier - viewany, or
     * following the candidate - it signs nothing.
     *
     * Drop the reading check from access::can_attest() and this signature goes through.
     */
    public function test_attest_held_at_system_level_signs_nothing_without_reading_the_dossier(): void {
        global $DB;

        $this->setUser($this->sitewide_signatory());

        // The trap is real: the capability is inherited by the candidate's context.
        $this->assertTrue(has_capability('tool/vae:attest', \context_user::instance($this->candidate->id)));

        $sink = $this->redirectEvents();

        try {
            (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
            $this->fail('Holding the capability at system level must not be enough to attest.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestrefused', $e->errorcode);
        }

        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events, 'The refusal is recorded before the exception is thrown.');
        $this->assertInstanceOf(\tool_vae\event\attestation_denied::class, $events[0]);

        $this->assertFalse(
            $DB->record_exists('tool_vae_attest', ['evidenceid' => $this->claim->id]),
            'Nothing may be written when the attempt is refused.'
        );
    }

    /**
     * Nor does the same signatory withdraw a signature on a dossier they do not read.
     */
    public function test_attest_held_at_system_level_withdraws_nothing_without_reading_the_dossier(): void {
        global $DB;

        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());

        $this->setUser($this->sitewide_signatory());

        try {
            (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
            $this->fail('Holding the capability at system level must not be enough to withdraw a signature.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorwithdraw', $e->errorcode);
        }

        $stored = $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST);
        $this->assertNull($stored->withdrawnat, 'A refused withdrawal must erase nothing.');
        $this->assertEquals($this->attestant->id, $stored->attestantid);
    }

    /**
     * One standing signature at a time.
     */
    public function test_a_second_attestation_is_refused_while_one_stands(): void {
        $manager = new attestation_manager();
        $manager->attest((int) $this->claim->id, $this->signature());

        try {
            $manager->attest((int) $this->claim->id, $this->signature());
            $this->fail('A claim may carry only one standing signature.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestexists', $e->errorcode);
        }

        $this->assertCount(1, attestation_manager::attestations((int) $this->claim->id));
    }

    /**
     * T-76: attest() takes the lock of the claim, and gives it back whatever happens.
     *
     * The race itself needs two processes and is not reproduced here: what is checked is the part
     * a single process can see, that the lock of the claim is free again after an attestation
     * and after a refusal. A lock left held would block every later attestation of the claim
     * for the length of the timeout.
     */
    public function test_attest_releases_the_lock_of_the_claim_on_success_and_on_refusal(): void {
        $resource = 'evidence-' . (int) $this->claim->id;
        $free = function () use ($resource): bool {
            $lock = \core\lock\lock_config::get_lock_factory('tool_vae_attest')->get_lock($resource, 1);
            if (!$lock) {
                return false;
            }
            $lock->release();
            return true;
        };

        $manager = new attestation_manager();
        $manager->attest((int) $this->claim->id, $this->signature());
        $this->assertTrue($free(), 'The lock is free after a successful attestation.');

        try {
            $manager->attest((int) $this->claim->id, $this->signature());
            $this->fail('A claim may carry only one standing signature.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestexists', $e->errorcode);
        }
        $this->assertTrue($free(), 'The lock is free after a refusal.');
    }

    /**
     * After a withdrawal, the claim can be attested again.
     *
     * The point of withdrawal is that the dossier survives it. A claim that could never be
     * signed again would be evidence destroyed by a slower route.
     */
    public function test_a_claim_can_be_attested_again_after_a_withdrawal(): void {
        $manager = new attestation_manager();
        $first = $manager->attest((int) $this->claim->id, $this->signature());
        $this->setAdminUser();
        $manager->withdraw((int) $first->id, 'erreur');
        $this->setUser($this->attestant);

        $second = $manager->attest((int) $this->claim->id, $this->signature());

        $this->assertNotEquals($first->id, $second->id);
        $this->assertCount(2, attestation_manager::attestations((int) $this->claim->id));
        $this->assertEquals($second->id, attestation_manager::active_attestation((int) $this->claim->id)->id);
    }

    /**
     * Withdrawing twice is refused rather than silently repeated.
     */
    public function test_withdrawing_twice_is_refused(): void {
        $manager = new attestation_manager();
        $attestation = $manager->attest((int) $this->claim->id, $this->signature());
        $this->setAdminUser();
        $manager->withdraw((int) $attestation->id, 'erreur');

        try {
            $manager->withdraw((int) $attestation->id, 'erreur');
            $this->fail('A signature already withdrawn cannot be withdrawn again.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestalreadywithdrawn', $e->errorcode);
        }
    }

    /**
     * B5: a second withdrawal of the same row announces nothing, and changes nothing.
     *
     * The candidate is told once. Before the lock and the conditional write, a second withdrawal
     * that passed the test as the first was writing overwrote the first one's reason and date and
     * sent a second event, hence a second message. The race itself cannot be staged in one PHP
     * process; this pins what the losing call must do, which is what the race now leads to.
     */
    public function test_a_second_withdrawal_emits_no_second_event(): void {
        global $DB;

        $manager = new attestation_manager();
        $attestation = $manager->attest((int) $this->claim->id, $this->signature());
        $this->setAdminUser();
        $manager->withdraw((int) $attestation->id, 'erreur');
        $first = $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST);

        $sink = $this->redirectEvents();
        try {
            $manager->withdraw((int) $attestation->id, 'contestation');
            $this->fail('A signature already withdrawn cannot be withdrawn again.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestalreadywithdrawn', $e->errorcode);
        }
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(0, $events, 'The losing withdrawal must not be announced.');
        $this->assertEquals(
            $first,
            $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST),
            'The first withdrawal\'s reason and date stand.'
        );
    }

    /**
     * The reason for withdrawal comes from the closed list, and from nowhere else.
     */
    public function test_an_unknown_reason_for_withdrawal_is_refused(): void {
        global $DB;

        $manager = new attestation_manager();
        $attestation = $manager->attest((int) $this->claim->id, $this->signature());
        $this->setAdminUser();

        try {
            $manager->withdraw((int) $attestation->id, 'parce que');
            $this->fail('An unknown reason must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorwithdrawreasonunknown', $e->errorcode);
        }

        $stored = $DB->get_record('tool_vae_attest', ['id' => $attestation->id], '*', MUST_EXIST);
        $this->assertNotNull($stored->attestantid, 'A refused withdrawal must erase nothing.');
    }

    /**
     * Both free fields are required. Neither may be blank, nor blank once trimmed.
     */
    public function test_the_position_and_the_wording_are_both_required(): void {
        $manager = new attestation_manager();

        try {
            $manager->attest((int) $this->claim->id, $this->signature(['attestantrole' => '   ']));
            $this->fail('A blank position must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestantrolemissing', $e->errorcode);
        }

        try {
            $manager->attest((int) $this->claim->id, $this->signature(['statement' => "\n\t "]));
            $this->fail('A blank wording must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorstatementempty', $e->errorcode);
        }
    }

    /**
     * A position longer than the column is refused rather than truncated.
     *
     * Silent truncation at 255 would cut a job title mid-word and store it as if it were
     * what the person wrote.
     */
    public function test_a_position_longer_than_the_column_is_refused(): void {
        try {
            (new attestation_manager())->attest(
                (int) $this->claim->id,
                $this->signature(['attestantrole' => str_repeat('é', 256)])
            );
            $this->fail('A position longer than the column must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestantroletoolong', $e->errorcode);
        }
    }

    /**
     * Attesting a declaration that does not exist is refused as one the caller may not sign.
     */
    public function test_an_unknown_declaration_is_refused(): void {
        try {
            (new attestation_manager())->attest(-1, $this->signature());
            $this->fail('An unknown declaration must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestrefused', $e->errorcode);
        }
    }

    /**
     * Every refusal to attest is the same, word for word and link included, whatever its cause.
     *
     * A refusal naming the attest capability told a reader who held it but could not read the
     * dossier that they lacked it; one telling an unknown id apart told anyone which ids are
     * declarations (M-3, lot 8 ter). Give any cause its own exception, code, message or link and
     * this test fails.
     */
    public function test_every_refusal_to_attest_is_the_same(): void {
        $generator = $this->getDataGenerator();

        // Reads every dossier, attests none.
        $reader = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $reader->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $attempts = [
            'without the capability' => [$reader, (int) $this->claim->id],
            'without reading the dossier' => [$this->sitewide_signatory(), (int) $this->claim->id],
            'on a declaration that does not exist' => [$this->attestant, -1],
        ];

        $refusals = [];
        foreach ($attempts as $cause => [$user, $evidenceid]) {
            $this->setUser($user);
            try {
                (new attestation_manager())->attest($evidenceid, $this->signature());
                $this->fail("An attestation $cause went through.");
            } catch (\moodle_exception $e) {
                $refusals[$cause] = [get_class($e), $e->module, $e->errorcode, $e->getMessage(), $e->link];
            }
        }

        $first = reset($refusals);
        $this->assertSame('errorattestrefused', $first[2]);
        $this->assertSame('', $first[4], 'No link: the candidate\'s profile would say whose declaration it is.');
        foreach ($refusals as $cause => $refusal) {
            $this->assertSame($first, $refusal, "The refusal $cause tells its cause apart.");
        }
    }

    /**
     * Every refusal to ask for a withdrawal, or to refuse one, is the same, whatever its cause.
     *
     * An id that is no attestation answered "this attestation does not exist" where an id one may not touch answered "this
     * request is not possible": any difference tells a stranger which ids are attestations - how many the site holds and how
     * fast they come (M-3, as for attest()). Give a cause its own exception, code, message or link and this test fails.
     */
    public function test_every_refusal_to_request_or_refuse_a_withdrawal_is_the_same(): void {
        global $DB;

        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
        $missing = (int) $DB->get_field_sql('SELECT MAX(id) FROM {tool_vae_attest}') + 1;
        $colleague = $this->colleague();
        $stranger = $this->getDataGenerator()->create_user();

        $refusal = function (string $act, \stdClass $user, int $id): array {
            $this->setUser($user);
            try {
                (new attestation_manager())->$act($id);
            } catch (\moodle_exception $e) {
                return [get_class($e), $e->module, $e->errorcode, $e->getMessage(), $e->link];
            }
            $this->fail("The $act of attestation $id went through for user {$user->id}.");
        };

        // Asking: a stranger on a real attestation, the signatory on their own, anyone on an id that is no attestation.
        $asking = [
            'a stranger on an attestation' => $refusal('request_withdrawal', $stranger, (int) $attestation->id),
            'the signatory on their own' => $refusal('request_withdrawal', $this->attestant, (int) $attestation->id),
            'a colleague on an id that is no attestation' => $refusal('request_withdrawal', $colleague, $missing),
        ];
        $first = reset($asking);
        $this->assertSame('errorrequestrefused', $first[2]);
        $this->assertSame('', $first[4], 'No link: the candidate\'s profile would say whose declaration it is.');
        foreach ($asking as $cause => $one) {
            $this->assertSame($first, $one, "The refusal to ask $cause tells its cause apart.");
        }

        // Refusing a request: the colleague asked; the colleague is not the signatory, a stranger is nobody, and an id is an id.
        $this->setUser($colleague);
        (new attestation_manager())->request_withdrawal((int) $attestation->id);
        $answering = [
            'the one who asked instead of the signatory' => $refusal('refuse_withdrawal', $colleague, (int) $attestation->id),
            'a stranger on an attestation' => $refusal('refuse_withdrawal', $stranger, (int) $attestation->id),
            'the signatory on an id that is no attestation' => $refusal('refuse_withdrawal', $this->attestant, $missing),
        ];
        foreach ($answering as $cause => $one) {
            $this->assertSame($first, $one, "The refusal to answer $cause tells its cause apart.");
        }

        // A declaration that is gone with its attestation row left behind says nothing more.
        $DB->set_field('tool_vae_attest', 'evidenceid', $missing + 1000, ['id' => $attestation->id]);
        $this->assertSame($first, $refusal('request_withdrawal', $colleague, (int) $attestation->id));
        $this->assertSame($first, $refusal('refuse_withdrawal', $this->attestant, (int) $attestation->id));
    }

    /**
     * A withdrawal erases the signatory, and the one who comes back to a page left open is told what happened.
     *
     * The setting lets the signatory withdraw their own attestation; the administrator withdraws it first. The signatory is no
     * longer recognised as one - attestantid is gone - and asked in the other order they were refused, told they may not
     * withdraw, and a refusal was logged against them: a false "attempt" in the journal of the site, for an act that was
     * already done.
     */
    public function test_the_former_signatory_is_told_it_is_already_withdrawn_and_no_refusal_is_logged(): void {
        set_config('managerwithdrawown', 1, 'tool_vae');
        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
        $this->assertTrue(attestation_manager::may_withdraw($attestation));

        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');

        $this->setUser($this->attestant);
        $sink = $this->redirectEvents();
        try {
            (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
            $this->fail('A withdrawal already made cannot be made again.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestalreadywithdrawn', $e->errorcode);
        }
        $this->assertCount(0, $sink->get_events(), 'No refusal is logged against the signatory for a row already withdrawn.');
        $sink->close();

        // Anybody who does not read the dossier still meets the one refusal, and it is logged: an id tells them nothing.
        $this->setUser($this->getDataGenerator()->create_user());
        $sink = $this->redirectEvents();
        try {
            (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
            $this->fail('A stranger must not learn that the attestation is withdrawn.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorwithdraw', $e->errorcode);
        }
        $events = $sink->get_events();
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\tool_vae\event\attestation_denied::class, $events[0]);
    }

    /**
     * The same for the signatory a colleague had asked, when the setting is off.
     */
    public function test_the_signatory_a_colleague_asked_is_told_it_is_already_withdrawn_too(): void {
        $attestation = (new attestation_manager())->attest((int) $this->claim->id, $this->signature());
        $this->setUser($this->colleague());
        (new attestation_manager())->request_withdrawal((int) $attestation->id);

        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');

        $this->setUser($this->attestant);
        $sink = $this->redirectEvents();
        try {
            (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
            $this->fail('A withdrawal already made cannot be made again.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestalreadywithdrawn', $e->errorcode);
        }
        $this->assertCount(0, $sink->get_events(), 'No refusal is logged against the signatory for a row already withdrawn.');
        $sink->close();
    }

    /**
     * The sentence said of a declaration that already carries an attestation is true of every way to withdraw it.
     *
     * It named the site administrator alone, from the day the rule was "the administrator withdraws" - and went on saying so
     * after a holder of withdrawany, the signatory with the setting on, and the signatory a colleague has asked, could
     * withdraw as well: the attestation page drew the button "Withdraw" just under it. The sentence is also the message of the
     * exception attest() raises, so the English and the French are read here, not only the page.
     */
    public function test_the_message_of_an_attestation_that_stands_does_not_name_the_administrator_alone(): void {
        $manager = new attestation_manager();
        $manager->attest((int) $this->claim->id, $this->signature());

        try {
            $manager->attest((int) $this->claim->id, $this->signature());
            $this->fail('A claim may carry only one standing signature.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorattestexists', $e->errorcode);
            $this->assertStringNotContainsStringIgnoringCase('administrat', $e->getMessage());
        }

        $french = (function (): array {
            $string = [];
            require(__DIR__ . '/../lang/fr/tool_vae.php');

            return $string;
        })();
        $this->assertArrayHasKey('errorattestexists', $french);
        $this->assertStringNotContainsStringIgnoringCase('administrat', $french['errorattestexists']);
    }

    // ----------------------------------------------------------------------
    // Events.

    /**
     * The recorded events carry identifiers and nothing else - anti-pattern 16.
     */
    public function test_the_events_carry_identifiers_and_nothing_else(): void {
        $manager = new attestation_manager();

        $sink = $this->redirectEvents();
        $attestation = $manager->attest((int) $this->claim->id, $this->signature());
        $this->setAdminUser();
        $manager->withdraw((int) $attestation->id, 'rgpd');
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(2, $events);
        $this->assertInstanceOf(\tool_vae\event\evidence_attested::class, $events[0]);
        $this->assertInstanceOf(\tool_vae\event\attestation_withdrawn::class, $events[1]);

        foreach ($events as $event) {
            foreach ($event->other as $key => $value) {
                $this->assertIsInt($value, "The value '$key' in other must be an identifier.");
            }

            $serialised = json_encode($event->get_data());
            $this->assertStringNotContainsString('Tilleuls', $serialised, 'No wording of a signature in the log.');
            $this->assertStringNotContainsString('atteste avoir', $serialised, 'No wording of a signature in the log.');
        }
    }
}
