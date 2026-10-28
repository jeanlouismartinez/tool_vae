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

use tool_vae\local\fiche_plan_store;
use tool_vae\local\fiche_refusal;

/**
 * The plan kept between the preview and the confirmation of an import of records (0.47.0).
 *
 * WHAT IS ASSERTED. That a plan is a small JSON file of the temporary directory, named after its user and a token nobody
 * can guess; that it is read back by that user only, for two hours; that what is malformed, unknown, expired or somebody
 * else's is simply not there; that a plan too large is refused; that creating one clears the expired plans of the same
 * user, and of nobody else.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\tool_vae\local\fiche_plan_store::class)]
final class fiche_plan_store_test extends \advanced_testcase {
    /** @var \frozen_clock The clock of the test. */
    private $clock;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->clock = $this->mock_clock_with_frozen(1792000000);
    }

    /**
     * The files of the directory of the plans.
     *
     * @return string[]
     */
    private static function files(): array {
        $directory = make_temp_directory('tool_vae/fiche_import');
        $names = array_values(array_diff(scandir($directory), ['.', '..']));
        sort($names);

        return $names;
    }

    public function test_a_plan_is_written_read_rewritten_and_discarded(): void {
        $user = $this->getDataGenerator()->create_user();

        $token = fiche_plan_store::create($user->id, (object) ['origin' => 'upload', 'records' => [['number' => 'RNCP99001']]]);

        $this->assertMatchesRegularExpression(fiche_plan_store::TOKEN_PATTERN, $token);
        $this->assertSame(["{$user->id}-$token.json"], self::files());
        $loaded = fiche_plan_store::load($user->id, $token);
        $this->assertSame((int) $user->id, $loaded->userid);
        $this->assertSame($token, $loaded->token);
        $this->assertSame(1792000000, $loaded->created);
        $this->assertSame('upload', $loaded->origin);
        $this->assertSame('RNCP99001', $loaded->records[0]->number);

        $loaded->results = [(object) ['number' => 'RNCP99001', 'status' => 'imported']];
        fiche_plan_store::save($user->id, $token, $loaded);
        $this->assertSame('imported', fiche_plan_store::load($user->id, $token)->results[0]->status);

        fiche_plan_store::discard($user->id, $token);
        $this->assertNull(fiche_plan_store::load($user->id, $token));
        $this->assertSame([], self::files());
    }

    public function test_the_file_is_json_only_and_nothing_else_is_left_beside_it(): void {
        $user = $this->getDataGenerator()->create_user();
        $token = fiche_plan_store::create($user->id, (object) ['origin' => 'fetch']);

        $content = file_get_contents(fiche_plan_store::path($user->id, $token));

        $this->assertStringStartsWith('{', $content);
        $this->assertNotNull(json_decode($content));
        $this->assertSame(["{$user->id}-$token.json"], self::files(), 'No temporary file is left.');
        $this->assertSame(
            make_temp_directory('tool_vae/fiche_import') . "/{$user->id}-$token.json",
            fiche_plan_store::path($user->id, $token)
        );
    }

    public function test_what_is_malformed_unknown_or_somebody_elses_is_not_there(): void {
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $token = fiche_plan_store::create($user->id, (object) ['origin' => 'upload']);

        $this->assertNull(fiche_plan_store::load($other->id, $token), 'Another user.');
        $this->assertNull(fiche_plan_store::load($user->id, str_repeat('0', 32)), 'Unknown.');
        foreach (['../' . $token, strtoupper($token), substr($token, 0, 10), $token . 'x', '', "$token/../x"] as $bad) {
            $this->assertNull(fiche_plan_store::load($user->id, $bad), "Malformed: $bad");
        }
        $this->assertNotNull(fiche_plan_store::load($user->id, $token), 'The right user still reads it.');

        // A file renamed for somebody else is not read as theirs: the user inside the file is compared.
        copy(fiche_plan_store::path($user->id, $token), fiche_plan_store::path($other->id, $token));
        $this->assertNull(fiche_plan_store::load($other->id, $token));
    }

    public function test_a_plan_expires_after_two_hours(): void {
        $user = $this->getDataGenerator()->create_user();
        $token = fiche_plan_store::create($user->id, (object) ['origin' => 'upload']);

        $this->clock->set_to(1792000000 + fiche_plan_store::TTL - 1);
        $this->assertNotNull(fiche_plan_store::load($user->id, $token), 'Just before.');

        $this->clock->set_to(1792000000 + fiche_plan_store::TTL + 1);
        $this->assertNull(fiche_plan_store::load($user->id, $token));
        $this->assertSame([], self::files(), 'An expired plan is deleted when it is met.');
    }

    public function test_a_plan_too_large_is_refused(): void {
        $user = $this->getDataGenerator()->create_user();

        try {
            fiche_plan_store::create($user->id, (object) ['blob' => str_repeat('a', fiche_plan_store::MAX_BYTES + 10)]);
            $this->fail('A plan over the cap must be refused.');
        } catch (fiche_refusal $refusal) {
            $this->assertSame(fiche_refusal::PLAN_TOO_LARGE, $refusal->reason());
        }
        $this->assertSame([], self::files());
    }

    public function test_creating_a_plan_clears_the_expired_ones_of_the_same_user_only(): void {
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $old = fiche_plan_store::create($user->id, (object) ['origin' => 'upload']);
        $others = fiche_plan_store::create($other->id, (object) ['origin' => 'upload']);

        $this->clock->set_to(1792000000 + fiche_plan_store::TTL + 10);
        $new = fiche_plan_store::create($user->id, (object) ['origin' => 'fetch']);

        $this->assertSame(
            ["{$user->id}-$new.json", "{$other->id}-$others.json"],
            array_values(array_filter(self::files(), fn($name) => $name !== "{$user->id}-$old.json"))
        );
        $this->assertNotContains("{$user->id}-$old.json", self::files());
        $this->assertContains("{$other->id}-$others.json", self::files(), 'The plan of somebody else is not touched.');
    }

    public function test_saving_gives_the_plan_two_more_hours(): void {
        $user = $this->getDataGenerator()->create_user();
        $token = fiche_plan_store::create($user->id, (object) ['origin' => 'upload']);

        // Saved with its results just before it would have expired.
        $this->clock->set_to(1792000000 + fiche_plan_store::TTL - 60);
        $plan = fiche_plan_store::load($user->id, $token);
        fiche_plan_store::save($user->id, $token, $plan);

        $this->clock->set_to(1792000000 + fiche_plan_store::TTL + 3600);
        $this->assertNotNull(fiche_plan_store::load($user->id, $token), 'The result outlives the preview that led to it.');

        $this->clock->set_to(1792000000 + fiche_plan_store::TTL - 60 + fiche_plan_store::TTL + 1);
        $this->assertNull(fiche_plan_store::load($user->id, $token), 'But not for ever.');
    }
}
