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

namespace tool_vae\admin;

use tool_vae\local\piece_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * A size in bytes the administrator sets for supporting pieces, refused when it is out of the bounds the code holds (T-13).
 *
 * Saying 100 000 000 and seeing it kept, while the code applies 30 MB, would be a setting that lies: the ceiling is what an
 * archive holds, so the screen refuses what the code would not apply.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_piecebytes extends \admin_setting_configtext {
    /**
     * A whole number of bytes.
     *
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param int $defaultsetting
     */
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_INT);
    }

    /**
     * Between 1 KB and the ceiling the code holds.
     *
     * @param string $data
     * @return bool|string True, or the message.
     */
    public function validate($data) {
        $valid = parent::validate($data);
        if ($valid !== true) {
            return $valid;
        }
        if ((int) $data < 1024 || (int) $data > piece_manager::CANDIDATE_CEILING) {
            return get_string('piecebytesrange', 'tool_vae', display_size(piece_manager::CANDIDATE_CEILING));
        }

        return true;
    }
}
