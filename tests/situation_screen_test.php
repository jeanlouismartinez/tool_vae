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

use tool_vae\form\situation_details_form;
use tool_vae\form\situation_form;
use tool_vae\local\display_date;
use tool_vae\local\draft_manager;
use tool_vae\local\situation_manager;
use tool_vae\local\situation_screen;
use tool_vae\local\spiece_manager;
use tool_vae\local\validation_catalog;
use tool_vae\local\validation_status;

/**
 * The pages "my work situations" and "situation" (lot 1b, task 8), played as the site plays them.
 *
 * WHAT IS ASSERTED. The list says each situation by its title, period, setting, tags, number of pieces and where its text
 * stands; the author gets the forms and a reader none; a reader reads the latest version, then the draft in progress said to
 * be one, under the CURRENT title (D-1b-I); every screen says who reads it and that nothing here is attested; no screen shows a
 * total or a rate; the inverse view of a node lists its situations and its declarations; a send without the session key is
 * refused; a closed preparation gives back the text typed; the pathway and the dossier carry the way in.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(situation_screen::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(situation_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(situation_details_form::class)]
final class situation_screen_test extends \advanced_testcase {
    /** @var \stdClass Declares, positions themselves and writes their preparation. */
    private $vaeiste;

    /** @var \stdClass Follows the vaeiste. */
    private $manager;

    /** @var \stdClass Reads every dossier. */
    private $direction;

    /** @var \stdClass Another vaeiste, with the same rights. */
    private $othervaeiste;

    /** @var \tool_vae_generator */
    private $generator;

    /**
     * RNCP100 (BC01 holding C01 and C02, BC02 holding C03); the people; the preparation and the pieces open; one declaration
     * of the vaeiste on RNCP100 C01.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->preventResetByRollback();
        validation_catalog::reset();
        set_config('validationopen', 1, 'tool_vae');
        set_config('piecesopen', 1, 'tool_vae');

        $datagenerator = $this->getDataGenerator();
        $this->generator = $datagenerator->get_plugin_generator('tool_vae');
        $this->generator->create_source(['fichecode' => 'RNCP100', 'ficherang' => 'BC01', 'shortname' => 'Bloc 1']);
        $this->generator->create_competency(['fichecode' => 'RNCP100', 'ficherang' => 'BC02', 'shortname' => 'Bloc 2']);
        foreach ([['C01', 'BC01'], ['C02', 'BC01'], ['C03', 'BC02']] as [$rank, $bloc]) {
            $this->generator->create_competency([
                'fichecode' => 'RNCP100', 'ficherang' => $rank, 'shortname' => 'Competence ' . $rank, 'bloc' => $bloc,
            ]);
        }

        $system = \context_system::instance()->id;
        $this->vaeiste = $datagenerator->create_user(['firstname' => 'Camille', 'lastname' => 'Vaeiste']);
        $this->manager = $datagenerator->create_user(['firstname' => 'Nadia', 'lastname' => 'Benali']);
        $this->direction = $datagenerator->create_user(['firstname' => 'Dominique', 'lastname' => 'Direction']);

        $own = $datagenerator->create_role();
        foreach (['declare', 'viewown', 'positionself', 'draftown'] as $capability) {
            assign_capability('tool/vae:' . $capability, CAP_ALLOW, $own, $system, true);
        }
        set_role_contextlevels($own, [CONTEXT_USER]);
        role_assign($own, $this->vaeiste->id, \context_user::instance($this->vaeiste->id)->id);
        $this->othervaeiste = $datagenerator->create_user(['firstname' => 'Sacha', 'lastname' => 'Autre']);
        role_assign($own, $this->othervaeiste->id, \context_user::instance($this->othervaeiste->id)->id);
        $follow = $datagenerator->create_role();
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $follow, $system, true);
        set_role_contextlevels($follow, [CONTEXT_USER]);
        role_assign($follow, $this->manager->id, \context_user::instance($this->vaeiste->id)->id);
        $director = $datagenerator->create_role();
        assign_capability('tool/vae:viewany', CAP_ALLOW, $director, $system, true);
        role_assign($director, $this->direction->id, $system);
        accesslib_clear_all_caches_for_unit_testing();

        $this->generator->create_declaration([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'ficherang' => 'C01', 'situation' => 'Une declaration.',
        ]);
    }

    /**
     * A situation of the vaeiste on RNCP100.
     *
     * @param string $title
     * @param string $tags Ranks separated by a comma.
     * @param array $more Other fields for the generator.
     * @return \stdClass
     */
    private function situation(string $title, string $tags = '', array $more = []): \stdClass {
        return $this->generator->create_situation([
            'userid' => $this->vaeiste->id, 'fichecode' => 'RNCP100', 'title' => $title, 'tags' => $tags,
        ] + $more);
    }

    /**
     * A page of the plugin, played as the site plays it, for the current user.
     *
     * @param string $script
     * @param array $get The parameters of the address.
     * @param array $post The fields sent, if the page is sent to.
     * @return string
     */
    private function page(string $script, array $get, array $post = []): string {
        // The page is a script of the site: it reads these as globals.
        global $CFG, $DB, $OUTPUT, $PAGE, $SITE, $USER;

        $_GET = $get;
        $_POST = $post;
        $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
        $PAGE = new \moodle_page();
        ob_start();
        try {
            include(__DIR__ . '/../' . $script);
        } finally {
            $out = ob_get_clean();
            $_GET = [];
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        return $out;
    }

    /**
     * The address parameters of the page of one situation.
     *
     * @param \stdClass $situation
     * @return array
     */
    private function at(\stdClass $situation): array {
        return ['userid' => (int) $this->vaeiste->id, 'fiche' => 'RNCP100', 'slot' => (int) $situation->slot];
    }

    /**
     * Every screen of the situations of the vaeiste, as the current user gets it.
     *
     * @param \stdClass $situation
     * @return string[] Name => HTML.
     */
    private function screens(\stdClass $situation): array {
        $who = ['userid' => (int) $this->vaeiste->id];

        return [
            'records' => $this->page('situations.php', $who),
            'list' => $this->page('situations.php', $who + ['fiche' => 'RNCP100']),
            'inverse' => $this->page('situations.php', $who + ['fiche' => 'RNCP100', 'rank' => 'C01']),
            'situation' => $this->page('situation.php', $this->at($situation)),
        ];
    }

    /**
     * What a reader sees of a page: its text, without the scripts and without the markup.
     *
     * @param string $html
     * @return string
     */
    private function words(string $html): string {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~si', '', $html);

        return html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function test_the_list_shows_title_period_tags_and_piece_count(): void {
        $situation = $this->situation('Accueil du matin', 'C01,C02', [
            'periodstart' => 1756684800, 'periodend' => 1782172800, 'setting' => 'Unite de vie', 'text' => 'Mon texte.',
        ]);
        $this->setUser($this->vaeiste);
        (new spiece_manager())->add_link((int) $situation->id, 'other', 'Un lien', 'https://example.org/a', 'Le debut');
        $this->situation('Nuit agitee');

        $html = $this->page('situations.php', ['fiche' => 'RNCP100']);

        $this->assertStringContainsString('Accueil du matin', $html);
        $this->assertStringContainsString('Nuit agitee', $html);
        $this->assertStringContainsString('Unite de vie', $html);
        $date = get_string('strftimedate', 'langconfig');
        $this->assertStringContainsString(s(display_date::format(1756684800, $date)), $html);
        $this->assertStringContainsString(s(display_date::format(1782172800, $date)), $html);
        $this->assertStringContainsString('C01', $html);
        $this->assertStringContainsString('C02', $html);
        $this->assertStringContainsString(s(get_string('situationpieces', 'tool_vae', 1)), $html);
        $this->assertStringContainsString(s(get_string('situationpieces', 'tool_vae', 0)), $html, 'The other has none.');
        $rows = (new situation_manager())->list((int) $this->vaeiste->id, 'RNCP100');
        $this->assertStringContainsString(s(validation_status::words($rows[(int) $situation->slot]->status)), $html);
        $this->assertStringContainsString(
            'situation.php?userid=' . $this->vaeiste->id . '&amp;fiche=RNCP100&amp;slot=' . $situation->slot,
            $html,
            'The way to the situation.'
        );
        $this->assertStringContainsString(s(get_string('situationnew', 'tool_vae')), $html, 'The author may open a new one.');
        $this->assertStringContainsString('tool-vae-whereami', $html, 'The panel of the record.');
        $this->assertLessThan(strpos($html, 'Nuit agitee'), strpos($html, 'Accueil du matin'), 'In the order of the slots.');

        // Without a record: the records offered, each leading to its list.
        $html = $this->page('situations.php', []);
        $this->assertStringContainsString('situations.php?userid=' . $this->vaeiste->id . '&amp;fiche=RNCP100', $html);

        // No situation yet on a record: said.
        $this->generator->create_source(['fichecode' => 'RNCP200', 'ficherang' => 'BC01', 'shortname' => 'Autre bloc']);
        $html = $this->page('situations.php', ['fiche' => 'RNCP200']);
        $this->assertStringContainsString(s(get_string('situationnone', 'tool_vae')), $html);
    }

    public function test_the_author_sees_the_forms_and_a_reader_sees_none(): void {
        $situation = $this->situation('Accueil du matin', 'C01', ['text' => 'Mon texte.']);
        $this->setUser($this->vaeiste);
        (new spiece_manager())->add_link((int) $situation->id, 'other', 'Un lien', 'https://example.org/a', 'Le debut');

        $html = $this->page('situation.php', $this->at($situation));
        foreach (
            [
                '_qf__tool_vae_form_situation_details_form', '_qf__tool_vae_form_situation_form', 'name="tags[]"',
                'name="content"', 'name="piecefile"', 'name="url"', 'name="part"', 'value="delete"', 'value="detach"',
            ] as $marker
        ) {
            $this->assertStringContainsString($marker, $html, $marker);
        }
        $this->assertStringContainsString(s(get_string('situationmemo', 'tool_vae')), $html, 'The eight prompts.');
        $this->assertStringContainsString('value="C01" id="tool-vae-whereami-tag-C01" class="me-2" checked', $html);
        $this->assertStringContainsString('history.php?draftid=' . $situation->draftid, $html);
        $this->assertStringContainsString(s(get_string('spiecepart', 'tool_vae')), $html);
        $this->assertStringContainsString('Le debut', $html, 'The part concerned of the link.');
        $this->assertStringContainsString('Mon texte.', $html, 'The text in the field.');

        $list = $this->page('situations.php', ['fiche' => 'RNCP100']);
        $this->assertStringContainsString('name="action" value="new"', $list);

        foreach (['manager' => $this->manager, 'direction' => $this->direction] as $who => $reader) {
            $this->setUser($reader);
            $html = $this->page('situation.php', $this->at($situation));
            foreach (
                [
                    '_qf__', 'name="tags[]"', 'name="content"', 'name="piecefile"', 'name="url"', 'name="part"',
                    'name="action"', '<textarea',
                ] as $marker
            ) {
                $this->assertStringNotContainsString($marker, $html, $who . ': ' . $marker);
            }
            $this->assertStringContainsString('Accueil du matin', $html, $who);
            $this->assertStringContainsString('Mon texte.', $html, $who);
            $this->assertStringContainsString('Le debut', $html, $who . ' reads the part concerned.');
            $this->assertStringContainsString(s(get_string('piecelinkopen', 'tool_vae')), $html, $who . ' opens the piece.');
            $this->assertStringContainsString('history.php?draftid=' . $situation->draftid, $html, $who);
            $list = $this->page('situations.php', ['userid' => (int) $this->vaeiste->id, 'fiche' => 'RNCP100']);
            $this->assertStringNotContainsString('name="action"', $list, $who);
            $this->assertStringContainsString('Accueil du matin', $list, $who);
        }

        // The site administrator does not read the preparation (MC-16), its situations included.
        $this->setAdminUser();
        try {
            $this->page('situation.php', $this->at($situation));
            $this->fail('The administrator is refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordraftrefused', $e->errorcode);
        }
        try {
            $this->page('situations.php', ['userid' => (int) $this->vaeiste->id, 'fiche' => 'RNCP100']);
            $this->fail('The administrator is refused the list too.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordraftrefused', $e->errorcode);
        }
    }

    public function test_the_reader_sees_the_latest_version_and_the_working_draft_marked_as_such(): void {
        $situation = $this->situation('Titre ancien', 'C01', ['text' => 'Version un.']);
        $this->setUser($this->vaeiste);
        $situations = new situation_manager();
        $situations->save_version((int) $situation->id, 'Version deux.');
        (new draft_manager())->save_working((int) $situation->draftid, 'Brouillon trois.');

        $this->setUser($this->manager);
        $html = $this->page('situation.php', $this->at($situation));
        $this->assertStringContainsString('Version deux.', $html);
        $this->assertStringNotContainsString('Version un.', $html, 'The latest version only; the earlier ones in the history.');
        $notice = strpos($html, s(get_string('situationworkingnotice', 'tool_vae')));
        $this->assertNotFalse($notice, 'The draft in progress is said to be one.');
        $this->assertLessThan(strpos($html, 'Brouillon trois.'), strpos($html, 'Version deux.'), 'The version first.');
        $this->assertLessThan(strpos($html, 'Brouillon trois.'), $notice, 'The notice before the draft.');

        // A title changed with no new version: the reader reads the CURRENT title (D-1b-I).
        $this->setUser($this->vaeiste);
        $situations->save_details((int) $situation->id, 'Titre nouveau', null, null, '', ['C01']);
        $this->setUser($this->manager);
        $html = $this->page('situation.php', $this->at($situation));
        $this->assertStringContainsString('Titre nouveau', $html);
        $this->assertStringNotContainsString('Titre ancien', $html);
        $list = $this->page('situations.php', ['userid' => (int) $this->vaeiste->id, 'fiche' => 'RNCP100']);
        $this->assertStringContainsString('Titre nouveau', $list);

        // Without a draft in progress, no notice.
        $this->setUser($this->vaeiste);
        $situations->save_version((int) $situation->id, 'Version quatre.');
        $this->setUser($this->manager);
        $html = $this->page('situation.php', $this->at($situation));
        $this->assertStringContainsString('Version quatre.', $html);
        $this->assertStringNotContainsString(s(get_string('situationworkingnotice', 'tool_vae')), $html);
    }

    public function test_every_screen_says_visible_and_not_attested(): void {
        $situation = $this->situation('Accueil du matin', 'C01', ['text' => 'Mon texte.']);
        $notattested = s(get_string('situationnotattested', 'tool_vae'));

        $this->setUser($this->vaeiste);
        foreach ($this->screens($situation) as $name => $html) {
            $this->assertStringContainsString(s(get_string('selfposvisible', 'tool_vae')), $html, $name);
            $this->assertStringContainsString($notattested, $html, $name);
        }
        $this->setUser($this->manager);
        foreach ($this->screens($situation) as $name => $html) {
            $this->assertStringContainsString(s(get_string('selfposvisibleread', 'tool_vae')), $html, $name);
            $this->assertStringNotContainsString(s(get_string('selfposvisible', 'tool_vae')), $html, $name);
            $this->assertStringContainsString($notattested, $html, $name);
        }
    }

    public function test_no_screen_shows_a_total_or_a_percentage(): void {
        // Something to count on every screen: two situations with tags, pieces, versions and a draft in progress.
        $first = $this->situation('Accueil du matin', 'C01,C02', [
            'text' => 'Mon texte.', 'periodstart' => 1756684800, 'setting' => 'Unite de vie',
        ]);
        $second = $this->situation('Nuit agitee', 'C01', ['text' => 'Un autre.']);
        $this->setUser($this->vaeiste);
        $pieces = new spiece_manager();
        $pieces->add_link((int) $first->id, 'other', 'Un lien', 'https://example.org/a', 'Le debut');
        $other = $pieces->add_link((int) $first->id, 'writing', 'Un ecrit', 'https://example.org/b', '');
        $pieces->attach((int) $second->id, (int) $other->id, 'La fin');
        (new situation_manager())->save_version((int) $first->id, 'Mon texte, deuxieme version.');
        (new draft_manager())->save_working((int) $first->draftid, 'En cours.');

        foreach ([$this->vaeiste, $this->manager] as $reader) {
            $this->setUser($reader);
            foreach ($this->screens($first) as $name => $html) {
                $text = $this->words($html);
                $this->assertStringContainsString('RNCP100', $text, $name . ': the screen has something to inspect.');
                if ($name !== 'records') {
                    $this->assertStringContainsString('Accueil du matin', $text, $name);
                }
                $this->assertDoesNotMatchRegularExpression('/\d+\s?%/u', $text, $name . ': no rate.');
                $this->assertDoesNotMatchRegularExpression('/\btotal\b/iu', $text, $name . ': no total.');
                // Two pieces on the first, one (the same writing) on the second: three would be a sum.
                $this->assertStringNotContainsString(s(get_string('situationpieces', 'tool_vae', 3)), $html, $name);
            }
        }
    }

    public function test_the_inverse_view_lists_the_situations_and_the_declarations_of_the_node(): void {
        $this->situation('Accueil du matin', 'C01');
        $this->situation('Nuit agitee', 'C02');
        $this->situation('Toilette', 'C01,C02');

        $this->setUser($this->vaeiste);
        $html = $this->page('situations.php', ['fiche' => 'RNCP100', 'rank' => 'C01']);
        $this->assertStringContainsString(s(get_string('situationsonnode', 'tool_vae', 'C01 - Competence C01')), $html);
        $this->assertStringContainsString('Accueil du matin', $html);
        $this->assertStringContainsString('Toilette', $html);
        $this->assertStringNotContainsString('Nuit agitee', $html, 'Not tagged on the node.');
        $this->assertStringContainsString('Une declaration.', $html, 'The declaration that carries the node, in reading.');
        $this->assertStringNotContainsString('_qf__', $html, 'In reading: no form.');

        $html = $this->page('situations.php', ['fiche' => 'RNCP100', 'rank' => 'C03']);
        $this->assertStringContainsString(s(get_string('situationnone', 'tool_vae')), $html);
        $this->assertStringContainsString(s(get_string('situationnodeclaration', 'tool_vae')), $html);
        $this->assertStringNotContainsString('Une declaration.', $html);

        $this->setUser($this->manager);
        $html = $this->page('situations.php', ['userid' => (int) $this->vaeiste->id, 'fiche' => 'RNCP100', 'rank' => 'C02']);
        $this->assertStringContainsString('Nuit agitee', $html);
        $this->assertStringContainsString('Toilette', $html);
        $this->assertStringNotContainsString('Accueil du matin', $html);
    }

    public function test_a_send_without_sesskey_is_refused(): void {
        global $DB;

        $situation = $this->situation('Accueil du matin', 'C01', ['text' => 'Mon texte.']);
        $this->setUser($this->vaeiste);
        $sends = [
            'new' => ['situations.php', ['fiche' => 'RNCP100'], ['action' => 'new']],
            'delete' => ['situation.php', $this->at($situation), ['action' => 'delete', 'confirm' => 1]],
            'details' => ['situation.php', $this->at($situation), [
                '_qf__tool_vae_form_situation_details_form' => 1, 'title' => 'Sans jeton', 'setting' => '',
                'submitbutton' => 'x',
            ]],
            'version' => ['situation.php', $this->at($situation), [
                '_qf__tool_vae_form_situation_form' => 1, 'content' => 'Sans jeton.', 'workingat' => 0, 'submitbutton' => 'x',
            ]],
            'link' => ['situation.php', $this->at($situation), [
                'action' => 'link', 'nature' => 'other', 'title' => 'x', 'url' => 'https://example.org/b', 'part' => '',
            ]],
        ];
        foreach ($sends as $name => [$script, $get, $post]) {
            try {
                $this->page($script, $get, $post);
                $this->fail($name . ': refused.');
            } catch (\moodle_exception $e) {
                $this->assertMatchesRegularExpression('/sesskey|session/i', $e->errorcode . ' ' . $e->getMessage(), $name);
            }
        }
        $this->assertSame(1, $DB->count_records('tool_vae_situation'), 'Nothing opened, nothing deleted.');
        $row = (new situation_manager())->get((int) $situation->id);
        $this->assertSame('Accueil du matin', $row->title);
        $this->assertCount(1, (new situation_manager())->versions((int) $situation->id));
        $this->assertSame(0, $DB->count_records('tool_vae_spiece'));

        // With the key, the same sends do what they say, then the page goes on (a redirection, which a test sees thrown).
        $withkey = [
            ['situations.php', ['fiche' => 'RNCP100'], ['action' => 'new']],
            ['situation.php', $this->at($situation), [
                'action' => 'link', 'nature' => 'writing', 'title' => 'Un ecrit', 'url' => 'https://example.org/b',
                'part' => 'Les objectifs',
            ]],
            ['situation.php', $this->at($situation), [
                '_qf__tool_vae_form_situation_details_form' => 1, 'title' => 'Avec jeton', 'setting' => 'Unite',
                'tags' => ['C02'], 'submitbutton' => 'x',
            ]],
            ['situation.php', $this->at($situation), [
                '_qf__tool_vae_form_situation_form' => 1, 'content' => 'Avec jeton.', 'workingat' => 0, 'submitbutton' => 'x',
            ]],
        ];
        foreach ($withkey as [$script, $get, $post]) {
            try {
                $this->page($script, $get, $post + ['sesskey' => sesskey()]);
                $this->fail('The page goes on.');
            } catch (\moodle_exception $e) {
                $this->assertSame('redirecterrordetected', $e->errorcode, $e->getMessage());
            }
        }
        $this->assertSame(2, $DB->count_records('tool_vae_situation'));
        $row = (new situation_manager())->get((int) $situation->id);
        $this->assertSame('Avec jeton', $row->title);
        $this->assertSame(['C02'], $row->tags);
        $versions = (new situation_manager())->versions((int) $situation->id);
        $this->assertSame('Avec jeton.', reset($versions)->content);
        $this->assertSame('C02', reset($versions)->snapshot->tags, 'The version takes the snapshot of the current tags.');
        $link = $DB->get_record('tool_vae_slink', ['situationid' => (int) $situation->id], '*', MUST_EXIST);
        $this->assertSame('Les objectifs', $link->part);

        // Deleting asks first, then deletes on the second send; the piece stays.
        $html = $this->page('situation.php', $this->at($situation), ['action' => 'delete', 'sesskey' => sesskey()]);
        $this->assertStringContainsString(s(get_string('situationdeleteconfirm', 'tool_vae')), $html);
        $this->assertSame(2, $DB->count_records('tool_vae_situation'), 'Asked, not done.');
        try {
            $this->page('situation.php', $this->at($situation), ['action' => 'delete', 'confirm' => 1, 'sesskey' => sesskey()]);
            $this->fail('The page goes on to the list.');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('tool_vae_situation', ['id' => (int) $situation->id]));
        $this->assertSame(1, $DB->count_records('tool_vae_spiece'), 'The piece stays.');
    }

    public function test_closed_preparation_keeps_the_typed_text(): void {
        $situation = $this->situation('Accueil du matin', 'C01', ['text' => 'Mon texte.']);
        set_config('validationopen', 0, 'tool_vae');
        $this->setUser($this->vaeiste);

        $html = $this->page('situation.php', $this->at($situation), [
            '_qf__tool_vae_form_situation_form' => 1, 'content' => 'Ce que je tapais.', 'workingat' => 0,
            'submitbutton' => 'x', 'sesskey' => sesskey(),
        ]);
        $this->assertStringContainsString(s(get_string('errorvalidationclosed', 'tool_vae')), $html);
        $this->assertMatchesRegularExpression('~<textarea[^>]*readonly[^>]*>Ce que je tapais\.</textarea>~', $html);
        $this->assertStringContainsString(s(get_string('selfposhiddenkept', 'tool_vae')), $html);
        foreach (['_qf__tool_vae_form_situation_form', '_qf__tool_vae_form_situation_details_form', 'value="delete"'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, 'Closed: ' . $gone);
        }
        $this->assertStringContainsString('Mon texte.', $html, 'Read as it stands.');
        $this->assertCount(1, (new situation_manager())->versions((int) $situation->id), 'Nothing written.');

        // The text comes back only from the vaeiste's own send, with the key.
        $html = $this->page('situation.php', $this->at($situation), [
            '_qf__tool_vae_form_situation_form' => 1, 'content' => 'Sans jeton.', 'submitbutton' => 'x',
        ]);
        $this->assertStringNotContainsString('Sans jeton.', $html);

        // The list says it is closed, and offers no new situation.
        $list = $this->page('situations.php', ['fiche' => 'RNCP100']);
        $this->assertStringContainsString(s(get_string('errorvalidationclosed', 'tool_vae')), $list);
        $this->assertStringNotContainsString('name="action" value="new"', $list);
    }

    public function test_the_pathway_and_the_dossier_carry_the_links(): void {
        $this->setUser($this->vaeiste);
        $html = $this->page('organisation.php', []);
        $this->assertStringContainsString(s(get_string('situationslink', 'tool_vae')), $html);
        // A button of the core: the address is the action of its form, the parameters its hidden fields.
        $this->assertStringContainsString('/admin/tool/vae/situations.php', $html);
        $html = $this->page('dossier.php', []);
        $this->assertStringContainsString(s(get_string('situationslink', 'tool_vae')), $html);

        $read = s(get_string('situationsreadlink', 'tool_vae'));
        $get = ['userid' => (int) $this->vaeiste->id];
        $this->setUser($this->manager);
        $this->assertStringNotContainsString($read, $this->page('dossier.php', $get), 'Nothing written, nothing offered.');

        $this->situation('Accueil du matin', 'C01');
        foreach (['manager' => $this->manager, 'direction' => $this->direction] as $who => $reader) {
            $this->setUser($reader);
            $html = $this->page('dossier.php', $get);
            $this->assertStringContainsString($read, $html, $who);
            $this->assertStringNotContainsString(s(get_string('situationslink', 'tool_vae')), $html, $who . ' does not write.');
        }
        $this->setAdminUser();
        $this->assertStringNotContainsString($read, $this->page('dossier.php', $get), 'Not to the administrator (MC-16).');
    }

    /**
     * Send to a page and expect it to go on (a redirection, which a test sees thrown).
     *
     * @param string $script
     * @param array $get
     * @param array $post
     */
    private function send(string $script, array $get, array $post): void {
        try {
            $this->page($script, $get, $post);
            $this->fail('The page goes on.');
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode, $e->getMessage());
        }
    }

    public function test_the_title_of_a_piece_to_attach_is_escaped(): void {
        $first = $this->situation('Accueil du matin', 'C01');
        $second = $this->situation('Nuit agitee', 'C01');
        $this->setUser($this->vaeiste);
        (new spiece_manager())->add_link((int) $first->id, 'other', 'A & B', 'https://example.org/a', '');

        $html = $this->page('situation.php', $this->at($second));

        $this->assertMatchesRegularExpression('~<option value="\d+">[^<]*A &amp; B~', $html, 'The title escaped in its option.');
        $this->assertStringNotContainsString('A & B', $html);
    }

    public function test_a_hidden_record_opens_no_new_situation_and_shows_its_situations_in_reading(): void {
        global $DB;

        $situation = $this->situation('Accueil du matin', 'C01', ['text' => 'Mon texte.']);
        $DB->set_field('competency_framework', 'visible', 0, ['idnumber' => 'RNCP100']);
        $this->setUser($this->vaeiste);

        // A new situation sent all the same, with the key: refused at the source (create(), errorframeworkhidden).
        $this->send('situations.php', ['fiche' => 'RNCP100'], ['action' => 'new', 'sesskey' => sesskey()]);
        $this->assertSame(1, $DB->count_records(situation_manager::TABLE), 'No new situation.');
        try {
            (new situation_manager())->create((int) $this->vaeiste->id, 'RNCP100');
            $this->fail('Refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorframeworkhidden', $e->errorcode);
        }

        // The situation of a hidden record: in reading, with the message of the record.
        $html = $this->page('situation.php', $this->at($situation));
        $source = $DB->get_record('tool_vae_source', ['fichecode' => 'RNCP100']);
        $this->assertStringContainsString((new \tool_vae\local\hidden_frameworks())->message($source), $html);
        $this->assertStringContainsString('Mon texte.', $html);
        foreach (['_qf__', 'name="action"', 'name="piecefile"', '<textarea'] as $marker) {
            $this->assertStringNotContainsString($marker, $html, 'Hidden: ' . $marker);
        }
    }

    public function test_closed_preparation_keeps_the_typed_title_and_setting(): void {
        $situation = $this->situation('Accueil du matin', 'C01');
        set_config('validationopen', 0, 'tool_vae');
        $this->setUser($this->vaeiste);

        $html = $this->page('situation.php', $this->at($situation), [
            '_qf__tool_vae_form_situation_details_form' => 1, 'title' => 'Titre tape ferme', 'setting' => 'Cadre tape ferme',
            'submitbutton' => 'x', 'sesskey' => sesskey(),
        ]);
        $this->assertStringContainsString('Titre tape ferme', $html);
        $this->assertStringContainsString('Cadre tape ferme', $html);
        $this->assertSame('Accueil du matin', (new situation_manager())->get((int) $situation->id)->title, 'Nothing written.');
    }

    public function test_a_record_or_a_rank_not_written_exactly_is_refused(): void {
        $situation = $this->situation('Accueil du matin', 'C01');
        $this->setUser($this->vaeiste);
        $refused = [
            'record in lower case' => ['situation.php', ['fiche' => 'rncp100', 'slot' => (int) $situation->slot]],
            'rank in lower case' => ['situations.php', ['fiche' => 'RNCP100', 'rank' => 'c01']],
            'rank unknown' => ['situations.php', ['fiche' => 'RNCP100', 'rank' => 'C99']],
            'rank of no shape' => ['situations.php', ['fiche' => 'RNCP100', 'rank' => 'C01<b>']],
        ];
        foreach ($refused as $name => [$script, $get]) {
            try {
                $this->page($script, $get);
                $this->fail($name . ': refused.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errordraftrefused', $e->errorcode, $name);
            }
        }
    }

    public function test_the_name_of_the_vaeiste_is_escaped_in_the_headings(): void {
        global $DB, $PAGE;

        $DB->set_field('user', 'lastname', 'Dupont & Fils', ['id' => (int) $this->vaeiste->id]);
        $situation = $this->situation('Accueil du matin', 'C01');
        $this->setUser($this->manager);

        foreach (
            [
                $this->page('situations.php', ['userid' => (int) $this->vaeiste->id, 'fiche' => 'RNCP100']),
                $this->page('situation.php', $this->at($situation)),
            ] as $html
        ) {
            $this->assertStringContainsString('Dupont &amp; Fils', $html);
            $this->assertStringNotContainsString('Dupont & Fils', $html);
        }
        // The title of the page (the head of the page is not drawn in a test: read on the page itself).
        $this->page('situation.php', $this->at($situation));
        // The core adds the name of the site after it.
        $this->assertStringStartsWith(get_string('situationheading', 'tool_vae', 'Accueil du matin'), $PAGE->title);
    }

    public function test_a_closed_preparation_asks_no_confirmation_of_a_deletion(): void {
        $situation = $this->situation('Accueil du matin', 'C01');
        set_config('validationopen', 0, 'tool_vae');
        $this->setUser($this->vaeiste);

        try {
            $html = $this->page('situation.php', $this->at($situation), ['action' => 'delete', 'sesskey' => sesskey()]);
            $this->assertStringNotContainsString(s(get_string('situationdeleteconfirm', 'tool_vae')), $html);
        } catch (\moodle_exception $e) {
            $this->assertSame('redirecterrordetected', $e->errorcode, 'Said closed and sent back.');
        }
        $this->assertNotEmpty((new situation_manager())->find((int) $this->vaeiste->id, 'RNCP100', (int) $situation->slot));
    }

    public function test_the_file_field_points_at_the_anonymisation_reminder(): void {
        $situation = $this->situation('Accueil du matin', 'C01');
        $this->setUser($this->vaeiste);

        $html = $this->page('situation.php', $this->at($situation));

        $this->assertMatchesRegularExpression(
            '~<input[^>]*name="piecefile"[^>]*aria-describedby="tool_vae_anonymise_spiece"~',
            $html
        );
    }

    public function test_attach_detach_and_remove_go_through_the_page_with_the_key_only(): void {
        global $DB;

        $first = $this->situation('Accueil du matin', 'C01');
        $second = $this->situation('Nuit agitee', 'C01');
        $this->setUser($this->vaeiste);
        $piece = (new spiece_manager())->add_link((int) $first->id, 'other', 'Un lien', 'https://example.org/a', '');
        $pieceid = (int) $piece->id;
        $live = fn(int $situationid) => $DB->count_records('tool_vae_slink', [
            'situationid' => $situationid, 'pieceid' => $pieceid, 'timeremoved' => null,
        ]);

        $acts = [
            'attach' => ['action' => 'attach', 'piece' => $pieceid, 'part' => 'La fin'],
            'detach' => ['action' => 'detach', 'piece' => $pieceid],
            'remove' => ['action' => 'remove', 'piece' => $pieceid],
        ];
        foreach ($acts as $name => $post) {
            try {
                $this->page('situation.php', $this->at($second), $post);
                $this->fail($name . ' without the key: refused.');
            } catch (\moodle_exception $e) {
                $this->assertMatchesRegularExpression('/sesskey|session/i', $e->errorcode . ' ' . $e->getMessage(), $name);
            }
        }
        $this->assertSame(0, $live((int) $second->id), 'Nothing attached without the key.');

        $this->send('situation.php', $this->at($second), $acts['attach'] + ['sesskey' => sesskey()]);
        $this->assertSame(1, $live((int) $second->id), 'Attached.');
        $this->assertSame('La fin', $DB->get_field('tool_vae_slink', 'part', ['situationid' => (int) $second->id]));
        $this->send('situation.php', $this->at($second), $acts['detach'] + ['sesskey' => sesskey()]);
        $this->assertSame(0, $live((int) $second->id), 'Detached.');
        $this->assertSame(1, $live((int) $first->id), 'Still on the first.');
        $this->send('situation.php', $this->at($first), $acts['remove'] + ['sesskey' => sesskey()]);
        $this->assertNotNull($DB->get_field('tool_vae_spiece', 'timeremoved', ['id' => $pieceid]), 'Removed.');
    }

    public function test_a_reader_sends_nothing_and_a_piece_of_another_vaeiste_is_not_attached(): void {
        global $DB;

        $situation = $this->situation('Accueil du matin', 'C01', ['text' => 'Mon texte.']);

        // The manager who follows: the acts of the vaeiste, with the manager's own key, change nothing.
        $this->setUser($this->manager);
        $sends = [
            ['action' => 'link', 'nature' => 'other', 'title' => 'x', 'url' => 'https://example.org/b', 'part' => ''],
            ['action' => 'delete', 'confirm' => 1],
            ['_qf__tool_vae_form_situation_details_form' => 1, 'title' => 'Par le responsable', 'submitbutton' => 'x'],
            ['_qf__tool_vae_form_situation_form' => 1, 'content' => 'Par le responsable.', 'submitbutton' => 'x'],
        ];
        foreach ($sends as $post) {
            $this->page('situation.php', $this->at($situation), $post + ['sesskey' => sesskey()]);
        }
        $row = (new situation_manager())->find((int) $this->vaeiste->id, 'RNCP100', (int) $situation->slot);
        $this->assertNotNull($row, 'Not deleted.');
        $this->assertSame('Accueil du matin', $row->title);
        $this->assertSame(0, $DB->count_records('tool_vae_spiece'));
        $this->assertCount(1, (new situation_manager())->versions((int) $situation->id));

        // A piece of another vaeiste, named by its id: refused, nothing linked.
        $theirs = $this->generator->create_situation([
            'userid' => $this->othervaeiste->id, 'fichecode' => 'RNCP100', 'title' => 'La leur',
        ]);
        $this->setUser($this->othervaeiste);
        $piece = (new spiece_manager())->add_link((int) $theirs->id, 'other', 'Le leur', 'https://example.org/c', '');
        $this->setUser($this->vaeiste);
        $this->send('situation.php', $this->at($situation), [
            'action' => 'attach', 'piece' => (int) $piece->id, 'part' => '', 'sesskey' => sesskey(),
        ]);
        $this->assertSame(0, $DB->count_records('tool_vae_slink', ['situationid' => (int) $situation->id]));
    }
}
