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

use tool_vae\local\consent_manager;
use tool_vae\local\identity_map;

/**
 * The consent of the vaeiste to the annotation of a manager who follows them (lot 1b, D-1b-B, D-1b-J, D-1b-L).
 *
 * THE VAEISTE ALONE GIVES IT, BY NAME, AND TAKES IT BACK AT ANY TIME. Lines added and never changed, the latest of a couple
 * written on this site stands; a line restored from an archive never opens the annotation. Whoever may read the dossier reads
 * the consent. The consent lets a manager annotate, never change the text of the vaeiste.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(consent_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\consent_changed::class)]
final class consent_manager_test extends \advanced_testcase {
    /** @var \stdClass The vaeiste: viewown on their own context. */
    private $vaeiste;

    /** @var \stdClass Another vaeiste, with the same rights on their own context. */
    private $othervaeiste;

    /** @var \stdClass Follows the vaeiste and holds annotate. */
    private $manager;

    /** @var \stdClass Follows the vaeiste, without annotate. */
    private $follower;

    /** @var \stdClass Holds annotate, follows the other vaeiste only. */
    private $outsider;

    /** @var \stdClass Holds viewany and annotate at the site, follows nobody. */
    private $direction;

    /** @var \tool_vae_generator */
    private $generator;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $datagenerator = $this->getDataGenerator();
        $this->generator = $datagenerator->get_plugin_generator('tool_vae');
        $system = \context_system::instance()->id;

        $this->vaeiste = $datagenerator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->othervaeiste = $datagenerator->create_user(['firstname' => 'Sacha', 'lastname' => 'Autre']);
        $this->manager = $datagenerator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->follower = $datagenerator->create_user(['firstname' => 'Paul', 'lastname' => 'Suivant']);
        $this->outsider = $datagenerator->create_user(['firstname' => 'Lea', 'lastname' => 'Ailleurs']);
        $this->direction = $datagenerator->create_user(['firstname' => 'Dana', 'lastname' => 'Direction']);

        $own = $datagenerator->create_role();
        assign_capability('tool/vae:viewown', CAP_ALLOW, $own, $system, true);
        // The vaeiste writes their preparation (draftown): they read the page of their situations.
        assign_capability('tool/vae:draftown', CAP_ALLOW, $own, $system, true);
        set_role_contextlevels($own, [CONTEXT_USER]);
        foreach ([$this->vaeiste, $this->othervaeiste] as $person) {
            role_assign($own, $person->id, \context_user::instance($person->id)->id);
        }

        $annotator = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $annotator, $system, true);
        assign_capability('tool/vae:annotate', CAP_ALLOW, $annotator, $system, true);
        set_role_contextlevels($annotator, [CONTEXT_USER]);
        role_assign($annotator, $this->manager->id, \context_user::instance($this->vaeiste->id)->id);
        role_assign($annotator, $this->outsider->id, \context_user::instance($this->othervaeiste->id)->id);

        $follow = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->follower->id, \context_user::instance($this->vaeiste->id)->id);

        $director = $datagenerator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $director, $system, true);
        assign_capability('tool/vae:annotate', CAP_ALLOW, $director, $system, true);
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
     * The number of lines of consent on the site.
     *
     * @return int
     */
    private function rows(): int {
        global $DB;

        return $DB->count_records(consent_manager::TABLE);
    }

    /**
     * A page of the plugin, played as the site plays it, for the current user.
     *
     * @param array $get The parameters of the address.
     * @param array $post The fields sent, if the page is sent to.
     * @param string $script The page; consent.php by default.
     * @return string
     */
    private function page(array $get, array $post = [], string $script = 'consent.php'): string {
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

    public function test_the_vaeiste_gives_and_withdraws_consent_to_a_follower_who_may_annotate_and_the_lines_stay(): void {
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $this->setUser($this->vaeiste);
        $consent = new consent_manager();

        $this->assertSame([$managerid], $consent->eligible($id), 'A follower without annotate, the direction: not eligible.');
        $this->assertFalse(consent_manager::stands($id, $managerid));

        $given = $consent->give($id, $managerid);
        $this->assertSame(consent_manager::GIVEN, $given->state);
        $this->assertSame($id, (int) $given->candidateid);
        $this->assertSame($managerid, (int) $given->managerid);
        $this->assertNull($given->manageruuid);
        $this->assertNull($given->importedat);
        $this->assertSame(26, strlen($given->uuid));
        $this->assertTrue(consent_manager::stands($id, $managerid));

        $withdrawn = $consent->withdraw($id, $managerid);
        $this->assertSame(consent_manager::WITHDRAWN, $withdrawn->state);
        $this->assertFalse(consent_manager::stands($id, $managerid), 'Taken back at any time.');

        $again = $consent->give($id, $managerid);
        $this->assertTrue(consent_manager::stands($id, $managerid));
        $this->assertSame(3, $this->rows(), 'Lines added, never changed.');

        $current = $consent->current($id);
        $this->assertSame([$managerid], array_keys($current));
        $this->assertSame((int) $again->id, (int) $current[$managerid]->id, 'The latest line stands.');
        $history = $consent->history($id);
        $this->assertSame(
            [(int) $given->id, (int) $withdrawn->id, (int) $again->id],
            array_map('intval', array_column($history, 'id'))
        );
        $this->assertSame([false, false, false], array_column($history, 'restored'));
        $this->assertFalse(consent_manager::stands((int) $this->othervaeiste->id, $managerid), 'By couple.');
    }

    public function test_consent_to_someone_who_does_not_follow_or_cannot_annotate_is_refused(): void {
        $id = (int) $this->vaeiste->id;
        $this->setUser($this->vaeiste);
        $consent = new consent_manager();

        $refused = [$this->follower, $this->outsider, $this->direction, get_admin(), $this->vaeiste, $this->othervaeiste];
        foreach ($refused as $person) {
            $this->assert_refused(fn() => $consent->give($id, (int) $person->id), 'errorconsentdenied');
            $this->assertFalse(consent_manager::stands($id, (int) $person->id), fullname($person));
        }
        $this->assert_refused(fn() => $consent->give($id, 999999), 'errorconsentdenied');
        $this->assertSame(0, $this->rows());
    }

    public function test_a_consent_that_stands_is_withdrawn_even_once_the_manager_no_longer_follows(): void {
        global $DB;
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $this->setUser($this->vaeiste);
        $consent = new consent_manager();
        $consent->give($id, $managerid);

        $DB->delete_records('role_assignments', ['userid' => $managerid]);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame([], $consent->eligible($id));

        $consent->withdraw($id, $managerid);
        $this->assertFalse(consent_manager::stands($id, $managerid));
        $this->assert_refused(fn() => $consent->give($id, $managerid), 'errorconsentdenied');
    }

    public function test_the_same_state_twice_is_refused(): void {
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $this->setUser($this->vaeiste);
        $consent = new consent_manager();

        $this->assert_refused(fn() => $consent->withdraw($id, $managerid), 'errorconsentsame');
        $consent->give($id, $managerid);
        $this->assert_refused(fn() => $consent->give($id, $managerid), 'errorconsentsame');
        $consent->withdraw($id, $managerid);
        $this->assert_refused(fn() => $consent->withdraw($id, $managerid), 'errorconsentsame');
        $this->assertSame(2, $this->rows());
    }

    /**
     * The bound counts what opens, never what closes (T15, ruling of the controller): a withdrawal is never refused by it,
     * and the four hundred and first consent GIVEN is, whatever the origin of the lines given (restored ones count).
     */
    public function test_a_withdrawal_is_never_refused_by_the_bound_and_the_four_hundred_and_first_consent_given_is(): void {
        global $DB;
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $this->setUser($this->vaeiste);
        $consent = new consent_manager();
        $consent->give($id, $managerid);
        $row = $DB->get_record(consent_manager::TABLE, [], '*', MUST_EXIST);
        for ($i = 2; $i <= consent_manager::MAX_LINES; $i++) {
            unset($row->id);
            $row->uuid = identity_map::generate_ulid();
            // The 400th line, the latest, is given.
            $row->state = $i % 2 ? consent_manager::WITHDRAWN : consent_manager::GIVEN;
            $DB->insert_record(consent_manager::TABLE, $row);
        }
        $this->assertSame(400, $this->rows());
        $this->assertTrue(consent_manager::stands($id, $managerid));

        // Four hundred lines, and the consent is taken back all the same: "at any time" (consentintro).
        $consent->withdraw($id, $managerid);
        $this->assertSame(401, $this->rows());
        $this->assertFalse(consent_manager::stands($id, $managerid));

        // Four hundred lines GIVEN - 201 of this couple, the rest raw lines to other managers, half of them restored: a new
        // consent given is refused, and nothing is written.
        $given = fn() => $DB->count_records(consent_manager::TABLE, ['candidateid' => $id, 'state' => consent_manager::GIVEN]);
        $this->assertSame(201, $given());
        $others = [(int) $this->follower->id, (int) $this->outsider->id];
        for ($i = 0; $given() < consent_manager::MAX_LINES; $i++) {
            $restored = $i % 2 === 1;
            $DB->insert_record(consent_manager::TABLE, (object) [
                'uuid' => identity_map::generate_ulid(), 'candidateid' => $id,
                'managerid' => $restored ? null : $others[$i % 4 === 0 ? 0 : 1],
                'manageruuid' => $restored ? identity_map::generate_ulid() : null,
                'state' => consent_manager::GIVEN, 'importedat' => $restored ? time() : null, 'timecreated' => time() - 1000 + $i,
            ]);
        }
        $this->assertSame(400, $given());
        $rows = $this->rows();
        $this->assert_refused(fn() => $consent->give($id, $managerid), 'errorconsentfull');
        $this->assertSame($rows, $this->rows());
        $this->assertFalse(consent_manager::stands($id, $managerid));

        // While a withdrawal still passes: the outsider's latest local line is given, the vaeiste takes it back.
        $this->assertTrue(consent_manager::stands($id, (int) $this->outsider->id));
        $consent->withdraw($id, (int) $this->outsider->id);
        $this->assertFalse(consent_manager::stands($id, (int) $this->outsider->id));
        $this->assertSame($rows + 1, $this->rows());
    }

    public function test_a_restored_consent_line_never_opens_the_annotation(): void {
        global $DB;
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $restored = [
            'candidateid' => $id, 'managerid' => null, 'manageruuid' => identity_map::generate_ulid(),
            'state' => consent_manager::GIVEN, 'importedat' => time(), 'timecreated' => time() - 100,
        ];
        $DB->insert_record(consent_manager::TABLE, (object) ($restored + ['uuid' => identity_map::generate_ulid()]));
        // Even a restored line that would name the manager (it never does) does not count: only the lines written here do.
        $DB->insert_record(consent_manager::TABLE, (object) (['managerid' => $managerid] + $restored
            + ['uuid' => identity_map::generate_ulid()]));

        $this->assertFalse(consent_manager::stands($id, $managerid));
        $this->setUser($this->vaeiste);
        $consent = new consent_manager();
        $this->assertSame([], $consent->current($id), 'A restored line names no account of the site.');
        $this->assertSame([true, true], array_column($consent->history($id), 'restored'), 'It stays in the history.');

        // The vaeiste gives it on this site: it stands; the restored lines do not count as a state to repeat.
        $consent->give($id, $managerid);
        $this->assertTrue(consent_manager::stands($id, $managerid));
        $this->assertSame(3, $this->rows());
    }

    public function test_only_the_vaeiste_manages_his_consent(): void {
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $consent = new consent_manager();

        foreach ([$this->manager, $this->direction, get_admin(), $this->othervaeiste, $this->follower] as $user) {
            $this->setUser($user);
            $this->assertFalse(consent_manager::can_manage($id), fullname($user));
            $this->assert_refused(fn() => $consent->give($id, $managerid), 'errorconsentdenied');
            $this->assert_refused(fn() => $consent->withdraw($id, $managerid), 'errorconsentdenied');
        }
        $this->setUser(null);
        $this->assertFalse(consent_manager::can_manage($id));
        $this->setGuestUser();
        $this->assertFalse(consent_manager::can_manage($id));
        $this->setUser($this->vaeiste);
        $this->assertTrue(consent_manager::can_manage($id));
        $this->assertFalse(consent_manager::can_manage((int) $this->othervaeiste->id), 'Not somebody else\'s consent.');
        $this->assertSame(0, $this->rows());

        // Without viewown on their own context, nobody manages a consent, even their own.
        $this->setUser($this->manager);
        $this->assertFalse(consent_manager::can_manage($managerid));
    }

    public function test_whoever_may_view_reads_the_consent_and_another_vaeiste_does_not(): void {
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $this->generator->create_consent(['userid' => $id, 'managerid' => $managerid]);
        $consent = new consent_manager();

        // The administrator passes can_view (viewany), the gate of every reading of the dossier: read, never written.
        foreach ([$this->vaeiste, $this->manager, $this->follower, $this->direction, get_admin()] as $user) {
            $this->setUser($user);
            $this->assertSame([$managerid], array_keys($consent->current($id)), fullname($user));
            $this->assertCount(1, $consent->history($id));
        }
        foreach ([$this->othervaeiste, $this->outsider] as $user) {
            $this->setUser($user);
            foreach (['current', 'history'] as $method) {
                try {
                    $consent->{$method}($id);
                    $this->fail($method . ' refused for ' . fullname($user));
                } catch (\required_capability_exception $e) {
                    $this->assertSame('nopermissions', $e->errorcode);
                }
            }
        }
    }

    public function test_the_event_carries_identifiers_only(): void {
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $this->setUser($this->vaeiste);
        $sink = $this->redirectEvents();

        $row = (new consent_manager())->give($id, $managerid);

        $this->assertSame(1, $sink->count());
        $event = $sink->get_events()[0];
        $this->assertInstanceOf(\tool_vae\event\consent_changed::class, $event);
        $data = $event->get_data();
        // The convention of the log (T15, point D): the vaeiste acts, in their own context; the manager is the person the
        // event is about, in relateduserid, where the privacy provider of the log finds them when they are erased. No
        // account id goes in other.
        $this->assertSame($id, (int) $data['userid']);
        $this->assertSame($managerid, (int) $data['relateduserid']);
        $this->assertSame((int) $row->id, (int) $data['objectid']);
        $this->assertSame(\context_user::instance($id)->id, (int) $data['contextid']);
        $this->assertSame(['state' => consent_manager::GIVEN], $data['other']);
        $this->assertStringContainsString("'$managerid'", $event->get_description());
        $this->assertStringContainsString("'$id'", $event->get_description());
        $encoded = json_encode($data) . $event->get_description();
        foreach (['Benali', 'Nadia', 'Camille', 'Vaeiste'] as $name) {
            $this->assertStringNotContainsString($name, $encoded);
        }

        $this->expectException(\coding_exception::class);
        \tool_vae\event\consent_changed::create([
            'context' => \context_user::instance($id), 'objectid' => 1, 'relateduserid' => $managerid,
            'other' => ['managerid' => $managerid, 'state' => consent_manager::GIVEN],
        ]);
    }

    public function test_a_restored_line_written_after_a_withdrawal_never_reopens_the_annotation(): void {
        global $DB;
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $this->setUser($this->vaeiste);
        $consent = new consent_manager();
        $consent->give($id, $managerid);
        $consent->withdraw($id, $managerid);
        $DB->insert_record(consent_manager::TABLE, (object) [
            'uuid' => identity_map::generate_ulid(), 'candidateid' => $id, 'managerid' => $managerid, 'manageruuid' => null,
            'state' => consent_manager::GIVEN, 'importedat' => time(), 'timecreated' => time() + 100,
        ]);

        $this->assertFalse(consent_manager::stands($id, $managerid), 'The latest line written HERE is the withdrawal.');
        $this->assertSame(consent_manager::WITHDRAWN, $consent->current($id)[$managerid]->state);
    }

    public function test_two_local_lines_of_the_same_second_are_told_apart_by_the_id(): void {
        global $DB;
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $this->setUser($this->vaeiste);
        $consent = new consent_manager();
        $given = $consent->give($id, $managerid);
        $withdrawn = $consent->withdraw($id, $managerid);
        $DB->set_field(consent_manager::TABLE, 'timecreated', 1000, ['candidateid' => $id]);
        $this->assertGreaterThan((int) $given->id, (int) $withdrawn->id);

        $this->assertFalse(consent_manager::stands($id, $managerid));
        $this->assertSame((int) $withdrawn->id, (int) $consent->current($id)[$managerid]->id);

        // The other way round: the greater id is now a given line.
        $DB->set_field(consent_manager::TABLE, 'state', consent_manager::WITHDRAWN, ['id' => $given->id]);
        $DB->set_field(consent_manager::TABLE, 'state', consent_manager::GIVEN, ['id' => $withdrawn->id]);
        $this->assertTrue(consent_manager::stands($id, $managerid));
        $this->assertSame(consent_manager::GIVEN, $consent->current($id)[$managerid]->state);
    }

    public function test_a_get_with_the_key_writes_nothing(): void {
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $this->setUser($this->vaeiste);

        $html = $this->page(['userid' => $id, 'action' => 'give', 'managerid' => $managerid, 'sesskey' => sesskey()]);

        $this->assertSame(0, $this->rows(), 'Only a send (POST) writes.');
        $this->assertStringContainsString(get_string('consentgive', 'tool_vae'), $html);
    }

    public function test_the_link_to_the_consent_is_shown_to_the_author_and_to_a_reader_of_the_preparation_before_any_line(): void {
        $id = (int) $this->vaeiste->id;
        $link = '/admin/tool/vae/consent.php';
        $this->assertSame(0, $this->rows());

        foreach ([$this->vaeiste, $this->manager] as $user) {
            $this->setUser($user);
            $this->assertStringContainsString($link, $this->page(['userid' => $id], [], 'dossier.php'), fullname($user));
            $this->assertStringContainsString($link, $this->page(['userid' => $id], [], 'situations.php'), fullname($user));
        }
        // The administrator does not read the preparation (MC-16): no link from the dossier.
        $this->setAdminUser();
        $this->assertStringNotContainsString($link, $this->page(['userid' => $id], [], 'dossier.php'));
    }

    public function test_the_generator_writes_in_the_name_of_the_vaeiste(): void {
        $this->setAdminUser();
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;

        $given = $this->generator->create_consent(['userid' => $id, 'managerid' => $managerid]);
        $withdrawn = $this->generator->create_consent(['userid' => $id, 'managerid' => $managerid, 'state' => 'withdrawn']);

        $this->assertSame(consent_manager::GIVEN, $given->state);
        $this->assertSame(consent_manager::WITHDRAWN, $withdrawn->state);
        $this->assertFalse(consent_manager::stands($id, $managerid));
        $this->assertSame((int) get_admin()->id, (int) $GLOBALS['USER']->id, 'The previous user is restored.');
    }

    public function test_the_page_gives_buttons_to_the_vaeiste_and_reading_to_a_reader(): void {
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;

        $this->setUser($this->vaeiste);
        $html = $this->page([]);
        $this->assertStringContainsString(get_string('consentintro', 'tool_vae'), $html);
        $this->assertStringContainsString('Nadia Benali', $html);
        $this->assertStringNotContainsString('Paul Suivant', $html, 'A follower who may not annotate is not offered.');
        $this->assertStringNotContainsString('Dana Direction', $html, 'The direction, who follows nobody, is not offered.');
        $this->assertStringContainsString(get_string('consentgive', 'tool_vae'), $html);
        $this->assertMatchesRegularExpression(
            '~<tr[^>]*>(?:(?!</tr>).)*Nadia Benali(?:(?!</tr>).)*' . preg_quote(get_string('consentnone', 'tool_vae'), '~') . '~s',
            $html,
            'The row of the eligible person says no consent yet.'
        );

        // Without the key, or with a wrong one, nothing is written.
        $this->assert_refused(
            fn() => $this->page(['userid' => $id], ['action' => 'give', 'managerid' => $managerid]),
            'missingparam'
        );
        $this->assert_refused(
            fn() => $this->page(['userid' => $id], ['action' => 'give', 'managerid' => $managerid, 'sesskey' => 'faux']),
            'invalidsesskey'
        );
        $this->assertSame(0, $this->rows());

        // With the key, the consent is given and the page goes on (a redirection, which a test sees thrown).
        try {
            $this->page(['userid' => $id], ['action' => 'give', 'managerid' => $managerid, 'sesskey' => sesskey()]);
            $this->fail('A redirection was expected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode, $e->getMessage());
        }
        $this->assertTrue(consent_manager::stands($id, $managerid));
        $html = $this->page(['userid' => $id]);
        $this->assertStringContainsString(get_string('consentwithdraw', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('consenthistory', 'tool_vae'), $html);

        // A reader: the state and the history, no button, no form.
        $this->setUser($this->manager);
        $html = $this->page(['userid' => $id]);
        $this->assertStringContainsString('Nadia Benali', $html);
        $this->assertStringContainsString(get_string('consentgiven', 'tool_vae'), $html);
        $this->assertStringNotContainsString(get_string('consentwithdraw', 'tool_vae'), $html);
        $this->assertStringNotContainsString('name="action"', $html);
        // A reader who sends anyway is refused, and nothing is written.
        $this->assert_refused(
            fn() => $this->page(['userid' => $id], ['action' => 'withdraw', 'managerid' => $managerid, 'sesskey' => sesskey()]),
            'errorconsentdenied'
        );
        $this->assertTrue(consent_manager::stands($id, $managerid));

        // Another vaeiste reads nothing.
        $this->setUser($this->othervaeiste);
        $this->expectException(\required_capability_exception::class);
        $this->page(['userid' => $id]);
    }
}
