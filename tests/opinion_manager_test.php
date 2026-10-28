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
use tool_vae\event\opinion_created;
use tool_vae\local\deposit_manager;
use tool_vae\local\draft_manager;
use tool_vae\local\opinion_manager;
use tool_vae\local\purge;
use tool_vae\privacy\provider;

/**
 * The opinion of a manager on a deposit (T-10, lot 5b, D-12).
 *
 * WHAT IS ASSERTED. That the opinion is written by a manager who follows the candidate and holds the
 * right, on one deposit; that it is never rewritten and never carried onto a later deposit; that the
 * candidate and the readers of the deposit read it; that it leaves with the dossier, and that its
 * author's erasure takes their name and keeps the text.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\opinion_manager::class)]
final class opinion_manager_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Follows the candidate, may read deposits and write opinions. */
    private $manager;

    /** @var \stdClass Follows the candidate and may read deposits, not write opinions. */
    private $reader;

    /** @var \stdClass Holds the rights but does not follow the candidate. */
    private $outsider;

    /** @var int The candidate's first deposit. */
    private $depositid;

    /**
     * A candidate with a deposit, a manager, a reader and an outsider.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;

        $this->candidate = $generator->create_user();
        $this->manager = $generator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->reader = $generator->create_user();
        $this->outsider = $generator->create_user();
        $context = \context_user::instance($this->candidate->id)->id;

        $own = $generator->create_role();
        assign_capability('tool/vae:draftown', CAP_ALLOW, $own, $system, true);
        role_assign($own, $this->candidate->id, $context);

        $follow = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        assign_capability('tool/vae:readdeposit', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, $context);
        role_assign($follow, $this->reader->id, $context);

        $write = $generator->create_role();
        assign_capability('tool/vae:writeopinion', CAP_ALLOW, $write, $system, true);
        set_role_contextlevels($write, [CONTEXT_USER]);
        role_assign($write, $this->manager->id, $context);

        // The outsider holds both rights in another person's context only, and follows nobody here.
        $other = $generator->create_user();
        $othercontext = \context_user::instance($other->id)->id;
        role_assign($write, $this->outsider->id, $othercontext);
        role_assign($follow, $this->outsider->id, $othercontext);

        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->candidate);
        $drafts = new draft_manager();
        $draft = $drafts->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'objectifs');
        $drafts->save_version((int) $draft->id, 'Obtenir le diplome.');
        $this->depositid = (int) (new deposit_manager())->deposit((int) $this->candidate->id)->id;
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

    public function test_the_manager_who_follows_writes_and_the_text_is_kept_as_written(): void {
        $this->setUser($this->manager);

        $opinion = (new opinion_manager())->write($this->depositid, "  Un avis circonstancie.\r\nDeux lignes.  ");

        $this->assertSame("Un avis circonstancie.\nDeux lignes.", $opinion->content);
        $this->assertSame(hash('sha256', $opinion->content), $opinion->contenthash);
        $this->assertSame((int) $this->manager->id, (int) $opinion->authorid);
        $this->assertSame(26, strlen($opinion->uuid));
    }

    public function test_who_may_not_write(): void {
        $manager = new opinion_manager();

        foreach ([$this->candidate, $this->reader, $this->outsider] as $user) {
            $this->setUser($user);
            $this->assertFalse(opinion_manager::can_write((int) $this->candidate->id));
            $this->assert_refused(function () use ($manager) {
                $manager->write($this->depositid, 'Un avis.');
            }, 'erroropiniondenied');
        }

        // The administrator holds every capability and follows nobody.
        $this->setAdminUser();
        $this->assertFalse(opinion_manager::can_write((int) $this->candidate->id));
    }

    public function test_an_empty_too_long_or_repeated_opinion_is_refused(): void {
        $this->setUser($this->manager);
        $manager = new opinion_manager();

        $this->assert_refused(function () use ($manager) {
            $manager->write($this->depositid, " \n ");
        }, 'erroropinionempty');
        $this->assert_refused(function () use ($manager) {
            $manager->write($this->depositid, str_repeat('a', opinion_manager::MAX_LENGTH + 1));
        }, 'errordrafttoolong');

        $manager->write($this->depositid, 'Un avis.');
        $this->assert_refused(function () use ($manager) {
            $manager->write($this->depositid, 'Un avis.');
        }, 'erroropinionduplicate');
        $this->assert_refused(function () use ($manager) {
            $manager->write(999999, 'Un avis.');
        }, 'erroropiniondenied');
    }

    public function test_an_opinion_stays_on_its_deposit(): void {
        $this->setUser($this->manager);
        (new opinion_manager())->write($this->depositid, 'Avis sur le premier depot.');

        // The candidate writes more and deposits again: the new deposit has no opinion.
        $this->setUser($this->candidate);
        $drafts = new draft_manager();
        $draft = $drafts->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'objectifs');
        $drafts->save_version((int) $draft->id, 'Obtenir le diplome et le montrer.');
        $second = (new deposit_manager())->deposit((int) $this->candidate->id);

        $this->setUser($this->reader);
        $manager = new opinion_manager();
        $deposits = (new deposit_manager())->deposits((int) $this->candidate->id);
        $this->assertCount(1, $manager->for_deposit($deposits[1]));
        $this->assertCount(0, $manager->for_deposit($deposits[2]));
        $this->assertSame(2, (int) $second->number);
    }

    public function test_the_candidate_and_the_readers_read_it_and_a_stranger_does_not(): void {
        $this->setUser($this->manager);
        (new opinion_manager())->write($this->depositid, 'Un avis.');
        $deposit = (new deposit_manager())->get((int) $this->candidate->id, 1);
        $manager = new opinion_manager();

        foreach ([$this->candidate, $this->reader, $this->manager] as $user) {
            $this->setUser($user);
            $opinions = $manager->for_deposit($deposit);
            $this->assertCount(1, $opinions);
            $this->assertSame('Nadia Benali', reset($opinions)->authorname);
        }

        $this->setUser($this->outsider);
        $this->assert_refused(function () use ($manager, $deposit) {
            $manager->for_deposit($deposit);
        }, 'errordepositrefused');
    }

    public function test_the_event_names_no_text(): void {
        $this->setUser($this->manager);
        $sink = $this->redirectEvents();

        (new opinion_manager())->write($this->depositid, 'Texte-Secret.');

        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof opinion_created;
        }));
        $sink->close();
        $this->assertCount(1, $events);
        $this->assertSame((int) $this->candidate->id, (int) $events[0]->relateduserid);
        $this->assertSame(['number' => 1], $events[0]->other);
        $this->assertStringNotContainsString('Texte-Secret', $events[0]->get_description());
    }

    public function test_the_erasure_of_the_dossier_takes_the_opinions(): void {
        global $DB;

        $this->setUser($this->manager);
        (new opinion_manager())->write($this->depositid, 'Un avis.');

        purge::dossier((int) $this->candidate->id);

        $this->assertSame(0, $DB->count_records('tool_vae_opinion'));
        $this->assertSame(0, $DB->count_records('tool_vae_deposit'));
    }

    public function test_the_erasure_of_the_author_takes_their_name_and_keeps_the_text(): void {
        global $DB;

        $this->setUser($this->manager);
        $written = (new opinion_manager())->write($this->depositid, 'Un avis.');

        $context = \context_user::instance((int) $this->candidate->id);
        $list = new approved_contextlist($this->manager, 'tool_vae', [$context->id]);
        provider::delete_data_for_user($list);

        $row = $DB->get_record('tool_vae_opinion', ['id' => $written->id], '*', MUST_EXIST);
        $this->assertNull($row->authorid);
        $this->assertSame('Un avis.', $row->content);
    }

    public function test_the_export_gives_the_candidate_every_opinion_and_the_author_their_own(): void {
        $this->setUser($this->manager);
        (new opinion_manager())->write($this->depositid, 'Avis du premier responsable.');

        $context = \context_user::instance((int) $this->candidate->id);
        $path = [get_string('pluginname', 'tool_vae'), get_string('privacy:path:opinions', 'tool_vae')];

        provider::export_user_data(new approved_contextlist($this->candidate, 'tool_vae', [$context->id]));
        $data = writer::with_context($context)->get_data($path);
        $this->assertSame('Avis du premier responsable.', $data->opinions[0]['content']);
        $this->assertSame(1, $data->opinions[0]['deposit']);

        writer::reset();
        provider::export_user_data(new approved_contextlist($this->reader, 'tool_vae', [$context->id]));
        $this->assertEmpty(
            writer::with_context($context)->get_data($path),
            'A reader exports what they wrote, not what others did.'
        );

        writer::reset();
        provider::export_user_data(new approved_contextlist($this->manager, 'tool_vae', [$context->id]));
        $this->assertNotEmpty(writer::with_context($context)->get_data($path));
    }
}
