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
