<?php
// /local/referral/index.php
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

redirect(new moodle_url('/local/referral/manage.php'));
