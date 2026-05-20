<?php
// /local/referral/register.php
// صفحة تسجيل المسوق بنفسه

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/referral/register.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('التسجيل كمسوق');
$PAGE->set_heading('التسجيل كمسوق');

global $DB, $USER, $OUTPUT;

// Already a marketer?
$existing = $DB->get_record('local_ref_marketer_profile', ['userid' => $USER->id]);
if ($existing) {
    redirect(new moodle_url('/local/referral/myaccount.php'),
        'أنت مسجل مسوق بالفعل! سيتم توجيهك للوحة حسابك.', 2);
}

$save    = optional_param('save', 0, PARAM_BOOL);
$refcode = trim(optional_param('ref_code', '', PARAM_ALPHANUMEXT));
$errormsg = '';

if ($save && confirm_sesskey()) {

    // التحقق من الكود
    if ($refcode === '') {
        $errormsg = 'الكود مطلوب.';
    } elseif (strlen($refcode) < 4) {
        $errormsg = 'الكود يجب أن يكون 4 أحرف على الأقل.';
    } elseif ($DB->record_exists('local_ref_marketer_profile', ['code' => $refcode])) {
        $errormsg = 'هذا الكود مستخدم مسبقاً، اختر كوداً آخر.';
    }

    if ($errormsg === '') {
        $now = time();

        // نسب العمولة الافتراضية من الإعدادات
        $default_pct          = (int)(get_config('local_referral', 'default_commission_percentage') ?: 10);
        $default_indirect_pct = (int)(get_config('local_referral', 'default_indirect_commission_percentage') ?: 0);

        $DB->insert_record('local_ref_marketer_profile', (object)[
            'userid'                          => $USER->id,
            'code'                            => $refcode,
            'commission_percentage'           => $default_pct,
            'indirect_commission_percentage'  => $default_indirect_pct,
            'type'                            => 'main',
            'timecreated'                     => $now,
            'timemodified'                    => $now,
        ]);

        redirect(new moodle_url('/local/referral/myaccount.php'),
            'تم تسجيلك كمسوق بنجاح! 🎉', 2);
    }
}

echo $OUTPUT->header();
?>

<div class="container" style="max-width:520px;margin:40px auto;">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white fw-bold">
            📣 التسجيل كمسوق
        </div>
        <div class="card-body">

            <?php if ($errormsg): ?>
                <div class="alert alert-danger"><?php echo s($errormsg); ?></div>
            <?php endif; ?>

            <p class="text-muted">اختر كود إحالة فريد. سيُستخدم هذا الكود لتتبع المستخدمين الذين يسجلون عن طريقك.</p>

            <form method="post" action="">
                <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]); ?>
                <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'save','value'=>1]); ?>

                <div class="mb-3">
                    <label class="form-label fw-bold">كود الإحالة الخاص بك</label>
                    <input type="text"
                           name="ref_code"
                           class="form-control form-control-lg"
                           placeholder="مثال: ahmed2025"
                           value="<?php echo s($refcode); ?>"
                           pattern="[A-Za-z0-9]+"
                           minlength="4"
                           maxlength="32"
                           required>
                    <div class="form-text">أحرف إنجليزية وأرقام فقط، بدون مسافات.</div>
                </div>

                <button type="submit" class="btn btn-primary w-100 btn-lg fw-bold">
                    ✅ تسجيل كمسوق
                </button>
            </form>
        </div>
    </div>
</div>

<?php echo $OUTPUT->footer();
