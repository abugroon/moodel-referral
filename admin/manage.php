<?php
// /local/referral/admin/manage.php
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
$sess       = sesskey();

// ── Helper: resolve marketer type from profile (fallback from parent_userid if type col missing) ──
function local_referral_get_type(\stdClass $mp): string {
    if (!empty($mp->type)) {
        return $mp->type;
    }
    return (!empty($mp->parent_userid)) ? 'sub' : 'main';
}

$base_url = new moodle_url('/local/referral/admin/manage.php');

$min_withdrawal      = (float)(get_config('local_referral', 'min_withdrawal') ?: 10);
$def_commission      = (int)(get_config('local_referral', 'default_commission_percentage') ?: 10);
$def_indirect        = (int)(get_config('local_referral', 'default_indirect_commission_percentage') ?: 0);
$enable_parent       = (bool)(get_config('local_referral', 'enable_parent_linking') ?: 0);

/* =====================================================
   ACTION: حفظ الإعدادات
===================================================== */
if ($action === 'savesettings' && confirm_sesskey()) {
    $new_min      = optional_param('min_withdrawal', 10, PARAM_FLOAT);
    $new_def      = optional_param('default_commission', 10, PARAM_INT);
    $new_indirect = optional_param('default_indirect_commission', 0, PARAM_INT);
    set_config('min_withdrawal', max(0, $new_min), 'local_referral');
    set_config('default_commission_percentage', max(0, min(100, $new_def)), 'local_referral');
    set_config('default_indirect_commission_percentage', max(0, min(100, $new_indirect)), 'local_referral');
    redirect($base_url, 'تم حفظ الإعدادات بنجاح.', 2);
}

/* =====================================================
   ACTION: دفع العمولات المعتمدة
===================================================== */
if ($action === 'pay' && $marketerid && confirm_sesskey()) {
    $DB->execute(
        "UPDATE {local_ref_commissions} SET status = 2 WHERE marketerid = :mid AND status < 2",
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
    $new_type = ($parentid > 0) ? 'sub' : 'main';
    $DB->set_field('local_ref_marketer_profile', 'parent_userid', $parentid ?: null, ['userid' => $marketerid]);
    $DB->set_field('local_ref_marketer_profile', 'type',          $new_type,         ['userid' => $marketerid]);
    $DB->set_field('local_ref_marketer_profile', 'timemodified',  $now,              ['userid' => $marketerid]);
    redirect($base_url, 'تم تحديث المسوق الأب.', 2);
}

/* =====================================================
   ACTION: تحويل المسوق إلى نوع محدد (main / sub)
===================================================== */
if ($action === 'settype' && $marketerid && confirm_sesskey()) {
    $newtype  = optional_param('newtype', 'main', PARAM_ALPHA);
    $parentid = optional_param('parentid', 0, PARAM_INT);

    $profile = $DB->get_record('local_ref_marketer_profile', ['userid' => $marketerid], '*', IGNORE_MISSING);
    if (!$profile) {
        redirect($base_url, 'المسوق غير موجود.', 2);
    }

    if ($newtype === 'main') {
        // Promote to main: clear parent, update type.
        $DB->set_field('local_ref_marketer_profile', 'parent_userid', null, ['userid' => $marketerid]);
        $DB->set_field('local_ref_marketer_profile', 'type', 'main',         ['userid' => $marketerid]);
        $DB->set_field('local_ref_marketer_profile', 'timemodified', $now,   ['userid' => $marketerid]);
        redirect($base_url, 'تم تحويل المسوق إلى مسوق رئيسي.', 2);

    } elseif ($newtype === 'sub') {
        if ($parentid <= 0 || $parentid === $marketerid) {
            redirect($base_url, 'يجب تحديد مسوق رئيسي صالح.', 2);
        }
        // Prevent: sub cannot become parent of existing sub-marketers.
        $has_subs = $DB->record_exists('local_ref_marketer_profile', ['parent_userid' => $marketerid]);
        if ($has_subs) {
            redirect($base_url, 'لا يمكن تحويل مسوق لديه مسوقون فرعيون إلى مسوق فرعي.', 2);
        }
        // Prevent circular: parent must be a main marketer (not a sub himself).
        $parent = $DB->get_record('local_ref_marketer_profile', ['userid' => $parentid], 'type, parent_userid', IGNORE_MISSING);
        if (!$parent || local_referral_get_type($parent) !== 'main') {
            redirect($base_url, 'يجب أن يكون الأب مسوقاً رئيسياً.', 2);
        }
        $DB->set_field('local_ref_marketer_profile', 'parent_userid', $parentid, ['userid' => $marketerid]);
        $DB->set_field('local_ref_marketer_profile', 'type', 'sub',              ['userid' => $marketerid]);
        $DB->set_field('local_ref_marketer_profile', 'timemodified', $now,       ['userid' => $marketerid]);
        redirect($base_url, 'تم تعيين المسوق الفرعي بنجاح.', 2);
    }
}

/* =====================================================
   ACTION: تحديث نسبة العمولة
===================================================== */
if ($action === 'setcommission' && $marketerid && confirm_sesskey()) {
    $pct        = max(0, min(100, optional_param('commission', 10, PARAM_INT)));
    $indirect   = max(0, min(100, optional_param('indirect_commission', 0, PARAM_INT)));
    $DB->set_field('local_ref_marketer_profile', 'commission_percentage',          $pct,      ['userid' => $marketerid]);
    $DB->set_field('local_ref_marketer_profile', 'indirect_commission_percentage', $indirect, ['userid' => $marketerid]);
    $DB->set_field('local_ref_marketer_profile', 'timemodified', $now, ['userid' => $marketerid]);
    redirect($base_url, 'تم تحديث نسبة العمولة.', 2);
}

/* =====================================================
   ACTION: الموافقة على طلب سحب
===================================================== */
if ($action === 'paywithdrawal' && $wid && $_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $wr = $DB->get_record('local_ref_withdrawals', ['id' => $wid, 'status' => 0]);
    if ($wr) {
        $transaction = $DB->start_delegated_transaction();
        try {
            $DB->set_field('local_ref_withdrawals', 'status', 1, ['id' => $wid]);
            $DB->set_field('local_ref_withdrawals', 'timemodified', $now, ['id' => $wid]);

            if (!empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
                if ($_FILES['receipt_file']['size'] <= 5242880) {
                    $ctx      = context_system::instance();
                    $fs       = get_file_storage();
                    $fileinfo = [
                        'contextid' => $ctx->id,
                        'component' => 'local_referral',
                        'filearea'  => 'withdrawal_receipts',
                        'itemid'    => $wid,
                        'filepath'  => '/',
                        'filename'  => clean_filename($_FILES['receipt_file']['name']),
                    ];
                    $fs->create_file_from_pathname($fileinfo, $_FILES['receipt_file']['tmp_name']);
                    $DB->set_field('local_ref_withdrawals', 'receipt_file', $fileinfo['filename'], ['id' => $wid]);
                }
            }

            // Withdrawal amount is tracked directly in local_ref_withdrawals (status=1).
            // Balance formula in myaccount.php uses SUM(paid withdrawals) to compute
            // what has been paid out — no need to mutate individual commission records.
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            \core\notification::error($e->getMessage());
            redirect($base_url);
        }
    }
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
   ACTION: حذف سجل دفعة معالجة
===================================================== */
if ($action === 'deletewithdrawal' && $wid && confirm_sesskey()) {
    $DB->delete_records('local_ref_withdrawals', ['id' => $wid]);
    redirect($base_url, 'تم حذف سجل الدفعة.', 2);
}

/* =====================================================
   ACTION: حذف مسوق
===================================================== */
if ($delete) {
    $marketer = $DB->get_record('local_ref_marketer_profile', ['userid' => $delete], '*', IGNORE_MISSING);
    if (!$marketer) {
        redirect($base_url);
    }
    if ($confirm && confirm_sesskey()) {
        $DB->delete_records('local_ref_commissions',      ['marketerid' => $delete]);
        $DB->delete_records('local_ref_users',            ['marketerid' => $delete]);
        $DB->delete_records('local_ref_withdrawals',      ['marketerid' => $delete]);
        $DB->delete_records('local_ref_marketer_profile', ['userid'     => $delete]);
        redirect($base_url, 'تم حذف المسوق بنجاح.', 2);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        'هل أنت متأكد من حذف هذا المسوق؟ سيتم حذف جميع عمولاته وطلبات سحبه.',
        new moodle_url('/local/referral/admin/manage.php', ['delete' => $delete, 'confirm' => 1, 'sesskey' => $sess]),
        $base_url
    );
    echo $OUTPUT->footer();
    exit;
}

/* =====================================================
   تحميل البيانات
===================================================== */

// ── Safety: ensure table exists; auto-migrate from old table if needed ──
{
    $dbman = $DB->get_manager();

    if (!$dbman->table_exists(new xmldb_table('local_ref_marketer_profile'))) {
        redirect(
            new moodle_url('/admin/index.php'),
            'الجدول local_ref_marketer_profile غير موجود — الرجاء تشغيل ترقية قاعدة البيانات.',
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // If profile table is empty but the old table still has rows, migrate now.
    $old_tbl = new xmldb_table('local_ref_marketers');
    if ($DB->count_records('local_ref_marketer_profile') == 0 && $dbman->table_exists($old_tbl)) {
        $old_rows = $DB->get_records_select(
            'local_ref_marketers', 'userid IS NOT NULL', [], '',
            'id, userid, code, commission_percentage, parent_id, timecreated, timemodified'
        );
        $id_to_userid = [];
        foreach ($old_rows as $r) {
            $id_to_userid[$r->id] = (int)$r->userid;
        }
        $migration_now = time();
        foreach ($old_rows as $r) {
            if ($DB->record_exists('local_ref_marketer_profile', ['userid' => $r->userid])) {
                continue;
            }
            $parent_uid = (!empty($r->parent_id) && isset($id_to_userid[$r->parent_id]))
                ? $id_to_userid[$r->parent_id] : null;
            $DB->insert_record('local_ref_marketer_profile', (object)[
                'userid'                => (int)$r->userid,
                'code'                  => $r->code,
                'commission_percentage' => (int)$r->commission_percentage,
                'parent_userid'         => $parent_uid,
                'timecreated'           => !empty($r->timecreated)  ? (int)$r->timecreated  : $migration_now,
                'timemodified'          => !empty($r->timemodified) ? (int)$r->timemodified : $migration_now,
            ]);
        }
        foreach ($id_to_userid as $old_id => $uid) {
            foreach (['local_ref_users', 'local_ref_commissions', 'local_ref_withdrawals'] as $child_tbl) {
                if ($dbman->table_exists(new xmldb_table($child_tbl))) {
                    $DB->execute(
                        "UPDATE {{$child_tbl}} SET marketerid = :uid WHERE marketerid = :old_id",
                        ['uid' => $uid, 'old_id' => $old_id]
                    );
                }
            }
        }
    }
}

// LEFT JOIN so marketers whose Moodle account was deleted still appear.
// Coalesce type from DB field; fall back to deriving it from parent_userid.
$marketers = $DB->get_records_sql(
    "SELECT mp.userid, mp.code, mp.commission_percentage, mp.indirect_commission_percentage, mp.parent_userid,
            CASE WHEN mp.type IS NOT NULL AND mp.type <> '' THEN mp.type
                 WHEN mp.parent_userid IS NOT NULL AND mp.parent_userid > 0 THEN 'sub'
                 ELSE 'main' END AS type,
            u.firstname, u.lastname, u.email,
            pu.firstname AS parentfirstname, pu.lastname AS parentlastname,
            pm.code AS parentcode
       FROM {local_ref_marketer_profile} mp
  LEFT JOIN {user}                       u   ON u.id  = mp.userid
  LEFT JOIN {local_ref_marketer_profile} pm  ON pm.userid = mp.parent_userid
  LEFT JOIN {user}                       pu  ON pu.id = mp.parent_userid
   ORDER BY COALESCE(u.firstname, 'zzz') ASC, COALESCE(u.lastname, 'zzz') ASC"
);

$all_marketers = $DB->get_records_sql(
    "SELECT mp.userid, mp.code, mp.type,
            CASE WHEN mp.type IS NOT NULL AND mp.type <> '' THEN mp.type
                 WHEN mp.parent_userid IS NOT NULL AND mp.parent_userid > 0 THEN 'sub'
                 ELSE 'main' END AS resolved_type,
            u.firstname, u.lastname
       FROM {local_ref_marketer_profile} mp
  LEFT JOIN {user} u ON u.id = mp.userid
      ORDER BY COALESCE(u.firstname, 'zzz') ASC"
);

// Batch stats — 2 queries instead of N×5
$comm_stats_raw = $DB->get_records_sql(
    "SELECT marketerid,
            COALESCE(SUM(commission), 0) AS total,
            COALESCE(SUM(CASE WHEN status=0 THEN commission ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status=1 THEN commission ELSE 0 END), 0) AS approved,
            COALESCE(SUM(CASE WHEN status=2 THEN commission ELSE 0 END), 0) AS paid
       FROM {local_ref_commissions}
      GROUP BY marketerid"
);
$referred_raw = $DB->get_records_sql(
    "SELECT marketerid, COUNT(*) AS cnt FROM {local_ref_users} GROUP BY marketerid"
);

// Paid withdrawal amounts per marketer
$wd_paid_raw = $DB->get_records_sql(
    "SELECT marketerid, COALESCE(SUM(amount), 0) AS wd_paid
       FROM {local_ref_withdrawals}
      WHERE status = 1
      GROUP BY marketerid"
);

$stats = [];
foreach ($marketers as $m) {
    $cs    = $comm_stats_raw[$m->userid] ?? null;
    $wd    = (float)(isset($wd_paid_raw[$m->userid]) ? $wd_paid_raw[$m->userid]->wd_paid : 0);
    $comm_paid = $cs ? (float)$cs->paid : 0.0;
    $stats[$m->userid] = [
        'referred'  => (int)(isset($referred_raw[$m->userid]) ? $referred_raw[$m->userid]->cnt : 0),
        'total'     => $cs ? (float)$cs->total    : 0.0,
        'pending'   => $cs ? (float)$cs->pending  : 0.0,
        'approved'  => $cs ? (float)$cs->approved : 0.0,
        'paid'      => $comm_paid + $wd,   // direct approvals + paid withdrawals
        'wd_paid'   => $wd,
    ];
}

$total_marketers           = count($marketers);
$total_all                 = (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions}");
$total_approved            = (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE status=0");
$total_paid_direct         = (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE status=2");
$total_paid_wd             = (float)$DB->get_field_sql("SELECT COALESCE(SUM(amount),0) FROM {local_ref_withdrawals} WHERE status=1");
$total_paid                = $total_paid_direct + $total_paid_wd;
$pending_withdrawals_count = (int)$DB->count_records('local_ref_withdrawals', ['status' => 0]);

$pending_withdrawals = $DB->get_records_sql(
    "SELECT w.*, mp.code, u.firstname, u.lastname
       FROM {local_ref_withdrawals} w
  LEFT JOIN {local_ref_marketer_profile} mp ON mp.userid = w.marketerid
  LEFT JOIN {user} u ON u.id = w.marketerid
      WHERE w.status = 0
   ORDER BY w.timecreated ASC"
);

$processed_withdrawals = $DB->get_records_sql(
    "SELECT w.*, mp.code, u.firstname, u.lastname
       FROM {local_ref_withdrawals} w
  LEFT JOIN {local_ref_marketer_profile} mp ON mp.userid = w.marketerid
  LEFT JOIN {user} u ON u.id = w.marketerid
      WHERE w.status IN (1, 2)
   ORDER BY COALESCE(w.timemodified, w.timecreated) DESC"
);

echo $OUTPUT->header();
echo local_referral_admin_tabs('manage');
?>
<style>
/* ── Referral plugin — unified light theme ── */
:root {
    --rp: #2563eb;
    --rg: #059669;
    --ra: #d97706;
    --rr: #dc2626;
    --rd: #1e293b;
    --rm: #64748b;
    --rb: #e2e8f0;
    --rs: #f8fafc;
}

.ref-wrap { padding: 2px 0 48px; }

/* Stats row */
.ref-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(135px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}
.ref-stat {
    background: #fff;
    border: 1px solid var(--rb);
    border-radius: 10px;
    padding: 14px 16px;
    border-left: 3px solid var(--rp);
}
.ref-stat.s-green { border-left-color: var(--rg); }
.ref-stat.s-amber { border-left-color: var(--ra); }
.ref-stat.s-red   { border-left-color: var(--rr); }
.ref-stat .st-lbl {
    font-size: .7rem; font-weight: 600; color: var(--rm);
    text-transform: uppercase; letter-spacing: .04em; margin-bottom: 5px;
}
.ref-stat .st-val { font-size: 1.4rem; font-weight: 800; color: var(--rd); line-height: 1.1; }

/* Cards */
.ref-card {
    background: #fff;
    border: 1px solid var(--rb);
    border-radius: 12px;
    margin-bottom: 20px;
    overflow: hidden;
}
.ref-card-hdr {
    padding: 12px 18px;
    background: var(--rs);
    border-bottom: 1px solid var(--rb);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.ref-card-hdr.clickable { cursor: pointer; user-select: none; }
.ref-card-hdr h3 {
    margin: 0;
    font-size: .88rem;
    font-weight: 700;
    color: var(--rd);
    display: flex;
    align-items: center;
    gap: 7px;
}
.hdr-badge {
    background: var(--rp); color: #fff;
    font-size: .68rem; font-weight: 700;
    padding: 2px 8px; border-radius: 20px;
}
.hdr-badge.ba { background: var(--ra); }
.ref-card-body { padding: 18px; }
.ref-caret { font-size: .78rem; color: var(--rm); transition: transform .2s; }
.ref-caret.open { transform: rotate(180deg); }
.ref-collapse { display: none; }
.ref-collapse.open { display: block; }

/* Settings grid */
.ref-settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 16px;
    align-items: end;
}
.ref-field { display: flex; flex-direction: column; gap: 5px; }
.ref-field label { font-size: .8rem; font-weight: 700; color: #374151; }
.ref-fhint { font-size: .72rem; color: var(--rm); }
.ref-input {
    padding: 8px 12px;
    border: 1.5px solid var(--rb);
    border-radius: 8px;
    font-size: .9rem;
    color: var(--rd);
    background: var(--rs);
    transition: border-color .15s;
    font-weight: 600;
}
.ref-input:focus { outline: none; border-color: var(--rp); background: #fff; }

/* Table */
.ref-tbl { width: 100%; border-collapse: collapse; }
.ref-tbl thead th {
    padding: 10px 14px;
    background: var(--rs);
    font-size: .69rem;
    font-weight: 700;
    color: var(--rm);
    text-transform: uppercase;
    letter-spacing: .04em;
    border-bottom: 2px solid var(--rb);
    white-space: nowrap;
}
.ref-tbl tbody td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--rb);
    font-size: .86rem;
    color: #334155;
    vertical-align: middle;
}
.ref-tbl tbody tr:last-child td { border-bottom: none; }
.ref-tbl tbody tr:hover td { background: #f8faff; }

/* User cell */
.u-name  { font-weight: 700; color: var(--rd); font-size: .89rem; }
.u-email { font-size: .73rem; color: var(--rm); }

/* Badges */
.rb { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: .7rem; font-weight: 700; }
.rb-blue  { background: #dbeafe; color: #1d4ed8; }
.rb-green { background: #d1fae5; color: #065f46; }
.rb-gray  { background: #f1f5f9; color: #475569; }

/* Commission inline form */
.comm-form { display: flex; gap: 5px; align-items: center; }
.comm-form input[type=number] {
    width: 68px; padding: 5px 7px;
    border: 1.5px solid var(--rb); border-radius: 6px;
    font-size: .86rem; font-weight: 700; color: var(--rd); text-align: center;
}
.comm-form input[type=number]:focus { outline: none; border-color: var(--rp); }

/* Stats mini */
.stat-mini { display: flex; flex-direction: column; gap: 3px; min-width: 108px; }
.sm-row { display: flex; justify-content: space-between; gap: 5px; font-size: .76rem; }
.sm-lbl { color: var(--rm); }
.sm-val { font-weight: 700; }
.sm-pending  { color: #b45309; }
.sm-approved { color: #059669; }
.sm-paid     { color: #1d4ed8; }

/* Referred count */
.ref-num { font-size: 1.2rem; font-weight: 800; color: var(--rp); text-align: center; line-height: 1; }
.ref-num-lbl { font-size: .67rem; color: var(--rm); text-transform: uppercase; text-align: center; }

/* Buttons */
.ref-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 6px 11px; border-radius: 7px; font-size: .77rem; font-weight: 600;
    border: none; cursor: pointer; text-decoration: none;
    transition: opacity .15s; white-space: nowrap; line-height: 1.3;
}
.ref-btn:hover { opacity: .82; text-decoration: none; }
.btn-p { background: var(--rp);  color: #fff; }
.btn-g { background: var(--rg);  color: #fff; }
.btn-r { background: var(--rr);  color: #fff; }
.btn-o { background: transparent; border: 1px solid var(--rb); color: var(--rm); }

/* Empty state */
.ref-empty { text-align: center; padding: 40px 20px; color: var(--rm); }
.ref-empty .re-icon { font-size: 2.2rem; margin-bottom: 10px; opacity: .4; }
.ref-empty p { font-size: .9rem; margin: 0; }

/* Modal */
.ref-modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,.4); align-items: center; justify-content: center;
}
.ref-modal {
    background: #fff; border-radius: 12px;
    max-width: 440px; width: 92%;
    box-shadow: 0 8px 24px rgba(0,0,0,.16); overflow: hidden;
}
.ref-modal-hdr {
    padding: 15px 18px;
    border-bottom: 1px solid var(--rb);
    background: var(--rs);
}
.ref-modal-hdr h4 { margin: 0; font-size: .92rem; font-weight: 700; color: var(--rd); }
.ref-modal-hdr p  { margin: 4px 0 0; font-size: .8rem; color: var(--rm); }
.ref-modal-body   { padding: 18px; }
.ref-modal-footer {
    padding: 12px 18px;
    border-top: 1px solid var(--rb);
    display: flex; gap: 8px; justify-content: flex-end;
}

@media (max-width: 768px) {
    .ref-stats { grid-template-columns: 1fr 1fr; }
    .ref-tbl-scroll { overflow-x: auto; }
}
</style>

<?php
$base_out = $base_url->out(false);

echo '<div class="ref-wrap">';

/* ── Stats row ── */
echo '<div class="ref-stats">';
foreach ([
    ['المسوقون',           $total_marketers,                  ''],
    ['إجمالي العمولات',   fmt_sdg($total_all),      ''],
    ['معلقة (غير مدفوعة)', fmt_sdg($total_approved), 's-amber'],
    ['مدفوعة',             fmt_sdg($total_paid),     's-green'],
    ['طلبات سحب معلقة',  $pending_withdrawals_count,        's-red'],
] as [$lbl, $val, $cls]) {
    echo "<div class=\"ref-stat {$cls}\"><div class=\"st-lbl\">{$lbl}</div><div class=\"st-val\">{$val}</div></div>";
}
echo '</div>';

/* ── Settings card (collapsible) ── */
echo '
<div class="ref-card">
    <div class="ref-card-hdr clickable" onclick="toggleCollapse(\'refCfg\',\'refCfgCaret\')">
        <h3>الإعدادات</h3>
        <span class="ref-caret" id="refCfgCaret">&#x25BC;</span>
    </div>
    <div class="ref-collapse" id="refCfg">
        <div class="ref-card-body">
            <form method="post" action="' . $base_out . '">
                <input type="hidden" name="action"  value="savesettings">
                <input type="hidden" name="sesskey" value="' . $sess . '">
                <div class="ref-settings-grid">
                    <div class="ref-field">
                        <label for="minWd">الحد الأدنى للسحب</label>
                        <input type="number" id="minWd" name="min_withdrawal" class="ref-input"
                               value="' . fmt_sdg($min_withdrawal, false) . '"
                               min="0" step="0.01" required>
                        <span class="ref-fhint">أقل مبلغ يمكن للمسوق طلبه</span>
                    </div>
                    <div class="ref-field">
                        <label for="defComm">نسبة العمولة المباشرة الافتراضية (%)</label>
                        <input type="number" id="defComm" name="default_commission" class="ref-input"
                               value="' . $def_commission . '"
                               min="0" max="100" step="1" required>
                        <span class="ref-fhint">تُطبَّق على المسوق الجديد عند التسجيل</span>
                    </div>
                    <div class="ref-field">
                        <label for="defIndirect">نسبة العمولة غير المباشرة الافتراضية (%)</label>
                        <input type="number" id="defIndirect" name="default_indirect_commission" class="ref-input"
                               value="' . $def_indirect . '"
                               min="0" max="100" step="1" required>
                        <span class="ref-fhint">تُطبَّق على المسوق الرئيسي عند تسجيل مسوق تابع له</span>
                    </div>
                    <div class="ref-field" style="justify-content:flex-end;">
                        <button type="submit" class="ref-btn btn-p" style="padding:9px 20px;">حفظ الإعدادات</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>';

/* ── Marketers table ── */
echo '
<div class="ref-card">
    <div class="ref-card-hdr">
        <h3>قائمة المسوقين <span class="hdr-badge">' . $total_marketers . '</span></h3>
    </div>';

if (empty($marketers)) {
    echo '
    <div class="ref-empty">
        <div class="re-icon">&#x1F465;</div>
        <p>لا يوجد مسوقون مسجلون بعد.</p>
    </div>';
} else {
    echo '<div class="ref-tbl-scroll"><table class="ref-tbl"><thead><tr>
        <th>المسوق</th>
        <th>الكود</th>
        <th style="text-align:center;">النوع</th>
        <th>المسوق الأب</th>
        <th>الإجمالي</th>
        <th>تفاصيل</th>
        <th style="text-align:center;">الإجراءات</th>
    </tr></thead><tbody>';

    foreach ($marketers as $m) {
        $st       = $stats[$m->userid];
        $name_raw = trim(($m->firstname ?? '') . ' ' . ($m->lastname ?? ''));
        $fullname = htmlspecialchars($name_raw !== '' ? $name_raw : '(حساب محذوف #' . $m->userid . ')');
        $email    = htmlspecialchars($m->email ?? '—');
        $refurl   = (new moodle_url('/', ['ref' => $m->code]))->out(false);

        $owed     = $st['pending'] + $st['approved'];
        $net_owed = max(0.0, $owed - $st['wd_paid']);
        $stats_html = '
        <div class="stat-mini">
            <div class="sm-row"><span class="sm-lbl">المستحق</span><span class="sm-val sm-pending">'  . fmt_sdg($net_owed) . '</span></div>
            <div class="sm-row"><span class="sm-lbl">مدفوع</span><span class="sm-val sm-paid">'        . fmt_sdg($st['paid']) . '</span></div>
        </div>';

        $pay_btn = '';
        if ($net_owed > 0) {
            $pay_url = (new moodle_url('/local/referral/admin/manage.php', [
                'action' => 'pay', 'marketerid' => $m->userid, 'sesskey' => $sess,
            ]))->out(false);
            $pay_btn = '
            <a href="' . $pay_url . '" class="ref-btn btn-g"
               onclick="return confirm(\'تسجيل دفع ' . fmt_sdg($net_owed, false) . ' SDG للمسوق ' . addslashes($fullname) . '؟\')">
                دفع (' . fmt_sdg($net_owed) . ')
            </a>';
        }

        $del_url = (new moodle_url('/local/referral/admin/manage.php', [
            'delete' => $m->userid, 'sesskey' => $sess,
        ]))->out(false);

        // Type badge — derived from DB, no convert controls here.
        $mtype     = $m->type;
        $type_cls  = ($mtype === 'main') ? 'rb-green' : 'rb-gray';
        $type_lbl  = ($mtype === 'main') ? 'رئيسي' : 'فرعي';
        $type_badge = '<span class="rb ' . $type_cls . '">' . $type_lbl . '</span>';

        // Parent cell — always visible; saving parent auto-updates type.
        $pname = (!empty($m->parentfirstname) || !empty($m->parentlastname))
            ? htmlspecialchars(trim(($m->parentfirstname ?? '') . ' ' . ($m->parentlastname ?? '')))
              . ' <span class="rb rb-gray" style="font-size:.68rem;">(' . htmlspecialchars($m->parentcode ?? '') . ')</span>'
            : '<span style="color:var(--rm);font-size:.78rem;">—</span>';

        $options = '<option value="0"' . (empty($m->parent_userid) ? ' selected' : '') . '>— بدون أب —</option>';
        foreach ($all_marketers as $pm) {
            if ($pm->userid == $m->userid) continue;
            $sel = (!empty($m->parent_userid) && $m->parent_userid == $pm->userid) ? ' selected' : '';
            $options .= '<option value="' . $pm->userid . '"' . $sel . '>'
                      . htmlspecialchars(trim($pm->firstname . ' ' . $pm->lastname))
                      . ' (' . htmlspecialchars($pm->code) . ')</option>';
        }

        $parent_cell = '
        <td>
            <div style="margin-bottom:5px;font-size:.82rem;">' . $pname . '</div>
            <form method="post" action="' . $base_out . '" style="display:flex;gap:4px;">
                <input type="hidden" name="action"     value="setparent">
                <input type="hidden" name="marketerid" value="' . $m->userid . '">
                <input type="hidden" name="sesskey"    value="' . $sess . '">
                <select name="parentid" class="ref-input" style="padding:4px 7px;font-size:.77rem;min-width:110px;">
                    ' . $options . '
                </select>
                <button type="submit" class="ref-btn btn-p" title="حفظ">&#x2713;</button>
            </form>
        </td>';

        echo '
        <tr>
            <td>
                <div class="u-name">' . $fullname . '</div>
                <div class="u-email">' . $email . '</div>
            </td>
            <td><span class="rb rb-blue">' . htmlspecialchars($m->code) . '</span></td>
            <td style="text-align:center;">' . $type_badge . '</td>
            ' . $parent_cell . '
            <td style="font-weight:700;color:var(--rd);">' . fmt_sdg($st['total']) . '</td>
            <td>' . $stats_html . '</td>
            <td>
                <div style="display:flex;flex-wrap:wrap;gap:5px;justify-content:center;">
                    ' . $pay_btn . '
                    <button type="button" class="ref-btn btn-o"
                        onclick="navigator.clipboard.writeText(\'' . addslashes($refurl) . '\').then(()=>{
                            this.textContent=\'تم النسخ\';
                            setTimeout(()=>this.textContent=\'نسخ الرابط\',2200);
                        })">نسخ الرابط</button>
                    <a href="' . $del_url . '" class="ref-btn btn-r"
                       onclick="return confirm(\'حذف المسوق ' . addslashes($fullname) . '؟\')">حذف</a>
                </div>
            </td>
        </tr>';
    }

    echo '</tbody></table></div>';
}
echo '</div>';

/* ── Pending withdrawals ── */
echo '
<div class="ref-card">
    <div class="ref-card-hdr">
        <h3>طلبات السحب المعلقة <span class="hdr-badge ba">' . count($pending_withdrawals) . '</span></h3>
    </div>';

if (empty($pending_withdrawals)) {
    echo '
    <div class="ref-empty" style="padding:30px 20px;">
        <div class="re-icon">&#x1F4EC;</div>
        <p>لا توجد طلبات سحب معلقة حالياً.</p>
    </div>';
} else {
    echo '
    <div class="ref-tbl-scroll"><table class="ref-tbl"><thead><tr>
        <th>المسوق</th>
        <th>الكود</th>
        <th>المبلغ</th>
        <th>التاريخ</th>
        <th style="text-align:center;">الإجراء</th>
    </tr></thead><tbody>';

    foreach ($pending_withdrawals as $wr) {
        $wname = htmlspecialchars(
            trim(($wr->firstname ?? '') . ' ' . ($wr->lastname ?? '')) ?: '(محذوف #' . $wr->marketerid . ')'
        );
        $rej_url = (new moodle_url('/local/referral/admin/manage.php', [
            'action' => 'rejectwithdrawal', 'wid' => $wr->id, 'sesskey' => $sess,
        ]))->out(false);

        echo '
        <tr>
            <td style="font-weight:700;color:var(--rd);">' . $wname . '</td>
            <td><span class="rb rb-blue">' . htmlspecialchars($wr->code) . '</span></td>
            <td style="font-weight:800;color:var(--rg);">' . fmt_sdg($wr->amount) . '</td>
            <td style="color:var(--rm);font-size:.8rem;">' . userdate($wr->timecreated, '%d/%m/%Y %H:%M') . '</td>
            <td style="text-align:center;">
                <div style="display:flex;gap:6px;justify-content:center;">
                    <button type="button" class="ref-btn btn-g"
                            onclick="openPayModal(' . (int)$wr->id . ',\'' . addslashes($wname) . '\',\'' . fmt_sdg($wr->amount, false) . '\')">`
                        قبول الدفع
                    </button>
                    <a href="' . $rej_url . '" class="ref-btn btn-r"
                       onclick="return confirm(\'رفض طلب السحب؟\')">رفض</a>
                </div>
            </td>
        </tr>';
    }

    echo '</tbody></table></div>';
}
echo '</div>';

/* ── Payment history (paid + rejected withdrawals) ── */
$wd_status_labels = [1 => ['مدفوع', 'rb-paid'], 2 => ['مرفوض', 'rb-rejected']];

echo '
<div class="ref-card">
    <div class="ref-card-hdr clickable" onclick="toggleCollapse(\'refProcWd\',\'refProcCaret\')">
        <h3>سجل الدفعات المعالجة <span class="hdr-badge">' . count($processed_withdrawals) . '</span></h3>
        <span class="ref-caret" id="refProcCaret">&#x25BC;</span>
    </div>
    <div class="ref-collapse" id="refProcWd">';

if (empty($processed_withdrawals)) {
    echo '
    <div class="ref-empty" style="padding:30px 20px;">
        <div class="re-icon">&#x1F4C4;</div>
        <p>لا توجد دفعات معالجة بعد.</p>
    </div>';
} else {
    $ctx_wr = context_system::instance();
    echo '
    <div class="ref-tbl-scroll"><table class="ref-tbl"><thead><tr>
        <th>المسوق</th>
        <th>الكود</th>
        <th>المبلغ</th>
        <th>الحالة</th>
        <th>التاريخ</th>
        <th>الإيصال</th>
        <th style="text-align:center;">حذف</th>
    </tr></thead><tbody>';

    foreach ($processed_withdrawals as $pw) {
        $pwname = htmlspecialchars(
            trim(($pw->firstname ?? '') . ' ' . ($pw->lastname ?? '')) ?: '(محذوف #' . $pw->marketerid . ')'
        );
        [$pwlbl, $pwcls] = $wd_status_labels[(int)$pw->status] ?? ['—', ''];

        $receipt_cell = '<span style="color:var(--rm);font-size:.78rem;">—</span>';
        if (!empty($pw->receipt_file)) {
            $dl_url = moodle_url::make_pluginfile_url(
                $ctx_wr->id, 'local_referral', 'withdrawal_receipts',
                $pw->id, '/', $pw->receipt_file, true
            );
            $receipt_cell = '<a href="' . $dl_url->out(false) . '" target="_blank"
                class="ref-btn btn-o" style="font-size:.72rem;">تحميل</a>';
        }

        $del_note = (int)$pw->status === 1
            ? 'حذف هذه الدفعة وإعادة العمولات المرتبطة إلى حالة الانتظار؟'
            : 'حذف سجل الرفض؟';
        $del_url = (new moodle_url($base_url, [
            'action' => 'deletewithdrawal', 'wid' => $pw->id, 'sesskey' => $sess,
        ]))->out(false);

        echo '
        <tr>
            <td style="font-weight:700;color:var(--rd);">' . $pwname . '</td>
            <td><span class="rb rb-blue">' . htmlspecialchars($pw->code ?? '—') . '</span></td>
            <td style="font-weight:800;color:var(--rg);">' . fmt_sdg($pw->amount) . '</td>
            <td><span class="rb ' . $pwcls . '">' . $pwlbl . '</span></td>
            <td style="color:var(--rm);font-size:.8rem;white-space:nowrap;">'
                . userdate($pw->timecreated, '%d/%m/%Y %H:%M') . '</td>
            <td>' . $receipt_cell . '</td>
            <td style="text-align:center;">
                <a href="' . $del_url . '" class="ref-btn btn-r" style="font-size:.72rem;"
                   onclick="return confirm(\'' . addslashes($del_note) . '\')">حذف</a>
            </td>
        </tr>';
    }

    echo '</tbody></table></div>';
}
echo '</div></div>';

/* ── Payment approval modal ── */
echo '
<div id="payWithdrawalModal" class="ref-modal-overlay">
    <div class="ref-modal">
        <div class="ref-modal-hdr">
            <h4>تأكيد قبول الدفع</h4>
            <p id="payModalDesc">—</p>
        </div>
        <form method="post" action="' . $base_out . '" enctype="multipart/form-data" onsubmit="return validatePayModal()">
            <input type="hidden" name="action"  value="paywithdrawal">
            <input type="hidden" name="sesskey" value="' . $sess . '">
            <input type="hidden" name="wid" id="payModalWid" value="0">
            <div class="ref-modal-body">
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
                    <div style="font-size:.68rem;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">المبلغ</div>
                    <div style="font-size:1.65rem;font-weight:800;color:#166534;" id="payModalAmount">—</div>
                </div>
                <div>
                    <label style="display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:5px;">
                        إيصال الدفع <span style="font-weight:400;color:var(--rm);">(اختياري — PDF/JPG/PNG — حد 5 MB)</span>
                    </label>
                    <input type="file" name="receipt_file" id="payModalReceipt" accept=".pdf,.jpg,.jpeg,.png"
                           style="width:100%;padding:8px 12px;border:1.5px dashed var(--rb);border-radius:8px;
                                  box-sizing:border-box;background:var(--rs);cursor:pointer;font-size:.86rem;">
                </div>
            </div>
            <div class="ref-modal-footer">
                <button type="button" onclick="closePayModal()" class="ref-btn btn-o">إلغاء</button>
                <button type="submit" class="ref-btn btn-g" style="padding:7px 20px;">تأكيد الدفع</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleCollapse(id, caretId) {
    var el   = document.getElementById(id);
    var open = el.classList.toggle("open");
    var car  = document.getElementById(caretId);
    if (car) car.classList.toggle("open", open);
}
function openPayModal(wid, name, amount) {
    document.getElementById("payModalWid").value       = wid;
    document.getElementById("payModalDesc").textContent = "الدفع لـ " + name;
    document.getElementById("payModalAmount").textContent = amount;
    document.getElementById("payModalReceipt").value   = "";
    document.getElementById("payWithdrawalModal").style.display = "flex";
}
function closePayModal() {
    document.getElementById("payWithdrawalModal").style.display = "none";
}
function validatePayModal() {
    var file = document.getElementById("payModalReceipt").files[0];
    if (file && file.size > 5242880) { alert("حجم الملف يتجاوز 5 MB."); return false; }
    return true;
}
document.getElementById("payWithdrawalModal").addEventListener("click", function(e) {
    if (e.target === this) closePayModal();
});
</script>';

echo '</div>';
echo $OUTPUT->footer();
