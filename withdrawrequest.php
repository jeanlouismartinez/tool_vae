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

/**
 * Ask the signatory to withdraw an attestation, or refuse such a request.
 *
 * One page, two acts, both a POST with the session key: nothing here is reached by a link.
 * Accepting is not here - accepting is withdrawing, and withdraw.php asks the reason.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\attestation_manager;

require_login();
require_sesskey();

$attestationid = required_param('attestationid', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

// An id that is no attestation, a declaration that is gone and an act this page does not know are refused word for word as a
// request one may not make (attestation_manager::request_withdrawal() says the same), before the page is set up and with no
// link: any difference would tell anyone which ids are attestations (M-3, as attest.php, withdraw.php and piece.php do).
$attestation = $DB->get_record('tool_vae_attest', ['id' => $attestationid], '*', IGNORE_MISSING);
$evidence = $attestation
    ? $DB->get_record('tool_vae_evidence', ['id' => $attestation->evidenceid], '*', IGNORE_MISSING)
    : false;
if (!$attestation || !$evidence || !in_array($action, ['request', 'refuse'], true)) {
    throw new moodle_exception('errorrequestrefused', 'tool_vae');
}
$candidateid = (int) $evidence->candidateid;

$PAGE->set_url(new moodle_url('/admin/tool/vae/withdrawrequest.php', ['attestationid' => $attestationid]));
$PAGE->set_context(context_user::instance($candidateid));

$dossier = new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $candidateid]);

if ($action === 'request') {
    (new attestation_manager())->request_withdrawal($attestationid);
    redirect(
        $dossier,
        get_string('requestwithdrawalsent', 'tool_vae'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'refuse') {
    (new attestation_manager())->refuse_withdrawal($attestationid);
    redirect(
        $dossier,
        get_string('requestwithdrawalrefused', 'tool_vae'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

throw new moodle_exception('errorrequestrefused', 'tool_vae');
