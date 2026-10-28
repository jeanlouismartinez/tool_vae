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
use tool_vae\local\validation_overview;

/**
 * The reflective journal (T-14, lot 1).
 *
 * WHAT IS ASSERTED. That the journal is closed until the administrator opens it; that an entry is one numbered slot of one
 * section code and nothing else; that the author alone reads it - not the administrator, not a drafts reader - and cannot be
 * made to deposit it; that a deleted entry takes its versions with it and gives its number back; that nobody deletes
 * another person's entry or a section of another document; and that the journal does not leak into the documents that are
 * shown to others.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\draft_manager::class)]
final class reflexion_journal_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Holds draftview only. */
    private $draftreader;

    /**
     * A candidate who writes, a drafts reader, and the journal open.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('reflectionopen', 1, 'tool_vae');

        $generator = $this->getDataGenerator();
        $system = \context_system::instance()->id;
        $this->candidate = $generator->create_user();
        $this->draftreader = $generator->create_user();
        $context = \context_user::instance($this->candidate->id)->id;

        $own = $generator->create_role();
        assign_capability('tool/vae:draftown', CAP_ALLOW, $own, $system, true);
        role_assign($own, $this->candidate->id, $context);

        $drafts = $generator->create_role();
        assign_capability('tool/vae:draftview', CAP_ALLOW, $drafts, $system, true);
        role_assign($drafts, $this->draftreader->id, $context);

        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->candidate);
    }

    /**
     * Write an entry and save a version of it, as the candidate.
     *
     * @param int $slot
     * @param string ...$versions
     * @return \stdClass
     */
    private function write(int $slot, string ...$versions): \stdClass {
        $manager = new draft_manager();
        $draft = $manager->section(
            (int) $this->candidate->id,
            draft_manager::DOCTYPE_REFLEXION,
            draft_manager::REFLEXION_SECTION,
            $slot
        );
        foreach ($versions as $text) {
            $manager->save_version((int) $draft->id, $text);
        }

        return $draft;
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

    public function test_the_journal_is_closed_until_it_is_opened(): void {
        set_config('reflectionopen', 0, 'tool_vae');

        $this->assert_refused(fn() => $this->write(1, 'Un texte.'), 'errorreflexionclosed');
    }

    public function test_an_entry_is_one_numbered_slot_of_one_section_code(): void {
        $draft = $this->write(1, 'Premiere version.', 'Seconde version.');

        $this->assertSame('reflexion', $draft->doctype);
        $this->assertSame('', $draft->fichecode);
        $manager = new draft_manager();
        $this->assertSame(2, (int) $manager->section(
            (int) $this->candidate->id,
            draft_manager::DOCTYPE_REFLEXION,
            draft_manager::REFLEXION_SECTION,
            1
        )->currentversion);

        $id = (int) $this->candidate->id;
        $type = draft_manager::DOCTYPE_REFLEXION;
        $this->assert_refused(fn() => $manager->section($id, $type, 'objectifs', 1), 'errordraftsection');
        $this->assert_refused(fn() => $manager->section($id, $type, 'entree', 0), 'errordraftsection');
        $this->assert_refused(fn() => $manager->section($id, $type, 'entree', 201), 'errordraftsection');
        $this->assert_refused(fn() => $manager->section($id, $type, 'entree', 1, 'RNCP100'), 'errordraftsection');
        $this->assertSame(200, draft_manager::REFLEXION_MAX);
        $this->assertNotNull($manager->section($id, $type, 'entree', 200));
    }

    public function test_only_the_author_reads_it(): void {
        $draft = $this->write(1, 'Un texte tres personnel.');
        $id = (int) $this->candidate->id;

        $this->assertTrue(draft_manager::can_read_doctype($id, 'reflexion'));
        $draft = \core\di::get(\moodle_database::class)->get_record('tool_vae_draft', ['id' => $draft->id]);

        foreach ([$this->draftreader, get_admin()] as $user) {
            $this->setUser($user);
            $this->assertFalse(draft_manager::can_read_doctype($id, 'reflexion'));
            $this->assert_refused(fn() => draft_manager::require_can_read_draft($draft), 'errordraftrefused');
        }
        // The dossier de faisabilite, for the same readers, is another matter and is left as it was.
        $this->setAdminUser();
        $this->assertTrue(draft_manager::can_read_doctype($id, 'faisabilite'));
    }

    public function test_it_cannot_be_deposited(): void {
        $this->write(1, 'Un texte.');

        $this->assert_refused(
            fn() => (new deposit_manager())->deposit((int) $this->candidate->id, null, false, 'reflexion'),
            'errordepositmissing'
        );
        $this->assert_refused(
            fn() => (new deposit_manager())->deposits((int) $this->candidate->id, 'reflexion'),
            'errordepositmissing'
        );
    }

    public function test_the_next_entry_takes_the_first_free_number_and_a_deleted_one_comes_back(): void {
        $first = $this->write(1, 'Un.');
        $this->write(2, 'Deux.');
        $id = (int) $this->candidate->id;

        $this->assertSame(3, draft_manager::next_reflection_slot($id));
        $this->assertSame([1, 2], array_keys(draft_manager::reflection_entries($id)));

        (new draft_manager())->delete_entry((int) $first->id);

        $this->assertSame(1, draft_manager::next_reflection_slot($id));
        $this->assertSame([2], array_keys(draft_manager::reflection_entries($id)));
    }

    public function test_a_deleted_entry_takes_its_versions_with_it(): void {
        global $DB;
        $draft = $this->write(1, 'Un.', 'Deux.');
        $other = $this->write(2, 'Autre.');
        $this->assertSame(2, $DB->count_records('tool_vae_version', ['draftid' => $draft->id]));

        (new draft_manager())->delete_entry((int) $draft->id);

        $this->assertFalse($DB->record_exists('tool_vae_draft', ['id' => $draft->id]));
        $this->assertSame(0, $DB->count_records('tool_vae_version', ['draftid' => $draft->id]));
        $this->assertSame(1, $DB->count_records('tool_vae_version', ['draftid' => $other->id]));
    }

    public function test_nobody_deletes_another_persons_entry_or_another_document(): void {
        global $DB;
        $draft = $this->write(1, 'Un texte.');
        $faisabilite = (new draft_manager())->section(
            (int) $this->candidate->id,
            draft_manager::DOCTYPE_FAISABILITE,
            'objectifs'
        );
        $manager = new draft_manager();

        $this->assert_refused(fn() => $manager->delete_entry((int) $faisabilite->id), 'errordraftrefused');
        $this->assert_refused(fn() => $manager->delete_entry(999999), 'errordraftrefused');

        foreach ([$this->draftreader, get_admin()] as $user) {
            $this->setUser($user);
            $this->assert_refused(fn() => $manager->delete_entry((int) $draft->id), 'errordraftrefused');
        }
        $this->assertTrue($DB->record_exists('tool_vae_draft', ['id' => $draft->id]));
    }

    public function test_a_closed_journal_cannot_be_written_in_but_its_author_can_still_delete_from_it(): void {
        global $DB;
        $draft = $this->write(1, 'Un texte.');
        set_config('reflectionopen', 0, 'tool_vae');
        $manager = new draft_manager();

        $this->assert_refused(fn() => $manager->save_version((int) $draft->id, 'Autre texte.'), 'errorreflexionclosed');
        $this->assert_refused(fn() => $manager->save_working((int) $draft->id, 'Brouillon.'), 'errorreflexionclosed');

        $manager->delete_entry((int) $draft->id);

        $this->assertFalse($DB->record_exists('tool_vae_draft', ['id' => $draft->id]));
    }

    public function test_a_full_journal_has_no_next_number(): void {
        global $DB;
        $now = time();
        for ($slot = 1; $slot <= draft_manager::REFLEXION_MAX; $slot++) {
            $DB->insert_record('tool_vae_draft', (object) [
                'uuid' => local\identity_map::generate_ulid(), 'candidateid' => (int) $this->candidate->id,
                'doctype' => 'reflexion', 'section' => 'entree', 'slot' => $slot, 'fichecode' => '',
                'currentversion' => 0, 'timecreated' => $now, 'timemodified' => $now,
                'usermodified' => (int) $this->candidate->id,
            ]);
        }

        $this->assert_refused(fn() => draft_manager::next_reflection_slot((int) $this->candidate->id), 'errorreflexionfull');
    }

    public function test_the_journal_does_not_leak_into_the_documents_shown_to_others(): void {
        $this->write(1, 'Un texte tres personnel.');

        $this->assertNull(validation_overview::document((int) $this->candidate->id));
        $this->assertSame([], \tool_vae\local\faisabilite_overview::saved_versions((int) $this->candidate->id));
    }
}
