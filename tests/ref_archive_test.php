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
use tool_vae\local\dossier_document;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\pdf_renderer;
use tool_vae\local\ref_manager;

/**
 * The addresses of the rules of validation in the archive and in the documents a candidate shows (T-41, lot 3).
 *
 * WHAT IS ASSERTED. That the referentiel of the archive carries the addresses of a record, with their title and the day
 * they were checked, ONLY once the site has opened them; that a record with none, or a site that has not opened them,
 * leaves the referentiel as it always was; that the printable copy and the editable copy write the address in text, with the
 * sentence that says the plugin applies nothing, and no active link; and that a title is escaped as any text.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\pdf_renderer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_document::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\ref_manager::class)]
final class ref_archive_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var int The day the administrator checked the page, at noon UTC. */
    private $checked;

    /**
     * Two records, addresses for one of them, and a candidate who declared on both.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $meta = fn(string $code) => (object) [
            'fichecode' => $code, 'fichetype' => 'rs', 'name' => 'Fiche ' . $code,
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne — jeu d\'essai',
        ];
        $importer = new importer();
        $with = $importer->import($meta('RS00000'), importer::rows_from_csv("rang,intitule\nC01,Une\n"));
        $without = $importer->import($meta('RS11111'), importer::rows_from_csv("rang,intitule\nC01,Une\n"));
        $gateway = new competency_gateway();
        $ids = [];
        foreach (['RS00000' => $with, 'RS11111' => $without] as $imported) {
            foreach ($gateway->list_framework_competencies((int) $imported->frameworkid) as $competency) {
                $ids[$competency->idnumber] = (int) $competency->id;
            }
        }

        $this->checked = strtotime('2026-10-20 12:00:00 UTC');
        ref_manager::add('RS00000', 'Fiche du <b>répertoire</b>', 'https://example.org/rncp/00000/', $this->checked);
        ref_manager::add('RS00000', 'Arrêté', 'https://example.org/arrete');

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $roleid = $generator->create_role();
        foreach (['declare', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        }
        role_assign($roleid, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->candidate);

        foreach (['RS00000-C01', 'RS11111-C01'] as $idnumber) {
            (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
                'competencyid' => $ids[$idnumber],
                'periodstart' => 1756684800,
                'periodend' => 1782777600,
                'settingtype' => 'ehpad',
                'situation' => 'Texte de ' . $idnumber,
                'situationformat' => FORMAT_PLAIN,
            ]);
        }
    }

    /**
     * The two arrays the archive carries and the documents read.
     *
     * @return array [preuves, referentiel]
     */
    private function snapshot(): array {
        $snapshot = (new exporter())->snapshot((int) $this->candidate->id);

        return [$snapshot['preuves'], $snapshot['referentiel']];
    }

    /**
     * The manifest the printable copy needs.
     *
     * @return array
     */
    private function manifest(): array {
        return [
            'archive' => identity_map::generate_ulid(),
            'exporte_le' => gmdate('Y-m-d\TH:i:s\Z'),
            'produit_par' => ['composant' => 'tool_vae', 'version_composant' => '0.0.0'],
            'candidat' => ['nom' => 'Camille Durand'],
            'avertissement' => 'Avertissement.',
        ];
    }

    /**
     * The fiches of a referentiel by code.
     *
     * @param array $referentiel
     * @return array
     */
    private static function fiches(array $referentiel): array {
        return array_column($referentiel['fiches'], null, 'code');
    }

    public function test_the_archive_carries_the_addresses_once_the_site_opened_them(): void {
        set_config('refopen', 1, 'tool_vae');

        [, $referentiel] = $this->snapshot();
        $fiches = self::fiches($referentiel);

        $this->assertSame([
            ['intitule' => 'Fiche du <b>répertoire</b>', 'adresse' => 'https://example.org/rncp/00000/',
                'verifie_le' => exporter::iso($this->checked)],
            ['intitule' => 'Arrêté', 'adresse' => 'https://example.org/arrete', 'verifie_le' => null],
        ], $fiches['RS00000']['regle_validation']);
        // A record with no address has no key at all.
        $this->assertArrayNotHasKey('regle_validation', $fiches['RS11111']);
    }

    public function test_a_closed_site_leaves_the_referentiel_as_it_always_was(): void {
        set_config('refopen', 0, 'tool_vae');

        [, $referentiel] = $this->snapshot();

        foreach ($referentiel['fiches'] as $fiche) {
            $this->assertArrayNotHasKey('regle_validation', $fiche);
        }
    }

    public function test_the_printable_copy_writes_the_addresses_in_text_with_the_sentence(): void {
        set_config('refopen', 1, 'tool_vae');
        [$preuves, $referentiel] = $this->snapshot();

        $html = pdf_renderer::html($this->manifest(), $preuves, $referentiel);

        $this->assertStringContainsString('https://example.org/rncp/00000/', $html);
        $this->assertStringContainsString('https://example.org/arrete', $html);
        $this->assertStringContainsString(s(get_string('refrulelabel', 'tool_vae')), $html);
        $this->assertStringContainsString(s(get_string('refsentence', 'tool_vae')), $html);
        // The sentence once, for the one record that has addresses; the day, for the address that carries it.
        $this->assertSame(1, substr_count($html, s(get_string('refsentence', 'tool_vae'))));
        $this->assertStringContainsString(s(get_string('refcheckedon', 'tool_vae', '20 October 2026')), $html);
        // Text, never an active link, and a title is escaped as any text.
        $this->assertStringNotContainsString('href="https://example.org', $html);
        $this->assertStringNotContainsString('<b>répertoire</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;répertoire&lt;/b&gt;', $html);
    }

    public function test_the_printable_copy_of_a_closed_site_names_no_address(): void {
        set_config('refopen', 0, 'tool_vae');
        [$preuves, $referentiel] = $this->snapshot();

        $html = pdf_renderer::html($this->manifest(), $preuves, $referentiel);

        $this->assertStringNotContainsString('example.org', $html);
        $this->assertStringNotContainsString(s(get_string('refsentence', 'tool_vae')), $html);
    }

    public function test_the_editable_copy_writes_the_addresses_and_the_sentence(): void {
        set_config('refopen', 1, 'tool_vae');
        [$preuves, $referentiel] = $this->snapshot();

        $bytes = dossier_document::compose($this->candidate, $preuves, $referentiel, time())->odt();
        $path = make_request_directory() . '/copie.odt';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $content = (string) $zip->getFromName('content.xml');
        $zip->close();

        $this->assertStringContainsString('https://example.org/rncp/00000/', $content);
        $this->assertStringContainsString('https://example.org/arrete', $content);
        $this->assertStringContainsString(htmlspecialchars(get_string('refsentence', 'tool_vae'), ENT_XML1 | ENT_QUOTES), $content);
    }

    public function test_the_editable_copy_of_a_closed_site_names_no_address(): void {
        set_config('refopen', 0, 'tool_vae');
        [$preuves, $referentiel] = $this->snapshot();

        foreach (['odt', 'docx'] as $format) {
            $bytes = dossier_document::compose($this->candidate, $preuves, $referentiel, time())->$format();
            $path = make_request_directory() . '/copie.' . $format;
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($path));
            $content = (string) $zip->getFromName($format === 'odt' ? 'content.xml' : 'word/document.xml');
            $zip->close();

            $this->assertNotSame('', $content, $format);
            $this->assertStringNotContainsString('example.org', $content, $format);
        }
    }

    public function test_a_new_address_makes_a_new_copy(): void {
        set_config('refopen', 1, 'tool_vae');
        [$preuves, $referentiel] = $this->snapshot();
        $before = dossier_document::digest($preuves, $referentiel);

        $this->setAdminUser();
        ref_manager::add('RS00000', 'Troisième', 'https://example.org/troisieme');
        $this->setUser($this->candidate);
        [$preuves, $referentiel] = $this->snapshot();

        $this->assertNotSame($before, dossier_document::digest($preuves, $referentiel));
    }
}
