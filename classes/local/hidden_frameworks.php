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

namespace tool_vae\local;

/**
 * The certification records whose competency framework is hidden in Moodle, as the vaeiste meets them.
 *
 * THE RULE STAYS, THE REFUSAL IS MADE CLEAR (decision of the project owner, 27 October 2026). A framework set to
 * "Visible: No" in the competency frameworks screen is not declared on - evidence_manager refuses it, as the core refuses
 * to attach it to a course. This class is how that refusal is SAID to the person who declares: the record named by its title
 * and its code, that it is hidden (the word Moodle uses in French), and whom to ask. It is the only place that writes it,
 * so that the page, the refusal of the write and the carry read one and the same text. The administration screens, which
 * keep their own wording for an administrator, do not come through here.
 *
 * AND IT IS HOW A FORM SENT TOO LATE IS GIVEN BACK. A form opened before the record was hidden cannot choose the competency
 * any more: the list no longer holds it and the form drops a value it does not offer, so the page used to learn nothing and
 * ended on the generic "this competency does not exist", the text lost. sent() reads what the request holds, and the pages
 * give the form back with what was typed; kept_text() is for the page that has no form left to give it back in.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class hidden_frameworks {
    /** @var competency_gateway The only door to the core. */
    private competency_gateway $gateway;

    /**
     * The gateway is injectable, as in the managers.
     *
     * @param competency_gateway|null $gateway
     */
    public function __construct(?competency_gateway $gateway = null) {
        $this->gateway = $gateway ?? new competency_gateway();
    }

    /**
     * Whether the framework of a record is hidden.
     *
     * A framework the core no longer holds is not hidden, it is gone: it has nothing to say to the person who declares, and
     * source_frameworks reports it to the administration.
     *
     * @param \stdClass $source A row of tool_vae_source.
     * @return bool
     */
    public function is_hidden(\stdClass $source): bool {
        $frameworkid = (int) $source->frameworkid;

        return $this->gateway->framework_exists($frameworkid) && !$this->gateway->framework_visible($frameworkid);
    }

    /**
     * The record as the vaeiste reads it: its title and its code, written as text.
     *
     * The title is the name given to the framework at the import. It often holds the code already (« Aide-Soignant
     * (RNCP40692) »), and is then not followed by it again; without a title, the code stands alone.
     *
     * @param \stdClass $source A row of tool_vae_source.
     * @return string Escaped, to be put in a message that is shown as HTML.
     */
    public function label(\stdClass $source): string {
        $code = (string) $source->fichecode;
        $title = trim((string) $this->gateway->framework_name((int) $source->frameworkid));

        if ($title === '') {
            return s($code);
        }

        return s(stripos($title, $code) !== false ? $title : $title . ' (' . $code . ')');
    }

    /**
     * What the vaeiste is told of a hidden record.
     *
     * @param \stdClass $source A row of tool_vae_source.
     * @return string HTML-safe text: the values it holds are escaped.
     */
    public function message(\stdClass $source): string {
        return get_string('errorframeworkhidden', 'tool_vae', $this->label($source));
    }

    /**
     * The refusal of a write on a hidden record, with the same text as the page.
     *
     * @param \stdClass $source A row of tool_vae_source.
     * @return \moodle_exception errorframeworkhidden
     */
    public function exception(\stdClass $source): \moodle_exception {
        return new \moodle_exception('errorframeworkhidden', 'tool_vae', '', $this->label($source));
    }

    /**
     * What the declaration page offers: the competencies of the visible records, and the message for each hidden one.
     *
     * @return \stdClass competencies (keyed by competency id, as competency_gateway::list_framework_competencies() gives
     *         them), outline (the nodes of outline::group(), each with the code of its record in `fichecode`) and hidden
     *         (the message of each hidden record, keyed by its code).
     */
    public function offered(): \stdClass {
        global $DB;

        $competencies = [];
        $outline = [];
        $hidden = [];
        foreach ($DB->get_records('tool_vae_source', null, 'fichecode ASC') as $source) {
            // The record is not left to vanish from the list: the vaeiste is told why it is not there.
            if ($this->is_hidden($source)) {
                $hidden[(string) $source->fichecode] = $this->message($source);
                continue;
            }
            $listed = $this->gateway->list_framework_competencies((int) $source->frameworkid);
            $competencies += $listed;
            foreach (outline::group($listed) as $node) {
                $node->fichecode = $source->fichecode;
                $outline[] = $node;
            }
        }

        return (object) ['competencies' => $competencies, 'outline' => $outline, 'hidden' => $hidden];
    }

    /**
     * The message to give for a competency a form chose, when its record has been hidden since the form was opened.
     *
     * @param int $competencyid What the request names; zero when nothing was chosen.
     * @return string|null Null when the competency is in a record that is visible, or in none.
     */
    public function refusal_for_competency(int $competencyid): ?string {
        global $DB;

        $frameworkid = $this->gateway->framework_of_competency($competencyid);
        if ($frameworkid <= 0) {
            return null;
        }
        $source = $DB->get_record('tool_vae_source', ['frameworkid' => $frameworkid]);

        return $source && $this->is_hidden($source) ? $this->message($source) : null;
    }

    /**
     * What a form sent, as far as a refusal has to give it back.
     *
     * Read from the request and only with the session key of the page: a request that does not carry it is read as empty,
     * and nothing is raised. A parameter that arrives as an array (competencyid[]=1, made by hand) is read as absent, as the
     * form of the core cleaned it, and not raised on. The form drops a competency that the list no longer offers, so the
     * competency is read here.
     *
     * @return \stdClass submitted (whether a form came with the session key), competencyid (0 when none), situation and
     *         settingdetail (the text typed, as the form cleans it), cancelled (whether the cancel button was pressed).
     */
    public static function sent(): \stdClass {
        $sent = (object) [
            'submitted' => false,
            'competencyid' => 0,
            'situation' => '',
            'settingdetail' => '',
            'cancelled' => false,
        ];

        $sesskey = self::single_param('sesskey', '', PARAM_RAW);
        if (!data_submitted() || $sesskey === '' || !confirm_sesskey($sesskey)) {
            return $sent;
        }

        $sent->submitted = true;
        $sent->competencyid = self::single_param('competencyid', 0, PARAM_INT);
        $sent->situation = self::single_param('situation', '', PARAM_TEXT);
        $sent->settingdetail = self::single_param('settingdetail', '', PARAM_TEXT);
        $sent->cancelled = (bool) self::single_param('cancel', 0, PARAM_RAW);

        return $sent;
    }

    /**
     * A parameter of the request, read where the core reads it (the form sent, then the address), an array being read as absent.
     *
     * optional_param() raises a coding exception on an array. That is the rule of the core for a page that expects one value,
     * but the declaration page used to meet such a request through the form of the core, which cleans it, and a request made by
     * hand must not end on an error of coding before any form is built.
     *
     * @param string $name The name of the parameter.
     * @param mixed $default What is given back when the parameter is absent or is not a single value.
     * @param string $type A PARAM_* type, as for optional_param().
     * @return mixed The value, cleaned as optional_param() cleans it.
     */
    private static function single_param(string $name, $default, string $type) {
        $raw = $_POST[$name] ?? $_GET[$name] ?? null;

        return is_array($raw) ? $default : optional_param($name, $default, $type);
    }

    /**
     * The text typed, given back in fields that can be read and copied, for the page that has no form to give it back in.
     *
     * That is the case when every record has been hidden since the form was opened: no competency can be chosen, so there is
     * no form. The page says so, and gives the text back instead of letting it vanish.
     *
     * @param string $situation The narrative.
     * @param string $settingdetail The detail on the setting.
     * @return string HTML, empty when nothing was typed.
     */
    public static function kept_text(string $situation, string $settingdetail = ''): string {
        $html = '';
        foreach (['situation' => $situation, 'settingdetail' => $settingdetail] as $field => $text) {
            if (trim($text) === '') {
                continue;
            }
            $id = 'tool_vae_kept_' . $field;
            $html .= \html_writer::label(get_string($field, 'tool_vae'), $id, true, ['class' => 'font-weight-bold d-block'])
                . \html_writer::tag('textarea', s($text), [
                    'id' => $id,
                    'readonly' => 'readonly',
                    'rows' => $field === 'situation' ? 12 : 2,
                    'cols' => 70,
                    'class' => 'form-control mb-3',
                ]);
        }

        return $html === '' ? '' : \html_writer::tag('p', get_string('declarekeptintro', 'tool_vae')) . $html;
    }
}
