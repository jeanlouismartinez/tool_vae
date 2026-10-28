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

namespace tool_vae;

use tool_vae\fixtures\unreadable_gateway;
use tool_vae\local\anchor;
use tool_vae\local\competency_gateway;
use tool_vae\local\evidence_manager;

/**
 * The anchor: what happens when the core record a claim hangs off is changed elsewhere.
 *
 * WHAT THIS FILE IS ABOUT. The candidate owns the evidence record this plugin anchors a claim
 * to, and can rename it or unlink its competency from tool_lp, under a capability this plugin
 * neither grants nor can revoke. Measured on a running site before any of this code was
 * written: both are two clicks away, neither is visible from the plugin, and one of them raises
 * no event at all.
 *
 * The failure being guarded against is not a leak but a LIE - a dossier that goes on asserting
 * a certification record and a competency link the platform no longer attests, read by a panel
 * with no way to know. Nothing here prevents the change; everything here makes it visible.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\anchor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\attest_screen::class)]
final class anchor_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass The claim. */
    private $claim;

    /** @var int The competency claimed. */
    private $competencyid;

    /** @var int The framework it belongs to. */
    private $frameworkid;

    /**
     * One candidate, one declaration, anchored to a real core record.
     */
    protected function setUp(): void {
        parent::setUp();
        global $CFG, $DB;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/formslib.php');
        require_once(__DIR__ . '/fixtures/unreadable_gateway.php');

        $generator = $this->getDataGenerator();
        $lpg = $generator->get_plugin_generator(competency_gateway::SUBSYSTEM);

        $framework = $lpg->create_framework(['shortname' => 'RNCP36832']);
        $competency = $lpg->create_competency([
            'competencyframeworkid' => $framework->get('id'),
            'idnumber' => 'RNCP36832-C03',
            'shortname' => 'Repérer les facteurs de risque de chute',
        ]);
        $this->competencyid = (int) $competency->get('id');
        $this->frameworkid = (int) $framework->get('id');

        $DB->insert_record('tool_vae_source', (object) [
            'frameworkid' => (int) $framework->get('id'),
            'fichecode' => 'RNCP36832',
            'fichetype' => 'rncp',
            'sourceurl' => 'https://www.data.gouv.fr/',
            'sourceupdated' => 1781136000,
            'licence' => 'LO-2.0',
            'attribution' => 'France compétences',
            'lastimport' => time(),
            'lastimportstatus' => 'ok',
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => 2,
        ]);

        $this->candidate = $generator->create_user();
        $roleid = $generator->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($this->candidate);
        $this->claim = (new evidence_manager())->declare_activity((int) $this->candidate->id, (object) [
            'competencyid' => $this->competencyid,
            'periodstart' => 1756684800,
            'periodend' => 1782172800,
            'settingtype' => 'ehpad',
            'settingdetail' => 'Unité protégée, équipe de nuit',
            'situation' => 'Accompagnement au lever et à la toilette.',
            'situationformat' => FORMAT_PLAIN,
        ]);
    }

    /**
     * Reload the claim as the dossier would read it.
     *
     * @return \stdClass
     */
    private function claim_record(): \stdClass {
        global $DB;

        return $DB->get_record('tool_vae_evidence', ['id' => $this->claim->id], '*', MUST_EXIST);
    }

    /**
     * A claim nobody has touched reports no divergence.
     *
     * The premise of every test below. Without it, a divergence() that returned a value in
     * every case would pass them all.
     */
    public function test_an_untouched_claim_reports_nothing(): void {
        $this->assertNull(anchor::divergence($this->claim_record()));
    }

    /**
     * The verdict does not move with the timezone the site is set to.
     *
     * THIS TEST FAILED, AND THAT IS WHY THE COLUMN EXISTS. The comparison first rebuilt the
     * label from the claim's dates; rebuilt in another timezone, a period starting on the
     * first of a month falls in the previous one, and the plugin reported a rename that never
     * happened - `2025-09` against `2025-08`, measured. A detector that cries wolf gets turned
     * off, and then it protects nothing. The label written on the core record is now KEPT, and
     * the comparison is between two strings that were both fixed in the past.
     */
    public function test_the_verdict_does_not_move_with_the_timezone(): void {
        $this->setTimezone('Pacific/Auckland', 'Pacific/Auckland');
        $this->assertNull(anchor::divergence($this->claim_record()));

        $this->setTimezone('America/Los_Angeles', 'America/Los_Angeles');
        $this->assertNull(anchor::divergence($this->claim_record()), 'The verdict moved with the site clock.');
    }

    /**
     * A claim made before the reference existed is not accused of anything.
     *
     * There is no such row in production - no site runs this plugin - but the upgrade path
     * creates the column empty, and a detector that treats "I do not know" as "renamed" would
     * flag every existing dossier the day the site is upgraded.
     */
    public function test_a_claim_with_no_reference_is_not_accused(): void {
        global $DB;

        $DB->set_field('tool_vae_evidence', 'corelabel', null, ['id' => $this->claim->id]);
        $this->rename_core_record('Anything the candidate feels like typing');

        $this->assertNull(anchor::divergence($this->claim_record()));
    }

    /**
     * Renaming the core record is reported.
     */
    public function test_renaming_the_core_record_is_reported(): void {
        $this->rename_core_record('Anything the candidate feels like typing');

        $this->assertSame(anchor::RENAMED, anchor::divergence($this->claim_record()));
    }

    /**
     * Unlinking the competency is reported - and it raises no event, so only this can catch it.
     */
    public function test_unlinking_the_competency_is_reported(): void {
        $this->unlink_competency();

        $this->assertSame(anchor::UNLINKED, anchor::divergence($this->claim_record()));
    }

    /**
     * A core record that vanished by some route raising no event is reported.
     */
    public function test_a_vanished_core_record_is_reported(): void {
        global $DB;

        // Deleted by hand, the way a bulk operation or a restore would: no event, so the
        // observer of lot 5 never runs and the claim survives its anchor.
        $DB->delete_records('competency_userevidence', ['id' => $this->claim->userevidenceid]);

        $this->assertSame(anchor::MISSING, anchor::divergence($this->claim_record()));
    }

    /**
     * The dossier says so, in words, and not only in a log.
     *
     * The event is a trace for afterwards; this is what the person reading the dossier sees.
     * A guarantee that lives only in logstore_standard_log informs nobody.
     */
    public function test_the_dossier_tells_its_reader(): void {
        $this->rename_core_record('Anything the candidate feels like typing');

        $record = $this->claim_record();
        $html = local\presenter::anchor_warning($record, anchor::divergence($record));

        $this->assertStringContainsString('RNCP36832-C03', $html);
        $this->assertStringContainsString(
            get_string('anchorrenamed', 'tool_vae', (object) ['record' => 'RNCP36832-C03']),
            html_to_text($html, 0, false)
        );
    }

    /**
     * The screen where a manager decides to sign says it as well, before the declaration.
     *
     * The attestation screen is the only reading of the narrative by someone who did not write it, and a favourite or an
     * open tab reaches it without passing by the dossier. It went on saying "Competency: ..." for a link the platform no
     * longer held, with not a line to tell the signatory so. The screen and the withdrawal screen draw the declaration
     * through attest_screen, which puts the warning of the dossier before it.
     */
    public function test_the_attestation_screen_tells_its_reader_before_the_declaration(): void {
        $this->unlink_competency();
        $record = $this->claim_record();
        $context = \context_user::instance((int) $this->candidate->id);

        $html = local\attest_screen::declaration($record, $context);

        $warning = local\presenter::anchor_warning($record, anchor::divergence($record));
        $this->assertNotSame('', $warning, 'The premise: the claim was unlinked, the dossier warns.');
        $this->assertSame($warning . local\presenter::declaration($record, $context), $html, 'The warning comes first.');
        $this->assertStringContainsString(
            get_string('anchorunlinked', 'tool_vae', (object) ['record' => 'RNCP36832-C03']),
            html_to_text($html, 0, false)
        );
    }

    /**
     * And the screen is the declaration alone when the anchor holds.
     */
    public function test_the_attestation_screen_is_the_declaration_alone_when_the_anchor_holds(): void {
        $record = $this->claim_record();
        $context = \context_user::instance((int) $this->candidate->id);

        $this->assertSame(
            local\presenter::declaration($record, $context),
            local\attest_screen::declaration($record, $context)
        );
    }

    /**
     * And says nothing when there is nothing to say.
     */
    public function test_the_dossier_stays_quiet_when_the_anchor_holds(): void {
        $record = $this->claim_record();

        $this->assertSame('', local\presenter::anchor_warning($record, anchor::divergence($record)));
    }

    /**
     * The core's own update event reaches this plugin, and leaves a trace.
     */
    public function test_the_rename_is_logged(): void {
        $sink = $this->redirectEvents();
        $this->rename_core_record('Anything the candidate feels like typing');

        foreach ($sink->get_events() as $event) {
            if ($event instanceof \core\event\competency_user_evidence_updated) {
                observer::user_evidence_updated($event);
            }
        }

        $events = $sink->get_events();
        $sink->close();

        $diverged = array_values(array_filter($events, function ($event) {
            return $event instanceof \tool_vae\event\evidence_diverged;
        }));

        $this->assertCount(1, $diverged, 'The divergence went unrecorded.');
        $this->assertEquals($this->claim->id, $diverged[0]->objectid);
        $this->assertEquals($this->candidate->id, $diverged[0]->relateduserid);
        foreach ($diverged[0]->other as $value) {
            $this->assertIsInt($value);
        }
    }

    /**
     * An update that changes nothing this plugin cares about is not reported.
     *
     * The core raises its update event for any change at all, the url field included. A
     * detector that logged every one of them would fill the log with noise, and the day a real
     * divergence appeared nobody would be reading.
     */
    public function test_an_update_that_leaves_the_label_alone_is_not_reported(): void {
        $sink = $this->redirectEvents();
        $this->update_core_record(['url' => 'https://example.invalid/attestation.pdf']);

        foreach ($sink->get_events() as $event) {
            if ($event instanceof \core\event\competency_user_evidence_updated) {
                observer::user_evidence_updated($event);
            }
        }

        $events = $sink->get_events();
        $sink->close();

        $diverged = array_filter($events, function ($event) {
            return $event instanceof \tool_vae\event\evidence_diverged;
        });

        $this->assertCount(0, $diverged, 'A divergence was announced where nothing diverged.');
    }

    // ----------------------------------------------------------------------
    // Uninstalling.

    /**
     * Uninstalling leaves the candidate's evidence, with a label that means something.
     *
     * Decision of 28 August 2026. Moodle removes this plugin's own tables, settings and
     * capabilities by itself; what it cannot know is that this plugin wrote rows in another
     * component's table. Left alone, every candidate kept an entry labelled with a code that
     * no longer resolves to anything, explained by no screen.
     */
    public function test_uninstalling_leaves_a_readable_label_and_keeps_the_evidence(): void {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/admin/tool/vae/db/uninstall.php');

        $before = $DB->get_field('competency_userevidence', 'name', ['id' => $this->claim->userevidenceid]);
        $this->assertSame($this->claim_record()->corelabel, $before, 'The stored reference and the core disagree.');

        xmldb_tool_vae_uninstall();

        $after = $DB->get_record('competency_userevidence', ['id' => $this->claim->userevidenceid], '*', MUST_EXIST);

        $this->assertNotSame($before, $after->name, 'The coded label survived the uninstall.');
        $this->assertStringContainsString('RNCP36832-C03', $after->name);
        $this->assertLessThanOrEqual(100, \core_text::strlen($after->name), 'The core column holds 100 characters.');
        $this->assertTrue(
            $DB->record_exists('competency_userevidencecomp', ['userevidenceid' => $this->claim->userevidenceid]),
            'The candidate lost the link to the competency their evidence stood for.'
        );
    }

    /**
     * Swapping one competency for another is reported.
     *
     * THE TEST THE FIRST VERSION DID NOT HAVE, AND THE DEFECT IT HID. The check asked whether
     * ANY link survived, so unlinking C03 and linking C07 - two clicks in tool_lp - left the
     * verdict at "nothing has changed" while the dossier went on naming C03. Measured in
     * adversarial review. The claim now records WHICH competency it was made against.
     */
    public function test_swapping_the_competency_is_reported(): void {
        $other = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM)->create_competency([
            'competencyframeworkid' => $this->frameworkid,
            'idnumber' => 'RNCP36832-C07',
            'shortname' => 'Transmettre une observation',
        ]);

        $this->setUser($this->candidate);
        $gateway = new competency_gateway();
        $gateway->link_to_competency((int) $this->claim->userevidenceid, (int) $other->get('id'));
        $gateway->unlink_competency((int) $this->claim->userevidenceid, $this->competencyid);

        $this->assertSame(anchor::UNLINKED, anchor::divergence($this->claim_record()));
    }

    /**
     * When both have happened, the graver one is what the reader is told.
     *
     * Renaming and unlinking are independent. Reported as a rename, the reader gets a note
     * about a label where they needed to be told that the platform no longer attests the link.
     */
    public function test_renamed_and_unlinked_reports_the_unlink(): void {
        $this->rename_core_record('Anything the candidate feels like typing');
        $this->unlink_competency();

        $this->assertSame(anchor::UNLINKED, anchor::divergence($this->claim_record()));
    }

    /**
     * A failure to read the core says nothing at all - it never says "gone".
     *
     * A timeout is not a deletion. Rendered as one, the dossier tells a candidate in front of
     * a panel that their evidence has vanished, because a query took too long.
     */
    public function test_a_failure_to_read_is_not_reported_as_a_disappearance(): void {
        $verdict = anchor::divergence($this->claim_record(), new unreadable_gateway());

        $this->assertDebuggingCalled();
        $this->assertNull($verdict, 'A database failure was rendered as a missing record.');
    }

    // WHAT THIS FILE DOES NOT COVER, NAMED RATHER THAN LEFT TO BE DISCOVERED. The test above
    // substitutes a gateway that throws, so it proves what divergence() does with a failure -
    // not that the GATEWAY only swallows a genuinely missing record. Widening its catch from
    // dml_missing_record_exception to \Throwable leaves this suite green: verified by mutation.
    // Exercising it would mean breaking the database under a live query, which no portable
    // test can do. The narrow catch is held by reading and by the comment that explains it.

    /**
     * The uninstall does not leave every dossier accusing its owner.
     *
     * The hook rewrites the label on the core record; if the stored reference did not follow,
     * an uninstall interrupted before the tables are dropped - a timeout, a closed tab - would
     * leave each claim reporting a rename the plugin itself performed. Measured in review.
     */
    public function test_the_uninstall_does_not_manufacture_a_divergence(): void {
        global $CFG;

        require_once($CFG->dirroot . '/admin/tool/vae/db/uninstall.php');
        xmldb_tool_vae_uninstall();

        $this->assertNull(anchor::divergence($this->claim_record()));
    }

    /**
     * And it does not rewrite the provenance of a record that is not this plugin's.
     *
     * The persistent stamps usermodified on every update: relabelling through it left the
     * candidate's own record claiming the administrator who removed a plugin had edited it.
     */
    public function test_the_uninstall_leaves_the_provenance_of_the_core_record(): void {
        global $CFG, $DB;

        $before = $DB->get_record('competency_userevidence', ['id' => $this->claim->userevidenceid], '*', MUST_EXIST);

        require_once($CFG->dirroot . '/admin/tool/vae/db/uninstall.php');
        $this->setAdminUser();
        xmldb_tool_vae_uninstall();

        $after = $DB->get_record('competency_userevidence', ['id' => $this->claim->userevidenceid], '*', MUST_EXIST);

        $this->assertEquals($before->usermodified, $after->usermodified, 'The candidate lost the provenance of their own record.');
        $this->assertEquals($before->userid, $after->userid);
    }

    /**
     * Rename the core record the way the candidate does from tool_lp.
     *
     * @param string $name
     */
    private function rename_core_record(string $name): void {
        $this->update_core_record(['name' => $name]);
    }

    /**
     * Update the core record as the candidate does from tool_lp.
     *
     * Through the gateway, because MC-5 forbids naming the core anywhere else - the test files
     * included. Through the core's own path rather than a write to the table, because the
     * point is to raise the event this plugin observes.
     *
     * @param array $fields
     */
    private function update_core_record(array $fields): void {
        $this->setUser($this->candidate);
        (new competency_gateway())->update_evidence((int) $this->claim->userevidenceid, $fields);
    }

    /**
     * Unlink the competency the way the candidate does from tool_lp.
     */
    private function unlink_competency(): void {
        $this->setUser($this->candidate);
        (new competency_gateway())->unlink_competency((int) $this->claim->userevidenceid, $this->competencyid);
    }
}
