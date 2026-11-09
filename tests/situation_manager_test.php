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

use tool_vae\local\draft_manager;
use tool_vae\local\situation_manager;
use tool_vae\local\text_diff;
use tool_vae\local\validation_catalog;
use tool_vae\local\validation_status;

/**
 * The work situations of the vaeiste: their text, their versions, their tags (lot 1b, task 5).
 *
 * THE VAEISTE'S OWN MATERIAL, NEVER A VALIDATION. The vaeiste alone writes, whoever may view the dossier reads, the site
 * administrator does neither. A situation is a draft with versions, as a section of the preparation is; each version keeps
 * the title, period, setting and tags it was written with. Nothing is computed: counts, never sums.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(situation_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\situation_created::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\situation_deleted::class)]
final class situation_manager_test extends \advanced_testcase {
    /** @var \stdClass The vaeiste: viewown and draftown on their own context, no declaration at all. */
    private $vaeiste;

    /** @var \stdClass Another vaeiste, with the same rights on their own context. */
    private $othervaeiste;

    /** @var \stdClass Follows the vaeiste (viewassigned in their context). */
    private $manager;

    /** @var \stdClass Follows somebody else only. */
    private $outsider;

    /** @var \stdClass Holds viewany at the site. */
    private $direction;

    /** @var \tool_vae_generator */
    private $generator;

    /**
     * RNCP100 with two blocs (BC01 holding C01 and C02, BC02 holding C03); RNCP400 with four levels (D1 holding C11, which
     * holds H111 and H112, and C12; D2 alone); the people; the preparation open.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // The code under test opens delegated transactions and rolls one back when a snapshot fails. advanced_testcase wraps
        // each test in a transaction of its own, and Moodle only undoes the outermost level: without this call, an inner
        // rollback undoes nothing the assertions can see and aborts the test's own transaction (trap 4 of docs/BANC-ESSAI.md).
        $this->preventResetByRollback();
        validation_catalog::reset();
        set_config('validationopen', 1, 'tool_vae');

        $datagenerator = $this->getDataGenerator();
        $this->generator = $datagenerator->get_plugin_generator('tool_vae');
        $system = \context_system::instance()->id;

        $this->generator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        $this->generator->create_competency(['fichecode' => 'RNCP100', 'ficherang' => 'BC02', 'shortname' => 'Bloc 2']);
        foreach ([['C01', 'BC01'], ['C02', 'BC01'], ['C03', 'BC02']] as [$rank, $bloc]) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP100', 'ficherang' => $rank, 'shortname' => 'Competence ' . $rank, 'bloc' => $bloc,
            ]);
        }
        $this->generator->create_source(['fichecode' => 'RNCP400', 'ficherang' => 'D1', 'shortname' => 'Domaine 1']);
        foreach ([['C11', 'D1'], ['H111', 'C11'], ['H112', 'C11'], ['C12', 'D1'], ['D2', '']] as [$rank, $parent]) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP400', 'ficherang' => $rank, 'shortname' => 'Noeud ' . $rank, 'parent' => $parent,
            ]);
        }

        $this->vaeiste = $datagenerator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->othervaeiste = $datagenerator->create_user(['firstname' => 'Sacha', 'lastname' => 'Autre']);
        $this->manager = $datagenerator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->outsider = $datagenerator->create_user();
        $this->direction = $datagenerator->create_user(['firstname' => 'Dana', 'lastname' => 'Direction']);

        $own = $datagenerator->create_role();
        assign_capability('tool/vae:viewown', CAP_ALLOW, $own, $system, true);
        assign_capability('tool/vae:draftown', CAP_ALLOW, $own, $system, true);
        set_role_contextlevels($own, [CONTEXT_USER]);
        foreach ([$this->vaeiste, $this->othervaeiste] as $person) {
            role_assign($own, $person->id, \context_user::instance($person->id)->id);
        }

        $follow = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, \context_user::instance($this->vaeiste->id)->id);
        role_assign($follow, $this->outsider->id, \context_user::instance($this->othervaeiste->id)->id);

        $director = $datagenerator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $director, $system, true);
        role_assign($director, $this->direction->id, $system);
        accesslib_clear_all_caches_for_unit_testing();
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
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * The vaeiste opens a situation.
     *
     * @param string $fichecode
     * @return \stdClass
     */
    private function open(string $fichecode = 'RNCP100'): \stdClass {
        $this->setUser($this->vaeiste);

        return (new situation_manager())->create((int) $this->vaeiste->id, $fichecode);
    }

    /**
     * The vaeiste saves the details of a situation.
     *
     * @param int $situationid
     * @param string[] $ranks
     * @param string $title
     * @param string $setting
     * @param int|null $start
     * @param int|null $end
     * @return \stdClass
     */
    private function details(
        int $situationid,
        array $ranks = [],
        string $title = 'Un titre',
        string $setting = '',
        ?int $start = null,
        ?int $end = null
    ): \stdClass {
        $this->setUser($this->vaeiste);

        return (new situation_manager())->save_details($situationid, $title, $start, $end, $setting, $ranks);
    }

    /**
     * Run a page of the plugin as the current user, with these request parameters, and give back what it printed.
     *
     * @param array $get The GET parameters.
     * @param string $script The page.
     * @param array $post The POST parameters, for a page that is sent a form.
     * @return string
     */
    private function page(array $get, string $script, array $post = []): string {
        // The page is a script of the site: it reads these as globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SITE, $USER;

        $_GET = $get;
        $_POST = $post;
        $PAGE = new \moodle_page();
        ob_start();
        try {
            include(__DIR__ . '/../' . $script);
        } finally {
            $out = ob_get_clean();
            $_GET = [];
            $_POST = [];
        }

        return $out;
    }

    public function test_a_situation_draft_cannot_be_written_outside_situation_manager(): void {
        global $DB;

        $id = (int) $this->vaeiste->id;
        $this->setUser($this->vaeiste);
        $drafts = new draft_manager();

        // The generic door of draft_manager opens no draft of a situation: situation_manager alone does, through its own door.
        $this->assert_refused(
            fn() => $drafts->section($id, draft_manager::DOCTYPE_VALIDATION, validation_catalog::SITUATION_SECTION, 1, 'RNCP100'),
            'errordraftsection'
        );
        $this->assertSame(0, $DB->count_records('tool_vae_draft', ['candidateid' => $id]), 'No draft was opened.');

        $situation = $this->open();
        $manager = new situation_manager();
        $manager->save_version((int) $situation->id, 'Première version, avec son instantané.');
        $before = $DB->count_records('tool_vae_version', ['draftid' => $situation->draftid]);

        // A version written through draft_manager would have no snapshot and no bound: refused, whoever asks.
        $this->assert_refused(fn() => $drafts->save_version((int) $situation->draftid, 'Sans instantané.'), 'errordraftsection');
        $this->assert_refused(fn() => $drafts->restore((int) $situation->draftid, 1), 'errordraftsection');
        $this->assertSame($before, $DB->count_records('tool_vae_version', ['draftid' => $situation->draftid]));
        $this->assertSame(1, $DB->count_records('tool_vae_sitversion', ['situationid' => $situation->id]));

        // The draft in progress is not a version: the autosave of the screen of lot 1b still goes through draft_manager.
        $drafts->save_working((int) $situation->draftid, 'Un brouillon en cours.');
        $this->assertSame('Un brouillon en cours.', $DB->get_field('tool_vae_draft', 'working', ['id' => $situation->draftid]));

        // The doors reserved to situation_manager open no other section: a part of the preparation is refused there.
        $this->assert_refused(
            fn() => $drafts->save_version_of_situation((int) $situation->draftid + 1000, 'x'),
            'errordraftrefused'
        );
    }

    public function test_the_pages_refuse_a_situation_and_the_history_restores_it_through_the_manager(): void {
        global $DB;

        $id = (int) $this->vaeiste->id;
        $this->setUser($this->vaeiste);
        $address = ['doctype' => 'validation', 'section' => '_situation', 'slot' => '1', 'fiche' => 'RNCP100'];

        // The page of a section and the aid to reword refuse a situation before touching anything.
        $this->assert_refused(fn() => $this->page($address, 'section.php'), 'errordraftsection');
        $this->assert_refused(
            fn() => $this->page(['source' => 'section'] + $address, 'ai.php', ['sesskey' => sesskey()]),
            'errordraftsection'
        );
        $this->assertSame(0, $DB->count_records('tool_vae_draft', ['candidateid' => $id]), 'No draft was opened.');

        $situation = $this->open();
        $manager = new situation_manager();
        $this->details((int) $situation->id, ['C01'], 'Accueil');
        $manager->save_version((int) $situation->id, 'Un.');
        $manager->save_version((int) $situation->id, 'Deux.');
        $this->assertSame((int) $situation->id, (int) $manager->find_by_draft((int) $situation->draftid)->id);
        $this->assertNull($manager->find_by_draft((int) $situation->draftid + 1000));

        // The history is read, by the author and by whoever may view the dossier; it is the history of the SITUATION, named by
        // its current title and never by the fixed name of the draft (T15, point Z2); it offers no way to section.php.
        $heading = s(get_string('historysituationheading', 'tool_vae'));
        $this->assertStringNotContainsString('[[', $heading, 'The string exists.');
        foreach ([$this->vaeiste, $this->manager] as $reader) {
            $this->setUser($reader);
            $out = $this->page(['draftid' => (string) $situation->draftid], 'history.php');
            $this->assertStringContainsString($heading, $out);
            $this->assertStringNotContainsString(s(get_string('historyheading', 'tool_vae')), $out);
            $this->assertStringContainsString(s(get_string('situationheading', 'tool_vae', 'Accueil')), $out);
            $this->assertStringNotContainsString(s(get_string('validpart:situation', 'tool_vae', 1)), $out, 'Not the fixed name.');
            $this->assertStringNotContainsString(s(get_string('faisabsectionline', 'tool_vae', 'Accueil')), $out);
            $this->assertStringNotContainsString('section.php', $out);
        }

        // Restoring from the history goes through situation_manager: the new version has its snapshot.
        $this->setUser($this->vaeiste);
        $this->assert_refused(
            fn() => $this->page(['draftid' => (string) $situation->draftid], 'history.php', [
                'action' => 'restore', 'number' => '1', 'workingat' => '0', 'sesskey' => sesskey(),
            ]),
            'redirecterrordetected'
        );
        $versions = $manager->versions((int) $situation->id);
        $this->assertSame([3, 2, 1], array_keys($versions));
        $this->assertSame('Un.', $versions[3]->content);
        $this->assertSame(1, (int) $versions[3]->restoredfrom);
        $this->assertNotNull($versions[3]->snapshot);
        $this->assertSame('C01', $versions[3]->snapshot->tags);
        $this->assertSame('Accueil', $versions[3]->snapshot->title);
        $this->assertSame(3, $DB->count_records('tool_vae_sitversion', ['situationid' => $situation->id]));

        // The name the history shows is the CURRENT title (D-1b-I), not the one the versions were saved with; a situation
        // that has no title yet is named "Work situation n" (situation_screen::title()).
        $this->details((int) $situation->id, ['C01'], 'Accueil, revu');
        $out = $this->page(['draftid' => (string) $situation->draftid], 'history.php');
        $this->assertStringContainsString(s(get_string('situationheading', 'tool_vae', 'Accueil, revu')), $out);
        $this->assertSame('Accueil', $manager->versions((int) $situation->id)[3]->snapshot->title, 'The snapshots keep theirs.');
        $untitled = $this->open();
        $manager->save_version((int) $untitled->id, 'Sans titre.');
        $out = $this->page(['draftid' => (string) $untitled->draftid], 'history.php');
        $this->assertStringContainsString(
            s(get_string('situationheading', 'tool_vae', get_string('validpart:situation', 'tool_vae', (int) $untitled->slot))),
            $out
        );
        // Any other section keeps the history of a section, named by its label.
        $drafts = new draft_manager();
        $part = $drafts->section($id, draft_manager::DOCTYPE_FAISABILITE, 'parcours');
        $drafts->save_version((int) $part->id, 'Ma partie.');
        $out = $this->page(['draftid' => (string) $part->id], 'history.php');
        $this->assertStringContainsString(s(get_string('historyheading', 'tool_vae')), $out);
        $this->assertStringNotContainsString($heading, $out);
        $this->assertStringContainsString(s(get_string('faisabsectionline', 'tool_vae', draft_manager::label($part))), $out);
    }

    public function test_a_situation_is_created_on_the_first_free_slot_and_the_thirty_first_is_refused(): void {
        global $DB;

        $sink = $this->redirectEvents();
        $first = $this->open();
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame(1, (int) $first->slot);
        $this->assertSame('', $first->title);
        $this->assertSame('', $first->setting);
        $this->assertNull($first->periodstart);
        $this->assertNull($first->periodend);
        $this->assertSame(26, strlen((string) $first->uuid));
        $draft = $DB->get_record('tool_vae_draft', ['id' => $first->draftid], '*', MUST_EXIST);
        $this->assertSame(draft_manager::DOCTYPE_VALIDATION, $draft->doctype);
        $this->assertSame(validation_catalog::SITUATION_SECTION, $draft->section);
        $this->assertSame(1, (int) $draft->slot);
        $this->assertSame('RNCP100', $draft->fichecode);
        $created = array_values(array_filter($events, fn($e) => $e instanceof \tool_vae\event\situation_created));
        $this->assertCount(1, $created);
        $this->assertSame((int) $first->id, (int) $created[0]->objectid);
        $this->assertSame((int) $this->vaeiste->id, (int) $created[0]->relateduserid);
        $this->assertSame(['fichecode' => 'RNCP100', 'slot' => 1], $created[0]->other);
        $this->assertStringNotContainsString('titre', $created[0]->get_description());

        $second = $this->open();
        $third = $this->open();
        $this->assertSame([2, 3], [(int) $second->slot, (int) $third->slot]);
        // Another record counts its own slots.
        $this->assertSame(1, (int) $this->open('RNCP400')->slot);

        // The slot of a deleted situation comes back: the first one free, not the next one.
        (new situation_manager())->delete((int) $second->id);
        $this->assertSame(2, (int) $this->open()->slot);

        for ($slot = 4; $slot <= validation_catalog::MAX_SITUATIONS; $slot++) {
            $this->assertSame($slot, (int) $this->open()->slot);
        }
        $this->assertSame(validation_catalog::MAX_SITUATIONS, $DB->count_records(situation_manager::TABLE, [
            'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100',
        ]));
        $this->assert_refused(fn() => $this->open(), 'errorsituationfull');
        $this->assertSame(validation_catalog::MAX_SITUATIONS, $DB->count_records(situation_manager::TABLE, [
            'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100',
        ]));
        $this->assertSame(validation_catalog::MAX_SITUATIONS + 1, $DB->count_records('tool_vae_draft', [
            'candidateid' => $this->vaeiste->id, 'section' => validation_catalog::SITUATION_SECTION,
        ]), 'The refused situation opened no draft.');
    }

    public function test_the_text_is_a_draft_with_versions_compared_and_restored_as_a_new_version(): void {
        global $DB;

        $situation = $this->open();
        $manager = new situation_manager();
        $drafts = new draft_manager();

        // The draft in progress is not a version.
        $drafts->save_working((int) $situation->draftid, 'Un brouillon.');
        $this->assertSame(0, $DB->count_records('tool_vae_version', ['draftid' => $situation->draftid]));
        $this->assertSame(0, $DB->count_records('tool_vae_sitversion', ['situationid' => $situation->id]));

        $one = $manager->save_version((int) $situation->id, "Le matin, j'accueille.\nLe soir, je transmets.");
        $this->assertSame(1, (int) $one->number);
        $this->assertNull($one->restoredfrom);
        $this->assertSame((int) $situation->id, (int) $one->snapshot->situationid);
        $this->assertSame(1, (int) $one->snapshot->number);
        $this->assertNull($manager->save_version((int) $situation->id, "Le matin, j'accueille.\nLe soir, je transmets."));
        $this->assertSame(1, $DB->count_records('tool_vae_sitversion', ['situationid' => $situation->id]), 'Nothing new.');

        $two = $manager->save_version((int) $situation->id, "Le matin, j'accueille.\nLe soir, je transmets à l'équipe.");
        $this->assertSame(2, (int) $two->number);
        $this->assertNotEmpty(text_diff::diff((string) $one->content, (string) $two->content));
        $this->assertNotEquals(
            [['op' => text_diff::EQUAL, 'text' => $one->content]],
            text_diff::diff($one->content, $two->content)
        );

        $three = $manager->restore((int) $situation->id, 1);
        $this->assertSame(3, (int) $three->number);
        $this->assertSame(1, (int) $three->restoredfrom);
        $this->assertSame($one->content, $three->content);
        $this->assertSame(3, (int) $three->snapshot->number);

        $versions = $manager->versions((int) $situation->id);
        $this->assertSame([3, 2, 1], array_keys($versions), 'The latest first.');
        foreach ($versions as $number => $version) {
            $this->assertSame($number, (int) $version->snapshot->number);
        }
        $this->assertSame(3, (int) $DB->get_field('tool_vae_draft', 'currentversion', ['id' => $situation->draftid]));
    }

    public function test_each_version_keeps_the_tags_title_period_and_setting_it_was_written_with(): void {
        $situation = $this->open();
        $manager = new situation_manager();
        $start = mktime(0, 0, 0, 1, 15, 2024);
        $end = mktime(0, 0, 0, 6, 30, 2024);

        $this->details((int) $situation->id, ['C02', 'C01'], 'Accueil du matin', 'EHPAD, unité protégée', $start, $end);
        $one = $manager->save_version((int) $situation->id, 'Premier état du texte.');

        $this->details((int) $situation->id, ['C03'], 'Accueil du matin, revu', 'EHPAD', null, null);
        $two = $manager->save_version((int) $situation->id, 'Second état du texte.');

        // The snapshot of version 1 keeps what it was written with; the row carries the current state (D-1b-I).
        $versions = $manager->versions((int) $situation->id);
        $this->assertSame('C01,C02', $versions[1]->snapshot->tags, 'Sorted, whatever the order given.');
        $this->assertSame('Accueil du matin', $versions[1]->snapshot->title);
        $this->assertSame('EHPAD, unité protégée', $versions[1]->snapshot->setting);
        $this->assertSame($start, (int) $versions[1]->snapshot->periodstart);
        $this->assertSame($end, (int) $versions[1]->snapshot->periodend);
        $this->assertSame('C03', $versions[2]->snapshot->tags);
        $this->assertSame('Accueil du matin, revu', $versions[2]->snapshot->title);
        $this->assertSame('EHPAD', $versions[2]->snapshot->setting);
        $this->assertNull($versions[2]->snapshot->periodstart);
        $this->assertNull($versions[2]->snapshot->periodend);
        $this->assertSame($one->snapshot->tags, $versions[1]->snapshot->tags);
        $this->assertSame($two->snapshot->tags, $versions[2]->snapshot->tags);

        $current = $manager->get((int) $situation->id);
        $this->assertSame(['C03'], $current->tags);
        $this->assertSame('Accueil du matin, revu', $current->title);

        // Restoring a version restores the text alone: the snapshot of the new version is the current state (D-1b-I).
        $three = $manager->restore((int) $situation->id, 1);
        $this->assertSame('Premier état du texte.', $three->content);
        $this->assertSame('C03', $three->snapshot->tags);
        $this->assertSame('Accueil du matin, revu', $three->snapshot->title);
        $this->assertSame(['C03'], $manager->get((int) $situation->id)->tags, 'The tags did not move.');
    }

    public function test_a_version_without_its_snapshot_is_never_written(): void {
        global $DB;

        $situation = $this->open();
        $manager = new situation_manager();
        // The snapshot of version 1 is made to fail: a row already holds that number (unique index situationnumber).
        $DB->insert_record('tool_vae_sitversion', (object) [
            'situationid' => (int) $situation->id, 'number' => 1, 'title' => '', 'periodstart' => null, 'periodend' => null,
            'setting' => '', 'tags' => '', 'timecreated' => time(),
        ]);
        $before = $DB->count_records('tool_vae_version');

        try {
            $manager->save_version((int) $situation->id, 'Un texte qui ne doit pas rester.');
            $this->fail('The failed snapshot was expected to raise.');
        } catch (\dml_exception $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertSame($before, $DB->count_records('tool_vae_version'), 'The version went away with its snapshot.');
        $this->assertSame(0, (int) $DB->get_field('tool_vae_draft', 'currentversion', ['id' => $situation->draftid]));
        $this->assertSame(1, $DB->count_records('tool_vae_sitversion', ['situationid' => $situation->id]), 'The planted row.');
    }

    public function test_the_two_hundred_and_first_version_is_refused(): void {
        global $DB;

        $situation = $this->open();
        $manager = new situation_manager();
        $versions = [];
        $snapshots = [];
        $now = time();
        for ($number = 1; $number <= situation_manager::MAX_VERSIONS; $number++) {
            $versions[] = (object) [
                'uuid' => \tool_vae\local\identity_map::generate_ulid(), 'draftid' => (int) $situation->draftid,
                'number' => $number, 'content' => 'Texte ' . $number, 'contenthash' => hash('sha256', 'Texte ' . $number),
                'authorid' => (int) $this->vaeiste->id, 'note' => null, 'restoredfrom' => null, 'timecreated' => $now,
            ];
            $snapshots[] = (object) [
                'situationid' => (int) $situation->id, 'number' => $number, 'title' => '', 'periodstart' => null,
                'periodend' => null, 'setting' => '', 'tags' => '', 'timecreated' => $now,
            ];
        }
        $DB->insert_records('tool_vae_version', $versions);
        $DB->insert_records('tool_vae_sitversion', $snapshots);
        $DB->set_field('tool_vae_draft', 'currentversion', situation_manager::MAX_VERSIONS, ['id' => $situation->draftid]);

        $this->assert_refused(fn() => $manager->save_version((int) $situation->id, 'Texte de trop.'), 'errorsituationversions');
        $this->assert_refused(fn() => $manager->restore((int) $situation->id, 1), 'errorsituationversions');
        $this->assertSame(
            situation_manager::MAX_VERSIONS,
            $DB->count_records('tool_vae_version', ['draftid' => $situation->draftid])
        );
        $this->assertSame(situation_manager::MAX_VERSIONS, (int) $DB->get_field('tool_vae_draft', 'currentversion', [
            'id' => $situation->draftid,
        ]));
        // The draft in progress is still written: the bound is on the versions.
        (new draft_manager())->save_working((int) $situation->draftid, 'Encore un brouillon.');
        $this->assertCount(situation_manager::MAX_VERSIONS, $manager->versions((int) $situation->id));
    }

    public function test_a_tag_on_level_two_three_or_four_is_accepted_and_level_one_unknown_or_malformed_is_refused(): void {
        global $DB;

        $situation = $this->open('RNCP400');
        $manager = new situation_manager();

        $row = $this->details((int) $situation->id, ['H111', 'D1', 'C11']);
        $this->assertSame(['C11', 'D1', 'H111'], $row->tags);
        $this->assertSame(['C11', 'D1', 'H111'], $manager->get((int) $situation->id)->tags);
        $this->assertSame(3, $DB->count_records('tool_vae_sitetag', ['situationid' => $situation->id]));
        $tag = $DB->get_record('tool_vae_sitetag', ['situationid' => $situation->id, 'ficherang' => 'D1'], '*', MUST_EXIST);
        $this->assertSame((int) $this->vaeiste->id, (int) $tag->candidateid);
        $this->assertSame('RNCP400', $tag->fichecode);

        // The record itself (level 1, the empty rank), an unknown rank, a rank in the wrong case, a rank of another record.
        foreach (['', 'ZZ9', 'c11', 'D1-X', 'C01'] as $bad) {
            $this->assert_refused(fn() => $this->details((int) $situation->id, ['C11', $bad]), 'errorsituationtag');
            $this->assertSame(['C11', 'D1', 'H111'], $manager->get((int) $situation->id)->tags, 'Nothing moved for "' . $bad . '"');
        }
        // A rank is a string: anything else is refused too.
        $this->assert_refused(fn() => $this->details((int) $situation->id, [12]), 'errorsituationtag');

        // The same rank twice is one tag.
        $this->assertSame(['H112'], $this->details((int) $situation->id, ['H112', 'H112'])->tags);
        $this->assertSame(1, $DB->count_records('tool_vae_sitetag', ['situationid' => $situation->id]));
    }

    public function test_zero_tags_are_allowed(): void {
        global $DB;

        $situation = $this->open('RNCP400');
        $manager = new situation_manager();

        $this->assertSame([], $this->details((int) $situation->id, [])->tags);
        $version = $manager->save_version((int) $situation->id, 'Un texte sans étiquette.');
        $this->assertSame('', $version->snapshot->tags);

        $this->details((int) $situation->id, ['C11']);
        $this->assertSame(['C11'], $manager->get((int) $situation->id)->tags);
        $this->assertSame([], $this->details((int) $situation->id, [])->tags, 'Taken back.');
        $this->assertSame(0, $DB->count_records('tool_vae_sitetag', ['situationid' => $situation->id]));
        $this->assertSame([], $manager->counts((int) $this->vaeiste->id, 'RNCP400'));
    }

    public function test_the_title_is_required_and_cut_to_sixty_and_the_period_must_be_ordered(): void {
        $situation = $this->open();
        $manager = new situation_manager();
        $sixty = str_repeat('a', situation_manager::TITLE_MAXLENGTH);

        foreach (['', '   ', "\n\t", $sixty . 'b'] as $bad) {
            $this->assert_refused(fn() => $this->details((int) $situation->id, [], $bad), 'errorsituationtitle');
        }
        $this->assertSame('', $manager->get((int) $situation->id)->title, 'Nothing was written.');

        $this->assertSame($sixty, $this->details((int) $situation->id, [], $sixty)->title);
        // Spaces are folded and HTML is not kept: a title is plain text, as the title of a piece is.
        $this->assertSame('Accueil du matin', $this->details((int) $situation->id, [], "  Accueil  du\nmatin ")->title);
        $this->assertSame('Accueil du matin', $this->details((int) $situation->id, [], '<b>Accueil</b> du matin')->title);

        $day = mktime(0, 0, 0, 3, 1, 2025);
        $this->assert_refused(
            fn() => $this->details((int) $situation->id, [], 'T', '', $day, $day - DAYSECS),
            'errorsituationperiod'
        );
        $row = $this->details((int) $situation->id, [], 'T', '', $day, $day);
        $this->assertSame([$day, $day], [(int) $row->periodstart, (int) $row->periodend], 'One day is a period.');
        $row = $this->details((int) $situation->id, [], 'T', '', $day, null);
        $this->assertSame($day, (int) $row->periodstart);
        $this->assertNull($row->periodend, 'A period may be open.');
        $row = $this->details((int) $situation->id, [], 'T', '', null, $day);
        $this->assertNull($row->periodstart);
        $this->assertSame($day, (int) $row->periodend);

        $hundred = str_repeat('s', situation_manager::SETTING_MAXLENGTH);
        $this->assert_refused(fn() => $this->details((int) $situation->id, [], 'T', $hundred . 't'), 'errorsituationsetting');
        $this->assertSame($hundred, $this->details((int) $situation->id, [], 'T', $hundred)->setting);
        $this->assertSame('', $this->details((int) $situation->id, [], 'T', '  ')->setting, 'The setting may be empty.');
    }

    public function test_a_situation_needs_no_declaration_but_needs_an_offered_record(): void {
        global $DB;

        $this->assertSame(0, $DB->count_records('tool_vae_evidence', ['candidateid' => $this->vaeiste->id]), 'No declaration.');
        $situation = $this->open('RNCP400');
        $this->assertSame(1, (int) $situation->slot);

        // A record that is not imported here.
        $this->assert_refused(fn() => $this->open('RNCP999'), 'errordraftsection');

        // A record hidden in Moodle is not offered: nothing is written on it.
        $DB->set_field('competency_framework', 'visible', 0, ['idnumber' => 'RNCP100']);
        $this->assert_refused(fn() => $this->open('RNCP100'), 'errorframeworkhidden');
        $this->assertSame(0, $DB->count_records(situation_manager::TABLE, ['fichecode' => 'RNCP100']));
        $this->assertSame(0, $DB->count_records('tool_vae_draft', ['fichecode' => 'RNCP100']), 'No draft either.');

        // Visible again, it is offered again; what is written stays readable while hidden.
        $DB->set_field('competency_framework', 'visible', 1, ['idnumber' => 'RNCP100']);
        $this->assertSame(1, (int) $this->open('RNCP100')->slot);
        $DB->set_field('competency_framework', 'visible', 0, ['idnumber' => 'RNCP100']);
        $this->assertCount(1, (new situation_manager())->list((int) $this->vaeiste->id, 'RNCP100'));
    }

    public function test_only_the_vaeiste_writes_his_situations_even_the_administrator_does_not(): void {
        global $DB;

        $situation = $this->open();
        $manager = new situation_manager();
        $id = (int) $this->vaeiste->id;
        $this->details((int) $situation->id, ['C01'], 'Mon titre');
        $manager->save_version((int) $situation->id, 'Mon texte.');

        foreach ([$this->manager, $this->direction, get_admin(), $this->othervaeiste, $this->outsider] as $user) {
            $this->setUser($user);
            $who = fullname($user);
            $this->assertFalse(situation_manager::can_write($id), $who);
            $this->assert_refused(fn() => $manager->create($id, 'RNCP100'), 'errordraftrefused');
            $this->assert_refused(
                fn() => $manager->save_details((int) $situation->id, 'Autre', null, null, '', []),
                'errordraftrefused'
            );
            $this->assert_refused(fn() => $manager->save_version((int) $situation->id, 'Autre texte.'), 'errordraftrefused');
            $this->assert_refused(fn() => $manager->restore((int) $situation->id, 1), 'errordraftrefused');
            $this->assert_refused(fn() => $manager->delete((int) $situation->id), 'errordraftrefused');
        }
        $this->setUser(null);
        $this->assertFalse(situation_manager::can_write($id));
        $this->setGuestUser();
        $this->assertFalse(situation_manager::can_write($id));

        $this->setUser($this->vaeiste);
        $this->assertTrue(situation_manager::can_write($id));
        $this->assertFalse(situation_manager::can_write((int) $this->othervaeiste->id), 'Not on somebody else\'s dossier.');

        // Nothing moved.
        $this->assertSame(1, $DB->count_records(situation_manager::TABLE));
        $this->assertSame(1, $DB->count_records('tool_vae_version'));
        $this->assertSame('Mon titre', $manager->get((int) $situation->id)->title);
        $this->assertSame(['C01'], $manager->get((int) $situation->id)->tags);
    }

    public function test_whoever_may_view_the_dossier_lists_and_reads_the_situations(): void {
        $situation = $this->open();
        $manager = new situation_manager();
        $id = (int) $this->vaeiste->id;
        $this->details((int) $situation->id, ['C01', 'C03'], 'Accueil');
        $manager->save_version((int) $situation->id, 'Mon texte.');
        (new draft_manager())->save_working((int) $situation->draftid, 'Mon texte, retouché.');
        $this->open('RNCP400');

        foreach (['vaeiste' => $this->vaeiste, 'manager' => $this->manager, 'direction' => $this->direction] as $who => $user) {
            $this->setUser($user);
            $list = $manager->list($id, 'RNCP100');
            $this->assertSame([1], array_keys($list), $who);
            $this->assertSame('Accueil', $list[1]->title, $who);
            $this->assertSame(['C01', 'C03'], $list[1]->tags, $who);
            $this->assertSame(0, $list[1]->pieces, $who);
            $this->assertSame(validation_status::STATE_VERSION_DRAFT, $list[1]->status['state'], $who);
            $this->assertSame(1, $list[1]->status['version'], $who);
            $this->assertSame('Accueil', $manager->get((int) $situation->id)->title, $who);
            $this->assertSame((int) $situation->id, (int) $manager->find($id, 'RNCP100', 1)->id, $who);
            $this->assertNull($manager->find($id, 'RNCP100', 2), $who);
            $this->assertSame([1], array_keys($manager->versions((int) $situation->id)), $who);
            $this->assertSame(['C01' => 1, 'C03' => 1], $manager->counts($id, 'RNCP100'), $who);
            $this->assertSame([1], array_keys($manager->tagged($id, 'RNCP100', ['C03'])), $who);
        }

        // Whoever may not view the dossier reads nothing; the site administrator is set aside by hand (MC-16, D-1b-G).
        $refused = ['outsider' => $this->outsider, 'other vaeiste' => $this->othervaeiste, 'administrator' => get_admin()];
        foreach ($refused as $who => $user) {
            $this->setUser($user);
            $this->assert_refused(fn() => $manager->list($id, 'RNCP100'), 'errordraftrefused');
            $this->assert_refused(fn() => $manager->get((int) $situation->id), 'errordraftrefused');
            $this->assert_refused(fn() => $manager->find($id, 'RNCP100', 1), 'errordraftrefused');
            $this->assert_refused(fn() => $manager->find($id, 'RNCP100', 2), 'errordraftrefused');
            $this->assert_refused(fn() => $manager->versions((int) $situation->id), 'errordraftrefused');
            $this->assert_refused(fn() => $manager->counts($id, 'RNCP100'), 'errordraftrefused');
            $this->assert_refused(fn() => $manager->tagged($id, 'RNCP100', ['C03']), 'errordraftrefused');
        }
        // A situation that does not exist is refused as one that may not be read: nothing says which ids exist.
        $this->setUser($this->vaeiste);
        $this->assert_refused(fn() => $manager->get(999999), 'errordraftrefused');
    }

    public function test_deleting_a_situation_frees_its_slot_and_takes_its_tags_versions_and_draft(): void {
        global $DB;

        $first = $this->open();
        $second = $this->open();
        $manager = new situation_manager();
        $this->details((int) $first->id, ['C01', 'C02'], 'Première');
        $manager->save_version((int) $first->id, 'Texte un.');
        $manager->save_version((int) $first->id, 'Texte un, revu.');
        $this->details((int) $second->id, ['C03'], 'Seconde');
        $manager->save_version((int) $second->id, 'Texte deux.');

        $sink = $this->redirectEvents();
        $manager->delete((int) $first->id);
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof \tool_vae\event\situation_deleted));
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertSame((int) $first->id, (int) $events[0]->objectid);
        $this->assertSame((int) $this->vaeiste->id, (int) $events[0]->relateduserid);
        $this->assertSame(['fichecode' => 'RNCP100', 'slot' => 1], $events[0]->other);

        $this->assertFalse($DB->record_exists(situation_manager::TABLE, ['id' => $first->id]));
        $this->assertSame(0, $DB->count_records('tool_vae_sitetag', ['situationid' => $first->id]));
        $this->assertSame(0, $DB->count_records('tool_vae_sitversion', ['situationid' => $first->id]));
        $this->assertSame(0, $DB->count_records('tool_vae_version', ['draftid' => $first->draftid]));
        $this->assertFalse($DB->record_exists('tool_vae_draft', ['id' => $first->draftid]));
        $this->assertNull($manager->find((int) $this->vaeiste->id, 'RNCP100', 1));

        // The second is untouched.
        $this->assertTrue($DB->record_exists(situation_manager::TABLE, ['id' => $second->id]));
        $this->assertSame(['C03'], $manager->get((int) $second->id)->tags);
        $this->assertSame(1, $DB->count_records('tool_vae_sitversion', ['situationid' => $second->id]));
        $this->assertSame(1, $DB->count_records('tool_vae_version', ['draftid' => $second->draftid]));
        $this->assertSame(['C03' => 1], $manager->counts((int) $this->vaeiste->id, 'RNCP100'));

        // The slot is free again, and a deleted situation is refused as one that does not exist.
        $this->assertSame(1, (int) $this->open()->slot);
        $this->assert_refused(fn() => $manager->delete((int) $first->id), 'errordraftrefused');
    }

    public function test_closed_validation_refuses_a_new_situation_and_a_new_version_but_leaves_reading(): void {
        $situation = $this->open();
        $manager = new situation_manager();
        $id = (int) $this->vaeiste->id;
        $this->details((int) $situation->id, ['C01'], 'Accueil');
        $manager->save_version((int) $situation->id, 'Mon texte.');

        set_config('validationopen', 0, 'tool_vae');
        $this->setUser($this->vaeiste);
        $this->assertTrue(situation_manager::can_write($id), 'The right stands; the door is closed.');
        $this->assert_refused(fn() => $manager->create($id, 'RNCP100'), 'errorvalidationclosed');
        $this->assert_refused(fn() => $manager->save_version((int) $situation->id, 'Autre.'), 'errorvalidationclosed');
        $this->assert_refused(fn() => $manager->restore((int) $situation->id, 1), 'errorvalidationclosed');
        $this->assert_refused(
            fn() => $manager->save_details((int) $situation->id, 'Autre', null, null, '', []),
            'errorvalidationclosed'
        );
        $this->assert_refused(fn() => $manager->delete((int) $situation->id), 'errorvalidationclosed');

        foreach (['vaeiste' => $this->vaeiste, 'manager' => $this->manager] as $who => $user) {
            $this->setUser($user);
            $this->assertSame('Accueil', $manager->list($id, 'RNCP100')[1]->title, $who);
            $this->assertSame(['C01'], $manager->get((int) $situation->id)->tags, $who);
            $this->assertSame('Mon texte.', $manager->versions((int) $situation->id)[1]->content, $who);
            $this->assertSame(['C01' => 1], $manager->counts($id, 'RNCP100'), $who);
        }

        set_config('validationopen', 1, 'tool_vae');
        $this->assertSame(2, (int) $this->open()->slot, 'Open again, writing resumes.');
    }

    /**
     * T15, point F: a record hidden in Moodle since the situation was opened is no longer offered (D-1b-F): every write
     * through the API is refused as the page refuses it, and the reading stays.
     */
    public function test_a_record_hidden_since_refuses_every_write_through_the_api_and_leaves_reading(): void {
        global $DB;
        $situation = $this->open();
        $manager = new situation_manager();
        $id = (int) $this->vaeiste->id;
        $this->details((int) $situation->id, ['C01'], 'Accueil');
        $manager->save_version((int) $situation->id, 'Mon texte.');
        $rows = fn() => [
            $DB->count_records('tool_vae_situation'), $DB->count_records('tool_vae_version'),
            $DB->count_records('tool_vae_sitetag'), $DB->get_field('tool_vae_situation', 'title', ['id' => $situation->id]),
        ];
        $before = $rows();

        $DB->set_field('competency_framework', 'visible', 0, ['idnumber' => 'RNCP100']);
        $this->setUser($this->vaeiste);
        $this->assert_refused(fn() => $manager->save_version((int) $situation->id, 'Autre.'), 'errorframeworkhidden');
        $this->assert_refused(fn() => $manager->restore((int) $situation->id, 1), 'errorframeworkhidden');
        $this->assert_refused(
            fn() => $manager->save_details((int) $situation->id, 'Autre', null, null, '', []),
            'errorframeworkhidden'
        );
        $this->assert_refused(fn() => $manager->delete((int) $situation->id), 'errorframeworkhidden');
        // The draft in progress goes by the same door (autosave.php, tour 1): refused too, the draft untouched.
        $drafts = new draft_manager();
        $this->assert_refused(fn() => $drafts->autosave((int) $situation->draftid, 'Tape en cache.', 0), 'errorframeworkhidden');
        $this->assert_refused(fn() => $drafts->save_working((int) $situation->draftid, 'Tape en cache.'), 'errorframeworkhidden');
        $this->assertNull($DB->get_field('tool_vae_draft', 'working', ['id' => $situation->draftid]), 'No draft in progress.');
        $this->assertSame($before, $rows(), 'Nothing written.');

        foreach (['vaeiste' => $this->vaeiste, 'manager' => $this->manager] as $who => $user) {
            $this->setUser($user);
            $this->assertSame('Accueil', $manager->list($id, 'RNCP100')[1]->title, $who);
            $this->assertSame(['C01'], $manager->get((int) $situation->id)->tags, $who);
            $this->assertSame('Mon texte.', $manager->versions((int) $situation->id)[1]->content, $who);
        }

        $DB->set_field('competency_framework', 'visible', 1, ['idnumber' => 'RNCP100']);
        $this->setUser($this->vaeiste);
        $this->assertNotNull($manager->save_version((int) $situation->id, 'Autre.'), 'Offered again, writing resumes.');
    }

    public function test_the_counts_per_rank_are_counts_never_sums(): void {
        $id = (int) $this->vaeiste->id;
        $manager = new situation_manager();
        // Through the generator, as a scenario would write them.
        $one = $this->generator->create_situation([
            'userid' => $id, 'fichecode' => 'RNCP400', 'title' => 'Première', 'tags' => 'C11', 'text' => 'Un texte.',
            'setting' => 'Service de nuit',
            'periodstart' => mktime(0, 0, 0, 1, 1, 2024), 'periodend' => mktime(0, 0, 0, 2, 1, 2024),
        ]);
        $two = $this->generator->create_situation([
            'userid' => $id, 'fichecode' => 'RNCP400', 'title' => 'Deuxième', 'tags' => 'C11',
        ]);
        $three = $this->generator->create_situation([
            'userid' => $id, 'fichecode' => 'RNCP400', 'title' => 'Troisième', 'tags' => 'D1',
        ]);
        $this->assertSame([1, 2, 3], [(int) $one->slot, (int) $two->slot, (int) $three->slot]);
        $this->assertSame(['C11'], $one->tags);
        $this->assertSame('Service de nuit', $one->setting);
        // The generator writes as the vaeiste and gives the session back: nobody is logged in here, and nobody reads.
        $this->assert_refused(fn() => $manager->list($id, 'RNCP400'), 'errordraftrefused');
        $this->setUser($this->vaeiste);
        $this->assertSame(1, $manager->list($id, 'RNCP400')[1]->status['version']);
        $this->assertSame(0, $manager->list($id, 'RNCP400')[2]->status['version'], 'No text given, no version.');

        $this->setUser($this->manager);
        $counts = $manager->counts($id, 'RNCP400');
        // C11 holds two situations, D1 one: D1 is NOT 3. Nothing goes up to the parent, nothing is added.
        $this->assertSame(['C11' => 2, 'D1' => 1], $counts);
        $this->assertArrayNotHasKey('', $counts);
        $this->assertArrayNotHasKey('H111', $counts);

        $this->assertSame([1, 2], array_keys($manager->tagged($id, 'RNCP400', ['C11'])));
        $this->assertSame([3], array_keys($manager->tagged($id, 'RNCP400', ['D1', 'H111'])));
        $this->assertSame([1, 2, 3], array_keys($manager->tagged($id, 'RNCP400', ['C11', 'D1'])));
        $this->assertSame('Troisième', $manager->tagged($id, 'RNCP400', ['D1'])[3]->title);
        $this->assertSame([], $manager->tagged($id, 'RNCP400', []));
        $this->assertSame([], $manager->tagged($id, 'RNCP400', ['C01']), 'A rank of another record counts nothing here.');
        $this->assertSame([], $manager->counts($id, 'RNCP100'));
    }
}
