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

/**
 * Remove the row of an imported record whose competency framework Moodle no longer holds.
 *
 * The gesture a manager needs when a file was imported under the wrong code: delete the framework in Moodle, which decides whether
 * it can go, then come here to remove the row this plugin kept. The page lists what leaves with the row and asks for a
 * confirmation; a record on which a candidate has left a trace, or whose framework Moodle still holds, is refused with the reason.
 *
 * admin_externalpage_setup() protects this page, the right to import is asked again, and record_remover::remove() asks it a third
 * time where it writes, after checking everything again.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_vae\form\remove_record_form;
use tool_vae\local\record_remover;

$fichecode = required_param('fichecode', PARAM_ALPHANUMEXT);
$self = new moodle_url('/admin/tool/vae/remove_record.php', ['fichecode' => $fichecode]);

admin_externalpage_setup('toolvae', '', null, $self);
require_capability('tool/vae:import', context_system::instance());

$index = new moodle_url('/admin/tool/vae/index.php');
$remover = new record_remover();
$refusal = $remover->refusal($fichecode);

// The form is handled before anything is printed: redirect() cannot send a header once the page has begun.
if ($refusal === null) {
    $form = new remove_record_form($self->out(false), ['inventory' => $remover->inventory($fichecode)]);

    if ($form->is_cancelled()) {
        redirect($index);
    }

    if ($form->get_data()) {
        try {
            $removed = $remover->remove($fichecode);
            redirect(
                $index,
                get_string('removerecorddone', 'tool_vae', s($removed->fichecode)),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        } catch (moodle_exception $e) {
            // Somebody wrote on the record, or put a framework back in Moodle, between the screen and the confirmation: the
            // reason, on the list. A second click does not come here: the core's form script disables the button at the first,
            // and a second request that gets through anyway finds no row, so it is refused above and answered by the refusal
            // screen below, not by the list.
            redirect($index, s($e->getMessage()), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('removerecordheading', 'tool_vae', s($fichecode)));

if ($refusal !== null) {
    // Said on the page rather than as an exception: the manager reached it by a button, and is told why there is nothing to do.
    echo $OUTPUT->notification(
        s(get_string($refusal->errorcode, 'tool_vae', $refusal->a)),
        \core\output\notification::NOTIFY_ERROR
    );
    echo $OUTPUT->continue_button($index);
} else {
    $form->display();
}

echo $OUTPUT->footer();
