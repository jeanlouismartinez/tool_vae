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

namespace tool_vae\local;

use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The one door of the plugin that opens onto the Internet: the latest export of France compétences (T-31, 0.47.0).
 *
 * UNTIL THIS VERSION THE PLUGIN MADE NO REQUEST OF ITS OWN, and that promise is kept as narrow as it can be. This is the only
 * class that may name an HTTP client (scripts/sortie-reseau.sh and fiche_no_background_test read the source and say so), it
 * is called by one button of the import screen and by nothing that runs on a schedule, and it goes to two hosts written
 * below: www.data.gouv.fr, which tells which file is the latest, and static.data.gouv.fr, which serves it.
 *
 *   - NOTHING THE CALLER OR THE SITE CAN CHOOSE. The dataset, the hosts, the paths, the sizes and the delays are constants. No
 *     address comes from an input or a setting. The first link is rebuilt from a constant and an identifier that has been
 *     checked; the only other address that is followed is the one the API announces for the chosen file (its field `url`),
 *     validated beforehand - https, the static host, a path under /resources/, the name of the file - and a redirection must
 *     repeat it exactly.
 *   - THE CLIENT OF THE CORE, not a bare one: it checks the certificate (the plugin writes `verify` true on every request and
 *     never asks the client to skip the checks of the core), it applies the list of blocked hosts and the proxy of the site,
 *     and a test can replace it. It is read at the moment of the request, never kept.
 *   - EVERY REDIRECTION IS JUDGED BY HAND, BEFORE IT IS FOLLOWED: three at most, each to the address announced for the chosen
 *     file, written exactly - which is, on its raw text, https://static.data.gouv.fr/resources/ and the name of the file: no
 *     port, no credentials, no other host, no address of a machine, no capital, no point at the end, no `..`, no escape.
 *     An address of the same shape that is not that one is refused too.
 *   - SIZES AND DELAYS ARE BOUNDED WHILE THE BYTES ARRIVE: the answer of the API at 512 Kio, the archive at 32 Mio, ten seconds to
 *     connect, twenty to read the API, two minutes for the archive. What arrives is the size announced and the SHA-1 announced,
 *     or it is deleted. (The SHA-1 comes from the same API: it protects against a truncated file, not against an API that lies,
 *     which is the job of the certificate.)
 *   - THE LICENCE IS CHECKED BEFORE ANYTHING IS TAKEN: the plugin writes "Licence Ouverte 2.0" next to what it copies, and the
 *     licence forbids misleading anybody about it.
 *   - NO MESSAGE CARRIES THE TEXT OF AN EXCEPTION. A refusal says what happened (the host, the cURL or HTTP code) and nothing
 *     else: that text can hold an address, a path or a header.
 *
 * The request carries no datum about anybody. It shows the address of the server (or of its proxy), the time, and a User-Agent
 * of its own that does not give the address of the site.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fiche_fetcher {
    /** @var string The dataset "Répertoire national des certifications professionnelles et répertoire spécifique". */
    public const DATASET_ID = '5eebbc067a14b6fecc9c9976';

    /** @var string The host that says which file is the latest. */
    public const HOST_API = 'www.data.gouv.fr';

    /** @var string The host that serves the files. */
    public const HOST_FILES = 'static.data.gouv.fr';

    /** @var string The dataset, on the API; "resources/" follows to list its files. */
    public const API_URL = 'https://www.data.gouv.fr/api/2/datasets/5eebbc067a14b6fecc9c9976/';

    /** @var string The stable link of a resource; its identifier follows. It redirects to the file of the day. */
    public const STABLE_URL = 'https://www.data.gouv.fr/api/1/datasets/r/';

    /** @var string The licence the dataset must state: Licence Ouverte 2.0. */
    public const LICENCE_ID = 'lov2';

    /** @var int The most bytes read from an answer of the API. */
    public const MAX_JSON_BYTES = 524288;

    /** @var int The most bytes taken for an archive. */
    public const MAX_ARCHIVE_BYTES = fiche_archive::MAX_ARCHIVE_BYTES;

    /** @var int The most redirections followed. */
    public const MAX_HOPS = 3;

    /** @var int The months looked in: the current one and the three before. */
    public const MONTHS = 4;

    /** @var int Seconds to connect. */
    public const CONNECT_TIMEOUT = 10;

    /** @var int Seconds to read an answer of the API. */
    public const JSON_TIMEOUT = 20;

    /** @var int Seconds to take the archive. */
    public const DOWNLOAD_TIMEOUT = 120;

    /** @var string What the plugin calls itself: the default of the core would give the address of the site. */
    public const USER_AGENT = 'tool_vae (Moodle plugin; import triggered by an administrator)';

    /** @var string The file of the archive in the directory of the request. */
    private const FILE = 'export.zip';

    /** @var string An identifier of a resource. */
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    /** @var string The title of the file of an export. */
    private const TITLE = '/^export-fiches-csv-(\d{4})-(\d{2})-(\d{2})\.zip$/D';

    /** @var string The address of a file of the static host, as it is written. */
    private const FILE_URL = '#^https://static\.data\.gouv\.fr/resources/[A-Za-z0-9._/~-]{1,300}$#D';

    /** @var ClientInterface|null The client given for a test; else the core's, read when it is used. */
    private ?ClientInterface $client;

    /**
     * The fetcher.
     *
     * @param ClientInterface|null $client Null to use the client of the core, which is what the plugin does.
     */
    public function __construct(?ClientInterface $client = null) {
        $this->client = $client;
    }

    /**
     * Which file is the latest export.
     *
     * Two questions to the API: is the dataset still under the Licence Ouverte 2.0, and, month after month from this one, which
     * files carry a title of the export (a prefix, `export-fiches-csv-AAAA-MM-`). Neither the order of the answer nor the date of
     * modification is trusted: the winner is the most recent DAY WRITTEN IN THE TITLE, then the later modification.
     *
     * @param int|null $now The time, for a test; else the clock of the core.
     * @return \stdClass id (the identifier of the resource), title, date (YYYY-MM-DD, the day in the title), url (the address of
     *         the file on the static host), size (bytes) and sha1 (40 hexadecimal digits), as the API announces them.
     * @throws fiche_refusal FETCH_LICENCE, FETCH_API, FETCH_NONE, FETCH_HTTP, FETCH_NETWORK, FETCH_TIMEOUT, FETCH_TLS,
     *         FETCH_BLOCKED.
     */
    public function latest(?int $now = null): \stdClass {
        $now ??= \core\di::get(\core\clock::class)->time();

        $dataset = $this->get_json(self::API_URL);
        $licence = $dataset['license'] ?? null;
        if (!is_string($licence) || !empty($dataset['private']) || !empty($dataset['archived']) || !empty($dataset['deleted'])) {
            throw new fiche_refusal(fiche_refusal::FETCH_API, ['host' => self::HOST_API], 'The dataset is not as expected.');
        }
        if ($licence !== self::LICENCE_ID) {
            throw new fiche_refusal(
                fiche_refusal::FETCH_LICENCE,
                ['licence' => mb_substr($licence, 0, 60)],
                'The dataset no longer states the Licence Ouverte 2.0.'
            );
        }

        $year = (int) gmdate('Y', $now);
        $month = (int) gmdate('n', $now);
        for ($back = 0; $back < self::MONTHS; $back++) {
            $stamp = gmmktime(0, 0, 0, $month - $back, 1, $year);
            $prefix = 'export-fiches-csv-' . gmdate('Y-m', $stamp) . '-';
            $page = $this->get_json(
                self::API_URL . 'resources/?q=' . rawurlencode($prefix) . '&page_size=100&page=1'
            );
            if (!isset($page['data']) || !is_array($page['data'])) {
                throw new fiche_refusal(fiche_refusal::FETCH_API, ['host' => self::HOST_API], 'The list of files is not there.');
            }

            $best = null;
            foreach ($page['data'] as $row) {
                $candidate = is_array($row) ? self::candidate($row, $now) : null;
                if ($candidate !== null && ($best === null || self::newer($candidate, $best))) {
                    $best = $candidate;
                }
            }
            if ($best !== null) {
                return (object) [
                    'id' => $best['id'],
                    'title' => $best['title'],
                    'date' => $best['date'],
                    'url' => $best['url'],
                    'size' => $best['size'],
                    'sha1' => $best['sha1'],
                ];
            }
        }

        throw new fiche_refusal(fiche_refusal::FETCH_NONE, ['host' => self::HOST_API], 'No export of records was found.');
    }

    /**
     * Take the archive of a resource, by its stable link, into a directory of the request.
     *
     * @param \stdClass $resource What latest() gives.
     * @param string $directory A directory of the request (make_request_directory()): the file is written there, and deleted
     *        again by this method if it is not the one announced.
     * @return string The path of the archive, <directory>/export.zip.
     * @throws fiche_refusal FETCH_REDIRECT, FETCH_HTTP, FETCH_API, FETCH_TOO_BIG, FETCH_DIGEST and the refusals of the network.
     */
    public function download(\stdClass $resource, string $directory): string {
        $id = (string) ($resource->id ?? '');
        $title = (string) ($resource->title ?? '');
        $announced = (string) ($resource->url ?? '');
        if (!preg_match(self::UUID, $id) || self::parse_title($title) === null || !self::is_file_url($announced, $title)) {
            throw new fiche_refusal(fiche_refusal::FETCH_API, ['host' => self::HOST_API], 'The resource is not one.');
        }

        $target = rtrim($directory, '/\\') . '/' . self::FILE;
        $done = false;
        try {
            $url = self::STABLE_URL . $id;
            for ($hops = 0;; $hops++) {
                $host = (string) parse_url($url, PHP_URL_HOST);
                $options = ['headers' => ['Accept' => '*/*']];
                // WHAT IS WRITTEN IS COUNTED AS IT IS WRITTEN, whatever the length announced and whatever the encoding: the file
                // stops growing at the cap. The first host has nothing to give but an address: its body is capped far lower.
                if ($host === self::HOST_FILES) {
                    $sink = self::capped_sink(fopen($target, 'w+'), self::MAX_ARCHIVE_BYTES, fiche_refusal::FETCH_TOO_BIG);
                    $options += [
                        'timeout' => self::DOWNLOAD_TIMEOUT,
                        'on_headers' => self::header_guard(self::MAX_ARCHIVE_BYTES, fiche_refusal::FETCH_TOO_BIG),
                    ];
                } else {
                    $sink = self::capped_sink(fopen('php://temp', 'w+'), self::MAX_JSON_BYTES, fiche_refusal::FETCH_API);
                    $options += [
                        'timeout' => self::JSON_TIMEOUT,
                        'on_headers' => self::header_guard(self::MAX_JSON_BYTES, fiche_refusal::FETCH_API),
                    ];
                }
                $options['sink'] = $sink;
                try {
                    $response = $this->request($url, $options);
                } finally {
                    $sink->close();
                }
                $status = $response->getStatusCode();

                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $location = $response->getHeaderLine('Location');
                    if ($location === '') {
                        throw new fiche_refusal(fiche_refusal::FETCH_API, ['host' => $host], 'A redirection with no address.');
                    }
                    // Judged BEFORE it is followed: nothing is sent to an address the plugin does not accept - and the only one it
                    // accepts is the address the API announced for this file, written exactly (the stable link of an identifier
                    // leads wherever the platform says, and that is not necessarily this file).
                    if ($hops >= self::MAX_HOPS || $location !== $announced) {
                        throw new fiche_refusal(
                            fiche_refusal::FETCH_REDIRECT,
                            ['host' => $host],
                            'A redirection leaves the addresses the plugin goes to.'
                        );
                    }
                    $url = $location;
                    continue;
                }
                if ($status !== 200) {
                    throw self::http_refusal($host, $status);
                }
                if ($host !== self::HOST_FILES) {
                    throw new fiche_refusal(
                        fiche_refusal::FETCH_API,
                        ['host' => $host],
                        'The file is not served by the file host.'
                    );
                }
                break;
            }

            $expected = (int) ($resource->size ?? 0);
            $length = $response->getHeaderLine('Content-Length');
            $size = is_file($target) ? filesize($target) : -1;
            if (
                $size !== $expected
                || ($length !== '' && (!ctype_digit($length) || (int) $length !== $expected))
                || !hash_equals(strtolower((string) ($resource->sha1 ?? '')), (string) sha1_file($target))
            ) {
                throw new fiche_refusal(
                    fiche_refusal::FETCH_DIGEST,
                    ['host' => self::HOST_FILES],
                    'The file received is not the one announced.'
                );
            }
            $done = true;

            return $target;
        } finally {
            if (!$done && is_file($target)) {
                unlink($target);
            }
        }
    }

    /**
     * The day written in the title of an export.
     *
     * @param string $title
     * @return string|null YYYY-MM-DD, null when the title is not exactly `export-fiches-csv-YYYY-MM-DD.zip` of a real day.
     */
    public static function parse_title(string $title): ?string {
        if (!preg_match(self::TITLE, $title, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return "$parts[1]-$parts[2]-$parts[3]";
    }

    /**
     * Whether an address is, as it is written, a file of the static host with the name of the export chosen.
     *
     * @param string $url The raw text of an address.
     * @param string $title The title of the export chosen.
     * @return bool
     */
    public static function is_file_url(string $url, string $title): bool {
        if (!preg_match(self::FILE_URL, $url)) {
            return false;
        }
        $path = substr($url, strlen('https://' . self::HOST_FILES));

        return !preg_match('~(^|/)\.\.?(/|$)~', $path) && basename($path) === $title;
    }

    /**
     * What an exception of the transfer comes to, as a refusal that says what happened and nothing of the exception's text.
     *
     * @param \Throwable $e
     * @return fiche_refusal
     */
    public static function classify(\Throwable $e): fiche_refusal {
        // A refusal raised inside a callback of the client arrives wrapped by it: it is looked for in the chain.
        for ($found = $e; $found !== null; $found = $found->getPrevious()) {
            if ($found instanceof fiche_refusal) {
                return $found;
            }
        }

        $host = '';
        if ($e instanceof \GuzzleHttp\Exception\RequestException || $e instanceof \GuzzleHttp\Exception\ConnectException) {
            $host = (string) $e->getRequest()->getUri()->getHost();
        }
        $context = method_exists($e, 'getHandlerContext') ? (array) $e->getHandlerContext() : [];
        $errno = (int) ($context['errno'] ?? 0);

        // The core's message for a blocked address is compared with its own string, whatever the language of the site.
        $blocked = (new \core\files\curl_security_helper())->get_blocked_url_string();
        if ($e instanceof \GuzzleHttp\Exception\RequestException && !$e->hasResponse() && $e->getMessage() === $blocked) {
            $reason = fiche_refusal::FETCH_BLOCKED;
        } else if ($errno === 28) {
            $reason = fiche_refusal::FETCH_TIMEOUT;
        } else if (in_array($errno, [35, 51, 58, 60, 77, 83], true)) {
            $reason = fiche_refusal::FETCH_TLS;
        } else if ($host === '' && $errno === 0) {
            // Neither the client's own exception nor a cURL error: not the network, and nothing to give to the host.
            return new fiche_refusal(fiche_refusal::UNEXPECTED);
        } else {
            $reason = fiche_refusal::FETCH_NETWORK;
        }

        return new fiche_refusal($reason, ['host' => $host, 'errno' => $errno]);
    }

    /**
     * A row of the list of the resources, if it is what it says it is.
     *
     * @param array $row
     * @param int $now
     * @return array|null id, title, date, url, size, sha1, modified, created; null when anything is not as expected.
     */
    private static function candidate(array $row, int $now): ?array {
        $id = $row['id'] ?? null;
        $title = $row['title'] ?? null;
        $url = $row['url'] ?? null;
        $checksum = $row['checksum'] ?? null;
        $size = $row['filesize'] ?? null;
        if (!is_string($id) || !is_string($title) || !is_string($url) || !is_array($checksum) || !is_int($size)) {
            return null;
        }
        $date = self::parse_title($title);
        $sha1 = $checksum['value'] ?? null;
        if (
            $date === null
            || $date > gmdate('Y-m-d', $now + DAYSECS)
            || !preg_match(self::UUID, $id)
            || ($row['format'] ?? null) !== 'zip'
            || ($row['filetype'] ?? null) !== 'file'
            || !self::is_file_url($url, $title)
            || ($checksum['type'] ?? null) !== 'sha1'
            || !is_string($sha1)
            || !preg_match('/^[0-9a-f]{40}$/D', $sha1)
            || $size < 1
            || $size > self::MAX_ARCHIVE_BYTES
        ) {
            return null;
        }

        return [
            'id' => $id, 'title' => $title, 'date' => $date, 'url' => $url, 'size' => $size, 'sha1' => $sha1,
            'modified' => (string) ($row['last_modified'] ?? ''), 'created' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * Whether a candidate is more recent than another: the day of the title, then the later modification, then the later
     * creation, then the identifier (so that the choice never depends on the order of the answer).
     *
     * @param array $candidate
     * @param array $other
     * @return bool
     */
    private static function newer(array $candidate, array $other): bool {
        foreach (['date', 'modified', 'created', 'id'] as $key) {
            if ($candidate[$key] !== $other[$key]) {
                return $candidate[$key] > $other[$key];
            }
        }

        return false;
    }

    /**
     * An answer of the API, decoded.
     *
     * @param string $url
     * @return array
     * @throws fiche_refusal
     */
    private function get_json(string $url): array {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $sink = self::capped_sink(fopen('php://temp', 'w+'), self::MAX_JSON_BYTES, fiche_refusal::FETCH_API);
        try {
            $response = $this->request($url, [
                'timeout' => self::JSON_TIMEOUT,
                'headers' => ['Accept' => 'application/json'],
                'on_headers' => self::header_guard(self::MAX_JSON_BYTES, fiche_refusal::FETCH_API),
                'sink' => $sink,
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw self::http_refusal($host, $status);
            }
            // Read from the sink, which holds what was written and never more than the cap.
            $body = (string) $sink;
        } finally {
            $sink->close();
        }

        try {
            $data = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $data = null;
        }
        if (!is_array($data)) {
            throw new fiche_refusal(fiche_refusal::FETCH_API, ['host' => $host], 'The answer is not the one expected.');
        }

        return $data;
    }

    /**
     * One request, with the options that make it safe.
     *
     * @param string $url
     * @param array $options timeout, headers (added to ours), on_headers, progress, sink.
     * @return ResponseInterface
     * @throws fiche_refusal
     */
    private function request(string $url, array $options): ResponseInterface {
        $options['headers'] = ($options['headers'] ?? []) + ['User-Agent' => self::USER_AGENT, 'Accept-Encoding' => 'identity'];
        // Accept-Encoding: identity is only a request: with decode_content on, the client would decode whatever a peer sends
        // all the same, and the bytes written would be a thousand times the bytes counted on the wire.
        $options += [
            'verify' => true,
            'allow_redirects' => false,
            'http_errors' => false,
            'decode_content' => false,
            'connect_timeout' => self::CONNECT_TIMEOUT,
        ];

        try {
            return ($this->client ?? \core\di::get(\core\http_client::class))->request('GET', $url, $options);
        } catch (\Throwable $e) {
            throw self::classify($e);
        }
    }

    /**
     * A stream that takes what a transfer writes into it and refuses to take more than the cap.
     *
     * The refusal is raised from the write itself: the client stops the transfer on the failed write, the file stops growing at
     * the cap, and no length announced - or left out - can make it grow further.
     *
     * @param resource $handle Where the bytes go.
     * @param int $cap The most bytes it takes.
     * @param string $reason The reason of the refusal.
     * @return \GuzzleHttp\Psr7\Stream
     */
    private static function capped_sink($handle, int $cap, string $reason): \GuzzleHttp\Psr7\Stream {
        if (!is_resource($handle)) {
            throw new fiche_refusal(fiche_refusal::UNEXPECTED, [], 'The file of the transfer cannot be opened.');
        }

        return new class ($handle, $cap, $reason) extends \GuzzleHttp\Psr7\Stream {
            /** @var int The most bytes taken. */
            private int $cap;

            /** @var string The reason of the refusal. */
            private string $reason;

            /** @var int The bytes taken so far. */
            private int $taken = 0;

            /**
             * The stream.
             *
             * @param resource $handle
             * @param int $cap
             * @param string $reason
             */
            public function __construct($handle, int $cap, string $reason) {
                parent::__construct($handle);
                $this->cap = $cap;
                $this->reason = $reason;
            }

            /**
             * Take bytes, up to the cap.
             *
             * @param string $string
             * @return int
             * @throws fiche_refusal
             */
            public function write($string): int {
                $this->taken += strlen($string);
                if ($this->taken > $this->cap) {
                    throw new fiche_refusal($this->reason, [], 'The transfer is bigger than the plugin accepts.');
                }

                return parent::write($string);
            }
        };
    }

    /**
     * A guard on the size an answer announces, raised before the body is read.
     *
     * @param int $max
     * @param string $reason
     * @return \Closure
     */
    private static function header_guard(int $max, string $reason): \Closure {
        return function (ResponseInterface $response) use ($max, $reason): void {
            $length = $response->getHeaderLine('Content-Length');
            if ($length !== '' && ctype_digit($length) && (int) $length > $max) {
                throw new fiche_refusal($reason, [], 'The answer announces more than the plugin accepts.');
            }
        };
    }

    /**
     * The refusal for an answer that is not a 200.
     *
     * @param string $host
     * @param int $status
     * @return fiche_refusal
     */
    private static function http_refusal(string $host, int $status): fiche_refusal {
        if ($status >= 400) {
            return new fiche_refusal(fiche_refusal::FETCH_HTTP, ['host' => $host, 'status' => $status]);
        }

        return new fiche_refusal(fiche_refusal::FETCH_API, ['host' => $host, 'status' => $status]);
    }
}
