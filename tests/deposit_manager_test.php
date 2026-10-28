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
use core_privacy\local\request\writer;
use tool_vae\event\deposit_created;
use tool_vae\local\deposit_manager;
use tool_vae\local\draft_manager;
use tool_vae\local\faisabilite_fields;
use tool_vae\local\purge;
use tool_vae\privacy\provider;

/**
 * The deposit of the dossier de faisabilité (T-21, lot 1).
 *
 * WHAT IS ASSERTED. That a deposit is a milestone and not a lock: dated, hashed, never changed, one
 * per change; that it holds what was SAVED and never a draft; that the candidate deposits alone and
 * a reader needs a right of their own; that the identity inside it stays behind identityview.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\deposit_manager::class)]
final class deposit_manager_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Holds nothing. */
    private $stranger;

    /** @var \stdClass Follows the candidate (viewassigned) and holds tool/vae:readdeposit in their context. */
    private $reader;

    /** @var \stdClass Follows the candidate and holds readdeposit and identityview. */
    private $identityreader;

    /** @var \stdClass Holds draftview only. */
    private $draftreader;

    /**
     * A candidate who writes and deposits, and readers with different rights.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;

        $this->candidate = $generator->create_user();
        $this->stranger = $generator->create_user();
        $this->reader = $generator->create_user();
        $this->identityreader = $generator->create_user();
        $this->draftreader = $generator->create_user();
        $context = \context_user::instance($this->candidate->id)->id;

        $own = $generator->create_role();
        assign_capability('tool/vae:draftown', CAP_ALLOW, $own, $system, true);
        role_assign($own, $this->candidate->id, $context);

        // Reading a deposit needs the right to read the dossier first (viewany, or following the candidate): the readers of
        // the fixture follow the candidate, as the managers the plugin means do.
        $read = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $read, $system, true);
        assign_capability('tool/vae:readdeposit', CAP_ALLOW, $read, $system, true);
        role_assign($read, $this->reader->id, $context);
        role_assign($read, $this->identityreader->id, $context);

        $identity = $generator->create_role();
        assign_capability('tool/vae:identityview', CAP_ALLOW, $identity, $system, true);
        role_assign($identity, $this->identityreader->id, $context);

        $drafts = $generator->create_role();
        assign_capability('tool/vae:draftview', CAP_ALLOW, $drafts, $system, true);
        role_assign($drafts, $this->draftreader->id, $context);

        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Save a version of a section as the candidate.
     *
     * @param string $section
     * @param int $slot
     * @param string $content
     */
    private function write(string $section, int $slot, string $content): void {
        $this->setUser($this->candidate);
        $manager = new draft_manager();
        $draft = $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, $section, $slot);
        $manager->save_version((int) $draft->id, $content);
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

    public function test_a_deposit_is_numbered_dated_and_hashed(): void {
        $this->write('objectifs', 0, 'Obtenir le diplome.');
        $this->setUser($this->candidate);

        $before = time();
        $deposit = (new deposit_manager())->deposit((int) $this->candidate->id, '  Pour relecture  ');

        $this->assertSame(1, (int) $deposit->number);
        $this->assertSame('Pour relecture', $deposit->note);
        $this->assertSame(hash('sha256', $deposit->content), $deposit->contenthash);
        $this->assertGreaterThanOrEqual($before, (int) $deposit->timecreated);
        $this->assertSame(26, strlen($deposit->uuid));
    }

    public function test_a_deposit_holds_what_was_saved_and_never_a_draft(): void {
        $this->write('objectifs', 0, 'Un premier jet.');
        $this->write('objectifs', 0, 'Obtenir le diplome.');
        $this->write('experiences', 2, 'Accueil de jour.');
        $manager = new draft_manager();
        $draft = $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'parcours');
        $manager->save_working((int) $draft->id, 'Brouillon-Secret');

        $deposit = (new deposit_manager())->deposit((int) $this->candidate->id);

        $sections = deposit_manager::sections($deposit, true);
        $this->assertSame(
            [['objectifs', 0, 2, 'Obtenir le diplome.'], ['experiences', 2, 1, 'Accueil de jour.']],
            array_map(function (array $s): array {
                return [$s['code'], $s['slot'], $s['version'], $s['content']];
            }, $sections)
        );
        $this->assertStringNotContainsString('Brouillon-Secret', $deposit->content);
        $this->assertStringNotContainsString('Un premier jet', $deposit->content);
    }

    public function test_nothing_to_deposit_is_refused(): void {
        $this->setUser($this->candidate);

        $this->assert_refused(function () {
            (new deposit_manager())->deposit((int) $this->candidate->id);
        }, 'errordepositempty');

        // A draft in progress is not something to deposit.
        $manager = new draft_manager();
        $draft = $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'parcours');
        $manager->save_working((int) $draft->id, 'Un brouillon.');
        $this->assert_refused(function () {
            (new deposit_manager())->deposit((int) $this->candidate->id);
        }, 'errordepositempty');
    }

    public function test_an_unchanged_dossier_is_not_deposited_twice(): void {
        global $DB;

        $this->write('objectifs', 0, 'Obtenir le diplome.');
        $manager = new deposit_manager();
        $manager->deposit((int) $this->candidate->id);

        $this->assert_refused(function () use ($manager) {
            $manager->deposit((int) $this->candidate->id);
        }, 'errordepositunchanged');
        $this->assertSame(1, $DB->count_records('tool_vae_deposit'));

        $this->write('objectifs', 0, 'Obtenir le diplome et le montrer.');
        $second = $manager->deposit((int) $this->candidate->id);
        $this->assertSame(2, (int) $second->number);
    }

    public function test_an_earlier_deposit_is_never_changed_by_later_writing(): void {
        $this->write('objectifs', 0, 'Premiere redaction.');
        $manager = new deposit_manager();
        $first = $manager->deposit((int) $this->candidate->id);

        $this->write('objectifs', 0, 'Seconde redaction.');
        $manager->deposit((int) $this->candidate->id);

        $again = $manager->get((int) $this->candidate->id, 1);
        $this->assertSame($first->content, $again->content);
        $this->assertSame($first->contenthash, $again->contenthash);
        $this->assertSame([2, 1], array_keys($manager->deposits((int) $this->candidate->id)));
    }

    public function test_the_candidate_alone_deposits(): void {
        $this->write('objectifs', 0, 'Obtenir le diplome.');
        $manager = new deposit_manager();

        foreach ([$this->stranger, $this->reader, $this->draftreader] as $user) {
            $this->setUser($user);
            $this->assert_refused(function () use ($manager) {
                $manager->deposit((int) $this->candidate->id);
            }, 'errordepositrefused');
        }

        // The site administrator holds every capability and still does not deposit for them.
        $this->setAdminUser();
        $this->assert_refused(function () use ($manager) {
            $manager->deposit((int) $this->candidate->id);
        }, 'errordepositrefused');
    }

    public function test_who_reads_the_deposits(): void {
        $this->write('objectifs', 0, 'Obtenir le diplome.');
        (new deposit_manager())->deposit((int) $this->candidate->id);
        $id = (int) $this->candidate->id;

        foreach ([$this->candidate, $this->reader, $this->identityreader] as $user) {
            $this->setUser($user);
            $this->assertTrue(deposit_manager::can_read($id));
            $this->assertCount(1, (new deposit_manager())->deposits($id));
        }
        $this->setAdminUser();
        $this->assertTrue(deposit_manager::can_read($id));

        // Reading the drafts is not reading the deposits, and a stranger reads neither.
        foreach ([$this->draftreader, $this->stranger] as $user) {
            $this->setUser($user);
            $this->assertFalse(deposit_manager::can_read($id));
            $this->assert_refused(function () use ($id) {
                (new deposit_manager())->deposits($id);
            }, 'errordepositrefused');
        }
    }

    /**
     * A user who holds the given capabilities through a role assigned in the given context.
     *
     * @param string[] $capabilities Names without the component.
     * @param \context $context Where the role is assigned.
     * @return \stdClass
     */
    private function holder_of(array $capabilities, \context $context): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $roleid = $generator->create_role();
        foreach ($capabilities as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $user;
    }

    public function test_readdeposit_held_at_system_level_opens_nothing(): void {
        $this->write('objectifs', 0, 'Obtenir le diplome.');
        (new deposit_manager())->deposit((int) $this->candidate->id);
        $id = (int) $this->candidate->id;

        // The role the administrator assigned in the wrong place: every user context inherits it.
        $misplaced = $this->holder_of(['readdeposit', 'identityview'], \context_system::instance());
        $this->setUser($misplaced);
        $this->assertTrue(has_capability('tool/vae:readdeposit', \context_user::instance($id)), 'The trap is real.');

        $this->assertFalse(deposit_manager::can_read($id));
        $this->assertFalse(deposit_manager::can_read_fields($id));
        $this->assert_refused(function () use ($id) {
            (new deposit_manager())->deposits($id);
        }, 'errordepositrefused');
        $this->assert_refused(function () use ($id) {
            (new deposit_manager())->get($id, 1);
        }, 'errordepositrefused');
    }

    public function test_readdeposit_without_following_the_candidate_opens_nothing(): void {
        $this->write('objectifs', 0, 'Obtenir le diplome.');
        (new deposit_manager())->deposit((int) $this->candidate->id);
        $id = (int) $this->candidate->id;

        $nofollow = $this->holder_of(['readdeposit'], \context_user::instance($id));
        $this->setUser($nofollow);

        $this->assertTrue(has_capability('tool/vae:readdeposit', \context_user::instance($id)));
        $this->assertFalse(deposit_manager::can_read($id), 'Reading a deposit first needs the right to read the dossier.');
    }

    public function test_the_direction_reads_every_deposit_with_viewany_and_readdeposit(): void {
        $this->write('objectifs', 0, 'Obtenir le diplome.');
        $this->write('identite', 0, faisabilite_fields::encode('identite', ['nom_naissance' => 'Dupont']));
        (new deposit_manager())->deposit((int) $this->candidate->id);
        $id = (int) $this->candidate->id;

        // Both at the site level, as the guide says for the direction.
        $direction = $this->holder_of(['viewany', 'readdeposit', 'identityview'], \context_system::instance());
        $this->setUser($direction);
        $this->assertTrue(deposit_manager::can_read($id));
        $this->assertTrue(deposit_manager::can_read_fields($id));
        $this->assertCount(1, (new deposit_manager())->deposits($id));

        // Reading every dossier is not reading every deposit: readdeposit is still needed.
        $viewonly = $this->holder_of(['viewany'], \context_system::instance());
        $this->setUser($viewonly);
        $this->assertFalse(deposit_manager::can_read($id));
    }

    public function test_an_id_that_is_no_account_is_refused_like_any_other(): void {
        global $DB;

        $gone = $this->getDataGenerator()->create_user();
        delete_user($gone);
        // After the deletion: the row of a deleted account stays in the table, so the id after the largest one is an id
        // that was never an account (taken before, it would be the id of the account deleted just above).
        $missing = (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}') + 1;

        foreach ([$this->stranger, $this->reader] as $user) {
            $this->setUser($user);
            foreach ([$missing, (int) $gone->id] as $id) {
                $this->assertFalse(deposit_manager::can_read($id));
                $this->assertFalse(deposit_manager::can_read_fields($id));
                $this->assert_refused(function () use ($id) {
                    (new deposit_manager())->deposits($id);
                }, 'errordepositrefused');
                $this->assert_refused(function () use ($id) {
                    (new deposit_manager())->get($id, 1);
                }, 'errordepositrefused');
            }
        }

        $this->setAdminUser();
        $this->assertFalse(deposit_manager::can_read($missing), 'Not even for the administrator: there is nothing to read.');
    }

    public function test_the_identity_stays_behind_its_own_right(): void {
        $this->write('objectifs', 0, 'Obtenir le diplome.');
        $this->write('identite', 0, faisabilite_fields::encode('identite', ['nom_naissance' => 'Dupont-Secret']));
        $deposit = (new deposit_manager())->deposit((int) $this->candidate->id);

        $codes = function (bool $withfields) use ($deposit): array {
            return array_column(deposit_manager::sections($deposit, $withfields), 'code');
        };
        $this->assertSame(['identite', 'objectifs'], $codes(true));
        $this->assertSame(['objectifs'], $codes(false));

        // The page asks can_read_fields(): the right to read the deposit, and identityview on top.
        $id = (int) $this->candidate->id;
        $this->setUser($this->reader);
        $this->assertFalse(deposit_manager::can_read_fields($id));
        $this->setUser($this->identityreader);
        $this->assertTrue(deposit_manager::can_read_fields($id));
        $this->setUser($this->candidate);
        $this->assertTrue(deposit_manager::can_read_fields($id));
        $this->setUser($this->stranger);
        $this->assertFalse(deposit_manager::can_read_fields($id));
    }

    public function test_the_commitment_is_confirmed_with_the_deposit_and_changes_the_digest(): void {
        $this->write('engagement', 0, 'Je m\'engage a fournir les pieces.');
        $manager = new deposit_manager();

        $plain = $manager->deposit((int) $this->candidate->id);
        $this->assertFalse(deposit_manager::engagement_confirmed($plain));

        // The same text, confirmed: a new milestone, not "nothing changed".
        $confirmed = $manager->deposit((int) $this->candidate->id, null, true);
        $this->assertSame(2, (int) $confirmed->number);
        $this->assertTrue(deposit_manager::engagement_confirmed($confirmed));
        $this->assertNotSame($plain->contenthash, $confirmed->contenthash);
        $this->assertFalse(deposit_manager::engagement_confirmed($manager->get((int) $this->candidate->id, 1)));
    }

    public function test_the_commitment_cannot_be_confirmed_before_it_is_written(): void {
        $this->write('objectifs', 0, 'Obtenir le diplome.');

        $this->assert_refused(function () {
            (new deposit_manager())->deposit((int) $this->candidate->id, null, true);
        }, 'errordepositengagement');
    }

    public function test_the_event_names_no_content(): void {
        $this->write('objectifs', 0, 'Texte-Secret.');
        $sink = $this->redirectEvents();

        $deposit = (new deposit_manager())->deposit((int) $this->candidate->id);

        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof deposit_created;
        }));
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertSame((int) $this->candidate->id, (int) $events[0]->relateduserid);
        $this->assertSame((int) $deposit->id, (int) $events[0]->objectid);
        $this->assertSame(['number' => 1, 'doctype' => 'faisabilite'], $events[0]->other);
        $this->assertStringNotContainsString('Texte-Secret', $events[0]->get_description());
    }

    public function test_the_erasure_of_the_dossier_takes_the_deposits(): void {
        global $DB;

        $this->write('objectifs', 0, 'Obtenir le diplome.');
        (new deposit_manager())->deposit((int) $this->candidate->id);
        $this->assertSame(1, $DB->count_records('tool_vae_deposit'));

        purge::dossier((int) $this->candidate->id);

        $this->assertSame(0, $DB->count_records('tool_vae_deposit'));
    }

    public function test_the_orphan_sweep_takes_the_deposits_of_a_deleted_account(): void {
        global $DB;

        $this->write('objectifs', 0, 'Obtenir le diplome.');
        (new deposit_manager())->deposit((int) $this->candidate->id);
        $DB->set_field('user', 'deleted', 1, ['id' => $this->candidate->id]);

        purge::orphan_sections();

        $this->assertSame(0, $DB->count_records('tool_vae_deposit'));
    }

    public function test_the_deposits_are_exported_with_the_candidate_data(): void {
        $this->write('objectifs', 0, 'Obtenir le diplome.');
        $deposit = (new deposit_manager())->deposit((int) $this->candidate->id, 'Pour relecture');

        $context = \context_user::instance((int) $this->candidate->id);
        $list = new approved_contextlist($this->candidate, 'tool_vae', [$context->id]);
        provider::export_user_data($list);

        $data = writer::with_context($context)->get_data([
            get_string('pluginname', 'tool_vae'),
            get_string('privacy:path:deposits', 'tool_vae'),
        ]);
        $this->assertNotEmpty($data);
        $this->assertSame($deposit->uuid, $data->deposits[0]['reference']);
        $this->assertSame('Pour relecture', $data->deposits[0]['note']);
        $this->assertSame($deposit->contenthash, $data->deposits[0]['sha256']);
    }
}
