<?php
// /local/referral/admin/payments.php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/tabs.php');

use local_referral\disbursement_manager;

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_referral_payments');

global $DB, $OUTPUT, $USER;

disbursement_manager::ensure_table();

/* ================================================================
   Date filter — default = current month.
================================================================ */
$start_ts   = optional_param('startdate', strtotime(date('Y-m-01')), PARAM_INT);
$end_ts     = optional_param('enddate', time(), PARAM_INT);
$end_of_day = $end_ts + 86399;

/* ================================================================
   POST handler — record a disbursement.
================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHA) === 'add_disbursement') {
    confirm_sesskey();

    $manual_categories = ['Servers', 'Center', 'Coordinators', 'IT'];

    $category      = required_param('category',      PARAM_TEXT);
    $recipient     = required_param('recipient_name', PARAM_TEXT);
    $amount        = required_param('amount',         PARAM_FLOAT);
    $period_start  = required_param('period_start',   PARAM_INT);
    $period_end    = required_param('period_end',     PARAM_INT);
    $notes         = optional_param('notes',          '', PARAM_TEXT);

    $errors = [];

    if (!in_array($category, $manual_categories, true)) {
        $errors[] = get_string('disbursement_err_category', 'local_referral');
    }
    if (trim($recipient) === '') {
        $errors[] = get_string('disbursement_err_recipient', 'local_referral');
    }
    if ($amount <= 0) {
        $errors[] = get_string('disbursement_err_amount', 'local_referral');
    }

    if (empty($errors)) {
        // Check remaining balance for this category.
        $sql_rev = "SELECT COALESCE(SUM(amount), 0) AS total
                      FROM {payments}
                     WHERE timecreated >= :start AND timecreated <= :end";
        $rev_record  = $DB->get_record_sql($sql_rev, ['start' => $period_start, 'end' => $period_end + 86399]);
        $grand_total = (float)($rev_record->total ?? 0);

        $distribution = ['Marketing' => 20, 'Teacher' => 30, 'Servers' => 10, 'Center' => 25, 'Coordinators' => 5, 'IT' => 10];
        $allocated    = ($grand_total * ($distribution[$category] ?? 0)) / 100;
        $disbursed    = disbursement_manager::get_disbursed_total($category, $period_start, $period_end + 86399);
        $remaining    = $allocated - $disbursed;

        if ($amount > $remaining + 0.001) {
            $errors[] = get_string('disbursement_err_exceeds', 'local_referral', number_format($remaining, 2));
        }
    }

    if (!empty($errors)) {
        foreach ($errors as $e) {
            \core\notification::error($e);
        }
        redirect(new moodle_url('/local/referral/admin/payments.php', ['startdate' => $start_ts, 'enddate' => $end_ts]));
    }

    $transaction = $DB->start_delegated_transaction();
    try {
        $newid = disbursement_manager::save([
            'category'      => $category,
            'recipient_name'=> $recipient,
            'amount'        => $amount,
            'period_start'  => $period_start,
            'period_end'    => $period_end,
            'notes'         => $notes,
        ]);

        // Handle receipt file upload.
        if (!empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
            if ($_FILES['receipt_file']['size'] > 5242880) {
                throw new \moodle_exception('File exceeds 5 MB limit.');
            }
            $context  = context_system::instance();
            $fs       = get_file_storage();
            $fileinfo = [
                'contextid' => $context->id,
                'component' => 'local_referral',
                'filearea'  => 'disbursement_receipts',
                'itemid'    => $newid,
                'filepath'  => '/',
                'filename'  => clean_filename($_FILES['receipt_file']['name']),
            ];
            $fs->create_file_from_pathname($fileinfo, $_FILES['receipt_file']['tmp_name']);
            disbursement_manager::update_receipt_file($newid, $fileinfo['filename']);
        }

        $transaction->allow_commit();
    } catch (\Throwable $e) {
        $transaction->rollback($e);
        \core\notification::error($e->getMessage());
        redirect(new moodle_url('/local/referral/admin/payments.php', ['startdate' => $start_ts, 'enddate' => $end_ts]));
    }

    \core\notification::success(get_string('disbursement_success', 'local_referral'));
    redirect(new moodle_url('/local/referral/admin/payments.php', ['startdate' => $start_ts, 'enddate' => $end_ts]));
}

/* ================================================================
   Revenue and distribution data.
================================================================ */
$params = ['start' => $start_ts, 'end' => $end_of_day];

$sql_rev = "SELECT COALESCE(SUM(amount), 0) AS total
              FROM {payments}
             WHERE timecreated >= :start AND timecreated <= :end";
$rev_record  = $DB->get_record_sql($sql_rev, $params);
$grand_total = (float)($rev_record->total ?? 0);

$marketing_paid = disbursement_manager::get_marketing_paid($start_ts, $end_of_day);
$teacher_paid   = disbursement_manager::get_teacher_paid($start_ts, $end_of_day);

$distribution = [
    'Marketing'    => 20,
    'Teacher'      => 30,
    'Servers'      => 10,
    'Center'       => 25,
    'Coordinators' => 5,
    'IT'           => 10,
];

$rows_data = [];
$total_allocated  = 0.0;
$total_disbursed  = 0.0;
$total_remaining  = 0.0;

foreach ($distribution as $cat => $pct) {
    $allocated = ($grand_total * $pct) / 100;
    if ($cat === 'Marketing') {
        $disbursed_cat = $marketing_paid;
    } elseif ($cat === 'Teacher') {
        $disbursed_cat = $teacher_paid;
    } else {
        $disbursed_cat = disbursement_manager::get_disbursed_total($cat, $start_ts, $end_of_day);
    }
    $remaining_cat = $allocated - $disbursed_cat;

    $rows_data[$cat] = [
        'pct'       => $pct,
        'allocated' => $allocated,
        'disbursed' => $disbursed_cat,
        'remaining' => $remaining_cat,
        'manual'    => !in_array($cat, ['Marketing', 'Teacher'], true),
    ];
    $total_allocated += $allocated;
    $total_disbursed += $disbursed_cat;
    $total_remaining += $remaining_cat;
}

$manual_cats = ['Servers', 'Center', 'Coordinators', 'IT'];
$remaining_by_category = [];
foreach ($manual_cats as $mc) {
    $remaining_by_category[$mc] = round($rows_data[$mc]['remaining'], 2);
}

/* ================================================================
   Disbursement history.
================================================================ */
$history = disbursement_manager::get_history($start_ts, $end_of_day);

// Group history by category for subtotals.
$history_by_cat = [];
foreach ($history as $h) {
    $history_by_cat[$h->category][] = $h;
}

/* ================================================================
   Render.
================================================================ */
echo $OUTPUT->header();
echo local_referral_admin_tabs('payments');
?>

<div class="container-fluid mt-4" style="max-width:1400px;">

    <!-- ── Page header ── -->
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-dark fw-light">Payment Distribution</h1>
        <button onclick="window.print();" class="btn btn-sm btn-outline-secondary shadow-sm">
            <i class="fa fa-print me-1"></i> Print Report
        </button>
    </div>

    <!-- ── Date filter ── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="m-0 fw-bold text-primary text-uppercase small">Filter by Period</h6>
        </div>
        <div class="card-body">
            <form method="get" action="" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-bold">From</label>
                    <input type="date" class="form-control"
                           value="<?php echo date('Y-m-d', $start_ts); ?>"
                           onchange="document.getElementById('ts_start').value = Math.floor(new Date(this.value).getTime() / 1000)">
                    <input type="hidden" name="startdate" id="ts_start" value="<?php echo $start_ts; ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold">To</label>
                    <input type="date" class="form-control"
                           value="<?php echo date('Y-m-d', $end_ts); ?>"
                           onchange="document.getElementById('ts_end').value = Math.floor(new Date(this.value).getTime() / 1000)">
                    <input type="hidden" name="enddate" id="ts_end" value="<?php echo $end_ts; ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Apply Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Revenue summary card ── -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0 border-start border-primary border-5 h-100 py-2">
                <div class="card-body">
                    <div class="small fw-bold text-primary text-uppercase mb-1">Total Revenue (Period)</div>
                    <div class="h2 mb-0 fw-bold text-primary">
                        <?php echo number_format($grand_total, 2); ?>
                        <span class="fs-6 fw-normal">USD</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 border-start border-success border-5 h-100 py-2">
                <div class="card-body">
                    <div class="small fw-bold text-success text-uppercase mb-1">Total Allocated</div>
                    <div class="h4 mb-0 fw-bold text-success"><?php echo number_format($total_allocated, 2); ?> USD</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 border-start border-warning border-5 h-100 py-2">
                <div class="card-body">
                    <div class="small fw-bold text-warning text-uppercase mb-1">Total Disbursed</div>
                    <div class="h4 mb-0 fw-bold text-warning"><?php echo number_format($total_disbursed, 2); ?> USD</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 border-start border-danger border-5 h-100 py-2">
                <div class="card-body">
                    <div class="small fw-bold text-danger text-uppercase mb-1">Total Remaining</div>
                    <div class="h4 mb-0 fw-bold text-danger"><?php echo number_format($total_remaining, 2); ?> USD</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Distribution table ── -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="m-0 fw-bold text-primary text-uppercase small">Revenue Distribution</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 border-0 text-primary small">Category</th>
                            <th class="py-3 border-0 text-primary small">Share (%)</th>
                            <th class="py-3 border-0 text-primary small text-end">Allocated</th>
                            <th class="py-3 border-0 text-primary small text-end">Disbursed</th>
                            <th class="pe-4 py-3 border-0 text-primary small text-end">Remaining</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows_data as $cat => $d): ?>
                        <tr>
                            <td class="ps-4 fw-medium text-dark"><?php echo htmlspecialchars($cat); ?></td>
                            <td><span class="small text-muted fw-bold"><?php echo $d['pct']; ?>%</span></td>
                            <td class="text-end fw-bold text-dark">
                                <?php echo number_format($d['allocated'], 2); ?>
                            </td>
                            <td class="text-end fw-bold <?php echo $d['disbursed'] > 0 ? 'text-warning' : 'text-muted'; ?>">
                                <?php echo $d['disbursed'] > 0 ? number_format($d['disbursed'], 2) : '—'; ?>
                            </td>
                            <td class="pe-4 text-end fw-bold <?php echo $d['remaining'] > 0.01 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo number_format($d['remaining'], 2); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-light border-top border-2">
                        <tr class="fw-bold">
                            <td class="ps-4 py-3" colspan="2">Total</td>
                            <td class="py-3 text-end text-primary fs-6"><?php echo number_format($total_allocated, 2); ?></td>
                            <td class="py-3 text-end text-warning fs-6"><?php echo number_format($total_disbursed, 2); ?></td>
                            <td class="pe-4 py-3 text-end text-danger fs-6"><?php echo number_format($total_remaining, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Record Disbursement button ── -->
    <div class="text-end mb-5">
        <button type="button"
                class="btn btn-success px-4 fw-bold"
                data-bs-toggle="modal"
                data-bs-target="#disbursementModal">
            <i class="fa fa-plus-circle me-1"></i> Record Disbursement
        </button>
    </div>

    <!-- ── Disbursement history ── -->
    <div class="card shadow-sm border-0 mb-5">
        <div class="card-header bg-white py-3">
            <h6 class="m-0 fw-bold text-primary text-uppercase small">Disbursement History</h6>
        </div>
        <div class="card-body p-0">
            <?php if (empty($history)): ?>
                <p class="text-muted p-4 mb-0"><?php echo get_string('nodisbursements', 'local_referral'); ?></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4 py-3 border-0 small">Date</th>
                                <th class="py-3 border-0 small">Category</th>
                                <th class="py-3 border-0 small">Recipient</th>
                                <th class="py-3 border-0 small text-end">Amount</th>
                                <th class="py-3 border-0 small">Period</th>
                                <th class="py-3 border-0 small">Recorded By</th>
                                <th class="pe-4 py-3 border-0 small">Receipt</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $ctx = context_system::instance();
                        foreach ($history_by_cat as $cat => $cat_rows):
                            $cat_total = array_sum(array_column($cat_rows, 'amount'));
                        ?>
                            <?php foreach ($cat_rows as $h): ?>
                                <tr>
                                    <td class="ps-4 small text-muted">
                                        <?php echo userdate($h->timecreated, '%d/%m/%Y'); ?>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo s($h->category); ?></span></td>
                                    <td><?php echo s($h->recipient_name); ?></td>
                                    <td class="text-end fw-bold text-warning">
                                        <?php echo number_format((float)$h->amount, 2); ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?php echo date('d/m/Y', $h->period_start); ?> –
                                        <?php echo date('d/m/Y', $h->period_end); ?>
                                    </td>
                                    <td class="small"><?php echo s($h->firstname . ' ' . $h->lastname); ?></td>
                                    <td class="pe-4">
                                        <?php if (!empty($h->receipt_file)): ?>
                                            <?php
                                            $url = moodle_url::make_pluginfile_url(
                                                $ctx->id, 'local_referral', 'disbursement_receipts',
                                                $h->id, '/', $h->receipt_file, true
                                            );
                                            ?>
                                            <a href="<?php echo $url->out(false); ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                                                <i class="fa fa-download me-1"></i><?php echo get_string('receipt_download', 'local_referral'); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="table-light fw-bold">
                                <td class="ps-4 py-2" colspan="3">
                                    <span class="text-secondary small text-uppercase"><?php echo s($cat); ?> subtotal</span>
                                </td>
                                <td class="py-2 text-end text-warning"><?php echo number_format($cat_total, 2); ?></td>
                                <td colspan="3" class="pe-4"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /container -->

<!-- ================================================================
     #disbursementModal — Bootstrap 5 modal
================================================================ -->
<div class="modal fade" id="disbursementModal" tabindex="-1" aria-labelledby="disbursementModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="disbursementModalLabel">
                    <i class="fa fa-plus-circle me-2"></i> Record Disbursement
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="post"
                  action="<?php echo (new moodle_url('/local/referral/admin/payments.php'))->out(false); ?>"
                  enctype="multipart/form-data"
                  id="disbursementForm"
                  onsubmit="return disbModalValidate()">
                <input type="hidden" name="action"    value="add_disbursement">
                <input type="hidden" name="sesskey"   value="<?php echo sesskey(); ?>">
                <input type="hidden" name="startdate" value="<?php echo $start_ts; ?>">
                <input type="hidden" name="enddate"   value="<?php echo $end_ts; ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Category <span class="text-danger">*</span></label>
                            <select name="category" id="disbCategory" class="form-select" required>
                                <option value="">— Select category —</option>
                                <?php foreach ($manual_cats as $mc): ?>
                                    <option value="<?php echo $mc; ?>"><?php echo $mc; ?> (Remaining: <?php echo number_format($remaining_by_category[$mc], 2); ?> USD)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Recipient Name <span class="text-danger">*</span></label>
                            <input type="text" name="recipient_name" class="form-control" required placeholder="Full name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Amount (USD) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" id="disbAmount" class="form-control"
                                   min="0.01" step="0.01" required placeholder="0.00">
                            <div id="disbAmountHint" class="form-text text-muted"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Period From <span class="text-danger">*</span></label>
                            <input type="date" id="disbPeriodStart" class="form-control"
                                   value="<?php echo date('Y-m-d', $start_ts); ?>" required>
                            <input type="hidden" name="period_start" id="disbPeriodStartTs" value="<?php echo $start_ts; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Period To <span class="text-danger">*</span></label>
                            <input type="date" id="disbPeriodEnd" class="form-control"
                                   value="<?php echo date('Y-m-d', $end_ts); ?>" required>
                            <input type="hidden" name="period_end" id="disbPeriodEndTs" value="<?php echo $end_ts; ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Receipt (optional, max 5 MB)</label>
                            <input type="file" name="receipt_file" id="disbReceipt" class="form-control"
                                   accept=".pdf,.jpg,.jpeg,.png">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold">
                        <i class="fa fa-save me-1"></i> Record Disbursement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const remainingByCategory = <?php echo json_encode($remaining_by_category); ?>;

document.getElementById('disbCategory').addEventListener('change', function () {
    const cat  = this.value;
    const inp  = document.getElementById('disbAmount');
    const hint = document.getElementById('disbAmountHint');
    if (cat && remainingByCategory[cat] !== undefined) {
        const rem = remainingByCategory[cat];
        inp.max   = rem;
        hint.textContent = 'Available: ' + rem.toFixed(2) + ' USD';
        hint.className = 'form-text ' + (rem > 0 ? 'text-success' : 'text-danger');
    } else {
        inp.removeAttribute('max');
        hint.textContent = '';
    }
});

document.getElementById('disbursementModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('disbursementForm').reset();
    document.getElementById('disbAmount').removeAttribute('max');
    document.getElementById('disbAmountHint').textContent = '';
});

document.getElementById('disbPeriodStart').addEventListener('change', function () {
    document.getElementById('disbPeriodStartTs').value = Math.floor(new Date(this.value).getTime() / 1000);
});
document.getElementById('disbPeriodEnd').addEventListener('change', function () {
    document.getElementById('disbPeriodEndTs').value = Math.floor(new Date(this.value).getTime() / 1000);
});

function disbModalValidate() {
    const cat    = document.getElementById('disbCategory').value;
    const amount = parseFloat(document.getElementById('disbAmount').value);
    const file   = document.getElementById('disbReceipt').files[0];

    if (!cat) {
        alert('Please select a category.');
        return false;
    }
    if (isNaN(amount) || amount <= 0) {
        alert('Please enter a valid amount.');
        return false;
    }
    if (remainingByCategory[cat] !== undefined && amount > remainingByCategory[cat] + 0.001) {
        alert('Amount exceeds remaining balance of ' + remainingByCategory[cat].toFixed(2) + ' USD.');
        return false;
    }
    if (file && file.size > 5242880) {
        alert('Receipt file must not exceed 5 MB.');
        return false;
    }
    return true;
}
</script>

<?php echo $OUTPUT->footer();
