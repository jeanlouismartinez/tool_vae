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
 * What the screens that ask a manager to act on an attestation draw above their form.
 *
 * THE DECLARATION, AND BEFORE IT WHAT THE PLATFORM NO LONGER SAYS OF IT. The attestation screen is the only reading of the
 * narrative by someone who did not write it, and so the screen where the anchor of the declaration matters most: the
 * candidate can unlink the competency from tool_lp in two clicks, with nothing in this plugin involved, and the screen would
 * go on saying "Competency: ..." for a link the platform no longer holds. The warning is the dossier's own, drawn the same
 * way (anchor::divergence() and presenter::anchor_warning()) and BEFORE the declaration, for the reader who has already taken
 * the record at face value.
 *
 * It only says: the plugin prevents nothing (decision of 0.6.0). Whoever signs knows what they sign.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class attest_screen {
    /**
     * The declaration as the attestation and the withdrawal screens show it.
     *
     * @param \stdClass $evidence A row of tool_vae_evidence.
     * @param \context $context The candidate's user context, for format_text().
     * @param competency_gateway|null $gateway The reader of the core; the real one when null.
     * @return string HTML: the warning of the anchor when there is one, then the declaration.
     */
    public static function declaration(
        \stdClass $evidence,
        \context $context,
        ?competency_gateway $gateway = null
    ): string {
        return presenter::anchor_warning($evidence, anchor::divergence($evidence, $gateway))
            . presenter::declaration($evidence, $context);
    }
}
