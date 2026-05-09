<?php
// /local/referral/myaccount.php
// لوحة حساب المسوق — يشوف عمولاته ويطلب السحب

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/referral/myaccount.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('حسابي كمسوق');
$PAGE->set_heading('حسابي كمسوق');

global $DB, $USER, $OUTPUT;

// Check if this user has a marketer profile
$marketer = $DB->get_record('local_ref_marketer_profile', ['userid' => $USER->id]);

if (!$marketer) {
    echo $OUTPUT->header();
    echo '
    <div style="max-width:480px;margin:60px auto;text-align:center;padding:0 16px;">
        <div style="width:80px;height:80px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:2.2rem;">&#x1F4E3;</div>
        <h3 style="color:#1e2a3a;font-weight:800;font-size:1.4rem;margin-bottom:10px;">لست مسوقاً بعد!</h3>
        <p style="color:#64748b;margin-bottom:28px;font-size:.97rem;line-height:1.6;">
            فعّل حساب التسويق واستمتع بعمولات مجزية على كل مستخدم تجلبه للمنصة.
        </p>
        <a href="' . (new moodle_url('/local/referral/register.php'))->out(false) . '"
           style="background:#2563eb;color:#fff;padding:13px 36px;border-radius:12px;font-weight:700;text-decoration:none;font-size:1rem;display:inline-block;">
            &#x1F680; سجّل الآن كمسوق
        </a>
    </div>';
    echo $OUTPUT->footer();
    exit;
}

/* ===========================
   طلب سحب
=========================== */
$requestwithdraw = optional_param('requestwithdraw', 0, PARAM_BOOL);
$withdrawamount  = (float)optional_param('withdrawamount', 0, PARAM_FLOAT);
$withdrawmsg     = '';

$min_withdrawal = (float)(get_config('local_referral', 'min_withdrawal') ?: 10);

if ($requestwithdraw && confirm_sesskey()) {

    // العمولات غير المدفوعة (انتظار + معتمدة) = الرصيد المتاح للسحب
    $available = (float)$DB->get_field_sql(
        "SELECT COALESCE(SUM(commission),0)
           FROM {local_ref_commissions}
          WHERE marketerid = :mid AND status IN (0, 1)",
        ['mid' => $marketer->userid]
    );

    $pending_withdrawal = (float)$DB->get_field_sql(
        "SELECT COALESCE(SUM(amount),0)
           FROM {local_ref_withdrawals}
          WHERE marketerid = :mid AND status = 0",
        ['mid' => $marketer->userid]
    );

    $net_available = $available - $pending_withdrawal;

    if ($withdrawamount <= 0) {
        $withdrawmsg = 'error:أدخل مبلغاً صحيحاً.';
    } elseif ($withdrawamount < $min_withdrawal) {
        $withdrawmsg = 'error:الحد الأدنى للسحب هو ' . number_format($min_withdrawal, 2) . '.';
    } elseif ($withdrawamount > $net_available) {
        $withdrawmsg = 'error:المبلغ المطلوب أكبر من رصيدك المتاح (' . number_format($net_available, 2) . ').';
    } else {
        $DB->insert_record('local_ref_withdrawals', (object)[
            'marketerid'   => $marketer->userid,
            'amount'       => $withdrawamount,
            'status'       => 0,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
        $withdrawmsg = 'success:تم إرسال طلب السحب بنجاح! سيتم مراجعته من قِبَل الإدارة.';

        // ============================================================
        // إشعار الإدارة بطلب السحب الجديد
        // أضف إيميلات الإدارة في إعدادات البرنامج:
        //   Site Admin → Plugins → Local plugins → Referral → إعدادات
        //   حقل: "إيميلات إشعارات الإدارة"
        // ============================================================
        $notification_emails_raw = get_config('local_referral', 'notification_emails');
        if (!empty($notification_emails_raw)) {
            $marketer_name   = fullname($USER);
            $manage_url      = (new moodle_url('/local/referral/admin/manage.php'))->out(false);
            $subject         = '[إشعار] طلب سحب جديد من مسوق';
            $body_plain      = "طلب سحب جديد\n\n"
                             . "المسوق: {$marketer_name}\n"
                             . "المبلغ: " . number_format($withdrawamount, 2) . "\n"
                             . "رابط الإدارة: {$manage_url}";
            $body_html       = "<div dir='rtl' style='font-family:Arial;'>"
                             . "<h3 style='color:#2563eb;'>&#x1F4E4; طلب سحب جديد</h3>"
                             . "<table style='border-collapse:collapse;width:100%;max-width:480px;'>"
                             . "<tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;'>المسوق</td>"
                             . "    <td style='padding:8px 12px;'>{$marketer_name}</td></tr>"
                             . "<tr><td style='padding:8px 12px;background:#f8fafc;font-weight:700;'>المبلغ</td>"
                             . "    <td style='padding:8px 12px;font-size:1.1rem;font-weight:800;color:#059669;'>"
                             . number_format($withdrawamount, 2) . "</td></tr>"
                             . "</table>"
                             . "<p style='margin-top:16px;'>"
                             . "<a href='{$manage_url}' style='background:#2563eb;color:#fff;padding:10px 20px;"
                             . "border-radius:8px;text-decoration:none;font-weight:700;'>&#x1F4CB; مراجعة الطلب</a>"
                             . "</p></div>";

            $noreply  = \core_user::get_noreply_user();
            $emails   = array_filter(array_map('trim', explode(',', $notification_emails_raw)));
            foreach ($emails as $email) {
                if (!validate_email($email)) {
                    continue;
                }
                $recipient              = new stdClass();
                $recipient->id          = -1;
                $recipient->email       = $email;
                $recipient->firstname   = 'Admin';
                $recipient->lastname    = '';
                $recipient->maildisplay = 0;
                $recipient->mailformat  = 1;
                $recipient->firstnamephonetic = '';
                $recipient->lastnamephonetic  = '';
                $recipient->middlename        = '';
                $recipient->alternatename     = '';
                $recipient->deleted           = 0;
                $recipient->suspended         = 0;
                $recipient->auth              = 'manual';
                $recipient->username          = $email;
                email_to_user($recipient, $noreply, $subject, $body_plain, $body_html);
            }
        }
    }
}

/* ===========================
   إحصائيات
=========================== */
$total_commission = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid = :mid",
    ['mid' => $marketer->userid]
);
$approved_commission = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid = :mid AND status = 1",
    ['mid' => $marketer->userid]
);
$paid_commission = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid = :mid AND status = 2",
    ['mid' => $marketer->userid]
);
$pending_withdrawal_total = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(amount),0) FROM {local_ref_withdrawals} WHERE marketerid = :mid AND status = 0",
    ['mid' => $marketer->userid]
);
// الرصيد القابل للسحب = كل العمولات غير المدفوعة ناقص طلبات السحب المعلقة
$withdrawable_total = $total_commission - $paid_commission;
$net_balance        = $withdrawable_total - $pending_withdrawal_total;

$referred_count = $DB->count_records('local_ref_users', ['marketerid' => $marketer->userid]);
$refurl = (new moodle_url('/', ['ref' => $marketer->code]))->out(false);

echo $OUTPUT->header();
?>

<style>
/* ==========================================
   MYACCOUNT — MARKETER DASHBOARD STYLES
========================================== */
:root {
    --mk-primary:   #2563eb;
    --mk-success:   #059669;
    --mk-warning:   #d97706;
    --mk-danger:    #dc2626;
    --mk-info:      #0891b2;
    --mk-purple:    #7c3aed;
    --mk-dark:      #1e293b;
    --mk-muted:     #64748b;
    --mk-bg:        #f1f5f9;
    --mk-card:      #ffffff;
    --mk-border:    #e2e8f0;
}

.mk-wrapper {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px 16px 52px;
    direction: rtl;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}

/* ===== HERO ===== */
.mk-hero {
    background: linear-gradient(135deg, #1e293b 0%, #1e3a6e 55%, #2563eb 100%);
    border-radius: 20px;
    padding: 32px 36px;
    color: #fff;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    position: relative;
    overflow: hidden;
}
.mk-hero::before {
    content: '';
    position: absolute;
    top: -60px; left: -60px;
    width: 240px; height: 240px;
    background: rgba(255,255,255,.04);
    border-radius: 50%;
    pointer-events: none;
}
.mk-hero::after {
    content: '';
    position: absolute;
    bottom: -70px; right: -40px;
    width: 280px; height: 280px;
    background: rgba(255,255,255,.03);
    border-radius: 50%;
    pointer-events: none;
}
.mk-hero-left h2 {
    font-size: 1.55rem;
    font-weight: 800;
    margin: 0 0 6px;
    position: relative;
}
.mk-hero-left .hero-sub { margin: 0; opacity: .75; font-size: .93rem; position: relative; }
.mk-code-box {
    background: rgba(255,255,255,.12);
    border: 1.5px solid rgba(255,255,255,.22);
    border-radius: 14px;
    padding: 14px 24px;
    text-align: center;
    position: relative;
    backdrop-filter: blur(6px);
    min-width: 140px;
}
.mk-code-box .cb-lbl  { font-size: .7rem; opacity: .7; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 4px; }
.mk-code-box .cb-code { font-size: 1.5rem; font-weight: 800; letter-spacing: .1em; }
.mk-code-box .cb-pct  { font-size: .78rem; opacity: .75; margin-top: 4px; }

/* ===== ALERT ===== */
.mk-alert {
    border-radius: 12px;
    padding: 14px 20px;
    margin-bottom: 20px;
    font-weight: 600;
    font-size: .95rem;
    display: flex;
    align-items: center;
    gap: 10px;
}
.mk-alert.success {
    background: #d1fae5;
    color: #065f46;
    border: 1.5px solid #6ee7b7;
}
.mk-alert.error {
    background: #fee2e2;
    color: #991b1b;
    border: 1.5px solid #fca5a5;
}

/* ===== REF LINK CARD ===== */
.mk-reflink {
    background: var(--mk-card);
    border-radius: 14px;
    padding: 20px 24px;
    margin-bottom: 22px;
    box-shadow: 0 1px 8px rgba(0,0,0,.08);
    border: 1px solid var(--mk-border);
}
.mk-reflink .rl-label {
    font-size: .75rem;
    font-weight: 700;
    color: var(--mk-muted);
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.mk-reflink .rl-inner {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.mk-reflink input {
    flex: 1 1 280px;
    padding: 11px 14px;
    border: 1.5px solid var(--mk-border);
    border-radius: 10px;
    font-size: .9rem;
    color: #334155;
    background: #f8fafc;
    min-width: 0;
}
.mk-reflink input:focus { outline: none; border-color: var(--mk-primary); background: #fff; }
.btn-copy {
    background: var(--mk-primary);
    color: #fff;
    border: none;
    padding: 11px 24px;
    border-radius: 10px;
    font-weight: 700;
    font-size: .9rem;
    cursor: pointer;
    white-space: nowrap;
    transition: filter .15s, transform .1s;
}
.btn-copy:hover { filter: brightness(.9); transform: translateY(-1px); }

/* ===== STATS GRID ===== */
.mk-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.mk-stat-card {
    background: var(--mk-card);
    border-radius: 14px;
    padding: 22px 18px;
    text-align: center;
    box-shadow: 0 1px 8px rgba(0,0,0,.08);
    border-bottom: 4px solid transparent;
    border: 1px solid var(--mk-border);
    border-bottom-width: 4px;
    transition: transform .15s, box-shadow .15s;
    position: relative;
    overflow: hidden;
}
.mk-stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 16px rgba(0,0,0,.12); }
.mk-stat-card.c-blue   { border-bottom-color: var(--mk-primary); }
.mk-stat-card.c-gray   { border-bottom-color: #94a3b8; }
.mk-stat-card.c-green  { border-bottom-color: var(--mk-success); }
.mk-stat-card.c-purple { border-bottom-color: var(--mk-purple); }
.mk-stat-card.c-amber  { border-bottom-color: var(--mk-warning); }
.mk-stat-card .sc-icon  { font-size: 1.7rem; margin-bottom: 10px; }
.mk-stat-card .sc-label {
    font-size: .72rem;
    color: var(--mk-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    margin-bottom: 6px;
}
.mk-stat-card .sc-value {
    font-size: 1.5rem;
    font-weight: 800;
    line-height: 1.1;
    color: var(--mk-dark);
}
.mk-stat-card.c-blue   .sc-value { color: #1d4ed8; }
.mk-stat-card.c-green  .sc-value { color: #065f46; }
.mk-stat-card.c-purple .sc-value { color: #5b21b6; }
.mk-stat-card.c-amber  .sc-value { color: #92400e; }
.mk-stat-card.c-gray   .sc-value { color: #334155; }

/* ===== MAIN GRID ===== */
.mk-grid {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 20px;
    align-items: start;
    direction: rtl;
}
@media (max-width: 900px) {
    .mk-grid { grid-template-columns: 1fr; }
}

/* ===== PANEL ===== */
.mk-panel {
    background: var(--mk-card);
    border-radius: 16px;
    box-shadow: 0 1px 8px rgba(0,0,0,.08);
    overflow: hidden;
    margin-bottom: 20px;
    border: 1px solid var(--mk-border);
}
.mk-panel-hdr {
    padding: 16px 22px;
    font-weight: 700;
    font-size: .93rem;
    color: var(--mk-dark);
    border-bottom: 1px solid var(--mk-border);
    display: flex;
    align-items: center;
    gap: 8px;
    background: #f8fafc;
}
.mk-panel-hdr.hdr-amber {
    background: linear-gradient(90deg, #d97706 0%, #b45309 100%);
    color: #fff;
    border: none;
}
.mk-panel-hdr.hdr-dark {
    background: var(--mk-dark);
    color: #fff;
    border: none;
}
.mk-panel-body { padding: 22px; }

/* ===== BALANCE DISPLAY ===== */
.balance-box {
    background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 16px;
    border: 1.5px solid #6ee7b7;
}
.balance-box .bb-label  { font-size: .75rem; color: #065f46; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
.balance-box .bb-amount { font-size: 2rem; font-weight: 800; color: #065f46; line-height: 1; }

.min-note {
    font-size: .79rem;
    color: var(--mk-muted);
    margin-bottom: 16px;
    padding: 8px 12px;
    background: #fef3c7;
    border-radius: 8px;
    border: 1px solid #fde68a;
    color: #92400e;
    font-weight: 600;
}

.form-grp { margin-bottom: 16px; }
.form-grp label { display: block; font-size: .82rem; font-weight: 700; color: #374151; margin-bottom: 6px; }
.form-ctrl {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid var(--mk-border);
    border-radius: 10px;
    font-size: .95rem;
    color: var(--mk-dark);
    box-sizing: border-box;
    transition: border-color .15s;
    font-weight: 600;
    background: #f8fafc;
}
.form-ctrl:focus { outline: none; border-color: var(--mk-warning); background: #fff; }

.btn-withdraw {
    width: 100%;
    background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
    color: #fff;
    border: none;
    padding: 13px;
    border-radius: 10px;
    font-size: 1rem;
    font-weight: 800;
    cursor: pointer;
    transition: filter .15s, transform .1s;
    letter-spacing: .02em;
}
.btn-withdraw:hover { filter: brightness(.95); transform: translateY(-1px); }

/* ===== WITHDRAWAL TABLE ===== */
.wr-tbl { width: 100%; border-collapse: collapse; }
.wr-tbl th {
    padding: 10px 14px;
    font-size: .73rem;
    font-weight: 700;
    color: var(--mk-muted);
    text-align: right;
    border-bottom: 2px solid var(--mk-border);
    text-transform: uppercase;
    letter-spacing: .04em;
    background: #f8fafc;
}
.wr-tbl td {
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
    font-size: .85rem;
    color: #334155;
}
.wr-tbl tr:last-child td { border-bottom: none; }
.wr-tbl tr:hover td { background: #f8faff; }

/* ===== COMMISSION TABLE ===== */
.comm-tbl { width: 100%; border-collapse: collapse; }
.comm-tbl th {
    padding: 13px 16px;
    font-size: .73rem;
    font-weight: 700;
    color: #fff;
    text-align: right;
    background: var(--mk-dark);
    text-transform: uppercase;
    letter-spacing: .04em;
}
.comm-tbl th:first-child { border-radius: 0 0 0 0; }
.comm-tbl td {
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    font-size: .86rem;
    color: #334155;
    vertical-align: middle;
}
.comm-tbl tr:hover td { background: #f8faff; }
.comm-tbl tr:last-child td { border-bottom: none; }
.comm-amount { font-weight: 800; color: var(--mk-success); font-size: .95rem; }

/* ===== STATUS BADGES ===== */
.sbadge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: .73rem;
    font-weight: 700;
    white-space: nowrap;
}
.sb-pending  { background: #fef3c7; color: #92400e; }
.sb-approved { background: #d1fae5; color: #065f46; }
.sb-paid     { background: #dbeafe; color: #1d4ed8; }
.sb-rejected { background: #fee2e2; color: #991b1b; }

/* ===== EMPTY ===== */
.empty-msg { text-align: center; padding: 36px 16px; color: #94a3b8; font-size: .92rem; }
.empty-msg .em-icon { font-size: 2rem; margin-bottom: 10px; opacity: .5; }

/* ===== RESPONSIVE ===== */
@media (max-width: 600px) {
    .mk-hero { padding: 22px 20px; }
    .mk-hero-left h2 { font-size: 1.3rem; }
    .mk-stats { grid-template-columns: 1fr 1fr; }
}
</style>

<div class="mk-wrapper">

    <!-- ===== HERO ===== -->
    <div class="mk-hero">
        <div class="mk-hero-left">
            <h2>&#x1F44B; مرحباً، <?php echo s(fullname($USER)); ?></h2>
            <p class="hero-sub">لوحة حسابك كمسوق &mdash; تابع أداءك وعمولاتك</p>
        </div>
        <div class="mk-code-box">
            <div class="cb-lbl">كود الإحالة</div>
            <div class="cb-code"><?php echo s($marketer->code); ?></div>
        </div>
    </div>

    <?php if ($withdrawmsg):
        [$type, $msg] = explode(':', $withdrawmsg, 2);
        $icon = $type === 'success' ? '&#x2705;' : '&#x26A0;&#xFE0F;';
    ?>
    <div class="mk-alert <?php echo $type === 'error' ? 'error' : 'success'; ?>">
        <span><?php echo $icon; ?></span>
        <span><?php echo s($msg); ?></span>
    </div>
    <?php endif; ?>

    <!-- ===== REFERRAL LINK ===== -->
    <div class="mk-reflink">
        <div class="rl-label">&#x1F517; رابط الإحالة الخاص بك</div>
        <div class="rl-inner">
            <input type="text" id="refLinkInput" value="<?php echo s($refurl); ?>" readonly>
            <button class="btn-copy" id="copyBtn"
                onclick="navigator.clipboard.writeText(document.getElementById('refLinkInput').value).then(()=>{
                    document.getElementById('copyBtn').textContent = '&#x2713; تم النسخ';
                    setTimeout(()=>document.getElementById('copyBtn').textContent='نسخ الرابط', 2200);
                })">
                نسخ الرابط
            </button>
        </div>
    </div>

    <!-- ===== STATS ===== -->
    <div class="mk-stats">
        <?php
        $cards = [
            ['c-blue',   '&#x1F465;', 'محوَّلون',         $referred_count],
            ['c-gray',   '&#x1F4B0;', 'إجمالي العمولات',  number_format($total_commission, 2)],
            ['c-green',  '&#x2705;',  'عمولات معتمدة',    number_format($approved_commission, 2)],
            ['c-purple', '&#x1F4B8;', 'تم دفعها',         number_format($paid_commission, 2)],
            ['c-amber',  '&#x1F3E6;', 'قابل للسحب',       number_format($net_balance, 2)],
        ];
        foreach ($cards as [$cls, $icon, $label, $val]):
        ?>
        <div class="mk-stat-card <?php echo $cls; ?>">
            <div class="sc-icon"><?php echo $icon; ?></div>
            <div class="sc-label"><?php echo $label; ?></div>
            <div class="sc-value"><?php echo $val; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ===== MAIN GRID ===== -->
    <div class="mk-grid">

        <!-- عمود اليمين: سجل العمولات (الأوسع) -->
        <div>
            <div class="mk-panel">
                <div class="mk-panel-hdr hdr-dark">&#x1F4CA; سجل عمولاتي</div>
                <div class="mk-panel-body" style="padding:0;">
                    <?php
                    $sql = "SELECT c.id, c.amount, c.commission, c.status, c.timecreated,
                                   u.firstname, u.lastname,
                                   crs.fullname as coursename
                              FROM {local_ref_commissions} c
                              JOIN {user} u ON u.id = c.userid
                              JOIN {course} crs ON crs.id = c.courseid
                             WHERE c.marketerid = :mid
                          ORDER BY c.timecreated DESC";
                    $commissions = $DB->get_records_sql($sql, ['mid' => $marketer->userid]);
                    ?>
                    <?php if (empty($commissions)): ?>
                        <div class="empty-msg">
                            <div class="em-icon">&#x1F4B8;</div>
                            <p>لا توجد عمولات بعد.<br>
                            <span style="font-size:.85rem;">شارك رابط الإحالة لتبدأ الكسب!</span></p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table class="comm-tbl">
                                <thead>
                                    <tr>
                                        <th>المستخدم</th>
                                        <th>الكورس</th>
                                        <th>عمولتي</th>
                                        <th>الحالة</th>
                                        <th>التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $statuses = [
                                    0 => ['&#x1F550; انتظار', 'sb-pending'],
                                    1 => ['&#x2705; معتمد',   'sb-approved'],
                                    2 => ['&#x1F4B8; مدفوع',  'sb-paid'],
                                ];
                                foreach ($commissions as $c):
                                    [$slabel, $sclass] = $statuses[$c->status] ?? ['-', 'sb-pending'];
                                ?>
                                    <tr>
                                        <td style="font-weight:700;color:var(--mk-dark);"><?php echo s($c->firstname . ' ' . $c->lastname); ?></td>
                                        <td style="color:var(--mk-muted);font-size:.83rem;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo s($c->coursename); ?>">
                                            <?php echo s($c->coursename); ?>
                                        </td>
                                        <td><span class="comm-amount"><?php echo number_format($c->commission, 2); ?></span></td>
                                        <td><span class="sbadge <?php echo $sclass; ?>"><?php echo $slabel; ?></span></td>
                                        <td style="color:#94a3b8;font-size:.78rem;white-space:nowrap;"><?php echo userdate($c->timecreated, '%d/%m/%Y'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- عمود اليسار: طلب السحب + السجل (الأضيق) -->
        <div>

            <!-- طلب سحب -->
            <div class="mk-panel">
                <div class="mk-panel-hdr hdr-amber">&#x1F4B3; طلب سحب عمولة</div>
                <div class="mk-panel-body">

                    <?php if ($withdrawable_total <= 0): ?>
                        <!-- لا توجد عمولات بعد -->
                        <div class="empty-msg">
                            <div class="em-icon">&#x1F4B3;</div>
                            <p>لا توجد عمولات بعد.<br>
                            <span style="font-size:.82rem;color:#b0b8c8;">شارك رابط الإحالة لتبدأ الكسب!</span></p>
                        </div>

                    <?php elseif ($net_balance <= 0): ?>
                        <!-- الرصيد صفر — كل المعتمد موجود في طلب سحب معلق -->
                        <div class="wd-info-box" style="background:#fef9ec;border:1.5px solid #fde68a;border-radius:12px;padding:16px 18px;text-align:center;">
                            <div style="font-size:1.6rem;margin-bottom:8px;">&#x23F3;</div>
                            <p style="margin:0;font-weight:700;color:#92400e;font-size:.93rem;">طلب سحب معلق بانتظار الموافقة</p>
                            <p style="margin:6px 0 0;font-size:.8rem;color:#b45309;">رصيدك المعتمد محجوز بطلب سحب حالي.</p>
                        </div>

                    <?php else: ?>
                        <!-- فيه رصيد — أظهر الفورم دائماً -->
                        <div class="balance-box">
                            <div class="bb-label">رصيدك المتاح للسحب</div>
                            <div class="bb-amount"><?php echo number_format($net_balance, 2); ?></div>
                        </div>

                        <?php if ($net_balance < $min_withdrawal): ?>
                            <!-- تحذير: الرصيد أقل من الحد الأدنى، لكن الفورم يظهر -->
                            <div class="min-note" style="background:#fee2e2;border-color:#fca5a5;color:#991b1b;">
                                &#x26A0; رصيدك أقل من الحد الأدنى للسحب
                                (<?php echo number_format($min_withdrawal, 2); ?>)
                            </div>
                        <?php else: ?>
                            <div class="min-note">
                                &#x2139; الحد الأدنى للسحب: <?php echo number_format($min_withdrawal, 2); ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]); ?>
                            <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'requestwithdraw','value'=>1]); ?>
                            <div class="form-grp">
                                <label>المبلغ المراد سحبه</label>
                                <input type="number"
                                       name="withdrawamount"
                                       class="form-ctrl"
                                       min="<?php echo $min_withdrawal > 0 ? $min_withdrawal : '0.01'; ?>"
                                       max="<?php echo $net_balance; ?>"
                                       step="0.01"
                                       placeholder="0.00"
                                       required>
                            </div>
                            <button type="submit" class="btn-withdraw"
                                <?php echo $net_balance < $min_withdrawal ? 'disabled style="opacity:.55;cursor:not-allowed;"' : ''; ?>>
                                &#x1F3E7; إرسال طلب السحب
                            </button>
                            <?php if ($net_balance < $min_withdrawal): ?>
                                <p style="text-align:center;font-size:.78rem;color:#94a3b8;margin-top:8px;">
                                    الزر يُفعَّل عند بلوغ الحد الأدنى
                                </p>
                            <?php endif; ?>
                        </form>

                    <?php endif; ?>
                </div>
            </div>

            <!-- طلبات السحب السابقة -->
            <div class="mk-panel">
                <div class="mk-panel-hdr">&#x1F4CB; سجل طلبات السحب</div>
                <div class="mk-panel-body" style="padding:0;">
                    <?php
                    $withdrawals = $DB->get_records('local_ref_withdrawals',
                        ['marketerid' => $marketer->userid], 'timecreated DESC', '*', 0, 10);
                    if (empty($withdrawals)):
                    ?>
                        <div class="empty-msg">
                            <div class="em-icon">&#x1F4C4;</div>
                            <p>لا توجد طلبات سحب بعد.</p>
                        </div>
                    <?php else: ?>
                        <table class="wr-tbl">
                            <thead>
                                <tr>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                    <th>التاريخ</th>
                                    <th>الإيصال</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php
                            $wr_ctx = context_system::instance();
                            foreach ($withdrawals as $wr):
                                $wr_data = [
                                    0 => ['&#x1F550; مراجعة', 'sb-pending'],
                                    1 => ['&#x2705; مدفوع',   'sb-approved'],
                                    2 => ['&#x274C; مرفوض',   'sb-rejected'],
                                ];
                                [$wrlabel, $wrcls] = $wr_data[$wr->status] ?? ['-', 'sb-pending'];
                                $receipt_link = '';
                                if (!empty($wr->receipt_file)) {
                                    $receipt_url = moodle_url::make_pluginfile_url(
                                        $wr_ctx->id, 'local_referral', 'withdrawal_receipts',
                                        $wr->id, '/', $wr->receipt_file, true
                                    );
                                    $receipt_link = '<a href="' . $receipt_url->out(false) . '" target="_blank"
                                        style="font-size:.78rem;font-weight:700;color:#2563eb;text-decoration:none;">
                                        &#x1F4CE; تحميل</a>';
                                } else {
                                    $receipt_link = '<span style="color:#94a3b8;font-size:.78rem;">—</span>';
                                }
                            ?>
                                <tr>
                                    <td style="font-weight:800;color:#065f46;font-size:.95rem;"><?php echo number_format($wr->amount, 2); ?></td>
                                    <td><span class="sbadge <?php echo $wrcls; ?>"><?php echo $wrlabel; ?></span></td>
                                    <td style="color:#94a3b8;font-size:.78rem;"><?php echo userdate($wr->timecreated, '%d/%m/%Y'); ?></td>
                                    <td><?php echo $receipt_link; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </div><!-- end mk-grid -->

</div><!-- end mk-wrapper -->

<?php echo $OUTPUT->footer();
