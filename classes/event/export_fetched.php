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

namespace tool_vae\event;

use tool_vae\local\fiche_fetcher;
use tool_vae\local\fiche_refusal;

/**
 * An administrator had the server fetch the latest export of France compétences from data.gouv.fr (0.47.0).
 *
 * The trace of the one request the plugin makes of its own, for each click that went out, whatever came of it: the resource
 * taken, its size and digest, and the outcome - "ok", or the reason of the refusal. It carries no file name but the title of
 * the export (a name of the dataset, checked), no text of an exception, no header, no address, and nothing about a person.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_fetched extends \core\event\base {
    /**
     * Initialise the event.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Human readable name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventexportfetched', 'tool_vae');
    }

    /**
     * Description for the log report. An outcome and a size only: this string is stored.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' had the server fetch the latest export of France competences from "
            . "data.gouv.fr: outcome '{$this->other['outcome']}', {$this->other['bytes']} byte(s).";
    }

    /**
     * Validate the data given to create().
     */
    protected function validate_data() {
        parent::validate_data();

        $other = $this->other;
        // What is stored is an identifier of a resource, the title of an export, a digest, a count and an outcome: nothing else.
        $forms = [
            'resourceid' => '/^(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})?$/D',
            'sha256' => '/^(?:[0-9a-f]{64})?$/D',
        ];
        foreach ($forms as $key => $pattern) {
            if (!isset($other[$key]) || !is_string($other[$key]) || !preg_match($pattern, $other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other, empty or in the form expected.");
            }
        }
        $title = $other['title'] ?? null;
        if (!is_string($title) || ($title !== '' && fiche_fetcher::parse_title($title) === null)) {
            throw new \coding_exception("The 'title' value must be set in other, empty or the title of an export.");
        }
        if (!isset($other['bytes']) || !is_int($other['bytes']) || $other['bytes'] < 0) {
            throw new \coding_exception("The 'bytes' value must be set in other, as an integer.");
        }
        if (!isset($other['outcome']) || !is_string($other['outcome']) || !self::is_outcome($other['outcome'])) {
            throw new \coding_exception("The 'outcome' value must be set in other, as 'ok' or the reason of a refusal.");
        }
        if (!isset($other['status']) || !is_int($other['status'])) {
            throw new \coding_exception("The 'status' value must be set in other, as an integer.");
        }
    }

    /**
     * Whether a value is an outcome: "ok", or the reason of a refusal.
     *
     * @param string $value
     * @return bool
     */
    private static function is_outcome(string $value): bool {
        return $value === 'ok' || in_array($value, (new \ReflectionClass(fiche_refusal::class))->getConstants(), true);
    }

    /**
     * Map the object id for restore from a backup. Not restorable.
     *
     * @return bool
     */
    public static function get_objectid_mapping() {
        return false;
    }
}
