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

use tool_vae\fixtures\export_zip_builder;
use tool_vae\local\fiche_archive;
use tool_vae\local\fiche_converter;
use tool_vae\local\fiche_refusal;

/**
 * Reading the archive of the daily export of France compétences, safely (0.47.0).
 *
 * THE ARCHIVES ARE INVENTED and built at run time in the shape measured on the real exports of 19 and 27 October 2026
 * (see export_zip_builder): ten entries, accented names, the date of the day before, an entry `Partenaires` of 32 Mo that
 * must never be opened. No datum of France compétences enters the tree (open point PO-2).
 *
 * WHAT IS ASSERTED. That the two entries that matter are found by their name on the bytes, whatever the accent is written
 * with, and nothing else is opened; that an archive which is not one, is too big, has a missing or ambiguous entry, an
 * entry that is encrypted, damaged, too big or that lies about its size, or a content that is not UTF-8, is refused by its
 * reason; that nothing is written to the disk.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\fiche_archive::class)]
final class fiche_archive_test extends \advanced_testcase {
    /** @var string The directory the archives of a test are written to. */
    private string $directory;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/export_zip_builder.php');
        $this->directory = make_request_directory();
    }

    /**
     * The reason an archive is refused for, with its parameters.
     *
     * @param string $path
     * @return fiche_refusal
     */
    private function refused(string $path): fiche_refusal {
        try {
            fiche_archive::read($path);
        } catch (fiche_refusal $refusal) {
            return $refusal;
        }
        $this->fail('The archive must be refused.');
    }

    /**
     * The files of a directory.
     *
     * @param string $directory
     * @return string[]
     */
    private static function listing(string $directory): array {
        $names = array_values(array_diff(scandir($directory), ['.', '..']));
        sort($names);

        return $names;
    }

    public function test_the_real_shape_of_the_export_is_read(): void {
        $path = export_zip_builder::build($this->directory, 'export-fiches-csv-2026-10-19.zip', [
            'partners' => 40 * 1024 * 1024,
        ]);

        $base = memory_get_usage();
        memory_reset_peak_usage();
        $got = fiche_archive::read($path);
        $growth = memory_get_peak_usage() - $base;

        $this->assertSame(export_zip_builder::blocs(export_zip_builder::records()), $got->blocs);
        $this->assertSame(export_zip_builder::standard(export_zip_builder::records()), $got->standard);
        $this->assertSame('2026-10-18', $got->blocsdate, 'The files are dated the day before the archive.');
        $this->assertSame('2026-10-18', $got->standarddate);
        $this->assertSame(filesize($path), $got->bytes);
        $this->assertSame(hash_file('sha256', $path), $got->sha256);
        $this->assertLessThan(24 * 1024 * 1024, $growth, 'The entry Partenaires (40 Mio of zeros here) is never opened.');

        // What was read converts, as it does when the files come from the disk.
        $this->assertCount(4, fiche_converter::convert($got->blocs, $got->standard, 'RNCP99001')->rows);
    }

    public function test_the_name_is_found_whatever_the_accent_is_written_with(): void {
        foreach (['default' => [], 'decomposed' => ['nfd' => true], 'one byte' => ['latin1name' => true]] as $label => $options) {
            $path = export_zip_builder::build($this->directory, 'export-fiches-csv-2026-10-19.zip', $options);

            $got = fiche_archive::read($path);

            $this->assertSame(export_zip_builder::blocs(export_zip_builder::records()), $got->blocs, $label);
        }
    }

    public function test_what_is_not_a_zip_is_refused(): void {
        $html = $this->directory . '/export-fiches-csv-2026-10-19.zip';
        file_put_contents($html, "<!DOCTYPE html><html><body>Page d'erreur renommée</body></html>");
        $this->assertSame(fiche_refusal::NOT_ZIP, $this->refused($html)->reason());

        $empty = $this->directory . '/vide.zip';
        file_put_contents($empty, '');
        $this->assertSame(fiche_refusal::NOT_ZIP, $this->refused($empty)->reason());

        $whole = export_zip_builder::build($this->directory, 'complet.zip');
        $cut = $this->directory . '/tronque.zip';
        file_put_contents($cut, substr(file_get_contents($whole), 0, (int) (filesize($whole) * 0.3)));
        $this->assertSame(fiche_refusal::NOT_ZIP, $this->refused($cut)->reason());
    }

    public function test_too_big_or_too_many_is_refused_before_reading(): void {
        $big = $this->directory . '/enorme.zip';
        $handle = fopen($big, 'w');
        ftruncate($handle, fiche_archive::MAX_ARCHIVE_BYTES + 1);
        fclose($handle);
        $this->assertSame(fiche_refusal::ZIP_TOO_BIG, $this->refused($big)->reason());

        $extra = [];
        for ($i = 1; $i <= fiche_archive::MAX_ENTRIES; $i++) {
            $extra["autre_$i.txt"] = 'x';
        }
        $many = export_zip_builder::build($this->directory, 'nombreux.zip', ['extra' => $extra]);
        $this->assertSame(fiche_refusal::ZIP_TOO_BIG, $this->refused($many)->reason());
    }

    public function test_a_missing_ambiguous_or_misplaced_entry_is_named(): void {
        $path = export_zip_builder::build($this->directory, 'a.zip', ['omit' => ['standard']]);
        $refusal = $this->refused($path);
        $this->assertSame(fiche_refusal::ENTRY_MISSING, $refusal->reason());
        $this->assertSame(['file' => fiche_converter::FILE_STANDARD], $refusal->params());

        $path = export_zip_builder::build($this->directory, 'b.zip', ['omit' => ['blocs']]);
        $refusal = $this->refused($path);
        $this->assertSame(fiche_refusal::ENTRY_MISSING, $refusal->reason());
        $this->assertSame(['file' => fiche_converter::FILE_BLOCS], $refusal->params());

        $path = export_zip_builder::build($this->directory, 'c.zip', ['duplicate' => ['blocs']]);
        $refusal = $this->refused($path);
        $this->assertSame(fiche_refusal::ENTRY_AMBIGUOUS, $refusal->reason());
        $this->assertSame(['file' => fiche_converter::FILE_BLOCS], $refusal->params());

        // A date that is not one is not a match.
        $path = export_zip_builder::build($this->directory, 'd.zip', ['dates' => ['blocs' => '2026_13_45']]);
        $this->assertSame(fiche_refusal::ENTRY_MISSING, $this->refused($path)->reason());

        // In a folder, it is not the entry of the export.
        $path = export_zip_builder::build($this->directory, 'e.zip', [
            'omit' => ['blocs'],
            'extra' => ["dossier/export_fiches_CSV_Blocs_De_Comp\u{00E9}tences_2026_10_18.csv" => 'x'],
        ]);
        $this->assertSame(fiche_refusal::ENTRY_MISSING, $this->refused($path)->reason());
    }

    public function test_an_entry_over_the_cap_is_not_read(): void {
        $path = export_zip_builder::build($this->directory, 'export-fiches-csv-2026-10-19.zip');
        $name = "export_fiches_CSV_Blocs_De_Comp\u{00E9}tences_2026_10_18.csv";
        export_zip_builder::lie_about_size($path, $name, fiche_archive::MAX_ENTRY_BYTES + 1024 * 1024);

        $base = memory_get_usage();
        memory_reset_peak_usage();
        $refusal = $this->refused($path);
        $growth = memory_get_peak_usage() - $base;

        $this->assertSame(fiche_refusal::ENTRY_UNSAFE, $refusal->reason());
        $this->assertSame(['file' => fiche_converter::FILE_BLOCS], $refusal->params());
        $this->assertLessThan(8 * 1024 * 1024, $growth, 'Refused on the size it declares, before any reading.');
    }

    public function test_a_size_that_lies_is_refused(): void {
        $name = "export_fiches_CSV_Blocs_De_Comp\u{00E9}tences_2026_10_18.csv";
        $padding = [['BC01', str_repeat('Un bloc inventé, assez long pour dépasser mille octets. ', 40)]];
        $records = [[
            'number' => 'RNCP99501', 'title' => 'Fiche', 'active' => 'ACTIVE', 'end' => '', 'blocs' => $padding,
        ]];

        $path = export_zip_builder::build($this->directory, 'bas.zip', ['records' => $records]);
        export_zip_builder::lie_about_size($path, $name, 1000);
        $this->assertSame(fiche_refusal::ENTRY_UNSAFE, $this->refused($path)->reason(), 'Declared smaller than it is.');

        $path = export_zip_builder::build($this->directory, 'haut.zip');
        $real = strlen(export_zip_builder::blocs(export_zip_builder::records()));
        export_zip_builder::lie_about_size($path, $name, $real + 500);
        $this->assertSame(fiche_refusal::ENTRY_UNSAFE, $this->refused($path)->reason(), 'Declared larger than it is.');
    }

    public function test_damaged_data_is_refused(): void {
        $path = export_zip_builder::build($this->directory, 'export-fiches-csv-2026-10-19.zip');
        $name = "export_fiches_CSV_Blocs_De_Comp\u{00E9}tences_2026_10_18.csv";

        // The data of an entry follows its local header: 30 bytes, the name, the extra field.
        $bytes = file_get_contents($path);
        $local = 0;
        while (($local = strpos($bytes, "PK\x03\x04", $local)) !== false) {
            $namelength = unpack('v', substr($bytes, $local + 26, 2))[1];
            if (substr($bytes, $local + 30, $namelength) === $name) {
                $extralength = unpack('v', substr($bytes, $local + 28, 2))[1];
                $data = $local + 30 + $namelength + $extralength;
                $bytes[$data + 5] = chr(ord($bytes[$data + 5]) ^ 0xFF);
                break;
            }
            $local += 4;
        }
        $this->assertNotFalse($local);
        file_put_contents($path, $bytes);

        $refusal = $this->refused($path);
        $this->assertSame(fiche_refusal::ENTRY_UNSAFE, $refusal->reason());
        $this->assertSame(['file' => fiche_converter::FILE_BLOCS], $refusal->params());
    }

    public function test_an_encrypted_entry_is_refused(): void {
        $name = "export_fiches_CSV_Blocs_De_Comp\u{00E9}tences_2026_10_18.csv";
        try {
            $path = export_zip_builder::build($this->directory, 'chiffre.zip', ['encrypt' => $name]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('This build of libzip does not encrypt: ' . $e->getMessage());
        }

        $refusal = $this->refused($path);
        $this->assertSame(fiche_refusal::ENTRY_UNSAFE, $refusal->reason());
    }

    public function test_what_is_not_utf8_is_refused(): void {
        $path = export_zip_builder::build($this->directory, 'latin.zip', [
            'blocs' => "\"Numero_Fiche\";\"Bloc_Competences_Code\";\"Bloc_Competences_Libelle\"\r\n"
                . "\"RNCP99001\";\"RNCP99001BC01\";\"Libell\xE9\"\r\n",
        ]);

        $refusal = $this->refused($path);

        $this->assertSame(fiche_refusal::NOT_UTF8, $refusal->reason());
        $this->assertSame(['file' => fiche_converter::FILE_BLOCS], $refusal->params());
    }

    public function test_two_different_inner_dates_are_returned_not_refused(): void {
        $path = export_zip_builder::build($this->directory, 'export-fiches-csv-2026-10-19.zip', [
            'dates' => ['blocs' => '2026_10_17', 'standard' => '2026_10_18'],
        ]);

        $got = fiche_archive::read($path);

        $this->assertSame('2026-10-17', $got->blocsdate);
        $this->assertSame('2026-10-18', $got->standarddate);
    }

    public function test_a_hostile_entry_name_writes_nothing(): void {
        $inner = make_request_directory();
        $path = export_zip_builder::build($inner, 'hostile.zip', ['extra' => ['../x.csv' => 'a;b']]);
        $before = self::listing($inner);

        $got = fiche_archive::read($path);

        $this->assertNotEmpty($got->blocs);
        $this->assertSame($before, self::listing($inner));
        $this->assertFileDoesNotExist(dirname($inner) . '/x.csv');
    }

    public function test_no_file_is_written(): void {
        $path = export_zip_builder::build($this->directory, 'export-fiches-csv-2026-10-19.zip');
        $before = self::listing($this->directory);

        fiche_archive::read($path);

        $this->assertSame($before, self::listing($this->directory));
    }

    public function test_the_number_of_entries_is_read_from_the_end_of_the_archive_without_opening_it(): void {
        $path = export_zip_builder::build($this->directory, 'compte.zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::RDONLY);
        $count = $zip->numFiles;
        $zip->close();
        $this->assertSame($count, fiche_archive::declared_entries($path));

        // Not an archive, or not there: nothing is declared, and opening the file will say what it is.
        $this->assertNull(fiche_archive::declared_entries($this->directory . '/absent.zip'));
        file_put_contents($this->directory . '/bruit.zip', str_repeat('this is not a zip ', 50));
        $this->assertNull(fiche_archive::declared_entries($this->directory . '/bruit.zip'));

        // A comment after the record does not hide it.
        $zip->open($path);
        $zip->setArchiveComment(str_repeat('c', 300));
        $zip->close();
        $this->assertSame($count, fiche_archive::declared_entries($path));
    }

    public function test_a_zip64_count_is_read_before_the_directory_is_loaded(): void {
        $path = export_zip_builder::build($this->directory, 'z64.zip');
        $bytes = file_get_contents($path);
        $this->assertSame("PK\x05\x06", substr($bytes, -22, 4));
        $body = substr($bytes, 0, -22);
        // The ZIP64 record, its locator, and the usual record with the counts that say "look in the ZIP64 one".
        $record = "PK\x06\x06" . pack('P', 44) . pack('vv', 45, 45) . pack('VV', 0, 0)
            . pack('PP', 600000, 600000) . pack('PP', 0, 0);
        $locator = "PK\x06\x07" . pack('V', 0) . pack('P', strlen($body)) . pack('V', 1);
        $end = "PK\x05\x06" . pack('vv', 0, 0) . pack('vv', 0xFFFF, 0xFFFF) . pack('VV', 0xFFFFFFFF, 0xFFFFFFFF) . pack('v', 0);
        $forged = $this->directory . '/z64-forge.zip';
        file_put_contents($forged, $body . $record . $locator . $end);

        $this->assertSame(600000, fiche_archive::declared_entries($forged));
        $this->assertSame(fiche_refusal::ZIP_TOO_BIG, $this->refused($forged)->reason());
    }

    public function test_a_stored_entry_changed_without_changing_its_size_is_refused(): void {
        $path = export_zip_builder::build($this->directory, 'stored.zip', ['store' => true]);
        $name = "export_fiches_CSV_Blocs_De_Comp\u{00E9}tences_2026_10_18.csv";
        $bytes = file_get_contents($path);
        $local = 0;
        while (($local = strpos($bytes, "PK\x03\x04", $local)) !== false) {
            $namelength = unpack('v', substr($bytes, $local + 26, 2))[1];
            if (substr($bytes, $local + 30, $namelength) === $name) {
                $extralength = unpack('v', substr($bytes, $local + 28, 2))[1];
                $data = $local + 30 + $namelength + $extralength;
                $this->assertSame(0, unpack('v', substr($bytes, $local + 8, 2))[1], 'The entry is stored.');
                // One bit of an ASCII letter: still text, same length - only the checksum can tell.
                $bytes[$data + 5] = chr(ord($bytes[$data + 5]) ^ 0x01);
                break;
            }
            $local += 4;
        }
        $this->assertNotFalse($local);
        file_put_contents($path, $bytes);

        $refusal = $this->refused($path);

        $this->assertSame(fiche_refusal::ENTRY_UNSAFE, $refusal->reason());
        $this->assertSame(['file' => fiche_converter::FILE_BLOCS], $refusal->params());
    }

    public function test_a_decoy_record_in_the_comment_does_not_hide_the_real_count(): void {
        $path = export_zip_builder::build($this->directory, 'leurre.zip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::RDONLY);
        $count = $zip->numFiles;
        $zip->close();
        $this->assertGreaterThan(1, $count);

        // The comment of the real record is written by hand (the library would not put a signature in it): a record of the end
        // that says one entry and ends exactly where the file ends. The real record has no comment yet: its last two bytes.
        $original = file_get_contents($path);
        $this->assertSame(0, unpack('v', substr($original, -2))[1], 'The archive has no comment to begin with.');
        $withcomment = function (string $decoy) use ($original): string {
            $comment = 'x' . $decoy;

            return substr($original, 0, -2) . pack('v', strlen($comment)) . $comment;
        };

        $small = "PK\x05\x06" . pack('vvvvVVv', 0, 0, 1, 1, 0, 0, 0);
        file_put_contents($path, $withcomment($small));
        $this->assertSame($count, fiche_archive::declared_entries($path), 'The largest count of the candidates is the one kept.');

        // And the other way: a decoy that says many is not believed over nothing - it makes the archive refused, as it should.
        $big = "PK\x05\x06" . pack('vvvvVVv', 0, 0, 0xFFF0, 0xFFF0, 0, 0, 0);
        file_put_contents($path, $withcomment($big));
        $this->assertSame(0xFFF0, fiche_archive::declared_entries($path));
        $this->assertSame(fiche_refusal::ZIP_TOO_BIG, $this->refused($path)->reason());
    }
}
