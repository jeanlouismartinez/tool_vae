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

use tool_vae\local\actors;

/**
 * The grid of the actors and their rights (T-90 lot 2).
 *
 * A READING OF THE CONFIGURATION. The catalogue says which right each actor of the scheme should hold, after the guide
 * (section 2.4); the grid says which roles of the site give it. It touches no database and names no person.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\actors::class)]
final class actors_test extends \advanced_testcase {
    /**
     * The table of the guide, section 2.4: the right of each actor, as the porteur's own guide gives them.
     */
    private const EXPECTED = [
        'vaeiste' => ['declare', 'viewown', 'draftown', 'positionself', 'export', 'useai'],
        'responsable' => ['viewassigned', 'attest', 'readdeposit', 'writeopinion', 'annotate', 'evaluate'],
        'direction' => ['viewany', 'export', 'readdeposit', 'annotate', 'draftview', 'identityview', 'certify', 'issuestructure'],
        'administrateur' => ['import', 'importdossier', 'withdrawany', 'linkcourse'],
        'referent' => [],
        'jury' => [],
        'certificateur' => [],
    ];

    /**
     * Every capability of the plugin, as site_powers() lists them, given by the roles in $given.
     *
     * @param array $given Short name of the capability => ['allow' => [...], 'prevent' => [...], 'prohibit' => [...]].
     * @return \stdClass[]
     */
    private function capabilities(array $given = []): array {
        $out = [];
        foreach ($this->names() as $short) {
            $row = (object) [
                'name' => 'tool/vae:' . $short,
                'label' => 'Label of ' . $short,
                'allow' => [],
                'prevent' => [],
                'prohibit' => [],
            ];
            foreach ($given[$short] ?? [] as $slot => $roles) {
                $row->{$slot} = $roles;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * The capabilities declared in db/access.php, read where the core reads them.
     *
     * @return string[] Short names.
     */
    private function names(): array {
        global $CFG;
        $capabilities = [];
        require($CFG->dirroot . '/admin/tool/vae/db/access.php');

        return array_map(fn($name) => substr($name, strlen('tool/vae:')), array_keys($capabilities));
    }

    /**
     * Settings as site_powers() lists them.
     *
     * @param bool $aiopen
     * @return \stdClass[]
     */
    private function settings(bool $aiopen = false): array {
        return [(object) ['name' => 'aiopen', 'value' => (int) $aiopen, 'open' => $aiopen]];
    }

    /**
     * The right of an actor in the grid.
     *
     * @param \stdClass[] $grid
     * @param string $actor
     * @param string $short
     * @return \stdClass|null
     */
    private function right(array $grid, string $actor, string $short): ?\stdClass {
        foreach ($grid as $row) {
            if ($row->key === $actor) {
                foreach ($row->rights as $right) {
                    if ($right->name === 'tool/vae:' . $short) {
                        return $right;
                    }
                }
            }
        }

        return null;
    }

    public function test_the_catalogue_holds_the_table_of_the_guide_and_nothing_else(): void {
        $catalogue = actors::catalogue();

        $this->assertSame(array_keys(self::EXPECTED), array_keys($catalogue));
        foreach (self::EXPECTED as $actor => $rights) {
            $this->assertEqualsCanonicalizing($rights, array_keys($catalogue[$actor]['rights']), $actor);
        }
        $this->assertTrue($catalogue['direction']['account']);
        $this->assertFalse($catalogue['jury']['account']);
    }

    public function test_every_capability_of_the_plugin_is_in_the_catalogue_and_every_name_of_the_catalogue_exists(): void {
        $incatalogue = [];
        foreach (actors::catalogue() as $actor) {
            $incatalogue = array_merge($incatalogue, array_keys($actor['rights']));
        }

        $this->assertEqualsCanonicalizing($this->names(), array_unique($incatalogue), 'A new right must be given to an actor.');
        $this->assertCount(21, $this->names());
    }

    /**
     * Annotate (lot 1b) is the manager's and the direction's, as the spec gives it; the direction annotates only a vaeiste
     * it also follows, since the consent is given by name (D-1b-B).
     */
    public function test_export_readdeposit_and_annotate_are_shared_and_the_others_are_not(): void {
        $count = [];
        foreach (actors::catalogue() as $actor) {
            foreach (array_keys($actor['rights']) as $short) {
                $count[$short] = ($count[$short] ?? 0) + 1;
            }
        }

        $shared = array_keys(array_filter($count, fn($n) => $n > 1));
        sort($shared);
        $this->assertSame(['annotate', 'export', 'readdeposit'], $shared);
        $this->assertSame(actors::EXPECTED, actors::catalogue()['responsable']['rights']['annotate']);
        $this->assertSame(actors::EXPECTED, actors::catalogue()['direction']['rights']['annotate']);
    }

    public function test_a_right_given_by_a_role_is_given_and_names_it(): void {
        $grid = actors::grid($this->capabilities(['declare' => ['allow' => ['Vaeiste', 'Autre']]]), $this->settings());

        $right = $this->right($grid, 'vaeiste', 'declare');

        $this->assertSame(actors::GIVEN, $right->state);
        $this->assertSame(['Vaeiste', 'Autre'], $right->roles);
    }

    public function test_an_expected_right_that_no_role_gives_is_missing(): void {
        $grid = actors::grid($this->capabilities(), $this->settings());

        $this->assertSame(actors::MISSING, $this->right($grid, 'vaeiste', 'declare')->state);
        $this->assertSame(actors::MISSING, $this->right($grid, 'direction', 'readdeposit')->state);
    }

    public function test_a_role_that_only_prevents_gives_nothing_and_a_role_that_prohibits_is_named(): void {
        $grid = actors::grid($this->capabilities([
            'declare' => ['prevent' => ['Etudiant']],
            'viewown' => ['allow' => ['Vaeiste'], 'prohibit' => ['Bloque']],
        ]), $this->settings());

        $declare = $this->right($grid, 'vaeiste', 'declare');
        $this->assertSame(actors::MISSING, $declare->state, 'PREVENT gives nothing.');
        $this->assertSame([], $declare->roles);
        $viewown = $this->right($grid, 'vaeiste', 'viewown');
        $this->assertSame(actors::GIVEN, $viewown->state);
        $this->assertSame(['Bloque'], $viewown->forbidden, 'A role that forbids the right is said.');
    }

    public function test_an_optional_or_admin_right_without_role_is_free_not_missing(): void {
        $grid = actors::grid($this->capabilities(), $this->settings());

        $pairs = [
            ['responsable', 'evaluate'], ['direction', 'certify'], ['direction', 'draftview'],
            ['direction', 'identityview'], ['administrateur', 'import'],
            ['administrateur', 'importdossier'], ['administrateur', 'withdrawany'], ['administrateur', 'linkcourse'],
        ];
        foreach ($pairs as $pair) {
            $this->assertSame(actors::FREE, $this->right($grid, $pair[0], $pair[1])->state, implode(':', $pair));
        }
    }

    public function test_the_ai_right_is_expected_only_once_the_setting_is_open(): void {
        $closed = actors::grid($this->capabilities(), $this->settings(false));
        $open = actors::grid($this->capabilities(), $this->settings(true));

        $this->assertSame(actors::FREE, $this->right($closed, 'vaeiste', 'useai')->state);
        $this->assertSame(actors::MISSING, $this->right($open, 'vaeiste', 'useai')->state);
    }

    public function test_the_administrator_cannot_exercise_two_rights_so_the_gap_says_nobody(): void {
        $grid = actors::grid($this->capabilities(), $this->settings());

        $this->assertFalse($this->right($grid, 'responsable', 'viewassigned')->adminmay);
        $this->assertFalse($this->right($grid, 'responsable', 'writeopinion')->adminmay);
        $this->assertFalse($this->right($grid, 'responsable', 'evaluate')->adminmay, 'The administrator follows nobody.');
        $this->assertFalse($this->right($grid, 'responsable', 'annotate')->adminmay, 'Nobody consents to the administrator.');
        $this->assertFalse($this->right($grid, 'direction', 'annotate')->adminmay);
        $this->assertTrue($this->right($grid, 'direction', 'certify')->adminmay);
        $this->assertTrue($this->right($grid, 'responsable', 'attest')->adminmay);
        $this->assertTrue($this->right($grid, 'direction', 'viewany')->adminmay);
    }

    public function test_a_right_the_database_does_not_hold_is_left_out_without_an_error(): void {
        $capabilities = array_values(array_filter(
            $this->capabilities(),
            fn($capability) => $capability->name !== 'tool/vae:declare'
        ));

        $grid = actors::grid($capabilities, $this->settings());

        $this->assertNull($this->right($grid, 'vaeiste', 'declare'));
        $this->assertNotNull($this->right($grid, 'vaeiste', 'viewown'));
    }

    public function test_the_actors_without_an_account_have_no_right_and_the_grid_reads_no_database(): void {
        global $DB;
        $before = $DB->perf_get_reads();

        $grid = actors::grid($this->capabilities(), $this->settings());

        $this->assertSame($before, $DB->perf_get_reads(), 'The grid is a pure function of what it is given.');
        foreach ($grid as $row) {
            if (!$row->account) {
                $this->assertSame([], $row->rights, $row->key);
            }
        }
        $this->assertSame(array_keys(self::EXPECTED), array_column($grid, 'key'));
    }

    public function test_the_keys_of_every_level_are_a_white_list(): void {
        $grid = actors::grid($this->capabilities(['declare' => ['allow' => ['Vaeiste']]]), $this->settings());

        foreach ($grid as $row) {
            $this->assertSame(['key', 'account', 'rights'], array_keys((array) $row));
            foreach ($row->rights as $right) {
                $this->assertSame(
                    ['name', 'label', 'status', 'roles', 'forbidden', 'state', 'adminmay'],
                    array_keys((array) $right)
                );
            }
        }
    }
}
