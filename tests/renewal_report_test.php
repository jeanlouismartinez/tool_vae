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

use tool_vae\local\certification_manager;
use tool_vae\local\identity_map;
use tool_vae\local\positioning_rules;
use tool_vae\local\renewal_report;

/**
 * The certifications whose date has passed, for the direction (T-90, plan 7).
 *
 * A DATE THAT HAS PASSED, NEVER A VERDICT ON A DOSSIER. The report lists the line that stands for a bloc when it is a
 * certification with a last day before the reader's day; a newer line, a withdrawal, no date or a date still to come keep
 * a bloc off it. It is one query, whatever the number of certifications.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(renewal_report::class)]
final class renewal_report_test extends \advanced_testcase {
    /** @var \tool_vae_generator */
    private $plugingenerator;

    /**
     * RNCP100 with two blocs (BC01 holding C01 and C02, BC02 holding C03) and RS200, a record with no bloc.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->plugingenerator = $this->getDataGenerator()->get_plugin_generator('tool_vae');
        $this->plugingenerator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        $this->plugingenerator->create_competency(['fichecode' => 'RNCP100', 'ficherang' => 'BC02', 'shortname' => 'Bloc 2']);
        foreach ([['C01', 'BC01'], ['C02', 'BC01'], ['C03', 'BC02']] as [$rank, $bloc]) {
            $this->plugingenerator->create_competency([
                'fichecode' => 'RNCP100', 'ficherang' => $rank, 'shortname' => 'Competence ' . $rank, 'bloc' => $bloc,
            ]);
        }
        $this->plugingenerator->create_source(['fichecode' => 'RS200', 'ficherang' => 'C01', 'shortname' => 'Une competence']);
    }

    /**
     * Insert one line of certification straight into the table.
     *
     * @param \stdClass $candidate
     * @param string $status certified or withdrawn.
     * @param string|null $until The last day; null for none.
     * @param int $time When it was written.
     * @param string $rank
     * @param string $code
     * @return \stdClass
     */
    private function line(
        \stdClass $candidate,
        string $status,
        ?string $until,
        int $time = 1762000000,
        string $rank = 'BC01',
        string $code = 'RNCP100'
    ): \stdClass {
        global $DB;

        $row = (object) [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => $candidate->id,
            'fichecode' => $code,
            'ficherang' => $rank,
            'status' => $status,
            'reason' => 'Motif.',
            'reasonhash' => positioning_rules::hash(
                [$status, $code, $rank, 'Motif.', '2026-01-01', '', (string) $until, (string) $time]
            ),
            'decisiondate' => '2026-01-01',
            'alertreason' => null,
            'validuntil' => $until,
            'authorid' => null,
            'authoruuid' => null,
            'importedat' => null,
            'timecreated' => $time,
        ];
        $row->id = $DB->insert_record('tool_vae_certif', $row);

        return $row;
    }

    /**
     * Record that a candidate declared on a record, straight in the table: the report only asks whether a row exists.
     *
     * @param \stdClass $candidate
     * @param string $code
     */
    private function declare_on(\stdClass $candidate, string $code = 'RNCP100'): void {
        global $DB;
        static $anchor = 900000;

        $DB->insert_record('tool_vae_evidence', (object) [
            'uuid' => identity_map::generate_ulid(),
            'userevidenceid' => ++$anchor,
            'candidateid' => $candidate->id,
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'situation' => 'Une situation.',
            'situationformat' => FORMAT_PLAIN,
            'fichecode' => $code,
            'ficherang' => 'C01',
            'ficheupdated' => 0,
            'timecreated' => 1762000000,
            'timemodified' => 1762000000,
            'usermodified' => $candidate->id,
        ]);
    }

    /**
     * The keys (candidate, record, rank) the report lists, in the order it gives them.
     *
     * @param string $today
     * @return string[]
     */
    private function listed(string $today): array {
        return array_values(array_map(
            fn($row) => $row->candidateid . '/' . $row->fichecode . '/' . $row->ficherang,
            renewal_report::expired($today)
        ));
    }

    public function test_a_certification_whose_last_day_is_before_today_is_listed_and_the_day_itself_is_not(): void {
        $yesterday = $this->getDataGenerator()->create_user();
        $today = $this->getDataGenerator()->create_user();
        $tomorrow = $this->getDataGenerator()->create_user();
        $this->line($yesterday, 'certified', '2026-11-09');
        $this->line($today, 'certified', '2026-11-10');
        $this->line($tomorrow, 'certified', '2026-11-11');

        $this->assertSame([$yesterday->id . '/RNCP100/BC01'], $this->listed('2026-11-10'));
    }

    public function test_a_line_with_no_date_null_or_empty_is_never_listed(): void {
        $none = $this->getDataGenerator()->create_user();
        $empty = $this->getDataGenerator()->create_user();
        $this->line($none, 'certified', null);
        $this->line($empty, 'certified', '');

        $this->assertSame([], $this->listed('2099-01-01'));
    }

    public function test_a_withdrawal_that_stands_is_not_listed(): void {
        $candidate = $this->getDataGenerator()->create_user();
        $this->line($candidate, 'certified', '2026-01-01', 1762000000);
        // A withdrawal that still carries a date (written by hand) is a withdrawal all the same.
        $this->line($candidate, 'withdrawn', '2026-01-01', 1762000100);

        $this->assertSame([], $this->listed('2026-11-10'));
    }

    public function test_a_renewal_takes_the_bloc_off_the_list_and_a_later_expired_renewal_puts_it_back(): void {
        $candidate = $this->getDataGenerator()->create_user();
        $this->line($candidate, 'certified', '2026-01-01', 1762000000);
        $this->line($candidate, 'certified', '2028-01-01', 1762000100);
        $this->assertSame([], $this->listed('2026-11-10'));

        $this->assertSame([$candidate->id . '/RNCP100/BC01'], $this->listed('2029-01-01'));
    }

    public function test_a_later_line_on_another_bloc_or_another_record_of_the_same_person_hides_nothing(): void {
        $candidate = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->line($candidate, 'certified', '2026-01-01', 1762000000, 'BC01');
        $this->line($candidate, 'certified', '2099-01-01', 1762000500, 'BC02');
        $this->line($candidate, 'certified', '2099-01-01', 1762000600, 'BC01', 'RS200');
        $this->line($other, 'certified', '2099-01-01', 1762000700, 'BC01');

        $this->assertSame([$candidate->id . '/RNCP100/BC01'], $this->listed('2026-11-10'));
    }

    public function test_a_line_that_stands_is_told_by_the_same_rule_as_latest_with_lines_of_the_same_second(): void {
        global $DB;
        $candidate = $this->getDataGenerator()->create_user();
        // Same second: the greater id stands, whatever the order the rows were read in.
        $this->line($candidate, 'certified', '2026-01-01', 1762000000);
        $this->line($candidate, 'certified', '2028-01-01', 1762000000);
        $this->assertSame([], $this->listed('2026-11-10'), 'The line with the greater id stands, and it has not expired.');

        $other = $this->getDataGenerator()->create_user();
        $this->line($other, 'certified', '2028-01-01', 1762000000);
        $this->line($other, 'certified', '2026-01-01', 1762000000);
        $this->assertSame([$other->id . '/RNCP100/BC01'], $this->listed('2026-11-10'));
        $this->assertSame(2, $DB->count_records('tool_vae_certif', ['candidateid' => $other->id]));
    }

    public function test_the_list_gives_exactly_the_keys_where_the_line_that_stands_has_expired(): void {
        $today = '2026-11-10';
        $expected = [];
        $codes = [['RNCP100', 'BC01'], ['RNCP100', 'BC02'], ['RS200', '']];
        $scenarios = [
            [['certified', '2026-01-01', 10], ['certified', '2027-01-01', 20]],
            [['certified', '2027-01-01', 10], ['certified', '2026-01-01', 20]],
            [['certified', '2026-11-09', 10], ['withdrawn', null, 20]],
            [['certified', '2026-11-10', 10]],
            [['certified', '2026-11-09', 10], ['certified', '2026-11-08', 10]],
            [['withdrawn', null, 10], ['certified', '2026-01-01', 20]],
        ];
        foreach ($scenarios as $index => $lines) {
            $candidate = $this->getDataGenerator()->create_user();
            [$code, $rank] = $codes[$index % 3];
            $last = null;
            foreach ($lines as [$status, $until, $offset]) {
                $last = $this->line($candidate, $status, $until, 1762000000 + $offset, $rank, $code);
            }
            $standing = positioning_rules::latest('tool_vae_certif', (int) $candidate->id, $code)[$rank];
            $this->assertSame((int) $last->id, (int) $standing->id);
            if (certification_manager::is_expired($standing, $today)) {
                $expected[] = $candidate->id . '/' . $code . '/' . $rank;
            }
        }
        $listed = $this->listed($today);
        sort($expected);
        sort($listed);

        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $listed);
    }

    public function test_a_deleted_account_is_left_out_and_a_suspended_one_is_kept(): void {
        global $DB;
        $gone = $this->getDataGenerator()->create_user();
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $this->line($gone, 'certified', '2026-01-01');
        $this->line($suspended, 'certified', '2026-01-01');
        $DB->set_field('user', 'deleted', 1, ['id' => $gone->id]);

        $this->assertSame([$suspended->id . '/RNCP100/BC01'], $this->listed('2026-11-10'));
    }

    public function test_the_list_is_ordered_by_last_day_then_by_line(): void {
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $third = $this->getDataGenerator()->create_user();
        $this->line($first, 'certified', '2026-06-01');
        $this->line($second, 'certified', '2026-02-01');
        $this->line($third, 'certified', '2026-02-01');

        $this->assertSame(
            [$second->id . '/RNCP100/BC01', $third->id . '/RNCP100/BC01', $first->id . '/RNCP100/BC01'],
            $this->listed('2026-11-10')
        );
    }

    public function test_the_rows_say_who_where_until_when_and_for_how_many_days(): void {
        $candidate = $this->getDataGenerator()->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->line($candidate, 'certified', '2026-11-05');
        $this->line($candidate, 'certified', '2026-11-01', 1762000000, 'BC02');
        $this->line($candidate, 'certified', '2026-11-01', 1762000000, '', 'RS200');
        $this->declare_on($candidate, 'RNCP100');
        $this->declare_on($candidate, 'RS200');

        $rows = array_values(renewal_report::rows('2026-11-10'));

        $this->assertCount(3, $rows);
        $this->assertSame('2026-11-01', $rows[0]->validuntil);
        $this->assertSame(9, $rows[0]->days);
        $this->assertSame('Camille Vaeiste', fullname($rows[0]));
        $by = [];
        foreach ($rows as $row) {
            $by[$row->fichecode . '/' . $row->ficherang] = $row;
        }
        $this->assertSame('Bloc 2', $by['RNCP100/BC02']->label);
        $this->assertTrue($by['RNCP100/BC02']->held);
        $this->assertSame(5, $by['RNCP100/BC01']->days);
        $this->assertSame('Bloc 1', $by['RNCP100/BC01']->label);
        $this->assertSame(get_string('positioningwhole', 'tool_vae'), $by['RS200/']->label);
    }

    public function test_a_record_the_site_no_longer_holds_is_listed_by_its_code(): void {
        $candidate = $this->getDataGenerator()->create_user();
        $this->line($candidate, 'certified', '2026-01-01', 1762000000, 'BC01', 'ZZ99999');

        $rows = array_values(renewal_report::rows('2026-11-10'));

        $this->assertCount(1, $rows);
        $this->assertSame('BC01', $rows[0]->label);
        $this->assertFalse($rows[0]->held, 'It cannot be renewed from here.');
        $this->assertSame('gone', $rows[0]->reason);
    }

    public function test_a_renewable_line_has_no_reason(): void {
        $candidate = $this->getDataGenerator()->create_user();
        $this->line($candidate, 'certified', '2026-01-01');
        $this->declare_on($candidate);

        $rows = array_values(renewal_report::rows('2026-11-10'));

        $this->assertTrue($rows[0]->held);
        $this->assertSame('', $rows[0]->reason);
    }

    /**
     * The write is refused to a candidate who has no declaration on the record (certification_manager::write), whatever the
     * lines already stored: offering the renewal link would offer a page that always refuses.
     */
    public function test_a_candidate_with_no_declaration_left_on_the_record_cannot_be_renewed_from_here(): void {
        $withdeclaration = $this->getDataGenerator()->create_user();
        $without = $this->getDataGenerator()->create_user();
        $this->line($withdeclaration, 'certified', '2026-01-01');
        $this->line($without, 'certified', '2026-01-02');
        $this->declare_on($withdeclaration);
        // A declaration on ANOTHER record does not open this one.
        $this->declare_on($without, 'RS200');

        $by = [];
        foreach (renewal_report::rows('2026-11-10') as $row) {
            $by[(int) $row->candidateid] = $row;
        }

        $this->assertTrue($by[(int) $withdeclaration->id]->held);
        $this->assertFalse($by[(int) $without->id]->held);
        $this->assertSame('undeclared', $by[(int) $without->id]->reason);
    }

    /**
     * Competencies switched off is not a record that left the site: the page must not say the latter.
     */
    public function test_competencies_switched_off_is_told_as_such_and_not_as_a_record_gone(): void {
        $candidate = $this->getDataGenerator()->create_user();
        $this->line($candidate, 'certified', '2026-01-01');
        $this->declare_on($candidate);
        set_config('enabled', 0, \tool_vae\local\competency_gateway::SUBSYSTEM);

        $rows = array_values(renewal_report::rows('2026-11-10'));

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]->held);
        $this->assertSame('disabled', $rows[0]->reason);
    }

    public function test_the_number_of_reads_does_not_grow_with_the_number_of_certifications(): void {
        global $DB;
        $few = $this->getDataGenerator()->create_user();
        $this->line($few, 'certified', '2026-01-01');
        // The first call fills the caches of the framework and of the strings: it is not the one that is measured.
        renewal_report::rows('2026-11-10');
        $before = $DB->perf_get_reads();
        renewal_report::rows('2026-11-10');
        $reads = $DB->perf_get_reads() - $before;

        for ($i = 0; $i < 12; $i++) {
            $this->line($this->getDataGenerator()->create_user(), 'certified', '2026-01-01');
        }
        $before = $DB->perf_get_reads();
        $rows = renewal_report::rows('2026-11-10');
        $after = $DB->perf_get_reads() - $before;

        $this->assertCount(13, $rows);
        $this->assertSame($reads, $after);
    }

    public function test_the_page_is_in_the_tree_for_who_holds_both_rights_and_for_nobody_else(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $generator = $this->getDataGenerator();
        $system = \context_system::instance();
        $holders = [
            'viewany' => ['tool/vae:viewany'],
            'certify' => ['tool/vae:certify'],
            'both' => ['tool/vae:viewany', 'tool/vae:certify'],
        ];
        $users = [];
        foreach ($holders as $name => $capabilities) {
            $users[$name] = $generator->create_user();
            $role = $generator->create_role();
            foreach ($capabilities as $capability) {
                assign_capability($capability, CAP_ALLOW, $role, $system->id, true);
            }
            role_assign($role, $users[$name]->id, $system->id);
        }
        accesslib_clear_all_caches_for_unit_testing();

        $found = [];
        foreach ($users as $name => $user) {
            $this->setUser($user);
            $found[$name] = admin_get_root(true, true)->locate('toolvae_renewals') !== null;
        }
        $this->setAdminUser();
        $found['admin'] = admin_get_root(true, true)->locate('toolvae_renewals') !== null;

        $this->assertSame(['viewany' => false, 'certify' => false, 'both' => true, 'admin' => true], $found);
    }
}
