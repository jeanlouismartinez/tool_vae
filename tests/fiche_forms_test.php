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

use tool_vae\form\fiche_confirm_form;
use tool_vae\form\fiche_import_form;

/**
 * The two forms of the import of records from an export of France compétences (0.47.0).
 *
 * WHAT IS ASSERTED. That the first form is a POST with the key of the session, that the button pressed is told apart,
 * that the numbers are checked on the server with the sentence of the plugin, that the date is optional; that the box
 * "also import the older ones" exists only when there is something ready and something older, that the button says how
 * many records go, that there is no button at all when nothing is ready, and that the token is checked by its pattern.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\form\fiche_import_form::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\form\fiche_confirm_form::class)]
final class fiche_forms_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The first form, rendered.
     *
     * @return string
     */
    private static function first_form_html(): string {
        $form = new fiche_import_form(null, ['maxbytes' => 1048576]);
        ob_start();
        $form->display();

        return ob_get_clean();
    }

    /**
     * The second form, rendered.
     *
     * @param int $importable
     * @param int $older
     * @return string
     */
    private static function confirm_form_html(int $importable, int $older): string {
        $form = new fiche_confirm_form(null, ['token' => str_repeat('a', 32), 'importable' => $importable, 'older' => $older]);
        ob_start();
        $form->display();

        return ob_get_clean();
    }

    public function test_the_first_form_is_a_post_with_the_key_of_the_session_and_two_buttons(): void {
        $html = self::first_form_html();

        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="sesskey"', $html);
        $this->assertStringContainsString('name="numbers"', $html);
        $this->assertStringContainsString('name="fetchbutton"', $html);
        $this->assertStringContainsString('name="zipbutton"', $html);
        $this->assertStringContainsString(get_string('fcimportfetchbutton', 'tool_vae'), $html);
        $this->assertStringContainsString(get_string('fcimportzipbutton', 'tool_vae'), $html);
        // The upload is a plain field with a real label, tied to it.
        $this->assertMatchesRegularExpression('/<input[^>]+type="file"[^>]+name="exportzip"/', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="id_exportzip"/', $html);
        $this->assertStringContainsString('name="cancel"', $html);
    }

    public function test_a_submission_without_the_key_of_the_session_gives_nothing(): void {
        fiche_import_form::mock_submit(['numbers' => 'RNCP99001', 'fetchbutton' => 'x']);
        unset($_POST['sesskey'], $_REQUEST['sesskey']);

        // The library of the forms refuses a POST that does not carry the key of the session, before any data is given.
        try {
            $form = new fiche_import_form(null, ['maxbytes' => 1048576]);
            $this->assertNull($form->get_data());
        } catch (\moodle_exception $e) {
            $this->assertSame('missingparam', $e->errorcode);
        }
    }

    public function test_the_button_pressed_is_told_apart(): void {
        fiche_import_form::mock_submit(['numbers' => 'RNCP99001', 'fetchbutton' => 'Aller chercher']);
        $data = (new fiche_import_form(null, ['maxbytes' => 1048576]))->get_data();
        $this->assertNotNull($data);
        $this->assertNotEmpty($data->fetchbutton);
        $this->assertTrue(empty($data->zipbutton));
        $this->assertSame('RNCP99001', trim($data->numbers));
        $this->assertEmpty($data->exportdate, 'The date is optional.');

        fiche_import_form::mock_submit(['numbers' => "RNCP99001\nRS5678", 'zipbutton' => 'Lire']);
        $data = (new fiche_import_form(null, ['maxbytes' => 1048576]))->get_data();
        $this->assertNotNull($data);
        $this->assertNotEmpty($data->zipbutton);
        $this->assertTrue(empty($data->fetchbutton));
    }

    public function test_the_numbers_are_checked_on_the_server_with_the_sentence_of_the_plugin(): void {
        fiche_import_form::mock_submit(['numbers' => '', 'fetchbutton' => 'x']);
        $form = new fiche_import_form(null, ['maxbytes' => 1048576]);
        $this->assertNull($form->get_data());

        // The tags of a text typed are removed by the form before anything else (PARAM_TEXT): the markup never gets as far as the
        // sentence. What survives and is not a number is named as it was typed, escaped.
        fiche_import_form::mock_submit(['numbers' => 'RNCP1 a&b', 'zipbutton' => 'x']);
        $form = new fiche_import_form(null, ['maxbytes' => 1048576]);
        $this->assertNull($form->get_data());
        ob_start();
        $form->display();
        $html = ob_get_clean();

        $this->assertStringContainsString('a&amp;b', $html);
        $this->assertStringContainsString('is not a record number', $html);

        fiche_import_form::mock_submit(['numbers' => '<img src=x onerror=alert(1)> RNCP1', 'zipbutton' => 'x']);
        $data = (new fiche_import_form(null, ['maxbytes' => 1048576]))->get_data();
        $this->assertNotNull($data);
        $this->assertStringNotContainsString('<img', $data->numbers, 'The form took the tag out of the text.');
    }

    public function test_the_box_for_the_older_records_exists_only_when_it_has_a_meaning(): void {
        $both = self::confirm_form_html(2, 1);
        $this->assertStringContainsString('name="allowolder"', $both);
        $this->assertStringContainsString(get_string('fcimportallowolder', 'tool_vae', 1), $both);
        $this->assertStringContainsString(get_string('fcimportconfirm', 'tool_vae', 2), $both);

        $this->assertStringNotContainsString('name="allowolder"', self::confirm_form_html(2, 0), 'Nothing older: no box.');
        $this->assertStringNotContainsString(
            'name="allowolder"',
            self::confirm_form_html(0, 3),
            'Only older ones: the button says it.'
        );
    }

    public function test_the_button_says_how_many_records_go_and_there_is_none_when_nothing_is_ready(): void {
        $this->assertStringContainsString(get_string('fcimportconfirm', 'tool_vae', 3), self::confirm_form_html(3, 0));

        $olderonly = self::confirm_form_html(0, 2);
        $this->assertStringContainsString(get_string('fcimportconfirmolder', 'tool_vae', 2), $olderonly);

        $none = self::confirm_form_html(0, 0);
        $this->assertStringNotContainsString('name="submitbutton"', $none);
        $this->assertStringNotContainsString(get_string('fcimportconfirm', 'tool_vae', 0), $none);
        $this->assertStringContainsString('name="cancel"', $none);
    }

    public function test_the_token_is_a_hidden_field_checked_by_its_pattern(): void {
        $html = self::confirm_form_html(1, 0);
        $this->assertMatchesRegularExpression('/<input[^>]+name="token"[^>]+value="a{32}"/', $html);

        fiche_confirm_form::mock_submit(['token' => '../../x', 'submitbutton' => 'x']);
        $form = new fiche_confirm_form(null, ['token' => str_repeat('a', 32), 'importable' => 1, 'older' => 0]);
        $data = $form->get_data();
        // The form keeps letters and digits only; the page, and the store, check the whole pattern again.
        $this->assertNotNull($data, 'The submission is read, with what the form could keep of the token.');
        $this->assertSame(1, preg_match('/^[A-Za-z0-9]*$/', $data->token));
        $this->assertNotSame('../../x', $data->token);
        $this->assertSame(0, preg_match(\tool_vae\local\fiche_plan_store::TOKEN_PATTERN, '../../x'));
    }

    public function test_enter_in_a_field_does_not_press_the_button_that_goes_out(): void {
        $html = self::first_form_html();

        // The first submit button of a form is the one that Enter presses in a field: it must not make the server go out.
        preg_match_all('/<input[^>]*type="submit"[^>]*>/', $html, $tags);
        $names = array_map(fn($tag) => preg_match('/name="([^"]*)"/', $tag, $found) ? $found[1] : '', $tags[0]);
        $this->assertSame('defaultbutton', $names[0]);
        $this->assertContains('fetchbutton', $names);
        $this->assertContains('zipbutton', $names);
        $this->assertLessThan(array_search('fetchbutton', $names), array_search('defaultbutton', $names));

        // Pressed, it chooses nothing: neither of the two buttons is in the data.
        fiche_import_form::mock_submit(['numbers' => 'RNCP99001', 'defaultbutton' => '']);
        $data = (new fiche_import_form(null, ['maxbytes' => 1048576]))->get_data();
        $this->assertNotNull($data);
        $this->assertTrue(empty($data->fetchbutton) && empty($data->zipbutton));
    }
}
