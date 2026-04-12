<?php
// /local/referral/admin/manage.php
// لوحة إدارة المسوقين — للمدير فقط

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/tabs.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_referral_manage');

global $DB, $OUTPUT;

$action     = optional_param('action', '', PARAM_ALPHANUMEXT);
$marketerid = optional_param('marketerid', 0, PARAM_INT);
$wid        = optional_param('wid', 0, PARAM_INT);
$delete     = optional_param('delete', 0, PARAM_INT);
$confirm    = optional_param('confirm', 0, PARAM_BOOL);
$now        = time();

$base_url = new moodle_url('/local/referral/admin/manage.php');

/* تحميل الإعدادات الحالية */
$min_withdrawal    = (float)(get_config('local_referral', 'min_withdrawal') ?: 10);
$def_commission    = (int)(get_config('local_referral', 'default_commission_percentage') ?: 10);
$enable_parent     = (bool)(get_config('local_referral', 'enable_parent_linking') ?: 0);

/* =====================================================
   ACTION: حفظ الإعدادات
===================================================== */
if ($action === 'savesettings' && confirm_sesskey()) {
    $new_min = optional_param('min_withdrawal', 10, PARAM_FLOAT);
    $new_def = optional_param('default_commission', 10, PARAM_INT);
    set_config('min_withdrawal', max(0, $new_min), 'local_referral');
    set_config('default_commission_percentage', max(0, min(100, $new_def)), 'local_referral');
    redirect($base_url, 'تم حفظ الإعدادات بنجاح.', 2);
}

/* =====================================================
   ACTION: دفع العمولات المعتمدة
===================================================== */
if ($action === 'pay' && $marketerid && confirm_sesskey()) {
    $DB->execute(
        "UPDATE {local_ref_commissions} SET status = 2 WHERE marketerid = :mid AND status = 1",
        ['mid' => $marketerid]
    );
    redirect($base_url, 'تم تسجيل الدفع بنجاح.', 2);
}

/* =====================================================
   ACTION: تحديد الأب
===================================================== */
if ($action === 'setparent' && $marketerid && confirm_sesskey()) {
    $parentid = optional_param('parentid', 0, PARAM_INT);
    if ($parentid == $marketerid) {
        redirect($base_url, 'لا يمكن ربط المسوق بنفسه.', 2);
    }
    $DB->set_field('local_ref_marketers', 'parent_id',    $parentid ?: null, ['id' => $marketerid]);
    $DB->set_field('local_ref_marketers', 'timemodified', $now,              ['id' => $marketerid]);
    redirect($base_url, 'تم تحديث المسوق الأب.', 2);
}

/* =====================================================
   ACTION: تحديث نسبة العمولة
===================================================== */
if ($action === 'setcommission' && $marketerid && confirm_sesskey()) {
    $pct = optional_param('commission', 10, PARAM_INT);
    $pct = max(0, min(100, $pct));
    $DB->set_field('local_ref_marketers', 'commission_percentage', $pct, ['id' => $marketerid]);
    $DB->set_field('local_ref_marketers', 'timemodified', $now, ['id' => $marketerid]);
    redirect($base_url, 'تم تحديث نسبة العمولة.', 2);
}

/* =====================================================
   ACTION: الموافقة على طلب سحب
===================================================== */
if ($action === 'paywithdrawal' && $wid && confirm_sesskey()) {
    $DB->set_field('local_ref_withdrawals', 'status', 1, ['id' => $wid]);
    $DB->set_field('local_ref_withdrawals', 'timemodified', $now, ['id' => $wid]);
    redirect($base_url, 'تم تأكيد الدفع لطلب السحب.', 2);
}

/* =====================================================
   ACTION: رفض طلب سحب
===================================================== */
if ($action === 'rejectwithdrawal' && $wid && confirm_sesskey()) {
    $DB->set_field('local_ref_withdrawals', 'status', 2, ['id' => $wid]);
    $DB->set_field('local_ref_withdrawals', 'timemodified', $now, ['id' => $wid]);
    redirect($base_url, 'تم رفض طلب السحب.', 2);
}

/* =====================================================
   ACTION: حذف مسوق
===================================================== */
if ($delete) {
    $marketer = $DB->get_record('local_ref_marketers', ['id' => $delete], '*', IGNORE_MISSING);
    if (!$marketer) {
        redirect($base_url);
    }
    if ($confirm && confirm_sesskey()) {
        $DB->delete_records('local_ref_commissions',  ['marketerid' => $delete]);
        $DB->delete_records('local_ref_users',        ['marketerid' => $delete]);
        $DB->delete_records('local_ref_withdrawals',  ['marketerid' => $delete]);
        $DB->delete_records('local_ref_marketers',    ['id' => $delete]);
        redirect($base_url, 'تم حذف المسوق بنجاح.', 2);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        'هل أنت متأكد من حذف هذا المسوق؟ سيتم حذف جميع عمولاته وطلبات سحبه.',
        new moodle_url('/local/referral/admin/manage.php', ['delete' => $delete, 'confirm' => 1, 'sesskey' => sesskey()]),
        $base_url
    );
    echo $OUTPUT->footer();
    exit;
}

/* =====================================================
   تحميل البيانات
===================================================== */
$marketers = $DB->get_records_sql(
    "SELECT m.*, u.firstname, u.lastname, u.email,
            p.name AS parentname, p.code AS parentcode
       FROM {local_ref_marketers} m
  LEFT JOIN {user} u ON u.id = m.userid
  LEFT JOIN {local_ref_marketers} p ON p.id = m.parent_id
      WHERE m.userid IS NOT NULL
   ORDER BY m.name ASC"
);
// قائمة كل المسوقين (للـ dropdown)
$all_marketers = $DB->get_records('local_ref_marketers', null, 'name ASC', 'id, name, code');

$total_marketers           = count($marketers);
$total_all                 = (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions}");
$total_approved            = (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE status=1");
$total_paid                = (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE status=2");
$pending_withdrawals_count = (int)$DB->count_records('local_ref_withdrawals', ['status' => 0]);

$pending_withdrawals = $DB->get_records_sql(
    "SELECT w.*, m.name as marketername, m.code, u.firstname, u.lastname
       FROM {local_ref_withdrawals} w
       JOIN {local_ref_marketers} m ON m.id = w.marketerid
  LEFT JOIN {user} u ON u.id = m.userid
      WHERE w.status = 0
   ORDER BY w.timecreated ASC"
);

$stats = [];
foreach ($marketers as $m) {
    $stats[$m->id] = [
        'referred' => (int)$DB->count_records('local_ref_users', ['marketerid' => $m->id]),
        'total'    => (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid=:mid", ['mid' => $m->id]),
        'pending'  => (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid=:mid AND status=0", ['mid' => $m->id]),
        'approved' => (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid=:mid AND status=1", ['mid' => $m->id]),
        'paid'     => (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid=:mid AND status=2", ['mid' => $m->id]),
    ];
}

echo $OUTPUT->header();
echo local_referral_admin_tabs('manage');
?>

<style>
/* ==========================================
   MANAGE MARKETERS — ADMIN STYLES
========================================== */
:root {
    --adm-primary:   #2563eb;
    --adm-success:   #059669;
    --adm-warning:   #d97706;
    --adm-danger:    #dc2626;
    --adm-info:      #0891b2;
    --adm-purple:    #7c3aed;
    --adm-dark:      #1e2a3a;
    --adm-bg:        #f1f5f9;
    --adm-card:      #ffffff;
    --adm-border:    #e2e8f0;
}

body { background: var(--adm-bg); }

/* ===== PAGE HEADER ===== */
.adm-page-header {
    background: linear-gradient(135deg, #1e2a3a 0%, #1e3a6e 60%, #2563eb 100%);
    color: #fff;
    border-radius: 18px;
    padding: 30px 36px;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    position: relative;
    overflow: hidden;
}
.adm-page-header::after {
    content: '';
    position: absolute;
    bottom: -50px; right: -30px;
    width: 220px; height: 220px;
    background: rgba(255,255,255,.04);
    border-radius: 50%;
    pointer-events: none;
}
.adm-page-header h1 { font-size: 1.75rem; font-weight: 800; margin: 0 0 4px; }
.adm-page-header p  { margin: 0; opacity: .75; font-size: .93rem; }

/* ===== SUMMARY CARDS ===== */
.adm-summary {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
    gap: 16px;
    margin-bottom: 28px;
}
.adm-scard {
    background: var(--adm-card);
    border-radius: 14px;
    padding: 20px 18px;
    box-shadow: 0 1px 8px rgba(0,0,0,.08);
    border-top: 4px solid var(--adm-primary);
    position: relative;
    overflow: hidden;
}
.adm-scard.sc-success { border-top-color: var(--adm-success); }
.adm-scard.sc-info    { border-top-color: var(--adm-info); }
.adm-scard.sc-warning { border-top-color: var(--adm-warning); }
.adm-scard.sc-danger  { border-top-color: var(--adm-danger); }
.adm-scard .sc-lbl  { font-size: .75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.adm-scard .sc-val  { font-size: 1.65rem; font-weight: 800; margin-top: 6px; color: var(--adm-dark); line-height: 1.1; }
.adm-scard .sc-ico  { position: absolute; left: 16px; top: 16px; font-size: 2.2rem; opacity: .08; }

/* ===== SETTINGS PANEL ===== */
.adm-settings-panel {
    background: var(--adm-card);
    border-radius: 16px;
    box-shadow: 0 1px 8px rgba(0,0,0,.08);
    margin-bottom: 28px;
    overflow: hidden;
    border: 1px solid var(--adm-border);
}
.adm-settings-header {
    background: linear-gradient(90deg, #1e2a3a 0%, #2d3f6b 100%);
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    user-select: none;
}
.adm-settings-header h4 {
    margin: 0;
    color: #fff;
    font-weight: 700;
    font-size: .97rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.adm-settings-header .toggle-icon {
    color: rgba(255,255,255,.7);
    font-size: .85rem;
    transition: transform .2s;
}
.adm-settings-header.open .toggle-icon { transform: rotate(180deg); }
.adm-settings-body {
    padding: 24px;
    display: none;
}
.adm-settings-body.open { display: block; }
.adm-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 20px;
    align-items: end;
}
.adm-field { display: flex; flex-direction: column; gap: 6px; }
.adm-field label {
    font-size: .82rem;
    font-weight: 700;
    color: #374151;
}
.adm-field .field-hint {
    font-size: .75rem;
    color: #94a3b8;
    margin-top: 2px;
}
.adm-input {
    padding: 10px 14px;
    border: 1.5px solid var(--adm-border);
    border-radius: 10px;
    font-size: .95rem;
    color: var(--adm-dark);
    background: #f8fafc;
    transition: border-color .15s;
    font-weight: 600;
}
.adm-input:focus { outline: none; border-color: var(--adm-primary); background: #fff; }

/* ===== SECTION HEADER ===== */
.adm-section-hdr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}
.adm-section-hdr h4 {
    font-size: 1rem;
    font-weight: 700;
    color: var(--adm-dark);
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.adm-section-hdr .badge-count {
    background: var(--adm-primary);
    color: #fff;
    font-size: .72rem;
    font-weight: 700;
    padding: 2px 9px;
    border-radius: 20px;
}

/* ===== MARKETERS TABLE ===== */
.adm-table-wrap {
    background: var(--adm-card);
    border-radius: 16px;
    box-shadow: 0 1px 8px rgba(0,0,0,.08);
    overflow: hidden;
    margin-bottom: 28px;
    border: 1px solid var(--adm-border);
}
.adm-table-wrap table {
    width: 100%;
    border-collapse: collapse;
}
.adm-table-wrap thead th {
    background: var(--adm-dark);
    color: #fff;
    font-size: .75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    padding: 14px 16px;
    white-space: nowrap;
}
.adm-table-wrap tbody tr { transition: background .12s; }
.adm-table-wrap tbody tr:hover { background: #f8faff; }
.adm-table-wrap tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
    font-size: .88rem;
    color: #334155;
}
.adm-table-wrap tbody tr:last-child td { border-bottom: none; }

/* ===== USER INFO ===== */
.u-info { display: flex; flex-direction: column; gap: 2px; }
.u-info .u-name  { font-weight: 700; color: var(--adm-dark); font-size: .92rem; }
.u-info .u-email { font-size: .76rem; color: #94a3b8; }

/* ===== BADGES ===== */
.adm-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: .74rem;
    font-weight: 700;
}
.badge-blue   { background: #dbeafe; color: #1d4ed8; }
.badge-green  { background: #d1fae5; color: #065f46; }
.badge-amber  { background: #fef3c7; color: #92400e; }
.badge-red    { background: #fee2e2; color: #991b1b; }
.badge-purple { background: #ede9fe; color: #5b21b6; }
.badge-gray   { background: #f1f5f9; color: #475569; }

/* ===== COMMISSION FORM ===== */
.comm-form { display: flex; gap: 6px; align-items: center; }
.comm-form input[type=number] {
    width: 78px;
    padding: 6px 8px;
    border: 1.5px solid var(--adm-border);
    border-radius: 8px;
    font-size: .88rem;
    font-weight: 700;
    color: var(--adm-dark);
    text-align: center;
}
.comm-form input[type=number]:focus { outline: none; border-color: var(--adm-primary); }

/* ===== STAT MINI ===== */
.stat-mini { display: flex; flex-direction: column; gap: 4px; min-width: 120px; }
.stat-mini .sr { display: flex; justify-content: space-between; align-items: center; gap: 6px; font-size: .79rem; }
.stat-mini .sr-lbl { color: #94a3b8; font-weight: 500; }
.stat-mini .sr-val { font-weight: 800; font-size: .82rem; }
.sr-val.v-pending  { color: #b45309; }
.sr-val.v-approved { color: #065f46; }
.sr-val.v-paid     { color: #1d4ed8; }

/* ===== TOTAL COMMISSION DISPLAY ===== */
.total-comm {
    font-size: 1.05rem;
    font-weight: 800;
    color: var(--adm-dark);
    margin-bottom: 4px;
}
.total-comm-label {
    font-size: .72rem;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: .04em;
}

/* ===== BUTTONS ===== */
.btn-adm {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 14px;
    border-radius: 8px;
    font-size: .8rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    text-decoration: none;
    transition: filter .15s, transform .1s;
    white-space: nowrap;
    line-height: 1.3;
}
.btn-adm:hover { filter: brightness(.9); transform: translateY(-1px); text-decoration: none; }
.btn-adm:active { transform: none; }
.btn-primary  { background: var(--adm-primary); color: #fff; }
.btn-success  { background: var(--adm-success); color: #fff; }
.btn-warning  { background: var(--adm-warning); color: #fff; }
.btn-danger   { background: var(--adm-danger);  color: #fff; }
.btn-dark     { background: var(--adm-dark);    color: #fff; }
.btn-outline  { background: transparent; border: 1.5px solid var(--adm-border); color: #475569; }
.btn-sm       { padding: 5px 11px; font-size: .76rem; }

/* ===== WITHDRAWALS SECTION ===== */
.wr-wrap {
    background: var(--adm-card);
    border-radius: 16px;
    box-shadow: 0 1px 8px rgba(0,0,0,.08);
    overflow: hidden;
    margin-bottom: 28px;
    border: 1px solid var(--adm-border);
}
.wr-header {
    background: linear-gradient(90deg, #d97706 0%, #b45309 100%);
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.wr-header h4 { margin: 0; font-weight: 700; color: #fff; font-size: 1rem; display: flex; align-items: center; gap: 8px; }
.wr-count { background: rgba(255,255,255,.25); color: #fff; font-weight: 800; font-size: .83rem; padding: 3px 10px; border-radius: 20px; }

/* ===== EMPTY STATE ===== */
.empty-state {
    text-align: center;
    padding: 52px 20px;
    color: #94a3b8;
}
.empty-state .es-icon { font-size: 2.8rem; margin-bottom: 12px; opacity: .5; }
.empty-state p { font-size: .93rem; margin: 0; }

/* ===== REFERRED COUNT ===== */
.referred-count {
    text-align: center;
}
.referred-count .rc-num { font-size: 1.4rem; font-weight: 800; color: var(--adm-primary); line-height: 1; }
.referred-count .rc-lbl { font-size: .7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }

/* ===== RESPONSIVE ===== */
@media (max-width: 768px) {
    .adm-summary { grid-template-columns: 1fr 1fr; }
    .adm-table-wrap { overflow-x: auto; }
    .adm-settings-grid { grid-template-columns: 1fr; }
}
</style>

<?php
/* ===== PAGE HEADER ===== */
echo '
<div class="adm-page-header">
    <div>
        <h1>&#x1F4CA; إدارة المسوقين</h1>
        <p>متابعة المسوقين النشطين، إدارة العمولات وطلبات السحب، وضبط إعدادات البرنامج.</p>
    </div>
    <div style="font-size:3rem;opacity:.2;">&#x1F91D;</div>
</div>';

/* ===== SUMMARY CARDS ===== */
echo '<div class="adm-summary">';
$summary_items = [
    ['المسوقون النشطون',   $total_marketers,                  'primary', '&#x1F464;'],
    ['إجمالي العمولات',    number_format($total_all, 2),      '',        '&#x1F4B0;'],
    ['عمولات معتمدة',      number_format($total_approved, 2), 'sc-success', '&#x2705;'],
    ['تم دفعها',           number_format($total_paid, 2),     'sc-info',  '&#x1F4B8;'],
    ['طلبات سحب معلقة',    $pending_withdrawals_count,        'sc-danger','&#x23F3;'],
];
foreach ($summary_items as [$lbl, $val, $cls, $icon]) {
    echo "
    <div class=\"adm-scard {$cls}\">
        <div class=\"sc-lbl\">{$lbl}</div>
        <div class=\"sc-val\">{$val}</div>
        <div class=\"sc-ico\">{$icon}</div>
    </div>";
}
echo '</div>';

/* ===== SETTINGS PANEL ===== */
$settings_url = (new moodle_url('/local/referral/admin/manage.php'))->out(false);
echo '
<div class="adm-settings-panel">
    <div class="adm-settings-header" id="settingsToggle" onclick="toggleSettings()">
        <h4>&#x2699;&#xFE0F; إعدادات برنامج التسويق</h4>
        <span class="toggle-icon" id="settingsArrow">&#x25BC;</span>
    </div>
    <div class="adm-settings-body" id="settingsBody">
        <form method="post" action="' . $settings_url . '">
            <input type="hidden" name="action"  value="savesettings">
            <input type="hidden" name="sesskey" value="' . sesskey() . '">
            <div class="adm-settings-grid">
                <div class="adm-field">
                    <label for="min_wd">&#x1F4B3; الحد الأدنى لطلب السحب</label>
                    <input type="number" id="min_wd" name="min_withdrawal" class="adm-input"
                           value="' . number_format($min_withdrawal, 2, '.', '') . '"
                           min="0" step="0.01" required>
                    <span class="field-hint">أقل مبلغ يمكن للمسوق طلب سحبه</span>
                </div>
                <div class="adm-field">
                    <label for="def_comm">&#x1F4CA; نسبة العمولة الافتراضية (%)</label>
                    <input type="number" id="def_comm" name="default_commission" class="adm-input"
                           value="' . $def_commission . '"
                           min="0" max="100" step="1" required>
                    <span class="field-hint">تُطبَّق تلقائياً على المسوق عند تفعيل حسابه</span>
                </div>
                <div class="adm-field" style="justify-content:flex-end;">
                    <button type="submit" class="btn-adm btn-dark" style="width:100%;justify-content:center;padding:11px;">
                        &#x1F4BE; حفظ الإعدادات
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
function toggleSettings() {
    var body   = document.getElementById("settingsBody");
    var header = document.getElementById("settingsToggle");
    var arrow  = document.getElementById("settingsArrow");
    var open   = body.classList.toggle("open");
    header.classList.toggle("open", open);
    arrow.innerHTML = open ? "&#x25B2;" : "&#x25BC;";
}
</script>';

/* ===== MARKETERS TABLE ===== */
echo '
<div class="adm-section-hdr">
    <h4>&#x1F4CB; قائمة المسوقين النشطين
        <span class="badge-count">' . $total_marketers . '</span>
    </h4>
</div>';

echo '<div class="adm-table-wrap">';
if (empty($marketers)) {
    echo '
    <div class="empty-state">
        <div class="es-icon">&#x1F465;</div>
        <p>لا يوجد مسوقون مفعَّلون حتى الآن.<br>
           سيظهر المسوق هنا بعد تفعيل حسابه عبر رابط التسويق.</p>
    </div>';
} else {
    echo '
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr>
                <th style="text-align:right;">المسوق</th>
                <th>كود الإحالة</th>
                <th style="text-align:center;">المُحالون</th>
                <th>نسبة العمولة</th>
                ' . ($enable_parent ? '<th>المسوق الأب</th>' : '') . '
                <th>إجمالي العمولات</th>
                <th>تفاصيل العمولات</th>
                <th style="text-align:center;">الإجراءات</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($marketers as $m) {
        $st       = $stats[$m->id];
        $refurl   = (new moodle_url('/', ['ref' => $m->code]))->out(false);
        $fullname = htmlspecialchars(trim($m->firstname . ' ' . $m->lastname));
        $email    = htmlspecialchars($m->email ?? '');

        /* Commission form */
        $comm_form = '
        <form method="post" action="' . $base_url->out(false) . '" class="comm-form">
            <input type="hidden" name="action"     value="setcommission">
            <input type="hidden" name="marketerid" value="' . $m->id . '">
            <input type="hidden" name="sesskey"    value="' . sesskey() . '">
            <input type="number" name="commission" value="' . (int)$m->commission_percentage . '"
                   min="0" max="100" title="نسبة العمولة %">
            <button type="submit" class="btn-adm btn-primary btn-sm" title="حفظ النسبة">&#x1F4BE;</button>
        </form>';

        /* Parent cell */
        $parent_cell = '';
        if ($enable_parent) {
            $current_parent_name = !empty($m->parentname)
                ? '<span class="adm-badge badge-purple" style="margin-bottom:4px;display:inline-block;">'
                  . htmlspecialchars($m->parentname) . ' (' . htmlspecialchars($m->parentcode ?? '') . ')</span><br>'
                : '<span style="color:#94a3b8;font-size:.78rem;">— بدون أب —</span><br>';

            $options = '<option value="0"' . (empty($m->parent_id) ? ' selected' : '') . '>— بدون أب —</option>';
            foreach ($all_marketers as $pm) {
                if ($pm->id == $m->id) continue;
                $sel = (!empty($m->parent_id) && $m->parent_id == $pm->id) ? ' selected' : '';
                $options .= '<option value="' . $pm->id . '"' . $sel . '>'
                          . htmlspecialchars($pm->name) . ' (' . htmlspecialchars($pm->code) . ')</option>';
            }

            $parent_cell = '
            <td>
                ' . $current_parent_name . '
                <form method="post" action="' . $base_url->out(false) . '" style="display:flex;gap:4px;align-items:center;margin-top:4px;">
                    <input type="hidden" name="action"     value="setparent">
                    <input type="hidden" name="marketerid" value="' . $m->id . '">
                    <input type="hidden" name="sesskey"    value="' . sesskey() . '">
                    <select name="parentid" class="adm-input" style="padding:4px 6px;font-size:.78rem;min-width:120px;">
                        ' . $options . '
                    </select>
                    <button type="submit" class="btn-adm btn-primary btn-sm" title="حفظ الأب">&#x1F4BE;</button>
                </form>
            </td>';
        }

        /* Stats mini */
        $stats_html = '
        <div class="stat-mini">
            <div class="sr">
                <span class="sr-lbl">انتظار:</span>
                <span class="sr-val v-pending">' . number_format($st['pending'], 2) . '</span>
            </div>
            <div class="sr">
                <span class="sr-lbl">معتمد:</span>
                <span class="sr-val v-approved">' . number_format($st['approved'], 2) . '</span>
            </div>
            <div class="sr">
                <span class="sr-lbl">مدفوع:</span>
                <span class="sr-val v-paid">' . number_format($st['paid'], 2) . '</span>
            </div>
        </div>';

        /* Pay button */
        $pay_btn = '';
        if ($st['approved'] > 0) {
            $pay_url = (new moodle_url('/local/referral/admin/manage.php', [
                'action' => 'pay', 'marketerid' => $m->id, 'sesskey' => sesskey()
            ]))->out(false);
            $pay_btn = '
            <a href="' . $pay_url . '"
               class="btn-adm btn-success btn-sm"
               onclick="return confirm(\'تأكيد دفع العمولات المعتمدة ('. number_format($st['approved'], 2) .') للمسوق ' . addslashes($fullname) . '؟\')">
                &#x1F4B3; دفع (' . number_format($st['approved'], 2) . ')
            </a>';
        }

        /* Delete button */
        $del_url = (new moodle_url('/local/referral/admin/manage.php', [
            'delete' => $m->id, 'sesskey' => sesskey()
        ]))->out(false);

        echo '
        <tr>
            <td>
                <div class="u-info">
                    <span class="u-name">' . $fullname . '</span>
                    <span class="u-email">' . $email . '</span>
                </div>
            </td>
            <td>
                <span class="adm-badge badge-blue">' . htmlspecialchars($m->code) . '</span>
            </td>
            <td>
                <div class="referred-count">
                    <div class="rc-num">' . $st['referred'] . '</div>
                    <div class="rc-lbl">مستخدم</div>
                </div>
            </td>
            <td>' . $comm_form . '</td>
            ' . $parent_cell . '
            <td>
                <div class="total-comm">' . number_format($st['total'], 2) . '</div>
                <div class="total-comm-label">إجمالي كل الوقت</div>
            </td>
            <td>' . $stats_html . '</td>
            <td>
                <div style="display:flex;flex-wrap:wrap;gap:6px;justify-content:center;">
                    ' . $pay_btn . '
                    <button type="button" class="btn-adm btn-outline btn-sm"
                        onclick="navigator.clipboard.writeText(\'' . addslashes($refurl) . '\').then(()=>{
                            this.textContent=\'&#x2713; تم النسخ\';
                            setTimeout(()=>this.innerHTML=\'&#x1F517; رابط\',2000);
                        })">
                        &#x1F517; رابط
                    </button>
                    <a href="' . $del_url . '" class="btn-adm btn-danger btn-sm"
                       onclick="return confirm(\'حذف المسوق ' . addslashes($fullname) . '؟ لا يمكن التراجع.\')">
                        &#x1F5D1; حذف
                    </a>
                </div>
            </td>
        </tr>';
    }

    echo '
        </tbody>
    </table>
    </div>';
}
echo '</div>';

/* ===== PENDING WITHDRAWALS ===== */
echo '
<div class="wr-wrap">
    <div class="wr-header">
        <h4>&#x1F4E4; طلبات السحب المعلقة</h4>
        <span class="wr-count">' . count($pending_withdrawals) . ' طلب</span>
    </div>';

if (empty($pending_withdrawals)) {
    echo '
    <div class="empty-state" style="padding:36px 20px;">
        <div class="es-icon">&#x1F4EC;</div>
        <p>لا توجد طلبات سحب معلقة حالياً.</p>
    </div>';
} else {
    echo '
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr style="background:#f8fafc;">
                <th style="padding:12px 16px;font-size:.75rem;font-weight:700;color:#475569;text-align:right;border-bottom:2px solid #e2e8f0;">المسوق</th>
                <th style="padding:12px 16px;font-size:.75rem;font-weight:700;color:#475569;border-bottom:2px solid #e2e8f0;">الكود</th>
                <th style="padding:12px 16px;font-size:.75rem;font-weight:700;color:#475569;text-align:center;border-bottom:2px solid #e2e8f0;">المبلغ المطلوب</th>
                <th style="padding:12px 16px;font-size:.75rem;font-weight:700;color:#475569;border-bottom:2px solid #e2e8f0;">تاريخ الطلب</th>
                <th style="padding:12px 16px;font-size:.75rem;font-weight:700;color:#475569;text-align:center;border-bottom:2px solid #e2e8f0;">الإجراء</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($pending_withdrawals as $wr) {
        $wname   = htmlspecialchars(trim($wr->firstname . ' ' . $wr->lastname) ?: $wr->marketername);
        $pay_url = (new moodle_url('/local/referral/admin/manage.php', [
            'action' => 'paywithdrawal', 'wid' => $wr->id, 'sesskey' => sesskey()
        ]))->out(false);
        $rej_url = (new moodle_url('/local/referral/admin/manage.php', [
            'action' => 'rejectwithdrawal', 'wid' => $wr->id, 'sesskey' => sesskey()
        ]))->out(false);

        echo '
        <tr style="border-bottom:1px solid #f1f5f9;transition:background .12s;" onmouseover="this.style.background=\'#f8faff\'" onmouseout="this.style.background=\'\'">
            <td style="padding:14px 16px;font-weight:700;color:#1e2a3a;">' . $wname . '</td>
            <td style="padding:14px 16px;">
                <span class="adm-badge badge-blue">' . htmlspecialchars($wr->code) . '</span>
            </td>
            <td style="padding:14px 16px;text-align:center;">
                <span style="font-size:1.1rem;font-weight:800;color:#059669;">' . number_format($wr->amount, 2) . '</span>
            </td>
            <td style="padding:14px 16px;font-size:.82rem;color:#94a3b8;">'
                . userdate($wr->timecreated, '%d/%m/%Y %H:%M') . '
            </td>
            <td style="padding:14px 16px;text-align:center;">
                <div style="display:flex;gap:8px;justify-content:center;">
                    <a href="' . $pay_url . '"
                       class="btn-adm btn-success btn-sm"
                       onclick="return confirm(\'تأكيد دفع ' . number_format($wr->amount, 2) . ' لـ ' . addslashes($wname) . '؟\')">
                        &#x2705; قبول الدفع
                    </a>
                    <a href="' . $rej_url . '"
                       class="btn-adm btn-danger btn-sm"
                       onclick="return confirm(\'رفض طلب السحب؟\')">
                        &#x274C; رفض
                    </a>
                </div>
            </td>
        </tr>';
    }

    echo '
        </tbody>
    </table>
    </div>';
}
echo '</div>';

echo $OUTPUT->footer();
