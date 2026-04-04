<?php
// /local/referral/myaccount.php
// لوحة حساب المسوق — يشوف عمولاته ويطلب السحب

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/referral/myaccount.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('حسابي كمسوق');
$PAGE->set_heading('حسابي كمسوق');

global $DB, $USER, $OUTPUT;

// التحقق من وجود حساب مسوق مرتبط
$marketer = $DB->get_record('local_ref_marketers', ['userid' => $USER->id]);

if (!$marketer) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification('لا يوجد حساب مسوق مرتبط بك.', 'notifyproblem');
    echo html_writer::link(
        new moodle_url('/local/referral/register.php'),
        'سجّل الآن كمسوق',
        ['class' => 'btn btn-primary']
    );
    echo $OUTPUT->footer();
    exit;
}

/* ===========================
   طلب سحب
=========================== */
$requestwithdraw = optional_param('requestwithdraw', 0, PARAM_BOOL);
$withdrawamount  = (float)optional_param('withdrawamount', 0, PARAM_FLOAT);
$withdrawmsg     = '';

if ($requestwithdraw && confirm_sesskey()) {

    // احسب الرصيد المتاح (approved = status 1)
    $available = (float)$DB->get_field_sql(
        "SELECT COALESCE(SUM(commission),0)
           FROM {local_ref_commissions}
          WHERE marketerid = :mid AND status = 1",
        ['mid' => $marketer->id]
    );

    // احسب ما طُلب سحبه ولم يُدفع بعد
    $pending_withdrawal = (float)$DB->get_field_sql(
        "SELECT COALESCE(SUM(amount),0)
           FROM {local_ref_withdrawals}
          WHERE marketerid = :mid AND status = 0",
        ['mid' => $marketer->id]
    );

    $net_available = $available - $pending_withdrawal;

    if ($withdrawamount <= 0) {
        $withdrawmsg = 'error:أدخل مبلغاً صحيحاً.';
    } elseif ($withdrawamount > $net_available) {
        $withdrawmsg = 'error:المبلغ المطلوب أكبر من رصيدك المتاح (' . number_format($net_available, 2) . ').';
    } else {
        $DB->insert_record('local_ref_withdrawals', (object)[
            'marketerid'   => $marketer->id,
            'amount'       => $withdrawamount,
            'status'       => 0,  // 0=pending, 1=done, 2=rejected
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
        $withdrawmsg = 'success:تم إرسال طلب السحب بنجاح! سيتم مراجعته من قِبَل الإدارة.';
    }
}

/* ===========================
   إحصائيات
=========================== */
$total_commission = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid = :mid",
    ['mid' => $marketer->id]
);
$approved_commission = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid = :mid AND status = 1",
    ['mid' => $marketer->id]
);
$paid_commission = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid = :mid AND status = 2",
    ['mid' => $marketer->id]
);
$pending_withdrawal_total = (float)$DB->get_field_sql(
    "SELECT COALESCE(SUM(amount),0) FROM {local_ref_withdrawals} WHERE marketerid = :mid AND status = 0",
    ['mid' => $marketer->id]
);
$net_balance = $approved_commission - $pending_withdrawal_total;

$referred_count = $DB->count_records('local_ref_users', ['marketerid' => $marketer->id]);

// رابط الإحالة
$refurl = (new moodle_url('/', ['ref' => $marketer->code]))->out(false);

echo $OUTPUT->header();
?>

<div class="container-fluid mt-3">

    <!-- ترحيب -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold mb-0">مرحباً، <?php echo s(fullname($USER)); ?> 👋</h2>
        <span class="badge bg-primary fs-6 px-3 py-2">كود الإحالة: <strong><?php echo s($marketer->code); ?></strong></span>
    </div>

    <?php if ($withdrawmsg): 
        [$type, $msg] = explode(':', $withdrawmsg, 2);
        $alertClass = $type === 'success' ? 'alert-success' : 'alert-danger';
    ?>
        <div class="alert <?php echo $alertClass; ?>"><?php echo s($msg); ?></div>
    <?php endif; ?>

    <!-- رابط الإحالة -->
    <div class="card border-0 shadow-sm mb-4 bg-light">
        <div class="card-body d-flex align-items-center gap-3 flex-wrap">
            <div class="flex-grow-1">
                <div class="small fw-bold text-muted mb-1">رابط الإحالة الخاص بك</div>
                <input type="text" class="form-control" id="refLinkInput" value="<?php echo s($refurl); ?>" readonly>
            </div>
            <button class="btn btn-outline-primary mt-3"
                    onclick="navigator.clipboard.writeText(document.getElementById('refLinkInput').value).then(()=>alert('✅ تم نسخ الرابط!'))">
                📋 نسخ الرابط
            </button>
        </div>
    </div>

    <!-- بطاقات الإحصائيات -->
    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['🧑‍🤝‍🧑 مستخدمون محوَّلون', $referred_count, 'info'],
            ['💰 إجمالي العمولات', number_format($total_commission, 2), 'secondary'],
            ['✅ عمولات معتمدة', number_format($approved_commission, 2), 'success'],
            ['💸 تم دفعه', number_format($paid_commission, 2), 'primary'],
            ['🏦 رصيد قابل للسحب', number_format($net_balance, 2), 'warning'],
        ];
        foreach ($cards as [$title, $val, $color]):
        ?>
        <div class="col-6 col-md-4 col-lg-2-4" style="flex:0 0 20%;max-width:20%;">
            <div class="card border-0 shadow-sm text-center py-3 h-100">
                <div class="small text-muted"><?php echo $title; ?></div>
                <div class="h4 fw-bold text-<?php echo $color; ?> mt-1"><?php echo $val; ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">

        <!-- طلب سحب -->
        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-warning text-dark fw-bold">💳 طلب سحب عمولة</div>
                <div class="card-body">
                    <?php if ($net_balance <= 0): ?>
                        <p class="text-muted">لا يوجد رصيد معتمد قابل للسحب حالياً.</p>
                    <?php else: ?>
                        <p class="text-muted small">رصيدك المتاح: <strong class="text-success"><?php echo number_format($net_balance, 2); ?></strong></p>
                        <form method="post" action="">
                            <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]); ?>
                            <?php echo html_writer::empty_tag('input', ['type'=>'hidden','name'=>'requestwithdraw','value'=>1]); ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold small">المبلغ المراد سحبه</label>
                                <input type="number"
                                       name="withdrawamount"
                                       class="form-control"
                                       min="1"
                                       max="<?php echo $net_balance; ?>"
                                       step="0.01"
                                       placeholder="0.00"
                                       required>
                            </div>
                            <button type="submit" class="btn btn-warning w-100 fw-bold">
                                🏧 إرسال طلب السحب
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- طلبات السحب السابقة -->
            <div class="card shadow-sm border-0 mt-3">
                <div class="card-header fw-bold">📋 طلبات السحب</div>
                <div class="card-body p-0">
                    <?php
                    $withdrawals = $DB->get_records('local_ref_withdrawals',
                        ['marketerid' => $marketer->id], 'timecreated DESC', '*', 0, 10);
                    if (empty($withdrawals)):
                    ?>
                        <p class="text-muted p-3 mb-0">لا توجد طلبات سحب بعد.</p>
                    <?php else: ?>
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>المبلغ</th>
                                    <th>الحالة</th>
                                    <th>التاريخ</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($withdrawals as $wr):
                                $wrstatus = ['🕐 قيد المراجعة','✅ تم الدفع','❌ مرفوض'][$wr->status] ?? '-';
                                $wrclass  = ['warning','success','danger'][$wr->status] ?? 'secondary';
                            ?>
                                <tr>
                                    <td><?php echo number_format($wr->amount, 2); ?></td>
                                    <td><span class="badge bg-<?php echo $wrclass; ?>"><?php echo $wrstatus; ?></span></td>
                                    <td class="small text-muted"><?php echo userdate($wr->timecreated, '%d/%m/%Y'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- سجل العمولات -->
        <div class="col-md-8">
            <div class="card shadow-sm border-0">
                <div class="card-header fw-bold">📊 سجل عمولاتي</div>
                <div class="card-body p-0">
                    <?php
                    $sql = "SELECT c.id, c.amount, c.commission, c.status, c.timecreated,
                                   u.firstname, u.lastname,
                                   crs.fullname as coursename
                              FROM {local_ref_commissions} c
                              JOIN {user} u ON u.id = c.userid
                              JOIN {course} crs ON crs.id = c.courseid
                             WHERE c.marketerid = :mid
                          ORDER BY c.timecreated DESC";
                    $commissions = $DB->get_records_sql($sql, ['mid' => $marketer->id]);
                    ?>
                    <?php if (empty($commissions)): ?>
                        <p class="text-muted p-3 mb-0">لا توجد عمولات بعد. شارك رابطك لتبدأ الكسب!</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>المستخدم</th>
                                        <th>الكورس</th>
                                        <th>قيمة الشراء</th>
                                        <th>عمولتي</th>
                                        <th>الحالة</th>
                                        <th>التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($commissions as $c):
                                    $statuses = [
                                        0 => ['🕐 انتظار','warning'],
                                        1 => ['✅ معتمد','success'],
                                        2 => ['💸 مدفوع','primary'],
                                    ];
                                    [$slabel, $sclass] = $statuses[$c->status] ?? ['-','secondary'];
                                ?>
                                    <tr>
                                        <td><?php echo s($c->firstname . ' ' . $c->lastname); ?></td>
                                        <td><?php echo s($c->coursename); ?></td>
                                        <td><?php echo number_format($c->amount, 2); ?></td>
                                        <td class="fw-bold text-success"><?php echo number_format($c->commission, 2); ?></td>
                                        <td><span class="badge bg-<?php echo $sclass; ?>"><?php echo $slabel; ?></span></td>
                                        <td class="small text-muted"><?php echo userdate($c->timecreated, '%d/%m/%Y'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- end row -->
</div>

<?php echo $OUTPUT->footer();
