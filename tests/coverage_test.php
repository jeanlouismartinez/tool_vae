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

use tool_vae\local\competency_gateway;
use tool_vae\local\coverage;
use tool_vae\local\importer;

/**
 * Tests for the coverage of a dossier (lot 9, R3).
 *
 * Declarations go through the plugin's generator, hence its API, as a candidate would make them.
 * Attestations are written as rows: what is under test is how they are counted and dated,
 * and a row lets a test set the date and the withdrawal exactly, which the API rightly does not.
 *
 * TWO CANDIDATES, ON PURPOSE. With one, a query that forgot its candidate filter would pass
 * every test here and show a candidate somebody else's attestations.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\coverage::class)]
final class coverage_test extends \advanced_testcase {
    /** @var \stdClass The candidate whose coverage is read. */
    private $candidate;

    /** @var \stdClass A second candidate, declaring against the same record. */
    private $other;

    /** @var \tool_vae_generator */
    private $plugingenerator;

    /** @var int Sequence for the ULIDs of the attestations written here. */
    private $uuids = 0;

    /**
     * Two records: RNCP36832 with three competencies, RS5000 with one; two candidates who may declare.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->plugingenerator = $generator->get_plugin_generator('tool_vae');

        $this->plugingenerator->create_source([
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C01',
            'shortname' => 'Welcome a person',
        ]);
        $frameworkid = (new competency_gateway())->find_framework('RNCP36832');
        $lpg = $generator->get_plugin_generator(competency_gateway::SUBSYSTEM);
        foreach (['C02' => 'Spot the risk factors for falls', 'C03' => 'Pass on what was observed'] as $rank => $label) {
            $lpg->create_competency([
                'competencyframeworkid' => $frameworkid,
                'idnumber' => 'RNCP36832-' . $rank,
                'shortname' => $label,
            ]);
        }

        $this->plugingenerator->create_source([
            'fichecode' => 'RS5000',
            'ficherang' => '1',
            'fichetype' => 'rs',
            'shortname' => 'Use a lifting aid',
        ]);

        $this->candidate = $generator->create_user();
        $this->other = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        foreach ([$this->candidate, $this->other] as $user) {
            role_assign($roleid, $user->id, \context_user::instance($user->id)->id);
        }
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Every competency of a record declared against appears, zeros included; a record never declared against does not.
     */
    public function test_competencies_without_declaration_are_listed_with_zeros(): void {
        $this->make_declaration($this->candidate, 'RNCP36832', 'C01');

        $coverage = $this->coverage_of($this->candidate);

        $this->assertSame(['RNCP36832'], array_keys($coverage));
        $this->assertSame(['RNCP36832-C01', 'RNCP36832-C02', 'RNCP36832-C03'], $this->idnumbers($coverage['RNCP36832']));

        $c02 = $coverage['RNCP36832']->competencies[1];
        $this->assertSame('Spot the risk factors for falls', $c02->shortname);
        $this->assertSame(0, $c02->declarations);
        $this->assertSame(0, $c02->attested);
        $this->assertNull($c02->lastattested);
        $this->assertFalse($c02->gone);
    }

    /**
     * Declarations and attestations in force are counted, and the date is that of the most recent.
     */
    public function test_counts_and_most_recent_attestation_in_force(): void {
        $first = $this->make_declaration($this->candidate, 'RNCP36832', 'C01');
        $second = $this->make_declaration($this->candidate, 'RNCP36832', 'C01');
        $this->make_declaration($this->candidate, 'RNCP36832', 'C02');
        $this->attest($first, 1760000000);
        $this->attest($second, 1770000000);

        $rows = $this->coverage_of($this->candidate)['RNCP36832']->competencies;

        $this->assertSame(2, $rows[0]->declarations);
        $this->assertSame(2, $rows[0]->attested);
        $this->assertSame(1770000000, $rows[0]->lastattested);
        $this->assertSame(1, $rows[1]->declarations);
        $this->assertSame(0, $rows[1]->attested);
        $this->assertNull($rows[1]->lastattested);
    }

    /**
     * A withdrawn attestation vouches for nothing: neither counted nor taken as the last date.
     */
    public function test_withdrawn_attestation_is_neither_counted_nor_dated(): void {
        $first = $this->make_declaration($this->candidate, 'RNCP36832', 'C01');
        $second = $this->make_declaration($this->candidate, 'RNCP36832', 'C01');
        $this->attest($first, 1760000000);
        $this->attest($second, 1770000000, 1775000000);

        $row = $this->coverage_of($this->candidate)['RNCP36832']->competencies[0];

        $this->assertSame(2, $row->declarations);
        $this->assertSame(1, $row->attested);
        $this->assertSame(1760000000, $row->lastattested);
    }

    /**
     * Each record declared against has its own entry, in code order, with its own competencies.
     */
    public function test_several_records_are_kept_apart(): void {
        $this->make_declaration($this->candidate, 'RS5000', '1');
        $this->make_declaration($this->candidate, 'RNCP36832', 'C03');

        $coverage = $this->coverage_of($this->candidate);

        $this->assertSame(['RNCP36832', 'RS5000'], array_keys($coverage));
        $this->assertSame(['RS5000-1'], $this->idnumbers($coverage['RS5000']));
        $this->assertSame('rs', $coverage['RS5000']->source->fichetype);
        $this->assertSame(1, $coverage['RS5000']->competencies[0]->declarations);
        $this->assertSame(1, $coverage['RNCP36832']->competencies[2]->declarations);
    }

    /**
     * A competency gone from the record stays listed, marked, while it carries declarations - and only then.
     */
    public function test_competency_gone_from_record_is_noted_only_when_declared(): void {
        global $DB;

        $this->make_declaration($this->candidate, 'RNCP36832', 'C02');

        // Listed as the administrator; the listing does not depend on who is logged in.
        $this->setAdminUser();
        $gateway = new competency_gateway();
        $sourceid = (int) $DB->get_field('tool_vae_source', 'id', ['fichecode' => 'RNCP36832']);
        $competencies = $gateway->list_framework_competencies($gateway->find_framework('RNCP36832'));
        foreach ($competencies as $competency) {
            if ($competency->idnumber === 'RNCP36832-C01') {
                continue;
            }
            $DB->insert_record('tool_vae_obsolete', (object) [
                'competencyid' => $competency->id,
                'sourceid' => $sourceid,
                'detectedat' => time(),
                'kind' => importer::GONE,
            ]);
        }

        $rows = $this->coverage_of($this->candidate)['RNCP36832']->competencies;

        // C03 left the record with nothing declared against it: it is no longer the candidate's concern.
        $this->assertSame(['RNCP36832-C01', 'RNCP36832-C02'], array_column($rows, 'idnumber'));
        $this->assertFalse($rows[0]->gone);
        $this->assertTrue($rows[1]->gone);
        $this->assertSame(1, $rows[1]->declarations);
    }

    /**
     * Another candidate's declarations and attestations count for nothing in this coverage.
     */
    public function test_only_the_candidates_own_data_is_counted(): void {
        $theirs = $this->make_declaration($this->other, 'RNCP36832', 'C01');
        $this->attest($theirs, 1760000000);
        $this->make_declaration($this->other, 'RS5000', '1');
        $this->make_declaration($this->candidate, 'RNCP36832', 'C02');

        $coverage = $this->coverage_of($this->candidate);

        $this->assertSame(['RNCP36832'], array_keys($coverage));
        $c01 = $coverage['RNCP36832']->competencies[0];
        $this->assertSame(0, $c01->declarations);
        $this->assertSame(0, $c01->attested);
        $this->assertNull($c01->lastattested);

        $this->assertSame([], $this->coverage_of($this->getDataGenerator()->create_user()));
    }

    /**
     * The coverage is read without moodle/competency:competencyview, which only the authenticated-user archetype
     * holds by default.
     *
     * A site that took the right away from that role - to keep its own frameworks out of tool_lp, which asks it -
     * installed the plugin and gave the role of the candidate: the dossier died on a refusal of the core as soon as
     * the candidate had one declaration. The records are imported on this site for everyone to declare against,
     * and what the dossier reads of them is what the archive already reads without the right.
     */
    public function test_coverage_is_read_without_the_core_viewing_right(): void {
        global $CFG;

        // The declaration is made while the right is still there: the core asks it again to link the evidence.
        $this->make_declaration($this->candidate, 'RNCP36832', 'C01');

        unassign_capability('moodle/competency:competencyview', $CFG->defaultuserroleid, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->candidate);
        $this->assertFalse(
            has_capability('moodle/competency:competencyview', \context_system::instance()),
            'The candidate still holds the right: the test proves nothing.'
        );

        $coverage = coverage::for_candidate((int) $this->candidate->id);

        $this->assertSame(['RNCP36832-C01', 'RNCP36832-C02', 'RNCP36832-C03'], $this->idnumbers($coverage['RNCP36832']));
        $this->assertSame(1, $coverage['RNCP36832']->competencies[0]->declarations);
    }

    /**
     * A declaration on a record whose framework is gone is still listed, under the rank the candidate gave.
     *
     * The core refuses to delete a framework while a user competency holds one of its competencies, so the usual
     * gesture does not reach this state; a deletion straight in the tables does, and the dossier must then do what it
     * does for a competency removed from its framework - list the declaration under the rank given, noted as gone -
     * and not raise.
     */
    public function test_a_declaration_on_a_record_whose_framework_is_gone_is_still_listed(): void {
        global $DB;

        $this->make_declaration($this->candidate, 'RNCP36832', 'C01');
        $frameworkid = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP36832']);
        $DB->delete_records('competency', ['competencyframeworkid' => $frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $frameworkid]);

        $coverage = $this->coverage_of($this->candidate);

        $this->assertSame(['RNCP36832-C01'], $this->idnumbers($coverage['RNCP36832']));
        $this->assertTrue($coverage['RNCP36832']->competencies[0]->gone);
        $this->assertSame(1, $coverage['RNCP36832']->competencies[0]->declarations);
    }

    /**
     * Declares as the candidate, through the plugin's API.
     *
     * @param \stdClass $user
     * @param string $fichecode
     * @param string $ficherang
     * @return int The declaration id.
     */
    private function make_declaration(\stdClass $user, string $fichecode, string $ficherang): int {
        return (int) $this->plugingenerator->create_declaration([
            'userid' => $user->id,
            'fichecode' => $fichecode,
            'ficherang' => $ficherang,
            'situation' => 'Night rounds ' . random_string(),
        ])->id;
    }

    /**
     * Writes an attestation row, given on a set date and possibly withdrawn.
     *
     * @param int $evidenceid
     * @param int $attestedat
     * @param int|null $withdrawnat
     */
    private function attest(int $evidenceid, int $attestedat, ?int $withdrawnat = null): void {
        global $DB;

        $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => str_pad((string) ++$this->uuids, 26, '0', STR_PAD_LEFT),
            'evidenceid' => $evidenceid,
            'attestantid' => get_admin()->id,
            'attestantrole' => 'Care manager',
            'statement' => 'I supervised this person.',
            'attestedat' => $attestedat,
            'withdrawnat' => $withdrawnat,
            'withdrawnreason' => $withdrawnat === null ? null : 'erreur',
            'timecreated' => $attestedat,
            'timemodified' => $withdrawnat ?? $attestedat,
            'usermodified' => get_admin()->id,
        ]);
    }

    /**
     * The coverage of a candidate, read in their session as the dossier reads it.
     *
     * @param \stdClass $user
     * @return \stdClass[]
     */
    private function coverage_of(\stdClass $user): array {
        $this->setUser($user);

        return coverage::for_candidate((int) $user->id);
    }

    /**
     * The idnumbers of a record's lines, in display order.
     *
     * @param \stdClass $fiche
     * @return string[]
     */
    private function idnumbers(\stdClass $fiche): array {
        return array_column($fiche->competencies, 'idnumber');
    }
}
