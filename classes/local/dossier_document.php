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
 * An editable copy of a dossier, as a .docx or an .odt (T-83, decision D-8; T-10, lot 4a).
 *
 * WHAT IT IS. The sections of the dossier de faisabilité the candidate wrote, then the content of
 * the PDF in the archive (the declarations, their attestations, the certification records they
 * refer to), set in a word-processing document the candidate can open, correct and reuse. The
 * declarations are drawn from the same arrays the archive encodes (exporter::snapshot()), so the
 * copy cannot say what the archive does not. It is NOT the normative archive: it is not
 * registered, carries no digest of its files, and the notice at its head says so.
 *
 * THE SECTIONS ARE THE LATEST SAVED VERSION, never the draft in progress: the same text as the
 * archive will carry, and the notice says that an unsaved draft is not in it.
 *
 * IDENTITY, CONTACT AND STATUS (MC-11) are written only when the caller says so - the candidate,
 * and whoever holds tool/vae:identityview. Anyone else gets the copy without them, and a line that
 * says they were left out. The contact details are READ from the account at the time of the copy,
 * never kept (MC-10), and enter the digest, so that a changed profile makes a new weekly copy.
 *
 * THE LANGUAGE is that of the candidate's account, as for the archive (T-52): a copy kept in the
 * candidate's own files is read by the candidate, whoever pressed the button. So is THE TIMEZONE of
 * its dates: a period typed as a day is that day in the candidate's own timezone (display_date).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dossier_document {
    /**
     * @var int Version of the layout. Part of the digest: a change of layout makes the weekly task
     *      write a fresh copy even for a dossier that did not change.
     */
    public const LAYOUT = 6;

    /** @var string[] The formats a copy can have. */
    public const FORMATS = ['docx', 'odt'];

    /**
     * The document of a candidate, ready to be written in a format.
     *
     * WHO READS IT IS SAID BY THE CALLER, NEVER READ FROM $USER (audit of 26 October 2026). The weekly copy is written by a
     * task that runs as the main administrator, and refreshed in the session of a manager who withdraws a signature or of the
     * candidate who removes a piece: the same copy, in the candidate's own files, came out with the preparation of the
     * validation dossier or without it according to who was running. Each flag is the right of the READER of this copy:
     * the candidate's own for the copy kept in their files, the one the person downloading has for document.php. A caller
     * that says nothing gets the rights of the current user, which is only for tests.
     *
     * @param int $candidateid
     * @param int $now The time of issue, written in the notice.
     * @param bool $withidentity Whether identity, contact and status are written (MC-11).
     * @param exporter|null $exporter Only passed by tests.
     * @param bool|null $forowner Whether the preparation of the validation dossier is written: it is read by its author alone
     *        (D-15 (3)), so true for the copy kept in the candidate's files and for the one the candidate downloads. Null:
     *        true when the current user is the candidate.
     * @param bool|null $withtitles Whether the titles of the pieces are written: for whoever may open the pieces, as on the
     *        screen (may_open_pieces_of()) - identity is another right. Null: as the current user may.
     * @param bool|null $withsections Whether the saved sections of the dossier de faisabilite are written: for whoever may read
     *        them (draft_manager::can_read()). Null: as the current user may.
     * @return array ['writer' => document_writer, 'digest' => string, 'declarations' => int]
     * @throws \moodle_exception errorexportempty when the candidate has no declaration.
     */
    public static function for_candidate(
        int $candidateid,
        int $now,
        bool $withidentity = false,
        ?exporter $exporter = null,
        ?bool $forowner = null,
        ?bool $withtitles = null,
        ?bool $withsections = null
    ): array {
        global $CFG, $USER;

        $candidate = \core_user::get_user($candidateid, '*', MUST_EXIST);
        $exporter = $exporter ?? new exporter();
        $forowner = $forowner ?? ((int) $USER->id === $candidateid);
        $withtitles = $withtitles ?? self::may_open_pieces_of($candidateid);
        $withsections = $withsections ?? draft_manager::can_read($candidateid);

        $faisabilite = [
            'identity' => $withidentity,
            'saved' => $withsections ? faisabilite_overview::saved_versions($candidateid) : [],
            'account' => [],
        ];
        if ($withidentity) {
            foreach (array_keys(faisabilite_fields::account_keys()) as $section) {
                $faisabilite['account'][$section] = faisabilite_fields::account_values($section, $candidate);
            }
        }

        $lang = (string) $candidate->lang;
        $timezone = \core_date::get_user_timezone($candidate);
        $previous = force_current_language(
            get_string_manager()->translation_exists($lang, false) ? $lang : $CFG->lang
        );
        // The dates are the candidate's, as the language is: see exporter::build().
        $previoustimezone = display_date::force_timezone($timezone);
        try {
            $snapshot = $exporter->snapshot($candidateid);
            $validation = $forowner ? (validation_overview::document($candidateid) ?? []) : [];
            // The supporting pieces (T-13, lot 3): the list, never an address and never the file.
            // The titles for whoever may open the pieces, as on the screen; identity is another right (see may_open_pieces_of()).
            $pieces = piece_overview::by_declaration(piece_overview::document($candidateid, false, $withtitles));
            // What the candidate says is outside their activity (T-42): their own word, without effect.
            $scope = scope_manager::document($candidateid) ?? [];
            $writer = self::compose(
                $candidate,
                $snapshot['preuves'],
                $snapshot['referentiel'],
                $now,
                $faisabilite,
                $validation,
                $pieces,
                $scope
            );
        } finally {
            display_date::force_timezone($previoustimezone);
            force_current_language($previous);
        }

        return [
            'writer' => $writer,
            'digest' => self::digest($snapshot['preuves'], $snapshot['referentiel'], $faisabilite, $validation, $pieces, $scope),
            'declarations' => count($snapshot['preuves']),
        ];
    }

    /**
     * Whether a reader may open the supporting pieces of a candidate: the rule of the screen, for the copy.
     *
     * The candidate, whoever can attest the candidate (access::can_attest()), the administrator of the site; whoever may
     * only view the dossier sees the list and not the titles. Exactly the rule of piece_manager::may_open(), which this
     * repeats because that class is not the place of a copy: a title is free text, written by the candidate, that the screen
     * shows to these readers and to no other. The copy used to give it to whoever reads the identity, which the direction
     * does and which has no right to open a piece - the same reader read in the copy what the screen and the archive
     * withhold from them.
     *
     * @param int $candidateid
     * @param int|null $readerid The reader; the current user when null.
     * @return bool
     */
    public static function may_open_pieces_of(int $candidateid, ?int $readerid = null): bool {
        global $USER;

        $readerid = $readerid ?? (int) $USER->id;
        if ($readerid <= 0) {
            return false;
        }
        if ($readerid === $candidateid) {
            return has_capability('tool/vae:viewown', \context_user::instance($candidateid), $readerid);
        }

        return is_siteadmin($readerid) || access::can_attest($candidateid, $readerid);
    }

    /**
     * The digest of what a copy is written from.
     *
     * The same dossier gives the same digest whatever the day, so the weekly task can tell a copy
     * that would repeat the last one. The time of issue is not part of it; the values read from the
     * account are, and so is the choice to write identity or not.
     *
     * @param array[] $preuves
     * @param array $referentiel
     * @param array $faisabilite As for_candidate() builds it: identity, saved, account.
     * @param array $validation The preparation of the validation dossier, as validation_overview::document() builds it:
     *        a section saved again makes a new copy.
     * @param array[] $pieces The supporting pieces by declaration (T-13): a piece added or removed makes a new copy.
     * @param array $scope What the candidate says is outside their activity (T-42), as hors_activite.json writes it: a mark
     *        said or taken back makes a new copy. Left out of the digest when empty, so that a dossier with none digests as before.
     * @return string SHA-256, hexadecimal.
     */
    public static function digest(
        array $preuves,
        array $referentiel,
        array $faisabilite = [],
        array $validation = [],
        array $pieces = [],
        array $scope = []
    ): string {
        $parts = [self::LAYOUT, $preuves, $referentiel, $faisabilite, $validation, $pieces];
        if ($scope) {
            $parts[] = $scope;
        }

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE));
    }

    /**
     * The document, from the sections, the claims and the record.
     *
     * Public so that a test reads what the copy says. Written in whatever language is current.
     *
     * @param \stdClass $candidate
     * @param array[] $preuves As preuves/*.json write them.
     * @param array $referentiel As referentiel.json writes it.
     * @param int $now
     * @param array $faisabilite 'identity' bool, 'saved' as faisabilite_overview::saved_versions()
     *        returns it, 'account' as account_values() returns it per section.
     * @param array $validation The preparation of the validation dossier (T-11), as validation.json writes it.
     * @param array[] $pieces The supporting pieces by declaration identifier (T-13), as piece_overview::by_declaration() gives
     *        them.
     * @param array $scope What the candidate says is outside their activity (T-42), as hors_activite.json writes it.
     * @return document_writer
     */
    public static function compose(
        \stdClass $candidate,
        array $preuves,
        array $referentiel,
        int $now,
        array $faisabilite = [],
        array $validation = [],
        array $pieces = [],
        array $scope = []
    ): document_writer {
        global $CFG, $SITE;

        $date = get_string('strftimedate', 'langconfig');
        $title = get_string('exportpdftitle', 'tool_vae', fullname($candidate));

        $writer = (new document_writer())
            ->title($title)
            ->language(self::language_of($candidate));

        // The name of the platform is TEXT here, not HTML: format_string() answers in HTML (an ampersand comes back as an
        // entity), the writer escapes what it is given for XML, and the entity would be written out twice and read as such in
        // Word and in LibreOffice. The filters it applies are kept; its answer is brought back to text.
        $platform = html_entity_decode(
            strip_tags(format_string($SITE->fullname, true, ['context' => \context_system::instance()])),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $writer->heading($title, 1);
        $writer->bullets([
            get_string('exportpdfexportedon', 'tool_vae') . ' : '
                . display_date::format($now, get_string('strftimedatetime', 'langconfig')),
            get_string('exportpdfplatform', 'tool_vae') . ' : ' . $platform . ' - ' . $CFG->wwwroot,
        ]);
        $writer->paragraph(get_string('documentnotice', 'tool_vae'));

        self::sections($writer, $faisabilite);

        foreach ($referentiel['fiches'] as $fiche) {
            $heading = $fiche['code'];
            if ($fiche['type'] !== null) {
                $heading .= ' - ' . get_string($fiche['type'] === 'rs' ? 'typers' : 'typerncp', 'tool_vae');
            }
            $writer->heading($heading, 2);

            // The provenance line the Licence Ouverte 2.0 requires wherever its text is shown.
            if ($fiche['licence'] !== null) {
                $writer->paragraph($fiche['mise_a_jour_source'] === null
                    ? get_string('sourceprovenancepending', 'tool_vae', (object) [
                        'attribution' => $fiche['attribution'],
                        'licence' => $fiche['licence'],
                    ])
                    : get_string('sourceprovenance', 'tool_vae', (object) [
                        'attribution' => $fiche['attribution'],
                        'licence' => $fiche['licence'],
                        'updated' => display_date::format(self::time($fiche['mise_a_jour_source']), $date),
                    ]));
            }

            // Where the certifier publishes its rule of validation (T-41): addresses in text, and the sentence.
            if (!empty($fiche['regle_validation'])) {
                $writer->paragraph(ref_manager::plain_line($fiche['regle_validation']));
                $writer->paragraph(get_string('refsentence', 'tool_vae'));
            }

            $competences = array_column($fiche['competences'], null, 'identifiant');
            $ofthisfiche = array_values(array_filter($preuves, fn($preuve) => $preuve['fiche']['code'] === $fiche['code']));

            // A record with blocs (T-33): the declarations by bloc, a heading for each, the bloc itself first, as the PDF
            // does. A flat record has no `blocs` and is written as it always was.
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
                if ($blocs && $bloc($preuve) !== $current) {
                    $current = $bloc($preuve);
                    $writer->heading($current === null
                        ? get_string('exportpdfnobloc', 'tool_vae')
                        : get_string('exportpdfbloc', 'tool_vae', $current . ' - ' . ($blocs[$current]['intitule'] ?? '')), 3);
                }
                self::declaration(
                    $writer,
                    $preuve,
                    $competences[$preuve['competence']] ?? null,
                    $date,
                    $blocs ? 4 : 3,
                    $pieces[$preuve['id']] ?? []
                );
            }
        }

        // What the candidate says is outside their activity (T-42), with the sentence that says it exempts from nothing.
        if (!empty($scope['competences'])) {
            $writer->heading(get_string('scopedossierheading', 'tool_vae'), 2);
            $writer->paragraph($scope['avertissement']);
            $lines = [];
            foreach ($scope['competences'] as $entry) {
                $line = $entry['fiche'] . '-' . $entry['rang'];
                $line .= isset($entry['intitule']) ? ' - ' . $entry['intitule'] : '';
                $line .= isset($entry['note']) ? ' - ' . $entry['note'] : '';
                $lines[] = $line;
            }
            $writer->bullets($lines);
        }

        // The preparation of the validation dossier, last, with the sentence that says what it is (T-11, MC-17).
        if (!empty($validation['rubriques'])) {
            $writer->heading(get_string('validationtitle', 'tool_vae'), 2);
            $writer->paragraph($validation['avertissement']);
            $current = null;
            foreach ($validation['rubriques'] as $rubrique) {
                if ($rubrique['fiche'] !== $current) {
                    $current = $rubrique['fiche'];
                    $writer->heading($current, 3);
                }
                $writer->heading(validation_catalog::label($rubrique['fiche'], $rubrique['code']), 4);
                foreach (self::paragraphs($rubrique['texte'], 'texte') as $paragraph) {
                    $writer->paragraph($paragraph);
                }
            }
        }

        return $writer;
    }

    /**
     * The sections of the dossier de faisabilité, in the order of the annex.
     *
     * @param document_writer $writer
     * @param array $faisabilite See compose().
     */
    private static function sections(document_writer $writer, array $faisabilite): void {
        $saved = $faisabilite['saved'] ?? [];
        $withidentity = !empty($faisabilite['identity']);
        $account = $faisabilite['account'] ?? [];

        $blocks = [];
        $omitted = false;
        foreach (faisabilite_catalog::sections() as $code => $definition) {
            $kind = $definition['kind'];

            if ($kind === faisabilite_catalog::KIND_TEXT) {
                foreach ($saved[$code] ?? [] as $slot => $version) {
                    $paragraphs = self::paragraphs((string) $version['content'], 'texte');
                    if ($paragraphs) {
                        $blocks[] = [faisabilite_catalog::label($code, (int) $slot), $paragraphs, false];
                    }
                }
                continue;
            }

            // Identity, status and contact: only for the candidate and whoever may read them (MC-11).
            if (!$withidentity) {
                $omitted = true;
                continue;
            }

            $lines = [];
            foreach ($account[$code] ?? [] as $key => $value) {
                $lines[] = get_string('faisabaccount:' . $key, 'tool_vae') . ' : ' . $value;
            }
            if ($kind === faisabilite_catalog::KIND_FIELDS && isset($saved[$code][0])) {
                $values = faisabilite_fields::decode($code, (string) $saved[$code][0]['content']);
                $readable = faisabilite_fields::readable_values($code, $values);
                if ($readable !== '') {
                    $lines = array_merge($lines, explode("\n", $readable));
                }
            }
            if ($lines) {
                $blocks[] = [faisabilite_catalog::label($code, 0), $lines, true];
            }
        }

        if (!$blocks && !$omitted) {
            return;
        }

        $writer->heading(get_string('faisabheading', 'tool_vae'), 2);
        foreach ($blocks as [$label, $content, $aslist]) {
            $writer->heading($label, 3);
            if ($aslist) {
                $writer->bullets($content);
            } else {
                foreach ($content as $paragraph) {
                    $writer->paragraph($paragraph);
                }
            }
        }
        if ($omitted) {
            $writer->paragraph(get_string('documentidentityomitted', 'tool_vae'));
        }
    }

    /**
     * One claim, with its attestations.
     *
     * @param document_writer $writer
     * @param array $preuve
     * @param array|null $competence Its entry in referentiel.json, which carries the label.
     * @param string $date The date format.
     * @param int $level The level of the heading: 4 under a bloc, 3 otherwise.
     * @param array[] $pieces The supporting pieces of this declaration (T-13), each an entry of pieces.json.
     */
    private static function declaration(
        document_writer $writer,
        array $preuve,
        ?array $competence,
        string $date,
        int $level = 3,
        array $pieces = []
    ): void {
        $title = $preuve['fiche']['rang'];
        if (($competence['intitule'] ?? null) !== null) {
            $title .= ' - ' . $competence['intitule'];
        }
        $writer->heading($title, $level);

        $rows = [
            get_string('ficheupdated', 'tool_vae') . ' : '
                . display_date::format(self::time($preuve['fiche']['mise_a_jour']), $date),
            get_string('exportpdfdeclaredon', 'tool_vae') . ' : '
                . display_date::format(self::time($preuve['declaree_le']), $date),
            get_string('period', 'tool_vae') . ' : ' . get_string('periodfromto', 'tool_vae', (object) [
                'from' => display_date::format(self::time($preuve['periode']['debut']), $date),
                'to' => display_date::format(self::time($preuve['periode']['fin']), $date),
            ]),
            get_string('settingtype', 'tool_vae') . ' : '
                . get_string('setting:' . $preuve['cadre']['type'], 'tool_vae'),
        ];
        if (isset($preuve['cadre']['precision'])) {
            $rows[] = get_string('settingdetail', 'tool_vae') . ' : ' . $preuve['cadre']['precision'];
        }
        $writer->bullets($rows);

        $writer->paragraph(get_string('situation', 'tool_vae') . ' :');
        foreach (self::paragraphs($preuve['situation']['texte'], $preuve['situation']['format']) as $paragraph) {
            $writer->paragraph($paragraph);
        }

        // The supporting pieces (T-13, lot 3): listed, never an address and never the file.
        if ($pieces) {
            $writer->paragraph(get_string('exportpdfpieces', 'tool_vae') . ' :');
            $writer->bullets(array_map(fn($entry) => piece_overview::line($entry), $pieces));
        }

        $writer->paragraph(get_string('exportpdfattestations', 'tool_vae') . ' :');
        if (!$preuve['attestations']) {
            $writer->paragraph(get_string('noattestationyet', 'tool_vae'));
            return;
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
                $items[] = $sentence;
                continue;
            }

            $items[] = $attestation['role'] . "\n" . $attestation['declaration'] . "\n"
                . get_string('attestedon', 'tool_vae', display_date::format(self::time($attestation['attestee_le']), $date));
        }
        $writer->bullets($items);
    }

    /**
     * A narrative as plain paragraphs.
     *
     * A document has no markup of its own to keep here: the Markdown is rendered and reduced to
     * text, which keeps the paragraphs and the line breaks and drops the rest.
     *
     * @param string $text
     * @param string $format 'markdown' or 'texte', as the claim file says.
     * @return string[]
     */
    private static function paragraphs(string $text, string $format): array {
        if ($format === 'markdown') {
            $html = format_text($text, FORMAT_MARKDOWN, [
                'context' => \context_system::instance(),
                'para' => true,
                'filter' => false,
            ]);
            $text = html_to_text($html, 0, false);
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '') {
            return [];
        }

        return preg_split('/\n{2,}/', $text) ?: [$text];
    }

    /**
     * The two letter code of the candidate's language, for the document's properties.
     *
     * @param \stdClass $candidate
     * @return string
     */
    private static function language_of(\stdClass $candidate): string {
        return substr((string) $candidate->lang, 0, 2) ?: 'en';
    }

    /**
     * A date-time of the archive's arrays as a Unix timestamp.
     *
     * @param string $value ISO 8601, as exporter writes it.
     * @return int
     */
    private static function time(string $value): int {
        return (new \DateTimeImmutable($value))->getTimestamp();
    }
}
