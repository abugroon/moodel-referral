<?php
// /local/referral/lib.php
defined('MOODLE_INTERNAL') || die();

/**
 * Capture ?ref=CODE into a cookie before headers are sent.
 */
function local_referral_before_http_headers() {
    $ref = optional_param('ref', '', PARAM_ALPHANUMEXT);
    if ($ref === '') {
        return;
    }

    global $CFG;

    $expire = time() + (60 * 60 * 24 * 30);
    $path = isset($CFG->sessioncookiepath) ? $CFG->sessioncookiepath : '/';
    $domain = isset($CFG->sessioncookiedomain) ? $CFG->sessioncookiedomain : '';
    $secure = !empty($CFG->cookiesecure);
    $httponly = !empty($CFG->cookiehttponly);

    setcookie('referral_code', $ref, $expire, $path, $domain, $secure, $httponly);
}

function local_referral_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    require_login();
    require_capability('moodle/site:config', context_system::instance());
    if ($filearea !== 'disbursement_receipts') {
        return false;
    }
    $itemid  = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? ('/' . implode('/', $args) . '/') : '/';
    $fs   = get_file_storage();
    $file = $fs->get_file($context->id, 'local_referral', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
