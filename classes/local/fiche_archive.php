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
 * The archive of the daily export of France compétences, read safely (T-31, 0.47.0).
 *
 * THE ARCHIVE IS NOT TRUSTED, WHEREVER IT COMES FROM. It was downloaded by a person, or fetched by the server; either way it
 * is a file of the Internet. Nothing in it is extracted to the disk, nothing in it is executed, and nothing in it is read but the
 * two files the plugin converts:
 *
 *   - THE LIST IS JUDGED BEFORE ANY READING: the size of the file, the number of its entries, and which entry is which. The two
 *     files are found by their name read on the BYTES - the accent of `Compétences` is one byte, two (NFC) or three (NFD)
 *     according to who wrote the archive - and each must be found once: none is a refusal, two is a refusal, since there is no
 *     telling which is the right one. A name with a path, a date that is not one, a name of another dataset, is not a match.
 *   - THE OTHER ENTRIES ARE NEVER OPENED. The archive holds eight more files (32 Mo for `Partenaires`: names and SIRET of the
 *     organisations, which nothing here wants and which must not even pass through the memory of the process).
 *   - A SIZE THAT THE ARCHIVE DECLARES CAN LIE: the entry is read for at most the size it declares plus one byte, and the
 *     length read, and its checksum, must be what it declared. An entry over 16 Mo, encrypted, damaged or inconsistent is
 *     refused without having been read whole.
 *   - THE CONTENT IS UTF-8 OR IT IS REFUSED: converting it with fix_utf8() would delete, in silence, every byte it cannot
 *     read, and a label would reach the database with its accents gone - the labels the licence forbids to alter.
 *
 * NO MOODLE IN HERE: the class only reads a file and says what is in it, and raises fiche_refusal.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fiche_archive {
    /** @var int The biggest archive read: 32 MiB. The real one weighs 9.7 Mo, and grows by about 1 Mo a year. */
    public const MAX_ARCHIVE_BYTES = 33554432;

    /** @var int The most entries an archive may hold. The real one holds 10. */
    public const MAX_ENTRIES = 50;

    /** @var int The biggest entry read: 16 MiB. The two real ones weigh 6.0 and 6.3 Mo. */
    public const MAX_ENTRY_BYTES = 16777216;

    /** @var string The name of the blocs file, on the bytes: the accent is 1, 2 or 3 bytes. */
    private const BLOCS = '~^export_fiches_CSV_Blocs_De_Comp[^_/\\\\]{1,4}tences_(\d{4})_(\d{2})_(\d{2})\.csv$~Di';

    /** @var string The name of the standard file. */
    private const STANDARD = '~^export_fiches_CSV_Standard_(\d{4})_(\d{2})_(\d{2})\.csv$~Di';

    /**
     * Read the two files of the export that the plugin converts.
     *
     * @param string $path The archive.
     * @return \stdClass blocs and standard (the contents), blocsdate and standarddate (the day written in the name of
     *         each file, YYYY-MM-DD: the day BEFORE the one in the name of the archive), bytes (the size of the archive)
     *         and sha256 (its digest).
     * @throws fiche_refusal NOT_ZIP, ZIP_TOO_BIG, ENTRY_MISSING, ENTRY_AMBIGUOUS, ENTRY_UNSAFE, NOT_UTF8.
     */
    public static function read(string $path): \stdClass {
        $bytes = is_file($path) ? filesize($path) : false;
        if ($bytes === false || $bytes < 22) {
            throw new fiche_refusal(fiche_refusal::NOT_ZIP, [], 'The file is not a readable zip archive.');
        }
        if ($bytes > self::MAX_ARCHIVE_BYTES) {
            throw new fiche_refusal(fiche_refusal::ZIP_TOO_BIG, [], 'The archive is bigger than an export.');
        }

        // THE LIST IS JUDGED BEFORE LIBZIP LOADS IT: opening an archive loads its whole directory, and one of six hundred thousand
        // entries fits under 32 Mio, costs seconds and a hundred and eighty Mo that memory_limit does not see.
        $declared = self::declared_entries($path);
        if ($declared !== null && $declared > self::MAX_ENTRIES) {
            throw new fiche_refusal(fiche_refusal::ZIP_TOO_BIG, [], 'The archive holds too many files.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new fiche_refusal(fiche_refusal::NOT_ZIP, [], 'The file is not a readable zip archive.');
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new fiche_refusal(fiche_refusal::ZIP_TOO_BIG, [], 'The archive holds too many files.');
            }

            $standard = self::find($zip, self::STANDARD, fiche_converter::FILE_STANDARD);
            $blocs = self::find($zip, self::BLOCS, fiche_converter::FILE_BLOCS);

            return (object) [
                'blocs' => self::contents($zip, $blocs, fiche_converter::FILE_BLOCS),
                'standard' => self::contents($zip, $standard, fiche_converter::FILE_STANDARD),
                'blocsdate' => $blocs['date'],
                'standarddate' => $standard['date'],
                'bytes' => $bytes,
                'sha256' => hash_file('sha256', $path),
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * The number of entries the archive says it holds, read in its end-of-archive record, without opening it.
     *
     * The record is at the end of the file, after a comment of 65 535 bytes at most; when its count is 0xFFFF the real one
     * is in the ZIP64 record that the locator just before it points to. Nothing else is read.
     *
     * THE COMMENT IS TEXT OF THE ARCHIVE'S AUTHOR, and may hold bytes that look like a record of the end: every candidate whose
     * comment ends exactly at the end of the file is read, and the LARGEST count is kept. A hostile archive cannot hide a big
     * directory behind a decoy that says one entry; an honest one has no such bytes in its comment.
     *
     * @param string $path
     * @return int|null The number, or null when no end-of-archive record is found (opening the file will say what it is).
     */
    public static function declared_entries(string $path): ?int {
        $size = is_file($path) ? filesize($path) : false;
        $handle = $size === false ? false : fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $length = (int) min($size, 65557);
            fseek($handle, $size - $length);
            $tail = (string) fread($handle, $length);

            $largest = null;
            for ($i = strlen($tail) - 22; $i >= 0; $i--) {
                if (substr($tail, $i, 4) !== "PK\x05\x06") {
                    continue;
                }
                $comment = unpack('v', substr($tail, $i + 20, 2))[1];
                // Bytes after the comment are tolerated by the readers of zip files: a record that ends before the end of the
                // file still counts, and the largest count wins.
                if ($i + 22 + $comment > strlen($tail)) {
                    continue;
                }
                $count = self::count_from($handle, $tail, $i, $size);
                if ($count !== null && ($largest === null || $count > $largest)) {
                    $largest = $count;
                }
            }

            return $largest;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The count of entries that one end-of-archive record gives, following the ZIP64 record when it points to one.
     *
     * @param resource $handle The archive, open.
     * @param string $tail The end of the archive, as it was read.
     * @param int $position Where the record starts in the tail.
     * @param int $size The size of the archive.
     * @return int|null
     */
    private static function count_from($handle, string $tail, int $position, int $size): ?int {
        $entries = unpack('v', substr($tail, $position + 10, 2))[1];
        if ($entries !== 0xFFFF) {
            return $entries;
        }

        // ZIP64: the locator is the 20 bytes before the record, and says where the ZIP64 record is.
        if ($position < 20 || substr($tail, $position - 20, 4) !== "PK\x06\x07") {
            return $entries;
        }
        $offset = unpack('P', substr($tail, $position - 20 + 8, 8))[1];
        if ($offset < 0 || $offset > $size - 56) {
            return null;
        }
        fseek($handle, $offset);
        $record = (string) fread($handle, 56);
        if (strlen($record) < 56 || substr($record, 0, 4) !== "PK\x06\x06") {
            return null;
        }
        $total = unpack('P', substr($record, 32, 8))[1];

        return $total < 0 ? PHP_INT_MAX : $total;
    }

    /**
     * The entry of the archive whose name is the one expected, found once.
     *
     * @param \ZipArchive $zip
     * @param string $pattern
     * @param string $file How the file is named in a refusal.
     * @return array index and date (YYYY-MM-DD).
     * @throws fiche_refusal ENTRY_MISSING, ENTRY_AMBIGUOUS.
     */
    private static function find(\ZipArchive $zip, string $pattern, string $file): array {
        $found = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, \ZipArchive::FL_ENC_RAW);
            if ($stat === false || !preg_match($pattern, (string) $stat['name'], $parts)) {
                continue;
            }
            // A day that does not exist is not the day of an export.
            if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                continue;
            }
            $found[] = ['index' => $index, 'date' => "$parts[1]-$parts[2]-$parts[3]"];
        }

        if (!$found) {
            throw new fiche_refusal(fiche_refusal::ENTRY_MISSING, ['file' => $file], "The file $file is not in the archive.");
        }
        if (count($found) > 1) {
            throw new fiche_refusal(
                fiche_refusal::ENTRY_AMBIGUOUS,
                ['file' => $file],
                "Several files of the archive could be the file $file."
            );
        }

        return $found[0];
    }

    /**
     * The contents of an entry, read for at most what it declares, and checked.
     *
     * @param \ZipArchive $zip
     * @param array $entry What find() gives.
     * @param string $file How the file is named in a refusal.
     * @return string UTF-8.
     * @throws fiche_refusal ENTRY_UNSAFE, NOT_UTF8.
     */
    private static function contents(\ZipArchive $zip, array $entry, string $file): string {
        $unsafe = new fiche_refusal(
            fiche_refusal::ENTRY_UNSAFE,
            ['file' => $file],
            "The file $file of the archive is damaged, encrypted, too large or inconsistent."
        );

        $stat = $zip->statIndex($entry['index']);
        if ($stat === false || !empty($stat['encryption_method']) || $stat['size'] > self::MAX_ENTRY_BYTES || $stat['size'] < 0) {
            throw $unsafe;
        }

        $declared = (int) $stat['size'];
        $data = $zip->getFromIndex($entry['index'], $declared + 1);
        if ($data === false || strlen($data) !== $declared || crc32($data) !== (int) $stat['crc']) {
            throw $unsafe;
        }
        if (!mb_check_encoding($data, 'UTF-8')) {
            throw new fiche_refusal(fiche_refusal::NOT_UTF8, ['file' => $file], "The file $file of the archive is not UTF-8.");
        }

        return $data;
    }
}
