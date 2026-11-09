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
 * The actors of the scheme and the rights each should hold (T-90 lot 2).
 *
 * A GRID READ FROM THE CONFIGURATION, NEVER A PERSON. The catalogue is the table of the guide (section 2.4, "Les droits à
 * cocher"): the right of the vaeiste, of the manager who follows, of the direction, of the administrator; three more actors
 * have no account - the referent of a bloc (who is routed messages, which is no right), the jury (who receives the archive
 * and decides alone) and the certifier (whose record is the authority). grid() puts beside each right the roles of the site
 * that give it. It touches no database: it is a function of what it is given, which is what site_powers() has already read.
 *
 * Each right of an actor has a status. EXPECTED: the role of the actor should give it, and a right no role gives is a gap.
 * OPTIONAL: wanted only if the site decides so (the direction reads sections and identities only if it must; the
 * cycle inside the structure - evaluating, certifying - is for a site that has adopted it). ADMIN: kept
 * for the site administrator, who passes every check; "no role" is its normal state. CONDITIONAL: expected once the setting
 * named opens it, optional until then (the reformulation aid).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class actors {
    /** @var string The right is given by at least one role of the site. */
    public const GIVEN = 'given';

    /** @var string No role gives the right, and none is expected to. */
    public const FREE = 'free';

    /** @var string No role gives a right the actor should hold. */
    public const MISSING = 'missing';

    /** @var string Status: the role of the actor should give the right. */
    public const EXPECTED = 'expected';

    /** @var string Status: wanted only if the site decides so. */
    public const OPTIONAL = 'optional';

    /** @var string Status: kept for the site administrator. */
    public const ADMIN = 'admin';

    /** @var string Status: expected once the setting named in the catalogue is open. */
    public const CONDITIONAL = 'conditional';

    /** @var string[] The rights the site administrator cannot exercise: they follow nobody (access::follows). */
    private const NOT_ADMIN = ['viewassigned', 'writeopinion', 'evaluate', 'annotate'];

    /** @var string[] The settings that open a right: short name of the right => setting. */
    private const OPENED_BY = ['useai' => 'aiopen'];

    /**
     * The actors, in the order of the guide, with their rights.
     *
     * @return array Actor key => ['account' => bool, 'rights' => short name of a right => status].
     */
    public static function catalogue(): array {
        return [
            'vaeiste' => ['account' => true, 'rights' => [
                'declare' => self::EXPECTED,
                'viewown' => self::EXPECTED,
                'draftown' => self::EXPECTED,
                'positionself' => self::EXPECTED,
                'export' => self::EXPECTED,
                'useai' => self::CONDITIONAL,
            ]],
            'responsable' => ['account' => true, 'rights' => [
                'viewassigned' => self::EXPECTED,
                'attest' => self::EXPECTED,
                'readdeposit' => self::EXPECTED,
                'writeopinion' => self::EXPECTED,
                'annotate' => self::EXPECTED,
                'evaluate' => self::OPTIONAL,
            ]],
            'direction' => ['account' => true, 'rights' => [
                'viewany' => self::EXPECTED,
                'export' => self::EXPECTED,
                'readdeposit' => self::EXPECTED,
                'annotate' => self::EXPECTED,
                'draftview' => self::OPTIONAL,
                'identityview' => self::OPTIONAL,
                'certify' => self::OPTIONAL,
                'issuestructure' => self::OPTIONAL,
            ]],
            'administrateur' => ['account' => true, 'rights' => [
                'import' => self::ADMIN,
                'importdossier' => self::ADMIN,
                'withdrawany' => self::ADMIN,
                'linkcourse' => self::ADMIN,
            ]],
            'referent' => ['account' => false, 'rights' => []],
            'jury' => ['account' => false, 'rights' => []],
            'certificateur' => ['account' => false, 'rights' => []],
        ];
    }

    /**
     * The grid: for each actor, each of its rights with the roles of the site that give it.
     *
     * A right is GIVEN when at least one role allows it at the level of the site; a role that only prevents it gives
     * nothing; a role that prohibits it is named, whatever other role gives it. A right the capabilities do not hold
     * (an installation that is not complete) is left out. Roles are as the site names them, already formatted for display.
     *
     * @param \stdClass[] $capabilities As site_powers() lists them: name, label, allow, prevent, prohibit.
     * @param \stdClass[] $settings As site_powers() lists them: name, value, open.
     * @return \stdClass[] Each with key, account and rights (name, label, status, roles, forbidden, state, adminmay).
     */
    public static function grid(array $capabilities, array $settings): array {
        $byname = [];
        foreach ($capabilities as $capability) {
            $byname[$capability->name] = $capability;
        }
        $open = [];
        foreach ($settings as $setting) {
            $open[$setting->name] = (bool) $setting->open;
        }

        $out = [];
        foreach (self::catalogue() as $key => $actor) {
            $rights = [];
            foreach ($actor['rights'] as $short => $status) {
                $capability = $byname['tool/vae:' . $short] ?? null;
                if ($capability === null) {
                    continue;
                }
                if ($status === self::CONDITIONAL) {
                    $status = !empty($open[self::OPENED_BY[$short] ?? '']) ? self::EXPECTED : self::OPTIONAL;
                }
                $roles = array_values($capability->allow);
                $state = self::GIVEN;
                if (!$roles) {
                    $state = $status === self::EXPECTED ? self::MISSING : self::FREE;
                }
                $rights[] = (object) [
                    'name' => $capability->name,
                    'label' => $capability->label,
                    'status' => $status,
                    'roles' => $roles,
                    'forbidden' => array_values($capability->prohibit),
                    'state' => $state,
                    'adminmay' => !in_array($short, self::NOT_ADMIN, true),
                ];
            }
            $out[] = (object) ['key' => $key, 'account' => $actor['account'], 'rights' => $rights];
        }

        return $out;
    }
}
