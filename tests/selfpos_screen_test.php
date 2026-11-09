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

use tool_vae\form\selfpos_form;
use tool_vae\local\access;
use tool_vae\local\competency_gateway;
use tool_vae\local\positioning_screen;
use tool_vae\local\selfpos_manager;
use tool_vae\local\selfpos_regard;
use tool_vae\local\selfpos_screen;
use tool_vae\local\selfpos_tree;

/**
 * The screens of the self-positioning of the vaeiste (lot 1a), and who reads them (lot 1a, task 9).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(selfpos_screen::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(selfpos_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(positioning_screen::class)]
final class selfpos_screen_test extends \advanced_testcase {
    /** @var \stdClass */
    private $vaeiste;

    /** @var \stdClass Another vaeiste. */
    private $othervaeiste;

    /** @var \stdClass Follows the vaeiste and evaluates. */
    private $manager;

    /** @var \stdClass Follows somebody else only. */
    private $outsider;

    /** @var \stdClass Reads every dossier. */
    private $direction;

    /** @var \tool_vae_generator */
    private $generator;

    /**
     * RNCP100 (BC01 holding C01 and C02, BC02 holding C03) and RNCP400 (four levels); the people.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

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
        $this->othervaeiste = $datagenerator->create_user();
        $this->manager = $datagenerator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->outsider = $datagenerator->create_user();
        $this->direction = $datagenerator->create_user();

        $own = $datagenerator->create_role();
        foreach (['declare', 'viewown', 'positionself'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, $system, true);
        }
        set_role_contextlevels($own, [CONTEXT_USER]);
        foreach ([$this->vaeiste, $this->othervaeiste] as $person) {
            role_assign($own, $person->id, \context_user::instance($person->id)->id);
        }
        $follow = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        assign_capability('tool/vae:evaluate', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, \context_user::instance($this->vaeiste->id)->id);
        role_assign($follow, $this->outsider->id, \context_user::instance($this->othervaeiste->id)->id);
        $director = $datagenerator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $director, $system, true);
        role_assign($director, $this->direction->id, $system);
        accesslib_clear_all_caches_for_unit_testing();

        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Une situation.',
        ]);
    }

    /**
     * The vaeiste writes one line.
     *
     * @param string $code
     * @param string $rank
     * @param int $level
     * @param string $justification
     * @return \stdClass
     */
    private function position(string $code, string $rank, int $level, string $justification = ''): \stdClass {
        return $this->generator->create_selfpos([
            'userid' => $this->vaeiste->id, 'fichecode' => $code, 'ficherang' => $rank, 'level' => $level,
            'justification' => $justification,
        ]);
    }

    /**
     * The tree screen of a record, as a reader sees it.
     *
     * @param string $code
     * @param bool $canwrite
     * @param \stdClass|null $reader
     * @return string
     */
    private function tree(string $code, bool $canwrite = true, ?\stdClass $reader = null): string {
        $this->setUser($reader ?? $this->vaeiste);
        $current = (new selfpos_manager())->current((int) $this->vaeiste->id, $code);

        return selfpos_screen::tree((int) $this->vaeiste->id, $code, selfpos_tree::for_record($code), $current, $canwrite);
    }

    /**
     * The node screen of a rank, as the vaeiste sees it.
     *
     * @param string $code
     * @param string $rank
     * @param bool $canwrite
     * @param \stdClass|null $reader
     * @return string
     */
    private function node(string $code, string $rank, bool $canwrite = true, ?\stdClass $reader = null): string {
        $this->setUser($reader ?? $this->vaeiste);
        $tree = selfpos_tree::for_record($code);
        $node = selfpos_tree::node($tree, $rank);
        $manager = new selfpos_manager();

        return selfpos_screen::node(
            (int) $this->vaeiste->id,
            $code,
            $node,
            $manager->lines((int) $this->vaeiste->id, $code, $rank),
            selfpos_regard::for_node((int) $this->vaeiste->id, $code, $node),
            [],
            $canwrite,
            $canwrite ? '<form class="tool-vae-selfpos-form"></form>' : ''
        );
    }

    public function test_the_tree_shows_every_node_with_its_level_name_current_level_and_date(): void {
        $row = $this->position('RNCP400', 'H111', 3, 'Trois semaines seul.');

        $html = $this->tree('RNCP400');

        foreach (['RNCP400', 'Domaine 1', 'Noeud C11', 'Noeud H111', 'Noeud H112', 'Noeud C12', 'Noeud D2'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString(get_string('selfposrecordlevel', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('selfposlevelname', 'tool_vae', 4), $html, 'Unnamed by the core: the number.');
        $this->assertStringContainsString(s(get_string('selfposcurrent', 'tool_vae', (object) [
            'label' => get_string('selfposlabeldefault3', 'tool_vae'),
            'when' => userdate((int) $row->timecreated, get_string('strftimedate', 'langconfig')),
        ])), $html);
        $this->assertSame(6, substr_count($html, s(get_string('selfposnone', 'tool_vae'))), 'The six other nodes have no level.');
        $this->assertSame(7, substr_count($html, 'tool-vae-selfpos-node'));
        $this->assertStringContainsString('rank=H111', $html);
        $this->assertStringContainsString('fiche=RNCP400&amp;rank"', $html, 'The record itself has its page, rank empty.');
    }

    public function test_a_non_positionable_node_is_shown_with_its_explanation_and_no_form(): void {
        $this->setAdminUser();
        $gateway = new competency_gateway();
        $gateway->create_competency((int) $gateway->find_framework('RNCP100'), 'AS-1-1', 'Un code a tirets');

        $html = $this->tree('RNCP100');

        $this->assertStringContainsString('Un code a tirets', $html);
        $this->assertStringContainsString(s(get_string('selfposexplainnotpositionable', 'tool_vae')), $html);
        $this->assertStringNotContainsString('rank=AS-1-1', $html, 'No way to its page.');

        $tree = selfpos_tree::for_record('RNCP100');
        $node = selfpos_tree::node($tree, 'AS-1-1');
        $nodehtml = selfpos_screen::node((int) $this->vaeiste->id, 'RNCP100', $node, [], (object) [
            'structure' => null, 'declarations' => 0, 'pieces' => 0,
        ], [], true, '<form class="tool-vae-selfpos-form"></form>');
        $this->assertStringNotContainsString('tool-vae-selfpos-form', $nodehtml, 'No form on a node nobody can name.');
        $this->assertStringContainsString(s(get_string('selfposexplainnotpositionable', 'tool_vae')), $nodehtml);
    }

    public function test_every_label_is_escaped(): void {
        $this->setAdminUser();
        $gateway = new competency_gateway();
        // The core refuses a tag in a shortname; the characters HTML would read are enough to show the escaping.
        $gateway->create_competency((int) $gateway->find_framework('RNCP100'), 'RNCP100-X01', 'Soins & "aide"');
        $this->position('RNCP100', 'X01', 2, '<b>gras</b> & <script>alert(2)</script>');

        $tree = $this->tree('RNCP100');
        $node = $this->node('RNCP100', 'X01');

        foreach ([$tree, $node] as $html) {
            $this->assertStringNotContainsString('Soins & "aide"', $html);
            $this->assertStringContainsString('Soins &amp; &quot;aide&quot;', $html);
            $this->assertStringNotContainsString('<script>', $html);
        }
        $this->assertStringNotContainsString('alert(2)</script>', $node, 'The justification is text, never markup.');
        $this->assertStringNotContainsString('<b>gras</b>', $node);
    }

    public function test_every_screen_says_it_is_visible_to_the_manager_and_not_a_validation(): void {
        $this->position('RNCP100', 'C01', 2);
        // The vaeiste's own screens: the sentences speak to them.
        $this->setUser($this->vaeiste);
        $records = selfpos_screen::records((int) $this->vaeiste->id, [], []);

        foreach ([$records, $this->tree('RNCP100'), $this->node('RNCP100', 'C01')] as $html) {
            $this->assertStringContainsString(s(get_string('selfposvisible', 'tool_vae')), $html);
            $this->assertStringContainsString(s(get_string('selfposnotvalidation', 'tool_vae')), $html);
        }
    }

    public function test_no_screen_shows_a_total_or_a_percentage(): void {
        foreach (['' => 1, 'BC01' => 2, 'C01' => 3, 'C02' => 4] as $rank => $level) {
            $this->position('RNCP100', (string) $rank, $level);
        }

        foreach ([$this->tree('RNCP100'), $this->node('RNCP100', 'C01')] as $html) {
            $this->assertStringNotContainsString('%', $html);
            $this->assertDoesNotMatchRegularExpression('/\b\d+\s*\/\s*\d+\b/', $html, 'No "3 / 4".');
            $this->assertDoesNotMatchRegularExpression('/total|moyenne|score|écart|gap/i', $html);
        }
    }

    public function test_the_node_screen_gives_the_structure_line_next_to_the_vaeiste_line_without_any_gap(): void {
        $this->position('RNCP100', 'C01', 2, 'Deux situations.');
        $this->generator->create_evaluation([
            'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'authorid' => $this->manager->id,
            'status' => 'sufficient', 'reason' => 'Trois situations vues.',
        ]);

        $html = $this->node('RNCP100', 'C01');

        $this->assertStringContainsString(get_string('selfposlabeldefault2', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('evalstatussufficient', 'tool_vae'), $html);
        $this->assertStringContainsString('Deux situations.', $html);
        $this->assertStringNotContainsString('Trois situations vues.', $html, 'The reason of the structure has its own screen.');
        $counts = (object) ['declarations' => 1, 'attested' => 0, 'pieces' => 0];
        $this->assertStringContainsString(s(get_string('selfposdeclarations', 'tool_vae', $counts)), $html);
        $this->assertDoesNotMatchRegularExpression('/écart|gap|[-+]\s?\d\s?(palier|niveau|level)/i', $html);
    }

    public function test_the_node_screen_says_how_many_declarations_of_the_node_are_attested(): void {
        global $DB;

        $evidenceid = (int) $DB->get_field('tool_vae_evidence', 'id', [
            'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01',
        ], MUST_EXIST);
        $DB->insert_record('tool_vae_attest', (object) [
            'uuid' => str_pad('1', 26, '0', STR_PAD_LEFT),
            'evidenceid' => $evidenceid,
            'attestantid' => $this->manager->id,
            'attestantrole' => 'Care manager',
            'statement' => 'I supervised this person.',
            'attestedat' => 1760000000,
            'timecreated' => 1760000000,
            'timemodified' => 1760000000,
            'usermodified' => $this->manager->id,
        ]);

        $html = $this->node('RNCP100', 'C01');

        $counts = (object) ['declarations' => 1, 'attested' => 1, 'pieces' => 0];
        $this->assertStringContainsString(s(get_string('selfposdeclarations', 'tool_vae', $counts)), $html);
        $this->assertStringContainsString('including 1 attested', $html, 'A count, said in words.');
        $this->assertStringNotContainsString('%', $html, 'No rate.');
    }

    public function test_the_node_screen_has_no_structure_line_where_the_structure_wrote_nothing(): void {
        $html = $this->node('RNCP100', 'C02');

        $this->assertStringContainsString(s(get_string('selfposregardnone', 'tool_vae')), $html);
        $this->assertStringContainsString(s(get_string('selfposnoline', 'tool_vae')), $html);
    }

    public function test_the_history_shows_the_line_that_stands_then_the_earlier_ones_with_a_withdrawal_as_a_line(): void {
        $this->position('RNCP100', 'C01', 1, 'Premiere.');
        $this->position('RNCP100', 'C01', 3, 'Deuxieme.');
        $this->position('RNCP100', 'C01', 0, 'Retrait.');

        $html = $this->node('RNCP100', 'C01');

        $this->assertStringContainsString(s(get_string('selfposstanding', 'tool_vae')), $html);
        $this->assertStringContainsString(s(get_string('selfposearlier', 'tool_vae')), $html);
        $this->assertStringContainsString(get_string('selfposwithdrawn', 'tool_vae'), $html);
        $this->assertLessThan(strpos($html, 'Deuxieme.'), strpos($html, 'Retrait.'), 'The latest first.');
        $this->assertLessThan(strpos($html, 'Premiere.'), strpos($html, 'Deuxieme.'));
    }

    public function test_the_form_refuses_a_send_without_sesskey(): void {
        $this->setUser($this->vaeiste);
        $_POST = [
            'level' => '2',
            'justification' => 'Sans jeton.',
            'submitbutton' => 'x',
            '_qf__tool_vae_form_selfpos_form' => '1',
        ];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/sesskey|session/i');
        $form = new selfpos_form('/admin/tool/vae/selfposition.php', ['canwithdraw' => false]);
        $form->get_data();
    }

    public function test_the_form_offers_the_four_steps_and_the_withdrawal_only_when_a_level_stands(): void {
        $this->setUser($this->vaeiste);

        $without = (new selfpos_form('/x', ['canwithdraw' => false]))->render();
        $with = (new selfpos_form('/x', ['canwithdraw' => true]))->render();

        foreach ([1, 2, 3, 4] as $step) {
            $this->assertStringContainsString(get_string('selfposlabeldefault' . $step, 'tool_vae'), $without);
        }
        $this->assertStringNotContainsString(get_string('selfposwithdrawoption', 'tool_vae'), $without);
        $this->assertStringContainsString(get_string('selfposwithdrawoption', 'tool_vae'), $with);
        $this->assertStringContainsString(get_string('anonymiseshort', 'tool_vae'), $with);
        $this->assertStringContainsString(get_string('selfpossubmit', 'tool_vae'), $with);
    }

    public function test_the_text_kept_on_a_hidden_record_is_read_only_from_a_send_with_a_valid_sesskey(): void {
        $this->setUser($this->vaeiste);

        // A text in the address is nothing: nobody can make a link that shows a text of their choice on the page.
        $_GET = ['justification' => 'Par l adresse.'];
        $_POST = [];
        $this->assertSame('', selfpos_screen::kept_text());

        // A send without the session key is nothing either.
        $_GET = [];
        $_POST = ['justification' => 'Sans jeton.', 'submitbutton' => 'x'];
        $this->assertSame('', selfpos_screen::kept_text());
        $_POST = ['justification' => 'Faux jeton.', 'sesskey' => 'faux', 'submitbutton' => 'x'];
        $this->assertSame('', selfpos_screen::kept_text());

        // The send of the vaeiste, with their key: the text is given back.
        $_POST = ['justification' => "Mon texte\nsur deux lignes.", 'sesskey' => sesskey(), 'submitbutton' => 'x'];
        $this->assertSame("Mon texte\nsur deux lignes.", selfpos_screen::kept_text());
        $_POST = [];
    }

    public function test_the_message_of_a_hidden_record_is_escaped_once_only(): void {
        // The message of hidden_frameworks::message() carries a title already escaped: a second pass would show entities.
        $message = get_string('errorframeworkhidden', 'tool_vae', s("Aide & soins (RNCP100)"));
        $this->assertStringContainsString('Aide &amp; soins', $message);

        $screens = [
            selfpos_screen::hidden($message, ''),
            selfpos_screen::records((int) $this->vaeiste->id, [], ['RNCP100' => $message]),
        ];
        foreach ($screens as $html) {
            $this->assertStringContainsString('Aide &amp; soins (RNCP100)', $html);
            $this->assertStringNotContainsString('&amp;amp;', $html);
        }
    }

    public function test_a_record_hidden_since_the_page_was_opened_gives_the_hidden_record_message_and_keeps_the_text(): void {
        $html = selfpos_screen::hidden('Ce referentiel est masque.', "Mon texte <b>long</b>\nsur deux lignes.");

        $this->assertStringContainsString('Ce referentiel est masque.', $html);
        $this->assertStringContainsString(s(get_string('selfposhiddenkept', 'tool_vae')), $html);
        $this->assertStringContainsString('Mon texte &lt;b&gt;long&lt;/b&gt;', $html);
        $this->assertStringContainsString('readonly', $html);
        $this->assertStringNotContainsString(get_string('selfposhiddenkept', 'tool_vae'), selfpos_screen::hidden('Masque.', ''));
    }

    // Task 9: who reads.

    public function test_a_manager_who_follows_reads_the_selfpositioning_of_the_vaeiste_and_cannot_write(): void {
        $this->position('RNCP100', 'C01', 2);
        $this->setUser($this->manager);

        access::require_can_view((int) $this->vaeiste->id);
        $this->assertFalse(selfpos_manager::can_write((int) $this->vaeiste->id));
        $current = (new selfpos_manager())->current((int) $this->vaeiste->id, 'RNCP100');
        $html = selfpos_screen::tree((int) $this->vaeiste->id, 'RNCP100', selfpos_tree::for_record('RNCP100'), $current, false);

        $this->assertStringContainsString(get_string('selfposlabeldefault2', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('selfposreadlink', 'tool_vae'), $html);
        $this->assertStringNotContainsString(get_string('selfposnodelink', 'tool_vae'), $html);
        // A reader is not told what to write: the two sentences speak of the vaeiste, and the invitation to position
        // oneself is absent.
        $this->assertStringNotContainsString(s(get_string('selfposintro', 'tool_vae')), $html);
        $this->assertStringNotContainsString(s(get_string('selfposvisible', 'tool_vae')), $html);
        $this->assertStringContainsString(s(get_string('selfposvisibleread', 'tool_vae')), $html);
        $this->assertStringContainsString(s(get_string('selfposnotvalidationread', 'tool_vae')), $html);
        $node = $this->node('RNCP100', 'C01', false, $this->manager);
        $this->assertStringNotContainsString('tool-vae-selfpos-form', $node);
        $this->assertStringNotContainsString(s(get_string('selfposvisible', 'tool_vae')), $node);
        $this->assertStringContainsString(s(get_string('selfposvisibleread', 'tool_vae')), $node);
    }

    public function test_a_manager_who_does_not_follow_gets_the_same_refusal_as_for_any_dossier(): void {
        $this->setUser($this->outsider);

        $this->expectException(\required_capability_exception::class);
        access::require_can_view((int) $this->vaeiste->id);
    }

    public function test_another_vaeiste_reads_nothing(): void {
        $this->setUser($this->othervaeiste);

        $this->assertFalse(selfpos_manager::can_write((int) $this->vaeiste->id));
        $this->expectException(\required_capability_exception::class);
        access::require_can_view((int) $this->vaeiste->id);
    }

    public function test_the_direction_reads_every_dossier(): void {
        $this->position('RNCP100', 'C01', 4);
        $this->setUser($this->direction);

        access::require_can_view((int) $this->vaeiste->id);
        $this->assertFalse(selfpos_manager::can_write((int) $this->vaeiste->id));
        $lines = (new selfpos_manager())->lines((int) $this->vaeiste->id, 'RNCP100', 'C01');

        $this->assertCount(1, $lines);
    }

    public function test_the_evaluation_screen_shows_the_selfpositioning_next_to_the_structure_line_with_no_gap(): void {
        $row = $this->position('RNCP100', 'C01', 2);
        $this->position('RNCP100', 'BC01', 3);
        $this->position('RNCP100', 'C02', 1);
        $this->position('RNCP100', 'C02', 0);
        $this->generator->create_evaluation([
            'candidateid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'authorid' => $this->manager->id,
            'status' => 'sufficient',
        ]);
        $this->setUser($this->manager);

        $html = positioning_screen::section((int) $this->vaeiste->id, \context_user::instance($this->vaeiste->id));

        $this->assertStringContainsString(s(get_string('selfposonevaluation', 'tool_vae', (object) [
            'label' => get_string('selfposlabeldefault2', 'tool_vae'),
            'when' => userdate((int) $row->timecreated, get_string('strftimedate', 'langconfig')),
        ])), $html);
        $this->assertStringContainsString(get_string('selfposlabeldefault3', 'tool_vae'), $html, 'The bloc too.');
        $this->assertStringNotContainsString(get_string('selfposlabeldefault1', 'tool_vae'), $html, 'Withdrawn on C02.');
        $this->assertSame(2, substr_count($html, 'tool-vae-selfpos-line'));
        $this->assertStringContainsString(get_string('evalstatussufficient', 'tool_vae'), $html);
        $this->assertDoesNotMatchRegularExpression('/écart|gap/i', $html);

        // The reader has a way to the whole self-positioning, at every level; the vaeiste has theirs to position themselves.
        $this->assertStringContainsString(s(get_string('selfposreadall', 'tool_vae')), $html);
        $this->assertStringContainsString(selfpos_screen::url((int) $this->vaeiste->id)->out(), $html);
        $this->setUser($this->vaeiste);
        $own = positioning_screen::section((int) $this->vaeiste->id, \context_user::instance($this->vaeiste->id));
        $this->assertStringContainsString(s(get_string('selfposlink', 'tool_vae')), $own);
        $this->assertStringNotContainsString(s(get_string('selfposreadall', 'tool_vae')), $own);
    }
}
