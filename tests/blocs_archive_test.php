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

/**
 * The blocs of a record in the archive and in the printable copy (T-33, lot 3).
 *
 * WHAT IS ASSERTED. That the referentiel of the archive names the bloc of a claimed competency and lists the blocs
 * once, a claimed bloc included; that a flat record's referentiel has neither field, byte for byte as before; that
 * the printable copy groups the declarations of a record with blocs under a heading per bloc, the bloc itself first
 * and the competencies under no bloc last, and writes a flat record as it always did.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\pdf_renderer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_document::class)]
final class blocs_archive_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var int[] Competency ids by idnumber. */
    private $ids = [];

    /**
     * A record with blocs, a flat one, and a candidate who may declare.
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
        $withblocs = $importer->import($meta('RS00000'), importer::rows_from_csv(
            "rang,intitule,description,bloc\n"
            . "BC02,Travailler en équipe,,\n"
            . "C03,Transmettre,,BC02\n"
            . "BC01,Accompagner la personne,,\n"
            . "C02,Aider au lever,,BC01\n"
            . "Z01,Une compétence hors bloc,,\n"
        ));
        $flat = $importer->import($meta('RS11111'), importer::rows_from_csv("rang,intitule\nC01,Une\nC02,Deux\n"));
        $gateway = new competency_gateway();
        foreach ([$withblocs->frameworkid, $flat->frameworkid] as $frameworkid) {
            foreach ($gateway->list_framework_competencies((int) $frameworkid) as $competency) {
                $this->ids[$competency->idnumber] = (int) $competency->id;
            }
        }

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $roleid = $generator->create_role();
        foreach (['declare', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        }
        role_assign($roleid, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->candidate);
    }

    /**
     * Declare against a competency.
     *
     * @param string $idnumber
     */
    private function declare(string $idnumber): void {
        (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => $this->ids[$idnumber],
            'periodstart' => 1756684800,
            'periodend' => 1782777600,
            'settingtype' => 'ehpad',
            'situation' => 'Texte de ' . $idnumber,
            'situationformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * The two arrays the archive carries and the printable copy reads.
     *
     * @return array [preuves, referentiel]
     */
    private function snapshot(): array {
        $snapshot = (new exporter())->snapshot((int) $this->candidate->id);

        return [$snapshot['preuves'], $snapshot['referentiel']];
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

    public function test_the_referentiel_names_the_bloc_and_lists_the_blocs_once(): void {
        $this->declare('RS00000-C02');
        $this->declare('RS00000-C03');
        $this->declare('RS00000-BC01');
        $this->declare('RS00000-Z01');

        [, $referentiel] = $this->snapshot();

        $fiche = self::fiches($referentiel)['RS00000'];
        $competences = array_column($fiche['competences'], null, 'rang');
        $this->assertSame('BC01', $competences['C02']['bloc']);
        $this->assertSame('BC02', $competences['C03']['bloc']);
        $this->assertArrayNotHasKey('bloc', $competences['BC01'], 'A bloc is under no bloc.');
        $this->assertArrayNotHasKey('bloc', $competences['Z01']);
        $this->assertSame(
            [['rang' => 'BC01', 'intitule' => 'Accompagner la personne'], ['rang' => 'BC02', 'intitule' => 'Travailler en équipe']],
            $fiche['blocs']
        );
    }

    public function test_a_claimed_bloc_is_listed_even_when_none_of_its_competencies_is_claimed(): void {
        $this->declare('RS00000-BC01');

        [, $referentiel] = $this->snapshot();

        $this->assertSame(
            [['rang' => 'BC01', 'intitule' => 'Accompagner la personne']],
            self::fiches($referentiel)['RS00000']['blocs']
        );
    }

    public function test_a_flat_record_has_neither_field(): void {
        $this->declare('RS11111-C01');
        $this->declare('RS11111-C02');

        [, $referentiel] = $this->snapshot();

        $fiche = self::fiches($referentiel)['RS11111'];
        $this->assertArrayNotHasKey('blocs', $fiche);
        foreach ($fiche['competences'] as $competence) {
            $this->assertArrayNotHasKey('bloc', $competence);
        }
    }

    public function test_the_printable_copy_groups_the_declarations_by_bloc(): void {
        // Declared in an order that is neither the bloc order nor the rank order.
        $this->declare('RS00000-Z01');
        $this->declare('RS00000-C03');
        $this->declare('RS00000-C02');
        $this->declare('RS00000-BC01');
        $this->declare('RS11111-C02');
        [$preuves, $referentiel] = $this->snapshot();

        $html = pdf_renderer::html($this->manifest(), $preuves, $referentiel);

        $bc01 = strpos($html, 'Bloc BC01 - Accompagner la personne');
        $bc02 = strpos($html, 'Bloc BC02 - Travailler en équipe');
        $this->assertNotFalse($bc01);
        $this->assertNotFalse($bc02);
        $this->assertLessThan($bc02, $bc01);
        // The bloc itself first, then its competency; the one under no bloc last.
        $this->assertLessThan(strpos($html, 'Texte de RS00000-C02'), strpos($html, 'Texte de RS00000-BC01'));
        $this->assertLessThan(strpos($html, 'Texte de RS00000-C03'), strpos($html, 'Texte de RS00000-C02'));
        $this->assertLessThan(strpos($html, 'Texte de RS00000-Z01'), strpos($html, 'Texte de RS00000-C03'));
        $this->assertSame(2, substr_count($html, 'Bloc BC0'), 'One heading per bloc.');
    }

    public function test_the_competencies_under_no_bloc_have_a_heading_of_their_own(): void {
        $this->declare('RS00000-C03');
        $this->declare('RS00000-Z01');
        [$preuves, $referentiel] = $this->snapshot();

        $html = pdf_renderer::html($this->manifest(), $preuves, $referentiel);

        $nobloc = strpos($html, get_string('exportpdfnobloc', 'tool_vae'));
        $this->assertNotFalse($nobloc);
        $this->assertLessThan($nobloc, strpos($html, 'Texte de RS00000-C03'));
        $this->assertLessThan(strpos($html, 'Texte de RS00000-Z01'), $nobloc);
    }

    public function test_the_blocs_are_in_natural_order_in_the_printable_copy(): void {
        $this->setAdminUser();
        $result = (new importer())->import((object) [
            'fichecode' => 'RS33333', 'fichetype' => 'rs', 'name' => 'Ordre',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv(
            "rang,intitule,description,bloc\nBC10,Dix,,\nC1,Un,,BC10\nBC2,Deux,,\nC2,Trois,,BC2\n"
        ));
        foreach ((new competency_gateway())->list_framework_competencies((int) $result->frameworkid) as $competency) {
            $this->ids[$competency->idnumber] = (int) $competency->id;
        }
        $this->setUser($this->candidate);
        $this->declare('RS33333-C1');
        $this->declare('RS33333-C2');
        [$preuves, $referentiel] = $this->snapshot();

        $html = pdf_renderer::html($this->manifest(), $preuves, $referentiel);

        $this->assertLessThan(strpos($html, 'Bloc BC10 - Dix'), strpos($html, 'Bloc BC2 - Deux'), 'BC2 before BC10.');
    }

    public function test_the_printable_copy_of_a_flat_record_is_written_as_it_always_was(): void {
        $this->declare('RS11111-C02');
        $this->declare('RS11111-C01');
        [$preuves, $referentiel] = $this->snapshot();

        $html = pdf_renderer::html($this->manifest(), $preuves, $referentiel);

        $this->assertStringNotContainsString('Bloc BC', $html);
        $this->assertLessThan(
            strpos($html, 'Texte de RS11111-C01'),
            strpos($html, 'Texte de RS11111-C02'),
            'Order of declaration.'
        );
    }

    /**
     * The text of the editable copy (.odt) of a candidate's declarations.
     *
     * @param array $preuves
     * @param array $referentiel
     * @return string The content.xml of the document.
     */
    private function odt_content(array $preuves, array $referentiel): string {
        $bytes = dossier_document::compose($this->candidate, $preuves, $referentiel, time())->odt();
        $path = make_request_directory() . '/copie.odt';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path));
        $content = (string) $zip->getFromName('content.xml');
        $zip->close();

        return $content;
    }

    public function test_the_editable_copy_groups_the_declarations_by_bloc(): void {
        $this->declare('RS00000-Z01');
        $this->declare('RS00000-C03');
        $this->declare('RS00000-C02');
        $this->declare('RS00000-BC01');
        $this->declare('RS11111-C02');
        [$preuves, $referentiel] = $this->snapshot();

        $content = $this->odt_content($preuves, $referentiel);

        $bc01 = strpos($content, 'Bloc BC01 - Accompagner la personne');
        $bc02 = strpos($content, 'Bloc BC02 - Travailler en équipe');
        $this->assertNotFalse($bc01);
        $this->assertNotFalse($bc02);
        $this->assertLessThan($bc02, $bc01);
        $this->assertLessThan(strpos($content, 'Texte de RS00000-C02'), strpos($content, 'Texte de RS00000-BC01'));
        $this->assertLessThan(strpos($content, 'Texte de RS00000-C03'), strpos($content, 'Texte de RS00000-C02'));
        $this->assertLessThan(strpos($content, 'Texte de RS00000-Z01'), strpos($content, 'Texte de RS00000-C03'));
        $this->assertLessThan(
            strpos($content, 'Texte de RS00000-Z01'),
            strpos($content, get_string('exportpdfnobloc', 'tool_vae'))
        );
        $this->assertSame(2, substr_count($content, 'Bloc BC0'), 'One heading per bloc.');
    }

    public function test_the_editable_copy_of_a_flat_record_is_written_as_it_always_was(): void {
        $this->declare('RS11111-C02');
        $this->declare('RS11111-C01');
        [$preuves, $referentiel] = $this->snapshot();

        $content = $this->odt_content($preuves, $referentiel);

        $this->assertStringNotContainsString('Bloc BC', $content);
        $this->assertStringNotContainsString(get_string('exportpdfnobloc', 'tool_vae'), $content);
        $this->assertLessThan(
            strpos($content, 'Texte de RS11111-C01'),
            strpos($content, 'Texte de RS11111-C02'),
            'Order of declaration.'
        );
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
}
