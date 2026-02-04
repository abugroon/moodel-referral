<?php
// /local/referral/manage.php

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_referral_manage');

$delete = optional_param('delete', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$edit = optional_param('edit', 0, PARAM_INT);
$save = optional_param('save', 0, PARAM_BOOL);
$code = trim(optional_param('code', '', PARAM_ALPHANUMEXT));
$name = trim(optional_param('name', '', PARAM_TEXT));
$userid = optional_param('userid', 0, PARAM_INT);
$commissionpercentage = optional_param('commission_percentage', 10, PARAM_INT);

$commissionaction = optional_param('commissionaction', '', PARAM_ALPHA);
$commissionid = optional_param('commissionid', 0, PARAM_INT);
$statusfilter = optional_param('status', -1, PARAM_INT);
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 25;

if (!in_array($statusfilter, [-1, 0, 1, 2], true)) {
    $statusfilter = -1;
}

/* ===========================
   Commission Actions
=========================== */
if ($commissionid > 0 && $commissionaction !== '' && confirm_sesskey()) {
    $commission = $DB->get_record('local_ref_commissions', ['id' => $commissionid], '*', IGNORE_MISSING);
    if ($commission) {
        if ($commissionaction === 'approve' && (int)$commission->status === 0) {
            $commission->status = 1;
            $DB->update_record('local_ref_commissions', $commission);
        } else if ($commissionaction === 'paid' && (int)$commission->status !== 2) {
            $commission->status = 2;
            $DB->update_record('local_ref_commissions', $commission);
        }
    }
    redirect(new moodle_url('/local/referral/manage.php', ['status' => $statusfilter, 'page' => $page]));
}

/* ===========================
   Delete Marketer
=========================== */
if ($delete) {
    $marketer = $DB->get_record('local_ref_marketers', ['id' => $delete], '*', IGNORE_MISSING);
    if (!$marketer) {
        redirect(new moodle_url('/local/referral/manage.php'));
    }

    if ($confirm && confirm_sesskey()) {
        $DB->delete_records('local_ref_commissions', ['marketerid' => $delete]);
        $DB->delete_records('local_ref_users', ['marketerid' => $delete]);
        $DB->delete_records('local_ref_marketers', ['id' => $delete]);
        redirect(new moodle_url('/local/referral/manage.php'));
    }

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdelete', 'local_referral'),
        new moodle_url('/local/referral/manage.php', ['delete'=>$delete,'confirm'=>1,'sesskey'=>sesskey()]),
        new moodle_url('/local/referral/manage.php')
    );
    echo $OUTPUT->footer();
    exit;
}

/* ===========================
   Save Marketer
=========================== */
$errormsg = '';
if ($save && confirm_sesskey()) {
    $now = time();

    if ($code === '') {
        $errormsg = get_string('errorcoderequired', 'local_referral');
    } else if ($name === '') {
        $errormsg = get_string('errornamerequired', 'local_referral');
    }

    if ($errormsg === '') {
        if ($edit) {
            $record = $DB->get_record('local_ref_marketers', ['id'=>$edit], '*', MUST_EXIST);
            $record->code = $code;
            $record->name = $name;
            $record->commission_percentage = $commissionpercentage;
            $record->timemodified = $now;
            $DB->update_record('local_ref_marketers', $record);
        } else {
            $DB->insert_record('local_ref_marketers', (object)[
                'code'=>$code,
                'name'=>$name,
                'commission_percentage'=>$commissionpercentage,
                'timecreated'=>$now,
                'timemodified'=>$now
            ]);
        }
        redirect(new moodle_url('/local/referral/manage.php'));
    }
}

$marketers = $DB->get_records('local_ref_marketers', null, 'name ASC');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage', 'local_referral'));

if ($errormsg) {
    echo $OUTPUT->notification($errormsg, 'notifyproblem');
}

/* ===========================
   ADD / EDIT FORM
=========================== */
echo html_writer::start_div('card mb-4');
echo html_writer::tag('div',
    get_string('addmarketer','local_referral'),
    ['class'=>'card-header fw-bold']
);
echo html_writer::start_div('card-body');

echo html_writer::start_tag('form', [
    'method'=>'post',
    'action'=>new moodle_url('/local/referral/manage.php')
]);

echo html_writer::start_div('row g-3');

// NAME
echo html_writer::start_div('col-12 col-md-6');
echo html_writer::label('Name', 'referral-name', false, ['class'=>'form-label']);
echo html_writer::empty_tag('input', [
    'type'=>'text',
    'name'=>'name',
    'id'=>'referral-name',
    'class'=>'form-control',
    'required'=>'required'
]);
echo html_writer::end_div();

// CODE
echo html_writer::start_div('col-12 col-md-6');
echo html_writer::label('Code', 'referral-code', false, ['class'=>'form-label']);
echo html_writer::empty_tag('input', [
    'type'=>'text',
    'name'=>'code',
    'id'=>'referral-code',
    'class'=>'form-control',
    'required'=>'required'
]);
echo html_writer::end_div();



// COMMISSION
echo html_writer::start_div('col-12 col-md-4');
echo html_writer::label('Commission %', 'commission', false, ['class'=>'form-label']);
echo html_writer::empty_tag('input', [
    'type'=>'number',
    'name'=>'commission_percentage',
    'id'=>'commission',
    'class'=>'form-control',
    'value'=>10,
    'min'=>0,
    'max'=>100
]);
echo html_writer::end_div();

echo html_writer::end_div(); // row

// BUTTON
echo html_writer::start_div('mt-4');
echo html_writer::empty_tag('input', [
    'type'=>'hidden',
    'name'=>'save',
    'value'=>1
]);
echo html_writer::empty_tag('input', [
    'type'=>'hidden',
    'name'=>'sesskey',
    'value'=>sesskey()
]);
echo html_writer::tag('button','Save',[
    'type'=>'submit',
    'class'=>'btn btn-primary'
]);
echo html_writer::end_div();

echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

/* ===========================
   MARKETERS TABLE
=========================== */
$table = new html_table();
$table->attributes['class'] = 'table table-striped table-bordered';
$table->head = ['Code','Name','Commission %','Referral Link','Actions'];

foreach ($marketers as $m) {
    $link = $CFG->wwwroot . '/?ref=' . $m->code;

    $linkhtml = '
<div class="d-flex align-items-center gap-2">
    <input type="text" 
           class="form-control form-control-sm" 
           value="'.s($link).'" 
           id="ref-link-'.$m->id.'" 
           readonly 
           style="max-width:300px;">
    <button type="button" 
            class="btn btn-sm btn-outline-secondary"
            onclick="copyReferralLink(\'ref-link-'.$m->id.'\')">
        Copy
    </button>
</div>';
    $actions =
        html_writer::link(
            new moodle_url('/local/referral/manage.php',['edit'=>$m->id]),
            'Edit',
            ['class'=>'btn btn-sm btn-secondary']
        ).' '.
        html_writer::link(
            new moodle_url('/local/referral/manage.php',['delete'=>$m->id,'sesskey'=>sesskey()]),
            'Delete',
            ['class'=>'btn btn-sm btn-danger']
        );

    $table->data[] = [
        s($m->code),
        s($m->name),
        $m->commission_percentage.'%',
        $linkhtml,
        $actions
    ];
}

echo html_writer::table($table);

/* ===========================
   COMMISSIONS TABLE
=========================== */
echo $OUTPUT->heading(get_string('commissionrecords','local_referral'),3);

$sql = "SELECT c.*, m.name as marketername, u.username, crs.fullname
        FROM {local_ref_commissions} c
        JOIN {local_ref_marketers} m ON m.id=c.marketerid
        JOIN {user} u ON u.id=c.userid
        JOIN {course} crs ON crs.id=c.courseid
        ORDER BY c.timecreated DESC";

$rows = $DB->get_records_sql($sql, null, $page*$perpage, $perpage);

$ctable = new html_table();
$ctable->attributes['class'] = 'table table-striped';
$ctable->head = ['Marketer','User','Course','Amount','Commission','Status','Created','Actions'];

foreach ($rows as $r) {

    $status = $r->status==0?'Pending':($r->status==1?'Approved':'Paid');

    $ctable->data[] = [
        s($r->marketername),
        s($r->username),
        s($r->fullname),
        format_float($r->amount,2),
        format_float($r->commission,2),
        $status,
        userdate($r->timecreated),
        '-'
    ];
}

echo html_writer::table($ctable);

echo '
<script>
function copyReferralLink(id) {
    var copyText = document.getElementById(id);
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(copyText.value);

    alert("Referral link copied!");
}
</script>
';

echo $OUTPUT->footer();
