<?php
// /local/referral/classes/observer.php
namespace local_referral;

defined('MOODLE_INTERNAL') || die();

class observer {
    /**
     * Handle user created event to record referral.
     *
     * @param \core\event\user_created $event
     */
    public static function user_created(\core\event\user_created $event) {
        if (empty($_COOKIE['referral_code'])) {
            return;
        }

        global $DB, $CFG;

        $code = clean_param($_COOKIE['referral_code'], PARAM_ALPHANUMEXT);
        if ($code === '') {
            return;
        }

        $marketer = $DB->get_record('local_ref_marketers', ['code' => $code], 'id', IGNORE_MISSING);
        if (!$marketer) {
            return;
        }

        $userid = $event->objectid;
        $exists = $DB->record_exists('local_ref_users', ['userid' => $userid]);
        if ($exists) {
            return;
        }

        $record = (object) [
            'userid' => $userid,
            'marketerid' => $marketer->id,
            'timecreated' => time(),
        ];
        $DB->insert_record('local_ref_users', $record);

        $path = isset($CFG->sessioncookiepath) ? $CFG->sessioncookiepath : '/';
        $domain = isset($CFG->sessioncookiedomain) ? $CFG->sessioncookiedomain : '';
        $secure = !empty($CFG->cookiesecure);
        $httponly = !empty($CFG->cookiehttponly);
        setcookie('referral_code', '', time() - 3600, $path, $domain, $secure, $httponly);
    }

    /**
     * Handle user enrolment created event to track commissions.
     *
     * @param \core\event\user_enrolment_created $event
     */
    public static function user_enrolment_created(\core\event\user_enrolment_created $event)
    {
        global $DB;

        $userid = (int) $event->relateduserid;
        $courseid = (int) $event->courseid;

        if ($userid <= 0 || $courseid <= 0) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();

        $referral = $DB->get_record('local_ref_users', ['userid' => $userid], 'marketerid', IGNORE_MISSING);
        if (!$referral) {
            $transaction->allow_commit();
            return;
        }

        $marketer = $DB->get_record(
            'local_ref_marketers',
            ['id' => $referral->marketerid],
            'id, commission_percentage',
            IGNORE_MISSING
        );
        if (!$marketer) {
            $transaction->allow_commit();
            return;
        }

        $amount = self::get_enrolment_amount($event->objectid);
        if ($amount <= 0) {
            $transaction->allow_commit();
            return;
        }

        $exists = $DB->record_exists('local_ref_commissions', ['userid' => $userid, 'courseid' => $courseid]);
        if ($exists) {
            $transaction->allow_commit();
            return;
        }

        $percentage = (int) $marketer->commission_percentage;
        if ($percentage < 0) {
            $percentage = 0;
        } else if ($percentage > 100) {
            $percentage = 100;
        }

        $commission = round(($amount * $percentage) / 100, 2);

        $record = (object) [
            'marketerid' => $marketer->id,
            'userid' => $userid,
            'courseid' => $courseid,
            'amount' => $amount,
            'commission' => $commission,
            'status' => 0,
            'timecreated' => time(),
        ];
        $DB->insert_record('local_ref_commissions', $record);

        $transaction->allow_commit();
    }

    /**
     * Resolve enrolment amount from the enrol plugin configuration.
     *
     * @param int $userenrolmentid
     * @return float
     */
    private static function get_enrolment_amount($userenrolmentid)
    {
        global $DB;

        if ($userenrolmentid <= 0) {
            return 0;
        }

        $sql = "SELECT e.cost
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE ue.id = :ueid";
        $cost = $DB->get_field_sql($sql, ['ueid' => $userenrolmentid], IGNORE_MISSING);
        if ($cost === false || $cost === null || $cost === '') {
            return 0;
        }

        return max(0, (float) $cost);
    }
}
