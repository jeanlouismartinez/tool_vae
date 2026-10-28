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

use tool_vae\local\document_writer;

/**
 * The .docx and .odt files (T-82).
 *
 * WHAT IS ASSERTED. That the archive is the shape each format demands, that every XML part is
 * well formed, and that what was written can be read back - text, headings, lists, the header row
 * of a table. A file that a word processor refuses to open fails here, not in front of the person
 * who asked for it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\document_writer::class)]
final class document_writer_test extends \advanced_testcase {
    /**
     * A document with one of everything.
     *
     * @return document_writer
     */
    private function sample(): document_writer {
        return (new document_writer())
            ->title('Dossier de faisabilité')
            ->language('fr')
            ->heading('Expériences', 1)
            ->paragraph("Première ligne.\nDeuxième ligne avec  deux espaces.")
            ->heading('Détail', 2)
            ->bullets(['Un élément', 'Un autre élément'])
            ->table(['Bloc', 'Activité'], [['BC01', 'OUI'], ['BC02', 'PARTIELLEMENT']]);
    }

    /**
     * Open bytes as a zip archive and return the archive and its path.
     *
     * @param string $bytes
     * @return \ZipArchive
     */
    private function open(string $bytes): \ZipArchive {
        $path = make_request_directory() . '/x.zip';
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'The bytes are a zip archive.');

        return $zip;
    }

    /**
     * Read a part as a DOM document, refusing one that is not well formed.
     *
     * @param \ZipArchive $zip
     * @param string $name
     * @return \DOMDocument
     */
    private function dom(\ZipArchive $zip, string $name): \DOMDocument {
        $xml = $zip->getFromName($name);
        $this->assertNotFalse($xml, "$name is in the archive.");
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        $this->assertTrue($loaded, "$name is well formed XML: " . ($errors ? trim($errors[0]->message) : ''));

        return $dom;
    }

    public function test_the_docx_has_every_part_a_reader_needs_and_all_are_well_formed(): void {
        $zip = $this->open($this->sample()->docx());

        foreach (
            ['[Content_Types].xml', '_rels/.rels', 'word/document.xml', 'word/_rels/document.xml.rels',
                'word/styles.xml', 'word/numbering.xml', 'docProps/core.xml'] as $part
        ) {
            $this->dom($zip, $part);
        }
        $this->assertStringContainsString(
            document_writer::DOCX_MIME === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                ? 'wordprocessingml.document.main+xml' : '',
            $zip->getFromName('[Content_Types].xml')
        );
    }

    public function test_the_docx_text_headings_list_and_table_read_back(): void {
        $zip = $this->open($this->sample()->docx());
        $dom = $this->dom($zip, 'word/document.xml');
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $texts = [];
        foreach ($xp->query('//w:t') as $node) {
            $texts[] = $node->textContent;
        }
        $this->assertContains('Expériences', $texts);
        $this->assertContains('Première ligne.', $texts);
        $this->assertContains('Deuxième ligne avec  deux espaces.', $texts, 'Spaces are kept.');
        $this->assertContains('PARTIELLEMENT', $texts);

        $this->assertSame(1, $xp->query('//w:pStyle[@w:val="Heading1"]')->length);
        $this->assertSame(1, $xp->query('//w:pStyle[@w:val="Heading2"]')->length);
        $this->assertSame(2, $xp->query('//w:numPr')->length, 'Two list items are real list paragraphs.');
        $this->assertSame(1, $xp->query('//w:tbl')->length);
        $this->assertSame(1, $xp->query('//w:tblHeader')->length, 'The first row is declared a header row.');
        $this->assertSame(3, $xp->query('//w:tr')->length);
        $this->assertSame(1, $xp->query('//w:br')->length, 'A line feed is a line break.');
    }

    public function test_the_docx_carries_the_language_and_the_title(): void {
        $zip = $this->open($this->sample()->docx());

        $this->assertStringContainsString('w:lang w:val="fr"', $zip->getFromName('word/styles.xml'));
        $this->assertStringContainsString('<dc:title>Dossier de faisabilité</dc:title>', $zip->getFromName('docProps/core.xml'));
    }

    public function test_the_odt_mimetype_is_first_and_stored_uncompressed(): void {
        $bytes = $this->sample()->odt();
        $zip = $this->open($bytes);

        $this->assertSame('mimetype', $zip->getNameIndex(0), 'The mimetype is the first entry.');
        $this->assertSame(document_writer::ODT_MIME, $zip->getFromName('mimetype'));
        $stat = $zip->statIndex(0);
        $this->assertSame(\ZipArchive::CM_STORE, $stat['comp_method'], 'It is stored, not compressed.');
        // The ODF rule in plain bytes: the mimetype sits at a fixed place, readable without unzipping.
        $this->assertStringContainsString('mimetype' . document_writer::ODT_MIME, substr($bytes, 30, 80));
    }

    public function test_the_odt_parts_are_well_formed_and_read_back(): void {
        $zip = $this->open($this->sample()->odt());

        foreach (['META-INF/manifest.xml', 'meta.xml', 'styles.xml', 'content.xml'] as $part) {
            $this->dom($zip, $part);
        }
        $dom = $this->dom($zip, 'content.xml');
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
        $xp->registerNamespace('table', 'urn:oasis:names:tc:opendocument:xmlns:table:1.0');

        $this->assertSame(2, $xp->query('//text:h')->length);
        $this->assertSame('1', $xp->query('//text:h')->item(0)->getAttribute('text:outline-level'));
        $this->assertSame(2, $xp->query('//text:list/text:list-item')->length);
        $this->assertSame(1, $xp->query('//table:table')->length);
        $this->assertSame(1, $xp->query('//table:table-header-rows')->length, 'The first row is the header.');
        $this->assertSame(1, $xp->query('//text:line-break')->length);
        $this->assertStringContainsString('PARTIELLEMENT', $dom->textContent);
        $this->assertSame('fr', substr(
            $zip->getFromName('styles.xml') !== false
                ? preg_match('/fo:language="([a-z]+)"/', $zip->getFromName('styles.xml'), $m) ? $m[1] : '' : '',
            0,
            2
        ));
    }

    /**
     * Audit 54: four levels of heading are written, with a style each, and a fifth is brought back to the fourth.
     *
     * Before: MAX_HEADING was 3, so the fourth level that dossier_document asks for (a declaration under a bloc, a section of
     * the validation preparation under its record) was written as the third, in a file that had no fourth style anyway.
     */
    public function test_a_fourth_heading_level_is_written_with_its_style_and_a_fifth_is_clamped(): void {
        $writer = (new document_writer())->heading('A', 3)->heading('B', 4)->heading('C', 5);

        $docx = $this->open($writer->docx());
        $xp = new \DOMXPath($this->dom($docx, 'word/document.xml'));
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $this->assertSame(2, $xp->query('//w:pStyle[@w:val="Heading4"]')->length, 'B and C, the fifth brought back to the fourth.');
        $this->assertSame(1, $xp->query('//w:pStyle[@w:val="Heading3"]')->length);
        $this->assertSame(0, $xp->query('//w:pStyle[@w:val="Heading5"]')->length);
        $styles = $docx->getFromName('word/styles.xml');
        $this->assertStringContainsString('w:styleId="Heading4"', $styles, 'A level with no style is read as plain text.');
        $this->assertStringContainsString('<w:outlineLvl w:val="3"/>', $styles);

        $odt = $this->open($writer->odt());
        $xp = new \DOMXPath($this->dom($odt, 'content.xml'));
        $xp->registerNamespace('text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0');
        $this->assertSame(2, $xp->query('//text:h[@text:outline-level="4"]')->length);
        $this->assertSame(0, $xp->query('//text:h[@text:outline-level="5"]')->length);
        $this->assertStringContainsString('style:name="Heading_20_4"', $odt->getFromName('styles.xml'));
    }

    public function test_the_odt_list_has_bullets_and_is_not_numbered_by_default(): void {
        // Found by reading the file back with pandoc on 15 October 2026: without a list style, a
        // reader numbers the items 1, 2. The bullets are declared and the list points at them.
        $zip = $this->open($this->sample()->odt());
        $content = $zip->getFromName('content.xml');

        $this->assertStringContainsString('<text:list-level-style-bullet text:level="1"', $content);
        $this->assertStringContainsString('<text:list text:style-name="Bullets">', $content);
    }

    public function test_the_characters_xml_forbids_are_dropped_and_the_others_escaped(): void {
        $bytes = (new document_writer())
            ->paragraph("A <b>&amp;</b> \"q\" 'a' \x0B\x0C\x00 fin")
            ->docx();
        $zip = $this->open($bytes);
        $dom = $this->dom($zip, 'word/document.xml');

        $this->assertSame("A <b>&amp;</b> \"q\" 'a'  fin", $dom->textContent, 'Control characters go, the rest stays.');
        $this->assertStringNotContainsString("\x0B", $zip->getFromName('word/document.xml'));
    }

    public function test_the_same_holds_for_the_odt(): void {
        $zip = $this->open((new document_writer())->paragraph("A <b>&amp;</b> \x0C fin")->odt());

        $dom = $this->dom($zip, 'content.xml');
        // The control character goes, leaving two spaces: OpenDocument says the second one with
        // text:s, which is an empty element and so has no text of its own.
        $this->assertStringContainsString('A <b>&amp;</b> fin', $dom->textContent);
        $this->assertStringContainsString('<text:s text:c="1"/>', $zip->getFromName('content.xml'));
    }

    public function test_a_short_row_is_completed_and_a_long_one_cut(): void {
        $zip = $this->open(
            (new document_writer())->table(['A', 'B'], [['1'], ['1', '2', '3']])->docx()
        );
        $xp = new \DOMXPath($this->dom($zip, 'word/document.xml'));
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        foreach ($xp->query('//w:tr') as $row) {
            $this->assertSame(2, $xp->query('w:tc', $row)->length, 'A table is rectangular.');
        }
    }

    public function test_an_empty_document_is_still_a_valid_file(): void {
        $docx = $this->open((new document_writer())->docx());
        $this->dom($docx, 'word/document.xml');
        $odt = $this->open((new document_writer())->odt());
        $this->dom($odt, 'content.xml');
    }

    public function test_a_table_without_columns_is_refused(): void {
        $this->expectException(\coding_exception::class);
        (new document_writer())->table([], []);
    }

    public function test_an_unknown_language_falls_back_to_english(): void {
        $zip = $this->open((new document_writer())->language('français!')->docx());

        $this->assertStringContainsString('w:lang w:val="en"', $zip->getFromName('word/styles.xml'));
    }

    public function test_a_long_text_in_a_table_cell_survives(): void {
        $long = str_repeat("Une phrase assez longue pour un récit.\n", 400);
        $zip = $this->open((new document_writer())->table(['Récit'], [[$long]])->docx());
        $dom = $this->dom($zip, 'word/document.xml');

        $this->assertSame(400, substr_count($dom->textContent, 'Une phrase assez longue'));
    }
}
