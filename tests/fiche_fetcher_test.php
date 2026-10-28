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

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use tool_vae\fixtures\export_zip_builder;
use tool_vae\local\fiche_fetcher;
use tool_vae\local\fiche_refusal;

/**
 * The one door of the plugin that opens onto the Internet: the latest export of France compétences (0.47.0).
 *
 * NOTHING HERE TOUCHES THE NETWORK. Every request goes to the simulator of the core (get_mocked_http_client), which raises
 * an exception for a request nobody queued. What the simulator does not do - it does not run the middleware of the core,
 * it never calls the `progress` option - is tested apart: the refusal of a blocked host with a client built by hand, the
 * guard of the size without a Content-Length on its own.
 *
 * WHAT IS ASSERTED. That the requests are the ones expected, word for word, with the options that make them safe; that
 * the most recent export wins whatever the order of the answer and that a row which is not what it says is dropped; that
 * a redirection which leaves the door is refused BEFORE it is followed; that the size, the delay and the digest are
 * checked and a refused file is deleted; that no message carries the text of an exception; that nothing in the class lets
 * a caller choose a host.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\fiche_fetcher::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\fiche_refusal::class)]
final class fiche_fetcher_test extends \advanced_testcase {
    /** @var MockHandler The queue of answers. */
    private MockHandler $mock;

    /** @var array The requests made, with their options. */
    private array $history = [];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/export_zip_builder.php');
        $this->resetAfterTest();
        $client = $this->get_mocked_http_client($this->history);
        $this->mock = $client['mock'];
        // The first of November 2026, 02:30 UTC: the new month has no export yet.
        $this->mock_clock_with_frozen(gmmktime(2, 30, 0, 11, 1, 2026));
    }

    /**
     * A UUID derived from a seed.
     *
     * @param string $seed
     * @return string
     */
    private static function uuid(string $seed): string {
        $hash = md5($seed);

        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-4' . substr($hash, 13, 3) . '-8' . substr($hash, 17, 3)
            . '-' . substr($hash, 20, 12);
    }

    /**
     * The address of a file of the static host, as the API gives it.
     *
     * @param string $date AAAA-MM-JJ
     * @param string|null $title
     * @return string
     */
    private static function static_url(string $date, ?string $title = null): string {
        return 'https://static.data.gouv.fr/resources/repertoire-national-des-certifications-professionnelles-et-repertoire'
            . '-specifique/' . str_replace('-', '', $date) . '-020002/' . ($title ?? "export-fiches-csv-$date.zip");
    }

    /**
     * A row of the list of the resources, as the API of data.gouv.fr gives it.
     *
     * @param string $date AAAA-MM-JJ
     * @param array $override
     * @return array
     */
    private static function row(string $date, array $override = []): array {
        return $override + [
            'id' => self::uuid($date),
            'title' => "export-fiches-csv-$date.zip",
            'filetype' => 'file',
            'format' => 'zip',
            'url' => self::static_url($date),
            'checksum' => ['type' => 'sha1', 'value' => sha1($date)],
            'filesize' => 9700000,
            'created_at' => "{$date}T02:00:03.908000+00:00",
            'last_modified' => "{$date}T02:00:08.465000+00:00",
        ];
    }

    /**
     * A JSON answer.
     *
     * @param array $data
     * @param int $status
     * @return Response
     */
    private static function json(array $data, int $status = 200): Response {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data));
    }

    /**
     * The answer to the question about the dataset.
     *
     * @param array $override
     * @return Response
     */
    private static function dataset(array $override = []): Response {
        return self::json($override + [
            'id' => fiche_fetcher::DATASET_ID, 'license' => 'lov2', 'private' => false, 'archived' => null, 'deleted' => null,
        ]);
    }

    /**
     * The answer to the question about the resources of a month.
     *
     * @param array[] $rows
     * @return Response
     */
    private static function page(array $rows): Response {
        return self::json(['data' => $rows, 'page' => 1, 'page_size' => 100, 'total' => count($rows)]);
    }

    /**
     * The refusal a call ends with.
     *
     * @param callable $call
     * @return fiche_refusal
     */
    private function refused(callable $call): fiche_refusal {
        try {
            $call();
        } catch (fiche_refusal $refusal) {
            return $refusal;
        }
        $this->fail('The call must be refused.');
    }

    public function test_latest_asks_the_dataset_then_the_month_prefix(): void {
        $this->mock->append(
            self::dataset(),
            self::page([]),
            self::page([self::row('2026-10-26'), self::row('2026-10-27')])
        );

        $latest = (new fiche_fetcher())->latest();

        $this->assertSame(self::uuid('2026-10-27'), $latest->id);
        $this->assertSame('export-fiches-csv-2026-10-27.zip', $latest->title);
        $this->assertSame('2026-10-27', $latest->date);
        $this->assertSame(9700000, $latest->size);
        $this->assertSame(sha1('2026-10-27'), $latest->sha1);

        $api = 'https://www.data.gouv.fr/api/2/datasets/5eebbc067a14b6fecc9c9976/';
        $this->assertSame([
            $api,
            $api . 'resources/?q=export-fiches-csv-2026-11-&page_size=100&page=1',
            $api . 'resources/?q=export-fiches-csv-2026-10-&page_size=100&page=1',
        ], array_map(fn($entry) => (string) $entry['request']->getUri(), $this->history));

        foreach ($this->history as $entry) {
            $options = $entry['options'];
            $this->assertTrue($options['verify'], 'The certificate is always checked.');
            $this->assertFalse($options['allow_redirects'], 'A redirection is judged by hand.');
            $this->assertFalse($options['http_errors']);
            $this->assertFalse($options['decode_content'], 'What is counted is what is written: nothing is decoded on the way.');
            $this->assertSame(fiche_fetcher::CONNECT_TIMEOUT, $options['connect_timeout']);
            $this->assertSame(fiche_fetcher::JSON_TIMEOUT, $options['timeout']);
            $this->assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $options['sink'], 'The body goes to a capped flow.');
            $this->assertArrayNotHasKey('ignoresecurity', $options);
            $this->assertSame(fiche_fetcher::USER_AGENT, $entry['request']->getHeaderLine('User-Agent'));
            $this->assertSame('identity', $entry['request']->getHeaderLine('Accept-Encoding'));
        }
        $this->assertStringNotContainsString($GLOBALS['CFG']->wwwroot, fiche_fetcher::USER_AGENT);
    }

    public function test_the_most_recent_wins_whatever_the_order(): void {
        $rows = [self::row('2026-10-25'), self::row('2026-10-27'), self::row('2026-10-26')];
        foreach ([$rows, array_reverse($rows), [$rows[1], $rows[0], $rows[2]]] as $order) {
            $this->mock->append(self::dataset(), self::page([]), self::page($order));
            $this->assertSame('2026-10-27', (new fiche_fetcher())->latest()->date);
        }

        // The same day twice: the later modification wins, then the later creation, then the identifier.
        $first = self::row('2026-10-27', ['id' => self::uuid('a'), 'last_modified' => '2026-10-27T03:00:00+00:00']);
        $second = self::row('2026-10-27', ['id' => self::uuid('b'), 'last_modified' => '2026-10-27T04:00:00+00:00']);
        $this->mock->append(self::dataset(), self::page([]), self::page([$first, $second]));
        $this->assertSame(self::uuid('b'), (new fiche_fetcher())->latest()->id);

        // The field `url` of the answer is validated and handed on: download() accepts no other address than that one, and
        // asks for the file by the stable link of its identifier, never by this address.
        $this->mock->append(self::dataset(), self::page([]), self::page([self::row('2026-10-27')]));
        $latest = (new fiche_fetcher())->latest();
        $this->assertSame(self::static_url('2026-10-27'), $latest->url);
    }

    /**
     * Rows that are not what they say.
     *
     * @return array
     */
    public static function bad_rows(): array {
        $d = '2026-10-27';

        return [
            'a backup copy' => [['title' => "export-fiches-csv-$d.zip.bak"]],
            'upper case' => [['title' => "EXPORT-FICHES-CSV-$d.zip"]],
            'another export' => [['title' => "export-fiches-csv-v2-$d.zip"]],
            'impossible date' => [['title' => 'export-fiches-csv-2026-13-45.zip']],
            // Title and address say the same day, so that only the rule of the date can drop the row (the clock is 1 Nov 2026).
            'a date after tomorrow' => [[
                'title' => 'export-fiches-csv-2026-11-05.zip', 'url' => self::static_url('2026-11-05'),
            ]],
            'two days from now' => [[
                'title' => 'export-fiches-csv-2026-11-03.zip', 'url' => self::static_url('2026-11-03'),
            ]],
            'another host' => [['url' => "https://evil.example/resources/x/export-fiches-csv-$d.zip"]],
            'not https' => [['url' => str_replace('https', 'http', self::static_url($d))]],
            'with a port' => [['url' => str_replace('static.data.gouv.fr', 'static.data.gouv.fr:8443', self::static_url($d))]],
            'with credentials' => [['url' => str_replace('//static', '//u:p@static', self::static_url($d))]],
            'outside resources' => [['url' => "https://static.data.gouv.fr/other/export-fiches-csv-$d.zip"]],
            'another file name' => [['url' => self::static_url($d, 'autre.zip')]],
            'a csv' => [['format' => 'csv']],
            'a remote file' => [['filetype' => 'remote']],
            'no digest' => [['checksum' => null]],
            'a short digest' => [['checksum' => ['type' => 'sha1', 'value' => 'abcd']]],
            'a digest that is not hexadecimal' => [['checksum' => ['type' => 'sha1', 'value' => str_repeat('z', 40)]]],
            'a size of zero' => [['filesize' => 0]],
            'a negative size' => [['filesize' => -5]],
            'a size over the cap' => [['filesize' => 40 * 1024 * 1024]],
            'an identifier that is not one' => [['id' => '../../x']],
        ];
    }

    /**
     * A row that is not what it says is dropped, and nothing is chosen from it.
     *
     * @param array $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_rows')]
    public function test_a_row_that_is_not_what_it_says_is_dropped(array $override): void {
        $row = self::row('2026-10-27', $override);
        $this->mock->append(self::dataset(), self::page([$row]), self::page([$row]), self::page([$row]), self::page([$row]));

        $refusal = $this->refused(fn() => (new fiche_fetcher())->latest());

        $this->assertSame(fiche_refusal::FETCH_NONE, $refusal->reason());
        $this->assertCount(5, $this->history, 'The dataset and the four months were asked.');
    }

    public function test_the_licence_is_checked(): void {
        $this->mock->append(self::dataset(['license' => 'etalab-2.0']));
        $refusal = $this->refused(fn() => (new fiche_fetcher())->latest());
        $this->assertSame(fiche_refusal::FETCH_LICENCE, $refusal->reason());
        $this->assertSame(['licence' => 'etalab-2.0'], $refusal->params());

        $this->mock->append(self::json(['id' => fiche_fetcher::DATASET_ID, 'private' => false]));
        $this->assertSame(
            fiche_refusal::FETCH_API,
            $this->refused(fn() => (new fiche_fetcher())->latest())->reason(),
            'No licence: the answer is not the one expected.'
        );

        $this->mock->append(self::dataset(['private' => true]));
        $this->assertSame(
            fiche_refusal::FETCH_API,
            $this->refused(fn() => (new fiche_fetcher())->latest())->reason(),
            'A private dataset.'
        );
    }

    public function test_an_http_error_or_a_strange_answer_is_named(): void {
        foreach ([503, 404, 429] as $status) {
            $this->mock->append(new Response($status, [], 'peu importe'));
            $refusal = $this->refused(fn() => (new fiche_fetcher())->latest());
            $this->assertSame(fiche_refusal::FETCH_HTTP, $refusal->reason());
            $this->assertSame($status, $refusal->params()['status']);
            $this->assertSame('www.data.gouv.fr', $refusal->params()['host']);
        }

        $strange = [
            'not json' => new Response(200, [], '<html>pas du json</html>'),
            'a list' => new Response(200, [], '[1,2,3]'),
            'too heavy' => new Response(200, ['Content-Length' => (string) (fiche_fetcher::MAX_JSON_BYTES + 1)], '{}'),
            'too heavy and unannounced' => new Response(200, [], '{"x": "' . str_repeat('a', fiche_fetcher::MAX_JSON_BYTES) . '"}'),
            'too deep' => new Response(200, [], str_repeat('[', 40) . str_repeat(']', 40)),
        ];
        foreach ($strange as $label => $response) {
            $this->mock->append($response);
            $this->assertSame(
                fiche_refusal::FETCH_API,
                $this->refused(fn() => (new fiche_fetcher())->latest())->reason(),
                $label
            );
        }

        $this->mock->append(self::dataset(), self::json(['page' => 1]));
        $this->assertSame(
            fiche_refusal::FETCH_API,
            $this->refused(fn() => (new fiche_fetcher())->latest())->reason(),
            'No list of resources.'
        );
    }

    public function test_four_empty_months_stop_there(): void {
        $this->mock->append(self::dataset(), self::page([]), self::page([]), self::page([]), self::page([]), self::page([]));

        $refusal = $this->refused(fn() => (new fiche_fetcher())->latest());

        $this->assertSame(fiche_refusal::FETCH_NONE, $refusal->reason());
        $this->assertCount(5, $this->history);
        $this->assertSame(1, $this->mock->count(), 'No further question was asked.');
    }

    public function test_download_follows_one_hop_and_writes_the_file(): void {
        $zip = export_zip_builder::build(make_request_directory(), 'export-fiches-csv-2026-10-27.zip');
        $bytes = file_get_contents($zip);
        $resource = (object) [
            'id' => self::uuid('2026-10-27'), 'title' => 'export-fiches-csv-2026-10-27.zip', 'date' => '2026-10-27',
            'url' => self::static_url('2026-10-27'), 'size' => strlen($bytes), 'sha1' => sha1($bytes),
        ];
        $this->mock->append(
            new Response(302, ['Location' => self::static_url('2026-10-27')]),
            new Response(200, ['Content-Length' => (string) strlen($bytes)], $bytes)
        );
        $directory = make_request_directory();

        $path = (new fiche_fetcher())->download($resource, $directory);

        $this->assertSame($directory . '/export.zip', $path);
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertSame([
            'https://www.data.gouv.fr/api/1/datasets/r/' . self::uuid('2026-10-27'),
            self::static_url('2026-10-27'),
        ], array_map(fn($entry) => (string) $entry['request']->getUri(), $this->history));
        $this->assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $this->history[0]['options']['sink']);
        $this->assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $this->history[1]['options']['sink']);
        // The caps are the ones of the two kinds of answer: the sink of the file is wired to the cap of the archive.
        $cap = fn($sink) => (new \ReflectionObject($sink))->getProperty('cap')->getValue($sink);
        $this->assertSame(fiche_fetcher::MAX_JSON_BYTES, $cap($this->history[0]['options']['sink']));
        $this->assertSame(fiche_fetcher::MAX_ARCHIVE_BYTES, $cap($this->history[1]['options']['sink']));
        $this->assertSame(
            fiche_fetcher::JSON_TIMEOUT,
            $this->history[0]['options']['timeout'],
            'The first hop is a short question.'
        );
        $this->assertSame(fiche_fetcher::DOWNLOAD_TIMEOUT, $this->history[1]['options']['timeout']);
        foreach ($this->history as $entry) {
            $this->assertFalse($entry['options']['allow_redirects']);
            $this->assertFalse($entry['options']['decode_content']);
            $this->assertTrue($entry['options']['verify']);
            $this->assertSame('identity', $entry['request']->getHeaderLine('Accept-Encoding'));
        }
    }

    /**
     * Locations that leave the door.
     *
     * @return array
     */
    public static function bad_locations(): array {
        $title = 'export-fiches-csv-2026-10-27.zip';
        $good = 'https://static.data.gouv.fr/resources/repertoire/20261027-020002/';

        return [
            'not https' => ['http://static.data.gouv.fr/resources/x/' . $title],
            'a port' => ['https://static.data.gouv.fr:8443/resources/x/' . $title],
            'another host' => ['https://evil.example/resources/x/' . $title],
            'credentials in front' => ['https://static.data.gouv.fr@evil.example/resources/x/' . $title],
            'a neighbour host' => ['https://static.data.gouv.fr.evil.example/resources/x/' . $title],
            'an IPv4 address' => ['https://127.0.0.1/resources/x/' . $title],
            'an IPv6 address' => ['https://[::1]/resources/x/' . $title],
            'a decimal address' => ['https://2130706433/resources/x/' . $title],
            'a hexadecimal address' => ['https://0x7f000001/resources/x/' . $title],
            'upper case host' => ['https://STATIC.data.gouv.fr/resources/x/' . $title],
            'a final dot' => ['https://static.data.gouv.fr./resources/x/' . $title],
            'outside resources' => ['https://static.data.gouv.fr/other/x/' . $title],
            'another file name' => [$good . 'autre.zip'],
            'relative' => ['/resources/x/' . $title],
            'encoded dots' => ['https://static.data.gouv.fr/resources/%2e%2e/x/' . $title],
            'dots' => ['https://static.data.gouv.fr/resources/../x/' . $title],
            'a query' => [$good . $title . '?next=https://evil.example/'],
            'the right shape, another day' => [$good . 'export-fiches-csv-2026-10-26.zip'],
            'the right shape, another folder' => ['https://static.data.gouv.fr/resources/autre-jeu/20261027-020002/' . $title],
        ];
    }

    /**
     * A redirection that leaves the door is refused before it is followed.
     *
     * @param string $location
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_locations')]
    public function test_a_redirect_that_leaves_the_door_is_refused_before_it_is_followed(string $location): void {
        $resource = (object) [
            'id' => self::uuid('2026-10-27'), 'title' => 'export-fiches-csv-2026-10-27.zip', 'date' => '2026-10-27',
            'url' => self::static_url('2026-10-27'), 'size' => 10, 'sha1' => sha1('x'),
        ];
        $this->mock->append(
            new Response(302, ['Location' => $location]),
            new Response(200, ['Content-Length' => '1'], 'x')
        );
        $directory = make_request_directory();

        $refusal = $this->refused(fn() => (new fiche_fetcher())->download($resource, $directory));

        $this->assertSame(fiche_refusal::FETCH_REDIRECT, $refusal->reason());
        $this->assertSame(1, $this->mock->count(), 'The next answer was not asked for: the request was not sent.');
        $this->assertCount(1, $this->history);
        $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])), 'No file is left.');
    }

    public function test_a_chain_of_redirects_ends_at_the_fourth_hop(): void {
        $resource = (object) [
            'id' => self::uuid('2026-10-27'), 'title' => 'export-fiches-csv-2026-10-27.zip', 'date' => '2026-10-27',
            'url' => self::static_url('2026-10-27'), 'size' => 10, 'sha1' => sha1('x'),
        ];
        $static = self::static_url('2026-10-27');
        for ($i = 0; $i < 6; $i++) {
            $this->mock->append(new Response(302, ['Location' => $static]));
        }
        $directory = make_request_directory();

        $refusal = $this->refused(fn() => (new fiche_fetcher())->download($resource, $directory));

        $this->assertSame(fiche_refusal::FETCH_REDIRECT, $refusal->reason());
        // The first request, then MAX_HOPS redirections followed, then the one that is refused before it is sent: no more.
        $this->assertCount(fiche_fetcher::MAX_HOPS + 1, $this->history);
        $this->assertSame(6 - (fiche_fetcher::MAX_HOPS + 1), $this->mock->count(), 'The others were not asked for.');
        $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])));
    }

    public function test_a_direct_answer_or_a_redirect_without_location_is_refused(): void {
        $resource = (object) [
            'id' => self::uuid('2026-10-27'), 'title' => 'export-fiches-csv-2026-10-27.zip', 'date' => '2026-10-27',
            'url' => self::static_url('2026-10-27'), 'size' => 1, 'sha1' => sha1('x'),
        ];
        $directory = make_request_directory();

        $this->mock->append(new Response(200, ['Content-Length' => '1'], 'x'));
        $this->assertSame(
            fiche_refusal::FETCH_API,
            $this->refused(fn() => (new fiche_fetcher())->download($resource, $directory))->reason(),
            'A 200 straight from the first host.'
        );

        $this->mock->append(new Response(302, []));
        $this->assertSame(
            fiche_refusal::FETCH_API,
            $this->refused(fn() => (new fiche_fetcher())->download($resource, $directory))->reason(),
            'A redirection without a Location.'
        );

        foreach ([404, 429, 503] as $status) {
            $this->mock->append(new Response($status, [], ''));
            $refusal = $this->refused(fn() => (new fiche_fetcher())->download($resource, $directory));
            $this->assertSame(fiche_refusal::FETCH_HTTP, $refusal->reason());
            $this->assertSame($status, $refusal->params()['status']);

            $this->mock->append(new Response(302, ['Location' => self::static_url('2026-10-27')]), new Response($status, [], ''));
            $refusal = $this->refused(fn() => (new fiche_fetcher())->download($resource, $directory));
            $this->assertSame(fiche_refusal::FETCH_HTTP, $refusal->reason(), "$status on the second hop");
            $this->assertSame('static.data.gouv.fr', $refusal->params()['host']);
        }
        $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])));
    }

    public function test_size_and_digest_are_checked(): void {
        $bytes = str_repeat('0123456789', 100);
        $resource = (object) [
            'id' => self::uuid('2026-10-27'), 'title' => 'export-fiches-csv-2026-10-27.zip', 'date' => '2026-10-27',
            'url' => self::static_url('2026-10-27'), 'size' => strlen($bytes), 'sha1' => sha1($bytes),
        ];
        $hop = new Response(302, ['Location' => self::static_url('2026-10-27')]);
        $cases = [
            'a Content-Length over the cap' => [
                new Response(200, ['Content-Length' => (string) (fiche_fetcher::MAX_ARCHIVE_BYTES + 1)], $bytes),
                fiche_refusal::FETCH_TOO_BIG,
            ],
            'a Content-Length that is not the size announced' => [
                new Response(200, ['Content-Length' => (string) (strlen($bytes) + 1)], $bytes . 'x'),
                fiche_refusal::FETCH_DIGEST,
            ],
            'a body that is not the size announced' => [
                new Response(200, ['Content-Length' => (string) strlen($bytes)], substr($bytes, 0, 500)),
                fiche_refusal::FETCH_DIGEST,
            ],
            'a digest that differs' => [
                new Response(200, ['Content-Length' => (string) strlen($bytes)], str_repeat('9876543210', 100)),
                fiche_refusal::FETCH_DIGEST,
            ],
        ];
        foreach ($cases as $label => [$answer, $reason]) {
            $this->mock->append($hop, $answer);
            $directory = make_request_directory();

            $refusal = $this->refused(fn() => (new fiche_fetcher())->download($resource, $directory));

            $this->assertSame($reason, $refusal->reason(), $label);
            $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])), "$label: the file is deleted.");
        }

        // The exact bytes pass.
        $this->mock->append($hop, new Response(200, ['Content-Length' => (string) strlen($bytes)], $bytes));
        $directory = make_request_directory();
        $this->assertSame($bytes, file_get_contents((new fiche_fetcher())->download($resource, $directory)));
    }

    public function test_a_flow_never_grows_past_its_cap(): void {
        // What the transfer writes is counted where it is written: no length announced, or left out, can make a file grow.
        $method = new \ReflectionMethod(fiche_fetcher::class, 'capped_sink');
        $handle = fopen('php://temp', 'w+');
        $sink = $method->invoke(null, $handle, 5, fiche_refusal::FETCH_TOO_BIG);

        $this->assertSame(3, $sink->write('abc'));
        $this->assertSame(2, $sink->write('de'), 'Exactly the cap is taken.');
        try {
            $sink->write('f');
            $this->fail('A byte past the cap must stop the transfer.');
        } catch (fiche_refusal $refusal) {
            $this->assertSame(fiche_refusal::FETCH_TOO_BIG, $refusal->reason());
        }
        $this->assertSame('abcde', (string) $sink, 'Nothing past the cap was written.');

        // A handle that did not open is not a flow.
        $this->assertSame(
            fiche_refusal::UNEXPECTED,
            $this->refused(fn() => $method->invoke(null, false, 5, fiche_refusal::FETCH_TOO_BIG))->reason()
        );
    }

    public function test_the_size_is_judged_on_what_was_written_when_no_length_is_announced(): void {
        // No Content-Length at all: the size is judged on what was written, and a file that is not the one announced is refused.
        $bytes = str_repeat('0123456789', 100);
        $resource = (object) [
            'id' => self::uuid('2026-10-27'), 'title' => 'export-fiches-csv-2026-10-27.zip', 'date' => '2026-10-27',
            'url' => self::static_url('2026-10-27'), 'size' => 10, 'sha1' => sha1($bytes),
        ];
        $this->mock->append(new Response(302, ['Location' => self::static_url('2026-10-27')]), new Response(200, [], $bytes));
        $directory = make_request_directory();

        $refusal = $this->refused(fn() => (new fiche_fetcher())->download($resource, $directory));

        $this->assertSame(fiche_refusal::FETCH_DIGEST, $refusal->reason());
        $this->assertSame([], array_values(array_diff(scandir($directory), ['.', '..'])));
    }

    public function test_a_resource_without_the_announced_address_is_not_downloaded(): void {
        foreach ([null, '', 'https://evil.example/export-fiches-csv-2026-10-27.zip', self::static_url('2026-10-26')] as $url) {
            $resource = (object) [
                'id' => self::uuid('2026-10-27'), 'title' => 'export-fiches-csv-2026-10-27.zip', 'date' => '2026-10-27',
                'size' => 10, 'sha1' => sha1('x'),
            ];
            if ($url !== null) {
                $resource->url = $url;
            }

            $refusal = $this->refused(fn() => (new fiche_fetcher())->download($resource, make_request_directory()));

            $this->assertSame(fiche_refusal::FETCH_API, $refusal->reason(), (string) $url);
        }
        $this->assertCount(0, $this->history, 'Nothing was asked.');
    }

    public function test_an_exception_is_classified_without_its_text(): void {
        $request = new Request('GET', 'https://static.data.gouv.fr/resources/x/export-fiches-csv-2026-10-27.zip');
        $secret = 'cURL error 99: secret detail at /var/www/private';

        $kinds = [
            6 => fiche_refusal::FETCH_NETWORK, 7 => fiche_refusal::FETCH_NETWORK, 28 => fiche_refusal::FETCH_TIMEOUT,
        ];
        foreach ($kinds as $errno => $reason) {
            $refusal = fiche_fetcher::classify(new ConnectException($secret, $request, null, ['errno' => $errno]));
            $this->assertSame($reason, $refusal->reason(), "cURL $errno");
            $this->assertSame($errno, $refusal->params()['errno']);
            $this->assertSame('static.data.gouv.fr', $refusal->params()['host']);
        }
        foreach ([35, 51, 58, 60, 77, 83] as $errno) {
            $refusal = fiche_fetcher::classify(new RequestException($secret, $request, null, null, ['errno' => $errno]));
            $this->assertSame(fiche_refusal::FETCH_TLS, $refusal->reason(), "cURL $errno");
        }

        // The message of the core for a blocked address is recognised by COMPARISON with its own string, whatever the language.
        $blocked = (new \core\files\curl_security_helper())->get_blocked_url_string();
        $this->assertSame(
            fiche_refusal::FETCH_BLOCKED,
            fiche_fetcher::classify(new RequestException($blocked, $request))->reason()
        );
        $this->assertNotSame(
            fiche_refusal::FETCH_BLOCKED,
            fiche_fetcher::classify(new RequestException('The URL is blocked, says somebody else.', $request))->reason()
        );

        // A refusal buried in the chain of previous exceptions is found (Guzzle wraps what a callback throws).
        $inner = new fiche_refusal(fiche_refusal::FETCH_TOO_BIG, ['host' => 'static.data.gouv.fr']);
        $wrapped = new RequestException('An error was encountered during the on_headers event', $request, null, $inner);
        $this->assertSame($inner, fiche_fetcher::classify($wrapped));

        foreach ([new \RuntimeException($secret), new ConnectException($secret, $request, null, ['errno' => 6])] as $e) {
            $params = fiche_fetcher::classify($e)->params();
            $this->assertStringNotContainsString('secret', json_encode($params));
            $this->assertStringNotContainsString('cURL error', json_encode($params));
            $this->assertStringNotContainsString('secret', fiche_fetcher::classify($e)->getMessage());
        }
    }

    public function test_a_host_blocked_by_moodle_is_named(): void {
        // The simulator of the core skips the middleware; a client built by hand runs it, with a helper that blocks.
        $mock = new MockHandler([self::dataset()]);
        $helper = new class extends \core\files\curl_security_helper {
            #[\Override]
            public function url_is_blocked($urlstring, $notused = null) {
                return true;
            }
        };
        \core\di::set(\core\http_client::class, new \core\http_client(['mock' => $mock, 'securityhelper' => $helper]));

        $refusal = $this->refused(fn() => (new fiche_fetcher())->latest());

        // The middleware of the core notes the blocked address in the debugging log, and that is expected here.
        $this->assertDebuggingCalled();
        $this->assertSame(fiche_refusal::FETCH_BLOCKED, $refusal->reason());
        $this->assertSame('www.data.gouv.fr', $refusal->params()['host']);
        $this->assertSame(1, $mock->count(), 'Nothing was sent.');
    }

    public function test_the_client_is_read_when_it_is_used(): void {
        // Built first, with the client of the previous setUp; the simulator is installed after.
        \core\di::set(\core\http_client::class, new \core\http_client(['mock' => new MockHandler([])]));
        $fetcher = new fiche_fetcher();
        $client = $this->get_mocked_http_client($this->history);
        $client['mock']->append(self::dataset(), self::page([]), self::page([self::row('2026-10-27')]));

        $this->assertSame('2026-10-27', $fetcher->latest()->date);
    }

    public function test_the_title_and_the_address_of_a_file_are_read_strictly(): void {
        $this->assertSame('2026-10-27', fiche_fetcher::parse_title('export-fiches-csv-2026-10-27.zip'));
        $bads = [
            'export-fiches-csv-2026-10-27.zip.bak', 'Export-fiches-csv-2026-10-27.zip', 'export-fiches-csv-2026-13-45.zip',
            'export-fiches-csv-2026-02-30.zip', ' export-fiches-csv-2026-10-27.zip', 'export-fiches-csv-2026-10-27.zip ',
        ];
        foreach ($bads as $bad) {
            $this->assertNull(fiche_fetcher::parse_title($bad), $bad);
        }

        $title = 'export-fiches-csv-2026-10-27.zip';
        $this->assertTrue(fiche_fetcher::is_file_url(self::static_url('2026-10-27'), $title));
        foreach (['/resources/../x/', '/resources/./x/', '/resources/x/..//', '/resources/%2e%2e/x/'] as $path) {
            $this->assertFalse(fiche_fetcher::is_file_url("https://static.data.gouv.fr$path$title", $title), $path);
        }
        $this->assertFalse(fiche_fetcher::is_file_url(self::static_url('2026-10-27', 'autre.zip'), $title));
        $this->assertFalse(fiche_fetcher::is_file_url("https://static.data.gouv.fr/resources/x/$title\n", $title));
    }

    public function test_there_is_no_way_to_change_the_host(): void {
        $class = new \ReflectionClass(fiche_fetcher::class);

        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (in_array($method->getName(), ['parse_title', 'is_file_url', 'classify', '__construct'], true)) {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                $this->assertNotContains(
                    $parameter->getName(),
                    ['host', 'url', 'uri', 'address', 'dataset'],
                    "{$method->getName()}() must not let a caller choose where to go."
                );
            }
        }

        $source = file_get_contents($class->getFileName());
        $this->assertStringNotContainsString('get_config', $source);
        $this->assertStringNotContainsString('ignoresecurity', $source);
        $this->assertDoesNotMatchRegularExpression("/'verify'\\s*=>\\s*(false|0)/", $source);
        $this->assertDoesNotMatchRegularExpression("/'allow_redirects'\\s*=>\\s*true/", $source);
    }

    public function test_a_row_dated_tomorrow_is_kept_and_the_day_after_is_not(): void {
        // The clock is 1 November 2026, 02:30 UTC: tomorrow is the 2nd (an administrator west of Greenwich).
        $tomorrow = self::row('2026-11-02', ['url' => self::static_url('2026-11-02')]);
        $this->mock->append(self::dataset(), self::page([$tomorrow]));

        $this->assertSame('2026-11-02', (new fiche_fetcher())->latest()->date);
    }

    public function test_an_archived_or_deleted_dataset_is_not_asked_for_its_resources(): void {
        foreach ([['archived' => '2026-01-01T00:00:00+00:00'], ['deleted' => '2026-01-01T00:00:00+00:00']] as $override) {
            $this->mock->append(self::dataset($override));

            $refusal = $this->refused(fn() => (new fiche_fetcher())->latest());

            $this->assertSame(fiche_refusal::FETCH_API, $refusal->reason(), json_encode($override));
        }
        $this->assertCount(2, $this->history, 'Only the dataset was asked about, twice.');
    }

    public function test_the_questions_to_the_api_are_capped_and_guarded(): void {
        $this->mock->append(self::dataset(), self::page([]), self::page([self::row('2026-10-27')]));

        (new fiche_fetcher())->latest();

        $cap = fn($sink) => (new \ReflectionObject($sink))->getProperty('cap')->getValue($sink);
        foreach ($this->history as $entry) {
            $this->assertSame(fiche_fetcher::MAX_JSON_BYTES, $cap($entry['options']['sink']));
            $this->assertIsCallable($entry['options']['on_headers']);
        }
    }

    public function test_an_exception_that_is_not_the_network_is_not_reported_as_the_network(): void {
        $refusal = fiche_fetcher::classify(new \TypeError('a mistake in the code'));

        $this->assertSame(fiche_refusal::UNEXPECTED, $refusal->reason());
    }
}
