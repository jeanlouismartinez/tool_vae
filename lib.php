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
 * Plugin callbacks.
 *
 * Callbacks are discovered by get_plugins_with_function(), which only reads lib.php and
 * caches the result under $CFG->allversionshash. Adding a callback without bumping
 * version.php or purging caches leaves it silently uncalled.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_user\output\myprofile\tree;
use tool_vae\local\access;
use tool_vae\local\competency_gateway;
use tool_vae\local\exporter;
use tool_vae\local\piece_manager;

/**
 * Serve an exported archive (lot 8, MC-9), or a supporting piece (T-13).
 *
 * The same shape as the core's own GDPR export in tool_dataprivacy: a user context, a login, a
 * check, an expiry. Here the check is exporter::may_download() - the archive's author only,
 * within its day, and only while they may still export that dossier. A supporting piece is served by
 * piece_manager::open(): whoever may open it, from the row of the piece, as a download.
 *
 * @param stdClass $course
 * @param stdClass|null $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool False when the request is not for this plugin's archives or pieces.
 */
function tool_vae_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    // A supporting piece (T-13): decided from its row, never from the URL alone, always downloaded, never shown in a page.
    if ((int) $context->contextlevel === CONTEXT_USER && $filearea === piece_manager::FILEAREA) {
        require_login(null, false);

        $file = piece_manager::open((int) array_shift($args), (int) $context->id);
        if (!$file) {
            send_file_not_found();
        }

        // Never the preview of the core: it turns the forced download off and makes the server decode the image.
        unset($options['preview']);
        send_stored_file($file, 0, 0, true, $options);
    }

    if ((int) $context->contextlevel !== CONTEXT_USER || $filearea !== exporter::FILEAREA) {
        return false;
    }

    require_login(null, false);

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $file = get_file_storage()->get_file($context->id, 'tool_vae', $filearea, $itemid, $filepath, $filename);
    if (!$file || !exporter::may_download($file, time())) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Add the plugin's links to a profile page: the candidate's dossier, and, on one's own
 * profile, the candidates one follows.
 *
 * The dossier node is only offered when the viewer may actually read the dossier, decided by
 * the very method the pages enforce - access::can_view(). Offering a link the viewer cannot
 * follow is a smaller fault than granting access, but it is still a fault.
 *
 * @param tree $tree
 * @param stdClass $user The user whose profile is displayed.
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 * @return bool True when a node was added.
 */
function tool_vae_myprofile_navigation(tree $tree, $user, $iscurrentuser, $course) {
    if (!competency_gateway::is_subsystem_enabled()) {
        return false;
    }

    $added = false;

    if (access::can_view((int) $user->id)) {
        // The node name must be unique across the whole tree, not just within this plugin.
        $tree->add_node(new \core_user\output\myprofile\node(
            'miscellaneous',
            'toolvaedossier',
            get_string('mydossier', 'tool_vae'),
            null,
            new moodle_url('/admin/tool/vae/dossier.php', ['userid' => $user->id])
        ));
        $added = true;
    }

    if ($iscurrentuser && access::followed_candidates()) {
        $tree->add_node(new \core_user\output\myprofile\node(
            'miscellaneous',
            'toolvaecandidates',
            get_string('mycandidates', 'tool_vae'),
            null,
            new moodle_url('/admin/tool/vae/candidates.php')
        ));
        $added = true;
    }

    return $added;
}

/**
 * Add the link to attach a certification record to a course (lot 8 bis).
 *
 * Offered only to whoever course_linker::can_link() lets through - the page asks the same.
 *
 * @param navigation_node $navigation The course's settings node.
 * @param stdClass $course
 * @param context_course $context
 */
function tool_vae_extend_navigation_course($navigation, $course, $context) {
    if (!competency_gateway::is_subsystem_enabled() || !\tool_vae\local\course_linker::can_link($context)) {
        return;
    }

    $navigation->add(
        get_string('linkrecord', 'tool_vae'),
        new moodle_url('/admin/tool/vae/course_link.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'toolvaelinkrecord',
        new pix_icon('i/competencies', '')
    );
}
