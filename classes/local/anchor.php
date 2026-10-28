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
 * The state of the core record a claim hangs off.
 *
 * WHAT THIS IS FOR. A claim of this plugin is anchored to a piece of evidence in the competency
 * subsystem. The candidate owns that record and can change it from tool_lp, with a capability
 * this plugin neither grants nor can revoke: they can rename it, and they can unlink it from the
 * competency it stood for. Measured on a running site on 28 August 2026 - both are two clicks
 * away, and neither is visible from here.
 *
 * The consequence is not a leak, it is a LIE. The dossier goes on stating "RNCP36832-C03" while
 * the platform no longer attests any link, and a panel reading the dossier has no way to know.
 *
 * WHAT THIS CLASS DOES NOT DO. It does not prevent anything. Those are the candidate's own data
 * in the candidate's own tool, and a plugin that fought the core for control of them would be
 * wrong twice over - once on the principle, once because an observer cannot refuse cleanly
 * anyway. It states what it finds, and lets the dossier say so.
 *
 * WHY IT COMPUTES RATHER THAN STORES. A flag written on divergence only catches what happened
 * after the flag existed, and only through the one door that raises an event - unlinking raises
 * none at all. Reading the core each time is one query per claim on a page that shows a handful,
 * and it is right about divergences that predate this code.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class anchor {
    /** @var string The core record is gone entirely. */
    public const MISSING = 'missing';

    /** @var string The label no longer matches what this plugin would generate. */
    public const RENAMED = 'renamed';

    /** @var string The link to the competency the claim names is gone, or points elsewhere. */
    public const UNLINKED = 'unlinked';

    /**
     * What has happened to the anchor of one claim, if anything.
     *
     * ORDER MATTERS, AND IT IS FROM THE GRAVEST DOWN. Renaming and unlinking are independent -
     * two gestures in one tool_lp session - and reporting the rename would replace "the
     * platform no longer attests that link" with a reassuring note about a label. Measured in
     * adversarial review: a claim both renamed and unlinked reported only the rename.
     *
     * SILENCE IS NOT INNOCENCE, AND IT IS NOT GUILT EITHER. A failure to read the core - a
     * timeout, a broken connection - returns null here, the same as "nothing has changed". It
     * is deliberate: the alternative is to tell a candidate in front of a panel that their
     * evidence has vanished because a query timed out. A detector that cannot read says
     * nothing, and leaves a debugging() line for whoever is looking.
     *
     * @param \stdClass $record A row of tool_vae_evidence.
     * @param competency_gateway|null $gateway
     * @return string|null One of the constants above, or null when the anchor still says what
     *                     this plugin wrote - or when this plugin could not find out.
     */
    public static function divergence(\stdClass $record, ?competency_gateway $gateway = null): ?string {
        $gateway = $gateway ?? new competency_gateway();

        try {
            $core = $gateway->read_anchor((int) $record->userevidenceid);
        } catch (\Throwable $e) {
            debugging(
                'tool_vae: could not read the anchor of claim ' . (int) $record->id
                    . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );

            return null;
        }

        if ($core === null) {
            // Not the same thing as a purge: the observer destroys the claim when the core
            // record is deleted through an event. A record missing WITHOUT the claim having
            // been purged means the deletion happened by a route that raised nothing - a bulk
            // delete, a restore, a hand-written query.
            return self::MISSING;
        }

        // THE COMPETENCY THIS CLAIM NAMES, not merely "some link". Asking whether any link
        // survives lets the candidate swap C03 for C07 in two clicks while the dossier goes
        // on naming C03 - measured in adversarial review, and the exact lie this class exists
        // to prevent. A row created before competencyid existed cannot be judged this way.
        $stillnamed = $record->competencyid === null
            || in_array((int) $record->competencyid, $core->competencyids, true);

        if (!$stillnamed) {
            return self::UNLINKED;
        }

        // Against the string STORED at creation, never a rebuilt one - see
        // evidence_manager::label_for(). A row created before that field existed has nothing
        // to compare against, and this says nothing rather than guessing.
        if ($record->corelabel !== null && $core->name !== $record->corelabel) {
            return self::RENAMED;
        }

        return null;
    }
}
