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
use tool_vae\event\structure_archive_issued;

/**
 * The archive of the accompanying structure: the positionings of the structure about one vaeiste, in a document of their own
 * (decision of the project owner, 7 November 2026).
 *
 * THE TWO ARCHIVES OF A VAEISTE. The archive of the vaeiste carries what the vaeiste wrote and was attested; it never carries a
 * positioning of the structure. The archive of the structure carries the positionings and nothing of the vaeiste's dossiers: it is
 * the livret of the site that qualifies or of the institution that accompanies, which the vaeiste may attach as an annex, as a
 * proof, to the validation dossier. The two are never mixed. Same model, both: a zip with a manifest and the digest of each file,
 * a printable PDF, an identifier and a digest in the register of the archives (the platform confirms a copy a panel holds).
 *
 * ONE PER VAEISTE. A new archive of the structure replaces the one in place, once it is written and registered: a failure keeps the
 * old one. The register keeps the line of the replaced archive, so that a panel which holds a copy of it can still have it
 * confirmed. The file is kept as long as the vaeiste's account exists (the rule of every duration of the plugin) and goes with it.
 *
 * Who produces it: the direction (tool/vae:issuestructure with tool/vae:viewany), or a manager the direction authorized
 * (booklet_authorization) for the candidates they follow. Nobody produces their own.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class structure_archive {
    /** @var string The file area, in the candidate's user context. */
    public const FILEAREA = 'structure';

    /** @var string The format written in the manifest: not the one of a dossier, so that a reimport never takes it for one. */
    public const FORMAT = 'tool_vae/structure';

    /** @var string The version of that format. */
    public const FORMAT_VERSION = '1';

    /** @var string The printable copy, in the archive. */
    public const PDF_PATH = 'livret-structure.pdf';

    /**
     * Whether the current user may produce the archive of the structure of this candidate.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_issue(int $candidateid): bool {
        global $USER;

        if ($candidateid === (int) $USER->id || !context_user::instance($candidateid, IGNORE_MISSING)) {
            return false;
        }
        if (booklet_authorization::can_decide()) {
            return true;
        }

        return booklet_authorization::stands((int) $USER->id) && access::follows($candidateid);
    }

    /**
     * Whether the current user may download the archive of the structure of this candidate: the vaeiste themself, and whoever
     * may produce it.
     *
     * @param int $candidateid
     * @return bool
     */
    public static function can_read(int $candidateid): bool {
        global $USER;

        $context = context_user::instance($candidateid, IGNORE_MISSING);
        if (!$context) {
            return false;
        }
        if ($candidateid === (int) $USER->id) {
            return has_capability('tool/vae:viewown', $context);
        }

        return self::can_issue($candidateid);
    }

    /**
     * Whether the file is an archive of the structure the current user may take away.
     *
     * @param \stored_file $file
     * @return bool
     */
    public static function may_download(\stored_file $file): bool {
        if (
            $file->get_component() !== 'tool_vae'
            || $file->get_filearea() !== self::FILEAREA
            || $file->is_directory()
        ) {
            return false;
        }
        $context = \context::instance_by_id($file->get_contextid(), IGNORE_MISSING);

        return $context && (int) $context->contextlevel === CONTEXT_USER && self::can_read((int) $context->instanceid);
    }

    /**
     * The archive of the structure in place for a candidate.
     *
     * @param int $candidateid
     * @return \stored_file|null
     */
    public static function current(int $candidateid): ?\stored_file {
        $context = context_user::instance($candidateid, IGNORE_MISSING);
        if (!$context) {
            return null;
        }
        $files = get_file_storage()->get_area_files($context->id, 'tool_vae', self::FILEAREA, 0, 'id DESC', false);

        return $files ? reset($files) : null;
    }

    /**
     * Delete the archive of the structure of a candidate: the dossier is erased, and the archive goes with it.
     *
     * @param int $candidateid
     */
    public static function discard(int $candidateid): void {
        $context = context_user::instance($candidateid, IGNORE_MISSING);
        if ($context) {
            get_file_storage()->delete_area_files($context->id, 'tool_vae', self::FILEAREA);
        }
    }

    /**
     * Produce the archive of the structure of a candidate and put it in place of the previous one.
     *
     * @param int $candidateid
     * @return \stored_file
     * @throws \moodle_exception errorstructuredenied, or errorstructureempty when no positioning was ever written.
     */
    public static function issue(int $candidateid): \stored_file {
        global $CFG, $DB, $SITE, $USER;

        if (!self::can_issue($candidateid)) {
            throw new \moodle_exception('errorstructuredenied', 'tool_vae');
        }
        $candidate = \core_user::get_user($candidateid, '*', MUST_EXIST);
        $positioning = positioning_archive::document($candidateid);
        if ($positioning === null) {
            throw new \moodle_exception('errorstructureempty', 'tool_vae');
        }

        $archiveid = identity_map::generate_ulid();
        $now = time();
        $personne = identity_map::uuid_for(identity_map::TYPE_USER, $candidateid);

        // The archive is read in the country of the candidate, as the archive of the dossier is: their language, their
        // timezone, given back when the files are written.
        $lang = (string) $candidate->lang;
        $previous = force_current_language(
            get_string_manager()->translation_exists($lang, false) ? $lang : $CFG->lang
        );
        $previoustimezone = display_date::force_timezone(\core_date::get_user_timezone($candidate));
        try {
            $notice = get_string('structurenotice', 'tool_vae');
            $head = [
                'archive' => $archiveid,
                'exporte_le' => exporter::iso($now),
                'produit_par' => [
                    'systeme' => exporter::SYSTEM,
                    'version' => $CFG->release ?? '',
                    'composant' => 'tool_vae',
                    'version_composant' => \core_plugin_manager::instance()->get_plugin_info('tool_vae')->release,
                ],
                'candidat' => ['identifiant' => $personne, 'nom' => fullname($candidate)],
                'avertissement' => $notice,
            ];
            $files = [
                positioning_archive::PATH => exporter::encode($positioning),
                'schema/positionnement.schema.json' => exporter::read_schema('positionnement.schema.json'),
                'LISEZMOI.txt' => implode("\n\n", [
                    get_string('structurereadme', 'tool_vae', (object) [
                        'name' => fullname($candidate),
                        'date' => display_date::format($now, get_string('strftimedatetime', 'langconfig')),
                    ]),
                    $notice,
                    get_string('exportreadmeverify', 'tool_vae', $archiveid),
                ]) . "\n",
            ];
            $head['type'] = 'structure';
            $files[self::PDF_PATH] = pdf_renderer::render($head, [], ['fiches' => []], [], [], [], [], $positioning);
        } finally {
            display_date::force_timezone($previoustimezone);
            force_current_language($previous);
        }

        $digests = [];
        foreach ($files as $path => $content) {
            $digests[] = ['chemin' => $path, 'octets' => strlen($content), 'sha256' => hash('sha256', $content)];
        }
        unset($head['type']);
        $manifest = [
            'format' => self::FORMAT,
            'version_format' => self::FORMAT_VERSION,
            'schema_positionnement' => positioning_archive::SCHEMA_VERSION,
        ] + $head + ['fichiers' => $digests];
        $files = ['manifeste.json' => exporter::encode($manifest)] + $files;
        $sha256 = hash('sha256', $files['manifeste.json']);

        $zip = [];
        foreach ($files as $path => $content) {
            $zip[$path] = [$content];
        }

        // Registered BEFORE the file is written, as the archive of the dossier is: a failure in between leaves a row that
        // vouches for an archive nobody holds, which nothing can be done with.
        $DB->insert_record(exporter::REGISTER, (object) [
            'archiveuuid' => $archiveid,
            'sha256' => $sha256,
            'candidateuuid' => $personne,
            'userid' => (int) $USER->id,
            'timecreated' => $now,
        ]);

        $context = context_user::instance($candidateid);
        $file = get_file_packer('application/zip')->archive_to_storage(
            $zip,
            $context->id,
            'tool_vae',
            self::FILEAREA,
            0,
            '/',
            'structure-' . $archiveid . '.zip',
            (int) $USER->id,
            false
        );
        if (!$file) {
            throw new \moodle_exception('errorexportfailed', 'tool_vae');
        }

        // The new one is in place: only now the previous one goes. A failure above keeps it.
        foreach (get_file_storage()->get_area_files($context->id, 'tool_vae', self::FILEAREA, 0, 'id', false) as $old) {
            if ((int) $old->get_id() < (int) $file->get_id()) {
                $old->delete();
            }
        }

        structure_archive_issued::create([
            'context' => $context,
            'objectid' => (int) $file->get_id(),
            'relateduserid' => $candidateid,
            'other' => ['sha256' => $sha256],
        ])->trigger();

        return $file;
    }
}
