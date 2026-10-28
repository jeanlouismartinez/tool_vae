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
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tool_vae\event\dossier_exported;
use tool_vae\local\access;
use tool_vae\local\attestation_manager;
use tool_vae\local\evidence_manager;
use tool_vae\local\dossier_importer;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\pdf_renderer;
use tool_vae\local\purge;
use tool_vae\privacy\provider;

/**
 * Tests for the normative export - lot 8, MC-1 and MC-9 of DER-referentiel-001.
 *
 * The claims built here are also checked against the schema itself, by ajv, whenever the
 * environment variable TOOL_VAE_SCHEMA_DUMP names a directory: the continuous
 * integration sets it, then validates what lands there. PHP has no JSON Schema validator, and
 * restating the schema's rules here in PHP would be one more copy to drift.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\pdf_renderer::class)]
final class exporter_test extends \advanced_testcase {
    /** @var string A narrative, anonymised as the reminder asks. */
    private const SITUATION = 'Accompagnement au lever, recherche de son accord a chaque geste.';

    /** @var string Position and establishment of the signatory. */
    private const ROLE = 'Cadre de sante, EHPAD Les Tilleuls';

    /** @var string The signed wording, which names its author as real ones do. */
    private const STATEMENT = 'Je soussignee Nadia Benali atteste avoir encadre le candidat.';

    /** @var int 1 September 2025, 00:00 UTC. */
    private const PERIODSTART = 1756684800;

    /** @var int 30 June 2026, 00:00 UTC. */
    private const PERIODEND = 1782777600;

    /** @var \stdClass Declares, reads and exports their own dossier. */
    private $candidate;

    /** @var \stdClass Follows the candidate and attests; holds export too, which must not suffice. */
    private $manager;

    /** @var \stdClass Reads and exports every dossier. */
    private $director;

    /** @var int The director's role. */
    private $directorrole;

    /** @var \stdClass Holds nothing. */
    private $stranger;

    /** @var int The competency of the record seeded in setUp(). */
    private $competencyid;

    /**
     * A record, a candidate, the manager who follows them, the direction and a stranger.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $generator->get_plugin_generator('tool_vae')->create_source([
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
        ]);
        $this->competencyid = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RNCP36832-C03'], MUST_EXIST);

        $system = \context_system::instance()->id;

        $candidaterole = $generator->create_role();
        foreach (['declare', 'viewown', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $candidaterole, $system, true);
        }
        $this->candidate = $generator->create_user(['firstname' => 'Camille', 'lastname' => 'Martin']);
        role_assign($candidaterole, $this->candidate->id, \context_user::instance($this->candidate->id)->id);

        $managerrole = $generator->create_role();
        foreach (['viewassigned', 'attest', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $managerrole, $system, true);
        }
        set_role_contextlevels($managerrole, [CONTEXT_USER]);
        $this->manager = $generator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        role_assign($managerrole, $this->manager->id, \context_user::instance($this->candidate->id)->id);

        $this->directorrole = $generator->create_role();
        foreach (['viewany', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $this->directorrole, $system, true);
        }
        $this->director = $generator->create_user();
        role_assign($this->directorrole, $this->director->id, $system);

        $this->stranger = $generator->create_user();

        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * One declaration by the candidate.
     *
     * @return \stdClass The stored claim.
     */
    private function declare(): \stdClass {
        global $DB;

        $this->setUser($this->candidate);
        $claim = (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => $this->competencyid,
            'periodstart' => self::PERIODSTART,
            'periodend' => self::PERIODEND,
            'settingtype' => 'ehpad',
            'settingdetail' => 'Unite protegee, equipe de jour',
            'situation' => self::SITUATION,
            'situationformat' => FORMAT_MARKDOWN,
        ]);

        return $DB->get_record('tool_vae_evidence', ['id' => $claim->id], '*', MUST_EXIST);
    }

    /**
     * Attest a claim as the manager.
     *
     * @param \stdClass $claim
     * @return \stdClass The attestation.
     */
    private function attest(\stdClass $claim): \stdClass {
        $this->setUser($this->manager);

        return (new attestation_manager())->attest((int) $claim->id, (object) [
            'attestantrole' => self::ROLE,
            'statement' => self::STATEMENT,
        ]);
    }

    /**
     * The files of the candidate's archive, as the candidate would export it.
     *
     * Every claim is also written to TOOL_VAE_SCHEMA_DUMP when set, for ajv.
     *
     * @return string[] Path => content.
     */
    private function build(): array {
        global $DB;

        $this->setUser($this->candidate);
        $claims = $DB->get_records(
            'tool_vae_evidence',
            ['candidateid' => $this->candidate->id],
            'timecreated ASC, id ASC'
        );
        $files = (new exporter())->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time());

        $dump = getenv('TOOL_VAE_SCHEMA_DUMP');
        if ($dump) {
            check_dir_exists($dump, true, true);
            foreach ($files as $path => $content) {
                if (str_starts_with($path, 'preuves/')) {
                    file_put_contents($dump . '/' . basename($path), $content);
                }
            }
        }

        return $files;
    }

    /**
     * The claim files of an archive, decoded.
     *
     * @param string[] $files
     * @return array Path => decoded claim.
     */
    private static function preuves(array $files): array {
        $out = [];
        foreach ($files as $path => $content) {
            if (str_starts_with($path, 'preuves/')) {
                $out[$path] = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $out;
    }

    /**
     * T-77: the export refuses what the import would refuse, at the very same boundary.
     *
     * The import reads at most MAX_FILES files and MAX_BYTES bytes. The export used to build
     * anything: 1 995 declarations gave 2 001 files, an archive that came out and could not come
     * back. The boundary is tested on both sides, for the count and for the size, with the
     * import's own constants - a change of either constant moves both ends.
     */
    public function test_export_refuses_an_archive_the_import_would_refuse(): void {
        $atmax = array_fill(0, dossier_importer::MAX_FILES, 'x');
        exporter::require_restorable($atmax);

        $over = array_fill(0, dossier_importer::MAX_FILES + 1, 'x');
        try {
            exporter::require_restorable($over);
            $this->fail('One file more than the import reads must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorexporttoolarge', $e->errorcode);
        }

        exporter::require_restorable(['a' => str_repeat('x', dossier_importer::MAX_BYTES)]);
        try {
            exporter::require_restorable(['a' => str_repeat('x', dossier_importer::MAX_BYTES + 1)]);
            $this->fail('One byte more than the import reads must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorexporttoolarge', $e->errorcode);
        }
    }

    /**
     * T-77: a dossier of ordinary size is not refused, and what it builds passes the same check.
     */
    public function test_an_ordinary_dossier_passes_the_restorable_check(): void {
        global $DB;

        $files = (new exporter())->build(
            (int) $this->candidate->id,
            $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id]),
            identity_map::generate_ulid(),
            time()
        );

        exporter::require_restorable($files);
        $this->assertLessThanOrEqual(dossier_importer::MAX_FILES, count($files));
    }

    /**
     * Export as the candidate, and store the archive.
     *
     * @return \stored_file
     */
    private function export_as_candidate(): \stored_file {
        $this->setUser($this->candidate);

        return (new exporter())->export((int) $this->candidate->id);
    }

    public function test_manifest_lists_every_other_file_with_its_digest(): void {
        $this->attest($this->declare());
        $files = $this->build();

        $this->assertSame('manifeste.json', array_key_first($files));
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);

        $others = $files;
        unset($others['manifeste.json']);
        $listed = array_column($manifest['fichiers'], 'sha256', 'chemin');
        $this->assertEqualsCanonicalizing(array_keys($others), array_keys($listed));
        foreach ($others as $path => $content) {
            $this->assertSame(hash('sha256', $content), $listed[$path], $path);
        }

        $expected = [
            'referentiel.json',
            'schema/preuve.schema.json',
            'schema/commun/identifiant.schema.json',
            'LISEZMOI.txt',
            pdf_renderer::PATH,
        ];
        foreach ($expected as $path) {
            $this->assertArrayHasKey($path, $files);
        }
        $this->assertSame(exporter::FORMAT, $manifest['format']);
        $this->assertSame('Camille Martin', $manifest['candidat']['nom']);
        $this->assertSame(
            identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->candidate->id),
            $manifest['candidat']['identifiant']
        );
    }

    public function test_archive_carries_the_schema_its_claims_follow(): void {
        global $CFG;

        $this->declare();
        $files = $this->build();

        $shipped = $CFG->dirroot . '/admin/tool/vae/docs/schema/';
        $this->assertSame(file_get_contents($shipped . 'preuve.schema.json'), $files['schema/preuve.schema.json']);
        $this->assertSame(
            file_get_contents($shipped . 'commun/identifiant.schema.json'),
            $files['schema/commun/identifiant.schema.json']
        );

        // The version the manifest announces is the version of the file it ships.
        $schema = json_decode($files['schema/preuve.schema.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString(exporter::SCHEMA_VERSION, $schema['$comment']);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(exporter::SCHEMA_VERSION, $manifest['schema_preuve']);
    }

    public function test_archive_states_that_a_later_withdrawal_does_not_reach_it(): void {
        $this->declare();
        $files = $this->build();

        $notice = get_string('exportwithdrawalnotice', 'tool_vae');
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($notice, $manifest['avertissement']);
        $this->assertStringContainsString($notice, $files['LISEZMOI.txt']);
    }

    public function test_claim_has_the_shape_of_the_schema(): void {
        $claim = $this->declare();
        $this->attest($claim);
        $preuve = self::preuves($this->build())['preuves/' . $claim->uuid . '.json'];

        $this->assertSame(
            ['id', 'personne', 'competence', 'fiche', 'periode', 'cadre', 'situation', 'declaree_le', 'attestations',
                'correspondances'],
            array_keys($preuve)
        );
        foreach (['id', 'personne', 'competence'] as $key) {
            $this->assertMatchesRegularExpression(identity_map::ULID_PATTERN, $preuve[$key], $key);
        }
        $this->assertSame($claim->uuid, $preuve['id']);
        $this->assertSame([
            'code' => 'RNCP36832',
            'rang' => 'C03',
            'mise_a_jour' => gmdate('Y-m-d\TH:i:s\Z', (int) $claim->ficheupdated),
        ], $preuve['fiche']);
        $this->assertSame([
            'debut' => gmdate('Y-m-d\TH:i:s\Z', (int) $claim->periodstart),
            'fin' => gmdate('Y-m-d\TH:i:s\Z', (int) $claim->periodend),
        ], $preuve['periode']);
        $this->assertSame(['type' => 'ehpad', 'precision' => 'Unite protegee, equipe de jour'], $preuve['cadre']);
        $this->assertSame(['texte' => self::SITUATION, 'format' => 'markdown'], $preuve['situation']);

        $this->assertCount(1, $preuve['attestations']);
        $attestation = $preuve['attestations'][0];
        $this->assertSame(['statut', 'id', 'attestant', 'role', 'declaration', 'attestee_le'], array_keys($attestation));
        $this->assertSame('en_vigueur', $attestation['statut']);
        $this->assertSame(identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->manager->id), $attestation['attestant']);
        $this->assertSame(self::ROLE, $attestation['role']);
        $this->assertSame(self::STATEMENT, $attestation['declaration']);
    }

    public function test_claim_without_detail_or_signature_omits_them(): void {
        global $DB;

        $claim = $this->declare();
        $DB->set_field('tool_vae_evidence', 'settingdetail', null, ['id' => $claim->id]);
        $preuve = self::preuves($this->build())['preuves/' . $claim->uuid . '.json'];

        $this->assertSame(['type' => 'ehpad'], $preuve['cadre']);
        $this->assertSame([], $preuve['attestations']);
    }

    public function test_withdrawn_attestation_keeps_only_a_date_and_a_reason(): void {
        $claim = $this->declare();
        $attestation = $this->attest($claim);
        // Withdrawing is the site administrator's act alone (12 October 2026).
        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $attestation->id, 'contestation');

        $files = $this->build();
        $text = $files['preuves/' . $claim->uuid . '.json'];
        $preuve = self::preuves($files)['preuves/' . $claim->uuid . '.json'];

        $this->assertSame(['statut', 'retiree_le', 'motif'], array_keys($preuve['attestations'][0]));
        $this->assertSame('retiree', $preuve['attestations'][0]['statut']);
        $this->assertSame('contestation', $preuve['attestations'][0]['motif']);

        // Nothing that designates the signatory: their words, their position, the identifier
        // of the signature, which carries its time, or their ULID and Moodle id.
        $traces = [
            self::ROLE,
            self::STATEMENT,
            'Benali',
            $attestation->uuid,
            identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->manager->id),
        ];
        foreach ($traces as $trace) {
            $this->assertStringNotContainsString($trace, $text);
        }
        $this->assertNotContains((string) $this->manager->id, array_column($preuve['correspondances'], 'valeur'));
    }

    public function test_platform_identifiers_appear_only_in_correspondences(): void {
        $this->attest($this->declare());

        foreach (self::preuves($this->build()) as $path => $preuve) {
            $values = array_column($preuve['correspondances'], 'valeur');
            $this->assertContains((string) $this->candidate->id, $values);
            $this->assertContains((string) $this->competencyid, $values);
            $this->assertContains((string) $this->manager->id, $values);
            foreach ($preuve['correspondances'] as $entry) {
                $this->assertSame(exporter::SYSTEM, $entry['systeme']);
            }

            unset($preuve['correspondances']);
            array_walk_recursive($preuve, function ($value, $key) use ($path) {
                $this->assertIsNotInt($value, "$path: an integer under '$key' outside correspondances");
            });
        }
    }

    /**
     * The body of the printable copy, redrawn from the archive's own JSON files.
     *
     * TCPDF compresses what it writes, so the PDF itself is not searched: its HTML is. Drawing it
     * from the decoded files, not from the database, shows that the archive holds all it says.
     *
     * @param string[] $files
     * @return string
     */
    private static function printed(array $files): string {
        return pdf_renderer::html(
            json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR),
            array_values(self::preuves($files)),
            json_decode($files['referentiel.json'], true, 512, JSON_THROW_ON_ERROR)
        );
    }

    /**
     * The archive carries a PDF, listed in its manifest with its size and digest.
     */
    public function test_archive_carries_a_printable_copy_its_manifest_vouches_for(): void {
        $this->attest($this->declare());
        $files = $this->build();

        $this->assertArrayHasKey(pdf_renderer::PATH, $files);
        $pdf = $files[pdf_renderer::PATH];
        $this->assertStringStartsWith('%PDF-', $pdf);

        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $listed = array_column($manifest['fichiers'], null, 'chemin');
        $this->assertSame(strlen($pdf), $listed[pdf_renderer::PATH]['octets']);
        $this->assertSame(hash('sha256', $pdf), $listed[pdf_renderer::PATH]['sha256']);
    }

    /**
     * One declaration is one page: the footer does not open a page of its own.
     *
     * Found by looking at a real PDF on Moodle 5.3 on 13 October 2026 - the footer, written
     * after the body, opened a new page and left a blank one behind, and the numbers it printed
     * said "page 2 of 2" on a third page. Nothing in the PDF's bytes announced it, and the test
     * above only reads the first five of them.
     */
    public function test_printable_copy_of_one_declaration_is_one_page(): void {
        $this->attest($this->declare());
        $pdf = $this->build()[pdf_renderer::PATH];

        // Page objects, not the /Pages node that holds them.
        $this->assertSame(1, preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', $pdf));
    }

    /**
     * A long dossier is several pages, and the footer opens none of its own.
     *
     * The single-page test above cannot tell a footer that opens a page from one that does
     * not when the body already runs over: here the body fills several pages, the renderer
     * counts them before and after writing the footer and refuses a PDF where they differ,
     * and the page objects must agree with the count the document announces.
     */
    public function test_printable_copy_of_a_long_dossier_has_as_many_pages_as_it_announces(): void {
        $this->setUser($this->candidate);
        for ($i = 0; $i < 12; $i++) {
            (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
                'competencyid' => $this->competencyid,
                'periodstart' => self::PERIODSTART,
                'periodend' => self::PERIODEND,
                'settingtype' => 'ehpad',
                'settingdetail' => 'Unite protegee, equipe de jour',
                'situation' => str_repeat('Je prepare le materiel, puis j\'accompagne la personne au lever. ', 25),
                'situationformat' => FORMAT_PLAIN,
            ]);
        }

        $pdf = $this->build()[pdf_renderer::PATH];

        $pages = preg_match_all('~/Type\s*/Page(?![a-zA-Z])~', $pdf);
        $this->assertGreaterThan(2, $pages, 'The fixture is meant to run over several pages.');
        $this->assertSame(1, preg_match('~/Type\s*/Pages\b.*?/Count\s+(\d+)~s', $pdf, $m));
        $announced = (int) $m[1];
        $this->assertSame($announced, $pages);
    }

    /**
     * A language the site does not have, so that a test can tell whose language a string is in.
     *
     * Written under the data directory, which is where a language pack lives. A real one is not
     * needed - and the continuous integration has none - only a language the strings of which
     * differ from English.
     *
     * @param string $code
     * @param string[] $strings Identifier => text, of tool_vae.
     */
    private function install_language(string $code, array $strings): void {
        global $CFG;

        $dir = $CFG->dataroot . '/lang/' . $code;
        check_dir_exists($dir);
        file_put_contents($dir . '/langconfig.php', "<?php\n"
            . "\$string['thislanguage'] = 'Langue d essai';\n"
            . "\$string['thislanguageint'] = 'Test language';\n"
            . "\$string['parentlanguage'] = 'en';\n");

        $lines = ['<?php'];
        foreach ($strings as $identifier => $text) {
            $lines[] = '$string[' . var_export($identifier, true) . '] = ' . var_export($text, true) . ';';
        }
        file_put_contents($dir . '/tool_vae.php', implode("\n", $lines) . "\n");

        get_string_manager()->reset_caches(true);
    }

    /**
     * Take the language installed by a test away, so that the next one starts without it.
     */
    protected function tearDown(): void {
        global $CFG;

        if (!empty($CFG->dataroot) && is_dir($CFG->dataroot . '/lang/xx')) {
            remove_dir($CFG->dataroot . '/lang/xx');
            get_string_manager()->reset_caches(true);
        }
        parent::tearDown();
    }

    /**
     * The archive for a candidate whose language is not the exporter's.
     *
     * @param string $candidatelang The language of the candidate's account.
     * @return string[] Path => content.
     */
    private function build_exported_by_the_director(string $candidatelang): array {
        global $DB;

        $this->attest($this->declare());
        $DB->set_field('user', 'lang', $candidatelang, ['id' => $this->candidate->id]);

        // The director's account is in English, and so is what they see.
        $this->setUser($this->director);
        $claims = $DB->get_records(
            'tool_vae_evidence',
            ['candidateid' => $this->candidate->id],
            'timecreated ASC, id ASC'
        );

        return (new exporter())->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time());
    }

    /**
     * T-52, decided 13 October 2026: the dossier is read by a panel in the candidate's country, so
     * the archive speaks the candidate's language, not that of whoever presses the button.
     *
     * Before, a director working in English produced an English dossier.pdf for a French candidate.
     */
    public function test_archive_speaks_the_candidates_language_not_the_exporters(): void {
        $this->install_language('xx', [
            'exportpdftitle' => 'XX DOSSIER DE {$a}',
            'exportwithdrawalnotice' => 'XX AVIS DE RETRAIT',
            'exportreadmeintro' => 'XX LISEZMOI {$a->name}',
        ]);

        $files = $this->build_exported_by_the_director('xx');

        $this->assertStringContainsString('XX LISEZMOI Camille Martin', $files['LISEZMOI.txt']);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('XX AVIS DE RETRAIT', $manifest['avertissement']);

        // The PDF is compressed, but its title is not: TCPDF writes it in the information
        // dictionary as UTF-16BE behind a byte order mark.
        $title = "\xFE\xFF" . mb_convert_encoding('XX DOSSIER DE Camille Martin', 'UTF-16BE', 'UTF-8');
        $this->assertStringContainsString($title, $files[pdf_renderer::PATH]);
    }

    /**
     * Writing the archive in the candidate's language must not leave the exporter's session in it.
     */
    public function test_exporting_gives_the_exporters_language_back(): void {
        global $SESSION;

        $this->install_language('xx', ['exportwithdrawalnotice' => 'XX AVIS DE RETRAIT']);
        $SESSION->forcelang = '';

        $this->build_exported_by_the_director('xx');

        $this->assertSame('', $SESSION->forcelang);
        $this->assertSame('en', current_language());
        $this->assertSame(
            get_string('exportwithdrawalnotice', 'tool_vae'),
            'Withdrawing an attestation after this export has no effect on this archive, nor on any copy of it.'
        );
    }

    /**
     * A candidate whose language is not installed gets the language of the site, not that of the
     * person exporting.
     */
    public function test_a_language_the_site_does_not_have_falls_back_to_the_sites(): void {
        global $CFG;

        $this->install_language('xx', ['exportwithdrawalnotice' => 'XX AVIS DE RETRAIT']);
        $CFG->lang = 'xx';

        $files = $this->build_exported_by_the_director('zz');

        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('XX AVIS DE RETRAIT', $manifest['avertissement']);
    }

    /**
     * The printable copy shows the dossier as a panel reads it, and nothing of the coverage.
     */
    public function test_printable_copy_shows_the_dossier_and_no_coverage(): void {
        $this->attest($this->declare());
        $files = $this->build();
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);

        $html = self::printed($files);

        $expected = [
            'Camille Martin',
            $manifest['archive'],
            'RNCP36832',
            'C03 - Competency of an imported record',
            'France competences',
            'Unite protegee, equipe de jour',
            self::SITUATION,
            self::ROLE,
            self::STATEMENT,
            get_string('exportwithdrawalnotice', 'tool_vae'),
        ];
        foreach ($expected as $text) {
            $this->assertStringContainsString($text, $html);
        }

        // R3 stays the candidate's alone: no table, no count, no date of a last attestation.
        foreach (['coverage', 'coverageintro', 'coverageattested', 'coveragelastattested'] as $identifier) {
            $this->assertStringNotContainsString(get_string($identifier, 'tool_vae'), $html);
        }
    }

    /**
     * A withdrawn attestation prints as the claim file holds it: a date and a reason.
     */
    public function test_printable_copy_shows_nothing_of_a_withdrawn_signatory(): void {
        $claim = $this->declare();
        $attestation = $this->attest($claim);
        // Withdrawing is the site administrator's act alone (12 October 2026).
        $this->setAdminUser();
        (new attestation_manager())->withdraw((int) $attestation->id, 'contestation');

        $html = self::printed($this->build());

        $this->assertStringContainsString(s(get_string('withdrawreason:contestation', 'tool_vae')), $html);
        foreach ([self::ROLE, self::STATEMENT, 'Benali', $attestation->uuid] as $trace) {
            $this->assertStringNotContainsString($trace, $html);
        }
    }

    /**
     * The narrative keeps its Markdown emphasis and loses whatever TCPDF would fetch or run.
     */
    public function test_printable_copy_keeps_text_markup_only(): void {
        global $DB;

        // Not example.com: the test site lives there, and the header of the copy names the platform.
        $claim = $this->declare();
        $DB->set_field('tool_vae_evidence', 'situation', implode("\n\n", [
            '**Lever** accompagne.',
            '![photo](http://piege.invalid/photo.png) <script>alert(1)</script>',
            '<a href="http://piege.invalid/">lien</a> <img src="http://piege.invalid/x.png">',
        ]), ['id' => $claim->id]);

        $html = self::printed($this->build());

        $this->assertStringContainsString('<strong>Lever</strong>', $html);
        foreach (['<img', '<script', '<a ', 'piege.invalid'] as $trace) {
            $this->assertStringNotContainsString($trace, $html);
        }
    }

    public function test_referentiel_carries_label_licence_and_attribution(): void {
        $this->declare();
        $referentiel = json_decode($this->build()['referentiel.json'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $referentiel['fiches']);
        $fiche = $referentiel['fiches'][0];
        $this->assertSame('RNCP36832', $fiche['code']);
        $this->assertSame('rncp', $fiche['type']);
        $this->assertSame('Licence Ouverte 2.0', $fiche['licence']);
        $this->assertSame('France competences', $fiche['attribution']);
        $this->assertNotNull($fiche['mise_a_jour_source']);

        $this->assertCount(1, $fiche['competences']);
        $competence = $fiche['competences'][0];
        $this->assertSame(identity_map::uuid_for(identity_map::TYPE_COMPETENCY, $this->competencyid), $competence['identifiant']);
        $this->assertSame('C03', $competence['rang']);
        $this->assertSame('Competency of an imported record', $competence['intitule']);
        $this->assertNull($competence['etat']);
    }

    /**
     * Audit 17: the label of a competency is read where nobody is logged in and where the competencies are switched off.
     *
     * Before: exporter::referentiel() went through the core's API, whose first act is require_enabled(): with the subsystem
     * off, building the archive - which is what the backup of an uninstall and the erasure of a signatory do - threw
     * `competenciesarenotenabled`.
     */
    public function test_the_referentiel_is_built_with_the_competencies_switched_off(): void {
        $this->declare();
        set_config('enabled', 0, local\competency_gateway::SUBSYSTEM);

        $referentiel = json_decode($this->build()['referentiel.json'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Competency of an imported record', $referentiel['fiches'][0]['competences'][0]['intitule']);
    }

    /**
     * Audit 38: the producer of an archive is whoever the caller says; the exporter, for an export.
     *
     * The line the fix must not cross: the direction that exports signs the register, the file and the event with its own
     * name, which is what may_download() compares.
     */
    public function test_the_producer_of_an_export_is_the_exporter(): void {
        $this->declare();
        $sink = $this->redirectEvents();
        $this->setUser($this->director);
        $file = (new exporter())->export((int) $this->candidate->id);
        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof dossier_exported;
        }));
        $sink->close();

        $this->assertSame((int) $this->director->id, (int) $file->get_userid());
        $this->assertSame((int) $this->director->id, (int) exporter::registered($file)->userid);
        $this->assertCount(1, $events);
        $this->assertSame((int) $this->director->id, (int) $events[0]->userid);
        $this->assertSame((int) $this->candidate->id, (int) $events[0]->relateduserid);
    }

    public function test_export_stores_the_archive_and_logs_it(): void {
        $this->declare();
        $sink = $this->redirectEvents();
        $file = $this->export_as_candidate();
        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof dossier_exported;
        }));
        $sink->close();

        $this->assertSame(exporter::FILEAREA, $file->get_filearea());
        $this->assertSame((int) \context_user::instance($this->candidate->id)->id, (int) $file->get_contextid());
        $this->assertSame((int) $this->candidate->id, (int) $file->get_userid());

        $this->assertCount(1, $events);
        $this->assertSame((int) $this->candidate->id, (int) $events[0]->relateduserid);
        $this->assertSame((int) $file->get_id(), (int) $events[0]->objectid);
        $this->assertSame(1, $events[0]->other['declarations']);

        // The stored zip holds what the manifest lists, byte for byte.
        $path = make_request_directory() . '/dossier.zip';
        $file->copy_content_to($path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $manifest = json_decode($zip->getFromName('manifeste.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['fichiers'] as $entry) {
            $this->assertSame($entry['sha256'], hash('sha256', (string) $zip->getFromName($entry['chemin'])), $entry['chemin']);
        }
        $zip->close();
    }

    /**
     * Every archive stored is registered under its ULID with the digest of its exact manifest,
     * and the event carries that digest - lot 8 quater. The backup stores through the same path.
     */
    public function test_export_registers_the_digest_of_its_manifest(): void {
        global $DB;

        $this->declare();
        $sink = $this->redirectEvents();
        $file = $this->export_as_candidate();
        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof dossier_exported;
        }));
        $sink->close();

        $path = make_request_directory() . '/dossier.zip';
        $file->copy_content_to($path);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $bytes = (string) $zip->getFromName('manifeste.json');
        $readme = (string) $zip->getFromName('LISEZMOI.txt');
        $zip->close();
        $manifest = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);

        $row = exporter::registered($file);
        $this->assertSame($manifest['archive'], $row->archiveuuid);
        $this->assertSame(hash('sha256', $bytes), $row->sha256);
        $this->assertSame($manifest['candidat']['identifiant'], $row->candidateuuid);
        $this->assertSame((int) $this->candidate->id, (int) $row->userid);
        $this->assertCount(1, $events);
        $this->assertSame($row->sha256, $events[0]->other['sha256']);

        // The notice inside tells a jury how to have the archive confirmed: by its ULID.
        $this->assertStringContainsString($row->archiveuuid, $readme);

        local\backup::save((int) $this->candidate->id, (int) $this->candidate->id);
        $this->assertSame(2, $DB->count_records(exporter::REGISTER, ['candidateuuid' => $row->candidateuuid]));
    }

    public function test_empty_dossier_is_refused(): void {
        $this->setUser($this->candidate);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorexportempty', 'tool_vae'));
        (new exporter())->export((int) $this->candidate->id);
    }

    /**
     * An archive that does not conform to the schema it carries is produced all the same, and the faulty line is reported.
     *
     * The rank `c7` is what an administrator may type into the idnumber of a competency of an imported framework, and what an
     * earlier version recorded: the schema refuses it (^[A-Z0-9]{1,8}$), so the reimport refuses the archive whole, the valid
     * declarations beside it with it. DECISION OF THE PROJECT OWNER, 27 OCTOBER 2026: that is no reason to keep a candidate
     * from handing their dossier to the panel on the day it is due. Before: build() threw errorexportfailed, nothing was
     * registered, stored or logged. Now: the archive is built as the dossier stands, registered, stored and logged, and
     * exporter::warnings() names the line. The warning, the notice in the archive, the strict import and the backup of the
     * page are in export_warning_test; the two protections that still refuse are there too.
     */
    public function test_an_archive_that_does_not_conform_to_its_own_schema_is_produced_and_reported(): void {
        global $DB;

        $claim = $this->declare();
        $DB->set_field('tool_vae_evidence', 'ficherang', 'c7', ['id' => $claim->id]);
        $registered = $DB->count_records(exporter::REGISTER);
        $context = \context_user::instance((int) $this->candidate->id);

        // The exporter hands the files back with the value as it stands - nothing is cleaned - and says what it found.
        $this->setUser($this->candidate);
        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');
        $builder = new exporter();
        $files = $builder->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time());
        $this->assertSame('c7', self::preuves($files)['preuves/' . $claim->uuid . '.json']['fiche']['rang']);
        $this->assertCount(1, $builder->warnings());
        $this->assertSame('/fiche/rang', $builder->warnings()[0]['field'], 'The first faulty value is named.');

        $sink = $this->redirectEvents();
        $exporter = new exporter();
        $file = $exporter->export((int) $this->candidate->id);
        $events = array_filter($sink->get_events(), fn($event) => $event instanceof dossier_exported);
        $sink->close();
        $this->assertCount(1, $events, 'The export is logged.');
        $this->assertSame($registered + 1, $DB->count_records(exporter::REGISTER), 'The export is registered.');
        $this->assertCount(
            1,
            get_file_storage()->get_area_files($context->id, 'tool_vae', exporter::FILEAREA, 0, 'id', false),
            'The archive is stored.'
        );
        $this->assertSame(exporter::registered($file)->archiveuuid, substr($file->get_filename(), -30, 26));
        $this->assertCount(1, $exporter->warnings());

        // The same dossier with a rank the schema accepts is built without a word.
        $DB->set_field('tool_vae_evidence', 'ficherang', 'C03', ['id' => $claim->id]);
        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');
        $builder = new exporter();
        $builder->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time());
        $this->assertSame([], $builder->warnings());
    }

    /**
     * The strings of the plugin in one language, read from its own file.
     *
     * Not through the string manager: the French pack is not installed in the test site, and the manager then answers in English
     * whatever language is asked, which would make every French check read English text.
     *
     * @param string $lang en or fr.
     * @return string[] Identifier => text.
     */
    private static function strings_of(string $lang): array {
        $string = [];
        include(__DIR__ . '/../lang/' . $lang . '/tool_vae.php');

        return $string;
    }

    /**
     * The promise that another site can take the archive in states both conditions the import asks, and says who it speaks of.
     *
     * The real refusals, both tested in dossier_importer_test and import_conflicts_test: the competencies the archive names are
     * numbered otherwise on the site that takes it in (errorarchivecompetencyheld), and the account of the vaeiste already holds an
     * identifier there (errorarchiveidentityheld) - which it receives with its first declaration, export, copy or backup. The two
     * screens that promised the takeover (the export screen, the import screen) named the first condition only, so that a vaeiste
     * who had made a trial declaration on the new platform was refused without a word of warning.
     *
     * The export screen is also shown to the direction or the administrator who exports somebody else's dossier: it then reads in
     * the third person, with no "your declarations" and no "your profile" (exportintroother). The words are written out here on
     * purpose: changing the wording means changing them too.
     */
    public function test_the_promises_of_takeover_by_another_site_state_both_conditions(): void {
        $account = [
            'en' => '(it gets one with its first declaration, export, copy or backup)',
            'fr' => '(il le reçoit dès sa première déclaration, son premier export, sa première copie ou sa première sauvegarde)',
        ];
        // The first condition, in the words export_limits.feature reads.
        $numbered = [
            'en' => 'provided that other platform has not already numbered differently the competencies it names',
            'fr' => 'à condition que cette autre plateforme n\'ait pas déjà numéroté autrement les compétences qu\'elle nomme',
        ];
        $own = [
            'en' => 'and that your account has no identifier there yet',
            'fr' => 'et que votre compte n\'y ait pas déjà d\'identifiant',
        ];
        $other = [
            'en' => 'and that the account of the candidate has no identifier there yet',
            'fr' => 'et que le compte du vaeiste n\'y ait pas déjà d\'identifiant',
        ];
        $imported = [
            'en' => 'and if the account of the candidate holds no identifier on this site yet',
            'fr' => 'et si le compte du vaeiste n\'a pas déjà d\'identifiant sur ce site',
        ];
        // What the third person must not say: the second person of the vaeiste. "To you only" is the exporter's, and stays.
        $second = [
            'en' => '/\byour\b/i',
            'fr' => '/\b(votre|vos)\b/iu',
        ];

        foreach (['en', 'fr'] as $lang) {
            $strings = self::strings_of($lang);

            foreach (['exportintro', 'exportintroother', 'importdossierintro'] as $identifier) {
                $this->assertArrayHasKey($identifier, $strings, "$lang $identifier");
                $this->assertStringContainsString($account[$lang], $strings[$identifier], "$lang $identifier");
            }
            $this->assertStringContainsString($numbered[$lang], $strings['exportintro'], $lang);
            $this->assertStringContainsString($numbered[$lang], $strings['exportintroother'], $lang);
            $this->assertStringContainsString($own[$lang], $strings['exportintro'], $lang);
            $this->assertStringContainsString($other[$lang], $strings['exportintroother'], $lang);
            $this->assertStringContainsString($imported[$lang], $strings['importdossierintro'], $lang);

            $this->assertSame(0, preg_match($second[$lang], $strings['exportintroother']), "$lang exportintroother says your");
            $this->assertSame(1, preg_match($second[$lang], $strings['exportintro']), "$lang exportintro no longer says your");
        }
    }

    /**
     * The export page chooses the text by who the dossier belongs to.
     *
     * The page is not run from here: what is asserted is that it asks for the third-person text when the dossier is not the one of
     * the person logged in, and for the second-person text when it is - with the comparison made between integers, as the page
     * casts the id it reads (the default of the parameter is the session's own id, a string).
     */
    public function test_the_export_page_speaks_in_the_third_person_of_the_dossier_of_somebody_else(): void {
        global $CFG;

        $page = file_get_contents($CFG->dirroot . '/admin/tool/vae/export.php');

        $this->assertStringContainsString("'exportintroother'", $page);
        $this->assertStringContainsString("'exportintro'", $page);
        $this->assertMatchesRegularExpression('/\$candidateid === \(int\) \$USER->id/', $page);
    }

    public function test_the_candidate_and_the_direction_may_export_and_nobody_else(): void {
        $id = (int) $this->candidate->id;

        $this->setUser($this->candidate);
        $this->assertTrue(access::can_export($id));

        $this->setUser($this->director);
        $this->assertTrue(access::can_export($id));

        // Follows the candidate and holds export in their context: still no.
        $this->setUser($this->manager);
        $this->assertFalse(access::can_export($id));

        $this->setUser($this->stranger);
        $this->assertFalse(access::can_export($id));
    }

    public function test_reading_one_s_own_dossier_is_not_enough_to_export_it(): void {
        $generator = $this->getDataGenerator();
        $role = $generator->create_role();
        assign_capability('tool/vae:viewown', CAP_ALLOW, $role, \context_system::instance()->id, true);
        $reader = $generator->create_user();
        role_assign($role, $reader->id, \context_user::instance($reader->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($reader);
        $this->assertFalse(access::can_export((int) $reader->id));
    }

    public function test_refusal_is_a_capability_exception(): void {
        $this->setUser($this->manager);

        $this->expectException(\required_capability_exception::class);
        access::require_can_export((int) $this->candidate->id);
    }

    public function test_download_is_reserved_to_its_author_within_a_day(): void {
        $this->declare();
        $this->setUser($this->director);
        $file = (new exporter())->export((int) $this->candidate->id);
        $created = (int) $file->get_timecreated();

        $this->assertTrue(exporter::may_download($file, $created));
        $this->assertTrue(exporter::may_download($file, $created + exporter::LIFETIME - 1));
        $this->assertFalse(exporter::may_download($file, $created + exporter::LIFETIME));

        // The candidate may export their dossier, but this archive is not theirs to fetch.
        $this->setUser($this->candidate);
        $this->assertFalse(exporter::may_download($file, $created));

        $this->setUser($this->stranger);
        $this->assertFalse(exporter::may_download($file, $created));
    }

    public function test_download_is_refused_once_the_right_is_withdrawn(): void {
        $this->declare();
        $this->setUser($this->director);
        $file = (new exporter())->export((int) $this->candidate->id);

        role_unassign($this->directorrole, $this->director->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(exporter::may_download($file, (int) $file->get_timecreated()));
    }

    public function test_purge_deletes_expired_archives_only(): void {
        global $DB;

        $this->declare();
        $old = $this->export_as_candidate();
        $fresh = $this->export_as_candidate();
        $DB->set_field('files', 'timecreated', time() - exporter::LIFETIME, ['id' => $old->get_id()]);

        $this->assertSame(1, exporter::purge_expired(time()));

        $fs = get_file_storage();
        $this->assertFalse($fs->file_exists_by_hash($old->get_pathnamehash()));
        $this->assertTrue($fs->file_exists_by_hash($fresh->get_pathnamehash()));
    }

    public function test_scheduled_task_runs_the_purge(): void {
        global $DB;

        $this->declare();
        $old = $this->export_as_candidate();
        $DB->set_field('files', 'timecreated', time() - exporter::LIFETIME - 60, ['id' => $old->get_id()]);

        $this->expectOutputRegex('/1 expired export\(s\) deleted/');
        (new \tool_vae\task\purge_exports())->execute();

        $this->assertFalse(get_file_storage()->file_exists_by_hash($old->get_pathnamehash()));
    }

    public function test_erasing_the_dossier_discards_waiting_archives(): void {
        $this->declare();
        $file = $this->export_as_candidate();

        purge::dossier((int) $this->candidate->id);

        $this->assertFalse(get_file_storage()->file_exists_by_hash($file->get_pathnamehash()));
    }

    public function test_erasing_the_signatory_discards_waiting_archives(): void {
        global $DB;

        $claim = $this->declare();
        $this->attest($claim);
        $file = $this->export_as_candidate();

        $context = \context_user::instance($this->candidate->id);
        provider::delete_data_for_user(new approved_contextlist($this->manager, 'tool_vae', [$context->id]));

        $this->assertFalse(get_file_storage()->file_exists_by_hash($file->get_pathnamehash()));
        $this->assertNull($DB->get_field('tool_vae_attest', 'attestantid', ['evidenceid' => $claim->id]));
    }

    public function test_the_candidate_s_privacy_export_includes_waiting_archives(): void {
        $this->declare();
        $this->export_as_candidate();

        $context = \context_user::instance($this->candidate->id);
        provider::export_user_data(new approved_contextlist($this->candidate, 'tool_vae', [$context->id]));

        $files = writer::with_context($context)->get_files([
            get_string('pluginname', 'tool_vae'),
            get_string('privacy:path:exports', 'tool_vae'),
        ]);
        $this->assertCount(1, $files);
    }

    public function test_signatory_identifier_is_found_exported_and_erased(): void {
        global $DB;

        $this->attest($this->declare());
        $this->build();

        $managerid = (int) $this->manager->id;
        $context = \context_user::instance($managerid);
        $ulid = identity_map::uuid_for(identity_map::TYPE_USER, $managerid);

        $contextids = array_map('intval', provider::get_contexts_for_userid($managerid)->get_contextids());
        $this->assertContains((int) $context->id, $contextids);

        $userlist = new userlist($context, 'tool_vae');
        provider::get_users_in_context($userlist);
        $this->assertContains($managerid, array_map('intval', $userlist->get_userids()));

        provider::export_user_data(new approved_contextlist($this->manager, 'tool_vae', [$context->id]));
        $exported = writer::with_context($context)->get_data([
            get_string('pluginname', 'tool_vae'),
            get_string('privacy:path:identity', 'tool_vae'),
        ]);
        $this->assertSame($ulid, $exported->identifier);

        provider::delete_data_for_user(new approved_contextlist($this->manager, 'tool_vae', [$context->id]));
        $this->assertFalse($DB->record_exists('tool_vae_identity', ['itemtype' => 'user', 'itemid' => $managerid]));
    }
}
