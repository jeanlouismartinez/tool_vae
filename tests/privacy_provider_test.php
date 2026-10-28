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
use core_privacy\local\request\approved_userlist;
use tool_vae\privacy\provider;

/**
 * The two people in a dossier, and their opposite rights.
 *
 * WHY THESE TESTS EXIST, BEYOND THE OBVIOUS.
 * Moodle's database drivers return integer columns as STRINGS, and so does
 * approved_contextlist::get_user()->id. A comparison written as
 *     (int) $context->instanceid === $userid
 * therefore compares an integer to a string and is ALWAYS false - which sent an erasure
 * request down the wrong branch and left the candidate's dossier in place. The site would
 * have reported the erasure as done.
 *
 * It is the same family of defect as FORMAT_PLAIN being the string '2': a strict comparison
 * against a value whose type is not what it looks like. Nothing in the code reads as wrong.
 * These tests assert the BEHAVIOUR - is the dossier gone - rather than the branch taken, so
 * they would catch the next variant of it too.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\privacy\provider::class)]
final class privacy_provider_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass The manager who attests. */
    private $attestant;

    /** @var int The plugin metadata row. */
    private $evidenceid;

    /** @var \stdClass The administrator who imported the certification record. */
    private $importer;

    /** @var int The provenance row they wrote. */
    private $sourceid;

    /** @var int The mapping they decided. */
    private $passageid;

    /**
     * A dossier with one claim and one attestation.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $DB;
        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $this->attestant = $generator->create_user();

        $now = time();
        $this->evidenceid = (int) $DB->insert_record('tool_vae_evidence', (object) [
            'uuid' => local\identity_map::generate_ulid(),
            'userevidenceid' => 4242,
            'candidateid' => $this->candidate->id,
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'settingdetail' => 'Unité protégée',
            'situation' => 'Accompagnement au lever.',
            'situationformat' => (int) FORMAT_PLAIN,
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
            'ficheupdated' => 1781136000,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $this->candidate->id,
        ]);

        $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => local\identity_map::generate_ulid(),
            'evidenceid' => $this->evidenceid,
            'attestantid' => $this->attestant->id,
            'attestantrole' => 'Cadre de santé, EHPAD Les Tilleuls',
            'statement' => 'Je soussignée Claire Dupont atteste…',
            'attestedat' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $this->attestant->id,
        ]);

        local\identity_map::uuid_for(local\identity_map::TYPE_USER, (int) $this->candidate->id);

        // The third person in this plugin: not a candidate, not a signatory, but named all
        // the same - in the provenance of an imported certification record and in the note
        // justifying a mapping between two of them. The provider announces them to the site
        // at system level, so the site announces them to the person.
        $this->importer = $generator->create_user();

        $this->sourceid = (int) $DB->insert_record('tool_vae_source', (object) [
            'frameworkid' => 1,
            'fichecode' => 'RNCP36832',
            'fichetype' => 'rncp',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => 1781136000,
            'licence' => 'LO-2.0',
            'attribution' => 'France compétences',
            'lastimport' => $now,
            'lastimportstatus' => 'ok',
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $this->importer->id,
        ]);

        $this->passageid = (int) $DB->insert_record('tool_vae_passage', (object) [
            'fromcompetencyid' => 11,
            'tocompetencyid' => 22,
            'decidedby' => $this->importer->id,
            'decidedat' => $now,
            'note' => 'Décidé en commission par Claire Dupont, après avis du référent VAE.',
        ]);
    }

    /**
     * The approval a request carries when it reaches the site level.
     *
     * @param \stdClass $user
     * @return approved_contextlist
     */
    private function site_approval(\stdClass $user): approved_contextlist {
        return new approved_contextlist(
            \core_user::get_user($user->id),
            'tool_vae',
            [\context_system::instance()->id]
        );
    }

    /**
     * Build the approval a real erasure request would carry.
     *
     * @param \stdClass $user
     * @param int $candidateid Whose user context the data sits in.
     * @return approved_contextlist
     */
    private function approval(\stdClass $user, int $candidateid): approved_contextlist {
        return new approved_contextlist(
            \core_user::get_user($user->id),
            'tool_vae',
            [\context_user::instance($candidateid)->id]
        );
    }

    /**
     * An attestation carried in from an archive, on the claim seeded in setUp().
     *
     * What dossier_importer writes for a signature in force: no account, the ULID the archive
     * gives. Here that ULID is the one this site gave a local account - a backup restored in place.
     *
     * @param \stdClass $signatory
     * @return int The attestation row.
     */
    private function imported_attestation(\stdClass $signatory): int {
        global $DB;

        $now = time();

        return (int) $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => local\identity_map::generate_ulid(),
            'evidenceid' => $this->evidenceid,
            'attestantid' => null,
            'attestantuuid' => local\identity_map::uuid_for(local\identity_map::TYPE_USER, (int) $signatory->id),
            'attestantrole' => 'Infirmière coordinatrice, EHPAD Les Tilleuls',
            'statement' => 'Je soussignée Anne Martin atteste…',
            'attestedat' => $now,
            'importedat' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);
    }

    /**
     * Erasing the candidate removes the dossier, because it is theirs.
     */
    public function test_erasing_the_candidate_removes_the_dossier(): void {
        global $DB;

        provider::delete_data_for_user($this->approval($this->candidate, (int) $this->candidate->id));

        $this->assertEquals(
            0,
            $DB->count_records('tool_vae_evidence', ['candidateid' => $this->candidate->id]),
            'The candidate asked for erasure and their dossier is still here.'
        );
        $this->assertEquals(0, $DB->count_records('tool_vae_attest', ['evidenceid' => $this->evidenceid]));
        $this->assertEquals(0, $DB->count_records('tool_vae_identity', [
            'itemtype' => local\identity_map::TYPE_USER,
            'itemid' => $this->candidate->id,
        ]));
    }

    /**
     * Erasing the manager must NOT remove the dossier - and must remove four fields, not one.
     *
     * attestantrole names an establishment and a job title; statement begins "I, the
     * undersigned" and spells the name out. Clearing only attestantid leaves a record that
     * believes itself anonymised.
     */
    public function test_erasing_the_manager_keeps_the_dossier_and_strips_four_fields(): void {
        global $DB;

        provider::delete_data_for_user($this->approval($this->attestant, (int) $this->candidate->id));

        $this->assertEquals(
            1,
            $DB->count_records('tool_vae_evidence', ['candidateid' => $this->candidate->id]),
            'The candidate lost their evidence because someone else asked for erasure.'
        );

        $attestation = $DB->get_record('tool_vae_attest', ['evidenceid' => $this->evidenceid]);
        $this->assertNotFalse($attestation, 'The attestation row must survive, marked withdrawn.');
        $this->assertNull($attestation->attestantid);
        $this->assertNull($attestation->attestantrole, 'The job title still names the person.');
        $this->assertNull($attestation->statement, 'The statement still names the person.');
        $this->assertNull($attestation->attestedat, 'A date of signature is still a trace of the signatory.');
        $this->assertNotNull($attestation->withdrawnat);
        $this->assertSame('rgpd', $attestation->withdrawnreason);
    }

    /**
     * The messages about an erasure waiting in the queue.
     *
     * @param string $provider What the task carries: 'attestationserased' is the message to the candidate.
     * @return \core\task\adhoc_task[]
     */
    private function queued_messages(string $provider): array {
        return array_values(array_filter(
            \core\task\manager::get_adhoc_tasks(\tool_vae\task\send_notification::class),
            fn($task) => $task->get_custom_data()->provider === $provider
        ));
    }

    /**
     * Erasing the manager tells the candidate, once, which declaration lost its attestation - and the candidate alone.
     *
     * The task carries the identifiers: who is told, whose dossier, which declaration. No name, no text. A manager who
     * attested nothing here, or the person whose user context this is, queue nothing.
     */
    public function test_erasing_the_manager_queues_one_message_for_the_candidate(): void {
        provider::delete_data_for_user($this->approval($this->attestant, (int) $this->candidate->id));

        $tasks = $this->queued_messages('attestationserased');
        $this->assertCount(1, $tasks, 'The candidate was not told that the attestation of their declaration was withdrawn.');
        $data = $tasks[0]->get_custom_data();
        $this->assertSame((int) $this->candidate->id, (int) $data->recipientid);
        $this->assertSame((int) $this->candidate->id, (int) $data->candidateid);
        $this->assertSame([$this->evidenceid], array_map('intval', (array) $data->evidenceids));
        $this->assertStringNotContainsString('Cadre de santé', json_encode($data));

        // The same request played again withdraws nothing, so tells nothing, and the message is not queued twice.
        provider::delete_data_for_user($this->approval($this->attestant, (int) $this->candidate->id));
        $this->assertCount(1, $this->queued_messages('attestationserased'));

        // A person who signed nothing in this dossier withdraws nothing from it.
        provider::delete_data_for_user($this->approval($this->importer, (int) $this->candidate->id));
        $this->assertCount(1, $this->queued_messages('attestationserased'));
    }

    /**
     * The bulk path tells the candidate as the individual one does.
     */
    public function test_the_bulk_path_tells_the_candidate_too(): void {
        $context = \context_user::instance($this->candidate->id);

        provider::delete_data_for_users(new approved_userlist($context, 'tool_vae', [(int) $this->attestant->id]));

        $tasks = $this->queued_messages('attestationserased');
        $this->assertCount(1, $tasks);
        $this->assertSame((int) $this->candidate->id, (int) $tasks[0]->get_custom_data()->recipientid);
    }

    /**
     * The candidate's own export contains their claims.
     *
     * The same string-versus-integer comparison made the candidate look like a third party
     * to their own dossier, so their export came back empty.
     */
    public function test_the_candidate_export_contains_their_own_claims(): void {
        $context = \context_user::instance($this->candidate->id);

        provider::export_user_data($this->approval($this->candidate, (int) $this->candidate->id));

        $writer = \core_privacy\local\request\writer::with_context($context);
        $this->assertTrue($writer->has_any_data(), 'The candidate exported their dossier and got nothing.');

        $data = $writer->get_data([get_string('pluginname', 'tool_vae')]);
        $this->assertNotEmpty($data->claims);
        $this->assertSame('Accompagnement au lever.', $data->claims[0]['situation']);
        $this->assertNotEmpty($data->claims[0]['attestations']);
    }

    /**
     * The context of a dossier is found for both people.
     */
    public function test_both_people_are_pointed_at_the_dossier_context(): void {
        $expected = \context_user::instance($this->candidate->id)->id;

        foreach ([$this->candidate, $this->attestant] as $user) {
            $contexts = provider::get_contexts_for_userid((int) $user->id)->get_contextids();
            $this->assertContains(
                (string) $expected,
                array_map('strval', $contexts),
                "No context found for user {$user->id}."
            );
        }
    }

    /**
     * A second manager, who asked the signatory to withdraw the attestation seeded in setUp().
     *
     * @return \stdClass The requester.
     */
    private function requester(): \stdClass {
        global $DB;

        $requester = $this->getDataGenerator()->create_user();
        $DB->execute(
            'UPDATE {tool_vae_attest} SET withdrawreqby = :by, withdrawreqat = :at WHERE evidenceid = :evidenceid',
            ['by' => $requester->id, 'at' => 1791850000, 'evidenceid' => $this->evidenceid]
        );

        return $requester;
    }

    /**
     * The manager who asked for a withdrawal is found in the dossier, and listed with it.
     */
    public function test_the_requester_of_a_withdrawal_is_found_in_the_dossier(): void {
        $requester = $this->requester();
        $context = \context_user::instance($this->candidate->id);

        $this->assertContains(
            (string) $context->id,
            array_map('strval', provider::get_contexts_for_userid((int) $requester->id)->get_contextids()),
            'The request names its author and the provider does not lead to it.'
        );

        $userlist = new \core_privacy\local\request\userlist($context, 'tool_vae');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $requester->id, array_map('intval', $userlist->get_userids()));
    }

    /**
     * The requester's export carries their request - and neither the narrative nor the signatory.
     */
    public function test_the_export_of_a_requester_holds_the_request_and_nothing_else_of_the_dossier(): void {
        $requester = $this->requester();
        $context = \context_user::instance($this->candidate->id);

        provider::export_user_data($this->approval($requester, (int) $this->candidate->id));

        $writer = \core_privacy\local\request\writer::with_context($context);
        $this->assertTrue($writer->has_any_data(), 'The requester exported and got nothing.');

        $data = $writer->get_data([get_string('pluginname', 'tool_vae')]);
        $this->assertCount(1, $data->withdrawalrequests);
        $this->assertSame('RNCP36832-C03', $data->withdrawalrequests[0]['record']);
        $this->assertEmpty($data->claims, 'A manager who only asked is not handed the candidate\'s claim.');

        // The entry holds three things and no more: which claim, which record, when.
        $this->assertSame(['reference', 'record', 'requestedat'], array_keys($data->withdrawalrequests[0]));

        $text = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Accompagnement au lever', $text);
        $this->assertStringNotContainsString('Cadre de santé', $text);
        $this->assertStringNotContainsString('Claire Dupont', $text);
    }

    /**
     * The candidate and the signatory are told that a withdrawal was asked, and when - not by whom.
     */
    public function test_the_candidate_export_says_a_withdrawal_was_asked_but_not_by_whom(): void {
        $requester = $this->requester();
        $context = \context_user::instance($this->candidate->id);

        provider::export_user_data($this->approval($this->candidate, (int) $this->candidate->id));

        $data = \core_privacy\local\request\writer::with_context($context)
            ->get_data([get_string('pluginname', 'tool_vae')]);
        $this->assertNotEmpty($data->claims[0]['attestations'][0]['withdrawalrequestedat']);
        $text = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(fullname($requester), $text);
        $this->assertStringNotContainsString('withdrawreqby', $text);
        $this->assertSame(
            [
                'piecesread', 'pieces_covered', 'role', 'statement', 'attestedat', 'withdrawnat', 'withdrawnreason',
                'recorded', 'modified', 'importedat', 'withdrawalrequestedat',
            ],
            array_keys($data->claims[0]['attestations'][0]),
            'The dates of the row, then the fact of the request and its date, no author.'
        );
        $this->assertObjectNotHasProperty('withdrawalrequests', $data, 'The requests belong to those who made them.');
    }

    /**
     * Erasing the requester clears their request and keeps the attestation - the signatory's.
     */
    public function test_erasing_the_requester_clears_the_request_and_keeps_the_attestation(): void {
        global $DB;

        $requester = $this->requester();

        provider::delete_data_for_user($this->approval($requester, (int) $this->candidate->id));

        $attestation = $DB->get_record('tool_vae_attest', ['evidenceid' => $this->evidenceid], '*', MUST_EXIST);
        $this->assertNull($attestation->withdrawreqby, 'The request still names the person who asked to be erased.');
        $this->assertNull($attestation->withdrawreqat);
        $this->assertEquals((int) $this->attestant->id, (int) $attestation->attestantid, 'Not the requester\'s to erase.');
        $this->assertNull($attestation->withdrawnat, 'Erasing a request withdraws nothing.');
        $this->assertSame('Cadre de santé, EHPAD Les Tilleuls', $attestation->attestantrole);
    }

    /**
     * The bulk path clears a request exactly as the individual one does.
     */
    public function test_the_bulk_path_clears_a_request(): void {
        global $DB;

        $requester = $this->requester();
        $context = \context_user::instance($this->candidate->id);

        provider::delete_data_for_users(new approved_userlist($context, 'tool_vae', [(int) $requester->id]));

        $this->assertNull($DB->get_field('tool_vae_attest', 'withdrawreqby', ['evidenceid' => $this->evidenceid]));
        $this->assertNull($DB->get_field('tool_vae_attest', 'withdrawreqat', ['evidenceid' => $this->evidenceid]));
        $this->assertEquals(1, $DB->count_records('tool_vae_attest', ['evidenceid' => $this->evidenceid]));
    }

    /**
     * Erasing the signatory takes the request on their signature with it.
     */
    public function test_erasing_the_signatory_leaves_no_request_on_a_withdrawn_signature(): void {
        global $DB;

        $this->requester();

        provider::delete_data_for_user($this->approval($this->attestant, (int) $this->candidate->id));

        $attestation = $DB->get_record('tool_vae_attest', ['evidenceid' => $this->evidenceid], '*', MUST_EXIST);
        $this->assertNotNull($attestation->withdrawnat);
        $this->assertNull($attestation->withdrawreqby, 'A request on a signature that is gone names someone for nothing.');
        $this->assertNull($attestation->withdrawreqat);
    }

    /**
     * The bulk path must behave exactly like the individual one.
     */
    public function test_the_bulk_path_treats_each_person_by_their_role(): void {
        global $DB;

        $context = \context_user::instance($this->candidate->id);
        $userlist = new approved_userlist($context, 'tool_vae', [(int) $this->attestant->id]);

        provider::delete_data_for_users($userlist);

        $this->assertEquals(1, $DB->count_records('tool_vae_evidence'));
        $this->assertNull($DB->get_field('tool_vae_attest', 'attestantid', ['evidenceid' => $this->evidenceid]));
    }

    /**
     * The administrator who reimported a dossier is found in it, and leaves it on erasure.
     *
     * A reimport writes their id into usermodified of every claim it rebuilds. The column is
     * declared as personal data, so it is found and erased like the others - and erasing it
     * is not erasing the claim, which is the candidate's.
     */
    public function test_the_administrator_who_reimported_a_dossier_is_found_and_leaves_it(): void {
        global $DB;

        $reimporter = $this->getDataGenerator()->create_user();
        $DB->set_field('tool_vae_evidence', 'usermodified', $reimporter->id, ['id' => $this->evidenceid]);
        $context = \context_user::instance($this->candidate->id);

        $this->assertContains(
            (string) $context->id,
            array_map('strval', provider::get_contexts_for_userid((int) $reimporter->id)->get_contextids()),
            'The reimporting administrator is named in the dossier and the provider does not lead to it.'
        );
        $userlist = new \core_privacy\local\request\userlist($context, 'tool_vae');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $reimporter->id, array_map('intval', $userlist->get_userids()));

        provider::delete_data_for_user($this->approval($reimporter, (int) $this->candidate->id));

        $evidence = $DB->get_record('tool_vae_evidence', ['id' => $this->evidenceid], '*', MUST_EXIST);
        $this->assertEquals(0, (int) $evidence->usermodified);
        $this->assertSame('Accompagnement au lever.', $evidence->situation);
        $this->assertEquals(
            (int) $this->attestant->id,
            (int) $DB->get_field('tool_vae_attest', 'attestantid', ['evidenceid' => $this->evidenceid]),
            'The attestation is someone else\'s and had to stay.'
        );
    }

    /**
     * A signatory known by ULID in a reimported attestation is found, served, and stripped.
     *
     * Their own context goes first in the request, as nothing guarantees otherwise. It takes their
     * identity map entry, the only link from their account to the ULID the attestation carries:
     * read after it, the attestation would keep their ULID, job title and statement for good.
     */
    public function test_a_signatory_known_by_ulid_is_found_served_and_stripped(): void {
        global $DB;

        $signatory = $this->getDataGenerator()->create_user();
        $attestationid = $this->imported_attestation($signatory);
        $context = \context_user::instance($this->candidate->id);

        $this->assertContains(
            (string) $context->id,
            array_map('strval', provider::get_contexts_for_userid((int) $signatory->id)->get_contextids()),
            'The signatory is named in the dossier by their ULID and the provider does not lead to it.'
        );
        $userlist = new \core_privacy\local\request\userlist($context, 'tool_vae');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $signatory->id, array_map('intval', $userlist->get_userids()));

        // Announced, so served: their request returns what they signed, and only that.
        provider::export_user_data($this->approval($signatory, (int) $this->candidate->id));
        $writer = \core_privacy\local\request\writer::with_context($context);
        $data = $writer->get_data([get_string('pluginname', 'tool_vae')]);
        $this->assertCount(1, $data->claims[0]['attestations']);
        $this->assertSame('Je soussignée Anne Martin atteste…', $data->claims[0]['attestations'][0]['statement']);

        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($signatory->id),
            'tool_vae',
            [\context_user::instance($signatory->id)->id, $context->id]
        ));
        $this->assertFalse(
            $DB->record_exists('tool_vae_identity', ['itemtype' => 'user', 'itemid' => $signatory->id]),
            'The premise: their own context went first and took their identity map entry.'
        );

        $attestation = $DB->get_record('tool_vae_attest', ['id' => $attestationid], '*', MUST_EXIST);
        $this->assertNull($attestation->attestantuuid, 'The ULID still names the signatory.');
        $this->assertNull($attestation->attestantrole, 'The job title still names the signatory.');
        $this->assertNull($attestation->statement, 'The statement still names the signatory.');
        $this->assertNull($attestation->attestedat);
        $this->assertSame('rgpd', $attestation->withdrawnreason);
        $this->assertEquals(
            1,
            $DB->count_records('tool_vae_attest', ['attestantid' => $this->attestant->id]),
            'The other attestation is someone else\'s and had to stay.'
        );
    }

    /**
     * The bulk path strips a signatory known by ULID as well.
     */
    public function test_the_bulk_path_strips_a_signatory_known_by_ulid(): void {
        global $DB;

        $signatory = $this->getDataGenerator()->create_user();
        $attestationid = $this->imported_attestation($signatory);

        provider::delete_data_for_users(new approved_userlist(
            \context_user::instance($this->candidate->id),
            'tool_vae',
            [(int) $signatory->id]
        ));

        $attestation = $DB->get_record('tool_vae_attest', ['id' => $attestationid], '*', MUST_EXIST);
        $this->assertNull($attestation->attestantuuid);
        $this->assertNull($attestation->statement);
    }

    /**
     * Erasing someone who signed nothing in a dossier leaves its backups as they are.
     *
     * A refresh replaces every backup of the dossier: new archives, without the claims deleted
     * since. Taking a signature out is worth that; zeroing usermodified, all that names the
     * administrator who reimported the dossier and something no archive carries, is not.
     */
    public function test_erasing_someone_who_signed_nothing_keeps_the_backups(): void {
        global $DB;

        $reimporter = $this->getDataGenerator()->create_user();
        $DB->set_field('tool_vae_evidence', 'usermodified', $reimporter->id, ['id' => $this->evidenceid]);

        // The mark ties a backup to its candidate (the name no longer does), so a stand-in content is enough: a refresh
        // would replace it whatever it holds. Without the mark no refresh would ever find this file, and the assertion
        // below would hold whatever the provider did.
        $personne = local\identity_map::uuid_for(local\identity_map::TYPE_USER, (int) $this->candidate->id);
        $backup = get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance((int) get_admin()->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => local\backup::FILEPATH,
            'filename' => 'dossier-' . $personne . '-' . local\identity_map::generate_ulid() . '.zip',
            'source' => local\backup::SOURCE_PREFIX . $personne,
        ], 'sauvegarde');

        provider::delete_data_for_user($this->approval($reimporter, (int) $this->candidate->id));

        $this->assertEquals(0, (int) $DB->get_field('tool_vae_evidence', 'usermodified', ['id' => $this->evidenceid]));
        $this->assertTrue(
            get_file_storage()->file_exists_by_hash($backup->get_pathnamehash()),
            'The backup was replaced although the erased person had signed nothing in the dossier.'
        );
    }

    // ----------------------------------------------------------------------
    // The site level - what the plugin announces, it has to serve.

    /**
     * The premise: the plugin does announce holding data about this person at site level.
     *
     * Everything below is only a defect BECAUSE of this. A provider that announced nothing
     * here and served nothing would be consistent, and merely incomplete.
     */
    public function test_the_site_actor_is_announced_at_system_level(): void {
        $contexts = provider::get_contexts_for_userid((int) $this->importer->id)->get_contextids();

        $this->assertContains(
            (string) \context_system::instance()->id,
            array_map('strval', $contexts),
            'The provider stopped announcing site-level data; these tests no longer mean anything.'
        );
    }

    /**
     * What is announced is served: an access request comes back with something in it.
     */
    public function test_the_site_actor_export_is_not_empty(): void {
        provider::export_user_data($this->site_approval($this->importer));

        $writer = \core_privacy\local\request\writer::with_context(\context_system::instance());

        $this->assertTrue(
            $writer->has_any_data(),
            'The site announced holding data about this person, and the plugin returned nothing.'
        );
    }

    /**
     * Erasure at site level removes the person, and only the person.
     */
    public function test_erasing_the_site_actor_removes_their_name(): void {
        global $DB;

        provider::delete_data_for_user($this->site_approval($this->importer));

        $this->assertEquals(
            0,
            (int) $DB->get_field('tool_vae_source', 'usermodified', ['id' => $this->sourceid]),
            'The erasure was reported as done and the importer is still named on the record.'
        );

        $passage = $DB->get_record('tool_vae_passage', ['id' => $this->passageid], '*', MUST_EXIST);
        $this->assertEquals(0, (int) $passage->decidedby);
        $this->assertEquals(0, (int) $passage->decidedat);
        $this->assertSame('', (string) $passage->note, 'The note names people and it was declared as such.');
    }

    /**
     * The referential itself survives the erasure of whoever imported it.
     *
     * Provenance is what makes a claim readable by a jury five years later, and the mapping
     * between two certification records is what keeps an old dossier meaningful. Neither
     * belongs to the administrator who happened to type them.
     */
    public function test_erasing_the_site_actor_keeps_the_referential(): void {
        global $DB;

        provider::delete_data_for_user($this->site_approval($this->importer));

        $source = $DB->get_record('tool_vae_source', ['id' => $this->sourceid], '*', MUST_EXIST);
        $this->assertEquals('RNCP36832', $source->fichecode);
        $this->assertEquals('LO-2.0', $source->licence);
        $this->assertEquals('France compétences', $source->attribution);

        $passage = $DB->get_record('tool_vae_passage', ['id' => $this->passageid], '*', MUST_EXIST);
        $this->assertEquals(11, (int) $passage->fromcompetencyid);
        $this->assertEquals(22, (int) $passage->tocompetencyid);
    }

    /**
     * The candidate's dossier is untouched by a site-level erasure.
     */
    public function test_erasing_the_site_actor_leaves_every_dossier_alone(): void {
        global $DB;

        provider::delete_data_for_user($this->site_approval($this->importer));

        $this->assertEquals(1, $DB->count_records('tool_vae_evidence', [
            'candidateid' => $this->candidate->id,
        ]));
        $this->assertEquals(1, $DB->count_records('tool_vae_attest', ['evidenceid' => $this->evidenceid]));
    }

    /**
     * The bulk path reaches the site level too.
     */
    public function test_the_bulk_path_reaches_the_system_context(): void {
        global $DB;

        provider::delete_data_for_users(new approved_userlist(
            \context_system::instance(),
            'tool_vae',
            [(int) $this->importer->id]
        ));

        $this->assertEquals(
            0,
            (int) $DB->get_field('tool_vae_source', 'usermodified', ['id' => $this->sourceid])
        );
    }

    /**
     * Purging the whole system context leaves no one named.
     */
    public function test_purging_the_system_context_leaves_no_named_actor(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertEquals(
            0,
            (int) $DB->get_field('tool_vae_source', 'usermodified', ['id' => $this->sourceid])
        );
        $this->assertEquals(
            0,
            (int) $DB->get_field('tool_vae_passage', 'decidedby', ['id' => $this->passageid])
        );
        $this->assertEquals(1, $DB->count_records('tool_vae_source'), 'The referential was destroyed.');
    }

    /**
     * A row of the register (lot 8 quater): the candidate's archive, produced by the importer.
     *
     * @return int The row.
     */
    private function registered_archive(): int {
        global $DB;

        return (int) $DB->insert_record(local\exporter::REGISTER, (object) [
            'archiveuuid' => local\identity_map::generate_ulid(),
            'sha256' => hash('sha256', 'manifeste'),
            'candidateuuid' => local\identity_map::uuid_for(local\identity_map::TYPE_USER, (int) $this->candidate->id),
            'userid' => $this->importer->id,
            'timecreated' => time(),
        ]);
    }

    /**
     * The register is declared, field by field, with its producer as personal data.
     */
    public function test_the_register_is_declared(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('tool_vae'));

        $declared = null;
        foreach ($collection->get_collection() as $item) {
            if ($item->get_name() === 'tool_vae_archive') {
                $declared = $item;
            }
        }

        $this->assertNotNull($declared, 'The register holds personal data and is not declared.');
        $this->assertEqualsCanonicalizing(
            ['archiveuuid', 'sha256', 'candidateuuid', 'userid', 'timecreated'],
            array_keys($declared->get_privacy_fields())
        );
    }

    /**
     * Whoever produced an archive is found at site level, served, and leaves the row on erasure;
     * the row stays, so the register still vouches for the archive.
     */
    public function test_the_producer_of_an_archive_is_found_served_and_stripped(): void {
        global $DB;

        $rowid = $this->registered_archive();
        $DB->delete_records('tool_vae_source');
        $DB->delete_records('tool_vae_passage');

        $contexts = array_map('intval', provider::get_contexts_for_userid((int) $this->importer->id)->get_contextids());
        $this->assertContains((int) \context_system::instance()->id, $contexts);

        provider::export_user_data($this->site_approval($this->importer));
        $exported = \core_privacy\local\request\writer::with_context(\context_system::instance())
            ->get_data([get_string('pluginname', 'tool_vae')]);
        $this->assertCount(1, $exported->archives);

        provider::delete_data_for_user($this->site_approval($this->importer));
        $this->assertSame(0, (int) $DB->get_field(local\exporter::REGISTER, 'userid', ['id' => $rowid], MUST_EXIST));
    }

    /**
     * Erasing the candidate takes the register's rows for their archives with the dossier.
     */
    public function test_erasing_the_candidate_removes_their_rows_of_the_register(): void {
        global $DB;

        $this->registered_archive();

        provider::delete_data_for_user($this->approval($this->candidate, (int) $this->candidate->id));

        $this->assertSame(0, $DB->count_records(local\exporter::REGISTER));
    }

    // ----------------------------------------------------------------------
    // What an access request returns, and to whom.

    /**
     * The export of a signatory holds what is theirs - the attestation, the reference of what they signed - and none of the
     * free text of the person they signed for.
     *
     * The file is written under the signatory's own request, and leaves the platform with their name on it. The narrative
     * and the detail of the setting name, or lead to, the people the candidate accompanied: they are the candidate's alone.
     */
    public function test_the_export_of_a_signatory_holds_their_attestation_and_not_the_narrative(): void {
        $context = \context_user::instance($this->candidate->id);

        provider::export_user_data($this->approval($this->attestant, (int) $this->candidate->id));

        $data = \core_privacy\local\request\writer::with_context($context)->get_data([get_string('pluginname', 'tool_vae')]);
        $this->assertCount(1, $data->claims);
        $this->assertSame('RNCP36832-C03', $data->claims[0]['record']);
        $this->assertSame('Je soussignée Claire Dupont atteste…', $data->claims[0]['attestations'][0]['statement']);
        $this->assertArrayNotHasKey('situation', $data->claims[0], 'The narrative of the candidate left in a third party\'s file.');
        $this->assertArrayNotHasKey('settingdetail', $data->claims[0], 'The setting detail left in a third party\'s file.');

        $text = json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Accompagnement au lever', $text);
        $this->assertStringNotContainsString('Unité protégée', $text);
    }

    /**
     * The export of a manager who asked a question holds the question, and that an answer was given - not what it says.
     *
     * The answer is the candidate's free text. The candidate's own export keeps all of it.
     */
    public function test_the_export_of_an_asker_holds_the_question_and_the_fact_of_an_answer_not_its_text(): void {
        global $DB;

        $asker = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('tool_vae_request', (object) [
            'uuid' => local\identity_map::generate_ulid(),
            'evidenceid' => $this->evidenceid,
            'authorid' => $asker->id,
            'question' => 'Dans quel service ?',
            'questionhash' => hash('sha256', 'Dans quel service ?'),
            'timecreated' => $now,
            'answer' => 'Au service Les Lilas, avec Mme Durand.',
            'answerhash' => hash('sha256', 'Au service Les Lilas, avec Mme Durand.'),
            'timeanswered' => $now,
        ]);
        $context = \context_user::instance($this->candidate->id);
        $path = [get_string('pluginname', 'tool_vae'), get_string('privacy:path:requests', 'tool_vae')];

        provider::export_user_data($this->approval($asker, (int) $this->candidate->id));
        $data = \core_privacy\local\request\writer::with_context($context)->get_data($path);
        $this->assertSame('Dans quel service ?', $data->requests[0]['question']);
        $this->assertArrayHasKey('answered', $data->requests[0], 'The asker is told that an answer was given, and when.');
        $this->assertArrayHasKey('answersha256', $data->requests[0]);
        $this->assertArrayNotHasKey('answer', $data->requests[0], 'The candidate\'s answer left in the asker\'s file.');
        $this->assertStringNotContainsString('Les Lilas', json_encode($data, JSON_UNESCAPED_UNICODE));

        \core_privacy\local\request\writer::reset();
        provider::export_user_data($this->approval($this->candidate, (int) $this->candidate->id));
        $data = \core_privacy\local\request\writer::with_context($context)->get_data($path);
        $this->assertSame('Au service Les Lilas, avec Mme Durand.', $data->requests[0]['answer'], 'The candidate keeps it all.');
    }

    /**
     * The candidate's export says when they declared, when they last changed it, and against which competency.
     *
     * Run where an export really runs - the scheduled task, nobody logged in - and not as an administrator: the competency
     * is read from itself, not through the core's reader, which answers nothing to a person who holds no right to view it.
     */
    public function test_the_candidate_export_dates_the_declaration_and_names_the_competency(): void {
        global $DB;

        $lpg = $this->getDataGenerator()->get_plugin_generator(local\competency_gateway::SUBSYSTEM);
        $framework = $lpg->create_framework(['shortname' => 'RNCP36832']);
        $competency = $lpg->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => 'RNCP36832-C03',
            'shortname' => 'Repérer les facteurs de risque de chute',
        ]);
        $DB->set_field('tool_vae_evidence', 'competencyid', $competency->get('id'), ['id' => $this->evidenceid]);
        $DB->set_field('tool_vae_evidence', 'timecreated', 1757000000, ['id' => $this->evidenceid]);
        $DB->set_field('tool_vae_evidence', 'timemodified', 1757100000, ['id' => $this->evidenceid]);
        $this->setUser(0);

        provider::export_user_data($this->approval($this->candidate, (int) $this->candidate->id));

        $data = \core_privacy\local\request\writer::with_context(\context_user::instance($this->candidate->id))
            ->get_data([get_string('pluginname', 'tool_vae')]);
        $this->assertSame(\core_privacy\local\request\transform::datetime(1757000000), $data->claims[0]['declared']);
        $this->assertSame(\core_privacy\local\request\transform::datetime(1757100000), $data->claims[0]['modified']);
        $this->assertSame('Repérer les facteurs de risque de chute', $data->claims[0]['competency']);
    }

    /**
     * A signature carried in from an archive is exported with the day it was carried in.
     */
    public function test_an_imported_attestation_is_exported_with_its_import_date(): void {
        $signatory = $this->getDataGenerator()->create_user();
        $this->imported_attestation($signatory);

        provider::export_user_data($this->approval($signatory, (int) $this->candidate->id));

        $data = \core_privacy\local\request\writer::with_context(\context_user::instance($this->candidate->id))
            ->get_data([get_string('pluginname', 'tool_vae')]);
        $this->assertNotNull($data->claims[0]['attestations'][0]['importedat']);
        $this->assertNotNull($data->claims[0]['attestations'][0]['recorded']);
    }

    /**
     * A section of the document is exported with the day it was created and changed, and when its draft was last saved.
     */
    public function test_a_section_is_exported_with_its_dates(): void {
        global $DB;

        $now = time();
        $DB->insert_record('tool_vae_draft', (object) [
            'uuid' => local\identity_map::generate_ulid(),
            'candidateid' => $this->candidate->id,
            'doctype' => 'faisabilite',
            'section' => 'objectifs',
            'slot' => 0,
            'fichecode' => '',
            'working' => 'Un brouillon.',
            'workingat' => $now - 60,
            'currentversion' => 0,
            'timecreated' => $now - 3600,
            'timemodified' => $now - 600,
            'usermodified' => $this->candidate->id,
        ]);

        provider::export_user_data($this->approval($this->candidate, (int) $this->candidate->id));

        $data = \core_privacy\local\request\writer::with_context(\context_user::instance($this->candidate->id))->get_data(
            [get_string('pluginname', 'tool_vae'), get_string('privacy:path:sections', 'tool_vae')]
        );
        $this->assertSame(\core_privacy\local\request\transform::datetime($now - 3600), $data->sections[0]['created']);
        $this->assertSame(\core_privacy\local\request\transform::datetime($now - 600), $data->sections[0]['modified']);
        $this->assertSame(\core_privacy\local\request\transform::datetime($now - 60), $data->sections[0]['workingat']);
    }

    /**
     * Every date a table declares as personal data is in the export: one added later without its export fails here.
     *
     * The map names, for each declared date, the key under which the export serves it. A date declared and absent from the
     * map, or in the map and absent from the export, is the contradiction the privacy API exists to prevent: a register
     * that announces data and a file that does not hand it over.
     */
    public function test_every_dated_field_declared_is_exported(): void {
        global $DB;

        $this->requester();
        $this->imported_attestation($this->getDataGenerator()->create_user());
        $now = time();
        $DB->insert_record('tool_vae_draft', (object) [
            'uuid' => local\identity_map::generate_ulid(),
            'candidateid' => $this->candidate->id,
            'doctype' => 'faisabilite',
            'section' => 'objectifs',
            'slot' => 0,
            'fichecode' => '',
            'working' => 'Un brouillon.',
            'workingat' => $now,
            'currentversion' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $this->candidate->id,
        ]);

        provider::export_user_data($this->approval($this->candidate, (int) $this->candidate->id));
        $writer = \core_privacy\local\request\writer::with_context(\context_user::instance($this->candidate->id));
        $claim = $writer->get_data([get_string('pluginname', 'tool_vae')])->claims[0];
        $section = $writer->get_data(
            [get_string('pluginname', 'tool_vae'), get_string('privacy:path:sections', 'tool_vae')]
        )->sections[0];

        // The attestation the request was made on: the only entry that carries every date, whatever order they come in.
        $asked = array_values(array_filter(
            $claim['attestations'],
            fn($entry) => array_key_exists('withdrawalrequestedat', $entry)
        ));
        $this->assertCount(1, $asked, 'The premise: one attestation carries the request.');

        $served = [
            'tool_vae_evidence' => [$claim, [
                'timecreated' => 'declared', 'timemodified' => 'modified', 'ficheupdated' => 'recordupdated',
            ]],
            'tool_vae_attest' => [$asked[0], [
                'timecreated' => 'recorded', 'timemodified' => 'modified', 'importedat' => 'importedat',
                'attestedat' => 'attestedat', 'withdrawnat' => 'withdrawnat', 'withdrawreqat' => 'withdrawalrequestedat',
            ]],
            'tool_vae_draft' => [$section, [
                'timecreated' => 'created', 'timemodified' => 'modified', 'workingat' => 'workingat',
            ]],
        ];

        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('tool_vae'));
        $checked = 0;
        foreach ($collection->get_collection() as $item) {
            if (!isset($served[$item->get_name()])) {
                continue;
            }
            [$entry, $keys] = $served[$item->get_name()];
            foreach (array_keys($item->get_privacy_fields()) as $field) {
                if (!preg_match('/^(time[a-z]+|[a-z]+at|ficheupdated)$/', $field)) {
                    continue;
                }
                $this->assertArrayHasKey(
                    $field,
                    $keys,
                    "{$item->get_name()}.$field is declared as a date and this test does not say where the export serves it."
                );
                $this->assertArrayHasKey(
                    $keys[$field],
                    $entry,
                    "{$item->get_name()}.$field is declared as personal data and the export does not serve it."
                );
                $checked++;
            }
        }
        $this->assertSame(12, $checked, 'The three tables and their twelve dates.');
    }

    /**
     * The file subsystem is linked once, however many things this plugin keeps in it.
     *
     * The register of the plugins shows one line per link: two links to the same subsystem are two lines the administrator
     * has to reconcile.
     */
    public function test_each_subsystem_is_linked_once(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('tool_vae'));

        $links = [];
        foreach ($collection->get_collection() as $item) {
            if ($item instanceof \core_privacy\local\metadata\types\subsystem_link) {
                $links[] = $item->get_name();
            }
        }

        $this->assertSame(array_unique($links), $links, 'A subsystem is linked twice: the register would list it twice.');
        $this->assertContains('core_files', $links);
    }
}
