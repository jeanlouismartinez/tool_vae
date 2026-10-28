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

use tool_vae\form\scope_form;
use tool_vae\local\importer;
use tool_vae\local\scope_choices;

/**
 * The choices of the page « outside my activity » are text, never markup (T-42).
 *
 * WHAT IS ASSERTED. That each competency of an imported record is a choice, keyed by its code and rank; and that a code a
 * manager rewrote by hand in the competencies screens of the core - which take any text - is shown as that text by the select,
 * which writes its labels as they are.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\scope_choices::class)]
final class scope_choices_test extends \advanced_testcase {
    /**
     * A flat record of two competencies.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG, $PAGE;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/formslib.php');
        $this->setAdminUser();
        $PAGE->set_url('/admin/tool/vae/scope.php');
        $PAGE->set_context(\context_system::instance());

        (new importer())->import((object) [
            'fichecode' => 'RS33333', 'fichetype' => 'rs', 'name' => 'Fiche plate',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv("rang,intitule\nC01,Un\nC02,Deux\n"));
    }

    public function test_each_competency_of_an_imported_record_is_a_choice(): void {
        [$options, $parts] = scope_choices::build();

        $this->assertSame(['RS33333-C01', 'RS33333-C02'], array_keys($options));
        $this->assertSame('RS33333-C02 — Deux', $options['RS33333-C02']);
        $this->assertSame(['RS33333', 'C02'], $parts['RS33333-C02']);
    }

    public function test_a_code_rewritten_by_hand_is_shown_as_text(): void {
        global $DB;

        $hostile = 'RS33333-C02</select><img src=x onerror=alert(1)>';
        $DB->set_field('competency', 'idnumber', $hostile, ['idnumber' => 'RS33333-C02']);

        [$options] = scope_choices::build();
        $html = (new scope_form(new \moodle_url('/admin/tool/vae/scope.php'), ['options' => $options]))->render();

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringNotContainsString('</select><img', $html);
        $this->assertStringContainsString('RS33333-C02&lt;/select&gt;&lt;img src=x onerror=alert(1)&gt; — Deux', $html);
    }
}
