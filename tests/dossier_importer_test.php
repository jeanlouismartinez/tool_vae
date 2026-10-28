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

use tool_vae\event\dossier_imported;
use tool_vae\fixtures\failing_evidence_gateway;
use tool_vae\local\attestation_manager;
use tool_vae\local\competency_gateway;
use tool_vae\local\draft_manager;
use tool_vae\local\faisabilite_fields;
use tool_vae\local\dossier_importer;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\pdf_renderer;
use tool_vae\local\identity_map;
use tool_vae\local\purge;
use tool_vae\local\schema_validator;
use tool_vae\local\scope_manager;

/**
 * Tests for the reimport of a dossier - lot 8, second half, and criterion 6 as redefined with the
 * project owner on 12 October 2026.
 *
 * A blank instance cannot be had inside one test. Its nearest equivalent is used instead: the
 * dossier is exported, then destroyed with purge::dossier() - claims, attestations and the
 * candidate's entry in the identity map - and rebuilt from the archive. The tests of the register
 * lose the claims alone, since the purge takes the register's rows too - see lose_claims().
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_importer::class)]
final class dossier_importer_test extends \advanced_testcase {
    /** @var int 1 September 2025, 00:00 UTC. */
    private const PERIODSTART = 1756684800;

    /** @var int 30 June 2026, 00:00 UTC. */
    private const PERIODEND = 1782777600;

    /** @var \stdClass Declares and exports their own dossier. */
    private $candidate;

    /** @var \stdClass Follows the candidate and attests. */
    private $manager;

    /** @var \stdClass Holds nothing. */
    private $stranger;

    /** @var int The competency of the record seeded in setUp(). */
    private $competencyid;

    /**
     * A record, a candidate and the manager who follows them.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;

        $this->resetAfterTest();
        require_once(__DIR__ . '/fixtures/failing_evidence_gateway.php');

        $generator = $this->getDataGenerator();
        $generator->get_plugin_generator('tool_vae')->create_source([
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
        ]);
        $this->competencyid = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RNCP36832-C03'], MUST_EXIST);

        $system = \context_system::instance()->id;

        $candidaterole = $generator->create_role();
        foreach (['declare', 'viewown', 'export', 'draftown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $candidaterole, $system, true);
        }
        $this->candidate = $generator->create_user();
        role_assign($candidaterole, $this->candidate->id, \context_user::instance($this->candidate->id)->id);

        $managerrole = $generator->create_role();
        foreach (['viewassigned', 'attest'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $managerrole, $system, true);
        }
        set_role_contextlevels($managerrole, [CONTEXT_USER]);
        $this->manager = $generator->create_user();
        role_assign($managerrole, $this->manager->id, \context_user::instance($this->candidate->id)->id);

        $this->stranger = $generator->create_user();

        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Two declarations: one attested, one attested then withdrawn.
     */
    private function seed(): void {
        $first = $this->declare('ehpad');
        $this->attest($first);

        $second = $this->declare('domicile');
        $attestation = $this->attest($second);
        // Withdrawing is the site administrator's act alone (12 October 2026).
        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $attestation->id, 'erreur');
        $this->setUser($this->manager);
    }

    /**
     * One declaration by the candidate.
     *
     * @param string $setting
     * @return \stdClass
     */
    private function declare(string $setting): \stdClass {
        $this->setUser($this->candidate);

        return (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => $this->competencyid,
            'periodstart' => self::PERIODSTART,
            'periodend' => self::PERIODEND,
            'settingtype' => $setting,
            'settingdetail' => 'Equipe de jour',
            'situation' => 'Accompagnement au lever, recherche de son accord a chaque geste.',
            'situationformat' => FORMAT_MARKDOWN,
        ]);
    }

    /**
     * Attest a claim as the manager.
     *
     * @param \stdClass $claim
     * @return \stdClass
     */
    private function attest(\stdClass $claim): \stdClass {
        $this->setUser($this->manager);

        return (new attestation_manager())->attest((int) $claim->id, (object) [
            'attestantrole' => 'Cadre de sante, EHPAD Les Tilleuls',
            'statement' => 'Je soussignee Nadia Benali atteste avoir encadre le candidat.',
        ]);
    }

    /**
     * The files of the candidate's archive.
     *
     * Claims are also written to TOOL_VAE_SCHEMA_DUMP when set, so that ajv checks the
     * re-export of an imported dossier as it checks a first export.
     *
     * @return string[]
     */
    private function build(): array {
        global $DB;

        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');
        $files = (new exporter())->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time());

        $dump = getenv('TOOL_VAE_SCHEMA_DUMP');
        if ($dump) {
            check_dir_exists($dump, true, true);
            foreach ($files as $path => $content) {
                if (str_starts_with($path, 'preuves/')) {
                    file_put_contents($dump . '/reimport-' . basename($path), $content);
                }
            }
        }

        return $files;
    }

    /**
     * The claims of an archive, decoded, without the one array a change of platform may alter.
     *
     * @param string[] $files
     * @return array Path => claim.
     */
    private static function claims(array $files): array {
        $out = [];
        foreach ($files as $path => $content) {
            if (str_starts_with($path, 'preuves/')) {
                $claim = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                unset($claim['correspondances']);
                $out[$path] = $claim;
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Replace a file of an archive, with a manifest that vouches for the new content.
     *
     * What a forger would do: the digest then matches, and only the checks behind it remain.
     *
     * @param string[] $files
     * @param string $path
     * @param string $content
     * @return string[]
     */
    private static function forge(array $files, string $path, string $content): array {
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
     * Rewrite one claim of an archive through a callback, digest included.
     *
     * @param string[] $files
     * @param callable $change Receives the decoded claim, returns it changed.
     * @return string[]
     */
    private static function forge_claim(array $files, callable $change): array {
        foreach ($files as $path => $content) {
            if (str_starts_with($path, 'preuves/')) {
                $claim = $change(json_decode($content, true, 512, JSON_THROW_ON_ERROR));

                return self::forge($files, $path, json_encode($claim, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        throw new \coding_exception('The archive holds no claim.');
    }

    /**
     * Destroy the candidate's dossier, as a blank instance would not hold it.
     */
    private function destroy(): void {
        purge::dossier((int) $this->candidate->id);
    }

    /**
     * Destroy the candidate's claims and nothing else, as deleting their evidence records does.
     *
     * The register verifies an archive only while this site still knows its candidate. The purge
     * of a dossier takes the register's rows with the identity map entry (12 October 2026), so
     * after destroy() an archive this platform issued comes back foreign. Losing the claims one
     * record at a time keeps the person and the register, which is what verification needs.
     */
    private function lose_claims(): void {
        global $DB;

        $userevidenceids = $DB->get_fieldset_select(
            'tool_vae_evidence',
            'userevidenceid',
            'candidateid = ?',
            [$this->candidate->id]
        );
        foreach ($userevidenceids as $userevidenceid) {
            purge::by_userevidence((int) $userevidenceid);
        }
    }

    /**
     * Import as the site administrator, and expect a refusal.
     *
     * @param int $candidateid
     * @param string[] $files
     * @param string $errorcode
     */
    private function assert_refused(int $candidateid, array $files, string $errorcode): void {
        $this->setAdminUser();

        try {
            (new dossier_importer())->import_files($candidateid, $files);
            $this->fail("The archive was accepted; $errorcode was expected.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * A zip archive on disk holding the given entries, each name written as given.
     *
     * @param string[] $entries Name => content.
     * @return string The path of the archive.
     */
    private static function zip(array $entries): string {
        $path = make_request_directory() . '/archive.zip';

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString((string) $name, $content);
        }
        $zip->close();

        return $path;
    }

    /**
     * Read an archive from disk, and expect a refusal before anything reaches the checks behind.
     *
     * @param string $path
     * @param string $errorcode
     */
    private function assert_archive_refused(string $path, string $errorcode): void {
        try {
            dossier_importer::read_archive($path);
            $this->fail("The archive was read; $errorcode was expected.");
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
    }

    /**
     * Rows of this plugin held for an account: claims, and its entry in the identity map.
     *
     * @param int $userid
     * @return int
     */
    private static function rows_for(int $userid): int {
        global $DB;

        return $DB->count_records('tool_vae_evidence', ['candidateid' => $userid])
            + $DB->count_records(identity_map::TABLE, ['itemtype' => identity_map::TYPE_USER, 'itemid' => $userid]);
    }

    /**
     * The files of an archive this platform issued: exported as the candidate, hence registered.
     *
     * build() goes round the register - exporter::build() only assembles the files - so what it
     * returns reads, on reimport, as an archive from another platform.
     *
     * @return string[]
     */
    private function exported(): array {
        $this->setUser($this->candidate);
        $file = (new exporter())->export((int) $this->candidate->id);
        $path = make_request_directory() . '/archive.zip';
        $file->copy_content_to($path);

        return dossier_importer::read_archive($path);
    }

    /**
     * Import as the site administrator and return the dossier_imported event.
     *
     * @param string[] $files
     * @return dossier_imported
     */
    private function import_logged(array $files): dossier_imported {
        $sink = $this->redirectEvents();
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);
        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof dossier_imported;
        }));
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertSame((bool) $events[0]->other['foreign'], $result->foreign);

        return $events[0];
    }

    /**
     * An archive this platform issued, untouched, comes back verified by the register.
     */
    public function test_an_untouched_archive_of_this_platform_comes_back_verified(): void {
        $this->seed();
        $files = $this->exported();
        $this->lose_claims();

        $event = $this->import_logged($files);

        $this->assertSame(0, $event->other['foreign']);
        $this->assertSame(2, $event->other['declarations']);
    }

    /**
     * THE ATTACK OF FINDING C1. A claim is altered and its digest recomputed in the manifest: every
     * digest agrees, the schema is satisfied, and before the register the archive went in. The
     * manifest no longer being the one issued, it is refused whole and nothing is written.
     */
    public function test_a_claim_altered_with_its_digest_recomputed_is_refused_by_the_register(): void {
        $this->seed();
        $files = self::forge_claim($this->exported(), function (array $claim): array {
            $claim['situation']['texte'] = 'Encadrement d\'une equipe de nuit, seul responsable du service.';
            return $claim;
        });
        $this->lose_claims();
        $before = self::rows_for((int) $this->candidate->id);

        $this->assert_refused((int) $this->candidate->id, $files, 'errorarchivenotasissued');
        $this->assertSame($before, self::rows_for((int) $this->candidate->id));
    }

    /**
     * The manifest is covered too: changing the candidate's name, or the date of the export, in it
     * refuses the archive, though no digest of the manifest names those fields.
     */
    public function test_a_rewritten_manifest_is_refused_by_the_register(): void {
        $this->seed();
        $files = $this->exported();
        $this->lose_claims();

        foreach (['nom' => 'Quelqu\'un d\'autre', 'exporte_le' => '2020-01-01T00:00:00Z'] as $field => $value) {
            $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
            if ($field === 'nom') {
                $manifest['candidat']['nom'] = $value;
            } else {
                $manifest[$field] = $value;
            }
            $forged = $files;
            $forged['manifeste.json'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $this->assert_refused((int) $this->candidate->id, $forged, 'errorarchivenotasissued');
        }
    }

    /**
     * An archive the register does not know - another platform's, or one whose ULID a forger
     * changed - is accepted, as decided with the project owner, and flagged in the result and the
     * log. No row is written for it.
     */
    public function test_an_archive_this_platform_did_not_issue_is_accepted_and_flagged(): void {
        global $DB;

        $this->seed();
        $files = $this->exported();
        $DB->delete_records(exporter::REGISTER);
        $this->destroy();

        $event = $this->import_logged($files);

        $this->assertSame(1, $event->other['foreign']);
        $this->assertSame(0, $DB->count_records(exporter::REGISTER));
    }

    public function test_an_exported_dossier_comes_back_with_the_same_claims_and_identifiers(): void {
        $this->seed();
        $before = $this->build();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->candidate->id);

        $this->destroy();
        $this->assertSame(0, self::rows_for((int) $this->candidate->id));

        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $before);

        $this->assertSame(2, $result->declarations);
        $this->assertSame(2, $result->attestations);
        $this->assertSame($personne, identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->candidate->id));

        // Criterion 6: the same claims, the same ULIDs, signatory included. Only correspondances[]
        // may differ - the signatory holds no account on the platform the dossier now lives on.
        $this->assertEquals(self::claims($before), self::claims($this->build()));
    }

    public function test_one_altered_byte_refuses_the_archive_and_writes_nothing(): void {
        $this->seed();
        $files = $this->build();
        $this->destroy();

        foreach (array_keys($files) as $path) {
            if (str_starts_with($path, 'preuves/')) {
                $files[$path] = str_replace('lever', 'Lever', $files[$path]);
                break;
            }
        }

        $this->assert_refused((int) $this->candidate->id, $files, 'errorarchivedigest');
        $this->assertSame(0, self::rows_for((int) $this->candidate->id));
    }

    /**
     * The printable copy travels with the archive and is never imported as a claim.
     */
    public function test_the_printable_copy_is_accepted_and_not_imported(): void {
        global $DB;

        $this->seed();
        $files = $this->build();
        $this->assertArrayHasKey(pdf_renderer::PATH, $files);
        $this->destroy();

        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(2, $result->declarations);
        $this->assertSame(2, $DB->count_records('tool_vae_evidence', ['candidateid' => $this->candidate->id]));
    }

    /**
     * A printable copy changed after the export refuses the archive, as any listed file would.
     */
    public function test_an_altered_printable_copy_refuses_the_archive(): void {
        $this->seed();
        $files = $this->build();
        $this->destroy();

        $files[pdf_renderer::PATH] .= '%';

        $this->assert_refused((int) $this->candidate->id, $files, 'errorarchivedigest');
        $this->assertSame(0, self::rows_for((int) $this->candidate->id));
    }

    /**
     * An archive produced before 0.10.0 carries no printable copy, and still reimports.
     */
    public function test_an_archive_without_printable_copy_still_reimports(): void {
        $this->seed();
        $files = $this->build();
        $this->destroy();

        unset($files[pdf_renderer::PATH]);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $manifest['fichiers'] = array_values(array_filter($manifest['fichiers'], function (array $entry): bool {
            return $entry['chemin'] !== pdf_renderer::PATH;
        }));
        $files['manifeste.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(2, $result->declarations);
        $this->assertSame(2, $result->attestations);
    }

    public function test_a_file_the_manifest_does_not_list_refuses_the_archive(): void {
        $this->seed();
        $files = $this->build();
        $this->destroy();

        $files['preuves/notes.txt'] = 'Ajoute a la main.';

        $this->assert_refused((int) $this->candidate->id, $files, 'errorarchivefiles');
    }

    public function test_a_file_the_manifest_lists_and_the_archive_lacks_refuses_it(): void {
        $this->seed();
        $files = $this->build();
        $this->destroy();

        unset($files['referentiel.json']);

        $this->assert_refused((int) $this->candidate->id, $files, 'errorarchivefiles');
    }

    public function test_a_claim_the_schema_refuses_is_refused_even_with_a_matching_digest(): void {
        $this->seed();
        $files = self::forge_claim($this->build(), function (array $claim): array {
            $claim['situation']['format'] = 'html';
            return $claim;
        });
        $this->destroy();

        $this->assert_refused((int) $this->candidate->id, $files, 'errorarchiveschema');
    }

    public function test_a_competency_this_site_does_not_hold_refuses_the_archive(): void {
        $this->seed();
        $files = self::forge_claim($this->build(), function (array $claim): array {
            $claim['fiche']['rang'] = 'C99';
            return $claim;
        });
        $this->destroy();

        $this->assert_refused((int) $this->candidate->id, $files, 'errorarchivenocompetency');
        $this->assertSame(0, self::rows_for((int) $this->candidate->id));
    }

    public function test_an_identifier_already_here_refuses_the_archive_and_merges_nothing(): void {
        $this->seed();
        $files = $this->build();

        // Not destroyed: the claims of the archive are still on this site.
        $this->assert_refused((int) $this->stranger->id, $files, 'errorarchiveexists');
        $this->assertSame(0, self::rows_for((int) $this->stranger->id));
    }

    public function test_an_account_known_under_another_identifier_refuses_the_archive(): void {
        global $DB;

        $this->seed();
        $files = $this->build();
        $this->destroy();

        identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->stranger->id);

        // Refused inside the transaction, after the checks: nothing of the archive stays.
        $this->assert_refused((int) $this->stranger->id, $files, 'errorarchiveidentityheld');
        $this->assertSame(0, $DB->count_records('tool_vae_evidence', ['candidateid' => $this->stranger->id]));
    }

    /**
     * What this site holds for a competency, changed to an identifier of its own.
     *
     * The state of a site that has already exported, backed up or copied a dossier on the competency: the first of them mints
     * an identifier for it (exporter::preuve()), and the archive of another platform gives the same competency another.
     *
     * @return string The identifier the archive gives the competency.
     */
    private function give_the_competency_an_identifier_of_this_site(): string {
        global $DB;

        $archives = identity_map::uuid_for(identity_map::TYPE_COMPETENCY, $this->competencyid);
        $DB->set_field(
            identity_map::TABLE,
            'uuid',
            identity_map::generate_ulid(),
            ['itemtype' => identity_map::TYPE_COMPETENCY, 'itemid' => $this->competencyid]
        );

        return $archives;
    }

    /**
     * Run the checks of an archive alone, as the administrator, and expect a refusal.
     *
     * check() writes nothing: a refusal that comes from it is a refusal that came before the transaction.
     *
     * @param string[] $files
     * @param string $errorcode
     * @return \moodle_exception
     */
    private function assert_check_refuses(array $files, string $errorcode): \moodle_exception {
        $this->setAdminUser();

        try {
            (new dossier_importer())->check($files);
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);

            return $e;
        }
        $this->fail("check() let the archive through; $errorcode was expected before anything is written.");
    }

    /**
     * THE MIGRATION OF FINDING 23. An archive from another platform names a competency this site already knows under its own
     * identifier: it cannot be given a second one, and the refusal has to say what it is.
     *
     * It came last, inside the transaction, after every other check, and said `this account is already known under another
     * identifier` of a competency, sending the administrator to choose another account for nothing.
     */
    public function test_a_competency_this_site_knows_under_another_identifier_is_named_before_anything_is_written(): void {
        $this->seed();
        $files = $this->build();
        $this->destroy();
        $this->give_the_competency_an_identifier_of_this_site();

        $e = $this->assert_check_refuses($files, 'errorarchivecompetencyheld');
        $this->assertStringContainsString('RNCP36832-C03', $e->getMessage());
        // And the name this site gives that competency stands beside the code (27 October 2026): the generator's, here.
        $this->assertStringContainsString('Competency of an imported record', $e->getMessage());
        // Two declarations stand on it in this archive, and it is named once.
        $this->assertSame(1, substr_count($e->getMessage(), 'RNCP36832-C03'));

        // And the import says the same, writes nothing, and does not so much as give the account its identifier.
        $this->assert_refused((int) $this->candidate->id, $files, 'errorarchivecompetencyheld');
        $this->assertSame(0, self::rows_for((int) $this->candidate->id));
    }

    /**
     * The same, for an archive this platform issued itself and the register vouches for.
     */
    public function test_an_archive_of_this_platform_naming_a_competency_under_another_identifier_is_refused_by_name(): void {
        $this->seed();
        $files = $this->exported();
        $this->lose_claims();
        $this->give_the_competency_an_identifier_of_this_site();

        $this->assert_check_refuses($files, 'errorarchivecompetencyheld');
    }

    /**
     * An identifier the archive gives a competency, that this site has already given to something else, is refused the same way.
     */
    public function test_an_identifier_this_site_gave_to_something_else_is_refused_before_anything_is_written(): void {
        global $DB;

        $this->seed();
        $files = $this->build();
        $this->destroy();
        $archives = identity_map::uuid_for(identity_map::TYPE_COMPETENCY, $this->competencyid);

        // The competency holds none here, and the identifier is an account's.
        $DB->delete_records(identity_map::TABLE, ['itemtype' => identity_map::TYPE_COMPETENCY, 'itemid' => $this->competencyid]);
        $DB->insert_record(identity_map::TABLE, (object) [
            'itemtype' => identity_map::TYPE_USER,
            'itemid' => (int) $this->stranger->id,
            'uuid' => $archives,
            'timecreated' => time(),
        ]);

        $this->assert_check_refuses($files, 'errorarchiveidentitytaken');
    }

    /**
     * The record code of an archive's hors_activite.json is the archive's own, and the refusal that names it is shown as HTML.
     *
     * The schema holds that field to a length and nothing else, so `<a href=http://x.io>x` (21 characters) is a record code to
     * it. Two entries alike refuse the file, and the refusal carried the code as it came: a link in the notification of the
     * administrator who imports. Every other message of this class escapes what it takes from the archive.
     */
    public function test_a_record_code_of_the_archive_is_escaped_in_the_refusal(): void {
        set_config('scopeopen', 1, 'tool_vae');
        $this->declare('ehpad');
        scope_manager::mark((int) $this->candidate->id, 'RNCP36832', 'C03');
        $files = $this->build();

        $document = json_decode($files[scope_manager::PATH], true, 512, JSON_THROW_ON_ERROR);
        $document['competences'][0]['fiche'] = '<a href=http://x.io>x';
        $document['competences'][1] = $document['competences'][0];
        $files = self::forge(
            $files,
            scope_manager::PATH,
            json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $this->destroy();

        $this->setAdminUser();
        try {
            (new dossier_importer())->import_files((int) $this->candidate->id, $files);
            $this->fail('The archive was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorarchivescope', $e->errorcode);
            $this->assertStringNotContainsString('<a', (string) $e->a);
            $this->assertStringContainsString('&lt;a href', (string) $e->a);
        }
    }

    /**
     * An import asks for the memory the archive will take before it reads it: the request starts at the site's standard 128 MB.
     */
    public function test_the_import_raises_the_memory_limit_before_it_reads_the_archive(): void {
        $limit = memory_get_usage(true) + 64 * 1048576;
        // A process that already uses 320 MB or more starts above the limit the import asks for: whatever it raised, or nothing,
        // the assertion below would hold, and the test would prove nothing about raise_memory_limit(MEMORY_EXTRA).
        if ($limit >= get_real_size('384M')) {
            $this->markTestSkipped('The process already uses too much memory to tell the starting limit from the raised one.');
        }
        $original = ini_get('memory_limit');
        // What a request starts at, as near as a running test allows: a limit under what is in use now is refused by PHP.
        ini_set('memory_limit', (string) $limit);

        try {
            try {
                (new dossier_importer())->import((int) $this->candidate->id, make_request_directory() . '/absent.zip');
                $this->fail('An archive that is not there was read.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errorarchiveunreadable', $e->errorcode);
            }

            $this->assertGreaterThanOrEqual(get_real_size('384M'), get_real_size((string) ini_get('memory_limit')));
        } finally {
            ini_set('memory_limit', (string) $original);
        }
    }

    /**
     * The copy of the upload is taken away on every way out the page can see, the unforeseen ones included.
     *
     * The page deleted it after a `catch (moodle_exception)` and nothing else: any other exception left the whole dossier,
     * pieces included, in the temporary folder of the core for a week. A fatal error cannot be provoked in a test - the process
     * would die; the removal registered with the shutdown functions of the core is what answers to it.
     */
    public function test_the_temporary_copy_is_removed_whatever_the_import_dies_of(): void {
        // The importer's transaction is nested in the harness's, and its rollback waits for the end of the test: without this
        // the identity row the write has already given the candidate is still there, correct code or not.
        $this->preventResetByRollback();
        $this->declare('ehpad');
        $this->setUser($this->candidate);
        $file = (new exporter())->export((int) $this->candidate->id);
        $path = make_request_directory() . '/archive.zip';
        $file->copy_content_to($path);
        $this->destroy();

        // A failure that is not a moodle_exception: the one the page's catch did not stop.
        $gateway = new class extends competency_gateway {
            /**
             * Fail as something else than a moodle_exception.
             *
             * @param int $candidateid
             * @param string $generatedlabel
             * @return int
             */
            public function create_evidence(int $candidateid, string $generatedlabel): int {
                throw new \RuntimeException('Deliberate failure mid-import.');
            }
        };
        $this->setAdminUser();
        try {
            (new dossier_importer($gateway))->import_upload((int) $this->candidate->id, $path);
            $this->fail('The import went through.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Deliberate failure mid-import.', $e->getMessage());
        }

        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, self::rows_for((int) $this->candidate->id));
    }

    /**
     * And after a good import, and after a refusal.
     */
    public function test_the_temporary_copy_is_removed_after_an_import_and_after_a_refusal(): void {
        $this->declare('ehpad');
        $this->setUser($this->candidate);
        $file = (new exporter())->export((int) $this->candidate->id);
        $path = make_request_directory() . '/archive.zip';
        $file->copy_content_to($path);
        // Both copies before the purge, which takes the stored archive with the dossier.
        $again = make_request_directory() . '/archive.zip';
        $file->copy_content_to($again);
        $this->destroy();

        $this->setAdminUser();
        $result = (new dossier_importer())->import_upload((int) $this->candidate->id, $path);
        $this->assertSame(1, $result->declarations);
        $this->assertFileDoesNotExist($path);

        // The archive is now here: a second import is refused for it, and the copy goes all the same.
        try {
            (new dossier_importer())->import_upload((int) $this->candidate->id, $again);
            $this->fail('The archive was imported twice.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorarchiveexists', $e->errorcode);
        }
        $this->assertFileDoesNotExist($again);
    }

    /**
     * What no archive carries comes back from none: the notice after an import says so, and says what.
     *
     * The deposits of the dossier de faisabilité, the opinions on them and the questions for clarification with their answers
     * are in no archive, by decision (README: an opinion is a third party's text on a milestone the archive does not carry
     * either), and an uninstall drops their tables after a backup that never held them. Asserted here, so that the day one of
     * them starts to travel, the notice that says it does not is found false by a test and not by an administrator.
     */
    public function test_deposits_opinions_and_questions_do_not_come_back_with_an_archive(): void {
        global $DB;

        $this->seed();
        $where = ['candidateid' => $this->candidate->id, 'settingtype' => 'ehpad'];
        $claim = $DB->get_record('tool_vae_evidence', $where, '*', MUST_EXIST);
        $now = time();
        $depositid = $DB->insert_record('tool_vae_deposit', (object) [
            'uuid' => identity_map::generate_ulid(),
            'candidateid' => (int) $this->candidate->id,
            'doctype' => draft_manager::DOCTYPE_FAISABILITE,
            'number' => 1,
            'content' => '{}',
            'contenthash' => hash('sha256', '{}'),
            'timecreated' => $now,
        ]);
        $DB->insert_record('tool_vae_opinion', (object) [
            'uuid' => identity_map::generate_ulid(),
            'depositid' => $depositid,
            'authorid' => (int) $this->manager->id,
            'content' => 'Un avis du responsable.',
            'contenthash' => hash('sha256', 'Un avis du responsable.'),
            'timecreated' => $now,
        ]);
        $DB->insert_record('tool_vae_request', (object) [
            'uuid' => identity_map::generate_ulid(),
            'evidenceid' => (int) $claim->id,
            'authorid' => (int) $this->manager->id,
            'question' => 'Pouvez-vous preciser le service ?',
            'questionhash' => hash('sha256', 'Pouvez-vous preciser le service ?'),
            'timecreated' => $now,
            'answer' => 'Le service de nuit.',
            'answerhash' => hash('sha256', 'Le service de nuit.'),
            'timeanswered' => $now,
        ]);

        $files = $this->build();
        foreach ($files as $content) {
            $this->assertStringNotContainsString('Un avis du responsable.', $content);
            $this->assertStringNotContainsString('Pouvez-vous preciser le service ?', $content);
            $this->assertStringNotContainsString('Le service de nuit.', $content);
        }

        // As an uninstall and a reinstall would leave it: the dossier gone, with its deposits, opinions and questions.
        $this->destroy();
        $this->assertSame(0, $DB->count_records('tool_vae_deposit'));
        $this->setAdminUser();
        (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(0, $DB->count_records('tool_vae_deposit'));
        $this->assertSame(0, $DB->count_records('tool_vae_opinion'));
        $this->assertSame(0, $DB->count_records('tool_vae_request'));
    }

    /**
     * The page that follows an import tells what the count of what came back leaves out.
     *
     * The page redirects and cannot be run from here: what is asserted is the sentence, which names the three things, and that
     * the page asks for it.
     */
    public function test_the_page_after_an_import_says_what_stays_behind(): void {
        global $CFG;

        foreach (['importdossierabsent', 'importdossierintro'] as $identifier) {
            $text = get_string($identifier, 'tool_vae');
            $this->assertStringContainsString('deposits', $text, $identifier);
            $this->assertStringContainsString('opinions', $text, $identifier);
            $this->assertStringContainsString('questions', $text, $identifier);
        }

        $page = file_get_contents($CFG->dirroot . '/admin/tool/vae/import_dossier.php');
        $this->assertStringContainsString("get_string('importdossierabsent', 'tool_vae')", $page);
    }

    public function test_an_imported_attestation_is_withdrawn_by_the_site_administrator_only(): void {
        global $DB;

        $this->seed();
        $files = $this->build();
        $this->destroy();
        $this->setAdminUser();
        (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $standing = $DB->get_record_select('tool_vae_attest', 'withdrawnat IS NULL', [], '*', MUST_EXIST);
        $this->assertNull($standing->attestantid);
        $this->assertNotEmpty($standing->attestantuuid);
        $this->assertNotEmpty($standing->importedat);

        // The manager still follows the candidate and still holds attest: not enough.
        $this->setUser($this->manager);
        $this->assertFalse(attestation_manager::may_withdraw($standing));
        try {
            (new attestation_manager())->withdraw((int) $standing->id, 'contestation');
            $this->fail('A manager withdrew an attestation carried in from an archive.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorwithdraw', $e->errorcode);
        }

        $this->setAdminUser();
        $withdrawn = (new attestation_manager())->withdraw((int) $standing->id, 'contestation');
        $this->assertNotNull($withdrawn->withdrawnat);
        $this->assertNull($withdrawn->attestantuuid);
        $this->assertNull($withdrawn->statement);
    }

    public function test_the_import_is_logged_with_counts_and_no_content(): void {
        $this->seed();
        $files = $this->build();
        $this->destroy();

        $sink = $this->redirectEvents();
        $this->setAdminUser();
        (new dossier_importer())->import_files((int) $this->candidate->id, $files);
        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof dossier_imported;
        }));
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertSame((int) $this->candidate->id, (int) $events[0]->relateduserid);
        $this->assertSame(2, $events[0]->other['declarations']);
        $this->assertSame(2, $events[0]->other['attestations']);
        $this->assertStringNotContainsString('lever', json_encode($events[0]->get_data()));
    }

    public function test_the_zip_the_export_writes_is_the_zip_the_import_reads(): void {
        $this->declare('ehpad');
        $this->setUser($this->candidate);
        $file = (new exporter())->export((int) $this->candidate->id);
        $path = make_request_directory() . '/archive.zip';
        $file->copy_content_to($path);
        $this->destroy();

        $this->setAdminUser();
        $result = (new dossier_importer())->import((int) $this->candidate->id, $path);

        $this->assertSame(1, $result->declarations);
        $this->assertSame(0, $result->attestations);
    }

    /**
     * A path that climbs out of the archive, or starts at the root, refuses it.
     */
    public function test_an_entry_outside_the_archive_refuses_it(): void {
        foreach (['../preuves/evasion.json', '/tmp/evasion.json'] as $name) {
            $this->assert_archive_refused(self::zip(['manifeste.json' => '{}', $name => '{}']), 'errorarchivefiles');
        }
    }

    /**
     * Two entries under one name refuse the archive: which one to believe is not the importer's to choose.
     */
    public function test_two_entries_under_one_name_refuse_the_archive(): void {
        $path = self::zip(['manifeste.json' => '{}', 'manifestf.json' => '{}']);

        // ZipArchive will not write a name twice: the second is respelled in the bytes, in its
        // local header and in the central directory alike.
        file_put_contents($path, str_replace('manifestf.json', 'manifeste.json', file_get_contents($path)));

        $this->assert_archive_refused($path, 'errorarchivefiles');
    }

    /**
     * One file more than the cap refuses the archive before any file is read.
     */
    public function test_more_files_than_any_dossier_holds_refuse_the_archive(): void {
        $entries = [];
        for ($i = 0; $i <= dossier_importer::MAX_FILES; $i++) {
            $entries["preuves/f$i.json"] = '';
        }

        $this->assert_archive_refused(self::zip($entries), 'errorarchivetoolarge');
    }

    /**
     * An entry holding more than the size it declares refuses the archive.
     *
     * 1000 bytes declared as 10, in the local header and in the central directory, so that every
     * cap judged on the listing passes. A guard of the refusal only, NOT a proof of the memory
     * bound: the extraction this code replaced refused the same archive, after writing all of
     * it to disk. How much is read rests on the length read_archive() passes to getFromIndex(),
     * which nothing at this scale can observe.
     */
    public function test_an_entry_larger_than_it_declares_refuses_the_archive(): void {
        $path = self::zip(['manifeste.json' => str_repeat('x', 1000)]);
        $bytes = file_get_contents($path);
        $declared = pack('V', 10);

        // One entry, so its local header opens the file; the end record, the last 22 bytes,
        // gives the offset of the central directory. The uncompressed size sits 22 bytes into
        // the first and 24 into the second.
        $central = unpack('V', substr($bytes, -6, 4))[1];
        $bytes = substr_replace($bytes, $declared, 22, 4);
        $bytes = substr_replace($bytes, $declared, $central + 24, 4);
        file_put_contents($path, $bytes);

        $this->assert_archive_refused($path, 'errorarchiveunreadable');
    }

    /**
     * An entry declaring 2^63 bytes refuses the archive before any file is read.
     *
     * ZipArchive hands PHP such a size as a negative integer. Without a check of its own, the
     * running total would drop under the cap, and getFromIndex() would be asked for the whole
     * entry: a length under 1 means all of it.
     */
    public function test_an_entry_declaring_two_to_the_63_bytes_refuses_the_archive(): void {
        $path = self::zip(['manifeste.json' => '{}']);
        $bytes = file_get_contents($path);

        // A Zip64 entry: its 32-bit size in the central directory set to 0xFFFFFFFF, and the
        // Zip64 field that then carries the real size - header 1, 8 bytes, PHP_INT_MIN being
        // 2^63 unsigned - put before the other extra fields. The end record, the last 22 bytes,
        // gives the size of the central directory 12 bytes in and its offset 16 bytes in; the
        // one header it holds has the size 24 bytes in, the length of the name 28, that of the
        // extra fields 30, and its name from 46.
        $zip64 = pack('vvP', 1, 8, PHP_INT_MIN);
        $central = unpack('V', substr($bytes, -6, 4))[1];
        $namelength = unpack('v', substr($bytes, $central + 28, 2))[1];
        $extralength = unpack('v', substr($bytes, $central + 30, 2))[1];
        $centralsize = unpack('V', substr($bytes, -10, 4))[1];

        $bytes = substr_replace($bytes, pack('V', $centralsize + strlen($zip64)), -10, 4);
        $bytes = substr_replace($bytes, $zip64, $central + 46 + $namelength, 0);
        $bytes = substr_replace($bytes, pack('v', $extralength + strlen($zip64)), $central + 30, 2);
        $bytes = substr_replace($bytes, pack('V', 0xFFFFFFFF), $central + 24, 4);
        file_put_contents($path, $bytes);

        $this->assert_archive_refused($path, 'errorarchivetoolarge');
    }

    /**
     * Two attestations in force on one claim refuse the archive, and nothing of it is written.
     */
    public function test_two_attestations_in_force_on_one_claim_refuse_the_archive(): void {
        global $DB;

        $this->attest($this->declare('ehpad'));
        $files = self::forge_claim($this->build(), function (array $claim): array {
            $second = $claim['attestations'][0];
            $second['id'] = identity_map::generate_ulid();
            $claim['attestations'][] = $second;
            return $claim;
        });
        $this->destroy();

        $this->assert_refused((int) $this->candidate->id, $files, 'errorarchiveattestmany');
        $this->assertSame(0, self::rows_for((int) $this->candidate->id));
        $this->assertSame(0, $DB->count_records('tool_vae_attest'));
    }

    /**
     * A claim attested by its own candidate refuses the archive.
     */
    public function test_an_attestation_by_the_candidate_refuses_the_archive(): void {
        $this->attest($this->declare('ehpad'));
        $files = self::forge_claim($this->build(), function (array $claim): array {
            $claim['attestations'][0]['attestant'] = $claim['personne'];
            return $claim;
        });
        $this->destroy();

        $this->assert_refused((int) $this->candidate->id, $files, 'errorarchiveattestown');
        $this->assertSame(0, self::rows_for((int) $this->candidate->id));
    }

    /**
     * A failure on the second claim leaves no trace of the first, in this plugin or in the core.
     *
     * preventResetByRollback(), for the reason evidence_manager_test gives: without it the
     * importer's transaction is nested in the harness's, and its rollback waits for the end of
     * the test - the rows would still be there, correct code or not.
     */
    public function test_a_failure_on_the_second_claim_leaves_no_trace_of_the_first(): void {
        global $DB;

        $this->preventResetByRollback();
        $this->seed();
        $files = $this->build();
        $this->destroy();
        // The purge leaves the core evidence of the seed where it is: only what the import adds is counted.
        $coreevidence = $DB->count_records('competency_userevidence', ['userid' => $this->candidate->id]);

        $this->setAdminUser();
        try {
            (new dossier_importer(new failing_evidence_gateway(1)))->import_files((int) $this->candidate->id, $files);
            $this->fail('The substituted gateway must fail on the second claim.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('Deliberate failure', $e->getMessage());
        }

        $this->assertSame(0, self::rows_for((int) $this->candidate->id));
        $this->assertSame(0, $DB->count_records('tool_vae_attest'));
        $this->assertSame($coreevidence, $DB->count_records('competency_userevidence', ['userid' => $this->candidate->id]));
    }

    public function test_rebuilding_a_dossier_takes_two_capabilities(): void {
        $generator = $this->getDataGenerator();
        $system = \context_system::instance();
        $importer = $generator->create_user();
        $role = $generator->create_role();
        assign_capability('tool/vae:importdossier', CAP_ALLOW, $role, $system->id, true);
        role_assign($role, $importer->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($importer);

        // This plugin's capability alone: the core would refuse the first write halfway.
        $this->assertTrue(dossier_importer::can_import());
        try {
            dossier_importer::require_can_import((int) $this->candidate->id);
            $this->fail('The core right to write evidence for someone else was not asked for.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        assign_capability('moodle/competency:userevidencemanage', CAP_ALLOW, $role, $system->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertSame(
            \context_user::instance($this->candidate->id)->id,
            dossier_importer::require_can_import((int) $this->candidate->id)->id
        );

        $this->setUser($this->stranger);
        $this->assertFalse(dossier_importer::can_import());
    }

    /**
     * An id no account holds is refused exactly as an account the importer may not write for.
     *
     * Before, the missing id answered `record not found`: whoever held the plugin's capability
     * alone could tell which ids are accounts.
     */
    public function test_an_id_no_account_holds_is_refused_like_one_the_importer_may_not_write_for(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $system = \context_system::instance();
        $importer = $generator->create_user();
        $role = $generator->create_role();
        assign_capability('tool/vae:importdossier', CAP_ALLOW, $role, $system->id, true);
        role_assign($role, $importer->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($importer);

        $answers = [];
        foreach ([(int) $this->candidate->id, (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}') + 1] as $userid) {
            try {
                dossier_importer::require_can_import($userid);
                $this->fail('The importer was let through without the core right to write evidence.');
            } catch (\required_capability_exception $e) {
                // The link is a fresh moodle_url on each refusal, so compare what it points to.
                $answers[] = [$e->errorcode, $e->getMessage(), $e->link->out(false)];
            }
        }

        $this->assertSame($answers[0], $answers[1]);
    }

    public function test_the_manager_archetype_imports_neither_records_nor_dossiers(): void {
        global $DB;

        foreach (get_archetype_roles('manager') as $role) {
            foreach (['import', 'importdossier'] as $capability) {
                $this->assertFalse($DB->record_exists('role_capabilities', [
                    'roleid' => $role->id,
                    'capability' => 'tool/vae:' . $capability,
                ]), $capability);
            }
        }
    }

    /**
     * Write one section of the dossier de faisabilité as the candidate, and save a version.
     *
     * @param string $section
     * @param int $slot
     * @param string $content
     */
    private function write_section(string $section, int $slot, string $content): void {
        $this->setUser($this->candidate);
        $manager = new draft_manager();
        $draft = $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, $section, $slot);
        $manager->save_version((int) $draft->id, $content);
    }

    /**
     * An archive of a dossier with no section written carries no faisabilite.json: the layout is as before.
     */
    public function test_an_archive_without_sections_carries_no_faisabilite_file(): void {
        $this->seed();

        $files = $this->build();

        $this->assertArrayNotHasKey(exporter::FAISABILITE_PATH, $files);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('schema_faisabilite', $manifest);
    }

    /**
     * Text sections, latest saved version, no draft, and never identity: MC-13.
     */
    public function test_the_archive_carries_the_text_sections_and_never_the_identity(): void {
        $this->seed();
        $this->write_section('objectifs', 0, 'Un premier jet.');
        $this->write_section('objectifs', 0, 'Obtenir le diplome.');
        $this->write_section('experiences', 2, 'Accueil de jour.');
        $this->write_section('identite', 0, faisabilite_fields::encode('identite', ['nom_naissance' => 'Dupont-Secret']));
        // A draft in progress, no version: not in the archive.
        $manager = new draft_manager();
        $draft = $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'parcours');
        $manager->save_working((int) $draft->id, 'Brouillon-Secret');

        $files = $this->build();

        $document = json_decode($files[exporter::FAISABILITE_PATH], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [['objectifs', 0, 2, 'Obtenir le diplome.'], ['experiences', 2, 1, 'Accueil de jour.']],
            array_map(function (array $r): array {
                return [$r['code'], $r['emplacement'], $r['version'], $r['texte']];
            }, $document['rubriques'])
        );
        $this->assertSame([], (new schema_validator())->validate(
            json_decode($files[exporter::FAISABILITE_PATH]),
            'faisabilite.schema.json'
        ));

        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(exporter::FAISABILITE_SCHEMA_VERSION, $manifest['schema_faisabilite']);
        $this->assertContains(exporter::FAISABILITE_PATH, array_column($manifest['fichiers'], 'chemin'));
        $this->assertArrayHasKey('schema/faisabilite.schema.json', $files);
        foreach ($files as $path => $content) {
            if ($path !== pdf_renderer::PATH) {
                $this->assertStringNotContainsString('Dupont-Secret', $content, $path);
                $this->assertStringNotContainsString('Brouillon-Secret', $content, $path);
            }
        }
    }

    /**
     * The printed copy says the sections the file says, before the declarations.
     */
    public function test_the_printable_copy_reads_the_text_sections(): void {
        $this->seed();
        $this->write_section('objectifs', 0, 'Obtenir le diplome.');
        $files = $this->build();

        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $document = json_decode($files[exporter::FAISABILITE_PATH], true, 512, JSON_THROW_ON_ERROR);
        $preuves = [];
        foreach ($files as $path => $content) {
            if (str_starts_with($path, 'preuves/')) {
                $preuves[] = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            }
        }
        $referentiel = json_decode($files['referentiel.json'], true, 512, JSON_THROW_ON_ERROR);

        $html = pdf_renderer::html($manifest, $preuves, $referentiel, $document['rubriques']);

        $this->assertStringContainsString(get_string('exportpdffaisabilite', 'tool_vae'), $html);
        $this->assertStringContainsString('Obtenir le diplome.', $html);
        $this->assertLessThan(strpos($html, 'RNCP36832'), strpos($html, 'Obtenir le diplome.'));
        $this->assertStringNotContainsString(
            get_string('exportpdffaisabilite', 'tool_vae'),
            pdf_renderer::html($manifest, $preuves, $referentiel)
        );
    }

    /**
     * The sections come back as their version 1, with a note naming the archive.
     */
    public function test_the_text_sections_come_back_as_version_one(): void {
        global $DB;

        $this->seed();
        $this->write_section('objectifs', 0, 'Un premier jet.');
        $this->write_section('objectifs', 0, 'Obtenir le diplome.');
        $this->write_section('experiences', 1, 'Aide a domicile.');
        $files = $this->build();
        $this->destroy();
        $this->assertSame(0, $DB->count_records('tool_vae_draft', ['candidateid' => $this->candidate->id]));

        $event = $this->import_logged($files);

        $this->assertSame(2, $event->other['rubriques']);
        $draft = $DB->get_record('tool_vae_draft', [
            'candidateid' => $this->candidate->id,
            'section' => 'objectifs',
            'slot' => 0,
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int) $draft->currentversion);
        $versions = $DB->get_records('tool_vae_version', ['draftid' => $draft->id]);
        $this->assertCount(1, $versions);
        $version = reset($versions);
        $this->assertSame('Obtenir le diplome.', $version->content);
        $this->assertStringContainsString($event->other['archive'], (string) $version->note);
        $this->assertNull($version->restoredfrom);
        $this->assertSame(2, $DB->count_records('tool_vae_draft', ['candidateid' => $this->candidate->id]));
    }

    /**
     * A section already written on this site is the living one: the archive does not overwrite it.
     */
    public function test_a_section_already_written_here_is_left_as_it_is(): void {
        global $DB;

        $this->seed();
        $this->write_section('objectifs', 0, 'Ce que disait l archive.');
        $this->write_section('parcours', 0, 'Le parcours prevu.');
        $files = $this->build();
        $this->destroy();
        $this->write_section('objectifs', 0, 'Ce que dit ce site.');

        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(1, $result->rubriques);
        $this->assertSame(1, $result->rubriquesgardees);
        $draft = $DB->get_record('tool_vae_draft', [
            'candidateid' => $this->candidate->id,
            'section' => 'objectifs',
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int) $draft->currentversion);
        $this->assertSame(
            'Ce que dit ce site.',
            $DB->get_field('tool_vae_version', 'content', ['draftid' => $draft->id, 'number' => 1])
        );
    }

    /**
     * A section holding a draft in progress and no saved version receives the archive's version 1 and
     * keeps its draft: the living dossier is not overwritten (T-44).
     */
    public function test_a_section_with_only_a_draft_receives_the_version_and_keeps_the_draft(): void {
        global $DB;

        $this->seed();
        $this->write_section('objectifs', 0, 'Ce que disait l archive.');
        $files = $this->build();
        $this->destroy();

        $this->setUser($this->candidate);
        $manager = new draft_manager();
        $draft = $manager->section((int) $this->candidate->id, draft_manager::DOCTYPE_FAISABILITE, 'objectifs');
        $manager->save_working((int) $draft->id, 'Un brouillon en cours.');

        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(1, $result->rubriques);
        $this->assertSame(0, $result->rubriquesgardees);
        $row = $DB->get_record('tool_vae_draft', ['id' => $draft->id], '*', MUST_EXIST);
        $this->assertSame('Un brouillon en cours.', $row->working);
        $this->assertSame(1, (int) $row->currentversion);
        $this->assertSame(
            'Ce que disait l archive.',
            $DB->get_field('tool_vae_version', 'content', ['draftid' => $draft->id, 'number' => 1])
        );
    }

    /**
     * What the schema cannot say, the catalogue does: identity, an address it does not hold, a twin.
     */
    public function test_a_faisabilite_file_naming_what_it_must_not_is_refused(): void {
        $this->seed();
        $this->write_section('objectifs', 0, 'Obtenir le diplome.');
        $files = $this->build();
        $this->destroy();

        $row = static function (string $code, int $slot): array {
            return [
                'code' => $code,
                'emplacement' => $slot,
                'version' => 1,
                'enregistree_le' => '2026-10-10T08:30:00Z',
                'texte' => 'Texte.',
            ];
        };
        $cases = [
            'identity' => [$row('identite', 0)],
            'account' => [$row('contact', 0)],
            'unknown code' => [$row('inconnue', 0)],
            'slot beyond the limit' => [$row('experiences', 11)],
            'slot zero of a repeated section' => [$row('experiences', 0)],
            'slot of a single section' => [$row('objectifs', 1)],
            'twin' => [$row('objectifs', 0), $row('objectifs', 0)],
        ];
        foreach ($cases as $name => $rubriques) {
            $forged = self::forge(
                $files,
                exporter::FAISABILITE_PATH,
                json_encode(['rubriques' => $rubriques], JSON_UNESCAPED_UNICODE)
            );
            $this->assert_refused((int) $this->candidate->id, $forged, 'errorarchivefaisabilite');
        }

        $broken = $row('objectifs', 0);
        unset($broken['texte']);
        $forged = self::forge(
            $files,
            exporter::FAISABILITE_PATH,
            json_encode(['rubriques' => [$broken]], JSON_UNESCAPED_UNICODE)
        );
        $this->assert_refused((int) $this->candidate->id, $forged, 'errorarchivefaisabilite');
        $forged = self::forge($files, exporter::FAISABILITE_PATH, '{');
        $this->assert_refused((int) $this->candidate->id, $forged, 'errorarchivefaisabilite');

        $this->assertSame(0, self::rows_for((int) $this->candidate->id));
    }
}
