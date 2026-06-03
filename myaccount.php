<?php
// /local/referral/myaccount.php
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/referral/myaccount.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('حسابي كمسوق');
$PAGE->set_heading('حسابي كمسوق');

global $DB, $USER, $OUTPUT;

$marketer = $DB->get_record('local_ref_marketer_profile', ['userid' => $USER->id]);

if (!$marketer) {
    echo $OUTPUT->header();
    echo '
    <div style="max-width:460px;margin:60px auto;text-align:center;padding:0 16px;">
        <div style="width:72px;height:72px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;
                    justify-content:center;margin:0 auto 18px;font-size:2rem;">&#x1F4E3;</div>
        <h3 style="color:#1e293b;font-weight:800;font-size:1.3rem;margin-bottom:10px;">لست مسوقاً بعد!</h3>
        <p style="color:#64748b;margin-bottom:26px;font-size:.95rem;line-height:1.6;">
            فعّل حساب التسويق واستمتع بعمولات مجزية على كل مستخدم تجلبه للمنصة.
        </p>
        <a href="' . (new moodle_url('/local/referral/register.php'))->out(false) . '"
           style="background:#2563eb;color:#fff;padding:12px 32px;border-radius:10px;font-weight:700;
                  text-decoration:none;font-size:.95rem;display:inline-block;">
            سجّل الآن كمسوق
        </a>
    </div>';
    echo $OUTPUT->footer();
    exit;
}

/* ============================================================
   MARKETER TYPE — resolve before any logic
============================================================ */
// Derive type from DB field; fall back from parent_userid for older installs.
$marketer_type = 'main';
if (isset($marketer->type) && $marketer->type !== '') {
    $marketer_type = $marketer->type;
} elseif (!empty($marketer->parent_userid)) {
    $marketer_type = 'sub';
}

/* ============================================================
   WITHDRAWAL REQUEST APPROVAL (for main marketers only)
============================================================ */
$approveaction = optional_param('approveaction', '', PARAM_ALPHANUMEXT);
$approvewid    = optional_param('approvewid', 0, PARAM_INT);
$approvetype   = optional_param('approvetype', 'marketer', PARAM_ALPHA); // marketer | teacher

if ($marketer_type === 'main' && $approveaction && $approvewid && confirm_sesskey()) {
    if ($approvetype === 'marketer') {
        // Approve/reject sub-marketer withdrawal request.
        $wr = $DB->get_record('local_ref_withdrawals', ['id' => $approvewid], '*', IGNORE_MISSING);
        if ($wr && (int)$wr->mainmarketerid === (int)$USER->id) {
            $newstatus = ($approveaction === 'approve') ? 1 : 2;
            $DB->update_record('local_ref_withdrawals', (object)[
                'id'           => $approvewid,
                'status'       => $newstatus,
                'approvedby'   => $USER->id,
                'timemodified' => time(),
            ]);
        }
        redirect(new moodle_url('/local/referral/myaccount.php'),
            ($approveaction === 'approve') ? 'تمت الموافقة على طلب السحب.' : 'تم رفض طلب السحب.', 2);

    } elseif ($approvetype === 'teacher'
            && class_exists('\local_teacher_commissions\withdrawal_manager')) {
        // Approve/reject teacher withdrawal request routed to this main marketer.
        try {
            $newstatus = ($approveaction === 'approve')
                ? \local_teacher_commissions\withdrawal_manager::STATUS_APPROVED
                : \local_teacher_commissions\withdrawal_manager::STATUS_REJECTED;
            \local_teacher_commissions\withdrawal_manager::update_status($approvewid, $newstatus);
        } catch (\Throwable $e) {
            \core\notification::error($e->getMessage());
        }
        redirect(new moodle_url('/local/referral/myaccount.php'),
            ($approveaction === 'approve') ? 'تمت الموافقة على طلب سحب المعلم.' : 'تم رفض طلب سحب المعلم.', 2);
    }
}

/* ============================================================
   WITHDRAWAL REQUEST (sub marketers or main marketers)
============================================================ */
$requestwithdraw = optional_param('requestwithdraw', 0, PARAM_BOOL);
$withdrawamount  = (float)optional_param('withdrawamount', 0, PARAM_FLOAT);
$withdrawmsg     = '';
$min_withdrawal  = (float)(get_config('local_referral', 'min_withdrawal') ?: 10);

if ($requestwithdraw && confirm_sesskey()) {
    $total_earned_now = (float)$DB->get_field_sql(
        "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid = :mid",
        ['mid' => $marketer->userid]
    );
    $direct_paid_now = (float)$DB->get_field_sql(
        "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid = :mid AND status IN (1,2)",
        ['mid' => $marketer->userid]
    );
    $wd_paid_now = (float)$DB->get_field_sql(
        "SELECT COALESCE(SUM(amount),0) FROM {local_ref_withdrawals} WHERE marketerid = :mid AND status = 1",
        ['mid' => $marketer->userid]
    );
    $pending_wd = (float)$DB->get_field_sql(
        "SELECT COALESCE(SUM(amount),0) FROM {local_ref_withdrawals}
          WHERE marketerid = :mid AND status = 0",
        ['mid' => $marketer->userid]
    );
    $available = max(0.0, $total_earned_now - $direct_paid_now - $wd_paid_now);
    $net_avail = max(0.0, $available - $pending_wd);

    if ($withdrawamount <= 0) {
        $withdrawmsg = 'error:أدخل مبلغاً صحيحاً.';
    } elseif ($withdrawamount < $min_withdrawal) {
        $withdrawmsg = 'error:الحد الأدنى للسحب هو ' . fmt_sdg($min_withdrawal) . '.';
    } elseif ($withdrawamount > $net_avail) {
        $withdrawmsg = 'error:المبلغ المطلوب أكبر من رصيدك المتاح (' . fmt_sdg($net_avail) . ').';
    } else {
        // Route to main marketer if sub, otherwise 0 (admin handles main marketer requests).
        $main_for_wd = ($marketer_type === 'sub' && !empty($marketer->parent_userid))
            ? (int)$marketer->parent_userid
            : 0;

        $DB->insert_record('local_ref_withdrawals', (object)[
            'marketerid'     => $marketer->userid,
            'mainmarketerid' => $main_for_wd,
            'amount'         => $withdrawamount,
            'status'         => 0,
            'createdby'      => $USER->id,
            'timecreated'    => time(),
            'timemodified'   => time(),
        ]);
        $withdrawmsg = 'success:تم إرسال طلب السحب بنجاح! سيتم مراجعته من قِبَل الإدارة.';

        // ── Build shared email content ──────────────────────────────────────
        $marketer_name = fullname($USER);
        $date_str      = userdate(time(), '%d/%m/%Y %H:%M');
        $amount_str    = fmt_sdg($withdrawamount, false) . ' SDG';
        $noreply       = \core_user::get_noreply_user();

        // ── 1. Notify main marketer (only when this is a sub-marketer request) ─
        if ($main_for_wd > 0) {
            $main_user = $DB->get_record('user', ['id' => $main_for_wd], '*', IGNORE_MISSING);
            if ($main_user) {
                $review_url   = (new moodle_url('/local/referral/myaccount.php'))->out(false);
                $subject_main = 'طلب سحب جديد من مسوق فرعي – ' . $marketer_name;
                $plain_main   = "عزيزي " . fullname($main_user) . ",\n\n"
                              . "تم إرسال طلب سحب جديد يحتاج إلى مراجعتك.\n\n"
                              . "──────────────────────────\n"
                              . "المسوق  : {$marketer_name}\n"
                              . "المبلغ  : {$amount_str}\n"
                              . "التاريخ : {$date_str}\n"
                              . "──────────────────────────\n\n"
                              . "رابط المراجعة: {$review_url}";
                $html_main    = "<div dir='rtl' style='font-family:Arial,sans-serif;max-width:520px;'>"
                              . "<p>عزيزي <strong>" . s(fullname($main_user)) . "</strong>،</p>"
                              . "<p>تم إرسال طلب سحب جديد يحتاج إلى مراجعتك.</p>"
                              . "<table style='border-collapse:collapse;width:100%;font-size:14px;'>"
                              . "<tr style='background:#f8fafc;'>"
                              .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;font-weight:700;color:#374151;'>المسوق</td>"
                              .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;'>" . s($marketer_name) . "</td>"
                              . "</tr><tr>"
                              .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;font-weight:700;color:#374151;'>المبلغ</td>"
                              .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;font-size:16px;font-weight:800;color:#059669;'>{$amount_str}</td>"
                              . "</tr><tr style='background:#f8fafc;'>"
                              .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;font-weight:700;color:#374151;'>التاريخ</td>"
                              .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;'>{$date_str}</td>"
                              . "</tr></table>"
                              . "<p style='margin-top:18px;'>"
                              .   "<a href='{$review_url}' style='display:inline-block;padding:10px 22px;"
                              .   "background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;"
                              .   "font-weight:700;font-size:14px;'>مراجعة الطلب</a>"
                              . "</p>"
                              . "<p style='font-size:12px;color:#94a3b8;'>هذا إشعار تلقائي من منصة التسويق.</p>"
                              . "</div>";
                email_to_user($main_user, $noreply, $subject_main, $plain_main, $html_main);
            }
        }

        // ── 2. Notify admins via notification_emails setting (all requests) ──
        $notification_emails_raw = get_config('local_referral', 'notification_emails');
        if (!empty($notification_emails_raw)) {
            $manage_url   = (new moodle_url('/local/referral/admin/manage.php'))->out(false);
            $subject_adm  = '[إشعار] طلب سحب جديد من مسوق – ' . $marketer_name;
            $plain_adm    = "طلب سحب جديد\n\n"
                          . "المسوق  : {$marketer_name}\n"
                          . "المبلغ  : {$amount_str}\n"
                          . "التاريخ : {$date_str}\n\n"
                          . "رابط الإدارة: {$manage_url}";
            $html_adm     = "<div dir='rtl' style='font-family:Arial,sans-serif;max-width:520px;'>"
                          . "<p>طلب سحب جديد من مسوق يحتاج مراجعة.</p>"
                          . "<table style='border-collapse:collapse;width:100%;font-size:14px;'>"
                          . "<tr style='background:#f8fafc;'>"
                          .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;font-weight:700;'>المسوق</td>"
                          .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;'>" . s($marketer_name) . "</td>"
                          . "</tr><tr>"
                          .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;font-weight:700;'>المبلغ</td>"
                          .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;font-size:16px;font-weight:800;color:#059669;'>{$amount_str}</td>"
                          . "</tr><tr style='background:#f8fafc;'>"
                          .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;font-weight:700;'>التاريخ</td>"
                          .   "<td style='padding:10px 14px;border:1px solid #e2e8f0;'>{$date_str}</td>"
                          . "</tr></table>"
                          . "<p style='margin-top:18px;'>"
                          .   "<a href='{$manage_url}' style='display:inline-block;padding:10px 22px;"
                          .   "background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;"
                          .   "font-weight:700;font-size:14px;'>مراجعة الطلب</a>"
                          . "</p></div>";

            $emails = array_filter(array_map('trim', explode(',', $notification_emails_raw)));
            foreach ($emails as $email) {
                if (!validate_email($email)) { continue; }
                $recipient = (object)[
                    'id' => -1, 'email' => $email, 'firstname' => 'Admin', 'lastname' => '',
                    'maildisplay' => 0, 'mailformat' => 1, 'deleted' => 0, 'suspended' => 0,
                    'firstnamephonetic' => '', 'lastnamephonetic' => '', 'middlename' => '',
                    'alternatename' => '', 'auth' => 'manual', 'username' => $email,
                ];
                email_to_user($recipient, $noreply, $subject_adm, $plain_adm, $html_adm);
            }
        }
    }
}

/* ============================================================
   DATA
============================================================ */
$total_commission    = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid = :mid",
    ['mid' => $marketer->userid]);
$direct_paid         = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid = :mid AND status IN (1, 2)",
    ['mid' => $marketer->userid]);
$paid_via_withdrawal = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(amount),0) FROM {local_ref_withdrawals} WHERE marketerid = :mid AND status = 1",
    ['mid' => $marketer->userid]);
$pending_withdrawal_total = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(amount),0) FROM {local_ref_withdrawals} WHERE marketerid = :mid AND status = 0",
    ['mid' => $marketer->userid]);

// Total paid = direct admin approvals + paid withdrawal amounts
$paid_commission    = $direct_paid + $paid_via_withdrawal;
// Available to withdraw = total earned - already paid - currently in pending request
$withdrawable_total = max(0.0, $total_commission - $paid_commission);
$net_balance        = max(0.0, $withdrawable_total - $pending_withdrawal_total);
$referred_count     = $DB->count_records('local_ref_users', ['marketerid' => $marketer->userid]);
$refurl             = (new moodle_url('/', ['ref' => $marketer->code]))->out(false);

// Fetch all visible courses (exclude site course id=1)
$courses = $DB->get_records_select(
    'course',
    'id <> 1 AND visible = 1',
    [],
    'fullname ASC',
    'id, fullname, shortname'
);

// Parent marketer
$parent_marketer = null;
if (!empty($marketer->parent_userid)) {
    $parent_marketer = $DB->get_record_sql(
        "SELECT mp.code, u.firstname, u.lastname
           FROM {local_ref_marketer_profile} mp
      LEFT JOIN {user} u ON u.id = mp.userid
          WHERE mp.userid = :pid",
        ['pid' => $marketer->parent_userid]
    );
}

// Sub-marketers (marketers that have the current user as parent)
$sub_marketers = $DB->get_records_sql(
    "SELECT mp.userid, mp.code, mp.commission_percentage,
            u.firstname, u.lastname
       FROM {local_ref_marketer_profile} mp
  LEFT JOIN {user} u ON u.id = mp.userid
      WHERE mp.parent_userid = :pid
      ORDER BY COALESCE(u.firstname,'zzz') ASC",
    ['pid' => $marketer->userid]
);

$sub_stats = [];
if (!empty($sub_marketers)) {
    $sub_ids = array_keys($sub_marketers); // keyed by userid
    [$in_sql, $in_params] = $DB->get_in_or_equal($sub_ids);
    $sub_ref_rows  = $DB->get_records_sql(
        "SELECT marketerid, COUNT(*) AS cnt FROM {local_ref_users} WHERE marketerid $in_sql GROUP BY marketerid",
        $in_params
    );
    $sub_comm_rows = $DB->get_records_sql(
        "SELECT marketerid, COALESCE(SUM(commission),0) AS total FROM {local_ref_commissions}
          WHERE marketerid $in_sql GROUP BY marketerid",
        $in_params
    );
    foreach ($sub_marketers as $sm) {
        $sub_stats[$sm->userid] = [
            'referred' => isset($sub_ref_rows[$sm->userid])  ? (int)$sub_ref_rows[$sm->userid]->cnt   : 0,
            'total'    => isset($sub_comm_rows[$sm->userid]) ? (float)$sub_comm_rows[$sm->userid]->total : 0.0,
        ];
    }
}

// Commission statement
$commissions = $DB->get_records_sql(
    "SELECT c.id, c.amount, c.commission, c.status, c.timecreated,
            u.firstname, u.lastname,
            crs.fullname AS coursename
       FROM {local_ref_commissions} c
  LEFT JOIN {user}   u   ON u.id   = c.userid
  LEFT JOIN {course} crs ON crs.id = c.courseid
      WHERE c.marketerid = :mid
      ORDER BY c.timecreated DESC",
    ['mid' => $marketer->userid]
);

// Withdrawal history
$withdrawals = $DB->get_records(
    'local_ref_withdrawals',
    ['marketerid' => $marketer->userid],
    'timecreated DESC', '*', 0, 20
);

echo $OUTPUT->header();
?>
<style>
:root {
    --p:  #2563eb;
    --g:  #059669;
    --a:  #d97706;
    --r:  #dc2626;
    --d:  #1e293b;
    --m:  #64748b;
    --b:  #e2e8f0;
    --s:  #f8fafc;
}

.mk { max-width:1380px; margin:0 auto; padding:14px 16px 56px; direction:rtl; }

/* ── Hero ── */
.mk-hero {
    background:#fff; border:1px solid var(--b); border-right:4px solid var(--p);
    border-radius:12px; padding:18px 22px; margin-bottom:18px;
    display:flex; align-items:center; flex-wrap:wrap; gap:14px;
}
.mk-hero-info h2 { margin:0 0 2px; font-size:1.2rem; font-weight:800; color:var(--d); }
.mk-hero-info p  { margin:0; font-size:.83rem; color:var(--m); }
.mk-hero-meta {
    margin-right:auto; display:flex; gap:10px; flex-wrap:wrap; align-items:center;
}
.mk-codebox {
    background:var(--s); border:1px solid var(--b); border-radius:9px;
    padding:9px 18px; text-align:center;
}
.mk-codebox .cb-lbl  { font-size:.65rem; color:var(--m); font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.mk-codebox .cb-code { font-size:1.2rem; font-weight:800; color:var(--p); letter-spacing:.1em; }
.mk-parentbox {
    background:#f0fdf4; border:1px solid #bbf7d0; border-radius:9px;
    padding:9px 16px; font-size:.82rem; color:#065f46;
}
.mk-parentbox span { font-weight:800; }

/* ── Ref link ── */
.mk-reflink {
    background:#fff; border:1px solid var(--b); border-radius:10px;
    padding:13px 18px; margin-bottom:18px;
    display:flex; align-items:center; gap:10px; flex-wrap:wrap;
}
.mk-reflink .rl-lbl { font-size:.7rem; font-weight:700; color:var(--m); text-transform:uppercase;
                      letter-spacing:.05em; white-space:nowrap; }
.mk-reflink input {
    flex:1 1 260px; min-width:0; padding:9px 12px;
    border:1.5px solid var(--b); border-radius:7px; font-size:.85rem; color:var(--d);
    background:var(--s);
}
.mk-reflink input:focus { outline:none; border-color:var(--p); }
.btn-copy {
    background:var(--p); color:#fff; border:none; padding:9px 20px; border-radius:7px;
    font-weight:700; font-size:.85rem; cursor:pointer; white-space:nowrap; transition:opacity .15s;
}
.btn-copy:hover { opacity:.85; }

/* ── Stats ── */
.mk-stats {
    display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
    gap:12px; margin-bottom:18px;
}
.mk-stat {
    background:#fff; border:1px solid var(--b); border-radius:10px;
    padding:14px 16px; border-left:3px solid var(--p);
}
.mk-stat.s-g { border-left-color:var(--g); }
.mk-stat.s-a { border-left-color:var(--a); }
.mk-stat.s-m { border-left-color:#94a3b8; }
.mk-stat .st-l { font-size:.67rem; font-weight:700; color:var(--m); text-transform:uppercase;
                 letter-spacing:.04em; margin-bottom:4px; }
.mk-stat .st-v { font-size:1.3rem; font-weight:800; color:var(--d); line-height:1.1; }
.mk-stat.s-g .st-v { color:#065f46; }
.mk-stat.s-a .st-v { color:#92400e; }

/* ── Alert ── */
.mk-alert {
    border-radius:9px; padding:11px 16px; margin-bottom:16px;
    font-weight:600; font-size:.88rem; display:flex; align-items:center; gap:9px;
    border:1px solid;
}
.mk-alert.ok  { background:#f0fdf4; color:#166534; border-color:#bbf7d0; }
.mk-alert.err { background:#fef2f2; color:#991b1b; border-color:#fecaca; }

/* ── Card ── */
.mk-card {
    background:#fff; border:1px solid var(--b); border-radius:12px;
    margin-bottom:18px; overflow:hidden;
}
.mk-card-hdr {
    padding:11px 18px; background:var(--s); border-bottom:1px solid var(--b);
    display:flex; align-items:center; justify-content:space-between; gap:8px;
}
.mk-card-hdr h3 { margin:0; font-size:.86rem; font-weight:700; color:var(--d); }
.mk-card-hdr .badge {
    background:var(--p); color:#fff; font-size:.65rem; font-weight:700;
    padding:2px 8px; border-radius:20px;
}
.mk-card-body { padding:18px; }

/* ── Withdrawal form ── */
.wd-row {
    display:flex; align-items:flex-end; flex-wrap:wrap; gap:12px;
}
.wd-balance {
    background:#f0fdf4; border:1px solid #bbf7d0; border-radius:9px;
    padding:10px 16px; min-width:160px; flex-shrink:0;
}
.wd-balance .wbl { font-size:.67rem; font-weight:700; color:#166534; text-transform:uppercase;
                   letter-spacing:.04em; margin-bottom:2px; }
.wd-balance .wbv { font-size:1.5rem; font-weight:800; color:#166534; line-height:1.1; }
.wd-field { display:flex; flex-direction:column; gap:5px; flex:1 1 180px; }
.wd-field label { font-size:.78rem; font-weight:700; color:#374151; }
.wd-input {
    padding:9px 12px; border:1.5px solid var(--b); border-radius:7px;
    font-size:.9rem; font-weight:700; color:var(--d); background:var(--s);
    transition:border-color .15s;
}
.wd-input:focus { outline:none; border-color:var(--a); background:#fff; }
.btn-wd {
    background:var(--a); color:#fff; border:none; padding:10px 24px;
    border-radius:7px; font-weight:700; font-size:.88rem; cursor:pointer;
    transition:opacity .15s; white-space:nowrap; align-self:flex-end;
}
.btn-wd:hover  { opacity:.88; }
.btn-wd:disabled { opacity:.45; cursor:not-allowed; }
.wd-hint { font-size:.75rem; color:var(--m); }
.wd-pending-note {
    background:#fffbeb; border:1px solid #fde68a; border-radius:9px;
    padding:12px 16px; font-size:.86rem; font-weight:600; color:#92400e;
    display:flex; align-items:center; gap:8px;
}

/* ── Tables ── */
.mk-tbl { width:100%; border-collapse:collapse; }
.mk-tbl thead th {
    padding:10px 14px; background:var(--s); font-size:.67rem; font-weight:700;
    color:var(--m); text-transform:uppercase; letter-spacing:.04em;
    border-bottom:2px solid var(--b); white-space:nowrap; text-align:right;
}
.mk-tbl tbody td {
    padding:11px 14px; border-bottom:1px solid var(--b);
    font-size:.84rem; color:#334155; vertical-align:middle; text-align:right;
}
.mk-tbl tbody tr:last-child td { border-bottom:none; }
.mk-tbl tbody tr:hover td { background:#f8faff; }
.mk-tbl tfoot td {
    padding:11px 14px; font-size:.84rem; font-weight:800;
    background:var(--s); border-top:2px solid var(--b); text-align:right;
}

/* ── Badges ── */
.sb { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.7rem; font-weight:700; white-space:nowrap; }
.sb-0 { background:#fef3c7; color:#92400e; }
.sb-1 { background:#d1fae5; color:#065f46; }
.sb-2 { background:#dbeafe; color:#1d4ed8; }
.sb-r { background:#fee2e2; color:#991b1b; }

/* ── Empty ── */
.mk-empty { text-align:center; padding:36px 20px; color:var(--m); font-size:.88rem; }
.mk-empty-icon { font-size:1.8rem; margin-bottom:8px; opacity:.4; }

.cur { font-size:.72em; font-weight:700; color:#94a3b8; letter-spacing:.03em; }

@media(max-width:700px) {
    .mk-stats { grid-template-columns:1fr 1fr; }
    .mk-tbl thead th, .mk-tbl tbody td, .mk-tbl tfoot td { padding:8px 10px; }
}
</style>

<?php
$base_out = (new moodle_url('/local/referral/myaccount.php'))->out(false);
$wr_ctx   = context_system::instance();

$statuses = [
    0 => ['انتظار', 'sb-0'],
    1 => ['معتمد',  'sb-1'],
    2 => ['مدفوع',  'sb-2'],
];
$wr_statuses = [
    0 => ['مراجعة', 'sb-0'],
    1 => ['مدفوع',  'sb-2'],
    2 => ['مرفوض',  'sb-r'],
];
?>

<div class="mk">

<!-- ── Hero ── -->
<div class="mk-hero">
    <div class="mk-hero-info">
        <h2><?php echo s(fullname($USER)); ?></h2>
        <p>لوحة حسابك كمسوق &mdash; تابع أداءك وعمولاتك</p>
    </div>
    <div class="mk-hero-meta">
        <?php if ($parent_marketer): ?>
        <div class="mk-parentbox">
            المسوق الرئيسي:
            <span><?php echo s(trim(($parent_marketer->firstname ?? '') . ' ' . ($parent_marketer->lastname ?? ''))); ?></span>
            &mdash; <span><?php echo s($parent_marketer->code); ?></span>
        </div>
        <?php endif; ?>
        <div class="mk-codebox">
            <div class="cb-lbl">كود الإحالة</div>
            <div class="cb-code"><?php echo s($marketer->code); ?></div>
        </div>
    </div>
</div>

<!-- ── Referral link ── -->
<div class="mk-reflink">
    <span class="rl-lbl">رابط الإحالة</span>
    <input type="text" id="mkRefIn" value="<?php echo s($refurl); ?>" readonly>
    <button class="btn-copy" id="mkCopyBtn"
        onclick="navigator.clipboard.writeText(document.getElementById('mkRefIn').value).then(()=>{
            this.textContent='تم النسخ ✓';
            setTimeout(()=>this.textContent='نسخ الرابط',2200);
        })">نسخ الرابط</button>
</div>

<!-- ── Course-specific referral link ── -->
<div class="mk-card" style="margin-top:18px;">
    <div class="mk-card-hdr"><h3>رابط إحالة الكورس <span style="font-weight:400;color:var(--m);font-size:.78rem;">/ Course Referral Link</span></h3></div>
    <div class="mk-card-body">
        <div style="margin-bottom:14px;">
            <label for="mkCourseSelect" style="display:block;font-weight:600;color:#1e293b;margin-bottom:6px;font-size:.92rem;">
                اختر كورساً <span style="font-weight:400;color:var(--m);font-size:.82rem;">/ Select a Course</span>
            </label>
            <select id="mkCourseSelect"
                style="width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:8px;
                       font-size:.95rem;color:#1e293b;background:#fff;cursor:pointer;outline:none;">
                <option value="">-- اختر الكورس / Choose a course --</option>
                <?php foreach ($courses as $course): ?>
                <option value="<?php echo (int)$course->id; ?>"
                        data-name="<?php echo s($course->fullname); ?>"
                        data-url="<?php echo s((new moodle_url('/course/view.php', ['id' => $course->id, 'ref' => $marketer->code]))->out(false)); ?>">
                    <?php echo s($course->fullname); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="mkCourseResult" style="display:none;background:#f8fafc;border:1.5px solid #e2e8f0;
             border-radius:10px;padding:16px 18px;">
            <div style="font-size:.82rem;font-weight:600;color:#64748b;text-transform:uppercase;
                        letter-spacing:.06em;margin-bottom:6px;">الكورس / Course</div>
            <div id="mkCourseName" style="font-size:1rem;font-weight:700;color:#1e293b;margin-bottom:14px;"></div>

            <div style="font-size:.82rem;font-weight:600;color:#64748b;text-transform:uppercase;
                        letter-spacing:.06em;margin-bottom:6px;">رابط الإحالة / Referral Link</div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="text" id="mkCourseRefIn" readonly
                    style="flex:1;min-width:0;padding:9px 13px;border:1.5px solid #e2e8f0;border-radius:8px;
                           font-size:.9rem;color:#1e293b;background:#fff;">
                <button id="mkCourseCopyBtn"
                    style="padding:9px 20px;background:#2563eb;color:#fff;border:none;border-radius:8px;
                           font-weight:600;font-size:.9rem;cursor:pointer;white-space:nowrap;transition:background .2s;"
                    onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'"
                    onclick="navigator.clipboard.writeText(document.getElementById('mkCourseRefIn').value).then(()=>{
                        this.textContent='تم النسخ ✓';
                        setTimeout(()=>this.textContent='نسخ الرابط',2200);
                    })">نسخ الرابط</button>
            </div>
        </div>

        <?php if (empty($courses)): ?>
        <div class="mk-empty">
            <div class="mk-empty-icon">&#x1F4DA;</div>
            <p>لا توجد كورسات متاحة / No courses available.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
    var sel    = document.getElementById('mkCourseSelect');
    var result = document.getElementById('mkCourseResult');
    var nameEl = document.getElementById('mkCourseName');
    var urlEl  = document.getElementById('mkCourseRefIn');
    if (!sel) return;
    sel.addEventListener('change', function(){
        var opt = sel.options[sel.selectedIndex];
        if (!opt || !opt.value) { result.style.display = 'none'; return; }
        nameEl.textContent = opt.getAttribute('data-name');
        urlEl.value        = opt.getAttribute('data-url');
        result.style.display = 'block';
    });
})();
</script>

<!-- ── Alert ── -->
<?php if ($withdrawmsg):
    [$wtype, $wmsg] = explode(':', $withdrawmsg, 2); ?>
<div class="mk-alert <?php echo $wtype === 'error' ? 'err' : 'ok'; ?>">
    <span><?php echo $wtype === 'error' ? '&#x26A0;' : '&#x2705;'; ?></span>
    <span><?php echo s($wmsg); ?></span>
</div>
<?php endif; ?>

<!-- ── Stats ── -->
<div class="mk-stats">
<?php
foreach ([
    ['',    'محوَّلون',         $referred_count,                          ''],
    ['',    'إجمالي العمولات',  fmt_sdg($total_commission),   ''],
    ['s-g', 'تم دفعها',         fmt_sdg($paid_commission),    ''],
    ['s-g', 'متاح للسحب',       fmt_sdg($withdrawable_total), ''],
    ['',    'صافي قابل للسحب',  fmt_sdg($net_balance),        ''],
] as [$cls, $lbl, $val, $_]) {
    echo '<div class="mk-stat ' . $cls . '"><div class="st-l">' . $lbl . '</div><div class="st-v">' . $val . '</div></div>';
}
?>
</div>

<!-- ── Quick links ── -->
<div style="margin-bottom:18px;">
    <a href="<?php echo (new moodle_url('/local/referral/mystudents.php'))->out(false); ?>"
       style="display:inline-flex;align-items:center;gap:8px;background:#2563eb;color:#fff;
              padding:10px 22px;border-radius:9px;font-weight:700;font-size:.88rem;
              text-decoration:none;transition:opacity .15s;"
       onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
        &#x1F393; طلابي المُحالون / My Referred Students
        <span style="background:rgba(255,255,255,.25);border-radius:20px;padding:1px 9px;font-size:.75rem;">
            <?php echo $referred_count; ?>
        </span>
    </a>
</div>

<!-- ── Withdrawal request ── -->
<div class="mk-card">
    <div class="mk-card-hdr"><h3>طلب سحب عمولة</h3></div>
    <div class="mk-card-body">
    <?php if ($withdrawable_total <= 0): ?>
        <div class="mk-empty">
            <div class="mk-empty-icon">&#x1F4B3;</div>
            <p>لا توجد عمولات قابلة للسحب بعد.</p>
        </div>
    <?php elseif ($net_balance <= 0): ?>
        <div class="wd-pending-note">
            &#x23F3; رصيدك المتاح محجوز بطلب سحب معلق بانتظار موافقة الإدارة.
        </div>
    <?php else: ?>
        <form method="post" action="<?php echo $base_out; ?>">
            <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]); ?>
            <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'requestwithdraw','value'=>1]); ?>
            <div class="wd-row">
                <div class="wd-balance">
                    <div class="wbl">رصيدك المتاح</div>
                    <div class="wbv"><?php echo fmt_sdg($net_balance); ?></div>
                </div>
                <div class="wd-field">
                    <label for="wdAmt">المبلغ المراد سحبه</label>
                    <input type="number" id="wdAmt" name="withdrawamount" class="wd-input"
                           min="<?php echo $min_withdrawal > 0 ? $min_withdrawal : '0.01'; ?>"
                           max="<?php echo $net_balance; ?>"
                           step="0.01" placeholder="0.00" required
                           <?php echo $net_balance < $min_withdrawal ? 'disabled' : ''; ?>>
                    <span class="wd-hint">الحد الأدنى: <?php echo fmt_sdg($min_withdrawal); ?></span>
                </div>
                <button type="submit" class="btn-wd"
                    <?php echo $net_balance < $min_withdrawal ? 'disabled' : ''; ?>>
                    إرسال طلب السحب
                </button>
            </div>
            <?php if ($net_balance < $min_withdrawal): ?>
            <p style="margin:10px 0 0;font-size:.78rem;color:var(--r);">
                رصيدك أقل من الحد الأدنى للسحب — الزر يُفعَّل عند بلوغه.
            </p>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    </div>
</div>

<!-- ── Commission statement ── -->
<div class="mk-card">
    <div class="mk-card-hdr">
        <h3>كشف حساب العمولات</h3>
        <span class="badge"><?php echo count($commissions); ?> سجل</span>
    </div>
    <?php if (empty($commissions)): ?>
    <div class="mk-empty">
        <div class="mk-empty-icon">&#x1F4B8;</div>
        <p>لا توجد عمولات بعد — شارك رابط الإحالة لتبدأ الكسب!</p>
    </div>
    <?php else:
        $stmt_amount = 0; $stmt_comm = 0;
        foreach ($commissions as $c) { $stmt_amount += (float)$c->amount; $stmt_comm += (float)$c->commission; }
    ?>
    <div style="overflow-x:auto;">
    <table class="mk-tbl">
        <thead>
            <tr>
                <th>التاريخ</th>
                <th>الطالب</th>
                <th>الكورس</th>
                <th style="text-align:left;">مبلغ التسجيل</th>
                <th style="text-align:left;">عمولتي</th>
                <th>الحالة</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($commissions as $c):
            [$slbl, $scls] = $statuses[(int)$c->status] ?? ['—', 'sb-0'];
            $sname = trim(($c->firstname ?? '') . ' ' . ($c->lastname ?? '')) ?: '—';
            $cname = $c->coursename ?? '—';
        ?>
            <tr>
                <td style="color:var(--m);white-space:nowrap;"><?php echo userdate($c->timecreated, '%d/%m/%Y'); ?></td>
                <td style="font-weight:600;"><?php echo s($sname); ?></td>
                <td style="color:var(--m);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                    title="<?php echo s($cname); ?>"><?php echo s($cname); ?></td>
                <td style="text-align:left;font-weight:700;"><?php echo fmt_sdg($c->amount); ?></td>
                <td style="text-align:left;font-weight:800;color:var(--g);"><?php echo fmt_sdg($c->commission); ?></td>
                <td><span class="sb <?php echo $scls; ?>"><?php echo $slbl; ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">الإجمالي</td>
                <td style="text-align:left;"><?php echo fmt_sdg($stmt_amount); ?></td>
                <td style="text-align:left;color:var(--g);"><?php echo fmt_sdg($stmt_comm); ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── Main marketer: type indicator ── -->
<?php if ($marketer_type === 'main'): ?>
<div class="mk-card" style="border-right:4px solid #059669;">
    <div class="mk-card-hdr">
        <h3 style="color:#065f46;">&#x2B50; مسوق رئيسي</h3>
    </div>
    <div class="mk-card-body" style="padding:12px 18px;font-size:.86rem;color:#374151;">
        أنت مسوق رئيسي. يمكنك إدارة مسوقيك الفرعيين ومراجعة طلبات السحب الواردة إليك.
    </div>
</div>
<?php elseif ($marketer_type === 'sub' && $parent_marketer): ?>
<div class="mk-card" style="border-right:4px solid #94a3b8;">
    <div class="mk-card-hdr">
        <h3 style="color:#475569;">&#x1F4CE; مسوق فرعي</h3>
    </div>
    <div class="mk-card-body" style="padding:12px 18px;font-size:.86rem;color:#374151;">
        أنت مسوق فرعي تحت إشراف
        <strong><?php echo s(trim(($parent_marketer->firstname ?? '') . ' ' . ($parent_marketer->lastname ?? ''))); ?></strong>.
        سيتم توجيه طلبات سحبك إليه للمراجعة.
    </div>
</div>
<?php endif; ?>

<?php
/* ── MAIN MARKETER: Pending sub-marketer withdrawal requests ── */
$sub_pending_wds = [];
$tc_pending_wds  = [];
if ($marketer_type === 'main') {
    $sub_pending_wds = $DB->get_records_sql(
        "SELECT w.*, u.firstname, u.lastname, mp.code
           FROM {local_ref_withdrawals} w
      LEFT JOIN {user}                       u  ON u.id  = w.marketerid
      LEFT JOIN {local_ref_marketer_profile} mp ON mp.userid = w.marketerid
          WHERE w.mainmarketerid = :mid AND w.status = 0
       ORDER BY w.timecreated ASC",
        ['mid' => $USER->id]
    );

    // Teacher withdrawal requests routed to this main marketer.
    if (class_exists('\local_teacher_commissions\withdrawal_manager')) {
        $tc_pending_wds = \local_teacher_commissions\withdrawal_manager::get_requests_for_main(
            (int)$USER->id,
            \local_teacher_commissions\withdrawal_manager::STATUS_PENDING
        );
    }
}

if ($marketer_type === 'main' && (!empty($sub_pending_wds) || !empty($tc_pending_wds))):
?>
<!-- ── Pending withdrawal requests section ── -->
<div class="mk-card">
    <div class="mk-card-hdr">
        <h3>طلبات السحب المعلقة الواردة إليك</h3>
        <span class="badge"><?php echo count($sub_pending_wds) + count($tc_pending_wds); ?></span>
    </div>
    <div class="mk-card-body" style="padding:0;">

    <?php if (!empty($sub_pending_wds)): ?>
        <div style="padding:10px 18px 5px;font-size:.75rem;font-weight:700;color:var(--m);
                    text-transform:uppercase;letter-spacing:.04em;">طلبات المسوقين الفرعيين</div>
        <div style="overflow-x:auto;">
        <table class="mk-tbl">
            <thead>
                <tr>
                    <th>المسوق</th>
                    <th style="text-align:left;">المبلغ</th>
                    <th>التاريخ</th>
                    <th style="text-align:center;">الإجراء</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sub_pending_wds as $swd):
                $swname = trim(($swd->firstname ?? '') . ' ' . ($swd->lastname ?? '')) ?: '—';
                $approve_url = new moodle_url('/local/referral/myaccount.php', [
                    'approveaction' => 'approve', 'approvewid' => $swd->id,
                    'approvetype'   => 'marketer', 'sesskey' => sesskey(),
                ]);
                $reject_url = new moodle_url('/local/referral/myaccount.php', [
                    'approveaction' => 'reject',  'approvewid' => $swd->id,
                    'approvetype'   => 'marketer', 'sesskey' => sesskey(),
                ]);
            ?>
                <tr>
                    <td style="font-weight:700;"><?php echo s($swname); ?>
                        <?php if (!empty($swd->code)): ?>
                        <span style="background:#dbeafe;color:#1d4ed8;font-size:.68rem;font-weight:700;
                                     padding:1px 7px;border-radius:20px;"><?php echo s($swd->code); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:left;font-weight:800;color:#059669;"><?php echo fmt_sdg($swd->amount); ?></td>
                    <td style="color:var(--m);font-size:.78rem;"><?php echo userdate($swd->timecreated, '%d/%m/%Y'); ?></td>
                    <td style="text-align:center;">
                        <div style="display:flex;gap:5px;justify-content:center;">
                            <a href="<?php echo $approve_url->out(false); ?>" class="btn-wd"
                               style="font-size:.75rem;padding:5px 12px;background:#059669;"
                               onclick="return confirm('الموافقة على سحب <?php echo fmt_sdg($swd->amount, false); ?> SDG؟')">
                                موافقة
                            </a>
                            <a href="<?php echo $reject_url->out(false); ?>" class="btn-wd"
                               style="font-size:.75rem;padding:5px 12px;background:#dc2626;"
                               onclick="return confirm('رفض طلب السحب؟')">
                                رفض
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($tc_pending_wds)): ?>
        <div style="padding:10px 18px 5px;font-size:.75rem;font-weight:700;color:var(--m);
                    text-transform:uppercase;letter-spacing:.04em;border-top:1px solid var(--b);">طلبات سحب المعلمين</div>
        <div style="overflow-x:auto;">
        <table class="mk-tbl">
            <thead>
                <tr>
                    <th>المعلم</th>
                    <th style="text-align:left;">المبلغ</th>
                    <th>العملة</th>
                    <th>التاريخ</th>
                    <th style="text-align:center;">الإجراء</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tc_pending_wds as $twd):
                $twname = trim(($twd->teacher_firstname ?? '') . ' ' . ($twd->teacher_lastname ?? '')) ?: '—';
                $tapprove_url = new moodle_url('/local/referral/myaccount.php', [
                    'approveaction' => 'approve', 'approvewid' => $twd->id,
                    'approvetype'   => 'teacher', 'sesskey' => sesskey(),
                ]);
                $treject_url = new moodle_url('/local/referral/myaccount.php', [
                    'approveaction' => 'reject',  'approvewid' => $twd->id,
                    'approvetype'   => 'teacher', 'sesskey' => sesskey(),
                ]);
            ?>
                <tr>
                    <td style="font-weight:700;"><?php echo s($twname); ?></td>
                    <td style="text-align:left;font-weight:800;color:#059669;"><?php echo fmt_sdg($twd->amount); ?></td>
                    <td style="font-size:.78rem;color:var(--m);"><?php echo s($twd->currency); ?></td>
                    <td style="color:var(--m);font-size:.78rem;"><?php echo userdate($twd->timecreated, '%d/%m/%Y'); ?></td>
                    <td style="text-align:center;">
                        <div style="display:flex;gap:5px;justify-content:center;">
                            <a href="<?php echo $tapprove_url->out(false); ?>" class="btn-wd"
                               style="font-size:.75rem;padding:5px 12px;background:#059669;"
                               onclick="return confirm('الموافقة على سحب المعلم <?php echo fmt_sdg($twd->amount, false); ?> SDG؟')">
                                موافقة
                            </a>
                            <a href="<?php echo $treject_url->out(false); ?>" class="btn-wd"
                               style="font-size:.75rem;padding:5px 12px;background:#dc2626;"
                               onclick="return confirm('رفض طلب السحب؟')">
                                رفض
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    </div>
</div>
<?php endif; ?>

<!-- ── Sub-marketers ── -->
<?php if (!empty($sub_marketers)): ?>
<div class="mk-card">
    <div class="mk-card-hdr">
        <h3>المسوقون الفرعيون</h3>
        <span class="badge"><?php echo count($sub_marketers); ?></span>
    </div>
    <div style="overflow-x:auto;">
    <table class="mk-tbl">
        <thead>
            <tr>
                <th>الاسم</th>
                <th>الكود</th>
                <th style="text-align:center;">نسبة عمولته</th>
                <th style="text-align:center;">المستخدمون المُحالون</th>
                <th style="text-align:left;">إجمالي عمولاته المكتسبة</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sub_marketers as $sm):
            $smname = trim(($sm->firstname ?? '') . ' ' . ($sm->lastname ?? '')) ?: '(محذوف #' . $sm->userid . ')';
            $sst    = $sub_stats[$sm->userid] ?? ['referred' => 0, 'total' => 0.0];
        ?>
            <tr>
                <td style="font-weight:700;color:var(--d);"><?php echo s($smname); ?></td>
                <td>
                    <span style="background:#dbeafe;color:#1d4ed8;font-size:.71rem;font-weight:700;
                                 padding:2px 9px;border-radius:20px;"><?php echo s($sm->code); ?></span>
                </td>
                <td style="text-align:center;font-weight:700;"><?php echo (int)$sm->commission_percentage; ?>%</td>
                <td style="text-align:center;font-weight:800;color:var(--p);font-size:1.05rem;"><?php echo $sst['referred']; ?></td>
                <td style="text-align:left;font-weight:800;color:var(--g);"><?php echo fmt_sdg($sst['total']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Withdrawal history ── -->
<div class="mk-card">
    <div class="mk-card-hdr">
        <h3>سجل طلبات السحب</h3>
        <span class="badge" style="background:var(--m);"><?php echo count($withdrawals); ?></span>
    </div>
    <?php if (empty($withdrawals)): ?>
    <div class="mk-empty">
        <div class="mk-empty-icon">&#x1F4C4;</div>
        <p>لا توجد طلبات سحب بعد.</p>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="mk-tbl">
        <thead>
            <tr>
                <th>التاريخ</th>
                <th style="text-align:left;">المبلغ</th>
                <th>الحالة</th>
                <th>الإيصال</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($withdrawals as $wr):
            [$wrlbl, $wrcls] = $wr_statuses[$wr->status] ?? ['—', 'sb-0'];
            $receipt_link = '<span style="color:#94a3b8;font-size:.78rem;">—</span>';
            if (!empty($wr->receipt_file)) {
                $dl_url = moodle_url::make_pluginfile_url(
                    $wr_ctx->id, 'local_referral', 'withdrawal_receipts',
                    $wr->id, '/', $wr->receipt_file, true
                );
                $receipt_link = '<a href="' . $dl_url->out(false) . '" target="_blank"
                    style="font-size:.8rem;font-weight:700;color:var(--p);text-decoration:none;">تحميل</a>';
            }
        ?>
            <tr>
                <td style="color:var(--m);white-space:nowrap;"><?php echo userdate($wr->timecreated, '%d/%m/%Y'); ?></td>
                <td style="text-align:left;font-weight:800;color:#065f46;font-size:.95rem;"><?php echo fmt_sdg($wr->amount); ?></td>
                <td><span class="sb <?php echo $wrcls; ?>"><?php echo $wrlbl; ?></span></td>
                <td><?php echo $receipt_link; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

</div><!-- /mk -->

<?php echo $OUTPUT->footer();
