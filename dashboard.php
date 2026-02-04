<?php
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/referral/dashboard.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('Referral Admin Dashboard');
$PAGE->set_heading('Referral Admin Dashboard');

$statusfilter = optional_param('status', -1, PARAM_INT);
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 20;

echo $OUTPUT->header();
//echo $OUTPUT->heading('Referral Admin Dashboard');

/* ===============================
   Global Statistics
================================ */

$totalmarketers = $DB->count_records('local_ref_marketers');
$totalusers = $DB->count_records('local_ref_users');

$totalcommission = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions}"
);

$pendingcommission = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE status = 0"
);

$paidcommission = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE status = 2"
);

/* ===============================
   Statistics Cards
================================ */

echo '<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:15px;margin-bottom:30px;">';

function stat_card($title,$value){
    return '
    <div style="background:#f8f9fa;padding:20px;border-radius:10px;text-align:center;">
        <div style="font-size:14px;color:#666;">'.$title.'</div>
        <div style="font-size:22px;font-weight:bold;margin-top:5px;">'.$value.'</div>
    </div>';
}

echo stat_card('Total marketers',$totalmarketers);
echo stat_card('Total referred users',$totalusers);
echo stat_card('Total commissions',format_float($totalcommission,2));
echo stat_card('Pending commissions',format_float($pendingcommission,2));
echo stat_card('Paid commissions',format_float($paidcommission,2));

echo '</div>';

/* ===============================
   Marketers Table
================================ */

echo $OUTPUT->heading('Marketers Overview',3);

$sql = "SELECT m.id,
               m.name,
               m.code,
               m.commission_percentage,
               COUNT(DISTINCT ru.userid) as userscount,
               COALESCE(SUM(c.commission),0) as totalcommission
        FROM {local_ref_marketers} m
        LEFT JOIN {local_ref_users} ru ON ru.marketerid = m.id
        LEFT JOIN {local_ref_commissions} c ON c.marketerid = m.id
        GROUP BY m.id,m.name,m.code,m.commission_percentage
        ORDER BY m.id DESC";

$marketers = $DB->get_records_sql($sql);

$table = new html_table();
$table->head = [
    'Name',
    'Code',
    'Commission %',
    'Referred Users',
    'Total Commission'
];

foreach ($marketers as $m) {
    $table->data[] = [
        '<strong>'.s($m->name).'</strong>',
        '<code>'.s($m->code).'</code>',
        $m->commission_percentage.'%',
        $m->userscount,
        format_float($m->totalcommission,2)
    ];
}

echo html_writer::table($table);

/* ===============================
   Commission Records
================================ */

echo $OUTPUT->heading('Commission Records',3);

$where = 'WHERE 1=1';
$params = [];

if ($statusfilter >= 0) {
    $where .= ' AND c.status = :status';
    $params['status'] = $statusfilter;
}

$sql = "SELECT c.id,
               c.amount,
               c.commission,
               c.status,
               c.timecreated,
               m.name as marketername,
               m.code as marketercode,
               u.username,
               crs.fullname as coursename
        FROM {local_ref_commissions} c
        JOIN {local_ref_marketers} m ON m.id = c.marketerid
        JOIN {user} u ON u.id = c.userid
        JOIN {course} crs ON crs.id = c.courseid
        $where
        ORDER BY c.timecreated DESC";

$rows = $DB->get_records_sql($sql,$params);

$table = new html_table();
$table->head = [
    'Marketer',
    'Code',
    'User',
    'Course',
    'Amount',
    'Commission',
    'Status',
    'Date'
];

foreach ($rows as $row) {

    $statuslabel = 'Pending';
    if ($row->status == 1) $statuslabel = 'Approved';
    if ($row->status == 2) $statuslabel = 'Paid';

    $table->data[] = [
        s($row->marketername),
        '<code>'.s($row->marketercode).'</code>',
        s($row->username),
        s($row->coursename),
        format_float($row->amount,2),
        format_float($row->commission,2),
        $statuslabel,
        userdate($row->timecreated)
    ];
}

if (empty($table->data)) {
    echo $OUTPUT->notification('No commission records found.');
} else {
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
