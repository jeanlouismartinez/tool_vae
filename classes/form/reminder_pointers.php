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

namespace tool_vae\form;

/**
 * Keeps the anonymisation reminder attached to a field that comes back with an error (T-72).
 *
 * Found on a real Moodle 5.3 on 14 October 2026. The core text and textarea templates write
 * `aria-describedby="id_error_<field>"` for a field in error, BEFORE the attributes a form gives
 * the element. An element cannot carry the attribute twice: a browser keeps the first. So the
 * very moment a person has just been refused - the moment the reminder matters most - the field
 * was described by its error message alone and the reminder was no longer read with it. It stayed
 * on screen; a screen reader no longer announced it.
 *
 * The templates are the core's and are not ours to change. The reminder ids are therefore merged
 * into the attribute once the page is built, after whatever the core wrote, in that order: the
 * error is read first, then the reminder. Nothing is removed and the merge is idempotent. Without
 * JavaScript the field keeps the core's attribute and the reminder stays visible.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reminder_pointers {
    /**
     * The script, pure so it can be tested without a page.
     *
     * @param array $map Element id => list of ids the element must be described by.
     * @return string
     */
    public static function script(array $map): string {
        $json = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        return '(function(map){Object.keys(map).forEach(function(id){'
            . 'var el=document.getElementById(id);if(!el){return;}'
            . 'var ids=(el.getAttribute("aria-describedby")||"").split(/\s+/).filter(Boolean);'
            . 'map[id].forEach(function(t){if(ids.indexOf(t)<0&&document.getElementById(t)){ids.push(t);}});'
            . 'el.setAttribute("aria-describedby",ids.join(" "));'
            . '});})(' . $json . ');';
    }

    /**
     * Add the script to the current page, to run once the form is in the DOM.
     *
     * @param array $map Element id => list of ids the element must be described by.
     */
    public static function apply(array $map): void {
        global $PAGE;
        $PAGE->requires->js_init_code(self::script($map), true);
    }
}
