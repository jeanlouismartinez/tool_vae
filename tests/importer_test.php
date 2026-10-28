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

use tool_vae\local\competency_gateway;
use tool_vae\local\course_linker;
use tool_vae\local\evidence_manager;
use tool_vae\local\importer;
use tool_vae\local\supersession;

/**
 * Criterion 5: importing the same certification record twice must damage nothing.
 *
 * WHY THIS IS THE CRITERION AND NOT A DETAIL. France compétences republishes its open data
 * often, and an administrator re-imports the same record several times a year. Every claim ever
 * made against that record hangs off the competencies this import touches. A second pass that
 * created duplicates, or renumbered anything, would not break a test - it would quietly change
 * what a candidate's dossier says they did.
 *
 * THE FIXTURE IS INVENTED, AND THAT IS DELIBERATE. See tests/fixtures/LISEZMOI-fixtures.md: a
 * real file from data.gouv.fr would put Licence Ouverte 2.0 data inside a GPLv3 tree, which is
 * open point PO-2, unsettled.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\importer::class)]
final class importer_test extends \advanced_testcase {
    /**
     * Every test starts from a site holding nothing.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        $this->resetAfterTest();
        require_once(__DIR__ . '/fixtures/failing_import_gateway.php');
        $this->setAdminUser();
    }

    /**
     * The provenance an administrator types on the import screen.
     *
     * @param array $overrides
     * @return \stdClass
     */
    private function meta(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'fichecode' => 'RS00000',
            'fichetype' => 'rs',
            'name' => 'Certification inventée pour les tests',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'Personne — jeu d\'essai',
        ]);
    }

    /**
     * Rows as the CSV reader hands them over.
     *
     * @param array $rows rank => label
     * @return array
     */
    private function rows(array $rows): array {
        $out = [];
        foreach ($rows as $rang => $intitule) {
            $out[] = (object) ['rang' => $rang, 'intitule' => $intitule, 'description' => ''];
        }

        return $out;
    }

    /**
     * A first import creates the framework, the competencies and the provenance row.
     */
    public function test_a_first_import_creates_the_record(): void {
        global $DB;

        $result = (new importer())->import($this->meta(), $this->rows([
            'BC01' => 'Accompagner la personne',
            'BC02' => 'Repérer les évolutions',
        ]));

        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->updated);

        $source = $DB->get_record('tool_vae_source', ['fichecode' => 'RS00000'], '*', MUST_EXIST);
        $this->assertEquals('ok', $source->lastimportstatus);
        $this->assertEquals('Licence Ouverte 2.0', $source->licence);
        $this->assertEquals($result->frameworkid, $source->frameworkid);

        $competencies = (new competency_gateway())->list_framework_competencies($result->frameworkid);
        $idnumbers = array_column($competencies, 'idnumber');
        sort($idnumbers);
        $this->assertSame(['RS00000-BC01', 'RS00000-BC02'], $idnumbers);
    }

    /**
     * CRITERION 5. The same file imported twice changes nothing and duplicates nothing.
     */
    public function test_the_same_file_twice_changes_nothing(): void {
        $rows = $this->rows(['BC01' => 'Accompagner la personne', 'BC02' => 'Repérer les évolutions']);
        $importer = new importer();

        $first = $importer->import($this->meta(), $rows);
        $second = $importer->import($this->meta(), $rows);

        $this->assertSame(0, $second->created, 'A second pass created competencies again.');
        $this->assertSame(0, $second->updated);
        $this->assertSame(0, $second->gone);
        $this->assertSame($first->frameworkid, $second->frameworkid, 'A second framework was created.');

        $this->assertCount(2, (new competency_gateway())->list_framework_competencies($second->frameworkid));
    }

    /**
     * And it leaves every claim exactly where it was.
     *
     * The assertion that matters most in this file. A re-import that renumbered a competency
     * would leave the claim pointing at a label that is no longer the one it was made against -
     * and nothing in the dossier would say so.
     */
    public function test_a_re_import_does_not_touch_a_claim(): void {
        global $DB;

        $rows = $this->rows(['BC01' => 'Accompagner la personne']);
        $importer = new importer();
        $first = $importer->import($this->meta(), $rows);

        $claim = $this->declare_against($first->frameworkid, 'RS00000-BC01');
        $before = $DB->get_record('tool_vae_evidence', ['id' => $claim->id], '*', MUST_EXIST);

        $importer->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne au quotidien']));

        $after = $DB->get_record('tool_vae_evidence', ['id' => $claim->id], '*', MUST_EXIST);
        $this->assertEquals($before, $after, 'A re-import moved a claim.');
        $this->assertEquals(1, $DB->count_records('competency_userevidencecomp', [
            'userevidenceid' => $claim->userevidenceid,
        ]));
    }

    /**
     * A label republished by the source is taken over.
     */
    public function test_a_label_the_source_changed_is_taken_over(): void {
        $importer = new importer();
        $first = $importer->import($this->meta(), $this->rows(['BC01' => 'Ancien intitulé']));

        // A new publication date: the record itself moved.
        $result = $importer->import(
            $this->meta(['sourceupdated' => strtotime('2026-07-15')]),
            $this->rows(['BC01' => 'Nouvel intitulé publié'])
        );

        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->diverged, 'A published change was reported as a local one.');

        $competencies = (new competency_gateway())->list_framework_competencies($first->frameworkid);
        $competency = reset($competencies);
        $this->assertSame('Nouvel intitulé publié', $competency->shortname);
    }

    /**
     * A label changed on this site, with the record standing still, is a DIVERGENCE.
     *
     * The two cases look identical row by row: a label that no longer matches. Only the
     * publication date tells them apart, and the difference matters - one is France compétences
     * doing its job, the other is somebody editing a competency in tool_lp.
     */
    public function test_a_label_changed_locally_is_recorded_as_a_divergence(): void {
        global $DB;

        $importer = new importer();
        $first = $importer->import($this->meta(), $this->rows(['BC01' => 'Intitulé publié']));

        // Somebody edits it in tool_lp.
        $competencies = (new competency_gateway())->list_framework_competencies($first->frameworkid);
        $competency = reset($competencies);
        (new competency_gateway())->update_competency((int) $competency->id, 'Intitulé bricolé sur le site');

        // The same file, the same publication date: the record has not moved.
        $result = $importer->import($this->meta(), $this->rows(['BC01' => 'Intitulé publié']));

        $this->assertSame(1, $result->diverged);

        // The whole row, not three of its four columns: a divergence filed against the wrong
        // competency, or against the wrong record, is a divergence filed against nobody.
        $sourceid = (int) $DB->get_field('tool_vae_source', 'id', ['fichecode' => 'RS00000']);
        $marks = $DB->get_records('tool_vae_obsolete');
        $this->assertCount(1, $marks);
        $mark = reset($marks);
        $this->assertEquals($competency->id, $mark->competencyid, 'The wrong competency was marked.');
        $this->assertEquals($sourceid, $mark->sourceid, 'The divergence was filed against the wrong record.');
        $this->assertSame(importer::DIVERGED, $mark->kind);

        $again = (new competency_gateway())->list_framework_competencies($first->frameworkid);
        $this->assertSame('Intitulé publié', reset($again)->shortname, 'The published label was not restored.');
    }

    /**
     * One row carrying a description, as a file with a third column hands it over.
     *
     * @param string $intitule
     * @param string $description
     * @return array
     */
    private function described(string $intitule, string $description): array {
        return [(object) ['rang' => 'BC01', 'intitule' => $intitule, 'description' => $description]];
    }

    /**
     * A description republished by the source, under the same label, is taken over.
     *
     * Lot 8 quater, B1. The import compared the label alone: the old description stayed on the
     * site for ever while the import said ok and the publication date moved.
     */
    public function test_a_description_the_source_changed_is_taken_over(): void {
        $importer = new importer();
        $first = $importer->import($this->meta(), $this->described('Accompagner la personne', 'Ancienne description'));

        $result = $importer->import(
            $this->meta(['sourceupdated' => strtotime('2026-07-15')]),
            $this->described('Accompagner la personne', 'Description republiée')
        );

        $this->assertSame(1, $result->updated, 'A republished description was ignored.');
        $this->assertSame(0, $result->diverged, 'A published change was reported as a local one.');

        $competencies = (new competency_gateway())->list_framework_competencies($first->frameworkid);
        $this->assertSame('Description republiée', reset($competencies)->description);
    }

    /**
     * The same file twice reports no update, line endings, trailing spaces and ampersand included.
     *
     * Comparing descriptions must not turn every import into a pass that "updates" every
     * competency: the report would stop meaning anything. The ampersand is there because the
     * core's getter would hand it back as `&amp;`.
     */
    public function test_the_same_descriptions_twice_report_no_update(): void {
        $rows = $this->described('Accompagner la personne', "Hygiène & confort.\r\nSeconde ligne. ");
        $importer = new importer();

        $importer->import($this->meta(), $rows);
        $second = $importer->import($this->meta(), $rows);

        $this->assertSame(0, $second->updated, 'An unchanged description was reported as updated.');
        $this->assertSame(0, $second->diverged);
    }

    /**
     * A description edited on this site, with the record standing still, is a divergence, and
     * the published text is put back - the same rule as for the label.
     */
    public function test_a_description_changed_locally_is_recorded_as_a_divergence(): void {
        global $DB;

        $rows = $this->described('Accompagner la personne', 'Description publiée');
        $importer = new importer();
        $first = $importer->import($this->meta(), $rows);

        $competencies = (new competency_gateway())->list_framework_competencies($first->frameworkid);
        $competency = reset($competencies);
        (new competency_gateway())->update_competency((int) $competency->id, 'Accompagner la personne', 'Réécrite ici');

        $result = $importer->import($this->meta(), $rows);

        $this->assertSame(1, $result->diverged);
        $this->assertSame(
            importer::DIVERGED,
            $DB->get_field('tool_vae_obsolete', 'kind', ['competencyid' => $competency->id])
        );

        $again = (new competency_gateway())->list_framework_competencies($first->frameworkid);
        $this->assertSame('Description publiée', reset($again)->description, 'The published text was not restored.');
    }

    /**
     * A competency that has left the record is RECORDED as gone, never deleted.
     *
     * Decision 3: an orphaned piece of evidence remains a piece of evidence. Deleting the
     * competency would take the claims made against it with it.
     */
    public function test_a_competency_that_left_the_record_is_kept(): void {
        global $DB;

        $importer = new importer();
        $first = $importer->import($this->meta(), $this->rows([
            'BC01' => 'Toujours là',
            'BC02' => 'Bientôt partie',
        ]));

        $result = $importer->import(
            $this->meta(['sourceupdated' => strtotime('2026-07-15')]),
            $this->rows(['BC01' => 'Toujours là'])
        );

        $this->assertSame(1, $result->gone);
        $this->assertCount(
            2,
            (new competency_gateway())->list_framework_competencies($first->frameworkid),
            'A competency was deleted; the claims against it would have gone too.'
        );
        $competencies = (new competency_gateway())->list_framework_competencies($first->frameworkid);
        $left = null;
        foreach ($competencies as $competency) {
            if ($competency->idnumber === 'RS00000-BC02') {
                $left = $competency;
            }
        }
        $this->assertNotNull($left);

        $marks = $DB->get_records('tool_vae_obsolete');
        $this->assertCount(1, $marks);
        $mark = reset($marks);
        $this->assertEquals($left->id, $mark->competencyid, 'The competency that stayed was marked instead.');
        $this->assertSame(importer::GONE, $mark->kind);

        // And a third pass does not record it twice.
        $importer->import(
            $this->meta(['sourceupdated' => strtotime('2026-07-15')]),
            $this->rows(['BC01' => 'Toujours là'])
        );
        $this->assertEquals(1, $DB->count_records('tool_vae_obsolete', ['kind' => importer::GONE]));
    }

    /**
     * A rank that is missing, malformed or repeated stops the import before any write.
     */
    public function test_a_file_with_a_bad_rank_is_refused_whole(): void {
        global $DB;

        // Chaque cas porte le fragment de message qui lui est propre. Sans cela, fusionner les
        // quatre chaines en un seul « fichier invalide » laisserait ce test au vert, et
        // l'administrateur devant un fichier de quatre-vingt-dix lignes sans savoir laquelle.
        $cases = [
            'absent' => [
                [(object) ['rang' => '', 'intitule' => 'Sans rang']],
                'errorimportnorank',
                1,
            ],
            'trop long' => [
                [(object) ['rang' => 'BC01C0299', 'intitule' => 'Rang de neuf caractères']],
                'errorimportbadrank',
                (object) ['line' => 1, 'rank' => 'BC01C0299'],
            ],
            'caractères interdits' => [
                [(object) ['rang' => 'BC 01', 'intitule' => 'Rang avec espace']],
                'errorimportbadrank',
                (object) ['line' => 1, 'rank' => 'BC 01'],
            ],
            'répété' => [
                [
                    (object) ['rang' => 'BC01', 'intitule' => 'Première'],
                    (object) ['rang' => 'BC01', 'intitule' => 'Seconde'],
                ],
                'errorimportduplicaterank',
                (object) ['line' => 2, 'rank' => 'BC01'],
            ],
            'sans intitulé' => [
                [(object) ['rang' => 'BC01', 'intitule' => '']],
                'errorimportnolabel',
                1,
            ],
        ];

        foreach ($cases as $cas => [$rows, $attendu, $args]) {
            try {
                (new importer())->import($this->meta(), $rows);
                $this->fail("Un rang $cas a été accepté.");
            } catch (\moodle_exception $e) {
                $this->assertSame('errorimportrows', $e->errorcode, "Cas : $cas");

                // Le message COMPLET du cas, pris dans le fichier de langue plutot que recopie
                // ici : fusionner les quatre chaines en une seule generique, en renommer une ou
                // en vider une fait rougir ce test, ce que « errorcode vaut errorimportrows »
                // ne faisait pas.
                $this->assertStringContainsString(
                    get_string($attendu, 'tool_vae', $args),
                    $e->getMessage(),
                    "Cas : $cas"
                );
            }
        }

        $this->assertEquals(0, $DB->count_records('competency'), 'Une écriture a eu lieu malgré le refus.');
    }

    /**
     * Markup in a rank cell reaches the message escaped, never as markup.
     *
     * import.php shows the message in a notification that renders HTML: the cell comes from an
     * uploaded file, and left as it was it would run in the administrator's session.
     */
    public function test_markup_in_a_rank_cell_is_escaped_in_the_message(): void {
        $rows = importer::rows_from_csv("rang,intitule\n<img src=x onerror=alert(1)>,Piégé\n");

        try {
            (new importer())->import($this->meta(), $rows);
            $this->fail('Un rang porteur de balises a été accepté.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportrows', $e->errorcode);
            $this->assertStringNotContainsString('<IMG', $e->getMessage(), 'La balise est passée telle quelle.');
            // Le rang est passé en majuscules avant d'être contrôlé, d'où la casse attendue.
            $this->assertStringContainsString('&lt;IMG SRC=X ONERROR=ALERT(1)&gt;', $e->getMessage());
        }
    }

    /**
     * A file REFUSED BY VALIDATION writes nothing at all, date included.
     *
     * The distinction the docblock of the class draws, and which this test exists to hold: a
     * pass that never touched the record leaves the previous date standing, because that date
     * is still true - the site does hold the version it says it holds, and the administrator
     * has just been told on screen why the new file was refused. Only a pass that BEGINS to
     * write must leave a trace saying it failed, which is the next test.
     */
    public function test_a_file_refused_before_any_write_leaves_the_record_untouched(): void {
        global $DB;

        $importer = new importer();
        $importer->import($this->meta(), $this->rows(['BC01' => 'Intitulé publié']));
        $DB->set_field('tool_vae_source', 'lastimport', 1, ['fichecode' => 'RS00000']);

        try {
            $importer->import($this->meta(['sourceupdated' => strtotime('2026-07-15')]), [
                (object) ['rang' => '', 'intitule' => 'Sans rang'],
            ]);
            $this->fail('A malformed file was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportrows', $e->errorcode);
        }

        $source = $DB->get_record('tool_vae_source', ['fichecode' => 'RS00000'], '*', MUST_EXIST);
        $this->assertEquals(1, $source->lastimport, 'The rows were refused before the attempt was even opened.');
        $this->assertEquals('ok', $source->lastimportstatus);
    }

    /**
     * An import that dies in the middle leaves NOTHING half written.
     *
     * The assertion that matters is the count of competencies, not the status of the row. The
     * previous version of this test looked only at the status, and would have stayed green on
     * a database holding two of the four competencies of a record - which is the state a
     * candidate could then have anchored a claim to.
     */
    public function test_an_import_that_dies_in_the_middle_writes_nothing(): void {
        global $DB;

        // PHPUnit DE MOODLE ENVELOPPE CHAQUE TEST DANS UNE TRANSACTION, qu'il annule pour
        // remettre la base a plat. Une transaction du code teste y devient imbriquee, et son
        // annulation ne prouve plus rien - c'est ainsi que la premiere version de ce test a
        // mesure « deux competences survivantes » sur un code qui, en production, en laisse
        // zero. preventResetByRollback rend la transaction du code teste reelle ; le menage
        // se fait alors par vidage des tables.
        $this->preventResetByRollback();

        $importer = new importer(new fixtures\failing_import_gateway(2));

        try {
            $importer->import($this->meta(), $this->rows([
                'BC01' => 'Première',
                'BC02' => 'Deuxième',
                'BC03' => 'Troisième',
                'BC04' => 'Quatrième',
            ]));
            $this->fail('The gateway failure did not surface.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error', $e->errorcode);
        }

        $this->assertEquals(
            0,
            $DB->count_records('competency'),
            'Un import mort en chemin a laissé des compétences derrière lui.'
        );
        $this->assertEquals(
            0,
            $DB->count_records('competency_framework'),
            'Le référentiel a survécu à l\'import qui l\'a créé.'
        );

        // Et la ligne de provenance, elle, doit survivre : c'est la seule trace de l'échec.
        $source = $DB->get_record('tool_vae_source', ['fichecode' => 'RS00000'], '*', MUST_EXIST);
        $this->assertEquals('error', $source->lastimportstatus);
    }

    /**
     * A failed first import announces no published version at all.
     *
     * Writing the publication date before the import succeeded had two costs, and this test
     * holds the smaller one: a provenance line stating a date the site does not hold. The
     * Licence Ouverte 2.0 forbids misleading anyone about the date of the data.
     */
    public function test_a_failed_first_import_claims_no_published_version(): void {
        global $DB;

        // PHPUnit DE MOODLE ENVELOPPE CHAQUE TEST DANS UNE TRANSACTION, qu'il annule pour
        // remettre la base a plat. Une transaction du code teste y devient imbriquee, et son
        // annulation ne prouve plus rien - c'est ainsi que la premiere version de ce test a
        // mesure « deux competences survivantes » sur un code qui, en production, en laisse
        // zero. preventResetByRollback rend la transaction du code teste reelle ; le menage
        // se fait alors par vidage des tables.
        $this->preventResetByRollback();

        try {
            (new importer(new fixtures\failing_import_gateway()))
                ->import($this->meta(), $this->rows(['BC01' => 'Première']));
            $this->fail('The gateway failure did not surface.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error', $e->errorcode);
        }

        $source = $DB->get_record('tool_vae_source', ['fichecode' => 'RS00000'], '*', MUST_EXIST);
        $this->assertEquals(0, $source->sourceupdated, 'Une version jamais importée est annoncée comme publiée.');
    }

    /**
     * And the larger cost: after a failure, a real republication is still seen as one.
     *
     * The sequence is ordinary - a file is refused, the administrator fixes it and re-runs -
     * and the bug turned every label France compétences had really changed into a divergence
     * blamed on the site.
     */
    public function test_a_retry_after_a_failure_still_sees_the_source_move(): void {
        // PHPUnit DE MOODLE ENVELOPPE CHAQUE TEST DANS UNE TRANSACTION, qu'il annule pour
        // remettre la base a plat. Une transaction du code teste y devient imbriquee, et son
        // annulation ne prouve plus rien - c'est ainsi que la premiere version de ce test a
        // mesure « deux competences survivantes » sur un code qui, en production, en laisse
        // zero. preventResetByRollback rend la transaction du code teste reelle ; le menage
        // se fait alors par vidage des tables.
        $this->preventResetByRollback();

        $importer = new importer();
        $importer->import($this->meta(), $this->rows(['BC01' => 'Intitulé de juin']));

        // Une nouvelle publication, dont l'import meurt en chemin.
        $republication = $this->meta(['sourceupdated' => strtotime('2026-07-15')]);
        try {
            (new importer(new fixtures\failing_import_gateway()))
                ->import($republication, $this->rows(['BC01' => 'Intitulé de juillet', 'BC02' => 'Nouvelle']));
            $this->fail('The gateway failure did not surface.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error', $e->errorcode);
        }

        // L'administrateur relance le même fichier, qui passe cette fois.
        $result = $importer->import($republication, $this->rows([
            'BC01' => 'Intitulé de juillet',
            'BC02' => 'Nouvelle',
        ]));

        $this->assertSame(0, $result->diverged, 'Une republication a été imputée au site.');
        $this->assertSame(1, $result->updated);
        $this->assertSame(1, $result->created);
    }

    /**
     * Lot 7 ter: a departure is announced by the pass that sees it, and by no later one.
     *
     * The candidates who declared against the competency are told through this event. A record
     * re-imported every few months finds the same competency absent every time; an event on
     * every pass would tell them every time.
     */
    public function test_a_departure_raises_one_event_on_the_pass_that_sees_it(): void {
        global $DB;

        $importer = new importer();
        $importer->import($this->meta(), $this->rows(['BC01' => 'Première', 'BC02' => 'Deuxième']));
        $bc01 = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-BC01'], MUST_EXIST);
        $sourceid = (int) $DB->get_field('tool_vae_source', 'id', ['fichecode' => 'RS00000']);

        $sink = $this->redirectEvents();
        $departures = function () use ($sink): array {
            return array_values(array_filter($sink->get_events(), function ($event) {
                return $event instanceof event\competency_left_record;
            }));
        };

        $importer->import($this->meta(), $this->rows(['BC02' => 'Deuxième']));

        $events = $departures();
        $this->assertCount(1, $events);
        $this->assertSame($bc01, $events[0]->other['competencyid']);
        $this->assertSame($sourceid, $events[0]->other['sourceid']);

        $sink->clear();
        $importer->import($this->meta(), $this->rows(['BC02' => 'Deuxième']));

        $this->assertSame([], $departures(), 'A departure already recorded was announced again.');

        // Back in the record - the case of a truncated file, then the right one: the mark goes.
        $importer->import($this->meta(), $this->rows(['BC01' => 'Première', 'BC02' => 'Deuxième']));
        $this->assertFalse(
            $DB->record_exists('tool_vae_obsolete', ['competencyid' => $bc01, 'kind' => importer::GONE]),
            'A competency back in the record is still counted as gone.'
        );

        // And a later departure is a new fact, announced as one.
        $sink->clear();
        $importer->import($this->meta(), $this->rows(['BC02' => 'Deuxième']));
        $this->assertCount(1, $departures(), 'A second departure, after a return, went unannounced.');
    }

    /**
     * Renamed locally, then gone from the record: one state, not two rows.
     *
     * The unique index holds one row per competency and record. Testing existence on the kind
     * as well made this sequence insert a second row and die on a duplicate key, halfway
     * through an import - a fault no single-state test could see.
     */
    public function test_a_competency_renamed_then_gone_is_recorded_once(): void {
        global $DB;

        $importer = new importer();
        $first = $importer->import($this->meta(), $this->rows([
            'BC01' => 'Toujours là',
            'BC02' => 'Intitulé publié',
        ]));

        // Quelqu'un renomme BC02 dans tool_lp, puis on réimporte le même fichier.
        $competencies = (new competency_gateway())->list_framework_competencies($first->frameworkid);
        $cible = null;
        foreach ($competencies as $competency) {
            if ($competency->idnumber === 'RS00000-BC02') {
                $cible = $competency;
            }
        }
        (new competency_gateway())->update_competency((int) $cible->id, 'Intitulé bricolé');
        $importer->import($this->meta(), $this->rows([
            'BC01' => 'Toujours là',
            'BC02' => 'Intitulé publié',
        ]));

        $this->assertEquals(1, $DB->count_records('tool_vae_obsolete', ['kind' => importer::DIVERGED]));

        // Puis France compétences republie la fiche sans BC02.
        $sink = $this->redirectEvents();
        $result = $importer->import(
            $this->meta(['sourceupdated' => strtotime('2026-07-15')]),
            $this->rows(['BC01' => 'Toujours là'])
        );

        // Renommée puis partie : le départ est un changement d'état, et il est annoncé (lot 7 ter).
        $departures = array_filter($sink->get_events(), function ($event) {
            return $event instanceof event\competency_left_record;
        });
        $this->assertCount(1, $departures, 'Le passage de « renommée » à « absente » n\'a pas été annoncé.');

        $this->assertSame(1, $result->gone);
        $marks = $DB->get_records('tool_vae_obsolete', ['competencyid' => $cible->id]);
        $this->assertCount(1, $marks, 'Deux états pour une même compétence.');
        $this->assertSame(importer::GONE, reset($marks)->kind, 'L\'état le plus récent n\'a pas remplacé l\'ancien.');
    }

    /**
     * A label longer than the core column is refused BEFORE anything is written.
     *
     * The blocks of competencies of a real RNCP record go past a hundred characters routinely.
     * Left to the database, the failure arrived on row 12 of 40, named a column the
     * administrator has never heard of, and left eleven competencies behind - and on a lax
     * MariaDB it would have truncated a published label instead, silently.
     */
    public function test_a_label_longer_than_the_core_column_is_refused(): void {
        global $DB;

        $long = str_repeat('a', importer::LABEL_MAXLENGTH + 1);

        try {
            (new importer())->import($this->meta(), $this->rows([
                'BC01' => 'Correcte',
                'BC02' => $long,
            ]));
            $this->fail('Un intitulé trop long a été accepté.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportrows', $e->errorcode);
            $this->assertStringContainsString((string) (importer::LABEL_MAXLENGTH + 1), $e->getMessage());
        }

        $this->assertEquals(0, $DB->count_records('competency'), 'Une écriture a eu lieu malgré le refus.');
    }

    /**
     * The name of the record is checked the same way, for the same column.
     */
    public function test_a_record_name_longer_than_the_core_column_is_refused(): void {
        global $DB;

        try {
            (new importer())->import(
                $this->meta(['name' => str_repeat('a', importer::LABEL_MAXLENGTH + 1)]),
                $this->rows(['BC01' => 'Correcte'])
            );
            $this->fail('Un nom de fiche trop long a été accepté.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportnametoolong', $e->errorcode);
        }

        $this->assertEquals(0, $DB->count_records('tool_vae_source'));
    }

    /**
     * The record code is one record whatever its case.
     *
     * MySQL compares case-insensitively and PostgreSQL does not: the same gesture gave a clean
     * re-import on one site and a duplicated framework on the next.
     */
    public function test_the_record_code_is_folded_to_upper_case(): void {
        global $DB;

        $importer = new importer();
        $importer->import($this->meta(), $this->rows(['BC01' => 'Intitulé publié']));
        $importer->import($this->meta(['fichecode' => 'rs00000']), $this->rows(['BC01' => 'Intitulé publié']));

        $this->assertEquals(1, $DB->count_records('tool_vae_source'));
        $this->assertEquals(1, $DB->count_records('competency_framework'));
        $this->assertEquals(
            'RS00000',
            $DB->get_field('tool_vae_source', 'fichecode', [], MUST_EXIST)
        );
    }

    /**
     * A no-break space is not a difference an administrator made.
     *
     * trim() does not see U+00A0, which a French word processor inserts freely and a paste into
     * a spreadsheet carries along. A label keeping one differed byte for byte from the same
     * label re-exported cleanly, and the next import blamed the site for a divergence.
     */
    public function test_invisible_spaces_are_not_read_as_a_divergence(): void {
        $importer = new importer();
        $importer->import($this->meta(), $this->rows(['BC01' => 'Intitulé publié']));

        $result = $importer->import($this->meta(), [
            (object) [
                'rang' => 'BC01',
                'intitule' => "\u{00A0}Intitulé publié\u{00A0}",
                'description' => '',
            ],
        ]);

        $this->assertSame(0, $result->diverged, 'Une espace insécable a été prise pour une modification.');
        $this->assertSame(0, $result->updated);
    }

    /**
     * A file that is not UTF-8 keeps its accents, when it is declared for what it is.
     *
     * Declaring UTF-8 for a file that is not makes the core DELETE the bytes it cannot read:
     * a green import, and a published label reaching the dossier with its accents gone. The
     * encoding is now asked on the form; this test holds the answer being used.
     */
    public function test_a_windows_1252_file_is_read_when_it_says_so(): void {
        $ligne = "rang,intitule\nBC01," . iconv('UTF-8', 'Windows-1252', 'Accompagner la personne âgée') . "\n";

        $rows = importer::rows_from_csv($ligne, 'Windows-1252');
        $this->assertCount(1, $rows);
        $this->assertSame('Accompagner la personne âgée', trim($rows[0]->intitule));
    }

    /**
     * Every encoding the form offers is one the core actually recognises.
     *
     * FOUND BY DRIVING THE REAL FORM, NOT BY A TEST. The first version of the list spelt it
     * `Windows-1252` where core_text says `WINDOWS-1252`. The select rejected the value in
     * silence, the form fell back to UTF-8, and the import stripped the accents off every
     * label - a green import and a mutilated record. This test is the guard that was missing.
     */
    public function test_every_offered_encoding_is_one_the_core_knows(): void {
        $known = \core_text::get_encodings();

        foreach (array_keys(\tool_vae\form\import_form::ENCODINGS) as $encoding) {
            $this->assertArrayHasKey(
                $encoding,
                $known,
                "L'encodage « $encoding » est proposé sur le formulaire et inconnu du cœur : "
                    . 'le choix sera rejeté en silence et les accents perdus.'
            );
        }

        // Et chacun porte une chaîne de langue dans les deux langues, sinon le menu affiche
        // un identifiant entre crochets.
        foreach (\tool_vae\form\import_form::ENCODINGS as $identifier) {
            $this->assertTrue(
                get_string_manager()->string_exists($identifier, 'tool_vae'),
                "La chaîne « $identifier » manque."
            );
        }
    }

    /**
     * A row with the wrong number of columns is named as such, not as a missing header.
     *
     * The reader refuses the whole file when ONE row is ragged, and the old code turned that
     * into "no readable header row" - sending an administrator to inspect a first line that
     * was perfectly correct while the fault sat on line 37.
     */
    public function test_a_ragged_row_is_reported_as_a_ragged_row(): void {
        $contenu = "rang,intitule\nBC01,Correcte\nBC02,Trop,de colonnes\n";

        try {
            importer::rows_from_csv($contenu);
            $this->fail('Un fichier mal formé a été accepté.');
        } catch (\moodle_exception $e) {
            $this->assertSame(
                'errorimportmalformed',
                $e->errorcode,
                'Une ligne bancale est encore présentée comme un en-tête illisible.'
            );
        }
    }

    /**
     * An attempt that dies after it began is left marked as failed.
     */
    public function test_an_attempt_that_dies_halfway_is_marked_failed(): void {
        global $DB;

        $importer = new importer(new fixtures\failing_import_gateway());

        try {
            $importer->import($this->meta(), $this->rows(['BC01' => 'Intitulé publié']));
            $this->fail('The gateway failure did not surface.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error', $e->errorcode);
        }

        $source = $DB->get_record('tool_vae_source', ['fichecode' => 'RS00000'], '*', MUST_EXIST);
        $this->assertEquals('error', $source->lastimportstatus, 'A failed import passes for a successful one.');
        $this->assertGreaterThan(1, $source->lastimport, 'The attempt left no date.');
    }

    /**
     * The provenance is required, in full.
     */
    public function test_the_provenance_is_required(): void {
        foreach (['fichecode', 'licence', 'attribution', 'name'] as $field) {
            try {
                (new importer())->import($this->meta([$field => '']), $this->rows(['BC01' => 'Intitulé']));
                $this->fail("A record was imported with no $field.");
            } catch (\moodle_exception $e) {
                $this->assertSame('errorimportmetamissing', $e->errorcode);
            }
        }

        try {
            (new importer())->import($this->meta(['sourceupdated' => 0]), $this->rows(['BC01' => 'Intitulé']));
            $this->fail('A record was imported with no publication date.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportnodate', $e->errorcode);
        }
    }

    // ----------------------------------------------------------------------
    // Reading the file.

    /**
     * The deposited file is read whatever separates its columns.
     *
     * THE COMMA IS NOT THE FRENCH DEFAULT. A file produced by a French Excel, or downloaded from
     * a French open data portal, is very often semicolon-separated. A reader hardcoded on the
     * comma sees one column per line and reports a missing header - which reads as "your file is
     * malformed" to someone whose file is perfectly fine.
     */
    public function test_the_file_is_read_whatever_separates_its_columns(): void {
        $attendu = [
            ['BC01', 'Accompagner la personne'],
            ['BC02', 'Repérer les évolutions'],
        ];

        $fichiers = [
            'virgule' => "rang,intitule\nBC01,Accompagner la personne\nBC02,Repérer les évolutions\n",
            'point-virgule' => "rang;intitule\nBC01;Accompagner la personne\nBC02;Repérer les évolutions\n",
            'tabulation' => "rang\tintitule\nBC01\tAccompagner la personne\nBC02\tRepérer les évolutions\n",
        ];

        foreach ($fichiers as $quoi => $contenu) {
            $rows = importer::rows_from_csv($contenu);

            $this->assertCount(2, $rows, "Séparateur : $quoi");
            foreach ($attendu as $i => [$rang, $intitule]) {
                $this->assertSame($rang, $rows[$i]->rang, "Séparateur : $quoi");
                $this->assertSame($intitule, $rows[$i]->intitule, "Séparateur : $quoi");
            }
        }
    }

    /**
     * The defects of the 2015 files (T-32), reproduced with invented rows: no real record enters the
     * tree (tests/fixtures/LISEZMOI-fixtures.md). Two competencies bearing the same title are two
     * competencies: the rank tells them apart, not the label.
     */
    public function test_two_competencies_with_the_same_title_are_both_kept(): void {
        $result = (new importer())->import($this->meta(), $this->rows([
            'C15' => 'Piloter le projet d etablissement',
            'C16' => 'Piloter le projet d etablissement',
            'C17' => 'Organiser le travail des equipes',
        ]));

        $this->assertSame(3, $result->created);
        $idnumbers = array_column((new competency_gateway())->list_framework_competencies($result->frameworkid), 'idnumber');
        sort($idnumbers);
        $this->assertSame(['RS00000-C15', 'RS00000-C16', 'RS00000-C17'], $idnumbers);
    }

    /**
     * An apostrophe a legacy export escaped with a backslash is stored as it was read, and a second
     * pass of the same file reports no change: the importer does not guess what the file meant (T-32).
     */
    public function test_an_escaped_apostrophe_is_stored_as_read_and_is_stable(): void {
        $rows = [(object) [
            'rang' => 'C01',
            'intitule' => 'Respecter l\\\'identite de la personne',
            'description' => 'Un temps d\\\'echange',
        ]];
        $importer = new importer();

        $first = $importer->import($this->meta(), $rows);
        $second = $importer->import($this->meta(), $rows);

        $this->assertSame(1, $first->created);
        $this->assertSame(0, $second->created);
        $this->assertSame(0, $second->updated);
        $competencies = (new competency_gateway())->list_framework_competencies($first->frameworkid);
        $this->assertSame('Respecter l\\\'identite de la personne', reset($competencies)->shortname);
    }

    /**
     * A code of the old files, with its dots and its domain prefix, is refused by name and nothing is
     * written: the administrator recodes the rank, the importer does not (T-32).
     */
    public function test_a_legacy_dotted_code_is_refused_by_name_and_writes_nothing(): void {
        global $DB;

        try {
            (new importer())->import($this->meta(), [
                (object) ['rang' => 'C01', 'intitule' => 'Une competence bien codee'],
                (object) ['rang' => 'DGDC1.5', 'intitule' => 'Une competence au code ancien'],
            ]);
            $this->fail('A dotted legacy code was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportrows', $e->errorcode);
            $this->assertStringContainsString('DGDC1.5', $e->getMessage());
        }

        $this->assertEquals(0, $DB->count_records('competency'));
    }

    /**
     * The fixture on disk reads, and it is the one an administrator would deposit.
     */
    public function test_the_example_file_reads(): void {
        $rows = importer::rows_from_csv(file_get_contents(__DIR__ . '/fixtures/fiche-exemple.csv'));

        $this->assertCount(3, $rows);
        $this->assertSame('BC01', $rows[0]->rang);
        $this->assertStringContainsString('Accompagner', $rows[0]->intitule);
        $this->assertNotSame('', $rows[0]->description);
        $this->assertSame('', $rows[1]->description, 'An empty third column became something else.');
    }

    /**
     * A file without the two required columns is refused by name.
     */
    public function test_a_file_without_the_required_columns_is_refused(): void {
        foreach (["code,libelle\nBC01,Quelque chose\n", "rang\nBC01\n"] as $contenu) {
            try {
                importer::rows_from_csv($contenu);
                $this->fail('A file with no usable header was accepted.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errorimportnoheader', $e->errorcode);
            }
        }

        // Un fichier vide est vide, pas illisible : lui servir « pas d'en-tete lisible »
        // envoie l'administrateur inspecter une premiere ligne qui n'existe pas.
        try {
            importer::rows_from_csv('');
            $this->fail('An empty file was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportempty', $e->errorcode);
        }
    }

    // ----------------------------------------------------------------------
    // The framework a record is written into, and what a first import leaves behind.

    /**
     * A first import that died leaves a provenance row at frameworkid 0, and no screen may die on it.
     *
     * The row stays on purpose (test_an_import_that_dies_in_the_middle_writes_nothing): it is the trace of
     * the failure. What the screens do with it is the subject here. Every one of them lists the competencies
     * of every row of tool_vae_source, and the core's listing raised a coding_exception for id 0 - the
     * declaration screen, the course screen and the scope screen were out of service for every user until
     * somebody imported the record again.
     */
    public function test_a_failed_first_import_leaves_the_declaration_screens_readable(): void {
        global $DB;

        // The rollback of the code under test has to be a real one, as in the test above.
        $this->preventResetByRollback();

        try {
            (new importer(new fixtures\failing_import_gateway(2)))->import($this->meta(), $this->rows([
                'BC01' => 'Première',
                'BC02' => 'Deuxième',
                'BC03' => 'Troisième',
            ]));
            $this->fail('The gateway failure did not surface.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error', $e->errorcode);
        }

        $source = $DB->get_record('tool_vae_source', ['fichecode' => 'RS00000'], '*', MUST_EXIST);
        $this->assertEquals(0, $source->frameworkid, 'The premise is a row with no framework.');

        $this->assertSame([], (new competency_gateway())->list_framework_competencies((int) $source->frameworkid));

        // The screen of a manager, which lists every record: this one is left out, and nothing is raised.
        $course = $this->getDataGenerator()->create_course();
        $this->assertArrayNotHasKey('RS00000', (new course_linker())->records((int) $course->id));
    }

    /**
     * An import that cannot create its framework is refused before anything is written.
     *
     * The provenance row is written first, outside the transaction, so that a pass that dies leaves a trace.
     * With the default competence scale renamed or deleted, the first import died just after it: a row at
     * frameworkid 0 for a record nobody could declare on, and the administrator with nothing to remove it.
     * The scale is asked for before the attempt is opened, as a malformed file is refused.
     */
    public function test_a_missing_competence_scale_is_refused_before_anything_is_written(): void {
        global $DB;

        $scale = competency_gateway::default_competence_scale();
        $DB->set_field('scale', 'name', 'Autre barème', ['courseid' => 0, 'name' => $scale->name]);

        try {
            (new importer())->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));
            $this->fail('A record was imported with no competence scale to give its framework.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornocompetencescale', $e->errorcode);
        }

        $this->assertFalse(
            $DB->record_exists('tool_vae_source', ['fichecode' => 'RS00000']),
            'An import that could not happen left a provenance row.'
        );
        $this->assertEquals(0, $DB->count_records('competency_framework'));
    }

    /**
     * A re-import follows the framework the source recorded, not the identifier that framework carries today.
     *
     * The identifier is a free text field of the core's form. An administrator who changed it made
     * find_framework() answer null: the import created a SECOND framework, the row moved to it, and the
     * declarations already made stayed on a first one that no source claimed any more - and that cannot be
     * deleted, since somebody has declared on it. Green, silent and permanent.
     */
    public function test_a_re_import_follows_the_framework_the_source_records(): void {
        global $DB;

        $importer = new importer();
        $first = $importer->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));
        $claim = $this->declare_against($first->frameworkid, 'RS00000-BC01');

        // The administrator edits the framework in tool_lp and changes its identifier.
        $DB->set_field('competency_framework', 'idnumber', 'RS00000-2025', ['id' => $first->frameworkid]);

        try {
            $importer->import(
                $this->meta(['sourceupdated' => strtotime('2026-07-15')]),
                $this->rows(['BC01' => 'Accompagner la personne', 'BC02' => 'Repérer les évolutions'])
            );
            $this->fail('A second framework was created for the same record.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportframeworkrenamed', $e->errorcode);
            // The identifier the framework carries now is named, so that the administrator can put it back.
            $this->assertStringContainsString('RS00000-2025', $e->getMessage());
        }

        $this->assertEquals(1, $DB->count_records('competency_framework'), 'A second framework exists.');
        $source = $DB->get_record('tool_vae_source', ['fichecode' => 'RS00000'], '*', MUST_EXIST);
        $this->assertEquals($first->frameworkid, $source->frameworkid, 'The source was moved off its framework.');
        $this->assertEquals('ok', $source->lastimportstatus, 'A refusal before any write marked the record as failed.');
        $competencyid = $DB->get_field('tool_vae_evidence', 'competencyid', ['id' => $claim->id]);
        $this->assertEquals(
            $first->frameworkid,
            $DB->get_field('competency', 'competencyframeworkid', ['id' => $competencyid]),
            'The declaration is no longer on the framework of its source.'
        );
    }

    /**
     * An identifier that changed only in case is still the record's framework.
     *
     * MySQL finds a framework by an identifier written in another case and PostgreSQL does not, so the same
     * gesture gave a clean re-import on one site and a second framework on the other. The source names the
     * framework; the identifier is asked only to be sure it is still the record's.
     */
    public function test_an_identifier_changed_only_in_case_is_still_the_records_framework(): void {
        global $DB;

        $importer = new importer();
        $first = $importer->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));
        $DB->set_field('competency_framework', 'idnumber', 'rs00000', ['id' => $first->frameworkid]);

        $second = $importer->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));

        $this->assertSame($first->frameworkid, $second->frameworkid);
        $this->assertSame(0, $second->created);
        $this->assertEquals(1, $DB->count_records('competency_framework'));
    }

    /**
     * A framework deleted in tool_lp is rebuilt by the next import, and the source follows the new one.
     *
     * The behaviour that stays: the core lets an administrator delete a framework nobody declared on, and the
     * record is then imported again from its file. Only a framework that still exists is followed.
     */
    public function test_a_re_import_after_the_framework_was_deleted_builds_a_new_one(): void {
        global $DB;

        $importer = new importer();
        $first = $importer->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));
        $DB->delete_records('competency', ['competencyframeworkid' => $first->frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $first->frameworkid]);

        $second = $importer->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));

        $this->assertSame(1, $second->created);
        $this->assertNotEquals($first->frameworkid, $second->frameworkid);
        $this->assertEquals(
            $second->frameworkid,
            $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RS00000'])
        );
        $this->assertFalse($second->adopted, 'A framework built by this import was reported as taken over.');
    }

    /**
     * A first import does not take over a framework this tool did not write.
     *
     * Any framework whose identifier was the code was adopted, whoever had made it: tool_lpimportcsv, or a
     * hand-built framework of forty competencies "C1.1", "C1.2"... used in an organisation's training plans.
     * The import wrote `<code>-<rank>` blocs into it, marked the forty as gone from the record, announced
     * a departure for each, and tied the record to that framework for good.
     */
    public function test_a_first_import_refuses_a_framework_the_plugin_did_not_write(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $framework = $generator->create_framework(['idnumber' => 'RS00000', 'shortname' => 'Cadre maison']);
        $foreign = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => 'C1.1',
            'shortname' => 'Compétence maison',
        ]);

        try {
            (new importer())->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));
            $this->fail('A framework this tool did not write was taken over.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportframeworkforeign', $e->errorcode);
            $this->assertStringContainsString('RS00000', $e->getMessage());
        }

        $this->assertEquals(0, $DB->count_records('tool_vae_source'), 'A refusal left a provenance row.');
        $this->assertEquals(0, $DB->count_records('tool_vae_obsolete'), 'The competencies of the site were marked as gone.');
        $this->assertEquals(1, $DB->count_records('competency', ['competencyframeworkid' => $framework->get('id')]));
        $this->assertSame('Compétence maison', $DB->get_field('competency', 'shortname', ['id' => $foreign->get('id')]));
    }

    /**
     * A record that has a provenance row with no framework is checked the same way.
     *
     * The row of a first import that died (frameworkid 0) is no more a proof that a framework with the code is
     * the record's than no row at all: the framework may have been made by hand since.
     */
    public function test_a_row_with_no_framework_does_not_vouch_for_one_that_is_found(): void {
        global $DB;

        $now = time();
        $DB->insert_record('tool_vae_source', (object) [
            'frameworkid' => 0, 'fichecode' => 'RS00000', 'fichetype' => 'rs', 'sourceurl' => '',
            'sourceupdated' => 0, 'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
            'lastimport' => $now, 'lastimportstatus' => 'error', 'timecreated' => $now, 'timemodified' => $now,
            'usermodified' => (int) get_admin()->id,
        ]);
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $framework = $generator->create_framework(['idnumber' => 'RS00000', 'shortname' => 'Cadre maison']);
        $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => 'C1.1',
            'shortname' => 'Compétence maison',
        ]);

        try {
            (new importer())->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));
            $this->fail('A framework this tool did not write was taken over.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportframeworkforeign', $e->errorcode);
        }

        $this->assertEquals(0, $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RS00000']));
        $this->assertEquals(1, $DB->count_records('competency', ['competencyframeworkid' => $framework->get('id')]));
    }

    /**
     * A first import takes over a framework this tool wrote earlier, and says so.
     *
     * What an uninstall leaves behind: db/uninstall.php keeps the frameworks, and the dossiers are rebuilt after
     * a reinstall by import_dossier.php, which needs the framework and its competency ids again. Refusing every
     * framework found would have broken that documented path.
     */
    public function test_a_first_import_adopts_a_framework_the_plugin_wrote_earlier(): void {
        global $DB;

        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $framework = $generator->create_framework(['idnumber' => 'RS00000', 'shortname' => 'Certification inventée']);
        $earlier = $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => 'RS00000-BC01',
            'shortname' => 'Ancien libellé',
        ]);

        $result = (new importer())->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));

        $this->assertEquals($framework->get('id'), $result->frameworkid, 'A second framework was created.');
        $this->assertSame(0, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertTrue($result->adopted, 'The import did not say it took a framework over.');
        $this->assertEquals(
            $earlier->get('id'),
            $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-BC01']),
            'The competency changed id.'
        );

        // The row now says the framework is the record's: the next pass does not adopt anything.
        $again = (new importer())->import($this->meta(), $this->rows(['BC01' => 'Accompagner la personne']));
        $this->assertFalse($again->adopted);
    }

    /**
     * A mark on a competency the site no longer holds goes at the next import.
     *
     * tool_vae_obsolete points at competencies by an id the database does not enforce, and the core lets an
     * administrator delete a framework nobody has declared on. The import that rebuilt the framework found every
     * competency new and never met the old ids: two screens went on counting competencies "gone from the record"
     * that no longer exist, whatever the next files said.
     */
    public function test_marks_of_competencies_the_site_no_longer_holds_go_on_the_next_import(): void {
        global $DB;

        $importer = new importer();
        $first = $importer->import($this->meta(), $this->rows(['BC01' => 'Toujours là', 'BC02' => 'Bientôt partie']));
        $importer->import(
            $this->meta(['sourceupdated' => strtotime('2026-07-15')]),
            $this->rows(['BC01' => 'Toujours là'])
        );
        $this->assertEquals(1, $DB->count_records('tool_vae_obsolete', ['kind' => importer::GONE]));
        // And a divergence on the other one, the other kind of mark.
        $DB->insert_record('tool_vae_obsolete', (object) [
            'competencyid' => (int) $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-BC01'], MUST_EXIST),
            'sourceid' => (int) $DB->get_field('tool_vae_source', 'id', ['fichecode' => 'RS00000'], MUST_EXIST),
            'detectedat' => time(),
            'kind' => importer::DIVERGED,
        ]);

        // What an administrator does in tool_lp on a framework nobody declared on.
        $DB->delete_records('competency', ['competencyframeworkid' => $first->frameworkid]);
        $DB->delete_records('competency_framework', ['id' => $first->frameworkid]);

        $result = $importer->import(
            $this->meta(['sourceupdated' => strtotime('2026-07-15')]),
            $this->rows(['BC01' => 'Toujours là', 'BC02' => 'Bientôt partie'])
        );

        $this->assertSame(2, $result->created);
        $this->assertEquals(
            0,
            $DB->count_records('tool_vae_obsolete'),
            'Marks on competencies that no longer exist are still counted.'
        );
        $this->assertSame(0, supersession::overview()['RS00000']->gone);
    }

    /**
     * Declare an activity against an imported competency, as a candidate would.
     *
     * @param int $frameworkid
     * @param string $idnumber
     * @return \stdClass
     */
    private function declare_against(int $frameworkid, string $idnumber): \stdClass {
        global $CFG, $DB;

        require_once($CFG->libdir . '/formslib.php');

        $candidate = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $candidate->id, \context_user::instance($candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $competencyid = (int) $DB->get_field('competency', 'id', ['idnumber' => $idnumber], MUST_EXIST);

        $this->setUser($candidate);
        $claim = (new evidence_manager())->declare_activity((int) $candidate->id, (object) [
            'competencyid' => $competencyid,
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'settingdetail' => '',
            'situation' => 'Accompagnement au lever.',
            'situationformat' => FORMAT_PLAIN,
        ]);
        $this->setAdminUser();

        return $claim;
    }
}
