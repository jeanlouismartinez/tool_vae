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
 * From the open data of France compétences to the file the import reads (T-31).
 *
 * NO MOODLE IN HERE. This class is also run by scripts/convertir-fiche-rncp.php on a workstation with no site: it
 * reads two files of the daily export (`Blocs_De_Compétences` and `Standard`, semicolon-separated, UTF-8, CRLF - read on
 * the exports of 19 and 27 October 2026, which carry no byte-order mark; an older one did, and both are read) and writes
 * the three columns the import reads.
 *
 * WHAT IT NEVER DOES. It never rewords a label and never cuts one (Licence Ouverte 2.0: no misleading anybody about
 * the content). A label of 100 characters or fewer goes to the import as it was published, trimmed of the blanks
 * around it. A longer one cannot be the label of a competency of the core (a column of 100 characters): the line gets
 * the neutral title `Bloc <rank>`, and the published label goes whole into the description. The title says nothing
 * the source did not say; nothing is lost. A SHORT label THAT THE CORE WOULD REFUSE AS A TITLE is treated the same way:
 * the core reads the title of a competency as plain text and cuts it at an opening `<` that strip_tags() takes for a tag
 * (a label of the shape `Installer un mât (<10m) dans une gaine` - the export of 19 October 2026 holds one, for the bloc
 * BC04 of RNCP35292 - would become `Installer un mât (`), and refuses what it would have to change. The description is
 * stored as it is given, so the label goes there whole.
 *
 * WHAT THE EXPORT DOES NOT CARRY. Only the bloc code and its label: no list of the competencies of a bloc. A record
 * is therefore converted as blocs, one line each, and a record the export holds without blocs (the old, inactive
 * ones) is refused by name rather than converted to nothing.
 *
 * ONE READING, MANY RECORDS. The two files weigh 6 Mo each. convert_many() reads each of them ONCE, as a stream, and
 * keeps the lines of the numbers asked for: the cost does not follow the number of records, nor the size of the export,
 * which is what lets it run on a host with a small memory limit. convert() is its envelope for one number.
 *
 * THE FILES ARE NOT TRUSTED. What the export says twice is not guessed: a number that appears twice in the standard file, a
 * rank that appears twice for one record, are refused by name, as two entries of the archive that could both be the right
 * one are. What a hostile file could make big is bounded: the header is read for at most 64 Kio and 64 columns, a record
 * for at most 500 blocs, a code, a state and an end of registration for 64 characters.
 *
 * A REFUSAL IS A fiche_refusal, a RuntimeException with the same English sentence as before, plus the reason and the facts.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class fiche_converter {
    /** @var int The longest label the core holds for a competency (importer::LABEL_MAXLENGTH). */
    public const LABEL_MAXLENGTH = 100;

    /** @var string The title given to a bloc whose published label is too long; the rank follows. */
    public const SHORT_TITLE = 'Bloc ';

    /** @var string How the standard file is named in a refusal. */
    public const FILE_STANDARD = 'Standard';

    /** @var string How the blocs file is named in a refusal. */
    public const FILE_BLOCS = 'Blocs de compétences';

    /** @var int The longest text of the export that is read: no label is a page. */
    public const MAX_TEXT_CHARS = 20000;

    /** @var int The longest bloc code, state or end of registration read: they are a few characters long. */
    public const MAX_SHORT_CHARS = 64;

    /** @var int The most blocs one record may have: the real maximum is 36. */
    public const MAX_BLOCS_PER_RECORD = 500;

    /** @var int The longest header read, in bytes, and the most columns it may have: the real ones are 3 and 16. */
    public const MAX_HEADER_BYTES = 65536;

    /** @var int The most columns of a header. */
    public const MAX_COLUMNS = 64;

    /** @var string What a text should not hold: the control characters but tab, line feed and return; the bidirectional ones. */
    private const CONTROL_PATTERN = '/(?![\t\n\r])\p{Cc}|[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FFFE}\x{FFFF}]/u';

    /**
     * Convert one record.
     *
     * @param string $blocs The contents of the export's blocs file.
     * @param string $standard The contents of the export's standard file.
     * @param string $number The record number, as the export writes it (RNCP40692).
     * @return \stdClass meta (fichecode, fichetype, name - the title, or the code when the title is too long, has several
     *         lines or would be cut by the core, for a framework name - title, active and enddate as the export publishes
     *         them), rows (each rang, intitule, description), shortened (the ranks whose label went to the description),
     *         long and markup (why).
     * @throws fiche_refusal The record is unknown, has no bloc, or a bloc code is not of the expected shape...
     */
    public static function convert(string $blocs, string $standard, string $number): \stdClass {
        $many = self::convert_many($blocs, $standard, [$number]);
        $result = reset($many);
        if ($result instanceof fiche_refusal) {
            throw $result;
        }

        return $result;
    }

    /**
     * Convert several records, reading each file once.
     *
     * @param string $blocs The contents of the export's blocs file.
     * @param string $standard The contents of the export's standard file.
     * @param string[] $numbers The record numbers; read in capitals, once each, in the order asked.
     * @return array Number => what convert() gives, or the fiche_refusal that tells why that record cannot be converted
     *         (the others are not stopped by it).
     * @throws fiche_refusal FORMAT or FILE_EMPTY: a file cannot be read at all, for every record.
     */
    public static function convert_many(string $blocs, string $standard, array $numbers): array {
        $wanted = [];
        foreach ($numbers as $number) {
            $wanted[strtoupper(trim((string) $number))] = true;
        }

        $titles = self::read_standard($standard, $wanted);
        [$lines, $toomany] = self::read_blocs($blocs, $wanted);

        $results = [];
        foreach (array_keys($wanted) as $number) {
            $results[$number] = self::build($number, $titles[$number] ?? null, $lines[$number] ?? [], isset($toomany[$number]));
        }

        return $results;
    }

    /**
     * The three columns the import reads, as CSV text.
     *
     * The text of a cell is written as it was read: a return inside a label is not rewritten.
     *
     * @param array[] $rows What convert() returns.
     * @return string UTF-8, comma-separated, LF at the end of a line.
     */
    public static function to_csv(array $rows): string {
        $stream = fopen('php://memory', 'w+');
        fputcsv($stream, ['rang', 'intitule', 'description'], ',', '"', '', "\n");
        foreach ($rows as $row) {
            fputcsv($stream, [$row['rang'], $row['intitule'], $row['description']], ',', '"', '', "\n");
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /**
     * The standard file: the row of each record asked for.
     *
     * @param string $content
     * @param bool[] $wanted Number => true.
     * @return array Number => the row (Intitule, and Actif and Date_Fin_Enregistrement when the columns are there, else
     *         null), with the key `duplicate` set when the file holds the number twice.
     * @throws fiche_refusal
     */
    private static function read_standard(string $content, array $wanted): array {
        $found = [];
        self::each_row(
            $content,
            self::FILE_STANDARD,
            ['Numero_Fiche', 'Intitule'],
            function (array $row) use ($wanted, &$found): void {
                $number = $row['Numero_Fiche'];
                if (!isset($wanted[$number])) {
                    return;
                }
                if (isset($found[$number])) {
                    // Said twice: not guessed.
                    $found[$number]['duplicate'] = true;

                    return;
                }
                $found[$number] = $row;
            }
        );

        return $found;
    }

    /**
     * The blocs file: the lines of each record asked for.
     *
     * @param string $content
     * @param bool[] $wanted Number => true.
     * @return array [Number => the lines (Bloc_Competences_Code, Bloc_Competences_Libelle), Number => true for a record that
     *         has more blocs than any record has].
     * @throws fiche_refusal
     */
    private static function read_blocs(string $content, array $wanted): array {
        $found = [];
        $toomany = [];
        self::each_row(
            $content,
            self::FILE_BLOCS,
            ['Numero_Fiche', 'Bloc_Competences_Code', 'Bloc_Competences_Libelle'],
            function (array $row) use ($wanted, &$found, &$toomany): void {
                $number = $row['Numero_Fiche'];
                if (!isset($wanted[$number]) || isset($toomany[$number])) {
                    return;
                }
                if (count($found[$number] ?? []) >= self::MAX_BLOCS_PER_RECORD) {
                    // A hostile file could hold a million lines for one number: they are not all kept.
                    $toomany[$number] = true;
                    unset($found[$number]);

                    return;
                }
                $found[$number][] = $row;
            }
        );

        return [$found, $toomany];
    }

    /**
     * Read a semicolon-separated export file as a stream, and give each row, keyed by column name, to a function.
     *
     * Only the columns the function reads are kept: the export has columns that nothing here wants.
     *
     * @param string $content With or without the byte-order mark, CRLF or LF.
     * @param string $file How the file is named in a refusal.
     * @param string[] $required The columns that must be there.
     * @param callable $each Called with each row.
     * @throws fiche_refusal FILE_EMPTY, FORMAT.
     */
    private static function each_row(string $content, string $file, array $required, callable $each): void {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $content);
        rewind($stream);
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            fseek($stream, 3);
        }

        try {
            // The header is read for at most MAX_HEADER_BYTES: a first line of sixteen million semicolons is not a header.
            $line = fgets($stream, self::MAX_HEADER_BYTES + 1);
            if ($line === false || trim($line) === '') {
                throw new fiche_refusal(fiche_refusal::FILE_EMPTY, ['file' => $file], 'The file is empty.');
            }
            // The read stops at the cap: a line that fills it without ending is longer than any header.
            $endless = strlen($line) >= self::MAX_HEADER_BYTES && substr($line, -1) !== "\n";
            $header = $endless ? [] : str_getcsv(rtrim($line, "\r\n"), ';', '"', '');
            if (!$header || count($header) > self::MAX_COLUMNS) {
                throw new fiche_refusal(
                    fiche_refusal::FORMAT,
                    ['file' => $file, 'column' => $required[0]],
                    "The column {$required[0]} is missing: the header of the file is not one."
                );
            }
            $header = array_map('trim', $header);
            foreach ($required as $column) {
                if (!in_array($column, $header, true)) {
                    throw new fiche_refusal(
                        fiche_refusal::FORMAT,
                        ['file' => $file, 'column' => $column],
                        "The column $column is missing; the file has: " . implode(', ', $header) . '.'
                    );
                }
            }
            // The columns kept: the required ones, and the optional ones the standard file may carry.
            $kept = [];
            foreach ($header as $position => $name) {
                if (in_array($name, $required, true) || in_array($name, ['Actif', 'Date_Fin_Enregistrement'], true)) {
                    $kept[$position] = $name;
                }
            }
            $optional = array_values(array_intersect(['Actif', 'Date_Fin_Enregistrement'], $kept));

            while (($fields = fgetcsv($stream, 0, ';', '"', '')) !== false) {
                if ($fields === [null]) {
                    continue;
                }
                $row = [];
                foreach ($kept as $position => $name) {
                    $row[$name] = $fields[$position] ?? '';
                }
                foreach (['Actif', 'Date_Fin_Enregistrement'] as $name) {
                    if (!in_array($name, $optional, true)) {
                        $row[$name] = null;
                    }
                }
                $each($row);
            }
        } finally {
            fclose($stream);
        }
    }

    /**
     * One record, from its row of the standard file and its lines of the blocs file.
     *
     * @param string $number
     * @param array|null $record The row of the standard file, null when the record is not in it.
     * @param array[] $lines The lines of the blocs file.
     * @param bool $toomany Whether the file holds more blocs for it than any record has.
     * @return \stdClass|fiche_refusal
     */
    private static function build(string $number, ?array $record, array $lines, bool $toomany) {
        if ($record === null) {
            return new fiche_refusal(
                fiche_refusal::NOT_FOUND,
                ['number' => $number],
                "The record $number is not in the standard file."
            );
        }
        if (!empty($record['duplicate'])) {
            return new fiche_refusal(
                fiche_refusal::DUPLICATE,
                ['number' => $number, 'file' => self::FILE_STANDARD],
                "The record $number is twice in the standard file."
            );
        }
        if ($toomany) {
            return new fiche_refusal(
                fiche_refusal::TOO_MANY_BLOCS,
                ['number' => $number, 'max' => self::MAX_BLOCS_PER_RECORD],
                "The record $number has more than " . self::MAX_BLOCS_PER_RECORD . ' blocs in the export.'
            );
        }

        $faults = [];
        $title = self::tidy($record['Intitule']);
        $fault = self::text_fault($title, self::FILE_STANDARD);
        if ($fault) {
            $faults[] = $fault;
        }
        foreach (['Actif', 'Date_Fin_Enregistrement'] as $column) {
            if ($record[$column] === null) {
                continue;
            }
            $fault = self::text_fault(self::tidy($record[$column]), self::FILE_STANDARD, self::MAX_SHORT_CHARS);
            if ($fault) {
                $faults[] = $fault;
            }
        }

        $rows = [];
        $shortened = [];
        $long = [];
        $markup = [];
        foreach ($lines as $line) {
            $code = self::tidy($line['Bloc_Competences_Code']);
            if (
                self::length($code) > self::MAX_SHORT_CHARS
                || !preg_match('/^' . preg_quote($number, '/') . '(BC[0-9]{1,6})$/', $code, $matches)
            ) {
                $shown = mb_substr($code, 0, 40, 'UTF-8');
                $faults[] = [
                    fiche_refusal::BAD_BLOC,
                    "the bloc code \"$shown\" is not $number followed by BC and digits",
                    ['codes' => $shown],
                ];
                continue;
            }
            $rank = $matches[1];
            $label = self::tidy($line['Bloc_Competences_Libelle']);
            if ($label === '') {
                $faults[] = [fiche_refusal::NO_LABEL, "the bloc $code has no label", ['codes' => $code]];
                continue;
            }
            $fault = self::text_fault($label, self::FILE_BLOCS);
            if ($fault) {
                $faults[] = $fault;
                continue;
            }
            if (isset($rows[$rank])) {
                // The same rank twice: the second line would silently replace the first.
                $faults[] = [fiche_refusal::DUPLICATE, "the bloc $code is twice in the blocs file", ['codes' => $code]];
                continue;
            }

            if (self::length($label) > self::LABEL_MAXLENGTH) {
                $rows[$rank] = ['rang' => $rank, 'intitule' => self::SHORT_TITLE . $rank, 'description' => $label];
                $shortened[] = $rank;
                $long[] = $rank;
            } else if (self::is_markup($label)) {
                $rows[$rank] = ['rang' => $rank, 'intitule' => self::SHORT_TITLE . $rank, 'description' => $label];
                $shortened[] = $rank;
                $markup[] = $rank;
            } else {
                $rows[$rank] = ['rang' => $rank, 'intitule' => $label, 'description' => ''];
            }
        }
        if ($faults) {
            return self::refusal_of($number, $faults);
        }
        if (!$rows) {
            // A record in force with no bloc is not a number to be replaced (the advice for an old record): said apart.
            $inforce = $record['Actif'] !== null && self::tidy($record['Actif']) === 'ACTIVE';

            return new fiche_refusal(
                $inforce ? fiche_refusal::NO_BLOC_ACTIVE : fiche_refusal::NO_BLOC,
                ['number' => $number],
                "The record $number has no bloc in the export: there is nothing to import."
            );
        }

        uksort($rows, 'strnatcmp');
        sort($shortened, SORT_NATURAL);
        sort($long, SORT_NATURAL);
        sort($markup, SORT_NATURAL);

        return (object) [
            'meta' => (object) [
                'fichecode' => $number,
                'fichetype' => strncmp($number, 'RS', 2) === 0 ? 'rs' : 'rncp',
                // The name of the record is the title of a framework of the core: 100 characters at most, plain text, one line.
                // A title that is longer, that has several lines, that is empty or that the core would cut is not cut either:
                // the code stands as the name, and the full title is given beside it.
                'name' => self::fits_a_name($title) ? $title : $number,
                'title' => $title,
                'active' => $record['Actif'] === null ? null : self::tidy($record['Actif']),
                'enddate' => $record['Date_Fin_Enregistrement'] === null ? null : self::tidy($record['Date_Fin_Enregistrement']),
            ],
            'rows' => array_values($rows),
            'shortened' => $shortened,
            'long' => $long,
            'markup' => $markup,
        ];
    }

    /**
     * What is wrong with a text of the export that is kept: not UTF-8, a control character, longer than it may be.
     *
     * @param string $text
     * @param string $file How the file is named in a refusal.
     * @param int $max The most characters this text may have.
     * @return array|null [reason, English sentence, parameters], or null.
     */
    private static function text_fault(string $text, string $file, int $max = self::MAX_TEXT_CHARS): ?array {
        if (!mb_check_encoding($text, 'UTF-8')) {
            return [fiche_refusal::NOT_UTF8, "a text of the file $file is not UTF-8", ['file' => $file]];
        }
        if (preg_match(self::CONTROL_PATTERN, $text, $found)) {
            $code = sprintf('U+%04X', mb_ord($found[0], 'UTF-8'));

            return [fiche_refusal::CONTROL, "a text holds the control character $code", ['code' => $code]];
        }
        if (self::length($text) > $max) {
            return [fiche_refusal::TOO_LONG, "a text is longer than $max characters", ['max' => $max]];
        }

        return null;
    }

    /**
     * The refusal that says what is wrong with a record: the sentence of all its faults, the reason of the first.
     *
     * @param string $number
     * @param array[] $faults Each [reason, English sentence, parameters].
     * @return fiche_refusal
     */
    private static function refusal_of(string $number, array $faults): fiche_refusal {
        $reason = $faults[0][0];
        $params = ['number' => $number];
        $codes = [];
        foreach ($faults as $fault) {
            if ($fault[0] === $reason && isset($fault[2]['codes'])) {
                $codes[] = $fault[2]['codes'];
            }
        }
        if ($codes) {
            $params['codes'] = implode(', ', array_slice($codes, 0, 3));
        }
        foreach ($faults[0][2] as $name => $value) {
            if ($name !== 'codes') {
                $params[$name] = $value;
            }
        }

        return new fiche_refusal(
            $reason,
            $params,
            "The record $number cannot be converted: " . implode('; ', array_column($faults, 1)) . '.'
        );
    }

    /**
     * Whether a title can be the name of a framework of the core as it stands.
     *
     * @param string $title
     * @return bool
     */
    private static function fits_a_name(string $title): bool {
        return $title !== ''
            && self::length($title) <= self::LABEL_MAXLENGTH
            && !preg_match('/[\r\n]/', $title)
            && !self::is_markup($title);
    }

    /**
     * Whether the core would not take a text as the title of a competency: strip_tags() would change it.
     *
     * @param string $text
     * @return bool
     */
    private static function is_markup(string $text): bool {
        return strip_tags($text) !== $text;
    }

    /**
     * A text without the blanks around it, the same blanks as the importer takes off (importer::tidy()).
     *
     * trim() leaves a no-break space, which a French word processor writes and which the importer removes: a label measured
     * here and stored there must have the length it will have where it is checked.
     *
     * @param string $text
     * @return string
     */
    private static function tidy(string $text): string {
        // U+00A0 no-break space, U+202F narrow no-break space, U+FEFF zero-width no-break space.
        $tidy = preg_replace('/^[\s\x{00A0}\x{202F}\x{FEFF}]+|[\s\x{00A0}\x{202F}\x{FEFF}]+$/u', '', $text);

        // A text that is not UTF-8 is left as it is: text_fault() names it.
        return $tidy === null ? trim($text) : $tidy;
    }

    /**
     * A length in characters, not bytes.
     *
     * @param string $text
     * @return int
     */
    private static function length(string $text): int {
        return mb_strlen($text, 'UTF-8');
    }
}
