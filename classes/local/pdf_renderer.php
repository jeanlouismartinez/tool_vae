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
 * The printable copy of the dossier, dossier.pdf inside the archive - R4 of the inventory.
 *
 * DECIDED WITH THE PROJECT OWNER, 12 OCTOBER 2026:
 *   - the PDF lives in the archive, listed in its manifest like every other file, hence covered
 *     by its digests and by the register of issued archives; the reimport checks it and ignores it;
 *   - it shows the dossier as a panel reads it, and nothing that weighs it: no coverage table, no
 *     count, no percentage - the coverage stays the candidate's alone (R3);
 *   - it is drawn from the very arrays the JSON files of the archive encode, never from a query of
 *     its own, so that the printed copy and the archive cannot disagree. It may even be redrawn
 *     from a decoded archive: html() takes nothing else.
 *
 * WHAT IT SHOWS OF A WITHDRAWN ATTESTATION is what the claim file holds: a date and a reason.
 * Nothing here can show more, since nothing more reaches it (MC-9).
 *
 * ESCAPING, as in presenter: every field goes through s(), the narrative through format_text()
 * with the format the candidate chose, plain text or Markdown. Then only text markup survives:
 * TCPDF would fetch the address of an image while rendering, and it reads attributes.
 *
 * DETERMINISM, AND ITS LIMIT. The creation and modification dates of the PDF are the time of the
 * export. TCPDF still draws a random document identifier for every file, so two renderings of the
 * same dossier do not give the same bytes: the digest of dossier.pdf proves that a copy is the one
 * this archive carries, not that it can be produced again.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pdf_renderer {
    /** @var string Where the printable copy lives in the archive. */
    public const PATH = 'dossier.pdf';

    /** @var string A unicode font shipped with Moodle's TCPDF: French accents render. */
    private const FONT = 'freesans';

    /** @var string[] The markup a narrative keeps once format_text() has cleaned it. */
    private const NARRATIVE_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr',
    ];

    /**
     * The PDF, as bytes.
     *
     * @param array $manifest At least archive, exporte_le, produit_par, candidat and avertissement,
     *                        as manifeste.json writes them.
     * @param array[] $preuves The claims, as preuves/*.json write them.
     * @param array $referentiel As referentiel.json writes it.
     * @param array[] $rubriques The text sections of the dossier de faisabilité, as faisabilite.json
     *                           writes them; none when the candidate has written none.
     * @param array $validation The preparation of the validation dossier, as validation.json writes it (T-11);
     *                          empty when the candidate has written none.
     * @param array[] $pieces The supporting pieces by declaration identifier, as piece_overview::by_declaration() gives
     *                        them (T-13): a list, never an address.
     * @param array $scope The competencies the candidate says are outside their activity, as hors_activite.json writes them
     *                     (T-42); empty when there are none.
     * @return string
     */
    public static function render(
        array $manifest,
        array $preuves,
        array $referentiel,
        array $rubriques = [],
        array $validation = [],
        array $pieces = [],
        array $scope = []
    ): string {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');

        $exported = self::time($manifest['exporte_le']);

        $pdf = new \pdf();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setDocCreationTimestamp($exported);
        $pdf->setDocModificationTimestamp($exported);
        $pdf->setCreator($manifest['produit_par']['composant'] . ' ' . $manifest['produit_par']['version_composant']);
        $pdf->setTitle(get_string('exportpdftitle', 'tool_vae', $manifest['candidat']['nom']));
        $pdf->setMargins(20, 20, 20);
        // Room at the foot of every page for the lines written below.
        $pdf->setAutoPageBreak(true, 30);
        $pdf->setFont(self::FONT, '', 10);
        $pdf->AddPage();
        foreach (self::chunks($manifest, $preuves, $referentiel, $rubriques, $validation, $pieces, $scope) as $chunk) {
            if (!$chunk['together']) {
                $pdf->writeHTML($chunk['html']);
                continue;
            }

            // A declaration is not cut between two pages (T-50, found on a real PDF on
            // 13 October 2026: a title and three lines at the foot, the rest on the next page).
            // Written once to see; if it ran onto another page and did not start at the top of
            // one, it is taken back and written again from the top of the next. One longer than
            // a page cannot be kept whole and flows as before.
            $startpage = $pdf->getPage();
            $starty = $pdf->GetY();
            $pdf->startTransaction();
            $pdf->writeHTML($chunk['html']);

            $attop = $starty <= $pdf->getMargins()['top'] + 1;
            if ($pdf->getPage() !== $startpage && !$attop) {
                $pdf->rollbackTransaction(true);
                $pdf->AddPage();
                $pdf->writeHTML($chunk['html']);
            } else {
                $pdf->commitTransaction();
            }
        }

        // The footer is written once the number of pages is known, page by page.
        $verify = get_string('exportpdfverify', 'tool_vae', $manifest['archive']);
        $pages = $pdf->getNumPages();
        for ($page = 1; $page <= $pages; $page++) {
            $pdf->setPage($page);
            // AFTER setPage(), and on every page. setPage() restores the page-break trigger the
            // page was created with, which leaves 30 mm at the foot; the footer starts 25 mm
            // from the bottom, so with the trigger back on, writing it opened a new page, put the
            // footer at the top of THAT page and left a blank last page behind. Found on a real
            // Moodle 5.3 on 13 October 2026, by looking at the PDF; no test had.
            $pdf->setAutoPageBreak(false, 0);
            // The same goes for the font: setPage() brings back the one the page ended on, which
            // on a page ending with a label was bold at 10 pt - a footer in the wrong weight that
            // ran off the right edge.
            $pdf->setFont(self::FONT, '', 8);
            $pdf->setY(-25);
            $number = get_string('exportpdfpage', 'tool_vae', (object) ['page' => $page, 'pages' => $pages]);
            $pdf->MultiCell(0, 0, $verify . "\n" . $number, 0, 'C');
        }

        // The footer must never add a page: if it did, the numbers it prints would be wrong and
        // the last page would be blank. Said aloud rather than left in the document.
        if ($pdf->getNumPages() !== $pages) {
            throw new \coding_exception('The footer of the PDF opened a page: ' . $pages . ' became ' . $pdf->getNumPages());
        }

        return $pdf->Output(self::PATH, 'S');
    }

    /**
     * The body of the PDF, as the HTML handed to TCPDF.
     *
     * Public so that a test reads what the PDF says without parsing a PDF.
     *
     * @param array $manifest See render().
     * @param array[] $preuves See render().
     * @param array $referentiel See render().
     * @param array[] $rubriques See render().
     * @param array $validation See render().
     * @param array[] $pieces See render().
     * @param array $scope See render().
     * @return string
     */
    public static function html(
        array $manifest,
        array $preuves,
        array $referentiel,
        array $rubriques = [],
        array $validation = [],
        array $pieces = [],
        array $scope = []
    ): string {
        $chunks = self::chunks($manifest, $preuves, $referentiel, $rubriques, $validation, $pieces, $scope);

        return implode('', array_column($chunks, 'html'));
    }

    /**
     * The body of the PDF in the pieces that must not be split across two pages.
     *
     * A declaration is one piece, and the first one of a certification record carries the heading
     * of the record with it, so that a heading is never left alone at the foot of a page. The head
     * of the document is free to flow.
     *
     * @param array $manifest See render().
     * @param array[] $preuves See render().
     * @param array $referentiel See render().
     * @param array[] $rubriques See render().
     * @param array $validation See render().
     * @param array[] $pieces See render().
     * @param array $scope See render().
     * @return array[] Each ['html' => string, 'together' => bool], in reading order.
     */
    private static function chunks(
        array $manifest,
        array $preuves,
        array $referentiel,
        array $rubriques = [],
        array $validation = [],
        array $pieces = [],
        array $scope = []
    ): array {
        global $CFG, $SITE;

        $producer = $manifest['produit_par'];
        $head = \html_writer::tag('h1', s(get_string('exportpdftitle', 'tool_vae', $manifest['candidat']['nom'])));
        $head .= self::rows([
            get_string('exportpdfarchive', 'tool_vae') => s($manifest['archive']),
            get_string('exportpdfexportedon', 'tool_vae')
                => s(display_date::format(self::time($manifest['exporte_le']), get_string('strftimedatetime', 'langconfig'))),
            get_string('exportpdfplatform', 'tool_vae')
                => format_string($SITE->fullname, true, ['context' => \context_system::instance()]) . ' - ' . s($CFG->wwwroot),
            get_string('exportpdfproducedby', 'tool_vae')
                => s($producer['composant'] . ' ' . $producer['version_composant']),
        ]);
        $head .= \html_writer::tag('p', s($manifest['avertissement']));

        $chunks = [['html' => $head, 'together' => false]];

        // The sections of the dossier de faisabilité come before the declarations, the first one
        // with the heading of the whole, and each with the very text faisabilite.json carries.
        foreach ($rubriques as $index => $rubrique) {
            $html = $index === 0
                ? \html_writer::tag('h2', s(get_string('exportpdffaisabilite', 'tool_vae')))
                : '';
            $label = faisabilite_catalog::label((string) $rubrique['code'], (int) $rubrique['emplacement']);
            $html .= \html_writer::tag('h3', s($label));
            $html .= self::narrative((string) $rubrique['texte'], 'texte');
            $chunks[] = ['html' => $html, 'together' => true];
        }

        $byid = array_column($preuves, null, 'id');
        foreach ($referentiel['fiches'] as $fiche) {
            $pending = self::fiche($fiche);

            $competences = array_column($fiche['competences'], null, 'identifiant');
            $ofthisfiche = array_values(array_filter($preuves, fn($preuve) => $preuve['fiche']['code'] === $fiche['code']));

            // A record with blocs (T-33): the declarations by bloc, a heading for each, the bloc itself first. The
            // archive of a flat record has no `blocs` and is written as it always was.
            $blocs = array_column($fiche['blocs'] ?? [], null, 'rang');
            $bloc = fn($preuve) => $competences[$preuve['competence']]['bloc']
                ?? (isset($blocs[$preuve['fiche']['rang']]) ? $preuve['fiche']['rang'] : null);
            if ($blocs) {
                // The bloc itself first in its group; the rest keep the order they had (usort is stable).
                usort($ofthisfiche, function ($a, $b) use ($bloc) {
                    $ka = $bloc($a);
                    $kb = $bloc($b);

                    return [$ka === null ? 1 : 0, strnatcmp((string) $ka, (string) $kb), $ka === $a['fiche']['rang'] ? 0 : 1]
                        <=> [$kb === null ? 1 : 0, 0, $kb === $b['fiche']['rang'] ? 0 : 1];
                });
            }
            $current = false;
            foreach ($ofthisfiche as $preuve) {
                $heading = '';
                if ($blocs && $bloc($preuve) !== $current) {
                    $current = $bloc($preuve);
                    // A rule above it: the title of a declaration under it is an h3 too, and without the rule the
                    // two read as one level (seen on the rendered PDF, 21 October 2026).
                    $heading = \html_writer::empty_tag('hr') . \html_writer::tag('h3', s($current === null
                        ? get_string('exportpdfnobloc', 'tool_vae')
                        : get_string('exportpdfbloc', 'tool_vae', $current . ' - ' . ($blocs[$current]['intitule'] ?? ''))));
                }
                $from = $byid[$preuve['reportee_depuis'] ?? ''] ?? null;
                $chunks[] = [
                    'html' => $pending . $heading . self::declaration(
                        $preuve,
                        $competences[$preuve['competence']] ?? null,
                        $from,
                        $pieces[$preuve['id']] ?? []
                    ),
                    'together' => true,
                ];
                $pending = '';
            }

            // A record with no declaration: its heading alone.
            if ($pending !== '') {
                $chunks[] = ['html' => $pending, 'together' => false];
            }
        }

        // What the candidate says is outside their activity (T-42): a list, with the sentence that says it exempts from nothing.
        if (!empty($scope['competences'])) {
            $items = [];
            foreach ($scope['competences'] as $entry) {
                $line = s($entry['fiche'] . '-' . $entry['rang']);
                $line .= isset($entry['intitule']) ? ' - ' . s($entry['intitule']) : '';
                $line .= isset($entry['note']) ? ' - ' . s($entry['note']) : '';
                $items[] = \html_writer::tag('li', $line);
            }
            $chunks[] = [
                'html' => \html_writer::tag('h2', s(get_string('scopedossierheading', 'tool_vae')))
                    . \html_writer::tag('p', \html_writer::tag('em', s($scope['avertissement'])))
                    . \html_writer::tag('ul', implode('', $items)),
                'together' => true,
            ];
        }

        // The preparation of the validation dossier (T-11): last, with the sentence that says what it is and that
        // nobody attests it. Each section under its record and its name, the text as the candidate wrote it.
        if (!empty($validation['rubriques'])) {
            $head = \html_writer::tag('h2', s(get_string('validationtitle', 'tool_vae')))
                . \html_writer::tag('p', \html_writer::tag('em', s($validation['avertissement'])));
            $chunks[] = ['html' => $head, 'together' => false];
            $current = null;
            foreach ($validation['rubriques'] as $rubrique) {
                $html = '';
                if ($rubrique['fiche'] !== $current) {
                    $current = $rubrique['fiche'];
                    $html .= \html_writer::tag('h3', s($current));
                }
                $html .= \html_writer::tag('h4', s(validation_catalog::label($rubrique['fiche'], $rubrique['code'])))
                    . self::narrative($rubrique['texte'], 'texte');
                $chunks[] = ['html' => $html, 'together' => true];
            }
        }

        return $chunks;
    }

    /**
     * The heading of a certification record, with the provenance line the Licence Ouverte 2.0
     * requires wherever its text is shown - the same line presenter prints.
     *
     * @param array $fiche One entry of referentiel.json's fiches.
     * @return string HTML.
     */
    private static function fiche(array $fiche): string {
        $title = $fiche['code'];
        if ($fiche['type'] !== null) {
            $title .= ' - ' . get_string($fiche['type'] === 'rs' ? 'typers' : 'typerncp', 'tool_vae');
        }
        $out = \html_writer::tag('h2', s($title));

        // A record this site never imported has no source row, hence nothing to attribute.
        if ($fiche['licence'] !== null) {
            $out .= \html_writer::tag('p', s($fiche['mise_a_jour_source'] === null
                ? get_string('sourceprovenancepending', 'tool_vae', (object) [
                    'attribution' => $fiche['attribution'],
                    'licence' => $fiche['licence'],
                ])
                : get_string('sourceprovenance', 'tool_vae', (object) [
                    'attribution' => $fiche['attribution'],
                    'licence' => $fiche['licence'],
                    'updated' => display_date::format(
                        self::time($fiche['mise_a_jour_source']),
                        get_string('strftimedate', 'langconfig')
                    ),
                ])));
        }

        // Where the certifier publishes its rule of validation (T-41): addresses in text, and the sentence that says the plugin
        // applies nothing. Nothing is quoted.
        if (!empty($fiche['regle_validation'])) {
            $out .= \html_writer::tag('p', s(ref_manager::plain_line($fiche['regle_validation'])));
            $out .= \html_writer::tag('p', \html_writer::tag('em', s(get_string('refsentence', 'tool_vae'))));
        }

        return $out;
    }

    /**
     * One claim, with its attestations.
     *
     * @param array $preuve
     * @param array|null $competence Its entry in referentiel.json, which carries the label.
     * @param array|null $from The claim this one was carried over from (T-30), when the archive holds it.
     * @param array[] $pieces The supporting pieces of this declaration (T-13), each an entry of pieces.json.
     * @return string HTML.
     */
    private static function declaration(array $preuve, ?array $competence, ?array $from = null, array $pieces = []): string {
        $date = get_string('strftimedate', 'langconfig');

        $title = $preuve['fiche']['rang'];
        if (($competence['intitule'] ?? null) !== null) {
            $title .= ' - ' . $competence['intitule'];
        }
        $out = \html_writer::tag('h3', s($title));

        $rows = [
            get_string('ficheupdated', 'tool_vae') => s(display_date::format(self::time($preuve['fiche']['mise_a_jour']), $date)),
            get_string('exportpdfdeclaredon', 'tool_vae') => s(display_date::format(self::time($preuve['declaree_le']), $date)),
            get_string('period', 'tool_vae') => s(get_string('periodfromto', 'tool_vae', (object) [
                'from' => display_date::format(self::time($preuve['periode']['debut']), $date),
                'to' => display_date::format(self::time($preuve['periode']['fin']), $date),
            ])),
            get_string('settingtype', 'tool_vae')
                => s(get_string('setting:' . $preuve['cadre']['type'], 'tool_vae')),
        ];
        if (isset($preuve['cadre']['precision'])) {
            $rows[get_string('settingdetail', 'tool_vae')] = s($preuve['cadre']['precision']);
        }
        // Carried over from a claim on the record this one replaces (T-30): where from and when, no more.
        if ($from !== null && isset($preuve['reportee_le'])) {
            $rows[get_string('exportpdfcarriedlabel', 'tool_vae')] = s(get_string('exportpdfcarried', 'tool_vae', (object) [
                'record' => $from['fiche']['code'] . '-' . $from['fiche']['rang'],
                'date' => display_date::format(self::time($preuve['reportee_le']), $date),
            ]));
        }
        $out .= self::rows($rows);

        $out .= \html_writer::tag('h4', s(get_string('situation', 'tool_vae')));
        $out .= self::narrative($preuve['situation']['texte'], $preuve['situation']['format']);

        // The supporting pieces (T-13, lot 3): listed, never incorporated, and no address: this is the copy the candidate
        // shows. The archive holds the rest.
        if ($pieces) {
            $out .= \html_writer::tag('h4', s(get_string('exportpdfpieces', 'tool_vae')));
            $out .= \html_writer::alist(array_map(fn($entry) => s(piece_overview::line($entry)), $pieces));
        }

        $out .= \html_writer::tag('h4', s(get_string('exportpdfattestations', 'tool_vae')));
        if (!$preuve['attestations']) {
            return $out . \html_writer::tag('p', s(get_string('noattestationyet', 'tool_vae')));
        }

        // The way out is said only when no attestation stands, and once, after the last one withdrawn (as the screen does).
        $standing = in_array('en_vigueur', array_column($preuve['attestations'], 'statut'), true);
        $last = array_key_last($preuve['attestations']);

        $items = [];
        foreach ($preuve['attestations'] as $key => $attestation) {
            if ($attestation['statut'] !== 'en_vigueur') {
                $sentence = get_string('attestationwithdrawnon', 'tool_vae', (object) [
                    'date' => display_date::format(self::time($attestation['retiree_le']), $date),
                    'reason' => get_string('withdrawreason:' . $attestation['motif'], 'tool_vae'),
                ]);
                if (!$standing && $key === $last) {
                    $sentence .= ' ' . get_string('attestationwithdrawnagain', 'tool_vae');
                }
                $items[] = s($sentence);
                continue;
            }

            $items[] = \html_writer::tag('strong', s($attestation['role']))
                . \html_writer::empty_tag('br')
                . nl2br(s($attestation['declaration']))
                . \html_writer::empty_tag('br')
                . s(get_string('attestedon', 'tool_vae', display_date::format(self::time($attestation['attestee_le']), $date)));
        }

        return $out . \html_writer::alist($items);
    }

    /**
     * The narrative, cleaned, then stripped to text markup without attributes.
     *
     * @param string $text
     * @param string $format 'markdown' or 'texte', as the claim file says.
     * @return string HTML.
     */
    private static function narrative(string $text, string $format): string {
        $html = format_text($text, $format === 'markdown' ? FORMAT_MARKDOWN : FORMAT_PLAIN, [
            'context' => \context_system::instance(),
            'para' => true,
            'filter' => false,
        ]);
        $html = strip_tags($html, self::NARRATIVE_TAGS);

        // Every tag left is one of the list above; whatever it carried besides its name goes.
        return preg_replace('/<([a-z][a-z0-9]*)\b[^>]*>/i', '<$1>', $html);
    }

    /**
     * Label and value pairs, as a borderless table TCPDF lays out on two columns.
     *
     * @param string[] $rows Plain-text label => value already escaped.
     * @return string HTML.
     */
    private static function rows(array $rows): string {
        $out = '<table cellpadding="2">';
        foreach ($rows as $label => $value) {
            $out .= '<tr><td width="35%"><strong>' . s($label) . '</strong></td><td width="65%">' . $value . '</td></tr>';
        }

        return $out . '</table>';
    }

    /**
     * A date-time of the archive as a Unix timestamp.
     *
     * @param string $value ISO 8601, as exporter writes it.
     * @return int
     */
    private static function time(string $value): int {
        return (new \DateTimeImmutable($value))->getTimestamp();
    }
}
