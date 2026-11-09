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
 * The pathway of a candidate: their records x blocs x competencies x what they did and where it is taught (T-90).
 *
 * COUNTS, NEVER A VERDICT, as in coverage: no total, no rate, no threshold, dates alone (T-40). What is new here is
 * read from the core through the gateway - the courses that carry a competency, the learning plans that contain it -
 * and set next to the counts. A record the candidate never declared on, but that one of their plans names, is cited
 * with its courses and plans and without a table of counts: the plan informs on the offer of training, it does not
 * measure the dossier (decision of 2 November 2026).
 *
 * FOLLOWED CANDIDATES, THE BOUNDED EXCEPTION (T-90 lot 3). A manager reads, for each candidate they follow, the two counts
 * of "My candidates", the records the candidate declared on and, under the core's own right, the names and statuses of
 * their plans - followed() below. Nothing per competency or per bloc, no date, no total: the rule that follows is about
 * the pathway of for_candidate(), where those details live.
 *
 * READ BY THE CANDIDATE ALONE (decision of 12 October 2026), for_candidate(). The counts of a person, put in the hands
 * of a manager, the direction or an administrator, become a gauge on that person. The refusal is here, in the method, and not in
 * the page: a page that filtered what it draws would leave the rule untestable and the structure readable by any
 * caller. The test is on identity, then on the right to read one's own dossier; a site administrator, who passes every
 * capability check, is refused by the first.
 *
 * Readings do not grow with the number of competencies: one query for the pieces, one for the courses of all the
 * competencies, one for their frameworks, one per plan, one per record for its competencies (patron of coverage).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class organisation {
    /** @var string The tab of the pathway of the reader, for the reader alone. */
    public const TAB_MINE = 'mine';

    /** @var string The tab of the candidates the reader follows. */
    public const TAB_FOLLOWED = 'followed';

    /** @var string The tab of the configuration of the scheme, for who may view any dossier. */
    public const TAB_SITE = 'site';

    /**
     * The candidates the reader follows: counts of work, and plans only where the core lets the reader read them (T-90 lot 3).
     *
     * Nothing per competency or per bloc, no date, no total: what "My candidates" counts, the records each one declared
     * on, and the plans of the core. The one exception is the choice of a bloc the reader is referent of, which narrows
     * what declarations and pending count (candidate_overview::narrowing()) and never the list. The plans of a
     * candidate are read after the core's own two questions on that candidate (can_view_plans_of(),
     * can_view_draft_plans_of()), and their flags are given so that an empty list can be told from a list the reader's
     * rights emptied. Under "log in as" the user of the session is the reader, as on "My candidates" (decision D5).
     *
     * @param int $managerid Must be the current user: nobody reads another person's list.
     * @param string $bloc A bloc to narrow the counts to, "fichecode" or "fichecode|ficherang"; ignored unless the reader
     *                     is referent of it.
     * @return \stdClass candidates (by candidate id: id, name, sortname, fiches [fichecode, name], declarations, pending,
     *                   plansreadable, draftsreadable, plans [name, status, statuslabel]), choices and chosen
     *                   (candidate_overview::narrowing()). candidates is empty for somebody who follows nobody.
     * @throws \required_capability_exception For anyone but the reader.
     */
    public static function followed(int $managerid, string $bloc = ''): \stdClass {
        global $DB, $USER;

        if ($managerid !== (int) $USER->id) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'tool/vae:viewassigned',
                'nopermissions',
                ''
            );
        }
        $ids = access::followed_candidates();
        if (!$ids) {
            return (object) ['candidates' => [], 'choices' => [], 'chosen' => ''];
        }

        $narrowing = candidate_overview::narrowing($managerid, $bloc);
        $counts = candidate_overview::counts($ids, $narrowing->fichecode, $narrowing->ranks);

        // The name fields alone: a row of the table of users holds a good deal more than a name.
        $fields = 'id, ' . implode(', ', \core_user\fields::get_name_fields());
        $candidates = [];
        foreach ($DB->get_records_list('user', 'id', $ids, '', $fields) as $user) {
            $candidates[(int) $user->id] = (object) [
                'id' => (int) $user->id,
                'name' => fullname($user),
                'sortname' => $user->lastname . ' ' . $user->firstname,
                'fiches' => [],
                'declarations' => isset($counts[(int) $user->id]) ? $counts[(int) $user->id]->declarations : 0,
                'pending' => isset($counts[(int) $user->id]) ? $counts[(int) $user->id]->pending : 0,
                'plansreadable' => false,
                'draftsreadable' => false,
                'plans' => [],
            ];
        }
        \core_collator::asort_objects_by_property($candidates, 'sortname');

        self::add_fiches($candidates);
        self::add_plans($candidates);

        return (object) [
            'candidates' => $candidates,
            'choices' => $narrowing->choices,
            'chosen' => $narrowing->chosen,
        ];
    }

    /**
     * Name, for each candidate, the records they declared on: code and name, in code order. One query for the codes, one
     * for the sources, one name per distinct record.
     *
     * @param \stdClass[] $candidates By candidate id; their fiches are filled.
     */
    private static function add_fiches(array $candidates): void {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(array_keys($candidates), SQL_PARAMS_NAMED, 'cd');
        $rows = [];
        $rs = $DB->get_recordset_sql(
            "SELECT DISTINCT e.candidateid, e.fichecode
               FROM {tool_vae_evidence} e
              WHERE e.candidateid $insql",
            $params
        );
        foreach ($rs as $row) {
            $rows[] = $row;
        }
        $rs->close();
        $codes = array_unique(array_column($rows, 'fichecode'));
        if (!$codes) {
            return;
        }
        $gateway = new competency_gateway();
        $names = [];
        foreach ($DB->get_records_list('tool_vae_source', 'fichecode', $codes) as $source) {
            $names[$source->fichecode] = $gateway->framework_name((int) $source->frameworkid);
        }
        foreach ($rows as $row) {
            $candidates[(int) $row->candidateid]->fiches[] = (object) [
                'fichecode' => $row->fichecode,
                'name' => $names[$row->fichecode] ?? $row->fichecode,
            ];
        }
        foreach ($candidates as $candidate) {
            usort($candidate->fiches, fn($a, $b) => strcmp($a->fichecode, $b->fichecode));
        }
    }

    /**
     * Read the plans of the candidates the reader may read them of: at most three queries for all of them.
     *
     * @param \stdClass[] $candidates By candidate id; plansreadable, draftsreadable and plans are filled.
     */
    private static function add_plans(array $candidates): void {
        // The candidates, grouped by the kinds of plan the core lets the reader read: both, the others alone, the drafts alone.
        $lots = [];
        foreach ($candidates as $id => $candidate) {
            $candidate->plansreadable = competency_gateway::can_view_plans_of($id);
            $candidate->draftsreadable = competency_gateway::can_view_draft_plans_of($id);
            if ($candidate->plansreadable || $candidate->draftsreadable) {
                $lots[($candidate->draftsreadable ? 'd' : '-') . ($candidate->plansreadable ? 'p' : '-')][] = $id;
            }
        }

        $gateway = new competency_gateway();
        foreach ($lots as $kinds => $ids) {
            foreach ($gateway->list_plans_of_users($ids, $kinds[0] === 'd', $kinds[1] === 'p') as $plan) {
                $candidates[(int) $plan->userid]->plans[] = (object) [
                    'name' => $plan->name,
                    'status' => (int) $plan->status,
                    'statuslabel' => competency_gateway::plan_status_label((int) $plan->status),
                ];
            }
        }
        foreach ($candidates as $candidate) {
            \core_collator::asort_objects_by_property($candidate->plans, 'name');
            $candidate->plans = array_values($candidate->plans);
        }
    }

    /**
     * Whether the current user may open a tab of the organisation page.
     *
     * One predicate for what the page draws and what the address accepts, so that the two never differ. A tab the page
     * does not know cannot be opened.
     *
     * @param string $tab TAB_MINE, TAB_FOLLOWED or TAB_SITE.
     * @return bool
     */
    public static function can_open_tab(string $tab): bool {
        global $USER;

        switch ($tab) {
            case self::TAB_MINE:
                return !\core\session\manager::is_loggedinas() && access::can_view((int) $USER->id);
            case self::TAB_FOLLOWED:
                return (bool) access::followed_candidates();
            case self::TAB_SITE:
                return has_capability('tool/vae:viewany', \context_system::instance());
            default:
                return false;
        }
    }

    /**
     * The tabs the current user may open, in the order they are drawn.
     *
     * A site administrator passes every capability check and is the candidate of their own dossier, so they open "mine" and
     * "site"; somebody who holds viewany alone opens "site" alone.
     *
     * @return string[]
     */
    public static function tabs(): array {
        return array_values(array_filter(
            [self::TAB_MINE, self::TAB_FOLLOWED, self::TAB_SITE],
            [self::class, 'can_open_tab']
        ));
    }

    /**
     * The tab the page opens on when the address names none, or one that does not exist (decision of 4 November 2026).
     *
     * The first tab one may open, except that somebody who may read the configuration of the scheme and has declared
     * nothing opens on it: their own pathway is empty, and it is the configuration they came for. One reading, of
     * their own declarations.
     *
     * @return string
     */
    public static function default_tab(): string {
        global $DB, $USER;

        $tabs = self::tabs();
        if (in_array(self::TAB_SITE, $tabs, true) && !$DB->record_exists('tool_vae_evidence', ['candidateid' => (int) $USER->id])) {
            return self::TAB_SITE;
        }

        return $tabs[0] ?? self::TAB_MINE;
    }

    /**
     * Refuse the address of a tab the current user may not open, with the right that opens it.
     *
     * @param string $tab TAB_MINE, TAB_FOLLOWED or TAB_SITE.
     * @throws \required_capability_exception
     */
    public static function require_tab(string $tab): void {
        global $USER;

        if ($tab === self::TAB_SITE) {
            require_capability('tool/vae:viewany', \context_system::instance());
            return;
        }
        if ($tab === self::TAB_FOLLOWED) {
            if (!self::can_open_tab($tab)) {
                throw new \required_capability_exception(
                    \context_system::instance(),
                    'tool/vae:viewassigned',
                    'nopermissions',
                    ''
                );
            }
            return;
        }
        // The pathway of the reader: the same guards as for_candidate(), and the same exception.
        if (\core\session\manager::is_loggedinas()) {
            throw new \required_capability_exception(\context_system::instance(), 'tool/vae:viewown', 'nopermissions', '');
        }
        access::require_can_view((int) $USER->id);
    }

    /**
     * The settings the configuration lists, with how each is read.
     *
     * Ten of the plugin's settings: the nine that open or close a part of the scheme (a checkbox each) and the number of
     * days after which a declaration that waits for an attestation is reminded. The others - the format of a copy, the
     * limits of the assistant and of the pieces, the scale of an import - are numbers and choices that open nothing.
     */
    private const SITE_SETTINGS = [
        'managerwithdrawown',
        'copyenabled',
        'overduedays',
        'validationopen',
        'reflectionopen',
        'scopeopen',
        'aiopen',
        'refopen',
        'piecesopen',
        'piecenoantivirus',
    ];

    /**
     * The configuration of the scheme on this site: its records and its powers, never a person (T-90 lot 4).
     *
     * Read by who may view any dossier. Per record: the blocs and competencies the core holds, the courses and the
     * templates that carry its competencies, the NUMBER of learning plans of accounts that left a trace in the plugin
     * (never a name, and drafts counted: decisions of the owner of 4 November 2026), its referents and its rule
     * addresses. Per right of the plugin: the roles of the site that give it, refuse it and forbid it; then the cohorts
     * whose members a manager follows through such a role, and the settings. The only people named are the referents of
     * a bloc, whom the staff already knows (decision of 2 November 2026).
     *
     * @return \stdClass fiches (null when the competency subsystem is off; else, by record code, each with fichecode,
     *                  name, blocs and competencies - null when the core no longer holds the framework -, courses
     *                  [courseid, fullname, visible], coursesunreadable, models [templateid, name, visible], plans,
     *                  referents [ficherang, names] and rules [title, url]) and powers (capabilities [name, label,
     *                  allow, prevent, prohibit], cohorts [cohortid, name, roleid, rolename] or null when the site has
     *                  no tool_cohortroles, and settings [name, value, open]).
     * @throws \required_capability_exception Without the right to view any dossier.
     */
    public static function site(): \stdClass {
        require_capability('tool/vae:viewany', \context_system::instance());

        return (object) [
            'fiches' => competency_gateway::is_subsystem_enabled() ? self::site_fiches() : null,
            'powers' => self::site_powers(),
        ];
    }

    /**
     * The accounts that left a trace in the plugin: a declaration, a mark "outside my activity" or a validation draft.
     *
     * The people whose plans the count of a record may count. The deleted are left out and the suspended kept: a
     * suspended account keeps its plans.
     *
     * @return int[]
     */
    private static function traced_accounts(): array {
        global $DB;

        $sql = "SELECT u.id
                  FROM {user} u
                 WHERE u.deleted = 0
                       AND u.id IN (SELECT e.candidateid FROM {tool_vae_evidence} e
                                     UNION
                                    SELECT s.candidateid FROM {tool_vae_scope} s
                                     UNION
                                    SELECT d.candidateid FROM {tool_vae_draft} d WHERE d.doctype = :doctype)";

        return array_map('intval', $DB->get_fieldset_sql($sql, ['doctype' => draft_manager::DOCTYPE_VALIDATION]));
    }

    /**
     * The records of the site with what the core and the plugin hold about each (see site()).
     *
     * Every record is listed, whatever became of its framework: leaving it out would say it never was imported.
     * Readings are by group - the frameworks, their competencies, their courses and templates, their plans, their
     * referents - and never one per record.
     *
     * @return \stdClass[] By record code, in code order.
     */
    private static function site_fiches(): array {
        global $DB;

        $sources = $DB->get_records('tool_vae_source', null, 'fichecode ASC', 'id, fichecode, frameworkid');
        if (!$sources) {
            return [];
        }
        $gateway = new competency_gateway();
        $summaries = $gateway->framework_summaries(array_map(fn($source) => (int) $source->frameworkid, $sources));
        $idsof = $gateway->competencies_of_frameworks(array_keys($summaries));
        $allids = array_merge([], ...array_values($idsof));

        $coursesof = $gateway->courses_with_competencies($allids);
        $mayseehidden = [];
        $mayseecompetencies = [];
        $withheld = [];
        foreach ($coursesof as $competencyid => &$courses) {
            $courses = self::without_secret_names(self::readable_courses($courses, $mayseecompetencies), $mayseehidden);
            if (!$courses) {
                $withheld[$competencyid] = true;
            }
        }
        unset($courses);

        $templatesof = $gateway->templates_with_competencies($allids);
        $maysee = competency_gateway::can_view_hidden_templates();
        [$activitiesof, $activitieswithheld] = self::activities_of($allids, $mayseecompetencies, $mayseehidden);
        $plancounts = $gateway->plan_counts_of_frameworks(self::traced_accounts());

        $codes = array_map(fn($source) => $source->fichecode, array_values($sources));
        $referents = self::site_referents($codes);
        // Null where the setting is closed: the addresses may exist, and "none" would say they do not.
        $rules = !empty(get_config('tool_vae', 'refopen')) ? ref_manager::for_fiches($codes) : null;

        $out = [];
        foreach ($sources as $source) {
            $frameworkid = (int) $source->frameworkid;
            $summary = $summaries[$frameworkid] ?? null;
            // A framework named with nothing, or with spaces, is the code alone: no dash after it.
            $name = $summary ? trim($summary->name) : '';
            $courses = [];
            $models = [];
            $anywithheld = false;
            foreach ($idsof[$frameworkid] ?? [] as $competencyid) {
                $courses += $coursesof[$competencyid] ?? [];
                $models += $templatesof[$competencyid] ?? [];
                $anywithheld = $anywithheld || isset($withheld[$competencyid]);
            }
            $courses = array_values(self::without_secret_names($courses, $mayseehidden));
            $activities = [];
            $activitywithheld = false;
            foreach ($idsof[$frameworkid] ?? [] as $competencyid) {
                $activities += $activitiesof[$competencyid] ?? [];
                $activitywithheld = $activitywithheld || isset($activitieswithheld[$competencyid]);
            }
            $activities = self::finish_activities($activities);
            $out[$source->fichecode] = (object) [
                'fichecode' => $source->fichecode,
                'name' => $name !== '' ? $name : $source->fichecode,
                'blocs' => $summary ? $summary->blocs : null,
                'competencies' => $summary ? $summary->competencies : null,
                'courses' => $courses,
                'coursesunreadable' => $anywithheld && !$courses,
                'activities' => $activities,
                'activitiesunreadable' => $activitywithheld && !$activities,
                'models' => self::models_named($models, $maysee),
                'plans' => $plancounts[$frameworkid] ?? 0,
                'referents' => $referents[$source->fichecode] ?? [],
                'rules' => $rules === null ? null : array_map(
                    fn($rule) => (object) ['title' => $rule->title, 'url' => $rule->url],
                    $rules[$source->fichecode] ?? []
                ),
            ];
        }

        return $out;
    }

    /**
     * Keep the name of a hidden template from who the core keeps it from, and put the templates in order.
     *
     * As for a hidden course: a hidden template is still in the offer, so it stays in the list, but its name is the
     * core's secret unless the reader may manage templates. Named templates come first, in the order of the words; the
     * others last, by id, so that their place says nothing of their name.
     *
     * @param \stdClass[] $templates Keyed by template id: templateid, shortname, visible.
     * @param bool $maysee Whether the reader may see the names of hidden templates.
     * @return \stdClass[] A list of templateid, name (null when withheld), visible.
     */
    private static function models_named(array $templates, bool $maysee): array {
        $named = [];
        $unnamed = [];
        foreach ($templates as $id => $template) {
            if ($template->visible || $maysee) {
                $named[$id] = (object) [
                    'templateid' => $template->templateid,
                    'name' => $template->shortname,
                    'visible' => $template->visible,
                ];
            } else {
                $unnamed[$id] = (object) ['templateid' => $template->templateid, 'name' => null, 'visible' => 0];
            }
        }
        \core_collator::asort_objects_by_property($named, 'name');
        ksort($unnamed);

        return array_values($named + $unnamed);
    }

    /**
     * The referents of some records, by record and bloc, with the names of those whose account is active.
     *
     * A referent whose account is suspended or deleted is left out of the names, and the bloc keeps its line with no
     * name: "no referent" would be false, and "no active referent" is what the line says.
     *
     * @param string[] $codes Record codes.
     * @return array[] Record code => list of objects with ficherang (empty for the whole record) and names (strings).
     */
    private static function site_referents(array $codes): array {
        global $DB;

        $rows = referent_manager::for_fiches($codes);
        $userids = [];
        foreach ($rows as $list) {
            foreach ($list as $row) {
                $userids[$row->userid] = $row->userid;
            }
        }
        $names = [];
        $active = referent_manager::active(array_values($userids));
        if ($active) {
            $fields = \core_user\fields::for_name()->get_sql('u');
            [$insql, $params] = $DB->get_in_or_equal($active, SQL_PARAMS_NAMED, 'ref');
            $users = $DB->get_records_sql(
                "SELECT u.id {$fields->selects} FROM {user} u WHERE u.id $insql",
                $fields->params + $params
            );
            foreach ($users as $user) {
                $names[(int) $user->id] = fullname($user);
            }
        }

        $out = [];
        foreach ($rows as $code => $list) {
            $byrank = [];
            foreach ($list as $row) {
                $byrank[$row->ficherang] ??= [];
                if (isset($names[$row->userid])) {
                    $byrank[$row->ficherang][] = $names[$row->userid];
                }
            }
            foreach ($byrank as $rank => $rankednames) {
                \core_collator::asort($rankednames);
                $out[$code][] = (object) ['ficherang' => (string) $rank, 'names' => array_values($rankednames)];
            }
        }

        return $out;
    }

    /**
     * The roles, cohorts and settings behind the rights of the plugin (see site()).
     *
     * Only what the site defines at its top level is read: the roles as the site defines them, not the overrides made
     * lower down nor who holds a role. A site administrator holds every right whatever a role says: the screen says it.
     *
     * @param bool|null $cohortrolesavailable Whether the site has tool_cohortroles; asked of the database when null. A
     *                  test passes false: no test can uninstall that tool.
     * @return \stdClass capabilities, cohorts and settings, and the grid of the actors built from them (actors::grid()).
     */
    private static function site_powers(?bool $cohortrolesavailable = null): \stdClass {
        global $DB;

        $system = \context_system::instance();
        $capabilities = $DB->get_records('capabilities', ['component' => 'tool_vae'], 'name ASC', 'id, name');
        $rolenames = role_get_names($system, ROLENAME_ORIGINAL, true);
        $byname = [];
        foreach ($capabilities as $capability) {
            $short = substr($capability->name, strlen('tool/vae:'));
            $byname[$capability->name] = (object) [
                'name' => $capability->name,
                'label' => get_string('vae:' . $short, 'tool_vae'),
                'allow' => [],
                'prevent' => [],
                'prohibit' => [],
            ];
        }

        $givingroles = [];
        if ($byname) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($byname), SQL_PARAMS_NAMED, 'cap');
            $rows = $DB->get_records_select(
                'role_capabilities',
                "contextid = :contextid AND capability $insql",
                ['contextid' => $system->id] + $params,
                'id ASC',
                'id, roleid, capability, permission'
            );
            $slots = [CAP_ALLOW => 'allow', CAP_PREVENT => 'prevent', CAP_PROHIBIT => 'prohibit'];
            foreach ($rows as $row) {
                $slot = $slots[(int) $row->permission] ?? null;
                if ($slot !== null && isset($rolenames[$row->roleid])) {
                    $byname[$row->capability]->{$slot}[(int) $row->roleid] = $rolenames[$row->roleid];
                }
                if ($slot === 'allow') {
                    $givingroles[(int) $row->roleid] = true;
                }
            }
            foreach ($byname as $capability) {
                foreach (['allow', 'prevent', 'prohibit'] as $slot) {
                    \core_collator::asort($capability->{$slot});
                    $capability->{$slot} = array_values($capability->{$slot});
                }
            }
        }

        $cohorts = self::cohort_roles(
            $cohortrolesavailable ?? $DB->get_manager()->table_exists('tool_cohortroles'),
            array_keys($givingroles)
        );
        foreach ($cohorts ?? [] as $cohort) {
            $cohort->rolename = $rolenames[$cohort->roleid] ?? '';
        }

        $settings = [];
        foreach (self::SITE_SETTINGS as $name) {
            $value = (int) get_config('tool_vae', $name);
            // The reminder is on for a number of days the scheduled task accepts, and off beyond it; the others are switches.
            $open = $name === 'overduedays' ? pending_report::is_reminder_days($value) : $value > 0;
            $settings[] = (object) ['name' => $name, 'value' => $value, 'open' => $open];
        }

        return (object) [
            'capabilities' => array_values($byname),
            'cohorts' => $cohorts,
            'settings' => $settings,
            'actors' => actors::grid(array_values($byname), $settings),
        ];
    }

    /**
     * The cohorts whose members are followed through a role of the plugin, and the role.
     *
     * Tool_cohortroles gives a role to a manager over every member of a cohort: the cohort names the people followed,
     * the role what the manager may do. Only the roles that give a right of the plugin are listed - the table holds the
     * roles of the whole site - and only the cohort and the role are read, never the manager. A cohort is named whatever
     * its visibility (decision of the owner, 4 November 2026); one that was deleted but left its line has no name.
     * Public so that a test can tell the answer for a site without the plugin, which no test can uninstall; it asks the
     * right to view any dossier first, as site() does, so that being public gives nobody the names of the cohorts.
     *
     * @param bool $available Whether the site has tool_cohortroles.
     * @param int[] $roleids The roles that give a right of the plugin.
     * @return \stdClass[]|null Each with cohortid, name (or null) and roleid; null when the site has no tool_cohortroles.
     * @throws \required_capability_exception Without the right to view any dossier.
     */
    public static function cohort_roles(bool $available, array $roleids): ?array {
        global $DB;

        require_capability('tool/vae:viewany', \context_system::instance());
        if (!$available) {
            return null;
        }
        if (!$roleids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($roleids), SQL_PARAMS_NAMED, 'role');
        $rs = $DB->get_recordset_sql(
            "SELECT DISTINCT cr.cohortid, cr.roleid, c.name
               FROM {tool_cohortroles} cr
          LEFT JOIN {cohort} c ON c.id = cr.cohortid
              WHERE cr.roleid $insql
           ORDER BY cr.cohortid ASC, cr.roleid ASC",
            $params
        );
        $named = [];
        $unnamed = [];
        foreach ($rs as $row) {
            $cohort = (object) [
                'cohortid' => (int) $row->cohortid,
                'name' => $row->name === null ? null : (string) $row->name,
                'roleid' => (int) $row->roleid,
            ];
            if ($cohort->name === null) {
                $unnamed[] = $cohort;
            } else {
                $named[] = $cohort;
            }
        }
        $rs->close();
        // In the order of the words; the sort is stable, so a cohort with two roles keeps them in the order of the roles.
        \core_collator::asort_objects_by_property($named, 'name');

        return array_merge(array_values($named), $unnamed);
    }

    /**
     * The pathway of the current user.
     *
     * @param int $candidateid Must be the current user: nobody reads another person's pathway.
     * @return \stdClass declared (by record code: the coverage of the record plus its name, and per competency
     *                  pieces, lastact, scoped, courses, coursesunreadable and plans; per bloc also blocpieces and
     *                  bloclastact), planned (by record code, records of a plan without any declaration: fichecode,
     *                  name, courses, coursesunreadable, plans), plans (all the plans the reader may see: id, name,
     *                  status, statuslabel), plansreadable (false when the reader may read neither the plans nor
     *                  their drafts: the empty lists then mean "hidden", not "none") and marks (the "outside my
     *                  activity" marks, by "code-rank"). coursesunreadable is true when courses carry the competency
     *                  and the reader may see the competencies of none of them: the empty list then means "hidden",
     *                  not "none". A course whose competencies the reader may not see is left out, as in the core.
     * @throws \required_capability_exception For anyone but the candidate, or a candidate without viewown.
     */
    public static function for_candidate(int $candidateid): \stdClass {
        global $DB, $USER;

        // Log-in-as makes $USER the candidate: whoever does it is still somebody else reading this person's counts.
        if ($candidateid !== (int) $USER->id || \core\session\manager::is_loggedinas()) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'tool/vae:viewown',
                'nopermissions',
                ''
            );
        }
        access::require_can_view($candidateid);

        $gateway = new competency_gateway();
        $coverage = coverage::for_candidate($candidateid);

        // The plans the reader may see, and the competencies each one holds: one query per plan.
        $plans = [];
        $plancompetencies = [];
        // Told apart from "no plan": a site that took planviewown from its users hides their plans from them, and the
        // screen must not say "none" over plans that exist. As in the core, the right on drafts alone is a right to read:
        // the reader then reads the drafts and not the others.
        $mayplans = competency_gateway::can_view_plans_of($candidateid);
        $maydrafts = competency_gateway::can_view_draft_plans_of($candidateid);
        $plansreadable = $mayplans || $maydrafts;
        if ($plansreadable) {
            foreach ($gateway->list_user_plans($candidateid, $maydrafts, $mayplans) as $plan) {
                $plans[(int) $plan->id] = (object) [
                    'id' => (int) $plan->id,
                    'name' => $plan->name,
                    'status' => (int) $plan->status,
                    'statuslabel' => competency_gateway::plan_status_label((int) $plan->status),
                ];
                $plancompetencies[(int) $plan->id] = $gateway->plan_competency_ids((int) $plan->id);
            }
        }

        // In the order of the words, as the courses are: the order of the database differs between sites on an accent.
        \core_collator::asort_objects_by_property($plans, 'name');

        // The plans that contain each competency.
        $plansof = [];
        foreach (array_keys($plans) as $planid) {
            foreach ($plancompetencies[$planid] as $competencyid) {
                $plansof[$competencyid][$planid] = $plans[$planid];
            }
        }

        // The competency ids of each declared record, by idnumber; none when the record's provenance is gone.
        $idsof = [];
        $declaredframeworks = [];
        foreach ($coverage as $code => $record) {
            $idsof[$code] = [];
            // The name typed at the import, as a record cited by a plan has it; the code when the framework is gone.
            $record->name = $code;
            if ($record->source) {
                $declaredframeworks[(int) $record->source->frameworkid] = true;
                $record->name = $gateway->framework_name((int) $record->source->frameworkid) ?? $code;
                foreach ($gateway->list_framework_competencies((int) $record->source->frameworkid) as $competency) {
                    $idsof[$code][$competency->idnumber] = $competency->id;
                }
            }
        }

        // The records a plan names that the candidate never declared on.
        $plannedids = [];
        $planned = [];
        $allplanned = array_unique(array_merge([], ...array_values($plancompetencies)));
        $frameworkof = $gateway->frameworks_of_competencies($allplanned);
        $undeclared = [];
        foreach ($frameworkof as $competencyid => $frameworkid) {
            if (!isset($declaredframeworks[$frameworkid])) {
                $undeclared[$frameworkid][] = $competencyid;
            }
        }
        if ($undeclared) {
            foreach ($DB->get_records_list('tool_vae_source', 'frameworkid', array_keys($undeclared)) as $source) {
                $plannedids[$source->fichecode] = $undeclared[(int) $source->frameworkid];
                $planned[$source->fichecode] = (object) [
                    'fichecode' => $source->fichecode,
                    'name' => $gateway->framework_name((int) $source->frameworkid) ?? $source->fichecode,
                ];
            }
        }
        ksort($planned, SORT_STRING);

        // All the courses at once.
        $wanted = array_merge([], ...array_values($plannedids), ...array_values(array_map('array_values', $idsof)));
        $coursesof = $gateway->courses_with_competencies(array_unique($wanted));
        $mayseehidden = [];
        $mayseecompetencies = [];
        // The competencies that courses carry and the reader may see none of: "hidden", not "none". A competency no
        // course carries has no key in $coursesof, so an empty list here is a list the reader's rights emptied.
        $withheld = [];
        foreach ($coursesof as $competencyid => &$courses) {
            $courses = self::without_secret_names(self::readable_courses($courses, $mayseecompetencies), $mayseehidden);
            if (!$courses) {
                $withheld[$competencyid] = true;
            }
        }
        unset($courses);

        // The activities of those courses that carry the competencies, with the same rules as the courses (lot 1 bis).
        [$activitiesof, $activitieswithheld] = self::activities_of(array_unique($wanted), $mayseecompetencies, $mayseehidden);

        // What the candidate did, per competency: one query for the pieces and the date of the last declaration.
        $sql = "SELECT e.fichecode, e.ficherang,
                       MAX(e.timecreated) AS lastdeclared,
                       COUNT(p.id) AS pieces,
                       MAX(p.timecreated) AS lastpiece
                  FROM {tool_vae_evidence} e
             LEFT JOIN {tool_vae_piece} p ON p.evidenceid = e.id AND p.timeremoved IS NULL
                 WHERE e.candidateid = :candidateid
              GROUP BY e.fichecode, e.ficherang";
        $acts = [];
        $rs = $DB->get_recordset_sql($sql, ['candidateid' => $candidateid]);
        foreach ($rs as $act) {
            $acts[$act->fichecode . '-' . $act->ficherang] = $act;
        }
        $rs->close();

        $marks = scope_manager::for_candidate($candidateid);

        $declared = [];
        foreach ($coverage as $code => $record) {
            // Coverage gives a list in the order of the blocs; here the lines are found by idnumber, in the same order.
            $record->competencies = array_column($record->competencies, null, 'idnumber');
            foreach ($record->competencies as $row) {
                $act = $acts[$row->idnumber] ?? null;
                $dates = array_filter([
                    $act ? (int) $act->lastdeclared : null,
                    $row->lastattested,
                    $act && $act->lastpiece !== null ? (int) $act->lastpiece : null,
                ], fn($date) => $date !== null);

                $competencyid = $idsof[$code][$row->idnumber] ?? 0;
                $row->pieces = $act ? (int) $act->pieces : 0;
                $row->lastact = $dates ? max($dates) : null;
                $row->scoped = isset($marks[$row->idnumber]);
                $row->courses = array_values($coursesof[$competencyid] ?? []);
                $row->coursesunreadable = isset($withheld[$competencyid]);
                $row->activities = self::finish_activities($activitiesof[$competencyid] ?? []);
                $row->activitiesunreadable = isset($activitieswithheld[$competencyid]);
                $row->plans = array_values($plansof[$competencyid] ?? []);
            }
            self::add_up_blocs($record->competencies);
            $declared[$code] = $record;
        }

        foreach ($planned as $code => $record) {
            $courses = [];
            $recordplans = [];
            $anywithheld = false;
            foreach ($plannedids[$code] as $competencyid) {
                $courses += $coursesof[$competencyid] ?? [];
                $recordplans += $plansof[$competencyid] ?? [];
                $anywithheld = $anywithheld || isset($withheld[$competencyid]);
            }
            $record->courses = array_values(self::without_secret_names($courses, $mayseehidden));
            $record->coursesunreadable = $anywithheld && !$record->courses;
            $recordactivities = [];
            $activitywithheld = false;
            foreach ($plannedids[$code] as $competencyid) {
                $recordactivities += $activitiesof[$competencyid] ?? [];
                $activitywithheld = $activitywithheld || isset($activitieswithheld[$competencyid]);
            }
            $record->activities = self::finish_activities($recordactivities);
            $record->activitiesunreadable = $activitywithheld && !$record->activities;
            \core_collator::asort_objects_by_property($recordplans, 'name');
            $record->plans = array_values($recordplans);
        }

        return (object) [
            'declared' => $declared,
            'planned' => $planned,
            'plans' => array_values($plans),
            'plansreadable' => $plansreadable,
            'marks' => $marks,
        ];
    }

    /**
     * Give each bloc line the pieces and the last act of its own line and of the competencies under it.
     *
     * Coverage does it for the declarations and the attestations. A bloc line that added up those and not these would
     * read "two declarations, no piece" over a competency that carries two pieces. Counts and a date, nothing else.
     *
     * @param \stdClass[] $rows Keyed by idnumber, each with bloc, isbloc, pieces and lastact; the blocs get blocpieces
     *                    and bloclastact (null when none).
     */
    private static function add_up_blocs(array $rows): void {
        foreach ($rows as $row) {
            if ($row->isbloc) {
                $row->blocpieces = $row->pieces;
                $row->bloclastact = $row->lastact;
            }
        }
        foreach ($rows as $row) {
            if ($row->bloc !== null && isset($rows[$row->bloc]) && $rows[$row->bloc]->isbloc) {
                $parent = $rows[$row->bloc];
                $parent->blocpieces += $row->pieces;
                $parent->bloclastact = max($parent->bloclastact, $row->lastact);
            }
        }
    }

    /**
     * The activities that carry each competency, as the reader may see them (lot 1 bis).
     *
     * The line of an activity takes the rule of its course, then its own. A course whose competencies the reader may
     * not see sets the activity aside, as it sets the course aside; a hidden course keeps the activity but not its name
     * (the course is not named either); an activity the reader cannot see - hidden, under a restriction, or outside
     * a course they are not in - is kept without its name, since leaving it out would say "none" where there is one
     * (decision of 4 November 2026). An activity the reader may not read the competencies of is set aside. The module
     * information of a course is read once, whatever the number of its activities.
     *
     * @param int[] $competencyids
     * @param bool[] $mayseecompetencies Course id => whether the reader may see its competencies; filled as they come.
     * @param bool[] $mayseehidden Course id => whether the reader may see it hidden; filled as they come.
     * @return array [competency id => described activities keyed by course module id, competency id => true where
     *               activities carry the competency and the reader may see none].
     */
    private static function activities_of(array $competencyids, array &$mayseecompetencies, array &$mayseehidden): array {
        $modulesof = (new competency_gateway())->modules_with_competencies($competencyids);
        $described = [];
        $mayseemodule = [];
        $infos = [];
        $of = [];
        $withheld = [];
        foreach ($modulesof as $competencyid => $modules) {
            $refused = false;
            foreach ($modules as $cmid => $module) {
                if (!array_key_exists($cmid, $described)) {
                    $described[$cmid] = self::describe_activity(
                        $module,
                        $mayseecompetencies,
                        $mayseehidden,
                        $mayseemodule,
                        $infos
                    );
                }
                if ($described[$cmid]) {
                    $of[$competencyid][$cmid] = $described[$cmid];
                } else if ($described[$cmid] === null) {
                    $refused = true;
                }
            }
            // The sign "not readable" says a right took them away; an activity the module information lacks is none.
            if (empty($of[$competencyid]) && $refused) {
                $withheld[$competencyid] = true;
            }
        }

        return [$of, $withheld];
    }

    /**
     * What the reader may be told of one activity, or null when it is left out (lot 1 bis).
     *
     * @param \stdClass $module cmid, courseid, modname, coursefullname, coursevisible.
     * @param bool[] $mayseecompetencies Course id => whether the reader may see its competencies.
     * @param bool[] $mayseehidden Course id => whether the reader may see it hidden.
     * @param bool[] $mayseemodule Course module id => whether the reader may read its competencies; filled as they come.
     * @param \course_modinfo[] $infos Course id => its module information; filled as they come.
     * @return \stdClass|null|false course, name, type, url - already formatted for display (the filters of the site,
     *         escaped) except the type, which is plain text; the last three null for an activity the reader may not be
     *         told of. Null where a right sets the activity aside, false where the module information does not hold it.
     */
    private static function describe_activity(
        \stdClass $module,
        array &$mayseecompetencies,
        array &$mayseehidden,
        array &$mayseemodule,
        array &$infos
    ): \stdClass|null|false {
        $courseid = $module->courseid;
        $course = self::without_secret_names(self::readable_courses([$courseid => (object) [
            'courseid' => $courseid,
            'fullname' => $module->coursefullname,
            'visible' => $module->coursevisible,
        ]], $mayseecompetencies), $mayseehidden);
        if (!$course) {
            return null;
        }
        $course = reset($course);

        $mayseemodule[$module->cmid] ??= competency_gateway::can_view_module_competencies($module->cmid);
        if (!$mayseemodule[$module->cmid]) {
            return null;
        }
        // An activity the module information does not hold (a plugin removed, a cache behind) is left out, not an error.
        $infos[$courseid] ??= get_fast_modinfo($courseid);
        $cm = $infos[$courseid]->cms[$module->cmid] ?? null;
        if (!$cm) {
            return false;
        }
        $coursename = null;
        if ($course->fullname !== null) {
            $coursename = format_string($course->fullname, true, ['context' => \context_course::instance($courseid)]);
        }
        if ($course->fullname === null || !$cm->uservisible) {
            return (object) ['course' => $coursename, 'name' => null, 'type' => null, 'url' => null];
        }

        return (object) [
            'course' => $coursename,
            'name' => $cm->get_formatted_name(),
            'type' => (string) $cm->get_module_type_name(),
            'url' => $cm->url ? $cm->url->out(false) : null,
        ];
    }

    /**
     * Put the activities in order, and leave the key out (lot 1 bis).
     *
     * The named ones come first, by the name of the course and then the name of the activity, in the order of the words;
     * those without a name come last, by course module id: the place of an activity says nothing of its name.
     *
     * @param \stdClass[] $activities Described activities, keyed by course module id.
     * @return \stdClass[] A list: course, name, type, url.
     */
    private static function finish_activities(array $activities): array {
        $bycourse = [];
        $unnamed = [];
        foreach ($activities as $cmid => $activity) {
            if ($activity->name === null) {
                $unnamed[$cmid] = $activity;
            } else {
                $bycourse[$activity->course][$cmid] = $activity;
            }
        }
        // The courses in the order of the words, then the activities of each in theirs: no joined key, whose separator
        // the collator would skip.
        \core_collator::ksort($bycourse);
        $named = [];
        foreach ($bycourse as $group) {
            \core_collator::asort_objects_by_property($group, 'name');
            $named += $group;
        }
        ksort($unnamed);

        $list = [];
        foreach ($named + $unnamed as $activity) {
            $list[] = (object) [
                'course' => $activity->course,
                'name' => $activity->name,
                'type' => $activity->type,
                'url' => $activity->url,
            ];
        }

        return $list;
    }

    /**
     * Leave out the courses whose competencies the reader may not see, as the core's list of the courses of a competency does.
     *
     * The test comes before the one on hidden courses: a hidden course the reader has not this right in is not listed
     * as "a hidden course" either, since the core would not list it (decision of 3 November 2026). Asked once per course.
     *
     * @param \stdClass[] $courses Keyed by course id.
     * @param bool[] $mayseecompetencies Course id => whether the reader may; filled as the courses come.
     * @return \stdClass[] The courses the reader may see the competencies of, under the same keys.
     */
    private static function readable_courses(array $courses, array &$mayseecompetencies): array {
        foreach (array_keys($courses) as $id) {
            $mayseecompetencies[$id] ??= competency_gateway::can_view_course_competencies((int) $id);
            if (!$mayseecompetencies[$id]) {
                unset($courses[$id]);
            }
        }

        return $courses;
    }

    /**
     * Keep the name of a hidden course from who the core keeps it from, and put the courses in order.
     *
     * A hidden course is still where the competency is taught, so it stays in the list - leaving it out would say "no
     * course" where there is one - but its name is the core's secret unless the reader may see hidden courses
     * (decision of 2 November 2026): its fullname becomes null and the screen says "a hidden course". The courses
     * whose name is given come first, in the order of the words (the collator); those without come last, by id, so
     * that the place of a hidden course says nothing of its name. Safe to apply twice.
     *
     * @param \stdClass[] $courses Keyed by course id: courseid, fullname, visible.
     * @param bool[] $mayseehidden Course id => whether the reader may see it; filled as the courses come.
     * @return \stdClass[] Keyed by course id.
     */
    private static function without_secret_names(array $courses, array &$mayseehidden): array {
        $named = [];
        $unnamed = [];
        foreach ($courses as $id => $course) {
            if (!$course->visible && $course->fullname !== null) {
                $mayseehidden[$id] ??= competency_gateway::can_view_hidden_course((int) $id);
                if (!$mayseehidden[$id]) {
                    $course = (object) ['courseid' => $course->courseid, 'fullname' => null, 'visible' => 0];
                }
            }
            if ($course->fullname === null) {
                $unnamed[$id] = $course;
            } else {
                $named[$id] = $course;
            }
        }
        \core_collator::asort_objects_by_property($named, 'fullname');
        ksort($unnamed);

        return $named + $unnamed;
    }
}
