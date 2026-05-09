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

/**
 * Inject a marketing account link into the Moodle top navbar.
 * Shown only to users who have an active marketer profile.
 *
 * @param \renderer_base $renderer
 * @return string HTML
 */
function local_referral_render_navbar_output(\renderer_base $renderer): string {
    global $USER, $DB, $PAGE;

    if (!isloggedin() || isguestuser()) {
        return '';
    }

    // Fast indexed lookup — the table has a unique index on userid.
    if (!$DB->record_exists('local_ref_marketer_profile', ['userid' => $USER->id])) {
        return '';
    }

    $url    = new moodle_url('/local/referral/myaccount.php');
    $active = strpos($PAGE->url->out(false), '/local/referral/') !== false;
    $style  = 'display:inline-flex;align-items:center;gap:5px;white-space:nowrap;'
            . 'font-weight:600;font-size:.88rem;text-decoration:none;'
            . 'padding:6px 12px;border-radius:8px;transition:background .15s;direction:rtl;'
            . ($active
                ? 'background:rgba(37,99,235,.12);color:#1d4ed8;'
                : 'color:inherit;');

    return '<a href="' . $url->out(false) . '" style="' . $style . '">'
         . '&#x1F4E3; حسابي كمسوق'
         . '</a>';
}

function local_referral_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;
    require_login();

    if ($filearea === 'disbursement_receipts') {
        require_capability('moodle/site:config', context_system::instance());
    } elseif ($filearea === 'withdrawal_receipts') {
        // Admins can always download; the marketer can download their own receipt.
        $syscontext = context_system::instance();
        if (!has_capability('moodle/site:config', $syscontext)) {
            $itemid  = (int) $args[0];
            $wr = $DB->get_record('local_ref_withdrawals', ['id' => $itemid]);
            if (!$wr || $wr->marketerid != $USER->id) {
                return false;
            }
        }
    } else {
        return false;
    }

    $itemid   = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? ('/' . implode('/', $args) . '/') : '/';
    $fs   = get_file_storage();
    $file = $fs->get_file($context->id, 'local_referral', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
