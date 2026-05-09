<?php
// /local/referral/admin/dashboard.php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/tabs.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_referral_dashboard');

global $DB, $OUTPUT;

$base_url = new moodle_url('/local/referral/admin/dashboard.php');

$action       = optional_param('action', '', PARAM_ALPHA);
$commissionid = optional_param('commissionid', 0, PARAM_INT);
$withdrawalid = optional_param('withdrawalid', 0, PARAM_INT);

// تنفيذ الإجراءات فقط عند وجود action وsesskey
if (($commissionid || $withdrawalid) && $action && confirm_sesskey()) {

    // ---- إدارة العمولات ----
    if ($commissionid) {
        $commission = $DB->get_record('local_ref_commissions', ['id' => $commissionid]);
        if ($commission) {
            if ($action == 'approve' && $commission->status == 0) {
                $commission->status = 1;
                $DB->update_record('local_ref_commissions', $commission);
            }
            if ($action == 'paid' && $commission->status == 1) {
                $commission->status = 2;
                $DB->update_record('local_ref_commissions', $commission);
            }
        }
        redirect($base_url);
    }

    // ---- إدارة طلبات السحب ----
    if ($withdrawalid && in_array($action, ['wdone', 'wreject'])) {
        $wr = $DB->get_record('local_ref_withdrawals', ['id' => $withdrawalid]);
        if ($wr && $wr->status == 0) {
            $wr->status       = ($action == 'wdone') ? 1 : 2;
            $wr->timemodified = time();
            $DB->update_record('local_ref_withdrawals', $wr);
            // عند الموافقة على السحب: تحديث العمولات المعتمدة كـ مدفوعة (FIFO)
            if ($action == 'wdone') {
                $remaining = (float)$wr->amount;
                $comms = $DB->get_records_select(
                    'local_ref_commissions',
                    'marketerid = :mid AND status = 1',
                    ['mid' => $wr->marketerid],
                    'timecreated ASC'
                );
                foreach ($comms as $c) {
                    if ($remaining <= 0) break;
                    $DB->set_field('local_ref_commissions', 'status', 2, ['id' => $c->id]);
                    $remaining -= (float)$c->commission;
                }
            }
        }
        redirect($base_url);
    }
}

$statusfilter = optional_param('status', -1, PARAM_INT);

echo $OUTPUT->header();
echo local_referral_admin_tabs('dashboard');

/* ===============================
   Global Statistics
================================ */
$totalmarketers  = $DB->count_records('local_ref_marketers');
$totalusers      = $DB->count_records('local_ref_users');
$totalcommission = (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions}");
$pendingcommission = (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE status = 0");
$paidcommission  = (float)$DB->get_field_sql("SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE status = 2");

echo '<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:15px;margin-bottom:30px;">';

function stat_card($title, $value) {
    return '
    <div style="background:#f8f9fa;padding:20px;border-radius:10px;text-align:center;">
        <div style="font-size:14px;color:#666;">' . $title . '</div>
        <div style="font-size:22px;font-weight:bold;margin-top:5px;">' . $value . '</div>
    </div>';
}

echo stat_card('Total marketers', $totalmarketers);
echo stat_card('Total referred users', $totalusers);
echo stat_card('Total commissions', format_float($totalcommission, 2));
echo stat_card('Pending commissions', format_float($pendingcommission, 2));
echo stat_card('Paid commissions', format_float($paidcommission, 2));
echo '</div>';

/* ===============================
   Marketers Overview
================================ */
echo $OUTPUT->heading('Marketers Overview', 3);

$sql = "SELECT m.id, m.name, m.code, m.commission_percentage, m.parent_id,
               COUNT(DISTINCT ru.userid) as userscount,
               COALESCE(SUM(c.commission),0) as totalcommission
        FROM {local_ref_marketers} m
        LEFT JOIN {local_ref_users} ru ON ru.marketerid = m.id
        LEFT JOIN {local_ref_commissions} c ON c.marketerid = m.id
        GROUP BY m.id, m.name, m.code, m.commission_percentage, m.parent_id
        ORDER BY m.id DESC";

$marketers = $DB->get_records_sql($sql);
$table = new html_table();
$table->head = ['Name', 'Code', 'Parent', 'Commission %', 'Referred Users', 'Total Commission'];

foreach ($marketers as $m) {
    $parentname = '-';
    if (!empty($m->parent_id)) {
        $parent = $DB->get_record('local_ref_marketers', ['id' => $m->parent_id]);
        if ($parent) $parentname = $parent->name;
    }
    $table->data[] = [
        '<strong>' . s($m->name) . '</strong>',
        '<code>' . s($m->code) . '</code>',
        s($parentname),
        $m->commission_percentage . '%',
        $m->userscount,
        format_float($m->totalcommission, 2),
    ];
}
echo html_writer::table($table);

/* ===============================
   Commission Records
================================ */
echo $OUTPUT->heading('Commission Records', 3);

$where  = 'WHERE 1=1';
$params = [];
if ($statusfilter >= 0) {
    $where .= ' AND c.status = :status';
    $params['status'] = $statusfilter;
}

$sql = "SELECT c.id, c.amount, c.commission, c.status, c.timecreated,
               m.name as marketername, m.code as marketercode, m.parent_id,
               u.username,
               crs.fullname as coursename
        FROM {local_ref_commissions} c
        JOIN {local_ref_marketers} m ON m.id = c.marketerid
        JOIN {user} u ON u.id = c.userid
        JOIN {course} crs ON crs.id = c.courseid
        $where
        ORDER BY c.timecreated DESC";

$rows  = $DB->get_records_sql($sql, $params);
$table = new html_table();
$table->head = ['Marketer', 'Code', 'User', 'Course', 'Amount', 'Commission', 'Status', 'Date', 'Actions'];

foreach ($rows as $row) {
    $statuslabel = 'Pending';
    if ($row->status == 1) $statuslabel = 'Approved';
    if ($row->status == 2) $statuslabel = 'Paid';

    $actions = '';
    if ($row->status == 0) {
        $actions .= html_writer::link(
            new moodle_url('/local/referral/admin/dashboard.php', ['commissionid' => $row->id, 'action' => 'approve', 'sesskey' => sesskey()]),
            'Approve', ['class' => 'btn btn-sm btn-success']
        );
    }
    if ($row->status == 1) {
        $actions .= ' ' . html_writer::link(
            new moodle_url('/local/referral/admin/dashboard.php', ['commissionid' => $row->id, 'action' => 'paid', 'sesskey' => sesskey()]),
            'Mark Paid', ['class' => 'btn btn-sm btn-primary']
        );
    }

    $table->data[] = [
        s($row->marketername),
        '<code>' . s($row->marketercode) . '</code>',
        s($row->username),
        s($row->coursename),
        format_float($row->amount, 2),
        format_float($row->commission, 2),
        $statuslabel,
        userdate($row->timecreated),
        $actions,
    ];
}

if (empty($table->data)) {
    echo $OUTPUT->notification('No commission records found.');
} else {
    echo html_writer::table($table);
}

/* ===============================
   طلبات سحب العمولات
================================ */
echo $OUTPUT->heading('طلبات سحب العمولات', 3);

$wr_sql = "SELECT wr.id, wr.amount, wr.status, wr.timecreated, wr.notes,
                  m.name as marketername, m.code as marketercode
             FROM {local_ref_withdrawals} wr
             JOIN {local_ref_marketers} m ON m.id = wr.marketerid
            ORDER BY wr.status ASC, wr.timecreated DESC";

$withdrawals = $DB->get_records_sql($wr_sql);
$wrtable     = new html_table();
$wrtable->head = ['المسوق', 'الكود', 'المبلغ', 'الحالة', 'التاريخ', 'إجراء'];

foreach ($withdrawals as $wr) {
    $wrstatus  = ['🕐 قيد المراجعة', '✅ تم الدفع', '❌ مرفوض'][$wr->status] ?? '-';
    $wractions = '';
    if ($wr->status == 0) {
        $wractions .= html_writer::link(
            new moodle_url('/local/referral/admin/dashboard.php', ['withdrawalid' => $wr->id, 'action' => 'wdone', 'sesskey' => sesskey()]),
            'تم الدفع ✅', ['class' => 'btn btn-sm btn-success me-1']
        );
        $wractions .= html_writer::link(
            new moodle_url('/local/referral/admin/dashboard.php', ['withdrawalid' => $wr->id, 'action' => 'wreject', 'sesskey' => sesskey()]),
            'رفض ❌', ['class' => 'btn btn-sm btn-danger']
        );
    }
    $wrtable->data[] = [
        s($wr->marketername),
        '<code>' . s($wr->marketercode) . '</code>',
        number_format($wr->amount, 2),
        $wrstatus,
        userdate($wr->timecreated),
        $wractions ?: '-',
    ];
}

if (empty($wrtable->data)) {
    echo $OUTPUT->notification('لا توجد طلبات سحب حالياً.');
} else {
    echo html_writer::table($wrtable);
}

echo $OUTPUT->footer();
