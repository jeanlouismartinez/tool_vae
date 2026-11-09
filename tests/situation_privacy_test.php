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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tool_vae\local\backup;
use tool_vae\local\consent_manager;
use tool_vae\local\draft_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\note_manager;
use tool_vae\local\positioning_rules;
use tool_vae\local\purge;
use tool_vae\local\situation_manager;
use tool_vae\local\spiece_manager;
use tool_vae\local\validation_catalog;
use tool_vae\privacy\provider;

/**
 * The work situations, their pieces, the annotations and the consents, and the right to be forgotten (lot 1b).
 *
 * WHAT IS ASSERTED. That the seven tables are declared to the privacy API, every column with a sentence; that the vaeiste
 * receives their situations with versions, snapshots, tags, pieces (files included) and links, every annotation written
 * about them and every line of consent, while a manager receives the annotations they wrote and the lines of consent that
 * name them, and nobody is named; that the three paths of erasure of the vaeiste (their request, the purge of the dossier,
 * the deletion of the account caught up by the scheduled task) leave no row in the seven tables and no file in the area; that
 * an erased manager leaves their annotations, text intact, without a signature (authorid AND authoruuid null, restored lines
 * included) and that their lines of consent go, a withdrawal they wrote still holding; and that a vaeiste who only wrote
 * situations is not backed up, as the project owner decided for lot 1a.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(purge::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(backup::class)]
final class situation_privacy_test extends \advanced_testcase {
    /** @var string[] The seven tables of lot 1b. */
    private const TABLES = [
        'tool_vae_situation', 'tool_vae_sitversion', 'tool_vae_sitetag', 'tool_vae_spiece', 'tool_vae_slink',
        'tool_vae_consent', 'tool_vae_note',
    ];

    /** @var \stdClass The vaeiste. */
    private $vaeiste;

    /** @var \stdClass Follows the vaeiste and holds annotate in their context. */
    private $manager;

    /** @var \stdClass Follows the vaeiste too, holds annotate, wrote nothing. */
    private $stranger;

    /** @var \tool_vae_generator */
    private $generator;

    /** @var \stdClass[] What write_dossier() wrote, by name. */
    private $written = [];

    /**
     * RNCP100 (BC01 holding C01, C02; BC02 holding C03); the people; the preparation and the pieces open. No declaration:
     * the situations need none (D-1b-F), and the backup test needs none.
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

        $this->vaeiste = $datagenerator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->manager = $datagenerator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->stranger = $datagenerator->create_user(['firstname' => 'Sami', 'lastname' => 'Stranger']);
        $this->entitle($this->vaeiste);
        foreach ([$this->manager, $this->stranger] as $follower) {
            $this->follow($follower, $this->vaeiste);
        }
        accesslib_clear_all_caches_for_unit_testing();
        $this->setAdminUser();
    }

    /**
     * Give a vaeiste the rights of one in their own context.
     *
     * @param \stdClass $vaeiste
     */
    private function entitle(\stdClass $vaeiste): void {
        $datagenerator = $this->getDataGenerator();
        $own = $datagenerator->create_role();
        foreach (['declare', 'export', 'viewown', 'positionself', 'draftown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, \context_system::instance()->id, true);
        }
        set_role_contextlevels($own, [CONTEXT_USER]);
        role_assign($own, $vaeiste->id, \context_user::instance($vaeiste->id)->id);
    }

    /**
     * Make a person follow a vaeiste, with the right to annotate them.
     *
     * @param \stdClass $follower
     * @param \stdClass $vaeiste
     */
    private function follow(\stdClass $follower, \stdClass $vaeiste): void {
        $datagenerator = $this->getDataGenerator();
        $annotator = $datagenerator->create_role();
        foreach (['viewassigned', 'annotate'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $annotator, \context_system::instance()->id, true);
        }
        set_role_contextlevels($annotator, [CONTEXT_USER]);
        role_assign($annotator, $follower->id, \context_user::instance($vaeiste->id)->id);
    }

    /**
     * The bytes of a small PDF.
     *
     * @param string $name
     * @return string
     */
    private static function pdf(string $name = 'a'): string {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog /Title ($name) >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * A dossier of situations for a vaeiste, written by the manager who annotates it.
     *
     * Two situations on RNCP100: the first with two versions, a draft in progress, a file and a link; the second with a
     * title alone and the same file. The consent given to the manager; three annotations of the manager (two on the first
     * situation, one on the node C01), the third withdrawn. And, written as a restoration writes them: an annotation and
     * the withdrawal that takes it back, both named by the manager's ULID, and a line of consent named by it too.
     *
     * @param \stdClass $vaeiste
     * @param \stdClass $manager
     * @return \stdClass[] By name: one, two, file, link, n1, n2, n3, w3, r1, rw1, rc.
     */
    private function write_dossier(\stdClass $vaeiste, \stdClass $manager): array {
        global $DB;

        $this->setUser($vaeiste);
        $situations = new situation_manager();
        $spieces = new spiece_manager();
        $vaeisteid = (int) $vaeiste->id;

        $one = $this->generator->create_situation([
            'userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'title' => 'Accueil du matin', 'tags' => 'C01,C02',
            'setting' => 'EHPAD Les Tilleuls', 'periodstart' => 1756684800, 'periodend' => 1759276800,
            'text' => 'Premiere version du texte de la situation.',
        ]);
        $situations->save_version((int) $one->id, 'Deuxieme version du texte.');
        (new draft_manager())->save_working((int) $one->draftid, 'Un brouillon en cours.');
        $path = make_request_directory() . '/upload';
        file_put_contents($path, self::pdf('un'));
        $file = $spieces->add_file((int) $one->id, 'writing', 'Mon ecrit professionnel', $path, 'Page 2');
        $link = $spieces->add_link((int) $one->id, 'other', 'Le site du service', 'https://exemple.test/service', '');
        $two = $this->generator->create_situation(['userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'title' => 'Transmissions']);
        $spieces->attach((int) $two->id, (int) $file->id, 'Page 1');

        $this->generator->create_consent(['userid' => $vaeisteid, 'managerid' => $manager->id]);
        $note = fn(array $data) => $this->generator->create_note($data + [
            'authorid' => $manager->id, 'userid' => $vaeisteid, 'fichecode' => 'RNCP100',
        ]);
        $n1 = $note(['slot' => 1, 'text' => 'Une annotation sur la premiere situation.']);
        $n2 = $note(['target' => note_manager::TARGET_SELFPOS, 'ficherang' => 'C01', 'text' => 'Sur le noeud C01.']);
        $n3 = $note(['slot' => 1, 'text' => 'Une annotation a retirer.']);
        $this->setUser($manager);
        $w3 = (new note_manager())->withdraw((int) $n3->id);

        // Restored lines, as situation_archive::restore() writes them: no account, the ULID of the archive, importedat set.
        $ulid = identity_map::uuid_for(identity_map::TYPE_USER, (int) $manager->id);
        $now = time();
        $restored = fn(array $fields) => (object) ($fields + [
            'uuid' => identity_map::generate_ulid(), 'candidateid' => $vaeisteid, 'target' => note_manager::TARGET_SITUATION,
            'targetuuid' => (string) $one->uuid, 'fichecode' => 'RNCP100', 'slot' => 1, 'ficherang' => '',
            'authorid' => null, 'authoruuid' => $ulid, 'textformat' => FORMAT_PLAIN, 'importedat' => $now,
            'timecreated' => $now - 100,
        ]);
        $r1 = $restored([
            'text' => 'Annotation restauree.', 'state' => note_manager::ACTIVE, 'withdraws' => null,
            'notehash' => positioning_rules::hash(['situation', 'RNCP100', 1, '', 'Annotation restauree.']),
        ]);
        $r1->id = $DB->insert_record(note_manager::TABLE, $r1);
        $rw1 = $restored([
            'text' => '', 'state' => note_manager::WITHDRAWN, 'withdraws' => (string) $r1->uuid,
            'notehash' => positioning_rules::hash(['situation', 'RNCP100', 1, '', '', (string) $r1->uuid]),
            'timecreated' => $now - 50,
        ]);
        $rw1->id = $DB->insert_record(note_manager::TABLE, $rw1);
        $rc = (object) [
            'uuid' => identity_map::generate_ulid(), 'candidateid' => $vaeisteid, 'managerid' => null, 'manageruuid' => $ulid,
            'state' => consent_manager::GIVEN, 'importedat' => $now, 'timecreated' => $now - 200,
        ];
        $rc->id = $DB->insert_record(consent_manager::TABLE, $rc);

        $this->setAdminUser();

        return compact('one', 'two', 'file', 'link', 'n1', 'n2', 'n3', 'w3', 'r1', 'rw1', 'rc');
    }

    /**
     * What a request made by this person would carry for the user context of the vaeiste.
     *
     * @param \stdClass $user
     * @param \stdClass|null $vaeiste The dossier; the vaeiste of setUp() when null.
     * @return approved_contextlist
     */
    private function approval(\stdClass $user, ?\stdClass $vaeiste = null): approved_contextlist {
        return new approved_contextlist(\core_user::get_user($user->id), 'tool_vae', [
            \context_user::instance(($vaeiste ?? $this->vaeiste)->id)->id,
        ]);
    }

    /**
     * How many rows each of the seven tables holds for a vaeiste.
     *
     * @param int $candidateid
     * @return int[] By table.
     */
    private function rows_of(int $candidateid): array {
        global $DB;

        $situations = "situationid IN (SELECT id FROM {tool_vae_situation} WHERE candidateid = :c)";
        $pieces = "pieceid IN (SELECT id FROM {tool_vae_spiece} WHERE candidateid = :c2)";
        $where = [
            'tool_vae_situation' => 'candidateid = :c',
            'tool_vae_sitversion' => $situations,
            'tool_vae_sitetag' => 'candidateid = :c',
            'tool_vae_spiece' => 'candidateid = :c',
            'tool_vae_slink' => "$situations OR $pieces",
            'tool_vae_consent' => 'candidateid = :c',
            'tool_vae_note' => 'candidateid = :c',
        ];
        $counts = [];
        foreach ($where as $table => $select) {
            $params = ['c' => $candidateid] + (str_contains($select, ':c2') ? ['c2' => $candidateid] : []);
            $counts[$table] = $DB->count_records_select($table, $select, $params);
        }

        return $counts;
    }

    /**
     * How many files the area of the pieces of the situations holds for a vaeiste.
     *
     * @param int $candidateid
     * @return int
     */
    private function files_of(int $candidateid): int {
        global $DB;

        $context = \context_user::instance($candidateid, IGNORE_MISSING);
        if (!$context) {
            return 0;
        }

        return $DB->count_records_select(
            'files',
            "contextid = :ctx AND component = 'tool_vae' AND filearea = :area AND filename <> '.'",
            ['ctx' => $context->id, 'area' => spiece_manager::FILEAREA]
        );
    }

    /**
     * The data the privacy export hands a person for the dossier of the vaeiste, under the path of the situations.
     *
     * @param \stdClass $user
     * @param \stdClass|null $vaeiste
     * @return \stdClass|array The payload, or an empty array when nothing was exported there.
     */
    private function exported(\stdClass $user, ?\stdClass $vaeiste = null) {
        writer::reset();
        provider::export_user_data($this->approval($user, $vaeiste));
        $writer = writer::with_context(\context_user::instance(($vaeiste ?? $this->vaeiste)->id));

        return $writer->get_data([get_string('pluginname', 'tool_vae'), get_string('privacy:path:situations', 'tool_vae')]);
    }

    /**
     * The one backup of a vaeiste, in whoever's private files it sits, as the text of every file of the zip.
     *
     * @param int $candidateid
     * @return string
     */
    private function backup_text(int $candidateid): string {
        global $DB;

        $personne = identity_map::uuid_for(identity_map::TYPE_USER, $candidateid);
        $rows = $DB->get_records_select(
            'files',
            "component = 'user' AND filearea = 'private' AND " .
                $DB->sql_compare_text('source', 255) . ' = ' . $DB->sql_compare_text(':source', 255),
            ['source' => backup::SOURCE_PREFIX . $personne]
        );
        $this->assertCount(1, $rows, 'One backup of the vaeiste.');
        $path = get_file_storage()->get_file_instance(reset($rows))->copy_content_to_temp();
        $zip = new \ZipArchive();
        $zip->open($path);
        $text = '';
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $text .= $zip->getFromIndex($index);
        }
        $zip->close();
        unlink($path);

        return $text;
    }

    /**
     * A fresh backup of the vaeiste in the administrator's files, the earlier ones discarded.
     *
     * @param int $candidateid
     */
    private function back_up(int $candidateid): void {
        $this->setAdminUser();
        backup::discard(identity_map::uuid_for(identity_map::TYPE_USER, $candidateid));
        backup::save($candidateid, (int) get_admin()->id, false);
    }

    /**
     * Every key of an array, at every depth.
     *
     * @param mixed $data
     * @return string[]
     */
    private static function keys_of($data): array {
        $keys = [];
        foreach ((array) $data as $key => $value) {
            $keys[] = (string) $key;
            if (is_array($value) || is_object($value)) {
                $keys = array_merge($keys, self::keys_of($value));
            }
        }

        return $keys;
    }

    public function test_the_privacy_metadata_lists_the_seven_tables(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('tool_vae'));
        $expected = [
            'tool_vae_situation' => ['uuid', 'candidateid', 'fichecode', 'slot', 'draftid', 'title', 'periodstart', 'periodend',
                'setting', 'timecreated', 'timemodified'],
            'tool_vae_sitversion' => ['situationid', 'number', 'title', 'periodstart', 'periodend', 'setting', 'tags',
                'timecreated'],
            'tool_vae_sitetag' => ['situationid', 'candidateid', 'fichecode', 'ficherang', 'timecreated'],
            'tool_vae_spiece' => ['uuid', 'candidateid', 'fichecode', 'kind', 'nature', 'title', 'ext', 'mimetype', 'filesize',
                'sha256', 'url', 'timecreated', 'timeremoved'],
            'tool_vae_slink' => ['situationid', 'pieceid', 'part', 'timecreated', 'timeremoved'],
            'tool_vae_consent' => ['uuid', 'candidateid', 'managerid', 'manageruuid', 'state', 'importedat', 'timecreated'],
            'tool_vae_note' => ['uuid', 'candidateid', 'target', 'targetuuid', 'fichecode', 'slot', 'ficherang', 'authorid',
                'authoruuid', 'text', 'textformat', 'state', 'withdraws', 'notehash', 'importedat', 'timecreated'],
        ];

        foreach ($expected as $table => $columns) {
            $items = array_values(array_filter($collection->get_collection(), fn($item) => $item->get_name() === $table));
            $this->assertCount(1, $items, "$table is not declared once.");
            $this->assertEqualsCanonicalizing($columns, array_keys($items[0]->get_privacy_fields()), $table);
            $this->assertStringNotContainsString('[[', get_string($items[0]->get_summary(), 'tool_vae'), $table);
            foreach ($items[0]->get_privacy_fields() as $field => $string) {
                $this->assertStringNotContainsString('[[', get_string($string, 'tool_vae'), "$table.$field");
            }
        }
        $this->assertStringNotContainsString('[[', get_string('privacy:path:situations', 'tool_vae'));
    }

    public function test_the_vaeiste_the_manager_and_the_ulid_of_a_restored_line_are_pointed_at_the_dossier(): void {
        global $DB;
        $written = $this->write_dossier($this->vaeiste, $this->manager);
        $context = \context_user::instance($this->vaeiste->id)->id;
        $pointed = fn(\stdClass $user) => in_array(
            $context,
            array_map('intval', provider::get_contexts_for_userid((int) $user->id)->get_contextids()),
            true
        );
        $listed = function (\stdClass $user) use ($context): bool {
            $userlist = new userlist(\context::instance_by_id($context), 'tool_vae');
            provider::get_users_in_context($userlist);

            return in_array((int) $user->id, array_map('intval', $userlist->get_userids()), true);
        };

        $this->assertTrue($pointed($this->vaeiste));
        $this->assertTrue($pointed($this->manager));
        $this->assertFalse($pointed($this->stranger), 'Following and holding annotate is not a trace.');
        $this->assertTrue($listed($this->vaeiste));
        $this->assertTrue($listed($this->manager));
        $this->assertFalse($listed($this->stranger));

        // A person known here by the ULID of a restored line alone: the manager's own lines go, their restored ones stay.
        $DB->delete_records(note_manager::TABLE, ['authorid' => $this->manager->id]);
        $DB->delete_records(consent_manager::TABLE, ['managerid' => $this->manager->id]);
        $this->assertTrue($pointed($this->manager), 'Named by the ULID of a restored annotation.');
        $this->assertTrue($listed($this->manager));
        $DB->delete_records(note_manager::TABLE, ['id' => $written['r1']->id]);
        $DB->delete_records(note_manager::TABLE, ['id' => $written['rw1']->id]);
        $this->assertTrue($pointed($this->manager), 'Named by the ULID of a restored line of consent.');
        $this->assertTrue($listed($this->manager));
        $DB->delete_records(consent_manager::TABLE, ['id' => $written['rc']->id]);
        $this->assertFalse($pointed($this->manager));
        $this->assertFalse($listed($this->manager));
    }

    public function test_the_export_gives_the_vaeiste_everything_and_a_manager_his_own_annotations_and_consents(): void {
        $written = $this->write_dossier($this->vaeiste, $this->manager);

        // The vaeiste: everything, and nobody named.
        $data = $this->exported($this->vaeiste);
        $this->assertCount(2, $data->situations);
        $one = $data->situations[0];
        $this->assertSame((string) $written['one']->uuid, $one['reference']);
        $this->assertSame('RNCP100', $one['record']);
        $this->assertSame(1, $one['slot']);
        $this->assertSame('Accueil du matin', $one['title']);
        $this->assertSame('EHPAD Les Tilleuls', $one['setting']);
        $this->assertNotEmpty($one['periodstart']);
        $this->assertNotEmpty($one['periodend']);
        $this->assertSame(['C01', 'C02'], array_column($one['tags'], 'rank'));
        $this->assertNotEmpty($one['tags'][0]['tagged']);
        $this->assertSame('Un brouillon en cours.', $one['working']);
        $this->assertNotEmpty($one['workingat']);
        $this->assertSame([1, 2], array_column($one['versions'], 'number'));
        $this->assertSame('Premiere version du texte de la situation.', $one['versions'][0]['content']);
        $this->assertSame('Deuxieme version du texte.', $one['versions'][1]['content']);
        $this->assertSame('C01,C02', $one['versions'][1]['snapshot']['tags']);
        $this->assertSame('Accueil du matin', $one['versions'][1]['snapshot']['title']);
        $this->assertNotEmpty($one['versions'][1]['snapshot']['taken']);
        $this->assertSame(
            [(string) $written['file']->uuid, (string) $written['link']->uuid],
            array_column($one['pieces'], 'piece')
        );
        $this->assertSame('Page 2', $one['pieces'][0]['part']);
        $this->assertNotEmpty($one['pieces'][0]['linked']);
        $this->assertNull($one['pieces'][0]['unlinked']);
        $this->assertSame('Transmissions', $data->situations[1]['title']);
        $this->assertSame([], $data->situations[1]['versions']);
        $this->assertSame([(string) $written['file']->uuid], array_column($data->situations[1]['pieces'], 'piece'));

        $this->assertCount(2, $data->pieces);
        $file = $data->pieces[0];
        $this->assertSame((string) $written['file']->uuid, $file['reference']);
        $this->assertSame('file', $file['kind']);
        $this->assertSame('writing', $file['nature']);
        $this->assertSame('Mon ecrit professionnel', $file['title']);
        $this->assertSame('application/pdf', $file['mimetype']);
        $this->assertSame(hash('sha256', self::pdf('un')), $file['sha256']);
        $this->assertNull($file['url']);
        $this->assertNotEmpty($file['addedat']);
        $this->assertNull($file['removedat']);
        $this->assertSame('https://exemple.test/service', $data->pieces[1]['url']);
        // The bytes of the file, under the piece's own identifier.
        $writer = writer::with_context(\context_user::instance($this->vaeiste->id));
        $files = $writer->get_files([
            get_string('pluginname', 'tool_vae'), get_string('privacy:path:situations', 'tool_vae'), 'pieces',
            (string) $written['file']->uuid,
        ]);
        $this->assertCount(1, $files);
        $this->assertSame(self::pdf('un'), reset($files)->get_content());

        // Every annotation about them, the restored ones and the withdrawals included, in the order written.
        $this->assertCount(6, $data->annotations);
        $texts = array_column($data->annotations, 'text');
        $this->assertContains('Une annotation sur la premiere situation.', $texts);
        $this->assertContains('Sur le noeud C01.', $texts);
        $this->assertContains('Annotation restauree.', $texts);
        $byref = array_column($data->annotations, null, 'reference');
        $this->assertSame('situation', $byref[$written['n1']->uuid]['target']);
        $this->assertSame((string) $written['one']->uuid, $byref[$written['n1']->uuid]['situation']);
        $this->assertSame(1, $byref[$written['n1']->uuid]['slot']);
        $this->assertSame('active', $byref[$written['n1']->uuid]['state']);
        $this->assertNull($byref[$written['n1']->uuid]['restored']);
        $this->assertSame('selfpos', $byref[$written['n2']->uuid]['target']);
        $this->assertNull($byref[$written['n2']->uuid]['situation']);
        $this->assertSame('C01', $byref[$written['n2']->uuid]['rank']);
        $this->assertSame('withdrawn', $byref[$written['w3']->uuid]['state']);
        $this->assertSame((string) $written['n3']->uuid, $byref[$written['w3']->uuid]['withdraws']);
        $this->assertNotEmpty($byref[$written['r1']->uuid]['restored']);
        $this->assertSame((string) $written['r1']->notehash, $byref[$written['r1']->uuid]['sha256']);
        $this->assertNotEmpty($byref[$written['r1']->uuid]['written']);

        // Both lines of consent, the restored one said so; the manager is not named.
        $this->assertCount(2, $data->consents);
        $this->assertSame(['given', 'given'], array_column($data->consents, 'state'));
        $this->assertSame([true, false], array_map(fn($line) => $line['restored'] !== null, $data->consents));
        $this->assertNotEmpty($data->consents[0]['written']);
        foreach (self::keys_of($data) as $key) {
            $this->assertDoesNotMatchRegularExpression('/author|manager|user/i', $key, 'Nobody is named.');
        }

        // The manager: the annotations they wrote, under their account or under their ULID, the lines of consent that name
        // them, and nothing of the situations nor of the pieces.
        $theirs = $this->exported($this->manager);
        $this->assertObjectNotHasProperty('situations', $theirs);
        $this->assertObjectNotHasProperty('pieces', $theirs);
        $this->assertEqualsCanonicalizing(
            [$written['n1']->uuid, $written['n2']->uuid, $written['n3']->uuid, $written['w3']->uuid, $written['r1']->uuid,
                $written['rw1']->uuid],
            array_column($theirs->annotations, 'reference')
        );
        $this->assertCount(2, $theirs->consents);
        // Read from the writer of this export: exported() reset the one the vaeiste's export wrote into.
        $writer = writer::with_context(\context_user::instance($this->vaeiste->id));
        $this->assertEmpty($writer->get_files([
            get_string('pluginname', 'tool_vae'), get_string('privacy:path:situations', 'tool_vae'), 'pieces',
            (string) $written['file']->uuid,
        ]), 'The pieces are the vaeiste\'s.');

        // Someone who follows and may annotate but wrote nothing: nothing.
        $this->assertEmpty((array) $this->exported($this->stranger));
    }

    public function test_the_three_erasure_paths_empty_the_seven_tables_and_the_file_area(): void {
        global $DB;
        $other = $this->getDataGenerator()->create_user();
        $this->entitle($other);
        $this->follow($this->manager, $other);
        accesslib_clear_all_caches_for_unit_testing();
        $this->write_dossier($other, $this->manager);
        $kept = $this->rows_of((int) $other->id);
        $this->assertSame(2, $kept['tool_vae_situation'], 'The premise: the other vaeiste has a dossier too.');
        $this->assertSame(1, $this->files_of((int) $other->id));
        $full = [
            'tool_vae_situation' => 2, 'tool_vae_sitversion' => 2, 'tool_vae_sitetag' => 2, 'tool_vae_spiece' => 2,
            'tool_vae_slink' => 3, 'tool_vae_consent' => 2, 'tool_vae_note' => 6,
        ];
        $empty = array_fill_keys(self::TABLES, 0);
        $id = (int) $this->vaeiste->id;

        // 1. The request of the person (right to be forgotten).
        $this->write_dossier($this->vaeiste, $this->manager);
        $this->assertSame($full, $this->rows_of($id));
        $this->assertSame(1, $this->files_of($id));
        provider::delete_data_for_user($this->approval($this->vaeiste));
        $this->assertSame($empty, $this->rows_of($id));
        $this->assertSame(0, $this->files_of($id));
        $this->assertSame(0, $DB->count_records('tool_vae_draft', ['candidateid' => $id]));

        // 2. The purge of the dossier, the one place that knows what a dossier is made of.
        $this->write_dossier($this->vaeiste, $this->manager);
        $this->assertSame($full, $this->rows_of($id));
        purge::dossier($id);
        $this->assertSame($empty, $this->rows_of($id));
        $this->assertSame(0, $this->files_of($id));

        // 3. The deletion of the account, caught up by the scheduled purge when the observer did not run.
        $this->write_dossier($this->vaeiste, $this->manager);
        $this->assertSame($full, $this->rows_of($id));
        $DB->set_field('user', 'deleted', 1, ['id' => $id]);
        purge::orphan_sections();
        $this->assertSame($empty, $this->rows_of($id));
        $this->assertSame(0, $this->files_of($id));

        // The other vaeiste's dossier is exactly as it was, files included.
        $this->assertSame($kept, $this->rows_of((int) $other->id));
        $this->assertSame(1, $this->files_of((int) $other->id));
        // And nothing stays in any of the seven tables but theirs.
        foreach (self::TABLES as $table) {
            $this->assertSame($kept[$table], $DB->count_records($table), $table);
        }
    }

    public function test_an_erased_manager_leaves_his_annotations_unsigned_and_his_consent_lines_gone(): void {
        global $DB;
        $written = $this->write_dossier($this->vaeiste, $this->manager);
        // A line of consent to someone else, which stays.
        $this->generator->create_consent(['userid' => $this->vaeiste->id, 'managerid' => $this->stranger->id]);
        $this->setUser($this->vaeiste);
        $standing = fn() => array_column((new note_manager())->for_candidate((int) $this->vaeiste->id), 'standing', 'uuid');
        $before = $standing();
        $this->assertFalse($before[$written['n3']->uuid], 'The premise: the third annotation is withdrawn.');
        $this->assertFalse(
            $before[$written['r1']->uuid],
            'The premise: the restored annotation is withdrawn by its restored line.'
        );
        $this->assertTrue($before[$written['n1']->uuid]);
        $texts = $DB->get_records_menu(note_manager::TABLE, [], 'id', 'id, text');
        $this->setAdminUser();

        // 1. The request of the manager, on the dossier of the vaeiste.
        provider::delete_data_for_user($this->approval($this->manager));

        $this->assertSame(6, $DB->count_records(note_manager::TABLE), 'Every annotation stays.');
        $this->assertSame($texts, $DB->get_records_menu(note_manager::TABLE, [], 'id', 'id, text'), 'The text is untouched.');
        $this->assertSame(0, $DB->count_records_select(note_manager::TABLE, 'authorid IS NOT NULL'));
        $this->assertSame(0, $DB->count_records_select(note_manager::TABLE, 'authoruuid IS NOT NULL'), 'Restored lines too.');
        $this->assertSame(0, $DB->count_records(consent_manager::TABLE, ['managerid' => $this->manager->id]));
        $this->assertSame(0, $DB->count_records_select(consent_manager::TABLE, 'manageruuid IS NOT NULL'));
        $this->assertSame(1, $DB->count_records(consent_manager::TABLE), 'The line to someone else stays.');
        $this->assertSame((int) $this->stranger->id, (int) $DB->get_field(consent_manager::TABLE, 'managerid', []));
        // The situations and the pieces are the vaeiste's: untouched.
        $this->assertSame(2, $DB->count_records(situation_manager::TABLE));
        $this->assertSame(2, $DB->count_records(spiece_manager::TABLE));
        // A withdrawal still takes back what the same hand wrote, erased or not: two erased authors read as one hand.
        $this->setUser($this->vaeiste);
        $this->assertSame($before, $standing(), 'Nothing stands that did not, nothing falls that stood.');
        $this->assertFalse(consent_manager::stands((int) $this->vaeiste->id, (int) $this->manager->id));
        $this->setAdminUser();

        // 2. The deletion of the manager's account (the path of the observer), with a second manager.
        $second = $this->getDataGenerator()->create_user();
        $this->follow($second, $this->vaeiste);
        accesslib_clear_all_caches_for_unit_testing();
        $this->generator->create_consent(['userid' => $this->vaeiste->id, 'managerid' => $second->id]);
        $note = $this->generator->create_note([
            'authorid' => $second->id, 'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'slot' => 2,
            'text' => 'Par le second responsable.',
        ]);
        $ulid = identity_map::uuid_for(identity_map::TYPE_USER, (int) $second->id);
        $DB->insert_record(consent_manager::TABLE, (object) [
            'uuid' => identity_map::generate_ulid(), 'candidateid' => $this->vaeiste->id, 'managerid' => null,
            'manageruuid' => $ulid, 'state' => consent_manager::GIVEN, 'importedat' => time(), 'timecreated' => time(),
        ]);
        $DB->set_field(note_manager::TABLE, 'authoruuid', $ulid, ['id' => $written['r1']->id]);
        $DB->set_field(note_manager::TABLE, 'authoruuid', $ulid, ['id' => $written['rw1']->id]);

        purge::dossier((int) $second->id);

        $this->assertSame(7, $DB->count_records(note_manager::TABLE));
        $this->assertSame('Par le second responsable.', $DB->get_field(note_manager::TABLE, 'text', ['id' => $note->id]));
        $this->assertSame(0, $DB->count_records_select(note_manager::TABLE, 'authorid IS NOT NULL'));
        $this->assertSame(0, $DB->count_records_select(note_manager::TABLE, 'authoruuid IS NOT NULL'));
        $this->assertSame(1, $DB->count_records(consent_manager::TABLE), 'Only the line to someone else.');
        $this->assertSame(0, $DB->count_records(consent_manager::TABLE, ['managerid' => $second->id]));
        $this->assertSame(0, $DB->count_records_select(consent_manager::TABLE, 'manageruuid IS NOT NULL'));
        $this->assertFalse(consent_manager::stands((int) $this->vaeiste->id, (int) $second->id));
        $this->setUser($this->vaeiste);
        $this->assertFalse($standing()[$written['r1']->uuid], 'The restored withdrawal still holds.');
    }

    /**
     * A manager named by a line of consent ALONE - no annotation - leaves the backups of the vaeiste too: the line goes, and
     * the backup, which carried their opaque identifier in accords[], is redone without it, on the three paths (the deletion
     * of their account for a line written here, the same for a restored line named by ULID, their request on the dossier).
     */
    public function test_an_erased_manager_named_by_a_consent_line_alone_leaves_the_backups_of_the_vaeiste(): void {
        global $DB;
        $vaeisteid = (int) $this->vaeiste->id;
        $this->generator->create_declaration([
            'userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Une situation vecue.',
        ]);

        // 1. A line written here, the account of the manager deleted (purge::dossier()).
        $this->generator->create_consent(['userid' => $vaeisteid, 'managerid' => $this->manager->id]);
        $ulid = identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->manager->id);
        $this->back_up($vaeisteid);
        // Booleans, not the text: a failure would otherwise print the whole zip in the log.
        $this->assertTrue(str_contains($this->backup_text($vaeisteid), $ulid), 'The premise: the backup names the manager.');
        purge::dossier((int) $this->manager->id);
        $this->assertSame(0, $DB->count_records(consent_manager::TABLE));
        $this->assertFalse(str_contains($this->backup_text($vaeisteid), $ulid), 'The backup was not redone.');

        // 2. A restored line alone, named by ULID, the account deleted (strip_signatory()).
        $second = $this->getDataGenerator()->create_user();
        $ulid2 = identity_map::uuid_for(identity_map::TYPE_USER, (int) $second->id);
        $DB->insert_record(consent_manager::TABLE, (object) [
            'uuid' => identity_map::generate_ulid(), 'candidateid' => $vaeisteid, 'managerid' => null,
            'manageruuid' => $ulid2, 'state' => consent_manager::GIVEN, 'importedat' => time(), 'timecreated' => time(),
        ]);
        $this->back_up($vaeisteid);
        $this->assertTrue(str_contains($this->backup_text($vaeisteid), $ulid2));
        purge::dossier((int) $second->id);
        $this->assertSame(0, $DB->count_records(consent_manager::TABLE));
        $this->assertFalse(str_contains($this->backup_text($vaeisteid), $ulid2), 'strip_signatory(): the backup was not redone.');

        // 3. A line written here, the request of the manager on the dossier (strip_attestant()).
        $third = $this->getDataGenerator()->create_user();
        $this->follow($third, $this->vaeiste);
        accesslib_clear_all_caches_for_unit_testing();
        $this->generator->create_consent(['userid' => $vaeisteid, 'managerid' => $third->id]);
        $ulid3 = identity_map::uuid_for(identity_map::TYPE_USER, (int) $third->id);
        $this->back_up($vaeisteid);
        $this->assertTrue(str_contains($this->backup_text($vaeisteid), $ulid3));
        provider::delete_data_for_user($this->approval($third));
        $this->assertSame(0, $DB->count_records(consent_manager::TABLE));
        $this->assertFalse(str_contains($this->backup_text($vaeisteid), $ulid3), 'strip_attestant(): the backup was not redone.');
    }

    /**
     * The bulk path of the privacy API, and the purge of a whole context, reach the seven tables as the other paths do.
     */
    public function test_the_bulk_path_and_the_whole_context_empty_the_seven_tables_too(): void {
        global $DB;
        $id = (int) $this->vaeiste->id;
        $context = \context_user::instance($id);
        $empty = array_fill_keys(self::TABLES, 0);

        // The manager alone among the people: unsigned annotations, consents gone, the dossier untouched.
        $this->write_dossier($this->vaeiste, $this->manager);
        provider::delete_data_for_users(new approved_userlist($context, 'tool_vae', [$this->manager->id]));
        $this->assertSame(2, $DB->count_records(situation_manager::TABLE));
        $this->assertSame(6, $DB->count_records(note_manager::TABLE));
        $this->assertSame(0, $DB->count_records_select(note_manager::TABLE, 'authorid IS NOT NULL OR authoruuid IS NOT NULL'));
        $this->assertSame(0, $DB->count_records(consent_manager::TABLE));

        // The vaeiste among the people: the dossier goes.
        provider::delete_data_for_users(new approved_userlist($context, 'tool_vae', [$this->manager->id, $id]));
        $this->assertSame($empty, $this->rows_of($id));
        $this->assertSame(0, $this->files_of($id));

        // The whole context.
        $this->write_dossier($this->vaeiste, $this->manager);
        provider::delete_data_for_all_users_in_context($context);
        $this->assertSame($empty, $this->rows_of($id));
        $this->assertSame(0, $this->files_of($id));
    }

    public function test_a_deleted_account_leaves_no_row(): void {
        global $DB;
        $written = $this->write_dossier($this->vaeiste, $this->manager);
        // Rows left without their situation or their piece by an interrupted purge: taken too.
        $now = time();
        $DB->insert_record('tool_vae_sitetag', (object) [
            'situationid' => 999999, 'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C03',
            'timecreated' => $now,
        ]);
        $DB->insert_record('tool_vae_sitversion', (object) [
            'situationid' => 999999, 'number' => 1, 'title' => 'Orpheline', 'setting' => '', 'tags' => '', 'timecreated' => $now,
        ]);
        $DB->insert_record('tool_vae_slink', (object) [
            'situationid' => 999999, 'pieceid' => (int) $written['file']->id, 'part' => '', 'timecreated' => $now,
        ]);
        $DB->insert_record('tool_vae_slink', (object) [
            'situationid' => (int) $written['one']->id, 'pieceid' => 999999, 'part' => '', 'timecreated' => $now,
        ]);

        delete_user($this->vaeiste);
        purge::orphan_sections();

        foreach (self::TABLES as $table) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
        $this->assertSame(0, $this->files_of((int) $this->vaeiste->id));
        $this->assertSame(0, $DB->count_records_select(
            'files',
            "component = 'tool_vae' AND filearea = :area AND filename <> '.'",
            ['area' => spiece_manager::FILEAREA]
        ));
    }

    /**
     * T15, point E: a manager's account deleted without the observer running (its purge failed) is caught up by the scheduled
     * task: their annotations lose their signature, their lines of consent go, and the backups of the vaeistes named are
     * redone without their identifier - exactly what the path of the account deleted does. Run twice, nothing changes.
     */
    public function test_a_manager_account_deleted_without_the_observer_is_caught_up_by_the_scheduled_task(): void {
        global $DB;
        $vaeisteid = (int) $this->vaeiste->id;
        $this->generator->create_declaration([
            'userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Une situation vecue.',
        ]);
        $one = $this->generator->create_situation([
            'userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'title' => 'Accueil du matin', 'text' => 'Mon texte.',
        ]);
        // The manager annotates (two lines, one withdrawn); the stranger has a consent and nothing else.
        $this->generator->create_consent(['userid' => $vaeisteid, 'managerid' => $this->manager->id]);
        $this->generator->create_consent(['userid' => $vaeisteid, 'managerid' => $this->stranger->id]);
        $n1 = $this->generator->create_note([
            'authorid' => $this->manager->id, 'userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'slot' => 1, 'text' => 'Une.',
        ]);
        $n2 = $this->generator->create_note([
            'authorid' => $this->manager->id, 'userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'slot' => 1, 'text' => 'Deux.',
        ]);
        $this->setUser($this->manager);
        (new note_manager())->withdraw((int) $n2->id);
        $ulids = [
            identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->manager->id),
            identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->stranger->id),
        ];
        $this->back_up($vaeisteid);
        foreach ($ulids as $ulid) {
            $this->assertTrue(str_contains($this->backup_text($vaeisteid), $ulid), 'The premise: the backup names them.');
        }
        $this->setUser($this->vaeiste);
        $standing = fn() => array_column((new note_manager())->for_candidate($vaeisteid), 'standing', 'uuid');
        $before = $standing();
        $this->assertFalse($before[$n2->uuid], 'The premise: the second annotation is withdrawn.');
        $this->setAdminUser();

        // The accounts are marked deleted in the table alone: no event, no observer, no purge.
        $DB->set_field('user', 'deleted', 1, ['id' => $this->manager->id]);
        $DB->set_field('user', 'deleted', 1, ['id' => $this->stranger->id]);
        $this->assertSame(3, $DB->count_records_select(note_manager::TABLE, 'authorid IS NOT NULL'), 'Two, and the withdrawal.');
        $this->assertSame(2, $DB->count_records(consent_manager::TABLE));

        purge::orphan_sections();

        $this->assertSame(3, $DB->count_records(note_manager::TABLE), 'Every annotation stays.');
        $texts = $DB->get_fieldset_select(note_manager::TABLE, 'text', '1 = 1');
        sort($texts);
        $this->assertSame(['', 'Deux.', 'Une.'], $texts, 'The text is untouched.');
        $this->assertSame(0, $DB->count_records_select(note_manager::TABLE, 'authorid IS NOT NULL OR authoruuid IS NOT NULL'));
        $this->assertSame(0, $DB->count_records(consent_manager::TABLE), 'The lines of consent of both are gone.');
        $this->assertSame(1, $DB->count_records(situation_manager::TABLE), 'The vaeiste\'s own material stays.');
        $this->assertSame((string) $one->uuid, $DB->get_field(situation_manager::TABLE, 'uuid', ['id' => $one->id]));
        foreach ($ulids as $ulid) {
            $this->assertFalse(str_contains($this->backup_text($vaeisteid), $ulid), 'The backup was redone without them.');
        }
        $this->setUser($this->vaeiste);
        $this->assertSame($before, $standing(), 'The withdrawal of the erased hand still holds.');
        $this->setAdminUser();

        // A second pass changes nothing: neither the lines nor the backups.
        $private = fn() => $DB->get_records_select(
            'files',
            "component = 'user' AND filearea = 'private'",
            [],
            'id',
            'id, contenthash'
        );
        $notes = $DB->get_records(note_manager::TABLE, [], 'id');
        $backups = $private();
        purge::orphan_sections();
        $this->assertEquals($notes, $DB->get_records(note_manager::TABLE, [], 'id'));
        $this->assertEquals($backups, $private());
    }

    /**
     * T15 tour 1 (point E): the catch-up of the scheduled task does for an account that is gone EXACTLY what dossier() does
     * for an erased person towards the other dossiers, the part by ULID included: the restored lines that name the person by
     * the ULID of their identity map entry lose it (annotations) or go (consents), the register rows and the identity map
     * entry go, and the backups are redone without the ULID. An account found by its identity entry alone - nothing written
     * here under its id - is caught too. Run twice, nothing changes.
     */
    public function test_a_manager_account_deleted_without_the_observer_loses_its_ulid_too(): void {
        global $DB;
        $vaeisteid = (int) $this->vaeiste->id;
        $this->generator->create_declaration([
            'userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Une situation vecue.',
        ]);
        $one = $this->generator->create_situation([
            'userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'title' => 'Accueil du matin', 'text' => 'Mon texte.',
        ]);
        // The manager: a local annotation, a restored annotation and a restored consent under their ULID, a register row
        // for an archive of their own. The stranger: restored lines under their ULID alone, nothing written under their id.
        $this->generator->create_consent(['userid' => $vaeisteid, 'managerid' => $this->manager->id]);
        $this->generator->create_note([
            'authorid' => $this->manager->id, 'userid' => $vaeisteid, 'fichecode' => 'RNCP100', 'slot' => 1, 'text' => 'Locale.',
        ]);
        $ulids = [
            'manager' => identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->manager->id),
            'stranger' => identity_map::uuid_for(identity_map::TYPE_USER, (int) $this->stranger->id),
        ];
        $now = time();
        foreach ($ulids as $who => $ulid) {
            $DB->insert_record(note_manager::TABLE, (object) [
                'uuid' => identity_map::generate_ulid(), 'candidateid' => $vaeisteid, 'target' => note_manager::TARGET_SITUATION,
                'targetuuid' => (string) $one->uuid, 'fichecode' => 'RNCP100', 'slot' => 1, 'ficherang' => '', 'authorid' => null,
                'authoruuid' => $ulid, 'text' => 'Restauree de ' . $who, 'textformat' => FORMAT_PLAIN,
                'state' => note_manager::ACTIVE, 'withdraws' => null,
                'notehash' => positioning_rules::hash(['situation', 'RNCP100', 1, '', 'Restauree de ' . $who]),
                'importedat' => $now, 'timecreated' => $now - 100,
            ]);
            $DB->insert_record(consent_manager::TABLE, (object) [
                'uuid' => identity_map::generate_ulid(), 'candidateid' => $vaeisteid, 'managerid' => null, 'manageruuid' => $ulid,
                'state' => consent_manager::GIVEN, 'importedat' => $now, 'timecreated' => $now - 200,
            ]);
        }
        $DB->insert_record(exporter::REGISTER, (object) [
            'archiveuuid' => identity_map::generate_ulid(), 'sha256' => hash('sha256', 'manifeste'),
            'candidateuuid' => $ulids['manager'], 'userid' => $this->manager->id, 'timecreated' => $now,
        ]);
        $this->back_up($vaeisteid);
        foreach ($ulids as $ulid) {
            $this->assertTrue(str_contains($this->backup_text($vaeisteid), $ulid), 'The premise: the backup names them.');
        }
        $identities = fn() => $DB->count_records_select(
            'tool_vae_identity',
            "itemtype = 'user' AND itemid IN (:m, :s)",
            ['m' => $this->manager->id, 's' => $this->stranger->id]
        );
        $this->assertSame(2, $identities());
        $this->assertSame(1, $DB->count_records(exporter::REGISTER, ['candidateuuid' => $ulids['manager']]));
        $this->setAdminUser();

        $DB->set_field('user', 'deleted', 1, ['id' => $this->manager->id]);
        $DB->set_field('user', 'deleted', 1, ['id' => $this->stranger->id]);
        purge::orphan_sections();

        $this->assertSame(3, $DB->count_records(note_manager::TABLE), 'Every annotation stays.');
        $this->assertSame(0, $DB->count_records_select(note_manager::TABLE, 'authorid IS NOT NULL OR authoruuid IS NOT NULL'));
        $this->assertSame(0, $DB->count_records(consent_manager::TABLE), 'The local line and the two restored ones.');
        $this->assertSame(0, $identities(), 'The identity map entries are gone: the ULIDs point at nobody.');
        $this->assertSame(0, $DB->count_records(exporter::REGISTER, ['candidateuuid' => $ulids['manager']]));
        $this->assertSame(1, $DB->count_records(situation_manager::TABLE), 'The vaeiste\'s own material stays.');
        foreach ($ulids as $ulid) {
            $this->assertFalse(str_contains($this->backup_text($vaeisteid), $ulid), 'The backup was redone without them.');
        }

        // A second pass changes nothing: neither the lines nor the backups.
        $private = fn() => $DB->get_records_select(
            'files',
            "component = 'user' AND filearea = 'private'",
            [],
            'id',
            'id, contenthash'
        );
        $notes = $DB->get_records(note_manager::TABLE, [], 'id');
        $backups = $private();
        purge::orphan_sections();
        $this->assertEquals($notes, $DB->get_records(note_manager::TABLE, [], 'id'));
        $this->assertEquals($backups, $private());
    }

    public function test_a_vaeiste_with_situations_only_has_no_backup_as_the_porteur_decided(): void {
        global $DB;
        $this->write_dossier($this->vaeiste, $this->manager);
        $this->setAdminUser();
        $admin = (int) get_admin()->id;

        $this->assertSame(0, backup::save_all($admin), 'No declaration: not backed up.');
        try {
            backup::save((int) $this->vaeiste->id, $admin, false);
            $this->fail('A dossier without a declaration was backed up.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorexportempty', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records_select(
            'files',
            "component = 'user' AND filearea = 'private' AND " . $DB->sql_like('source', ':source'),
            ['source' => $DB->sql_like_escape(backup::SOURCE_PREFIX) . '%']
        ));

        // With a declaration, the backup exists and carries the situations.
        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Une situation vecue.',
        ]);
        $this->setAdminUser();
        $this->assertSame(1, backup::save_all($admin));
        $file = backup::save((int) $this->vaeiste->id, $admin, false);
        $path = $file->copy_content_to_temp();
        $zip = new \ZipArchive();
        $zip->open($path);
        $this->assertNotFalse($zip->locateName('situations.json'));
        $zip->close();
        unlink($path);
    }
}
