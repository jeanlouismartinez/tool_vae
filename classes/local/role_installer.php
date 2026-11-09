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

namespace tool_vae\local;

/**
 * The VAE roles, created from the presets shipped in presets/roles/.
 *
 * WHAT THIS SPARES, AND WHAT IT DOES NOT. It spares an administrator the import of five XML files by hand. It changes nothing
 * about who may do what: a preset grants a handful of rights of this plugin and nothing else, the rights the installation
 * guide keeps out of the roles stay out (and are refused here even if a file were edited to grant them), and a role that
 * already exists is never modified, except by the upgrade of the plugin, which adds to a role of a preset a new right it
 * lacks (decision of the project owner, 8 November 2026; see grant_missing()). Nothing is created at installation: the
 * administrator asks for each role, and confirms.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class role_installer {
    /** @var string[] The three roles of the guide: the others of the folder are optional. */
    public const BASE = ['vaeiste', 'vae_responsable', 'vae_direction'];

    /** @var string[] Rights no preset may grant: given by hand, or not for a role at all (the guide, part 6). */
    public const BARRED = [
        'tool/vae:import', 'tool/vae:importdossier', 'tool/vae:identityview', 'tool/vae:useai',
        'tool/vae:withdrawany', 'tool/vae:linkcourse',
    ];

    /** @var string[] Rights only an optional preset adds, never a base role. */
    public const SENSITIVE = ['tool/vae:evaluate', 'tool/vae:certify', 'tool/vae:issuestructure'];

    /**
     * The presets of the folder, read from the files.
     *
     * @return \stdClass[] Keyed by shortname: shortname, name, description, archetype, levels, rights, optional.
     */
    public static function presets(): array {
        global $CFG;
        require_once($CFG->dirroot . '/admin/roles/lib.php');

        $found = [];
        foreach (glob(dirname(__DIR__, 2) . '/presets/roles/role_*.xml') ?: [] as $file) {
            $info = \core_role_preset::parse_preset((string) file_get_contents($file));
            if (!is_array($info) || empty($info['shortname'])) {
                continue;
            }
            $found[(string) $info['shortname']] = (object) [
                'shortname' => (string) $info['shortname'],
                'name' => (string) $info['name'],
                'description' => (string) $info['description'],
                'archetype' => (string) $info['archetype'],
                'levels' => array_map('intval', array_keys($info['contextlevels'])),
                'rights' => array_keys(array_filter($info['permissions'], fn($permission) => (int) $permission === CAP_ALLOW)),
                'optional' => !in_array((string) $info['shortname'], self::BASE, true),
                'permissions' => $info['permissions'],
            ];
        }
        ksort($found);

        return $found;
    }

    /**
     * Whether a role of this shortname is already on the site.
     *
     * @param string $shortname
     * @return bool
     */
    public static function exists(string $shortname): bool {
        global $DB;

        return $DB->record_exists('role', ['shortname' => $shortname]);
    }

    /**
     * Refuse a set of permissions that grants what no role of this plugin may: a right kept out, or one that is not declared.
     *
     * @param array $permissions Capability => permission.
     * @param bool $optional Whether the preset is one of the optional ones (which may add evaluate or certify).
     * @throws \moodle_exception errorrolebadright
     */
    public static function check_rights(array $permissions, bool $optional): void {
        foreach ($permissions as $capability => $permission) {
            if ((int) $permission !== CAP_ALLOW) {
                continue;
            }
            $barred = in_array($capability, self::BARRED, true)
                || (!$optional && in_array($capability, self::SENSITIVE, true));
            if ($barred || strpos((string) $capability, 'tool/vae:') !== 0 || !get_capability_info((string) $capability)) {
                throw new \moodle_exception('errorrolebadright', 'tool_vae', '', (string) $capability);
            }
        }
    }

    /**
     * Create a role from a preset of the folder, if there is none of this shortname yet.
     *
     * @param string $shortname
     * @return int The id of the role.
     * @throws \moodle_exception errorroleunknownpreset, errorroleexists, errorrolebadright
     */
    public static function create(string $shortname): int {
        require_capability('moodle/role:manage', \context_system::instance());

        $presets = self::presets();
        if (!isset($presets[$shortname])) {
            throw new \moodle_exception('errorroleunknownpreset', 'tool_vae');
        }
        $preset = $presets[$shortname];
        // Checked before anything is written: nothing of a refused preset is left behind.
        self::check_rights($preset->permissions, $preset->optional);
        if (self::exists($shortname)) {
            throw new \moodle_exception('errorroleexists', 'tool_vae', '', $shortname);
        }

        $system = \context_system::instance();
        $roleid = create_role($preset->name, $preset->shortname, $preset->description, $preset->archetype);
        set_role_contextlevels($roleid, $preset->levels);
        foreach ($preset->rights as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $system->id, true);
        }

        // The event of the creation is the core's own: create_role() triggers it, once.
        return $roleid;
    }

    /**
     * Add a new right of the plugin to an installed role of a preset that lacks it: the one change the upgrade makes to a role.
     *
     * Only a role whose shortname is that of a preset of the folder, only at the system level, only CAP_ALLOW. Nothing else of
     * the role moves: neither its name, description, archetype or context levels, nor any other right. A role that already has
     * a permission of its own for this right at the system level (allowed, prevented or prohibited by the administrator) is
     * left as it is. The right must pass check_rights(): never a barred right, never one the core has not registered (the
     * upgrade step calls update_capabilities() first, for that reason).
     *
     * @param string $shortname
     * @param string $capability
     * @return bool True if the right was added; false if the role is not a preset's, is not on the site, or has it already.
     * @throws \moodle_exception errorrolebadright
     */
    public static function grant_missing(string $shortname, string $capability): bool {
        global $DB;

        $presets = self::presets();
        if (!isset($presets[$shortname])) {
            return false;
        }
        self::check_rights([$capability => CAP_ALLOW], $presets[$shortname]->optional);
        $roleid = $DB->get_field('role', 'id', ['shortname' => $shortname]);
        if (!$roleid) {
            return false;
        }
        $system = \context_system::instance();
        $held = ['roleid' => $roleid, 'contextid' => $system->id, 'capability' => $capability];
        if ($DB->record_exists('role_capabilities', $held)) {
            return false;
        }
        assign_capability($capability, CAP_ALLOW, (int) $roleid, $system->id, true);

        return true;
    }
}
