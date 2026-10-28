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
use tool_vae\local\backup;
use tool_vae\local\dossier_importer;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\purge;
use tool_vae\privacy\provider;

/**
 * Tests for the lasting backup of a dossier - lot 8 bis, asked by the project owner on
 * 12 October 2026.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\backup::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\purge::class)]
final class backup_test extends \advanced_testcase {
    /** @var int The role that lets a user declare. */
    private $candidaterole;

    /** @var int The competency of the record seeded in setUp(). */
    private $competencyid;

    /**
     * A record, and a role to declare with.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $generator->get_plugin_generator('tool_vae')->create_source([
            'fichecode' => 'RNCP36832',
            'ficherang' => 'C03',
        ]);
        $this->competencyid = (int) $DB->get_field('competency', 'id', ['idnumber' => 'RNCP36832-C03'], MUST_EXIST);

        $this->candidaterole = $generator->create_role();
        foreach (['declare', 'viewown'] as $capability) {
            assign_capability(
                'tool/vae:' . $capability,
                CAP_ALLOW,
                $this->candidaterole,
                \context_system::instance()->id,
                true
            );
        }
    }

    /**
     * A candidate with one declaration.
     *
     * @return \stdClass The candidate.
     */
    private function candidate_with_a_claim(): \stdClass {
        $candidate = $this->getDataGenerator()->create_user();
        role_assign($this->candidaterole, $candidate->id, \context_user::instance($candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($candidate);
        (new evidence_manager())->declare_activity((int) $candidate->id, (object) [
            'competencyid' => $this->competencyid,
            'periodstart' => 1756684800,
            'periodend' => 1782777600,
            'settingtype' => 'ehpad',
            'situation' => 'Accompagnement au lever.',
            'situationformat' => FORMAT_PLAIN,
        ]);

        return $candidate;
    }

    /**
     * Give an account the right to back up, as the administrator would: a role of its own, on the system.
     *
     * Both rights a backup takes (backup::may_hold()): the capability of this plugin and the core's right to write evidence on a
     * candidate's behalf. Only the first when $withcore is false, the state of an account that was given importdossier alone.
     *
     * @param int $userid
     * @param bool $withcore Whether the core's moodle/competency:userevidencemanage goes with it.
     * @return int The role.
     */
    private function grant_the_right_to_back_up(int $userid, bool $withcore = true): int {
        $role = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:importdossier', CAP_ALLOW, $role, \context_system::instance()->id, true);
        if ($withcore) {
            assign_capability('moodle/competency:userevidencemanage', CAP_ALLOW, $role, \context_system::instance()->id, true);
        }
        role_assign($role, $userid, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        return $role;
    }

    /**
     * The backups held in a user's private files.
     *
     * @param int $userid
     * @return \stored_file[]
     */
    private static function backups_of(int $userid): array {
        $files = get_file_storage()->get_directory_files(
            \context_user::instance($userid)->id,
            'user',
            'private',
            0,
            backup::FILEPATH,
            false,
            false
        );

        return array_values($files);
    }

    /**
     * Every file inside a backup, as text.
     *
     * @param \stored_file $file
     * @return string[] Path inside the archive => content.
     */
    private static function contents_of(\stored_file $file): array {
        $path = make_request_directory() . '/sauvegarde.zip';
        $file->copy_content_to($path);

        $zip = new \ZipArchive();
        $zip->open($path);
        $contents = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $contents[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $contents;
    }

    /**
     * A signatory erased from a dossier is erased from its backups, and every owner keeps one.
     *
     * A backup is kept without time limit. Left as it was, it would hold for good what the erasure
     * removed from the dossier: the signatory's account in correspondances[], their ULID, their job
     * title and their statement.
     */
    public function test_erasing_a_signatory_takes_them_out_of_every_backup(): void {
        global $DB;

        $candidate = $this->candidate_with_a_claim();
        $attestant = $this->getDataGenerator()->create_user();
        $now = time();
        $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => identity_map::generate_ulid(),
            'evidenceid' => $DB->get_field('tool_vae_evidence', 'id', ['candidateid' => $candidate->id], MUST_EXIST),
            'attestantid' => $attestant->id,
            'attestantrole' => 'Cadre de santé, EHPAD Les Tilleuls',
            'statement' => 'Je soussignée Claire Dupont atteste…',
            'attestedat' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $attestant->id,
        ]);

        // Two backups for one owner, one for another: an account that was given the right to back up, which is what keeps a
        // backup written again when the signatory is erased (a stranger's file with the mark is erased and not replaced).
        $this->setAdminUser();
        $admin = (int) get_admin()->id;
        $other = (int) $this->getDataGenerator()->create_user()->id;
        $this->grant_the_right_to_back_up($other);
        backup::save((int) $candidate->id, $admin);
        backup::save((int) $candidate->id, $admin);
        backup::save((int) $candidate->id, $other);

        // The premise: a backup does carry the signature.
        $signatory = identity_map::uuid_for(identity_map::TYPE_USER, (int) $attestant->id);
        $this->assertStringContainsString($signatory, implode("\n", self::contents_of(self::backups_of($admin)[0])));

        provider::delete_data_for_user(new approved_contextlist(
            \core_user::get_user($attestant->id),
            'tool_vae',
            [\context_user::instance($candidate->id)->id]
        ));

        foreach ([$admin, $other] as $ownerid) {
            $backups = self::backups_of($ownerid);
            $this->assertCount(1, $backups, 'The owner lost their backup, or kept the old one beside the new.');

            $contents = self::contents_of($backups[0]);
            $text = implode("\n", $contents);
            $this->assertStringNotContainsString($signatory, $text);
            $this->assertStringNotContainsString('Cadre de santé', $text);
            $this->assertStringNotContainsString('Claire Dupont', $text);

            $preuves = array_filter($contents, function (string $path): bool {
                return strpos($path, 'preuves/') === 0;
            }, ARRAY_FILTER_USE_KEY);
            $this->assertCount(1, $preuves);
            foreach ($preuves as $content) {
                $preuve = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                $people = array_filter($preuve['correspondances'], function (array $entry): bool {
                    return $entry['type'] === 'personne';
                });
                $this->assertSame([(string) $candidate->id], array_values(array_column($people, 'valeur')));

                // The claim and the trace of a withdrawn attestation remain.
                $this->assertSame('retiree', $preuve['attestations'][0]['statut']);
            }
        }
    }

    /**
     * Deleting a local account strips the imported signatures naming it by ULID, backups included.
     *
     * The imported signature has no attestantid: only the identity map entry ties it to the
     * account. The purge deletes that entry, so it must strip the signature first, or no later
     * erasure can ever find it again.
     */
    public function test_deleting_an_imported_signatory_strips_them_everywhere(): void {
        global $DB;

        $candidate = $this->candidate_with_a_claim();
        $signatory = $this->getDataGenerator()->create_user();
        $ulid = identity_map::uuid_for(identity_map::TYPE_USER, (int) $signatory->id);
        $now = time();
        $attestationid = $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => identity_map::generate_ulid(),
            'evidenceid' => $DB->get_field('tool_vae_evidence', 'id', ['candidateid' => $candidate->id], MUST_EXIST),
            'attestantid' => null,
            'attestantuuid' => $ulid,
            'attestantrole' => 'Cadre de santé, EHPAD Les Tilleuls',
            'statement' => 'Je soussignée Claire Dupont atteste…',
            'attestedat' => $now,
            'importedat' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => 0,
        ]);

        $this->setAdminUser();
        $admin = (int) get_admin()->id;
        backup::save((int) $candidate->id, $admin);

        // The premise: the backup does carry the imported signature.
        $this->assertStringContainsString('Claire Dupont', implode("\n", self::contents_of(self::backups_of($admin)[0])));

        delete_user($DB->get_record('user', ['id' => $signatory->id], '*', MUST_EXIST));

        $attestation = $DB->get_record('tool_vae_attest', ['id' => $attestationid], '*', MUST_EXIST);
        $this->assertNull($attestation->attestantuuid);
        $this->assertNull($attestation->attestantrole);
        $this->assertNull($attestation->statement);
        $this->assertNull($attestation->attestedat);
        $this->assertSame('rgpd', $attestation->withdrawnreason);

        $backups = self::backups_of($admin);
        $this->assertCount(1, $backups, 'The owner lost their backup, or kept the old one beside the new.');
        $text = implode("\n", self::contents_of($backups[0]));
        $this->assertStringNotContainsString($ulid, $text);
        $this->assertStringNotContainsString('Cadre de santé', $text);
        $this->assertStringNotContainsString('Claire Dupont', $text);
    }

    /**
     * Deleting an account that signed nothing leaves every backup as it was.
     *
     * A refresh replaces every backup of a dossier; one triggered for nothing would rewrite
     * the backups of the whole site each time an account is closed.
     */
    public function test_deleting_an_account_that_signed_nothing_refreshes_no_backup(): void {
        global $DB;

        $candidate = $this->candidate_with_a_claim();
        $stranger = $this->getDataGenerator()->create_user();
        identity_map::uuid_for(identity_map::TYPE_USER, (int) $stranger->id);

        $this->setAdminUser();
        $admin = (int) get_admin()->id;
        $before = backup::save((int) $candidate->id, $admin);

        delete_user($DB->get_record('user', ['id' => $stranger->id], '*', MUST_EXIST));

        $backups = self::backups_of($admin);
        $this->assertCount(1, $backups);
        $this->assertEquals($before->get_id(), $backups[0]->get_id(), 'The backup was replaced for nothing.');
    }

    public function test_a_backup_lands_in_private_files_survives_and_rebuilds_the_dossier(): void {
        $candidate = $this->candidate_with_a_claim();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $candidate->id);

        $this->setAdminUser();
        $admin = get_admin();
        $file = backup::save((int) $candidate->id, (int) $admin->id);

        $this->assertSame('user', $file->get_component());
        $this->assertSame('private', $file->get_filearea());
        $this->assertSame(backup::FILEPATH, $file->get_filepath());
        $this->assertStringStartsWith('dossier-' . $personne . '-', $file->get_filename());
        $this->assertCount(1, self::backups_of((int) $admin->id));

        // The backup is the normative archive: the reimport reads it as it is.
        $path = make_request_directory() . '/sauvegarde.zip';
        $file->copy_content_to($path);
        purge::dossier((int) $candidate->id);

        // An erasure takes the backup with it, wherever it sits.
        $this->assertCount(0, self::backups_of((int) $admin->id));

        $result = (new dossier_importer())->import((int) $candidate->id, $path);
        $this->assertSame(1, $result->declarations);
        $this->assertSame($personne, identity_map::uuid_for(identity_map::TYPE_USER, (int) $candidate->id));
    }

    /**
     * T-75: a copy renamed and moved by its owner is still found, and an erasure still removes it.
     *
     * The audit of 14 October 2026 saw it in the code: the copies were found by folder and file
     * name only, and the owner of private files can change both.
     */
    public function test_a_renamed_and_moved_backup_is_still_found_and_erased(): void {
        $candidate = $this->candidate_with_a_claim();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $candidate->id);

        $this->setAdminUser();
        $file = backup::save((int) $candidate->id, (int) get_admin()->id);
        $this->assertSame(backup::SOURCE_PREFIX . $personne, $file->get_source());

        $file->rename('/ailleurs/', 'quelconque.zip');
        $this->assertCount(0, self::backups_of((int) get_admin()->id), 'It is out of the folder, by name.');

        $this->assertSame(1, backup::discard($personne), 'The mark still finds it.');
        $this->assertFalse(
            get_file_storage()->file_exists(
                $file->get_contextid(),
                'user',
                'private',
                0,
                '/ailleurs/',
                'quelconque.zip'
            )
        );
    }

    /**
     * Audit 4 (26 October 2026): a copy taken before the mark existed is found once the upgrade has marked it, not by its name.
     *
     * T-75 kept the folder and the name as a second way of finding it. Any account names a file in its own private files as it
     * likes, so the name proves nothing the day a stranger can read a candidate's ULID in an archive handed to a jury.
     * Before: files_of() read `filepath = /vae/ AND filename LIKE dossier-<ULID>-%` beside the mark, and found it.
     */
    public function test_a_backup_without_the_mark_is_found_once_the_upgrade_has_marked_it_and_not_by_its_name(): void {
        $candidate = $this->candidate_with_a_claim();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $candidate->id);

        $this->setAdminUser();
        $file = backup::save((int) $candidate->id, (int) get_admin()->id);
        // The state of a copy of before T-75.
        $file->set_source('');

        $this->assertSame(0, backup::discard($personne), 'The name proves nothing.');
        $this->assertSame(1, backup::mark_unmarked(), 'The upgrade marks it, in the files of an account that may hold it.');
        $this->assertSame(0, backup::mark_unmarked(), 'A second run finds nothing to mark.');
        $this->assertSame(1, backup::discard($personne), 'Marked, it is found.');
    }

    /**
     * Audit 4: the upgrade marks a backup in the files of an entitled account only, and only a file named as a backup is.
     */
    public function test_the_upgrade_leaves_alone_a_file_that_is_not_a_backup_this_platform_made(): void {
        $candidate = $this->candidate_with_a_claim();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $candidate->id);
        $stranger = $this->getDataGenerator()->create_user();
        $fs = get_file_storage();
        $planted = function (int $userid, string $filename) use ($fs) {
            return $fs->create_file_from_string([
                'contextid' => \context_user::instance($userid)->id, 'component' => 'user', 'filearea' => 'private',
                'itemid' => 0, 'filepath' => backup::FILEPATH, 'filename' => $filename, 'userid' => $userid,
            ], 'pas une sauvegarde');
        };

        $name = 'dossier-' . $personne . '-' . identity_map::generate_ulid() . '.zip';
        $homonym = $planted((int) $stranger->id, $name);
        $strangename = 'dossier-' . identity_map::generate_ulid() . '-' . identity_map::generate_ulid() . '.zip';
        $unknown = $planted((int) get_admin()->id, $strangename);
        $other = $planted((int) get_admin()->id, 'notes.zip');

        $this->assertSame(0, backup::mark_unmarked());
        foreach ([$homonym, $unknown, $other] as $file) {
            $fresh = $fs->get_file_by_id($file->get_id());
            $this->assertEmpty((string) $fresh->get_source(), $file->get_filename());
        }

        // The administrator's own file, named as a backup is, for a candidate this platform knows: marked.
        $mine = $planted((int) get_admin()->id, $name);
        $this->assertSame(1, backup::mark_unmarked());
        $this->assertSame(backup::SOURCE_PREFIX . $personne, $fs->get_file_by_id($mine->get_id())->get_source());
    }

    /**
     * Audit 4: a file a stranger named after a candidate's ULID receives no backup, and is theirs still.
     *
     * U read the ULID of C in an archive C handed to a jury, and named a file of U's own private files after it. Before: the
     * next refresh - C removing a piece - found it by its name, took it for a backup, erased it and wrote the whole dossier of
     * C in its place, for U to download without time limit.
     */
    public function test_a_homonymous_file_planted_by_a_stranger_receives_no_backup(): void {
        $candidate = $this->candidate_with_a_claim();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $candidate->id);
        $stranger = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $admin = (int) get_admin()->id;
        backup::save((int) $candidate->id, $admin);

        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($stranger->id)->id, 'component' => 'user', 'filearea' => 'private',
            'itemid' => 0, 'filepath' => backup::FILEPATH,
            'filename' => 'dossier-' . $personne . '-' . identity_map::generate_ulid() . '.zip', 'userid' => $stranger->id,
        ], 'pas une sauvegarde');

        $this->setUser($candidate);
        $this->assertSame(1, backup::refresh((int) $candidate->id), 'Only the administrator is served.');

        $kept = self::backups_of((int) $stranger->id);
        $this->assertCount(1, $kept);
        $this->assertSame('pas une sauvegarde', $kept[0]->get_content(), 'What a stranger named so is theirs, and stays so.');
        $admins = self::backups_of($admin);
        $this->assertCount(1, $admins);
        $this->assertStringContainsString('Accompagnement au lever.', implode("\n", self::contents_of($admins[0])));
    }

    /**
     * Audit 4: the mark is no proof either - the core copies the name a browser announced into it - so the owner must
     * hold the right.
     *
     * Before: refresh() wrote a backup into the files of every owner it found, with no question put to the owner.
     */
    public function test_a_file_that_carries_the_mark_in_the_files_of_a_stranger_is_erased_and_replaced_by_nothing(): void {
        global $DB;

        $candidate = $this->candidate_with_a_claim();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $candidate->id);
        $stranger = $this->getDataGenerator()->create_user();
        $this->setAdminUser();
        $admin = (int) get_admin()->id;
        backup::save((int) $candidate->id, $admin);

        $forged = get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($stranger->id)->id, 'component' => 'user', 'filearea' => 'private',
            'itemid' => 0, 'filepath' => backup::FILEPATH, 'filename' => 'quelconque.zip', 'userid' => $stranger->id,
            'source' => backup::SOURCE_PREFIX . $personne,
        ], 'pas une sauvegarde');
        $this->assertSame(backup::SOURCE_PREFIX . $personne, $forged->get_source(), 'The premise: it carries the mark.');

        $this->setUser($candidate);
        $this->assertSame(1, backup::refresh((int) $candidate->id), 'Only the administrator is served.');

        $this->assertCount(0, self::backups_of((int) $stranger->id), 'Erased, and nothing written in its place.');
        $this->assertSame(
            0,
            $DB->count_records_select(
                'files',
                'contextid = :contextid AND component = :component AND filearea = :filearea AND filename <> :dot',
                [
                    'contextid' => \context_user::instance($stranger->id)->id,
                    'component' => 'user',
                    'filearea' => 'private',
                    'dot' => '.',
                ]
            )
        );
        $this->assertCount(1, self::backups_of($admin));
    }

    /**
     * Audit 37, the other door: an account holding importdossier alone receives no backup of a dossier the page refuses it.
     *
     * The mark is no proof (the core copies the name a browser announced into it), so what makes an account a holder is its
     * right - and backup.php asks two: importdossier AND moodle/competency:userevidencemanage on the candidate. may_hold() asked
     * the first only: U, holding importdossier without the core's right, put a file carrying the mark of C in its private files,
     * and the next refresh triggered by C wrote the whole list of C's pieces there, for a page that refuses U the backup of C.
     */
    public function test_an_account_holding_importdossier_alone_receives_no_backup_of_a_dossier_the_page_refuses_it(): void {
        $candidate = $this->candidate_with_a_claim();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $candidate->id);
        $holder = (int) $this->getDataGenerator()->create_user()->id;
        $role = $this->grant_the_right_to_back_up($holder, false);
        $this->setAdminUser();
        $admin = (int) get_admin()->id;
        backup::save((int) $candidate->id, $admin);

        $this->assertTrue(backup::may_hold($holder), 'The right in general: the premise.');
        $this->assertFalse(backup::may_hold($holder, (int) $candidate->id), 'Not for this dossier: the core right is missing.');
        $this->assertTrue(backup::may_hold($admin, (int) $candidate->id));
        $this->assertFalse(backup::may_hold($holder, 2000000000), 'An id that is no account is held by nobody.');

        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($holder)->id, 'component' => 'user', 'filearea' => 'private',
            'itemid' => 0, 'filepath' => backup::FILEPATH, 'filename' => 'quelconque.zip', 'userid' => $holder,
            'source' => backup::SOURCE_PREFIX . $personne,
        ], 'pas une sauvegarde');

        $this->setUser($candidate);
        $this->assertSame(1, backup::refresh((int) $candidate->id), 'Only the administrator is served.');
        $this->assertCount(0, self::backups_of($holder), 'Erased, and nothing written in its place.');
        $this->assertCount(1, self::backups_of($admin));

        // With the core's right as well, the same account is a holder, and is served.
        assign_capability('moodle/competency:userevidencemanage', CAP_ALLOW, $role, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertTrue(backup::may_hold($holder, (int) $candidate->id));
        backup::save((int) $candidate->id, $holder);
        $this->assertSame(2, backup::refresh((int) $candidate->id));
        $this->assertCount(1, self::backups_of($holder));
    }

    /**
     * Audit 4: an account that holds the right keeps receiving the refreshed backup, and one that lost it does not.
     */
    public function test_an_account_that_lost_the_right_to_hold_a_backup_receives_no_fresh_one(): void {
        $candidate = $this->candidate_with_a_claim();
        $holder = (int) $this->getDataGenerator()->create_user()->id;
        $role = $this->grant_the_right_to_back_up($holder);
        $this->setAdminUser();
        backup::save((int) $candidate->id, $holder);

        $this->assertSame(1, backup::refresh((int) $candidate->id));
        $this->assertCount(1, self::backups_of($holder), 'Entitled: served.');

        role_unassign($role, $holder, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertSame(0, backup::refresh((int) $candidate->id));
        $this->assertCount(0, self::backups_of($holder), 'No longer entitled: its copy goes, and no other is written.');
    }

    /**
     * T-78: a backup whose owner this platform no longer knows is deleted by the reconciliation.
     *
     * The identity entry is removed by a direct write, the state an erasure interrupted between
     * its transaction and the deletion of the files leaves. A backup of a candidate still known,
     * and a copy without the mark, are not touched.
     */
    public function test_orphaned_backups_are_deleted_and_the_others_are_kept(): void {
        global $DB;

        $gone = $this->candidate_with_a_claim();
        $kept = $this->candidate_with_a_claim();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $gone->id);

        $this->setAdminUser();
        $admin = (int) get_admin()->id;
        backup::save((int) $gone->id, $admin);
        $stay = backup::save((int) $kept->id, $admin);
        $DB->delete_records('tool_vae_identity', ['itemtype' => identity_map::TYPE_USER, 'uuid' => $personne]);

        $this->assertSame(1, backup::discard_orphans());
        $this->assertSame(0, backup::discard_orphans(), 'A second run finds nothing.');

        $left = self::backups_of($admin);
        $this->assertCount(1, $left);
        $this->assertEquals($stay->get_id(), $left[0]->get_id());
    }

    /**
     * T-75: the mark of one candidate does not reach the copy of another.
     */
    public function test_discarding_one_candidates_backups_leaves_another_untouched(): void {
        $first = $this->candidate_with_a_claim();
        $second = $this->candidate_with_a_claim();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, (int) $first->id);

        $this->setAdminUser();
        backup::save((int) $first->id, (int) get_admin()->id);
        $other = backup::save((int) $second->id, (int) get_admin()->id);
        $other->rename('/ailleurs/', 'autre.zip');

        $this->assertSame(1, backup::discard($personne));
        $this->assertTrue(
            get_file_storage()->file_exists($other->get_contextid(), 'user', 'private', 0, '/ailleurs/', 'autre.zip')
        );
    }

    public function test_the_uninstall_backs_up_every_dossier_first(): void {
        global $CFG;

        $this->candidate_with_a_claim();
        $this->candidate_with_a_claim();

        require_once($CFG->dirroot . '/admin/tool/vae/db/uninstall.php');
        $this->setAdminUser();
        xmldb_tool_vae_uninstall();

        $this->assertCount(2, self::backups_of((int) get_admin()->id));
    }

    public function test_from_the_command_line_the_main_administrator_receives_them(): void {
        global $CFG;

        $this->candidate_with_a_claim();

        require_once($CFG->dirroot . '/admin/tool/vae/db/uninstall.php');
        $this->setUser(0);
        xmldb_tool_vae_uninstall();

        $backups = self::backups_of((int) get_admin()->id);
        $this->assertCount(1, $backups);

        // Where nobody is logged in the label of the competency is read all the same: the core's API refuses the user 0 and
        // gave `intitule: null`, in the very archive the uninstall leaves behind (audit 17, which found it on the way).
        $referentiel = json_decode(self::contents_of($backups[0])['referentiel.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Competency of an imported record', $referentiel['fiches'][0]['competences'][0]['intitule']);
    }

    /**
     * Audit 17: the backup an uninstall takes first does not need the competencies to be switched on.
     *
     * Before: exporter::referentiel() read the label through the core's API, whose first act is require_enabled(): with the
     * subsystem off, every backup threw `competenciesarenotenabled`, and the uninstall - which lets a failing backup stop it -
     * could not be done until the administrator switched the competencies back on, without being told.
     */
    public function test_the_uninstall_backs_up_every_dossier_with_the_competencies_switched_off(): void {
        global $CFG;

        $this->candidate_with_a_claim();
        $this->candidate_with_a_claim();
        set_config('enabled', 0, \tool_vae\local\competency_gateway::SUBSYSTEM);

        require_once($CFG->dirroot . '/admin/tool/vae/db/uninstall.php');
        $this->setAdminUser();
        xmldb_tool_vae_uninstall();

        $backups = self::backups_of((int) get_admin()->id);
        $this->assertCount(2, $backups);
        $referentiel = json_decode(self::contents_of($backups[0])['referentiel.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Competency of an imported record', $referentiel['fiches'][0]['competences'][0]['intitule']);
    }

    /**
     * A row the schema refuses no longer refuses the backup of the page: it is taken, and the faulty line is reported.
     *
     * DECISION OF THE PROJECT OWNER, 27 OCTOBER 2026. The page used to refuse (errorexportfailed) a dossier the import would
     * refuse, while the backup of an uninstall and the refresh that follows an erasure went through; all three now behave
     * alike. The backup is taken, the exporter handed to save() says which line is faulty so that the page can warn the
     * administrator, and the notice inside the backup carries the mention. The import stays strict: the backup is not
     * reimportable as it stands, which the screen no longer promises without reserve. The whole story is in
     * export_warning_test.
     */
    public function test_a_faulty_row_does_not_refuse_the_backup_of_the_page_nor_the_acts_that_rewrite_one(): void {
        global $DB;

        $candidate = $this->candidate_with_a_claim();
        // The rank tool_lp lets an administrator type, and an earlier version recorded: outside ^[A-Z0-9]{1,8}$.
        $DB->set_field('tool_vae_evidence', 'ficherang', 'c7', ['candidateid' => $candidate->id]);
        $this->setAdminUser();
        $admin = (int) get_admin()->id;

        $exporter = new exporter();
        $file = backup::save((int) $candidate->id, $admin, true, $exporter);
        $this->assertCount(1, self::backups_of($admin), 'The backup of the page is taken.');
        $this->assertCount(1, $exporter->warnings());
        $this->assertSame('RNCP36832-c7', $exporter->warnings()[0]['declaration']);
        $this->assertStringContainsString(
            get_string('exportreadmewarning', 'tool_vae'),
            self::contents_of($file)['LISEZMOI.txt']
        );

        backup::save((int) $candidate->id, $admin, false);
        $this->assertCount(2, self::backups_of($admin), 'The uninstall keeps what it can write.');

        $this->setUser($candidate);
        $this->assertSame(1, backup::refresh((int) $candidate->id), 'The refresh was stopped by the faulty row.');
        $this->assertCount(1, self::backups_of($admin));
    }

    /**
     * Audit 20: a backup says, in its notice, what it does not carry - so that nobody takes it for a copy of the database.
     *
     * A backup is never for its author: it says so too. The reimport restores the declarations, the signatures and the
     * sections; the deposits, the opinions, the requests, the earlier versions, the journal and the preparation stay behind.
     */
    public function test_a_backup_says_what_it_does_not_carry(): void {
        $candidate = $this->candidate_with_a_claim();

        $this->setAdminUser();
        $notice = self::contents_of(backup::save((int) $candidate->id, (int) get_admin()->id))['LISEZMOI.txt'];

        $this->assertStringContainsString(get_string('exportreadmeabsent', 'tool_vae'), $notice);
        $this->assertStringContainsString(get_string('exportreadmeabsentauthor', 'tool_vae'), $notice);
    }

    /**
     * The strings of the plugin in one language, read from its own file.
     *
     * Not through the string manager: the French pack is not installed in the test site, and the manager then answers in English
     * whatever language is asked, which would make every French check read English text.
     *
     * @param string $lang en or fr.
     * @return string[] Identifier => text.
     */
    private static function strings_of(string $lang): array {
        $string = [];
        include(__DIR__ . '/../lang/' . $lang . '/tool_vae.php');

        return $string;
    }

    /**
     * The screen that offers the backup promises its reimport with the reserves the import has, and the screens after it do not.
     *
     * The real refusals, tested in dossier_importer_test and import_conflicts_test: after an uninstall and a reinstall the
     * identity table is empty, so the first export, copy or backup that names a competency gives it a new number
     * (errorarchivecompetencyheld), and the first declaration of a vaeiste gives their account a new identifier
     * (errorarchiveidentityheld): the backup taken before is then refused whole. The README carries these reserves for this
     * very backup; the screen that the administrator reads before deciding to count on it carried the one about the format
     * only. The words are written out here on purpose.
     *
     * The message that follows a backup on a living site is left as it was: there the numbers cannot have been lost, and a
     * backup made with a warning has its own message that does not promise the reimport.
     */
    public function test_the_screen_of_the_backup_gives_the_reserves_of_its_reimport(): void {
        $expected = [
            'en' => [
                'Nor can it be reimported if, since the plugin was reinstalled, a dossier has already left the site on the same '
                    . 'competencies (archive, Word or OpenDocument copy, backup)',
                'or if the account of the candidate has already been given a new identifier (with its first declaration, export, '
                    . 'copy or backup)',
                'the import is refused as a whole, and the message says why',
            ],
            'fr' => [
                'Elle ne se réimporte pas non plus si, depuis la réinstallation du plugin, un dossier est déjà sorti du site sur '
                    . 'les mêmes compétences (archive, copie Word ou OpenDocument, sauvegarde)',
                'ou si le compte du vaeiste a déjà reçu un nouvel identifiant (dès sa première déclaration, son premier export, '
                    . 'sa première copie ou sa première sauvegarde)',
                'l\'import est refusé en entier, et le message dit pourquoi',
            ],
        ];

        foreach (['en', 'fr'] as $lang) {
            $intro = self::strings_of($lang)['backupdossierintro'];
            foreach ($expected[$lang] as $words) {
                $this->assertStringContainsString($words, $intro, "$lang backupdossierintro");
            }
            // The reserve of the format is still there, and comes first.
            $format = $lang === 'fr' ? 'ne pourra pas être réimportée telle quelle' : 'cannot be reimported as it stands';
            $this->assertLessThan(strpos($intro, $expected[$lang][0]), strpos($intro, $format), "$lang order of the reserves");
        }
    }

    public function test_only_who_may_reimport_may_back_up(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(backup::can_backup());

        $this->setAdminUser();
        $this->assertTrue(backup::can_backup());
    }

    /**
     * Audit 37: backing a dossier up takes the two rights reimporting it takes, as backup.php now asks.
     *
     * The capability of this plugin alone read the whole dossier of any candidate into the private files of its holder,
     * who is refused dossier.php and import_dossier.php for that candidate: the core's right to write evidence on their
     * behalf (moodle/competency:userevidencemanage) is asked there and was not here.
     */
    public function test_backing_up_takes_the_same_two_capabilities_as_reimporting(): void {
        $candidate = $this->candidate_with_a_claim();
        $holder = $this->getDataGenerator()->create_user();
        // The plugin's capability alone: the core's right is what this test adds below.
        $role = $this->grant_the_right_to_back_up((int) $holder->id, false);
        $system = \context_system::instance();

        $this->setUser($holder);
        $this->assertTrue(backup::can_backup(), 'The right in general.');
        $this->assertFalse(backup::can_backup((int) $candidate->id), 'Not for this dossier: the core\'s right is missing.');
        try {
            backup::require_can_backup((int) $candidate->id);
            $this->fail('The page would have taken the backup.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }

        assign_capability('moodle/competency:userevidencemanage', CAP_ALLOW, $role, $system->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(backup::can_backup((int) $candidate->id));
        $this->assertSame(
            \context_user::instance((int) $candidate->id)->id,
            backup::require_can_backup((int) $candidate->id)->id
        );

        // An id that is no account gets the very refusal an account the holder may not back up gets.
        $this->assertFalse(backup::can_backup(2000000000));

        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertFalse(backup::can_backup((int) $candidate->id));
    }
}
