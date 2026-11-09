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

use tool_vae\local\deposit_manager;
use tool_vae\local\draft_manager;

/**
 * What the screen tells the vaeiste about who reads the preparation of the validation dossier must be what the code does.
 *
 * The intro of the deposit page once promised that the people who follow the vaeiste never read the drafts or the earlier
 * versions. Since D-1b-G (lot 1b) they read the latest version, the draft in progress (said to be one, D-1b-H) and the
 * history of the versions (draft_manager::can_read_doctype()); the journal stays the author's alone; the site administrator
 * reads none of that (MC-16) but does read the deposits, as deposit_manager::can_read() has always allowed. A promise made
 * to the vaeiste that the code does not keep is a privacy defect, so the text is pinned here against both rules.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(draft_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(deposit_manager::class)]
final class validation_deposit_text_test extends \advanced_testcase {
    /**
     * The promises the text may not make, and what it has to say, in each language of the plugin.
     *
     * @return array
     */
    public static function languages(): array {
        return [
            'fr' => [
                'fr',
                ['jamais vos brouillons', 'jamais vos versions', 'ne lit rien'],
                ['brouillon en cours', 'historique', 'journal de bord', 'administrateur du site', 'ne lit que les dépôts'],
            ],
            'en' => [
                'en',
                ['never your drafts', 'never your earlier versions', 'reads nothing'],
                ['draft in progress', 'history', 'journal', 'site administrator', 'reads only the deposits'],
            ],
        ];
    }

    /**
     * The intro does not promise what the code does not keep, and it names who reads what.
     *
     * @param string $lang
     * @param string[] $forbidden
     * @param string[] $required
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('languages')]
    public function test_the_intro_keeps_only_the_promises_the_code_keeps(string $lang, array $forbidden, array $required): void {
        // The plugin's own language files are read: the test site has no French pack installed, so the string manager would
        // answer in English whatever language is asked for.
        global $CFG;
        $string = [];
        include($CFG->dirroot . '/admin/tool/vae/lang/' . $lang . '/tool_vae.php');
        $text = $string['validationdepositintro'];

        foreach ($forbidden as $promise) {
            $this->assertStringNotContainsStringIgnoringCase($promise, $text, "the {$lang} intro still promises: {$promise}");
        }
        foreach ($required as $said) {
            $this->assertStringContainsStringIgnoringCase($said, $text, "the {$lang} intro does not say: {$said}");
        }
    }
}
