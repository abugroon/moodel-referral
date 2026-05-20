<?php
// /local/referral/settings.php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    // ===== الفئة الرئيسية =====
    $ADMIN->add('localplugins',
        new admin_category('local_referral', get_string('pluginname', 'local_referral'))
    );

    // ===== إعدادات البرنامج =====
    $settingspage = new admin_settingpage(
        'local_referral_config',
        'إعدادات برنامج التسويق'
    );

    $settingspage->add(new admin_setting_configtext(
        'local_referral/default_commission_percentage',
        'نسبة العمولة المباشرة الافتراضية (%)',
        'النسبة المئوية للعمولة المباشرة التي تُطبَّق تلقائياً على المسوق عند التسجيل.',
        '10',
        PARAM_INT
    ));

    $settingspage->add(new admin_setting_configtext(
        'local_referral/default_indirect_commission_percentage',
        'نسبة العمولة غير المباشرة الافتراضية (%)',
        'النسبة المئوية للعمولة غير المباشرة (للمسوق الرئيسي عند بيع تابعيه). تُطبَّق تلقائياً عند التسجيل.',
        '0',
        PARAM_INT
    ));

    $settingspage->add(new admin_setting_configtext(
        'local_referral/min_withdrawal',
        'الحد الأدنى لطلب السحب',
        'أقل مبلغ مسموح للمسوق بطلب سحبه. القيمة بالعملة الافتراضية.',
        '10',
        PARAM_FLOAT
    ));

    $settingspage->add(new admin_setting_configcheckbox(
        'local_referral/enable_parent_linking',
        'تفعيل نظام ربط المسوقين (الأب والابن)',
        'عند التفعيل، يظهر عمود الأب في جدول المسوقين مع إمكانية تحديد المسوق الرئيسي لكل مسوق.',
        '0'
    ));

    $settingspage->add(new admin_setting_configtext(
        'local_referral/notification_emails',
        'إيميلات إشعارات الإدارة',
        'أدخل إيميلات الإدارة مفصولة بفواصل لتلقي إشعار عند كل طلب سحب جديد. مثال: admin@site.com, manager@site.com',
        '',
        PARAM_TEXT
    ));

    $ADMIN->add('local_referral', $settingspage);

    // ===== إدارة المسوقين =====
    $ADMIN->add('local_referral',
        new admin_externalpage(
            'local_referral_manage',
            get_string('manage', 'local_referral'),
            new moodle_url('/local/referral/admin/manage.php')
        )
    );

    // ===== توزيع الأرباح =====
    $ADMIN->add('local_referral',
        new admin_externalpage(
            'local_referral_payments',
            'توزيع العمولات',
            new moodle_url('/local/referral/admin/payments.php')
        )
    );

    // ===== التقارير =====
    $ADMIN->add('local_referral',
        new admin_externalpage(
            'local_referral_report',
            get_string('report', 'local_referral'),
            new moodle_url('/local/referral/admin/report.php')
        )
    );

    // ===== لوحة التحكم =====
    $ADMIN->add('local_referral',
        new admin_externalpage(
            'local_referral_dashboard',
            get_string('dashboard', 'local_referral'),
            new moodle_url('/local/referral/admin/dashboard.php')
        )
    );
}
