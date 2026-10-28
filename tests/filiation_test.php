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

use tool_vae\local\carry_manager;
use tool_vae\local\dossier_importer;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\passage_manager;
use tool_vae\local\pdf_renderer;
use tool_vae\local\purge;
use tool_vae\local\supersession;

/**
 * The filiation of a carried-over declaration, in the archive (T-30, lot 3, follow-up).
 *
 * WHAT IS ASSERTED. That an archive says, on the claim that was carried over, which claim it comes from and when -
 * and nothing of who decided the correspondence nor of the note; that the filiation comes back on import, as a
 * trace without those two; that an archive without filiation reads as before; and that an archive naming a claim
 * it does not hold, carrying one claim over twice, or going round in a circle is refused whole.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_importer::class)]
final class filiation_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass The claim on the old record. */
    private $old;

    /** @var \stdClass The claim carried over to the new record. */
    private $new;

    /**
     * A record replaced by another, an equivalent decided, a declaration carried over.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $tool = $generator->get_plugin_generator('tool_vae');
        $tool->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'C01']);
        $tool->create_source(['fichecode' => 'RNCP200', 'ficherang' => 'C01']);
        supersession::declare_successor('RNCP100', 'RNCP200');
        $tool->create_correspondence(['from' => 'RNCP100-C01', 'to' => 'RNCP200-C01', 'note' => 'Note secrete de la commission.']);

        $role = $generator->create_role();
        foreach (['declare', 'viewown', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $role, \context_system::instance()->id, true);
        }
        $this->candidate = $generator->create_user();
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->candidate);
        $this->old = (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $DB->get_field('competency', 'id', ['idnumber' => 'RNCP100-C01'], MUST_EXIST),
            'periodstart' => 1756684800,
            'periodend' => 1782777600,
            'settingtype' => 'ehpad',
            'situation' => 'Accompagnement au lever, recherche de son accord a chaque geste.',
            'situationformat' => FORMAT_PLAIN,
        ]);
        $row = (new carry_manager())->carry((int) $this->old->id, (object) [
            'periodstart' => 1756684800,
            'periodend' => 1782777600,
            'settingtype' => 'ehpad',
            'settingdetail' => '',
            'situation' => 'Accompagnement au lever, relu pour la nouvelle fiche.',
        ]);
        $this->new = $DB->get_record('tool_vae_evidence', ['id' => $row->newevidenceid], '*', MUST_EXIST);
    }

    /**
     * The files of the candidate's archive.
     *
     * @return string[]
     */
    private function build(): array {
        global $DB;

        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');

        return (new exporter())->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time());
    }

    /**
     * One claim of an archive, decoded.
     *
     * @param string[] $files
     * @param string $uuid
     * @return array
     */
    private static function claim(array $files, string $uuid): array {
        return json_decode($files['preuves/' . $uuid . '.json'], true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Rewrite one claim of an archive, digest included.
     *
     * @param string[] $files
     * @param string $uuid
     * @param callable $change Receives the decoded claim, returns it changed.
     * @return string[]
     */
    private static function forge(array $files, string $uuid, callable $change): array {
        $path = 'preuves/' . $uuid . '.json';
        $content = json_encode($change(self::claim($files, $uuid)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $files[$path] = $content;

        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['fichiers'] as $index => $entry) {
            if ($entry['chemin'] === $path) {
                $manifest['fichiers'][$index]['octets'] = strlen($content);
                $manifest['fichiers'][$index]['sha256'] = hash('sha256', $content);
            }
        }
        $files['manifeste.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $files;
    }

    /**
     * Destroy the dossier, then import the files as the administrator and expect a refusal.
     *
     * @param string[] $files
     * @param string $errorcode
     */
    private function assert_refused_after_destroy(array $files, string $errorcode): void {
        global $DB;

        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        try {
            (new dossier_importer())->import_files((int) $this->candidate->id, $files);
            $this->fail("The archive was accepted; $errorcode was expected.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('tool_vae_evidence'), 'Nothing was written.');
        $this->assertSame(0, $DB->count_records('tool_vae_carry'));
    }

    public function test_the_claim_carried_over_names_where_it_comes_from_and_when(): void {
        global $DB;
        $files = $this->build();

        $new = self::claim($files, $this->new->uuid);
        $this->assertSame($this->old->uuid, $new['reportee_depuis']);
        $carriedat = (int) $DB->get_field('tool_vae_carry', 'carriedat', ['newevidenceid' => $this->new->id]);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $carriedat), $new['reportee_le']);

        $old = self::claim($files, $this->old->uuid);
        $this->assertArrayNotHasKey('reportee_depuis', $old);
        $this->assertArrayNotHasKey('reportee_le', $old);
    }

    public function test_the_printable_copy_says_where_a_claim_was_carried_over_from(): void {
        $files = $this->build();
        $preuves = [self::claim($files, $this->old->uuid), self::claim($files, $this->new->uuid)];
        $referentiel = json_decode($files['referentiel.json'], true, 512, JSON_THROW_ON_ERROR);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);

        $html = pdf_renderer::html($manifest, $preuves, $referentiel);

        $this->assertStringContainsString(get_string('exportpdfcarriedlabel', 'tool_vae'), $html);
        $this->assertStringContainsString('RNCP100-C01', $html);
        $this->assertSame(1, substr_count($html, 'RNCP100-C01'), 'Only the copy says where it comes from.');
    }

    public function test_who_decided_the_correspondence_and_the_note_never_leave_the_site(): void {
        $files = $this->build();

        foreach ($files as $path => $content) {
            $this->assertStringNotContainsString('Note secrete', $content, $path);
            $this->assertStringNotContainsString('decidedby', $content, $path);
            $this->assertStringNotContainsString(fullname(get_admin()), $content, $path);
        }
    }

    public function test_the_filiation_comes_back_on_import_as_a_trace_without_who_or_note(): void {
        global $DB;
        $files = $this->build();
        $carriedat = (int) $DB->get_field('tool_vae_carry', 'carriedat', ['newevidenceid' => $this->new->id]);

        purge::dossier((int) $this->candidate->id);
        $this->assertSame(0, $DB->count_records('tool_vae_carry'));
        $this->setAdminUser();
        (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $old = $DB->get_record('tool_vae_evidence', ['uuid' => $this->old->uuid], '*', MUST_EXIST);
        $new = $DB->get_record('tool_vae_evidence', ['uuid' => $this->new->uuid], '*', MUST_EXIST);
        $carry = $DB->get_record('tool_vae_carry', ['newevidenceid' => $new->id], '*', MUST_EXIST);
        $this->assertSame((int) $old->id, (int) $carry->oldevidenceid);
        $this->assertSame($carriedat, (int) $carry->carriedat);
        $this->assertSame((int) $old->competencyid, (int) $carry->fromcompetencyid);
        $this->assertSame((int) $new->competencyid, (int) $carry->tocompetencyid);
        $this->assertSame(0, (int) $carry->decidedby);
        $this->assertSame(0, (int) $carry->decidedat);
        $this->assertSame('', (string) $carry->note);

        // And a second export says the same thing as the first.
        $again = self::claim($this->build(), $this->new->uuid);
        $this->assertSame($this->old->uuid, $again['reportee_depuis']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $carriedat), $again['reportee_le']);
    }

    public function test_an_archive_without_filiation_reads_as_before(): void {
        global $DB;
        $files = $this->build();
        $files = self::forge($files, $this->new->uuid, function (array $claim): array {
            unset($claim['reportee_depuis'], $claim['reportee_le']);

            return $claim;
        });

        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(2, $result->declarations);
        $this->assertSame(0, $DB->count_records('tool_vae_carry'));
    }

    public function test_a_claim_named_that_the_archive_does_not_hold_refuses_it(): void {
        $files = self::forge($this->build(), $this->new->uuid, function (array $claim): array {
            $claim['reportee_depuis'] = '01K2N8Q4W5X6Y7Z8A9B0C1D2E3';

            return $claim;
        });

        $this->assert_refused_after_destroy($files, 'errorarchivefiliation');
    }

    public function test_a_filiation_going_round_in_a_circle_refuses_the_archive(): void {
        $newuuid = $this->new->uuid;
        $files = self::forge($this->build(), $this->old->uuid, function (array $claim) use ($newuuid): array {
            $claim['reportee_depuis'] = $newuuid;
            $claim['reportee_le'] = '2026-03-03T09:00:00Z';

            return $claim;
        });

        $this->assert_refused_after_destroy($files, 'errorarchivefiliation');
    }

    public function test_one_field_without_the_other_refuses_the_archive(): void {
        $files = self::forge($this->build(), $this->new->uuid, function (array $claim): array {
            unset($claim['reportee_le']);

            return $claim;
        });

        $this->assert_refused_after_destroy($files, 'errorarchiveschema');
    }

    public function test_a_claim_carried_over_from_itself_refuses_the_archive(): void {
        $uuid = $this->new->uuid;
        $files = self::forge($this->build(), $uuid, function (array $claim) use ($uuid): array {
            $claim['reportee_depuis'] = $uuid;

            return $claim;
        });

        $this->assert_refused_after_destroy($files, 'errorarchiveschema');
    }
}
