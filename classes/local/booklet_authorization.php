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

use context_system;
use tool_vae\event\booklet_authorization_changed;

/**
 * The authorization the direction gives a manager to produce the archive of the structure (decision of the project owner,
 * 7 November 2026): once for the manager, valid for every candidate they follow, taken back at any time.
 *
 * The archive of the structure is the livret of the accompanying structure: the positionings, in a document of their own,
 * never a part of the vaeiste's dossiers. The direction produces it with tool/vae:issuestructure and tool/vae:viewany; a manager
 * produces it for the candidates they follow while the latest line of their authorization says `granted`.
 *
 * ADDED TO, NEVER CHANGED: a line is written for each decision, the latest line of a manager says where they stand, and the
 * earlier ones stay as the history of who authorized whom and when. The authorization widens nothing a manager may read: they
 * still follow only their own candidates (access::follows()).
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class booklet_authorization {
    /** @var string The table. */
    public const TABLE = 'tool_vae_booklet_auth';

    /** @var string The manager may produce the archive of the structure. */
    public const GRANTED = 'granted';

    /** @var string The authorization was taken back. */
    public const REVOKED = 'revoked';

    /**
     * Whether the current user may authorize a manager, or take the authorization back: the right to produce the archive of the
     * structure, and, with it, the right to read every dossier. Neither is enough alone.
     *
     * @return bool
     */
    public static function can_decide(): bool {
        $system = context_system::instance();

        return has_capability('tool/vae:issuestructure', $system) && has_capability('tool/vae:viewany', $system);
    }

    /**
     * Authorize a manager.
     *
     * @param int $managerid
     * @return \stdClass The line written.
     * @throws \moodle_exception errorbookletdenied, errorbookletself, errorbookletmanager or errorbookletnothing.
     */
    public static function grant(int $managerid): \stdClass {
        global $USER;

        if (!self::can_decide()) {
            throw new \moodle_exception('errorbookletdenied', 'tool_vae');
        }
        if ($managerid === (int) $USER->id) {
            throw new \moodle_exception('errorbookletself', 'tool_vae');
        }
        $manager = \core_user::get_user($managerid);
        if (!$manager || $manager->deleted || !self::follows_anyone($managerid)) {
            throw new \moodle_exception('errorbookletmanager', 'tool_vae');
        }
        if (self::stands($managerid)) {
            throw new \moodle_exception('errorbookletnothing', 'tool_vae');
        }

        return self::write($managerid, self::GRANTED);
    }

    /**
     * Take the authorization of a manager back.
     *
     * @param int $managerid
     * @return \stdClass The line written.
     * @throws \moodle_exception errorbookletdenied or errorbookletnothing (nothing stands).
     */
    public static function revoke(int $managerid): \stdClass {
        if (!self::can_decide()) {
            throw new \moodle_exception('errorbookletdenied', 'tool_vae');
        }
        if (!self::stands($managerid)) {
            throw new \moodle_exception('errorbookletnothing', 'tool_vae');
        }

        return self::write($managerid, self::REVOKED);
    }

    /**
     * Whether the manager is authorized now: their latest line says granted.
     *
     * @param int $managerid
     * @return bool
     */
    public static function stands(int $managerid): bool {
        global $DB;

        $rows = $DB->get_records(self::TABLE, ['managerid' => $managerid], 'id DESC', 'id, status', 0, 1);
        $latest = reset($rows);

        return $latest !== false && $latest->status === self::GRANTED;
    }

    /**
     * Every line of a manager, oldest first.
     *
     * @param int $managerid
     * @return \stdClass[]
     */
    public static function lines(int $managerid): array {
        global $DB;

        return array_values($DB->get_records(self::TABLE, ['managerid' => $managerid], 'id ASC'));
    }

    /**
     * The accounts the direction may authorize or take the authorization back from: those that follow at least one candidate
     * and those whose authorization stands, whoever they follow today - an authorization must stay visible, and so revocable,
     * after the roles that made the manager follow someone are gone. Never the current user, by name.
     *
     * @return \stdClass[] Each with the id and the fields of a name.
     */
    public static function managers(): array {
        global $DB, $USER;

        $names = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $sql = "SELECT DISTINCT u.id, $names
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND u.id <> :me
                   AND (u.id IN (SELECT ra.userid
                                   FROM {role_assignments} ra
                                   JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :contextlevel)
                        OR u.id IN (SELECT b.managerid FROM {" . self::TABLE . "} b))
              ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC";
        $found = $DB->get_records_sql($sql, ['contextlevel' => CONTEXT_USER, 'me' => (int) $USER->id]);

        return array_values(array_filter(
            $found,
            fn($user) => self::stands((int) $user->id) || self::follows_anyone((int) $user->id)
        ));
    }

    /**
     * Whether the account follows at least one candidate, by the very rule the dossier pages enforce (access::follows()).
     *
     * Looked for where a follower's rights can come from: the role assignments in the user contexts of the candidates.
     *
     * @param int $managerid
     * @return bool
     */
    public static function follows_anyone(int $managerid): bool {
        global $DB;

        $sql = "SELECT DISTINCT ctx.instanceid
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ra.userid = :userid
                   AND ctx.contextlevel = :contextlevel";
        $ids = $DB->get_fieldset_sql($sql, ['userid' => $managerid, 'contextlevel' => CONTEXT_USER]);
        foreach ($ids as $candidateid) {
            if (access::follows((int) $candidateid, $managerid)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write a line and say so in the log.
     *
     * @param int $managerid
     * @param string $status
     * @return \stdClass
     */
    private static function write(int $managerid, string $status): \stdClass {
        global $DB, $USER;

        $row = (object) [
            'managerid' => $managerid,
            'grantedby' => (int) $USER->id,
            'status' => $status,
            'timecreated' => time(),
        ];
        $row->id = $DB->insert_record(self::TABLE, $row);

        booklet_authorization_changed::create([
            'context' => context_system::instance(),
            'objectid' => (int) $row->id,
            // The manager is the person the line is about: relateduserid, where the log's privacy provider finds them (T15, Y).
            'relateduserid' => $managerid,
            'other' => ['status' => $status],
        ])->trigger();

        return $row;
    }
}
