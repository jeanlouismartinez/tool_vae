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

use tool_vae\form\note_form;
use tool_vae\local\identity_map;
use tool_vae\local\note_manager;
use tool_vae\local\note_screen;
use tool_vae\local\positioning_rules;
use tool_vae\local\situation_manager;
use tool_vae\local\validation_catalog;

/**
 * The annotations of a manager the vaeiste consented to (lot 1b, task 10), on a work situation or on a node of the
 * self-positioning.
 *
 * WHAT IS ASSERTED. Without consent no annotation, with consent yes; the rights are read again at each annotation (the
 * consent, the following, the right to annotate); a withdrawn consent forbids new annotations and leaves the old ones
 * readable; an annotation never changes the text of the vaeiste; withdrawing adds a line, by the author alone, even after the
 * consent is withdrawn; the bounds (4 000 characters, 200 lines a target); the event carries identifiers, never the text; an
 * annotation of a deleted situation stays readable from the list and a new situation on the same slot does not inherit it;
 * the block signs, dates, escapes, says that an annotation is no validation and keeps apart from the vaeiste's voice.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(note_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(note_screen::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(note_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\note_recorded::class)]
final class note_manager_test extends \advanced_testcase {
    /** @var \stdClass Declares, positions themselves and writes their preparation. */
    private $vaeiste;

    /** @var \stdClass Another vaeiste, with the same rights. */
    private $othervaeiste;

    /** @var \stdClass Follows the vaeiste and holds annotate in their context. */
    private $manager;

    /** @var \stdClass Holds annotate at the site; follows the vaeiste by a role of their context, which can be taken away. */
    private $former;

    /** @var \stdClass Follows the vaeiste, without consent. */
    private $follower;

    /** @var \stdClass Reads every dossier and holds annotate at the site; follows nobody. */
    private $direction;

    /** @var int The role by which the former manager follows the vaeiste. */
    private $followrole;

    /** @var \tool_vae_generator */
    private $generator;

    /**
     * RNCP100 (BC01 holding C01 and C02, BC02 holding C03); the people; the preparation open; one declaration of the vaeiste.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        validation_catalog::reset();
        set_config('validationopen', 1, 'tool_vae');

        $datagenerator = $this->getDataGenerator();
        $this->generator = $datagenerator->get_plugin_generator('tool_vae');
        $this->generator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        $this->generator->create_competency(['fichecode' => 'RNCP100', 'ficherang' => 'BC02', 'shortname' => 'Bloc 2']);
        foreach ([['C01', 'BC01'], ['C02', 'BC01'], ['C03', 'BC02']] as [$rank, $bloc]) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP100', 'ficherang' => $rank, 'shortname' => 'Competence ' . $rank, 'bloc' => $bloc,
            ]);
        }

        $system = \context_system::instance()->id;
        $this->vaeiste = $datagenerator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->othervaeiste = $datagenerator->create_user(['firstname' => 'Sacha', 'lastname' => 'Autre']);
        $this->manager = $datagenerator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->former = $datagenerator->create_user(['firstname' => 'Remi', 'lastname' => 'Ancien']);
        $this->follower = $datagenerator->create_user(['firstname' => 'Paul', 'lastname' => 'Suivant']);
        $this->direction = $datagenerator->create_user(['firstname' => 'Dana', 'lastname' => 'Direction']);
        $vaeistecontext = \context_user::instance($this->vaeiste->id)->id;

        $own = $datagenerator->create_role();
        foreach (['declare', 'viewown', 'positionself', 'draftown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, $system, true);
        }
        set_role_contextlevels($own, [CONTEXT_USER]);
        foreach ([$this->vaeiste, $this->othervaeiste] as $person) {
            role_assign($own, $person->id, \context_user::instance($person->id)->id);
        }

        $annotator = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $annotator, $system, true);
        assign_capability('tool/vae:annotate', CAP_ALLOW, $annotator, $system, true);
        set_role_contextlevels($annotator, [CONTEXT_USER]);
        role_assign($annotator, $this->manager->id, $vaeistecontext);
        role_assign($annotator, $this->follower->id, $vaeistecontext);

        // The former manager: the right to annotate at the site, the following by a role of the vaeiste's context alone.
        $annotatesite = $datagenerator->create_role();
        assign_capability('tool/vae:annotate', CAP_ALLOW, $annotatesite, $system, true);
        role_assign($annotatesite, $this->former->id, $system);
        $this->followrole = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $this->followrole, $system, true);
        set_role_contextlevels($this->followrole, [CONTEXT_USER]);
        role_assign($this->followrole, $this->former->id, $vaeistecontext);

        $director = $datagenerator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $director, $system, true);
        assign_capability('tool/vae:annotate', CAP_ALLOW, $director, $system, true);
        role_assign($director, $this->direction->id, $system);
        accesslib_clear_all_caches_for_unit_testing();

        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Une declaration.',
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
            $this->assertSame($errorcode, $e->errorcode, $e->getMessage());
        }
    }

    /**
     * A situation of the vaeiste on RNCP100, with a text.
     *
     * @param string $title
     * @return \stdClass
     */
    private function situation(string $title = 'Accueil du matin'): \stdClass {
        return $this->generator->create_situation([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'title' => $title, 'tags' => 'C01',
            'text' => 'Le texte du vaeiste.',
        ]);
    }

    /**
     * The consent of the vaeiste to a person.
     *
     * @param \stdClass $person
     * @param string $state given or withdrawn
     */
    private function consent(\stdClass $person, string $state = 'given'): void {
        $this->generator->create_consent([
            'userid' => $this->vaeiste->id, 'managerid' => $person->id, 'state' => $state,
        ]);
    }

    /**
     * Annotate a situation of the vaeiste as the current user.
     *
     * @param \stdClass $situation
     * @param string $text
     * @param int $format
     * @return \stdClass
     */
    private function annotate(\stdClass $situation, string $text = 'Une annotation.', int $format = FORMAT_PLAIN): \stdClass {
        return (new note_manager())->write(
            (int) $this->vaeiste->id,
            note_manager::TARGET_SITUATION,
            'RNCP100',
            (int) $situation->slot,
            '',
            $text,
            $format
        );
    }

    /**
     * The block of the annotations of a situation of the vaeiste, as the current user gets it, without a form.
     *
     * @param \stdClass $situation
     * @return string
     */
    private function block(\stdClass $situation): string {
        return note_screen::block(
            (int) $this->vaeiste->id,
            note_manager::TARGET_SITUATION,
            'RNCP100',
            (int) $situation->slot,
            '',
            $this->read($situation),
            false
        );
    }

    /**
     * The annotations of a situation of the vaeiste, as the current user reads them.
     *
     * @param \stdClass $situation
     * @return \stdClass[]
     */
    private function read(\stdClass $situation): array {
        return (new note_manager())->for_target(
            (int) $this->vaeiste->id,
            note_manager::TARGET_SITUATION,
            'RNCP100',
            (int) $situation->slot,
            ''
        );
    }

    /**
     * A page of the plugin, played as the site plays it, for the current user.
     *
     * @param string $script
     * @param array $get The parameters of the address.
     * @param array $post The fields sent, if the page is sent to.
     * @return string
     */
    private function page(string $script, array $get, array $post = []): string {
        // The page is a script of the site: it reads these as globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SITE, $USER;

        $_GET = $get;
        $_POST = $post;
        $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
        $PAGE = new \moodle_page();
        ob_start();
        try {
            include(__DIR__ . '/../' . $script);
        } finally {
            $out = ob_get_clean();
            $_GET = [];
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        return $out;
    }

    /**
     * Send to a page that redirects when it is done.
     *
     * @param string $script
     * @param array $get
     * @param array $post
     */
    private function send(string $script, array $get, array $post): void {
        try {
            $this->page($script, $get, $post);
            $this->fail('The page goes on.');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode, $e->getMessage());
        }
    }

    public function test_without_consent_no_annotation_with_consent_yes(): void {
        global $DB;

        $situation = $this->situation();
        $this->setUser($this->manager);
        $this->assertFalse(note_manager::can_write((int) $this->vaeiste->id));
        $this->assert_refused(fn() => $this->annotate($situation), 'errornotedenied');
        $this->assertSame(0, $DB->count_records(note_manager::TABLE));

        $this->consent($this->manager);
        $this->setUser($this->manager);
        $this->assertTrue(note_manager::can_write((int) $this->vaeiste->id));
        $this->assertTrue(note_manager::can_write((int) $this->vaeiste->id, (int) $this->manager->id));
        $this->assertFalse(note_manager::can_write((int) $this->vaeiste->id, (int) $this->follower->id), 'Consent is by name.');
        $row = $this->annotate($situation, 'Pensez a decrire le contexte.');

        $this->assertSame((int) $this->manager->id, (int) $row->authorid);
        $this->assertNull($row->authoruuid, 'Written on this site: the archive computes the uuid (D-1b-L).');
        $this->assertSame($situation->uuid, $row->targetuuid);
        $this->assertSame(note_manager::ACTIVE, $row->state);
        $this->assertSame(26, strlen($row->uuid));
        $parts = [note_manager::TARGET_SITUATION, 'RNCP100', (int) $situation->slot, '', 'Pensez a decrire le contexte.'];
        $this->assertSame(positioning_rules::hash($parts), $row->notehash);
        $this->assertSame(1, $DB->count_records(note_manager::TABLE));

        // The same text again, by the same author, is refused; another one is a new line.
        $this->assert_refused(fn() => $this->annotate($situation, 'Pensez a decrire le contexte.'), 'errornoteduplicate');
        $this->annotate($situation, 'Et la suite.');
        $this->assertSame(2, $DB->count_records(note_manager::TABLE));
        $this->assert_refused(fn() => $this->annotate($situation, "  \n "), 'errornoteempty');
        $this->assert_refused(
            fn() => (new note_manager())->write(
                (int) $this->vaeiste->id,
                note_manager::TARGET_SITUATION,
                'RNCP100',
                (int) $situation->slot,
                '',
                'En HTML.',
                FORMAT_HTML
            ),
            'errornotedenied'
        );
    }

    public function test_a_withdrawn_consent_forbids_new_annotations_and_leaves_the_old_ones_readable(): void {
        $situation = $this->situation();
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $this->annotate($situation, 'Avant le retrait.');

        $this->consent($this->manager, 'withdrawn');
        $this->setUser($this->manager);
        $this->assertFalse(note_manager::can_write((int) $this->vaeiste->id));
        $this->assert_refused(fn() => $this->annotate($situation, 'Apres le retrait.'), 'errornotedenied');

        $rows = array_values($this->read($situation));
        $this->assertCount(1, $rows);
        $this->assertSame('Avant le retrait.', $rows[0]->text);
        $this->assertTrue($rows[0]->standing, 'Withdrawing the consent erases no annotation.');
        $this->setUser($this->vaeiste);
        $this->assertCount(1, $this->read($situation));
    }

    public function test_an_annotation_never_changes_the_text_of_the_vaeiste(): void {
        global $DB;

        $situation = $this->situation();
        $before = [
            'draft' => $DB->get_records('tool_vae_draft', null, 'id'),
            'version' => $DB->get_records('tool_vae_version', null, 'id'),
            'sitversion' => $DB->get_records('tool_vae_sitversion', null, 'id'),
            'situation' => $DB->get_records('tool_vae_situation', null, 'id'),
            'sitetag' => $DB->get_records('tool_vae_sitetag', null, 'id'),
            'selfpos' => $DB->get_records('tool_vae_selfpos', null, 'id'),
        ];
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $row = $this->annotate($situation, 'Une remarque.');
        (new note_manager())->write((int) $this->vaeiste->id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'C01', 'Sur C01.');
        (new note_manager())->withdraw((int) $row->id);

        foreach ($before as $table => $rows) {
            $this->assertEquals($rows, $DB->get_records('tool_vae_' . $table, null, 'id'), $table . ' is untouched.');
        }
        $versions = (new situation_manager())->versions((int) $situation->id);
        $this->assertCount(1, $versions);
        $this->assertSame('Le texte du vaeiste.', reset($versions)->content);
    }

    public function test_withdrawing_adds_a_line_and_the_author_alone_does_it_even_after_the_consent_is_withdrawn(): void {
        global $DB;

        $situation = $this->situation();
        $this->consent($this->manager);
        $this->consent($this->follower);
        $this->setUser($this->manager);
        $row = $this->annotate($situation, 'Ma parole.');

        // Neither another annotator, nor the vaeiste, nor the administrator withdraws it.
        foreach ([$this->follower, $this->vaeiste, $this->direction] as $person) {
            $this->setUser($person);
            $this->assert_refused(fn() => (new note_manager())->withdraw((int) $row->id), 'errornotedenied');
        }
        $this->setAdminUser();
        $this->assert_refused(fn() => (new note_manager())->withdraw((int) $row->id), 'errornotedenied');

        // The consent withdrawn, the author still takes back their word.
        $this->consent($this->manager, 'withdrawn');
        $this->setUser($this->manager);
        $withdrawal = (new note_manager())->withdraw((int) $row->id);
        $this->assertSame(note_manager::WITHDRAWN, $withdrawal->state);
        $this->assertSame($row->uuid, $withdrawal->withdraws);
        $this->assertSame('', $withdrawal->text);
        $this->assertSame((int) $this->manager->id, (int) $withdrawal->authorid);
        $this->assertSame(2, $DB->count_records(note_manager::TABLE), 'A line is added; none is changed.');
        $this->assertSame('Ma parole.', $DB->get_field(note_manager::TABLE, 'text', ['id' => $row->id]));
        $this->assert_refused(fn() => (new note_manager())->withdraw((int) $row->id), 'errornotegone');
        $this->assert_refused(fn() => (new note_manager())->withdraw((int) $withdrawal->id), 'errornotegone');
        $this->assert_refused(fn() => (new note_manager())->withdraw(999999), 'errornotegone');

        $this->setUser($this->vaeiste);
        $rows = array_values($this->read($situation));
        $this->assertCount(2, $rows);
        $this->assertFalse($rows[0]->standing, 'Withdrawn by the later line.');
        $this->assertSame(note_manager::WITHDRAWN, $rows[1]->state);

        // The author who no longer follows takes back their word too.
        $this->consent($this->former);
        $this->setUser($this->former);
        $other = $this->annotate($situation, 'Du responsable parti.');
        role_unassign($this->followrole, $this->former->id, \context_user::instance($this->vaeiste->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        (new note_manager())->withdraw((int) $other->id);
        $this->assertSame(4, $DB->count_records(note_manager::TABLE));
    }

    public function test_four_thousand_and_one_characters_are_refused(): void {
        $situation = $this->situation();
        $this->consent($this->manager);
        $this->setUser($this->manager);

        $this->assertSame(4000, note_manager::MAX_LENGTH);
        $this->assert_refused(fn() => $this->annotate($situation, str_repeat('é', 4001)), 'errorpositioningtoolong');
        $row = $this->annotate($situation, str_repeat('é', 4000));
        $this->assertSame(4000, \core_text::strlen($row->text));
    }

    /**
     * A raw line of the manager on a target, written as the table holds it.
     *
     * @param array $target target, targetuuid, slot, ficherang.
     * @param string $state
     * @param string|null $withdraws
     * @return \stdClass
     */
    private function raw_line(array $target, string $state = note_manager::ACTIVE, ?string $withdraws = null): \stdClass {
        return (object) ($target + [
            'uuid' => identity_map::generate_ulid(), 'candidateid' => (int) $this->vaeiste->id, 'fichecode' => 'RNCP100',
            'authorid' => (int) $this->manager->id, 'authoruuid' => null, 'text' => $state === note_manager::ACTIVE ? 'Ligne' : '',
            'textformat' => FORMAT_PLAIN, 'state' => $state, 'withdraws' => $withdraws, 'notehash' => str_repeat('0', 64),
            'importedat' => null, 'timecreated' => time(),
        ]);
    }

    /**
     * The bound of a target counts the annotations that stand open, never the withdrawals (T15, ruling of the controller): a
     * withdrawal is never refused by it, the two hundred and first ACTIVE line is.
     */
    public function test_more_than_two_hundred_active_lines_on_one_target_are_refused_and_a_withdrawal_never_is(): void {
        global $DB;

        $situation = $this->situation();
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $first = $this->annotate($situation, 'La premiere.');
        $on = [
            'target' => note_manager::TARGET_SITUATION, 'targetuuid' => $situation->uuid, 'slot' => (int) $situation->slot,
            'ficherang' => '',
        ];
        $rows = [];
        for ($i = 2; $i <= note_manager::MAX_PER_TARGET; $i++) {
            $rows[] = $this->raw_line($on);
        }
        $DB->insert_records(note_manager::TABLE, $rows);
        $this->assertSame(200, $DB->count_records(note_manager::TABLE));

        $this->assert_refused(fn() => $this->annotate($situation, 'La deux cent unieme.'), 'errornotefull');
        // Two hundred lines, and the author takes their word back all the same: 201 lines, the first withdrawn.
        (new note_manager())->withdraw((int) $first->id);
        $this->assertSame(201, $DB->count_records(note_manager::TABLE));
        $standing = array_column($this->read($situation), 'standing', 'uuid');
        $this->assertFalse($standing[$first->uuid]);
        // The withdrawn annotation was written all the same: a 201st annotation is still refused (the storage stays bounded).
        $this->assert_refused(fn() => $this->annotate($situation, 'La deux cent unieme.'), 'errornotefull');
        $this->assertSame(201, $DB->count_records(note_manager::TABLE));

        // Lines of withdrawal do not count: 150 annotations and 60 withdrawals on a node leave room to write.
        $node = ['target' => note_manager::TARGET_SELFPOS, 'targetuuid' => '', 'slot' => 0, 'ficherang' => 'C01'];
        $rows = [];
        for ($i = 1; $i <= 150; $i++) {
            $rows[] = $this->raw_line($node);
        }
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = $this->raw_line($node, note_manager::WITHDRAWN, $rows[$i - 1]->uuid);
        }
        $DB->insert_records(note_manager::TABLE, $rows);
        (new note_manager())->write((int) $this->vaeiste->id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'C01', 'Ailleurs.');
        $this->assertSame(201 + 210 + 1, $DB->count_records(note_manager::TABLE));
    }

    public function test_the_vaeiste_the_administrator_and_a_follower_who_does_not_follow_anymore_cannot_annotate(): void {
        global $DB;

        $situation = $this->situation();
        $id = (int) $this->vaeiste->id;

        $this->setUser($this->vaeiste);
        $this->assertFalse(note_manager::can_write($id));
        $this->assert_refused(fn() => $this->annotate($situation), 'errornotedenied');

        $this->setAdminUser();
        $this->assertFalse(note_manager::can_write($id));
        $this->assert_refused(fn() => $this->annotate($situation), 'errornotedenied');

        // The direction reads every dossier and holds annotate, but follows nobody: no consent can be given to it.
        $this->setUser($this->direction);
        $this->assertFalse(note_manager::can_write($id));

        // The former manager could annotate; once they no longer follow, the consent that stands opens nothing.
        $this->consent($this->former);
        $this->setUser($this->former);
        $this->assertTrue(note_manager::can_write($id));
        role_unassign($this->followrole, $this->former->id, \context_user::instance($id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(\tool_vae\local\consent_manager::stands($id, (int) $this->former->id), 'The consent stays.');
        $this->assertFalse(note_manager::can_write($id));
        $this->assert_refused(fn() => $this->annotate($situation), 'errornotedenied');

        // A manager whose right to annotate is taken away after the consent is refused as well.
        $this->consent($this->manager);
        $prevent = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:annotate', CAP_PROHIBIT, $prevent, \context_system::instance()->id, true);
        role_assign($prevent, $this->manager->id, \context_user::instance($id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->manager);
        $this->assertFalse(note_manager::can_write($id));
        $this->assert_refused(fn() => $this->annotate($situation), 'errornotedenied');

        $this->assertSame(0, $DB->count_records(note_manager::TABLE));
        $this->setGuestUser();
        $this->assertFalse(note_manager::can_write($id));
    }

    public function test_an_annotation_on_a_selfpositioning_node_and_on_the_record_itself(): void {
        $id = (int) $this->vaeiste->id;
        $situation = $this->situation();
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $manager = new note_manager();

        $node = $manager->write($id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'C01', 'Sur la competence.');
        $this->assertSame('', $node->targetuuid);
        $this->assertSame('C01', $node->ficherang);
        $record = $manager->write($id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, '', 'Sur le referentiel.', FORMAT_MARKDOWN);
        $this->assertSame('', $record->ficherang);
        $this->assertSame((int) FORMAT_MARKDOWN, (int) $record->textformat);
        $manager->write($id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'BC01', 'Sur le bloc.');

        // A node that is not there, a rank given to a situation, a slot given to a node, an unknown record or target: refused.
        $refused = [
            [note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'C99'],
            [note_manager::TARGET_SELFPOS, 'RNCP100', 3, 'C01'],
            [note_manager::TARGET_SELFPOS, 'rncp100', 0, 'C01'],
            [note_manager::TARGET_SELFPOS, 'RNCP999', 0, ''],
            [note_manager::TARGET_SITUATION, 'RNCP100', (int) $situation->slot, 'C01'],
            [note_manager::TARGET_SITUATION, 'RNCP100', 7, ''],
            [note_manager::TARGET_SITUATION, 'rncp100', (int) $situation->slot, ''],
            ['other', 'RNCP100', 0, ''],
        ];
        foreach ($refused as [$target, $code, $slot, $rank]) {
            $this->assert_refused(fn() => $manager->write($id, $target, $code, $slot, $rank, 'Refusee.'), 'errornotedenied');
        }

        $c01 = $manager->for_target($id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'C01');
        $this->assertSame(['Sur la competence.'], array_values(array_map(fn($row) => $row->text, $c01)));
        $whole = $manager->for_target($id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, '');
        $this->assertSame(['Sur le referentiel.'], array_values(array_map(fn($row) => $row->text, $whole)));
        $this->assertSame([], $this->read($situation), 'A node is not the situation.');
        $this->assertCount(3, $manager->for_candidate($id));
    }

    public function test_whoever_may_view_reads_the_annotations(): void {
        $situation = $this->situation();
        $this->consent($this->manager);
        $row = $this->generator->create_note([
            'authorid' => $this->manager->id, 'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100',
            'slot' => (int) $situation->slot, 'text' => 'A lire.',
        ]);
        $this->assertSame((int) $this->manager->id, (int) $row->authorid, 'The generator writes in the name of the author.');

        foreach ([$this->vaeiste, $this->manager, $this->follower, $this->direction] as $person) {
            $this->setUser($person);
            $this->assertCount(1, $this->read($situation), fullname($person));
            $this->assertCount(1, (new note_manager())->for_candidate((int) $this->vaeiste->id), fullname($person));
        }
        $this->setUser($this->othervaeiste);
        $this->expectException(\required_capability_exception::class);
        $this->read($situation);
    }

    public function test_the_event_carries_identifiers_never_the_text(): void {
        $situation = $this->situation();
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $sink = $this->redirectEvents();

        $row = $this->annotate($situation, 'Texte confidentiel de Nadia.');
        $withdrawal = (new note_manager())->withdraw((int) $row->id);

        $events = $sink->get_events();
        $this->assertCount(2, $events);
        foreach ([[$events[0], $row, 'active'], [$events[1], $withdrawal, 'withdrawn']] as [$event, $line, $state]) {
            $this->assertInstanceOf(\tool_vae\event\note_recorded::class, $event);
            $data = $event->get_data();
            $this->assertSame((int) $this->vaeiste->id, (int) $data['relateduserid']);
            $this->assertSame((int) $line->id, (int) $data['objectid']);
            $this->assertSame(\context_user::instance($this->vaeiste->id)->id, (int) $data['contextid']);
            $this->assertSame([
                'target' => 'situation', 'fichecode' => 'RNCP100', 'slot' => (int) $situation->slot, 'ficherang' => '',
                'state' => $state,
            ], $data['other']);
            $encoded = json_encode($data) . $event->get_description();
            foreach (['confidentiel', 'Benali', 'Camille', 'Accueil'] as $word) {
                $this->assertStringNotContainsString($word, $encoded);
            }
        }

        $this->expectException(\coding_exception::class);
        \tool_vae\event\note_recorded::create([
            'context' => \context_user::instance($this->vaeiste->id), 'objectid' => 1,
            'relateduserid' => (int) $this->vaeiste->id,
            'other' => [
                'target' => 'situation', 'fichecode' => 'RNCP100', 'slot' => 1, 'ficherang' => '', 'state' => 'active',
                'text' => 'Un texte libre',
            ],
        ]);
    }

    public function test_a_deleted_situation_keeps_its_annotations_readable_and_a_new_situation_does_not_inherit_them(): void {
        global $DB;

        $situation = $this->situation('Ancienne situation');
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $this->annotate($situation, 'Sur la situation supprimee.');

        $this->setUser($this->vaeiste);
        (new situation_manager())->delete((int) $situation->id);
        $new = $this->situation('Nouvelle situation');
        $this->assertSame((int) $situation->slot, (int) $new->slot, 'The slot is taken again.');
        $this->assertNotSame($situation->uuid, $new->uuid);

        $this->assertSame([], $this->read($new), 'The new situation inherits nothing.');
        $all = (new note_manager())->for_candidate((int) $this->vaeiste->id);
        $this->assertCount(1, $all);
        $this->assertSame($situation->uuid, reset($all)->targetuuid);

        // The list of the situations shows it apart, under "situation deleted", to the vaeiste and to a reader.
        foreach ([$this->vaeiste, $this->manager] as $person) {
            $this->setUser($person);
            $html = $this->page('situations.php', ['userid' => (int) $this->vaeiste->id, 'fiche' => 'RNCP100']);
            $this->assertStringContainsString(s(get_string('notetargetgone', 'tool_vae')), $html);
            $this->assertStringContainsString('Sur la situation supprimee.', $html);
            $page = $this->page('situation.php', ['userid' => (int) $this->vaeiste->id, 'fiche' => 'RNCP100',
                'slot' => (int) $new->slot]);
            $this->assertStringNotContainsString('Sur la situation supprimee.', $page);
        }

        // An annotation on the new situation is its own.
        $this->setUser($this->manager);
        $this->annotate($new, 'Sur la nouvelle.');
        $this->assertSame(['Sur la nouvelle.'], array_values(array_map(fn($row) => $row->text, $this->read($new))));
        $this->assertSame(2, $DB->count_records(note_manager::TABLE));
    }

    public function test_the_block_signs_dates_and_separates_the_annotation_from_the_vaeiste_voice(): void {
        global $DB;

        $id = (int) $this->vaeiste->id;
        $situation = $this->situation();
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $row = $this->annotate($situation, 'Une remarque du responsable.');
        $restored = (object) [
            'uuid' => identity_map::generate_ulid(), 'candidateid' => $id, 'target' => note_manager::TARGET_SITUATION,
            'targetuuid' => $situation->uuid, 'fichecode' => 'RNCP100', 'slot' => (int) $situation->slot, 'ficherang' => '',
            'authorid' => null, 'authoruuid' => identity_map::generate_ulid(), 'text' => 'Une annotation sans auteur.',
            'textformat' => FORMAT_PLAIN, 'state' => note_manager::ACTIVE, 'withdraws' => null,
            'notehash' => str_repeat('0', 64), 'importedat' => time(), 'timecreated' => time(),
        ];
        $DB->insert_record(note_manager::TABLE, $restored);
        // And a line whose author was erased: no account, no identifier of an archive either.
        $erased = clone $restored;
        $erased->uuid = identity_map::generate_ulid();
        $erased->authoruuid = null;
        $erased->importedat = null;
        $erased->text = 'Une annotation d\'un auteur efface.';
        $DB->insert_record(note_manager::TABLE, $erased);

        $html = $this->block($situation);
        $this->assertStringContainsString(s(get_string('notesheading', 'tool_vae')), $html);
        $this->assertStringContainsString(s(get_string('notesnotvalidation', 'tool_vae')), $html);
        $this->assertStringContainsString('tool-vae-note', $html);
        $this->assertStringContainsString(s(get_string('noteby', 'tool_vae', (object) [
            'name' => fullname($this->manager),
            'when' => userdate((int) $row->timecreated, get_string('strftimedatetime', 'langconfig')),
        ])), $html);
        // A restored line names an author of the archive, not resolved into an account (T15, point G6): never "erased".
        $this->assertSame(1, substr_count($html, s(get_string('noteauthorarchive', 'tool_vae'))), 'The restored line.');
        $this->assertSame(1, substr_count($html, s(get_string('noteauthorgone', 'tool_vae'))), 'The erased author.');
        $this->assertStringContainsString(s(get_string('notewithdraw', 'tool_vae')), $html, 'The author may withdraw.');
        $this->assertSame(1, substr_count($html, 'withdrawnote'), 'Only their own line.');
        $this->assertDoesNotMatchRegularExpression('/\d+\s*%|' . preg_quote(get_string('total'), '/') . '/i', strip_tags($html));

        // The withdrawal is shown as a line.
        (new note_manager())->withdraw((int) $row->id);
        $html = $this->block($situation);
        $this->assertStringContainsString(s(get_string('notewithdrawn', 'tool_vae')), $html);
        $this->assertStringNotContainsString('withdrawnote', $html, 'Nothing left to withdraw.');

        // Empty, the block says so; the form is drawn for whoever may write only.
        $form = '<form id="x"></form>';
        $empty = note_screen::block($id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'C02', [], true, $form);
        $this->assertStringContainsString(s(get_string('notesnone', 'tool_vae')), $empty);
        $this->assertStringContainsString($form, $empty);
        $reading = note_screen::block($id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'C02', [], false, $form);
        $this->assertStringNotContainsString($form, $reading);

        // On the page, the vaeiste's text comes first, and the annotations after it, in their own section.
        $this->setUser($this->vaeiste);
        $page = $this->page('situation.php', ['userid' => $id, 'fiche' => 'RNCP100', 'slot' => (int) $situation->slot]);
        $this->assertStringContainsString('Une annotation sans auteur.', $page);
        $this->assertStringNotContainsString('_qf__tool_vae_form_note_form', $page, 'The vaeiste never annotates.');
        $this->assertStringContainsString(s(get_string('notesnotvalidation', 'tool_vae')), $page);
        $this->setUser($this->manager);
        $page = $this->page('situation.php', ['userid' => $id, 'fiche' => 'RNCP100', 'slot' => (int) $situation->slot]);
        $this->assertGreaterThan(strpos($page, 'Le texte du vaeiste.'), strpos($page, 'tool-vae-notes'));
        $this->assertStringContainsString('_qf__tool_vae_form_note_form', $page, 'The manager with consent annotates.');
        $this->assertStringContainsString('tool_vae_anonymise_note', $page, 'The reminder is on the form.');
        $this->setUser($this->follower);
        $page = $this->page('situation.php', ['userid' => $id, 'fiche' => 'RNCP100', 'slot' => (int) $situation->slot]);
        $this->assertStringContainsString('Une annotation sans auteur.', $page);
        $this->assertStringNotContainsString('_qf__tool_vae_form_note_form', $page, 'Without consent, no form.');
    }

    public function test_the_pages_write_and_withdraw_with_the_session_key_only(): void {
        global $DB;

        $id = (int) $this->vaeiste->id;
        $situation = $this->situation();
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $at = ['userid' => $id, 'fiche' => 'RNCP100', 'slot' => (int) $situation->slot];
        $form = ['_qf__tool_vae_form_note_form' => 1, 'notetext' => 'Par la page.', 'markdown' => 0, 'submitbutton' => 'x'];

        try {
            $this->page('situation.php', $at, $form);
            $this->fail('A send without the key is refused.');
        } catch (\moodle_exception $e) {
            $this->assertMatchesRegularExpression('/sesskey|session/i', $e->errorcode . ' ' . $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records(note_manager::TABLE), 'Without the key nothing is written.');
        $this->send('situation.php', $at, $form + ['sesskey' => sesskey()]);
        $row = $DB->get_record(note_manager::TABLE, [], '*', MUST_EXIST);
        $this->assertSame('Par la page.', $row->text);

        // On a node of the self-positioning, from its page.
        $node = ['userid' => $id, 'fiche' => 'RNCP100', 'rank' => 'C01'];
        $html = $this->page('selfposition.php', $node);
        $this->assertStringContainsString('_qf__tool_vae_form_note_form', $html);
        $this->assertStringContainsString(s(get_string('notesheading', 'tool_vae')), $html);
        $this->send('selfposition.php', $node, ['notetext' => 'Sur le noeud.'] + $form + ['sesskey' => sesskey()]);
        $this->assertSame(1, $DB->count_records(note_manager::TABLE, ['target' => 'selfpos', 'ficherang' => 'C01']));

        // Withdrawing: a POST with the key, by the author.
        $withdraw = ['action' => 'withdrawnote', 'note' => (int) $row->id];
        try {
            $this->page('situation.php', $at, $withdraw);
            $this->fail('A withdrawal without the key is refused.');
        } catch (\moodle_exception $e) {
            $this->assertMatchesRegularExpression('/sesskey|session/i', $e->errorcode . ' ' . $e->getMessage());
        }
        $this->assertSame(0, $DB->count_records(note_manager::TABLE, ['state' => 'withdrawn']));
        $this->setUser($this->vaeiste);
        $this->send('situation.php', $at, $withdraw + ['sesskey' => sesskey()]);
        $this->assertSame(0, $DB->count_records(note_manager::TABLE, ['state' => 'withdrawn']), 'Not the vaeiste.');
        $this->setUser($this->manager);
        $this->send('situation.php', $at, $withdraw + ['sesskey' => sesskey()]);
        $this->assertSame(1, $DB->count_records(note_manager::TABLE, ['state' => 'withdrawn']));

        // The vaeiste sending the form writes nothing.
        $this->setUser($this->vaeiste);
        $this->page('situation.php', $at, ['notetext' => 'Du vaeiste.'] + $form + ['sesskey' => sesskey()]);
        $this->assertSame(3, $DB->count_records(note_manager::TABLE), 'Two annotations and a withdrawal, nothing more.');
    }

    public function test_the_administrator_reads_no_annotation_of_a_situation(): void {
        $id = (int) $this->vaeiste->id;
        $situation = $this->situation();
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $this->annotate($situation, 'Pour le vaeiste.');

        foreach ([$this->vaeiste, $this->manager, $this->direction] as $person) {
            $this->setUser($person);
            $this->assertCount(1, $this->read($situation), fullname($person));
        }

        // The site administrator passes viewany, never the reading of the preparation (MC-16): neither the API nor a page.
        $this->setAdminUser();
        $this->assert_refused(fn() => $this->read($situation), 'errordraftrefused');
        $pages = [
            ['situations.php', ['userid' => $id, 'fiche' => 'RNCP100']],
            ['situation.php', ['userid' => $id, 'fiche' => 'RNCP100', 'slot' => (int) $situation->slot]],
        ];
        foreach ($pages as [$script, $get]) {
            try {
                $html = $this->page($script, $get);
                $this->fail($script . ' renders nothing to the administrator: ' . strlen($html));
            } catch (\moodle_exception $e) {
                $this->assertSame('errordraftrefused', $e->errorcode, $script);
            }
        }
    }

    public function test_a_page_withdraws_only_an_annotation_of_its_own_target(): void {
        global $DB;

        $id = (int) $this->vaeiste->id;
        $otherid = (int) $this->othervaeiste->id;
        $first = $this->situation('Premiere');
        $second = $this->situation('Seconde');
        $this->consent($this->manager);
        // The manager follows the other vaeiste too, and holds their consent.
        $annotator = $this->getDataGenerator()->create_role();
        $system = \context_system::instance()->id;
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $annotator, $system, true);
        assign_capability('tool/vae:annotate', CAP_ALLOW, $annotator, $system, true);
        set_role_contextlevels($annotator, [CONTEXT_USER]);
        role_assign($annotator, $this->manager->id, \context_user::instance($otherid)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->generator->create_consent(['userid' => $otherid, 'managerid' => $this->manager->id]);

        $this->setUser($this->manager);
        $manager = new note_manager();
        $onfirst = $this->annotate($first, 'Sur la premiere.');
        $onnode = $manager->write($id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'C01', 'Sur C01.');
        $onother = $manager->write($otherid, note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'C01', 'Sur l autre vaeiste.');

        $at = fn(\stdClass $situation) => ['userid' => $id, 'fiche' => 'RNCP100', 'slot' => (int) $situation->slot];
        $node = fn(string $rank) => ['userid' => $id, 'fiche' => 'RNCP100', 'rank' => $rank];
        $wrong = [
            ['situation.php', $at($second), $onfirst],
            ['situation.php', $at($first), $onnode],
            ['situation.php', $at($first), $onother],
            ['selfposition.php', $node('C02'), $onnode],
            ['selfposition.php', $node('C01'), $onfirst],
            ['selfposition.php', $node('C01'), $onother],
            ['situations.php', ['userid' => $id, 'fiche' => 'RNCP100'], $onfirst],
        ];
        foreach ($wrong as [$script, $get, $note]) {
            $this->send($script, $get, ['action' => 'withdrawnote', 'note' => (int) $note->id, 'sesskey' => sesskey()]);
            $this->assertSame(0, $DB->count_records(note_manager::TABLE, ['state' => 'withdrawn']), $script . ' ' . $note->text);
        }
        $this->assert_refused(
            fn() => $manager->withdraw((int) $onother->id, ['candidateid' => $id]),
            'errornotedenied'
        );

        // From the page of its own target, it is withdrawn.
        $this->send('situation.php', $at($first), ['action' => 'withdrawnote', 'note' => (int) $onfirst->id,
            'sesskey' => sesskey()]);
        $this->send('selfposition.php', $node('C01'), ['action' => 'withdrawnote', 'note' => (int) $onnode->id,
            'sesskey' => sesskey()]);
        $this->assertSame(2, $DB->count_records(note_manager::TABLE, ['state' => 'withdrawn']));
    }

    public function test_the_author_withdraws_an_annotation_of_a_deleted_situation_from_the_list(): void {
        global $DB;

        $id = (int) $this->vaeiste->id;
        $list = ['userid' => $id, 'fiche' => 'RNCP100'];
        $situation = $this->situation('Ancienne');
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $orphan = $this->annotate($situation, 'Sur l ancienne.');
        $this->setUser($this->vaeiste);
        (new situation_manager())->delete((int) $situation->id);
        $new = $this->situation('Nouvelle');
        $this->setUser($this->manager);
        $held = $this->annotate($new, 'Sur la nouvelle.');

        $this->assertStringContainsString('withdrawnote', $this->page('situations.php', $list), 'The author has the button.');
        $this->setUser($this->vaeiste);
        $this->assertStringNotContainsString('withdrawnote', $this->page('situations.php', $list), 'Not the vaeiste.');
        $this->send('situations.php', $list, ['action' => 'withdrawnote', 'note' => (int) $orphan->id, 'sesskey' => sesskey()]);
        $this->assertSame(0, $DB->count_records(note_manager::TABLE, ['state' => 'withdrawn']), 'Not the vaeiste.');

        $this->setUser($this->manager);
        try {
            $this->page('situations.php', $list, ['action' => 'withdrawnote', 'note' => (int) $orphan->id]);
            $this->fail('A withdrawal without the key is refused.');
        } catch (\moodle_exception $e) {
            $this->assertMatchesRegularExpression('/sesskey|session/i', $e->errorcode . ' ' . $e->getMessage());
        }
        // The annotation of a situation that is still there is withdrawn from its own page, not from the list.
        $this->send('situations.php', $list, ['action' => 'withdrawnote', 'note' => (int) $held->id, 'sesskey' => sesskey()]);
        $this->assertSame(0, $DB->count_records(note_manager::TABLE, ['state' => 'withdrawn']));

        $this->send('situations.php', $list, ['action' => 'withdrawnote', 'note' => (int) $orphan->id, 'sesskey' => sesskey()]);
        $this->assertSame(1, $DB->count_records(note_manager::TABLE, ['state' => 'withdrawn', 'withdraws' => $orphan->uuid]));
        $html = $this->page('situations.php', $list);
        $this->assertStringContainsString(s(get_string('notewithdrawn', 'tool_vae')), $html);
        $this->assertStringNotContainsString('withdrawnote', $html, 'Nothing left to withdraw there.');
    }

    public function test_the_same_text_twice_is_refused_and_written_again_after_its_withdrawal(): void {
        global $DB;

        $situation = $this->situation();
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $first = $this->annotate($situation, 'Le meme texte.');
        $this->assert_refused(fn() => $this->annotate($situation, 'Le meme texte.'), 'errornoteduplicate');
        (new note_manager())->withdraw((int) $first->id);
        $again = $this->annotate($situation, 'Le meme texte.');
        $this->assertSame($first->notehash, $again->notehash);
        $this->assertSame(3, $DB->count_records(note_manager::TABLE));

        // The generator asks the slot of a situation: none would be a note on nothing.
        $this->expectException(\coding_exception::class);
        $this->generator->create_note([
            'authorid' => $this->manager->id, 'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100',
            'text' => 'Sans emplacement.',
        ]);
    }

    public function test_every_text_is_escaped(): void {
        global $DB;

        $id = (int) $this->vaeiste->id;
        $situation = $this->situation();
        $evil = $this->getDataGenerator()->create_user(['firstname' => 'Mal', 'lastname' => 'Veillant']);
        // The generator cleans a name; an account made elsewhere (an authentication plugin, a script) may not.
        $DB->set_field('user', 'firstname', '<b>Mal</b>', ['id' => $evil->id]);
        $DB->set_field('user', 'lastname', '<i>Veillant</i>', ['id' => $evil->id]);
        $role = $this->getDataGenerator()->create_role();
        $system = \context_system::instance()->id;
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $role, $system, true);
        assign_capability('tool/vae:annotate', CAP_ALLOW, $role, $system, true);
        set_role_contextlevels($role, [CONTEXT_USER]);
        role_assign($role, $evil->id, \context_user::instance($id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->consent($evil);
        $this->setUser($evil);
        $this->annotate($situation, '<script>alert(1)</script> <img src=x onerror=alert(2)>');
        $this->annotate($situation, "**Gras** <script>alert(3)</script>\n\n[lien](javascript:alert(4))", FORMAT_MARKDOWN);

        $this->setUser($this->vaeiste);
        $html = $this->block($situation);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('<b>Mal</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Mal&lt;/b&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('<strong>Gras</strong>', $html);
    }

    /**
     * T12, tour 1: a line of withdrawal takes back an annotation only when it comes from the same place (written here, or
     * restored) and from the same author (authorid for a local line, authoruuid for a restored one). A restored line can
     * therefore never take back what a manager wrote here, whatever identifier it names.
     */
    public function test_a_withdrawal_withdraws_only_the_annotation_of_its_own_author_and_origin(): void {
        global $DB;
        $situation = $this->situation();
        $this->consent($this->manager);
        $this->setUser($this->manager);
        $local = $this->annotate($situation, 'Ma ligne locale.');
        $authora = '01JC8M3Q5R7T9V2X4Z6B8D0G1A';
        $authorb = '01JC8M3Q5R7T9V2X4Z6B8D0G2B';
        $restored = function (array $fields) use ($situation): string {
            global $DB;
            $row = (object) ($fields + [
                'uuid' => identity_map::generate_ulid(), 'candidateid' => (int) $this->vaeiste->id,
                'target' => note_manager::TARGET_SITUATION, 'targetuuid' => (string) $situation->uuid, 'fichecode' => 'RNCP100',
                'slot' => (int) $situation->slot, 'ficherang' => '', 'authorid' => null, 'authoruuid' => null, 'text' => '',
                'textformat' => FORMAT_PLAIN, 'state' => note_manager::WITHDRAWN, 'withdraws' => null,
                'notehash' => str_repeat('0', 64), 'importedat' => time(), 'timecreated' => time() + 1,
            ]);
            $DB->insert_record(note_manager::TABLE, $row);

            return (string) $row->uuid;
        };
        $standing = function () use ($situation): array {
            $this->setUser($this->vaeiste);
            $out = [];
            foreach ($this->read($situation) as $row) {
                $out[(string) $row->uuid] = $row->standing;
            }

            return $out;
        };

        // A restored withdrawal naming the local annotation: the annotation stands.
        $restored(['withdraws' => $local->uuid, 'authoruuid' => $authora]);
        $this->assertTrue($standing()[$local->uuid]);

        // A restored annotation of A, withdrawn by a restored line of B: stands; by a restored line of A: withdrawn.
        $ua = $restored(['state' => note_manager::ACTIVE, 'text' => 'Restauree.', 'authoruuid' => $authora]);
        $restored(['withdraws' => $ua, 'authoruuid' => $authorb]);
        $this->assertTrue($standing()[$ua]);
        $restored(['withdraws' => $ua, 'authoruuid' => $authora]);
        $this->assertFalse($standing()[$ua]);

        // A local withdrawal by another account than the author (written by hand: withdraw() refuses it) takes nothing back.
        $other = $restored([
            'withdraws' => $local->uuid, 'authorid' => (int) $this->follower->id, 'importedat' => null,
        ]);
        $this->assertTrue($standing()[$local->uuid]);
        $DB->delete_records(note_manager::TABLE, ['uuid' => $other]);

        // The author's own withdrawal, written here, takes it back.
        $this->setUser($this->manager);
        $DB->delete_records(note_manager::TABLE, ['withdraws' => $local->uuid]);
        (new note_manager())->withdraw((int) $local->id);
        $this->assertFalse($standing()[$local->uuid]);
    }
}
