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

/**
 * From a refusal to a sentence a person who is not a developer can read (0.47.0).
 *
 * THE CONTRACT OF html(): it returns HTML that is already safe, and the caller shows it as it is, without escaping it again (the
 * same convention as importer and import.php: what is read from a file is escaped where it enters a sentence). Every parameter
 * of a refusal is cut at sixty characters and then escaped as it is put into the sentence of the language file, the sentences
 * are the plugin's own, and nothing of the text of an exception of the network ever reaches one: a refusal of the network says
 * the host and the code of cURL or of HTTP, which is what a host needs to be told.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fiche_messages {
    /**
     * @var string[] The refusals of the network that the host can do something about, which add a line for its technician.
     *      Not the address blocked by a setting of Moodle (the administrator's own), and not an error of data.gouv.fr (theirs).
     */
    private const NETWORK = [fiche_refusal::FETCH_NETWORK, fiche_refusal::FETCH_TIMEOUT, fiche_refusal::FETCH_TLS];

    /**
     * The sentence of a refusal.
     *
     * @param fiche_refusal $refusal
     * @param int|null $exportts The day of the export, when the sentence names it (a number that is not in the export).
     * @return string HTML that is already safe.
     */
    public static function html(fiche_refusal $refusal, ?int $exportts = null): string {
        $params = $refusal->params();
        if ($refusal->reason() === fiche_refusal::MOODLE) {
            return (string) ($params['html'] ?? '');
        }

        $a = new \stdClass();
        foreach ($params as $name => $value) {
            $a->$name = is_int($value) ? $value : s(self::shorten((string) $value));
        }
        switch ($refusal->reason()) {
            case fiche_refusal::UPLOAD:
                $a->max = display_size(get_max_upload_file_size());
                break;
            case fiche_refusal::ZIP_TOO_BIG:
                $a->max = display_size(fiche_archive::MAX_ARCHIVE_BYTES);
                break;
            case fiche_refusal::NOT_FOUND:
                $a->date = $exportts === null
                    ? ''
                    : display_date::format($exportts, get_string('strftimedaydate', 'langconfig'));
                break;
            default:
                break;
        }

        $text = get_string($refusal->lang_key(), 'tool_vae', $a);
        if (in_array($refusal->reason(), self::NETWORK, true) && (string) ($params['host'] ?? '') !== '') {
            $text .= ' ' . get_string(
                'fcimporterrordetail',
                'tool_vae',
                s(self::shorten((string) ($params['host'] ?? ''))) . ', cURL ' . (int) ($params['errno'] ?? 0)
            );
        }

        return $text;
    }

    /**
     * A text cut at the length a sentence can carry, never escaped here.
     *
     * @param string $text
     * @param int $max Characters.
     * @return string The text, or its beginning and an ellipsis.
     */
    public static function shorten(string $text, int $max = 60): string {
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') . '…' : $text;
    }
}
