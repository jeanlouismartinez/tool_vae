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
use tool_vae\local\competency_gateway;
use tool_vae\local\deposit_manager;
use tool_vae\local\draft_manager;
use tool_vae\local\evidence_manager;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\opinion_manager;
use tool_vae\local\piece_manager;
use tool_vae\local\request_manager;

/**
 * Every message of the plugin says where to act (decision of 27 October 2026).
 *
 * THE DECISION. Every message leaves the site's no-reply address - the sender is unchanged - and carries one fixed sentence,
 * just above the link to the dossier: that the message is automatic, and what the reader can do from the dossier (attest,
 * ask a question, answer, accept or refuse, read). Before it, no text said where to act, and the message that asks the
 * candidate for a precision said "you may read the question and answer it" without saying that the answer is given in the
 * dossier - while the "Reply" button of the email leads to nobody.
 *
 * WHAT IS ASSERTED, TWICE OVER.
 *   - On the strings, in English and in French: the body of each of the thirteen texts (twelve providers, the preparation of
 *     the validation dossier having words of its own) ends with the sentence below, adjacent to the link the notifier adds.
 *   - Through the notifier, for each text: the message that would leave the site, as notifier::deliver() composes it, ends with
 *     that sentence and then the link, in the plain text and in the HTML, and is sent by the site's no-reply user.
 *
 * WHY THE FRENCH IS READ FROM THE STRINGS, NOT FROM A DELIVERED MESSAGE. notifier::send() composes the message in the language
 * of its recipient only when that translation is installed on the site, and the PHPUnit site has no language pack (its data root
 * holds no lang directory): a French recipient would be sent English text here. The French is therefore read from the plugin's
 * lang/fr directly, as the other tests of this plugin do; the delivery tests below run in English.
 *
 * THE SENTENCES ARE WRITTEN OUT HERE ON PURPOSE. The wording was decided word by word; a test that read them back from the
 * strings would pass on any wording. Changing a sentence means changing it here too, which is the point.
 *
 * NO WORD OF EMAIL, NO "REPLY". The bell is not an email, and there is no box to reply in: "Reply in Moodle" would promise what
 * the screen does not do. One test reads every body of the plugin, in both languages, for those words.
 *
 * A NEW MESSAGE NEEDS ITS SENTENCE. The last test compares the providers of db/messages.php and the notify*_body strings with
 * the table below: a message added without its sentence fails here, and so does a sentence kept for a message that is gone.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\notifier::class)]
final class message_pointer_test extends \advanced_testcase {
    /** @var string[] The sentence that opens the pointer of every message, by language. */
    private const AUTOMATIC = [
        'en' => 'This message is automatic.',
        'fr' => 'Ce message est automatique.',
    ];

    /**
     * What the reader can do from the dossier, by text, in each language: the end of the body, after AUTOMATIC.
     *
     * The keys are the names of the strings (notify<key>_body): the providers of db/messages.php, and
     * validationdepositmade, the words of the provider depositmade when the document deposited is the preparation of the
     * validation dossier. The verbs are those the dossier really offers to the recipient of that message: a deposit is only
     * read, a request to withdraw is accepted or refused, a precision is answered in the dossier, a declaration is attested
     * or asked about.
     *
     * @var array[]
     */
    private const CLAUSES = [
        'declarationpending' => [
            'en' => 'To attest the declaration or ask a question, open the dossier with the link below.',
            'fr' => 'Pour attester la déclaration ou poser une question, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'pieceafterattest' => [
            'en' => 'To read the piece, open the dossier with the link below.',
            'fr' => 'Pour lire la pièce, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'depositmade' => [
            'en' => 'To read the deposit, open the dossier with the link below.',
            'fr' => 'Pour lire le dépôt, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'validationdepositmade' => [
            'en' => 'To read the preparation, open the dossier with the link below.',
            'fr' => 'Pour lire la préparation, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'opinionmade' => [
            'en' => 'To read the opinion, open the dossier with the link below.',
            'fr' => 'Pour lire l\'avis, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'declarationoverdue' => [
            'en' => 'To attest or ask a question, open the dossier with the link below.',
            'fr' => 'Pour attester ou poser une question, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'precisionasked' => [
            'en' => 'To read the question and answer it, open the dossier with the link below: '
                . 'the answer is given in the dossier.',
            'fr' => 'Pour lire la question et y répondre, ouvrez le dossier avec le lien ci-dessous : '
                . 'la réponse se donne dans le dossier.',
        ],
        'evidenceattested' => [
            'en' => 'To read the attestation, open the dossier with the link below.',
            'fr' => 'Pour lire l\'attestation, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'attestationwithdrawn' => [
            'en' => 'To read the date and the reason of the withdrawal, open the dossier with the link below.',
            'fr' => 'Pour lire la date et le motif du retrait, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'withdrawalrequested' => [
            'en' => 'To accept or refuse, open the dossier with the link below.',
            'fr' => 'Pour accepter ou refuser, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'withdrawalrefused' => [
            'en' => 'To read the attestation, open the dossier with the link below.',
            'fr' => 'Pour lire l\'attestation, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'declarationpurged' => [
            'en' => 'To read the dossier, open it with the link below.',
            'fr' => 'Pour lire le dossier, ouvrez-le avec le lien ci-dessous.',
        ],
        'competencyleftrecord' => [
            'en' => 'To read your declarations, open the dossier with the link below.',
            'fr' => 'Pour lire vos déclarations, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'attestationswithdrawn' => [
            'en' => 'To read the date and the reason of the withdrawal, open the dossier with the link below.',
            'fr' => 'Pour lire la date et le motif du retrait, ouvrez le dossier avec le lien ci-dessous.',
        ],
        'declarationsawaitagain' => [
            'en' => 'To attest the declarations or ask a question, open the dossier with the link below.',
            'fr' => 'Pour attester les déclarations ou poser une question, ouvrez le dossier avec le lien ci-dessous.',
        ],
    ];

    /** @var string The reference of the competency seeded in setUp(), as the messages name it. */
    private const COMPETENCY_ID_NUMBER = 'RNCP36832-C03';

    /** @var \stdClass The candidate whose dossier the messages are about. */
    private $candidate;

    /** @var \stdClass Follows the candidate and may attest: the one to be told. */
    private $manager;

    /** @var int The "Responsable VAE" role the manager holds in the candidate's context. */
    private $managerrole;

    /** @var int The competency of the record seeded in setUp(). */
    private $competencyid;

    /** @var int|null The role every candidate of these tests holds on their own context. */
    private $candidaterole = null;

    /** @var \phpunit_message_sink */
    private $sink;

    /**
     * A record, a candidate, and a manager who follows them the way the README sets it up.
     *
     * The same fixtures as notifier_test: the managers' APIs are the ones that test already drives to a delivered message.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();
        require_once($CFG->libdir . '/formslib.php');

        $generator = $this->getDataGenerator();
        $generator->get_plugin_generator('tool_vae')->create_source([
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
        ]);
        $this->competencyid = (int) $DB->get_field(
            'competency',
            'id',
            ['idnumber' => self::COMPETENCY_ID_NUMBER],
            MUST_EXIST
        );

        $this->candidate = $generator->create_user(['firstname' => 'Camille', 'lastname' => 'Martin']);
        $this->make_candidate($this->candidate);

        // The "Responsable VAE" role of the README: follow, and attest, in the candidate's own user context.
        $this->managerrole = $generator->create_role();
        $system = \context_system::instance()->id;
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $this->managerrole, $system, true);
        assign_capability('tool/vae:attest', CAP_ALLOW, $this->managerrole, $system, true);
        set_role_contextlevels($this->managerrole, [CONTEXT_USER]);

        $this->manager = $generator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        role_assign($this->managerrole, $this->manager->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->sink = $this->redirectMessages();
    }

    /**
     * Give a user what a candidate holds: declare, and read their own dossier.
     *
     * @param \stdClass $user
     */
    private function make_candidate(\stdClass $user): void {
        if ($this->candidaterole === null) {
            $this->candidaterole = $this->getDataGenerator()->create_role();
            $system = \context_system::instance()->id;
            assign_capability('tool/vae:declare', CAP_ALLOW, $this->candidaterole, $system, true);
            assign_capability('tool/vae:viewown', CAP_ALLOW, $this->candidaterole, $system, true);
        }

        role_assign($this->candidaterole, $user->id, \context_user::instance($user->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * One declaration against a competency, as the candidate.
     *
     * @param \stdClass $user
     * @param int $competencyid
     * @return \stdClass
     */
    private function declare_as(\stdClass $user, int $competencyid): \stdClass {
        $this->setUser($user);

        return (new evidence_manager())->declare_activity((int) $user->id, (object) [
            'competencyid' => $competencyid,
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'settingdetail' => 'Unité Les Glycines',
            'situation' => 'Toilette au lever.',
            'situationformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * Attest a declaration as the manager.
     *
     * @param \stdClass $claim
     * @return \stdClass The attestation.
     */
    private function attest(\stdClass $claim): \stdClass {
        $this->setUser($this->manager);

        return (new attestation_manager())->attest((int) $claim->id, (object) [
            'attestantrole' => 'Cadre de santé',
            'statement' => 'Je soussigné atteste avoir encadré cette personne.',
        ]);
    }

    /**
     * The notifications waiting in the queue for one provider.
     *
     * @param string $provider
     * @return \core\task\adhoc_task[]
     */
    private function queued(string $provider): array {
        return array_values(array_filter(
            \core\task\manager::get_adhoc_tasks(\tool_vae\task\send_notification::class),
            function ($task) use ($provider) {
                return $task->get_custom_data()->provider === $provider;
            }
        ));
    }

    /**
     * The single message caught for one provider of this plugin.
     *
     * @param string $provider
     * @return \stdClass
     */
    private function only_message(string $provider): \stdClass {
        $messages = array_values($this->sink->get_messages_by_component_and_type('tool_vae', $provider));
        $this->assertCount(1, $messages, "The $provider message was not handed to the messaging subsystem exactly once.");

        return $messages[0];
    }

    /**
     * Deliver the one task queued for a provider, as cron would, and return the message it handed to the messaging subsystem.
     *
     * One task, not all of them: the others queued on the way (a declaration pending that an attestation has made untrue) are
     * withheld and say so on the output, and what is read here is the one message of the scenario.
     *
     * @param string $provider
     * @return \stdClass
     */
    private function deliver_one(string $provider): \stdClass {
        $tasks = $this->queued($provider);
        $this->assertCount(1, $tasks, "Nothing, or more than one message, was queued for $provider.");
        $this->assertTrue(notifier::deliver($tasks[0]->get_custom_data()), "The $provider message was withheld at delivery.");

        return $this->only_message($provider);
    }

    /**
     * An import of an invented record, as notifier_test makes them.
     *
     * @param array $rows rank => label
     */
    private function record(array $rows): void {
        $this->setAdminUser();

        $lines = [];
        foreach ($rows as $rang => $intitule) {
            $lines[] = (object) ['rang' => $rang, 'intitule' => $intitule, 'description' => ''];
        }

        (new importer())->import((object) [
            'fichecode' => 'RS00000',
            'fichetype' => 'rs',
            'name' => 'Certification inventée pour les tests',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'Personne - jeu d\'essai',
        ], $lines);
    }

    /**
     * The candidate may write and deposit; the manager who follows them may also read deposits.
     */
    private function deposit_rights(): void {
        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $candidatecontext = \context_user::instance($this->candidate->id)->id;

        $writer = $generator->create_role();
        assign_capability('tool/vae:draftown', CAP_ALLOW, $writer, $system, true);
        role_assign($writer, $this->candidate->id, $candidatecontext);

        $reader = $generator->create_role();
        assign_capability('tool/vae:readdeposit', CAP_ALLOW, $reader, $system, true);
        set_role_contextlevels($reader, [CONTEXT_USER]);
        role_assign($reader, $this->manager->id, $candidatecontext);

        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Write one section of the dossier de faisabilité and deposit it, as the candidate.
     */
    private function deposit_as_candidate(): void {
        $this->setUser($this->candidate);
        $drafts = new draft_manager();
        $draft = $drafts->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'objectifs');
        $drafts->save_version((int) $draft->id, 'Un objectif.');

        (new deposit_manager())->deposit((int) $this->candidate->id);
    }

    /**
     * A colleague of the manager, who follows the candidate and may attest, asks the manager to withdraw a signature.
     *
     * @return \stdClass[] The attestation, then the colleague.
     */
    private function colleague_asks(): array {
        $colleague = $this->getDataGenerator()->create_user(['firstname' => 'Hugo', 'lastname' => 'Marchand']);
        role_assign($this->managerrole, $colleague->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $attestation = $this->attest($this->declare_as($this->candidate, $this->competencyid));
        $this->setUser($colleague);
        (new attestation_manager())->request_withdrawal((int) $attestation->id);

        return [$attestation, $colleague];
    }

    // The fifteen scenarios. Each leaves the site in the state that makes the message true, and returns the message the
    // notifier handed to the messaging subsystem for it.

    /**
     * A declaration awaits the attestation of the manager.
     *
     * @return \stdClass
     */
    private function scenario_declarationpending(): \stdClass {
        $this->declare_as($this->candidate, $this->competencyid);

        return $this->deliver_one(notifier::DECLARATION_PENDING);
    }

    /**
     * A supporting piece is added to a declaration that already carries a signature.
     *
     * @return \stdClass
     */
    private function scenario_pieceafterattest(): \stdClass {
        set_config('piecesopen', 1, 'tool_vae');
        set_config('piecenoantivirus', 1, 'tool_vae');

        $claim = $this->declare_as($this->candidate, $this->competencyid);
        $this->attest($claim);
        $this->setUser($this->candidate);
        (new piece_manager())->add_link((int) $claim->id, 'diploma', '', 'https://exemple.test/a');

        return $this->deliver_one(notifier::PIECE_AFTER_ATTEST);
    }

    /**
     * The candidate deposits the dossier de faisabilité.
     *
     * @return \stdClass
     */
    private function scenario_depositmade(): \stdClass {
        $this->deposit_rights();
        $this->deposit_as_candidate();

        return $this->deliver_one(notifier::DEPOSIT_MADE);
    }

    /**
     * The candidate deposits the preparation of the validation dossier.
     *
     * The message asks only that the deposit exist, that the manager follow the candidate and may read deposits: the row is
     * written here rather than made by the preparation's own path, which needs a record with blocs, the catalogue of the
     * certifier's rules and sections written - none of which the sentence under test depends on.
     *
     * @return \stdClass
     */
    private function scenario_validationdepositmade(): \stdClass {
        global $DB;

        $this->deposit_rights();
        $DB->insert_record('tool_vae_deposit', (object) [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => (int) $this->candidate->id,
            'doctype' => draft_manager::DOCTYPE_VALIDATION,
            'number' => 1,
            'content' => '{}',
            'contenthash' => hash('sha256', '{}'),
            'timecreated' => time(),
        ]);

        $this->assertTrue(notifier::deliver((object) [
            'provider' => notifier::DEPOSIT_MADE,
            'recipientid' => (int) $this->manager->id,
            'candidateid' => (int) $this->candidate->id,
            'number' => 1,
            'doctype' => draft_manager::DOCTYPE_VALIDATION,
        ]), 'The message about the preparation of the validation dossier was withheld at delivery.');

        return $this->only_message(notifier::DEPOSIT_MADE);
    }

    /**
     * A manager writes an opinion on the candidate's deposit.
     *
     * @return \stdClass
     */
    private function scenario_opinionmade(): \stdClass {
        $this->deposit_rights();
        $write = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:writeopinion', CAP_ALLOW, $write, \context_system::instance()->id, true);
        set_role_contextlevels($write, [CONTEXT_USER]);
        role_assign($write, $this->manager->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->deposit_as_candidate();
        $this->setUser($this->manager);
        $deposit = (new deposit_manager())->get((int) $this->candidate->id, 1);
        (new opinion_manager())->write((int) $deposit->id, 'Un avis de lecture.');

        return $this->deliver_one(notifier::OPINION_MADE);
    }

    /**
     * A declaration has waited for an attestation for the number of days the administrator chose.
     *
     * @return \stdClass
     */
    private function scenario_declarationoverdue(): \stdClass {
        global $DB;

        $claim = $this->declare_as($this->candidate, $this->competencyid);
        $DB->set_field('tool_vae_evidence', 'timecreated', time() - 15 * DAYSECS, ['id' => $claim->id]);
        $this->assertSame(1, notifier::declarations_overdue((int) $this->candidate->id, 14), 'The manager was not queued.');

        return $this->deliver_one(notifier::DECLARATION_OVERDUE);
    }

    /**
     * A manager asks the candidate for a precision.
     *
     * @return \stdClass
     */
    private function scenario_precisionasked(): \stdClass {
        $claim = $this->declare_as($this->candidate, $this->competencyid);
        $this->setUser($this->manager);
        (new request_manager())->ask((int) $claim->id, 'Pouvez-vous préciser la période ?');

        return $this->deliver_one(notifier::PRECISION_ASKED);
    }

    /**
     * A manager attests the declaration.
     *
     * @return \stdClass
     */
    private function scenario_evidenceattested(): \stdClass {
        $this->attest($this->declare_as($this->candidate, $this->competencyid));

        return $this->deliver_one(notifier::EVIDENCE_ATTESTED);
    }

    /**
     * The administrator withdraws the attestation.
     *
     * @return \stdClass
     */
    private function scenario_attestationwithdrawn(): \stdClass {
        $attestation = $this->attest($this->declare_as($this->candidate, $this->competencyid));
        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');

        return $this->deliver_one(notifier::ATTESTATION_WITHDRAWN);
    }

    /**
     * A colleague asks the signatory to withdraw their attestation.
     *
     * @return \stdClass
     */
    private function scenario_withdrawalrequested(): \stdClass {
        $this->colleague_asks();

        return $this->deliver_one(notifier::WITHDRAWAL_REQUESTED);
    }

    /**
     * The signatory refuses.
     *
     * @return \stdClass
     */
    private function scenario_withdrawalrefused(): \stdClass {
        [$attestation] = $this->colleague_asks();
        $this->setUser($this->manager);
        (new attestation_manager())->refuse_withdrawal((int) $attestation->id);

        return $this->deliver_one(notifier::WITHDRAWAL_REFUSED);
    }

    /**
     * The candidate deletes, in the core's own screen, the evidence record the declaration hung off.
     *
     * @return \stdClass
     */
    private function scenario_declarationpurged(): \stdClass {
        $claim = $this->declare_as($this->candidate, $this->competencyid);
        $this->attest($claim);
        $this->setUser($this->candidate);
        (new competency_gateway())->delete_evidence((int) $claim->userevidenceid);

        return $this->deliver_one(notifier::DECLARATION_PURGED);
    }

    /**
     * A competency the candidate declared against leaves its record.
     *
     * @return \stdClass
     */
    private function scenario_competencyleftrecord(): \stdClass {
        global $DB;

        $this->record(['BC01' => 'Accompagner la personne', 'BC02' => 'Repérer les évolutions']);
        $bc01 = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-BC01'], MUST_EXIST);
        $this->declare_as($this->candidate, $bc01);
        $this->record(['BC02' => 'Repérer les évolutions']);

        return $this->deliver_one(notifier::COMPETENCY_LEFT_RECORD);
    }

    /**
     * The data of the manager who attested are erased: what the privacy provider hands to the plugin when Moodle approves.
     *
     * The attestation is given, then withdrawn by the erasure; the messages are queued, and are delivered by the scenarios.
     */
    private function erase_the_manager_who_attested(): void {
        // Two declarations: with one, the messages are the ordinary ones in the singular (the text of a pending declaration),
        // and the plural bodies, the ones of this erasure, are only used from two.
        $this->attest($this->declare_as($this->candidate, $this->competencyid));
        $this->attest($this->declare_as($this->candidate, $this->competencyid));

        $this->setAdminUser();
        \tool_vae\privacy\provider::delete_data_for_user(new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($this->manager->id),
            'tool_vae',
            [\context_user::instance($this->candidate->id)->id]
        ));
    }

    /**
     * Deliver the one task queued under a provider of the erasure, and return the one message of a type that it handed over.
     *
     * The queue keeps the provider of the notifier; the message goes out under the type of Moodle that already exists.
     *
     * @param string $queued The provider the task was queued under.
     * @param string $type The message type it is sent as.
     * @return \stdClass
     */
    private function deliver_the_erasure_task(string $queued, string $type): \stdClass {
        $tasks = $this->queued($queued);
        $this->assertCount(1, $tasks, "Nothing, or more than one message, was queued for $queued.");
        $this->assertTrue(notifier::deliver($tasks[0]->get_custom_data()), "The $queued message was withheld at delivery.");

        return $this->only_message($type);
    }

    /**
     * The vaeiste is told, once, that attestations of their dossier were withdrawn by an erasure.
     *
     * @return \stdClass
     */
    private function scenario_attestationswithdrawn(): \stdClass {
        $this->erase_the_manager_who_attested();

        return $this->deliver_the_erasure_task(notifier::ATTESTATIONS_ERASED, notifier::ATTESTATION_WITHDRAWN);
    }

    /**
     * A colleague of the erased manager, who follows the candidate and may attest, is told the declarations await again.
     *
     * @return \stdClass
     */
    private function scenario_declarationsawaitagain(): \stdClass {
        $colleague = $this->getDataGenerator()->create_user(['firstname' => 'Hugo', 'lastname' => 'Marchand']);
        role_assign($this->managerrole, $colleague->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->erase_the_manager_who_attested();

        return $this->deliver_the_erasure_task(notifier::DECLARATIONS_AWAIT_AGAIN, notifier::DECLARATION_PENDING);
    }

    /**
     * The message of a text, as the notifier hands it to the messaging subsystem.
     *
     * @param string $text A key of CLAUSES.
     * @return \stdClass
     */
    private function message_of(string $text): \stdClass {
        $method = 'scenario_' . $text;
        $this->assertTrue(method_exists($this, $method), "No scenario drives the message '$text'.");

        return $this->$method();
    }

    /**
     * The body of a text in one language, with values like those of a real message.
     *
     * @param string $text A key of CLAUSES.
     * @param string $lang
     * @return string
     */
    private function body(string $text, string $lang): string {
        $strings = self::strings_of($lang);
        $values = [
            'candidate' => 'Camille Martin',
            'record' => self::COMPETENCY_ID_NUMBER,
            'number' => 3,
            'days' => 14,
            'count' => 2,
            'requester' => 'Hugo Marchand',
            'relay' => str_replace('{$a}', 'Nadia Benali', $strings['notifyrelay_some']),
        ];

        $body = $strings["notify{$text}_body"];
        foreach ($values as $name => $value) {
            $body = str_replace('{$a->' . $name . '}', (string) $value, $body);
        }

        return $body;
    }

    /**
     * The strings of the plugin in one language, read from its own file.
     *
     * Read from the file and not through the string manager: the French pack is not installed in the test site, and the
     * manager then answers in English whatever language is asked, which made every French check read English text.
     *
     * @param string $lang en or fr.
     * @return string[]
     */
    private static function strings_of(string $lang): array {
        $string = [];
        include(__DIR__ . '/../lang/' . $lang . '/tool_vae.php');

        return $string;
    }

    /**
     * The fifteen texts: the twelve providers, the preparation of the validation dossier that has words of its own, and the two
     * that tell of an erasure (they are sent under types that exist).
     *
     * @return array[]
     */
    public static function texts(): array {
        $cases = [];
        foreach (array_keys(self::CLAUSES) as $text) {
            $cases[$text] = [$text];
        }

        return $cases;
    }

    /**
     * The thirteen texts in each of the two languages.
     *
     * @return array[]
     */
    public static function texts_in_each_language(): array {
        $cases = [];
        foreach (['en', 'fr'] as $lang) {
            foreach (array_keys(self::CLAUSES) as $text) {
                $cases["$lang $text"] = [$lang, $text];
            }
        }

        return $cases;
    }

    /**
     * The body of a text ends with the sentence that says where to act, in each language.
     *
     * Ends with it: the notifier adds the link right after the body, so the sentence is the one just above the link.
     *
     * @param string $lang
     * @param string $text
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('texts_in_each_language')]
    public function test_the_body_ends_with_the_sentence_that_says_where_to_act(string $lang, string $text): void {
        $body = $this->body($text, $lang);

        $this->assertStringNotContainsString('{$a', $body, "lang/$lang notify{$text}_body leaves a value unreplaced.");
        $this->assertStringEndsWith(
            self::AUTOMATIC[$lang] . ' ' . self::CLAUSES[$text][$lang],
            $body,
            "lang/$lang notify{$text}_body does not end with the sentence that says where to act."
        );
    }

    /**
     * The message that asks the candidate for a precision says that the answer is given in the dossier.
     *
     * It used to say "you may read the question and answer it", and the "Reply" button of the email leads to nobody.
     */
    public function test_the_precision_message_says_the_answer_is_given_in_the_dossier(): void {
        $this->assertStringContainsString('dans le dossier', $this->body('precisionasked', 'fr'));
        $this->assertStringContainsString('in the dossier', $this->body('precisionasked', 'en'));
    }

    /**
     * No body of the plugin speaks of an email or tells the reader to reply, in either language.
     *
     * The bell is not an email, and the dossier has no box to reply in. Read over every notify*_body string, so that a body
     * added later is read too. This cannot fail on a text that never had those words: it guards the wording of the pointer.
     */
    public function test_no_body_speaks_of_an_email_or_tells_to_reply(): void {
        foreach (['en', 'fr'] as $lang) {
            $strings = self::strings_of($lang);
            $bodies = array_filter(
                $strings,
                fn($key) => preg_match('/^notify.+_body$/', $key) === 1,
                ARRAY_FILTER_USE_KEY
            );
            $this->assertNotEmpty($bodies, "lang/$lang holds no message body.");

            foreach ($bodies as $key => $body) {
                $this->assertDoesNotMatchRegularExpression(
                    '/courriel|e-?mail|répondez|repondez|\breply\b/iu',
                    $body,
                    "lang/$lang $key speaks of an email or tells the reader to reply."
                );
            }
        }
    }

    /**
     * Every provider of db/messages.php and every body string has its sentence in the table above, and the table keeps none
     * for a message that is gone.
     */
    public function test_every_message_of_the_plugin_has_its_sentence(): void {
        $messageproviders = [];
        require(__DIR__ . '/../db/messages.php');
        $texts = array_keys(self::CLAUSES);
        $bodies = [];
        foreach (array_keys(get_string_manager()->load_component_strings('tool_vae', 'en')) as $key) {
            if (preg_match('/^notify(.+)_body$/', $key, $found) === 1) {
                $bodies[] = $found[1];
            }
        }

        $todo = ' has no sentence that says where to act: add it to CLAUSES, to its body and to a scenario.';
        $this->assertSame(
            [],
            array_values(array_diff(array_keys($messageproviders), $texts)),
            'A provider of db/messages.php' . $todo
        );
        $this->assertSame(
            [],
            array_values(array_diff($bodies, $texts)),
            'A notify*_body string' . $todo
        );
        $this->assertSame(
            [],
            array_values(array_diff($texts, $bodies)),
            'CLAUSES keeps a sentence for a message that has no body string.'
        );
    }

    /**
     * The message that leaves the site ends with the sentence, then the link, and is sent by the no-reply user.
     *
     * Through the notifier, in English (see the class comment): the plain text is the body, a blank line and the address of the
     * dossier; the HTML has the body in one paragraph and the link in the next. The sender is unchanged by this decision.
     *
     * @param string $text
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('texts')]
    public function test_each_message_ends_with_the_sentence_then_the_link(string $text): void {
        $message = $this->message_of($text);
        $url = (new \moodle_url('/admin/tool/vae/dossier.php', ['userid' => $this->candidate->id]))->out(false);
        $pointer = self::AUTOMATIC['en'] . ' ' . self::CLAUSES[$text]['en'];

        $this->assertEquals(
            \core_user::NOREPLY_USER,
            $message->useridfrom,
            "The $text message is not sent by the site's no-reply user."
        );
        $this->assertStringEndsWith(
            $pointer . "\n\n" . $url,
            $message->fullmessage,
            "The $text message does not end with the sentence that says where to act, then the link."
        );
        $this->assertStringContainsString(
            s($pointer) . '</p><p><a href="',
            $message->fullmessagehtml,
            "The HTML of the $text message does not put the sentence just above the link."
        );
        $this->assertSame($url, $message->contexturl);
    }
}
