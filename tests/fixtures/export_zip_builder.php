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

namespace tool_vae\fixtures;

/**
 * Builds, at run time, an INVENTED export of France compétences in the shape of the real one (T-31).
 *
 * THE SHAPE IS MEASURED, THE DATA IS NOT. Read on the daily exports of 19 and 27 October 2026: ten entries in the archive,
 * names with an accent (`Blocs_De_Compétences`, `VoixdAccès`) and the date of the day BEFORE the one in the name of the
 * archive, semicolon-separated files with every value in double quotes except an empty one, CRLF at the end of a line, a
 * line feed inside a quoted field, no byte-order mark, and an archive entry `Partenaires` of 32 Mo that nothing here
 * reads. No datum of France compétences is in the tree (open point PO-2): the records are invented, the codes are
 * RNCP99xxx and RS99xxx, and the CSV is built from arrays, never read from the fixtures of the other tests - so the bytes
 * are the same on a Windows tree (CRLF, byte-order mark), in the container and in the continuous integration.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class export_zip_builder {
    /** @var string[] The columns of the standard file, as the export writes them. */
    private const STANDARD_COLUMNS = [
        'Id_Fiche', 'Numero_Fiche', 'Intitule', 'Abrege_Libelle', 'Abrege_Intitule', 'Nomenclature_Europe_Niveau',
        'Nomenclature_Europe_Intitule', 'Accessible_Nouvelle_Caledonie', 'Accessible_Polynesie_Francaise',
        'Date_dernier_jo', 'Date_Decision', 'Date_Fin_Enregistrement', 'Date_Effet', 'Type_Enregistrement',
        'Validation_Partielle', 'Actif',
    ];

    /** @var string[] The other entries of the archive, which nothing reads. */
    private const OTHER_ENTRIES = [
        'VoixdAcc%C3%A8s', 'Ancienne_Nouvelle_Certification', 'CCN', 'Certificateurs', 'Formacode', 'Nsf', 'Rome',
    ];

    /**
     * The invented records.
     *
     * @return array[] Each with number, title, active, end (as the export writes it, or empty) and blocs (pairs of a
     *         code - a rank, or a whole code when it starts with RNCP or RS - and a label).
     */
    public static function records(): array {
        return [
            [
                'number' => 'RNCP99001',
                'title' => "Diplôme inventé d'accompagnement (DIA)",
                'active' => 'ACTIVE',
                'end' => '31/08/2030',
                'blocs' => [
                    ['BC03', "Un libellé de bloc inventé, volontairement plus long que cent caractères, pour vérifier "
                        . "qu'il est publié en entier dans la description"],
                    ['BC10', 'Dixième bloc inventé'],
                    ['BC01', ' Premier bloc inventé, avec un blanc devant'],
                    ['BC02', "Évaluer l'état de la personne et agir avec soin"],
                ],
            ],
            [
                'number' => 'RNCP99002',
                'title' => "Titre inventé d'une certification dont l'intitulé dépasse largement\nles cent caractères permis "
                    . "pour le nom d'un référentiel du cœur",
                'active' => 'ACTIVE',
                'end' => '31/08/2030',
                'blocs' => [['BC01', 'Bloc de la fiche au titre trop long']],
            ],
            [
                'number' => 'RNCP99003',
                'title' => 'Certification inventée sans bloc',
                'active' => 'ACTIVE',
                'end' => '31/08/2030',
                'blocs' => [],
            ],
            [
                'number' => 'RNCP99004',
                'title' => 'Certification inventée aux codes de bloc faux',
                'active' => 'ACTIVE',
                'end' => '31/08/2030',
                'blocs' => [
                    ['BC01', 'Un bloc correct'],
                    ['RNCP99999BC02', "Un bloc dont le code est celui d'une autre fiche"],
                ],
            ],
            [
                'number' => 'RS99005',
                'title' => 'Habilitation inventée du répertoire spécifique',
                'active' => 'ACTIVE',
                'end' => '31/08/2030',
                'blocs' => [],
            ],
            [
                'number' => 'RNCP99006',
                'title' => 'Certification inventée, devenue inactive',
                'active' => 'INACTIVE',
                'end' => '31/12/2020',
                'blocs' => [
                    ['BC01', 'Installer un mât (<10m) dans une gaine'],
                    ['BC02', 'Hauteur de levée > 1,20 m'],
                ],
            ],
            [
                'number' => 'RNCP99007',
                'title' => 'Certification inventée, devenue inactive, sans bloc',
                'active' => 'INACTIVE',
                'end' => '31/12/2020',
                'blocs' => [],
            ],
        ];
    }

    /**
     * The standard file.
     *
     * @param array[] $records What records() returns, or a selection.
     * @param array $options bom (bool, false), eol (string, CRLF).
     * @return string
     */
    public static function standard(array $records, array $options = []): string {
        $eol = $options['eol'] ?? "\r\n";
        $lines = [self::line(self::STANDARD_COLUMNS, true)];
        foreach (array_values($records) as $position => $record) {
            $lines[] = self::line([
                (string) (9001 + $position), $record['number'], $record['title'], 'DE', "Diplôme d'état", 'NIV4', 'Niveau 4',
                'Non', 'Non', '', '', $record['end'], '01/09/2026', 'Enregistrement de droit', 'Oui', $record['active'],
            ]);
        }

        return (!empty($options['bom']) ? "\xEF\xBB\xBF" : '') . implode($eol, $lines) . $eol;
    }

    /**
     * The blocs file.
     *
     * @param array[] $records What records() returns, or a selection.
     * @param array $options bom (bool, false), eol (string, CRLF).
     * @return string
     */
    public static function blocs(array $records, array $options = []): string {
        $eol = $options['eol'] ?? "\r\n";
        $lines = [self::line(['Numero_Fiche', 'Bloc_Competences_Code', 'Bloc_Competences_Libelle'], true)];
        foreach ($records as $record) {
            foreach ($record['blocs'] as [$code, $label]) {
                $whole = preg_match('/^(RNCP|RS)\d/', $code) ? $code : $record['number'] . $code;
                $lines[] = self::line([$record['number'], $whole, $label]);
            }
        }

        return (!empty($options['bom']) ? "\xEF\xBB\xBF" : '') . implode($eol, $lines) . $eol;
    }

    /**
     * Write an archive in the shape of the daily export.
     *
     * @param string $directory Where to write it; it must exist.
     * @param string $name The name of the archive, e.g. export-fiches-csv-2026-10-19.zip.
     * @param array $options inner (AAAA_MM_JJ in the names of the entries; default the day before the one in $name, else
     *        2026_10_18), bom (false), eol (CRLF), nfd (the accent of the blocs entry written e + U+0301), latin1name (the
     *        accent written as one byte), records, blocs and standard (the whole content of the file, to put anything in
     *        it), partners (the number of bytes of zeros of the Partenaires entry; 64), omit (['blocs'] or ['standard']),
     *        duplicate (['blocs'] - a second blocs entry), extra (name => content, any further entry), encrypt (the name of
     *        an entry to protect with a password), dates (['blocs' => AAAA_MM_JJ, 'standard' => AAAA_MM_JJ]),
     *        store (every entry written without compression: a byte changed in it changes no length).
     * @return string The path of the archive.
     */
    public static function build(string $directory, string $name, array $options = []): string {
        $records = $options['records'] ?? self::records();
        $inner = $options['inner'] ?? self::day_before($name) ?? '2026_10_18';
        $dates = ($options['dates'] ?? []) + ['blocs' => $inner, 'standard' => $inner];
        $omit = $options['omit'] ?? [];
        $accent = "\u{00E9}";
        $blocsname = 'export_fiches_CSV_Blocs_De_Comp' . $accent . 'tences_' . $dates['blocs'] . '.csv';
        $flags = \ZipArchive::FL_OVERWRITE | \ZipArchive::FL_ENC_UTF_8;
        if (!empty($options['nfd'])) {
            $blocsname = 'export_fiches_CSV_Blocs_De_Compe' . "\u{0301}" . 'tences_' . $dates['blocs'] . '.csv';
        }
        if (!empty($options['latin1name'])) {
            $blocsname = 'export_fiches_CSV_Blocs_De_Comp' . "\xE9" . 'tences_' . $dates['blocs'] . '.csv';
        }
        $latin = !empty($options['latin1name']);

        $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            unlink($path);
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \coding_exception('The test archive cannot be created.');
        }

        foreach (self::OTHER_ENTRIES as $other) {
            $label = rawurldecode($other);
            $zip->addFromString('export_fiches_CSV_' . $label . '_' . $inner . '.csv', "\"Numero_Fiche\"\r\n", $flags);
        }
        $zip->addFromString(
            'export_fiches_CSV_Partenaires_' . $inner . '.csv',
            str_repeat("\0", (int) ($options['partners'] ?? 64)),
            $flags
        );
        if (!in_array('standard', $omit, true)) {
            $zip->addFromString(
                'export_fiches_CSV_Standard_' . $dates['standard'] . '.csv',
                $options['standard'] ?? self::standard($records, $options),
                $flags
            );
        }
        if (!in_array('blocs', $omit, true)) {
            $zip->addFromString(
                $blocsname,
                $options['blocs'] ?? self::blocs($records, $options),
                $latin ? \ZipArchive::FL_OVERWRITE | \ZipArchive::FL_ENC_RAW : $flags
            );
        }
        if (in_array('blocs', $options['duplicate'] ?? [], true)) {
            $zip->addFromString(
                'export_fiches_CSV_Blocs_De_Comp' . $accent . 'tences_2026_10_17.csv',
                self::blocs($records, $options),
                $flags
            );
        }
        foreach ($options['extra'] ?? [] as $extraname => $content) {
            $zip->addFromString($extraname, $content, \ZipArchive::FL_OVERWRITE | \ZipArchive::FL_ENC_RAW);
        }
        if (!empty($options['store'])) {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $zip->setCompressionIndex($index, \ZipArchive::CM_STORE);
            }
        }
        if (!empty($options['encrypt'])) {
            $zip->setEncryptionName($options['encrypt'], \ZipArchive::EM_AES_256, 'invented-password');
        }
        $zip->close();

        return $path;
    }

    /**
     * Make an entry declare another size than the one it has (the central directory of the archive is rewritten).
     *
     * @param string $zippath
     * @param string $entry The name of the entry, as it was written.
     * @param int $size The size to declare.
     */
    public static function lie_about_size(string $zippath, string $entry, int $size): void {
        $bytes = file_get_contents($zippath);
        $offset = 0;
        $patched = false;
        while (($offset = strpos($bytes, "PK\x01\x02", $offset)) !== false) {
            $namelength = unpack('v', substr($bytes, $offset + 28, 2))[1];
            if (substr($bytes, $offset + 46, $namelength) === $entry) {
                $bytes = substr_replace($bytes, pack('V', $size), $offset + 24, 4);
                $patched = true;
                break;
            }
            $offset += 4;
        }
        if (!$patched) {
            throw new \coding_exception("The entry $entry is not in the central directory.");
        }
        file_put_contents($zippath, $bytes);
    }

    /**
     * One line of a file of the export: every value quoted but an empty one, semicolon-separated.
     *
     * @param string[] $values
     * @param bool $header Whether it is the header line (every name quoted).
     * @return string
     */
    private static function line(array $values, bool $header = false): string {
        return implode(';', array_map(
            fn($value) => $value === '' && !$header ? '' : '"' . str_replace('"', '""', $value) . '"',
            $values
        ));
    }

    /**
     * The day before the one in the name of an archive.
     *
     * @param string $name
     * @return string|null AAAA_MM_JJ, or null when the name carries no date.
     */
    private static function day_before(string $name): ?string {
        if (!preg_match('/(\d{4})-(\d{2})-(\d{2})/', $name, $parts)) {
            return null;
        }

        return gmdate('Y_m_d', gmmktime(12, 0, 0, (int) $parts[2], (int) $parts[3], (int) $parts[1]) - DAYSECS);
    }
}
