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

use tool_vae\local\display_date;
use tool_vae\local\fiche_archive;
use tool_vae\local\fiche_messages;
use tool_vae\local\fiche_refusal;

/**
 * From a refusal to a sentence a person who is not a developer can read (0.47.0).
 *
 * WHAT IS ASSERTED. That every reason has its sentence, in English and in French, and that the sentence renders without a
 * marker or a parameter left over; that what comes from the file or from the server never reaches the page unescaped nor
 * longer than sixty characters; that the figures a sentence carries (the limit of the upload, the date of the export) are
 * the real ones; that a refusal of the network names the host and the cURL or HTTP code and never the text of an
 * exception; that every refusal of the export says nothing was written, and every refusal of the fetch points to the upload.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\fiche_messages::class)]
final class fiche_messages_test extends \advanced_testcase {
    /**
     * The reasons a refusal can have, but the one that carries a sentence already written.
     *
     * @return string[] Constant name => reason.
     */
    private static function reasons(): array {
        $reasons = [];
        foreach ((new \ReflectionClass(fiche_refusal::class))->getConstants() as $name => $value) {
            if (is_string($value) && $name !== 'MOODLE') {
                $reasons[$name] = $value;
            }
        }

        return $reasons;
    }

    /**
     * The strings of a language file of the plugin.
     *
     * @param string $language
     * @return string[]
     */
    private static function strings(string $language): array {
        $string = [];
        include(__DIR__ . "/../lang/$language/tool_vae.php");

        return $string;
    }

    /**
     * Parameters a refusal can carry, all at once: a sentence uses the ones it needs.
     *
     * @return array
     */
    private static function params(): array {
        return [
            'number' => 'RNCP99001', 'codes' => 'RNCP99001BC09', 'code' => 'U+202E', 'file' => 'Standard',
            'column' => 'Intitule', 'name' => 'export.zip', 'token' => 'RNCP1 OR 1=1', 'max' => '5',
            'host' => 'static.data.gouv.fr',
            'status' => 503, 'errno' => 6, 'licence' => 'etalab-2.0',
        ];
    }

    public function test_every_reason_has_its_sentence_in_english_and_in_french(): void {
        $english = self::strings('en');
        $french = self::strings('fr');

        $this->assertGreaterThan(30, count(self::reasons()));
        foreach (self::reasons() as $name => $reason) {
            $this->assertArrayHasKey('fcimporterror' . $reason, $english, "en, $name");
            $this->assertArrayHasKey('fcimporterror' . $reason, $french, "fr, $name");
            $this->assertSame(
                (new fiche_refusal($reason))->lang_key(),
                'fcimporterror' . $reason
            );
        }
        $this->assertArrayHasKey('fcimporterrordetail', $english);
        $this->assertArrayHasKey('fcimporterrordetail', $french);
    }

    public function test_every_sentence_renders_without_a_marker_or_a_parameter_left_over(): void {
        $this->resetAfterTest();

        foreach (self::reasons() as $name => $reason) {
            $html = fiche_messages::html(new fiche_refusal($reason, self::params()), make_timestamp(2026, 10, 27, 12));

            $this->assertNotSame('', trim(strip_tags($html)), $name);
            $this->assertStringNotContainsString('[[', $html, $name);
            $this->assertStringNotContainsString('{$a', $html, $name);
        }
    }

    public function test_what_comes_from_the_file_or_the_server_is_escaped_and_cut(): void {
        $hostile = '<img src=x onerror=alert(1)>';
        $refusal = new fiche_refusal(fiche_refusal::BAD_BLOC, ['number' => $hostile, 'codes' => $hostile . str_repeat('x', 5000)]);

        $html = fiche_messages::html($refusal);

        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertLessThan(1500, strlen($html), 'A text of five thousand characters is cut to sixty.');
        $this->assertSame('abc…', fiche_messages::shorten('abcdef', 3));
        $this->assertSame('abcdef', fiche_messages::shorten('abcdef', 60));
        $this->assertLessThanOrEqual(61, mb_strlen(fiche_messages::shorten(str_repeat('é', 500))));
    }

    public function test_the_figures_in_a_sentence_are_the_real_ones(): void {
        $this->resetAfterTest();

        $upload = fiche_messages::html(new fiche_refusal(fiche_refusal::UPLOAD));
        $this->assertStringContainsString(display_size(get_max_upload_file_size()), $upload);

        $big = fiche_messages::html(new fiche_refusal(fiche_refusal::ZIP_TOO_BIG));
        $this->assertStringContainsString(display_size(fiche_archive::MAX_ARCHIVE_BYTES), $big);

        $when = make_timestamp(2026, 10, 27, 12);
        $notfound = fiche_messages::html(new fiche_refusal(fiche_refusal::NOT_FOUND, ['number' => 'RNCP00000']), $when);
        $this->assertStringContainsString(display_date::format($when, get_string('strftimedaydate', 'langconfig')), $notfound);

        $moodle = fiche_messages::html(new fiche_refusal(fiche_refusal::MOODLE, ['html' => 'Déjà <b>sûr</b>']));
        $this->assertSame('Déjà <b>sûr</b>', $moodle, 'A message already escaped at its source is not escaped twice.');
    }

    public function test_the_network_names_the_host_and_the_code_never_the_text_of_an_exception(): void {
        // The host and the code are for the technician of the host, where the host can act: not for an address that Moodle's
        // own setting blocks (the administrator's), and not for an error of data.gouv.fr (theirs).
        foreach ([fiche_refusal::FETCH_NETWORK, fiche_refusal::FETCH_TIMEOUT, fiche_refusal::FETCH_TLS] as $reason) {
            $html = fiche_messages::html(
                new fiche_refusal($reason, ['host' => 'static.data.gouv.fr', 'status' => 503, 'errno' => 6])
            );

            $this->assertStringContainsString('static.data.gouv.fr', $html, $reason);
            $this->assertStringContainsString('cURL 6', $html, $reason);
            $this->assertStringNotContainsString('cURL error', $html, $reason);
        }
        foreach ([fiche_refusal::FETCH_BLOCKED, fiche_refusal::FETCH_HTTP] as $reason) {
            $html = fiche_messages::html(
                new fiche_refusal($reason, ['host' => 'static.data.gouv.fr', 'status' => 503, 'errno' => 0])
            );

            $this->assertDoesNotMatchRegularExpression('/cURL \d/', $html, "$reason: no fake code for a host that cannot act.");
            $this->assertStringNotContainsString(
                get_string('fcimporterrordetail', 'tool_vae', ''),
                $html,
                "$reason: no line to give to the host."
            );
        }
        $this->assertStringContainsString(
            '503',
            fiche_messages::html(new fiche_refusal(fiche_refusal::FETCH_HTTP, ['host' => 'www.data.gouv.fr', 'status' => 503])),
            'The code of an error of data.gouv.fr is in the sentence.'
        );
    }

    public function test_the_refusals_of_the_fetch_point_to_the_upload_and_those_of_the_export_say_nothing_was_written(): void {
        $this->resetAfterTest();
        $english = self::strings('en');
        $fetch = [
            fiche_refusal::FETCH_NETWORK, fiche_refusal::FETCH_TIMEOUT, fiche_refusal::FETCH_TLS, fiche_refusal::FETCH_BLOCKED,
            fiche_refusal::FETCH_HTTP, fiche_refusal::FETCH_API, fiche_refusal::FETCH_NONE, fiche_refusal::FETCH_REDIRECT,
            fiche_refusal::FETCH_TOO_BIG, fiche_refusal::FETCH_DIGEST,
        ];
        foreach ($fetch as $reason) {
            $this->assertMatchesRegularExpression(
                '/upload(ing)? the zip file yourself|download the export yourself/i',
                $english['fcimporterror' . $reason],
                $reason
            );
        }

        $export = [
            fiche_refusal::NOT_ZIP, fiche_refusal::ZIP_TOO_BIG, fiche_refusal::ENTRY_MISSING, fiche_refusal::ENTRY_AMBIGUOUS,
            fiche_refusal::ENTRY_UNSAFE, fiche_refusal::NOT_UTF8, fiche_refusal::FORMAT, fiche_refusal::FILE_EMPTY,
            fiche_refusal::NO_DATE, fiche_refusal::DATE_FUTURE, fiche_refusal::BUSY,
            fiche_refusal::PLAN_TOO_LARGE, fiche_refusal::UPLOAD,
        ];
        foreach ($export as $reason) {
            $this->assertStringContainsStringIgnoringCase('nothing has been written', $english['fcimporterror' . $reason], $reason);
        }

        // An expired preview may be one whose records were written: it does not say that nothing was, it says what to do.
        $this->assertStringNotContainsStringIgnoringCase('nothing has been written', $english['fcimporterrorexpired']);
        $this->assertStringContainsString('is compared, not redone', $english['fcimporterrorexpired']);
    }

    public function test_a_network_refusal_with_no_host_gives_no_empty_line_for_the_host(): void {
        $html = fiche_messages::html(new fiche_refusal(fiche_refusal::FETCH_NETWORK, ['host' => '', 'errno' => 0]));

        $this->assertStringNotContainsString('cURL 0', $html);
    }
}
