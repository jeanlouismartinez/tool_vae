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

namespace tool_vae\form;

/**
 * The button that asks for a proposal of rewording, under a text field (T-70, lot 2).
 *
 * IT SENDS NOTHING. It posts the form, in a new tab, to the page of the aid (ai.php), which shows the text and asks to confirm:
 * the form is not saved, no provider is reached from here, and the page being written keeps the text that is not saved yet.
 *
 * IT COMES AFTER THE BUTTONS OF THE FORM, so that Enter in a field of one line still saves: the browser takes the first button
 * of the form for the default one.
 *
 * The button is drawn only when the aid is offered to this candidate (ai_assistant::offered()); for anyone else the form is as
 * it was.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ai_button {
    /**
     * Add the button to a form, when an address is given.
     *
     * @param \MoodleQuickForm $mform The form being defined.
     * @param \moodle_url|null $url The address of the page of the aid with what it needs to know where it was asked; null for none.
     */
    public static function add(\MoodleQuickForm $mform, ?\moodle_url $url): void {
        if ($url === null) {
            return;
        }
        // The attribute formaction posts the whole form to the page of the aid without saving it. The validation of the form
        // is skipped, since a text can be reworded before the rest of the form is filled.
        $mform->addElement('submit', 'aiask', get_string('aiaskbutton', 'tool_vae'), [
            'formaction' => $url->out(false),
            // In a new tab: the page being written stays as it is, with the text not yet saved.
            'formtarget' => '_blank',
            'formnovalidate' => 'formnovalidate',
            'data-skip-validation' => 1,
            'class' => 'btn-secondary',
        ]);
    }
}
