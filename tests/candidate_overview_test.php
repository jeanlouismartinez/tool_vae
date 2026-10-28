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

use tool_vae\local\candidate_overview;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\referent_manager;

/**
 * What the page "My candidates" counts, whole or narrowed to a bloc (lot 7 bis, T-23 lot 3).
 *
 * WHAT IS ASSERTED. That the counts are those of declarations and of declarations awaiting an attestation, a withdrawn one
 * awaiting again; that they can be narrowed to the declarations of one record, and to those under one bloc of it; that the
 * blocs of a person are read from the referent rows and no one else's; that the whole record is no restriction on the ranks;
 * and that a bloc with no competency counts nothing.
 *
 * The declarations are inserted as rows: what is under test is the counting, not how a declaration is made.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\candidate_overview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\referent_manager::class)]
final class candidate_overview_test extends \advanced_testcase {
    /** @var \stdClass A candidate. */
    private $camille;

    /** @var \stdClass Another candidate. */
    private $denis;

    /** @var \stdClass A manager, referent of blocs. */
    private $manager;

    /**
     * A record with two blocs and a rank under none, a flat one, two candidates and a manager.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();

        $meta = fn(string $code) => (object) [
            'fichecode' => $code, 'fichetype' => 'rs', 'name' => 'Fiche ' . $code,
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ];
        $importer = new importer();
        $importer->import($meta('RS00000'), importer::rows_from_csv(
            "rang,intitule,description,bloc\nBC01,Accompagner,,\nC01,Aider au lever,,BC01\nBC02,Travailler en équipe,,\n"
            . "C02,Transmettre,,BC02\nZ01,Hors bloc,,\n"
        ));
        $importer->import($meta('RS11111'), importer::rows_from_csv("rang,intitule\nC01,Une\n"));

        $generator = $this->getDataGenerator();
        $this->camille = $generator->create_user();
        $this->denis = $generator->create_user();
        $this->manager = $generator->create_user();
    }

    /**
     * Insert a declaration.
     *
     * @param \stdClass $candidate
     * @param string $fichecode
     * @param string $ficherang
     * @param string|null $attested null for none, 'standing' or 'withdrawn'.
     * @return int The id.
     */
    private function declare_row(\stdClass $candidate, string $fichecode, string $ficherang, ?string $attested = null): int {
        global $DB;

        $at = time() - DAYSECS;
        $id = (int) $DB->insert_record('tool_vae_evidence', (object) [
            'uuid' => identity_map::generate_ulid(),
            'userevidenceid' => $DB->count_records('tool_vae_evidence') + 1,
            'candidateid' => (int) $candidate->id,
            'periodstart' => $at - 1000,
            'periodend' => $at,
            'settingtype' => 'ehpad',
            'situationformat' => FORMAT_PLAIN,
            'situation' => 'Une situation.',
            'fichecode' => $fichecode,
            'ficherang' => $ficherang,
            'ficheupdated' => $at,
            'timecreated' => $at,
            'timemodified' => $at,
            'usermodified' => (int) $candidate->id,
        ]);
        if ($attested !== null) {
            $DB->insert_record('tool_vae_attest', (object) [
                'uuid' => identity_map::generate_ulid(),
                'evidenceid' => $id,
                'attestantid' => (int) $this->manager->id,
                'attestantrole' => 'Responsable',
                'statement' => 'Vu.',
                'attestedat' => $at,
                'withdrawnat' => $attested === 'withdrawn' ? $at : null,
                'withdrawnreason' => $attested === 'withdrawn' ? 'erreur' : null,
                'timecreated' => $at,
                'timemodified' => $at,
                'usermodified' => (int) $this->manager->id,
            ]);
        }

        return $id;
    }

    /**
     * Declarations spread over blocs, records and states, for Camille; one for Denis.
     */
    private function spread(): void {
        $this->declare_row($this->camille, 'RS00000', 'C01');
        $this->declare_row($this->camille, 'RS00000', 'C01', 'standing');
        $this->declare_row($this->camille, 'RS00000', 'BC01', 'withdrawn');
        $this->declare_row($this->camille, 'RS00000', 'C02');
        $this->declare_row($this->camille, 'RS00000', 'Z01');
        $this->declare_row($this->camille, 'RS11111', 'C01');
        $this->declare_row($this->denis, 'RS00000', 'C02');
    }

    public function test_the_counts_are_those_of_declarations_and_of_declarations_awaiting(): void {
        $this->spread();
        $counts = candidate_overview::counts([(int) $this->camille->id, (int) $this->denis->id]);

        $this->assertSame(6, $counts[(int) $this->camille->id]->declarations);
        // Five wait: one standing attestation is the only one that does not, and a withdrawn one waits again.
        $this->assertSame(5, $counts[(int) $this->camille->id]->pending);
        $this->assertSame(1, $counts[(int) $this->denis->id]->declarations);
        $this->assertSame(1, $counts[(int) $this->denis->id]->pending);
    }

    public function test_a_candidate_without_declaration_is_not_in_the_answer(): void {
        $this->declare_row($this->camille, 'RS00000', 'C01');
        $counts = candidate_overview::counts([(int) $this->camille->id, (int) $this->denis->id]);

        $this->assertSame([(int) $this->camille->id], array_keys($counts));
        $this->assertSame([], candidate_overview::counts([]));
    }

    public function test_the_counts_can_be_narrowed_to_a_record(): void {
        $this->spread();
        $counts = candidate_overview::counts([(int) $this->camille->id, (int) $this->denis->id], 'RS11111');

        $this->assertSame([(int) $this->camille->id], array_keys($counts));
        $this->assertSame(1, $counts[(int) $this->camille->id]->declarations);
    }

    public function test_the_counts_can_be_narrowed_to_a_bloc(): void {
        $this->spread();
        $ids = [(int) $this->camille->id, (int) $this->denis->id];

        // The bloc BC01 and the competency under it.
        $ranks = referent_manager::ranks_under('RS00000', 'BC01');
        $this->assertEqualsCanonicalizing(['BC01', 'C01'], $ranks);
        $counts = candidate_overview::counts($ids, 'RS00000', $ranks);
        $this->assertSame([(int) $this->camille->id], array_keys($counts));
        $this->assertSame(3, $counts[(int) $this->camille->id]->declarations);
        $this->assertSame(2, $counts[(int) $this->camille->id]->pending);

        // The bloc BC02: one declaration of each candidate.
        $counts = candidate_overview::counts($ids, 'RS00000', referent_manager::ranks_under('RS00000', 'BC02'));
        $this->assertSame(1, $counts[(int) $this->camille->id]->declarations);
        $this->assertSame(1, $counts[(int) $this->denis->id]->declarations);
    }

    public function test_the_whole_record_is_not_a_restriction_on_the_ranks(): void {
        $this->spread();
        $this->assertNull(referent_manager::ranks_under('RS00000', ''));
        $counts = candidate_overview::counts([(int) $this->camille->id], 'RS00000', null);

        $this->assertSame(5, $counts[(int) $this->camille->id]->declarations);
    }

    public function test_a_bloc_with_no_competency_counts_nothing(): void {
        $this->spread();

        $this->assertSame([], referent_manager::ranks_under('RS00000', 'BC99'));
        $this->assertSame([], candidate_overview::counts([(int) $this->camille->id], 'RS00000', []));
        $this->assertSame([], referent_manager::ranks_under('RS99999', 'BC01'));
    }

    public function test_the_blocs_of_a_record_from_one_reading(): void {
        $map = referent_manager::bloc_map('RS00000');

        // The order of the keys is the tree's, which no one relies on.
        ksort($map);
        $this->assertSame(
            ['BC01' => 'BC01', 'BC02' => 'BC02', 'C01' => 'BC01', 'C02' => 'BC02', 'Z01' => ''],
            $map
        );
        $this->assertSame(['C01' => ''], referent_manager::bloc_map('RS11111'));
        $this->assertSame([], referent_manager::bloc_map('RS99999'));
    }

    public function test_the_blocs_of_a_person_are_read_from_their_own_rows(): void {
        $other = $this->getDataGenerator()->create_user();
        referent_manager::add('RS00000', 'BC02', (int) $this->manager->id);
        referent_manager::add('RS00000', 'BC01', (int) $this->manager->id);
        referent_manager::add('RS11111', '', (int) $this->manager->id);
        referent_manager::add('RS00000', 'BC01', (int) $other->id);

        $mine = referent_manager::for_user((int) $this->manager->id);

        $this->assertSame(
            [['RS00000', 'BC01'], ['RS00000', 'BC02'], ['RS11111', '']],
            array_map(fn($row) => [$row->fichecode, $row->ficherang], $mine)
        );
        $this->assertSame([], referent_manager::for_user((int) $this->camille->id));
    }
}
