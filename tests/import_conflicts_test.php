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
use tool_vae\local\dossier_importer;
use tool_vae\local\evidence_manager;
use tool_vae\local\exporter;
use tool_vae\local\identity_map;
use tool_vae\local\purge;

/**
 * Tests for the refusal of an archive that names competencies this site numbers differently.
 *
 * DECIDED WITH THE PROJECT OWNER ON 27 OCTOBER 2026. An archive from another site names a competency this site already
 * knows under another identifier: the archive stays refused whole, nothing is adopted, but the refusal names EVERY competency
 * in conflict, not the first, and says what can be done today. A screen of correspondence validated by a person comes later.
 *
 * The cases are the ones of a change of site: the three first blocs of the record of the aide-soignant diploma (RNCP40692-BC01,
 * -BC02, -BC03), declared on a first site, exported, and offered to a second site which has already given its own identifier to
 * each of them (an archive handed to a panel, a Word copy, a backup are enough to mint one). The second site is played by the
 * same site: the dossier is destroyed and the identifiers of the competencies are changed, which is what a site that numbered
 * them first leaves in its table.
 *
 * The labels are those of a published record in shape (a label of more than 100 characters is the description of a competency
 * named `Bloc <rank>`, as the conversion of France competences writes it); their wording is the test's.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\dossier_importer::class)]
final class import_conflicts_test extends \advanced_testcase {
    /** @var int 1 September 2025, 00:00 UTC. */
    private const PERIODSTART = 1756684800;

    /** @var int 30 June 2026, 00:00 UTC. */
    private const PERIODEND = 1782777600;

    /** @var string The refusal under test. */
    private const REFUSAL = 'errorarchivecompetencyheld';

    /** @var string[] The first three blocs of the record, rank => published label (100, 80 and 102 characters). */
    private const BLOCS = [
        'BC01' => 'Accompagnement et soins de la personne dans les activités de sa vie quotidienne et de sa vie sociale',
        'BC02' => 'Évaluation de l\'état clinique et mise en œuvre de soins adaptés en collaboration',
        'BC03' => 'Information et accompagnement des personnes et de leur entourage, des professionnels et des apprenants',
    ];

    /** @var \stdClass Declares and exports their own dossier. */
    private $candidate;

    /**
     * A candidate who may declare. The records are made by each test: they differ from one to the next.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $role = $generator->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $role, \context_system::instance()->id, true);
        $this->candidate = $generator->create_user();
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);

        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * An imported certification record with the given competencies, as the conversion of France competences writes them.
     *
     * A label of up to 100 characters is the name of the competency. A longer one cannot be: the name is `Bloc <rank>` and the
     * label is the description (competency_gateway::label_of()).
     *
     * @param string $code The record code.
     * @param string[] $labels Rank => published label, at least one.
     * @return int[] Rank => competency id.
     */
    private function import_record(string $code, array $labels): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $shape = function (string $rank, string $label): array {
            return \core_text::strlen($label) > 100
                ? ['shortname' => 'Bloc ' . $rank, 'description' => $label]
                : ['shortname' => $label, 'description' => ''];
        };

        $first = array_key_first($labels);
        $generator->get_plugin_generator('tool_vae')->create_source(
            ['fichecode' => $code, 'ficherang' => $first] + $shape($first, $labels[$first])
        );

        $frameworkid = (new competency_gateway())->find_framework($code);
        $lpg = $generator->get_plugin_generator(competency_gateway::SUBSYSTEM);
        foreach ($labels as $rank => $label) {
            if ($rank === $first) {
                continue;
            }
            $lpg->create_competency([
                'competencyframeworkid' => $frameworkid,
                'idnumber' => $code . '-' . $rank,
            ] + $shape((string) $rank, $label));
        }

        $ids = [];
        foreach (array_keys($labels) as $rank) {
            $ids[$rank] = (int) $DB->get_field('competency', 'id', ['idnumber' => $code . '-' . $rank], MUST_EXIST);
        }

        return $ids;
    }

    /**
     * One declaration by the candidate, on a competency.
     *
     * @param int $competencyid
     */
    private function declare_on(int $competencyid): void {
        $this->setUser($this->candidate);

        (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => $competencyid,
            'periodstart' => self::PERIODSTART,
            'periodend' => self::PERIODEND,
            'settingtype' => 'ehpad',
            'settingdetail' => 'Equipe de jour',
            'situation' => 'Accompagnement au lever, recherche de son accord a chaque geste.',
            'situationformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * The files of the archive of the candidate's dossier as it stands, then the dossier is gone, as on a site that never held it.
     *
     * @return string[]
     */
    private function archive_then_destroy(): array {
        global $DB;

        $claims = $DB->get_records('tool_vae_evidence', ['candidateid' => $this->candidate->id], 'timecreated ASC, id ASC');
        $files = (new exporter())->build((int) $this->candidate->id, $claims, identity_map::generate_ulid(), time());
        purge::dossier((int) $this->candidate->id);

        return $files;
    }

    /**
     * Give a competency the identifier of this site, which is not the one the archive gives it.
     *
     * The state of a site that has already let a dossier go out on the competency: the first of them mints an identifier for it.
     *
     * @param int $competencyid
     */
    private function number_on_this_site(int $competencyid): void {
        global $DB;

        identity_map::uuid_for(identity_map::TYPE_COMPETENCY, $competencyid);
        $DB->set_field(
            identity_map::TABLE,
            'uuid',
            identity_map::generate_ulid(),
            ['itemtype' => identity_map::TYPE_COMPETENCY, 'itemid' => $competencyid]
        );
    }

    /**
     * What this plugin holds, table by table, and the evidence of the core it writes through.
     *
     * @return int[] Table => number of rows.
     */
    private function rows_held(): array {
        global $DB;

        $core = ['competency_userevidence', 'competency_userevidencecomp'];
        $counts = [];
        foreach ($DB->get_tables() as $table) {
            if (str_starts_with($table, 'tool_vae_') || in_array($table, $core, true)) {
                $counts[$table] = $DB->count_records($table);
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * The strings of the plugin in one language, read from the language file itself.
     *
     * Read from the file and not through the string manager: the French pack is not installed in the test site, and the manager
     * then answers in English whatever language is asked.
     *
     * @param string $lang en or fr.
     * @return string[]
     */
    private static function strings_of(string $lang): array {
        $string = [];
        include(__DIR__ . '/../lang/' . $lang . '/tool_vae.php');

        return $string;
    }

    /**
     * The placeholders a text holds, each once, in alphabetical order.
     *
     * @param string $text
     * @return string[] `{$a}` or `{$a->name}`, as the string manager reads them.
     */
    private static function markers(string $text): array {
        preg_match_all('/\{\$a(?:->\w+)?\}/', $text, $found);
        $markers = array_unique($found[0]);
        sort($markers);

        return array_values($markers);
    }

    /**
     * Run the checks of an archive alone, as the administrator, and expect the refusal under test.
     *
     * @param string[] $files
     * @return string The message of the refusal.
     */
    private function refusal_of_check(array $files): string {
        $this->setAdminUser();

        try {
            (new dossier_importer())->check($files);
        } catch (\moodle_exception $e) {
            $this->assertSame(self::REFUSAL, $e->errorcode);

            return $e->getMessage();
        }
        $this->fail('check() let the archive through; ' . self::REFUSAL . ' was expected.');
    }

    /**
     * Import as the administrator, and expect the refusal under test.
     *
     * @param string[] $files
     * @return string The message of the refusal.
     */
    private function refusal_of_import(array $files): string {
        $this->setAdminUser();

        try {
            (new dossier_importer())->import_files((int) $this->candidate->id, $files);
        } catch (\moodle_exception $e) {
            $this->assertSame(self::REFUSAL, $e->errorcode);

            return $e->getMessage();
        }
        $this->fail('The archive was accepted; ' . self::REFUSAL . ' was expected.');
    }

    /**
     * THE CASE OF THE DECISION. Three blocs of the same record are known here under other identifiers than the archive's: the
     * refusal names the three, not the first, and nothing is written.
     *
     * It named RNCP40692-BC01 and stopped there; the administrator, once that one was dealt with, met the second, then the third.
     */
    public function test_every_competency_numbered_differently_is_named_in_the_one_refusal(): void {
        global $DB;

        $ids = $this->import_record('RNCP40692', self::BLOCS);
        foreach ($ids as $id) {
            $this->declare_on($id);
        }
        $files = $this->archive_then_destroy();
        foreach ($ids as $id) {
            $this->number_on_this_site($id);
        }
        $before = $this->rows_held();

        $message = $this->refusal_of_check($files);
        foreach (['RNCP40692-BC01', 'RNCP40692-BC02', 'RNCP40692-BC03'] as $name) {
            $this->assertStringContainsString($name, $message);
        }

        // The import says the same, from the same checks.
        $imported = $this->refusal_of_import($files);
        foreach (['RNCP40692-BC01', 'RNCP40692-BC02', 'RNCP40692-BC03'] as $name) {
            $this->assertStringContainsString($name, $imported);
        }

        // Nothing is adopted and nothing is written: not a declaration, not an evidence of the core, not even the account's
        // identifier.
        $this->assertSame($before, $this->rows_held());
        $this->assertSame(
            0,
            $DB->count_records(identity_map::TABLE, ['itemtype' => identity_map::TYPE_USER, 'itemid' => $this->candidate->id])
        );
    }

    /**
     * Only what is in conflict is named: a bloc numbered alike here is not in the list for being in the archive.
     */
    public function test_only_the_competencies_in_conflict_are_named(): void {
        $ids = $this->import_record('RNCP40692', self::BLOCS);
        foreach ($ids as $id) {
            $this->declare_on($id);
        }
        $files = $this->archive_then_destroy();
        $this->number_on_this_site($ids['BC02']);
        $this->number_on_this_site($ids['BC03']);

        $message = $this->refusal_of_check($files);

        $this->assertStringContainsString('RNCP40692-BC02', $message);
        $this->assertStringContainsString('RNCP40692-BC03', $message);
        $this->assertStringNotContainsString('RNCP40692-BC01', $message);
    }

    /**
     * The name this site gives the competency stands beside the code, so that the administrator can tell whether it is the
     * same competency; a label of more than 100 characters is cut, and a label of exactly 100 is not.
     */
    public function test_the_label_of_this_site_stands_beside_each_name_and_is_cut_at_one_hundred_characters(): void {
        $ids = $this->import_record('RNCP40692', self::BLOCS);
        foreach ($ids as $id) {
            $this->declare_on($id);
        }
        $files = $this->archive_then_destroy();
        foreach ($ids as $id) {
            $this->number_on_this_site($id);
        }

        $message = $this->refusal_of_check($files);

        // 100 characters: whole. 80: whole. 102: the first 97 and three dots, and the end of it is not there.
        $this->assertStringContainsString(s(self::BLOCS['BC01']), $message);
        $this->assertStringContainsString(s(self::BLOCS['BC02']), $message);
        $this->assertStringContainsString(s(\core_text::substr(self::BLOCS['BC03'], 0, 97) . '...'), $message);
        $this->assertStringNotContainsString(s(self::BLOCS['BC03']), $message);
    }

    /**
     * The refusal says what can be done today, in plain words.
     *
     * Read in English, the language the tests run in: it is the wording the project owner decided on 27 October 2026 (start
     * again on the new site and keep the archive and its PDF as a document of the dossier, or call on a developer). No
     * manoeuvre in the database is described: the project owner asked for none.
     */
    public function test_the_refusal_says_what_can_be_done_today(): void {
        $ids = $this->import_record('RNCP40692', self::BLOCS);
        $this->declare_on($ids['BC03']);
        $files = $this->archive_then_destroy();
        $this->number_on_this_site($ids['BC03']);

        $message = $this->refusal_of_check($files);

        $this->assertStringContainsString('start again from scratch', $message);
        $this->assertStringContainsString('its PDF', $message);
        $this->assertStringContainsString('ask a developer', $message);
        $this->assertStringContainsString('do not number', $message);
    }

    /**
     * The texts of the refusal hold the values the importer gives them, in both languages: no more, no fewer, none misspelt.
     *
     * Read from the language files. The importer gives the refusal `count` and `list`, an item of the list `name` and `label`, and
     * the line that counts the others a whole number. A placeholder misspelt in lang/fr (`{$a->liste}` for `{$a->list}`) is not
     * replaced by the string manager: the administrator reads it raw, and PHPUnit, Behat (both in English) and the language check
     * (which compares the keys, not what is in them) stay green.
     */
    public function test_the_texts_of_the_refusal_hold_the_values_the_importer_gives_them_in_each_language(): void {
        foreach (['en', 'fr'] as $lang) {
            $strings = self::strings_of($lang);

            $this->assertSame(['{$a->count}', '{$a->list}'], self::markers($strings[self::REFUSAL]), "lang/$lang " . self::REFUSAL);
            $this->assertSame(
                ['{$a->label}', '{$a->name}'],
                self::markers($strings['errorarchivecompetencyheldone']),
                "lang/$lang errorarchivecompetencyheldone"
            );
            $this->assertSame(['{$a}'], self::markers($strings['errorarchivecompetencyheldmore']), "lang/$lang ...heldmore");
        }
    }

    /**
     * The refusal says what can be done today, in French, in the words the project owner decided on 27 October 2026.
     *
     * Read from the language file, as the site language of a test is English (see test_the_refusal_says_what_can_be_done_today()
     * for the English). The administrator is told that nothing was imported, that the sites do not number the competencies the same
     * way, and the two ways out: start again on this site and keep the archive and its PDF as a document of the dossier, or call on
     * a developer. No manoeuvre in the database is described (the project owner asked for none), in French or in English.
     */
    public function test_the_french_refusal_says_what_can_be_done_today(): void {
        $french = self::strings_of('fr');

        $text = str_replace(['{$a->count}', '{$a->list}'], ['3', '<ul><li>RNCP40692-BC01</li></ul>'], $french[self::REFUSAL]);

        $this->assertStringNotContainsString('{$a', $text);
        $this->assertStringContainsString('refusée en entier', $text);
        $this->assertStringContainsString('rien n\'a été importé', $text);
        $this->assertStringContainsString('ne numérotent pas de la même façon 3 compétence(s)', $text);
        $this->assertStringContainsString('repartir de zéro sur ce site', $text);
        $this->assertStringContainsString('nouvelles signatures des responsables', $text);
        $this->assertStringContainsString('l\'archive et son PDF comme pièce du dossier', $text);
        $this->assertStringContainsString('demander l\'aide d\'un développeur', $text);

        // An item of the list and the line that counts the others.
        $one = $french['errorarchivecompetencyheldone'];
        $item = str_replace(['{$a->name}', '{$a->label}'], ['RNCP40692-BC01', 'Accueillir'], $one);
        $this->assertSame('RNCP40692-BC01 (sur ce site : Accueillir)', $item);
        $this->assertSame('et 2 autre(s)', str_replace('{$a}', '2', $french['errorarchivecompetencyheldmore']));
    }

    /**
     * No manoeuvre in the database is described to the administrator, and the house rules of wording hold, in both languages.
     *
     * The decision asks for none: what can be done today is to start again or to ask a developer. French: never "candidat", no em
     * dash.
     */
    public function test_the_refusal_describes_no_manoeuvre_in_the_database_and_follows_the_house_rules(): void {
        $keys = [self::REFUSAL, 'errorarchivecompetencyheldone', 'errorarchivecompetencyheldmore'];
        $manoeuvres = [
            'en' => ['database', 'SQL', 'phpMyAdmin', 'query', 'tool_vae', 'UPDATE', 'DELETE'],
            'fr' => ['base de données', 'SQL', 'phpMyAdmin', 'requête', 'tool_vae', 'UPDATE', 'DELETE'],
        ];

        foreach ($manoeuvres as $lang => $terms) {
            $strings = self::strings_of($lang);
            foreach ($keys as $key) {
                foreach ($terms as $term) {
                    $this->assertStringNotContainsString($term, $strings[$key], "lang/$lang $key");
                }
                $this->assertStringNotContainsString("\u{2014}", $strings[$key], "lang/$lang $key");
            }
        }
        $french = self::strings_of('fr');
        foreach ($keys as $key) {
            $this->assertStringNotContainsString('candidat', $french[$key], "lang/fr $key");
        }
    }

    /**
     * The texts of the refusal are shown as HTML: what comes from the site's own record is escaped, tags and ampersand alike.
     *
     * A label of a competency is the administrator's to type, in a description that takes any markup the editor lets through.
     */
    public function test_the_label_of_this_site_is_escaped_in_the_refusal(): void {
        global $DB;

        $ids = $this->import_record('RNCP40692', self::BLOCS);
        $this->declare_on($ids['BC02']);
        $files = $this->archive_then_destroy();

        $trap = '<script>alert(1)</script><a href="http://x.io">x</a> & "soins"';
        $DB->set_field('competency', 'shortname', 'Bloc BC02', ['id' => $ids['BC02']]);
        $DB->set_field('competency', 'description', $trap, ['id' => $ids['BC02']]);
        $this->number_on_this_site($ids['BC02']);

        $message = $this->refusal_of_check($files);

        $this->assertStringContainsString(s($trap), $message);
        $this->assertStringNotContainsString('<script', $message);
        $this->assertStringNotContainsString('<a href', $message);
        $this->assertStringNotContainsString(' & "soins"', $message);
    }

    /**
     * The code of the record is the archive's own as much as the site's (the archive's must equal the site's to be found), and the
     * refusal that names it is shown as HTML: a code with an ampersand and a quote in it is escaped.
     *
     * A record code is free text up to 32 characters, in the schema and in the table of sources, and every other message of the
     * importer escapes it. It was already escaped here: this test keeps it so now that the name is part of a list. A tag cannot be
     * in it: the name of the core record a declaration writes refuses tags, so nothing can be declared on such a code.
     */
    public function test_a_record_code_is_escaped_in_the_refusal(): void {
        $ids = $this->import_record('RNCP&"1', ['C01' => 'Accueillir la personne', 'C02' => 'Repérer les risques de chute']);
        foreach ($ids as $id) {
            $this->declare_on($id);
        }
        $files = $this->archive_then_destroy();
        foreach ($ids as $id) {
            $this->number_on_this_site($id);
        }

        $message = $this->refusal_of_check($files);

        $this->assertStringContainsString('RNCP&amp;&quot;1-C01', $message);
        $this->assertStringContainsString('RNCP&amp;&quot;1-C02', $message);
        $this->assertStringNotContainsString('RNCP&"1', $message);
    }

    /**
     * An identifier this site has given to something else is another matter than a competency numbered differently: it is refused
     * by itself, with the identifier, before the list of the others.
     *
     * One bloc is numbered differently here (the list), another bloc's identifier in the archive is an account's here (this).
     */
    public function test_an_identifier_given_to_something_else_is_refused_by_itself_before_the_list(): void {
        global $DB;

        $ids = $this->import_record('RNCP40692', self::BLOCS);
        $this->declare_on($ids['BC01']);
        $this->declare_on($ids['BC02']);
        $files = $this->archive_then_destroy();

        $this->number_on_this_site($ids['BC01']);
        $given = identity_map::uuid_for(identity_map::TYPE_COMPETENCY, $ids['BC02']);
        $DB->delete_records(identity_map::TABLE, ['itemtype' => identity_map::TYPE_COMPETENCY, 'itemid' => $ids['BC02']]);
        $DB->insert_record(identity_map::TABLE, (object) [
            'itemtype' => identity_map::TYPE_USER,
            'itemid' => (int) $this->getDataGenerator()->create_user()->id,
            'uuid' => $given,
            'timecreated' => time(),
        ]);

        $this->setAdminUser();
        try {
            (new dossier_importer())->check($files);
            $this->fail('check() let the archive through.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorarchiveidentitytaken', $e->errorcode);
            $this->assertStringContainsString($given, $e->getMessage());
        }
    }

    /**
     * The list is capped: ten names, then how many more. Twelve competencies of one record in conflict.
     *
     * The ten are the first ten in the order of the codes, C1 before C10 (C1 to C10, whatever order the archive lists them in),
     * and the last two are counted, not named.
     *
     * THE CASE THAT TELLS A SORT FROM NO SORT, AND A NATURAL SORT FROM A PLAIN ONE. The competencies are declared from the last
     * to the first, and the archive lists the claims in the order they were declared: without the sort the ten named would be C12
     * down to C3. The ranks are not padded (C1 to C12; the importer takes a rank of up to eight capitals and digits): a plain
     * string sort puts C10, C11 and C12 before C2, and the ten named would be C1, C10, C11, C12, then C2 to C7. It stayed green
     * with the sort removed, and with strcmp for strnatcmp, when the ranks were C01 to C12 declared in that order.
     */
    public function test_at_most_ten_competencies_are_named_and_the_others_are_counted(): void {
        $labels = [];
        for ($number = 1; $number <= 12; $number++) {
            $labels['C' . $number] = 'Competence numero ' . $number;
        }
        $ids = $this->import_record('RNCP36832', $labels);
        foreach (array_reverse($ids) as $id) {
            $this->declare_on($id);
        }
        $files = $this->archive_then_destroy();
        foreach ($ids as $id) {
            $this->number_on_this_site($id);
        }
        $before = $this->rows_held();

        $message = $this->refusal_of_check($files);

        // The names the message gives, in the order it gives them: the first ten in the order of the codes, and no other.
        $expected = [];
        for ($number = 1; $number <= 10; $number++) {
            $expected[] = 'RNCP36832-C' . $number;
        }
        preg_match_all('/RNCP36832-C[0-9]+/', $message, $named);
        $this->assertSame($expected, $named[0]);
        $this->assertStringContainsString(get_string('errorarchivecompetencyheldmore', 'tool_vae', 2), $message);
        $this->assertSame($before, $this->rows_held());
    }

    /**
     * THE RACE BETWEEN THE CHECKS AND THE WRITE. A competency the checks found free is numbered by another request before the
     * write opens its transaction: the refusal is the one of the checks, naming the competency, not the one of the account.
     *
     * The site has not yet let a dossier go out on the three blocs, so the identifier of the archive is free when check() runs.
     * Between check() and write() (the two calls of import_files()) an export of a dossier of this site, or the copy of the night,
     * numbers two of them: the call it makes is identity_map::uuid_for(). write() is private and is called by reflection, with the
     * plan check() gave. adopt() refused it, with `this account is already known on this site under another identifier`, of a
     * competency, and no word of which.
     */
    public function test_a_competency_numbered_between_the_checks_and_the_write_is_refused_by_name(): void {
        global $DB;

        $ids = $this->import_record('RNCP40692', self::BLOCS);
        foreach ($ids as $id) {
            $this->declare_on($id);
        }
        $files = $this->archive_then_destroy();
        $DB->delete_records(identity_map::TABLE, ['itemtype' => identity_map::TYPE_COMPETENCY]);

        $this->setAdminUser();
        $importer = new dossier_importer();
        $plan = $importer->check($files);
        $this->assertCount(3, $plan->competencies, 'Nothing is in the way when the checks run.');

        // The other request, between the two.
        identity_map::uuid_for(identity_map::TYPE_COMPETENCY, $ids['BC01']);
        identity_map::uuid_for(identity_map::TYPE_COMPETENCY, $ids['BC03']);
        $before = $this->rows_held();

        try {
            (new \ReflectionMethod($importer, 'write'))->invoke($importer, (int) $this->candidate->id, $plan);
            $this->fail('The write let the archive through.');
        } catch (\moodle_exception $e) {
            $this->assertSame(self::REFUSAL, $e->errorcode);
            $message = $e->getMessage();
        }

        $this->assertStringContainsString('RNCP40692-BC01', $message);
        $this->assertStringContainsString('RNCP40692-BC03', $message);
        $this->assertStringNotContainsString('RNCP40692-BC02', $message);
        // The same words the checks would have used, had they run after the other request.
        $this->assertSame($this->refusal_of_check($files), $message);
        // All or nothing: not a declaration, not an evidence of the core, not even the account's identifier.
        $this->assertSame($before, $this->rows_held());
    }
}
