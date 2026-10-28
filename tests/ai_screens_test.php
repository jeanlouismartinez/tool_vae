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
use tool_vae\form\section_form;
use tool_vae\local\ai_assistant;
use tool_vae\local\ai_screens;

/**
 * The screens of the reformulation aid and the button that leads to them (T-70, lot 2).
 *
 * WHAT IS ASSERTED. That the confirmation shows the exact text in a field that goes with the form, the name of the provider and
 * the number of characters, and offers no way to send a text that is empty or too long; that the policy and the confirmation are
 * posted forms that carry a session key and the place the text was written; that the result shows the proposal next to the
 * original and says it is to be read, and shows nothing of the provider when there is none; that everything printed is escaped,
 * the proposal first; that the button is drawn on the two forms only when an address is given; and that the text is cleaned the
 * same way for the screen and for the send.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\ai_screens::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\form\ai_button::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\ai_assistant::class)]
final class ai_screens_test extends \advanced_testcase {
    /** @var array What the page passes the screens. */
    private $params = ['source' => 'section', 'doctype' => 'faisabilite', 'section' => 'objectifs', 'slot' => 0];

    /**
     * A candidate, as the current user.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * The back address of the screens.
     *
     * @return \moodle_url
     */
    private function back(): \moodle_url {
        return new \moodle_url('/admin/tool/vae/section.php', ['section' => 'objectifs']);
    }

    public function test_the_confirmation_shows_the_exact_text_the_provider_and_the_count(): void {
        $text = "Mon texte.\nDeuxieme ligne.";
        $carried = ['confirm' => 'abc123', 'mark' => 'def456'];
        $html = ai_screens::confirm($this->params, $text, 'Fournisseur d\'essai', $this->back(), 'Retour', $carried);

        $this->assertStringContainsString('Check what will be sent', $html);
        $this->assertStringContainsString('Fournisseur d&#039;essai', $html);
        $this->assertStringContainsString('26 characters, 3000 at most.', $html);
        // The text is in a field that is read only and goes with the form: the confirmation is of this very text.
        $this->assertMatchesRegularExpression(
            '/<textarea[^>]*readonly[^>]*name="text"[^>]*>Mon texte\.\nDeuxieme ligne\.<\/textarea>/',
            $html
        );
        $this->assertStringContainsString('Send this text to Fournisseur d&#039;essai', $html);
        $this->assertStringContainsString('name="step" value="send"', $html);
        // The token of this confirmation and the mark of the provider it names go back with the text.
        $this->assertStringContainsString('name="confirm" value="abc123"', $html);
        $this->assertStringContainsString('name="mark" value="def456"', $html);
        // And the reminder that the page saved nothing and where the text is.
        $this->assertStringContainsString('opened in a new tab and saved nothing', $html);
        $this->assertStringContainsString('name="sesskey" value="' . sesskey() . '"', $html);
        $this->assertStringContainsString('name="source" value="section"', $html);
        $this->assertStringContainsString('name="section" value="objectifs"', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('ai.php', $html);
        // The reminder not to name anyone is there, every time.
        $this->assertStringContainsString('tool-vae-anonymise', $html);
    }

    public function test_an_empty_or_too_long_text_cannot_be_sent(): void {
        $empty = ai_screens::confirm($this->params, '', 'Fournisseur', $this->back(), 'Retour');
        $this->assertStringNotContainsString('Send this text', $empty);
        $this->assertStringContainsString('There is no text to rephrase.', $empty);

        $toolong = str_repeat('a', ai_assistant::max_chars() + 1);
        $long = ai_screens::confirm($this->params, $toolong, 'Fournisseur', $this->back(), 'Retour');
        $this->assertStringNotContainsString('Send this text', $long);
        $this->assertStringContainsString('The text is too long', $long);
        $this->assertStringContainsString('Retour', $long, 'The way out is always there.');

        $exact = str_repeat('a', ai_assistant::max_chars());
        $edge = ai_screens::confirm($this->params, $exact, 'Fournisseur', $this->back(), 'Retour');
        $this->assertStringContainsString('Send this text to Fournisseur', $edge);
    }

    /**
     * The policy shown is the site's, the core's, and not a text of the plugin's: the one the editor and the course assistant
     * show too, and that the core records one acceptance of for every place.
     */
    public function test_the_policy_is_the_sites_own_and_carries_the_text_to_the_acceptance(): void {
        $html = ai_screens::policy($this->params, 'Mon texte.', $this->back(), 'Retour');

        $this->assertStringContainsString(s(get_string('aiusagepolicy', 'core_ai')), $html);
        $this->assertStringContainsString('Welcome to the new AI feature!', $html);
        $this->assertStringContainsString('<h4>', $html, 'The core\'s text is HTML and is shown as such.');
        $this->assertStringNotContainsString('&lt;h4&gt;', $html);
        $this->assertStringNotContainsString('Name no person you accompanied', $html, 'The plugin carries a policy of its own.');
        $this->assertStringNotContainsString('Policy of use of the rewording aid', $html);
        $this->assertStringContainsString('I have read the policy and I accept it', $html);
        $this->assertStringContainsString('name="step" value="accept"', $html);
        $this->assertStringContainsString('>Mon texte.</textarea>', $html);
        $this->assertStringContainsString('name="sesskey"', $html);
    }

    public function test_the_result_sets_the_proposal_next_to_the_original_and_says_to_read_it(): void {
        $html = ai_screens::result('Une autre formulation.', null, 'Mon texte.', $this->back(), 'Retour');

        $this->assertStringContainsString('A proposal, to read', $html);
        $this->assertStringContainsString('read it against your text', $html);
        $this->assertStringContainsString('Nothing was saved in your dossier', $html);
        $this->assertStringContainsString('>Une autre formulation.</textarea>', $html);
        $this->assertStringContainsString('>Mon texte.</textarea>', $html);
        // Neither field goes back anywhere: the result is the end of the road, and saves nothing.
        $this->assertStringNotContainsString('name="text"', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringContainsString('opened in a new tab and saved nothing', $html);
    }

    public function test_without_a_proposal_only_the_plugins_own_words_are_shown(): void {
        $html = ai_screens::result(null, 'No proposal could be obtained.', 'Mon texte.', $this->back(), 'Retour');

        $this->assertStringContainsString('No proposal could be obtained.', $html);
        $this->assertStringNotContainsString('A proposal, to read', $html);
        $this->assertStringContainsString('>Mon texte.</textarea>', $html, 'The text is given back, so that it is not lost.');
    }

    public function test_everything_printed_is_escaped_the_proposal_first(): void {
        $attack = '</textarea><script>alert(1)</script><img src=x onerror=alert(2)>';

        $result = ai_screens::result($attack, null, $attack, $this->back(), 'Retour');
        $confirm = ai_screens::confirm($this->params, $attack, $attack, $this->back(), 'Retour');
        $policy = ai_screens::policy($this->params, $attack, $this->back(), 'Retour');

        foreach ([$result, $confirm, $policy] as $html) {
            $this->assertStringNotContainsString('<script>', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        }
        // A provider's name or an error that carries markup is escaped as well.
        $this->assertStringNotContainsString('<b>', ai_screens::result(null, '<b>x</b>', 'Mon texte.', $this->back(), 'Retour'));
        $params = ['source' => 'section', 'section' => '"><script>x</script>'];
        $this->assertStringNotContainsString('<script>x', ai_screens::confirm($params, 'a', 'b', $this->back(), 'Retour'));
    }

    public function test_the_text_is_cleaned_the_same_way_for_the_screen_and_the_send(): void {
        $this->assertSame("Une ligne.\nDeux.", ai_assistant::clean("  Une ligne.\r\nDeux.\r  "));
        $this->assertSame('', ai_assistant::clean(" \r\n "));
    }

    public function test_the_button_is_drawn_only_when_an_address_is_given(): void {
        $url = new \moodle_url('/admin/tool/vae/section.php');
        $aiurl = new \moodle_url('/admin/tool/vae/ai.php', ['source' => 'section', 'section' => 'objectifs']);
        $withbutton = new section_form($url->out(false), ['aiurl' => $aiurl]);
        $without = new section_form($url->out(false));

        $html = $this->display($withbutton);
        $this->assertStringContainsString('name="aiask"', $html);
        $this->assertStringContainsString('Propose another wording', $html);
        $this->assertStringContainsString('formaction="' . $aiurl->out(true) . '"', $html);
        $this->assertStringContainsString('formnovalidate', $html);
        // In a new tab, so that the text not yet saved stays where it is.
        $this->assertStringContainsString('formtarget="_blank"', $html);
        // After the buttons of the form: Enter in a field of one line takes the FIRST button for the default one, and must save.
        $this->assertGreaterThan(strpos($html, 'name="submitbutton"'), strpos($html, 'name="aiask"'));
        $this->assertStringNotContainsString('name="aiask"', $this->display($without));

        $declare = new declare_form($url->out(false), ['competencies' => []]);
        $this->assertStringNotContainsString('name="aiask"', $this->display($declare));
        $declarewith = $this->display(new declare_form($url->out(false), ['competencies' => [], 'aiurl' => $aiurl]));
        $this->assertStringContainsString('name="aiask"', $declarewith);
        $this->assertGreaterThan(strpos($declarewith, 'name="submitbutton"'), strpos($declarewith, 'name="aiask"'));
    }

    /**
     * What a form prints.
     *
     * @param \moodleform $form
     * @return string
     */
    private function display(\moodleform $form): string {
        ob_start();
        $form->display();

        return ob_get_clean();
    }
}
