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

namespace tool_vae\privacy;

use context;
use context_system;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tool_vae\local\backup;
use tool_vae\local\competency_gateway;
use tool_vae\local\document_copies;
use tool_vae\local\exporter;
use tool_vae\local\purge;
use tool_vae\notifier;

/**
 * Privacy implementation.
 *
 * WRITTEN WITH THE TABLES, NOT AFTER THEM. Lot 0 shipped a null_provider, which was exactly
 * true then and would have become a lie the moment db/install.xml appeared: the site would
 * have answered a subject access request with "this plugin holds nothing about you" while
 * holding a candidate's whole dossier. Separating the schema from the declaration of what
 * it holds is how a null_provider survives past its truth. `make doctor` refuses that state.
 *
 * TWO PEOPLE ARE INVOLVED IN EVERY ATTESTATION - the candidate and the manager who
 * attests - and their rights point in opposite directions:
 *
 *   - erasing the CANDIDATE removes the dossier, which is theirs;
 *   - erasing the MANAGER must NOT remove it. Their identity is stripped and the
 *     attestation is marked withdrawn and dated, so the candidate keeps a piece of evidence
 *     that says "attested, then withdrawn on such a date" instead of losing it.
 *
 * Stripping the manager means four fields, not one. attestantrole ("Nursing manager, Les
 * Tilleuls care home") and statement ("I, the undersigned Claire Dupont...") name the
 * person as surely as their user id, and attestedat dates a signature that is still theirs.
 * Nulling only attestantid produces a record that believes itself anonymised and spells the
 * person's name out in full. The pivot schema refuses that shape too, and its counter-
 * examples prove it.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe every piece of personal data this plugin holds.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table('tool_vae_evidence', [
            'uuid'            => 'privacy:metadata:evidence:uuid',
            'corelabel'       => 'privacy:metadata:evidence:corelabel',
            'competencyid'    => 'privacy:metadata:evidence:competencyid',
            'candidateid'     => 'privacy:metadata:evidence:candidateid',
            'userevidenceid'  => 'privacy:metadata:evidence:userevidenceid',
            'periodstart'     => 'privacy:metadata:evidence:periodstart',
            'periodend'       => 'privacy:metadata:evidence:periodend',
            'settingtype'     => 'privacy:metadata:evidence:settingtype',
            'settingdetail'   => 'privacy:metadata:evidence:settingdetail',
            'situation'       => 'privacy:metadata:evidence:situation',
            'fichecode'       => 'privacy:metadata:evidence:fichecode',
            'ficherang'       => 'privacy:metadata:evidence:ficherang',
            'ficheupdated'    => 'privacy:metadata:evidence:ficheupdated',
            'timecreated'     => 'privacy:metadata:evidence:timecreated',
            'timemodified'    => 'privacy:metadata:evidence:timemodified',
            'usermodified'    => 'privacy:metadata:evidence:usermodified',
        ], 'privacy:metadata:evidence');

        $collection->add_database_table('tool_vae_attest', [
            'uuid'            => 'privacy:metadata:attest:uuid',
            'evidenceid'      => 'privacy:metadata:attest:evidenceid',
            'attestantid'     => 'privacy:metadata:attest:attestantid',
            'attestantrole'   => 'privacy:metadata:attest:attestantrole',
            'statement'       => 'privacy:metadata:attest:statement',
            'attestedat'      => 'privacy:metadata:attest:attestedat',
            'withdrawnat'     => 'privacy:metadata:attest:withdrawnat',
            'withdrawnreason' => 'privacy:metadata:attest:withdrawnreason',
            'withdrawreqby'   => 'privacy:metadata:attest:withdrawreqby',
            'withdrawreqat'   => 'privacy:metadata:attest:withdrawreqat',
            'attestantuuid'   => 'privacy:metadata:attest:attestantuuid',
            'importedat'      => 'privacy:metadata:attest:importedat',
            'piecesread'      => 'privacy:metadata:attest:piecesread',
            'timecreated'     => 'privacy:metadata:attest:timecreated',
            'timemodified'    => 'privacy:metadata:attest:timemodified',
            'usermodified'    => 'privacy:metadata:attest:usermodified',
        ], 'privacy:metadata:attest');

        $collection->add_database_table('tool_vae_identity', [
            'itemtype'        => 'privacy:metadata:identity:itemtype',
            'itemid'          => 'privacy:metadata:identity:itemid',
            'uuid'            => 'privacy:metadata:identity:uuid',
            'timecreated'     => 'privacy:metadata:identity:timecreated',
        ], 'privacy:metadata:identity');

        $collection->add_database_table('tool_vae_source', [
            'usermodified'    => 'privacy:metadata:source:usermodified',
            'timemodified'    => 'privacy:metadata:source:timemodified',
        ], 'privacy:metadata:source');

        // T-23: who is the referent of a bloc, a rule of the site: the staff member and who named them.
        $collection->add_database_table('tool_vae_referent', [
            'userid'          => 'privacy:metadata:referent:userid',
            'fichecode'       => 'privacy:metadata:referent:fichecode',
            'ficherang'       => 'privacy:metadata:referent:ficherang',
            'timecreated'     => 'privacy:metadata:referent:timecreated',
            'createdby'       => 'privacy:metadata:referent:createdby',
        ], 'privacy:metadata:referent');

        $collection->add_database_table('tool_vae_passage', [
            'decidedby'       => 'privacy:metadata:passage:decidedby',
            'decidedat'       => 'privacy:metadata:passage:decidedat',
            'note'            => 'privacy:metadata:passage:note',
        ], 'privacy:metadata:passage');

        // T-13: the supporting pieces of a declaration. The files are in the plugin's own area, in the candidate's context.
        $collection->add_database_table('tool_vae_piece', [
            'candidateid' => 'privacy:metadata:piece:candidateid',
            'evidenceid'  => 'privacy:metadata:piece:evidenceid',
            'kind'        => 'privacy:metadata:piece:kind',
            'ext'         => 'privacy:metadata:piece:ext',
            'usercreated' => 'privacy:metadata:piece:usercreated',
            'removedby'   => 'privacy:metadata:piece:removedby',
            'nature'      => 'privacy:metadata:piece:nature',
            'title'       => 'privacy:metadata:piece:title',
            'mimetype'    => 'privacy:metadata:piece:mimetype',
            'filesize'    => 'privacy:metadata:piece:filesize',
            'sha256'      => 'privacy:metadata:piece:sha256',
            'url'         => 'privacy:metadata:piece:url',
            'timecreated' => 'privacy:metadata:piece:timecreated',
            'timeremoved' => 'privacy:metadata:piece:timeremoved',
        ], 'privacy:metadata:piece');
        // T-13, lot 2: what an attestation covered, and who opened a piece.
        $collection->add_database_table('tool_vae_attest_piece', [
            'attestid' => 'privacy:metadata:attestpiece:attestid',
            'pieceid'  => 'privacy:metadata:attestpiece:pieceid',
            'sha256'   => 'privacy:metadata:attestpiece:sha256',
        ], 'privacy:metadata:attestpiece');
        $collection->add_database_table('tool_vae_piece_open', [
            'pieceid'    => 'privacy:metadata:pieceopen:pieceid',
            'userid'     => 'privacy:metadata:pieceopen:userid',
            'timeopened' => 'privacy:metadata:pieceopen:timeopened',
        ], 'privacy:metadata:pieceopen');

        // T-30, lot 3: a declaration a candidate carried over to the record that replaces its own, with a copy of
        // the administrator's decision.
        $collection->add_database_table('tool_vae_carry', [
            'oldevidenceid'    => 'privacy:metadata:carry:oldevidenceid',
            'newevidenceid'    => 'privacy:metadata:carry:newevidenceid',
            'fromcompetencyid' => 'privacy:metadata:carry:fromcompetencyid',
            'tocompetencyid'   => 'privacy:metadata:carry:tocompetencyid',
            'decidedby'        => 'privacy:metadata:carry:decidedby',
            'decidedat'        => 'privacy:metadata:carry:decidedat',
            'note'             => 'privacy:metadata:carry:note',
            'carriedat'        => 'privacy:metadata:carry:carriedat',
        ], 'privacy:metadata:carry');

        // Lot 8 quater: the register of the archives issued. Two people in each row, like an
        // attestation: the candidate, by the ULID the archive gives them, and whoever produced it.
        // The row is not the archive (MC-9) - a digest and two identifiers, no claim.
        // The name is spelt out, not exporter::REGISTER: scripts/verifier-privacy.php reads it literally.
        $collection->add_database_table('tool_vae_archive', [
            'archiveuuid'     => 'privacy:metadata:archive:archiveuuid',
            'sha256'          => 'privacy:metadata:archive:sha256',
            'candidateuuid'   => 'privacy:metadata:archive:candidateuuid',
            'userid'          => 'privacy:metadata:archive:userid',
            'timecreated'     => 'privacy:metadata:archive:timecreated',
        ], 'privacy:metadata:archive');

        // T-81: the sections of a document a candidate writes, and their versions. The name is
        // spelt out for scripts/verifier-privacy.php, which reads it literally.
        $collection->add_database_table('tool_vae_draft', [
            'uuid'            => 'privacy:metadata:draft:uuid',
            'candidateid'     => 'privacy:metadata:draft:candidateid',
            'doctype'         => 'privacy:metadata:draft:doctype',
            'section'         => 'privacy:metadata:draft:section',
            'slot'            => 'privacy:metadata:draft:slot',
            'fichecode'       => 'privacy:metadata:draft:fichecode',
            'working'         => 'privacy:metadata:draft:working',
            'workingat'       => 'privacy:metadata:draft:workingat',
            'timecreated'     => 'privacy:metadata:draft:timecreated',
            'timemodified'    => 'privacy:metadata:draft:timemodified',
            'usermodified'    => 'privacy:metadata:draft:usermodified',
        ], 'privacy:metadata:draft');

        $collection->add_database_table('tool_vae_version', [
            'uuid'            => 'privacy:metadata:version:uuid',
            'draftid'         => 'privacy:metadata:version:draftid',
            'number'          => 'privacy:metadata:version:number',
            'content'         => 'privacy:metadata:version:content',
            'contenthash'     => 'privacy:metadata:version:contenthash',
            'authorid'        => 'privacy:metadata:version:authorid',
            'note'            => 'privacy:metadata:version:note',
            'restoredfrom'    => 'privacy:metadata:version:restoredfrom',
            'timecreated'     => 'privacy:metadata:version:timecreated',
        ], 'privacy:metadata:version');

        // T-21: the deposits of the dossier de faisabilite, the candidate's alone.
        $collection->add_database_table('tool_vae_deposit', [
            'uuid'            => 'privacy:metadata:deposit:uuid',
            'candidateid'     => 'privacy:metadata:deposit:candidateid',
            'doctype'         => 'privacy:metadata:deposit:doctype',
            'number'          => 'privacy:metadata:deposit:number',
            'content'         => 'privacy:metadata:deposit:content',
            'contenthash'     => 'privacy:metadata:deposit:contenthash',
            'note'            => 'privacy:metadata:deposit:note',
            'timecreated'     => 'privacy:metadata:deposit:timecreated',
        ], 'privacy:metadata:deposit');

        // T-42: what the candidate says is outside their activity. Theirs alone.
        $collection->add_database_table('tool_vae_scope', [
            'uuid'            => 'privacy:metadata:scope:uuid',
            'candidateid'     => 'privacy:metadata:scope:candidateid',
            'fichecode'       => 'privacy:metadata:scope:fichecode',
            'ficherang'       => 'privacy:metadata:scope:ficherang',
            'note'            => 'privacy:metadata:scope:note',
            'timecreated'     => 'privacy:metadata:scope:timecreated',
        ], 'privacy:metadata:scope');

        // T-70: the calls a candidate made to the reformulation aid, who and when. The text goes to the core's subsystem.
        $collection->add_database_table('tool_vae_aicall', [
            'userid'          => 'privacy:metadata:aicall:userid',
            'timecreated'     => 'privacy:metadata:aicall:timecreated',
        ], 'privacy:metadata:aicall');
        $collection->add_subsystem_link('core_ai', [], 'privacy:metadata:core_ai');

        // T-10, lot 5b: the opinions of the managers on the deposits.
        $collection->add_database_table('tool_vae_opinion', [
            'uuid'            => 'privacy:metadata:opinion:uuid',
            'depositid'       => 'privacy:metadata:opinion:depositid',
            'authorid'        => 'privacy:metadata:opinion:authorid',
            'content'         => 'privacy:metadata:opinion:content',
            'contenthash'     => 'privacy:metadata:opinion:contenthash',
            'timecreated'     => 'privacy:metadata:opinion:timecreated',
        ], 'privacy:metadata:opinion');

        // T-20: the requests for precision on the declarations, and the answers.
        $collection->add_database_table('tool_vae_request', [
            'uuid'            => 'privacy:metadata:request:uuid',
            'evidenceid'      => 'privacy:metadata:request:evidenceid',
            'authorid'        => 'privacy:metadata:request:authorid',
            'question'        => 'privacy:metadata:request:question',
            'questionhash'    => 'privacy:metadata:request:questionhash',
            'timecreated'     => 'privacy:metadata:request:timecreated',
            'answer'          => 'privacy:metadata:request:answer',
            'answerhash'      => 'privacy:metadata:request:answerhash',
            'timeanswered'    => 'privacy:metadata:request:timeanswered',
        ], 'privacy:metadata:request');

        // Privacy-exempt: tool_vae_obsolete.
        // No personal data: a competency identifier, a source identifier, a date and a
        // reason. The marker above is what `make doctor` reads, so the omission is a dated
        // decision rather than a gap - a table added later and forgotten fails the build.

        // Privacy-exempt: tool_vae_ref.
        // No personal data: an address that a certifier publishes, a short title chosen by an
        // administrator and the day they checked the page. The event of the log says who acted.

        // This plugin causes the competency subsystem to write on the candidate's behalf.
        // Not declaring the link would leave the administrator's answer incomplete even
        // though every field above is listed.
        $collection->add_subsystem_link(
            'core_competency',
            [],
            'privacy:metadata:corecompetency'
        );

        // Lot 7 ter: the notifications are stored, exported and erased by the messaging
        // subsystem, not by this plugin. They carry no narrative - see \tool_vae\notifier -
        // but they exist, and an answer that left them out would be incomplete.
        $collection->add_subsystem_link(
            'core_message',
            [],
            'privacy:metadata:coremessage'
        );

        // The file subsystem holds three things for this plugin, and a subsystem is linked ONCE: the register of the plugins
        // lists a link per call, so a second one would show the administrator two lines for the same subsystem (T-13 files,
        // lot 8 archives, lot 8 bis backups). One summary, three uses. Lot 8: an exported archive waits in the candidate's
        // user context for a day. It holds the whole dossier, so it is announced, exported and erased with the dossier.
        $collection->add_subsystem_link(
            'core_files',
            [],
            'privacy:metadata:corefiles'
        );

        return $collection;
    }

    /**
     * Contexts holding data for a user.
     *
     * Everything a dossier contains lives in the candidate's user context, including the
     * attestations written by someone else: that is where the data is, whoever wrote it.
     * Framework provenance and mapping decisions are site-level acts and live in the
     * system context.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        // As a candidate.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_evidence} e ON e.candidateid = ctx.instanceid
              WHERE ctx.contextlevel = :userlevel AND e.candidateid = :candidateid",
            ['userlevel' => CONTEXT_USER, 'candidateid' => $userid]
        );

        // As the manager who attested someone else's declaration.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_evidence} e ON e.candidateid = ctx.instanceid
               JOIN {tool_vae_attest} a ON a.evidenceid = e.id
              WHERE ctx.contextlevel = :userlevel AND a.attestantid = :attestantid",
            ['userlevel' => CONTEXT_USER, 'attestantid' => $userid]
        );

        // As the manager who asked a colleague's signatory to withdraw an attestation
        // (13 October 2026). The request is two fields on the attestation, in the candidate's
        // context, and names the requester until the signatory answers. It is theirs, so it has to
        // be found like any other trace.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_evidence} e ON e.candidateid = ctx.instanceid
               JOIN {tool_vae_attest} a ON a.evidenceid = e.id
              WHERE ctx.contextlevel = :userlevel AND a.withdrawreqby = :requester",
            ['userlevel' => CONTEXT_USER, 'requester' => $userid]
        );

        // As the signatory of an attestation carried in from an archive (lot 8 ter). The reimport
        // names them by the ULID the archive gives, attestantid null. When the archive came from
        // this site - a backup restored in place - that ULID is the one the identity map holds for
        // their account, and the row names them by role and statement as surely as by an id.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_evidence} e ON e.candidateid = ctx.instanceid
               JOIN {tool_vae_attest} a ON a.evidenceid = e.id
               JOIN {tool_vae_identity} i ON i.uuid = a.attestantuuid AND i.itemtype = :type
              WHERE ctx.contextlevel = :userlevel AND i.itemid = :userid",
            ['userlevel' => CONTEXT_USER, 'type' => 'user', 'userid' => $userid]
        );

        // As the person who last wrote an attestation row.
        //
        // usermodified is NOT a duplicate of attestantid. It holds the signatory's id from
        // the moment they sign, and no other code path clears it - which is why withdraw()
        // sets it to 0 and why strip_attestant() below reaches the rows this person merely
        // last touched. Those two measures are what keep the column in step with the
        // signature; this query is what makes the column reachable at all, including on
        // rows written before either measure existed. The field is declared as personal
        // data in get_metadata(): it has to be findable like any other.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_evidence} e ON e.candidateid = ctx.instanceid
               JOIN {tool_vae_attest} a ON a.evidenceid = e.id
              WHERE ctx.contextlevel = :userlevel AND a.usermodified = :usermodified",
            ['userlevel' => CONTEXT_USER, 'usermodified' => $userid]
        );

        // As the administrator who reimported someone else's dossier (lot 8 ter): the reimport
        // writes their id into usermodified of every claim it rebuilds. get_users_in_context()
        // already lists that column; it has to lead here too, or their erasure never reaches it.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_evidence} e ON e.candidateid = ctx.instanceid
              WHERE ctx.contextlevel = :userlevel AND e.usermodified = :usermodified",
            ['userlevel' => CONTEXT_USER, 'usermodified' => $userid]
        );

        // As the candidate whose sections are written (T-81).
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_draft} d ON d.candidateid = ctx.instanceid
              WHERE ctx.contextlevel = :userlevel AND d.candidateid = :candidateid",
            ['userlevel' => CONTEXT_USER, 'candidateid' => $userid]
        );

        // As the candidate who made a deposit (T-21). Theirs alone: nobody else writes one.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_deposit} p ON p.candidateid = ctx.instanceid
              WHERE ctx.contextlevel = :userlevel AND p.candidateid = :candidateid",
            ['userlevel' => CONTEXT_USER, 'candidateid' => $userid]
        );

        // As the candidate who said a competency is outside their activity (T-42). Theirs alone.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_scope} s ON s.candidateid = ctx.instanceid
              WHERE ctx.contextlevel = :userlevel AND s.candidateid = :candidateid",
            ['userlevel' => CONTEXT_USER, 'candidateid' => $userid]
        );

        // As the candidate who asked the reformulation aid (T-70). Theirs alone.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_aicall} ai ON ai.userid = ctx.instanceid
              WHERE ctx.contextlevel = :userlevel AND ai.userid = :candidateid",
            ['userlevel' => CONTEXT_USER, 'candidateid' => $userid]
        );

        // As the manager who wrote an opinion on a candidate's deposit (T-10, lot 5b).
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_deposit} p ON p.candidateid = ctx.instanceid
               JOIN {tool_vae_opinion} o ON o.depositid = p.id
              WHERE ctx.contextlevel = :userlevel AND o.authorid = :authorid",
            ['userlevel' => CONTEXT_USER, 'authorid' => $userid]
        );

        // As the manager who asked for a precision on a candidate's declaration (T-20).
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_evidence} e ON e.candidateid = ctx.instanceid
               JOIN {tool_vae_request} q ON q.evidenceid = e.id
              WHERE ctx.contextlevel = :userlevel AND q.authorid = :authorid",
            ['userlevel' => CONTEXT_USER, 'authorid' => $userid]
        );

        // As the person who saved a version in someone else's document, or who last touched a section.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_draft} d ON d.candidateid = ctx.instanceid
               JOIN {tool_vae_version} v ON v.draftid = d.id
              WHERE ctx.contextlevel = :userlevel AND v.authorid = :authorid",
            ['userlevel' => CONTEXT_USER, 'authorid' => $userid]
        );
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_draft} d ON d.candidateid = ctx.instanceid
              WHERE ctx.contextlevel = :userlevel AND d.usermodified = :usermodified",
            ['userlevel' => CONTEXT_USER, 'usermodified' => $userid]
        );

        // As their own identity map entry.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_identity} i ON i.itemid = ctx.instanceid AND i.itemtype = :type
              WHERE ctx.contextlevel = :userlevel AND i.itemid = :userid",
            ['userlevel' => CONTEXT_USER, 'type' => 'user', 'userid' => $userid]
        );

        // As the administrator who imported a framework or recorded a mapping.
        // add_system_context() rather than a query selecting SYSCONTEXTID: the constant is
        // defined lazily during setup, and a provider that depends on that ordering fails
        // in ways that are tedious to trace.
        // Producing an archive is such an act too (lot 8 quater): the register is the site's, and
        // its row stays when its producer leaves it. The candidate a row names is reached through
        // their identity map entry above, which every archive of their dossier mints.
        $hassitedata = $DB->record_exists('tool_vae_source', ['usermodified' => $userid])
            || $DB->record_exists('tool_vae_passage', ['decidedby' => $userid])
            || $DB->record_exists('tool_vae_carry', ['decidedby' => $userid])
            || $DB->record_exists('tool_vae_referent', ['userid' => $userid])
            || $DB->record_exists_select('tool_vae_referent', 'createdby = :createdby', ['createdby' => $userid])
            || $DB->record_exists(exporter::REGISTER, ['userid' => $userid]);

        if ($hassitedata) {
            $contextlist->add_system_context();
        }

        // A person who opened a supporting piece of a candidate (T-13, lot 2) holds data in that candidate's context,
        // even if they signed nothing: it is erased through the same door.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {tool_vae_piece} p ON p.candidateid = ctx.instanceid
               JOIN {tool_vae_piece_open} o ON o.pieceid = p.id
              WHERE ctx.contextlevel = :userlevel AND o.userid = :userid",
            ['userlevel' => CONTEXT_USER, 'userid' => $userid]
        );

        return $contextlist;
    }

    /**
     * Users holding data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context instanceof context_user) {
            $userlist->add_from_sql(
                'candidateid',
                "SELECT e.candidateid FROM {tool_vae_evidence} e WHERE e.candidateid = :userid",
                ['userid' => $context->instanceid]
            );
            // Those who opened one of the candidate's supporting pieces (T-13, lot 2).
            $userlist->add_from_sql(
                'userid',
                "SELECT o.userid
                   FROM {tool_vae_piece_open} o
                   JOIN {tool_vae_piece} p ON p.id = o.pieceid
                  WHERE p.candidateid = :candidateid",
                ['candidateid' => $context->instanceid]
            );

            $userlist->add_from_sql(
                'attestantid',
                "SELECT a.attestantid
                   FROM {tool_vae_attest} a
                   JOIN {tool_vae_evidence} e ON e.id = a.evidenceid
                  WHERE e.candidateid = :userid AND a.attestantid IS NOT NULL",
                ['userid' => $context->instanceid]
            );

            // The manager who asked for a withdrawal - see get_contexts_for_userid().
            $userlist->add_from_sql(
                'withdrawreqby',
                "SELECT a.withdrawreqby
                   FROM {tool_vae_attest} a
                   JOIN {tool_vae_evidence} e ON e.id = a.evidenceid
                  WHERE e.candidateid = :userid AND a.withdrawreqby IS NOT NULL",
                ['userid' => $context->instanceid]
            );

            // A signatory known here by the ULID an imported attestation carries - see
            // get_contexts_for_userid().
            $userlist->add_from_sql(
                'itemid',
                "SELECT i.itemid
                   FROM {tool_vae_attest} a
                   JOIN {tool_vae_evidence} e ON e.id = a.evidenceid
                   JOIN {tool_vae_identity} i ON i.uuid = a.attestantuuid AND i.itemtype = :type
                  WHERE e.candidateid = :userid",
                ['type' => 'user', 'userid' => $context->instanceid]
            );

            $userlist->add_from_sql(
                'usermodified',
                "SELECT e.usermodified FROM {tool_vae_evidence} e WHERE e.candidateid = :userid",
                ['userid' => $context->instanceid]
            );

            // Same reason as in get_contexts_for_userid(): usermodified outlives the
            // stripping of attestantid, so a listing built without it would leave a person
            // attached to this context and invisible to the request that should reach them.
            $userlist->add_from_sql(
                'usermodified',
                "SELECT a.usermodified
                   FROM {tool_vae_attest} a
                   JOIN {tool_vae_evidence} e ON e.id = a.evidenceid
                  WHERE e.candidateid = :userid",
                ['userid' => $context->instanceid]
            );

            // T-81: the candidate who writes the sections, whoever saved a version, whoever last
            // touched a section - see get_contexts_for_userid().
            $userlist->add_from_sql(
                'candidateid',
                "SELECT d.candidateid FROM {tool_vae_draft} d WHERE d.candidateid = :userid",
                ['userid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'candidateid',
                "SELECT p.candidateid FROM {tool_vae_deposit} p WHERE p.candidateid = :userid",
                ['userid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'candidateid',
                "SELECT s.candidateid FROM {tool_vae_scope} s WHERE s.candidateid = :userid",
                ['userid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'userid',
                "SELECT ai.userid FROM {tool_vae_aicall} ai WHERE ai.userid = :userid",
                ['userid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'authorid',
                "SELECT o.authorid
                   FROM {tool_vae_opinion} o
                   JOIN {tool_vae_deposit} p ON p.id = o.depositid
                  WHERE p.candidateid = :userid AND o.authorid IS NOT NULL",
                ['userid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'authorid',
                "SELECT q.authorid
                   FROM {tool_vae_request} q
                   JOIN {tool_vae_evidence} e ON e.id = q.evidenceid
                  WHERE e.candidateid = :userid AND q.authorid IS NOT NULL",
                ['userid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'authorid',
                "SELECT v.authorid
                   FROM {tool_vae_version} v
                   JOIN {tool_vae_draft} d ON d.id = v.draftid
                  WHERE d.candidateid = :userid AND v.authorid IS NOT NULL",
                ['userid' => $context->instanceid]
            );
            $userlist->add_from_sql(
                'usermodified',
                "SELECT d.usermodified FROM {tool_vae_draft} d WHERE d.candidateid = :userid",
                ['userid' => $context->instanceid]
            );

            // Lot 8: the opaque identifier of a signatory, minted when a dossier they signed is
            // exported. get_contexts_for_userid() announces it in their own context; it has to
            // be found here as well, or a request built from this list would miss it.
            $userlist->add_from_sql(
                'itemid',
                "SELECT i.itemid FROM {tool_vae_identity} i WHERE i.itemtype = :type AND i.itemid = :userid",
                ['type' => 'user', 'userid' => $context->instanceid]
            );

            return;
        }

        if ($context instanceof context_system) {
            $userlist->add_from_sql(
                'usermodified',
                "SELECT s.usermodified FROM {tool_vae_source} s",
                []
            );
            $userlist->add_from_sql(
                'decidedby',
                "SELECT p.decidedby FROM {tool_vae_passage} p",
                []
            );
            $userlist->add_from_sql(
                'decidedby',
                "SELECT c.decidedby FROM {tool_vae_carry} c WHERE c.decidedby > 0",
                []
            );
            $userlist->add_from_sql('userid', "SELECT r.userid FROM {tool_vae_referent} r", []);
            $userlist->add_from_sql(
                'createdby',
                "SELECT r.createdby FROM {tool_vae_referent} r WHERE r.createdby > 0",
                []
            );
            $userlist->add_from_sql(
                'userid',
                "SELECT a.userid FROM {tool_vae_archive} a",
                []
            );
        }
    }

    /**
     * Export a user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        // CAST AT SOURCE. approved_contextlist::get_user()->id comes from the database and
        // is a STRING, as is context::$instanceid. Comparing either of them strictly against
        // an integer is always false - which made the candidate look like a third party to
        // their own dossier, and their export came back empty. Same family of defect as
        // FORMAT_PLAIN being the string '2'.
        $userid = (int) $contextlist->get_user()->id;

        // Their own opaque identifier: what an attestation carried in from an archive names them by.
        $ulid = self::ulid_of($userid);

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_user) {
                $candidateid = (int) $context->instanceid;

                $records = $DB->get_records('tool_vae_evidence', ['candidateid' => $candidateid]);
                $claims = [];
                $requests = [];
                $gateway = new competency_gateway();

                foreach ($records as $record) {
                    $attestations = $DB->get_records('tool_vae_attest', ['evidenceid' => $record->id]);
                    $exported = [];

                    foreach ($attestations as $attestation) {
                        // A request for withdrawal this person made and nobody has answered. Only
                        // what is theirs - the claim, the record, the date - and not the claim's
                        // narrative nor the signatory: they asked, they did not sign.
                        if ($userid !== $candidateid && (int) ($attestation->withdrawreqby ?? 0) === $userid) {
                            $requests[] = [
                                'reference'   => $record->uuid,
                                'record'      => $record->fichecode . '-' . $record->ficherang,
                                'requestedat' => \core_privacy\local\request\transform::datetime(
                                    (int) $attestation->withdrawreqat
                                ),
                            ];
                        }

                        // The candidate sees every attestation on their dossier; a manager
                        // asking for their own data sees only what they signed, under their
                        // account or under their ULID.
                        if (
                            $userid !== $candidateid
                            && (int) $attestation->attestantid !== $userid
                            && ($ulid === null || $attestation->attestantuuid !== $ulid)
                        ) {
                            continue;
                        }
                        $entry = [
                            'piecesread'      => $attestation->piecesread === null ? null : (bool) $attestation->piecesread,
                            'pieces_covered'  => array_values(array_map(
                                fn($covered) => ['reference' => $covered->uuid, 'sha256' => $covered->sha256],
                                $DB->get_records_sql(
                                    "SELECT ap.id, p.uuid, ap.sha256
                                       FROM {tool_vae_attest_piece} ap
                                       JOIN {tool_vae_piece} p ON p.id = ap.pieceid
                                      WHERE ap.attestid = :attestid
                                   ORDER BY p.id",
                                    ['attestid' => $attestation->id]
                                )
                            )),
                            'role'            => $attestation->attestantrole,
                            'statement'       => $attestation->statement,
                            'attestedat'      => $attestation->attestedat
                                ? \core_privacy\local\request\transform::datetime($attestation->attestedat) : null,
                            'withdrawnat'     => $attestation->withdrawnat
                                ? \core_privacy\local\request\transform::datetime($attestation->withdrawnat) : null,
                            'withdrawnreason' => $attestation->withdrawnreason,
                            // The dates get_metadata() declares for the row: when it was written and last changed, and when
                            // it was carried in from an archive.
                            'recorded'        => \core_privacy\local\request\transform::datetime(
                                (int) $attestation->timecreated
                            ),
                            'modified'        => \core_privacy\local\request\transform::datetime(
                                (int) $attestation->timemodified
                            ),
                            'importedat'      => $attestation->importedat
                                ? \core_privacy\local\request\transform::datetime((int) $attestation->importedat) : null,
                        ];
                        // That a withdrawal has been asked of this signature, and when - never by
                        // whom: the candidate and the signatory are told the fact, not the person.
                        if (($attestation->withdrawreqat ?? null) !== null) {
                            $entry['withdrawalrequestedat'] = \core_privacy\local\request\transform::datetime(
                                (int) $attestation->withdrawreqat
                            );
                        }
                        $exported[] = $entry;
                    }

                    if ($userid !== $candidateid && empty($exported)) {
                        continue;
                    }

                    // Where this declaration was carried to, and where it comes from (T-30, lot 3), by reference.
                    $carriedto = $DB->get_field_sql(
                        "SELECT n.uuid FROM {tool_vae_carry} c JOIN {tool_vae_evidence} n ON n.id = c.newevidenceid
                          WHERE c.oldevidenceid = :id",
                        ['id' => $record->id]
                    );
                    $carriedfrom = $DB->get_field_sql(
                        "SELECT o.uuid FROM {tool_vae_carry} c JOIN {tool_vae_evidence} o ON o.id = c.oldevidenceid
                          WHERE c.newevidenceid = :id",
                        ['id' => $record->id]
                    );

                    // When the candidate carried it over: the same date on both ends of a carry.
                    $carriedat = $DB->get_field_sql(
                        "SELECT c.carriedat FROM {tool_vae_carry} c
                          WHERE c.oldevidenceid = :old OR c.newevidenceid = :new",
                        ['old' => $record->id, 'new' => $record->id]
                    );

                    $claim = [
                        'carriedto'    => $carriedto ?: null,
                        'carriedfrom'  => $carriedfrom ?: null,
                        'carriedat'    => $carriedat
                            ? \core_privacy\local\request\transform::datetime((int) $carriedat) : null,
                        'reference'    => $record->uuid,
                        'periodstart'  => \core_privacy\local\request\transform::datetime($record->periodstart),
                        'periodend'    => \core_privacy\local\request\transform::datetime($record->periodend),
                        'setting'      => $record->settingtype,
                        'record'       => $record->fichecode . '-' . $record->ficherang,
                        'anchorlabel'  => $record->corelabel,
                        'recordupdated' => \core_privacy\local\request\transform::datetime($record->ficheupdated),
                        'attestations' => $exported,
                    ];

                    // THE NARRATIVE IS THE CANDIDATE'S, AND ONLY THEIRS TO RECEIVE. A signatory's request is answered with
                    // what is theirs - the attestation, its dates, the reference of what they signed - not with the free
                    // text of the person they signed for: that text names, or can lead to, someone who asked for nothing,
                    // and the file leaves the platform under the signatory's name. They read it to sign; they do not take
                    // it away. The dates of the declaration and the label of its competency answer "when did I declare
                    // this, and against what", and are declared in get_metadata().
                    if ($userid === $candidateid) {
                        $claim += [
                            'settingdetail' => $record->settingdetail,
                            'situation'    => $record->situation,
                            'declared'     => \core_privacy\local\request\transform::datetime((int) $record->timecreated),
                            'modified'     => \core_privacy\local\request\transform::datetime((int) $record->timemodified),
                            // Read from the competency itself, without the viewing right: an export runs from cron, where
                            // nobody is logged in, and the core's own read would answer nothing there.
                            'competency'   => $record->competencyid
                                ? $gateway->competency_name((int) $record->competencyid) : null,
                        ];
                    }
                    $claims[] = $claim;
                }

                if ($claims || $requests) {
                    $payload = ['claims' => $claims];
                    if ($requests) {
                        $payload['withdrawalrequests'] = $requests;
                    }
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'tool_vae')],
                        (object) $payload
                    );
                }

                // The pieces this person opened in this candidate's dossier (T-13, lot 2): their own data, whoever they are.
                $openings = $DB->get_records_sql(
                    "SELECT o.id, p.uuid, o.timeopened
                       FROM {tool_vae_piece_open} o
                       JOIN {tool_vae_piece} p ON p.id = o.pieceid
                      WHERE o.userid = :userid AND p.candidateid = :candidateid
                   ORDER BY o.id",
                    ['userid' => $userid, 'candidateid' => $candidateid]
                );
                if ($openings) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'tool_vae'), 'piece openings'],
                        (object) ['openings' => array_values(array_map(
                            fn($opening) => [
                                'reference' => $opening->uuid,
                                'openedat' => \core_privacy\local\request\transform::datetime((int) $opening->timeopened),
                            ],
                            $openings
                        ))]
                    );
                }

                // The supporting pieces (T-13): the candidate's own data, files included. A manager asking for their own data
                // reads none of them.
                if ($userid === $candidateid) {
                    $pieces = $DB->get_records('tool_vae_piece', ['candidateid' => $candidateid], 'id');
                    $listed = [];
                    foreach ($pieces as $piece) {
                        $listed[] = [
                            'reference'   => $piece->uuid,
                            'declaration' => $records[$piece->evidenceid]->uuid ?? null,
                            'kind'        => $piece->kind,
                            'nature'      => $piece->nature,
                            'title'       => $piece->title,
                            'mimetype'    => $piece->mimetype,
                            'filesize'    => $piece->filesize,
                            'sha256'      => $piece->sha256,
                            'url'         => $piece->url,
                            'addedat'     => \core_privacy\local\request\transform::datetime((int) $piece->timecreated),
                            'removedat'   => $piece->timeremoved
                                ? \core_privacy\local\request\transform::datetime((int) $piece->timeremoved) : null,
                        ];
                        if ($piece->timeremoved === null && $piece->kind === 'file') {
                            writer::with_context($context)->export_area_files(
                                [get_string('pluginname', 'tool_vae'), 'pieces', $piece->uuid],
                                'tool_vae',
                                'piece',
                                (int) $piece->id
                            );
                        }
                    }
                    if ($listed) {
                        writer::with_context($context)->export_data(
                            [get_string('pluginname', 'tool_vae'), 'pieces'],
                            (object) ['pieces' => $listed]
                        );
                    }
                }

                // The opinions on the deposits (T-10, lot 5b): the candidate reads every one written on
                // their deposits; a manager's own request covers what they wrote, and nothing else.
                $opinions = [];
                $written = $DB->get_records_sql(
                    "SELECT o.id, o.uuid, o.authorid, o.content, o.contenthash, o.timecreated, p.number AS depositnumber
                       FROM {tool_vae_opinion} o
                       JOIN {tool_vae_deposit} p ON p.id = o.depositid
                      WHERE p.candidateid = :candidateid
                   ORDER BY p.number, o.id",
                    ['candidateid' => $candidateid]
                );
                foreach ($written as $opinion) {
                    if ($userid !== $candidateid && (int) $opinion->authorid !== $userid) {
                        continue;
                    }
                    $opinions[] = [
                        'reference' => $opinion->uuid,
                        'deposit'   => (int) $opinion->depositnumber,
                        'written'   => \core_privacy\local\request\transform::datetime($opinion->timecreated),
                        'sha256'    => $opinion->contenthash,
                        'content'   => $opinion->content,
                    ];
                }
                if ($opinions) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'tool_vae'), get_string('privacy:path:opinions', 'tool_vae')],
                        (object) ['opinions' => $opinions]
                    );
                }

                // The requests for precision and their answers (T-20): the candidate reads every one on
                // their declarations; a manager's own request covers the questions they asked.
                $precisions = [];
                $asked = $DB->get_records_sql(
                    "SELECT q.id, q.uuid, q.authorid, q.question, q.questionhash, q.timecreated, q.answer,
                            q.answerhash, q.timeanswered, e.uuid AS declaration
                       FROM {tool_vae_request} q
                       JOIN {tool_vae_evidence} e ON e.id = q.evidenceid
                      WHERE e.candidateid = :candidateid
                   ORDER BY q.id",
                    ['candidateid' => $candidateid]
                );
                foreach ($asked as $request) {
                    if ($userid !== $candidateid && (int) $request->authorid !== $userid) {
                        continue;
                    }
                    $entry = [
                        'reference'   => $request->uuid,
                        'declaration' => $request->declaration,
                        'asked'       => \core_privacy\local\request\transform::datetime($request->timecreated),
                        'question'    => $request->question,
                        'sha256'      => $request->questionhash,
                    ];
                    if ($request->answer !== null) {
                        $entry['answered'] = \core_privacy\local\request\transform::datetime($request->timeanswered);
                        $entry['answersha256'] = $request->answerhash;
                        // The question is the asker's; the answer is the candidate's own free text, and goes to them alone.
                        // The asker learns that one was given, when, and its digest - not what it says.
                        if ($userid === $candidateid) {
                            $entry['answer'] = $request->answer;
                        }
                    }
                    $precisions[] = $entry;
                }
                if ($precisions) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'tool_vae'), get_string('privacy:path:requests', 'tool_vae')],
                        (object) ['requests' => $precisions]
                    );
                }

                if ($userid === $candidateid) {
                    // The sections they write and every version they kept (T-81). Theirs alone: the
                    // request of someone who only saved a version in this document covers that act,
                    // and is not the document.
                    $sections = [];
                    $drafts = $DB->get_records(
                        'tool_vae_draft',
                        ['candidateid' => $candidateid],
                        'doctype, section, slot'
                    );
                    foreach ($drafts as $draft) {
                        $versions = [];
                        $saved = $DB->get_records('tool_vae_version', ['draftid' => $draft->id], 'number');
                        foreach ($saved as $version) {
                            $versions[] = [
                                'number'       => (int) $version->number,
                                'saved'        => \core_privacy\local\request\transform::datetime($version->timecreated),
                                'note'         => $version->note,
                                'restoredfrom' => $version->restoredfrom === null ? null : (int) $version->restoredfrom,
                                'content'      => $version->content,
                            ];
                        }
                        $sections[] = [
                            'reference' => $draft->uuid,
                            'document'  => $draft->doctype,
                            'section'   => $draft->section,
                            'slot'      => (int) $draft->slot,
                            'record'    => $draft->fichecode,
                            'created'   => \core_privacy\local\request\transform::datetime((int) $draft->timecreated),
                            'modified'  => \core_privacy\local\request\transform::datetime((int) $draft->timemodified),
                            'workingat' => $draft->workingat
                                ? \core_privacy\local\request\transform::datetime((int) $draft->workingat) : null,
                            'working'   => $draft->working,
                            'versions'  => $versions,
                        ];
                    }
                    if ($sections) {
                        writer::with_context($context)->export_data(
                            [get_string('pluginname', 'tool_vae'), get_string('privacy:path:sections', 'tool_vae')],
                            (object) ['sections' => $sections]
                        );
                    }

                    // Their deposits of the dossier de faisabilite (T-21), with their snapshot.
                    $deposits = [];
                    foreach ($DB->get_records('tool_vae_deposit', ['candidateid' => $candidateid], 'number') as $row) {
                        $deposits[] = [
                            'reference' => $row->uuid,
                            'document'  => $row->doctype,
                            'number'    => (int) $row->number,
                            'deposited' => \core_privacy\local\request\transform::datetime($row->timecreated),
                            'note'      => $row->note,
                            'sha256'    => $row->contenthash,
                            'content'   => $row->content,
                        ];
                    }
                    if ($deposits) {
                        writer::with_context($context)->export_data(
                            [get_string('pluginname', 'tool_vae'), get_string('privacy:path:deposits', 'tool_vae')],
                            (object) ['deposits' => $deposits]
                        );
                    }

                    // The competencies they said are outside their activity (T-42), with their note.
                    $outside = [];
                    foreach ($DB->get_records('tool_vae_scope', ['candidateid' => $candidateid], 'fichecode, ficherang') as $row) {
                        $outside[] = [
                            'reference' => $row->uuid,
                            'record'    => $row->fichecode,
                            'rank'      => $row->ficherang,
                            'note'      => $row->note,
                            'said'      => \core_privacy\local\request\transform::datetime($row->timecreated),
                        ];
                    }
                    if ($outside) {
                        writer::with_context($context)->export_data(
                            [get_string('pluginname', 'tool_vae'), get_string('privacy:path:scope', 'tool_vae')],
                            (object) ['outside_activity' => $outside]
                        );
                    }

                    // Their calls to the reformulation aid (T-70), still held: the moment only. The text and the answer are the
                    // core's, which exports them itself.
                    $asked = [];
                    foreach ($DB->get_records('tool_vae_aicall', ['userid' => $candidateid], 'timecreated, id') as $row) {
                        $asked[] = ['asked' => \core_privacy\local\request\transform::datetime($row->timecreated)];
                    }
                    if ($asked) {
                        writer::with_context($context)->export_data(
                            [get_string('pluginname', 'tool_vae'), get_string('privacy:path:aicalls', 'tool_vae')],
                            (object) ['reformulation_aid_calls' => $asked]
                        );
                    }

                    // Their own opaque identifier, and the archives waiting for them (lot 8).
                    // Never handed to anyone else: a manager's request covers what they signed.
                    if ($ulid !== null) {
                        writer::with_context($context)->export_data(
                            [get_string('pluginname', 'tool_vae'), get_string('privacy:path:identity', 'tool_vae')],
                            (object) ['identifier' => $ulid]
                        );

                        // The register's rows for their archives (lot 8 quater): which were issued,
                        // when, and the digest a jury may ask about. Not who produced them.
                        $issued = [];
                        foreach ($DB->get_records(exporter::REGISTER, ['candidateuuid' => $ulid], 'timecreated, id') as $row) {
                            $issued[] = [
                                'archive' => $row->archiveuuid,
                                'sha256' => $row->sha256,
                                'timecreated' => \core_privacy\local\request\transform::datetime($row->timecreated),
                            ];
                        }
                        if ($issued) {
                            writer::with_context($context)->export_data(
                                [
                                    get_string('pluginname', 'tool_vae'),
                                    get_string('privacy:path:exports', 'tool_vae'),
                                ],
                                (object) ['archives' => $issued]
                            );
                        }
                    }
                    writer::with_context($context)->export_area_files(
                        [get_string('pluginname', 'tool_vae'), get_string('privacy:path:exports', 'tool_vae')],
                        'tool_vae',
                        exporter::FILEAREA,
                        0
                    );
                }

                continue;
            }

            if ($context instanceof context_system) {
                self::export_site_actor($context, $userid);
            }
        }
    }

    /**
     * Export what this plugin holds about someone at site level.
     *
     * Not a dossier: the traces of two administrative acts, importing a certification record
     * and deciding that one record supersedes another. They are announced by
     * get_contexts_for_userid(), so they have to come back here. A provider that announces a
     * context and exports nothing from it makes the site answer "we hold data about you" and
     * then hand over an empty file - which reads as a refusal, not as an absence.
     *
     * @param context_system $context
     * @param int $userid
     */
    private static function export_site_actor(context_system $context, int $userid): void {
        global $DB;

        $imports = [];
        foreach ($DB->get_records('tool_vae_source', ['usermodified' => $userid]) as $source) {
            $imports[] = [
                'record'       => $source->fichecode,
                'type'         => $source->fichetype,
                'source'       => $source->sourceurl,
                'licence'      => $source->licence,
                'attribution'  => $source->attribution,
                'lastimport'   => $source->lastimport
                    ? \core_privacy\local\request\transform::datetime($source->lastimport) : null,
                'lastimportstatus' => $source->lastimportstatus,
                'timemodified' => \core_privacy\local\request\transform::datetime($source->timemodified),
            ];
        }

        $mappings = [];
        foreach ($DB->get_records('tool_vae_passage', ['decidedby' => $userid]) as $passage) {
            $mappings[] = [
                'decidedat' => $passage->decidedat
                    ? \core_privacy\local\request\transform::datetime($passage->decidedat) : null,
                'note'      => $passage->note,
            ];
        }
        // The same decisions, as copied on the declarations a candidate carried over (T-30, lot 3).
        foreach ($DB->get_records('tool_vae_carry', ['decidedby' => $userid]) as $carry) {
            $mappings[] = [
                'decidedat' => $carry->decidedat
                    ? \core_privacy\local\request\transform::datetime($carry->decidedat) : null,
                'note'      => $carry->note,
            ];
        }

        // The archives they produced, by ULID and digest. Not the candidate's ULID: that names
        // someone else, and the producer's request covers their own act.
        $archives = [];
        foreach ($DB->get_records(exporter::REGISTER, ['userid' => $userid], 'timecreated, id') as $row) {
            $archives[] = [
                'archive'     => $row->archiveuuid,
                'sha256'      => $row->sha256,
                'timecreated' => \core_privacy\local\request\transform::datetime($row->timecreated),
            ];
        }

        // The blocs they are referent of (T-23): the record, the rank and the day.
        $referents = [];
        foreach ($DB->get_records('tool_vae_referent', ['userid' => $userid], 'fichecode, ficherang') as $row) {
            $referents[] = [
                'record'  => $row->fichecode,
                'bloc'    => $row->ficherang,
                'named'   => \core_privacy\local\request\transform::datetime($row->timecreated),
            ];
        }

        // The referents they named (T-23): the record, the bloc and the day, not who was named.
        $named = [];
        foreach ($DB->get_records('tool_vae_referent', ['createdby' => $userid], 'fichecode, ficherang') as $row) {
            $named[] = [
                'record' => $row->fichecode,
                'bloc'   => $row->ficherang,
                'named'  => \core_privacy\local\request\transform::datetime($row->timecreated),
            ];
        }

        if (!$imports && !$mappings && !$archives && !$referents && !$named) {
            return;
        }

        writer::with_context($context)->export_data(
            [get_string('pluginname', 'tool_vae')],
            (object) [
                'imports' => $imports,
                'mappings' => $mappings,
                'archives' => $archives,
                'referent_of' => $referents,
                'referents_named' => $named,
            ]
        );
    }

    /**
     * Delete everything held for a context.
     *
     * @param context $context
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        if ($context instanceof context_user) {
            self::delete_dossier($context->instanceid);

            return;
        }

        if ($context instanceof context_system) {
            self::strip_site_actor(null);
        }
    }

    /**
     * Delete or strip data for one user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        // Cast at source - see the note in export_user_data(). Without it an erasure request
        // took the "someone else's dossier" branch and left the candidate's dossier intact,
        // while the site reported the erasure as done.
        $userid = (int) $contextlist->get_user()->id;

        // Read before the loop. Their own context, should it come first - nothing fixes the order -
        // takes their identity map entry with their dossier, and with it the only link from their
        // account to the ULID an imported attestation names them by.
        $ulid = self::ulid_of($userid);

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_system) {
                self::strip_site_actor($userid);

                continue;
            }

            if (!$context instanceof context_user) {
                continue;
            }

            if ((int) $context->instanceid === $userid) {
                // The dossier is theirs. It goes.
                self::delete_dossier($userid);
            } else {
                // They attested someone else's dossier. It stays; they leave it.
                self::strip_attestant($userid, (int) $context->instanceid, $ulid);
            }
        }
    }

    /**
     * Delete or strip data for several users in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();

        if ($context instanceof context_system) {
            foreach ($userlist->get_userids() as $userid) {
                self::strip_site_actor((int) $userid);
            }

            return;
        }

        if (!$context instanceof context_user) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            if ((int) $context->instanceid === (int) $userid) {
                self::delete_dossier((int) $userid);
            } else {
                // Read here, per person: the owner erased in this same loop takes only their own entry.
                self::strip_attestant((int) $userid, (int) $context->instanceid, self::ulid_of((int) $userid));
            }
        }
    }

    /**
     * Remove a candidate's dossier and their identity map entry.
     *
     * The work itself is in \tool_vae\local\purge, which is also what the observer on
     * account deletion calls. One erasure, one implementation: a table added to the schema and
     * forgotten is then forgotten on every path at once, which a test can catch, rather than on
     * one path only, which nothing can.
     *
     * The core user_evidence records are not touched: the competency subsystem has its own
     * provider, and deleting another component's rows from this one would double-delete on some
     * paths and leave orphans on others.
     *
     * The register's rows for the candidate's archives go too (lot 8 quater). They hold no claim,
     * but a platform still able to say "I issued this archive of this person" has not forgotten
     * them. A copy a jury still holds is then reimported as foreign, which is the erasure's price.
     *
     * @param int $candidateid
     */
    private static function delete_dossier(int $candidateid): void {
        global $DB;

        // Read before the purge, which deletes the identity map entry that leads to the rows.
        $ulid = self::ulid_of($candidateid);

        purge::dossier($candidateid);

        if ($ulid !== null) {
            $DB->delete_records(exporter::REGISTER, ['candidateuuid' => $ulid]);
        }
    }

    /**
     * Remove someone's identity from the referential they administered, keeping the referential.
     *
     * THE ROW STAYS, THE PERSON LEAVES - the same rule as a withdrawn attestation, and for
     * the same reason. Provenance is what lets a jury read a claim five years later, and a
     * mapping between two certification records is what keeps an old dossier meaningful;
     * neither belongs to whoever happened to type them. Deleting the rows would erase the
     * licence and the attribution that Licence Ouverte 2.0 obliges this plugin to display, and
     * would orphan every claim made against that record.
     *
     * `note` goes with the identifier, and that is a loss taken knowingly: it is free text, it
     * was declared as personal data in get_metadata(), and in practice it names the people who
     * sat on the commission. Keeping it would be the same contradiction this method exists to
     * close - announcing data and then not erasing it.
     *
     * @param int|null $userid Whose traces to strip; null for everyone, as a whole-context purge.
     */
    private static function strip_site_actor(?int $userid): void {
        global $DB;

        $where = $userid === null ? '' : ' WHERE usermodified = :userid';
        $params = $userid === null ? [] : ['userid' => $userid];
        $DB->execute("UPDATE {tool_vae_source} SET usermodified = 0" . $where, $params);

        $where = $userid === null ? '' : ' WHERE decidedby = :userid';
        $params = $userid === null ? [] : ['userid' => $userid];
        $DB->execute(
            "UPDATE {tool_vae_passage} SET decidedby = 0, decidedat = 0, note = ''" . $where,
            $params
        );

        // The copy of that decision on the declarations carried over (T-30, lot 3): the same loss.
        $where = $userid === null ? '' : ' WHERE decidedby = :userid';
        $params = $userid === null ? [] : ['userid' => $userid];
        $DB->execute(
            "UPDATE {tool_vae_carry} SET decidedby = 0, decidedat = 0, note = ''" . $where,
            $params
        );

        // The archive stays issued and the register keeps vouching for it; its producer leaves.
        $where = $userid === null ? '' : ' WHERE userid = :userid';
        $DB->execute("UPDATE {tool_vae_archive} SET userid = 0" . $where, $params);

        // The pieces a person opened (T-13, lot 2): the fact that they did goes with them, the piece stays.
        $DB->execute("DELETE FROM {tool_vae_piece_open}" . $where, $params);

        // The blocs they are referent of (T-23): the rule goes with the person, and who named a referent leaves the row.
        $DB->execute("DELETE FROM {tool_vae_referent}" . $where, $params);
        $named = $userid === null ? '' : ' WHERE createdby = :userid';
        $DB->execute("UPDATE {tool_vae_referent} SET createdby = 0" . $named, $params);
    }

    /**
     * Remove a manager's identity from the attestations they signed, keeping the evidence.
     *
     * Four fields, not one: the identifier, the job title, the statement and the date of
     * signature. See the class header.
     *
     * An attestation carried in from an archive names its signatory by ULID instead, attestantid
     * null (lot 8 ter). When that ULID is this person's, the row is theirs just as surely, and
     * goes the same way: the ULID with the four fields, as install.xml says of attestantuuid.
     *
     * The same path serves anyone else this dossier names without it being theirs - the
     * administrator who reimported it, kept in usermodified of each claim. The claims stay.
     *
     * THE CANDIDATE IS TOLD (decision of 27 October 2026). The declarations whose standing attestation this strip withdraws
     * are read before it and handed to notifier::attestations_erased() as soon as it is recorded - before the archives and the
     * backups are redone, because a request that fails there is played again and finds nothing left to withdraw. The
     * notifier never throws, so the erasure cannot fail for a message. The event of an ordinary withdrawal is not raised: this
     * runs under the account of the main administrator, who withdrew nothing.
     *
     * @param int $attestantid
     * @param int $candidateid
     * @param string|null $attestantuuid Their ULID, read by the caller before anything could delete it.
     */
    private static function strip_attestant(int $attestantid, int $candidateid, ?string $attestantuuid): void {
        global $DB;

        // T-81: the person leaves the sections and the versions of this document too. The text stays,
        // it is the candidate's; what names this person goes. Zero, not null, on a section - the
        // column is not nullable and 0 is what every erased author already is.
        $DB->execute(
            "UPDATE {tool_vae_version}
                SET authorid = NULL
              WHERE authorid = :authorid
                AND draftid IN (SELECT id FROM {tool_vae_draft} WHERE candidateid = :candidateid)",
            ['authorid' => $attestantid, 'candidateid' => $candidateid]
        );
        // T-10, lot 5b: the person leaves the opinions they wrote on this candidate's deposits. The text
        // stays: it is about the candidate, who has the right to read it.
        $DB->execute(
            "UPDATE {tool_vae_opinion}
                SET authorid = NULL
              WHERE authorid = :authorid
                AND depositid IN (SELECT id FROM {tool_vae_deposit} WHERE candidateid = :candidateid)",
            ['authorid' => $attestantid, 'candidateid' => $candidateid]
        );
        // T-20: the person leaves the questions they asked on this candidate's declarations. The text
        // stays: it is about the candidate, who has the right to read it.
        $DB->execute(
            "UPDATE {tool_vae_request}
                SET authorid = NULL
              WHERE authorid = :authorid
                AND evidenceid IN (SELECT id FROM {tool_vae_evidence} WHERE candidateid = :candidateid)",
            ['authorid' => $attestantid, 'candidateid' => $candidateid]
        );
        $DB->execute(
            "UPDATE {tool_vae_draft}
                SET usermodified = 0
              WHERE usermodified = :usermodified AND candidateid = :candidateid",
            ['usermodified' => $attestantid, 'candidateid' => $candidateid]
        );

        $signedby = 'attestantid = :attestantid';
        $signer = ['attestantid' => $attestantid, 'candidateid' => $candidateid];
        if ($attestantuuid !== null) {
            $signedby = '(attestantid = :attestantid OR attestantuuid = :attestantuuid)';
            $signer['attestantuuid'] = $attestantuuid;
        }

        // The declarations that lose a signature in force, read before the statement below takes it away: they are what the
        // candidate is told about.
        $lost = [];

        // One transaction for the three statements, and the files after it, as in purge: a file
        // deletion cannot be rolled back, and a strip that failed must leave the archives alone.
        $transaction = $DB->start_delegated_transaction();
        try {
            $lost = array_map('intval', $DB->get_fieldset_sql(
                "SELECT DISTINCT evidenceid
                   FROM {tool_vae_attest}
                  WHERE $signedby
                    AND withdrawnat IS NULL
                    AND evidenceid IN (SELECT id FROM {tool_vae_evidence} WHERE candidateid = :candidateid)",
                $signer
            ));

            // Whether they signed anything here, read before the statement below takes it away. It
            // decides whether the archives are redone: a refresh replaces every backup of the
            // dossier - new archives, without the claims deleted since - and only a signature is
            // worth that. usermodified, all that names the administrator who reimported the
            // dossier, is in no archive.
            $signedhere = $DB->record_exists_sql(
                "SELECT 1
                   FROM {tool_vae_attest}
                  WHERE $signedby
                    AND evidenceid IN (
                        SELECT id FROM {tool_vae_evidence} WHERE candidateid = :candidateid
                    )",
                $signer
            );

            // T-13, lot 2: the pieces a signature covered go with what it said, and the pieces this person opened with
            // them (the piece stays, it is the candidate's).
            $DB->execute(
                "DELETE FROM {tool_vae_attest_piece}
                       WHERE attestid IN (
                           SELECT id FROM {tool_vae_attest}
                            WHERE $signedby
                              AND evidenceid IN (SELECT id FROM {tool_vae_evidence} WHERE candidateid = :candidateid)
                       )",
                $signer
            );
            $DB->execute(
                "DELETE FROM {tool_vae_piece_open}
                       WHERE userid = :attestantid
                         AND pieceid IN (SELECT id FROM {tool_vae_piece} WHERE candidateid = :candidateid)",
                ['attestantid' => $attestantid, 'candidateid' => $candidateid]
            );

            $sql = "UPDATE {tool_vae_attest}
                       SET attestantid = NULL,
                           attestantuuid = NULL,
                           usermodified = 0,
                           attestantrole = NULL,
                           statement = NULL,
                           attestedat = NULL,
                           piecesread = NULL,
                           withdrawnat = :now,
                           withdrawnreason = :reason,
                           withdrawreqby = NULL,
                           withdrawreqat = NULL,
                           timemodified = :now2
                     WHERE $signedby
                       AND evidenceid IN (
                           SELECT id FROM {tool_vae_evidence} WHERE candidateid = :candidateid
                       )";

            $DB->execute($sql, $signer + [
                'now'         => time(),
                'now2'        => time(),
                'reason'      => 'rgpd',
            ]);

            // A request this person made and nobody answered. The attestation is not theirs
            // and stays; the request is, and goes - the signatory is asked nothing on behalf of
            // someone who has left. Cleared on the rows they did NOT sign too, which is the point.
            $DB->execute(
                "UPDATE {tool_vae_attest}
                    SET withdrawreqby = NULL, withdrawreqat = NULL
                  WHERE withdrawreqby = :requester
                    AND evidenceid IN (
                        SELECT id FROM {tool_vae_evidence} WHERE candidateid = :candidateid
                    )",
                ['requester' => $attestantid, 'candidateid' => $candidateid]
            );

            // The rows this manager signed are now anonymous. The rows they merely LAST TOUCHED
            // are not: an attestation withdrawn earlier already had its four fields cleared, and
            // kept this person's id in usermodified. The statement above does not reach them,
            // because it selects on attestantid, which is already null there.
            $DB->execute(
                "UPDATE {tool_vae_attest}
                    SET usermodified = 0
                  WHERE usermodified = :attestantid
                    AND evidenceid IN (
                        SELECT id FROM {tool_vae_evidence} WHERE candidateid = :candidateid
                    )",
                ['attestantid' => $attestantid, 'candidateid' => $candidateid]
            );

            // Same column on the claims, same reason. Only the trace goes: what the claim says
            // is the candidate's, and timemodified stays, since the candidate changed nothing.
            $DB->execute(
                "UPDATE {tool_vae_evidence}
                    SET usermodified = 0
                  WHERE usermodified = :attestantid AND candidateid = :candidateid",
                ['attestantid' => $attestantid, 'candidateid' => $candidateid]
            );

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The rollback() call rethrows; the line after it is never reached.
            $transaction->rollback($e);
            throw $e;
        }

        // The candidate is told NOW, the withdrawal being recorded and nothing redone yet: if what follows fails, the request is
        // played again, finds nothing to withdraw, and a message queued later would never have been queued. Never throws.
        if ($lost) {
            notifier::attestations_erased($candidateid, $lost, $attestantid);
        }

        if (!$signedhere) {
            return;
        }

        // An archive of this dossier still waiting for download carries the signature just
        // removed (lot 8). It goes; copies already downloaded are out of reach, as they state.
        exporter::discard($candidateid);

        // The backups carry it too, and they are kept without time limit (lot 8 bis). Each owner
        // gets one of the dossier as it now stands in place of theirs.
        backup::refresh($candidateid);
        document_copies::refresh($candidateid);
    }

    /**
     * The opaque identifier this site gave a user, if it gave one.
     *
     * @param int $userid
     * @return string|null
     */
    private static function ulid_of(int $userid): ?string {
        global $DB;

        $ulid = $DB->get_field('tool_vae_identity', 'uuid', ['itemtype' => 'user', 'itemid' => $userid]);

        return $ulid === false ? null : $ulid;
    }
}
