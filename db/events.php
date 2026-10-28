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
 * Deletions decided outside this plugin, the handlers that catch up with them, and the messages
 * that tell the right people what happened in it.
 *
 * The event names are literal strings on purpose. This file is read by the event manager at
 * install time and on every cache rebuild, including before this plugin's classes can be
 * autoloaded; a class constant here would be a dependency at the worst possible moment.
 *
 * THE FOUR CATCH-UP OBSERVERS ARE DECLARED INTERNAL, AND THE WORD IS WRITTEN OUT ON
 * PURPOSE. Moodle's default is `internal => true` - \core\event\manager::add_observers() sets
 * it when the key is absent - so omitting it does not make an observer external, it makes it
 * internal while looking like a decision nobody took. Declared, it can be read and argued with.
 *
 * Internal is also the right choice here. An internal observer runs INSIDE the transaction of
 * whoever caused the deletion: if the purge cannot finish, that transaction is doomed and the
 * deletion it was reacting to does not stand. An external one would run after the commit, so a
 * failed purge would leave the orphaned narrative that criterion 8 exists to prevent, and leave
 * it silently - the event manager turns every exception an observer throws into a debugging()
 * call. On the two paths the core actually takes there is no surrounding transaction at all,
 * verified in the source, so the choice costs nothing where it changes nothing. The fourth, a framework deleted in tool_lp,
 * is raised by the core AFTER the commit of the deletion (api::delete_framework()): it only sets right the provenance
 * rows of the framework, which the deletion has already made final, and joins no transaction whatever it declares.
 *
 * See \tool_vae\observer for why the account deletion needs its own handler.
 *
 * THE FOUR NOTIFICATION OBSERVERS ARE EXTERNAL, AND FOR THE OPPOSITE REASON (lot 7 ter). A purge
 * must belong to the act it reacts to; a message must not. Run after the commit, they announce
 * only what the site holds: the import of a certification record raises its departures inside
 * its own transaction, and an import that rolls back takes them with it, unheard. They do no more
 * than queue one ad hoc task per recipient - the sending happens in cron - and they catch
 * whatever they throw themselves, an \Error included, which the event manager would let through
 * into a request whose act is already committed. See \tool_vae\notifier.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\competency_user_evidence_deleted',
        'callback' => '\tool_vae\observer::user_evidence_deleted',
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\competency_user_evidence_updated',
        'callback' => '\tool_vae\observer::user_evidence_updated',
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\tool_vae\observer::user_deleted',
        'internal' => true,
    ],
    [
        'eventname' => '\core\event\competency_framework_deleted',
        'callback' => '\tool_vae\observer::framework_deleted',
        'internal' => true,
    ],
    [
        'eventname' => '\tool_vae\event\evidence_declared',
        'callback' => '\tool_vae\notifier::evidence_declared',
        'internal' => false,
    ],
    [
        'eventname' => '\tool_vae\event\piece_added',
        'callback' => '\tool_vae\notifier::piece_added',
        'internal' => false,
    ],
    [
        'eventname' => '\tool_vae\event\deposit_created',
        'callback' => '\tool_vae\notifier::deposit_created',
        'internal' => false,
    ],
    [
        'eventname' => '\tool_vae\event\opinion_created',
        'callback' => '\tool_vae\notifier::opinion_created',
        'internal' => false,
    ],
    [
        'eventname' => '\tool_vae\event\request_created',
        'callback' => '\tool_vae\notifier::request_created',
        'internal' => false,
    ],
    [
        'eventname' => '\tool_vae\event\evidence_attested',
        'callback' => '\tool_vae\notifier::evidence_attested',
        'internal' => false,
    ],
    [
        'eventname' => '\tool_vae\event\attestation_withdrawn',
        'callback' => '\tool_vae\notifier::attestation_withdrawn',
        'internal' => false,
    ],
    [
        'eventname' => '\tool_vae\event\withdrawal_requested',
        'callback' => '\tool_vae\notifier::withdrawal_requested',
        'internal' => false,
    ],
    [
        'eventname' => '\tool_vae\event\withdrawal_refused',
        'callback' => '\tool_vae\notifier::withdrawal_refused',
        'internal' => false,
    ],
    [
        'eventname' => '\tool_vae\event\competency_left_record',
        'callback' => '\tool_vae\notifier::competency_left_record',
        'internal' => false,
    ],
];
