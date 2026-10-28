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

use tool_vae\event\export_fetched;
use tool_vae\event\record_imported;

/**
 * The two events of the import of records from an export of France compétences (0.47.0).
 *
 * WHAT IS ASSERTED. That each event carries what it must and nothing about a person; that it refuses data of the wrong kind
 * at creation, so that nothing that is not a code, a count or a digest is ever stored in the log; that its description
 * names no label, no file name and no path.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\record_imported::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\event\export_fetched::class)]
final class fiche_events_test extends \advanced_testcase {
    /**
     * The data of an event of an import.
     *
     * @param array $override
     * @return array
     */
    private static function imported(array $override = []): array {
        return [
            'context' => \context_system::instance(),
            'objectid' => 7,
            'other' => $override + [
                'fichecode' => 'RNCP99001', 'origin' => 'upload', 'exportdate' => '2026-10-19', 'resourceid' => '',
                'sha256' => str_repeat('ab', 32), 'bytes' => 9704180, 'created' => 4, 'updated' => 0, 'moved' => 0, 'gone' => 0,
                'diverged' => 0, 'adopted' => 0,
            ],
        ];
    }

    /**
     * The data of an event of a fetch.
     *
     * @param array $override
     * @return array
     */
    private static function fetched(array $override = []): array {
        return [
            'context' => \context_system::instance(),
            'other' => $override + [
                'resourceid' => 'c302e089-f5c6-4e52-a685-c877e3a6cdf5', 'title' => 'export-fiches-csv-2026-10-27.zip',
                'bytes' => 9704180, 'sha256' => str_repeat('cd', 32), 'outcome' => 'ok', 'status' => 200,
            ],
        ];
    }

    public function test_an_import_is_recorded_on_the_provenance_row_without_any_person(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $sink = $this->redirectEvents();

        record_imported::create(self::imported())->trigger();

        $events = array_filter($sink->get_events(), fn($event) => $event instanceof record_imported);
        $event = reset($events);
        $this->assertSame('tool_vae_source', $event->objecttable);
        $this->assertSame(7, (int) $event->objectid);
        $this->assertSame('u', $event->crud);
        $this->assertSame(record_imported::LEVEL_OTHER, $event->edulevel);
        $this->assertSame('RNCP99001', $event->other['fichecode']);
        $this->assertFalse(record_imported::get_objectid_mapping());
        $this->assertNotSame('', record_imported::get_name());
        $this->assertStringContainsString('RNCP99001', $event->get_description());
        $this->assertStringNotContainsString('.zip', $event->get_description(), 'No file name in a stored description.');
    }

    public function test_an_import_refuses_data_of_the_wrong_kind(): void {
        $bad = [
            'a code that is not a number' => ['fichecode' => 'RNCP1 OR 1=1'],
            'an origin nobody knows' => ['origin' => 'ftp'],
            'a date that is not one' => ['exportdate' => '27/10/2026'],
            'a digest that is not hexadecimal' => ['sha256' => str_repeat('z', 64)],
            'a short digest' => ['sha256' => 'abcd'],
            'a count that is not an integer' => ['created' => '4'],
            'an adopted flag that is not 0 or 1' => ['adopted' => 2],
            'a resource that is not a uuid' => ['resourceid' => '../../x'],
        ];
        foreach ($bad as $label => $override) {
            try {
                record_imported::create(self::imported($override));
                $this->fail("$label must be refused.");
            } catch (\coding_exception $e) {
                $this->assertInstanceOf(\coding_exception::class, $e, $label);
            }
        }
    }

    public function test_a_fetch_is_recorded_without_object_and_refuses_data_of_the_wrong_kind(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $sink = $this->redirectEvents();

        export_fetched::create(self::fetched())->trigger();
        $refused = ['resourceid' => '', 'title' => '', 'bytes' => 0, 'sha256' => '', 'outcome' => 'fetchhttp', 'status' => 503];
        export_fetched::create(self::fetched($refused))->trigger();

        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof export_fetched));
        $this->assertCount(2, $events);
        $this->assertSame('r', $events[0]->crud);
        $this->assertSame(export_fetched::LEVEL_OTHER, $events[0]->edulevel);
        $this->assertNull($events[0]->objectid);
        $this->assertFalse(export_fetched::get_objectid_mapping());
        $this->assertNotSame('', export_fetched::get_name());
        $this->assertStringNotContainsString('.zip', $events[0]->get_description());

        foreach (
            [
                'a title that is not one' => ['title' => 'passwd'],
                'an outcome nobody knows' => ['outcome' => 'because I said so'],
                'a status that is not an integer' => ['status' => 'ok'],
                'a digest that is not hexadecimal' => ['sha256' => 'not a digest'],
            ] as $label => $override
        ) {
            try {
                export_fetched::create(self::fetched($override));
                $this->fail("$label must be refused.");
            } catch (\coding_exception $e) {
                $this->assertInstanceOf(\coding_exception::class, $e, $label);
            }
        }
    }
}
