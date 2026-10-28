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
 * What this plugin may tell, and to whom (lot 7 ter, reprise R2).
 *
 * The names are those of \tool_vae\notifier's constants, written out here because this
 * file is read at install time, before the plugin's classes can be autoloaded - the same reason
 * db/events.php spells its event names in full.
 *
 * EMAIL IS ON BY DEFAULT, AND THAT IS THE POINT. A manager who follows thirty candidates does
 * not open the platform to find out whether one of them has declared something; a candidate does
 * not log in to check whether a competency has left their record. A notice they would only see
 * after logging in would leave R2 where it started. Each person can still turn either channel off
 * in their own preferences, and the administrator for the whole site.
 *
 * THE `capability` KEY DECIDES WHO SEES THE PROVIDER, not who receives a given message. Moodle
 * looks for it anywhere the person holds it, user contexts included - a manager attached by
 * cohort holds `attest` in their candidates' contexts and nowhere else, and is found. It keeps
 * "a declaration awaits your attestation" off the preferences page of every candidate on
 * the site. Who receives each message is decided in \tool_vae\notifier, against the
 * candidate's own context, like every other right of this plugin.
 *
 * @package    tool_vae
 * @copyright  2026 Jean-Louis Martinez <jeanlouis@martinez.pro>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    // To the managers who follow a candidate and may attest: a declaration awaits them.
    'declarationpending' => [
        'capability' => 'tool/vae:attest',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // To the managers who follow a candidate and may attest: a supporting piece was added to a declaration that already
    // carries a signature, after it (T-13, lot 2). No title, no name of file: the candidate's name and the reference.
    'pieceafterattest' => [
        'capability' => 'tool/vae:attest',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // To the managers who follow a candidate and may read their deposits: a dossier de faisabilité was
    // deposited (T-21). No content in the message, only the candidate's name and the number.
    'depositmade' => [
        'capability' => 'tool/vae:readdeposit',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // To the candidate: a manager wrote an opinion on one of their deposits (T-10, lot 5b). No names,
    // no text: the number of the deposit.
    'opinionmade' => [
        'capability' => 'tool/vae:draftown',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // To the managers who follow a candidate and may attest: a declaration has waited for an attestation
    // for the number of days the administrator chose (T-22). The candidate's name and a count, no text.
    'declarationoverdue' => [
        'capability' => 'tool/vae:attest',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // To the candidate: a manager asks them for a precision on one of their declarations (T-20). No names,
    // no text: the fact that a question awaits, and the dossier where to read it.
    'precisionasked' => [
        'capability' => 'tool/vae:declare',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // To the candidate: one of their declarations was attested.
    'evidenceattested' => [
        'capability' => 'tool/vae:viewown',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // To the candidate: an attestation was withdrawn; the declaration stands.
    'attestationwithdrawn' => [
        'capability' => 'tool/vae:viewown',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // To a signatory: a colleague asks them to withdraw their attestation.
    'withdrawalrequested' => [
        'capability' => 'tool/vae:attest',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // To the manager who asked: the signatory refused.
    'withdrawalrefused' => [
        'capability' => 'tool/vae:attest',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // To each signatory: the candidate deleted, in the core's own screen, the evidence record a declaration hung off, and the
    // declaration went with the attestations it carried - theirs among them. The candidate's name and the reference of the
    // competency, no word of the declaration.
    'declarationpurged' => [
        'capability' => 'tool/vae:attest',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],

    // To the candidate: a competency they declared against is absent from its record's latest import.
    'competencyleftrecord' => [
        'capability' => 'tool/vae:viewown',
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
        ],
    ],
];
