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
 * The three screens of the reformulation aid, as HTML (T-70, lot 2): the policy, the confirmation and the result.
 *
 * ONLY WORDS AND FIELDS. Nothing here decides whether the aid may be used (ai_assistant does) and nothing here is saved. Every
 * form posts to ai.php, and the text it carries is shown in a field nobody edits on the page: what is displayed is what is
 * submitted, so the candidate confirms the very text that leaves. Everything printed is escaped, the proposal first: it is text
 * from a provider.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class ai_screens {
    /**
     * The policy of use, to accept once. The text is carried through, so that nothing has to be written again.
     *
     * THE SITE'S POLICY, NOT THE PLUGIN'S: the text of the core (core_ai, `userpolicy`), which the site words once, by the
     * customisation of its language, for every place that offers AI - the editor, the course assistant, this aid. The core
     * keeps one acceptance per person, whatever the place, and a second policy of the plugin's own, accepted into that same
     * register, said two things about one act. What belongs to this aid alone - name nobody, what is kept, read it against
     * your text - is on the confirmation, shown at every send, which is where it can be read before the text leaves.
     *
     * The core's text is HTML written by the site, shown the way the core shows it, cleaned: format_text() in the context of
     * the site.
     *
     * @param array $params What tells ai.php where the text was written.
     * @param string $text The candidate's text.
     * @param \moodle_url $back Where the way out leads.
     * @param string $backwords The words of the way out.
     * @return string HTML.
     */
    public static function policy(array $params, string $text, \moodle_url $back, string $backwords): string {
        global $OUTPUT;

        $policy = format_text(get_string('userpolicy', 'core_ai'), FORMAT_HTML, ['context' => ai_assistant::core_context()]);

        return $OUTPUT->heading(s(get_string('aiusagepolicy', 'core_ai')), 3)
            . \html_writer::div($policy, 'ai-policy-display')
            . self::form($params, 'accept', $text, get_string('aipolicyaccept', 'tool_vae'), $back, $backwords);
    }

    /**
     * The confirmation, at every send: the exact text, where it goes, what is kept. It cannot be switched off.
     *
     * @param array $params What tells ai.php where the text was written.
     * @param string $text The candidate's text, as ai_assistant::clean() gives it.
     * @param string $provider The name of the one provider the text will reach.
     * @param \moodle_url $back Where the way out leads.
     * @param string $backwords The words of the way out.
     * @param string[] $carried More fields the send step asks back: the single-use token and the mark of the provider.
     * @return string HTML.
     */
    public static function confirm(
        array $params,
        string $text,
        string $provider,
        \moodle_url $back,
        string $backwords,
        array $carried = []
    ): string {
        global $OUTPUT;

        $max = ai_assistant::max_chars();
        $count = \core_text::strlen($text);
        // What becomes of the text is said as it is on this site: that Moodle empties it once the deletion request is approved
        // only where it does (ai_assistant::moodle_erases_the_texts()), and that it does not where it does not.
        $logged = ai_assistant::moodle_erases_the_texts() ? 'aiconfirmlogged' : 'aiconfirmloggednoerase';
        $html = $OUTPUT->heading(get_string('aiconfirmheading', 'tool_vae'), 3)
            . \html_writer::div(s(get_string('anonymisereminder', 'tool_vae')), 'alert alert-info tool-vae-anonymise', [
                'role' => 'note',
            ])
            . \html_writer::tag('p', s(get_string('aiconfirmwhere', 'tool_vae', $provider)))
            . \html_writer::tag('p', s(get_string('aiconfirmcount', 'tool_vae', ['count' => $count, 'max' => $max])))
            . \html_writer::tag('p', s(get_string($logged, 'tool_vae')));
        if ($text === '') {
            $html .= $OUTPUT->notification(s(get_string('errorainotext', 'tool_vae')), \core\output\notification::NOTIFY_ERROR);
        } else if ($count > $max) {
            $html .= $OUTPUT->notification(
                s(get_string('errorailength', 'tool_vae', $max)),
                \core\output\notification::NOTIFY_ERROR
            );
        }
        $sendable = $text !== '' && $count <= $max;

        return $html . self::form(
            $params,
            'send',
            $text,
            $sendable ? get_string('aiconfirmsend', 'tool_vae', $provider) : '',
            $back,
            $backwords,
            $carried
        );
    }

    /**
     * The result: the proposal next to the original, or why there is none.
     *
     * @param string|null $proposal What the provider proposed; null when it did not.
     * @param string|null $error Why there is none, in the plugin's own words; null when there is a proposal.
     * @param string $text The candidate's text.
     * @param \moodle_url $back Where the way out leads.
     * @param string $backwords The words of the way out.
     * @return string HTML.
     */
    public static function result(?string $proposal, ?string $error, string $text, \moodle_url $back, string $backwords): string {
        global $OUTPUT;

        if ($proposal === null) {
            $html = $OUTPUT->notification(s((string) $error), \core\output\notification::NOTIFY_ERROR);
        } else {
            $html = $OUTPUT->heading(get_string('airesultheading', 'tool_vae'), 3)
                . \html_writer::div(s(get_string('airesultnotice', 'tool_vae')), 'alert alert-warning', ['role' => 'note'])
                . self::text(get_string('airesultproposal', 'tool_vae'), $proposal, '', 'tool-vae-ai-proposal');
        }

        return $html
            . self::text(get_string('aioriginal', 'tool_vae'), $text, '', 'tool-vae-ai-text')
            . \html_writer::tag('p', s(get_string('aibackhint', 'tool_vae')), ['class' => 'text-muted small'])
            . \html_writer::tag('p', \html_writer::link($back, s($backwords), ['class' => 'btn btn-secondary']));
    }

    /**
     * The fields every form of the page carries.
     *
     * @param array $params What tells ai.php where the text was written.
     * @param string $step The step the form leads to.
     * @return string HTML.
     */
    private static function hidden(array $params, string $step): string {
        $html = \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        foreach ($params + ['step' => $step] as $name => $value) {
            $html .= \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => (string) $value]);
        }

        return $html;
    }

    /**
     * A text in a field nobody edits here.
     *
     * @param string $label The label of the field.
     * @param string $text The text.
     * @param string $name The name of the field, empty to send nothing back.
     * @param string $id The id of the field.
     * @return string HTML.
     */
    private static function text(string $label, string $text, string $name, string $id): string {
        $attributes = ['id' => $id, 'readonly' => 'readonly', 'rows' => 12, 'class' => 'form-control mb-2'];
        if ($name !== '') {
            $attributes['name'] = $name;
        }

        return \html_writer::tag('label', s($label), ['for' => $id, 'class' => 'font-weight-bold'])
            . \html_writer::tag('textarea', s($text), $attributes);
    }

    /**
     * A form of the page: the text carried back, a button that goes on and a way out.
     *
     * @param array $params What tells ai.php where the text was written.
     * @param string $step The step the form leads to.
     * @param string $text The text.
     * @param string $button The words of the button that goes on; empty for none.
     * @param \moodle_url $back Where the way out leads.
     * @param string $backwords The words of the way out.
     * @param string[] $carried More hidden fields.
     * @return string HTML.
     */
    private static function form(
        array $params,
        string $step,
        string $text,
        string $button,
        \moodle_url $back,
        string $backwords,
        array $carried = []
    ): string {
        $buttons = '';
        if ($button !== '') {
            $buttons .= \html_writer::empty_tag('input', [
                'type' => 'submit', 'class' => 'btn btn-primary mr-2', 'value' => $button,
            ]);
        }
        $buttons .= \html_writer::link($back, s($backwords), ['class' => 'btn btn-secondary']);

        return \html_writer::tag(
            'form',
            self::hidden($params + $carried, $step)
                . self::text(get_string('aioriginal', 'tool_vae'), $text, 'text', 'tool-vae-ai-text')
                . \html_writer::div($buttons, 'mt-2')
                . \html_writer::tag('p', s(get_string('aibackhint', 'tool_vae')), ['class' => 'text-muted small mt-2']),
            ['method' => 'post', 'action' => (new \moodle_url('/admin/tool/vae/ai.php'))->out(false)]
        );
    }
}
