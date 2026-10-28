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

use tool_vae\event\declaration_denied;
use tool_vae\event\evidence_declared;

/**
 * Recording a declaration.
 *
 * A declaration is always first person. The candidate holds
 * moodle/competency:userevidencemanageown on their own context natively - it carries the
 * "user" archetype - which is what lets the core API accept the write without anyone being
 * granted a right over someone else's user context. Verified on Moodle 4.5.13.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class evidence_manager {
    /**
     * Settings an activity may be carried out in.
     *
     * PROVISIONAL. Open point PO-3 of the blueprint says this list is to be established
     * with a practitioner of the sector, not from a desk. It is closed rather than free so
     * that the setting cannot quietly become a second narrative field.
     *
     * @var string[]
     */
    public const SETTINGS = [
        'ehpad', 'ssiad', 'saad', 'esat', 'domicile', 'hopital', 'foyer', 'autre',
    ];

    /**
     * How wide the certification record code may be.
     *
     * The width of the column, and not a number chosen here: the point of a named constant is
     * that the check and the schema cannot drift apart in silence.
     *
     * @var int
     */
    public const CODE_MAXLENGTH = 32;

    /** @var int How wide the rank within a record may be. The width of the column, likewise. */
    public const RANK_MAXLENGTH = 8;

    /**
     * Text formats a narrative may use.
     *
     * HTML is excluded. The narrative is stored in this plugin's table, outside the
     * PARAM_CLEANHTML cleaning core applies to its own description field, and it is
     * rendered on the attestation screen - in the session of a manager who has access to
     * other people's dossiers. Allowing HTML there is a stored cross-site scripting hole
     * pointed at the one account worth attacking.
     *
     * @var string[] Moodle declares these constants as STRINGS, not integers.
     */
    public const FORMATS = [FORMAT_PLAIN, FORMAT_MARKDOWN];

    /**
     * Whether a text format may be used for a narrative.
     *
     * MOODLE DEFINES FORMAT_PLAIN AND FORMAT_MARKDOWN AS STRINGS - '2' and '4', not 2 and 4.
     * A strict in_array((int) $value, self::FORMATS, true) is therefore ALWAYS false,
     * whatever the input: it compares an integer to a string. The first version of this
     * check did exactly that, and rejected every declaration ever made. It reads as
     * obviously correct, and only running it shows otherwise.
     *
     * Both sides are normalised here, once, so the trap exists in a single place.
     *
     * @param mixed $value
     * @return bool
     */
    public static function is_allowed_format($value): bool {
        return in_array((int) $value, array_map('intval', self::FORMATS), true);
    }

    /** @var competency_gateway The only door to the core subsystem. */
    private competency_gateway $gateway;

    /**
     * Constructor.
     *
     * The gateway is injected so a test can substitute one that fails on the second core
     * call, which is how the transaction is exercised: no reachable input makes the plugin
     * write fail after the core write has succeeded.
     *
     * @param competency_gateway|null $gateway
     */
    public function __construct(?competency_gateway $gateway = null) {
        $this->gateway = $gateway ?? new competency_gateway();
    }

    /**
     * Record a candidate's declaration.
     *
     * Not named declare(): that is a reserved word in PHP.
     *
     * @param int $candidateid
     * @param \stdClass $data competencyid, periodstart, periodend, settingtype,
     *                        settingdetail, situation, situationformat.
     * @return \stdClass The stored record.
     */
    public function declare_activity(int $candidateid, \stdClass $data): \stdClass {
        global $DB, $USER;

        if (!competency_gateway::is_subsystem_enabled()) {
            throw new \moodle_exception('errorcompetenciesdisabled', 'tool_vae');
        }

        // 1. A declaration is first person, always.
        if ($candidateid !== (int) $USER->id) {
            throw new \moodle_exception('errordeclareforothers', 'tool_vae');
        }

        // 2. Permission, with the refusal logged BEFORE the exception. require_capability()
        // throws on the spot and would make criterion 2 impossible to satisfy.
        $context = \context_user::instance($candidateid);
        if (!has_capability('tool/vae:declare', $context)) {
            declaration_denied::create([
                'context' => $context,
                'relateduserid' => $candidateid,
            ])->trigger();

            throw new \required_capability_exception(
                $context,
                'tool/vae:declare',
                'nopermissions',
                ''
            );
        }

        // 3. Business validation. Refused BEFORE any write - criterion 3.
        $source = $this->validate($data);

        // 4. Two core writes then one of ours. Without a transaction, a failure on the
        // third leaves a core evidence record with no metadata, no period and no
        // attestation possible - invisible to this plugin and orphaned in tool_lp.
        $transaction = $DB->start_delegated_transaction();

        try {
            $corelabel = self::label_for(
                (string) $source->fichecode,
                (string) $source->ficherang,
                (int) $data->periodstart,
                (int) $data->periodend
            );

            $userevidenceid = $this->gateway->create_evidence($candidateid, $corelabel);

            $this->gateway->link_to_competency($userevidenceid, (int) $data->competencyid);

            $now = time();
            $record = (object) [
                'uuid' => identity_map::generate_ulid(),
                'userevidenceid' => $userevidenceid,
                'candidateid' => $candidateid,
                'periodstart' => (int) $data->periodstart,
                'periodend' => (int) $data->periodend,
                'settingtype' => $data->settingtype,
                'settingdetail' => $data->settingdetail ?? null,
                'situation' => $data->situation,
                'situationformat' => (int) $data->situationformat,
                'fichecode' => $source->fichecode,
                'ficherang' => $source->ficherang,
                // The source update date AS IT STANDS TODAY. A panel must be able to tell
                // which version of a framework a claim refers to, and a later re-import
                // must not silently rewrite the answer.
                'ficheupdated' => (int) $source->sourceupdated,
                // The label as it was written on the core record, kept verbatim. What the
                // dossier compares against later is THIS string, never a rebuilt one.
                'corelabel' => $corelabel,
                // And the competency the link was made to. Kept for the same reason: to tell
                // a link that is gone from a link that has been replaced by another.
                'competencyid' => (int) $data->competencyid,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => (int) $USER->id,
            ];
            $record->id = $DB->insert_record('tool_vae_evidence', $record);

            // Mint the candidate's opaque identifier now, so an export never has to invent
            // one for a dossier that already exists.
            identity_map::uuid_for(identity_map::TYPE_USER, $candidateid);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The rollback() call rethrows; the line below is never reached.
            $transaction->rollback($e);
            throw $e;
        }

        evidence_declared::create([
            'context' => $context,
            'objectid' => (int) $record->id,
            'relateduserid' => $candidateid,
            'other' => [
                'competencyid' => (int) $data->competencyid,
                'userevidenceid' => (int) $userevidenceid,
            ],
        ])->trigger();

        return $record;
    }

    /**
     * Check a submission, and return the source record the competency comes from.
     *
     * Errors name the offending field, because criterion 3 requires the message to identify
     * it - never colour alone, and never a generic refusal.
     *
     * @param \stdClass $data
     * @return \stdClass The tool_vae_source row.
     */
    private function validate(\stdClass $data): \stdClass {
        global $DB;

        $competency = $this->gateway->get_competency((int) ($data->competencyid ?? 0));
        if ($competency === null) {
            throw new \moodle_exception('errorunknowncompetency', 'tool_vae');
        }

        // A declaration must point at an IMPORTED certification record. Without a source
        // there is no publication date, and without a publication date the claim cannot say
        // which version of the framework it refers to - which is the whole point.
        $source = $DB->get_record('tool_vae_source', ['frameworkid' => $competency->frameworkid]);
        if (!$source) {
            throw new \moodle_exception('errorframeworknotimported', 'tool_vae');
        }

        // A FRAMEWORK HIDDEN IN tool_lp IS NOT DECLARED ON, as it is not offered to be chosen there (its picker lists the visible
        // frameworks only) and is refused when attached to a course. The core's own API does not ask - read_competency() and the
        // link of a piece of evidence ignore `visible` - so this plugin does, here, before anything is written: the screen
        // leaves the record out, and a form opened before it was hidden is refused all the same. What was declared stays.
        // The refusal is written for the person who declares (hidden_frameworks), who is not the administrator who hid it: the
        // record by its title and its code, that it is hidden, and whom to ask. The pages give the form back with its text.
        if (!$this->gateway->framework_visible((int) $source->frameworkid)) {
            throw (new hidden_frameworks($this->gateway))->exception($source);
        }

        [$fichecode, $ficherang] = self::split_idnumber($competency->idnumber);
        if ($ficherang === null) {
            throw new \moodle_exception('errorcompetencywithoutrank', 'tool_vae');
        }

        // THE COLUMN WIDTHS ARE A RULE OF THIS PLUGIN, SO THIS PLUGIN ENFORCES THEM. These two
        // values are not typed by anyone: they are split off the idnumber the competency
        // carries, which comes from whatever framework an administrator imported. Left
        // unchecked, `RNCP36832-BC01C0299` - an ordinary shape for a block of competencies -
        // reached the database and came back as
        // `dml_write_exception: value too long for type character varying(8)`, on the
        // candidate's screen. Measured on 28 August 2026.
        //
        // Criterion 3 requires an error to NAME the field it is about. A driver message names
        // a column type. And this cannot wait for the import of lot 6 to be written: the
        // frameworks that break it can already be created by hand in tool_lp.
        //
        // ESCAPED, LIKE THE REFUSALS BELOW: the message is shown as HTML, and both values are fragments of an idnumber an
        // administrator typed by hand into tool_lp. A rank of nine characters or more, such as `<img src=x>`, is refused here
        // before it reaches the refusal that escapes it.
        if (\core_text::strlen($fichecode) > self::CODE_MAXLENGTH) {
            throw new \moodle_exception('errorfichecodetoolong', 'tool_vae', '', (object) [
                'code' => s($fichecode),
                'max' => self::CODE_MAXLENGTH,
            ]);
        }
        if (\core_text::strlen($ficherang) > self::RANK_MAXLENGTH) {
            throw new \moodle_exception('errorficheranktoolong', 'tool_vae', '', (object) [
                'rank' => s($ficherang),
                'max' => self::RANK_MAXLENGTH,
            ]);
        }

        // THE WIDTH IS NOT THE FORM. The archive this claim goes into is held to the schema, and the schema
        // holds a rank to importer::RANK_PATTERN: capital letters and digits. `RNCP36832-c7`, `-C7.1` and
        // `-BC 1` are all within eight characters, and tool_lp lets an administrator type any of them into an
        // imported framework (the idnumber is PARAM_RAW there). Accepted here, such a claim was exported, registered
        // and downloaded, then refused whole on reimport - the valid declarations beside it with it, and the
        // uninstall backup with them. Refused here, by name, at the one door every claim goes through. What this
        // plugin writes itself (importer, fiche_converter) already is within the pattern.
        if (!preg_match(importer::RANK_PATTERN, $ficherang)) {
            throw new \moodle_exception('errorficherankinvalid', 'tool_vae', '', (object) [
                // Escaped: by construction this value is one the pattern refuses, whatever it holds.
                'rank' => s($ficherang),
                'code' => s($fichecode),
                'max' => self::RANK_MAXLENGTH,
            ]);
        }

        // THE CODE IS THE RECORD'S, NOT WHATEVER PRECEDES THE LAST HYPHEN. `RNCP36832bis-C7` splits into a code that is
        // not the one of the record this framework was imported as: the claim would be written under a record that does
        // not exist here, and the archive that carries it is refused on reimport (errorarchivenocompetency).
        if ($fichecode !== $source->fichecode) {
            throw new \moodle_exception('errorcompetencyoutsiderecord', 'tool_vae', '', (object) [
                'idnumber' => s((string) $competency->idnumber),
                'code' => s((string) $source->fichecode),
            ]);
        }

        if (empty($data->periodstart) || empty($data->periodend)) {
            throw new \moodle_exception('errorperiodmissing', 'tool_vae');
        }
        if ((int) $data->periodend < (int) $data->periodstart) {
            throw new \moodle_exception('errorperiodreversed', 'tool_vae');
        }

        if (!in_array($data->settingtype ?? '', self::SETTINGS, true)) {
            throw new \moodle_exception('errorsettingunknown', 'tool_vae');
        }
        if (isset($data->settingdetail) && \core_text::strlen($data->settingdetail) > 255) {
            throw new \moodle_exception('errorsettingdetailtoolong', 'tool_vae');
        }

        if (trim((string) ($data->situation ?? '')) === '') {
            throw new \moodle_exception('errorsituationempty', 'tool_vae');
        }
        if (!self::is_allowed_format($data->situationformat ?? -1)) {
            throw new \moodle_exception('errorsituationformat', 'tool_vae');
        }

        $source->fichecode = $fichecode;
        $source->ficherang = $ficherang;

        return $source;
    }

    /**
     * Split an idnumber of the form CODE-RANK.
     *
     * @param string|null $idnumber
     * @return array{0: string, 1: string|null}
     */
    public static function split_idnumber(?string $idnumber): array {
        $idnumber = trim((string) $idnumber);
        $position = strrpos($idnumber, '-');

        if ($position === false || $position === 0 || $position === strlen($idnumber) - 1) {
            return [$idnumber, null];
        }

        return [substr($idnumber, 0, $position), substr($idnumber, $position + 1)];
    }

    /**
     * The label written into the core record.
     *
     * GENERATED, never typed. Core copies this string into competency_evidence.desca on
     * both link and unlink, so anything a user could put here would persist in a core table
     * this plugin does not control - including after the declaration is deleted.
     *
     * Deliberately symbolic and untranslated: it is stored once and read later, possibly by
     * someone whose language differs from the person who created it.
     *
     * THE TIMEZONE IS THE SITE'S, NOT THE READER'S. Timezone 99 is the reader's own, and a
     * period starting on the first of a month falls in the previous one a few hours away: the
     * same claim would then be labelled differently depending on who happened to create it.
     * The site timezone is fixed for a site, and it is the calendar its users keep.
     *
     * AND THE LABEL IS NOT REBUILT LATER, IT IS STORED - in `corelabel`, on this plugin's own
     * row. That is what the dossier compares the core record against. A rebuilt label carries
     * the timezone it was rebuilt in, so it would report a divergence the day an administrator
     * changes the site's timezone setting. Measured: the test that asserts this failed while
     * the comparison still rebuilt the label.
     *
     * @param string $fichecode
     * @param string $ficherang
     * @param int $periodstart
     * @param int $periodend
     * @return string
     */
    public static function label_for(string $fichecode, string $ficherang, int $periodstart, int $periodend): string {
        $timezone = \core_date::get_server_timezone();

        return sprintf(
            '%s-%s · %s → %s',
            $fichecode,
            $ficherang,
            userdate($periodstart, '%Y-%m', $timezone, false),
            userdate($periodend, '%Y-%m', $timezone, false)
        );
    }
}
