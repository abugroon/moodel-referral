<?php
// /local/referral/lang/en/local_referral.php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Referral';
$string['manage'] = 'Manage marketers';
$string['report'] = 'Referral report';
$string['dashboard'] = 'Marketer dashboard';
$string['addmarketer'] = 'Add marketer';
$string['editmarketer'] = 'Edit marketer';
$string['code'] = 'Code';
$string['name'] = 'Name';
$string['user'] = 'User';
$string['actions'] = 'Actions';
$string['referrallink'] = 'Referral link';
$string['save'] = 'Save';
$string['delete'] = 'Delete';
$string['filter'] = 'Filter';
$string['approve'] = 'Approve';
$string['markpaid'] = 'Mark paid';
$string['confirmdelete'] = 'Are you sure you want to delete this marketer?';
$string['reporttitle'] = 'Referral report';
$string['userscount'] = 'Users referred';
$string['marketeruserid'] = 'Marketer user ID';
$string['marketeruseriddesc'] = 'Optional. Set a Moodle user ID so the marketer can access the dashboard.';
$string['commissionpercentage'] = 'Commission percentage';
$string['amount'] = 'Amount';
$string['commission'] = 'Commission';
$string['commissionrecords'] = 'Commission records';
$string['status'] = 'Status';
$string['createdat'] = 'Created at';
$string['statusfilter'] = 'Status filter';
$string['statusall'] = 'All';
$string['statuspending'] = 'Pending';
$string['statusapproved'] = 'Approved';
$string['statuspaid'] = 'Paid';
$string['totalreferredusers'] = 'Total referred users';
$string['totalcommissions'] = 'Total commissions';
$string['pendingcommissions'] = 'Pending commissions';
$string['paidcommissions'] = 'Paid commissions';
$string['marketernotlinked'] = 'No marketer profile is linked to your user account.';
$string['nocommissions'] = 'No commission records found.';
$string['errorcoderequired'] = 'Referral code is required.';
$string['errornamerequired'] = 'Marketer name is required.';
$string['errorcodeexists'] = 'Referral code already exists.';
$string['errorinvalidcommission'] = 'Commission percentage must be between 0 and 100.';
$string['errorinvaliduserid'] = 'Marketer user ID is invalid.';
$string['erroruserlinked'] = 'This user is already linked to another marketer.';

$string['payments']               = 'Payment distribution';
$string['disbursement_category']  = 'Category';
$string['disbursement_recipient'] = 'Recipient name';
$string['disbursement_amount']    = 'Amount';
$string['disbursement_period']    = 'Period';
$string['disbursement_receipt']   = 'Receipt';
$string['disbursement_notes']     = 'Notes';
$string['disbursement_recorded_by'] = 'Recorded by';
$string['disbursement_save']      = 'Record disbursement';
$string['disbursement_success']   = 'Disbursement recorded successfully.';
$string['disbursement_err_category']  = 'Invalid category.';
$string['disbursement_err_recipient'] = 'Recipient name is required.';
$string['disbursement_err_amount']    = 'Amount must be greater than zero.';
$string['disbursement_err_exceeds']   = 'Amount exceeds remaining balance ({$a}).';
$string['allocated']       = 'Allocated';
$string['disbursed']       = 'Paid / Disbursed';
$string['remaining']       = 'Remaining';
$string['totalallocated']  = 'Total Allocated';
$string['totaldisbursed']  = 'Total Paid';
$string['totalremaining']  = 'Total Remaining';
$string['nodisbursements'] = 'No disbursements recorded for this period.';
$string['receipt_download'] = 'Download';

// Marketer type system.
$string['type_main']              = 'Main marketer';
$string['type_sub']               = 'Sub marketer';
$string['type_main_badge']        = 'Main';
$string['type_sub_badge']         = 'Sub';
$string['convert_to_main']        = 'Promote to Main';
$string['convert_to_sub']         = 'Set as Sub';
$string['assign_parent_marketer'] = 'Assign parent marketer';
$string['has_sub_marketers']      = 'Has sub-marketers';
$string['no_parent_selected']     = 'Please select a parent marketer.';
$string['error_self_parent']      = 'A marketer cannot be its own parent.';
$string['error_parent_not_main']  = 'The selected parent must be a main marketer.';
$string['error_has_subs']         = 'Cannot convert to sub: this marketer already has sub-marketers.';
$string['promoted_to_main']       = 'Marketer promoted to main successfully.';
$string['set_as_sub']             = 'Marketer set as sub-marketer successfully.';

// Withdrawal routing.
$string['pending_sub_withdrawals']     = 'Pending sub-marketer withdrawal requests';
$string['pending_teacher_withdrawals'] = 'Pending teacher withdrawal requests';
$string['approve_withdrawal']          = 'Approve';
$string['reject_withdrawal']           = 'Reject';
$string['main_marketer_label']         = 'Main marketer';
$string['sub_marketer_label']          = 'Sub marketer';

// Capabilities.
$string['local/referral:manage']             = 'Manage referral marketers (admin)';
$string['local/referral:approvewithdrawals'] = 'Approve marketer and teacher withdrawal requests';
$string['local/referral:viewhierarchy']      = 'View full marketer hierarchy';
$string['local/referral:viewownaccount']     = 'View own marketer account page';
