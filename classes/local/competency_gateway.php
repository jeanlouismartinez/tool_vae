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
 * The single point of contact with the core competency subsystem.
 *
 * WHY THIS CLASS EXISTS - DER-referentiel-001, compensating measure MC-5.
 *
 * The project rules require a thin GPLv3 bridge holding no business logic, with all value
 * in a Moodle-independent core. This plugin derogates from that: everything lives inside
 * Moodle. The derogation costs the project its shock absorber - normally the bridge is the
 * only layer that knows Moodle's vocabulary, so a Moodle upgrade touches one file.
 *
 * This class rebuilds that absorber inside the native model. It is the ONLY file in the
 * plugin allowed to name \core_competency. An LTS migration must touch this file and
 * nothing else. `make mc5` enforces it, and a failure there is a derogation failure, not a
 * style warning.
 *
 * NOT FINAL, on purpose. The transaction in evidence_manager can only be exercised by a
 * failure occurring AFTER the core write has succeeded, and no reachable input produces one:
 * every value is validated before the transaction opens. A test therefore substitutes a
 * subclass whose second core call throws. An interface for a single implementation would be
 * the abstraction-before-two-real-uses the project rules forbid; dropping `final` is the
 * smaller price.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class competency_gateway {
    /**
     * The core subsystem this plugin feeds.
     *
     * Declared here so that the literal string exists in this file and nowhere else in the
     * plugin. Tests and callers that need to name the subsystem reference this constant,
     * which keeps the MC-5 control meaningful instead of forcing an exemption for every
     * file that merely mentions it.
     */
    public const SUBSYSTEM = 'core_competency';

    // The statuses of a learning plan, as the core numbers them. Tied to the core's constants here, in the one file
    // that may name the core (MC-5), so that a renumbering upstream reaches the plugin without a word changed elsewhere.
    /** @var int A learning plan of the core still being written. */
    public const PLAN_DRAFT = \core_competency\plan::STATUS_DRAFT;
    /** @var int A learning plan in force. */
    public const PLAN_ACTIVE = \core_competency\plan::STATUS_ACTIVE;
    /** @var int A learning plan closed, its competencies frozen in the core's archive. */
    public const PLAN_COMPLETE = \core_competency\plan::STATUS_COMPLETE;
    /** @var int A learning plan its owner sent for review. */
    public const PLAN_WAITING_FOR_REVIEW = \core_competency\plan::STATUS_WAITING_FOR_REVIEW;
    /** @var int A learning plan a reviewer took. */
    public const PLAN_IN_REVIEW = \core_competency\plan::STATUS_IN_REVIEW;

    /**
     * Whether the core competency subsystem is switched on.
     *
     * The setting is core_competency/enabled in config_plugins. It is NOT
     * $CFG->enablecompetencies - that name only exists as a language string for the
     * setting's label, and reading it returns null on every site.
     *
     * Every core user_evidence entry point calls require_enabled() first and throws
     * moodle_exception('competenciesarenotenabled') when the subsystem is off. The plugin
     * checks this itself so the administrator gets an explanation instead of a core
     * exception surfacing in the UI. Acceptance criterion 10.
     *
     * @return bool
     */
    public static function is_subsystem_enabled(): bool {
        return (bool) get_config(self::SUBSYSTEM, 'enabled');
    }

    /**
     * Create a piece of prior-learning evidence for a candidate.
     *
     * Deliberately takes no description: the narrative never reaches the core record.
     * See PRP correction C4 - core's `name` is copied into competency_evidence.desca on
     * both link and unlink, and `description` is readable through tool_lp and through the
     * core_competency_read_user_evidence web service under a capability whose default
     * archetype is manager. The generated label carries no user input.
     *
     * @param int $candidateid
     * @param string $generatedlabel Built by the plugin, never typed by a user.
     * @return int The core user evidence id.
     */
    public function create_evidence(int $candidateid, string $generatedlabel): int {
        $data = (object) [
            'userid' => $candidateid,
            'name' => $generatedlabel,
            // Empty on purpose. See the paragraph above; anti-pattern 15.
            'description' => '',
            'descriptionformat' => FORMAT_PLAIN,
            'url' => '',
        ];

        // Core resolves the context from $data->userid, so that field must be set before
        // the call: get_context() runs before the capability check and fails without it.
        $evidence = \core_competency\api::create_user_evidence($data);

        return (int) $evidence->get('id');
    }

    /**
     * Link a piece of evidence to a competency.
     *
     * Pass the object rather than the id where possible: core then skips a redundant
     * userevidenceview check. Note that this call creates a competency_usercomp row when
     * none exists - grade and proficiency stay null, so the candidate is never marked
     * proficient, but the row does appear. Acceptance criterion 1 asserts the null grade,
     * not the absence of the row.
     *
     * @param int $userevidenceid
     * @param int $competencyid
     */
    public function link_to_competency(int $userevidenceid, int $competencyid): void {
        // Passing the object rather than the id: core then skips a redundant
        // userevidenceview check, which is what core does itself when it calls this.
        $evidence = new \core_competency\user_evidence($userevidenceid);

        \core_competency\api::create_user_evidence_competency($evidence, $competencyid);
    }

    /**
     * The state of one piece of evidence, read once.
     *
     * ONE CONSTRUCTION, NOT TWO. The label and the links used to be read by two methods, each
     * building its own persistent for the same row: five queries per claim on the dossier
     * where three suffice, and half of them a pure duplicate - measured in adversarial review,
     * against a comment that claimed one.
     *
     * A record that is genuinely absent returns null. ANY OTHER FAILURE IS RAISED, and that
     * distinction is the point: swallowing a timeout and returning null would make the dossier
     * tell a candidate, in front of a panel, that their evidence no longer exists - when the
     * truth is that this plugin could not read it. "I do not know" must never be rendered as
     * "it is gone".
     *
     * Read without api::read_user_evidence(): that one checks a capability on the owner's
     * context, and the callers here - an observer reacting to someone else's action, and the
     * dossier reporting on an anchor - have already made their own access decision. A read
     * that refused would be reported as a divergence.
     *
     * @param int $userevidenceid
     * @return \stdClass|null name and competencyids, or null when the record is gone.
     */
    public function read_anchor(int $userevidenceid): ?\stdClass {
        try {
            $evidence = new \core_competency\user_evidence($userevidenceid);
        } catch (\dml_missing_record_exception $e) {
            return null;
        }

        $competencyids = [];
        foreach ($evidence->get_competencies() as $competency) {
            $competencyids[] = (int) $competency->get('id');
        }

        return (object) [
            'id' => (int) $evidence->get('id'),
            'name' => (string) $evidence->get('name'),
            'competencyids' => $competencyids,
        ];
    }

    /**
     * Which of these core evidence records still exist (T-78).
     *
     * One read for many ids, for the reconciliation task, which asks about every anchor the plugin
     * holds: asking read_anchor() for each would load a core object per claim. Nothing is checked
     * but existence, and no capability: the caller is a system task.
     *
     * @param int[] $userevidenceids
     * @return int[] The ids that exist, keyed by id.
     */
    public function existing_evidence_ids(array $userevidenceids): array {
        global $DB;

        $found = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $userevidenceids))), 500) as $chunk) {
            [$insql, $params] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED);
            foreach ($DB->get_fieldset_select('competency_userevidence', 'id', "id $insql", $params) as $id) {
                $found[(int) $id] = (int) $id;
            }
        }

        return $found;
    }

    /**
     * Change a piece of evidence the way the candidate does from tool_lp.
     *
     * WHY THIS IS HERE AND NOT IN THE TESTS. It is used by the tests of the anchor, and by
     * nothing in production - the plugin never edits a record it does not own.
     *
     * The reason it sits here is MC-5, and the honest version of that reason is narrower than
     * it first looks: the tests DO name core tables directly, through $DB, and the control
     * does not object because it looks for the string `core_competency`. What the rule buys is
     * that every call to the core's API - the only calls that carry its capability checks and
     * raise its events - is in one file, where a reader can see the whole surface this plugin
     * depends on. Reproducing the candidate's gesture through that door is also the only way
     * to raise the event this plugin observes; a direct write to the table would test nothing.
     *
     * @param int $userevidenceid
     * @param array $fields Fields to change; the rest are carried over unchanged.
     */
    public function update_evidence(int $userevidenceid, array $fields): void {
        $evidence = \core_competency\api::read_user_evidence($userevidenceid);

        $data = (object) [
            'id' => $userevidenceid,
            'name' => $evidence->get('name'),
            'description' => $evidence->get('description'),
            'descriptionformat' => $evidence->get('descriptionformat'),
            'url' => $evidence->get('url'),
        ];

        foreach ($fields as $field => $value) {
            $data->$field = $value;
        }

        \core_competency\api::update_user_evidence($data);
    }

    /**
     * Unlink a competency from a piece of evidence, the way tool_lp does.
     *
     * Same reason as update_evidence() for being here. Note that the core raises NO event of
     * its own for this: it is the one mutation of the anchor that no observer can catch, which
     * is why the dossier recomputes the state of the anchor rather than trusting a flag.
     *
     * @param int $userevidenceid
     * @param int $competencyid
     */
    public function unlink_competency(int $userevidenceid, int $competencyid): void {
        \core_competency\api::delete_user_evidence_competency($userevidenceid, $competencyid);
    }

    /**
     * Write a new label on a piece of evidence, and touch nothing else.
     *
     * WRITTEN WITH set_field(), NOT WITH THE PERSISTENT, AND THAT IS THE WHOLE POINT. The
     * persistent stamps `usermodified` and `timemodified` on every update: relabelling on
     * uninstall would leave the candidate's own record claiming that the administrator who
     * removed a plugin had edited it - measured, the column went from the candidate's id to 0
     * on a command-line uninstall. This plugin's position is that the record belongs to the
     * candidate; rewriting its provenance to say otherwise contradicts it in the one operation
     * meant to leave things tidy.
     *
     * The persistent also triggers no event here, which suits the only caller: an uninstall
     * that logged a divergence about itself, moments before dropping its own tables, would be
     * a poor last act.
     *
     * FOR THE UNINSTALL HOOK, AND FOR NOTHING ELSE. It bypasses the core's capability check by
     * design - it runs as a site operation, possibly from the command line, where
     * has_capability() is false for everyone - and it writes on a row belonging to another
     * component and another person. No other caller is legitimate.
     *
     * @param int $userevidenceid
     * @param string $name Truncated to the 100 characters the core column holds.
     */
    public function relabel_evidence(int $userevidenceid, string $name): void {
        global $DB;

        $DB->set_field(
            'competency_userevidence',
            'name',
            \core_text::substr($name, 0, 100),
            ['id' => $userevidenceid]
        );
    }

    /**
     * Delete a piece of evidence.
     *
     * @param int $userevidenceid
     * @return bool
     */
    public function delete_evidence(int $userevidenceid): bool {
        return \core_competency\api::delete_user_evidence($userevidenceid);
    }

    /**
     * The competencies a candidate may declare against, for one framework.
     *
     * Returned as plain objects rather than core persistents: nothing outside this class
     * is allowed to hold a core object, or MC-5 would leak by the back door.
     *
     * The description travels with the label: a re-import compares both, and a list without it
     * left a description France compétences had republished on its old wording for ever. It is
     * read AS STORED, through to_record(): get() passes it through PARAM_CLEANHTML on the way
     * out, which turns `A & B` into `A &amp; B` - and every import would then report as updated
     * a description nobody touched.
     *
     * A FRAMEWORK THAT IS NOT THERE LISTS NOTHING, AND THAT IS DECIDED HERE ONCE FOR EVERY CALLER. Two
     * states of tool_vae_source name no framework the core can open, and the core's own listing
     * raises on both, which took down the declaration screen, the course screen and the scope screen
     * of every candidate for the sake of one record:
     *   - frameworkid 0, left by a first import that died after its provenance row was written:
     *     the core builds an empty persistent for id 0 and its get_context() raises a
     *     coding_exception;
     *   - a framework an administrator deleted in tool_lp, which the core allows as long as nobody
     *     has declared on it: api::list_competencies() raises dml_missing_record_exception, and the
     *     foreign key of tool_vae_source is declared but never enforced by the database.
     * An empty list is the truth in both. Reading the persistents directly gives it without a test
     * for either state, since a framework that does not exist has no competency.
     *
     * READ WITHOUT THE VIEWING RIGHT OF THE CORE, for the reason framework_tree() gives: what is
     * listed is a record imported on this site for everyone to declare against, and the callers
     * - the dossier of a candidate, the course screen of a manager, the backup run by the command
     * line, where nobody is logged in - have made their own access decision. api::list_competencies()
     * asks moodle/competency:competencyview, which only the authenticated-user archetype holds by
     * default; a site that took it away from that role lost the whole plugin to a refusal nobody
     * could read. The callers check is_subsystem_enabled() themselves, which the core's listing used
     * to check for them.
     *
     * @param int $frameworkid
     * @return array Keyed by competency id, each with id, idnumber, shortname, description,
     *               descriptionformat, frameworkid and parentid (0 for a competency with no parent: the
     *               bloc of a record that has blocs, or any competency of a flat one - T-33). Empty when the
     *               id is not a framework the core holds.
     */
    public function list_framework_competencies(int $frameworkid): array {
        if ($frameworkid <= 0) {
            return [];
        }

        $competencies = \core_competency\competency::get_records(['competencyframeworkid' => $frameworkid]);

        $out = [];
        foreach ($competencies as $competency) {
            $stored = $competency->to_record();
            $out[(int) $competency->get('id')] = (object) [
                'id' => (int) $competency->get('id'),
                'idnumber' => $competency->get('idnumber'),
                'shortname' => $competency->get('shortname'),
                'label' => self::label_of(
                    (string) $competency->get('idnumber'),
                    (string) $competency->get('shortname'),
                    (string) $stored->description
                ),
                'description' => (string) $stored->description,
                'descriptionformat' => (int) $stored->descriptionformat,
                'frameworkid' => (int) $competency->get('competencyframeworkid'),
                'parentid' => (int) $competency->get('parentid'),
            ];
        }

        return $out;
    }

    /**
     * How many competencies a framework holds.
     *
     * HERE BECAUSE OF MC-5, NOT BECAUSE IT IS CONVENIENT. `competency` is a table of the core,
     * and counting it with $DB from a presenter is the same breach as calling the core's api
     * from one - the migration to the next LTS would then have to visit two files instead of
     * this one. The guard script now matches the table name as well as the class name.
     *
     * Zero for an id that is not a framework (a first import that died leaves frameworkid 0), as
     * list_framework_competencies() gives nothing for it.
     *
     * @param int $frameworkid
     * @return int
     */
    public function count_framework_competencies(int $frameworkid): int {
        global $DB;

        if ($frameworkid <= 0) {
            return 0;
        }

        return (int) $DB->count_records('competency', ['competencyframeworkid' => $frameworkid]);
    }

    /**
     * Whether the core still holds this framework.
     *
     * HERE FOR MC-5, AND BECAUSE tool_vae_source CANNOT TELL. Its frameworkid is a declared foreign key
     * the database never enforces: an administrator who deletes the framework in tool_lp - the core
     * allows it while nobody has declared on it - leaves the row pointing at nothing, and a first import
     * that died leaves it at 0. The screens that must say so ask here.
     *
     * @param int $frameworkid
     * @return bool False for 0 and for an id the core no longer holds.
     */
    public function framework_exists(int $frameworkid): bool {
        global $DB;

        return $frameworkid > 0 && $DB->record_exists('competency_framework', ['id' => $frameworkid]);
    }

    /**
     * The identifier a framework carries now.
     *
     * Asked by the import before it writes anything: the framework a record was imported into may
     * have had its identifier changed in tool_lp, a free text field of the core's form, and
     * find_framework() would then no longer find it.
     *
     * @param int $frameworkid
     * @return string|null Null for 0 and for a framework the core no longer holds.
     */
    public function framework_idnumber(int $frameworkid): ?string {
        global $DB;

        if ($frameworkid <= 0) {
            return null;
        }
        $idnumber = $DB->get_field('competency_framework', 'idnumber', ['id' => $frameworkid]);

        return $idnumber === false ? null : (string) $idnumber;
    }

    /**
     * The name of a framework, which is the name typed at the import of its record.
     *
     * The import writes the name nowhere else: tool_vae_source has no column for it, and the
     * administration screen reads it here.
     *
     * @param int $frameworkid
     * @return string|null Null for 0 and for a framework the core no longer holds.
     */
    public function framework_name(int $frameworkid): ?string {
        global $DB;

        if ($frameworkid <= 0) {
            return null;
        }
        $name = $DB->get_field('competency_framework', 'shortname', ['id' => $frameworkid]);

        return $name === false ? null : (string) $name;
    }

    /**
     * Whether a framework is visible, as the core's own form has it ("Visible: No" hides it).
     *
     * The core refuses to attach a competency of a hidden framework to a course, and refuses by a
     * coding_exception, which is meant for a programmer and not for a manager of a course. The course
     * screen asks first.
     *
     * @param int $frameworkid
     * @return bool False for a hidden framework, and for one the core does not hold: nothing of it can
     *         be attached either.
     */
    public function framework_visible(int $frameworkid): bool {
        global $DB;

        if ($frameworkid <= 0) {
            return false;
        }

        return (bool) $DB->get_field('competency_framework', 'visible', ['id' => $frameworkid]);
    }

    /**
     * Delete a framework the way tool_lp does.
     *
     * WHY THIS IS HERE AND NOT IN THE TESTS, FOR THE REASON update_evidence() GIVES: it is used by the
     * tests, and by nothing in production - this plugin never deletes a framework. Going through the
     * core's door is the only way to find out what the core allows (it refuses while a user competency,
     * a course, a plan, a template or a badge holds one of its competencies) and to raise the event
     * the plugin may observe; a direct write on the tables would test neither.
     *
     * @param int $frameworkid
     * @return bool False when the core refuses.
     */
    public function delete_framework(int $frameworkid): bool {
        return (bool) \core_competency\api::delete_framework($frameworkid);
    }

    /**
     * The framework a competency belongs to.
     *
     * HERE FOR THE SAME REASON AS THE COUNT ABOVE (MC-5): the correspondences between two records (T-30) must
     * check that a competency belongs to the record they name, and `competency` is a table of the core.
     *
     * @param int $competencyid
     * @return int The framework id, or zero when there is no such competency.
     */
    public function framework_of_competency(int $competencyid): int {
        global $DB;

        return (int) $DB->get_field('competency', 'competencyframeworkid', ['id' => $competencyid]);
    }

    /**
     * Whether this user may create and update the frameworks an import writes.
     *
     * ASKED BEFORE THE IMPORT, NOT DURING IT. api::create_framework() requires the core's
     * moodle/competency:competencymanage, which a custom role holding tool/vae:import
     * need not have. Discovering that inside the import leaves a ghost record on the screen -
     * status failed, zero competencies - for a permission problem nobody named. Criterion 10
     * asks the same question of the subsystem itself.
     *
     * @param \context $context
     * @return bool
     */
    public static function can_manage_frameworks(\context $context): bool {
        return has_capability('moodle/competency:competencymanage', $context);
    }

    /**
     * Whether this user may see the competencies of a framework, as the core judges it when a declaration links to one.
     *
     * ASKED BEFORE THE DECLARATION FORM, FOR THE REASON can_manage_frameworks() GIVES. This plugin reads the competencies of
     * an imported record without the core's viewing right (list_framework_competencies()), so the form opens for anyone. But
     * the core asks that right again when the declaration is recorded: api::create_user_evidence_competency() reads the
     * competency through api::read_competency(), which refuses a user holding neither competencyview nor competencymanage
     * and rolls the transaction back. A site that took competencyview away from the authenticated-user role would then let
     * the vaeiste write the whole situation, and refuse it at the end with an error page and the text lost.
     *
     * The two rights the core accepts there, on the context of the framework: the system, where this plugin creates them.
     *
     * @param \context $context The context of the framework.
     * @return bool
     */
    public static function can_view_competencies(\context $context): bool {
        return has_any_capability(['moodle/competency:competencyview', 'moodle/competency:competencymanage'], $context);
    }

    /**
     * Create the competency framework that holds an imported certification record.
     *
     * The framework's idnumber is the record code, which is what lets a re-import find it
     * again. The scale is the core's default competence scale: this plugin never grades
     * anything, so the scale it carries is the core's business, not its own.
     *
     * NOT SCALE 1. Until lot 8 quater this said `'scaleid' => 1` under a comment calling it the
     * site default. On a standard install scale 1 is "Separate and Connected ways of knowing",
     * three items created first by lib/db/install.php; the competence scale comes later, from
     * make_competence_scale(). Frameworks created before the fix keep the scale they were given:
     * nothing grades against them, and changing the scale of a framework is the core's act.
     *
     * @param string $fichecode
     * @param string $name
     * @return int The framework id.
     */
    public function create_framework(string $fichecode, string $name): int {
        $scale = self::default_competence_scale();

        // First item the default, last one proficient: on the core's two-item scale, "Not yet
        // competent" and "Competent", which is what a trainer would expect to see.
        $count = count(explode(',', $scale->scale));
        $configuration = [['scaleid' => (string) $scale->id]];
        for ($item = 1; $item <= $count; $item++) {
            $configuration[] = [
                'id' => $item,
                'scaledefault' => $item === 1 ? 1 : 0,
                'proficient' => $item === $count ? 1 : 0,
            ];
        }

        $framework = \core_competency\api::create_framework((object) [
            'shortname' => $name,
            'idnumber' => $fichecode,
            'description' => '',
            'descriptionformat' => FORMAT_PLAIN,
            'visible' => 1,
            'scaleid' => (int) $scale->id,
            'scaleconfiguration' => json_encode($configuration),
            'contextid' => \context_system::instance()->id,
        ]);

        return (int) $framework->get('id');
    }

    /**
     * The scale the core created for competencies at install, "Default competence scale".
     *
     * FOUND BY ITS NAME, BECAUSE THE CORE KEEPS NOTHING ELSE. make_competence_scale(), in
     * lib/db/upgradelib.php, inserts a site scale named after the core string
     * `defaultcompetencescale` and records its id nowhere. The name was written in the language
     * of the install, so it is looked for in the current language, the site's, and English. The
     * oldest match wins: an administrator who later made a scale with the same name did not mean
     * to replace the core's.
     *
     * A site where none matches - renamed, or deleted - is told so, with the names looked for and the setting where the
     * administrator names the scale, instead of being handed a framework on a scale nobody chose. That setting
     * (`importscaleid`) comes first: the name Moodle looks for is the one of its current translation, and a site whose
     * scale was written under an older wording (fildargent.net, 2 November 2026: "Echelle d'évaluation de compétence par
     * défault") never matched it. A choice that no longer points at a scale of the whole site - deleted since, or a
     * course's own scale - is ignored, and the search by name takes over as if nothing were chosen.
     *
     * @return \stdClass The scale row.
     */
    public static function default_competence_scale(): \stdClass {
        global $CFG, $DB;

        $chosen = (int) get_config('tool_vae', 'importscaleid');
        if ($chosen) {
            $scale = $DB->get_record('scale', ['id' => $chosen, 'courseid' => 0]);
            if ($scale) {
                return $scale;
            }
        }

        $strings = get_string_manager();
        $names = array_values(array_unique([
            get_string('defaultcompetencescale'),
            $strings->get_string('defaultcompetencescale', 'core', null, $CFG->lang),
            $strings->get_string('defaultcompetencescale', 'core', null, 'en'),
        ]));

        [$insql, $params] = $DB->get_in_or_equal($names, SQL_PARAMS_NAMED);
        $scales = $DB->get_records_select('scale', "courseid = 0 AND name $insql", $params, 'id ASC', '*', 0, 1);

        if (!$scales) {
            $looked = implode(', ', array_map(static fn(string $name): string => '"' . $name . '"', $names));
            throw new \moodle_exception('errornocompetencescale', 'tool_vae', '', $looked);
        }

        return reset($scales);
    }

    /**
     * The framework carrying a certification record, or null.
     *
     * @param string $fichecode
     * @return int|null
     */
    public function find_framework(string $fichecode): ?int {
        global $DB;

        $id = $DB->get_field('competency_framework', 'id', ['idnumber' => $fichecode]);

        return $id ? (int) $id : null;
    }

    /**
     * Add a competency to an imported record.
     *
     * @param int $frameworkid
     * @param string $idnumber Record code and rank, joined by a hyphen.
     * @param string $shortname The label AS PUBLISHED. Never reworded - see the licence.
     * @param string $description
     * @param int $parentid The bloc this competency sits under (T-33), 0 for none. The bloc must already exist
     *        in the same framework.
     * @return int The competency id.
     */
    public function create_competency(
        int $frameworkid,
        string $idnumber,
        string $shortname,
        string $description = '',
        int $parentid = 0
    ): int {
        $competency = \core_competency\api::create_competency((object) [
            'competencyframeworkid' => $frameworkid,
            'idnumber' => $idnumber,
            'shortname' => $shortname,
            'description' => $description,
            'descriptionformat' => FORMAT_PLAIN,
            'parentid' => $parentid,
        ]);

        return (int) $competency->get('id');
    }

    /**
     * Put a competency under a bloc, or back at the top of its record (T-33).
     *
     * ONLY THE PLACE IN THE TREE, for the competency. The idnumber, the label, the description and everything that
     * hangs off it - claims, links, correspondences - stay as they are: a re-import that moves a competency to
     * another bloc must never touch what was declared against it. The core itself clears the competency rule
     * (tool_lp) of the two blocs concerned, the one it leaves and the one it joins; it does so for any creation
     * under a bloc too, and nothing here can prevent it.
     *
     * @param int $competencyid
     * @param int $parentid The bloc, or 0 for the top. Not the one it already has: the core refuses a move to the
     *        same place.
     */
    public function set_parent_competency(int $competencyid, int $parentid): void {
        \core_competency\api::set_parent_competency($competencyid, $parentid);
    }

    /**
     * Rewrite the label and description of a competency already imported.
     *
     * ONLY those two fields. A re-import must never touch what hangs off a competency - the
     * claims, the evidence, the links - and the core's update() would happily carry other
     * fields along if they were passed.
     *
     * @param int $competencyid
     * @param string $shortname
     * @param string $description
     */
    public function update_competency(int $competencyid, string $shortname, string $description = ''): void {
        $competency = \core_competency\api::read_competency($competencyid);

        \core_competency\api::update_competency((object) [
            'id' => $competencyid,
            'shortname' => $shortname,
            'description' => $description,
            'descriptionformat' => FORMAT_PLAIN,
            'idnumber' => $competency->get('idnumber'),
        ]);
    }

    /**
     * One competency, or null when it does not exist.
     *
     * READ FROM THE PERSISTENT, NOT THROUGH api::read_competency(), for the reason competency_name()
     * gives. The core's read asks moodle/competency:competencyview, and this method used to turn that
     * refusal into null: a reader without the right was told the competency did not exist - "unknown
     * competency" on a declaration, a correspondence "no longer valid" on a carry, an archive whose
     * every label is null when it is produced where nobody is logged in. Null now means one thing, and
     * it is true.
     *
     * @param int $competencyid
     * @return \stdClass|null
     */
    public function get_competency(int $competencyid): ?\stdClass {
        $competency = $competencyid > 0 ? \core_competency\competency::get_record(['id' => $competencyid]) : false;
        if (!$competency) {
            return null;
        }

        return (object) [
            'id' => (int) $competency->get('id'),
            'idnumber' => $competency->get('idnumber'),
            'shortname' => $competency->get('shortname'),
            'label' => self::label_of(
                (string) $competency->get('idnumber'),
                (string) $competency->get('shortname'),
                (string) $competency->to_record()->description
            ),
            'frameworkid' => (int) $competency->get('competencyframeworkid'),
        ];
    }

    /**
     * The name of a competency, for showing next to its code.
     *
     * Read from the persistent itself, NOT through get_competency(): that one goes through the
     * core API, which refuses a reader without moodle/competency:competencyview - and a manager who
     * follows a candidate has the rights of this plugin, not necessarily those. What is shown is a
     * label of a framework that was imported on this site for everyone to declare against; the
     * dossier already names its code to the same readers.
     *
     * @param int $competencyid
     * @return string|null Null when there is none, so that the caller keeps the code alone.
     */
    public function competency_name(int $competencyid): ?string {
        if ($competencyid <= 0) {
            return null;
        }

        $competency = \core_competency\competency::get_record(['id' => $competencyid]);

        return $competency ? self::label_of(
            (string) $competency->get('idnumber'),
            (string) $competency->get('shortname'),
            (string) $competency->to_record()->description
        ) : null;
    }

    /**
     * Where each competency of a record stands in its tree, read WITHOUT the viewing right of the core (T-33).
     *
     * The archive and the backups are produced where nobody is logged in - the command line, the uninstall, the
     * scheduled task - and the core's own listing refuses a reader without moodle/competency:competencyview. What
     * is read here is the idnumber, the label and the place of competencies of a record imported on this site for
     * everyone to declare against, the same that competency_name() reads.
     *
     * @param int $frameworkid
     * @return \stdClass[] Keyed by competency id, each with id, idnumber, shortname, label and parentid. Empty
     *         for 0 and for a framework the core no longer holds.
     */
    public function framework_tree(int $frameworkid): array {
        if ($frameworkid <= 0) {
            return [];
        }

        $out = [];
        foreach (\core_competency\competency::get_records(['competencyframeworkid' => $frameworkid]) as $competency) {
            $out[(int) $competency->get('id')] = (object) [
                'id' => (int) $competency->get('id'),
                'idnumber' => (string) $competency->get('idnumber'),
                'shortname' => (string) $competency->get('shortname'),
                'label' => self::label_of(
                    (string) $competency->get('idnumber'),
                    (string) $competency->get('shortname'),
                    (string) $competency->to_record()->description
                ),
                'parentid' => (int) $competency->get('parentid'),
            ];
        }

        return $out;
    }

    /**
     * How a framework names its levels: the taxonomy of each level, as the core displays it (lot 1a).
     *
     * The core keeps, on the framework, one taxonomy per level of competency (domain, competency, skill...), the first level
     * of competency being 1; a level it does not name falls back on "competency", as the core itself does. Read without the
     * viewing right of the core, for the reason framework_tree() gives.
     *
     * @param int $frameworkid
     * @return string[] Level (from 1, the top level of competency) => the displayable name. Empty for a framework the core
     *         no longer holds.
     */
    public function framework_taxonomies(int $frameworkid): array {
        if ($frameworkid <= 0) {
            return [];
        }
        $framework = \core_competency\competency_framework::get_record(['id' => $frameworkid]);
        if (!$framework) {
            return [];
        }
        $names = \core_competency\competency_framework::get_taxonomies_list();
        $out = [];
        // The persistent's own getter: the constants by level from 1, a level left empty already read as "competency".
        $constants = $framework->get('taxonomies');
        foreach ((array) $constants as $level => $constant) {
            $constant = (string) $constant;
            $out[(int) $level] = (string) ($names[$constant] ?? $names[\core_competency\competency_framework::TAXONOMY_COMPETENCY]);
        }

        return $out;
    }

    /**
     * The bloc a competency sits under, for showing next to it (T-33).
     *
     * Read from the persistents themselves, for the reason competency_name() gives.
     *
     * @param int $competencyid
     * @return \stdClass|null idnumber and shortname of the bloc; null when the competency sits under none.
     */
    public function bloc_of(int $competencyid): ?\stdClass {
        if ($competencyid <= 0) {
            return null;
        }

        $competency = \core_competency\competency::get_record(['id' => $competencyid]);
        $parentid = $competency ? (int) $competency->get('parentid') : 0;
        $parent = $parentid > 0 ? \core_competency\competency::get_record(['id' => $parentid]) : null;

        return $parent ? (object) [
            'idnumber' => (string) $parent->get('idnumber'),
            'shortname' => (string) $parent->get('shortname'),
            'label' => self::label_of(
                (string) $parent->get('idnumber'),
                (string) $parent->get('shortname'),
                (string) $parent->to_record()->description
            ),
        ] : null;
    }

    /**
     * The label to show for a competency: the label as published (T-31).
     *
     * A label of more than 100 characters cannot be the name of a competency of the core. The converter of the
     * export of France compétences then writes the neutral title `Bloc <rank>` as the name and the published label
     * whole in the description (fiche_converter). What a person must read is the published label: it is returned
     * here, and everywhere a competency is shown asks for it here. Any other competency shows its name.
     *
     * @param string $idnumber `<record code>-<rank>`.
     * @param string $shortname
     * @param string $description
     * @return string
     */
    public static function label_of(string $idnumber, string $shortname, string $description): string {
        $position = strrpos($idnumber, '-');
        $rank = $position === false ? '' : substr($idnumber, $position + 1);

        return $rank !== '' && $shortname === fiche_converter::SHORT_TITLE . $rank && trim($description) !== ''
            ? trim($description) : $shortname;
    }

    /**
     * Whether a user may change the competencies of a course, as the core judges it.
     *
     * Asked before anything is attempted, for the reason can_manage_frameworks() gives.
     *
     * @param \context_course $context
     * @return bool
     */
    public static function can_manage_course_competencies(\context_course $context): bool {
        return has_capability('moodle/competency:coursecompetencymanage', $context);
    }

    /**
     * The competencies already attached to a course.
     *
     * @param int $courseid
     * @return int[]
     */
    public function course_competency_ids(int $courseid): array {
        $ids = [];
        foreach (\core_competency\api::list_course_competencies($courseid) as $entry) {
            $ids[] = (int) $entry['competency']->get('id');
        }

        return $ids;
    }

    /**
     * Attach a competency to a course - the core's own "course competencies".
     *
     * @param int $courseid
     * @param int $competencyid
     * @return bool False when it was already attached.
     */
    public function add_competency_to_course(int $courseid, int $competencyid): bool {
        return (bool) \core_competency\api::add_competency_to_course($courseid, $competencyid);
    }

    /**
     * The learning plans of a user: name, status and template, nothing else (T-90).
     *
     * A plan is a fact of the person's pathway, which the organisation screen shows next to the
     * records they declared on. Who reviews it is the beginning of an evaluation, and the plugin
     * reads no evaluation (D-1): the columns are named one by one so that reviewerid never comes
     * along, and a test captures the SQL to hold it there. Read by the table, not by the core's
     * api::list_user_plans(), which throws for a reader the core does not admit: who may see a plan here
     * is decided before the call, in organisation, by the core's own rules (can_view_plans_of() and
     * can_view_draft_plans_of(); conception T-90, § 4.2 and § 4.3).
     *
     * Two kinds of plan, two rights, as in the core (api::list_user_plans, word for word): a draft - draft,
     * waiting for review, in review - is the core's secret until a right to see drafts (see
     * can_view_draft_plans_of()), and an active or complete plan until a right to see plans (see
     * can_view_plans_of()). The caller, having decided which it holds, asks for the kinds it may read; asked
     * for neither, the listing gives none of the five statuses. A status the core does not know is listed, as the
     * core lists it: it takes a write straight into the table to make one.
     *
     * @param int $userid
     * @param bool $drafts Whether the drafts are listed.
     * @param bool $active Whether the active and complete plans are listed.
     * @return \stdClass[] Keyed by plan id, name order: id, name, status, templateid, origtemplateid, timemodified.
     */
    public function list_user_plans(int $userid, bool $drafts = false, bool $active = true): array {
        global $DB;

        $where = 'userid = :userid';
        $params = ['userid' => $userid];
        [$statussql, $statusparams] = self::plan_status_filter($drafts, $active);
        $where .= $statussql;
        $params += $statusparams;

        return $DB->get_records_select(
            'competency_plan',
            $where,
            $params,
            'name ASC, id ASC',
            'id, name, status, templateid, origtemplateid, timemodified'
        );
    }

    /**
     * The learning plans of several people, in one query (T-90 lot 3).
     *
     * The table of the people a manager follows reads their plans together: one query whatever the number of people,
     * never one per person. The kinds of plan follow list_user_plans() - the caller decides, person by person, which
     * kinds the core lets it read (can_view_plans_of(), can_view_draft_plans_of()) and asks for each kind separately.
     * Four columns, named one by one: the plan's template, its reviewer and its dates are not needed to say that a
     * plan exists, and a reviewer is the beginning of an evaluation (D-1).
     *
     * @param int[] $userids The people whose plans are read; the caller has checked the right on each of them.
     * @param bool $drafts Whether the drafts are listed.
     * @param bool $active Whether the active and complete plans are listed.
     * @return \stdClass[] Keyed by plan id, name order: id, userid, name, status. Empty for nobody, without a query.
     */
    public function list_plans_of_users(array $userids, bool $drafts = false, bool $active = true): array {
        global $DB;

        if (!$userids) {
            return [];
        }
        [$usersql, $params] = $DB->get_in_or_equal(array_values($userids), SQL_PARAMS_NAMED, 'plu');
        [$statussql, $statusparams] = self::plan_status_filter($drafts, $active);

        return $DB->get_records_select(
            'competency_plan',
            "userid $usersql" . $statussql,
            $params + $statusparams,
            'name ASC, id ASC',
            'id, userid, name, status'
        );
    }

    /**
     * The condition on the status of a plan that list_user_plans() and list_plans_of_users() share.
     *
     * A status the core does not know is listed, as the core lists it: the condition names what it leaves out.
     *
     * @param bool $drafts Whether the drafts are listed.
     * @param bool $active Whether the active and complete plans are listed.
     * @return array [string SQL beginning with AND (empty when nothing is left out), array parameters].
     */
    private static function plan_status_filter(bool $drafts, bool $active): array {
        global $DB;

        $left = [];
        if (!$drafts) {
            $left = [self::PLAN_DRAFT, self::PLAN_WAITING_FOR_REVIEW, self::PLAN_IN_REVIEW];
        }
        if (!$active) {
            $left = array_merge($left, [self::PLAN_ACTIVE, self::PLAN_COMPLETE]);
        }
        if (!$left) {
            return ['', []];
        }
        [$insql, $inparams] = $DB->get_in_or_equal($left, SQL_PARAMS_NAMED, 'status', false);

        return [" AND status $insql", $inparams];
    }

    /**
     * The competencies of a learning plan, as the core itself resolves them (T-90).
     *
     * Three places, one rule, copied from plan::get_competencies(): a complete plan is frozen in
     * competency_usercompplan (by plan AND user, as the core reads it); a plan on a template
     * follows the template's list; any other plan has its own. A plan the core detached from its
     * template has templateid null and origtemplateid kept, so it reads its own list like any plan
     * without a template. The archive row also carries a grade and a proficiency: the competency id
     * is the one column read, and a test captures the SQL to hold it there (D-1). A plan that does
     * not exist has no competencies, and nothing is thrown.
     *
     * @param int $planid
     * @return int[] Competency ids, in the core's order (sortorder, then id).
     */
    public function plan_competency_ids(int $planid): array {
        global $DB;

        $plan = $DB->get_record('competency_plan', ['id' => $planid], 'id, userid, status, templateid');
        if (!$plan) {
            return [];
        }

        if ((int) $plan->status === self::PLAN_COMPLETE) {
            $ids = $DB->get_fieldset_select(
                'competency_usercompplan',
                'competencyid',
                'planid = :planid AND userid = :userid ORDER BY sortorder ASC, id ASC',
                ['planid' => $planid, 'userid' => $plan->userid]
            );
        } else if ($plan->templateid) {
            $ids = $DB->get_fieldset_select(
                'competency_templatecomp',
                'competencyid',
                'templateid = :templateid ORDER BY sortorder ASC, id ASC',
                ['templateid' => $plan->templateid]
            );
        } else {
            $ids = $DB->get_fieldset_select(
                'competency_plancomp',
                'competencyid',
                'planid = :planid ORDER BY sortorder ASC, id ASC',
                ['planid' => $planid]
            );
        }

        return array_map('intval', $ids);
    }

    /**
     * The courses that carry a competency - hidden ones included, and said to be hidden (T-90).
     *
     * The pathway shows, next to a competency, where the site teaches it. A hidden course is still
     * where it is taught: omitting it would put a "no course" where there is one, a false zero, so
     * it is returned with visible = 0 and the screen marks it. The course's name and visibility are
     * all that is read; the core's list_course_competencies() is not used because it is asked per
     * course, and this is asked per competency. The name is raw: the screen passes it through
     * format_string(). Sorted in PHP by the collator, not by the database: the order of the bytes
     * differs between PostgreSQL under musl, PostgreSQL under glibc and MariaDB on a capital or an
     * accented initial, and a pathway must read the same on every site.
     *
     * @param int $competencyid
     * @return \stdClass[] Keyed by course id, name order: courseid, fullname, visible.
     */
    public function courses_with_competency(int $competencyid): array {
        global $DB;

        $courses = $DB->get_records_sql(
            "SELECT c.id AS courseid, c.fullname, c.visible
               FROM {competency_coursecomp} cc
               JOIN {course} c ON c.id = cc.courseid
              WHERE cc.competencyid = :competencyid",
            ['competencyid' => $competencyid]
        );
        \core_collator::asort_objects_by_property($courses, 'fullname');

        return $courses;
    }

    /**
     * The courses that carry each of a list of competencies, in one reading (T-90).
     *
     * The pathway asks for every competency of every record at once: one query for the list, never one per
     * competency. Same rows and same order as courses_with_competency(), which is the single-competency form.
     *
     * @param int[] $competencyids
     * @return \stdClass[][] Competency id => courses keyed by course id, name order: courseid, fullname, visible.
     *                       A competency no course carries has no key.
     */
    public function courses_with_competencies(array $competencyids): array {
        global $DB;

        if (!$competencyids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($competencyids), SQL_PARAMS_NAMED, 'comp');
        $rows = $DB->get_records_sql(
            "SELECT cc.id, cc.competencyid, c.id AS courseid, c.fullname, c.visible
               FROM {competency_coursecomp} cc
               JOIN {course} c ON c.id = cc.courseid
              WHERE cc.competencyid $insql",
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->competencyid][(int) $row->courseid] = (object) [
                'courseid' => (int) $row->courseid,
                'fullname' => $row->fullname,
                'visible' => (int) $row->visible,
            ];
        }
        foreach ($out as &$courses) {
            \core_collator::asort_objects_by_property($courses, 'fullname');
        }

        return $out;
    }

    /**
     * The activities that carry each of a list of competencies, with their course, in one reading (T-90 lot 1 bis).
     *
     * The offer of training one level below the course: which quiz, which assignment works a competency. A fact about
     * the site, never about a person. What the activity does with the competency (the rule it applies, the grade it
     * overrides) is the start of an assessment and is not read here. An activity being deleted is not counted. The
     * context of each activity is loaded with the row, so that the right asked of each afterwards reads nothing more.
     * The name of an activity is not here: it is in the table of its module, and the core reads it through the
     * course's module information, with the right of the reader.
     *
     * @param int[] $competencyids
     * @return \stdClass[][] Competency id => activities keyed by course module id: cmid, courseid, modname,
     *                       coursefullname, coursevisible. A competency no activity carries has no key.
     */
    public function modules_with_competencies(array $competencyids): array {
        global $DB;

        if (!$competencyids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($competencyids), SQL_PARAMS_NAMED, 'comp');
        $params['modlevel'] = CONTEXT_MODULE;
        $ctxfields = \context_helper::get_preload_record_columns_sql('ctx');
        $rows = $DB->get_records_sql(
            "SELECT mc.id, mc.competencyid, cm.id AS cmid, cm.course AS courseid, md.name AS modname,
                    c.fullname AS coursefullname, c.visible AS coursevisible, $ctxfields
               FROM {competency_modulecomp} mc
               JOIN {course_modules} cm ON cm.id = mc.cmid
               JOIN {modules} md ON md.id = cm.module
               JOIN {course} c ON c.id = cm.course
          LEFT JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :modlevel
              WHERE mc.competencyid $insql AND cm.deletioninprogress = 0",
            $params
        );

        $out = [];
        foreach ($rows as $row) {
            \context_helper::preload_from_record($row);
            $out[(int) $row->competencyid][(int) $row->cmid] = (object) [
                'cmid' => (int) $row->cmid,
                'courseid' => (int) $row->courseid,
                'modname' => $row->modname,
                'coursefullname' => $row->coursefullname,
                'coursevisible' => (int) $row->coursevisible,
            ];
        }

        return $out;
    }

    /**
     * The framework of each of a list of competencies, in one reading (T-90).
     *
     * @param int[] $competencyids
     * @return int[] Competency id => framework id. A competency the core does not hold has no key.
     */
    public function frameworks_of_competencies(array $competencyids): array {
        global $DB;

        if (!$competencyids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($competencyids), SQL_PARAMS_NAMED, 'comp');
        $out = [];
        foreach ($DB->get_records_select('competency', "id $insql", $params, '', 'id, competencyframeworkid') as $row) {
            $out[(int) $row->id] = (int) $row->competencyframeworkid;
        }

        return $out;
    }

    /**
     * The name, the blocs and the competencies of each of a list of frameworks, in two readings (T-90 lot 4).
     *
     * The page of the organisation lists every record the site imported: a reading per record would grow with the site.
     * A BLOC is a competency other competencies of the same framework sit under, as the rest of the plugin has it; a
     * record of one level - the France compétences export - has none. A parent that belongs to another framework is a
     * bloc of nothing here. COMPETENCIES are all the rows of the framework, blocs included, as
     * count_framework_competencies() counts them. A framework with no competency is returned with zeros; one the core no
     * longer holds, or an id of 0, has no key - the caller tells the two apart by that.
     *
     * @param int[] $frameworkids
     * @return \stdClass[] Keyed by framework id: name (the short name typed at the import), blocs, competencies.
     */
    public function framework_summaries(array $frameworkids): array {
        global $DB;

        $frameworkids = array_filter(array_map('intval', $frameworkids), fn($id) => $id > 0);
        if (!$frameworkids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($frameworkids), SQL_PARAMS_NAMED, 'fw');
        $rows = $DB->get_records_sql(
            "SELECT f.id, f.shortname, COUNT(c.id) AS competencies
               FROM {competency_framework} f
          LEFT JOIN {competency} c ON c.competencyframeworkid = f.id
              WHERE f.id $insql
           GROUP BY f.id, f.shortname",
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = (object) [
                'name' => (string) $row->shortname,
                'blocs' => 0,
                'competencies' => (int) $row->competencies,
            ];
        }
        if (!$out) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_keys($out), SQL_PARAMS_NAMED, 'fw');
        $blocs = $DB->get_records_sql(
            "SELECT c.competencyframeworkid AS frameworkid, COUNT(DISTINCT parent.id) AS blocs
               FROM {competency} c
               JOIN {competency} parent ON parent.id = c.parentid AND parent.competencyframeworkid = c.competencyframeworkid
              WHERE c.competencyframeworkid $insql
           GROUP BY c.competencyframeworkid",
            $params
        );
        foreach ($blocs as $row) {
            $out[(int) $row->frameworkid]->blocs = (int) $row->blocs;
        }

        return $out;
    }

    /**
     * The competencies of several frameworks, in one reading (T-90 lot 4).
     *
     * @param int[] $frameworkids
     * @return int[][] Framework id => competency ids, in id order. A framework with none, or that the core does not
     *                 hold, has no key.
     */
    public function competencies_of_frameworks(array $frameworkids): array {
        global $DB;

        $frameworkids = array_filter(array_map('intval', $frameworkids), fn($id) => $id > 0);
        if (!$frameworkids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($frameworkids), SQL_PARAMS_NAMED, 'fw');
        $out = [];
        $rows = $DB->get_records_select(
            'competency',
            "competencyframeworkid $insql",
            $params,
            'id ASC',
            'id, competencyframeworkid'
        );
        foreach ($rows as $row) {
            $out[(int) $row->competencyframeworkid][] = (int) $row->id;
        }

        return $out;
    }

    /**
     * The templates of the core that carry each of a list of competencies, in one reading (T-90 lot 4).
     *
     * What a template is named is the offer of training, not a fact about a person. A hidden template is returned with
     * visible = 0, never left out; its name is the core's secret unless the reader may manage templates (see
     * can_view_hidden_templates()), and the caller decides. Names are raw, and put in order by the collator rather than
     * by the database, for the reason courses_with_competency() gives.
     *
     * @param int[] $competencyids
     * @return \stdClass[][] Competency id => templates keyed by template id, name order: templateid, shortname, visible.
     *                       A competency no template carries has no key.
     */
    public function templates_with_competencies(array $competencyids): array {
        global $DB;

        if (!$competencyids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal(array_values($competencyids), SQL_PARAMS_NAMED, 'comp');
        $rows = $DB->get_records_sql(
            "SELECT tc.id, tc.competencyid, t.id AS templateid, t.shortname, t.visible
               FROM {competency_templatecomp} tc
               JOIN {competency_template} t ON t.id = tc.templateid
              WHERE tc.competencyid $insql",
            $params
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->competencyid][(int) $row->templateid] = (object) [
                'templateid' => (int) $row->templateid,
                'shortname' => (string) $row->shortname,
                'visible' => (int) $row->visible,
            ];
        }
        foreach ($out as &$templates) {
            \core_collator::asort_objects_by_property($templates, 'shortname');
        }

        return $out;
    }

    /**
     * Whether the current user may see the name of a hidden template (T-90 lot 4).
     *
     * The core lists hidden templates to who may manage them and to nobody else, and asks it at the system level
     * (api::list_templates_using_competency): moodle/competency:templatemanage.
     *
     * @return bool
     */
    public static function can_view_hidden_templates(): bool {
        return has_capability('moodle/competency:templatemanage', \context_system::instance());
    }

    /**
     * How many learning plans name a competency of each of a list of frameworks, for some people (T-90 lot 4).
     *
     * A number of PLANS, each counted once per framework however many of its competencies are there; no id of a person,
     * no name of a plan and no status leaves this method. Every plan counts, drafts included (decision of the owner,
     * 4 November 2026: the page shows a count, never a name, and the drafts are counted with the rest). The
     * competencies of a plan are read where the core reads them, the three places being exclusive: a complete plan
     * from its archive (competency_usercompplan, by plan AND user), a plan on a template from the template's list, any
     * other from its own list. The archive row carries a grade and a proficiency: neither is read (D-1).
     *
     * The people are cut in lists of 1000: a plan belongs to one person, so the counts of the cuts are added.
     *
     * @param int[] $userids The people whose plans are counted.
     * @return int[] Framework id => number of plans. A framework no plan names has no key.
     */
    public function plan_counts_of_frameworks(array $userids): array {
        global $DB;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        $out = [];
        foreach (array_chunk($userids, 1000) as $chunk) {
            [$insql1, $params1] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'pua');
            [$insql2, $params2] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'pub');
            [$insql3, $params3] = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'puc');
            $rows = $DB->get_records_sql(
                "SELECT u.frameworkid, COUNT(DISTINCT u.planid) AS plans
                   FROM (SELECT c.competencyframeworkid AS frameworkid, p.id AS planid
                           FROM {competency_plan} p
                           JOIN {competency_usercompplan} ucp ON ucp.planid = p.id AND ucp.userid = p.userid
                           JOIN {competency} c ON c.id = ucp.competencyid
                          WHERE p.userid $insql1 AND p.status = :complete1
                          UNION
                         SELECT c.competencyframeworkid AS frameworkid, p.id AS planid
                           FROM {competency_plan} p
                           JOIN {competency_templatecomp} tc ON tc.templateid = p.templateid
                           JOIN {competency} c ON c.id = tc.competencyid
                          WHERE p.userid $insql2 AND p.status <> :complete2
                                AND p.templateid IS NOT NULL AND p.templateid <> 0
                          UNION
                         SELECT c.competencyframeworkid AS frameworkid, p.id AS planid
                           FROM {competency_plan} p
                           JOIN {competency_plancomp} pc ON pc.planid = p.id
                           JOIN {competency} c ON c.id = pc.competencyid
                          WHERE p.userid $insql3 AND p.status <> :complete3
                                AND (p.templateid IS NULL OR p.templateid = 0)) u
               GROUP BY u.frameworkid",
                $params1 + $params2 + $params3
                    + ['complete1' => self::PLAN_COMPLETE, 'complete2' => self::PLAN_COMPLETE, 'complete3' => self::PLAN_COMPLETE]
            );
            foreach ($rows as $row) {
                $out[(int) $row->frameworkid] = ($out[(int) $row->frameworkid] ?? 0) + (int) $row->plans;
            }
        }

        return $out;
    }

    /**
     * Whether the current user may see a hidden course, and so its name (T-90).
     *
     * The core shows a hidden course to who holds moodle/course:viewhiddencourses in it, and to nobody else: the name
     * of a course the teacher has not opened can be a working title. Asked once per course by the caller.
     *
     * @param int $courseid
     * @return bool
     */
    public static function can_view_hidden_course(int $courseid): bool {
        $context = \context_course::instance($courseid, IGNORE_MISSING);

        return $context && has_capability('moodle/course:viewhiddencourses', $context);
    }

    /**
     * Whether the current user may see which competencies a course carries, as the core decides it (T-90).
     *
     * The rule is that of api::list_courses_using_competency(), word for word: coursecompetencyview, or
     * coursecompetencymanage, in the course. The user archetype holds the first by default, so on a standard site
     * every signed-in account reads the list; a site that withdraws it from its learners, in a course or a category,
     * keeps the link between that course and its competencies from them, here as in the core - but for one bit: the
     * pathway says "not readable" where every course carrying the competency is withheld, so that it does not say
     * "none" over courses that exist. Neither name, number nor address of such a course comes out. The core's other test
     * on a course, its visibility, is the caller's: a hidden course is listed without its name and not left out
     * (decision of 2 November 2026). Asked once per course by the caller.
     *
     * @param int $courseid
     * @return bool False for a course that does not exist.
     */
    public static function can_view_course_competencies(int $courseid): bool {
        $context = \context_course::instance($courseid, IGNORE_MISSING);

        return $context && has_any_capability(
            ['moodle/competency:coursecompetencyview', 'moodle/competency:coursecompetencymanage'],
            $context
        );
    }

    /**
     * Whether the current user may see which competencies an activity carries, as the core decides it (T-90 lot 1 bis).
     *
     * The rule is that of api::list_course_module_competencies(), without its first test: coursecompetencyview, or
     * coursecompetencymanage, in the context of the module. The core's first test, that the module be visible to the
     * reader, is the caller's: an activity the reader cannot see is listed without its name and not left out, as a
     * hidden course is. Asked once per activity by the caller.
     *
     * @param int $cmid
     * @return bool False for an activity that does not exist.
     */
    public static function can_view_module_competencies(int $cmid): bool {
        $context = \context_module::instance($cmid, IGNORE_MISSING);

        return $context && has_any_capability(
            ['moodle/competency:coursecompetencyview', 'moodle/competency:coursecompetencymanage'],
            $context
        );
    }

    /**
     * Whether the current user may read a person's learning plans, as the core decides it (T-90).
     *
     * The rule is plan::can_read_user(), word for word: planview in that person's context, plus planviewown on
     * oneself, or whoever may manage the plans (plan::can_manage_user(): planmanage, plus planmanageown on
     * oneself). The user archetype holds planviewown by default and a site may withdraw it; the manager archetype
     * holds planview - a site manager reads everyone's plans here as in the core, a manager of this plugin only if
     * the site gave it (conception T-90, § 4.3). The plugin opens no plan the core would not, and hides none it
     * lists: a reader who holds the right on drafts and not this one reads their drafts alone, as the core lists
     * them (see can_view_draft_plans_of() and list_user_plans()). The names of the core's capabilities are kept in
     * this class, as every name of the competency subsystem is.
     *
     * @param int $userid The person whose plans would be read.
     * @return bool
     */
    public static function can_view_plans_of(int $userid): bool {
        global $USER;

        $capabilities = ['moodle/competency:planview', 'moodle/competency:planmanage'];
        if ($userid === (int) $USER->id) {
            $capabilities[] = 'moodle/competency:planviewown';
            $capabilities[] = 'moodle/competency:planmanageown';
        }

        return has_any_capability($capabilities, \context_user::instance($userid));
    }

    /**
     * Whether the current user may read a person's draft plans too (T-90).
     *
     * The drafts - draft, waiting for review, in review - are the core's secret: planviewowndraft for
     * oneself, which no role holds by default, planviewdraft for another - and whoever may manage the drafts
     * (planmanagedraft, plus planmanageowndraft on oneself), word for word plan::can_read_user_draft().
     *
     * @param int $userid The person whose plans would be read.
     * @return bool
     */
    public static function can_view_draft_plans_of(int $userid): bool {
        global $USER;

        $capabilities = ['moodle/competency:planviewdraft', 'moodle/competency:planmanagedraft'];
        if ($userid === (int) $USER->id) {
            $capabilities[] = 'moodle/competency:planviewowndraft';
            $capabilities[] = 'moodle/competency:planmanageowndraft';
        }

        return has_any_capability($capabilities, \context_user::instance($userid));
    }

    /**
     * The status of a learning plan, in the words of the core.
     *
     * The screen never invents a word for a plan: brouillon, actif, terminé and the two review
     * states are the core's, in the user's language. A number the core does not know is given back
     * as it is, so that nothing is said that is not in the row.
     *
     * @param int $status One of the PLAN_* constants.
     * @return string
     */
    public static function plan_status_label(int $status): string {
        $words = [
            self::PLAN_DRAFT => 'draft',
            self::PLAN_ACTIVE => 'active',
            self::PLAN_COMPLETE => 'complete',
            self::PLAN_WAITING_FOR_REVIEW => 'waitingforreview',
            self::PLAN_IN_REVIEW => 'inreview',
        ];
        if (!isset($words[$status])) {
            return (string) $status;
        }

        return get_string('planstatus' . $words[$status], self::SUBSYSTEM);
    }
}
