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
 * A word-processing document, written as .docx or .odt (T-82).
 *
 * NO DEPENDENCY, ON PURPOSE. Moodle ships a spreadsheet library and no word-processing one, and a
 * converter service (unoconv, a cloud drive) is not something a plugin may count on. Both formats are
 * zip archives of XML; for headings, paragraphs, bullet lists and tables, that is a few hundred lines.
 *
 * WHAT IT WRITES, AND WHAT IT DOES NOT. Real heading styles, real lists, tables whose first row is
 * declared a header row, and the language of the document - so a screen reader and the navigation
 * pane of a word processor have something to work with. It writes no images, no fonts, no page
 * numbering, no fields: a clean, simple document that the person then formats, not a layout.
 *
 * PURE. It reads no database and no session. The caller gives the blocks and receives the bytes.
 *
 *     $bytes = (new document_writer())
 *         ->title('Dossier')->language('fr')
 *         ->heading('Expériences', 1)->paragraph("Ligne un.\nLigne deux.")
 *         ->bullets(['un', 'deux'])->table(['Bloc', 'Activité'], [['BC01', 'OUI']])
 *         ->docx();
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class document_writer {
    /** @var string Media type of a .docx file. */
    public const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** @var string Media type of an .odt file. */
    public const ODT_MIME = 'application/vnd.oasis.opendocument.text';

    /**
     * @var int Deepest heading level.
     *
     * Four, and the styles of the two formats go with it: docx_styles() and odt_styles() define one style per level up to this
     * one, and a heading whose style is not defined is read by Word and LibreOffice as plain text. The constant alone would
     * write "Heading4" into a file that has no such style. A declaration under a bloc, and a section of the preparation of the
     * validation dossier under its record, are of level 4 (dossier_document::compose()); at 3 they were brothers of what
     * they sit under, and the navigation pane lost the hierarchy that the PDF keeps.
     */
    public const MAX_HEADING = 4;

    /** @var string The title of the document, in its properties. */
    private $title = '';

    /** @var string The language code, such as fr or en. */
    private $language = 'en';

    /** @var array[] The blocks, in order. */
    private $blocks = [];

    /**
     * Set the title kept in the document's properties.
     *
     * @param string $title
     * @return self
     */
    public function title(string $title): self {
        $this->title = $title;
        return $this;
    }

    /**
     * Set the language of the document.
     *
     * @param string $language A two letter code, such as fr.
     * @return self
     */
    public function language(string $language): self {
        $this->language = preg_match('/^[a-z]{2,3}$/', $language) ? $language : 'en';
        return $this;
    }

    /**
     * Add a heading.
     *
     * @param string $text
     * @param int $level 1 to MAX_HEADING.
     * @return self
     */
    public function heading(string $text, int $level = 1): self {
        $this->blocks[] = ['type' => 'heading', 'text' => $text, 'level' => max(1, min(self::MAX_HEADING, $level))];
        return $this;
    }

    /**
     * Add a paragraph. A line feed inside the text is a line break, not a new paragraph.
     *
     * @param string $text
     * @return self
     */
    public function paragraph(string $text): self {
        $this->blocks[] = ['type' => 'paragraph', 'text' => $text];
        return $this;
    }

    /**
     * Add a bullet list.
     *
     * @param string[] $items
     * @return self
     */
    public function bullets(array $items): self {
        $this->blocks[] = ['type' => 'bullets', 'items' => array_values(array_map('strval', $items))];
        return $this;
    }

    /**
     * Add a table. The first row is a header row.
     *
     * @param string[] $header One label per column.
     * @param string[][] $rows Each row has as many cells as the header has columns.
     * @return self
     */
    public function table(array $header, array $rows): self {
        $columns = count($header);
        if ($columns === 0) {
            throw new \coding_exception('A table needs at least one column.');
        }
        $clean = [];
        foreach ($rows as $row) {
            $cells = array_values(array_map('strval', $row));
            // A short row is completed and a long one cut: a table is rectangular or it is broken.
            $clean[] = array_slice(array_pad($cells, $columns, ''), 0, $columns);
        }
        $this->blocks[] = [
            'type' => 'table',
            'header' => array_values(array_map('strval', $header)),
            'rows' => $clean,
        ];
        return $this;
    }

    /**
     * The document as a .docx file.
     *
     * @return string The bytes.
     */
    public function docx(): string {
        $lang = $this->language;
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-'
                . 'officedocument.wordprocessingml.document.main+xml"/>'
                . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-'
                . 'officedocument.wordprocessingml.styles+xml"/>'
                . '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-'
                . 'officedocument.wordprocessingml.numbering+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-'
                . 'package.core-properties+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/'
                . 'relationships/officeDocument" Target="word/document.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/'
                . 'relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '</Relationships>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/'
                . 'relationships/styles" Target="styles.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/'
                . 'relationships/numbering" Target="numbering.xml"/>'
                . '</Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/'
                . 'core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/">'
                . '<dc:title>' . self::xml($this->title) . '</dc:title>'
                . '<dc:language>' . self::xml($lang) . '</dc:language>'
                . '</cp:coreProperties>',
            'word/styles.xml' => $this->docx_styles(),
            'word/numbering.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                . '<w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="hybridMultilevel"/>'
                . '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="&#8226;"/>'
                . '<w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>'
                . '</w:abstractNum><w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num></w:numbering>',
            'word/document.xml' => $this->docx_document(),
        ];

        return $this->zip($files, false);
    }

    /**
     * The document as an .odt file.
     *
     * @return string The bytes.
     */
    public function odt(): string {
        $files = [
            // First, and stored without compression: how a reader recognises the format.
            'mimetype' => self::ODT_MIME,
            'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" '
                . 'manifest:version="1.2">'
                . '<manifest:file-entry manifest:full-path="/" manifest:version="1.2" '
                . 'manifest:media-type="' . self::ODT_MIME . '"/>'
                . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
                . '<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>'
                . '<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>'
                . '</manifest:manifest>',
            'meta.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                . '<office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
                . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:meta="urn:oasis:names:tc:opendocument:'
                . 'xmlns:meta:1.0" office:version="1.2"><office:meta>'
                . '<dc:title>' . self::xml($this->title) . '</dc:title>'
                . '<dc:language>' . self::xml($this->language) . '</dc:language>'
                . '<meta:generator>tool_vae</meta:generator>'
                . '</office:meta></office:document-meta>',
            'styles.xml' => $this->odt_styles(),
            'content.xml' => $this->odt_content(),
        ];

        return $this->zip($files, true);
    }

    /**
     * Escape a string for an XML text node or attribute, and drop the characters XML 1.0 forbids.
     *
     * @param string $text
     * @return string
     */
    public static function xml(string $text): string {
        // The control characters that cannot appear in an XML 1.0 document, even escaped: a stray
        // form feed pasted into a narrative would make the whole file unreadable.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Pack the parts into a zip archive and return its bytes.
     *
     * @param string[] $files Path => content, in the order they must appear.
     * @param bool $storefirst Keep the first entry uncompressed (the ODT mimetype).
     * @return string
     */
    private function zip(array $files, bool $storefirst): string {
        $path = make_request_directory() . '/document.zip';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \moodle_exception('errorexportfailed', 'tool_vae');
        }
        $first = true;
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
            if ($first && $storefirst) {
                $zip->setCompressionName($name, \ZipArchive::CM_STORE);
            }
            $first = false;
        }
        $zip->close();

        $bytes = file_get_contents($path);
        @unlink($path);
        if ($bytes === false) {
            throw new \moodle_exception('errorexportfailed', 'tool_vae');
        }

        return $bytes;
    }

    /**
     * The text of a run, with line feeds as line breaks.
     *
     * @param string $text
     * @param bool $docx True for WordprocessingML, false for OpenDocument.
     * @return string
     */
    private static function runs(string $text, bool $docx): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $out = [];
        foreach ($lines as $line) {
            if ($docx) {
                $out[] = '<w:r><w:t xml:space="preserve">' . self::xml($line) . '</w:t></w:r>';
            } else {
                // A run of spaces is one space in OpenDocument unless it is said otherwise.
                $out[] = preg_replace_callback('/ {2,}/', function ($m) {
                    return ' <text:s text:c="' . (strlen($m[0]) - 1) . '"/>';
                }, self::xml($line));
            }
        }

        return $docx ? implode('<w:r><w:br/></w:r>', $out) : implode('<text:line-break/>', $out);
    }

    /**
     * The body of word/document.xml.
     *
     * @return string
     */
    private function docx_document(): string {
        $body = '';
        foreach ($this->blocks as $block) {
            switch ($block['type']) {
                case 'heading':
                    $body .= '<w:p><w:pPr><w:pStyle w:val="Heading' . (int) $block['level'] . '"/></w:pPr>'
                        . self::runs($block['text'], true) . '</w:p>';
                    break;
                case 'paragraph':
                    $body .= '<w:p>' . self::runs($block['text'], true) . '</w:p>';
                    break;
                case 'bullets':
                    foreach ($block['items'] as $item) {
                        $body .= '<w:p><w:pPr><w:pStyle w:val="ListParagraph"/><w:numPr><w:ilvl w:val="0"/>'
                            . '<w:numId w:val="1"/></w:numPr></w:pPr>' . self::runs($item, true) . '</w:p>';
                    }
                    break;
                case 'table':
                    $columns = count($block['header']);
                    $width = intdiv(9000, $columns);
                    $body .= '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="0" w:type="auto"/>'
                        . '<w:tblLook w:val="04A0" w:firstRow="1"/></w:tblPr><w:tblGrid>'
                        . str_repeat('<w:gridCol w:w="' . $width . '"/>', $columns) . '</w:tblGrid>';
                    $body .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
                    foreach ($block['header'] as $cell) {
                        $body .= '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/></w:tcPr>'
                            . '<w:p><w:pPr><w:pStyle w:val="TableHeading"/></w:pPr>'
                            . self::runs($cell, true) . '</w:p></w:tc>';
                    }
                    $body .= '</w:tr>';
                    foreach ($block['rows'] as $row) {
                        $body .= '<w:tr>';
                        foreach ($row as $cell) {
                            $body .= '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/></w:tcPr>'
                                . '<w:p>' . self::runs($cell, true) . '</w:p></w:tc>';
                        }
                        $body .= '</w:tr>';
                    }
                    // A table must be followed by a paragraph in WordprocessingML.
                    $body .= '</w:tbl><w:p/>';
                    break;
            }
        }
        if ($body === '') {
            $body = '<w:p/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $body
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417" w:header="708" '
            . 'w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>';
    }

    /**
     * The styles of a .docx file.
     *
     * @return string
     */
    private function docx_styles(): string {
        $heading = function (int $level, int $size) {
            return '<w:style w:type="paragraph" w:styleId="Heading' . $level . '"><w:name w:val="heading '
                . $level . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
                . '<w:pPr><w:keepNext/><w:spacing w:before="360" w:after="120"/><w:outlineLvl w:val="'
                . ($level - 1) . '"/></w:pPr><w:rPr><w:b/><w:sz w:val="' . $size . '"/></w:rPr></w:style>';
        };

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial"/>'
            . '<w:sz w:val="22"/><w:lang w:val="' . self::xml($this->language) . '"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr>'
            . '</w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/>'
            . '<w:qFormat/></w:style>'
            . $heading(1, 32) . $heading(2, 28) . $heading(3, 24) . $heading(4, 22)
            . '<w:style w:type="paragraph" w:styleId="ListParagraph"><w:name w:val="List Paragraph"/>'
            . '<w:basedOn w:val="Normal"/><w:qFormat/><w:pPr><w:ind w:left="720"/></w:pPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="TableHeading"><w:name w:val="Table Heading"/>'
            . '<w:basedOn w:val="Normal"/><w:qFormat/><w:rPr><w:b/></w:rPr></w:style>'
            . '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/>'
            . '<w:uiPriority w:val="99"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar>'
            . '<w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/>'
            . '<w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>'
            . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/>'
            . '<w:basedOn w:val="TableNormal"/><w:tblPr><w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:left w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:bottom w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:right w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:insideH w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '<w:insideV w:val="single" w:sz="4" w:space="0" w:color="auto"/>'
            . '</w:tblBorders></w:tblPr></w:style>'
            . '</w:styles>';
    }

    /**
     * The common namespaces of the OpenDocument parts.
     *
     * @return string
     */
    private static function odt_namespaces(): string {
        return 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
            . 'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
            . 'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
            . 'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
            . 'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" '
            . 'xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0"';
    }

    /**
     * The styles.xml of an .odt file.
     *
     * @return string
     */
    private function odt_styles(): string {
        $heading = function (int $level, string $size) {
            return '<style:style style:name="Heading_20_' . $level . '" style:display-name="Heading ' . $level
                . '" style:family="paragraph" style:parent-style-name="Standard" '
                . 'style:next-style-name="Text_20_body" style:default-outline-level="' . $level . '">'
                . '<style:paragraph-properties fo:margin-top="0.25in" fo:margin-bottom="0.08in" '
                . 'fo:keep-with-next="always"/>'
                . '<style:text-properties fo:font-size="' . $size . '" fo:font-weight="bold"/>'
                . '</style:style>';
        };

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<office:document-styles ' . self::odt_namespaces() . ' office:version="1.2">'
            . '<office:styles>'
            . '<style:default-style style:family="paragraph"><style:text-properties '
            . 'style:font-name="Arial" fo:font-size="11pt" fo:language="' . self::xml($this->language)
            . '" fo:country="none"/></style:default-style>'
            . '<style:style style:name="Standard" style:family="paragraph" style:class="text"/>'
            . '<style:style style:name="Text_20_body" style:display-name="Text body" style:family="paragraph" '
            . 'style:parent-style-name="Standard"><style:paragraph-properties fo:margin-bottom="0.08in"/>'
            . '</style:style>'
            . $heading(1, '16pt') . $heading(2, '14pt') . $heading(3, '12pt') . $heading(4, '11pt')
            . '<style:style style:name="Table_20_Heading" style:display-name="Table Heading" '
            . 'style:family="paragraph" style:parent-style-name="Standard">'
            . '<style:text-properties fo:font-weight="bold"/></style:style>'
            . '</office:styles></office:document-styles>';
    }

    /**
     * The content.xml of an .odt file.
     *
     * @return string
     */
    private function odt_content(): string {
        $body = '';
        $tables = 0;
        foreach ($this->blocks as $block) {
            switch ($block['type']) {
                case 'heading':
                    $body .= '<text:h text:style-name="Heading_20_' . (int) $block['level']
                        . '" text:outline-level="' . (int) $block['level'] . '">'
                        . self::runs($block['text'], false) . '</text:h>';
                    break;
                case 'paragraph':
                    $body .= '<text:p text:style-name="Text_20_body">' . self::runs($block['text'], false)
                        . '</text:p>';
                    break;
                case 'bullets':
                    $body .= '<text:list text:style-name="Bullets">';
                    foreach ($block['items'] as $item) {
                        $body .= '<text:list-item><text:p text:style-name="Text_20_body">'
                            . self::runs($item, false) . '</text:p></text:list-item>';
                    }
                    $body .= '</text:list>';
                    break;
                case 'table':
                    $tables++;
                    $columns = count($block['header']);
                    $body .= '<table:table table:name="Table' . $tables . '" table:style-name="Table">'
                        . '<table:table-column table:number-columns-repeated="' . $columns . '"/>'
                        . '<table:table-header-rows><table:table-row>';
                    foreach ($block['header'] as $cell) {
                        $body .= '<table:table-cell table:style-name="Cell" office:value-type="string">'
                            . '<text:p text:style-name="Table_20_Heading">' . self::runs($cell, false)
                            . '</text:p></table:table-cell>';
                    }
                    $body .= '</table:table-row></table:table-header-rows>';
                    foreach ($block['rows'] as $row) {
                        $body .= '<table:table-row>';
                        foreach ($row as $cell) {
                            $body .= '<table:table-cell table:style-name="Cell" office:value-type="string">'
                                . '<text:p text:style-name="Text_20_body">' . self::runs($cell, false)
                                . '</text:p></table:table-cell>';
                        }
                        $body .= '</table:table-row>';
                    }
                    $body .= '</table:table>';
                    break;
            }
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<office:document-content ' . self::odt_namespaces() . ' office:version="1.2">'
            . '<office:automatic-styles>'
            // Without a list style, a reader numbers the list: the bullets have to be said.
            . '<text:list-style style:name="Bullets"><text:list-level-style-bullet text:level="1" '
            . 'text:bullet-char="&#8226;"><style:list-level-properties '
            . 'text:list-level-position-and-space-mode="label-alignment"><style:list-level-label-alignment '
            . 'text:label-followed-by="listtab" text:list-tab-stop-position="0.25in" '
            . 'fo:text-indent="-0.25in" fo:margin-left="0.25in"/></style:list-level-properties>'
            . '</text:list-level-style-bullet></text:list-style>'
            . '<style:style style:name="Table" style:family="table"><style:table-properties '
            . 'style:width="6.2in" table:align="left"/></style:style>'
            . '<style:style style:name="Cell" style:family="table-cell"><style:table-cell-properties '
            . 'fo:padding="0.04in" fo:border="0.5pt solid #000000"/></style:style>'
            . '</office:automatic-styles>'
            . '<office:body><office:text>' . $body . '</office:text></office:body>'
            . '</office:document-content>';
    }
}
