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

use tool_vae\local\deposit_manager;
use tool_vae\local\draft_manager;
use tool_vae\local\evidence_manager;
use tool_vae\local\importer;
use tool_vae\local\opinion_manager;
use tool_vae\local\validation_catalog;

/**
 * The deposit of the preparation of the validation dossier, and its reading by the manager (T-11, lot 3).
 *
 * WHAT IS ASSERTED. That the third control of regime 2 exists: what the candidate deposited - the saved versions of every
 * record together, never a draft - is read by whoever may read deposits, as it was, after later writing; that it is numbered
 * apart from the deposits of the dossier de faisabilite; that nobody who may not read deposits reads it, and a drafts reader
 * does not either; that nobody writes an opinion on it; that it is refused while the feature is closed, empty, unchanged,
 * or with the commitment confirmed; and that the manager who follows the candidate is told, in words of its own.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\deposit_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\opinion_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\notifier::class)]
final class validation_deposit_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Follows the candidate, reads deposits and may write opinions. */
    private $manager;

    /** @var \stdClass Holds draftview only. */
    private $draftreader;

    /** @var \stdClass Holds nothing. */
    private $stranger;

    /**
     * A record with two blocs, a candidate, a manager who follows them, and the preparation open.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        validation_catalog::reset();
        set_config('validationopen', 1, 'tool_vae');
        $this->setAdminUser();

        (new importer())->import((object) [
            'fichecode' => 'RS00000', 'fichetype' => 'rs', 'name' => 'Fiche RS00000',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv(
            "rang,intitule,description,bloc\nBC01,Premier,,\nC01,Un,,BC01\nBC02,Deuxième,,\nC02,Deux,,BC02\n"
        ));

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $this->candidate = $generator->create_user();
        $this->manager = $generator->create_user();
        $this->draftreader = $generator->create_user();
        $this->stranger = $generator->create_user();
        $context = \context_user::instance($this->candidate->id)->id;

        $own = $generator->create_role();
        foreach (['declare', 'draftown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, $system, true);
        }
        role_assign($own, $this->candidate->id, $context);

        $follow = $generator->create_role();
        foreach (['viewassigned', 'readdeposit', 'writeopinion'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $follow, $system, true);
        }
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, $context);

        $drafts = $generator->create_role();
        assign_capability('tool/vae:draftview', CAP_ALLOW, $drafts, $system, true);
        role_assign($drafts, $this->draftreader->id, $context);

        accesslib_clear_all_caches_for_unit_testing();

        // The preparation is written on a record the candidate declared on.
        $this->setUser($this->candidate);
        $competency = $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-C02'], MUST_EXIST);
        (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $competency, 'periodstart' => 1756684800, 'periodend' => 1782777600,
            'settingtype' => 'ehpad', 'situation' => 'Une situation.', 'situationformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * Write a section of the preparation and save a version of it, as the candidate.
     *
     * @param string $section
     * @param string ...$versions
     * @return \stdClass The section.
     */
    private function write(string $section, string ...$versions): \stdClass {
        $this->setUser($this->candidate);
        $manager = new draft_manager();
        $draft = $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_VALIDATION, $section, 0, 'RS00000');
        foreach ($versions as $text) {
            $manager->save_version((int) $draft->id, $text);
        }

        return $draft;
    }

    /**
     * Deposit the preparation, as the candidate.
     *
     * @param string|null $note
     * @return \stdClass
     */
    private function deposit(?string $note = null): \stdClass {
        $this->setUser($this->candidate);

        return (new deposit_manager())->deposit(
            (int) $this->candidate->id,
            $note,
            false,
            draft_manager::DOCTYPE_VALIDATION
        );
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

    public function test_the_deposit_holds_the_saved_versions_and_never_a_draft(): void {
        $draft = $this->write('bc01_decrire', 'Premiere version.', 'Seconde version.');
        $this->write('bc02_analyser', 'Une analyse.');
        (new draft_manager())->save_working((int) $draft->id, 'Brouillon en cours, pas enregistre.');

        $deposit = $this->deposit('Pour relecture');

        $this->assertSame('validation', $deposit->doctype);
        $this->assertSame(1, (int) $deposit->number);
        $this->assertSame(hash('sha256', $deposit->content), $deposit->contenthash);
        $rubriques = deposit_manager::validation_rubriques($deposit);
        $this->assertSame(['bc01_decrire', 'bc02_analyser'], array_column($rubriques, 'code'));
        $this->assertSame('Seconde version.', $rubriques[0]['texte']);
        $this->assertSame(2, $rubriques[0]['version']);
        $this->assertStringNotContainsString('Brouillon en cours', $deposit->content);
        $this->assertStringNotContainsString('Premiere version', $deposit->content);
    }

    public function test_the_manager_reads_what_was_deposited_as_it_was(): void {
        $draft = $this->write('bc01_decrire', 'Texte depose.');
        $this->deposit();
        (new draft_manager())->save_version((int) $draft->id, 'Texte ecrit apres le depot.');

        $this->setUser($this->manager);
        $manager = new deposit_manager();
        $deposit = $manager->get((int) $this->candidate->id, 1, draft_manager::DOCTYPE_VALIDATION);

        $this->assertSame('Texte depose.', deposit_manager::validation_rubriques($deposit)[0]['texte']);
        $this->assertCount(1, $manager->deposits((int) $this->candidate->id, draft_manager::DOCTYPE_VALIDATION));
    }

    public function test_it_is_numbered_apart_from_the_dossier_de_faisabilite(): void {
        $this->write('bc01_decrire', 'Un texte.');
        $this->setUser($this->candidate);
        $drafts = new draft_manager();
        $draft = $drafts->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'objectifs');
        $drafts->save_version((int) $draft->id, 'Obtenir le diplome.');

        $faisabilite = (new deposit_manager())->deposit((int) $this->candidate->id);
        $validation = $this->deposit();

        $this->assertSame(1, (int) $faisabilite->number);
        $this->assertSame(1, (int) $validation->number);
        $this->assertCount(1, (new deposit_manager())->deposits((int) $this->candidate->id));
        $this->assertCount(
            1,
            (new deposit_manager())->deposits((int) $this->candidate->id, draft_manager::DOCTYPE_VALIDATION)
        );
        $this->assertSame([], deposit_manager::validation_rubriques($faisabilite));
    }

    public function test_who_may_not_read_deposits_reads_nothing(): void {
        $this->write('bc01_decrire', 'Un texte.');
        $this->deposit();

        foreach ([$this->stranger, $this->draftreader] as $user) {
            $this->setUser($user);
            $this->assert_refused(
                fn() => (new deposit_manager())->get((int) $this->candidate->id, 1, draft_manager::DOCTYPE_VALIDATION),
                'errordepositrefused'
            );
        }
    }

    public function test_the_administrator_reads_only_what_was_deposited(): void {
        $draft = $this->write('bc01_decrire', 'Texte depose.');
        $this->deposit();
        (new draft_manager())->save_working((int) $draft->id, 'Brouillon intime.');

        $this->setAdminUser();
        $deposit = (new deposit_manager())->get((int) $this->candidate->id, 1, draft_manager::DOCTYPE_VALIDATION);

        $this->assertStringNotContainsString('Brouillon intime', $deposit->content);
    }

    public function test_nobody_writes_an_opinion_on_it(): void {
        $this->write('bc01_decrire', 'Un texte.');
        $deposit = $this->deposit();

        $this->setUser($this->manager);
        $this->assertTrue(opinion_manager::can_write((int) $this->candidate->id));
        $this->assert_refused(
            fn() => (new opinion_manager())->write((int) $deposit->id, 'Un avis sur le texte.'),
            'erroropiniondenied'
        );
    }

    public function test_a_deposit_is_refused_when_closed_empty_unchanged_or_with_the_commitment(): void {
        $this->setUser($this->candidate);
        $manager = new deposit_manager();
        $id = (int) $this->candidate->id;

        $this->assert_refused(fn() => $manager->deposit($id, null, false, draft_manager::DOCTYPE_VALIDATION), 'errordepositempty');

        $this->write('bc01_decrire', 'Un texte.');
        $this->setUser($this->candidate);
        $this->assert_refused(fn() => $manager->deposit($id, null, true, draft_manager::DOCTYPE_VALIDATION), 'errordepositrefused');

        $this->deposit();
        $this->assert_refused(
            fn() => $manager->deposit($id, null, false, draft_manager::DOCTYPE_VALIDATION),
            'errordepositunchanged'
        );

        set_config('validationopen', 0, 'tool_vae');
        $this->assert_refused(
            fn() => $manager->deposit($id, null, false, draft_manager::DOCTYPE_VALIDATION),
            'errordepositrefused'
        );
    }

    public function test_an_unknown_document_is_refused(): void {
        $this->setUser($this->candidate);

        $this->assert_refused(
            fn() => (new deposit_manager())->deposit((int) $this->candidate->id, null, false, 'avis'),
            'errordepositmissing'
        );
        $this->assert_refused(
            fn() => (new deposit_manager())->deposits((int) $this->candidate->id, 'avis'),
            'errordepositmissing'
        );
    }

    public function test_only_the_candidate_deposits(): void {
        $this->write('bc01_decrire', 'Un texte.');

        $this->setUser($this->manager);
        $this->assert_refused(
            fn() => (new deposit_manager())->deposit(
                (int) $this->candidate->id,
                null,
                false,
                draft_manager::DOCTYPE_VALIDATION
            ),
            'errordepositrefused'
        );
    }

    public function test_the_manager_is_told_in_words_of_its_own_without_the_text(): void {
        $this->write('bc01_decrire', 'Une situation tres personnelle.');
        $sink = $this->redirectMessages();
        $this->preventResetByRollback();

        $this->deposit();
        $this->runAdhocTasks('\tool_vae\task\send_notification');

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertEquals($this->manager->id, $message->useridto);
        $this->assertSame('depositmade', $message->eventtype);
        $this->assertStringContainsString(fullname($this->candidate), $message->fullmessage);
        $this->assertStringContainsString('validation', strtolower($message->subject . $message->fullmessage));
        $this->assertStringNotContainsString('faisabilité', $message->subject);
        $this->assertStringNotContainsString('personnelle', $message->fullmessage . $message->fullmessagehtml);
    }
}
