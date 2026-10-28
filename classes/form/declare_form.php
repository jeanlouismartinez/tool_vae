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

use tool_vae\local\evidence_manager;
use tool_vae\local\outline;

// THE PARENT CLASS IS NOT AUTOLOADED. `moodleform` lives in lib/formslib.php, which Moodle's
// class loader knows nothing about: a page that instantiates this form without having loaded
// that library dies on `Class "moodleform" not found`. It happened - on declare.php, the
// central screen of this plugin - and the 101 unit tests did not see it, because every one of
// them requires formslib in its own setUp(). A harness that loads what the page does not is a
// harness that hides a fatal error.
//
// The require goes HERE rather than in each page, which is what the core does for its own
// autoloaded form base, \core\form\persistent: whoever loads the class gets its parent, and
// a page added later cannot forget. $CFG is in scope - core_component::classloader() declares
// it global before including, precisely for this.
require_once($CFG->libdir . '/formslib.php');


/**
 * The declaration form.
 *
 * THE ANONYMISATION REMINDER IS THE CONTROL THAT KEEPS THIS PROJECT IN REGIME 2.
 * The narrative describes what the professional did, never who it was done to. Three things
 * make that true, and all three live here:
 *
 *   1. no field anywhere invites the name of a person receiving care;
 *   2. a visible, non-dismissible reminder sits against each of the three free fields;
 *   3. the manager attesting re-reads the narrative before signing (lot 4).
 *
 * The reminder is rendered as real markup tied to its field by aria-describedby, never as a
 * placeholder: a placeholder disappears at the first keystroke, which is precisely when the
 * person starts writing the sentence the reminder was meant to influence.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class declare_form extends \moodleform {
    /** @var int How many years back the period selectors reach. */
    const PERIOD_SPAN_YEARS = 50;

    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;
        $competencies = $this->_customdata['competencies'] ?? [];
        // Whether the list starts on "Choose...": set by the page that gives the form back after a refusal.
        $choose = !empty($this->_customdata['choose']);

        $mform->addElement('hidden', 'userid');
        $mform->setType('userid', PARAM_INT);

        // The reminder in full, once, before the first field (T-54).
        $mform->addElement('html', self::full_reminder());

        // The competency being claimed. Grouped by bloc when a record has blocs (T-33): one group per bloc, the bloc
        // itself first, then its competencies; the competencies that sit under no bloc together in the last group.
        // A flat record gives the plain list it always did.
        $outline = $this->_customdata['outline'] ?? null;
        if ($outline !== null && outline::has_blocs($outline)) {
            $records = [];
            foreach ($outline as $node) {
                $records[$node->fichecode ?? ''][] = $node;
            }

            $groups = [];
            foreach ($records as $code => $nodes) {
                // A record with no bloc keeps its competencies together, under its code: they are not "under no
                // bloc", the record has none.
                $hasblocs = outline::has_blocs($nodes);
                $others = [];
                foreach ($nodes as $node) {
                    if (!$node->children) {
                        $others[$node->competency->id] = s(self::label($node->competency));

                        continue;
                    }
                    $group = [
                        $node->competency->id => s(get_string(
                            'declareblocitself',
                            'tool_vae',
                            self::label($node->competency)
                        )),
                    ];
                    foreach ($node->children as $child) {
                        $group[$child->id] = s(self::label($child));
                    }
                    $groups[self::label($node->competency)] = $group;
                }
                if ($others) {
                    $groups[$hasblocs ? get_string('declaregroupother', 'tool_vae', $code) : (string) $code] = $others;
                }
            }
            if ($choose) {
                // The core draws a grouped list without its "Choose..." entry: selectgroups::export_for_template() fills a
                // variable it misspells ($optionsgroups) and never uses, so the flag of the element does nothing in 5.3. The
                // entry is written here, as a group WITHOUT A LABEL: the template of the core writes an optgroup only for a group
                // that has one, so the entry stands on its own, first in the list, and is not said twice (a group header and an
                // option of the same name). Nothing is chosen for the person.
                $groups = ['' => ['' => get_string('choosedots')]] + $groups;
            }
            $mform->addElement('selectgroups', 'competencyid', get_string('competency', 'tool_vae'), $groups);
        } else {
            // After a refusal (the record chosen was hidden since the form was opened), nothing is chosen for the person: the
            // list would otherwise fall on its first competency, of another record, and a second send would declare there.
            $options = $choose ? ['' => get_string('choosedots')] : [];
            foreach ($competencies as $competency) {
                // As in the grouped list above: the select writes its labels as they are, and a code is text a manager may
                // rewrite in the competencies screens of the core.
                $options[$competency->id] = s(self::label($competency));
            }
            $mform->addElement('select', 'competencyid', get_string('competency', 'tool_vae'), $options);
        }
        $mform->addRule('competencyid', null, 'required', null, 'client');

        // Period of practice. Moodle's default year list runs from 1900 to 2050; a working life
        // is a few decades and nothing is practised in the future, so the list is narrowed.
        $thisyear = (int) userdate(time(), '%Y');
        $years = ['startyear' => $thisyear - self::PERIOD_SPAN_YEARS, 'stopyear' => $thisyear];
        $mform->addElement('date_selector', 'periodstart', get_string('periodstart', 'tool_vae'), $years);
        $mform->addElement('date_selector', 'periodend', get_string('periodend', 'tool_vae'), $years);

        // Setting: closed list, then an optional free detail.
        $settings = [];
        foreach (evidence_manager::SETTINGS as $setting) {
            $settings[$setting] = get_string('setting:' . $setting, 'tool_vae');
        }
        $mform->addElement('select', 'settingtype', get_string('settingtype', 'tool_vae'), $settings);

        $mform->addElement('html', self::reminder('settingdetail'));
        $mform->addElement(
            'text',
            'settingdetail',
            get_string('settingdetail', 'tool_vae'),
            ['maxlength' => 255, 'size' => 60, 'aria-describedby' => self::described_by('settingdetail')]
        );
        $mform->setType('settingdetail', PARAM_TEXT);
        $mform->addHelpButton('settingdetail', 'settingdetail', 'tool_vae');

        // The narrative.
        $mform->addElement('html', self::reminder('situation'));
        $mform->addElement(
            'textarea',
            'situation',
            get_string('situation', 'tool_vae'),
            ['rows' => 12, 'cols' => 70, 'aria-describedby' => self::described_by('situation')]
        );
        $mform->setType('situation', PARAM_TEXT);
        $mform->addRule('situation', null, 'required', null, 'client');
        $mform->addHelpButton('situation', 'situation', 'tool_vae');

        // No format field: the candidate does not choose one. declare.php stores the narrative
        // as plain text; the manager refuses anything else.

        $this->add_action_buttons(true, $this->_customdata['submitlabel'] ?? get_string('declaresubmit', 'tool_vae'));
        // The aid (T-70): after the buttons of the form, and only when the page gives its address, that is when it is offered.
        ai_button::add($mform, $this->_customdata['aiurl'] ?? null);
    }

    /**
     * A competency as the list shows it: its code and its label.
     *
     * @param \stdClass $competency
     * @return string
     */
    private static function label(\stdClass $competency): string {
        return $competency->idnumber . ' — ' . ($competency->label ?? $competency->shortname);
    }

    /**
     * Keep each reminder attached to its field when the field comes back with an error (T-72).
     */
    public function definition_after_data() {
        parent::definition_after_data();
        $map = [];
        foreach (['settingdetail', 'situation'] as $field) {
            $map['id_' . $field] = [self::reminder_id($field), self::FULL_REMINDER_ID];
        }
        reminder_pointers::apply($map);
    }

    /**
     * Server side checks.
     *
     * Criterion 3 asks for three things at once: the submission is refused, the message
     * identifies the offending field, and the values the person typed are still there. A
     * moodleform returning a keyed array does all three; an exception would do none.
     *
     * Never colour alone - the message is text, attached to its field.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // A competency the list does not offer is dropped by the select, which leaves nothing here. Said by name, on the form
        // that gives back what was typed, and not left to the manager's "unknown competency" on an error page.
        if (empty($data['competencyid'])) {
            $errors['competencyid'] = get_string('required');
        }

        if (
            !empty($data['periodstart']) && !empty($data['periodend'])
                && (int) $data['periodend'] < (int) $data['periodstart']
        ) {
            $errors['periodend'] = get_string('errorperiodreversed', 'tool_vae');
        }

        if (trim((string) ($data['situation'] ?? '')) === '') {
            $errors['situation'] = get_string('errorsituationempty', 'tool_vae');
        }

        if (isset($data['settingdetail']) && \core_text::strlen($data['settingdetail']) > 255) {
            $errors['settingdetail'] = get_string('errorsettingdetailtoolong', 'tool_vae');
        }

        return $errors;
    }

    /** The id of the full reminder, which every free field points at. */
    private const FULL_REMINDER_ID = 'tool_vae_anonymise';

    /**
     * The id an aria-describedby points at, for the short reminder of one field.
     *
     * @param string $field
     * @return string
     */
    private static function reminder_id(string $field): string {
        return 'tool_vae_anonymise_' . $field;
    }

    /**
     * What a free field is described by: its own short reminder, then the full one.
     *
     * @param string $field
     * @return string
     */
    private static function described_by(string $field): string {
        return self::reminder_id($field) . ' ' . self::FULL_REMINDER_ID;
    }

    /**
     * The reminder in full, said once, at the head of the form (T-54).
     *
     * Before, this long text was repeated against every free field: a candidate read it twice
     * before writing a word, and learnt to skip it. Semantic markup, always visible, with no way
     * to fold or dismiss it. It is a mitigation and not a blocking control: the field is free,
     * and no form can stop someone typing a name. Saying so plainly is part of the mitigation.
     *
     * @return string
     */
    private static function full_reminder(): string {
        return \html_writer::div(
            get_string('anonymisereminder', 'tool_vae'),
            'alert alert-info tool-vae-anonymise',
            ['id' => self::FULL_REMINDER_ID]
        );
    }

    /**
     * The short reminder against one free field.
     *
     * Regime 2 rests on the reminder being NEXT TO each free field and impossible to fold away -
     * see DER-referentiel-001. Only its length changed: it is the full one that is said once.
     *
     * @param string $field
     * @return string
     */
    private static function reminder(string $field): string {
        return \html_writer::div(
            get_string('anonymiseshort', 'tool_vae'),
            'alert alert-info tool-vae-anonymise tool-vae-anonymise-short',
            ['id' => self::reminder_id($field)]
        );
    }
}
