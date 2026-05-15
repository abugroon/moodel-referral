<?php
// /local/referral/db/access.php
defined('MOODLE_INTERNAL') || die();

/**
 * Capability definitions for local_referral.
 *
 * @package   local_referral
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$capabilities = [

    // Full admin management: manage all marketers, approve all withdrawals.
    'local/referral:manage' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Approve or reject any marketer or teacher withdrawal request.
    'local/referral:approvewithdrawals' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // View full marketer hierarchy (admin view).
    'local/referral:viewhierarchy' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // A marketer can view their own account page (myaccount.php).
    // Granted dynamically in myaccount.php by checking marketer profile existence.
    'local/referral:viewownaccount' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [],
    ],
];
