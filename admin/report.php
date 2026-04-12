<?php
// /local/referral/admin/report.php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/tabs.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_referral_report');

global $DB, $OUTPUT;

$sql = "SELECT m.id, m.code, m.name, COUNT(u.id) AS usercount
          FROM {local_ref_marketers} m
     LEFT JOIN {local_ref_users} u ON u.marketerid = m.id
      GROUP BY m.id, m.code, m.name
      ORDER BY m.name ASC";

$rows = $DB->get_records_sql($sql);

echo $OUTPUT->header();
echo local_referral_admin_tabs('report');
echo $OUTPUT->heading(get_string('reporttitle', 'local_referral'));

$table       = new html_table();
$table->head = [
    get_string('code', 'local_referral'),
    get_string('name', 'local_referral'),
    get_string('referrallink', 'local_referral'),
    get_string('userscount', 'local_referral'),
];

foreach ($rows as $row) {
    $link          = $CFG->wwwroot . '/?ref=' . $row->code;
    $table->data[] = [
        s($row->code),
        s($row->name),
        s($link),
        (int)$row->usercount,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
