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

use tool_vae\local\dossier_importer;
use tool_vae\local\schema_validator;

/**
 * Tests for the schema validator the reimport relies on.
 *
 * The validator reads docs/schema rather than restating it. What this test proves is that it
 * reads it the way ajv does in continuous integration: the two examples pass, and every one of
 * the schema's own counter-examples - built by the same single mutations scripts/verifier-schema.mjs
 * applies - is refused.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\schema_validator::class)]
final class schema_validator_test extends \advanced_testcase {
    /**
     * A file of docs/schema, decoded as the validator expects it: objects as stdClass.
     *
     * @param string $path
     * @return mixed
     */
    private static function read(string $path) {
        global $CFG;

        $content = file_get_contents($CFG->dirroot . '/admin/tool/vae/docs/schema/' . $path);

        return json_decode($content, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The base example with one counter-example's mutation applied.
     *
     * @param \stdClass $base
     * @param \stdClass $case
     * @return \stdClass
     */
    private static function mutate(\stdClass $base, \stdClass $case): \stdClass {
        $copy = json_decode(json_encode($base), false, 512, JSON_THROW_ON_ERROR);

        $path = $case->chemin;
        $last = array_pop($path);
        $node = $copy;
        foreach ($path as $key) {
            $node = is_array($node) ? $node[$key] : $node->$key;
        }

        if (!empty($case->supprimer)) {
            unset($node->$last);
        } else if (isset($case->repeter)) {
            $node->$last = str_repeat($case->repeter, $case->fois);
        } else {
            $node->$last = $case->valeur;
        }

        return $copy;
    }

    public function test_both_examples_conform(): void {
        $validator = new schema_validator();

        foreach (['exemples/preuve-complete.json', 'exemples/preuve-minimale.json', 'exemples/preuve-reportee.json'] as $example) {
            $this->assertSame([], $validator->validate(self::read($example)), $example);
        }
    }

    public function test_every_counter_example_of_the_schema_is_refused(): void {
        $set = self::read('tests/contre-exemples.json');
        $base = self::read($set->base);
        $importer = new dossier_importer();

        $this->assertCount(39, $set->cas, 'The counter-example set changed size: check this test still runs all of it.');
        foreach ($set->cas as $case) {
            $this->assertNotEmpty($importer->preuve_errors(self::mutate($base, $case)), "{$case->nom} passed: {$case->regle}");
        }
    }

    public function test_the_text_sections_of_the_faisabilite_conform(): void {
        $validator = new schema_validator();

        foreach (['complete', 'minimale'] as $example) {
            $document = self::read("exemples/faisabilite/faisabilite-$example.json");
            $this->assertSame([], $validator->validate($document, 'faisabilite.schema.json'), $example);
        }
    }

    public function test_every_counter_example_of_the_faisabilite_schema_is_refused(): void {
        $set = self::read('tests/contre-exemples-faisabilite.json');
        $base = self::read($set->base);
        $validator = new schema_validator();

        $this->assertCount(21, $set->cas, 'The counter-example set changed size: check this test still runs all of it.');
        foreach ($set->cas as $case) {
            $errors = $validator->validate(self::mutate($base, $case), 'faisabilite.schema.json');
            $this->assertNotEmpty($errors, "{$case->nom} passed: {$case->regle}");
        }
    }

    public function test_the_preparation_of_the_validation_dossier_conforms(): void {
        $validator = new schema_validator();

        foreach (['complete', 'minimale'] as $example) {
            $document = self::read("exemples/validation/validation-$example.json");
            $this->assertSame([], $validator->validate($document, 'validation.schema.json'), $example);
        }
    }

    public function test_every_counter_example_of_the_validation_schema_is_refused(): void {
        $set = self::read('tests/contre-exemples-validation.json');
        $base = self::read($set->base);
        $validator = new schema_validator();

        $this->assertCount(19, $set->cas, 'The counter-example set changed size: check this test still runs all of it.');
        foreach ($set->cas as $case) {
            $errors = $validator->validate(self::mutate($base, $case), 'validation.schema.json');
            $this->assertNotEmpty($errors, "{$case->nom} passed: {$case->regle}");
        }
    }

    public function test_the_reflective_journal_conforms(): void {
        $validator = new schema_validator();

        foreach (['complete', 'minimale'] as $example) {
            $document = self::read("exemples/reflexion/reflexion-$example.json");
            $this->assertSame([], $validator->validate($document, 'reflexion.schema.json'), $example);
        }
    }

    public function test_every_counter_example_of_the_reflexion_schema_is_refused(): void {
        $set = self::read('tests/contre-exemples-reflexion.json');
        $base = self::read($set->base);
        $validator = new schema_validator();

        $this->assertCount(20, $set->cas, 'The counter-example set changed size: check this test still runs all of it.');
        foreach ($set->cas as $case) {
            $errors = $validator->validate(self::mutate($base, $case), 'reflexion.schema.json');
            $this->assertNotEmpty($errors, "{$case->nom} passed: {$case->regle}");
        }
    }

    public function test_the_supporting_pieces_conform(): void {
        $validator = new schema_validator();

        foreach (['complete', 'minimale'] as $example) {
            $document = self::read("exemples/pieces/pieces-$example.json");
            $this->assertSame([], $validator->validate($document, 'pieces.schema.json'), $example);
        }
    }

    public function test_every_counter_example_of_the_pieces_schema_is_refused(): void {
        $set = self::read('tests/contre-exemples-pieces.json');
        $base = self::read($set->base);
        $validator = new schema_validator();

        $this->assertCount(35, $set->cas, 'The counter-example set changed size: check this test still runs all of it.');
        foreach ($set->cas as $case) {
            $errors = $validator->validate(self::mutate($base, $case), 'pieces.schema.json');
            $this->assertNotEmpty($errors, "{$case->nom} passed: {$case->regle}");
        }
    }

    public function test_a_keyword_the_validator_does_not_know_breaks_loudly(): void {
        $directory = make_request_directory();
        file_put_contents($directory . '/x.schema.json', '{"type": "integer", "multipleOf": 2}');

        $this->expectException(\coding_exception::class);
        (new schema_validator($directory))->validate(0, 'x.schema.json');
    }

    public function test_a_uuid_v4_conforms_but_is_not_imported(): void {
        $preuve = self::read('exemples/preuve-minimale.json');
        $preuve->competence = '3f2b8c1e-9a4d-4e6f-8b7a-1c2d3e4f5a6b';

        $this->assertSame([], (new schema_validator())->validate($preuve));
        $errors = (new dossier_importer())->preuve_errors($preuve);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('/competence', $errors[0]);
    }

    /**
     * A pattern ends where ajv ends it: PHP's $ also matches before a final newline unless told not to.
     */
    public function test_a_ulid_followed_by_a_newline_does_not_conform(): void {
        $preuve = self::read('exemples/preuve-minimale.json');
        $preuve->id .= "\n";

        $this->assertNotEmpty((new schema_validator())->validate($preuve));
    }

    public function test_date_time_is_rfc_3339_with_its_offset(): void {
        $this->assertTrue(schema_validator::is_date_time('2026-03-01T00:00:00Z'));
        $this->assertTrue(schema_validator::is_date_time('2026-03-01T10:30:00.125+02:00'));
        $this->assertFalse(schema_validator::is_date_time('2026-03-01'));
        $this->assertFalse(schema_validator::is_date_time('2026-03-01T10:30:00'));
        $this->assertFalse(schema_validator::is_date_time('2026-02-30T00:00:00Z'));
        $this->assertFalse(schema_validator::is_date_time('2026-03-01T24:00:00Z'));
    }
}
