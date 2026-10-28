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

use tool_vae\form\declare_form;
use tool_vae\local\carry_manager;
use tool_vae\local\competency_gateway;
use tool_vae\local\evidence_manager;
use tool_vae\local\hidden_frameworks;
use tool_vae\local\importer;
use tool_vae\local\passage_manager;
use tool_vae\local\supersession;

/**
 * A competency framework hidden in Moodle ("Visible: No"), as the vaeiste meets it (decision of 27 October 2026).
 *
 * WHAT IS ASSERTED. That a hidden record is left out of the list to declare in while the others stay offered; that the refusal
 * is written for the vaeiste (the record by its title and its code, the word Moodle uses, whom to ask, no path of the
 * administration screens) and is the same text wherever it is given; that a form sent after the record was hidden is recognised
 * from what it sent, and gives back what was typed instead of the generic error; that when every record is hidden the text is
 * kept all the same; that the form asks for a new choice after a refusal. The rule itself (nothing is declared on a hidden
 * record) is asserted in evidence_manager_test, and the carry in carry_manager_test.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\hidden_frameworks::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\form\declare_form::class)]
final class framework_hidden_test extends \advanced_testcase {
    /** @var string What the vaeiste typed in the narrative of the form. */
    private const SITUATION = 'Accompagnement au lever et à la toilette, en binôme avec une aide-soignante.';

    /** @var \stdClass The vaeiste, who may declare. */
    private $candidate;

    /** @var int[] The competencies of the records, by idnumber. */
    private $ids = [];

    /** @var int[] The framework of each record, by record code. */
    private $frameworks = [];

    /**
     * Two imported records, one flat and one with a bloc, as the import writes them, and a vaeiste who may declare.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/formslib.php');
        $this->setAdminUser();

        $this->import(
            'RNCP36832',
            'Accompagnement des personnes âgées',
            "rang,intitule,description\nC03,Repérer les facteurs de risque de chute,\n"
        );
        $this->import(
            'RNCP40692',
            'Aide-Soignant (RNCP40692)',
            "rang,intitule,description,bloc\n"
            . "BC03,Travailler en équipe pluriprofessionnelle,,\n"
            . "C07,Transmettre les informations utiles à l'équipe,,BC03\n"
        );

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->candidate);
    }

    /**
     * Import a record the way the plugin does, and remember its framework and its competencies.
     *
     * @param string $code
     * @param string $name The title of the certification, which becomes the name of the framework.
     * @param string $csv
     */
    private function import(string $code, string $name, string $csv): void {
        $result = (new importer())->import((object) [
            'fichecode' => $code,
            'fichetype' => 'rncp',
            'name' => $name,
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => strtotime('2026-06-01'),
            'licence' => 'Licence Ouverte 2.0',
            'attribution' => 'France compétences',
        ], importer::rows_from_csv($csv));

        $this->frameworks[$code] = (int) $result->frameworkid;
        $listed = (new competency_gateway())->list_framework_competencies((int) $result->frameworkid);
        $this->ids += array_column($listed, 'id', 'idnumber');
    }

    /**
     * The gesture of an administrator in the competency frameworks screen: "Visible: No". By the table, so that no test names
     * the core.
     *
     * @param string $code The record whose framework is hidden.
     * @param int $visible 0 to hide, 1 to show again.
     */
    private function set_visible(string $code, int $visible = 0): void {
        global $DB;

        $DB->set_field('competency_framework', 'visible', $visible, ['id' => $this->frameworks[$code]]);
    }

    /**
     * The strings of the plugin in a language, read from the file itself, whatever language packs the site holds.
     *
     * @param string $lang en or fr.
     * @return string[]
     */
    private function strings(string $lang): array {
        $string = [];
        include(__DIR__ . '/../lang/' . $lang . '/tool_vae.php');

        return $string;
    }

    /**
     * What the browser posts when the declaration form is sent, every field of it.
     *
     * @param int $competencyid The competency chosen.
     * @return array
     */
    private function posted(int $competencyid): array {
        return [
            'userid' => $this->candidate->id,
            'competencyid' => $competencyid,
            'periodstart' => ['day' => 1, 'month' => 9, 'year' => 2025],
            'periodend' => ['day' => 30, 'month' => 6, 'year' => 2026],
            'settingtype' => 'ehpad',
            'settingdetail' => 'Unité protégée, équipe de nuit',
            'situation' => self::SITUATION,
        ];
    }

    /**
     * What the page offers, as it reads it.
     *
     * @return \stdClass
     */
    private function offered(): \stdClass {
        return (new hidden_frameworks())->offered();
    }

    /**
     * The competency list of a rendered declaration form: what is inside its select, and nothing of the rest of the page.
     *
     * @param string $html The form as it is rendered.
     * @return string The options (and the groups) of the select.
     */
    private function competency_list(string $html): string {
        $this->assertSame(
            1,
            preg_match('/<select\b[^>]*\bname="competencyid"[^>]*>(.*?)<\/select>/s', $html, $found),
            'The list of competencies is in the form.'
        );

        return $found[1];
    }

    /**
     * The list starts on "Choose...": one line, the first of the list, with an empty value, and nothing is selected.
     *
     * The entry is what stops the browser from falling on the first competency of the list, which is of another record: a second
     * send would declare there. It does so only as the FIRST entry, with an empty value that the form refuses, and as ONE line (a
     * group header that says it as well makes a screen reader say it twice). Read from the markup, not from the presence of the
     * words: an entry written last, or written twice, still holds the words.
     *
     * @param string $html The form as it is rendered.
     */
    private function assert_list_starts_on_choose(string $html): void {
        $list = $this->competency_list($html);
        $choose = get_string('choosedots');

        $this->assertSame(1, preg_match('/<option\b([^>]*)>(.*?)<\/option>/s', $list, $first), 'The list holds options.');
        $this->assertMatchesRegularExpression('/\bvalue=""/', $first[1], 'The first entry has an empty value.');
        $this->assertSame($choose, trim($first[2]), 'The first entry is "Choose...".');
        $this->assertSame(1, substr_count($list, $choose), 'One line "Choose...", not a group header and an option.');
        $this->assertStringNotContainsString('<optgroup label="' . $choose . '"', $list, 'Not a group of its own.');
        $this->assertSame(0, preg_match('/<option\b[^>]*\bselected\b/', $list), 'Nothing is chosen for the person.');
    }

    // ----------------------------------------------------------------------
    // What the list offers.

    /**
     * The list to declare in leaves a hidden record out and keeps the others; the page is told which are hidden.
     */
    public function test_a_hidden_record_is_left_out_of_the_list_and_the_others_stay_offered(): void {
        $this->set_visible('RNCP40692');

        $offered = $this->offered();

        $this->assertSame([$this->ids['RNCP36832-C03']], array_keys($offered->competencies));
        $this->assertSame(['RNCP36832'], array_values(array_unique(array_map(fn($n) => $n->fichecode, $offered->outline))));
        $this->assertSame(['RNCP40692'], array_keys($offered->hidden));
    }

    /**
     * Visible again, the record is offered as before and nothing is said of it.
     */
    public function test_a_record_made_visible_again_is_offered_again(): void {
        $this->set_visible('RNCP40692');
        $this->set_visible('RNCP40692', 1);

        $offered = $this->offered();

        $this->assertArrayHasKey($this->ids['RNCP40692-BC03'], $offered->competencies);
        $this->assertArrayHasKey($this->ids['RNCP40692-C07'], $offered->competencies);
        $this->assertSame([], $offered->hidden);
    }

    /**
     * Every record hidden: nothing is offered and each record is named, which is the truth ("no record imported" is not).
     */
    public function test_every_record_hidden_offers_nothing_and_names_each_one(): void {
        $this->set_visible('RNCP36832');
        $this->set_visible('RNCP40692');

        $offered = $this->offered();

        $this->assertSame([], $offered->competencies);
        $this->assertSame([], $offered->outline);
        $this->assertSame(['RNCP36832', 'RNCP40692'], array_keys($offered->hidden));
    }

    /**
     * A record whose framework the core no longer holds is not "hidden": it has nothing to hide, and the page does not name it.
     */
    public function test_a_record_without_framework_is_not_called_hidden(): void {
        global $DB;

        $DB->set_field('tool_vae_source', 'frameworkid', 0, ['fichecode' => 'RNCP36832']);

        $offered = $this->offered();

        $this->assertSame([], $offered->hidden);
        $offeredids = array_keys($offered->competencies);
        sort($offeredids);
        $expected = [$this->ids['RNCP40692-BC03'], $this->ids['RNCP40692-C07']];
        sort($expected);
        $this->assertSame($expected, $offeredids);
    }

    // ----------------------------------------------------------------------
    // What is said.

    /**
     * The message names the record by its title and its code, and says that it is hidden and whom to ask.
     */
    public function test_the_message_names_the_record_by_its_title_and_its_code(): void {
        $this->set_visible('RNCP36832');

        $message = $this->offered()->hidden['RNCP36832'];

        $this->assertSame(
            get_string('errorframeworkhidden', 'tool_vae', 'Accompagnement des personnes âgées (RNCP36832)'),
            $message
        );
        $this->assertStringContainsString('Accompagnement des personnes âgées (RNCP36832)', $message);
        $this->assertStringContainsString('hidden', $message);
        $this->assertStringContainsString('manager', $message);
    }

    /**
     * The message is for the vaeiste: no path of the administration screens, which he cannot act in, and no technical word.
     */
    public function test_the_message_gives_no_path_of_the_administration(): void {
        $this->set_visible('RNCP36832');

        $message = $this->offered()->hidden['RNCP36832'];

        foreach (['Site administration', 'Competency frameworks', 'Competencies', 'administrator', 'Moodle', 'visible'] as $term) {
            $this->assertStringNotContainsString($term, $message);
        }
    }

    /**
     * The French text uses the word Moodle uses ("caché"), sends to the responsable, and gives no path either.
     *
     * Read from the language file, as the site language of a test is English.
     */
    public function test_the_french_message_says_cachee_and_sends_to_the_responsable(): void {
        $french = $this->strings('fr');

        $text = $french['errorframeworkhidden'];

        $this->assertStringContainsString('{$a}', $text);
        $this->assertStringContainsString('cachée', $text);
        $this->assertStringContainsString('responsable', $text);
        $this->assertStringContainsString('organisme qui vous accompagne', $text);
        $refused = ['masqué', 'Administration du site', 'Référentiels de compétences', 'administrateur', 'Moodle', 'candidat'];
        foreach ($refused as $term) {
            $this->assertStringNotContainsString($term, $text);
        }
    }

    /**
     * The texts written for this decision carry no em dash and, in French, never say "candidat".
     */
    public function test_the_new_texts_follow_the_house_rules_of_wording(): void {
        $keys = ['errorframeworkhidden', 'declarehiddenkept', 'declarekeptintro', 'carryhiddenkept'];

        foreach (['en', 'fr'] as $lang) {
            $strings = $this->strings($lang);
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $strings, "$lang: $key");
                $this->assertStringNotContainsString('—', $strings[$key], "$lang: $key");
                $this->assertStringNotContainsString('–', $strings[$key], "$lang: $key");
            }
        }
        foreach ($keys as $key) {
            $this->assertStringNotContainsString('candidat', mb_strtolower($this->strings('fr')[$key]), "fr: $key");
        }
    }

    /**
     * A title that already holds the code is not followed by it again: "Aide-Soignant (RNCP40692)" names both.
     */
    public function test_a_title_that_holds_the_code_is_not_repeated(): void {
        $this->set_visible('RNCP40692');

        $message = $this->offered()->hidden['RNCP40692'];

        $this->assertSame(1, substr_count($message, 'RNCP40692'));
        $this->assertStringContainsString('Aide-Soignant (RNCP40692)', $message);
    }

    /**
     * The title is text typed by a person, escaped like everything the pages print; without a title the code stands alone.
     */
    public function test_the_title_is_written_as_text_and_a_record_without_title_is_named_by_its_code(): void {
        global $DB;

        $this->set_visible('RNCP36832');
        $this->set_visible('RNCP40692');
        $DB->set_field('competency_framework', 'shortname', '<b>Aide</b> & "soignant"', ['id' => $this->frameworks['RNCP36832']]);
        $DB->set_field('competency_framework', 'shortname', '', ['id' => $this->frameworks['RNCP40692']]);

        $hidden = $this->offered()->hidden;

        $this->assertStringNotContainsString('<b>', $hidden['RNCP36832']);
        $this->assertSame(
            get_string('errorframeworkhidden', 'tool_vae', s('<b>Aide</b> & "soignant" (RNCP36832)')),
            $hidden['RNCP36832']
        );
        $this->assertSame(get_string('errorframeworkhidden', 'tool_vae', 'RNCP40692'), $hidden['RNCP40692']);
    }

    /**
     * The refusal given at the write is the very text the page shows, so that a vaeiste reads one thing, not two.
     */
    public function test_the_refusal_at_the_write_is_the_text_of_the_page(): void {
        $this->set_visible('RNCP36832');

        try {
            (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
                'competencyid' => $this->ids['RNCP36832-C03'],
                'periodstart' => 1756684800,
                'periodend' => 1782172800,
                'settingtype' => 'ehpad',
                'situation' => self::SITUATION,
                'situationformat' => FORMAT_PLAIN,
            ]);
            $this->fail('A declaration on a hidden record was recorded.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorframeworkhidden', $e->errorcode);
            $this->assertSame($this->offered()->hidden['RNCP36832'], $e->getMessage());
        }
    }

    /**
     * A declaration is not offered a carry towards a record hidden in Moodle, and is again once the record is visible.
     *
     * The pair is the one of the Behat scenario: RNCP36832-C03 replaced by RNCP40692-BC03, with the correspondence decided.
     * The details of the carry are asserted in carry_manager_test.
     */
    public function test_the_carry_is_not_offered_towards_a_hidden_record(): void {
        $this->setAdminUser();
        supersession::declare_successor('RNCP36832', 'RNCP40692');
        (new passage_manager())->decide(
            $this->ids['RNCP36832-C03'],
            $this->ids['RNCP40692-BC03'],
            'Même champ, nouvelle formulation.'
        );
        $this->setUser($this->candidate);
        $old = (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => $this->ids['RNCP36832-C03'],
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'situation' => self::SITUATION,
            'situationformat' => FORMAT_PLAIN,
        ]);
        $manager = new carry_manager();
        $this->assertNotNull($manager->offer($old), 'Offered while the new record is visible.');

        $this->set_visible('RNCP40692');
        $this->assertNull($manager->offer($old));

        $this->set_visible('RNCP40692', 1);
        $this->assertNotNull($manager->offer($old));
    }

    // ----------------------------------------------------------------------
    // What a form sent after the record was hidden.

    /**
     * The competency a form chose, in a record hidden since, is refused with the message of that record.
     *
     * The case is the vaeiste whose page was opened before the record was hidden. Anything else gives nothing to say.
     */
    public function test_the_competency_a_form_chose_in_a_hidden_record_is_refused_with_its_message(): void {
        $frameworks = new hidden_frameworks();
        $this->assertNull($frameworks->refusal_for_competency($this->ids['RNCP40692-BC03']), 'Visible: nothing to say.');

        $this->set_visible('RNCP40692');

        $refusal = $frameworks->refusal_for_competency($this->ids['RNCP40692-BC03']);
        $this->assertSame($this->offered()->hidden['RNCP40692'], $refusal);
        $this->assertSame($refusal, $frameworks->refusal_for_competency($this->ids['RNCP40692-C07']), 'Under a bloc, too.');
        $this->assertNull($frameworks->refusal_for_competency($this->ids['RNCP36832-C03']), 'Another record, visible.');
        $this->assertNull($frameworks->refusal_for_competency(0), 'Nothing chosen.');
        $this->assertNull($frameworks->refusal_for_competency(987654), 'A competency nobody holds.');
    }

    /**
     * Why the page reads the request itself: a competency which the list no longer offers is dropped by the form.
     *
     * The first two assertions characterise the core and would pass before this change as well: the select keeps only the
     * values it offers, so the page cannot learn from get_data() that the vaeiste had chosen the competency of a record hidden
     * since. The error "this competency does not exist" came from there. The last one is what the page does instead.
     */
    public function test_the_form_drops_a_competency_it_does_not_offer(): void {
        $this->set_visible('RNCP40692');
        $offered = $this->offered();
        declare_form::mock_submit($this->posted($this->ids['RNCP40692-BC03']));
        $form = new declare_form(null, ['competencies' => $offered->competencies, 'outline' => $offered->outline]);

        $this->assertTrue($form->is_submitted());
        $this->assertEmpty($form->get_submitted_data()->competencyid ?? null);
        $this->assertSame($this->ids['RNCP40692-BC03'], hidden_frameworks::sent()->competencyid);
    }

    /**
     * The form is given back with what was typed, and asks for a new choice instead of picking another record in silence.
     */
    public function test_the_form_gives_back_what_was_typed_and_asks_for_a_new_choice(): void {
        $this->set_visible('RNCP40692');
        $offered = $this->offered();
        declare_form::mock_submit($this->posted($this->ids['RNCP40692-BC03']));

        $form = new declare_form(null, [
            'competencies' => $offered->competencies,
            'outline' => $offered->outline,
            'choose' => true,
        ]);
        $html = $form->render();

        $this->assertStringContainsString(self::SITUATION, $html);
        $this->assertStringContainsString('Unité protégée, équipe de nuit', $html);
        $this->assertStringContainsString(get_string('choosedots'), $html, 'A choice is asked for.');
        $this->assert_list_starts_on_choose($html);
        $this->assertStringContainsString('RNCP36832-C03', $html);
        $this->assertStringNotContainsString('RNCP40692-BC03', $html, 'The hidden record is not offered.');
    }

    /**
     * The same, for a list grouped by bloc.
     *
     * The second part is the case that comes with a refusal: the record chosen was hidden since, and what the form received is a
     * competency of it, which the grouped list no longer holds.
     */
    public function test_the_grouped_list_asks_for_a_new_choice_too(): void {
        $offered = $this->offered();

        $with = (new declare_form(null, [
            'competencies' => $offered->competencies,
            'outline' => $offered->outline,
            'choose' => true,
        ]))->render();
        $without = (new declare_form(null, [
            'competencies' => $offered->competencies,
            'outline' => $offered->outline,
        ]))->render();

        $this->assertStringContainsString('<optgroup', $with);
        $this->assertStringContainsString(get_string('choosedots'), $with);
        $this->assertStringNotContainsString(get_string('choosedots'), $without, 'Only after a refusal.');
        $this->assert_list_starts_on_choose($with);

        // The flat record is hidden, the record with a bloc is the one left: the list stays grouped.
        $this->set_visible('RNCP36832');
        $offered = $this->offered();
        declare_form::mock_submit($this->posted($this->ids['RNCP36832-C03']));
        $refused = (new declare_form(null, [
            'competencies' => $offered->competencies,
            'outline' => $offered->outline,
            'choose' => true,
        ]))->render();

        $this->assertStringContainsString('<optgroup', $refused, 'The list is still grouped by bloc.');
        $this->assertStringNotContainsString('RNCP36832-C03', $refused, 'The hidden record is not offered.');
        $this->assert_list_starts_on_choose($refused);
    }

    /**
     * The flat list asks for a new choice only after a refusal, as the grouped one does.
     */
    public function test_the_flat_list_asks_for_a_new_choice_only_after_a_refusal(): void {
        $this->set_visible('RNCP40692');
        $offered = $this->offered();

        $with = (new declare_form(null, [
            'competencies' => $offered->competencies,
            'outline' => $offered->outline,
            'choose' => true,
        ]))->render();
        $without = (new declare_form(null, [
            'competencies' => $offered->competencies,
            'outline' => $offered->outline,
        ]))->render();

        $this->assertStringNotContainsString('<optgroup', $with);
        $this->assertStringContainsString(get_string('choosedots'), $with);
        $this->assertStringNotContainsString(get_string('choosedots'), $without);
        $this->assert_list_starts_on_choose($with);
    }

    /**
     * Sent with no competency chosen, the form says so by name: no error page, no text lost.
     */
    public function test_the_form_refuses_a_missing_choice_by_name(): void {
        $offered = $this->offered();
        $form = new declare_form(null, ['competencies' => $offered->competencies, 'outline' => $offered->outline]);
        $data = [
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'situation' => self::SITUATION,
        ];

        $errors = $form->validation($data + ['competencyid' => null], []);
        $this->assertArrayHasKey('competencyid', $errors);
        $this->assertNotEmpty($errors['competencyid']);
        $this->assertArrayNotHasKey('situation', $errors);

        $this->assertArrayHasKey('competencyid', $form->validation($data, []));
        $this->assertArrayNotHasKey(
            'competencyid',
            $form->validation($data + ['competencyid' => $this->ids['RNCP36832-C03']], [])
        );
    }

    // ----------------------------------------------------------------------
    // What is read from the request, and what is kept when nothing can be shown.

    /**
     * What a form sent is read back, and only with its session key.
     */
    public function test_what_a_form_sent_is_read_back_only_with_its_session_key(): void {
        $_POST = [
            'sesskey' => sesskey(),
            'competencyid' => (string) $this->ids['RNCP40692-BC03'],
            'situation' => "Accompagnement au lever.\nSecond ligne.",
            'settingdetail' => 'Unité protégée, équipe de nuit',
        ];

        $sent = hidden_frameworks::sent();
        $this->assertTrue($sent->submitted);
        $this->assertSame($this->ids['RNCP40692-BC03'], $sent->competencyid);
        $this->assertSame("Accompagnement au lever.\nSecond ligne.", $sent->situation);
        $this->assertSame('Unité protégée, équipe de nuit', $sent->settingdetail);
        $this->assertFalse($sent->cancelled);

        $_POST['cancel'] = 'Cancel';
        $this->assertTrue(hidden_frameworks::sent()->cancelled);
        unset($_POST['cancel']);

        // Not from a page of this session: nothing is read back, and nothing is raised.
        $_POST['sesskey'] = 'not-the-key';
        $this->assertFalse(hidden_frameworks::sent()->submitted);
        $this->assertSame(0, hidden_frameworks::sent()->competencyid);
        $this->assertSame('', hidden_frameworks::sent()->situation);
        unset($_POST['sesskey']);
        $this->assertSame('', hidden_frameworks::sent()->situation);

        $_POST = [];
        $nothing = hidden_frameworks::sent();
        $this->assertFalse($nothing->submitted);
        $this->assertSame(0, $nothing->competencyid);
        $this->assertSame('', $nothing->situation);
        $this->assertSame('', $nothing->settingdetail);
        $this->assertFalse($nothing->cancelled);
    }

    /**
     * A parameter that arrives as an array is read as absent: the page does not end on an error of coding.
     *
     * The request is one a browser does not make and a hand can: competencyid[]=1 with a valid session key. The form of the core
     * cleaned such a parameter; optional_param() raises "clean() can not process arrays" on it, which would have fallen on the
     * page before any form was built.
     */
    public function test_a_parameter_sent_as_an_array_is_read_as_absent(): void {
        $_POST = [
            'sesskey' => sesskey(),
            'competencyid' => [(string) $this->ids['RNCP40692-BC03']],
            'situation' => ['x'],
            'settingdetail' => ['x'],
            'cancel' => ['1'],
        ];

        $sent = hidden_frameworks::sent();
        $this->assertTrue($sent->submitted, 'The session key is the right one.');
        $this->assertSame(0, $sent->competencyid);
        $this->assertSame('', $sent->situation);
        $this->assertSame('', $sent->settingdetail);
        $this->assertFalse($sent->cancelled);

        // An array in one parameter hides nothing of the others.
        $_POST = [
            'sesskey' => sesskey(),
            'competencyid' => ['1'],
            'situation' => 'Accompagnement au lever.',
            'cancel' => 'Cancel',
        ];
        $sent = hidden_frameworks::sent();
        $this->assertSame(0, $sent->competencyid);
        $this->assertSame('Accompagnement au lever.', $sent->situation);
        $this->assertTrue($sent->cancelled);

        // The session key itself, as an array, in the form or in the address: it is none, nothing is read, nothing is raised.
        $_POST = ['sesskey' => ['x'], 'situation' => 'Accompagnement au lever.'];
        $this->assertFalse(hidden_frameworks::sent()->submitted);
        $this->assertSame('', hidden_frameworks::sent()->situation);

        $_POST = ['situation' => 'Accompagnement au lever.'];
        $_GET = ['sesskey' => ['x']];
        $this->assertFalse(hidden_frameworks::sent()->submitted);
        $this->assertSame('', hidden_frameworks::sent()->situation);
    }

    /**
     * What is read is read where the core reads it, in the same order: the form sent first, then the address.
     */
    public function test_the_request_is_read_in_the_order_of_the_core(): void {
        $_POST = ['sesskey' => sesskey(), 'competencyid' => (string) $this->ids['RNCP40692-BC03']];
        $_GET = ['competencyid' => (string) $this->ids['RNCP36832-C03'], 'situation' => 'Dans l\'adresse'];

        $sent = hidden_frameworks::sent();

        $this->assertSame($this->ids['RNCP40692-BC03'], $sent->competencyid, 'The form first.');
        $this->assertSame('Dans l\'adresse', $sent->situation, 'The address for what the form does not hold.');
    }

    /**
     * The text sent is given back whole, in a field that can be copied, when there is no form to give it back in.
     */
    public function test_the_text_sent_is_kept_whole_when_there_is_no_form_to_give_it_back_in(): void {
        $html = hidden_frameworks::kept_text("Accompagnement au lever.\nSecond ligne.", 'Unité protégée, équipe de nuit');

        $this->assertStringContainsString(get_string('declarekeptintro', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('situation', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('settingdetail', 'tool_vae'), $html);
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*readonly[^>]*>Accompagnement au lever\.\nSecond ligne\.<\/textarea>/',
            $html
        );
        $this->assertStringContainsString('Unité protégée, équipe de nuit', $html);
    }

    /**
     * What was typed is shown as text, never as markup, and nothing is shown when nothing was typed.
     */
    public function test_the_text_kept_is_escaped_and_empty_when_nothing_was_typed(): void {
        $html = hidden_frameworks::kept_text('Un texte </textarea><script>alert(1)</script>', '');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('Un texte &lt;/textarea&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString(get_string('settingdetail', 'tool_vae'), $html, 'Nothing typed there.');

        $this->assertSame('', hidden_frameworks::kept_text('', ''));
        $this->assertSame('', hidden_frameworks::kept_text("  \n ", ' '));
    }
}
