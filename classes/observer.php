<?php
// /local/referral/classes/observer.php
namespace local_referral;

defined('MOODLE_INTERNAL') || die();

class observer {

    public static function user_created(\core\event\user_created $event) {
        if (empty($_COOKIE['referral_code'])) {
            return;
        }

        global $DB, $CFG;

        $code = clean_param($_COOKIE['referral_code'], PARAM_ALPHANUMEXT);
        if ($code === '') {
            return;
        }

        $profile = $DB->get_record('local_ref_marketer_profile', ['code' => $code], 'userid', IGNORE_MISSING);
        if (!$profile) {
            return;
        }

        $userid = $event->objectid;

        if ($DB->record_exists('local_ref_users', ['userid' => $userid])) {
            return;
        }

        $DB->insert_record('local_ref_users', (object)[
            'userid'      => $userid,
            'marketerid'  => $profile->userid,   // marketerid = marketer's user id
            'timecreated' => time(),
        ]);

        $path     = $CFG->sessioncookiepath ?? '/';
        $domain   = $CFG->sessioncookiedomain ?? '';
        $secure   = !empty($CFG->cookiesecure);
        $httponly = !empty($CFG->cookiehttponly);

        setcookie('referral_code', '', time() - 3600, $path, $domain, $secure, $httponly);
    }


    public static function user_enrolment_created(\core\event\user_enrolment_created $event)
    {
        global $DB;

        $userid   = (int)$event->relateduserid;
        $courseid = (int)$event->courseid;

        if ($userid <= 0 || $courseid <= 0) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();

        // Find which marketer referred this user
        $referral = $DB->get_record(
            'local_ref_users',
            ['userid' => $userid],
            'marketerid',
            IGNORE_MISSING
        );

        if (!$referral) {
            $transaction->allow_commit();
            return;
        }

        // marketerid is now the marketer's user id
        $marketer_userid = (int)$referral->marketerid;

        $profile = $DB->get_record(
            'local_ref_marketer_profile',
            ['userid' => $marketer_userid],
            'userid, commission_percentage, parent_userid',
            IGNORE_MISSING
        );

        if (!$profile) {
            $transaction->allow_commit();
            return;
        }

        $amount = self::get_enrolment_amount($event->objectid);

        if ($amount <= 0) {
            $transaction->allow_commit();
            return;
        }

        /* ===============================
           Child Commission
        ================================ */

        $commission = 0;

        $exists = $DB->record_exists('local_ref_commissions', [
            'userid'      => $userid,
            'courseid'    => $courseid,
            'marketerid'  => $profile->userid,
        ]);

        if (!$exists) {
            $percentage = max(0, min(100, (int)$profile->commission_percentage));
            $commission = round(($amount * $percentage) / 100, 2);

            $DB->insert_record('local_ref_commissions', (object)[
                'marketerid'  => $profile->userid,
                'userid'      => $userid,
                'courseid'    => $courseid,
                'amount'      => $amount,
                'commission'  => $commission,
                'status'      => 0,
                'timecreated' => time(),
            ]);
        } else {
            $existing = $DB->get_record('local_ref_commissions', [
                'userid'     => $userid,
                'courseid'   => $courseid,
                'marketerid' => $profile->userid,
            ], 'commission', IGNORE_MISSING);

            if ($existing) {
                $commission = (float)$existing->commission;
            }
        }

        /* ===============================
           Parent Commission
        ================================ */

        if (!empty($profile->parent_userid) && $amount > 0) {

            $parent = $DB->get_record(
                'local_ref_marketer_profile',
                ['userid' => $profile->parent_userid],
                'userid, commission_percentage, indirect_commission_percentage',
                IGNORE_MISSING
            );

            if ($parent) {

                $parentExists = $DB->record_exists('local_ref_commissions', [
                    'userid'     => $userid,
                    'courseid'   => $courseid,
                    'marketerid' => $parent->userid,
                ]);

                if (!$parentExists) {
                    $parentPercentage = max(0, min(100, (int)$parent->indirect_commission_percentage));
                    $parentCommission = round(($amount * $parentPercentage) / 100, 2);

                    $DB->insert_record('local_ref_commissions', (object)[
                        'marketerid'  => $parent->userid,
                        'userid'      => $userid,
                        'courseid'    => $courseid,
                        'amount'      => $amount,
                        'commission'  => $parentCommission,
                        'status'      => 0,
                        'timecreated' => time(),
                    ]);
                }
            }
        }

        $transaction->allow_commit();
    }


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

        if (!$cost) {
            return 0;
        }

        return max(0, (float)$cost);
    }
}
