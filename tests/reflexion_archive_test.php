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

use tool_vae\local\dossier_importer;
use tool_vae\local\draft_manager;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\importer;
use tool_vae\local\purge;
use tool_vae\local\reflexion_overview;
use tool_vae\local\schema_validator;

/**
 * The reflective journal in the archive its author exports (T-14, lot 2).
 *
 * WHAT IS ASSERTED. That reflexion.json holds the latest SAVED version of each entry - never the draft - and says in the file
 * itself that it is no dossier; that it is valid against its schema and listed in the manifest; that it is absent when nothing
 * is saved; that it enters ONLY an archive the author exports themself - an administrator's or a manager's export carries
 * nothing of it; that the import brings the entries back to their numbers as version 1 without ever overwriting; and that
 * a file the schema refuses, or a number written twice, refuses the whole archive.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\reflexion_overview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_importer::class)]
final class reflexion_archive_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /**
     * A record, a candidate with a declaration, and the journal open.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        set_config('reflectionopen', 1, 'tool_vae');
        $this->setAdminUser();

        (new importer())->import((object) [
            'fichecode' => 'RS00000', 'fichetype' => 'rs', 'name' => 'Fiche RS00000',
            'sourceurl' => 'https://www.data.gouv.fr/', 'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0', 'attribution' => 'Personne',
        ], importer::rows_from_csv("rang,intitule,description,bloc\nC01,Une,,\nC02,Deux,,\n"));

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $role = $generator->create_role();
        foreach (['declare', 'draftown', 'export'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $role, \context_system::instance()->id, true);
        }
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->candidate);
        $competency = $DB->get_field('competency', 'id', ['idnumber' => 'RS00000-C02'], MUST_EXIST);
        (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => (int) $competency, 'periodstart' => 1756684800, 'periodend' => 1782777600,
            'settingtype' => 'ehpad', 'situation' => 'Une situation.', 'situationformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * Write an entry of the journal and save versions of it, as the candidate.
     *
     * @param int $slot
     * @param string ...$versions
     * @return \stdClass
     */
    private function write(int $slot, string ...$versions): \stdClass {
        $this->setUser($this->candidate);
        $manager = new draft_manager();
        $draft = $manager->section(
            (int) $this->candidate->id,
            draft_manager::DOCTYPE_REFLEXION,
            draft_manager::REFLEXION_SECTION,
            $slot
        );
        foreach ($versions as $text) {
            $manager->save_version((int) $draft->id, $text);
        }

        return $draft;
    }

    /**
     * The files of the archive the CURRENT user builds for the candidate.
     *
     * @param bool|null $forauthor Whether the archive is for the candidate, said in so many words; null leaves it to the
     *        session, which is the candidate's own when they are the current user.
     * @return string[]
     */
    private function build(?bool $forauthor = null): array {
        global $DB;

        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');

        return (new exporter())->build(
            (int) $this->candidate->id,
            $claims,
            identity_map::generate_ulid(),
            time(),
            false,
            true,
            $forauthor
        );
    }

    /**
     * Rewrite reflexion.json in an archive, digest included.
     *
     * @param string[] $files
     * @param callable $change Receives the decoded document, returns it changed.
     * @return string[]
     */
    private static function forge(array $files, callable $change): array {
        $content = json_encode(
            $change(json_decode($files[exporter::REFLEXION_PATH], true, 512, JSON_THROW_ON_ERROR)),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $files[exporter::REFLEXION_PATH] = $content;
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['fichiers'] as $index => $entry) {
            if ($entry['chemin'] === exporter::REFLEXION_PATH) {
                $manifest['fichiers'][$index]['octets'] = strlen($content);
                $manifest['fichiers'][$index]['sha256'] = hash('sha256', $content);
            }
        }
        $files['manifeste.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $files;
    }

    /**
     * Import as the administrator and expect a refusal that writes nothing.
     *
     * @param string[] $files
     */
    private function assert_refused_after_destroy(array $files): void {
        global $DB;

        purge::dossier((int) $this->candidate->id);
        $this->setAdminUser();
        try {
            (new dossier_importer())->import_files((int) $this->candidate->id, $files);
            $this->fail('The archive was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorarchivereflexion', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('tool_vae_draft'));
        $this->assertSame(0, $DB->count_records('tool_vae_evidence'));
    }

    /**
     * An archive the candidate exports with two entries written.
     *
     * @return string[]
     */
    private function archive_with_entries(): array {
        $this->write(1, 'Premier jet.', 'Texte enregistré de la première entrée.');
        $this->write(3, 'Une troisième entrée.');

        return $this->build();
    }

    public function test_an_archive_without_a_saved_entry_carries_no_journal(): void {
        $files = $this->build();
        $draft = $this->write(2);
        (new draft_manager())->save_working((int) $draft->id, 'Brouillon jamais enregistré.');
        $files = $this->build();

        $this->assertArrayNotHasKey(exporter::REFLEXION_PATH, $files);
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('schema_reflexion', $manifest);
    }

    public function test_the_authors_own_export_holds_the_latest_saved_version_and_says_what_it_is(): void {
        $draft = $this->write(2, 'Version enregistrée.');
        (new draft_manager())->save_working((int) $draft->id, 'Brouillon jamais enregistré.');
        $files = $this->archive_with_entries();

        $document = json_decode($files[exporter::REFLEXION_PATH], true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($document['officiel']);
        $this->assertStringContainsString('Reflective journal of the candidate', $document['avertissement']);
        $this->assertSame([1, 2, 3], array_column($document['entrees'], 'numero'));
        $rows = array_column($document['entrees'], null, 'numero');
        $this->assertSame('Texte enregistré de la première entrée.', $rows[1]['texte']);
        $this->assertSame(2, $rows[1]['version']);
        $this->assertStringNotContainsString('Brouillon jamais enregistré', json_encode($document));
    }

    public function test_the_file_is_valid_against_its_schema_and_listed_in_the_manifest(): void {
        $files = $this->archive_with_entries();

        $document = json_decode($files[exporter::REFLEXION_PATH], false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], (new schema_validator())->validate($document, 'reflexion.schema.json'));

        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(reflexion_overview::SCHEMA_VERSION, $manifest['schema_reflexion']);
        $this->assertContains(exporter::REFLEXION_PATH, array_column($manifest['fichiers'], 'chemin'));
        $this->assertArrayHasKey('schema/reflexion.schema.json', $files);
    }

    public function test_an_export_made_by_anyone_else_carries_nothing_of_it(): void {
        $this->write(1, 'Un texte tres personnel.');

        foreach ([get_admin(), $this->getDataGenerator()->create_user()] as $user) {
            $this->setUser($user);
            $files = $this->build();
            $this->assertArrayNotHasKey(exporter::REFLEXION_PATH, $files);
            $this->assertStringNotContainsString('tres personnel', implode('', $files));
        }
        $this->setUser($this->candidate);
        $this->assertArrayHasKey(exporter::REFLEXION_PATH, $this->build());
    }

    /**
     * Audit 5: whether an archive carries the journal is said by the caller, not read from the session.
     *
     * Before: build() asked $USER, which is the candidate in the request where they remove a piece - the backup refreshed
     * there came out with the journal, into the private files of the administrator. Said "not for the author", the archive
     * a candidate's own session builds carries none.
     */
    public function test_an_archive_not_said_to_be_for_its_author_carries_no_journal_in_the_authors_own_session(): void {
        $this->write(1, 'Un texte tres personnel.');

        $this->setUser($this->candidate);
        $files = $this->build(false);
        $this->assertArrayNotHasKey(exporter::REFLEXION_PATH, $files);
        $this->assertStringNotContainsString('tres personnel', implode('', $files));
        $manifest = json_decode($files['manifeste.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('schema_reflexion', $manifest);

        // And said "for the author" it carries it, whoever is at the keyboard.
        $this->setAdminUser();
        $this->assertArrayHasKey(exporter::REFLEXION_PATH, $this->build(true));
    }

    public function test_the_import_brings_the_entries_back_to_their_numbers_as_version_one(): void {
        global $DB;
        $files = $this->archive_with_entries();
        $opened = (int) $DB->get_field('tool_vae_draft', 'timecreated', ['doctype' => 'reflexion', 'slot' => 3]);

        purge::dossier((int) $this->candidate->id);
        $this->assertSame(0, $DB->count_records('tool_vae_draft'));
        $this->setAdminUser();
        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(2, $result->reflexion);
        $this->assertSame(0, $result->reflexiongardees);
        $draft = $DB->get_record('tool_vae_draft', [
            'candidateid' => $this->candidate->id, 'doctype' => 'reflexion', 'section' => 'entree', 'slot' => 1,
        ], '*', MUST_EXIST);
        $this->assertSame(1, (int) $draft->currentversion);
        $this->assertSame('', $draft->fichecode);
        $version = $DB->get_record('tool_vae_version', ['draftid' => $draft->id, 'number' => 1], '*', MUST_EXIST);
        $this->assertSame('Texte enregistré de la première entrée.', $version->content);
        $this->assertSame(
            (int) $this->candidate->id,
            (int) $version->authorid,
            'The restored entry is the candidate\'s, not the importer\'s.'
        );
        $this->assertStringContainsString('Restored from the archive', (string) $version->note);
        $third = $DB->get_record('tool_vae_draft', ['doctype' => 'reflexion', 'slot' => 3], '*', MUST_EXIST);
        $this->assertEqualsWithDelta($opened, (int) $third->timecreated, 1);
    }

    public function test_an_entry_already_written_here_is_left_as_it_is(): void {
        global $DB;
        $files = $this->archive_with_entries();
        purge::dossier((int) $this->candidate->id);
        $this->write(1, 'Ce que j ai ecrit ici entre-temps.');
        $this->setAdminUser();

        $result = (new dossier_importer())->import_files((int) $this->candidate->id, $files);

        $this->assertSame(1, $result->reflexion);
        $this->assertSame(1, $result->reflexiongardees);
        $draft = $DB->get_record('tool_vae_draft', ['doctype' => 'reflexion', 'slot' => 1], '*', MUST_EXIST);
        $this->assertSame(1, (int) $draft->currentversion);
        $this->assertSame(
            'Ce que j ai ecrit ici entre-temps.',
            $DB->get_field('tool_vae_version', 'content', ['draftid' => $draft->id, 'number' => 1])
        );
    }

    public function test_the_same_number_twice_refuses_the_archive(): void {
        $files = self::forge($this->archive_with_entries(), function (array $document): array {
            $document['entrees'][] = $document['entrees'][0];

            return $document;
        });

        $this->assert_refused_after_destroy($files);
    }

    public function test_a_file_the_schema_refuses_refuses_the_archive_even_with_a_matching_digest(): void {
        $files = self::forge($this->archive_with_entries(), function (array $document): array {
            $document['officiel'] = true;

            return $document;
        });

        $this->assert_refused_after_destroy($files);
    }
}
