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

/**
 * The seven tables of the work situations (lot 1b): what the spec names is there.
 *
 * The situations, their versions, their tags, their pieces and links are written by the vaeiste alone. The consents and
 * the annotations are lines added and never changed; the latest line stands. An annotation is never a validation.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class situation_schema_test extends \advanced_testcase {
    /**
     * Every column the spec names is in its table.
     */
    public function test_the_seven_tables_carry_the_columns_the_spec_names(): void {
        global $DB;

        $manager = $DB->get_manager();
        $tables = [
            'tool_vae_situation' => [
                'id', 'uuid', 'candidateid', 'fichecode', 'slot', 'draftid', 'title', 'periodstart', 'periodend', 'setting',
                'timecreated', 'timemodified',
            ],
            'tool_vae_sitversion' => [
                'id', 'situationid', 'number', 'title', 'periodstart', 'periodend', 'setting', 'tags', 'timecreated',
            ],
            'tool_vae_sitetag' => ['id', 'situationid', 'candidateid', 'fichecode', 'ficherang', 'timecreated'],
            'tool_vae_spiece' => [
                'id', 'uuid', 'candidateid', 'fichecode', 'kind', 'nature', 'title', 'ext', 'mimetype', 'filesize', 'sha256',
                'url', 'timecreated', 'timeremoved',
            ],
            'tool_vae_slink' => ['id', 'situationid', 'pieceid', 'part', 'timecreated', 'timeremoved'],
            'tool_vae_consent' => [
                'id', 'uuid', 'candidateid', 'managerid', 'manageruuid', 'state', 'importedat', 'timecreated',
            ],
            'tool_vae_note' => [
                'id', 'uuid', 'candidateid', 'target', 'targetuuid', 'fichecode', 'slot', 'ficherang', 'authorid', 'authoruuid',
                'text', 'textformat', 'state', 'withdraws', 'notehash', 'importedat', 'timecreated',
            ],
        ];
        foreach ($tables as $name => $fields) {
            $this->assertTrue($manager->table_exists($name), 'The table ' . $name . ' is missing.');
            $table = new \xmldb_table($name);
            foreach ($fields as $field) {
                $this->assertTrue($manager->field_exists($table, $field), 'The column ' . $name . '.' . $field . ' is missing.');
            }
        }
    }

    /**
     * The indexes that read the latest line are there, and those that refuse a double are unique.
     */
    public function test_the_indexes_that_read_the_latest_line_and_refuse_a_double_exist(): void {
        global $DB;

        $manager = $DB->get_manager();
        $indexes = [
            ['tool_vae_situation', 'uuid', XMLDB_INDEX_UNIQUE, ['uuid']],
            ['tool_vae_situation', 'candidateslot', XMLDB_INDEX_UNIQUE, ['candidateid', 'fichecode', 'slot']],
            ['tool_vae_situation', 'draftid', XMLDB_INDEX_UNIQUE, ['draftid']],
            ['tool_vae_sitversion', 'situationnumber', XMLDB_INDEX_UNIQUE, ['situationid', 'number']],
            ['tool_vae_sitetag', 'situationrank', XMLDB_INDEX_UNIQUE, ['situationid', 'ficherang']],
            ['tool_vae_sitetag', 'candidaterank', XMLDB_INDEX_NOTUNIQUE, ['candidateid', 'fichecode', 'ficherang']],
            ['tool_vae_spiece', 'uuid', XMLDB_INDEX_UNIQUE, ['uuid']],
            ['tool_vae_spiece', 'candidateid', XMLDB_INDEX_NOTUNIQUE, ['candidateid']],
            ['tool_vae_slink', 'situationpiece', XMLDB_INDEX_UNIQUE, ['situationid', 'pieceid']],
            ['tool_vae_consent', 'uuid', XMLDB_INDEX_UNIQUE, ['uuid']],
            ['tool_vae_consent', 'candidatemanager', XMLDB_INDEX_NOTUNIQUE, ['candidateid', 'managerid', 'id']],
            ['tool_vae_consent', 'managerid', XMLDB_INDEX_NOTUNIQUE, ['managerid']],
            ['tool_vae_note', 'uuid', XMLDB_INDEX_UNIQUE, ['uuid']],
            [
                'tool_vae_note', 'candidatetarget', XMLDB_INDEX_NOTUNIQUE,
                ['candidateid', 'target', 'fichecode', 'slot', 'ficherang', 'id'],
            ],
            ['tool_vae_note', 'authorid', XMLDB_INDEX_NOTUNIQUE, ['authorid']],
        ];
        foreach ($indexes as [$tablename, $indexname, $unique, $fields]) {
            $table = new \xmldb_table($tablename);
            $index = new \xmldb_index($indexname, $unique, $fields);
            $message = 'The index ' . $tablename . '.' . $indexname . ' is missing.';
            $this->assertTrue($manager->index_exists($table, $index), $message);
            // The manager compares the columns alone and ignores UNIQUE: the database says whether the index is unique.
            $found = null;
            foreach ($DB->get_indexes($tablename) as $existing) {
                if ($existing['columns'] === $fields) {
                    $found = $existing;
                }
            }
            $this->assertNotNull($found, $message);
            $kind = $unique === XMLDB_INDEX_UNIQUE ? 'unique' : 'not unique';
            $should = 'The index ' . $tablename . '.' . $indexname . ' should be ' . $kind . '.';
            $this->assertSame($unique === XMLDB_INDEX_UNIQUE, (bool) $found['unique'], $should);
        }
    }

    /**
     * Two situations of the same slot for one vaeiste and record are refused by the database.
     */
    public function test_a_second_situation_in_the_same_slot_is_refused(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->insert_situation((int) $user->id, 1, $this->insert_draft((int) $user->id, 1), '01ARZ3NDEKTSV4RRFFQ69G5FB1');

        $this->expectException(\dml_write_exception::class);
        $this->insert_situation((int) $user->id, 1, $this->insert_draft((int) $user->id, 2), '01ARZ3NDEKTSV4RRFFQ69G5FB2');
    }

    /**
     * Two situations on one draft are refused by the database: the key on draftid is foreign-unique.
     */
    public function test_a_second_situation_on_the_same_draft_is_refused(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $draftid = $this->insert_draft((int) $user->id, 1);
        $this->insert_situation((int) $user->id, 1, $draftid, '01ARZ3NDEKTSV4RRFFQ69G5FB3');

        $this->expectException(\dml_write_exception::class);
        $this->insert_situation((int) $user->id, 2, $draftid, '01ARZ3NDEKTSV4RRFFQ69G5FB4');
    }

    /**
     * Writes a draft of a situation, as the database accepts it.
     *
     * @param int $userid The vaeiste.
     * @param int $slot The slot of the draft.
     * @return int The id of the draft.
     */
    private function insert_draft(int $userid, int $slot): int {
        global $DB;

        return (int) $DB->insert_record('tool_vae_draft', (object) [
            'uuid' => '01ARZ3NDEKTSV4RRFFQ69G5FD' . $slot,
            'candidateid' => $userid,
            'doctype' => 'validation',
            'fichecode' => 'RNCP100',
            'section' => '_situation',
            'slot' => $slot,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => $userid,
        ]);
    }

    /**
     * Writes a situation, as the database accepts it.
     *
     * @param int $userid The vaeiste.
     * @param int $slot The slot of the situation.
     * @param int $draftid The draft of the situation.
     * @param string $uuid The identifier of the situation.
     * @return int The id of the situation.
     */
    private function insert_situation(int $userid, int $slot, int $draftid, string $uuid): int {
        global $DB;

        return (int) $DB->insert_record('tool_vae_situation', (object) [
            'uuid' => $uuid,
            'candidateid' => $userid,
            'fichecode' => 'RNCP100',
            'slot' => $slot,
            'draftid' => $draftid,
            'title' => 'Situation',
            'periodstart' => null,
            'periodend' => null,
            'setting' => '',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * A row of each table at its bounds is accepted and read back: a title of 60, a setting of 100, a part of 255, an
     * annotation of 4 000 characters, a state of 9 and a rank of 8.
     */
    public function test_a_row_of_each_table_at_its_bounds_fits(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $manager = $this->getDataGenerator()->create_user();
        $now = time();
        $title = str_repeat('t', 60);
        $setting = str_repeat('c', 100);

        $draftid = $DB->insert_record('tool_vae_draft', (object) [
            'uuid' => '01ARZ3NDEKTSV4RRFFQ69G5FA0',
            'candidateid' => (int) $user->id,
            'doctype' => 'validation',
            'fichecode' => 'RNCP100',
            'section' => '_situation',
            'slot' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => (int) $user->id,
        ]);

        $situationid = $DB->insert_record('tool_vae_situation', (object) [
            'uuid' => '01ARZ3NDEKTSV4RRFFQ69G5FA1',
            'candidateid' => (int) $user->id,
            'fichecode' => str_repeat('R', 32),
            'slot' => 30,
            'draftid' => $draftid,
            'title' => $title,
            'periodstart' => null,
            'periodend' => null,
            'setting' => $setting,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $situation = $DB->get_record('tool_vae_situation', ['id' => $situationid], '*', MUST_EXIST);
        $this->assertSame(60, \core_text::strlen($situation->title));
        $this->assertSame(100, \core_text::strlen($situation->setting));
        $this->assertNull($situation->periodstart);
        $this->assertSame((int) $draftid, (int) $situation->draftid);

        $versionid = $DB->insert_record('tool_vae_sitversion', (object) [
            'situationid' => $situationid,
            'number' => 200,
            'title' => $title,
            'periodstart' => $now - 86400,
            'periodend' => $now,
            'setting' => $setting,
            'tags' => '',
            'timecreated' => $now,
        ]);
        $version = $DB->get_record('tool_vae_sitversion', ['id' => $versionid], '*', MUST_EXIST);
        $this->assertSame('', $version->tags);
        $this->assertSame(60, \core_text::strlen($version->title));

        $tagid = $DB->insert_record('tool_vae_sitetag', (object) [
            'situationid' => $situationid,
            'candidateid' => (int) $user->id,
            'fichecode' => 'RNCP100',
            'ficherang' => '10.10.10',
            'timecreated' => $now,
        ]);
        $this->assertSame('10.10.10', $DB->get_field('tool_vae_sitetag', 'ficherang', ['id' => $tagid]));

        $pieceid = $DB->insert_record('tool_vae_spiece', (object) [
            'uuid' => '01ARZ3NDEKTSV4RRFFQ69G5FA2',
            'candidateid' => (int) $user->id,
            'fichecode' => 'RNCP100',
            'kind' => 'link',
            'nature' => 'writing',
            'title' => $title,
            'ext' => null,
            'mimetype' => null,
            'filesize' => null,
            'sha256' => null,
            'url' => 'https://example.org/' . str_repeat('u', 235),
            'timecreated' => $now,
            'timeremoved' => null,
        ]);
        $piece = $DB->get_record('tool_vae_spiece', ['id' => $pieceid], '*', MUST_EXIST);
        $this->assertSame(255, \core_text::strlen($piece->url));
        $this->assertNull($piece->timeremoved);

        $linkid = $DB->insert_record('tool_vae_slink', (object) [
            'situationid' => $situationid,
            'pieceid' => $pieceid,
            'part' => str_repeat('p', 255),
            'timecreated' => $now,
            'timeremoved' => null,
        ]);
        $this->assertSame(255, \core_text::strlen($DB->get_field('tool_vae_slink', 'part', ['id' => $linkid])));

        $consentid = $DB->insert_record('tool_vae_consent', (object) [
            'uuid' => '01ARZ3NDEKTSV4RRFFQ69G5FA3',
            'candidateid' => (int) $user->id,
            'managerid' => null,
            'manageruuid' => '01ARZ3NDEKTSV4RRFFQ69G5FA4',
            'state' => 'withdrawn',
            'importedat' => $now,
            'timecreated' => $now,
        ]);
        $consent = $DB->get_record('tool_vae_consent', ['id' => $consentid], '*', MUST_EXIST);
        $this->assertSame('withdrawn', $consent->state);
        $this->assertNull($consent->managerid);

        $noteid = $DB->insert_record('tool_vae_note', (object) [
            'uuid' => '01ARZ3NDEKTSV4RRFFQ69G5FA5',
            'candidateid' => (int) $user->id,
            'target' => 'situation',
            'targetuuid' => $situation->uuid,
            'fichecode' => 'RNCP100',
            'slot' => 30,
            'ficherang' => '',
            'authorid' => (int) $manager->id,
            'authoruuid' => null,
            'text' => str_repeat('n', 4000),
            'textformat' => FORMAT_MARKDOWN,
            'state' => 'withdrawn',
            'withdraws' => '01ARZ3NDEKTSV4RRFFQ69G5FA6',
            'notehash' => str_repeat('0', 64),
            'importedat' => null,
            'timecreated' => $now,
        ]);
        $note = $DB->get_record('tool_vae_note', ['id' => $noteid], '*', MUST_EXIST);
        $this->assertSame(4000, \core_text::strlen($note->text));
        $this->assertSame('', $note->ficherang);
        $this->assertSame('withdrawn', $note->state);
        $this->assertSame((int) FORMAT_MARKDOWN, (int) $note->textformat);

        $selfposnote = $DB->insert_record('tool_vae_note', (object) [
            'uuid' => '01ARZ3NDEKTSV4RRFFQ69G5FA7',
            'candidateid' => (int) $user->id,
            'target' => 'selfpos',
            'targetuuid' => '',
            'fichecode' => 'RNCP100',
            'ficherang' => '10.10.10',
            'authorid' => null,
            'text' => '',
            'state' => 'active',
            'notehash' => str_repeat('1', 64),
            'timecreated' => $now,
        ]);
        $defaults = $DB->get_record('tool_vae_note', ['id' => $selfposnote], '*', MUST_EXIST);
        $this->assertSame(0, (int) $defaults->slot, 'The slot of a note defaults to 0.');
        $this->assertSame((int) FORMAT_PLAIN, (int) $defaults->textformat, 'The format of a note defaults to FORMAT_PLAIN.');
        $this->assertSame('', $defaults->targetuuid);
    }
}
