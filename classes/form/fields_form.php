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

use tool_vae\local\draft_manager;
use tool_vae\local\faisabilite_fields;

// The parent class is not autoloaded - see declare_form for the story. The require goes here.
require_once($CFG->libdir . '/formslib.php');

/**
 * The form of a section of separate fields: identity, status (T-10, lot 3).
 *
 * What is read from the account is shown first, as text, with the way to change it. The fields are
 * built from faisabilite_fields, so that the form, the check and the stored text cannot disagree.
 * The asterisk is the annex's: it says what the official form demands, and the form still saves a
 * partial section - nothing here judges a dossier complete (D-1).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fields_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition() {
        global $USER;

        $mform = $this->_form;
        $section = (string) $this->_customdata['section'];

        $mform->addElement('hidden', 'draftid');
        $mform->setType('draftid', PARAM_INT);

        $shown = faisabilite_fields::account_values($section, $USER);
        if ($shown) {
            $mform->addElement('header', 'accounthdr', get_string('faisabfromaccount', 'tool_vae'));
            foreach ($shown as $key => $value) {
                $mform->addElement('static', 'account_' . $key, get_string('faisabaccount:' . $key, 'tool_vae'), s($value));
            }
            $mform->addElement(
                'static',
                'accountedit',
                '',
                \html_writer::link(
                    new \moodle_url('/user/edit.php', ['id' => $USER->id]),
                    get_string('faisabeditprofile', 'tool_vae')
                )
            );
        }

        $mform->addElement('header', 'fieldshdr', get_string('faisabfromhere', 'tool_vae'));
        $mform->addElement('static', 'requiredlegend', '', s(get_string('faisabrequiredlegend', 'tool_vae')));

        $countries = get_string_manager()->get_list_of_countries();
        foreach (faisabilite_fields::fields($section) as $field) {
            $key = $field['key'];
            $label = get_string('faisabfield:' . $key, 'tool_vae') . ($field['required'] ? ' *' : '');

            switch ($field['type']) {
                case 'text':
                    $mform->addElement('text', $key, $label, [
                        'maxlength' => faisabilite_fields::TEXT_MAX, 'size' => 50,
                    ]);
                    $mform->setType($key, PARAM_TEXT);
                    break;
                case 'longtext':
                    $mform->addElement('textarea', $key, $label, ['rows' => 5, 'cols' => 60]);
                    $mform->setType($key, PARAM_TEXT);
                    break;
                case 'date':
                    $mform->addElement('date_selector', $key, $label, [
                        'startyear' => 1900, 'stopyear' => (int) date('Y'), 'optional' => true,
                    ]);
                    break;
                case 'country':
                    $mform->addElement('select', $key, $label, ['' => get_string('choosedots')] + $countries);
                    $mform->setType($key, PARAM_ALPHA);
                    break;
                case 'select':
                    $choices = ['' => get_string('choosedots')];
                    foreach ($field['options'] as $option) {
                        $choices[$option] = get_string('faisabfieldlevel', 'tool_vae', $option);
                    }
                    $mform->addElement('select', $key, $label, $choices);
                    $mform->setType($key, PARAM_INT);
                    break;
                case 'multi':
                    $boxes = [];
                    foreach ($field['options'] as $option) {
                        $boxes[] = $mform->createElement(
                            'advcheckbox',
                            $key . '_' . $option,
                            '',
                            get_string('faisabopt:' . $key . '_' . $option, 'tool_vae'),
                            null,
                            [0, 1]
                        );
                    }
                    $mform->addGroup($boxes, $key . '_group', $label, '<br>', false);
                    break;
            }
        }

        $mform->addElement(
            'text',
            'note',
            get_string('draftnote', 'tool_vae'),
            ['maxlength' => draft_manager::NOTE_MAXLENGTH, 'size' => 60]
        );
        $mform->setType('note', PARAM_TEXT);

        $this->add_action_buttons(false, get_string('draftsubmit', 'tool_vae'));
    }

    /**
     * Server side checks: each value must be one the field accepts.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $section = (string) $this->_customdata['section'];

        $values = self::values($section, (object) $data);
        foreach (faisabilite_fields::fields($section) as $field) {
            $key = $field['key'];
            if (!isset($values[$key])) {
                continue;
            }
            try {
                faisabilite_fields::clean($section, [$key => $values[$key]]);
            } catch (\moodle_exception $e) {
                $errors[$field['type'] === 'multi' ? $key . '_group' : $key] = get_string('errordraftfields', 'tool_vae');
            }
        }

        return $errors;
    }

    /**
     * The values of the fields, as faisabilite_fields keeps them, from what the form returned.
     *
     * @param string $section
     * @param \stdClass $data
     * @return array
     */
    public static function values(string $section, \stdClass $data): array {
        $values = [];
        foreach (faisabilite_fields::fields($section) as $field) {
            $key = $field['key'];
            switch ($field['type']) {
                case 'date':
                    $stamp = (int) ($data->{$key} ?? 0);
                    if ($stamp > 0) {
                        $values[$key] = userdate($stamp, '%Y-%m-%d', 99, false);
                    }
                    break;
                case 'multi':
                    $chosen = [];
                    foreach ($field['options'] as $option) {
                        if (!empty($data->{$key . '_' . $option})) {
                            $chosen[] = $option;
                        }
                    }
                    if ($chosen) {
                        $values[$key] = $chosen;
                    }
                    break;
                default:
                    $value = $data->{$key} ?? '';
                    if (is_string($value) || is_int($value)) {
                        $values[$key] = (string) $value;
                    }
            }
        }

        return $values;
    }

    /**
     * The form's initial data from the values kept.
     *
     * @param string $section
     * @param array $values As faisabilite_fields::decode() returns them.
     * @return array
     */
    public static function data_from_values(string $section, array $values): array {
        $data = [];
        foreach (faisabilite_fields::fields($section) as $field) {
            $key = $field['key'];
            if (!isset($values[$key])) {
                continue;
            }
            switch ($field['type']) {
                case 'date':
                    [$year, $month, $day] = array_map('intval', explode('-', $values[$key]));
                    $data[$key] = make_timestamp($year, $month, $day, 0, 0, 0, 99, false);
                    break;
                case 'multi':
                    foreach ($values[$key] as $option) {
                        $data[$key . '_' . $option] = 1;
                    }
                    break;
                default:
                    $data[$key] = $values[$key];
            }
        }

        return $data;
    }
}
