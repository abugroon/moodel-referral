<?php
// /local/referral/admin/dashboard.php
// Commissions approval — review and approve/reject individual commission records.
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/tabs.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_referral_dashboard');

global $DB, $OUTPUT;

$base_url = new moodle_url('/local/referral/admin/dashboard.php');
$sess     = sesskey();

/* =====================================================
   ACTIONS
===================================================== */
$action = optional_param('action', '', PARAM_ALPHA);
$cid    = optional_param('cid', 0, PARAM_INT);   // single commission id

if ($action && $cid && confirm_sesskey()) {
    $rec = $DB->get_record('local_ref_commissions', ['id' => $cid]);
    if ($rec) {
        if ($action === 'approve' && (int)$rec->status === 0) {
            // Approval = direct payment: skip the intermediate status=1.
            $DB->set_field('local_ref_commissions', 'status', 2, ['id' => $cid]);
        } elseif ($action === 'reject' && (int)$rec->status === 0) {
            $DB->set_field('local_ref_commissions', 'status', 3, ['id' => $cid]);
        } elseif ($action === 'unapprove' && (int)$rec->status === 2) {
            // Allow reverting a paid/approved commission back to pending for correction.
            $DB->set_field('local_ref_commissions', 'status', 0, ['id' => $cid]);
        }
    }
    redirect($base_url);
}

// Bulk approve all pending — marks directly as paid
if ($action === 'approveall' && confirm_sesskey()) {
    $DB->execute("UPDATE {local_ref_commissions} SET status = 2 WHERE status = 0");
    redirect($base_url, 'تم اعتماد وتسجيل جميع العمولات المعلقة كمدفوعة.', 2);
}

/* =====================================================
   DATA
===================================================== */
$status_filter = optional_param('sf', -1, PARAM_INT);  // -1 = all, 0/1/2 = specific
$marketer_filter = optional_param('mf', 0, PARAM_INT); // 0 = all

// Summary counts — status=2 is now the single "approved/paid" state.
$cnt_pending  = (int)$DB->count_records('local_ref_commissions', ['status' => 0]);
$cnt_approved = (int)$DB->count_records('local_ref_commissions', ['status' => 2]);
$cnt_paid     = $cnt_approved; // same bucket; kept for template compatibility
$sum_pending  = (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE status=0");
$sum_approved = (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE status=2");

// Build filter WHERE
$where  = '1=1';
$params = [];
if ($status_filter >= 0) {
    $where .= ' AND c.status = :sf';
    $params['sf'] = $status_filter;
}
if ($marketer_filter > 0) {
    $where .= ' AND c.marketerid = :mf';
    $params['mf'] = $marketer_filter;
}

$commissions = $DB->get_records_sql(
    "SELECT c.id, c.amount, c.commission, c.status, c.timecreated,
            c.marketerid, c.userid, c.courseid,
            u.firstname AS ufirst, u.lastname AS ulast,
            mu.firstname AS mfirst, mu.lastname AS mlast,
            mp.code AS mcode,
            crs.fullname AS coursename
       FROM {local_ref_commissions} c
  LEFT JOIN {user}                        u   ON u.id  = c.userid
  LEFT JOIN {user}                        mu  ON mu.id = c.marketerid
  LEFT JOIN {local_ref_marketer_profile}  mp  ON mp.userid = c.marketerid
  LEFT JOIN {course}                      crs ON crs.id = c.courseid
      WHERE {$where}
      ORDER BY c.timecreated DESC",
    $params,
    0,
    200
);

// Marketer list for filter dropdown
$all_marketers = $DB->get_records_sql(
    "SELECT mp.userid, mp.code, u.firstname, u.lastname
       FROM {local_ref_marketer_profile} mp
  LEFT JOIN {user} u ON u.id = mp.userid
      ORDER BY COALESCE(u.firstname, 'zzz') ASC"
);

echo $OUTPUT->header();
echo local_referral_admin_tabs('dashboard');
?>
<style>
:root {
    --rp: #2563eb; --rg: #059669; --ra: #d97706; --rr: #dc2626;
    --rd: #1e293b; --rm: #64748b; --rb: #e2e8f0; --rs: #f8fafc;
}
.ref-wrap { padding: 2px 0 48px; }
.ref-stats { display: grid; grid-template-columns: repeat(auto-fit,minmax(130px,1fr)); gap:12px; margin-bottom:20px; }
.ref-stat { background:#fff; border:1px solid var(--rb); border-radius:10px; padding:13px 15px; border-left:3px solid var(--rp); }
.ref-stat.s-green { border-left-color:var(--rg); }
.ref-stat.s-amber { border-left-color:var(--ra); }
.ref-stat .st-lbl { font-size:.68rem; font-weight:600; color:var(--rm); text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
.ref-stat .st-val { font-size:1.35rem; font-weight:800; color:var(--rd); line-height:1.1; }
.ref-card { background:#fff; border:1px solid var(--rb); border-radius:12px; margin-bottom:20px; overflow:hidden; }
.ref-card-hdr { padding:12px 18px; background:var(--rs); border-bottom:1px solid var(--rb); display:flex; align-items:center; justify-content:space-between; gap:8px; }
.ref-card-hdr h3 { margin:0; font-size:.88rem; font-weight:700; color:var(--rd); }
.hdr-badge { background:var(--rp); color:#fff; font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:20px; }
.hdr-badge.ba { background:var(--ra); }
.ref-filter { padding:14px 18px; border-bottom:1px solid var(--rb); background:var(--rs); display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
.ref-filter select, .ref-filter input { padding:6px 10px; border:1.5px solid var(--rb); border-radius:7px; font-size:.83rem; color:var(--rd); background:#fff; }
.ref-filter select:focus, .ref-filter input:focus { outline:none; border-color:var(--rp); }
.ref-tbl { width:100%; border-collapse:collapse; }
.ref-tbl thead th { padding:10px 13px; background:var(--rs); font-size:.68rem; font-weight:700; color:var(--rm); text-transform:uppercase; letter-spacing:.04em; border-bottom:2px solid var(--rb); white-space:nowrap; }
.ref-tbl tbody td { padding:11px 13px; border-bottom:1px solid var(--rb); font-size:.84rem; color:#334155; vertical-align:middle; }
.ref-tbl tbody tr:last-child td { border-bottom:none; }
.ref-tbl tbody tr:hover td { background:#f8faff; }
.rb { display:inline-block; padding:2px 8px; border-radius:20px; font-size:.69rem; font-weight:700; }
.rb-pending  { background:#fef3c7; color:#92400e; }
.rb-approved { background:#d1fae5; color:#065f46; }
.rb-paid     { background:#dbeafe; color:#1d4ed8; }
.rb-rejected { background:#fee2e2; color:#991b1b; }
.rb-blue     { background:#dbeafe; color:#1d4ed8; }
.ref-btn { display:inline-flex; align-items:center; gap:4px; padding:5px 10px; border-radius:6px; font-size:.75rem; font-weight:600; border:none; cursor:pointer; text-decoration:none; transition:opacity .15s; white-space:nowrap; }
.ref-btn:hover { opacity:.82; text-decoration:none; }
.btn-p { background:var(--rp); color:#fff; }
.btn-g { background:var(--rg); color:#fff; }
.btn-r { background:var(--rr); color:#fff; }
.btn-o { background:transparent; border:1px solid var(--rb); color:var(--rm); }
.ref-empty { text-align:center; padding:40px 20px; color:var(--rm); }
.ref-empty .re-icon { font-size:2rem; margin-bottom:8px; opacity:.4; }
.ref-empty p { font-size:.9rem; margin:0; }
@media (max-width:768px) { .ref-stats { grid-template-columns:1fr 1fr; } .ref-tbl-scroll { overflow-x:auto; } }
</style>

<?php
$base_out = $base_url->out(false);

echo '<div class="ref-wrap">';

/* ── Summary stats ── */
echo '<div class="ref-stats">';
foreach ([
    ['انتظار اعتماد',     $cnt_pending  . ' سجل',           ''],
    ['مجموع انتظار',      fmt_sdg($sum_pending),   ''],
    ['معتمدة / مدفوعة',  $cnt_approved . ' سجل',  's-green'],
    ['مجموع معتمدة',      fmt_sdg($sum_approved),  's-green'],
] as [$lbl, $val, $cls]) {
    echo "<div class=\"ref-stat {$cls}\"><div class=\"st-lbl\">{$lbl}</div><div class=\"st-val\">{$val}</div></div>";
}
echo '</div>';

/* ── Commissions card ── */
$filter_badge = $status_filter >= 0 ? count($commissions) : ($cnt_pending + $cnt_approved + $cnt_paid);
echo '
<div class="ref-card">
    <div class="ref-card-hdr">
        <h3>سجل العمولات <span class="hdr-badge">' . count($commissions) . '</span></h3>
        <div style="display:flex;gap:8px;">';

if ($cnt_pending > 0) {
    echo '
        <a href="' . (new moodle_url($base_url, ['action' => 'approveall', 'sesskey' => $sess]))->out(false) . '"
           class="ref-btn btn-g"
           onclick="return confirm(\'اعتماد جميع العمولات المعلقة (' . $cnt_pending . ' سجل)؟\')">
            اعتماد الكل (' . $cnt_pending . ')
        </a>';
}

echo '
        </div>
    </div>

    <!-- Filter bar -->
    <form method="get" action="' . $base_out . '" class="ref-filter">
        <select name="sf">
            <option value="-1"' . ($status_filter === -1 ? ' selected' : '') . '>جميع الحالات</option>
            <option value="0"'  . ($status_filter === 0  ? ' selected' : '') . '>انتظار</option>
            <option value="1"'  . ($status_filter === 1  ? ' selected' : '') . '>معتمد</option>
            <option value="2"'  . ($status_filter === 2  ? ' selected' : '') . '>مدفوع</option>
        </select>
        <select name="mf">
            <option value="0">جميع المسوقين</option>';
foreach ($all_marketers as $am) {
    $sel = $marketer_filter == $am->userid ? ' selected' : '';
    $am_name = trim(($am->firstname ?? '') . ' ' . ($am->lastname ?? '')) ?: '(محذوف #' . $am->userid . ')';
    echo '<option value="' . $am->userid . '"' . $sel . '>'
        . htmlspecialchars($am_name)
        . ' (' . htmlspecialchars($am->code) . ')</option>';
}
echo '
        </select>
        <button type="submit" class="ref-btn btn-p" style="padding:6px 16px;">تصفية</button>';
if ($status_filter >= 0 || $marketer_filter > 0) {
    echo '<a href="' . $base_out . '" class="ref-btn btn-o">إلغاء الفلتر</a>';
}
echo '
    </form>';

if (empty($commissions)) {
    echo '
    <div class="ref-empty">
        <div class="re-icon">&#x1F4B8;</div>
        <p>لا توجد سجلات عمولات بهذا الفلتر.</p>
    </div>';
} else {
    $statuses = [
        0 => ['انتظار',  'rb-pending'],
        1 => ['معتمد',   'rb-approved'],
        2 => ['مدفوع',   'rb-paid'],
        3 => ['مرفوض',   'rb-rejected'],
    ];

    echo '<div class="ref-tbl-scroll"><table class="ref-tbl"><thead><tr>
        <th>المسوق</th>
        <th>الكود</th>
        <th>الطالب</th>
        <th>الكورس</th>
        <th>مبلغ التسجيل</th>
        <th>العمولة</th>
        <th>الحالة</th>
        <th>التاريخ</th>
        <th style="text-align:center;">الإجراء</th>
    </tr></thead><tbody>';

    foreach ($commissions as $c) {
        [$slabel, $sclass] = $statuses[(int)$c->status] ?? ['—', 'rb-pending'];
        $mn_raw = trim(($c->mfirst ?? '') . ' ' . ($c->mlast ?? ''));
        $un_raw = trim(($c->ufirst ?? '') . ' ' . ($c->ulast ?? ''));
        $mname  = htmlspecialchars($mn_raw !== '' ? $mn_raw : '(محذوف #' . $c->marketerid . ')');
        $uname  = htmlspecialchars($un_raw !== '' ? $un_raw : '(محذوف #' . $c->userid . ')');

        $actions = '';
        if ((int)$c->status === 0) {
            $approve_url = (new moodle_url($base_url, ['action' => 'approve', 'cid' => $c->id, 'sesskey' => $sess, 'sf' => $status_filter, 'mf' => $marketer_filter]))->out(false);
            $reject_url  = (new moodle_url($base_url, ['action' => 'reject',  'cid' => $c->id, 'sesskey' => $sess, 'sf' => $status_filter, 'mf' => $marketer_filter]))->out(false);
            $actions = '
            <div style="display:flex;gap:5px;justify-content:center;">
                <a href="' . $approve_url . '" class="ref-btn btn-g">اعتماد</a>
                <a href="' . $reject_url  . '" class="ref-btn btn-r"
                   onclick="return confirm(\'رفض العمولة؟\')">رفض</a>
            </div>';
        } elseif ((int)$c->status === 2) {
            $unapp_url = (new moodle_url($base_url, ['action' => 'unapprove', 'cid' => $c->id, 'sesskey' => $sess, 'sf' => $status_filter, 'mf' => $marketer_filter]))->out(false);
            $actions = '<a href="' . $unapp_url . '" class="ref-btn btn-o" style="font-size:.72rem;"
                onclick="return confirm(\'إلغاء الاعتماد وإعادتها للانتظار؟\')">إلغاء الاعتماد</a>';
        }

        echo '
        <tr>
            <td style="font-weight:700;color:var(--rd);">' . $mname . '</td>
            <td><span class="rb rb-blue">' . htmlspecialchars($c->mcode) . '</span></td>
            <td>' . $uname . '</td>
            <td style="color:var(--rm);font-size:.8rem;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"
                title="' . htmlspecialchars($c->coursename) . '">' . htmlspecialchars($c->coursename) . '</td>
            <td style="font-weight:700;">' . fmt_sdg($c->amount) . '</td>
            <td style="font-weight:800;color:var(--rg);">' . fmt_sdg($c->commission) . '</td>
            <td><span class="rb ' . $sclass . '">' . $slabel . '</span></td>
            <td style="color:var(--rm);font-size:.78rem;white-space:nowrap;">' . userdate($c->timecreated, '%d/%m/%Y') . '</td>
            <td style="text-align:center;">' . $actions . '</td>
        </tr>';
    }

    echo '</tbody></table></div>';
}

echo '</div>'; // ref-card
echo '</div>'; // ref-wrap
echo $OUTPUT->footer();
