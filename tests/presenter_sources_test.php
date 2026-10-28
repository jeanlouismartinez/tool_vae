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

use tool_vae\local\presenter;

/**
 * The table of the certification records imported, on the administration screen.
 *
 * WHAT IS ASSERTED. That the column headed "name of the certification" holds the name typed at the import, and that
 * the register has its own column: the cell used to hold "RNCP - national register..." under a header that promised
 * the name, and the name was shown nowhere - with six records of the same register the administrator had the code
 * and nothing else to tell one from another.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\presenter::class)]
final class presenter_sources_test extends \advanced_testcase {
    /**
     * The cells of the heading and of the first row of the table the presenter draws.
     *
     * @return string[][] [the texts of the th, the texts of the td of the first row]
     */
    private function table(): array {
        global $DB;

        $html = presenter::sources($DB->get_records('tool_vae_source', null, 'fichecode ASC'));

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $texts = function (string $query) use ($xpath): array {
            $out = [];
            foreach ($xpath->query($query) as $node) {
                $out[] = trim($node->textContent);
            }

            return $out;
        };

        return [
            $texts('//table[contains(@class, "tool-vae-sources")]//th'),
            $texts('//table[contains(@class, "tool-vae-sources")]//tbody//tr[1]/td'),
        ];
    }

    public function test_the_name_of_the_certification_is_shown_and_the_register_has_its_own_column(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->get_plugin_generator('tool_vae')->create_source([
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
            'fichetype' => 'rncp',
            'name' => 'Diplôme aide-soignant',
        ]);

        [$head, $cells] = $this->table();

        $this->assertSame(get_string('importname', 'tool_vae'), $head[1]);
        $this->assertSame(get_string('importfichetype', 'tool_vae'), $head[2]);
        $this->assertSame('Diplôme aide-soignant', $cells[1], 'The name typed at the import is shown nowhere.');
        $this->assertSame(get_string('typerncp', 'tool_vae'), $cells[2]);
        $this->assertCount(count($head), $cells, 'A heading with no cell, or a cell with no heading.');
    }

    public function test_two_records_of_the_same_register_are_told_apart_by_their_names(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('tool_vae');
        $generator->create_source(['fichecode' => 'RS5000', 'fichetype' => 'rs', 'name' => 'Utiliser un lève-personne']);
        $generator->create_source(['fichecode' => 'RS6000', 'fichetype' => 'rs', 'name' => 'Prévenir les chutes']);

        $html = presenter::sources($DB->get_records('tool_vae_source', null, 'fichecode ASC'));

        $this->assertStringContainsString('Utiliser un lève-personne', $html);
        $this->assertStringContainsString('Prévenir les chutes', $html);
    }

    public function test_a_record_with_no_framework_shows_no_name_and_raises_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->get_plugin_generator('tool_vae')->create_source(['fichecode' => 'RNCP36832']);
        $DB->set_field('tool_vae_source', 'frameworkid', 0, ['fichecode' => 'RNCP36832']);

        [, $cells] = $this->table();

        $this->assertSame('', $cells[1]);
        $this->assertSame('0', $cells[3], 'The competencies of a framework that is not there are not zero.');
    }
}
