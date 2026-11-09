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
 * The role presets of presets/roles/ stay what the installation guide (part 6) says, and only grant rights that exist.
 *
 * WHAT IS ASSERTED. That every right a preset grants is declared by db/access.php; that the vaeiste preset carries the
 * "student" archetype of the guide (the role is ranked with the students, without a single student right) and the others
 * none; that the contexts are those of the guide; that no preset grants the rights the guide keeps out of the roles
 * (the imports, the identity, the AI, the withdrawal of any attestation, the link to a course); and that the two rights of
 * the positioning cycle are in the two optional presets and in no base one.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class role_presets_test extends \advanced_testcase {
    /** @var string[] Rights no preset may grant: given by hand, and only where the need is written down. */
    private const KEPT_OUT = [
        'tool/vae:import', 'tool/vae:importdossier', 'tool/vae:identityview', 'tool/vae:useai',
        'tool/vae:withdrawany', 'tool/vae:linkcourse',
    ];

    /** @var string[] The base roles: they never carry the rights of the positioning cycle. */
    private const BASE = ['vaeiste', 'vae_responsable', 'vae_direction'];

    /**
     * The presets, read from the files: shortname => [archetype, context levels, rights].
     *
     * @return array
     */
    private function presets(): array {
        $found = [];
        foreach (glob(dirname(__DIR__) . '/presets/roles/role_*.xml') as $file) {
            $xml = simplexml_load_string((string) file_get_contents($file));
            $this->assertNotFalse($xml, basename($file) . ' is not valid XML.');
            $found[(string) $xml->shortname] = [
                'archetype' => (string) $xml->archetype,
                'levels' => array_map('strval', iterator_to_array($xml->contextlevels->level, false)),
                'rights' => array_map('strval', iterator_to_array($xml->permissions->allow, false)),
            ];
        }

        return $found;
    }

    public function test_the_roles_of_the_guide_are_there_with_the_contexts_and_the_archetype_of_the_guide(): void {
        $presets = $this->presets();

        $this->assertEqualsCanonicalizing(
            array_merge(self::BASE, ['vae_responsable_evaluateur', 'vae_direction_certificatrice']),
            array_keys($presets)
        );
        $this->assertSame('student', $presets['vaeiste']['archetype']);
        foreach (array_diff(array_keys($presets), ['vaeiste']) as $other) {
            $this->assertSame('', $presets[$other]['archetype'], $other);
        }
        $this->assertSame(['system'], $presets['vaeiste']['levels']);
        $this->assertSame(['user'], $presets['vae_responsable']['levels']);
        $this->assertSame(['user'], $presets['vae_responsable_evaluateur']['levels']);
        $this->assertSame(['system'], $presets['vae_direction']['levels']);
        $this->assertSame(['system'], $presets['vae_direction_certificatrice']['levels']);
    }

    public function test_every_right_a_preset_grants_is_declared_by_the_plugin_and_none_is_kept_out(): void {
        $capabilities = [];
        require(dirname(__DIR__) . '/db/access.php');

        foreach ($this->presets() as $shortname => $preset) {
            $this->assertNotEmpty($preset['rights'], $shortname);
            $unique = array_values(array_unique($preset['rights']));
            $this->assertSame($preset['rights'], $unique, "$shortname lists a right twice.");
            foreach ($preset['rights'] as $right) {
                $this->assertArrayHasKey($right, $capabilities, "$shortname grants $right, which db/access.php does not declare.");
                $this->assertNotContains($right, self::KEPT_OUT, "$shortname must not grant $right.");
            }
        }
    }

    /**
     * The rights of the vaeiste are the four of the guide and the self-positioning (lot 1a), no more: ranked with the
     * students, the role gets nothing of theirs. Nobody else positions a vaeiste.
     */
    public function test_the_vaeiste_gets_the_five_rights_of_the_guide_and_the_responsable_never_reads_all(): void {
        $presets = $this->presets();

        $this->assertEqualsCanonicalizing(
            ['tool/vae:declare', 'tool/vae:viewown', 'tool/vae:draftown', 'tool/vae:export', 'tool/vae:positionself'],
            $presets['vaeiste']['rights']
        );
        foreach (array_diff(array_keys($presets), ['vaeiste']) as $other) {
            $this->assertNotContains('tool/vae:positionself', $presets[$other]['rights'], $other);
        }
        $this->assertNotContains('tool/vae:viewany', $presets['vae_responsable']['rights']);
        $this->assertNotContains('tool/vae:viewany', $presets['vae_responsable_evaluateur']['rights']);
        $this->assertContains('tool/vae:viewany', $presets['vae_direction']['rights']);
    }

    /**
     * Annotate (lot 1b): the four presets of the managers and the direction carry it, the vaeiste never does - nobody
     * annotates their own text. In each pair, the right sits at the same place, so that the optional preset stays the base
     * one plus its own rights (the test below).
     */
    public function test_the_four_presets_of_the_managers_and_the_direction_carry_annotate_and_the_vaeiste_does_not(): void {
        $presets = $this->presets();

        foreach (['vae_responsable', 'vae_responsable_evaluateur', 'vae_direction', 'vae_direction_certificatrice'] as $name) {
            $this->assertContains('tool/vae:annotate', $presets[$name]['rights'], $name);
        }
        $this->assertNotContains('tool/vae:annotate', $presets['vaeiste']['rights']);
    }

    public function test_the_rights_of_the_positioning_cycle_are_in_the_two_optional_presets_only(): void {
        $presets = $this->presets();

        foreach (self::BASE as $base) {
            $sensitive = array_intersect(
                $presets[$base]['rights'],
                ['tool/vae:evaluate', 'tool/vae:certify', 'tool/vae:issuestructure']
            );
            $this->assertSame([], array_values($sensitive), $base);
        }
        $this->assertSame(
            array_merge($presets['vae_responsable']['rights'], ['tool/vae:evaluate']),
            $presets['vae_responsable_evaluateur']['rights'],
            'The evaluating manager is the manager, plus evaluate.'
        );
        $this->assertSame(
            array_merge($presets['vae_direction']['rights'], ['tool/vae:certify', 'tool/vae:issuestructure']),
            $presets['vae_direction_certificatrice']['rights'],
            'The certifying management is the management, plus certify and issuestructure (which need viewany, there).'
        );
    }
}
