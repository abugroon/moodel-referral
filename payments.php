<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_referral_payments');

global $DB, $OUTPUT;

// 1. استقبال الفلاتر
$start_ts = optional_param('startdate', strtotime('-1 month'), PARAM_INT);
$end_ts   = optional_param('enddate', time(), PARAM_INT);

$params = ['start' => $start_ts, 'end' => $end_ts + 86399];

// 2. جلب الإجمالي (تأكد من استخدام اسم الجدول الصحيح بدون mdl_)
$sql = "SELECT SUM(CAST(amount AS DECIMAL(10,2))) as total 
        FROM {payments} 
        WHERE timecreated >= :start AND timecreated <= :end";

$total_record = $DB->get_record_sql($sql, $params);
$grand_total = $total_record->total ?: 0;

// 3. توزيع النسب
$distribution = [
    'Marketing' => 20, 'Teacher' => 30, 'Servers' => 10,
    'Center' => 25, 'Coordinators' => 5, 'IT' => 10
];

echo $OUTPUT->header();
?>

    <div class="container-fluid mt-4">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-dark fw-light">لوحة توزيع الأرباح</h1>
            <button onclick="window.print();" class="btn btn-sm btn-outline-secondary shadow-sm">
                <i class="fa fa-print me-1"></i> طباعة التقرير
            </button>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 fw-bold text-primary text-uppercase small">تصفية حسب الفترة</h6>
            </div>
            <div class="card-body">
                <form method="get" action="" class="row g-3 align-items-end">
                    <input type="hidden" name="section" value="local_referral_payments">

                    <div class="col-md-4">
                        <label class="form-label small fw-bold">من تاريخ</label>
                        <input type="date" class="form-control form-control-lg"
                               value="<?php echo date('Y-m-d', $start_ts); ?>"
                               onchange="document.getElementById('ts_start').value = Math.floor(new Date(this.value).getTime() / 1000)">
                        <input type="hidden" name="startdate" id="ts_start" value="<?php echo $start_ts; ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label small fw-bold">إلى تاريخ</label>
                        <input type="date" class="form-control form-control-lg"
                               value="<?php echo date('Y-m-d', $end_ts); ?>"
                               onchange="document.getElementById('ts_end').value = Math.floor(new Date(this.value).getTime() / 1000)">
                        <input type="hidden" name="enddate" id="ts_end" value="<?php echo $end_ts; ?>">
                    </div>

                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary btn-lg w-100 fw-bold">
                            تحديث التقرير
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm border-0 border-start border-primary border-5 h-100 py-2">
                    <div class="card-body">
                        <div class="text-xs fw-bold text-primary text-uppercase mb-1">صافي مبيعات الفترة</div>
                        <div class="h2 mb-0 fw-bold text-primary"><?php echo number_format($grand_total, 2); ?> <span class="fs-6 fw-normal">USD</span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="bg-light">
                        <tr>
                            <th class="ps-4 py-3 border-0 text-primary small">الجهة</th>
                            <th class="py-3 border-0 text-primary small">الحصة (%)</th>
                            <th class="pe-4 py-3 border-0 text-primary small text-end">المبلغ المستحق</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($distribution as $name => $percent):
                            $share = ($grand_total * $percent) / 100;
                            ?>
                            <tr>
                                <td class="ps-4 fw-medium text-dark"><?php echo $name; ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                  
                                        <span class="small text-muted fw-bold"><?php echo $percent; ?>%</span>
                                    </div>
                                </td>
                                <td class="pe-4 text-end fw-bold text-success fs-5">
                                    <?php echo number_format($share, 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-light border-top border-2">
                        <tr>
                            <td colspan="2" class="ps-4 py-3 fw-bold text-center h5 mb-0">الإجمالي الموزع النهائي</td>
                            <td class="pe-4 py-3 text-end fw-bold text-primary h4 mb-0"><?php echo number_format($grand_total, 2); ?></td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php
echo $OUTPUT->footer();