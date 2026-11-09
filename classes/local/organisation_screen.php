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
 * What the pathway of a candidate shows: one table per declared record, then the records only a plan names (T-90).
 *
 * ALL THE HTML IN ONE PLACE, SO THAT THE ESCAPING AND THE SECRETS CAN BE TESTED. Names of competencies, of plans and of
 * courses come from the framework, from the core or from a person: all go through s() or format_string(). This class draws
 * what organisation::for_candidate() gives; it reads nothing and decides nothing, and in particular it does not decide who
 * may read: the refusal is the class's, before the page exists.
 *
 * COUNTS, NEVER A VERDICT, as in presenter::coverage(): plain counts and dates, no total, no percentage, no colour that
 * ranks one line above another, a competency with nothing declared shown with its zeros like any other. A bloc line adds
 * up the counts of its own line and of the competencies under it; its courses and plans are its own.
 *
 * FOLLOWED: the candidates a manager follows are drawn from organisation::followed() in the same way: counts of work and
 * plans as the core lets the reader read them, five columns, no date, no total, no action.
 *
 * SITE: the configuration of the scheme is drawn from organisation::site() - the records with what the core holds around
 * them and a number of plans, then the rights, the roles that give them, the cohorts followed through those roles and the
 * settings. Nothing in it is a person but the referents of a bloc.
 *
 * A HIDDEN COURSE HAS NO NAME TO DRAW. The class gives a null fullname to a reader the core keeps the course from: it is
 * drawn as a word, without a link, so that the line does not say "no course" where there is one, and the page does not
 * give what the core would not (decision of 2 November 2026).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class organisation_screen {
    /**
     * The tabs of the page, in the order given: the keys the reader may open (organisation::tabs()).
     *
     * Each tab keeps its link when it is the open one: the core draws a selected tab without an address unless it is told
     * to, and a reader on one tab would have no link to follow back to it.
     *
     * @param string[] $keys organisation::TAB_* keys.
     * @return \tabobject[]
     */
    public static function tabs(array $keys): array {
        $tabs = [];
        foreach ($keys as $key) {
            $tabs[] = new \tabobject(
                $key,
                new \moodle_url('/admin/tool/vae/organisation.php', ['tab' => $key]),
                get_string('orgtab' . $key, 'tool_vae'),
                '',
                true
            );
        }

        return $tabs;
    }

    /**
     * The candidates the reader follows: one line each, with the records they declared on, their plans and two counts.
     *
     * FOLLOWED: counts of work, plans as the core lets the reader read them. Five columns and no more - no date, no total,
     * no action on a dossier. A cell of plans never says "none" over a list the reader's rights may have emptied.
     *
     * @param \stdClass $data What organisation::followed() gives.
     * @param \moodle_url $action The address of the page: the filter by bloc sends its choice there.
     * @return string HTML.
     */
    public static function followed(\stdClass $data, \moodle_url $action): string {
        if (!$data->candidates) {
            return self::notice(get_string('nocandidates', 'tool_vae'));
        }

        $out = self::followed_filter($data, $action);

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable tool-vae-organisation tool-vae-followed';
        $table->head = [
            get_string('candidate', 'tool_vae'),
            get_string('orgcolfiches', 'tool_vae'),
            get_string('orgcolplans', 'tool_vae'),
            get_string('candidatedeclarations', 'tool_vae'),
            get_string('candidatepending', 'tool_vae'),
        ];
        foreach ($data->candidates as $candidate) {
            $name = new \html_table_cell(\html_writer::link(
                new \moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidate->id]),
                s($candidate->name)
            ));
            $name->header = true;
            $name->scope = 'row';
            $table->data[] = new \html_table_row([
                $name,
                self::fiches($candidate->fiches),
                self::followed_plans($candidate),
                (string) $candidate->declarations,
                (string) $candidate->pending,
            ]);
        }

        return $out . \html_writer::table($table);
    }

    /**
     * The form that narrows the counts to a bloc the reader is referent of, and the sentence that says it is narrowed.
     *
     * Offered only with a choice; it carries the tab, so that the answer comes back to the same tab.
     *
     * @param \stdClass $data What organisation::followed() gives.
     * @param \moodle_url $action The address of the page.
     * @return string HTML, empty without a choice.
     */
    private static function followed_filter(\stdClass $data, \moodle_url $action): string {
        if (!$data->choices) {
            return '';
        }

        $options = [get_string('candidatesfilterall', 'tool_vae')];
        foreach ($data->choices as $key => $referent) {
            $options[$key] = $referent->fichecode . ' — ' . ($referent->ficherang === ''
                ? get_string('referentwhole', 'tool_vae')
                : get_string('referentblocrank', 'tool_vae', $referent->ficherang));
        }
        $form = \html_writer::tag('label', s(get_string('candidatesfilter', 'tool_vae')), ['for' => 'candidatesbloc'])
            // The select does not escape its labels, and a rank is whatever the competency tree holds.
            . ' ' . \html_writer::select(array_map('s', $options), 'bloc', $data->chosen, false, ['id' => 'candidatesbloc'])
            . \html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'followed'])
            . ' ' . \html_writer::empty_tag('input', [
                'type' => 'submit', 'class' => 'btn btn-secondary', 'value' => get_string('candidatesfilterapply', 'tool_vae'),
            ]);
        $out = \html_writer::tag('form', $form, ['method' => 'get', 'action' => $action->out_omit_querystring()]);
        if ($data->chosen !== '') {
            $out .= \html_writer::tag('p', s(get_string('candidatesfiltered', 'tool_vae', $options[$data->chosen])));
        }

        return $out;
    }

    /**
     * The records a candidate declared on, code and name.
     *
     * @param \stdClass[] $fiches fichecode, name.
     * @return string HTML.
     */
    private static function fiches(array $fiches): string {
        if (!$fiches) {
            return s(get_string('orgnone', 'tool_vae'));
        }

        $items = [];
        foreach ($fiches as $fiche) {
            $items[] = \html_writer::tag('li', s($fiche->name === $fiche->fichecode
                ? $fiche->fichecode
                : $fiche->fichecode . ' — ' . $fiche->name));
        }

        return \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled mb-0']);
    }

    /**
     * The plans cell of a followed candidate, told apart by what the core lets the reader read of them.
     *
     * Neither right: "not readable", never "none". One of the two: the plans of that kind, followed by the sentence that
     * says the list may be incomplete - an empty list is then the sentence alone. Both: the list, or "none" when there is
     * truly none.
     *
     * @param \stdClass $candidate As organisation::followed() gives it.
     * @return string HTML.
     */
    private static function followed_plans(\stdClass $candidate): string {
        if (!$candidate->plansreadable && !$candidate->draftsreadable) {
            return s(get_string('orgplansunreadable', 'tool_vae'));
        }
        if ($candidate->plansreadable && $candidate->draftsreadable) {
            return self::plans($candidate->plans);
        }

        $partial = \html_writer::tag('p', s(get_string('orgplanspartial', 'tool_vae')), ['class' => 'mb-0 text-muted']);

        return ($candidate->plans ? self::plans($candidate->plans) : '') . $partial;
    }
    /**
     * The configuration of the scheme: the records and their surroundings, then the rights and what stands behind them.
     *
     * SITE: nothing here is a person but the referents of a bloc. The number of plans is drawn as it comes, with the
     * sentence that says what it counts. A right with no role is a line with the word "no role", never an omitted line;
     * a record whose framework is gone is a line with dashes, never an omitted one.
     *
     * @param \stdClass $data What organisation::site() gives.
     * @return string HTML.
     */
    public static function site(\stdClass $data): string {
        $out = \html_writer::tag('h3', s(get_string('orgcolfiches', 'tool_vae')));
        if ($data->fiches === null) {
            $out .= self::notice(get_string('errorcompetenciesdisabled', 'tool_vae'));
        } else if (!$data->fiches) {
            $out .= self::notice(get_string('orgsitenofiche', 'tool_vae'));
        } else {
            $out .= self::site_fiches($data->fiches);
            $out .= \html_writer::tag('p', s(get_string('orgplansnote', 'tool_vae')), ['class' => 'text-muted small']);
        }

        $out .= \html_writer::tag('h3', s(get_string('orgpowersheading', 'tool_vae')));
        $out .= \html_writer::tag('p', s(get_string('orgrolesatsite', 'tool_vae')), ['class' => 'text-muted small']);
        $out .= \html_writer::tag('h4', s(get_string('orgactorsheading', 'tool_vae')));
        $out .= \html_writer::tag('p', s(get_string('orgactorsintro', 'tool_vae')), ['class' => 'text-muted small']);
        $out .= self::site_actors($data->powers->actors);
        $out .= \html_writer::tag('h4', s(get_string('orgactorcapheading', 'tool_vae')));
        $out .= self::site_capabilities($data->powers->capabilities);
        $out .= \html_writer::tag('h3', s(get_string('orgcohortsheading', 'tool_vae')));
        $out .= self::site_cohorts($data->powers->cohorts);
        $out .= \html_writer::tag('h3', s(get_string('orgsettingsheading', 'tool_vae')));
        $out .= self::site_settings($data->powers->settings);

        return $out;
    }

    /**
     * The table of the records of the site.
     *
     * @param \stdClass[] $fiches As organisation::site() gives them.
     * @return string HTML.
     */
    private static function site_fiches(array $fiches): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable tool-vae-organisation tool-vae-site-fiches';
        $table->caption = get_string('orgsitefiches', 'tool_vae');
        $table->captionhide = true;
        $table->head = [
            get_string('orgcolfiches', 'tool_vae'),
            get_string('orgcolblocs', 'tool_vae'),
            get_string('orgcolcompetencies', 'tool_vae'),
            get_string('orgcolcourses', 'tool_vae'),
            get_string('orgcolactivities', 'tool_vae'),
            get_string('orgcolmodels', 'tool_vae'),
            get_string('orgcolplancount', 'tool_vae'),
            get_string('orgcolreferents', 'tool_vae'),
            get_string('orgcolrules', 'tool_vae'),
        ];
        $dash = get_string('orgnoblocs', 'tool_vae');
        foreach ($fiches as $fiche) {
            $title = \html_writer::tag('strong', s($fiche->fichecode));
            if ($fiche->name !== $fiche->fichecode) {
                // The name typed at the import is the framework's: shown as the core shows it, filters included.
                $title .= ' — ' . format_string($fiche->name, true, ['context' => \context_system::instance()]);
            }
            $head = new \html_table_cell($title);
            $head->header = true;
            $head->scope = 'row';
            $table->data[] = new \html_table_row([
                $head,
                $fiche->blocs === null || $fiche->blocs === 0 ? s($dash) : (string) $fiche->blocs,
                $fiche->competencies === null ? s($dash) : (string) $fiche->competencies,
                self::courses($fiche->courses, $fiche->coursesunreadable),
                self::activity_count($fiche->activities, $fiche->activitiesunreadable, $fiche->fichecode),
                self::models($fiche->models),
                (string) $fiche->plans,
                self::referents($fiche->referents),
                self::rules($fiche->rules),
            ]);
        }

        return \html_writer::table($table);
    }

    /**
     * The grid of the actors: one line per right, the roles that give it, and the word that says when none does.
     *
     * The actors without an account are a sentence each, below. The names of the roles come from role_get_names(): they
     * are already HTML, and escaping them again would show an "&amp;" of a name as the letters of the entity.
     *
     * @param \stdClass[] $actors As actors::grid() gives them.
     * @return string HTML.
     */
    private static function site_actors(array $actors): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable tool-vae-organisation tool-vae-site-actors';
        $table->caption = get_string('orgactorcaption', 'tool_vae');
        $table->captionhide = true;
        $table->head = [
            get_string('orgactorcolactor', 'tool_vae'),
            get_string('orgactorcolright', 'tool_vae'),
            get_string('orgactorcolroles', 'tool_vae'),
        ];
        $notes = [];
        foreach ($actors as $actor) {
            $name = s(get_string('orgactor' . $actor->key, 'tool_vae'));
            if (!$actor->account) {
                $notes[] = \html_writer::tag('li', \html_writer::tag('strong', $name) . ' - '
                    . s(get_string('orgactor' . $actor->key . 'note', 'tool_vae')));
                continue;
            }
            foreach ($actor->rights as $right) {
                $who = new \html_table_cell($name);
                $who->header = true;
                $who->scope = 'row';
                $table->data[] = new \html_table_row([
                    $who,
                    s($right->label) . ' ' . \html_writer::tag('code', s($right->name), ['class' => 'small']),
                    self::actor_roles($right),
                ]);
            }
        }

        return \html_writer::table($table) . ($notes
            ? \html_writer::tag('ul', implode('', $notes), ['class' => 'list-unstyled small'])
            : '');
    }

    /**
     * What the third cell of a right says: the roles that give it, or the word for none.
     *
     * @param \stdClass $right As actors::grid() gives it.
     * @return string HTML.
     */
    private static function actor_roles(\stdClass $right): string {
        if ($right->state === \tool_vae\local\actors::GIVEN) {
            $text = implode(', ', $right->roles);
        } else if ($right->state === \tool_vae\local\actors::MISSING) {
            $key = $right->adminmay ? 'orgactormissingadmin' : 'orgactormissingnobody';
            $badge = \html_writer::tag(
                'span',
                s(get_string('orgactormissing', 'tool_vae')),
                ['class' => 'badge bg-warning text-dark']
            );
            $text = $badge . ' ' . s(get_string($key, 'tool_vae'));
        } else {
            $key = $right->status === \tool_vae\local\actors::ADMIN ? 'orgactorfreeadmin' : 'orgactorfreeoptional';
            $text = s(get_string($key, 'tool_vae'));
        }
        if ($right->forbidden) {
            // The names are HTML already (role_get_names): carried as they are, not escaped again.
            $text .= ' (' . get_string('orgactorforbiddenby', 'tool_vae', implode(', ', $right->forbidden)) . ')';
        }

        return $text;
    }

    /**
     * The templates of the core that carry a competency of a record.
     *
     * @param \stdClass[] $models templateid, name (null when the core keeps it), visible.
     * @return string HTML.
     */
    private static function models(array $models): string {
        if (!$models) {
            return s(get_string('orgnone', 'tool_vae'));
        }

        $items = [];
        foreach ($models as $model) {
            if ($model->name === null) {
                $items[] = \html_writer::tag('li', s(get_string('orgmodelhidden', 'tool_vae')));
                continue;
            }
            $item = format_string($model->name, true, ['context' => \context_system::instance()]);
            if (!$model->visible) {
                $mark = s(get_string('orgcoursehiddenmark', 'tool_vae'));
                $item .= ' ' . \html_writer::tag('span', $mark, ['class' => 'small text-muted']);
            }
            $items[] = \html_writer::tag('li', $item);
        }

        return \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled mb-0']);
    }

    /**
     * The referents of a record, by bloc: the names of those whose account is active.
     *
     * @param \stdClass[] $referents ficherang (empty for the whole record), names.
     * @return string HTML.
     */
    private static function referents(array $referents): string {
        if (!$referents) {
            return s(get_string('orgnone', 'tool_vae'));
        }

        $items = [];
        foreach ($referents as $referent) {
            $where = $referent->ficherang === ''
                ? get_string('referentwhole', 'tool_vae')
                : get_string('referentblocrank', 'tool_vae', $referent->ficherang);
            $who = $referent->names ? implode(', ', array_map('s', $referent->names)) : s(get_string('orgnoreferent', 'tool_vae'));
            $items[] = \html_writer::tag('li', get_string('orgreferentline', 'tool_vae', (object) [
                'where' => s($where),
                'who' => $who,
            ]));
        }

        return \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled mb-0']);
    }

    /**
     * The addresses where the certifier publishes its rule, as links.
     *
     * @param \stdClass[]|null $rules title, url; null where the setting that opens the addresses is closed.
     * @return string HTML.
     */
    private static function rules(?array $rules): string {
        if ($rules === null) {
            return s(get_string('orgrulesclosed', 'tool_vae'));
        }
        if (!$rules) {
            return s(get_string('orgnone', 'tool_vae'));
        }

        $items = [];
        foreach ($rules as $rule) {
            $items[] = \html_writer::tag('li', \html_writer::link(new \moodle_url($rule->url), s($rule->title)));
        }

        return \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled mb-0']);
    }

    /**
     * A list of role names, or the word that says there is none.
     *
     * The names come from role_get_names(), which has already put them through format_string(): they are HTML, and
     * escaping them again would show an "&amp;" of a name as the letters of the entity.
     *
     * @param string[] $names Names ready to be written as HTML.
     * @param string $none The key of the word.
     * @return string HTML.
     */
    private static function role_names(array $names, string $none): string {
        if (!$names) {
            return s(get_string($none, 'tool_vae'));
        }

        return \html_writer::tag('ul', implode('', array_map(
            fn($name) => \html_writer::tag('li', $name),
            $names
        )), ['class' => 'list-unstyled mb-0']);
    }

    /**
     * The rights of the plugin and the roles of the site that give, withhold or forbid each.
     *
     * @param \stdClass[] $capabilities name, label, allow, prevent, prohibit.
     * @return string HTML.
     */
    private static function site_capabilities(array $capabilities): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable tool-vae-organisation tool-vae-site-powers';
        $table->caption = get_string('orgsitepowers', 'tool_vae');
        $table->captionhide = true;
        $table->head = [
            get_string('orgcolcapability', 'tool_vae'),
            get_string('orgcolroles', 'tool_vae'),
            get_string('orgcolrolesprevent', 'tool_vae'),
            get_string('orgcolrolesprohibit', 'tool_vae'),
        ];
        foreach ($capabilities as $capability) {
            $code = \html_writer::tag('code', s($capability->name), ['class' => 'small']);
            $head = new \html_table_cell(s($capability->label) . ' ' . $code);
            $head->header = true;
            $head->scope = 'row';
            $table->data[] = new \html_table_row([
                $head,
                self::role_names($capability->allow, 'orgnorole'),
                self::role_names($capability->prevent, 'orgnone'),
                self::role_names($capability->prohibit, 'orgnone'),
            ]);
        }

        return \html_writer::table($table);
    }

    /**
     * The cohorts whose members a manager follows through a role of the plugin.
     *
     * @param \stdClass[]|null $cohorts cohortid, name (or null), roleid, rolename; null when the site has no such tool.
     * @return string HTML.
     */
    private static function site_cohorts(?array $cohorts): string {
        if ($cohorts === null) {
            return self::notice(get_string('orgcohortsunavailable', 'tool_vae'));
        }
        if (!$cohorts) {
            return \html_writer::tag('p', s(get_string('orgnone', 'tool_vae')));
        }

        $table = new \html_table();
        $table->attributes['class'] = 'generaltable tool-vae-organisation tool-vae-site-cohorts';
        $table->caption = get_string('orgcohortsheading', 'tool_vae');
        $table->captionhide = true;
        $table->head = [get_string('orgcolcohort', 'tool_vae'), get_string('orgcolcohortrole', 'tool_vae')];
        foreach ($cohorts as $cohort) {
            $name = new \html_table_cell($cohort->name === null
                ? s(get_string('orgcohortgone', 'tool_vae'))
                : format_string($cohort->name, true, ['context' => \context_system::instance()]));
            $name->header = true;
            $name->scope = 'row';
            // The name of the role has been through format_string() already (see role_names()).
            $table->data[] = new \html_table_row([$name, $cohort->rolename]);
        }

        return \html_writer::table($table);
    }

    /**
     * The settings that open or close a part of the scheme, each with its state.
     *
     * @param \stdClass[] $settings name, value, open.
     * @return string HTML.
     */
    private static function site_settings(array $settings): string {
        $table = new \html_table();
        $table->attributes['class'] = 'generaltable tool-vae-organisation tool-vae-site-settings';
        $table->caption = get_string('orgsettingsheading', 'tool_vae');
        $table->captionhide = true;
        $table->head = [get_string('orgcolsetting', 'tool_vae'), get_string('orgcolstate', 'tool_vae')];
        foreach ($settings as $setting) {
            $name = new \html_table_cell(s(get_string($setting->name, 'tool_vae')));
            $name->header = true;
            $name->scope = 'row';
            if ($setting->name === 'overduedays') {
                $state = get_string('orgclosed', 'tool_vae');
                if ($setting->open) {
                    $state = (int) $setting->value === 1
                        ? get_string('orgdayone', 'tool_vae')
                        : get_string('orgdays', 'tool_vae', $setting->value);
                }
            } else {
                $state = get_string($setting->open ? 'orgopen' : 'orgclosed', 'tool_vae');
            }
            $table->data[] = new \html_table_row([$name, s($state)]);
        }

        return \html_writer::table($table);
    }

    /**
     * The tables of the declared records, then the list of the records a plan names without a declaration.
     *
     * @param \stdClass $pathway What organisation::for_candidate() gives.
     * @return string HTML, empty when there is neither.
     */
    public static function mine(\stdClass $pathway): string {
        // The pieces are a function the site opens: closed, a column of zeros would say "no piece" of something the person
        // cannot do. The column stays as soon as the person holds a piece, whatever the setting is now.
        $showpieces = piece_manager::is_open() || self::holds_pieces($pathway);

        $out = '';
        foreach ($pathway->declared as $record) {
            $out .= self::record($record, $pathway->plansreadable, $showpieces);
        }
        if ($pathway->planned) {
            $out .= self::planned($pathway->planned);
        }
        // The records only a plan names cannot be listed when the plans cannot be read: say so, once, not by leaving them out.
        if ($pathway->declared && !$pathway->plansreadable) {
            $out .= \html_writer::tag('p', s(get_string('orgplansunreadablenote', 'tool_vae')), ['class' => 'small text-muted']);
        }

        return $out;
    }

    /**
     * Whether the person holds a piece on any line of their pathway.
     *
     * @param \stdClass $pathway What organisation::for_candidate() gives.
     * @return bool
     */
    private static function holds_pieces(\stdClass $pathway): bool {
        foreach ($pathway->declared as $record) {
            foreach ($record->competencies as $row) {
                if ($row->pieces > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The sentence for a pathway with nothing in it: no declaration, and no record a plan names.
     *
     * Told apart from a reader whose plans are hidden by the site: "no plan names a record" would be false, since the
     * plans are there and only unreadable.
     *
     * @param \stdClass $pathway What organisation::for_candidate() gives.
     * @return string HTML, a box that stays on the page.
     */
    public static function nothing(\stdClass $pathway): string {
        return self::notice(get_string($pathway->plansreadable ? 'orgnofiche' : 'orgnoficheunreadable', 'tool_vae'));
    }

    /**
     * A sentence of the page in an information box that cannot be closed.
     *
     * Not a notification of the core: it is dismissed with a click and does not come back, and the sentences of this page
     * say what the figures do not say ("the panel alone decides"), which must not disappear with one.
     *
     * @param string $text Plain text.
     * @return string HTML.
     */
    public static function notice(string $text): string {
        return \html_writer::div(s($text), 'alert alert-info');
    }

    /**
     * One declared record: its title, where its text comes from, and the table of its lines.
     *
     * @param \stdClass $record As organisation::for_candidate() gives it in declared.
     * @param bool $plansreadable Whether the reader may read plans at all.
     * @param bool $showpieces Whether the column of pieces is drawn.
     * @return string HTML.
     */
    private static function record(\stdClass $record, bool $plansreadable, bool $showpieces): string {
        global $OUTPUT;

        $dateformat = get_string('strftimedate', 'langconfig');
        $title = $record->fichecode;
        if ($record->name !== $record->fichecode) {
            $title .= ' — ' . $record->name;
        }
        if ($record->source) {
            $title .= ' — ' . get_string($record->source->fichetype === 'rs' ? 'typers' : 'typerncp', 'tool_vae');
        }

        $out = \html_writer::start_tag('section', ['class' => 'tool-vae-organisation-record mb-4']);
        $out .= $OUTPUT->heading(s($title), 3);
        if ($record->source) {
            // The labels of the record are printed: the licence asks for the attribution wherever they are.
            $out .= presenter::provenance($record->source);
        }
        if ($record->hasblocs) {
            $out .= \html_writer::tag('p', s(get_string('orgblocnote', 'tool_vae')), ['class' => 'small text-muted']);
        }

        $table = new \html_table();
        $table->caption = get_string('orgcaption', 'tool_vae', s($record->fichecode));
        $table->captionhide = true;
        $table->head = array_merge(
            [get_string('orgcolcompetency', 'tool_vae'), get_string('orgcoldeclarations', 'tool_vae')],
            $showpieces ? [get_string('orgcolpieces', 'tool_vae')] : [],
            [
                get_string('orgcolattested', 'tool_vae'),
                get_string('orgcollastact', 'tool_vae'),
                get_string('orgcolcourses', 'tool_vae'),
                get_string('orgcolactivities', 'tool_vae'),
                get_string('orgcolplans', 'tool_vae'),
            ]
        );
        $table->attributes['class'] = 'generaltable tool-vae-organisation';

        foreach ($record->competencies as $row) {
            $label = s($row->idnumber);
            if ($row->shortname !== null) {
                $label .= ' — ' . s($row->shortname);
            }
            if ($row->gone) {
                $label .= \html_writer::div(s(get_string('coveragegone', 'tool_vae')), 'small');
            }

            $declarations = $row->declarations;
            $pieces = $row->pieces;
            $attested = $row->attested;
            $last = $row->lastact;
            if ($row->isbloc) {
                $label = \html_writer::tag('strong', $label) . ' '
                    . \html_writer::tag('span', s(get_string('coveragebloctag', 'tool_vae')), ['class' => 'small text-muted']);
                $declarations = $row->blocdeclarations;
                $pieces = $row->blocpieces;
                $attested = $row->blocattested;
                $last = $row->bloclastact;
            }

            $competency = new \html_table_cell($label);
            $competency->header = true;
            $competency->scope = 'row';
            if ($row->bloc !== null) {
                $competency->attributes['class'] = 'ps-4';
            }

            $table->data[] = array_merge(
                [$competency, (string) $declarations],
                $showpieces ? [(string) $pieces] : [],
                [
                    (string) $attested,
                    $last === null ? '' : s(display_date::format($last, $dateformat)),
                    self::courses($row->courses, $row->coursesunreadable),
                    self::activities($row->activities, $row->activitiesunreadable),
                    $plansreadable ? self::plans($row->plans) : s(get_string('orgplansunreadable', 'tool_vae')),
                ]
            );
        }

        return $out . \html_writer::table($table) . \html_writer::end_tag('section');
    }

    /**
     * The records only a plan names: each with its courses and its plans, and no count.
     *
     * @param \stdClass[] $planned As organisation::for_candidate() gives them in planned.
     * @return string HTML.
     */
    private static function planned(array $planned): string {
        global $OUTPUT;

        $items = [];
        foreach ($planned as $record) {
            $line = \html_writer::tag('strong', s($record->fichecode));
            if ($record->name !== $record->fichecode) {
                $line .= ' — ' . s($record->name);
            }
            $details = \html_writer::tag('dt', s(get_string('orgcolcourses', 'tool_vae')))
                . \html_writer::tag('dd', self::courses($record->courses, $record->coursesunreadable))
                . \html_writer::tag('dt', s(get_string('orgcolactivities', 'tool_vae')))
                . \html_writer::tag('dd', self::activities($record->activities, $record->activitiesunreadable))
                . \html_writer::tag('dt', s(get_string('orgcolplans', 'tool_vae')))
                . \html_writer::tag('dd', self::plans($record->plans));
            $items[] = \html_writer::tag('li', $line . \html_writer::tag('dl', $details, ['class' => 'mb-2']));
        }

        return \html_writer::start_tag('section', ['class' => 'tool-vae-organisation-planned mb-4'])
            . $OUTPUT->heading(get_string('orgplannedheading', 'tool_vae'), 3)
            . \html_writer::tag('p', s(get_string('orgplannedintro', 'tool_vae')))
            . \html_writer::tag('ul', implode('', $items))
            . \html_writer::end_tag('section');
    }

    /**
     * The courses that carry a competency: a link for each course the reader may open, a word for each the core keeps.
     *
     * @param \stdClass[] $courses courseid, fullname (null when the core keeps the name), visible.
     * @param bool $unreadable Whether courses carry the competency and the reader may see none: not "none".
     * @return string HTML.
     */
    private static function courses(array $courses, bool $unreadable): string {
        if (!$courses) {
            return s(get_string($unreadable ? 'orgcoursesunreadable' : 'orgnone', 'tool_vae'));
        }

        $items = [];
        foreach ($courses as $course) {
            if ($course->fullname === null) {
                $items[] = \html_writer::tag('li', s(get_string('orgcoursehidden', 'tool_vae')));
                continue;
            }
            $name = format_string($course->fullname, true, ['context' => \context_course::instance((int) $course->courseid)]);
            $item = \html_writer::link(new \moodle_url('/course/view.php', ['id' => $course->courseid]), $name);
            // A reader who may see hidden courses is told this one is: others do not see it.
            if (!$course->visible) {
                $mark = s(get_string('orgcoursehiddenmark', 'tool_vae'));
                $item .= ' ' . \html_writer::tag('span', $mark, ['class' => 'small text-muted']);
            }
            $items[] = \html_writer::tag('li', $item);
        }

        return \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled mb-0']);
    }

    /**
     * The activities that carry a competency: "course > activity (type)", a link for the activity the reader can open.
     *
     * The names come from the data already formatted (the filters of the site, escaped), as the core gives them. An
     * activity the reader may not be told of has a word and no name; neither has a hidden course.
     *
     * @param \stdClass[] $activities course, name, type, url - null where the reader may not be told.
     * @param bool $unreadable Whether activities carry the competency and the reader may see none: not "none".
     * @return string HTML.
     */
    private static function activities(array $activities, bool $unreadable): string {
        if (!$activities) {
            return s(get_string($unreadable ? 'orgcoursesunreadable' : 'orgnone', 'tool_vae'));
        }

        $items = [];
        foreach ($activities as $activity) {
            $course = $activity->course === null ? s(get_string('orgcoursehidden', 'tool_vae')) : $activity->course;
            if ($activity->name === null) {
                $name = s(get_string('orgactivityhidden', 'tool_vae'));
            } else {
                $name = $activity->name;
                if ($activity->url !== null) {
                    $name = \html_writer::link(new \moodle_url($activity->url), $activity->name);
                }
                $name .= ' (' . s($activity->type) . ')';
            }
            $items[] = \html_writer::tag('li', $course . ' › ' . $name);
        }

        return \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled mb-0']);
    }

    /**
     * The number of activities of a record, and the list behind it, folded away (no script needed).
     *
     * @param \stdClass[] $activities As in activities().
     * @param bool $unreadable Whether activities carry the competencies of the record and the reader may see none.
     * @param string $fichecode The record, said to a screen reader after the count: many folds share one table.
     * @return string HTML.
     */
    private static function activity_count(array $activities, bool $unreadable, string $fichecode): string {
        if (!$activities) {
            return s(get_string($unreadable ? 'orgcoursesunreadable' : 'orgnone', 'tool_vae'));
        }
        $count = count($activities);
        $label = $count === 1
            ? get_string('orgactivitiesone', 'tool_vae')
            : get_string('orgactivitiesmany', 'tool_vae', $count);
        $hidden = \html_writer::span(' ' . s($fichecode), 'visually-hidden');
        $summary = \html_writer::tag('summary', s($label) . $hidden, ['title' => get_string('orgactivitieslist', 'tool_vae')]);

        return \html_writer::tag('details', $summary . self::activities($activities, false));
    }

    /**
     * The plans that contain a competency, each with the core's word for its status.
     *
     * @param \stdClass[] $plans name, statuslabel.
     * @return string HTML.
     */
    private static function plans(array $plans): string {
        if (!$plans) {
            return s(get_string('orgnone', 'tool_vae'));
        }

        $items = [];
        foreach ($plans as $plan) {
            $items[] = \html_writer::tag('li', s($plan->name . ' (' . $plan->statuslabel . ')'));
        }

        return \html_writer::tag('ul', implode('', $items), ['class' => 'list-unstyled mb-0']);
    }
}
