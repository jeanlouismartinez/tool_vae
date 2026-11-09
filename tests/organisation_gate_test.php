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

/**
 * One door onto the learning plans of the core (T-90, conception § 4.2).
 *
 * The gateway reads what it is asked for and keeps no right of its own; the one caller of list_user_plans() and
 * plan_competency_ids() is organisation, which checks the plugin's rights at the top of each method. A future caller -
 * the export, a message, the AI - that named them would read a person's plans under no right at all. This test reads
 * the source literally, as fiche_no_background_test does for the network door, and asserts both halves: nobody else
 * names them, and the two files that may do name them (a guard that finds nothing proves nothing).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class organisation_gate_test extends \advanced_testcase {
    /** @var string[] The files that may name the two readings: whole paths. */
    private const NAMERS = ['classes/local/competency_gateway.php', 'classes/local/organisation.php'];

    /** @var string What no other file may contain. */
    private const FORBIDDEN = '~list_user_plans|list_plans_of_users|plan_competency_ids|plan_counts_of_frameworks~';

    /**
     * The source files of the plugin: everything that is code, but tests, docs, language files and scripts.
     *
     * @return string[] Path relative to the root => absolute path.
     */
    private static function sources(): array {
        $root = dirname(__DIR__);
        $skipped = ['.git', 'node_modules', 'vendor', 'tests', 'docs', 'lang', 'scripts'];
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $file, string $key, \RecursiveDirectoryIterator $directory) use ($skipped): bool {
                // Only the top-level directories are skipped, as fiche_no_background_test does.
                return !($file->isDir() && $directory->getSubPath() === '' && in_array($file->getFilename(), $skipped, true));
            }
        ));
        foreach ($iterator as $file) {
            if (strtolower($file->getExtension()) === 'php') {
                $found[substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1)] = $file->getPathname();
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Nobody but the gateway and organisation names the two readings; both of them do.
     */
    public function test_only_the_gateway_and_organisation_name_the_plan_readings(): void {
        $sources = self::sources();
        $this->assertGreaterThan(40, count($sources), 'The plugin has more source files than this.');

        $offenders = [];
        $namers = [];
        foreach ($sources as $relative => $path) {
            if (!preg_match(self::FORBIDDEN, file_get_contents($path))) {
                continue;
            }
            if (in_array($relative, self::NAMERS, true)) {
                $namers[] = $relative;
            } else {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders, 'A file other than the gateway and organisation reads the plans of the core.');
        $this->assertSame(self::NAMERS, $namers, 'The two files that may name the readings must both do so.');
    }

    /**
     * The grouped reading of the followed candidates goes through the gateway, from organisation and nowhere else.
     *
     * The test above is satisfied by organisation alone naming list_user_plans (for_candidate does); this one fails if
     * followed() stops reading the plans of the candidates it follows through the gateway's grouped reading.
     */
    public function test_organisation_reads_the_plans_of_the_followed_through_the_grouped_reading(): void {
        $root = dirname(__DIR__);

        $this->assertStringContainsString('list_plans_of_users', file_get_contents($root . '/classes/local/organisation.php'));
        $this->assertStringContainsString(
            'function list_plans_of_users',
            file_get_contents($root . '/classes/local/competency_gateway.php')
        );
    }

    /**
     * The count of plans of the configuration goes through the gateway, from organisation and nowhere else (T-90 lot 4).
     *
     * It reads the plans of people the reader has no right on, which it returns as a number and never as a plan: the
     * only door is organisation::site(), whose first line is the right to view any dossier.
     */
    public function test_organisation_counts_the_plans_of_the_configuration_through_the_gateway(): void {
        $root = dirname(__DIR__);

        $this->assertStringContainsString(
            'plan_counts_of_frameworks',
            file_get_contents($root . '/classes/local/organisation.php')
        );
        $this->assertStringContainsString(
            'function plan_counts_of_frameworks',
            file_get_contents($root . '/classes/local/competency_gateway.php')
        );
    }

    /**
     * The table of the activities that carry a competency is named by the gateway alone, and its reading by the gateway
     * and organisation alone (T-90 lot 1 bis). The table also holds the rule an activity applies to a competency, the
     * start of an assessment: no other file has a reason to open it.
     */
    public function test_only_the_gateway_names_the_table_of_the_activities_and_only_organisation_reads_it(): void {
        $sources = self::sources();
        $tablenamers = [];
        $readers = [];
        foreach ($sources as $relative => $path) {
            $code = file_get_contents($path);
            if (str_contains($code, 'competency_modulecomp')) {
                $tablenamers[] = $relative;
            }
            if (str_contains($code, 'modules_with_competencies')) {
                $readers[] = $relative;
            }
        }

        $this->assertSame(['classes/local/competency_gateway.php'], $tablenamers);
        $this->assertSame(self::NAMERS, $readers);
    }
}
