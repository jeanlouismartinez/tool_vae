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
 * Save the draft in progress of a section (T-81, lot 3).
 *
 * Called by the script of section.php. It writes the draft in progress and nothing else: a
 * version is only written by the form's button. POST and session key only.
 *
 * The answer is JSON, and every refusal is one too: the script on the page reads it and says it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

use tool_vae\local\draft_manager;

require_login(null, false, null, false, true);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => get_string('errordraftrefused', 'tool_vae')]);
    die();
}
require_sesskey();

$draftid = required_param('draftid', PARAM_INT);
$text = required_param('text', PARAM_RAW);
// What the page knew of the draft in progress: 0 when it was opened with none, and 0 is compared like any other time, so that a
// draft another tab has written since is not overwritten (draft_manager::autosave()).
$known = optional_param('workingat', 0, PARAM_INT);

try {
    $draft = (new draft_manager())->autosave($draftid, $text, $known);
    if ($draft->workingat === null) {
        // The text is the one of the latest version: no draft is kept, and the page is told so rather than a time.
        echo json_encode(['workingat' => 0, 'message' => get_string('draftautosavedsame', 'tool_vae')]);
    } else {
        echo json_encode([
            'workingat' => (int) $draft->workingat,
            'message' => get_string('draftautosaved', 'tool_vae', userdate((int) $draft->workingat)),
        ]);
    }
} catch (moodle_exception $e) {
    http_response_code($e->errorcode === 'errordraftconflict' ? 409 : 403);
    echo json_encode(['error' => $e->getMessage()]);
}
