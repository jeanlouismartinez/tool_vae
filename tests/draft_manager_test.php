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
use tool_vae\local\draft_manager;
use tool_vae\local\purge;
use tool_vae\privacy\provider;

/**
 * The sections of a document and their versions (T-81, lot 1).
 *
 * WHAT IS ASSERTED. The history, not the code path: that a version is never rewritten, that a
 * restoration is a new version, that the draft in progress is not history, and that the person who
 * may write is the candidate alone - the site administrator holds every capability and still does
 * not write a candidate's document.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\draft_manager::class)]
final class draft_manager_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass Someone who follows nobody and holds nothing. */
    private $stranger;

    /** @var \stdClass A reader who follows the candidate (viewassigned) and holds tool/vae:draftview in their context. */
    private $reader;

    /**
     * A candidate who may write, a stranger, a reader.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $this->stranger = $generator->create_user();
        $this->reader = $generator->create_user();

        $ownrole = $generator->create_role();
        assign_capability('tool/vae:draftown', CAP_ALLOW, $ownrole, \context_system::instance()->id, true);
        role_assign($ownrole, $this->candidate->id, \context_user::instance($this->candidate->id)->id);

        // Reading the versions of someone else needs the right to read the dossier first (viewany, or following the candidate):
        // the reader follows the candidate, as a manager does, and holds draftview on top.
        $viewrole = $generator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $viewrole, \context_system::instance()->id, true);
        assign_capability('tool/vae:draftview', CAP_ALLOW, $viewrole, \context_system::instance()->id, true);
        role_assign($viewrole, $this->reader->id, \context_user::instance($this->candidate->id)->id);

        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * The section of the candidate, as the candidate.
     *
     * @param string $section
     * @param int $slot
     * @return \stdClass
     */
    private function section(string $section = 'experiences', int $slot = 1): \stdClass {
        $this->setUser($this->candidate);

        return (new draft_manager())->section(
            (int) $this->candidate->id,
            draft_manager::DOCTYPE_FAISABILITE,
            $section,
            $slot
        );
    }

    public function test_a_section_is_created_once_and_found_again(): void {
        global $DB;

        $first = $this->section();
        $second = $this->section();

        $this->assertEquals($first->id, $second->id);
        $this->assertSame(1, $DB->count_records('tool_vae_draft'));
        $this->assertSame(0, (int) $first->currentversion);
        $this->assertNull($first->working);
    }

    public function test_the_draft_in_progress_is_not_history(): void {
        global $DB;

        $draft = $this->section();
        $manager = new draft_manager();
        $saved = $manager->save_working((int) $draft->id, "Premier jet\nsur deux lignes");

        $this->assertSame("Premier jet\nsur deux lignes", $saved->working);
        $this->assertNotNull($saved->workingat);
        $this->assertSame(0, $DB->count_records('tool_vae_version'), 'The draft in progress writes no version.');
    }

    public function test_a_version_is_a_snapshot_and_empties_the_draft_in_progress(): void {
        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_working((int) $draft->id, 'Brouillon');

        $version = $manager->save_version((int) $draft->id, 'Texte un', 'Après relecture');

        $this->assertSame(1, (int) $version->number);
        $this->assertSame('Après relecture', $version->note);
        $this->assertSame(hash('sha256', 'Texte un'), $version->contenthash);
        $this->assertEquals($this->candidate->id, $version->authorid);

        global $DB;
        $row = $DB->get_record('tool_vae_draft', ['id' => $draft->id], '*', MUST_EXIST);
        $this->assertSame(1, (int) $row->currentversion);
        $this->assertNull($row->working, 'The draft in progress is done with once it is a version.');
    }

    public function test_the_same_text_twice_creates_nothing(): void {
        global $DB;

        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Texte');

        $this->assertNull($manager->save_version((int) $draft->id, 'Texte'));
        $this->assertSame(1, $DB->count_records('tool_vae_version'));
    }

    public function test_line_endings_do_not_make_a_new_version(): void {
        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, "a\nb");

        $this->assertNull($manager->save_version((int) $draft->id, "a\r\nb"), 'A browser sends CRLF.');
    }

    public function test_versions_are_numbered_and_listed_latest_first(): void {
        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Un');
        $manager->save_version((int) $draft->id, 'Deux');
        $manager->save_version((int) $draft->id, 'Trois');

        $versions = $manager->versions((int) $draft->id);

        $this->assertSame([3, 2, 1], array_keys($versions));
        $this->assertSame('Un', $manager->version((int) $draft->id, 1)->content);
    }

    public function test_restoring_creates_a_new_version_and_rewinds_nothing(): void {
        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Un');
        $manager->save_version((int) $draft->id, 'Deux');

        $restored = $manager->restore((int) $draft->id, 1);

        $this->assertSame(3, (int) $restored->number);
        $this->assertSame('Un', $restored->content);
        $this->assertSame(1, (int) $restored->restoredfrom);
        $this->assertSame('Deux', $manager->version((int) $draft->id, 2)->content, 'Version 2 is untouched.');
        $this->assertCount(3, $manager->versions((int) $draft->id));
    }

    public function test_restoring_the_latest_text_creates_nothing(): void {
        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Un');

        $this->assertNull($manager->restore((int) $draft->id, 1));
    }

    public function test_restoring_a_version_that_does_not_exist_is_refused(): void {
        $draft = $this->section();

        try {
            (new draft_manager())->restore((int) $draft->id, 9);
            $this->fail('A version that does not exist cannot be restored.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordraftnoversion', $e->errorcode);
        }
    }

    public function test_a_page_loaded_before_another_tab_saved_does_not_overwrite_it(): void {
        $draft = $this->section();
        $manager = new draft_manager();
        $first = $manager->save_working((int) $draft->id, 'Onglet A');

        // The other tab saved one second later; this page still knows the first time.
        global $DB;
        $DB->set_field('tool_vae_draft', 'workingat', (int) $first->workingat + 5, ['id' => $draft->id]);

        try {
            $manager->save_working((int) $draft->id, 'Onglet B', (int) $first->workingat);
            $this->fail('A newer draft must not be overwritten by an older page.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordraftconflict', $e->errorcode);
        }
        $this->assertSame('Onglet A', $DB->get_field('tool_vae_draft', 'working', ['id' => $draft->id]));
    }

    public function test_a_version_from_a_page_loaded_before_another_tab_saved_is_refused(): void {
        global $DB;

        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Texte un');
        $first = $manager->save_working((int) $draft->id, 'Texte un + deux heures de travail');

        // The stale tab was opened before any draft in progress existed - it knows 0 - or with an older draft.
        foreach ([0, (int) $first->workingat - 5] as $known) {
            try {
                $manager->save_version((int) $draft->id, 'Texte un.', null, $known);
                $this->fail('A version from a page older than the draft in progress must be refused.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errordraftconflict', $e->errorcode);
            }
        }
        $this->assertSame(
            'Texte un + deux heures de travail',
            $DB->get_field('tool_vae_draft', 'working', ['id' => $draft->id])
        );
        $this->assertSame(1, $DB->count_records('tool_vae_version'), 'No version was written from the stale page.');

        // The page that knows the draft in progress saves its version, and the draft is then done with.
        $version = $manager->save_version((int) $draft->id, 'Texte un, relu.', null, (int) $first->workingat);
        $this->assertSame(2, (int) $version->number);
        $this->assertNull($DB->get_field('tool_vae_draft', 'working', ['id' => $draft->id]));
    }

    public function test_a_version_from_a_stale_page_goes_through_when_its_text_is_the_draft_in_progress(): void {
        global $DB;

        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Texte un');

        // The autosave of this very page landed a moment before the click, and the page has not been told its time yet:
        // nothing is lost by turning that draft into a version, so it is not a conflict.
        $manager->save_working((int) $draft->id, 'Texte deux');
        $version = $manager->save_version((int) $draft->id, 'Texte deux', null, 0);

        $this->assertSame(2, (int) $version->number);
        $this->assertNull($DB->get_field('tool_vae_draft', 'working', ['id' => $draft->id]));
    }

    public function test_a_restoration_from_a_stale_page_is_refused(): void {
        global $DB;

        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Un');
        $manager->save_version((int) $draft->id, 'Deux');
        $first = $manager->save_working((int) $draft->id, 'Brouillon de l\'onglet A');

        try {
            $manager->restore((int) $draft->id, 1, 0);
            $this->fail('A restoration from a page older than the draft in progress must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordraftconflict', $e->errorcode);
        }
        $this->assertSame('Brouillon de l\'onglet A', $DB->get_field('tool_vae_draft', 'working', ['id' => $draft->id]));
        $this->assertSame(2, $DB->count_records('tool_vae_version'));

        $restored = $manager->restore((int) $draft->id, 1, (int) $first->workingat);
        $this->assertSame(3, (int) $restored->number);
        $this->assertNull($DB->get_field('tool_vae_draft', 'working', ['id' => $draft->id]));
    }

    public function test_a_stale_page_that_changed_nothing_does_not_empty_the_draft_of_another_tab(): void {
        global $DB;

        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Texte un');
        $manager->save_working((int) $draft->id, 'Deux heures de travail');

        // This page shows the latest version untouched: "nothing has changed" must not be the answer that empties the draft.
        try {
            $manager->save_version((int) $draft->id, 'Texte un', null, 0);
            $this->fail('A stale page must not empty the draft of another tab, even with the text of the latest version.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordraftconflict', $e->errorcode);
        }
        $this->assertSame('Deux heures de travail', $DB->get_field('tool_vae_draft', 'working', ['id' => $draft->id]));
    }

    public function test_a_page_loaded_with_no_draft_does_not_overwrite_a_draft_saved_since(): void {
        global $DB;

        $draft = $this->section();
        $manager = new draft_manager();

        // Two tabs opened with no draft in progress: both know 0.
        $first = $manager->autosave((int) $draft->id, 'Onglet A', 0);
        try {
            $manager->autosave((int) $draft->id, 'Onglet B', 0);
            $this->fail('A page that knew no draft must not overwrite the one saved since.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordraftconflict', $e->errorcode);
        }
        $this->assertSame('Onglet A', $DB->get_field('tool_vae_draft', 'working', ['id' => $draft->id]));

        // The first tab goes on: the time it was answered is the one it sends.
        $again = $manager->autosave((int) $draft->id, 'Onglet A, la suite', (int) $first->workingat);
        $this->assertSame('Onglet A, la suite', $again->working);
    }

    public function test_the_same_text_again_empties_the_draft_in_progress(): void {
        global $DB;

        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'abc');

        // A letter typed and autosaved, then taken back: the writer clicks before the next autosave.
        $page = $manager->save_working((int) $draft->id, 'abcd');
        $this->assertNull($manager->save_version((int) $draft->id, 'abc', null, (int) $page->workingat));

        $row = $DB->get_record('tool_vae_draft', ['id' => $draft->id], '*', MUST_EXIST);
        $this->assertNull($row->working, 'A text that is the latest version leaves no draft in progress.');
        $this->assertNull($row->workingat);
        $this->assertSame(1, $DB->count_records('tool_vae_version'));
        $places = \tool_vae\local\faisabilite_overview::for_candidate((int) $this->candidate->id)['experiences']['places'];
        $this->assertSame(\tool_vae\local\faisabilite_overview::STATE_VERSION, $places[1]['state']);

        // A row left by the former code: the draft in progress is the very text of the version.
        $DB->update_record('tool_vae_draft', (object) ['id' => $draft->id, 'working' => 'abc', 'workingat' => time()]);
        $this->assertNull($manager->save_version((int) $draft->id, 'abc'));
        $this->assertNull($DB->get_field('tool_vae_draft', 'working', ['id' => $draft->id]));
    }

    public function test_an_autosave_that_lands_after_the_version_keeps_no_draft(): void {
        global $DB;

        $draft = $this->section();
        $manager = new draft_manager();
        $page = $manager->save_working((int) $draft->id, 'Texte un');
        $manager->save_version((int) $draft->id, 'Texte un', null, (int) $page->workingat);

        // The tick that left just before the click lands after the commit, with the time the page knew.
        $late = $manager->save_working((int) $draft->id, 'Texte un', (int) $page->workingat);

        $this->assertNull($late->working, 'A text that is the version is no draft in progress.');
        $this->assertNull($late->workingat);
        $places = \tool_vae\local\faisabilite_overview::for_candidate((int) $this->candidate->id)['experiences']['places'];
        $this->assertSame(\tool_vae\local\faisabilite_overview::STATE_VERSION, $places[1]['state']);
        $this->assertSame(1, $DB->count_records('tool_vae_version'));

        // And typing one's way back to the version: the autosave that says so keeps nothing either.
        $typed = $manager->save_working((int) $draft->id, 'Texte un, et plus', null);
        $this->assertSame('Texte un, et plus', $typed->working);
        $back = $manager->save_working((int) $draft->id, 'Texte un', (int) $typed->workingat);
        $this->assertNull($back->working);
        $this->assertNull($back->workingat);
    }

    public function test_only_the_candidate_writes_even_the_administrator_does_not(): void {
        $draft = $this->section();
        $manager = new draft_manager();

        foreach ([$this->stranger, $this->reader, get_admin()] as $user) {
            $this->setUser($user);
            try {
                $manager->save_version((int) $draft->id, 'Écrit par un autre');
                $this->fail('Only the candidate writes their own sections.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errordraftrefused', $e->errorcode);
            }
        }
        global $DB;
        $this->assertSame(0, $DB->count_records('tool_vae_version'));
    }

    public function test_who_may_read(): void {
        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Texte');

        $this->setUser($this->reader);
        $this->assertCount(1, $manager->versions((int) $draft->id), 'A holder of draftview reads.');

        $this->setAdminUser();
        $this->assertCount(1, $manager->versions((int) $draft->id), 'The site administrator reads.');

        $this->setUser($this->stranger);
        try {
            $manager->versions((int) $draft->id);
            $this->fail('A stranger reads nothing.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordraftrefused', $e->errorcode);
        }
    }

    /**
     * A role holding the given capabilities, assigned where the test says.
     *
     * @param string[] $capabilities Names without the component.
     * @param \context $context Where the role is assigned.
     * @return \stdClass The user who holds it.
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

    public function test_draftview_held_at_system_level_opens_nothing(): void {
        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Texte');
        $id = (int) $this->candidate->id;

        // The role the administrator assigned in the wrong place: every user context inherits it.
        $misplaced = $this->holder_of(['draftview', 'identityview'], \context_system::instance());
        $this->setUser($misplaced);
        $this->assertTrue(has_capability('tool/vae:draftview', \context_user::instance($id)), 'The trap is real.');

        $this->assertFalse(draft_manager::can_read($id));
        $this->assertFalse(draft_manager::can_read_fields($id));
        try {
            $manager->versions((int) $draft->id);
            $this->fail('draftview held at system level must not open the versions of every candidate.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordraftrefused', $e->errorcode);
        }
    }

    public function test_draftview_without_following_the_candidate_opens_nothing(): void {
        $draft = $this->section();
        (new draft_manager())->save_version((int) $draft->id, 'Texte');
        $id = (int) $this->candidate->id;

        $nofollow = $this->holder_of(['draftview'], \context_user::instance($id));
        $this->setUser($nofollow);

        $this->assertTrue(has_capability('tool/vae:draftview', \context_user::instance($id)));
        $this->assertFalse(draft_manager::can_read($id), 'Reading the versions first needs the right to read the dossier.');
    }

    public function test_the_four_readers_of_the_versions(): void {
        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Texte');
        $id = (int) $this->candidate->id;

        // The candidate reads their own.
        $this->setUser($this->candidate);
        $this->assertTrue(draft_manager::can_read($id));
        $this->assertTrue(draft_manager::can_read_fields($id));

        // The manager who follows the candidate reads with draftview in their context, the identity with identityview on top.
        $follower = $this->holder_of(['viewassigned', 'draftview', 'identityview'], \context_user::instance($id));
        $this->setUser($follower);
        $this->assertTrue(draft_manager::can_read($id));
        $this->assertTrue(draft_manager::can_read_fields($id));
        $this->assertCount(1, $manager->versions((int) $draft->id));

        // The direction reads every dossier: viewany and draftview, held at the site level as the guide says.
        $direction = $this->holder_of(['viewany', 'draftview', 'identityview'], \context_system::instance());
        $this->setUser($direction);
        $this->assertTrue(draft_manager::can_read($id));
        $this->assertTrue(draft_manager::can_read_fields($id));
        $this->assertCount(1, $manager->versions((int) $draft->id));

        // The site administrator reads as a matter of course.
        $this->setAdminUser();
        $this->assertTrue(draft_manager::can_read($id));
        $this->assertTrue(draft_manager::can_read_fields($id));
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
                $this->assertFalse(draft_manager::can_read($id));
                $this->assertFalse(draft_manager::can_read_fields($id));
            }
        }
        $this->setAdminUser();
        $this->assertFalse(draft_manager::can_read($missing), 'Not even for the administrator: there is nothing to read.');
    }

    public function test_an_unknown_address_is_refused(): void {
        $this->setUser($this->candidate);
        $manager = new draft_manager();

        foreach ([['inconnu', 'experiences', 0], ['faisabilite', 'Majuscule', 0], ['faisabilite', 'ok', 100]] as $address) {
            try {
                $manager->section((int) $this->candidate->id, $address[0], $address[1], $address[2]);
                $this->fail('An address this plugin does not know must be refused.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errordraftsection', $e->errorcode);
            }
        }
    }

    public function test_a_text_too_long_is_refused(): void {
        $draft = $this->section();

        try {
            (new draft_manager())->save_version(
                (int) $draft->id,
                str_repeat('x', draft_manager::CONTENT_MAXLENGTH + 1)
            );
            $this->fail('A text beyond the bound is refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordrafttoolong', $e->errorcode);
        }
    }

    public function test_the_event_names_identifiers_and_never_the_text(): void {
        $draft = $this->section();
        $sink = $this->redirectEvents();

        (new draft_manager())->save_version((int) $draft->id, 'Un texte qui ne doit pas se retrouver au journal');

        $events = array_values(array_filter(
            $sink->get_events(),
            fn($e) => $e instanceof \tool_vae\event\draft_version_saved
        ));
        $this->assertCount(1, $events);
        $this->assertSame(['draftid' => (int) $draft->id, 'number' => 1], $events[0]->other);
        $this->assertStringNotContainsString('journal', $events[0]->get_description());
    }

    public function test_erasing_the_dossier_destroys_sections_and_versions(): void {
        global $DB;

        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Un');
        $manager->save_version((int) $draft->id, 'Deux');

        $other = $this->getDataGenerator()->create_user();
        $DB->insert_record('tool_vae_draft', (object) [
            'uuid' => \tool_vae\local\identity_map::generate_ulid(), 'candidateid' => $other->id,
            'doctype' => 'faisabilite', 'fichecode' => '', 'section' => 'objectifs', 'slot' => 0, 'currentversion' => 0,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => $other->id,
        ]);

        purge::dossier((int) $this->candidate->id);

        $this->assertSame(0, $DB->count_records('tool_vae_draft', ['candidateid' => $this->candidate->id]));
        $this->assertSame(0, $DB->count_records('tool_vae_version'));
        $this->assertSame(1, $DB->count_records('tool_vae_draft'), 'Another candidate loses nothing.');
    }

    public function test_the_reconciliation_removes_the_sections_of_a_candidate_who_is_gone(): void {
        global $DB;

        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Un');
        $manager->save_version((int) $draft->id, 'Deux');

        // Another candidate, still here, with a section of their own.
        $kept = $this->getDataGenerator()->create_user();
        $keptid = $DB->insert_record('tool_vae_draft', (object) [
            'uuid' => \tool_vae\local\identity_map::generate_ulid(), 'candidateid' => $kept->id,
            'doctype' => 'faisabilite', 'fichecode' => '', 'section' => 'objectifs', 'slot' => 0, 'currentversion' => 1,
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => $kept->id,
        ]);
        $DB->insert_record('tool_vae_version', (object) [
            'uuid' => \tool_vae\local\identity_map::generate_ulid(), 'draftid' => $keptid, 'number' => 1,
            'content' => 'Conservé', 'contenthash' => hash('sha256', 'Conservé'), 'timecreated' => time(),
        ]);

        // A version whose section no longer exists.
        $DB->insert_record('tool_vae_version', (object) [
            'uuid' => \tool_vae\local\identity_map::generate_ulid(), 'draftid' => 99999, 'number' => 1,
            'content' => 'Sans rubrique', 'contenthash' => hash('sha256', 'Sans rubrique'), 'timecreated' => time(),
        ]);

        // The account is marked deleted without the purge having run: what an interrupted erasure leaves.
        $DB->set_field('user', 'deleted', 1, ['id' => $this->candidate->id]);

        $this->assertSame(1, purge::orphan_sections());

        $this->assertSame(0, $DB->count_records('tool_vae_draft', ['candidateid' => $this->candidate->id]));
        $this->assertSame(0, $DB->count_records('tool_vae_version', ['draftid' => $draft->id]));
        $this->assertSame(0, $DB->count_records('tool_vae_version', ['draftid' => 99999]));
        $this->assertSame(1, $DB->count_records('tool_vae_draft', ['id' => $keptid]), 'A present candidate loses nothing.');
        $this->assertSame(1, $DB->count_records('tool_vae_version', ['draftid' => $keptid]));
        $this->assertSame(0, purge::orphan_sections(), 'A second run finds nothing.');
    }

    public function test_the_candidate_export_carries_every_version(): void {
        $draft = $this->section();
        $manager = new draft_manager();
        $manager->save_version((int) $draft->id, 'Un');
        $manager->save_version((int) $draft->id, 'Deux');
        $manager->save_working((int) $draft->id, 'En cours');

        $context = \context_user::instance($this->candidate->id);
        $list = provider::get_contexts_for_userid((int) $this->candidate->id);
        $this->assertContains((int) $context->id, array_map('intval', $list->get_contextids()));

        provider::export_user_data(new approved_contextlist(
            \core_user::get_user($this->candidate->id),
            'tool_vae',
            [$context->id]
        ));

        $data = \core_privacy\local\request\writer::with_context($context)->get_data([
            get_string('pluginname', 'tool_vae'),
            get_string('privacy:path:sections', 'tool_vae'),
        ]);
        $this->assertCount(1, $data->sections);
        $this->assertCount(2, $data->sections[0]['versions']);
        $this->assertSame('Deux', $data->sections[0]['versions'][1]['content']);
        $this->assertSame('En cours', $data->sections[0]['working']);
    }

    public function test_a_text_brought_back_from_an_archive_has_the_candidate_as_its_author(): void {
        global $DB;

        // The administrator imports the dossier of the candidate: they are not the author of what it says.
        $this->setAdminUser();
        $savedat = time() - 86400;
        $version = (new draft_manager())->restore_from_archive(
            (int) $this->candidate->id,
            'objectifs',
            0,
            'Obtenir le diplome.',
            $savedat,
            'Entrée reprise de l\'archive'
        );

        $this->assertNotNull($version);
        $row = $DB->get_record('tool_vae_version', ['id' => $version->id], '*', MUST_EXIST);
        $this->assertSame((int) $this->candidate->id, (int) $row->authorid, 'The text is the candidate\'s, not the importer\'s.');
        $this->assertSame($savedat, (int) $row->timecreated);
        $draft = $DB->get_record('tool_vae_draft', ['id' => $row->draftid], '*', MUST_EXIST);
        $this->assertSame((int) get_admin()->id, (int) $draft->usermodified, 'The import is traced on the section.');
    }

    public function test_a_person_erased_leaves_the_versions_they_saved_in_someone_elses_document(): void {
        global $DB;

        $draft = $this->section();
        (new draft_manager())->save_version((int) $draft->id, 'Un');
        $DB->set_field('tool_vae_version', 'authorid', $this->reader->id, ['draftid' => $draft->id]);
        $DB->set_field('tool_vae_draft', 'usermodified', $this->reader->id, ['id' => $draft->id]);

        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($this->reader->id),
            'tool_vae',
            [\context_user::instance($this->candidate->id)->id]
        ));

        $this->assertNull($DB->get_field('tool_vae_version', 'authorid', ['draftid' => $draft->id]));
        $this->assertSame('0', (string) $DB->get_field('tool_vae_draft', 'usermodified', ['id' => $draft->id]));
        $this->assertSame(1, $DB->count_records('tool_vae_version'), 'The text stays: it is the candidate\'s.');
    }
}
