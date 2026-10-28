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

use tool_vae\event\ref_changed;
use tool_vae\local\competency_gateway;
use tool_vae\local\presenter;
use tool_vae\local\ref_manager;

/**
 * The addresses where a certifier publishes its rule of validation (T-41).
 *
 * WHAT IS ASSERTED. That an administrator gives an address and takes it back; that only an address in http or https
 * is accepted, with a warning when it is a query or a private name; that a title is required and short; that a day to
 * come is refused; that a record carries a limited number of addresses, none twice; that an unknown record is refused;
 * that only someone who may import can write; that the log carries a code of record and nothing else; and that the
 * dossier shows the addresses with the sentence that frames them ONLY once the site has opened them, quoting no rule.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\ref_manager::class)]
final class ref_manager_test extends \advanced_testcase {
    /**
     * Two imported records, as rows, and an administrator.
     */
    protected function setUp(): void {
        parent::setUp();
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $now = time();
        foreach (['RNCP100', 'RNCP200'] as $code) {
            $framework = $generator->create_framework(['idnumber' => $code, 'shortname' => "Fiche $code"]);
            $DB->insert_record('tool_vae_source', (object) [
                'frameworkid' => (int) $framework->get('id'),
                'fichecode' => $code,
                'fichetype' => 'rncp',
                'sourceupdated' => $now - DAYSECS,
                'licence' => 'Licence Ouverte 2.0',
                'attribution' => 'France competences',
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => (int) get_admin()->id,
            ]);
        }
    }

    /**
     * An address is given, listed with its title and its day, and taken back.
     */
    public function test_give_list_and_remove(): void {
        $day = time() - DAYSECS;
        [$row, $warnings] = ref_manager::add('RNCP100', 'Fiche du répertoire', 'https://example.org/rncp/100/', $day);

        $this->assertSame([], $warnings);
        $listed = ref_manager::for_fiches(['RNCP100', 'RNCP200']);
        $this->assertSame(['RNCP100'], array_keys($listed));
        $this->assertSame('Fiche du répertoire', $listed['RNCP100'][0]->title);
        $this->assertSame('https://example.org/rncp/100/', $listed['RNCP100'][0]->url);
        $this->assertSame($day, $listed['RNCP100'][0]->checkedat);

        ref_manager::remove((int) $row->id);
        $this->assertSame([], ref_manager::for_fiches(['RNCP100']));
    }

    /**
     * The day is facultative: nothing is said then, and nothing is made up.
     */
    public function test_the_day_is_facultative(): void {
        ref_manager::add('RNCP100', 'Arrêté', 'https://example.org/arrete');

        $this->assertNull(ref_manager::for_fiches(['RNCP100'])['RNCP100'][0]->checkedat);
    }

    /**
     * Only http and https, with a host and no name or password.
     *
     * @dataProvider bad_addresses
     * @param string $url
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_addresses')]
    public function test_a_bad_address_is_refused(string $url): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorpieceurl', 'tool_vae'));
        ref_manager::add('RNCP100', 'Titre', $url);
    }

    /**
     * Addresses that must not be accepted.
     *
     * @return array
     */
    public static function bad_addresses(): array {
        return [
            'javascript' => ['javascript:alert(1)'],
            'file' => ['file:///etc/passwd'],
            'data' => ['data:text/html,x'],
            'ftp' => ['ftp://example.org/x'],
            'no host' => ['https:///x'],
            'password' => ['https://user:secret@example.org/x'],
            'space' => ['https://example.org/a b'],
            'empty' => [''],
            'too long' => ['https://example.org/' . str_repeat('a', 250)],
        ];
    }

    /**
     * A query, or a private name, is accepted and said.
     */
    public function test_a_query_and_a_private_name_are_warned(): void {
        [, $warnings] = ref_manager::add('RNCP100', 'Partage', 'https://example.org/x?token=1');
        $this->assertSame(['piecewarnquery'], $warnings);

        [, $warnings] = ref_manager::add('RNCP100', 'Interne', 'http://intranet/regle');
        $this->assertSame(['piecewarnprivate'], $warnings);
    }

    /**
     * A title is required, and short, and on one line.
     */
    public function test_the_title_is_required_and_short(): void {
        // The last one hides a right-to-left override (U+202E), a format character: it would turn the title round.
        foreach (['', '   ', str_repeat('a', 61), "deux\nlignes", "Titre \u{202E}piege"] as $title) {
            try {
                ref_manager::add('RNCP100', $title, 'https://example.org/t');
                $this->fail('A title that is refused was accepted.');
            } catch (\moodle_exception $e) {
                $this->assertSame('errorreftitle', $e->errorcode);
            }
        }
        // Sixty characters exactly, accents counted as one.
        ref_manager::add('RNCP100', str_repeat('é', 60), 'https://example.org/t');
        $this->assertCount(1, ref_manager::for_fiches(['RNCP100'])['RNCP100']);
    }

    /**
     * A day to come is not a day of checking.
     */
    public function test_a_day_to_come_is_refused(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorrefdate', 'tool_vae'));
        ref_manager::add('RNCP100', 'Titre', 'https://example.org/d', time() + 10 * DAYSECS);
    }

    /**
     * The same address twice for a record is one too many; for two records it is two addresses.
     */
    public function test_no_address_twice_for_a_record(): void {
        ref_manager::add('RNCP100', 'Une', 'https://example.org/u');
        ref_manager::add('RNCP200', 'Une', 'https://example.org/u');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorrefduplicate', 'tool_vae'));
        ref_manager::add('RNCP100', 'Autre titre', 'https://example.org/u');
    }

    /**
     * A record carries a limited number of addresses.
     */
    public function test_a_record_carries_a_limited_number(): void {
        for ($i = 1; $i <= ref_manager::MAX_PER_FICHE; $i++) {
            ref_manager::add('RNCP100', "Adresse $i", "https://example.org/$i");
        }

        try {
            ref_manager::add('RNCP100', 'De trop', 'https://example.org/trop');
            $this->fail('A sixth address was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorreflimit', $e->errorcode);
        }
        // Another record is not concerned.
        ref_manager::add('RNCP200', 'Autre fiche', 'https://example.org/autre');
        $this->assertCount(1, ref_manager::for_fiches(['RNCP200'])['RNCP200']);
    }

    /**
     * A record that is not imported here has no address to give.
     */
    public function test_an_unknown_record_is_refused(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorrefunknownfiche', 'tool_vae'));
        ref_manager::add('RNCP999', 'Titre', 'https://example.org/x');
    }

    /**
     * An address that does not exist cannot be taken back.
     */
    public function test_an_unknown_address_cannot_be_removed(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorrefunknown', 'tool_vae'));
        ref_manager::remove(12345);
    }

    /**
     * Writing asks for the right to import, whoever calls.
     */
    public function test_writing_asks_for_the_right_to_import(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        try {
            ref_manager::add('RNCP100', 'Titre', 'https://example.org/x');
            $this->fail('A person without the right wrote an address.');
        } catch (\required_capability_exception $e) {
            $this->assertSame([], $this->get_ref_rows());
        }
    }

    /**
     * Taking an address back asks for the right to import too, whoever calls.
     */
    public function test_removing_asks_for_the_right_to_import(): void {
        [$row] = ref_manager::add('RNCP100', 'Titre', 'https://example.org/x');
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            ref_manager::remove((int) $row->id);
            $this->fail('A person without the right removed an address.');
        } catch (\required_capability_exception $e) {
            $this->assertCount(1, $this->get_ref_rows());
        }
    }

    /**
     * The day is the one the administrator picked, whatever the timezone of the reader: stored at noon UTC, shown in UTC.
     */
    public function test_the_day_reads_the_same_in_every_timezone(): void {
        ref_manager::add('RNCP100', 'Titre', 'https://example.org/jour', gmmktime(12, 0, 0, 10, 20, 2026));
        $fiches = ['RNCP100' => (object) ['fichecode' => 'RNCP100', 'source' => null, 'hasblocs' => false, 'competencies' => []]];
        set_config('refopen', 1, 'tool_vae');

        foreach (['Pacific/Auckland', 'Pacific/Tahiti', 'America/Guadeloupe', 'Europe/Paris'] as $timezone) {
            $user = $this->getDataGenerator()->create_user(['timezone' => $timezone]);
            $this->setUser($user);
            $this->assertStringContainsString(
                s(get_string('refcheckedon', 'tool_vae', '20 October 2026')),
                presenter::coverage($fiches),
                "The day read in $timezone."
            );
        }
    }

    /**
     * The rows of the table.
     *
     * @return array
     */
    private function get_ref_rows(): array {
        global $DB;

        return array_values($DB->get_records('tool_vae_ref'));
    }

    /**
     * The log names a record and an action: neither the address nor its title.
     */
    public function test_the_log_names_no_address(): void {
        $sink = $this->redirectEvents();
        [$row] = ref_manager::add('RNCP100', 'Titre secret', 'https://example.org/secret-path');
        ref_manager::remove((int) $row->id);
        $events = array_values(array_filter($sink->get_events(), fn($e) => $e instanceof ref_changed));
        $sink->close();

        $this->assertCount(2, $events);
        $this->assertSame('added', $events[0]->other['action']);
        $this->assertSame('removed', $events[1]->other['action']);
        // A creation and a deletion for the log, not two updates.
        $this->assertSame('c', $events[0]->crud);
        $this->assertSame('d', $events[1]->crud);
        foreach ($events as $event) {
            $this->assertSame('RNCP100', $event->other['fichecode']);
            $this->assertStringNotContainsString('secret', $event->get_description());
            $this->assertStringNotContainsString('secret', json_encode($event->other));
        }
    }

    /**
     * The host is lower-cased, the path is not: two spellings of one host are one address, two spellings of a path are two.
     */
    public function test_the_host_is_lower_cased_and_the_path_is_not(): void {
        [$row] = ref_manager::add('RNCP100', 'Une', 'HTTPS://Example.ORG:8443/Regle/Arrete?A=B#Haut');
        $this->assertSame('https://example.org:8443/Regle/Arrete?A=B#Haut', $row->url);

        try {
            ref_manager::add('RNCP100', 'Deux', 'https://EXAMPLE.org:8443/Regle/Arrete?A=B#Haut');
            $this->fail('The same page under another spelling of its host was accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorrefduplicate', $e->errorcode);
        }
        // Not asserted: two paths that differ only by case. The database compares them (MariaDB ignores the case, PostgreSQL does
        // not), and the CI of 23/10/2026 showed the two answers; the plugin does not claim either.
        $this->assertCount(1, ref_manager::for_fiches(['RNCP100'])['RNCP100']);
    }

    /**
     * One box for the dossier, ahead of the section of the figures: no record's heading and table has the rule between them.
     */
    public function test_one_box_ahead_of_the_coverage_for_all_the_records(): void {
        ref_manager::add('RNCP100', 'Fiche 100', 'https://example.org/100');
        ref_manager::add('RNCP200', 'Fiche 200', 'https://example.org/200');
        set_config('refopen', 1, 'tool_vae');
        $fiches = [];
        foreach (['RNCP100', 'RNCP200'] as $code) {
            $fiches[$code] = (object) ['fichecode' => $code, 'source' => null, 'hasblocs' => false, 'competencies' => []];
        }

        $html = presenter::coverage($fiches);

        $this->assertSame(1, substr_count($html, 'tool-vae-rule-refs'), 'One box.');
        $this->assertSame(1, substr_count($html, s(get_string('refsentence', 'tool_vae'))), 'The sentence once.');
        $this->assertLessThan(strpos($html, 'tool-vae-coverage mb-4'), strpos($html, 'tool-vae-rule-refs'));
        $this->assertStringContainsString('https://example.org/100', $html);
        $this->assertStringContainsString('https://example.org/200', $html);
        // Nothing of the box after the section opened: no address sits among the figures.
        $this->assertStringNotContainsString('example.org', substr($html, strpos($html, 'tool-vae-coverage mb-4')));
    }

    /**
     * Candidates see nothing while the site has not opened the addresses; then the addresses and the sentence, and no rule.
     */
    public function test_the_dossier_shows_the_addresses_only_when_opened(): void {
        ref_manager::add('RNCP100', 'Fiche du répertoire', 'https://example.org/rncp/100/', time() - DAYSECS);
        // Keyed by record code, as coverage::for_candidate() gives them.
        $fiches = ['RNCP100' => (object) [
            'fichecode' => 'RNCP100',
            'source' => null,
            'hasblocs' => false,
            'competencies' => [],
        ]];

        set_config('refopen', 0, 'tool_vae');
        $this->assertStringNotContainsString('example.org', presenter::coverage($fiches));

        set_config('refopen', 1, 'tool_vae');
        $html = presenter::coverage($fiches);
        $this->assertStringContainsString('href="https://example.org/rncp/100/"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString(s(get_string('refsentence', 'tool_vae')), $html);
        // The text of the title is escaped as any text.
        ref_manager::add('RNCP100', '<b>gras</b>', 'https://example.org/2');
        $this->assertStringNotContainsString('<b>gras</b>', presenter::coverage($fiches));
    }
}
