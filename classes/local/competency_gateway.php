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
     * A site where none matches - renamed, or deleted - is told so instead of being handed a
     * framework on a scale nobody chose.
     *
     * @return \stdClass The scale row.
     */
    public static function default_competence_scale(): \stdClass {
        global $CFG, $DB;

        $strings = get_string_manager();
        $names = array_values(array_unique([
            get_string('defaultcompetencescale'),
            $strings->get_string('defaultcompetencescale', 'core', null, $CFG->lang),
            $strings->get_string('defaultcompetencescale', 'core', null, 'en'),
        ]));

        [$insql, $params] = $DB->get_in_or_equal($names, SQL_PARAMS_NAMED);
        $scales = $DB->get_records_select('scale', "courseid = 0 AND name $insql", $params, 'id ASC', '*', 0, 1);

        if (!$scales) {
            throw new \moodle_exception('errornocompetencescale', 'tool_vae');
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
}
