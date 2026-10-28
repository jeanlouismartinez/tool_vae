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
 * One door onto the Internet, and never from the background (0.47.0).
 *
 * THE PLUGIN MADE NO REQUEST OF ITS OWN BEFORE THIS VERSION, and the guarantee is kept as narrow as it can be: one class,
 * fiche_fetcher, may name an HTTP client, and nothing that runs on a schedule may name it or what calls it. This is the PHP
 * twin of scripts/sortie-reseau.sh (measure MC-30), for the continuous integration that has no shell to run it; the script
 * is run by `make valider`. Both read the source literally, comments included, whatever the case, in every file of the
 * plugin that is code (not tests, docs, language files or scripts), and the exemption of the door is a whole path.
 *
 * A GUARD THAT FINDS NOTHING PROVES NOTHING: the patterns are tried on strings that must be caught and strings that must
 * not, the number of files read is asserted, and the script itself is run on small trees that hold a violation.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class fiche_no_background_test extends \advanced_testcase {
    /** @var string What only the door may contain. */
    private const DOOR = 'classes/local/fiche_fetcher.php';

    /** @var string[] The files that may name the door or what calls it: whole paths. */
    private const NAMERS = [
        'classes/local/fiche_fetcher.php', 'classes/local/fiche_import.php', 'import_fiches.php',
        'classes/event/export_fetched.php',
    ];

    /** @var string[] The files that may name the class that calls the door: whole paths. */
    private const IMPORTERS = [
        'classes/local/fiche_import.php', 'classes/local/fiche_screen.php', 'classes/local/fiche_plan_store.php',
        'classes/form/fiche_import_form.php', 'classes/form/import_form.php', 'classes/event/record_imported.php',
        'import_fiches.php',
    ];

    /** @var string What no other file may contain. */
    private const FORBIDDEN = '~http_client|GuzzleHttp|curl_init|curl_exec|curl_multi|curl_setopt|CURLOPT|FOLLOWLOCATION'
        . '|new\s+\\\\?curl(?![_a-z0-9])|\\\\curl::|download_file_content|create_file_from_url|fsockopen|stream_socket_client'
        . '|SoapClient|dns_get_record|gethostbyname|(?<![_a-z0-9>:$])get_headers\s*\('
        . '|(?:file_get_contents|fopen|readfile|copy|file|simplexml_load_file)\s*\(\s*[\'"]https?:~i';

    /**
     * The root of the plugin.
     *
     * @return string
     */
    private static function root(): string {
        return dirname(__DIR__);
    }

    /**
     * The source files of the plugin: everything that is code, but tests, docs, language files and scripts.
     *
     * @return string[] Path relative to the root => absolute path.
     */
    private static function sources(): array {
        $root = self::root();
        $skipped = ['.git', 'node_modules', 'vendor', 'tests', 'docs', 'lang', 'scripts'];
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $file, string $key, \RecursiveDirectoryIterator $directory) use ($skipped): bool {
                // Only the top-level directories are skipped: the same rule as the script, which prunes by path.
                return !($file->isDir() && $directory->getSubPath() === '' && in_array($file->getFilename(), $skipped, true));
            }
        ));
        foreach ($iterator as $file) {
            if (in_array(strtolower($file->getExtension()), ['php', 'js', 'inc'], true)) {
                $found[substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1)] = $file->getPathname();
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * The shell, when there is one.
     *
     * @return string|null The command, or null.
     */
    private static function bash(): ?string {
        $path = PHP_OS_FAMILY === 'Windows' ? '' : trim((string) @shell_exec('command -v bash 2>/dev/null'));

        return $path === '' ? null : $path;
    }

    public function test_the_guard_reads_the_plugin_and_not_nothing(): void {
        $sources = self::sources();

        $this->assertGreaterThan(100, count($sources), 'Every file that is code is read.');
        $expected = ['index.php', 'import_fiches.php', 'lib.php', 'db/tasks.php', 'classes/local/importer.php', self::DOOR];
        foreach ($expected as $relative) {
            $this->assertArrayHasKey($relative, $sources, $relative);
        }
        foreach (array_keys($sources) as $relative) {
            $this->assertDoesNotMatchRegularExpression('~^(tests|docs|lang|scripts|vendor|\.git|node_modules)/~', $relative);
        }
    }

    public function test_the_patterns_catch_what_they_must_and_let_the_rest_be(): void {
        $caught = [
            '\core\http_client', 'new \GuzzleHttp\Client()', 'curl_init($u)', 'CURLOPT_URL', 'new curl()', 'new \curl',
            '\curl::', 'fsockopen(', 'stream_socket_client(', 'new SoapClient($wsdl)', 'dns_get_record($host)', 'gethostbyname($h)',
            'get_headers($url)', ' get_headers ($url)', "file_get_contents('https://x.example/')", 'fopen("http://x/", "r")',
            "readfile('https://x')", "copy('https://x', \$to)", "file ('http://x')", 'CURL_MULTI', 'curl_multi_init()',
            'NEW \CURL()', 'create_file_from_url($u)', 'CURLOPT_FOLLOWLOCATION',
        ];
        foreach ($caught as $text) {
            $this->assertMatchesRegularExpression(self::FORBIDDEN, $text, $text);
        }
        $left = [
            '$response->get_headers()', 'self::get_headers($x)', 'copy(ies) written', 'new curl_security_helper()',
            "file_get_contents('/tmp/x')", "fopen('php://memory', 'w+')", '$this->http_headers', 'copy($from, $to)',
        ];
        foreach ($left as $text) {
            $match = preg_match(self::FORBIDDEN, $text, $found);
            // Names of the core that look like a client or a header and are neither: only these strings are expected to pass.
            $this->assertSame(0, $match, "$text must not be caught, caught: " . ($found[0] ?? ''));
        }
    }

    public function test_only_the_door_names_an_http_client_curl_or_an_open_address(): void {
        $found = [];
        foreach (self::sources() as $relative => $path) {
            if ($relative === self::DOOR) {
                continue;
            }
            if (preg_match(self::FORBIDDEN, (string) file_get_contents($path), $match)) {
                $found[] = "$relative ({$match[0]})";
            }
        }

        $this->assertSame([], $found, 'Only ' . self::DOOR . ' may open onto the Internet.');
        $this->assertFileExists(self::root() . '/' . self::DOOR);
        $this->assertStringContainsString(
            'http_client',
            file_get_contents(self::root() . '/' . self::DOOR),
            'The door uses the client of the core.'
        );
    }

    public function test_nothing_that_runs_in_the_background_names_the_door_or_what_calls_it(): void {
        $paths = glob(self::root() . '/classes/task/*.php');
        $paths[] = self::root() . '/db/tasks.php';
        $found = [];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $content = (string) file_get_contents($path);
            if (preg_match('~fiche_fetcher|fiche_import\b|from_data_gouv|http_client~i', $content, $match)) {
                $found[] = basename($path) . " ({$match[0]})";
            }
        }

        $this->assertSame([], $found);
        $observers = is_file(self::root() . '/db/events.php') ? file_get_contents(self::root() . '/db/events.php') : '';
        $this->assertStringNotContainsString('fiche_fetcher', $observers);
        $this->assertStringNotContainsString('fiche_import', $observers);
    }

    public function test_the_door_is_named_by_four_files_and_no_other(): void {
        $found = [];
        foreach (self::sources() as $relative => $path) {
            if (in_array($relative, self::NAMERS, true)) {
                continue;
            }
            // The case does not count in PHP: a method may be called From_Data_Gouv().
            if (preg_match('~fiche_fetcher|from_data_gouv~i', (string) file_get_contents($path), $match)) {
                $found[] = "$relative ({$match[0]})";
            }
        }

        $this->assertSame([], $found, 'An observer, a web service, a command line script or a new file does not open the door.');
        foreach (self::NAMERS as $relative) {
            $this->assertFileExists(self::root() . '/' . $relative);
        }
    }

    public function test_the_class_that_calls_the_door_is_named_by_seven_files_and_no_other(): void {
        // The name of a method can be built; the name of the class that holds it cannot be avoided.
        $found = [];
        foreach (self::sources() as $relative => $path) {
            if (in_array($relative, self::IMPORTERS, true)) {
                continue;
            }
            if (preg_match('~fiche_import(?![_a-z0-9])~i', (string) file_get_contents($path), $match)) {
                $found[] = "$relative ({$match[0]})";
            }
        }

        $this->assertSame([], $found);
        foreach (self::IMPORTERS as $relative) {
            $this->assertFileExists(self::root() . '/' . $relative);
        }
    }

    public function test_the_door_cannot_be_loosened(): void {
        $door = file_get_contents(self::root() . '/' . self::DOOR);

        $this->assertDoesNotMatchRegularExpression('/ignoresecurity|CURLOPT_SSL|skipcertverify/i', $door);
        $this->assertDoesNotMatchRegularExpression("/verify['\"]?\\s*=>\\s*[^t\\s]/i", $door, 'verify is true, and only true.');
        $this->assertDoesNotMatchRegularExpression(
            "/allow_redirects['\"]?\\s*=>\\s*[^f\\s]/i",
            $door,
            'Redirections are never followed.'
        );
        $this->assertMatchesRegularExpression("/'verify'\\s*=>\\s*true/", $door);
        $this->assertMatchesRegularExpression("/'allow_redirects'\\s*=>\\s*false/", $door);
    }

    public function test_the_script_fails_on_a_violation_passes_on_a_clean_tree_and_refuses_a_wrong_root(): void {
        $bash = self::bash();
        if ($bash === null) {
            $this->markTestSkipped('No shell here: the PHP tests above do the same reading.');
        }
        $script = self::root() . '/scripts/sortie-reseau.sh';
        $run = function (string $root) use ($bash, $script): array {
            exec(escapeshellarg($bash) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($root) . ' 2>&1', $output, $code);

            return [$code, implode("\n", $output)];
        };

        $make = function (array $files): string {
            $root = make_request_directory();
            $base = [
                'version.php' => "<?php\n",
                'classes/local/fiche_fetcher.php' => "<?php\n'verify' => true; 'allow_redirects' => false;\n",
                'classes/local/fiche_import.php' => "<?php\n",
                'import_fiches.php' => "<?php\n",
                'classes/event/export_fetched.php' => "<?php\n",
            ];
            foreach ($files + $base as $relative => $content) {
                @mkdir(dirname("$root/$relative"), 0777, true);
                file_put_contents("$root/$relative", $content);
            }

            return $root;
        };

        [$code, $text] = $run($make([]));
        $this->assertSame(0, $code, $text);

        $violations = [
            'a client named elsewhere' => ['classes/local/other.php' => "<?php\n// the HTTP_CLIENT of the core\n"],
            'a socket in a page' => ['index.php' => "<?php\nfsockopen('x', 80);\n"],
            'a url opened in a script of the amd directory' => ['amd/src/x.js' => "file_get_contents('https://x.example/')\n"],
            'the door named by an observer' => ['classes/observer.php' => "<?php\nnew \\tool_vae\\local\\fiche_fetcher();\n"],
            'the door called with another case' => [
                'classes/observer.php' => "<?php\n(new X())->From_Data_Gouv([]);\n",
            ],
            'the class that calls the door, named by an observer' => [
                'classes/observer.php' => "<?php\n\$import = new \\tool_vae\\local\\Fiche_Import();\n",
            ],
            'the door named by the back office' => ['classes/task/job.php' => "<?php\nfiche_import::from_data_gouv();\n"],
            'the door named by a command line script' => ['cli/fetch.php' => "<?php\n\$x->from_data_gouv();\n"],
            'a loosened door' => ['classes/local/fiche_fetcher.php' => "<?php\n'verify' => false;\n"],
            'a door that follows redirections' => [
                'classes/local/fiche_fetcher.php' => "<?php\n'verify' => true; 'allow_redirects' => true;\n",
            ],
            'a door that follows them with options' => [
                'classes/local/fiche_fetcher.php' => "<?php\n'verify' => true; 'allow_redirects' => ['max' => 3];\n",
            ],
        ];
        foreach ($violations as $label => $files) {
            [$code, $text] = $run($make($files));
            $this->assertSame(1, $code, "$label: $text");
        }

        // The exemption is a whole path: another file with the name of the door is not the door.
        [$code, $text] = $run($make(['classes/other/fiche_fetcher.php' => "<?php\n\\core\\http_client::class;\n"]));
        $this->assertSame(1, $code, $text);

        // A root that is not the plugin's reads nothing, and says so.
        [$code, $text] = $run(make_request_directory());
        $this->assertSame(2, $code, $text);
    }

    public function test_the_new_classes_do_not_name_the_competency_subsystem(): void {
        // MC-5: only the gateway names it. The string is built here so that this file does not break the rule it checks.
        $subsystem = 'core_' . 'competency';
        $found = [];
        foreach (glob(self::root() . '/classes/local/fiche_*.php') as $path) {
            if (strpos((string) file_get_contents($path), $subsystem) !== false) {
                $found[] = basename($path);
            }
        }
        foreach (['import_fiches.php', 'classes/form/fiche_import_form.php', 'classes/form/fiche_confirm_form.php'] as $relative) {
            if (strpos((string) file_get_contents(self::root() . "/$relative"), $subsystem) !== false) {
                $found[] = $relative;
            }
        }

        $this->assertSame([], $found);
    }

    public function test_the_script_loads_the_refusal_first_and_the_makefile_has_the_guard(): void {
        $script = file_get_contents(self::root() . '/scripts/convertir-fiche-rncp.php');
        $refusal = strpos($script, 'fiche_refusal.php');
        $converter = strpos($script, 'fiche_converter.php');

        $this->assertNotFalse($refusal);
        $this->assertNotFalse($converter);
        $this->assertLessThan(
            $converter,
            $refusal,
            'The converter extends nothing but throws the refusal: it must be loaded first.'
        );

        $makefile = file_get_contents(self::root() . '/Makefile');
        $this->assertMatchesRegularExpression('/^sortie:/m', $makefile);
        $this->assertStringContainsString('scripts/sortie-reseau.sh', $makefile);
        $this->assertFileExists(self::root() . '/scripts/sortie-reseau.sh');
    }
}
