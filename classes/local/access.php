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
use context_user;

/**
 * Access checks, in one place.
 *
 * WHY OWNERSHIP IS PROVED IN PHP:
 * a capability declared at CONTEXT_USER with a default archetype is held in every other
 * user's context, because the user context is a direct child of the system context where
 * archetype roles are assigned. Relying on the capability alone to mean "own" therefore
 * grants everyone access to everyone. Core proves ownership with an explicit identity
 * comparison before widening the capability set, and so does this class.
 *
 * Every page that takes a userid from the request calls one of these methods first.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class access {
    /**
     * Require permission to read a candidate's dossier.
     *
     * @param int $candidateid
     * @return context_user The candidate's user context.
     */
    public static function require_can_view(int $candidateid): context_user {
        global $USER;

        // An id that is no account has no context, and can_view() is false for it: it is refused
        // below exactly as a dossier one may not read is, so the answer never tells which ids are
        // accounts.
        $context = context_user::instance($candidateid, IGNORE_MISSING);

        if ($context && $candidateid === (int) $USER->id) {
            require_capability('tool/vae:viewown', $context);
        } else if (!self::can_view($candidateid)) {
            // The exception require_capability() would have raised for viewany: a reader who
            // follows nobody gets exactly the refusal they got before lot 7 bis.
            throw new \required_capability_exception(
                context_system::instance(),
                'tool/vae:viewany',
                'nopermissions',
                ''
            );
        }

        return $context;
    }

    /**
     * Whether a reader may read a candidate's dossier.
     *
     * The same decision as require_can_view(), returned rather than enforced, for the pages
     * that only decide what to draw - the profile link above all.
     *
     * The reader is the current user unless one is named, as in follows(): can_attest() asks
     * this question about the manager a notification is meant for.
     *
     * @param int $candidateid
     * @param int|null $readerid The reader; the current user when null.
     * @return bool
     */
    public static function can_view(int $candidateid, ?int $readerid = null): bool {
        global $USER;

        $readerid = $readerid ?? (int) $USER->id;

        // An id that is no account - never one, or deleted along with its context - has no dossier,
        // and is answered as one the reader may not read. viewany alone would say yes to it.
        $context = context_user::instance($candidateid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        if ($candidateid === $readerid) {
            return has_capability('tool/vae:viewown', $context, $readerid);
        }

        // Someone else's dossier: site-wide reading, or a candidate this reader follows.
        return has_capability('tool/vae:viewany', context_system::instance(), $readerid)
            || self::follows($candidateid, $readerid);
    }

    /**
     * Whether a reader follows this candidate: holds viewassigned in the candidate's own user
     * context, through a role assigned there - by hand, or by tool_cohortroles for every
     * member of a cohort.
     *
     * The reader is the current user unless one is named. Naming one is how a notification
     * finds its recipients (lot 7 ter): the same question, asked about someone who is not the
     * person logged in. It is asked HERE, not rebuilt elsewhere, so that the guard below holds
     * for a message exactly as it holds for a page.
     *
     * WHY HOLDING IT AT SYSTEM LEVEL MEANS "NO", NOT "EVERYONE":
     * a user context is a direct child of the system context. A role carrying viewassigned
     * assigned at system level would be inherited by every user context on the site - the
     * reach of viewany under a name that promises a narrower one. Rather than trust the
     * administrator never to make that assignment, the capability is refused outright when it
     * is held at system level. Fail closed: the misconfigured reader sees nothing, and the
     * capability can only ever mean what it says. Reading everyone has its own capability.
     *
     * @param int $candidateid
     * @param int|null $readerid The reader; the current user when null.
     * @return bool
     */
    public static function follows(int $candidateid, ?int $readerid = null): bool {
        global $USER;

        $readerid = $readerid ?? (int) $USER->id;

        if ($candidateid === $readerid) {
            return false;
        }

        if (has_capability('tool/vae:viewassigned', context_system::instance(), $readerid)) {
            return false;
        }

        // Nobody follows an id that is no account; asked directly, as notifier does, it is a no.
        $context = context_user::instance($candidateid, IGNORE_MISSING);

        return $context && has_capability('tool/vae:viewassigned', $context, $readerid);
    }

    /**
     * The people who follow this candidate, as user ids.
     *
     * The mirror of followed_candidates(), for the one caller that starts from the candidate:
     * a declaration has just been made, and the question is who should hear of it. Readers are
     * looked for where a follower's rights can come from - role assignments in the candidate's
     * own user context - and each one is put through follows(), so that a role without the
     * capability, a prohibition, or the system-level guard all count exactly as they do on the
     * dossier page.
     *
     * A HOLDER OF viewany IS NOT A FOLLOWER, and is not found here. Reading every dossier is the
     * exception kept for management and audit; being told of every declaration on the site
     * would turn that exception into a mailing list.
     *
     * @param int $candidateid
     * @return int[]
     */
    public static function followers(int $candidateid): array {
        global $DB;

        $sql = "SELECT DISTINCT ra.userid
                  FROM {role_assignments} ra
                 WHERE ra.contextid = :contextid";
        $ids = $DB->get_fieldset_sql($sql, ['contextid' => context_user::instance($candidateid)->id]);

        $followers = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (self::follows($candidateid, $id)) {
                $followers[] = $id;
            }
        }
        sort($followers);

        return $followers;
    }

    /**
     * The candidates the current user follows, as user ids.
     *
     * Candidates are found where a follower's rights can come from: role assignments held by
     * the current user in user contexts. Each one is then put through follows(), so that a
     * role without the capability, a prohibition, or the system-level guard all count.
     *
     * @return int[]
     */
    public static function followed_candidates(): array {
        global $DB, $USER;

        if (has_capability('tool/vae:viewassigned', context_system::instance())) {
            return [];
        }

        $sql = "SELECT DISTINCT ctx.instanceid
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ra.userid = :userid
                   AND ctx.contextlevel = :contextlevel";
        $ids = $DB->get_fieldset_sql($sql, ['userid' => $USER->id, 'contextlevel' => CONTEXT_USER]);

        $followed = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (self::follows($id)) {
                $followed[] = $id;
            }
        }
        sort($followed);

        return $followed;
    }

    /**
     * Require permission to declare an activity on one's own behalf.
     *
     * Declaring is always first person: the candidate holds
     * moodle/competency:userevidencemanageown natively on their own context, which is what
     * lets the core API accept the write. Declaring for a third party would require
     * moodle/competency:userevidencemanage in that person's user context, which is not a
     * right this plugin asks anyone to hand out.
     *
     * @param int $candidateid
     * @return context_user
     */
    public static function require_can_declare(int $candidateid): context_user {
        global $USER;

        if ($candidateid !== (int) $USER->id) {
            throw new \moodle_exception('errordeclareforothers', 'tool_vae');
        }

        $context = context_user::instance($candidateid);
        require_capability('tool/vae:declare', $context);

        return $context;
    }

    /**
     * Whether a reader may attest a declaration belonging to a candidate.
     *
     * Returned rather than enforced: the caller logs the refusal before throwing, which
     * require_capability() makes impossible since it throws itself. Acceptance criterion 2.
     *
     * WHY THE CAPABILITY ALONE IS NOT ENOUGH: the trap follows() guards against, met again. A
     * role carrying attest assigned at system level is inherited by every user context on the
     * site, and attest.php shows the narrative and the signature history of whatever claim its
     * URL names. Attesting therefore first requires reading the dossier, by the very rule of
     * can_view() - viewany, or following the candidate. Nobody signs what they may not read.
     * Whether the reader is the candidate is left to the callers, which refuse it with
     * errorattestown.
     *
     * @param int $candidateid
     * @param int|null $readerid The reader; the current user when null.
     * @return bool
     */
    public static function can_attest(int $candidateid, ?int $readerid = null): bool {
        return self::can_view($candidateid, $readerid)
            && has_capability('tool/vae:attest', context_user::instance($candidateid), $readerid);
    }

    /**
     * Whether the current user may export a candidate's dossier as the normative archive.
     *
     * Narrower than reading, on purpose: the archive leaves the platform and cannot be called
     * back. The candidate exports their own dossier, for the panel; the direction, which reads
     * every dossier, may export any of them. A manager who follows a candidate reads the dossier
     * but does not export it, whatever capability they were handed in that context.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_export(int $candidateid): bool {
        global $USER;

        // As in can_view(): no account, no archive, whoever asks.
        $context = context_user::instance($candidateid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }

        if ($candidateid === (int) $USER->id) {
            return has_capability('tool/vae:viewown', $context)
                && has_capability('tool/vae:export', $context);
        }

        $system = context_system::instance();

        return has_capability('tool/vae:viewany', $system)
            && has_capability('tool/vae:export', $system);
    }

    /**
     * Require permission to export a candidate's dossier.
     *
     * @param int $candidateid
     * @return context_user The candidate's user context, where the archive is stored.
     */
    public static function require_can_export(int $candidateid): context_user {
        // The refusal names the system context, never the candidate's: an id that is no account
        // has none, and a refusal pointing at the account would tell which ids are accounts.
        if (!self::can_export($candidateid)) {
            throw new \required_capability_exception(
                context_system::instance(),
                'tool/vae:export',
                'nopermissions',
                ''
            );
        }

        return context_user::instance($candidateid);
    }
}
