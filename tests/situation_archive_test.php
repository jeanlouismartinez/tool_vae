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

use tool_vae\local\backup;
use tool_vae\local\consent_manager;
use tool_vae\local\dossier_importer;
use tool_vae\local\draft_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\note_manager;
use tool_vae\local\piece_manager;
use tool_vae\local\positioning_rules;
use tool_vae\local\purge;
use tool_vae\local\schema_validator;
use tool_vae\local\situation_archive;
use tool_vae\local\situation_manager;
use tool_vae\local\spiece_manager;
use tool_vae\local\validation_catalog;

/**
 * The work situations, their pieces, the annotations and the consents in the archive of the dossier, and back (lot 1b).
 *
 * WHAT IS ASSERTED. That an archive holds situations.json only when something was written, listed in the manifest with its
 * schema, valid by it, saying in its first lines that nothing in it is attested or validated; that every situation is there
 * with its versions, its tags, its draft in progress and its pieces, every annotation and every line of consent; that the
 * import brings a situation back whole or leaves it, and the annotations and consents line by line, a restored line naming
 * no account of this site and opening no annotation (D-1b-L); that the bounds of D-1b-J hold; and that the situations never
 * enter validation.json nor the printed copy (D-1b-D).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(situation_archive::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(dossier_importer::class)]
final class situation_archive_test extends \advanced_testcase {
    /** @var string The address of the link piece. */
    private const ADDRESS = 'https://exemple.test/service';

    /** @var string The text of the first version of the first situation. */
    private const FIRST_TEXT = 'Premiere version du texte de la situation.';

    /** @var string The text of an annotation. */
    private const NOTE_TEXT = 'Une annotation sur la premiere situation.';

    /** @var \stdClass The vaeiste. */
    private $vaeiste;

    /** @var \stdClass Follows the vaeiste and holds annotate in their context. */
    private $manager;

    /** @var \stdClass Reads every dossier and exports; follows nobody. */
    private $direction;

    /** @var \tool_vae_generator */
    private $generator;

    /** @var \stdClass[] What write_dossier() wrote, by name: one, two, three (deleted), file, link, n1, n2, n3, w3, n4. */
    private $written = [];

    /**
     * RNCP100 (BC01 holding C01, C02; BC02 holding C03) and RNCP400 (four levels); the people; the preparation and the
     * pieces open; one declaration of the vaeiste on RNCP100.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        validation_catalog::reset();
        set_config('validationopen', 1, 'tool_vae');
        set_config('piecesopen', 1, 'tool_vae');
        set_config('piecenoantivirus', 1, 'tool_vae');

        $datagenerator = $this->getDataGenerator();
        $this->generator = $datagenerator->get_plugin_generator('tool_vae');
        $this->generator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        $this->generator->create_competency(['fichecode' => 'RNCP100', 'ficherang' => 'BC02', 'shortname' => 'Bloc 2']);
        foreach ([['C01', 'BC01'], ['C02', 'BC01'], ['C03', 'BC02']] as [$rank, $bloc]) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP100', 'ficherang' => $rank, 'shortname' => 'Competence ' . $rank, 'bloc' => $bloc,
            ]);
        }
        $this->generator->create_source(['fichecode' => 'RNCP400', 'ficherang' => 'D1', 'shortname' => 'Domaine 1']);
        foreach ([['C11', 'D1'], ['H111', 'C11'], ['H112', 'C11'], ['C12', 'D1'], ['D2', '']] as [$rank, $parent]) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP400', 'ficherang' => $rank, 'shortname' => 'Noeud ' . $rank, 'parent' => $parent,
            ]);
        }

        $system = \context_system::instance()->id;
        $this->vaeiste = $datagenerator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->manager = $datagenerator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->direction = $datagenerator->create_user(['firstname' => 'Dana', 'lastname' => 'Direction']);
        $vaeistecontext = \context_user::instance($this->vaeiste->id)->id;

        $own = $datagenerator->create_role();
        foreach (['declare', 'export', 'viewown', 'positionself', 'draftown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, $system, true);
        }
        set_role_contextlevels($own, [CONTEXT_USER]);
        role_assign($own, $this->vaeiste->id, $vaeistecontext);

        $annotator = $datagenerator->create_role();
        foreach (['viewassigned', 'annotate', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $annotator, $system, true);
        }
        set_role_contextlevels($annotator, [CONTEXT_USER]);
        role_assign($annotator, $this->manager->id, $vaeistecontext);

        $director = $datagenerator->create_role();
        foreach (['viewany', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $director, $system, true);
        }
        role_assign($director, $this->direction->id, $system);
        accesslib_clear_all_caches_for_unit_testing();

        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01',
            'situation' => 'Une situation vecue en etablissement.',
        ]);
        $this->setAdminUser();
    }

    /**
     * The bytes of a small PDF, distinct for each name.
     *
     * @param string $name
     * @return string
     */
    private static function pdf(string $name = 'a'): string {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Title ($name) >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * A dossier of situations: two situations on RNCP100 (the first with three versions, a draft in progress, a file and a
     * link; the second with a title alone and the same file), one on RNCP400 that is deleted after it was annotated, the
     * consent given, taken back and given again, four annotations and one withdrawal.
     */
    private function write_dossier(): void {
        $this->setUser($this->vaeiste);
        $situations = new situation_manager();
        $spieces = new spiece_manager();
        $vaeisteid = (int) $this->vaeiste->id;

        $one = $this->generator->create_situation([
            'userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'title' => 'Accueil du matin', 'tags' => 'C01,C02',
            'setting' => 'EHPAD Les Tilleuls', 'periodstart' => 1756684800, 'periodend' => 1759276800,
            'text' => self::FIRST_TEXT,
        ]);
        $situations->save_version((int) $one->id, "Deuxieme version, **plus longue**.\nSur deux lignes.");
        $situations->save_details((int) $one->id, 'Accueil du matin, revu', 1756684800, 1759276800, 'EHPAD Les Tilleuls', ['C01']);
        $situations->restore((int) $one->id, 1);
        (new draft_manager())->save_working((int) $one->draftid, 'Un brouillon en cours.');
        $path = make_request_directory() . '/upload';
        file_put_contents($path, self::pdf('un'));
        $file = $spieces->add_file((int) $one->id, 'writing', 'Mon ecrit professionnel', $path, 'Page 2');
        $link = $spieces->add_link((int) $one->id, 'other', 'Le site du service', self::ADDRESS, '');

        $two = $this->generator->create_situation([
            'userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'title' => 'Transmissions',
        ]);
        $spieces->attach((int) $two->id, (int) $file->id, 'Page 1');
        $spieces->remove((int) $link->id);

        $three = $this->generator->create_situation([
            'userid' => $vaeisteid, 'fichecode' => 'RNCP400', 'title' => 'Nuit', 'tags' => 'H111', 'text' => 'Texte de la nuit.',
        ]);

        $this->generator->create_consent(['userid' => $vaeisteid, 'managerid' => $this->manager->id]);
        $note = fn(array $data) => $this->generator->create_note($data + [
            'authorid' => $this->manager->id, 'userid' => $vaeisteid, 'fichecode' => 'RNCP100',
        ]);
        $n1 = $note(['slot' => 1, 'text' => self::NOTE_TEXT]);
        $n2 = $note(['target' => note_manager::TARGET_SELFPOS, 'ficherang' => 'C01', 'text' => '**Sur le noeud** C01.',
            'format' => FORMAT_MARKDOWN]);
        $n3 = $note(['slot' => 1, 'text' => 'Une annotation a retirer.']);
        $this->setUser($this->manager);
        $w3 = (new note_manager())->withdraw((int) $n3->id);
        $n4 = $note(['fichecode' => 'RNCP400', 'slot' => 1, 'text' => 'Sur la nuit.']);
        $this->setUser($this->vaeiste);
        $situations->delete((int) $three->id);
        $this->generator->create_consent(['userid' => $vaeisteid, 'managerid' => $this->manager->id, 'state' => 'withdrawn']);
        $this->generator->create_consent(['userid' => $vaeisteid, 'managerid' => $this->manager->id]);

        $this->written = compact('one', 'two', 'three', 'file', 'link', 'n1', 'n2', 'n3', 'w3', 'n4');
        $this->setAdminUser();
    }

    /**
     * The files of the archive the vaeiste exports themself: bytes, addresses and titles in.
     *
     * @return string[]
     */
    private function build(): array {
        global $DB;

        $this->setUser($this->vaeiste);
        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->vaeiste->id], 'timecreated ASC, id ASC');
        $exporter = new exporter();
        $files = $exporter->build((int) $this->vaeiste->id, $claims, identity_map::generate_ulid(), time(), true, true, true);
        $this->setAdminUser();

        return $files;
    }

    /**
     * The files of a stored archive, path => content.
     *
     * @param \stored_file $archive
     * @return string[]
     */
    private static function unzip(\stored_file $archive): array {
        $path = $archive->copy_content_to_temp();
        $zip = new \ZipArchive();
        $zip->open($path);
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            if (substr($name, -1) !== '/') {
                $files[$name] = $zip->getFromIndex($index);
            }
        }
        $zip->close();
        unlink($path);

        return $files;
    }

    /**
     * Rewrite situations.json in an archive, digest included; take files out or add some too.
     *
     * @param string[] $files
     * @param callable $change Receives the decoded document, returns it changed.
     * @param array $more Path => content, or null to take the file out.
     * @return string[]
     */
    private static function forge(array $files, callable $change, array $more = []): array {
        $document = $change(json_decode($files[situation_archive::PATH], true, 512, JSON_THROW_ON_ERROR));
        $changes = [situation_archive::PATH => json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] + $more;

        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $entries = array_column($manifest['fichiers'], null, 'chemin');
        foreach ($changes as $path => $content) {
            if ($content === null) {
                unset($files[$path], $entries[$path]);
                continue;
            }
            $files[$path] = $content;
            $entries[$path] = ['chemin' => $path, 'octets' => strlen($content), 'sha256' => hash('sha256', $content)];
        }
        $manifest['fichiers'] = array_values($entries);
        $files['manifeste.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $files;
    }

    /**
     * Empty the dossier: the declarations, the drafts, the seven tables of lot 1b and the files of the pieces, all by the
     * one place that knows what a dossier is made of (task 13).
     */
    private function empty_dossier(): void {
        purge::dossier((int) $this->vaeiste->id);
    }

    /**
     * Empty the dossier, import as the administrator, and give the result back.
     *
     * @param string[] $files
     * @return \stdClass
     */
    private function import_after_emptying(array $files): \stdClass {
        $this->empty_dossier();
        $this->setAdminUser();

        return (new dossier_importer())->import_files((int) $this->vaeiste->id, $files);
    }

    /**
     * Import after the dossier is emptied, and expect a refusal that writes nothing.
     *
     * @param string[] $files
     * @param string $code
     */
    private function assert_refused(array $files, string $code = 'errorarchivesituations'): void {
        global $DB;

        try {
            $this->import_after_emptying($files);
            $this->fail('The archive was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode, $e->getMessage());
        }
        foreach (['tool_vae_situation', 'tool_vae_spiece', 'tool_vae_note', 'tool_vae_consent', 'tool_vae_evidence'] as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
    }

    /**
     * The fingerprint of an annotation of the file, as the importer recomputes it.
     *
     * @param array $line
     * @return string
     */
    private static function note_hash(array $line): string {
        $target = $line['cible'] === 'situation' ? 'situation' : 'selfpos';
        if ($line['etat'] === 'retiree') {
            return positioning_rules::hash([$target, $line['fiche'], $line['emplacement'], $line['rang'], '', $line['retire']]);
        }

        return positioning_rules::hash([$target, $line['fiche'], $line['emplacement'], $line['rang'], $line['texte']]);
    }

    public function test_the_document_is_null_without_anything_and_carries_situations_pieces_annotations_and_consents(): void {
        $id = (int) $this->vaeiste->id;
        $this->assertNull(situation_archive::document($id, true));
        $this->write_dossier();
        $manageruuid = identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->manager->id);

        $document = situation_archive::document($id, true);

        $this->assertFalse($document['officiel']);
        $this->assertSame(get_string('situationarchivesentence', 'tool_vae'), $document['avertissement']);
        $this->assertSame(['avertissement', 'officiel', 'situations', 'pieces', 'annotations', 'accords'], array_keys($document));

        // The situations, the deleted one gone, each whole.
        $this->assertCount(2, $document['situations']);
        $one = $document['situations'][0];
        $this->assertSame($this->written['one']->uuid, $one['identifiant']);
        $this->assertSame('RNCP100', $one['fiche']);
        $this->assertSame(1, $one['emplacement']);
        $this->assertSame('Accueil du matin, revu', $one['intitule']);
        $this->assertSame(exporter::iso(1756684800), $one['periode_debut']);
        $this->assertSame(exporter::iso(1759276800), $one['periode_fin']);
        $this->assertSame('EHPAD Les Tilleuls', $one['cadre']);
        $this->assertSame(['C01'], $one['etiquettes']);
        $this->assertSame([1, 2, 3], array_column($one['versions'], 'numero'));
        $this->assertSame(self::FIRST_TEXT, $one['versions'][0]['contenu']);
        $this->assertSame(hash('sha256', self::FIRST_TEXT), $one['versions'][0]['empreinte']);
        $this->assertArrayNotHasKey('restauree_de', $one['versions'][0]);
        $this->assertSame(1, $one['versions'][2]['restauree_de']);
        $this->assertSame(self::FIRST_TEXT, $one['versions'][2]['contenu']);
        $this->assertSame('Accueil du matin', $one['versions'][0]['instantane']['intitule']);
        $this->assertSame(['C01', 'C02'], $one['versions'][0]['instantane']['etiquettes']);
        $this->assertSame('Accueil du matin, revu', $one['versions'][2]['instantane']['intitule']);
        $this->assertSame(['C01'], $one['versions'][2]['instantane']['etiquettes']);
        $this->assertSame('Un brouillon en cours.', $one['brouillon']);
        $this->assertSame([$this->written['file']->uuid, $this->written['link']->uuid], array_column($one['pieces'], 'piece'));
        $this->assertSame(['Page 2', ''], array_column($one['pieces'], 'partie'));
        $two = $document['situations'][1];
        $this->assertSame(2, $two['emplacement']);
        $this->assertSame('Transmissions', $two['intitule']);
        $this->assertSame([], $two['etiquettes']);
        $this->assertSame([], $two['versions']);
        $this->assertArrayNotHasKey('brouillon', $two);
        $this->assertArrayNotHasKey('periode_debut', $two);
        $this->assertSame([['piece' => $this->written['file']->uuid, 'partie' => 'Page 1']], array_map(
            fn(array $link) => ['piece' => $link['piece'], 'partie' => $link['partie']],
            $two['pieces']
        ));

        // The pieces, as pieces.json says them, the removed one without its address.
        $this->assertCount(2, $document['pieces']);
        $file = $document['pieces'][0];
        $this->assertSame($this->written['file']->uuid, $file['identifiant']);
        $this->assertSame('RNCP100', $file['fiche']);
        $this->assertSame('fichier', $file['genre']);
        $this->assertSame('writing', $file['nature']);
        $this->assertSame('Mon ecrit professionnel', $file['intitule']);
        $this->assertSame('application/pdf', $file['type']);
        $this->assertSame(strlen(self::pdf('un')), $file['taille']);
        $this->assertSame(hash('sha256', self::pdf('un')), $file['sha256']);
        $this->assertSame(situation_archive::FOLDER . $file['identifiant'] . '.pdf', $file['fichier']);
        $this->assertArrayNotHasKey('retiree_le', $file);
        $link = $document['pieces'][1];
        $this->assertSame('lien', $link['genre']);
        $this->assertArrayHasKey('retiree_le', $link);
        $this->assertArrayNotHasKey('adresse', $link);
        $this->assertArrayNotHasKey('intitule', $link);

        // The annotations: every line, the withdrawal included, the author by an opaque identifier.
        $this->assertCount(5, $document['annotations']);
        $notes = $document['annotations'];
        $cibles = ['situation', 'autopositionnement', 'situation', 'situation', 'situation'];
        $this->assertSame($cibles, array_column($notes, 'cible'));
        $this->assertSame($this->written['one']->uuid, $notes[0]['cible_identifiant']);
        $this->assertArrayNotHasKey('cible_identifiant', $notes[1]);
        $this->assertSame([1, 0, 1, 1, 1], array_column($notes, 'emplacement'));
        $this->assertSame(['', 'C01', '', '', ''], array_column($notes, 'rang'));
        $this->assertSame($manageruuid, $notes[0]['auteur']);
        $this->assertSame(self::NOTE_TEXT, $notes[0]['texte']);
        $this->assertSame(['texte', 'markdown', 'texte', 'texte', 'texte'], array_column($notes, 'format'));
        $this->assertSame(['active', 'active', 'active', 'retiree', 'active'], array_column($notes, 'etat'));
        $this->assertArrayNotHasKey('retire', $notes[0]);
        $this->assertSame($this->written['n3']->uuid, $notes[3]['retire']);
        $this->assertSame('', $notes[3]['texte']);
        $this->assertSame(self::note_hash($notes[0]), $notes[0]['empreinte']);
        $this->assertSame(self::note_hash($notes[3]), $notes[3]['empreinte']);
        $this->assertSame($this->written['three']->uuid, $notes[4]['cible_identifiant'], 'The deleted situation keeps its line.');
        $this->assertSame('RNCP400', $notes[4]['fiche']);

        // The consents: every line, the manager by an opaque identifier.
        $this->assertSame(['donne', 'retire', 'donne'], array_column($document['accords'], 'etat'));
        $this->assertSame([$manageruuid, $manageruuid, $manageruuid], array_column($document['accords'], 'responsable'));
        $this->assertStringNotContainsString('valid', json_encode($document['situations']));
        $this->assertStringNotContainsString((string) $this->manager->id, json_encode($document['accords']));

        // Without the bytes: neither path, nor address, nor title (the export of a third party).
        $bare = situation_archive::document($id, false);
        $this->assertArrayNotHasKey('fichier', $bare['pieces'][0]);
        $this->assertArrayNotHasKey('intitule', $bare['pieces'][0]);
        $this->assertSame(self::NOTE_TEXT, $bare['annotations'][0]['texte'], 'The annotations travel.');
        // A file that went missing from the archive: listed without its path.
        $this->assertArrayNotHasKey('fichier', situation_archive::document($id, true, [])['pieces'][0]);
    }

    public function test_the_document_is_valid_against_the_schema(): void {
        $this->write_dossier();

        $document = json_decode(json_encode(situation_archive::document((int) $this->vaeiste->id, true)), false);

        $this->assertSame([], (new schema_validator())->validate($document, 'situations.schema.json'));
    }

    public function test_a_round_trip_restores_every_situation_version_tag_link_annotation_and_consent_once(): void {
        global $DB;
        $this->write_dossier();
        $files = $this->build();
        $id = (int) $this->vaeiste->id;
        $this->assertArrayHasKey(situation_archive::FOLDER . $this->written['file']->uuid . '.pdf', $files);
        $before = situation_archive::document($id, true);

        $this->empty_dossier();
        $this->setAdminUser();
        $plan = (new dossier_importer())->check($files);
        $result = (new dossier_importer())->import_files($id, $files);

        // Two situations, two pieces, four annotations (the one on the deleted situation is left) and three consents.
        $this->assertSame(11, $result->situations);
        $this->assertSame(1, $result->situationslaissees);

        $after = situation_archive::document($id, true);
        $this->assertSame($before['situations'], $after['situations'], 'Every situation, version, tag, draft and link.');
        $this->assertSame($before['pieces'], $after['pieces']);
        $this->assertSame($before['accords'], $after['accords']);
        $this->assertSame(array_slice($before['annotations'], 0, 4), $after['annotations']);

        // The rows behind: the draft holds the text in progress and the right current version.
        $one = $DB->get_record('tool_vae_situation', ['uuid' => $this->written['one']->uuid], '*', MUST_EXIST);
        $draft = $DB->get_record('tool_vae_draft', ['id' => $one->draftid], '*', MUST_EXIST);
        $this->assertSame(3, (int) $draft->currentversion);
        $this->assertSame('Un brouillon en cours.', $draft->working);
        $this->assertSame(validation_catalog::SITUATION_SECTION, $draft->section);
        $versions = $DB->get_records('tool_vae_version', ['draftid' => $one->draftid], 'number ASC');
        $this->assertSame([1, 2, 3], array_map('intval', array_column($versions, 'number')));
        $this->assertSame([$id, $id, $id], array_map('intval', array_column($versions, 'authorid')), 'The vaeiste signs.');
        $this->assertSame(3, $DB->count_records('tool_vae_sitversion', ['situationid' => $one->id]));
        $this->assertSame(2, $DB->count_records('tool_vae_slink', ['situationid' => $one->id]));
        $piece = $DB->get_record('tool_vae_spiece', ['uuid' => $this->written['file']->uuid], '*', MUST_EXIST);
        $stored = get_file_storage()->get_file(
            \context_user::instance($id)->id,
            'tool_vae',
            spiece_manager::FILEAREA,
            (int) $piece->id,
            '/',
            $piece->uuid . '.pdf'
        );
        $this->assertNotFalse($stored);
        $this->assertSame(self::pdf('un'), $stored->get_content());
        foreach ($DB->get_records('tool_vae_note') as $note) {
            $this->assertNotNull($note->importedat, 'A restored line says when it came.');
        }
        $this->setUser($this->vaeiste);
        $list = (new situation_manager())->list($id, 'RNCP100');
        $counts = array_map(fn(\stdClass $row) => (int) $row->pieces, $list);
        $this->assertSame([1 => 1, 2 => 1], $counts, 'The removed piece is not counted.');

        // A second restoration of the same lines adds nothing.
        $this->assertSame([0, 12], situation_archive::restore($id, $plan->situations, time()));
        $this->assertSame(2, $DB->count_records('tool_vae_situation'));
        $this->assertSame(2, $DB->count_records('tool_vae_spiece'));
        $this->assertSame(4, $DB->count_records('tool_vae_note'));
        $this->assertSame(3, $DB->count_records('tool_vae_consent'));
    }

    public function test_a_restored_annotation_or_consent_never_names_a_local_account_nor_opens_the_annotation(): void {
        global $DB;
        $this->write_dossier();
        $files = $this->build();
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $manageruuid = identity_map::uuid_for(identity_map::TYPE_USER, $managerid);

        $this->import_after_emptying($files);

        foreach ($DB->get_records('tool_vae_note') as $note) {
            $this->assertNull($note->authorid);
            $this->assertSame($manageruuid, $note->authoruuid);
        }
        foreach ($DB->get_records('tool_vae_consent') as $consent) {
            $this->assertNull($consent->managerid);
            $this->assertSame($manageruuid, $consent->manageruuid);
            $this->assertNotNull($consent->importedat);
        }

        // The manager exists, follows the vaeiste and holds annotate: the restored consent opens nothing (D-1b-L).
        $this->assertFalse(consent_manager::stands($id, $managerid));
        $this->assertFalse(note_manager::can_write($id, $managerid));
        $this->setUser($this->vaeiste);
        $this->assertContains($managerid, (new consent_manager())->eligible($id), 'The manager is eligible all the same.');
        $this->assertSame([], (new consent_manager())->current($id));
        $this->assertSame([true, true, true], array_column((new consent_manager())->history($id), 'restored'));

        // The restored withdrawal still withdraws the annotation it names; nobody withdraws a restored annotation.
        $notes = array_values((new note_manager())->for_candidate($id));
        $this->assertSame([true, true, false, false], array_column($notes, 'standing'));
        $restored = (int) $DB->get_field('tool_vae_note', 'id', ['uuid' => $this->written['n1']->uuid], MUST_EXIST);
        foreach ([$this->manager, $this->vaeiste, get_admin()] as $person) {
            $this->setUser($person);
            try {
                (new note_manager())->withdraw($restored);
                $this->fail('A restored annotation was withdrawn.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errornotedenied', $e->errorcode);
            }
        }
        $this->assertSame(4, $DB->count_records('tool_vae_note'));
    }

    public function test_a_restored_withdrawal_must_name_an_annotation_of_the_same_author_in_the_file(): void {
        $this->write_dossier();
        $files = $this->build();

        // The withdrawal signed by another author than the annotation it names: refused by name.
        $this->assert_refused(self::forge($files, function (array $document): array {
            $document['annotations'][3]['auteur'] = '01JC8M3Q5R7T9V2X4Z6B8D0G9Z';

            return $document;
        }));
        // A withdrawal of an annotation the file does not hold.
        $this->assert_refused(self::forge($files, function (array $document): array {
            $document['annotations'][3]['retire'] = '01JC8M3Q5R7T9V2X4Z6B8D0G9Z';
            $document['annotations'][3]['empreinte'] = self::note_hash($document['annotations'][3]);

            return $document;
        }));
        // A forged fingerprint, and a text under the fingerprint of another.
        $this->assert_refused(self::forge($files, function (array $document): array {
            $document['annotations'][0]['texte'] = 'Un autre texte, sous la meme empreinte.';

            return $document;
        }));
        // An annotation of a situation with a rank, and one of a node with a slot.
        $this->assert_refused(self::forge($files, function (array $document): array {
            $document['annotations'][0]['rang'] = 'C01';
            $document['annotations'][0]['empreinte'] = self::note_hash($document['annotations'][0]);

            return $document;
        }));
        // A version whose text is not the one of its fingerprint, and a duplicate identifier.
        $this->assert_refused(self::forge($files, function (array $document): array {
            $document['situations'][0]['versions'][1]['contenu'] = 'Un autre texte.';

            return $document;
        }));
        $this->assert_refused(self::forge($files, function (array $document): array {
            $document['situations'][1]['identifiant'] = $document['situations'][0]['identifiant'];

            return $document;
        }));
        // A file in the folder that no piece names.
        $this->assert_refused(self::forge($files, fn(array $document): array => $document, [
            situation_archive::FOLDER . '01JC8M3Q5R7T9V2X4Z6B8D0G9Z.pdf' => self::pdf('orphan'),
        ]));
        // The bytes of a piece that are not the ones the archive says.
        $this->assert_refused(self::forge($files, fn(array $document): array => $document, [
            situation_archive::FOLDER . $this->written['file']->uuid . '.pdf' => self::pdf('autre'),
        ]));
    }

    public function test_a_situation_whose_slot_is_taken_or_whose_piece_is_missing_is_left_whole(): void {
        global $DB;
        $this->write_dossier();
        $files = $this->build();
        $id = (int) $this->vaeiste->id;

        // The first slot of RNCP100 taken by a new situation: the first situation and its three annotations are left.
        $this->empty_dossier();
        $taken = $this->generator->create_situation(['userid' => $id, 'fichecode' => 'RNCP100', 'title' => 'Une autre']);
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files($id, $files);

        $this->assertSame(7, $result->situations, 'The second situation, two pieces, the annotation of the node, three consents.');
        $this->assertSame(5, $result->situationslaissees, 'The first situation, its three annotations, the orphan annotation.');
        $this->assertSame(2, $DB->count_records('tool_vae_situation'));
        $this->assertSame('Une autre', $DB->get_field('tool_vae_situation', 'title', ['id' => $taken->id]));
        $this->assertFalse($DB->record_exists('tool_vae_situation', ['uuid' => $this->written['one']->uuid]));
        $this->assertTrue($DB->record_exists('tool_vae_situation', ['uuid' => $this->written['two']->uuid]));
        $this->assertSame(1, $DB->count_records('tool_vae_note'));
        $this->assertSame(0, $DB->count_records('tool_vae_sitversion'));

        // A piece cited by the second situation that is neither in the archive nor here: the second comes without that link,
        // which is left and counted (T15, point C: what cannot be restored is left one by one, never the situation).
        $forged = self::forge($files, function (array $document): array {
            $document['situations'][1]['pieces'][0]['piece'] = '01JC8M3Q5R7T9V2X4Z6B8D0G9Z';

            return $document;
        });
        $result = $this->import_after_emptying($forged);

        $this->assertSame(11, $result->situations);
        $this->assertSame(2, $result->situationslaissees, 'The link, the orphan annotation.');
        $this->assertTrue($DB->record_exists('tool_vae_situation', ['uuid' => $this->written['one']->uuid]));
        $two = $DB->get_record('tool_vae_situation', ['uuid' => $this->written['two']->uuid], '*', MUST_EXIST);
        $this->assertSame(0, $DB->count_records('tool_vae_slink', ['situationid' => $two->id]), 'Without the link.');
        $this->assertSame(2, $DB->count_records('tool_vae_slink'));
        $this->assertSame(2, $DB->count_records('tool_vae_spiece'), 'The pieces come whether a situation cites them or not.');
        $this->assertSame(3, $DB->count_records('tool_vae_sitversion'));

        // A tag this record does not hold: the situation comes without it, the tag is left. A record this site does not
        // hold: the situation is left whole, nothing of it can be placed.
        $forged = self::forge($files, function (array $document): array {
            $document['situations'][0]['etiquettes'] = ['C99'];
            $document['situations'][1]['fiche'] = 'RNCP999';

            return $document;
        });
        $result = $this->import_after_emptying($forged);

        $this->assertSame(1, $DB->count_records('tool_vae_situation'));
        $one = $DB->get_record('tool_vae_situation', ['uuid' => $this->written['one']->uuid], '*', MUST_EXIST);
        $this->assertSame(0, $DB->count_records('tool_vae_sitetag', ['situationid' => $one->id]), 'Without the tag.');
        $this->assertSame(3, $DB->count_records('tool_vae_sitversion', ['situationid' => $one->id]), 'Text and versions.');
        $this->assertSame(1 + 2 + 4 + 3, $result->situations, 'The first, two pieces, four annotations, three consents.');
        $this->assertSame(1 + 1 + 1, $result->situationslaissees, 'The tag, the second situation, the orphan annotation.');
    }

    public function test_a_situation_or_a_target_over_its_own_bound_is_left_and_a_file_over_its_bound_is_refused_whole(): void {
        global $DB;
        $this->write_dossier();
        $files = $this->build();
        $id = (int) $this->vaeiste->id;

        // Two hundred and one versions: the situation is left, the rest comes.
        $result = $this->import_after_emptying(self::forge($files, function (array $document): array {
            $first = $document['situations'][0]['versions'][0];
            $versions = [];
            for ($number = 1; $number <= situation_archive::MAX_VERSIONS + 1; $number++) {
                $versions[] = ['numero' => $number] + $first;
            }
            unset($versions[0]['restauree_de']);
            $document['situations'][0]['versions'] = $versions;

            return $document;
        }));
        $this->assertFalse($DB->record_exists('tool_vae_situation', ['uuid' => $this->written['one']->uuid]));
        $this->assertTrue($DB->record_exists('tool_vae_situation', ['uuid' => $this->written['two']->uuid]));
        $this->assertSame(5, $result->situationslaissees, 'The situation, its three annotations and the orphan one.');

        // A target that cannot hold every ACTIVE line of the archive takes none; the consent lines likewise for the lines GIVEN
        // (D-1b-J, restored lines count; the bound counts what opens, T15): the import never leaves a target or a vaeiste
        // already full without saying so. 199 annotations here and two in the file, 399 consents given here and two in the file.
        $this->empty_dossier();
        for ($i = 0; $i < note_manager::MAX_PER_TARGET - 1; $i++) {
            $DB->insert_record('tool_vae_note', (object) [
                'uuid' => identity_map::generate_ulid(), 'candidateid' => $id, 'target' => note_manager::TARGET_SITUATION,
                'targetuuid' => $this->written['one']->uuid, 'fichecode' => 'RNCP100', 'slot' => 1, 'ficherang' => '',
                'authorid' => $this->manager->id, 'authoruuid' => null, 'text' => 'Ligne ' . $i, 'textformat' => FORMAT_PLAIN,
                'state' => note_manager::ACTIVE, 'withdraws' => null, 'notehash' => str_repeat('0', 64), 'importedat' => null,
                'timecreated' => 1700000000 + $i,
            ]);
        }
        for ($i = 0; $i < consent_manager::MAX_LINES - 1; $i++) {
            $DB->insert_record('tool_vae_consent', (object) [
                'uuid' => identity_map::generate_ulid(), 'candidateid' => $id, 'managerid' => $this->manager->id,
                'manageruuid' => null, 'state' => consent_manager::GIVEN, 'importedat' => null, 'timecreated' => 1700000000 + $i,
            ]);
        }
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files($id, $files);

        $this->assertSame(2 + 2 + 1, $result->situations, 'Two situations, two pieces, the annotation of the node.');
        $this->assertSame(3 + 1 + 3, $result->situationslaissees, 'Three lines of the full target, the orphan, three consents.');
        $this->assertSame(note_manager::MAX_PER_TARGET - 1 + 1, $DB->count_records('tool_vae_note'));
        $this->assertSame(consent_manager::MAX_LINES - 1, $DB->count_records('tool_vae_consent'));

        // Eight hundred and one lines of consent (given and withdrawn in pairs): the file is refused whole (T15 tour 2, A-1:
        // the file may hold twice the consents given, a withdrawal following each one).
        $this->assert_refused(self::forge($files, function (array $document): array {
            $document['accords'] = self::consent_pairs($document['accords'][0], situation_archive::MAX_CONSENT_LINES + 1);

            return $document;
        }));
        // A slot of thirty-one, a title of sixty-one, a tag in lower case, an annotation of four thousand and one: the schema.
        $changes = [
            fn(array $d) => self::with($d, ['situations', 1, 'emplacement'], 31),
            fn(array $d) => self::with($d, ['situations', 1, 'intitule'], str_repeat('a', 61)),
            fn(array $d) => self::with($d, ['situations', 0, 'etiquettes'], ['c01']),
            fn(array $d) => self::with($d, ['annotations', 0, 'texte'], str_repeat('a', 4001)),
            fn(array $d) => self::with($d, ['accords', 0, 'etat'], 'peut-etre'),
            fn(array $d) => self::with($d, ['pieces', 0, 'fichier'], '../preuves/x.pdf'),
        ];
        foreach ($changes as $change) {
            $this->assert_refused(self::forge($files, $change));
        }
    }

    /**
     * T15, point B: the bound of thirty situations is per vaeiste and per record, never on the whole file. The schema
     * (emplacement 1..30) and the refusal of an address twice hold it; the importer counts nothing on the file.
     */
    public function test_thirty_one_situations_on_several_records_are_accepted_and_the_bound_holds_per_record(): void {
        global $DB;
        $this->write_dossier();
        $files = $this->build();

        // Twenty-nine more on records this site does not hold: nothing is refused, they are left and counted.
        $result = $this->import_after_emptying(self::forge($files, function (array $document): array {
            $model = $document['situations'][1];
            for ($i = 0; $i < 29; $i++) {
                $model['identifiant'] = identity_map::generate_ulid();
                $model['fiche'] = 'RNCP' . (500 + $i);
                $document['situations'][] = $model;
            }

            return $document;
        }));
        $this->assertSame(11, $result->situations);
        $this->assertSame(29 + 1, $result->situationslaissees, 'The twenty-nine unknown records, the orphan annotation.');
        $this->assertSame(2, $DB->count_records('tool_vae_situation'));

        // Twenty on RNCP100 and eleven on RNCP400: thirty-one situations, every one of them comes back.
        $result = $this->import_after_emptying(self::forge($files, function (array $document): array {
            $model = $document['situations'][1];
            $model['pieces'] = [];
            foreach ([['RNCP100', 3, 20], ['RNCP400', 1, 11]] as [$fiche, $from, $to]) {
                for ($slot = $from; $slot <= $to; $slot++) {
                    $model['identifiant'] = identity_map::generate_ulid();
                    $model['fiche'] = $fiche;
                    $model['emplacement'] = $slot;
                    $document['situations'][] = $model;
                }
            }

            return $document;
        }));
        $this->assertSame(11 + 29, $result->situations);
        $this->assertSame(1, $result->situationslaissees);
        $this->assertSame(20, $DB->count_records('tool_vae_situation', ['fichecode' => 'RNCP100']));
        $this->assertSame(11, $DB->count_records('tool_vae_situation', ['fichecode' => 'RNCP400']));

        // What holds the bound per record: a slot of thirty-one is refused by the schema (see the list of the schema above),
        // and the same address twice by the importer.
        $this->assert_refused(self::forge($files, fn(array $d) => self::with($d, ['situations', 1, 'emplacement'], 1)));
    }

    /**
     * T15, point A: at the restoration too, the bound counts what opens. Four hundred lines of consent of which two hundred
     * withdrawals, and two hundred lines of annotation of which one hundred withdrawals, come back whole when the consents
     * given and the annotations standing fit.
     */
    public function test_an_archive_of_lines_with_withdrawals_comes_back_whole_when_what_opens_fits(): void {
        global $DB;
        $this->write_dossier();
        $files = $this->build();
        $id = (int) $this->vaeiste->id;
        $one = $this->written['one']->uuid;

        $forged = self::forge($files, function (array $document) use ($one): array {
            $consent = $document['accords'][0];
            $document['accords'] = [];
            for ($i = 0; $i < consent_manager::MAX_LINES; $i++) {
                $consent['identifiant'] = identity_map::generate_ulid();
                $consent['etat'] = $i % 2 ? 'retire' : 'donne';
                $document['accords'][] = $consent;
            }
            $model = $document['annotations'][0];
            $this->assertSame($one, $model['cible_identifiant']);
            $document['annotations'] = [];
            for ($i = 0; $i < note_manager::MAX_PER_TARGET / 2; $i++) {
                $note = $model;
                $note['identifiant'] = identity_map::generate_ulid();
                $note['texte'] = 'Annotation ' . $i;
                $note['empreinte'] = self::note_hash($note);
                $taken = $model;
                $taken['identifiant'] = identity_map::generate_ulid();
                $taken['texte'] = '';
                $taken['etat'] = 'retiree';
                $taken['retire'] = $note['identifiant'];
                $taken['empreinte'] = self::note_hash($taken);
                $document['annotations'][] = $note;
                $document['annotations'][] = $taken;
            }

            return $document;
        });

        // Two hundred consents given here, one hundred annotations standing here on the first situation: it still fits.
        $this->empty_dossier();
        for ($i = 0; $i < consent_manager::MAX_LINES / 2; $i++) {
            $DB->insert_record('tool_vae_consent', (object) [
                'uuid' => identity_map::generate_ulid(), 'candidateid' => $id, 'managerid' => $this->manager->id,
                'manageruuid' => null, 'state' => consent_manager::GIVEN, 'importedat' => null, 'timecreated' => 1700000000 + $i,
            ]);
        }
        for ($i = 0; $i < note_manager::MAX_PER_TARGET / 2; $i++) {
            $DB->insert_record('tool_vae_note', (object) [
                'uuid' => identity_map::generate_ulid(), 'candidateid' => $id, 'target' => note_manager::TARGET_SITUATION,
                'targetuuid' => $one, 'fichecode' => 'RNCP100', 'slot' => 1, 'ficherang' => '',
                'authorid' => $this->manager->id, 'authoruuid' => null, 'text' => 'Ligne ' . $i, 'textformat' => FORMAT_PLAIN,
                'state' => note_manager::ACTIVE, 'withdraws' => null, 'notehash' => str_repeat('0', 64), 'importedat' => null,
                'timecreated' => 1700000000 + $i,
            ]);
        }
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files($id, $forged);

        $this->assertSame(2 + 2 + 200 + 400, $result->situations, 'Two situations, two pieces, every line.');
        $this->assertSame(0, $result->situationslaissees);
        $this->assertSame(600, $DB->count_records('tool_vae_consent'));
        $this->assertSame(300, $DB->count_records('tool_vae_note'));
        $this->setUser($this->vaeiste);
        $lines = (new note_manager())->for_target($id, note_manager::TARGET_SITUATION, 'RNCP100', 1, '');
        $this->assertSame(100, count(array_filter($lines, fn($row) => $row->standing)), 'The hundred written here.');
    }

    /**
     * Lines of consent of one manager, given and taken back in turn: donne, retire, donne, retire...
     *
     * @param array $model A line of the file: its responsable and its date are kept.
     * @param int $count How many lines.
     * @param string|null $manager The responsable; the model's when null.
     * @return array[]
     */
    private static function consent_pairs(array $model, int $count, ?string $manager = null): array {
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $line = $model;
            $line['identifiant'] = identity_map::generate_ulid();
            $line['etat'] = $i % 2 ? 'retire' : 'donne';
            if ($manager !== null) {
                $line['responsable'] = $manager;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * T15 tour 2 (A-1, A-2): the file holds up to twice the consents given, since a withdrawal follows each one; every
     * withdrawal of the file follows a consent given to the same manager not taken back yet, or the file is refused; and the
     * lines of a vaeiste, whatever their origin and state, never exceed that same bound at the restoration.
     */
    public function test_eight_hundred_consent_lines_in_pairs_are_restored_and_an_orphan_withdrawal_is_refused(): void {
        global $DB;
        $this->write_dossier();
        $files = $this->build();
        $id = (int) $this->vaeiste->id;
        $model = json_decode($files[situation_archive::PATH], true, 512, JSON_THROW_ON_ERROR)['accords'][0];
        $pairs = fn(int $count, ?string $manager = null) => self::forge(
            $files,
            function (array $document) use ($model, $count, $manager): array {
                $document['accords'] = self::consent_pairs($model, $count, $manager);

                return $document;
            }
        );

        // A-1: eight hundred lines, four hundred given - the export of a vaeiste who gave and took back four hundred times.
        // The plan of a second file is read while the dossier is empty: check() refuses an archive whose declarations are
        // already here, and the second pass goes through restore(), as the round trip does.
        $limit = 2 * consent_manager::MAX_LINES;
        $this->empty_dossier();
        $this->setAdminUser();
        $importer = new dossier_importer();
        $second = $importer->check($pairs(2, '01JC8M3Q5R7T9V2X4Z6B8D0G9Z'));
        $result = $importer->import_files($id, $pairs($limit));
        $this->assertSame($limit, $DB->count_records('tool_vae_consent'));
        $this->assertSame(2 + 2 + 4 + 800, $result->situations);
        $this->assertSame(1, $result->situationslaissees, 'The orphan annotation alone.');

        // The second file, two lines of a new manager: the vaeiste already holds four hundred given, every line is left.
        [$restored, $left] = situation_archive::restore($id, $second->situations, time());
        $this->assertSame($limit, $DB->count_records('tool_vae_consent'), 'Nothing added.');
        $this->assertSame(0, $restored);
        $this->assertSame(2 + 2 + 2 + 4 + 1, $left, 'Two lines left, and everything already here.');

        // A-2 (i): a withdrawal without the consent it takes back - withdrawals alone, a second withdrawal of one consent, a
        // withdrawal of another manager's consent. Refused whole, as an orphan withdrawal of an annotation is.
        $withdrawals = fn(array $states, array $managers = []) => self::forge(
            $files,
            function (array $document) use ($model, $states, $managers): array {
                $document['accords'] = [];
                foreach ($states as $i => $state) {
                    $line = $model;
                    $line['identifiant'] = identity_map::generate_ulid();
                    $line['etat'] = $state;
                    if (isset($managers[$i])) {
                        $line['responsable'] = $managers[$i];
                    }
                    $document['accords'][] = $line;
                }

                return $document;
            }
        );
        $this->assert_refused($withdrawals(['retire', 'retire', 'retire']));
        $this->assert_refused($withdrawals(['donne', 'retire', 'retire']));
        $this->assert_refused($withdrawals(['donne', 'retire'], [1 => '01JC8M3Q5R7T9V2X4Z6B8D0G9Z']));
        $this->assert_refused($withdrawals(['retire', 'donne']));

        // A-2 (ii): the guard at the restoration, reached only with raw lines - three hundred given and three hundred and
        // fifty withdrawn written here, a file of a hundred pairs - four hundred given fits, eight hundred and fifty lines do
        // not: every line of the file is left. A hundred withdrawn lines fewer here, and the file comes whole.
        $this->empty_dossier();
        $raw = function (string $state, int $count) use ($DB, $id): void {
            for ($i = 0; $i < $count; $i++) {
                $DB->insert_record('tool_vae_consent', (object) [
                    'uuid' => identity_map::generate_ulid(), 'candidateid' => $id, 'managerid' => $this->manager->id,
                    'manageruuid' => null, 'state' => $state, 'importedat' => null, 'timecreated' => 1700000000 + $i,
                ]);
            }
        };
        $raw(consent_manager::GIVEN, 300);
        $raw(consent_manager::WITHDRAWN, 350);
        $this->setAdminUser();
        $plan = $importer->check($pairs(200));
        $result = $importer->import_files($id, $pairs(200));
        $this->assertSame(650, $DB->count_records('tool_vae_consent'), 'Every line of the file is left.');
        $this->assertSame(200 + 1, $result->situationslaissees);
        $withdrawn = $DB->get_records_select(
            'tool_vae_consent',
            'candidateid = :c AND state = :s',
            ['c' => $id, 's' => consent_manager::WITHDRAWN],
            'id DESC',
            'id',
            0,
            100
        );
        $DB->delete_records_list('tool_vae_consent', 'id', array_keys($withdrawn));
        $this->assertSame(550, $DB->count_records('tool_vae_consent'));
        [$restored, $left] = situation_archive::restore($id, $plan->situations, time());
        $this->assertSame(750, $DB->count_records('tool_vae_consent'), 'The file comes whole.');
        $this->assertSame(200, $restored);
        $this->assertSame(2 + 2 + 4 + 1, $left, 'What was already here, and the orphan annotation.');
        $this->assertSame($limit, situation_archive::MAX_CONSENT_LINES, 'The bound of the file is the one of the vaeiste.');
    }

    /**
     * A copy of a document with one value changed.
     *
     * @param array $document
     * @param array $path
     * @param mixed $value
     * @return array
     */
    private static function with(array $document, array $path, $value): array {
        $cursor = &$document;
        foreach ($path as $step) {
            $cursor = &$cursor[$step];
        }
        $cursor = $value;

        return $document;
    }

    public function test_the_third_party_export_and_the_backup_carry_the_file(): void {
        $this->write_dossier();
        $id = (int) $this->vaeiste->id;
        $path = situation_archive::FOLDER . $this->written['file']->uuid . '.pdf';

        // The export of the direction: the file, with the texts and the annotations, without bytes, addresses or titles.
        $this->setUser($this->direction);
        $files = self::unzip((new exporter())->export($id));
        $this->assertArrayHasKey(situation_archive::PATH, $files);
        $this->assertArrayHasKey('schema/situations.schema.json', $files);
        $this->assertArrayNotHasKey($path, $files);
        $document = json_decode($files[situation_archive::PATH], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(self::FIRST_TEXT, $document['situations'][0]['versions'][0]['contenu']);
        $this->assertSame(self::NOTE_TEXT, $document['annotations'][0]['texte']);
        $this->assertArrayNotHasKey('fichier', $document['pieces'][0]);
        $this->assertArrayNotHasKey('intitule', $document['pieces'][0]);
        $this->assertStringNotContainsString(self::ADDRESS, $files[situation_archive::PATH]);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(situation_archive::SCHEMA_VERSION, $manifest['schema_situations']);
        $this->assertStringContainsString('situations.json', $files['LISEZMOI.txt']);

        // The backup kept on the platform: the file and the bytes.
        $this->setAdminUser();
        $files = self::unzip(backup::save($id, (int) get_admin()->id));
        $this->assertArrayHasKey(situation_archive::PATH, $files);
        $this->assertSame(self::pdf('un'), $files[$path]);
        $document = json_decode($files[situation_archive::PATH], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($path, $document['pieces'][0]['fichier']);
    }

    /**
     * Decision of the project owner, 9 November 2026 (T15, point X): situations.json follows the rule of reading of the
     * preparation (D-1b-G). The export a third party makes carries it only when that person may read the preparation - the
     * direction and the managers who follow: yes; the site administrator: never (MC-16). The vaeiste's own export always does,
     * and the backup, the safety net of an uninstall, stays whole.
     */
    public function test_a_third_party_export_carries_the_situations_only_when_the_exporter_may_read_the_preparation(): void {
        $this->write_dossier();
        $id = (int) $this->vaeiste->id;
        $path = situation_archive::FOLDER . $this->written['file']->uuid . '.pdf';
        $absent = function (array $files, string $who): void {
            $this->assertArrayNotHasKey(situation_archive::PATH, $files, $who);
            $this->assertArrayNotHasKey('schema/situations.schema.json', $files, $who);
            $this->assertSame([], array_filter(array_keys($files), fn($p) => str_starts_with($p, situation_archive::FOLDER)), $who);
            $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('schema_situations', $manifest, $who);
            $this->assertNotContains(situation_archive::PATH, array_column($manifest['fichiers'], 'chemin'), $who);
            $this->assertStringNotContainsString('situations.json', $files['LISEZMOI.txt'], $who);
            // The rest of the archive is whole: the declaration and the record (the dossier holds no piece of a declaration).
            $this->assertSame(1, count(array_filter(array_keys($files), fn($p) => str_starts_with($p, 'preuves/'))), $who);
            $this->assertArrayHasKey('referentiel.json', $files, $who);
        };
        $present = function (array $files, string $who): void {
            $this->assertArrayHasKey(situation_archive::PATH, $files, $who);
            $this->assertArrayHasKey('schema/situations.schema.json', $files, $who);
            $document = json_decode($files[situation_archive::PATH], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(self::FIRST_TEXT, $document['situations'][0]['versions'][0]['contenu'], $who);
            $this->assertSame(self::NOTE_TEXT, $document['annotations'][0]['texte'], $who);
            $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(situation_archive::SCHEMA_VERSION, $manifest['schema_situations'], $who);
            $this->assertStringContainsString('situations.json', $files['LISEZMOI.txt'], $who);
        };

        // Case 1: the site administrator views every dossier and never reads the preparation: no situations, no pieces of theirs.
        $this->setAdminUser();
        $exporter = new exporter();
        $files = self::unzip($exporter->export($id));
        $absent($files, 'administrator');
        $this->assertSame([], $exporter->warnings(), 'Nothing is reported about a file that is not there.');

        // Case 2: the direction (viewany, not administrator) and the manager who follows read the preparation: the file comes,
        // without bytes, addresses or titles (MC-28, unchanged).
        foreach (['direction' => $this->direction, 'manager' => $this->manager] as $who => $reader) {
            $this->setUser($reader);
            $files = self::unzip((new exporter())->export($id));
            $present($files, $who);
            $this->assertArrayNotHasKey($path, $files, $who);
            $document = json_decode($files[situation_archive::PATH], true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('fichier', $document['pieces'][0], $who);
            $this->assertArrayNotHasKey('intitule', $document['pieces'][0], $who);
            $this->assertStringNotContainsString(self::ADDRESS, $files[situation_archive::PATH], $who);
        }

        // Case 3: the vaeiste exports themself: the file, and the bytes with it.
        $this->setUser($this->vaeiste);
        $files = self::unzip((new exporter())->export($id));
        $present($files, 'vaeiste');
        $this->assertSame(self::pdf('un'), $files[$path]);

        // Case 4: the backup of the administrator stays whole: the file and the bytes, whoever holds it.
        $this->setAdminUser();
        $files = self::unzip(backup::save($id, (int) get_admin()->id));
        $present($files, 'backup');
        $this->assertSame(self::pdf('un'), $files[$path]);
        $this->assertSame($path, json_decode($files[situation_archive::PATH], true)['pieces'][0]['fichier']);
    }

    public function test_validation_json_and_the_pdf_never_carry_a_situation(): void {
        $this->write_dossier();
        $this->setUser($this->vaeiste);
        $drafts = new draft_manager();
        $section = $drafts->section((int) $this->vaeiste->id, draft_manager::DOCTYPE_VALIDATION, 'bc01_decrire', 0, 'RNCP100');
        $drafts->save_version((int) $section->id, 'Ma partie decrire, enregistree.');

        $files = $this->build();

        $this->assertArrayHasKey(exporter::VALIDATION_PATH, $files);
        $validation = json_decode($files[exporter::VALIDATION_PATH], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['bc01_decrire'], array_column($validation['rubriques'], 'code'));
        $this->assertArrayHasKey(\tool_vae\local\pdf_renderer::PATH, $files);
        // TCPDF compresses what it writes, so the PDF itself is not searched: its HTML is, drawn from the archive's own files
        // as exporter_test does. The saved part is in it, so its silence about the situations means something.
        $decode = fn(string $content) => json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $preuves = array_filter($files, fn(string $path) => str_starts_with($path, 'preuves/'), ARRAY_FILTER_USE_KEY);
        $html = \tool_vae\local\pdf_renderer::html(
            $decode($files['manifeste.json']),
            array_values(array_map($decode, $preuves)),
            $decode($files['referentiel.json']),
            [],
            $validation
        );
        $this->assertStringContainsString('Ma partie decrire, enregistree.', $html);
        $this->assertStringNotContainsString(self::FIRST_TEXT, $html);
        $this->assertStringNotContainsString(self::NOTE_TEXT, $html);
        $this->assertStringNotContainsString('Accueil du matin', $html);
        $this->assertStringNotContainsString(validation_catalog::SITUATION_SECTION, $files[exporter::VALIDATION_PATH]);
        foreach ($files as $path => $content) {
            if ($path === situation_archive::PATH || $path === \tool_vae\local\pdf_renderer::PATH) {
                continue;
            }
            $this->assertStringNotContainsString(self::FIRST_TEXT, $content, $path);
            $this->assertStringNotContainsString(self::NOTE_TEXT, $content, $path);
            $this->assertStringNotContainsString('Accueil du matin', $content, $path);
        }
    }

    /**
     * Tour 1, point 1 (critical): a restored withdrawal may never take back an annotation written here by another author, and
     * an annotation never comes back without the withdrawal the archive says followed it.
     */
    public function test_a_restored_withdrawal_and_the_annotation_it_names_come_together_or_not_at_all(): void {
        global $DB;
        $this->write_dossier();
        $files = $this->build();
        $id = (int) $this->vaeiste->id;
        $managerid = (int) $this->manager->id;
        $stranger = '01JC8M3Q5R7T9V2X4Z6B8D0G9Z';

        // Case (a): a LOCAL annotation of the manager on the node C01; the archive forged to carry a copy of it under another
        // author and that author's withdrawal of it. The copy is already here and is left; the withdrawal must not come either.
        $this->empty_dossier();
        $this->generator->create_consent(['userid' => $id, 'managerid' => $managerid]);
        $local = $this->generator->create_note([
            'authorid' => $managerid, 'userid' => $id, 'fichecode' => 'RNCP100', 'target' => note_manager::TARGET_SELFPOS,
            'ficherang' => 'C01', 'text' => 'Ma ligne locale.',
        ]);
        $withdrawal = identity_map::generate_ulid();
        $forged = self::forge($files, function (array $document) use ($local, $stranger, $withdrawal): array {
            $copy = $document['annotations'][1];
            $copy['identifiant'] = $local->uuid;
            $copy['auteur'] = $stranger;
            $copy['texte'] = 'Ma ligne locale.';
            $copy['format'] = 'texte';
            $copy['empreinte'] = self::note_hash($copy);
            $taken = $copy;
            $taken['identifiant'] = $withdrawal;
            $taken['texte'] = '';
            $taken['etat'] = 'retiree';
            $taken['retire'] = $local->uuid;
            $taken['empreinte'] = self::note_hash($taken);
            $taken['ecrit_le'] = end($document['annotations'])['ecrit_le'];
            $document['annotations'][1] = $copy;
            $document['annotations'][] = $taken;

            return $document;
        });
        $this->setAdminUser();
        (new dossier_importer())->import_files($id, $forged);

        $this->assertFalse($DB->record_exists('tool_vae_note', ['uuid' => $withdrawal]), 'The forged withdrawal is not written.');
        $this->setUser($this->manager);
        $lines = array_values((new note_manager())->for_target($id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'C01'));
        $this->assertCount(1, $lines);
        $this->assertTrue($lines[0]->standing, 'The local annotation still stands.');
        $this->assertSame($managerid, (int) $lines[0]->authorid);
        (new note_manager())->withdraw((int) $local->id);
        $onnode = $DB->count_records('tool_vae_note', ['ficherang' => 'C01']);
        $this->assertSame(2, $onnode, 'The manager still takes their word back.');

        // Case (b): the annotation is new here but the withdrawal that follows it in the archive carries an identifier already
        // here: the annotation would stand against the word of the archive. The whole target is left.
        $this->empty_dossier();
        $DB->insert_record('tool_vae_note', (object) [
            'uuid' => $this->written['w3']->uuid, 'candidateid' => $id, 'target' => note_manager::TARGET_SELFPOS,
            'targetuuid' => '', 'fichecode' => 'RNCP100', 'slot' => 0, 'ficherang' => 'C02', 'authorid' => $managerid,
            'authoruuid' => null, 'text' => 'Une autre ligne.', 'textformat' => FORMAT_PLAIN, 'state' => note_manager::ACTIVE,
            'withdraws' => null, 'notehash' => str_repeat('0', 64), 'importedat' => null, 'timecreated' => time(),
        ]);
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files($id, $files);

        $this->assertFalse($DB->record_exists('tool_vae_note', ['uuid' => $this->written['n3']->uuid]));
        $this->assertFalse($DB->record_exists('tool_vae_note', ['uuid' => $this->written['n1']->uuid]), 'The whole target.');
        $this->assertTrue($DB->record_exists('tool_vae_note', ['uuid' => $this->written['n2']->uuid]));
        $this->assertSame(2, $DB->count_records('tool_vae_note'));
        $this->assertSame(3 + 1, $result->situationslaissees, 'The three lines of the first situation and the orphan one.');
    }

    /**
     * Tour 1, point 2, and T15 point C: a piece the archive lists without its bytes or its address (the export of a third
     * party) is left, never written as an empty shell; the situations that cite it come back with their text, their versions
     * and their annotations, without the link to it, which is left and counted; the removed piece keeps its trace.
     */
    public function test_a_piece_without_bytes_nor_address_is_left_and_the_situations_that_cite_it_come_without_it(): void {
        global $DB;
        $this->write_dossier();
        $id = (int) $this->vaeiste->id;
        $this->setUser($this->direction);
        $files = self::unzip((new exporter())->export($id));

        $result = $this->import_after_emptying($files);

        $this->assertFalse($DB->record_exists('tool_vae_spiece', ['uuid' => $this->written['file']->uuid]), 'No empty shell.');
        $this->assertTrue($DB->record_exists('tool_vae_spiece', ['uuid' => $this->written['link']->uuid]), 'The trace comes.');
        $this->assertSame(2, $DB->count_records('tool_vae_situation'), 'Both come, though both cite the file.');
        $one = $DB->get_record('tool_vae_situation', ['uuid' => $this->written['one']->uuid], '*', MUST_EXIST);
        $this->assertSame(3, $DB->count_records('tool_vae_version', ['draftid' => $one->draftid]));
        $this->assertSame(1, $DB->count_records('tool_vae_slink'), 'The link to the removed piece alone.');
        $this->assertSame(3, $DB->count_records('tool_vae_note', ['targetuuid' => $one->uuid]), 'Its annotations follow it.');
        $this->assertSame(2 + 1 + 4 + 3, $result->situations, 'Two situations, the trace, four annotations, three consents.');
        $this->assertSame(1 + 2 + 1, $result->situationslaissees, 'The file, the two links to it, the orphan annotation.');
        $this->assertSame([], situation_archive::files($id, true), 'The strict export of the vaeiste still holds.');
        $this->build();
    }

    /**
     * Tour 1, point 3, and T15 point C: the bounds of a situation's pieces hold at the restoration as they hold at the
     * keyboard - five active pieces at most, each of the record of the situation - and what does not fit is left link by
     * link, the situation comes.
     */
    public function test_a_situation_with_more_than_five_active_pieces_or_a_piece_of_another_record_comes_without_them(): void {
        global $DB;
        $this->write_dossier();
        $files = $this->build();

        // Six active pieces on the first situation (the file, and five links): the first five stand, the sixth link is left;
        // the pieces themselves all come.
        $uuids = array_map(fn(int $i) => identity_map::generate_ulid(), range(1, 5));
        $result = $this->import_after_emptying(self::forge($files, function (array $document) use ($uuids): array {
            $when = $document['pieces'][0]['ajoutee_le'];
            foreach ($uuids as $i => $uuid) {
                $document['pieces'][] = [
                    'identifiant' => $uuid, 'fiche' => 'RNCP100', 'genre' => 'lien', 'nature' => 'other',
                    'intitule' => 'Lien ' . $i, 'ajoutee_le' => $when, 'adresse' => 'https://exemple.test/p' . $i,
                ];
                $document['situations'][0]['pieces'][] = ['piece' => $uuid, 'partie' => '', 'ajoutee_le' => $when];
            }

            return $document;
        }));
        $one = $DB->get_record('tool_vae_situation', ['uuid' => $this->written['one']->uuid], '*', MUST_EXIST);
        $this->assertTrue($DB->record_exists('tool_vae_situation', ['uuid' => $this->written['two']->uuid]));
        $this->assertSame(7, $DB->count_records('tool_vae_spiece'));
        $links = $DB->get_records('tool_vae_slink', ['situationid' => $one->id], 'id ASC');
        $this->assertCount(6, $links, 'The file, the link of the removed piece, four of the five new links.');
        $fifth = $DB->get_record('tool_vae_spiece', ['uuid' => $uuids[4]], '*', MUST_EXIST);
        $this->assertFalse($DB->record_exists('tool_vae_slink', ['pieceid' => $fifth->id]), 'The sixth active link is left.');
        $this->setUser($this->vaeiste);
        $list = (new situation_manager())->list((int) $this->vaeiste->id, 'RNCP100');
        $this->assertSame(piece_manager::MAX_ACTIVE, (int) $list[1]->pieces, 'Five pieces stand on the first.');
        $this->setAdminUser();
        $this->assertSame(1 + 1, $result->situationslaissees, 'The sixth link, the orphan annotation.');
        $this->assertSame(2 + 7 + 4 + 3, $result->situations);

        // The file said to be of another record: the two situations that cite it come without the link to it.
        $result = $this->import_after_emptying(
            self::forge($files, fn(array $d): array => self::with($d, ['pieces', 0, 'fiche'], 'RNCP400'))
        );
        $this->assertSame(2, $DB->count_records('tool_vae_situation'));
        $this->assertSame(2, $DB->count_records('tool_vae_spiece'));
        $this->assertSame(1, $DB->count_records('tool_vae_slink'), 'The link to the removed piece alone.');
        $this->assertSame(2 + 1, $result->situationslaissees, 'The two links, the orphan annotation.');
    }

    /**
     * Tour 1, point 5: a control character in a note, a date out of bounds on a piece, a link or a period, and a flood of
     * annotations refuse the file. (The text of a version and the draft are the vaeiste's free text, kept as typed: tour 2,
     * test_a_text_the_keyboard_accepts_makes_the_round_trip_and_a_nul_byte_does_not.)
     */
    public function test_control_characters_dates_out_of_bounds_and_a_flood_of_annotations_are_refused(): void {
        $this->write_dossier();
        $files = $this->build();

        $changes = [
            fn(array $d) => self::with($d, ['situations', 0, 'versions', 1, 'note'], "Une note\x07"),
            fn(array $d) => self::with($d, ['pieces', 0, 'ajoutee_le'], '2999-01-01T00:00:00Z'),
            fn(array $d) => self::with($d, ['situations', 0, 'pieces', 0, 'ajoutee_le'], '1999-12-31T00:00:00Z'),
            fn(array $d) => self::with($d, ['situations', 0, 'periode_debut'], '1799-01-01T00:00:00Z'),
            function (array $d): array {
                $model = $d['annotations'][0];
                $d['annotations'] = [];
                for ($i = 0; $i <= situation_archive::MAX_NOTE_LINES; $i++) {
                    $model['identifiant'] = identity_map::generate_ulid();
                    $d['annotations'][] = $model;
                }

                return $d;
            },
        ];
        foreach ($changes as $change) {
            $this->assert_refused(self::forge($files, $change));
        }
    }

    /**
     * Tour 1, point 5: a row in a state the archive has no word for is a coding error, never a false state in the file.
     */
    public function test_a_row_in_an_unknown_state_is_a_coding_error_not_a_false_state(): void {
        global $DB;
        $this->write_dossier();
        $DB->set_field('tool_vae_note', 'state', 'bizarre', ['uuid' => $this->written['n1']->uuid]);

        $this->expectException(\coding_exception::class);
        situation_archive::document((int) $this->vaeiste->id, true);
    }

    /**
     * Tour 2, C1: the text of a version and the draft in progress are the vaeiste's free text, kept as typed (the keyboard
     * filters no control character): what the site saved makes the round trip; a NUL byte, which no text holds, is refused.
     */
    public function test_a_text_the_keyboard_accepts_makes_the_round_trip_and_a_nul_byte_does_not(): void {
        global $DB;
        $this->write_dossier();
        $text = "Colle depuis Word\x0B avec un U+0085\u{0085} dedans.";
        $this->setUser($this->vaeiste);
        (new situation_manager())->save_version((int) $this->written['one']->id, $text);
        (new draft_manager())->save_working((int) $this->written['one']->draftid, "Brouillon\x0C en cours.");
        $files = $this->build();

        $this->import_after_emptying($files);

        $one = $DB->get_record('tool_vae_situation', ['uuid' => $this->written['one']->uuid], '*', MUST_EXIST);
        $fourth = $DB->get_field('tool_vae_version', 'content', ['draftid' => $one->draftid, 'number' => 4]);
        $this->assertSame($text, $fourth);
        $this->assertSame("Brouillon\x0C en cours.", $DB->get_field('tool_vae_draft', 'working', ['id' => $one->draftid]));

        $this->assert_refused(self::forge($files, function (array $d): array {
            $d['situations'][0]['versions'][0]['contenu'] = "Texte\0nul.";
            $d['situations'][0]['versions'][0]['empreinte'] = hash('sha256', "Texte\0nul.");

            return $d;
        }));
        $nul = fn(array $d): array => self::with($d, ['situations', 0, 'brouillon'], "Nul\0");
        $this->assert_refused(self::forge($files, $nul));
    }

    /**
     * Tour 2, C2: the date selector of the site gives midnight LOCAL time from 1900: the first day of 1900 in Paris falls
     * before 1900 in UTC and must make the round trip; the bounds are 1800 and 2200, in the schema and in the importer.
     */
    public function test_a_period_starting_on_the_first_day_of_1900_in_paris_makes_the_round_trip_and_1799_does_not(): void {
        global $DB;
        $this->write_dossier();
        $start = (new \DateTimeImmutable('1900-01-01 00:00:00', new \DateTimeZone('Europe/Paris')))->getTimestamp();
        $this->assertLessThan(-2208988800, $start, 'Before 1900 in UTC.');
        $this->setUser($this->vaeiste);
        $oneid = (int) $this->written['one']->id;
        (new situation_manager())->save_details($oneid, 'Accueil du matin, revu', $start, null, '', ['C01']);
        $files = $this->build();

        $this->import_after_emptying($files);

        $restoredstart = $DB->get_field('tool_vae_situation', 'periodstart', ['uuid' => $this->written['one']->uuid]);
        $this->assertSame($start, (int) $restoredstart);
        $this->assert_refused(self::forge($files, fn(array $d): array => self::with(
            $d,
            ['situations', 0, 'periode_debut'],
            '1799-12-31T23:00:00Z'
        )));
        $this->assert_refused(self::forge($files, fn(array $d): array => self::with(
            $d,
            ['situations', 0, 'periode_fin'],
            '2201-01-01T00:00:00Z'
        )));
    }

    /**
     * Tour 2, A1 and A2: a restored withdrawal against an annotation already RESTORED names an ACTIVE annotation, of the same
     * target, not yet withdrawn; the legitimate case still comes.
     */
    public function test_a_restored_withdrawal_names_an_active_annotation_of_the_same_target_not_yet_withdrawn(): void {
        global $DB;
        $this->write_dossier();
        $files = $this->build();
        $id = (int) $this->vaeiste->id;
        $author = '01JC8M3Q5R7T9V2X4Z6B8D0G9Z';
        $when = end(json_decode($files[situation_archive::PATH], true)['annotations'])['ecrit_le'];

        // A line already restored here, on a node of RNCP100.
        $site = function (string $uuid, string $rank, string $state, ?string $withdraws) use ($id, $author): void {
            global $DB;
            $DB->insert_record('tool_vae_note', (object) [
                'uuid' => $uuid, 'candidateid' => $id, 'target' => note_manager::TARGET_SELFPOS, 'targetuuid' => '',
                'fichecode' => 'RNCP100', 'slot' => 0, 'ficherang' => $rank, 'authorid' => null, 'authoruuid' => $author,
                'text' => $state === note_manager::ACTIVE ? 'Restauree.' : '', 'textformat' => FORMAT_PLAIN, 'state' => $state,
                'withdraws' => $withdraws, 'notehash' => str_repeat('0', 64), 'importedat' => time(), 'timecreated' => time(),
            ]);
        };
        // A line of the archive, on a node of RNCP100, by that author.
        $line = function (string $uuid, string $rank, ?string $retire) use ($author, $when): array {
            $entry = [
                'identifiant' => $uuid, 'cible' => 'autopositionnement', 'fiche' => 'RNCP100', 'emplacement' => 0, 'rang' => $rank,
                'auteur' => $author, 'texte' => $retire === null ? 'Restauree.' : '', 'format' => 'texte',
                'etat' => $retire === null ? 'active' : 'retiree',
            ];
            if ($retire !== null) {
                $entry['retire'] = $retire;
            }
            $entry['empreinte'] = self::note_hash($entry);
            $entry['ecrit_le'] = $when;

            return $entry;
        };
        $import = function (array $annotations) use ($files, $id): void {
            $this->setAdminUser();
            (new dossier_importer())->import_files($id, self::forge($files, function (array $d) use ($annotations): array {
                $d['annotations'] = $annotations;

                return $d;
            }));
        };

        // The legitimate case: the annotation restored earlier, its withdrawal by the same author on the same node comes.
        $this->empty_dossier();
        $u = identity_map::generate_ulid();
        $w = identity_map::generate_ulid();
        $site($u, 'C01', note_manager::ACTIVE, null);
        $import([$line($u, 'C01', null), $line($w, 'C01', $u)]);
        $this->assertTrue($DB->record_exists('tool_vae_note', ['uuid' => $w]));
        $this->setUser($this->vaeiste);
        $lines = (new note_manager())->for_target($id, note_manager::TARGET_SELFPOS, 'RNCP100', 0, 'C01');
        $standing = array_column($lines, 'standing', 'uuid');
        $this->assertFalse($standing[$u]);

        // A1: the archive says the annotation is on C02 and withdraws it there; here it is on C01. Left.
        $this->empty_dossier();
        $u = identity_map::generate_ulid();
        $w = identity_map::generate_ulid();
        $site($u, 'C01', note_manager::ACTIVE, null);
        $import([$line($u, 'C02', null), $line($w, 'C02', $u)]);
        $this->assertFalse($DB->record_exists('tool_vae_note', ['uuid' => $w]), 'Another target.');
        $this->assertSame(1, $DB->count_records('tool_vae_note'));

        // A1: the line named is a withdrawal here, said active by the archive. Left.
        $this->empty_dossier();
        $w0 = identity_map::generate_ulid();
        $w = identity_map::generate_ulid();
        $site($w0, 'C01', note_manager::WITHDRAWN, identity_map::generate_ulid());
        $import([$line($w0, 'C01', null), $line($w, 'C01', $w0)]);
        $this->assertFalse($DB->record_exists('tool_vae_note', ['uuid' => $w]), 'A withdrawal is named.');
        $this->assertSame(1, $DB->count_records('tool_vae_note'));

        // A2: the annotation is already withdrawn here by a restored line. A second withdrawal is left.
        $this->empty_dossier();
        $u = identity_map::generate_ulid();
        $w0 = identity_map::generate_ulid();
        $w = identity_map::generate_ulid();
        $site($u, 'C01', note_manager::ACTIVE, null);
        $site($w0, 'C01', note_manager::WITHDRAWN, $u);
        $import([$line($u, 'C01', null), $line($w, 'C01', $u)]);
        $this->assertFalse($DB->record_exists('tool_vae_note', ['uuid' => $w]), 'Already withdrawn.');
        $this->assertSame(2, $DB->count_records('tool_vae_note'));
    }

    public function test_the_document_is_read_with_the_competencies_switched_off(): void {
        $this->write_dossier();
        set_config('enabled', 0, \tool_vae\local\competency_gateway::SUBSYSTEM);

        $document = situation_archive::document((int) $this->vaeiste->id, true);

        $this->assertCount(2, $document['situations']);
        $this->assertCount(5, $document['annotations']);
    }
}
