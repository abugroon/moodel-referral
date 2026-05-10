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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && optional_param('action', '', PARAM_ALPHAEXT) === 'add_disbursement') {
    confirm_sesskey();

    $manual_categories = ['Servers', 'Center', 'Coordinators', 'IT'];

    $category          = required_param('category',          PARAM_TEXT);
    $recipient         = required_param('recipient_name',    PARAM_TEXT);
    $amount            = required_param('amount',            PARAM_FLOAT);
    $disbursement_date = required_param('disbursement_date', PARAM_INT);
    $notes             = optional_param('notes',             '', PARAM_TEXT);

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

    if (!empty($errors)) {
        $errmsg = implode(' | ', $errors);
        redirect(new moodle_url('/local/referral/admin/payments.php',
            ['startdate' => $start_ts, 'enddate' => $end_ts, 'status' => 'error', 'msg' => $errmsg]));
    }

    $newid = disbursement_manager::save([
        'category'          => $category,
        'recipient_name'    => $recipient,
        'amount'            => $amount,
        'disbursement_date' => $disbursement_date,
        'notes'             => $notes,
    ]);

    if ($newid && !empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['receipt_file']['size'] <= 5242880) {
            $ctx      = context_system::instance();
            $fs       = get_file_storage();
            $fileinfo = [
                'contextid' => $ctx->id,
                'component' => 'local_referral',
                'filearea'  => 'disbursement_receipts',
                'itemid'    => $newid,
                'filepath'  => '/',
                'filename'  => clean_filename($_FILES['receipt_file']['name']),
            ];
            $fs->create_file_from_pathname($fileinfo, $_FILES['receipt_file']['tmp_name']);
            disbursement_manager::update_receipt_file($newid, $fileinfo['filename']);
        }
    }

    redirect(new moodle_url('/local/referral/admin/payments.php',
        ['startdate' => $start_ts, 'enddate' => $end_ts, 'status' => 'success']));
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
$history = disbursement_manager::get_history();

// Group history by category for subtotals.
$history_by_cat = [];
foreach ($history as $h) {
    $history_by_cat[$h->category][] = $h;
}

/* ================================================================
   Render.
================================================================ */
$page_status = optional_param('status', '', PARAM_ALPHA);
$page_msg    = optional_param('msg', '', PARAM_TEXT);

echo $OUTPUT->header();
echo local_referral_admin_tabs('payments');
?>

<div class="container-fluid mt-4" style="max-width:1400px;">

    <?php if ($page_status === 'success'): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="fa fa-check-circle fa-lg"></i>
            <strong>Disbursement recorded successfully.</strong>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($page_status === 'error'): ?>
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-4" role="alert">
            <i class="fa fa-times-circle fa-lg"></i>
            <div><strong>Error:</strong> <?php echo s($page_msg); ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

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
                        <span class="fs-6 fw-normal">SDG</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 border-start border-success border-5 h-100 py-2">
                <div class="card-body">
                    <div class="small fw-bold text-success text-uppercase mb-1">Total Allocated</div>
                    <div class="h4 mb-0 fw-bold text-success"><?php echo number_format($total_allocated, 2); ?> SDG</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 border-start border-warning border-5 h-100 py-2">
                <div class="card-body">
                    <div class="small fw-bold text-warning text-uppercase mb-1">Total Disbursed</div>
                    <div class="h4 mb-0 fw-bold text-warning"><?php echo number_format($total_disbursed, 2); ?> SDG</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-0 border-start border-danger border-5 h-100 py-2">
                <div class="card-body">
                    <div class="small fw-bold text-danger text-uppercase mb-1">Total Remaining</div>
                    <div class="h4 mb-0 fw-bold text-danger"><?php echo number_format($total_remaining, 2); ?> SDG</div>
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
                                <th class="ps-4 py-3 border-0 small">Disbursement Date</th>
                                <th class="py-3 border-0 small">Category</th>
                                <th class="py-3 border-0 small">Recipient</th>
                                <th class="py-3 border-0 small text-end">Amount</th>
                                <th class="py-3 border-0 small" style="max-width:200px;">Notes</th>
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
                                        <?php echo date('d/m/Y', $h->period_start); ?>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo s($h->category); ?></span></td>
                                    <td><?php echo s($h->recipient_name); ?></td>
                                    <td class="text-end fw-bold text-warning">
                                        <?php echo number_format((float)$h->amount, 2); ?>
                                    </td>
                                    <td class="small text-muted" style="max-width:200px;">
                                        <?php if (!empty($h->notes)): ?>
                                            <span class="d-inline-block text-truncate" style="max-width:180px;"
                                                  title="<?php echo s($h->notes); ?>"
                                                  data-bs-toggle="tooltip" data-bs-placement="top">
                                                <?php echo s($h->notes); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small"><?php echo s($h->firstname . ' ' . $h->lastname); ?></td>
                                    <td class="pe-4">
                                        <?php if (!empty($h->receipt_file)): ?>
                                            <?php
                                            $preview_url  = moodle_url::make_pluginfile_url(
                                                $ctx->id, 'local_referral', 'disbursement_receipts',
                                                $h->id, '/', $h->receipt_file, false
                                            );
                                            $download_url = moodle_url::make_pluginfile_url(
                                                $ctx->id, 'local_referral', 'disbursement_receipts',
                                                $h->id, '/', $h->receipt_file, true
                                            );
                                            $receipt_ext = strtolower(pathinfo($h->receipt_file, PATHINFO_EXTENSION));
                                            $is_img = in_array($receipt_ext, ['jpg', 'jpeg', 'png']) ? '1' : '0';
                                            ?>
                                            <div class="d-flex gap-1 flex-nowrap">
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-primary"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#receiptPreviewModal"
                                                        data-preview-url="<?php echo s($preview_url->out(false)); ?>"
                                                        data-filename="<?php echo s($h->receipt_file); ?>"
                                                        data-isimage="<?php echo $is_img; ?>">
                                                    <i class="fa fa-eye me-1"></i>Preview
                                                </button>
                                                <a href="<?php echo s($download_url->out(false)); ?>"
                                                   download="<?php echo s($h->receipt_file); ?>"
                                                   class="btn btn-sm btn-outline-secondary"
                                                   title="Download">
                                                    <i class="fa fa-download"></i>
                                                </a>
                                            </div>
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
                                    <option value="<?php echo $mc; ?>"><?php echo $mc; ?> (Remaining: <?php echo number_format($remaining_by_category[$mc], 2); ?> SDG)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Recipient Name <span class="text-danger">*</span></label>
                            <input type="text" name="recipient_name" class="form-control" required placeholder="Full name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Amount (SDG) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" id="disbAmount" class="form-control"
                                   min="0.01" step="0.01" required placeholder="0.00">
                            <div id="disbAmountHint" class="form-text text-muted"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Disbursement Date <span class="text-danger">*</span></label>
                            <input type="date" id="disbDate" class="form-control"
                                   value="<?php echo date('Y-m-d'); ?>" required>
                            <input type="hidden" name="disbursement_date" id="disbDateTs"
                                   value="<?php echo strtotime(date('Y-m-d')); ?>">
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

<!-- ================================================================
     #receiptPreviewModal
================================================================ -->
<div class="modal fade" id="receiptPreviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">
                    <i class="fa fa-file-image-o me-2 text-primary"></i>
                    <span id="receiptPreviewTitle">Receipt Preview</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 text-center bg-light" id="receiptPreviewBody" style="min-height:420px;">
                <div class="d-flex align-items-center justify-content-center h-100 py-5 text-muted">
                    <div class="spinner-border text-primary me-2" role="status" style="width:1.2rem;height:1.2rem;"></div>
                    Loading…
                </div>
            </div>
            <div class="modal-footer">
                <a id="receiptDownloadBtn" href="#" class="btn btn-outline-secondary">
                    <i class="fa fa-download me-1"></i> Download
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
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
        hint.textContent = 'Available: ' + rem.toFixed(2) + ' SDG';
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

document.getElementById('disbDate').addEventListener('change', function () {
    document.getElementById('disbDateTs').value = Math.floor(new Date(this.value).getTime() / 1000);
});

// Populate receipt preview modal before it shows (Bootstrap 5 official pattern).
document.getElementById('receiptPreviewModal').addEventListener('show.bs.modal', function (e) {
    const btn      = e.relatedTarget;
    const url      = btn.dataset.previewUrl;
    const filename = btn.dataset.filename;
    const isImage  = btn.dataset.isimage === '1';

    document.getElementById('receiptPreviewTitle').textContent = filename;

    const dlBtn = document.getElementById('receiptDownloadBtn');
    dlBtn.href = url;
    dlBtn.setAttribute('download', filename);

    const body = document.getElementById('receiptPreviewBody');
    if (isImage) {
        const img = new Image();
        img.src       = url;
        img.className = 'img-fluid';
        img.style.cssText = 'max-height:80vh;display:block;margin:0 auto;';
        body.innerHTML = '';
        body.appendChild(img);
    } else {
        body.innerHTML = '<embed src="' + url + '" type="application/pdf" width="100%" height="600" style="display:block;">';
    }
});

document.getElementById('receiptPreviewModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('receiptPreviewBody').innerHTML = '';
    document.getElementById('receiptPreviewTitle').textContent = 'Receipt Preview';
    document.getElementById('receiptDownloadBtn').href = '#';
});

// Bootstrap tooltips for truncated notes.
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    new bootstrap.Tooltip(el);
});

function disbModalValidate() {
    const cat    = document.getElementById('disbCategory').value;
    const amount = parseFloat(document.getElementById('disbAmount').value);
    const file   = document.getElementById('disbReceipt').files[0];

    if (!cat) {
        alert('الرجاء اختيار الجهة.');
        return false;
    }
    if (isNaN(amount) || amount <= 0) {
        alert('الرجاء إدخال مبلغ صحيح.');
        return false;
    }
    if (file && file.size > 5242880) {
        alert('حجم الملف يتجاوز 5 ميجابايت.');
        return false;
    }
    return true;
}
</script>

<?php echo $OUTPUT->footer();
