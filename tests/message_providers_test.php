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
 * Every message provider the plugin declares has the string Moodle shows for it, in every language.
 *
 * WHY THIS EXISTS. The notification preferences page names each provider by the string
 * 'messageprovider:<name>' of its component. A provider without that string is shown to the user
 * as the raw placeholder '[[messageprovider:<name>]]' - seen on the test instance on 26 October 2026
 * for 'withdrawalrequested' and 'withdrawalrefused'. Nothing else checks this: the strings of the
 * capabilities are controlled by scripts/verifier-langue.php, those of the providers were not.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class message_providers_test extends \advanced_testcase {
    /**
     * The providers of db/messages.php, read as Moodle reads them.
     *
     * @return string[] Provider names.
     */
    private function providers(): array {
        $messageproviders = [];
        require(__DIR__ . '/../db/messages.php');
        $this->assertNotEmpty($messageproviders, 'db/messages.php declares no provider');
        return array_keys($messageproviders);
    }

    /**
     * Each provider has its 'messageprovider:<name>' string in English and in French.
     */
    public function test_each_provider_has_its_string_in_every_language(): void {
        $manager = get_string_manager();
        $missing = [];
        foreach (['en', 'fr'] as $lang) {
            $strings = $manager->load_component_strings('tool_vae', $lang);
            foreach ($this->providers() as $provider) {
                $key = 'messageprovider:' . $provider;
                if (!isset($strings[$key]) || trim((string) $strings[$key]) === '') {
                    $missing[] = "lang/$lang : $key";
                }
            }
        }
        $this->assertSame([], $missing, 'Providers shown as raw placeholders on the notification preferences page');
    }

    /**
     * No 'messageprovider:' string names a provider that does not exist: a stale string would hide a
     * renamed provider behind a label that still looks right.
     */
    public function test_no_string_names_an_unknown_provider(): void {
        $providers = $this->providers();
        $orphans = [];
        foreach (['en', 'fr'] as $lang) {
            foreach (array_keys(get_string_manager()->load_component_strings('tool_vae', $lang)) as $key) {
                if (str_starts_with($key, 'messageprovider:') && !in_array(substr($key, 16), $providers, true)) {
                    $orphans[] = "lang/$lang : $key";
                }
            }
        }
        $this->assertSame([], $orphans);
    }
}
