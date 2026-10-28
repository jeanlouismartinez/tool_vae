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
use tool_vae\local\faisabilite_fields;

/**
 * The separate fields of the dossier de faisabilité - identity and status (T-10, lot 3).
 *
 * WHAT IS ASSERTED. That a value is kept only if the field accepts it, that the same values make
 * the same text (so that saving twice creates no second version), that the account's values are
 * read and never kept, and that the identity is read with a capability of its own.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\faisabilite_fields::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\draft_manager::class)]
final class faisabilite_fields_test extends \advanced_testcase {
    /** @var \stdClass The candidate. */
    private $candidate;

    /** @var \stdClass A reader who follows the candidate and holds draftview only. */
    private $reader;

    /** @var \stdClass A reader who follows the candidate and holds draftview and identityview. */
    private $identityreader;

    /**
     * A candidate who may write, and two readers.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $this->candidate = $generator->create_user([
            'firstname' => 'Camille', 'lastname' => 'Durand', 'email' => 'camille@example.com',
            'city' => 'Nantes', 'country' => 'FR', 'phone1' => '0240000000', 'address' => '1 rue des Lilas',
        ]);
        $this->reader = $generator->create_user();
        $this->identityreader = $generator->create_user();
        $context = \context_user::instance($this->candidate->id);

        $own = $generator->create_role();
        assign_capability('tool/vae:draftown', CAP_ALLOW, $own, \context_system::instance()->id, true);
        role_assign($own, $this->candidate->id, $context->id);

        // Following the candidate (viewassigned, held in the candidate's own context through the role assigned there) is
        // what lets draftview open anything: draft_manager::can_read() asks access::can_view() first.
        $view = $generator->create_role();
        assign_capability('tool/vae:draftview', CAP_ALLOW, $view, \context_system::instance()->id, true);
        assign_capability('tool/vae:viewassigned', CAP_ALLOW, $view, \context_system::instance()->id, true);
        role_assign($view, $this->reader->id, $context->id);
        role_assign($view, $this->identityreader->id, $context->id);

        $identity = $generator->create_role();
        assign_capability('tool/vae:identityview', CAP_ALLOW, $identity, \context_system::instance()->id, true);
        role_assign($identity, $this->identityreader->id, $context->id);

        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($this->candidate);
    }

    /**
     * A section of the candidate, as the candidate.
     *
     * @param string $section
     * @return \stdClass
     */
    private function section(string $section): \stdClass {
        return (new draft_manager())->section(
            (int) $this->candidate->id,
            draft_manager::DOCTYPE_FAISABILITE,
            $section
        );
    }

    /**
     * The same values make the same text, whatever the order they arrive in.
     */
    public function test_the_same_values_make_the_same_text(): void {
        $one = faisabilite_fields::encode('identite', [
            'nationalite' => 'FR', 'nom_naissance' => '  Martin ', 'date_naissance' => '1980-05-12', 'niveau_formation' => 4,
        ]);
        $two = faisabilite_fields::encode('identite', [
            'niveau_formation' => '4', 'date_naissance' => '1980-05-12', 'nom_naissance' => 'Martin', 'nationalite' => 'FR',
        ]);

        $this->assertSame($one, $two);
        $this->assertSame(
            '{"nom_naissance":"Martin","date_naissance":"1980-05-12","nationalite":"FR","niveau_formation":"4"}',
            $one
        );
        $this->assertSame('{}', faisabilite_fields::encode('identite', []));
    }

    /**
     * A value the field does not accept is refused, not tidied up.
     *
     * @param string $section
     * @param array $input
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_values')]
    public function test_an_invalid_value_is_refused(string $section, array $input): void {
        $this->expectException(\moodle_exception::class);
        faisabilite_fields::clean($section, $input);
    }

    /**
     * Values no field accepts.
     *
     * @return array[]
     */
    public static function invalid_values(): array {
        return [
            'a date that does not exist' => ['identite', ['date_naissance' => '1980-02-31']],
            'a date in a wrong format' => ['identite', ['date_naissance' => '12/05/1980']],
            'a date in the future' => ['identite', ['date_naissance' => '2999-01-01']],
            'a date before 1900' => ['identite', ['date_naissance' => '1899-12-31']],
            'an unknown country' => ['identite', ['nationalite' => 'ZZ']],
            'a level outside the list' => ['identite', ['niveau_formation' => '1']],
            'a certification level 2' => ['identite', ['niveau_certification' => '2']],
            'a long text too long' => ['identite', ['certifications' => str_repeat('a', 2001)]],
            'a short text on two lines' => ['identite', ['nom_naissance' => "A\nB"]],
            'an unknown key' => ['identite', ['genre' => 'F']],
            'an unknown status' => ['statut', ['statut' => ['roi']]],
            'a status that is not a list' => ['statut', ['statut' => 'benevole']],
            'a number as a name' => ['identite', ['nom_naissance' => 12]],
        ];
    }

    /**
     * The statuses keep the order of the list and accept several.
     */
    public function test_several_statuses_are_kept_in_the_order_of_the_list(): void {
        $values = faisabilite_fields::clean('statut', [
            'statut' => ['retraite', 'benevole'], 'convention' => '1486',
        ]);

        $this->assertSame(['benevole', 'retraite'], $values['statut']);
        $this->assertSame('1486', $values['convention']);
    }

    /**
     * The values read as lines, and a text that cannot be decoded is shown as it is.
     */
    public function test_the_values_read_as_lines(): void {
        $content = faisabilite_fields::encode('statut', ['statut' => ['benevole'], 'convention' => '1486']);

        $lines = explode("\n", faisabilite_fields::readable('statut', $content));
        $this->assertSame(
            get_string('faisabfield:statut', 'tool_vae') . ' : ' . get_string('faisabopt:statut_benevole', 'tool_vae'),
            $lines[0]
        );
        $this->assertSame(get_string('faisabfield:convention', 'tool_vae') . ' : 1486', $lines[1]);

        $this->assertSame('free text', faisabilite_fields::readable('objectifs', 'free text'));
        $this->assertSame('not json', faisabilite_fields::readable('statut', 'not json'));
    }

    /**
     * What the account holds is read, with the empty values left out, and nothing is kept.
     */
    public function test_the_account_values_are_read_and_not_kept(): void {
        global $DB;

        $user = \core_user::get_user((int) $this->candidate->id);
        $user->middlename = 'Anne';

        $identity = faisabilite_fields::account_values('identite', $user);
        $this->assertSame('Camille Anne', $identity['prenoms']);
        $this->assertSame('Durand', $identity['nom_usage']);

        $contact = faisabilite_fields::account_values('contact', $user);
        $this->assertSame('camille@example.com', $contact['email']);
        $this->assertSame('1 rue des Lilas', $contact['address']);
        $this->assertSame('Nantes', $contact['city']);
        $this->assertSame('0240000000', $contact['phone']);
        $this->assertSame(get_string_manager()->get_list_of_countries()['FR'], $contact['country']);

        $this->assertSame(0, $DB->count_records('tool_vae_draft'));
    }

    /**
     * Separate fields are saved whole, once: a second save of the same values creates no version.
     */
    public function test_a_section_of_fields_is_saved_as_a_canonical_version(): void {
        $manager = new draft_manager();
        $draft = $this->section('identite');

        $first = $manager->save_version((int) $draft->id, '{"nationalite":"FR","nom_naissance":"Martin"}');
        $this->assertSame(1, (int) $first->number);
        $this->assertSame('{"nom_naissance":"Martin","nationalite":"FR"}', $first->content, 'Canonical order.');

        $this->assertNull($manager->save_version((int) $draft->id, '{"nom_naissance":"Martin","nationalite":"FR"}'));
    }

    /**
     * A content that is not the section's own JSON is refused, and no draft in progress is kept.
     */
    public function test_a_content_that_is_not_the_sections_json_is_refused(): void {
        $manager = new draft_manager();
        $draft = $this->section('statut');

        foreach (['free text', '{"statut":["roi"]}', '{"genre":"F"}', '[1,2]'] as $content) {
            try {
                $manager->save_version((int) $draft->id, $content);
                $this->fail($content . ' was accepted.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errordraftfields', $e->errorcode);
            }
        }

        $this->expectException(\moodle_exception::class);
        $manager->save_working((int) $draft->id, '{"convention":"1486"}');
    }

    /**
     * The contact details hold nothing here: no row is made for them.
     */
    public function test_the_contact_section_is_never_created(): void {
        global $DB;

        $this->assertSame(faisabilite_catalog::KIND_ACCOUNT, faisabilite_catalog::kind('contact'));

        try {
            $this->section('contact');
            $this->fail('A section read from the account was created.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordraftsection', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('tool_vae_draft'));
    }

    /**
     * Whoever may read the narratives does not thereby read the identity (MC-11).
     */
    public function test_the_identity_needs_its_own_capability(): void {
        $manager = new draft_manager();
        $identity = $this->section('identite');
        $manager->save_version((int) $identity->id, '{"nom_naissance":"Martin"}');
        $objectifs = $this->section('objectifs');
        $manager->save_version((int) $objectifs->id, 'Un objectif.');

        $this->setUser($this->reader);
        $this->assertSame('Un objectif.', $manager->version((int) $objectifs->id, 1)->content);
        try {
            $manager->version((int) $identity->id, 1);
            $this->fail('A reader without identityview read the identity.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errordraftrefused', $e->errorcode);
        }
        $this->assertTrue(draft_manager::can_read((int) $this->candidate->id));
        $this->assertFalse(draft_manager::can_read_fields((int) $this->candidate->id));

        $this->setUser($this->identityreader);
        $this->assertSame('{"nom_naissance":"Martin"}', $manager->version((int) $identity->id, 1)->content);

        $this->setUser($this->candidate);
        $this->assertTrue(draft_manager::can_read_fields((int) $this->candidate->id));

        $this->setAdminUser();
        $this->assertSame('{"nom_naissance":"Martin"}', $manager->version((int) $identity->id, 1)->content);
    }
}
