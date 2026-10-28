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

use tool_vae\local\identity_map;

/**
 * Tests for the ULID correspondence.
 *
 * The pattern asserted here mirrors commun/identifiant.schema.json. If the two ever drift,
 * the export produces objects the pivot schema rejects and MC-1 stops being satisfied - so
 * these assertions are about the derogation holding, not about string formatting.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\identity_map::class)]
final class identity_map_test extends \advanced_testcase {
    public function test_generated_ulid_has_the_shape_the_pivot_schema_accepts(): void {
        for ($i = 0; $i < 1000; $i++) {
            $ulid = identity_map::generate_ulid();
            $this->assertSame(26, strlen($ulid));
            $this->assertMatchesRegularExpression(identity_map::ULID_PATTERN, $ulid);
        }
    }

    /**
     * The leading character must stay in 0-7, which is what the pivot pattern anchors on.
     *
     * Checked at the boundaries rather than at "now", because a generator that happens to
     * work today can still overflow the first character at some future timestamp.
     */
    public function test_leading_character_stays_within_range_at_the_boundaries(): void {
        $boundaries = [
            0, // Unix epoch.
            1787000000000, // Around today.
            4102444800000, // Year 2100.
            253402300800000, // Year 10000.
            (1 << 48) - 1, // Largest 48 bit timestamp.
        ];

        foreach ($boundaries as $timestamp) {
            $ulid = identity_map::generate_ulid($timestamp);
            $this->assertMatchesRegularExpression(
                identity_map::ULID_PATTERN,
                $ulid,
                "Rejected for timestamp $timestamp"
            );
        }
    }

    /**
     * Lexicographic order must be chronological order.
     *
     * A dossier is read in the order the activities happened; if identifiers do not sort
     * that way, every listing needs an extra sort key it should not need.
     */
    public function test_ulids_sort_chronologically(): void {
        $earlier = identity_map::generate_ulid(1787000000000);
        $later = identity_map::generate_ulid(1787000000001);

        $this->assertLessThan(0, strcmp($earlier, $later));
    }

    public function test_the_alphabet_excludes_the_ambiguous_letters(): void {
        $sample = '';
        for ($i = 0; $i < 500; $i++) {
            $sample .= identity_map::generate_ulid();
        }

        // I and L read as 1, O reads as 0, U is excluded by Crockford. A panel member
        // reading an identifier aloud from a printed dossier is a real situation.
        $this->assertDoesNotMatchRegularExpression('/[ILOU]/', $sample);
    }

    public function test_a_moodle_integer_is_not_a_valid_ulid(): void {
        $this->assertFalse(identity_map::is_valid_ulid('1187'));
        $this->assertFalse(identity_map::is_valid_ulid(''));
        $this->assertFalse(identity_map::is_valid_ulid('01J9ZP7X4K8QZ2M6R3B5N7T0V'));
        $this->assertFalse(identity_map::is_valid_ulid('81J9ZP7X4K8QZ2M6R3B5N7T0VW'));
        $this->assertFalse(identity_map::is_valid_ulid('01J9ZP7X4K8QZ2M6R3B5N7TOVW'));
    }

    /**
     * A ULID followed by a newline is refused, as ajv refuses it against the pivot schema.
     */
    public function test_a_ulid_followed_by_a_newline_is_not_valid(): void {
        $ulid = identity_map::generate_ulid();

        $this->assertTrue(identity_map::is_valid_ulid($ulid));
        $this->assertFalse(identity_map::is_valid_ulid($ulid . "\n"));
    }

    public function test_the_identifier_of_an_item_is_stable(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $first = identity_map::uuid_for(identity_map::TYPE_USER, $user->id);
        $second = identity_map::uuid_for(identity_map::TYPE_USER, $user->id);

        $this->assertSame($first, $second);
        $this->assertTrue(identity_map::is_valid_ulid($first));
    }

    public function test_two_items_never_share_an_identifier(): void {
        $this->resetAfterTest();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();

        $this->assertNotSame(
            identity_map::uuid_for(identity_map::TYPE_USER, $one->id),
            identity_map::uuid_for(identity_map::TYPE_USER, $two->id)
        );

        // Same numeric id, different kind of thing: still two identifiers.
        $this->assertNotSame(
            identity_map::uuid_for(identity_map::TYPE_USER, $one->id),
            identity_map::uuid_for(identity_map::TYPE_COMPETENCY, $one->id)
        );
    }

    public function test_an_identifier_resolves_back_to_its_item(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $uuid = identity_map::uuid_for(identity_map::TYPE_USER, $user->id);

        $this->assertSame(
            ['itemtype' => identity_map::TYPE_USER, 'itemid' => (int) $user->id],
            identity_map::resolve($uuid)
        );
    }

    /**
     * An archive produced elsewhere carries identifiers this instance has never seen.
     * The importer must be able to tell "unknown here" from "malformed".
     */
    public function test_an_unknown_identifier_resolves_to_null_rather_than_failing(): void {
        $this->resetAfterTest();

        $this->assertNull(identity_map::resolve(identity_map::generate_ulid()));
    }

    public function test_an_unknown_item_type_is_refused(): void {
        $this->resetAfterTest();

        $this->expectException(\coding_exception::class);
        identity_map::uuid_for('utilisateur', 1);
    }

    /**
     * The unique index is what makes the concurrent-mint recovery correct. If it were
     * dropped, two requests could mint two identifiers for one item and the duplicate
     * would only surface in an exported dossier.
     */
    public function test_the_item_index_is_unique(): void {
        global $DB;
        $this->resetAfterTest();

        $row = (object) [
            'itemtype' => identity_map::TYPE_USER,
            'itemid' => 42,
            'uuid' => identity_map::generate_ulid(),
            'timecreated' => time(),
        ];
        $DB->insert_record(identity_map::TABLE, $row);

        $duplicate = clone $row;
        $duplicate->uuid = identity_map::generate_ulid();

        $this->expectException(\dml_exception::class);
        $DB->insert_record(identity_map::TABLE, $duplicate);
    }

    /**
     * The refusal of adopt() can be asked for before adopt() is called, and adopt() answers as it was told.
     *
     * An archive is checked whole before anything is written, and adopt() runs in the transaction of the write: asked there,
     * a competency that holds another identifier was refused last, and as an account. The four situations, once each.
     */
    public function test_what_adopt_would_refuse_can_be_asked_without_giving_anything(): void {
        global $DB;
        $this->resetAfterTest();

        $held = identity_map::uuid_for(identity_map::TYPE_COMPETENCY, 7);
        $other = identity_map::generate_ulid();
        $before = $DB->count_records(identity_map::TABLE);

        // An item with no identifier yet, and an identifier nobody has: free.
        $this->assertNull(identity_map::adoption_conflict(identity_map::TYPE_COMPETENCY, 8, $other));
        // An item that already holds this very identifier: nothing to say.
        $this->assertNull(identity_map::adoption_conflict(identity_map::TYPE_COMPETENCY, 7, $held));
        // An item that holds another one keeps it.
        $this->assertSame(
            'errorarchiveidentityheld',
            identity_map::adoption_conflict(identity_map::TYPE_COMPETENCY, 7, $other)
        );
        // An identifier given to another item is not given twice.
        $this->assertSame(
            'errorarchiveidentitytaken',
            identity_map::adoption_conflict(identity_map::TYPE_COMPETENCY, 8, $held)
        );
        $this->assertSame($before, $DB->count_records(identity_map::TABLE), 'Asking changed the map.');

        // And adopt() is the rule asked, then acted on.
        $this->assertTrue(identity_map::adopt(identity_map::TYPE_COMPETENCY, 7, $held));
        $this->assertFalse(identity_map::adopt(identity_map::TYPE_COMPETENCY, 8, $other));
        $this->assertSame($other, identity_map::uuid_for(identity_map::TYPE_COMPETENCY, 8));
        foreach (
            [
                [identity_map::TYPE_COMPETENCY, 7, $other, 'errorarchiveidentityheld'],
                [identity_map::TYPE_COMPETENCY, 9, $held, 'errorarchiveidentitytaken'],
            ] as [$type, $id, $uuid, $errorcode]
        ) {
            try {
                identity_map::adopt($type, $id, $uuid);
                $this->fail("adopt() did not refuse; $errorcode was expected.");
            } catch (\moodle_exception $e) {
                $this->assertSame($errorcode, $e->errorcode);
            }
        }
    }

    /**
     * The same question asked of several items at once names EVERY item in conflict, not the first, and gives nothing.
     *
     * An archive from another site is refused whole, once: the competencies RNCP40692-BC01, -BC02 and -BC03 are three items here,
     * each with its identifier on this site. The answer keeps the order of the items and leaves out those that may take theirs.
     */
    public function test_what_adopt_would_refuse_can_be_asked_of_several_items_at_once(): void {
        global $DB;
        $this->resetAfterTest();

        $first = identity_map::uuid_for(identity_map::TYPE_COMPETENCY, 11);
        $second = identity_map::uuid_for(identity_map::TYPE_COMPETENCY, 12);
        $third = identity_map::uuid_for(identity_map::TYPE_COMPETENCY, 13);
        $before = $DB->count_records(identity_map::TABLE);

        $wanted = [
            // Holds another identifier than the one asked: in conflict.
            11 => identity_map::generate_ulid(),
            // Holds this very identifier: nothing to say.
            12 => $second,
            // Holds another one again: in conflict, and named after the first.
            13 => identity_map::generate_ulid(),
            // No identifier yet and one nobody has: free.
            14 => identity_map::generate_ulid(),
            // No identifier yet, but the one asked is the first item's: not given twice.
            15 => $first,
        ];
        $conflicts = identity_map::adoption_conflicts(identity_map::TYPE_COMPETENCY, $wanted);

        $this->assertSame(
            [
                11 => 'errorarchiveidentityheld',
                13 => 'errorarchiveidentityheld',
                15 => 'errorarchiveidentitytaken',
            ],
            $conflicts
        );
        $this->assertSame($before, $DB->count_records(identity_map::TABLE), 'Asking changed the map.');
        $this->assertSame($third, identity_map::uuid_for(identity_map::TYPE_COMPETENCY, 13));

        // And it is the question of adoption_conflict(), item by item: one statement of the rule.
        foreach ($wanted as $itemid => $uuid) {
            $this->assertSame(
                $conflicts[$itemid] ?? null,
                identity_map::adoption_conflict(identity_map::TYPE_COMPETENCY, $itemid, $uuid)
            );
        }
        $this->assertSame([], identity_map::adoption_conflicts(identity_map::TYPE_COMPETENCY, []));
    }

    /**
     * An item type that does not exist is refused when several items are asked about too, even if there are none.
     */
    public function test_an_unknown_item_type_is_refused_when_asking_about_several_items(): void {
        $this->resetAfterTest();

        $this->expectException(\coding_exception::class);
        identity_map::adoption_conflicts('utilisateur', []);
    }

    /**
     * adopt() decides, and answers, from ONE reading of the item.
     *
     * It read the table twice: once to ask the rule (get_field() in adoption_conflict()) and once to learn whether the item held
     * the ULID (record_exists()). A request that inserted another ULID for the same item between the two made the second say
     * "already held this very one": adopt() answered true having adopted nothing and refused nothing, where a single reading
     * decides alone and a concurrent insert is stopped by the unique index. A race cannot be staged here; what can is the
     * number of readings, which is how the two stories were told.
     */
    public function test_adopt_decides_and_answers_from_one_reading_of_the_item(): void {
        global $DB;
        $this->resetAfterTest();

        $held = identity_map::uuid_for(identity_map::TYPE_COMPETENCY, 7);
        $where = ['itemtype' => identity_map::TYPE_COMPETENCY, 'itemid' => 7];

        // What one reading of the item costs here, the queries of the driver included.
        $before = $DB->perf_get_reads();
        $DB->get_field(identity_map::TABLE, 'uuid', $where);
        $single = $DB->perf_get_reads() - $before;
        $this->assertGreaterThan(0, $single);

        $before = $DB->perf_get_reads();
        $this->assertTrue(identity_map::adopt(identity_map::TYPE_COMPETENCY, 7, $held));
        $this->assertSame($single, $DB->perf_get_reads() - $before, 'An item that holds the ULID was read more than once.');
    }

    /**
     * An item type that does not exist is refused when the question is asked as when the identifier is given.
     */
    public function test_an_unknown_item_type_is_refused_when_asking_too(): void {
        $this->resetAfterTest();

        $this->expectException(\coding_exception::class);
        identity_map::adoption_conflict('utilisateur', 1, identity_map::generate_ulid());
    }
}
