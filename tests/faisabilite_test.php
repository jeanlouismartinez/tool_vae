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

use tool_vae\local\draft_manager;
use tool_vae\local\faisabilite_catalog;
use tool_vae\local\faisabilite_overview;

/**
 * The catalogue of the sections of the dossier de faisabilité and the overview built on it
 * (T-10, lot 1).
 *
 * WHAT IS ASSERTED. That the catalogue is the only list of places - an unknown code or a slot
 * beyond the limit cannot be written - and that the overview says what is there without creating
 * anything.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\faisabilite_catalog::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\faisabilite_overview::class)]
final class faisabilite_test extends \advanced_testcase {
    /** @var \stdClass A candidate who may write. */
    private $candidate;

    /**
     * A candidate who may write.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user();
        $role = $generator->create_role();
        assign_capability('tool/vae:draftown', CAP_ALLOW, $role, \context_system::instance()->id, true);
        role_assign($role, $this->candidate->id, \context_user::instance($this->candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->candidate);
    }

    /**
     * The sections of annexe II come in the order of the annex, and every one has its label.
     */
    public function test_the_catalogue_lists_the_sections_in_the_order_of_the_annex(): void {
        $this->assertSame([
            'certification', 'prerequis', 'identite', 'contact', 'statut',
            'objectifs', 'experiences', 'blocs', 'parcours', 'engagement',
        ], array_keys(faisabilite_catalog::sections()));

        foreach (array_keys(faisabilite_catalog::sections()) as $code) {
            $this->assertTrue(get_string_manager()->string_exists('faisabsection:' . $code, 'tool_vae'), $code);
        }
    }

    /**
     * Every section is open, and each has its kind: identity and status are fields, contact is read.
     */
    public function test_every_section_is_open_and_has_its_kind(): void {
        foreach (array_keys(faisabilite_catalog::sections()) as $code) {
            $this->assertTrue(faisabilite_catalog::is_open($code), $code);
        }
        $this->assertFalse(faisabilite_catalog::is_open('nonexistent'));

        $this->assertSame(faisabilite_catalog::KIND_FIELDS, faisabilite_catalog::kind('identite'));
        $this->assertSame(faisabilite_catalog::KIND_FIELDS, faisabilite_catalog::kind('statut'));
        $this->assertSame(faisabilite_catalog::KIND_ACCOUNT, faisabilite_catalog::kind('contact'));
        $this->assertSame(faisabilite_catalog::KIND_TEXT, faisabilite_catalog::kind('objectifs'));
        $this->assertNull(faisabilite_catalog::kind('nonexistent'));
    }

    /**
     * A single section has the place 0 and a repeated one has 1 up to its limit.
     */
    public function test_addresses_are_checked_against_the_catalogue(): void {
        $this->assertTrue(faisabilite_catalog::valid_address('objectifs', 0));
        $this->assertFalse(faisabilite_catalog::valid_address('objectifs', 1));

        $this->assertFalse(faisabilite_catalog::valid_address('experiences', 0));
        $this->assertTrue(faisabilite_catalog::valid_address('experiences', 1));
        $this->assertTrue(faisabilite_catalog::valid_address('experiences', faisabilite_catalog::MAX_EXPERIENCES));
        $this->assertFalse(faisabilite_catalog::valid_address('experiences', faisabilite_catalog::MAX_EXPERIENCES + 1));

        $this->assertFalse(faisabilite_catalog::valid_address('nonexistent', 0));
    }

    /**
     * The manager creates nothing for a place the catalogue does not hold.
     */
    public function test_the_manager_refuses_a_place_the_catalogue_does_not_hold(): void {
        global $DB;

        $manager = new draft_manager();
        $id = (int) $this->candidate->id;

        foreach ([['nonexistent', 0], ['objectifs', 1], ['experiences', 0], ['experiences', 11]] as [$section, $slot]) {
            try {
                $manager->section($id, draft_manager::DOCTYPE_FAISABILITE, $section, $slot);
                $this->fail("$section/$slot was accepted.");
            } catch (\moodle_exception $e) {
                $this->assertSame('errordraftsection', $e->errorcode);
            }
        }
        $this->assertSame(0, $DB->count_records('tool_vae_draft'));
    }

    /**
     * The overview reads and creates nothing: every place is empty, a repeated section offers its first slot.
     */
    public function test_the_overview_of_a_new_candidate_is_empty_and_creates_nothing(): void {
        global $DB;

        $overview = faisabilite_overview::for_candidate((int) $this->candidate->id);

        $this->assertSame(0, $DB->count_records('tool_vae_draft'));
        $this->assertSame(array_keys(faisabilite_catalog::sections()), array_keys($overview));
        $this->assertSame(faisabilite_overview::STATE_EMPTY, $overview['objectifs']['places'][0]['state']);
        $this->assertSame([], $overview['experiences']['places']);
        $this->assertSame(1, $overview['experiences']['nextslot']);
        $this->assertNull($overview['objectifs']['nextslot']);
        $this->assertSame(faisabilite_overview::STATE_ACCOUNT, $overview['contact']['places'][0]['state']);
        $this->assertSame(faisabilite_overview::STATE_EMPTY, $overview['identite']['places'][0]['state']);
    }

    /**
     * The four states, and the next free slot of a repeated section.
     */
    public function test_the_overview_says_draft_version_and_both(): void {
        $manager = new draft_manager();
        $id = (int) $this->candidate->id;
        $type = draft_manager::DOCTYPE_FAISABILITE;

        $objectifs = $manager->section($id, $type, 'objectifs');
        $manager->save_working((int) $objectifs->id, 'Un premier jet.');

        $parcours = $manager->section($id, $type, 'parcours');
        $manager->save_version((int) $parcours->id, 'Une version.');

        $prerequis = $manager->section($id, $type, 'prerequis');
        $manager->save_version((int) $prerequis->id, 'Une version.');
        $manager->save_working((int) $prerequis->id, 'Un brouillon plus récent.');

        $first = $manager->section($id, $type, 'experiences', 1);
        $manager->save_version((int) $first->id, 'Première expérience.');
        $manager->section($id, $type, 'experiences', 2);

        $overview = faisabilite_overview::for_candidate($id);

        $this->assertSame(faisabilite_overview::STATE_DRAFT, $overview['objectifs']['places'][0]['state']);
        $this->assertSame(faisabilite_overview::STATE_VERSION, $overview['parcours']['places'][0]['state']);
        $this->assertSame(1, $overview['parcours']['places'][0]['version']);
        $this->assertSame(faisabilite_overview::STATE_VERSION_DRAFT, $overview['prerequis']['places'][0]['state']);
        $this->assertSame(faisabilite_overview::STATE_VERSION, $overview['experiences']['places'][1]['state']);
        $this->assertSame(
            faisabilite_overview::STATE_EMPTY,
            $overview['experiences']['places'][2]['state'],
            'Opened, not written.'
        );
        $this->assertSame(3, $overview['experiences']['nextslot']);
        $this->assertSame(
            (int) $objectifs->id,
            $overview['objectifs']['places'][0]['draftid']
        );
    }

    /**
     * A place is named as the candidate reads it, and an unknown code is shown as it is.
     */
    public function test_a_place_is_named_for_the_candidate(): void {
        $this->assertSame(
            get_string('faisabsection:objectifs', 'tool_vae'),
            faisabilite_catalog::label('objectifs', 0)
        );
        $this->assertSame(
            get_string('faisabslot', 'tool_vae', (object) [
                'section' => get_string('faisabsection:experiences', 'tool_vae'),
                'slot' => 2,
            ]),
            faisabilite_catalog::label('experiences', 2)
        );
        $this->assertSame('unknown_code', faisabilite_catalog::label('unknown_code', 0));
    }

    /**
     * Every section has a help sentence, in English and in French.
     */
    public function test_every_section_has_its_help(): void {
        foreach (array_keys(faisabilite_catalog::sections()) as $code) {
            $this->assertNotEmpty(faisabilite_catalog::help($code), $code);
        }
        $this->assertNull(faisabilite_catalog::help('unknown_code'));
    }

    /**
     * The overview carries the time of the last change, and none for a place that was never written.
     */
    public function test_the_overview_carries_the_time_of_the_last_change(): void {
        $manager = new draft_manager();
        $id = (int) $this->candidate->id;
        $type = draft_manager::DOCTYPE_FAISABILITE;

        $objectifs = $manager->section($id, $type, 'objectifs');
        $manager->save_working((int) $objectifs->id, 'Un premier jet.');
        $manager->section($id, $type, 'experiences', 1);

        $overview = faisabilite_overview::for_candidate($id);

        $this->assertGreaterThan(0, $overview['objectifs']['places'][0]['modified']);
        $this->assertSame(0, $overview['experiences']['places'][1]['modified'], 'Opened, never written.');
        $this->assertSame(0, $overview['parcours']['places'][0]['modified']);
    }

    /**
     * Another candidate's sections do not appear.
     */
    public function test_the_overview_is_the_candidates_own(): void {
        $manager = new draft_manager();
        $type = draft_manager::DOCTYPE_FAISABILITE;
        $manager->save_working(
            (int) $manager->section((int) $this->candidate->id, $type, 'objectifs')->id,
            'Mon texte.'
        );

        $other = $this->getDataGenerator()->create_user();
        $overview = faisabilite_overview::for_candidate((int) $other->id);

        $this->assertSame(faisabilite_overview::STATE_EMPTY, $overview['objectifs']['places'][0]['state']);
    }
}
