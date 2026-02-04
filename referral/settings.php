<?php
// /local/referral/settings.php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins',
        new admin_category('local_referral', get_string('pluginname', 'local_referral'))
    );

    $ADMIN->add('local_referral',
        new admin_externalpage('local_referral_manage',
            get_string('manage', 'local_referral'),
            new moodle_url('/local/referral/manage.php')
        )
    );

    $ADMIN->add('local_referral',
        new admin_externalpage('local_referral_report',
            get_string('report', 'local_referral'),
            new moodle_url('/local/referral/report.php')
        )
    );

    $ADMIN->add('local_referral',
        new admin_externalpage('local_referral_dashboard',
            get_string('dashboard', 'local_referral'),
            new moodle_url('/local/referral/dashboard.php')
        )
    );
}
