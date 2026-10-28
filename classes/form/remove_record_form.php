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

defined('MOODLE_INTERNAL') || die();

use tool_vae\local\display_date;

// Not autoloaded - see withdraw_form.php for the fatal error this line prevents.
require_once($CFG->libdir . '/formslib.php');

/**
 * Confirming the removal of the row of a record whose framework Moodle no longer holds.
 *
 * A separate, deliberate screen rather than a link with a confirmation dialogue: the row cannot be recovered, and what goes with
 * it was typed by hand (the addresses, the referents, the correspondences, the replacing record). The screen lists it, so that
 * the manager sees what leaves before saying yes. The form carries the key of the session like any form of the library, and the
 * removal itself is checked again on the server (record_remover::remove()).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class remove_record_form extends \moodleform {
    /**
     * Build the form. Custom data: `inventory` (what record_remover::inventory() gives for the record).
     */
    protected function definition() {
        $mform = $this->_form;
        $inventory = $this->_customdata['inventory'];

        $mform->addElement('hidden', 'fichecode', $inventory->fichecode);
        $mform->setType('fichecode', PARAM_ALPHANUMEXT);

        $mform->addElement('html', \html_writer::div(s(get_string('removerecordintro', 'tool_vae')), 'alert alert-warning'));
        $mform->addElement('html', self::leaves($inventory));

        $this->add_action_buttons(true, get_string('removerecordbutton', 'tool_vae'));
    }

    /**
     * What leaves with the row, as a list.
     *
     * Everything is written as text: the titles and the addresses were typed by an administrator, the licence and the attribution
     * come from a file, and none of it is markup. Every kind is listed, with its count, so that an absence reads as an absence.
     *
     * @param \stdClass $inventory What record_remover::inventory() gives.
     * @return string HTML.
     */
    public static function leaves(\stdClass $inventory): string {
        $source = $inventory->source;
        $none = get_string('removerecordnone', 'tool_vae');
        $nodate = get_string('removerecordnodate', 'tool_vae');
        $dateformat = get_string('strftimedate', 'langconfig');
        $date = fn($time): string => empty($time) ? $nodate : display_date::format((int) $time, $dateformat);

        $addresses = '';
        foreach ($inventory->refs as $ref) {
            $addresses .= \html_writer::tag('li', s($ref->title) . ' (' . s($ref->url) . ')');
        }
        $refs = s(get_string('removerecordrefs', 'tool_vae', count($inventory->refs)))
            . ($addresses === '' ? '' : \html_writer::tag('ul', $addresses));

        $items = [
            s(get_string('removerecordlicence', 'tool_vae', (object) [
                'licence' => $source->licence,
                'attribution' => $source->attribution,
            ])),
            s(get_string('removerecorddates', 'tool_vae', (object) [
                'updated' => $date($source->sourceupdated),
                'lastimport' => $date($source->lastimport),
            ])),
            $refs,
            s(get_string('removerecordreferents', 'tool_vae', (int) $inventory->referents)),
            s(get_string('removerecordpassages', 'tool_vae', count($inventory->passageids))),
            s(get_string('removerecordmarks', 'tool_vae', (int) $inventory->marks)),
            s(get_string(
                'removerecordsuccessor',
                'tool_vae',
                $inventory->successor === '' ? get_string('supersessionnosuccessor', 'tool_vae') : $inventory->successor
            )),
            s(get_string(
                'removerecordreplaced',
                'tool_vae',
                $inventory->replaced ? implode(', ', $inventory->replaced) : $none
            )),
        ];

        return \html_writer::tag('p', s(get_string('removerecordleaves', 'tool_vae')), ['class' => 'mb-1'])
            . \html_writer::tag('ul', implode('', array_map(fn($item) => \html_writer::tag('li', $item), $items)), [
                'class' => 'tool-vae-removal',
            ]);
    }
}
