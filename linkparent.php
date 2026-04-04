<?php
// /local/referral/linkparent.php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

admin_externalpage_setup('local_referral_manage');

$PAGE->set_url(new moodle_url('/local/referral/linkparent.php'));
$PAGE->set_title('ربط المسوقين');
$PAGE->set_heading('ربط المسوقين بمسوقين آباء');

global $DB, $OUTPUT;

$save              = optional_param('save', 0, PARAM_BOOL);
$childid           = optional_param('childid', 0, PARAM_INT);
$newparentid       = optional_param('newparentid', 0, PARAM_INT);
$child_commission  = optional_param('child_commission', 10, PARAM_INT);
$parent_commission = optional_param('parent_commission', 0, PARAM_INT);
$msg = '';

if ($save && $childid && confirm_sesskey()) {
    $child = $DB->get_record('local_ref_marketers', ['id' => $childid], '*', IGNORE_MISSING);
    if ($child) {
        if ($newparentid == $childid) {
            $msg = 'error:لا يمكن ربط المسوق بنفسه!';
        } else {
            $child->parent_id             = $newparentid ?: null;
            $child->commission_percentage = max(0, min(100, $child_commission));
            $child->timemodified          = time();
            $DB->update_record('local_ref_marketers', $child);

            if ($newparentid) {
                $parent = $DB->get_record('local_ref_marketers', ['id' => $newparentid], '*', IGNORE_MISSING);
                if ($parent) {
                    $parent->commission_percentage = max(0, min(100, $parent_commission));
                    $parent->timemodified          = time();
                    $DB->update_record('local_ref_marketers', $parent);
                }
            }
            $msg = 'success:تم الحفظ بنجاح';
        }
    }
}

$allmarketers = $DB->get_records('local_ref_marketers', null, 'name ASC');

$search   = trim(optional_param('search', '', PARAM_TEXT));
$filtered = [];
foreach ($allmarketers as $m) {
    if ($search === '' || stripos($m->name, $search) !== false || stripos($m->code, $search) !== false) {
        $filtered[$m->id] = $m;
    }
}

echo $OUTPUT->header();
?>
<div class="container-fluid mt-3" style="max-width:1100px;">
    <h2 class="fw-bold mb-1">🔗 ربط المسوقين بمسوقين آباء</h2>
    <p class="text-muted mb-4">تقدر تحدد الأب وتعدّل نسبة عمولة الابن والأب في نفس الوقت.</p>

    <?php if ($msg):
        [$type, $text] = explode(':', $msg, 2);
    ?>
    <div class="alert <?php echo $type === 'success' ? 'alert-success' : 'alert-danger'; ?>">
        <?php echo s($text); ?>
    </div>
    <?php endif; ?>

    <form method="get" action="" class="mb-4">
        <div class="input-group" style="max-width:400px;">
            <input type="text" name="search" class="form-control form-control-lg"
                   placeholder="🔍 ابحث باسم المسوق أو الكود..."
                   value="<?php echo s($search); ?>">
            <button type="submit" class="btn btn-primary">بحث</button>
            <?php if ($search): ?>
                <a href="linkparent.php" class="btn btn-outline-secondary">مسح</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">المسوق (الابن)</th>
                            <th>الكود</th>
                            <th>الأب الحالي</th>
                            <th>اختيار الأب</th>
                            <th>نسبة الابن %</th>
                            <th>نسبة الأب %</th>
                            <th>حفظ</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($filtered)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">لا توجد نتائج</td></tr>
                    <?php endif; ?>

                    <?php foreach ($filtered as $m):
                        $currentParent = null;
                        $parentCommPct = 0;
                        if (!empty($m->parent_id)) {
                            $currentParent = $DB->get_record('local_ref_marketers',
                                ['id' => $m->parent_id], 'id,name,code,commission_percentage', IGNORE_MISSING);
                            if ($currentParent) $parentCommPct = (int)$currentParent->commission_percentage;
                        }
                    ?>
                        <tr>
                        <form method="post" action="">
                            <?php echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]); ?>
                            <?php echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'save','value'=>1]); ?>
                            <?php echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'childid','value'=>$m->id]); ?>

                            <td class="ps-4 fw-bold"><?php echo s($m->name); ?></td>
                            <td><code><?php echo s($m->code); ?></code></td>

                            <td>
                                <?php if ($currentParent): ?>
                                    <span class="badge bg-success px-2 py-1">
                                        <?php echo s($currentParent->name); ?>
                                        <small>(<?php echo s($currentParent->code); ?>)</small>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">— رئيسي —</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <select name="newparentid" class="form-select form-select-sm" style="min-width:160px;">
                                    <option value="0" <?php echo empty($m->parent_id) ? 'selected' : ''; ?>>— بدون أب —</option>
                                    <?php foreach ($allmarketers as $pm):
                                        if ($pm->id == $m->id) continue;
                                    ?>
                                        <option value="<?php echo $pm->id; ?>"
                                            <?php echo (!empty($m->parent_id) && $m->parent_id == $pm->id) ? 'selected' : ''; ?>>
                                            <?php echo s($pm->name); ?> (<?php echo s($pm->code); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>

                            <td>
                                <div class="input-group input-group-sm" style="width:95px;">
                                    <input type="number" name="child_commission" class="form-control"
                                           value="<?php echo (int)$m->commission_percentage; ?>" min="0" max="100">
                                    <span class="input-group-text">%</span>
                                </div>
                            </td>

                            <td>
                                <div class="input-group input-group-sm" style="width:95px;">
                                    <input type="number" name="parent_commission" class="form-control"
                                           value="<?php echo $parentCommPct; ?>" min="0" max="100">
                                    <span class="input-group-text">%</span>
                                </div>
                            </td>

                            <td>
                                <button type="submit" class="btn btn-sm btn-primary">💾 حفظ</button>
                            </td>
                        </form>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="alert alert-info mt-4">
        <strong>💡 ملاحظة:</strong> نسبة الأب تُحسب من عمولة الابن وليس من قيمة الكورس مباشرة.<br>
        <strong>مثال:</strong> كورس 100$ — الابن 10% = يأخذ 10$ — الأب 20% = يأخذ 20% من الـ10$ = 2$
    </div>
</div>
<?php echo $OUTPUT->footer();
