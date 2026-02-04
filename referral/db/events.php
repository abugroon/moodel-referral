<?php
// /local/referral/db/events.php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\\core\\event\\user_created',
        'callback' => '\\local_referral\\observer::user_created',
    ],
    [
        'eventname' => '\\core\\event\\user_enrolment_created',
        'callback' => '\\local_referral\\observer::user_enrolment_created',
    ],
];
