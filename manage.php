<?php
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
$parentid = optional_param('parent_id', 0, PARAM_INT);
$commissionpercentage = optional_param('commission_percentage', 10, PARAM_INT);

$now = time();

/* ===========================
   LOAD EDIT DATA
=========================== */
$editing = null;
if ($edit) {
    $editing = $DB->get_record('local_ref_marketers', ['id'=>$edit], '*', IGNORE_MISSING);
}

/* ===========================
   DELETE MARKETER
=========================== */
if ($delete) {
    $marketer = $DB->get_record('local_ref_marketers', ['id' => $delete], '*', IGNORE_MISSING);

    if (!$marketer) {
        redirect(new moodle_url('/local/referral/manage.php'));
    }

    if ($confirm && confirm_sesskey()) {
        $DB->delete_records('local_ref_commissions', ['marketerid'=>$delete]);
        $DB->delete_records('local_ref_users', ['marketerid'=>$delete]);
        $DB->delete_records('local_ref_marketers', ['id'=>$delete]);

        redirect(new moodle_url('/local/referral/manage.php'));
    }

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        'Are you sure you want to delete this marketer?',
        new moodle_url('/local/referral/manage.php', [
            'delete'=>$delete,
            'confirm'=>1,
            'sesskey'=>sesskey()
        ]),
        new moodle_url('/local/referral/manage.php')
    );
    echo $OUTPUT->footer();
    exit;
}

/* ===========================
   SAVE MARKETER
=========================== */
$errormsg = '';

if ($save && confirm_sesskey()) {

    if ($code === '') {
        $errormsg = 'Code is required';
    } else if ($name === '') {
        $errormsg = 'Name is required';
    }

    if ($errormsg === '') {

        if ($edit && $editing) {

            $editing->code = $code;
            $editing->name = $name;
            $editing->parent_id = $parentid;
            $editing->commission_percentage = $commissionpercentage;
            $editing->timemodified = $now;

            $DB->update_record('local_ref_marketers', $editing);

        } else {

            $DB->insert_record('local_ref_marketers', (object)[
                'code'=>$code,
                'name'=>$name,
                'parent_id'=>$parentid,
                'commission_percentage'=>$commissionpercentage,
                'timecreated'=>$now,
                'timemodified'=>$now
            ]);
        }

        redirect(new moodle_url('/local/referral/manage.php'), 'Saved successfully', 1);
    }
}

$marketers = $DB->get_records('local_ref_marketers', null, 'name ASC');

echo $OUTPUT->header();
echo $OUTPUT->heading('Manage Marketers');

if ($errormsg) {
    echo $OUTPUT->notification($errormsg, 'notifyproblem');
}

/* ===========================
   ADD / EDIT FORM
=========================== */

echo html_writer::start_div('card mb-4');
echo html_writer::tag('div', $edit ? 'Edit Marketer' : 'Add Marketer', ['class'=>'card-header fw-bold']);
echo html_writer::start_div('card-body');

echo html_writer::start_tag('form', [
    'method'=>'post',
    'action'=>new moodle_url('/local/referral/manage.php')
]);

echo html_writer::start_div('row g-3');

/* NAME */
echo html_writer::start_div('col-md-6');
echo html_writer::label('Name', 'ref-name', false, ['class'=>'form-label']);
echo html_writer::empty_tag('input', [
    'type'=>'text',
    'name'=>'name',
    'class'=>'form-control',
    'value'=>$editing ? s($editing->name) : '',
    'required'=>'required'
]);
echo html_writer::end_div();

/* CODE */
echo html_writer::start_div('col-md-6');
echo html_writer::label('Code', 'ref-code', false, ['class'=>'form-label']);
echo html_writer::empty_tag('input', [
    'type'=>'text',
    'name'=>'code',
    'class'=>'form-control',
    'value'=>$editing ? s($editing->code) : '',
    'required'=>'required'
]);
echo html_writer::end_div();

/* COMMISSION */
echo html_writer::start_div('col-md-4');
echo html_writer::label('Commission %', 'commission', false, ['class'=>'form-label']);
echo html_writer::empty_tag('input', [
    'type'=>'number',
    'name'=>'commission_percentage',
    'class'=>'form-control',
    'value'=>$editing ? $editing->commission_percentage : 10,
    'min'=>0,
    'max'=>100
]);
echo html_writer::end_div();

/* PARENT MARKETER */
$options = [0 => '— Main Marketer —'];
foreach ($marketers as $pm) {
    if (!$edit || $pm->id != $edit) {
        $options[$pm->id] = $pm->name;
    }
}

echo html_writer::start_div('col-md-6');
echo html_writer::label('Parent Marketer', 'parent_id', false, ['class'=>'form-label']);
echo html_writer::select(
    $options,
    'parent_id',
    $editing ? $editing->parent_id : 0,
    false,
    ['class'=>'form-select']
);
echo html_writer::end_div();

echo html_writer::end_div();

/* HIDDEN FIELDS */
echo html_writer::empty_tag('input', [
    'type'=>'hidden',
    'name'=>'save',
    'value'=>1
]);

if ($edit) {
    echo html_writer::empty_tag('input', [
        'type'=>'hidden',
        'name'=>'edit',
        'value'=>$edit
    ]);
}

echo html_writer::empty_tag('input', [
    'type'=>'hidden',
    'name'=>'sesskey',
    'value'=>sesskey()
]);

echo html_writer::tag('button', 'Save', [
    'type'=>'submit',
    'class'=>'btn btn-primary mt-3'
]);

echo html_writer::end_tag('form');
echo html_writer::end_div();
echo html_writer::end_div();

/* ===========================
   MARKETERS TABLE
=========================== */

$table = new html_table();
$table->attributes['class'] = 'table table-striped table-bordered';
$table->head = ['Code','Name','Commission %','Parent','Referral Link','Actions'];

foreach ($marketers as $m) {

    $parentname = '-';
    if (!empty($m->parent_id)) {
        $parent = $DB->get_record('local_ref_marketers', ['id'=>$m->parent_id]);
        if ($parent) {
            $parentname = $parent->name;
        }
    }

    /* Referral Link */
    $refurl = new moodle_url('/', ['ref' => $m->code]);
    $refurlstring = $refurl->out(false);

    $copybutton = html_writer::tag(
        'button',
        'Copy',
        [
            'type' => 'button',
            'class' => 'btn btn-sm btn-info',
            'onclick' => "copyToClipboard('".$refurlstring."')"
        ]
    );

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
        s($parentname),
        html_writer::link($refurl, 'Open', ['target'=>'_blank']) . ' ' . $copybutton,
        $actions
    ];
}

echo html_writer::table($table);


echo html_writer::script("
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        alert('Referral link copied successfully!');
    }, function(err) {
        alert('Failed to copy link');
    });
}
");

echo $OUTPUT->footer();
