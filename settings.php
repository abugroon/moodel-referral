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

    $admin_page = new admin_externalpage(
        'local_referral_payments', // يجب أن يطابق الاسم الموجود في صفحتك
        'توزيع العمولات',             // العنوان الذي سيظهر في القائمة
        new moodle_url('/local/referral/payments.php') // المسار الصحيح لملفك
    );

    // إضافة الصفحة إلى قائمة الإدارة (مثلاً داخل قائمة التقارير أو قسم خاص)
    $ADMIN->add('reports', $admin_page);
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
