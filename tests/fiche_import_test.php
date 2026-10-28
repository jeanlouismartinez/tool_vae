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

use GuzzleHttp\Psr7\Response;
use tool_vae\event\export_fetched;
use tool_vae\event\record_imported;
use tool_vae\fixtures\export_zip_builder;
use tool_vae\local\competency_gateway;
use tool_vae\local\evidence_manager;
use tool_vae\local\fiche_converter;
use tool_vae\local\fiche_import;
use tool_vae\local\fiche_messages;
use tool_vae\local\fiche_plan_store;
use tool_vae\local\fiche_refusal;
use tool_vae\local\importer;

/**
 * Importing records from an export of France compétences, from the numbers to the write (0.47.0).
 *
 * THE EXPORT IS INVENTED and built at run time (export_zip_builder); the network is the simulator of the core. What a
 * preview holds is checked against what the site then holds, and against what the import by the CSV file has always done.
 *
 * WHAT IS ASSERTED. That the numbers are read the way a person types them; that the date of the export is read, never
 * guessed, and written as the date selector of the CSV import writes it; that a preview writes nothing and says what the
 * site holds; that an export older than the version the site holds is not imported without a tick; that the write goes
 * record by record, a refused record does not stop the others, an unexpected failure stops the loop and says which
 * records were not tried; that a second click or a reload imports nothing again; that the rights are asked before any
 * read or request; that two imports at once are refused by name.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\fiche_import::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\importer::class)]
final class fiche_import_test extends \advanced_testcase {
    /** @var string Where the archives of a test are written. */
    private string $directory;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/export_zip_builder.php');
        require_once(__DIR__ . '/fixtures/failing_import_gateway.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->directory = make_request_directory();
    }

    /**
     * An invented archive, named as the real ones are.
     *
     * @param array $options
     * @param string $name
     * @return string The path.
     */
    private function zip(array $options = [], string $name = 'export-fiches-csv-2026-10-19.zip'): string {
        return export_zip_builder::build($this->directory, $name, $options);
    }

    /**
     * A refusal, or null.
     *
     * @param callable $call
     * @return fiche_refusal
     */
    private function refused(callable $call): fiche_refusal {
        try {
            $call();
        } catch (fiche_refusal $refusal) {
            return $refusal;
        }
        $this->fail('The call must be refused.');
    }

    /**
     * A plan from the archive, for these numbers.
     *
     * @param string[] $numbers
     * @param array $options
     * @param string $name
     * @return \stdClass
     */
    private function plan(array $numbers, array $options = [], string $name = 'export-fiches-csv-2026-10-19.zip'): \stdClass {
        $import = new fiche_import();
        $token = $import->from_upload($this->zip($options, $name), $name, $numbers);

        return $import->load($token);
    }

    /**
     * A record of a plan, by its number.
     *
     * @param \stdClass $plan
     * @param string $number
     * @return \stdClass
     */
    private static function record(\stdClass $plan, string $number): \stdClass {
        foreach ($plan->records as $record) {
            if ($record->number === $number) {
                return $record;
            }
        }
        throw new \coding_exception("No record $number in the plan.");
    }

    /**
     * The files called export.zip in the request directories: where the fetch writes the archive it takes.
     *
     * @return string[]
     */
    private static function export_files(): array {
        global $CFG;

        $found = [];
        if (!empty($CFG->localrequestdir) && is_dir($CFG->localrequestdir)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $CFG->localrequestdir,
                \FilesystemIterator::SKIP_DOTS
            ));
            foreach ($iterator as $file) {
                if ($file->getFilename() === 'export.zip') {
                    $found[] = $file->getPathname();
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * What the site holds that an import writes.
     *
     * @return array
     */
    private function holdings(): array {
        global $DB;

        return [
            $DB->count_records('tool_vae_source'),
            $DB->count_records('competency_framework'),
            $DB->count_records('competency'),
            $DB->count_records('tool_vae_obsolete'),
        ];
    }

    /**
     * A declaration against a competency of a record, by a candidate who may declare.
     *
     * @param string $idnumber
     */
    private function declare_against(string $idnumber): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/formslib.php');
        $candidate = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:declare', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $candidate->id, \context_user::instance($candidate->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $competencyid = (int) $DB->get_field('competency', 'id', ['idnumber' => $idnumber], MUST_EXIST);

        $this->setUser($candidate);
        (new evidence_manager())->declare_activity((int) $candidate->id, (object) [
            'competencyid' => $competencyid, 'periodstart' => 1756684800, 'periodend' => 1782172800,
            'settingtype' => 'ehpad', 'settingdetail' => '', 'situation' => 'Accompagnement au lever.',
            'situationformat' => FORMAT_PLAIN,
        ]);
        $this->setAdminUser();
    }

    public function test_the_numbers_are_read_the_way_a_person_types_them(): void {
        $got = fiche_import::parse_numbers("rncp99001, RS5678 ; RNCP99001\nrs99005");
        $this->assertSame(['RNCP99001', 'RS5678', 'RS99005'], $got->numbers);
        $this->assertSame(['RNCP99001'], $got->repeated);

        $this->assertSame(['RNCP40692'], fiche_import::parse_numbers('RNCP 40692')->numbers);
        $this->assertSame(['RNCP40692', 'RNCP41747'], fiche_import::parse_numbers("RNCP40692\r\nRNCP41747")->numbers);

        foreach (['RNCP', '40692', 'RNCP1 OR 1=1', 'RNCP123456789', 'ABC12', 'RNCP12.5', 'RNCP1;DROP'] as $bad) {
            $this->assertSame(
                fiche_refusal::BAD_NUMBER,
                $this->refused(fn() => fiche_import::parse_numbers($bad))->reason(),
                $bad
            );
        }
        $this->assertSame(fiche_refusal::NO_NUMBER, $this->refused(fn() => fiche_import::parse_numbers(" \n "))->reason());
        $this->assertSame(fiche_refusal::NO_NUMBER, $this->refused(fn() => fiche_import::parse_numbers(''))->reason());

        $many = implode(' ', array_map(fn($i) => 'RNCP' . (1000 + $i), range(1, fiche_import::MAX_NUMBERS + 1)));
        $refusal = $this->refused(fn() => fiche_import::parse_numbers($many));
        $this->assertSame(fiche_refusal::TOO_MANY, $refusal->reason());
        $this->assertSame(fiche_import::MAX_NUMBERS, $refusal->params()['max']);
        $this->assertSame(
            fiche_refusal::TOO_MANY,
            $this->refused(fn() => fiche_import::parse_numbers(str_repeat('RNCP1 ', 400)))->reason(),
            'A text of more than 2000 characters.'
        );
        // The token that is refused is named; it is not used to build anything.
        $this->assertSame('OR', $this->refused(fn() => fiche_import::parse_numbers('RNCP1 OR 1=1'))->params()['token']);
    }

    public function test_the_date_of_the_export_is_read_from_the_name_never_guessed(): void {
        $this->assertSame('2026-10-27', fiche_import::date_from_name('export-fiches-csv-2026-10-27.zip'));
        $this->assertSame('2026-10-27', fiche_import::date_from_name('export-fiches-csv-2026-10-27 (1).zip'));
        $this->assertSame('2026-10-27', fiche_import::date_from_name('mon-export-fiches-csv-2026-10-27.zip'));
        foreach (['export.zip', 'export-fiches-csv-2026-13-45.zip', 'export-fiches-csv-2026-02-30.zip', ''] as $none) {
            $this->assertNull(fiche_import::date_from_name($none), $none);
        }

        $this->assertSame(make_timestamp(2026, 10, 27, 0, 0, 0, 99), fiche_import::to_timestamp('2026-10-27'));
        foreach (['Europe/Paris', 'Pacific/Auckland', 'America/Los_Angeles'] as $timezone) {
            $user = $this->getDataGenerator()->create_user(['timezone' => $timezone]);
            $this->setUser($user);
            $this->assertSame(
                '2026-10-27',
                userdate(fiche_import::to_timestamp('2026-10-27'), '%Y-%m-%d', 99),
                "The day is the same for an administrator in $timezone."
            );
        }
    }

    public function test_the_date_of_the_export_is_refused_when_it_is_missing_or_in_the_future(): void {
        $this->mock_clock_with_frozen(gmmktime(10, 0, 0, 10, 27, 2026));
        $import = new fiche_import();

        $path = $this->zip([], 'export.zip');
        $this->assertSame(
            fiche_refusal::NO_DATE,
            $this->refused(fn() => $import->from_upload($path, 'export.zip', ['RNCP99001']))->reason()
        );

        // A date typed wins over the name.
        $token = $import->from_upload($path, 'export.zip', ['RNCP99001'], make_timestamp(2026, 10, 19, 0, 0, 0, 99));
        $this->assertSame('2026-10-19', $import->load($token)->exportdate);
        $token = $import->from_upload(
            $this->zip(),
            'export-fiches-csv-2026-10-19.zip',
            ['RNCP99001'],
            make_timestamp(2026, 10, 25, 0, 0, 0, 99)
        );
        $this->assertSame('2026-10-25', $import->load($token)->exportdate);

        // Tomorrow is accepted (an administrator west of Greenwich just after the publication), the day after is not.
        $tomorrow = $this->zip([], 'export-fiches-csv-2026-10-28.zip');
        $this->assertNotEmpty($import->from_upload($tomorrow, 'export-fiches-csv-2026-10-28.zip', ['RNCP99001']));
        $later = $this->zip([], 'export-fiches-csv-2026-10-29.zip');
        $this->assertSame(
            fiche_refusal::DATE_FUTURE,
            $this->refused(fn() => $import->from_upload($later, 'export-fiches-csv-2026-10-29.zip', ['RNCP99001']))->reason()
        );
    }

    public function test_an_upload_is_read_where_php_put_it(): void {
        $ok = ['name' => 'a.zip', 'tmp_name' => '/tmp/phpXYZ', 'error' => UPLOAD_ERR_OK, 'size' => 10];

        $this->assertSame('/tmp/phpXYZ', fiche_import::upload_path($ok, false));
        $this->assertSame(fiche_refusal::ZIP_MISSING, $this->refused(fn() => fiche_import::upload_path(null))->reason());
        $this->assertSame(
            fiche_refusal::ZIP_MISSING,
            $this->refused(fn() => fiche_import::upload_path(['error' => UPLOAD_ERR_NO_FILE] + $ok))->reason()
        );
        $failures = [
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE,
        ];
        foreach ($failures as $error) {
            $this->assertSame(
                fiche_refusal::UPLOAD,
                $this->refused(fn() => fiche_import::upload_path(['error' => $error] + $ok, false))->reason(),
                "Error $error"
            );
        }
        $this->assertSame(
            fiche_refusal::UPLOAD,
            $this->refused(fn() => fiche_import::upload_path(['error' => [UPLOAD_ERR_OK]] + $ok, false))->reason(),
            'An array of errors is somebody playing.'
        );
        $this->assertSame(
            fiche_refusal::UPLOAD,
            $this->refused(fn() => fiche_import::upload_path($ok, true))->reason(),
            'A path that PHP did not put there is refused.'
        );
    }

    public function test_a_preview_writes_nothing_and_says_what_the_export_holds(): void {
        $path = $this->zip();
        $hash = hash_file('sha256', $path);
        $before = $this->holdings();
        $sink = $this->redirectEvents();
        $import = new fiche_import();

        $token = $import->from_upload(
            $path,
            'export-fiches-csv-2026-10-19.zip',
            ['RNCP99001', 'RNCP99002', 'RNCP99003', 'RNCP99004', 'RS99005', 'RNCP00000', 'RNCP99006']
        );
        $plan = $import->load($token);

        $this->assertSame($before, $this->holdings(), 'Nothing was written: no framework, no competency, no provenance row.');
        $this->assertSame([], $sink->get_events());
        $this->assertSame($hash, hash_file('sha256', $path), 'The archive is not modified.');
        $this->assertSame($hash, $plan->sha256);
        $this->assertSame('upload', $plan->origin);
        $this->assertSame('2026-10-19', $plan->exportdate);
        $this->assertSame(make_timestamp(2026, 10, 19, 0, 0, 0, 99), $plan->exportts);
        $this->assertSame('2026-10-18', $plan->innerdates->blocs);
        $this->assertSame('2026-10-18', $plan->innerdates->standard);
        $this->assertNull($plan->results);

        $one = self::record($plan, 'RNCP99001');
        $this->assertSame('ready', $one->status);
        $this->assertSame(4, $one->blocs);
        $this->assertSame(['BC03'], $one->long);
        $this->assertSame('ACTIVE', $one->active);
        $this->assertSame('new', $one->state);

        $two = self::record($plan, 'RNCP99002');
        $this->assertSame('ready', $two->status);
        $this->assertSame('RNCP99002', $two->name, 'The code stands as the name of a title over a hundred characters.');
        $this->assertStringContainsString("\n", $two->title, 'The whole title is kept in the plan, to be shown.');

        $this->assertSame(fiche_refusal::NO_BLOC_ACTIVE, self::record($plan, 'RNCP99003')->refusal->reason);
        $this->assertSame(fiche_refusal::BAD_BLOC, self::record($plan, 'RNCP99004')->refusal->reason);
        $this->assertSame(fiche_refusal::NO_BLOC_ACTIVE, self::record($plan, 'RS99005')->refusal->reason);
        $this->assertSame(fiche_refusal::NOT_FOUND, self::record($plan, 'RNCP00000')->refusal->reason);
        $this->assertSame('refused', self::record($plan, 'RNCP99003')->status);

        $six = self::record($plan, 'RNCP99006');
        $this->assertSame('ready', $six->status);
        $this->assertSame('INACTIVE', $six->active);
        $this->assertSame(['BC01'], $six->markup);
    }

    public function test_the_preview_says_what_moodle_holds_for_each_record(): void {
        global $DB;

        // A record imported and declared on; another whose framework was deleted; a framework left by an uninstall.
        $import = new fiche_import();
        $names = ['RNCP99001', 'RNCP99006', 'RNCP99002'];
        $token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', $names);
        $import->apply($token);
        $this->declare_against('RNCP99001-BC01');
        $rebuild = (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP99006']);
        $DB->delete_records('competency', ['competencyframeworkid' => $rebuild]);
        $DB->delete_records('competency_framework', ['id' => $rebuild]);
        $DB->delete_records('tool_vae_source', ['fichecode' => 'RNCP99002']);

        $plan = $this->plan(['RNCP99001', 'RNCP99006', 'RNCP99002', 'RNCP99004']);

        $held = self::record($plan, 'RNCP99001');
        $this->assertSame('held', $held->state);
        $this->assertSame(make_timestamp(2026, 10, 19, 0, 0, 0, 99), $held->heldts);
        $this->assertSame(1, $held->declared);
        $this->assertNotNull($held->forecast);
        $this->assertSame(0, $held->forecast->created);
        $this->assertSame(0, $held->forecast->updated);
        $this->assertFalse($held->older);

        $this->assertSame('rebuild', self::record($plan, 'RNCP99006')->state);
        $this->assertSame('adopted', self::record($plan, 'RNCP99002')->state);
    }

    public function test_a_record_in_a_framework_the_plugin_did_not_write_is_refused_by_the_message_of_the_importer(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator(competency_gateway::SUBSYSTEM);
        $framework = $generator->create_framework(['idnumber' => 'RNCP99001', 'shortname' => 'Cadre maison']);
        $generator->create_competency([
            'competencyframeworkid' => $framework->get('id'), 'idnumber' => 'C1.1', 'shortname' => 'Compétence maison',
        ]);

        $plan = $this->plan(['RNCP99001', 'RNCP99006']);

        $foreign = self::record($plan, 'RNCP99001');
        $this->assertSame('refused', $foreign->status);
        $this->assertSame(fiche_refusal::MOODLE, $foreign->refusal->reason);
        $this->assertStringContainsString('RNCP99001', $foreign->refusal->params->html);
        $this->assertSame('ready', self::record($plan, 'RNCP99006')->status, 'The other record stays ready.');
    }

    public function test_an_export_older_than_the_version_moodle_holds_is_flagged_by_day(): void {
        $this->assertTrue(fiche_import::is_older(
            make_timestamp(2026, 11, 15, 0, 0, 0, 99),
            make_timestamp(2026, 10, 19, 0, 0, 0, 99)
        ));
        $this->assertFalse(fiche_import::is_older(
            make_timestamp(2026, 10, 19, 0, 0, 0, 99),
            make_timestamp(2026, 10, 19, 0, 0, 0, 99)
        ), 'The same day is accepted.');
        $this->assertFalse(fiche_import::is_older(
            make_timestamp(2026, 10, 1, 0, 0, 0, 99),
            make_timestamp(2026, 10, 19, 0, 0, 0, 99)
        ));

        // An administrator of another time zone wrote the row on the same day: it is not older by hours.
        foreach (['Pacific/Auckland', 'America/Los_Angeles', 'Europe/Paris'] as $timezone) {
            $this->setUser($this->getDataGenerator()->create_user(['timezone' => $timezone]));
            $this->assertFalse(fiche_import::is_older(
                make_timestamp(2026, 10, 19, 0, 0, 0, 'Europe/Paris'),
                make_timestamp(2026, 10, 19, 0, 0, 0, $timezone)
            ), $timezone);
        }
        $this->setAdminUser();

        // The real case at hand: the six records held at the export of 27 October against the archive of 19 October.
        (new importer())->import((object) [
            'fichecode' => 'RNCP99001', 'fichetype' => 'rncp', 'name' => 'Diplôme inventé', 'sourceurl' => fiche_import::SOURCE_URL,
            'sourceupdated' => make_timestamp(2026, 11, 15, 0, 0, 0, 99), 'licence' => fiche_import::LICENCE,
            'attribution' => fiche_import::ATTRIBUTION,
        ], [(object) ['rang' => 'BC01', 'intitule' => 'Un', 'description' => '']]);
        $plan = $this->plan(['RNCP99001', 'RNCP99006']);
        $this->assertTrue(self::record($plan, 'RNCP99001')->older);
        $this->assertFalse(self::record($plan, 'RNCP99006')->older);

        $counts = fiche_import::counts($plan);
        $this->assertSame(1, $counts->importable);
        $this->assertSame(1, $counts->older);
        $this->assertSame(0, $counts->refused);
        $this->assertFalse(fiche_import::allow_older($plan, false));
        $this->assertTrue(fiche_import::allow_older($plan, true));
    }

    public function test_an_older_export_is_the_only_thing_to_import_then_the_button_allows_it(): void {
        (new importer())->import((object) [
            'fichecode' => 'RNCP99001', 'fichetype' => 'rncp', 'name' => 'Diplôme inventé', 'sourceurl' => fiche_import::SOURCE_URL,
            'sourceupdated' => make_timestamp(2026, 11, 15, 0, 0, 0, 99), 'licence' => fiche_import::LICENCE,
            'attribution' => fiche_import::ATTRIBUTION,
        ], [(object) ['rang' => 'BC01', 'intitule' => 'Un', 'description' => '']]);
        $plan = $this->plan(['RNCP99001', 'RNCP99003']);

        $this->assertSame(0, fiche_import::counts($plan)->importable);
        $this->assertTrue(fiche_import::allow_older($plan, false), 'Only older records are ready: the button itself says it.');
    }

    public function test_the_write_goes_record_by_record_and_a_refused_record_stops_nobody(): void {
        global $DB;

        $sink = $this->redirectEvents();
        $import = new fiche_import();
        $token = $import->from_upload(
            $this->zip(),
            'export-fiches-csv-2026-10-19.zip',
            ['RNCP99001', 'RNCP99002', 'RNCP99003', 'RNCP99006']
        );

        $plan = $import->apply($token);

        $this->assertSame(
            ['RNCP99001' => 'imported', 'RNCP99002' => 'imported', 'RNCP99003' => 'refused', 'RNCP99006' => 'imported'],
            array_column($plan->results, 'status', 'number')
        );
        $this->assertFalse($DB->record_exists('tool_vae_source', ['fichecode' => 'RNCP99003']), 'A refused record leaves no row.');
        foreach (['RNCP99001', 'RNCP99002', 'RNCP99006'] as $code) {
            $source = $DB->get_record('tool_vae_source', ['fichecode' => $code], '*', MUST_EXIST);
            $this->assertSame(fiche_import::LICENCE, $source->licence);
            $this->assertSame(fiche_import::ATTRIBUTION, $source->attribution);
            $this->assertSame(fiche_import::SOURCE_URL, $source->sourceurl);
            $this->assertSame(make_timestamp(2026, 10, 19, 0, 0, 0, 99), (int) $source->sourceupdated);
            $this->assertSame('ok', $source->lastimportstatus);
            $this->assertSame('rncp', $source->fichetype);
        }
        $this->assertSame(
            'RNCP99002',
            (new competency_gateway())->framework_name(
                (int) $DB->get_field('tool_vae_source', 'frameworkid', ['fichecode' => 'RNCP99002'])
            )
        );

        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof record_imported));
        $this->assertCount(3, $events);
        $first = $events[0];
        $this->assertSame((int) $DB->get_field('tool_vae_source', 'id', ['fichecode' => 'RNCP99001']), (int) $first->objectid);
        $this->assertSame('RNCP99001', $first->other['fichecode']);
        $this->assertSame('upload', $first->other['origin']);
        $this->assertSame('2026-10-19', $first->other['exportdate']);
        $this->assertSame($plan->sha256, $first->other['sha256']);
        $this->assertSame(4, $first->other['created']);
    }

    public function test_a_second_apply_of_the_same_plan_imports_nothing_again(): void {
        $sink = $this->redirectEvents();
        $import = new fiche_import();
        $token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001', 'RNCP99006']);
        $first = $import->apply($token);
        $held = $this->holdings();
        $eventcount = count($sink->get_events());

        $second = $import->apply($token);

        $this->assertEquals($first->results, $second->results, 'The results are read back, not redone.');
        $this->assertSame($held, $this->holdings());
        $this->assertCount($eventcount, $sink->get_events());
    }

    public function test_a_new_preview_of_the_same_zip_then_apply_changes_nothing(): void {
        $sink = $this->redirectEvents();
        $import = new fiche_import();
        $import->apply($import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001', 'RNCP99006']));
        $held = $this->holdings();
        $sink->clear();

        $token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001', 'RNCP99006']);
        $import->apply($token);

        $this->assertSame($held, $this->holdings());
        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof record_imported));
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertSame(0, $event->other['created']);
            $this->assertSame(0, $event->other['updated']);
            $this->assertSame(0, $event->other['diverged']);
        }
    }

    public function test_the_same_content_by_the_csv_and_by_the_zip_writes_the_same_date(): void {
        global $DB;

        // What the CSV screen does: the converter's rows through the CSV reader, the date of the date selector.
        $blocs = export_zip_builder::blocs(export_zip_builder::records());
        $standard = export_zip_builder::standard(export_zip_builder::records());
        $converted = fiche_converter::convert_many($blocs, $standard, ['RNCP99001'])['RNCP99001'];
        $rows = importer::rows_from_csv(fiche_converter::to_csv($converted->rows));
        (new importer())->import((object) [
            'fichecode' => 'RNCP99001', 'fichetype' => 'rncp', 'name' => $converted->meta->name,
            'sourceurl' => fiche_import::SOURCE_URL, 'sourceupdated' => make_timestamp(2026, 10, 19, 0, 0, 0, 99),
            'licence' => fiche_import::LICENCE, 'attribution' => fiche_import::ATTRIBUTION,
        ], $rows);

        $import = new fiche_import();
        $plan = $this->plan(['RNCP99001']);
        $this->assertSame(0, self::record($plan, 'RNCP99001')->forecast->updated);
        $import->apply($import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']));
        $this->assertSame(
            make_timestamp(2026, 10, 19, 0, 0, 0, 99),
            (int) $DB->get_field('tool_vae_source', 'sourceupdated', ['fichecode' => 'RNCP99001'])
        );

        // The source did not move, so a label changed on this site is a divergence: proof that both paths write the same integer.
        $DB->set_field('competency', 'shortname', 'Changé ici', ['idnumber' => 'RNCP99001-BC01']);
        $sink = $this->redirectEvents();
        $import->apply($import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']));
        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof record_imported));
        $this->assertSame(1, $events[0]->other['updated']);
        $this->assertSame(1, $events[0]->other['diverged']);
    }

    public function test_a_label_with_a_less_than_sign_is_kept_byte_for_byte_and_stable(): void {
        global $DB;

        $import = new fiche_import();
        $import->apply($import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99006']));

        $this->assertSame(
            'Installer un mât (<10m) dans une gaine',
            $DB->get_field('competency', 'description', ['idnumber' => 'RNCP99006-BC01'])
        );
        $this->assertSame('Bloc BC01', $DB->get_field('competency', 'shortname', ['idnumber' => 'RNCP99006-BC01']));

        $sink = $this->redirectEvents();
        $import->apply($import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99006']));
        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof record_imported));
        $this->assertSame(0, $events[0]->other['updated'], 'Read back as written: nothing to update the second time.');
    }

    public function test_an_older_record_is_not_imported_without_the_tick_and_goes_back_with_it(): void {
        global $DB;

        $held = make_timestamp(2026, 11, 15, 0, 0, 0, 99);
        (new importer())->import((object) [
            'fichecode' => 'RNCP99001', 'fichetype' => 'rncp', 'name' => 'Diplôme inventé', 'sourceurl' => fiche_import::SOURCE_URL,
            'sourceupdated' => $held, 'licence' => fiche_import::LICENCE, 'attribution' => fiche_import::ATTRIBUTION,
        ], [(object) ['rang' => 'BC01', 'intitule' => 'Un', 'description' => '']]);
        $import = new fiche_import();

        $plan = $import->apply($import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001', 'RNCP99006']));
        $this->assertSame(['RNCP99001' => 'older', 'RNCP99006' => 'imported'], array_column($plan->results, 'status', 'number'));
        $this->assertSame($held, (int) $DB->get_field('tool_vae_source', 'sourceupdated', ['fichecode' => 'RNCP99001']));

        $plan = $import->apply($import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']), true);
        $this->assertSame('imported', $plan->results[0]->status);
        $this->assertSame(
            make_timestamp(2026, 10, 19, 0, 0, 0, 99),
            (int) $DB->get_field('tool_vae_source', 'sourceupdated', ['fichecode' => 'RNCP99001']),
            'The date goes back to that of the export.'
        );
    }

    public function test_the_comparison_is_made_again_at_the_write(): void {
        global $DB;

        $import = new fiche_import();
        $token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']);
        $this->assertFalse(self::record($import->load($token), 'RNCP99001')->older);

        // Between the preview and the confirmation somebody imported a more recent version.
        (new importer())->import((object) [
            'fichecode' => 'RNCP99001', 'fichetype' => 'rncp', 'name' => 'Diplôme inventé', 'sourceurl' => fiche_import::SOURCE_URL,
            'sourceupdated' => make_timestamp(2026, 11, 15, 0, 0, 0, 99), 'licence' => fiche_import::LICENCE,
            'attribution' => fiche_import::ATTRIBUTION,
        ], [(object) ['rang' => 'BC01', 'intitule' => 'Un', 'description' => '']]);

        $plan = $import->apply($token);

        $this->assertSame('older', $plan->results[0]->status);
        $this->assertSame(
            make_timestamp(2026, 11, 15, 0, 0, 0, 99),
            (int) $DB->get_field('tool_vae_source', 'sourceupdated', ['fichecode' => 'RNCP99001'])
        );
    }

    public function test_an_unexpected_failure_stops_the_loop_and_the_next_records_are_not_tried(): void {
        global $DB;

        $this->preventResetByRollback();

        $gateway = new class extends competency_gateway {
            #[\Override]
            public function create_competency(
                int $frameworkid,
                string $idnumber,
                string $shortname,
                string $description = '',
                int $parentid = 0
            ): int {
                throw new \RuntimeException('<b>Boom</b> ' . $idnumber);
            }
        };
        $import = new fiche_import(new importer($gateway));
        $token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001', 'RNCP99006']);

        $plan = $import->apply($token);
        // The detail goes to the debugging messages of the site, and never to the screen.
        $debugging = $this->getDebuggingMessages();
        $this->resetDebugging();
        $this->assertCount(1, $debugging);
        $this->assertStringContainsString('Boom', $debugging[0]->message);

        $this->assertSame(['RNCP99001' => 'failed', 'RNCP99006' => 'notrun'], array_column($plan->results, 'status', 'number'));
        $this->assertSame(
            fiche_messages::html(new fiche_refusal(fiche_refusal::UNEXPECTED)),
            $plan->results[0]->html,
            'An unexpected failure is said in the words of the plugin.'
        );
        $this->assertStringNotContainsString('Boom', $plan->results[0]->html, 'Its own text is not shown.');
        $this->assertStringNotContainsString('Stack trace', $plan->results[0]->html);
        $this->assertSame('error', $DB->get_field('tool_vae_source', 'lastimportstatus', ['fichecode' => 'RNCP99001']));
        $this->assertFalse($DB->record_exists('tool_vae_source', ['fichecode' => 'RNCP99006']), 'The next record was not tried.');
        // The framework made for the first record went with its transaction: only the line of provenance stays, as a trace.
        $this->assertSame(0, $DB->count_records('competency_framework'), 'Nothing half written.');
    }

    public function test_a_refusal_of_the_core_fails_that_record_and_the_next_is_tried(): void {
        $import = new fiche_import(new importer(new fixtures\failing_import_gateway(0)));
        $token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001', 'RNCP99006']);

        $plan = $import->apply($token);

        $this->assertSame(['RNCP99001' => 'failed', 'RNCP99006' => 'failed'], array_column($plan->results, 'status', 'number'));
    }

    public function test_the_rights_are_asked_before_any_read_or_request(): void {
        $history = [];
        $client = $this->get_mocked_http_client($history);
        $path = $this->zip();

        // Nobody may import without the right.
        $this->setUser($this->getDataGenerator()->create_user());
        $import = new fiche_import();
        try {
            $import->from_upload($path, 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']);
            $this->fail('A user without the right read an archive.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        try {
            $import->from_data_gouv(['RNCP99001']);
            $this->fail('A user without the right made a request.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->assertSame([], $history, 'No request went out.');
        $this->assertSame(0, $client['mock']->count());

        // The right to import, but not to manage the competency frameworks.
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/vae:import', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        $user = $this->getDataGenerator()->create_user();
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);
        try {
            $import->from_data_gouv(['RNCP99001']);
            $this->fail('A user who cannot manage the frameworks made a request.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportnocompetencymanage', $e->errorcode);
        }
        try {
            $import->apply(str_repeat('0', 32));
            $this->fail('A user who cannot manage the frameworks wrote.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorimportnocompetencymanage', $e->errorcode);
        }
        $this->assertSame([], $history);
    }

    public function test_two_imports_at_once_are_refused_by_name(): void {
        global $CFG;

        // The advisory locks of PostgreSQL belong to the connection, and are taken again by the same one: two requests are two
        // connections, but a test is one. The lock by records of the database is exclusive within a process too.
        $CFG->lock_factory = '\core\lock\db_record_lock_factory';
        $history = [];
        $client = $this->get_mocked_http_client($history);
        $factory = \core\lock\lock_config::get_lock_factory('tool_vae_fiche_import');
        $import = new fiche_import();

        $lock = $factory->get_lock('prepare', 0, 600);
        $this->assertNotFalse($lock);
        try {
            $refusal = $this->refused(fn() => $import->from_data_gouv(['RNCP99001']));
            $this->assertSame(fiche_refusal::BUSY, $refusal->reason());
            $this->assertSame([], $history, 'No request went out while another preparation was running.');
        } finally {
            $lock->release();
        }

        $token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']);
        $held = $this->holdings();
        $lock = $factory->get_lock('apply', 0, 600);
        $this->assertNotFalse($lock);
        try {
            $this->assertSame(fiche_refusal::BUSY, $this->refused(fn() => $import->apply($token))->reason());
            $this->assertSame($held, $this->holdings(), 'Nothing was written.');
        } finally {
            $lock->release();
        }
        $this->assertSame('imported', $import->apply($token)->results[0]->status, 'Once the lock is free it goes through.');
    }

    public function test_a_plan_is_the_one_of_its_user(): void {
        $import = new fiche_import();
        $token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']);

        $this->assertNotNull($import->load($token));
        $this->assertNull($import->load('not a token'));
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertNull($import->load($token), 'A token that leaks is of no use to another account.');

        $this->setAdminUser();
        $import->discard($token);
        $this->assertNull($import->load($token));
        $this->assertSame(fiche_refusal::EXPIRED, $this->refused(fn() => $import->apply($token))->reason());
    }

    public function test_the_fetch_goes_from_the_dataset_to_a_plan_like_the_upload(): void {
        global $CFG;

        $history = [];
        $client = $this->get_mocked_http_client($history);
        $this->mock_clock_with_frozen(gmmktime(10, 0, 0, 10, 27, 2026));
        $zip = $this->zip([], 'export-fiches-csv-2026-10-27.zip');
        $bytes = file_get_contents($zip);
        $id = 'c302e089-f5c6-4e52-a685-c877e3a6cdf5';
        $static = 'https://static.data.gouv.fr/resources/repertoire-national-des-certifications-professionnelles-et-repertoire-'
            . 'specifique/20261027-020002/export-fiches-csv-2026-10-27.zip';
        $row = [
            'id' => $id, 'title' => 'export-fiches-csv-2026-10-27.zip', 'filetype' => 'file', 'format' => 'zip', 'url' => $static,
            'checksum' => ['type' => 'sha1', 'value' => sha1($bytes)], 'filesize' => strlen($bytes),
            'created_at' => '2026-10-27T02:00:03.908000+00:00', 'last_modified' => '2026-10-27T02:00:08.465000+00:00',
        ];
        $client['mock']->append(
            new Response(200, [], json_encode(['id' => 'x', 'license' => 'lov2', 'private' => false, 'archived' => null])),
            new Response(200, [], json_encode(['data' => [$row], 'page' => 1, 'page_size' => 100, 'total' => 1])),
            new Response(302, ['Location' => $static]),
            new Response(200, ['Content-Length' => (string) strlen($bytes)], $bytes)
        );
        $before = $this->holdings();
        $leftovers = self::export_files();
        $sink = $this->redirectEvents();

        $import = new fiche_import();
        $plan = $import->load($import->from_data_gouv(['RNCP99001', 'RNCP99003']));

        $this->assertSame('fetch', $plan->origin);
        $this->assertSame('2026-10-27', $plan->exportdate);
        $this->assertSame($id, $plan->resource->id);
        $this->assertSame('export-fiches-csv-2026-10-27.zip', $plan->resource->title);
        $this->assertSame(sha1($bytes), $plan->resource->sha1);
        $this->assertSame('ready', self::record($plan, 'RNCP99001')->status);
        $this->assertSame('refused', self::record($plan, 'RNCP99003')->status);
        $this->assertSame($before, $this->holdings());

        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof export_fetched));
        $this->assertCount(1, $events);
        $this->assertSame('ok', $events[0]->other['outcome']);
        $this->assertSame($id, $events[0]->other['resourceid']);
        $this->assertSame(strlen($bytes), $events[0]->other['bytes']);
        $this->assertSame(hash('sha256', $bytes), $events[0]->other['sha256']);

        // The archive is not kept: the request directories hold no file of that name that they did not hold before.
        $this->assertSame([], array_values(array_diff(self::export_files(), $leftovers)));
    }

    public function test_a_fetch_that_fails_is_traced_and_writes_nothing(): void {
        $history = [];
        $client = $this->get_mocked_http_client($history);
        $this->mock_clock_with_frozen(gmmktime(10, 0, 0, 10, 27, 2026));
        $before = $this->holdings();
        $sink = $this->redirectEvents();
        $import = new fiche_import();

        $client['mock']->append(new Response(503, [], 'maintenance'));
        $refusal = $this->refused(fn() => $import->from_data_gouv(['RNCP99001']));

        $this->assertSame(fiche_refusal::FETCH_HTTP, $refusal->reason());
        $events = array_values(array_filter($sink->get_events(), fn($event) => $event instanceof export_fetched));
        $this->assertCount(1, $events, 'A refused fetch is traced too.');
        $this->assertSame(fiche_refusal::FETCH_HTTP, $events[0]->other['outcome']);
        $this->assertSame(503, $events[0]->other['status']);
        $this->assertSame($before, $this->holdings());
    }

    public function test_the_provenance_is_written_by_the_plugin_within_the_columns_of_the_table(): void {
        $this->assertLessThanOrEqual(255, strlen(fiche_import::SOURCE_URL));
        $this->assertLessThanOrEqual(64, strlen(fiche_import::LICENCE));
        $this->assertLessThanOrEqual(255, strlen(fiche_import::ATTRIBUTION));
        $this->assertSame('France compétences', fiche_import::ATTRIBUTION, 'With its accent.');
        $this->assertSame('Licence Ouverte 2.0', fiche_import::LICENCE);

        // One definition: the form of the CSV import reads the constants, it does not repeat them.
        $form = file_get_contents(__DIR__ . '/../classes/form/import_form.php');
        $this->assertStringContainsString('fiche_import::LICENCE', $form);
        $this->assertStringContainsString('fiche_import::ATTRIBUTION', $form);
        $this->assertStringNotContainsString("'Licence Ouverte 2.0'", $form);
    }

    public function test_days_below_ten_are_compared_as_days(): void {
        // The function userdate() takes the zero off a day of one digit unless told not to: 3 November then read 2026113,
        // a number smaller than the twenty-seventh of October (20261027), and an older export looked newer.
        $third = make_timestamp(2026, 11, 3, 0, 0, 0, 99);
        $twentyseventh = make_timestamp(2026, 10, 27, 0, 0, 0, 99);
        $this->assertTrue(fiche_import::is_older($third, $twentyseventh), 'The export of 27 October is older than 3 November.');
        $this->assertFalse(fiche_import::is_older($twentyseventh, $third), 'And not the other way round.');
        $this->assertFalse(fiche_import::is_older($third, $third));
        $eighth = make_timestamp(2026, 10, 8, 0, 0, 0, 99);
        $ninth = make_timestamp(2026, 10, 9, 0, 0, 0, 99);
        $this->assertTrue(fiche_import::is_older($ninth, $eighth));
        $this->assertFalse(fiche_import::is_older($eighth, $ninth));

        // The case at hand: the site holds the version of 3 November, the archive is the one of 27 October.
        (new importer())->import((object) [
            'fichecode' => 'RNCP99001', 'fichetype' => 'rncp', 'name' => 'Diplôme inventé', 'sourceurl' => fiche_import::SOURCE_URL,
            'sourceupdated' => $third, 'licence' => fiche_import::LICENCE, 'attribution' => fiche_import::ATTRIBUTION,
        ], [(object) ['rang' => 'BC01', 'intitule' => 'Un', 'description' => '']]);
        $import = new fiche_import();
        $token = $import->from_upload(
            $this->zip([], 'export-fiches-csv-2026-10-27.zip'),
            'export-fiches-csv-2026-10-27.zip',
            ['RNCP99001']
        );
        $this->assertTrue(self::record($import->load($token), 'RNCP99001')->older);
        $this->assertSame('older', $import->apply($token)->results[0]->status, 'Not written without the tick.');
    }

    public function test_a_day_typed_with_a_single_digit_is_read_as_that_day(): void {
        $this->mock_clock_with_frozen(gmmktime(10, 0, 0, 11, 4, 2026));
        $import = new fiche_import();
        $typed = make_timestamp(2026, 11, 5, 0, 0, 0, 99);

        $token = $import->from_upload($this->zip([], 'export.zip'), 'export.zip', ['RNCP99001'], $typed);

        $plan = $import->load($token);
        $this->assertSame('2026-11-05', $plan->exportdate, 'The zero of the day is kept.');
        $this->assertSame($typed, $plan->exportts);
        $this->assertSame('imported', $import->apply($token)->results[0]->status);
    }

    public function test_what_is_not_an_export_is_told_before_its_name(): void {
        $garbage = $this->directory . '/garbage.zip';
        file_put_contents($garbage, str_repeat('this is not a zip archive. ', 4));

        $refusal = $this->refused(fn() => (new fiche_import())->from_upload($garbage, 'export.zip', ['RNCP99001']));

        $this->assertSame(fiche_refusal::NOT_ZIP, $refusal->reason(), 'A file that is not a zip is not blamed for its name.');
    }

    /**
     * Failures that say nothing a reader can use, and that would fail every record after them in the same way.
     *
     * @return array
     */
    public static function failures_that_stop(): array {
        return [
            'the database' => [\dml_write_exception::class],
            'a coding error' => [\coding_exception::class],
            'a runtime error' => [\RuntimeException::class],
            'a type error' => [\TypeError::class],
        ];
    }

    /**
     * The database or the code failing stops the loop, is never shown, and leaves a refusal a refusal.
     *
     * @param string $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures_that_stop')]
    public function test_a_failure_of_the_database_or_the_code_stops_the_loop_and_is_not_shown(string $class): void {
        $gateway = new class extends competency_gateway {
            /** @var string The class of the exception thrown. */
            public static string $class = '';

            #[\Override]
            public function create_competency(
                int $frameworkid,
                string $idnumber,
                string $shortname,
                string $description = '',
                int $parentid = 0
            ): int {
                $class = self::$class;
                throw new $class('Secret ' . $idnumber);
            }
        };
        $gateway::$class = $class;
        $import = new fiche_import(new importer($gateway));
        $token = $import->from_upload(
            $this->zip(),
            'export-fiches-csv-2026-10-19.zip',
            ['RNCP99001', 'RNCP99999', 'RNCP99006']
        );

        $plan = $import->apply($token);

        $this->assertDebuggingCalled(null, DEBUG_NORMAL);
        $this->assertSame(
            ['RNCP99001' => 'failed', 'RNCP99999' => 'refused', 'RNCP99006' => 'notrun'],
            array_column($plan->results, 'status', 'number'),
            'The loop stopped, and the record that was refused stays refused.'
        );
        $generic = fiche_messages::html(new fiche_refusal(fiche_refusal::UNEXPECTED));
        $this->assertSame($generic, $plan->results[0]->html);
        foreach ($plan->results as $result) {
            $this->assertStringNotContainsString('Secret', $result->html);
        }
    }

    public function test_a_failure_of_the_trace_does_not_hide_a_record_that_was_written(): void {
        global $DB;

        // The core swallows what an observer throws; what can fail is the creation of the event itself, here for want of the
        // row of provenance it must point to.
        $importer = new class extends importer {
            #[\Override]
            public function import(\stdClass $meta, array $rows): \stdClass {
                $done = parent::import($meta, $rows);
                $done->sourceid = 0;

                return $done;
            }
        };
        $import = new fiche_import($importer);
        $token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001', 'RNCP99006']);

        $plan = $import->apply($token);

        $debugging = $this->getDebuggingMessages();
        $this->resetDebugging();
        $this->assertCount(2, $debugging, 'Each trace that failed is said, in the debugging messages only.');
        $this->assertStringContainsString('RNCP99001', $debugging[0]->message);
        $this->assertSame(['RNCP99001' => 'imported', 'RNCP99006' => 'imported'], array_column($plan->results, 'status', 'number'));
        $this->assertTrue($DB->record_exists('tool_vae_source', ['fichecode' => 'RNCP99001']), 'The record is written.');
        $this->assertTrue($DB->record_exists('tool_vae_source', ['fichecode' => 'RNCP99006']), 'And the next one is not skipped.');
        $this->assertMatchesRegularExpression('/^Imported: \d+ competency\(ies\) added/', $plan->results[0]->html);
    }

    /**
     * What a plan must not carry for it to be written.
     *
     * @return array
     */
    public static function altered_plans(): array {
        $record = static fn(string $field, $value) => static function (\stdClass $plan) use ($field, $value): void {
            $plan->records[0]->$field = $value;
        };
        $plan = static fn(string $field, $value) => static function (\stdClass $plan) use ($field, $value): void {
            $plan->$field = $value;
        };

        return [
            'another origin' => [$plan('origin', 'somewhere')],
            'a day that does not exist' => [$plan('exportdate', '2026-13-45')],
            'a day with a path in it' => [$plan('exportdate', '../../x')],
            'a time that is not that day' => [$plan('exportts', 0)],
            'a digest that is not one' => [$plan('sha256', 'zz')],
            'a size that is not a number' => [$plan('bytes', 'many')],
            'records that are not a list' => [$plan('records', 'x')],
            'a number that is not one' => [$record('number', 'RNCP99001; DROP')],
            'a type that is not the number\'s' => [$record('type', 'rs')],
            'a status that is not one' => [$record('status', 'maybe')],
            'rows that are not rows' => [$record('rows', [1, 2])],
            'no row at all' => [$record('rows', [])],
            'an identifier that is not one' => [static function (\stdClass $plan): void {
                $plan->resource = (object) ['id' => '../x', 'title' => 't', 'sha1' => 'x'];
            }],
        ];
    }

    /**
     * The plan is a file: what it says is checked again before it is written to the site.
     *
     * @param callable $alter
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('altered_plans')]
    public function test_a_plan_that_was_altered_is_not_written(callable $alter): void {
        global $USER;

        $import = new fiche_import();
        $token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']);
        $plan = $import->load($token);
        $alter($plan);
        fiche_plan_store::save((int) $USER->id, $token, $plan);
        $before = $this->holdings();

        $refusal = $this->refused(fn() => $import->apply($token));

        $this->assertSame(fiche_refusal::UNEXPECTED, $refusal->reason());
        $this->assertSame($before, $this->holdings(), 'Nothing was written.');
    }

    public function test_the_results_are_kept_when_the_plan_no_longer_fits(): void {
        global $DB, $USER;

        $import = new fiche_import();
        $token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']);
        $plan = $import->load($token);
        // A plan that fits by a handful of bytes: the results, once added, no longer do.
        $plan->records[0]->rows[0]->description = '';
        fiche_plan_store::save((int) $USER->id, $token, $plan);
        $size = filesize(fiche_plan_store::path((int) $USER->id, $token));
        $plan->records[0]->rows[0]->description = str_repeat('a', fiche_plan_store::MAX_BYTES - $size - 60);
        fiche_plan_store::save((int) $USER->id, $token, $plan);
        $this->assertLessThanOrEqual(fiche_plan_store::MAX_BYTES, filesize(fiche_plan_store::path((int) $USER->id, $token)));

        $applied = $import->apply($token);

        // The results so far did not fit either (the checkpoint), then the whole plan with its results.
        $this->assertDebuggingCalledCount(2);
        $this->assertTrue($applied->resultkept, 'Kept, without the blocs.');
        $this->assertSame('imported', $applied->results[0]->status);
        $this->assertTrue($DB->record_exists('tool_vae_source', ['fichecode' => 'RNCP99001']));
        $again = $import->load($token);
        $this->assertNotNull($again, 'The results can be read again.');
        $this->assertSame('imported', $again->results[0]->status);
        $this->assertSame([], $again->records[0]->rows, 'The blocs, which are the bulk of it, were let go.');
    }

    public function test_the_numbers_taken_back_are_those_of_records_and_at_most_twenty(): void {
        $importer = new importer();
        $rows = [(object) ['rang' => 'BC01', 'intitule' => 'Un', 'description' => '']];
        $meta = fn(string $code) => (object) [
            'fichecode' => $code, 'fichetype' => 'rncp', 'name' => "Diplôme $code", 'sourceurl' => fiche_import::SOURCE_URL,
            'sourceupdated' => make_timestamp(2026, 10, 19, 0, 0, 0, 99), 'licence' => fiche_import::LICENCE,
            'attribution' => fiche_import::ATTRIBUTION,
        ];

        $importer->import($meta('MON-CODE'), $rows);
        $importer->import($meta('RNCP99001'), $rows);
        $this->assertSame(['RNCP99001'], fiche_import::known_numbers(), 'A code that is not a number of a record is left out.');

        for ($i = 0; $i < 22; $i++) {
            $importer->import($meta(sprintf('RNCP98%03d', $i)), $rows);
        }
        $this->assertCount(fiche_import::MAX_NUMBERS, fiche_import::known_numbers(), 'More would be refused by the form.');
        $this->assertSame(23, fiche_import::known_total(), 'The link says how many there are in all.');
        foreach (fiche_import::known_numbers() as $number) {
            $this->assertSame(1, preg_match(fiche_import::NUMBER_PATTERN, $number));
        }
    }

    public function test_what_an_exception_says_is_safe_and_the_rest_is_not_shown(): void {
        // A sentence of the plugin was made safe where it was raised: it is shown as it is.
        $own = new \moodle_exception('errorcompetenciesdisabled', 'tool_vae');
        $this->assertSame($own->getMessage(), fiche_import::exception_html($own));

        // That of another module is escaped here.
        $other = new \invalid_parameter_exception('<b>x</b>');
        $html = fiche_import::exception_html($other);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('&lt;b&gt;', $html);

        // What is not meant for a reader - the database, the code, anything else - is not shown at all.
        $generic = fiche_messages::html(new fiche_refusal(fiche_refusal::UNEXPECTED));
        foreach ([new \dml_write_exception('Secret'), new \coding_exception('Secret'), new \TypeError('Secret')] as $e) {
            $this->assertSame($generic, fiche_import::exception_html($e), get_class($e));
            $this->assertDebuggingCalled(null, DEBUG_NORMAL);
        }
    }

    public function test_a_record_with_no_bloc_is_said_in_force_or_not(): void {
        $plan = $this->plan(['RNCP99003', 'RNCP99007']);

        $this->assertSame(fiche_refusal::NO_BLOC_ACTIVE, self::record($plan, 'RNCP99003')->refusal->reason);
        $this->assertSame(fiche_refusal::NO_BLOC, self::record($plan, 'RNCP99007')->refusal->reason);
    }

    public function test_a_first_attempt_that_failed_is_not_shown_as_an_import(): void {
        global $DB;

        // The importer rolls its transaction back for real: the test must not be inside the one that resets the database.
        $this->preventResetByRollback();

        $failing = new fiche_import(new importer(new fixtures\failing_import_gateway(0)));
        $token = $failing->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']);
        $this->assertSame('failed', $failing->apply($token)->results[0]->status);
        $this->assertTrue($DB->record_exists('tool_vae_source', ['fichecode' => 'RNCP99001', 'sourceupdated' => 0]));
        $this->assertSame([], fiche_import::known_numbers(), 'Nothing was imported: nothing is offered as already imported.');

        $record = self::record($this->plan(['RNCP99001']), 'RNCP99001');

        $this->assertSame('new', $record->state, 'Not "imported before, its framework is gone".');
        $this->assertNull($record->heldts, 'Not the first of January 1970.');
        $this->assertFalse($record->older);
    }

    public function test_a_first_attempt_that_failed_on_a_framework_taken_over_is_still_a_framework_taken_over(): void {
        $this->preventResetByRollback();
        // The framework is made by hand, empty, with the identifier of the record.
        (new competency_gateway())->create_framework('RNCP99001', 'Fait main');
        $failing = new fiche_import(new importer(new fixtures\failing_import_gateway(0)));
        $token = $failing->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']);
        $this->assertSame('failed', $failing->apply($token)->results[0]->status);

        $record = self::record($this->plan(['RNCP99001']), 'RNCP99001');

        $this->assertSame('adopted', $record->state, 'Not "already imported on the first of January 1970".');
        $this->assertNull($record->heldts);
        $this->assertFalse($record->older);
    }

    public function test_what_has_been_done_is_kept_as_the_loop_goes(): void {
        global $USER;

        // The script may be killed in the middle of the loop: what a reload finds is what was written, and the rest "not run" -
        // not an old preview that offers to import what is already there. The second import looks at the plan as it is kept then.
        $importer = new class extends importer {
            /** @var \stdClass|null The plan as it was kept when the second record was about to be written. */
            public ?\stdClass $seen = null;

            /** @var string The token of the plan. */
            public string $token = '';

            /** @var int The number of the records written. */
            private int $count = 0;

            #[\Override]
            public function import(\stdClass $meta, array $rows): \stdClass {
                global $USER;

                if (++$this->count === 2) {
                    $this->seen = fiche_plan_store::load((int) $USER->id, $this->token);
                }

                return parent::import($meta, $rows);
            }
        };
        $import = new fiche_import($importer);
        $importer->token = $import->from_upload(
            $this->zip(),
            'export-fiches-csv-2026-10-19.zip',
            ['RNCP99001', 'RNCP99003', 'RNCP99006']
        );

        $import->apply($importer->token);

        $seen = $importer->seen;
        $this->assertNotNull($seen);
        $this->assertSame(
            ['RNCP99001' => 'imported', 'RNCP99003' => 'refused', 'RNCP99006' => 'notrun'],
            array_column($seen->results, 'status', 'number'),
            'Killed there, a reload would say what was written, and what was not run.'
        );
        $final = $import->load($importer->token);
        $this->assertSame(
            ['RNCP99001' => 'imported', 'RNCP99003' => 'refused', 'RNCP99006' => 'imported'],
            array_column($final->results, 'status', 'number')
        );
        $this->assertSame((int) $USER->id, (int) $final->userid);
    }

    public function test_the_results_are_shown_at_once_when_they_cannot_be_kept(): void {
        global $USER, $DB;

        // The place of the plans goes away while the first record is being written: nothing can be saved any more.
        $importer = new class extends importer {
            /** @var string The token of the plan. */
            public string $token = '';

            #[\Override]
            public function import(\stdClass $meta, array $rows): \stdClass {
                global $USER;

                $path = fiche_plan_store::path((int) $USER->id, $this->token);
                $directory = dirname($path);
                foreach (glob("$directory/*") as $file) {
                    unlink($file);
                }
                rmdir($directory);
                file_put_contents($directory, 'a file where the directory was');

                return parent::import($meta, $rows);
            }
        };
        $import = new fiche_import($importer);
        $importer->token = $import->from_upload($this->zip(), 'export-fiches-csv-2026-10-19.zip', ['RNCP99001']);
        $directory = dirname(fiche_plan_store::path((int) $USER->id, $importer->token));

        try {
            $applied = $import->apply($importer->token);
        } finally {
            unlink($directory);
            make_temp_directory('tool_vae/fiche_import');
        }

        $this->resetDebugging();
        $this->assertFalse($applied->resultkept, 'The page is told, and shows the results itself.');
        $this->assertSame('imported', $applied->results[0]->status);
        $this->assertTrue($DB->record_exists('tool_vae_source', ['fichecode' => 'RNCP99001']));
    }
}
