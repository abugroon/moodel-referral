<?php
// /local/referral/mystudents.php  — referred students list for the logged-in marketer
require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/referral/mystudents.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('طلابي المُحالون / My Referred Students');
$PAGE->set_heading('طلابي المُحالون / My Referred Students');

global $DB, $USER, $OUTPUT;

$marketer = $DB->get_record('local_ref_marketer_profile', ['userid' => $USER->id]);

if (!$marketer) {
    echo $OUTPUT->header();
    echo '
    <div style="max-width:460px;margin:60px auto;text-align:center;padding:0 16px;">
        <div style="width:72px;height:72px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;
                    justify-content:center;margin:0 auto 18px;font-size:2rem;">&#x1F4E3;</div>
        <h3 style="color:#1e293b;font-weight:800;font-size:1.3rem;margin-bottom:10px;">لست مسوقاً بعد! / You are not a marketer yet!</h3>
        <a href="' . (new moodle_url('/local/referral/register.php'))->out(false) . '"
           style="background:#2563eb;color:#fff;padding:12px 32px;border-radius:10px;font-weight:700;
                  text-decoration:none;font-size:.95rem;display:inline-block;">
            سجّل كمسوق / Register as a Marketer
        </a>
    </div>';
    echo $OUTPUT->footer();
    exit;
}

// ── Search / filter ──────────────────────────────────────────────
$search = optional_param('search', '', PARAM_TEXT);
$search = trim($search);

// ── Pagination ───────────────────────────────────────────────────
$perpage = 20;
$page    = optional_param('page', 0, PARAM_INT);
$offset  = $page * $perpage;

// ── Count total referred students ────────────────────────────────
$search_sql    = '';
$search_params = ['mid' => $marketer->userid];

if ($search !== '') {
    $search_sql    = " AND (" . $DB->sql_like('u.firstname', ':s1', false) .
                     " OR "   . $DB->sql_like('u.lastname',  ':s2', false) .
                     " OR "   . $DB->sql_like('u.email',     ':s3', false) . ")";
    $like          = '%' . $DB->sql_like_escape($search) . '%';
    $search_params += ['s1' => $like, 's2' => $like, 's3' => $like];
}

$count_sql = "SELECT COUNT(*)
                FROM {local_ref_users} ru
           LEFT JOIN {user} u ON u.id = ru.userid
               WHERE ru.marketerid = :mid $search_sql";
$total = (int)$DB->count_records_sql($count_sql, $search_params);

// ── Fetch referred students (paginated) ──────────────────────────
$rows_sql = "SELECT ru.userid, ru.timecreated AS refdate,
                    u.firstname, u.lastname, u.email,
                    COALESCE(comm.total_comm, 0)  AS total_comm,
                    COALESCE(comm.num_courses, 0) AS num_courses
               FROM {local_ref_users} ru
          LEFT JOIN {user} u ON u.id = ru.userid
          LEFT JOIN (
                    SELECT userid,
                           COUNT(*)              AS num_courses,
                           COALESCE(SUM(commission), 0) AS total_comm
                      FROM {local_ref_commissions}
                     WHERE marketerid = :mid2
                     GROUP BY userid
                    ) comm ON comm.userid = ru.userid
              WHERE ru.marketerid = :mid $search_sql
           ORDER BY ru.timecreated DESC";

$rows = $DB->get_records_sql(
    $rows_sql,
    array_merge($search_params, ['mid2' => $marketer->userid]),
    $offset,
    $perpage
);

// ── Back link ────────────────────────────────────────────────────
$myaccount_url = (new moodle_url('/local/referral/myaccount.php'))->out(false);

echo $OUTPUT->header();
?>
<style>
:root {
    --p:  #2563eb;
    --g:  #059669;
    --a:  #d97706;
    --r:  #dc2626;
    --d:  #1e293b;
    --m:  #64748b;
    --b:  #e2e8f0;
    --s:  #f8fafc;
}

.ms { max-width:1100px; margin:0 auto; padding:14px 16px 56px; }

/* ── Header bar ── */
.ms-topbar {
    display:flex; align-items:center; flex-wrap:wrap; gap:12px;
    margin-bottom:20px;
}
.ms-topbar h1 {
    margin:0; font-size:1.2rem; font-weight:800; color:var(--d); flex:1;
}
.ms-back {
    display:inline-flex; align-items:center; gap:6px;
    background:#fff; border:1px solid var(--b); border-radius:8px;
    padding:7px 16px; font-size:.83rem; font-weight:600; color:var(--m);
    text-decoration:none; transition:border-color .15s, color .15s;
}
.ms-back:hover { border-color:var(--p); color:var(--p); }

/* ── Stats strip ── */
.ms-stats {
    display:flex; gap:12px; flex-wrap:wrap; margin-bottom:20px;
}
.ms-stat {
    background:#fff; border:1px solid var(--b); border-left:3px solid var(--p);
    border-radius:10px; padding:12px 20px; min-width:140px;
}
.ms-stat .sl { font-size:.67rem; font-weight:700; color:var(--m);
               text-transform:uppercase; letter-spacing:.04em; margin-bottom:3px; }
.ms-stat .sv { font-size:1.3rem; font-weight:800; color:var(--d); }

/* ── Search bar ── */
.ms-search {
    margin-bottom:18px; display:flex; gap:8px; flex-wrap:wrap;
}
.ms-search input {
    flex:1; min-width:220px; padding:9px 14px;
    border:1.5px solid var(--b); border-radius:8px;
    font-size:.9rem; color:var(--d); background:#fff;
    transition:border-color .15s;
}
.ms-search input:focus { outline:none; border-color:var(--p); }
.ms-search button {
    padding:9px 22px; background:var(--p); color:#fff; border:none;
    border-radius:8px; font-weight:600; font-size:.9rem; cursor:pointer;
    transition:opacity .15s;
}
.ms-search button:hover { opacity:.85; }
.ms-search .btn-clear {
    background:#fff; color:var(--m); border:1.5px solid var(--b);
}
.ms-search .btn-clear:hover { border-color:var(--r); color:var(--r); opacity:1; }

/* ── Card / table ── */
.ms-card {
    background:#fff; border:1px solid var(--b); border-radius:12px; overflow:hidden;
}
.ms-card-hdr {
    padding:11px 18px; background:var(--s); border-bottom:1px solid var(--b);
    display:flex; align-items:center; justify-content:space-between; gap:8px;
}
.ms-card-hdr h3 { margin:0; font-size:.86rem; font-weight:700; color:var(--d); }
.ms-card-hdr .badge {
    background:var(--p); color:#fff; font-size:.65rem; font-weight:700;
    padding:2px 9px; border-radius:20px;
}
.ms-tbl { width:100%; border-collapse:collapse; }
.ms-tbl thead th {
    padding:10px 14px; background:var(--s); font-size:.67rem; font-weight:700;
    color:var(--m); text-transform:uppercase; letter-spacing:.04em;
    border-bottom:2px solid var(--b); white-space:nowrap;
}
.ms-tbl tbody td {
    padding:11px 14px; border-bottom:1px solid var(--b);
    font-size:.84rem; color:#334155; vertical-align:middle;
}
.ms-tbl tbody tr:last-child td { border-bottom:none; }
.ms-tbl tbody tr:hover td { background:#f8faff; }

.badge-courses {
    display:inline-block; background:#dbeafe; color:#1d4ed8;
    padding:2px 9px; border-radius:20px; font-size:.72rem; font-weight:700;
}
.badge-zero {
    display:inline-block; background:#f1f5f9; color:#94a3b8;
    padding:2px 9px; border-radius:20px; font-size:.72rem; font-weight:700;
}

/* ── Pagination ── */
.ms-pages {
    padding:14px 18px; background:var(--s); border-top:1px solid var(--b);
    display:flex; align-items:center; justify-content:space-between;
    flex-wrap:wrap; gap:10px;
}
.ms-pages .pg-info { font-size:.8rem; color:var(--m); }
.ms-pages .pg-links { display:flex; gap:6px; flex-wrap:wrap; }
.ms-pages a, .ms-pages span {
    display:inline-block; padding:5px 12px; border-radius:6px;
    font-size:.8rem; font-weight:600; text-decoration:none;
    border:1px solid var(--b); color:var(--d); background:#fff;
    transition:background .15s, border-color .15s;
}
.ms-pages a:hover  { background:#eff6ff; border-color:var(--p); }
.ms-pages span.cur { background:var(--p); color:#fff; border-color:var(--p); }
.ms-pages span.dis { color:#cbd5e1; background:var(--s); cursor:default; }

/* ── Empty ── */
.ms-empty { text-align:center; padding:48px 20px; color:var(--m); font-size:.9rem; }
.ms-empty-icon { font-size:2rem; margin-bottom:10px; opacity:.4; }
</style>

<div class="ms">

<!-- ── Top bar ── -->
<div class="ms-topbar">
    <h1>طلابي المُحالون <span style="font-weight:400;color:var(--m);font-size:.85rem;">/ My Referred Students</span></h1>
    <a href="<?php echo $myaccount_url; ?>" class="ms-back">&#8592; العودة للوحة / Back to Dashboard</a>
</div>

<!-- ── Stats ── -->
<div class="ms-stats">
    <div class="ms-stat">
        <div class="sl">إجمالي المُحالين / Total Referred</div>
        <div class="sv"><?php echo $total; ?></div>
    </div>
    <?php
    // Total commission from all referred students
    $total_comm = (float)$DB->get_field_sql(
        "SELECT COALESCE(SUM(commission),0) FROM {local_ref_commissions} WHERE marketerid = :mid",
        ['mid' => $marketer->userid]
    );
    ?>
    <div class="ms-stat" style="border-left-color:var(--g);">
        <div class="sl">إجمالي العمولات / Total Commissions</div>
        <div class="sv" style="color:#065f46;"><?php echo number_format($total_comm, 2); ?></div>
    </div>
    <div class="ms-stat" style="border-left-color:var(--a);">
        <div class="sl">كود الإحالة / Referral Code</div>
        <div class="sv" style="color:#92400e;letter-spacing:.08em;"><?php echo s($marketer->code); ?></div>
    </div>
</div>

<!-- ── Search ── -->
<form method="get" action="" class="ms-search">
    <input type="text" name="search"
           value="<?php echo s($search); ?>"
           placeholder="ابحث بالاسم أو الإيميل / Search by name or email...">
    <button type="submit">بحث / Search</button>
    <?php if ($search !== ''): ?>
    <a href="<?php echo (new moodle_url('/local/referral/mystudents.php'))->out(false); ?>"
       class="ms-search button btn-clear" style="display:inline-flex;align-items:center;padding:9px 18px;
              text-decoration:none;border-radius:8px;font-weight:600;font-size:.9rem;
              color:var(--m);border:1.5px solid var(--b);background:#fff;">
        &#x2715; مسح / Clear
    </a>
    <?php endif; ?>
</form>

<!-- ── Table ── -->
<div class="ms-card">
    <div class="ms-card-hdr">
        <h3>الطلاب المُحالون / Referred Students</h3>
        <span class="badge"><?php echo $total; ?></span>
    </div>

    <?php if (empty($rows)): ?>
    <div class="ms-empty">
        <div class="ms-empty-icon">&#x1F393;</div>
        <?php if ($search !== ''): ?>
        <p>لا يوجد طلاب يطابقون "<strong><?php echo s($search); ?></strong>" / No students match this search.</p>
        <?php else: ?>
        <p>لم يسجّل أي طالب عبر رابطك بعد / No students have registered through your referral link yet.</p>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="ms-tbl">
            <thead>
                <tr>
                    <th>#</th>
                    <th>الاسم الكامل / Full Name</th>
                    <th>الإيميل / Email</th>
                    <th>تاريخ التسجيل / Registered On</th>
                    <th>الكورسات / Enrolled Courses</th>
                    <th>العمولة / Commission Earned</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $row_num = $offset + 1;
            foreach ($rows as $row):
                $full_name = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
            ?>
            <tr>
                <td style="color:var(--m);font-weight:600;"><?php echo $row_num++; ?></td>
                <td style="font-weight:700;color:var(--d);"><?php echo s($full_name ?: '—'); ?></td>
                <td style="color:var(--m);"><?php echo s($row->email ?? '—'); ?></td>
                <td><?php echo userdate($row->refdate, '%d %b %Y'); ?></td>
                <td>
                    <?php if ($row->num_courses > 0): ?>
                    <span class="badge-courses"><?php echo (int)$row->num_courses; ?> كورس<?php echo $row->num_courses > 1 ? ' / courses' : ' / course'; ?></span>
                    <?php else: ?>
                    <span class="badge-zero">لا يوجد / None</span>
                    <?php endif; ?>
                </td>
                <td style="font-weight:700;color:<?php echo $row->total_comm > 0 ? '#065f46' : 'var(--m)'; ?>;">
                    <?php echo number_format((float)$row->total_comm, 2); ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Pagination ── -->
    <?php
    $num_pages = (int)ceil($total / $perpage);
    if ($num_pages > 1):
        $base_params = $search !== '' ? ['search' => $search] : [];
    ?>
    <div class="ms-pages">
        <div class="pg-info">
            عرض <?php echo $offset + 1; ?>–<?php echo min($offset + $perpage, $total); ?> من <?php echo $total; ?> / Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $perpage, $total); ?> of <?php echo $total; ?>
        </div>
        <div class="pg-links">
            <?php if ($page > 0): ?>
            <a href="<?php echo (new moodle_url('/local/referral/mystudents.php', array_merge($base_params, ['page' => $page - 1])))->out(false); ?>">&#8592; السابق / Prev</a>
            <?php else: ?>
            <span class="dis">&#8592; السابق / Prev</span>
            <?php endif; ?>

            <?php for ($p = 0; $p < $num_pages; $p++): ?>
            <?php if ($p === $page): ?>
            <span class="cur"><?php echo $p + 1; ?></span>
            <?php else: ?>
            <a href="<?php echo (new moodle_url('/local/referral/mystudents.php', array_merge($base_params, ['page' => $p])))->out(false); ?>"><?php echo $p + 1; ?></a>
            <?php endif; ?>
            <?php endfor; ?>

            <?php if ($page < $num_pages - 1): ?>
            <a href="<?php echo (new moodle_url('/local/referral/mystudents.php', array_merge($base_params, ['page' => $page + 1])))->out(false); ?>">التالي / Next &#8594;</a>
            <?php else: ?>
            <span class="dis">التالي / Next &#8594;</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div><!-- .ms-card -->

</div><!-- .ms -->
<?php
echo $OUTPUT->footer();
