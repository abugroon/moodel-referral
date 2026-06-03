<?php
// /local/referral/admin/report.php
// Payment Transactions Report — shows revenue, marketer commissions, teacher commissions.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/tabs.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_referral_report');

global $DB, $OUTPUT;

/* ================================================================
   Date filter — default = current month.
================================================================ */
$start_ts   = optional_param('startdate', strtotime(date('Y-m-01')), PARAM_INT);
$end_ts     = optional_param('enddate', time(), PARAM_INT);
$end_of_day = $end_ts + 86399;

/* ================================================================
   Check whether local_tc_transactions exists.
   If not, substitute 0 for teacher commission.
================================================================ */
$has_tc = $DB->get_manager()->table_exists('local_tc_transactions');

// payments.itemid = enrol.id for component='enrol_fee'; enrol gives us courseid.
if ($has_tc) {
    $tc_join   = "LEFT JOIN {local_tc_transactions} tc
                         ON tc.studentid = p.userid
                        AND tc.courseid  = e.courseid
                        AND tc.status    = 'paid'";
    $tc_select = "COALESCE(tc.commissionamount, 0) AS teacher_commission";
} else {
    $tc_join   = '';
    $tc_select = '0 AS teacher_commission';
}

/* ================================================================
   Main query — all payment transactions in the date range.

   payments has no courseid; for enrol_fee payments:
     payments.itemid = enrol.id  →  enrol.courseid = course.id
================================================================ */
$sql = "SELECT p.id,
               p.timecreated,
               p.amount,
               p.currency,
               p.gateway,
               u.firstname,
               u.lastname,
               c.fullname AS coursename,
               COALESCE(CONCAT(mu.firstname, ' ', mu.lastname), 'Direct') AS marketer_name,
               COALESCE(rc.commission, 0) AS marketer_commission,
               $tc_select
          FROM {payments} p
          JOIN {user}   u  ON u.id  = p.userid
          JOIN {enrol}  e  ON e.id  = p.itemid AND p.component = 'enrol_fee'
          JOIN {course} c  ON c.id  = e.courseid
          LEFT JOIN {local_ref_users}           ru ON ru.userid     = p.userid
          LEFT JOIN {local_ref_marketer_profile} mp ON mp.userid    = ru.marketerid
          LEFT JOIN {user}                       mu ON mu.id        = mp.userid
          LEFT JOIN {local_ref_commissions}      rc ON rc.userid    = p.userid
                                                   AND rc.courseid  = e.courseid
                                                   AND rc.status    = 2
          $tc_join
         WHERE p.timecreated >= :start
           AND p.timecreated <= :end
         ORDER BY p.timecreated DESC";

$rows = $DB->get_records_sql($sql, ['start' => $start_ts, 'end' => $end_of_day]);

/* ================================================================
   Summary totals.
================================================================ */
$total_revenue  = 0.0;
$total_marketer = 0.0;
$total_teacher  = 0.0;

foreach ($rows as $row) {
    $total_revenue  += (float)$row->amount;
    $total_marketer += (float)$row->marketer_commission;
    $total_teacher  += (float)$row->teacher_commission;
}

$net_revenue = $total_revenue - $total_marketer - $total_teacher;

/* ================================================================
   Render.
================================================================ */
echo $OUTPUT->header();
echo local_referral_admin_tabs('report');

?>
    <div class="container-fluid mt-4" style="max-width:1600px;">

        <!-- ── Page header ── -->
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 fw-light">Payment Transactions Report</h1>
            <button onclick="window.print();" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-print me-1"></i> Print
            </button>
        </div>

        <!-- ── Date filter ── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 fw-bold text-primary text-uppercase small">Filter by Date Range</h6>
            </div>
            <div class="card-body">
                <form method="get" action="" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">From</label>
                        <input type="date"
                               class="form-control"
                               value="<?php echo date('Y-m-d', $start_ts); ?>"
                               onchange="document.getElementById('rp_ts_start').value =
                               Math.floor(new Date(this.value).getTime() / 1000)">
                        <input type="hidden" name="startdate" id="rp_ts_start"
                               value="<?php echo $start_ts; ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-bold">To</label>
                        <input type="date"
                               class="form-control"
                               value="<?php echo date('Y-m-d', $end_ts); ?>"
                               onchange="document.getElementById('rp_ts_end').value =
                               Math.floor(new Date(this.value).getTime() / 1000)">
                        <input type="hidden" name="enddate" id="rp_ts_end"
                               value="<?php echo $end_ts; ?>">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100 fw-bold">
                            Apply Filter
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── Summary cards ── -->
        <div class="row g-4 mb-5">
            <div class="col-md-3">
                <div class="card shadow-sm border-0 border-start border-primary border-5 h-100 py-2">
                    <div class="card-body">
                        <div class="small fw-bold text-primary text-uppercase mb-1">Total Revenue</div>
                        <div class="h4 fw-bold text-primary mb-0">
                            <?php echo fmt_sdg($total_revenue); ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm border-0 border-start border-warning border-5 h-100 py-2">
                    <div class="card-body">
                        <div class="small fw-bold text-warning text-uppercase mb-1">Marketer Commissions Paid</div>
                        <div class="h4 fw-bold text-warning mb-0">
                            <?php echo fmt_sdg($total_marketer); ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm border-0 border-start border-info border-5 h-100 py-2">
                    <div class="card-body">
                        <div class="small fw-bold text-info text-uppercase mb-1">Teacher Commissions Paid</div>
                        <div class="h4 fw-bold text-info mb-0">
                            <?php echo fmt_sdg($total_teacher); ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm border-0 border-start border-success border-5 h-100 py-2">
                    <div class="card-body">
                        <div class="small fw-bold text-success text-uppercase mb-1">Net Revenue</div>
                        <div class="h4 fw-bold text-success mb-0">
                            <?php echo fmt_sdg($net_revenue); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Transactions table ── -->
        <div class="card shadow-sm border-0 mb-5">
            <div class="card-body p-0">
                <?php if (empty($rows)): ?>
                    <p class="text-muted p-4 mb-0">No payment transactions found for the selected period.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                            <tr>
                                <th class="ps-4 py-3 border-0">Date</th>
                                <th class="py-3 border-0">Student</th>
                                <th class="py-3 border-0">Course</th>
                                <th class="py-3 border-0 text-end">Amount Paid</th>
                                <th class="py-3 border-0">Currency</th>
                                <th class="py-3 border-0">Gateway</th>
                                <th class="py-3 border-0">Marketer</th>
                                <th class="py-3 border-0 text-end">Marketer Commission</th>
                                <th class="pe-4 py-3 border-0 text-end">Teacher Commission</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td class="ps-4 small text-muted">
                                        <?php echo userdate($row->timecreated, '%d/%m/%Y'); ?>
                                    </td>
                                    <td><?php echo s($row->firstname . ' ' . $row->lastname); ?></td>
                                    <td class="small"><?php echo s($row->coursename); ?></td>
                                    <td class="text-end fw-bold">
                                        <?php echo fmt_sdg($row->amount); ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?php echo s($row->currency); ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?php echo s($row->gateway); ?>
                                    </td>
                                    <td>
                                        <?php if ($row->marketer_name === 'Direct'): ?>
                                            <span class="text-muted small">Direct</span>
                                        <?php else: ?>
                                            <?php echo s($row->marketer_name); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php
                                        $mc = (float)$row->marketer_commission;
                                        echo $mc > 0
                                            ? '<span class="text-warning fw-bold">' . fmt_sdg($mc) . '</span>'
                                            : '<span class="text-muted">—</span>';
                                        ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <?php
                                        $tc = (float)$row->teacher_commission;
                                        echo $tc > 0
                                            ? '<span class="text-info fw-bold">' . fmt_sdg($tc) . '</span>'
                                            : '<span class="text-muted">—</span>';
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light border-top border-2">
                            <tr class="fw-bold">
                                <td class="ps-4 py-3" colspan="3">Total (<?php echo count($rows); ?> transactions)</td>
                                <td class="py-3 text-end text-primary fs-6">
                                    <?php echo fmt_sdg($total_revenue); ?>
                                </td>
                                <td colspan="2"></td>
                                <td class="py-3">—</td>
                                <td class="py-3 text-end text-warning fs-6">
                                    <?php echo fmt_sdg($total_marketer); ?>
                                </td>
                                <td class="pe-4 py-3 text-end text-info fs-6">
                                    <?php echo fmt_sdg($total_teacher); ?>
                                </td>
                            </tr>
                            <tr class="fw-bold bg-white">
                                <td class="ps-4 py-2" colspan="3" style="border-top:none;">
                                    Net Revenue (after commissions)
                                </td>
                                <td class="py-2 text-end text-success fs-5" colspan="6"
                                    style="border-top:none;">
                                    <?php echo fmt_sdg($net_revenue); ?>
                                </td>
                            </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /container -->

<?php
echo $OUTPUT->footer();
