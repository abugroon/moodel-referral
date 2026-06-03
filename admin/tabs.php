<?php
// /local/referral/admin/tabs.php
defined('MOODLE_INTERNAL') || die();

function local_referral_admin_tabs(string $active): string {
    $tabs = [
        'manage'    => ['label' => 'إدارة المسوقين', 'url' => '/local/referral/admin/manage.php'],
        'payments'  => ['label' => 'توزيع الأرباح',  'url' => '/local/referral/admin/payments.php'],
        'report'    => ['label' => 'التقارير',        'url' => '/local/referral/admin/report.php'],
        'dashboard' => ['label' => 'لوحة التحكم',    'url' => '/local/referral/admin/dashboard.php'],
    ];

    $html  = '<style>';
    $html .= '
.ref-tabs-wrap {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 5px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    margin-bottom: 22px;
    direction: rtl;
}
.ref-tab-link {
    display: inline-flex;
    align-items: center;
    padding: 8px 18px;
    border-radius: 7px;
    font-size: .85rem;
    font-weight: 600;
    color: #64748b;
    text-decoration: none;
    transition: background .15s, color .15s;
    white-space: nowrap;
}
.ref-tab-link:hover {
    background: #f1f5f9;
    color: #1e293b;
    text-decoration: none;
}
.ref-tab-active {
    background: #2563eb;
    color: #fff !important;
}
.ref-tab-active:hover { background: #1d4ed8; }
@media (max-width: 600px) {
    .ref-tab-link { padding: 7px 12px; font-size: .8rem; }
}
.cur { font-size: .72em; font-weight: 700; color: #94a3b8; letter-spacing: .03em; }
    ';
    $html .= '</style>';

    $html .= '<div class="ref-tabs-wrap">';
    foreach ($tabs as $id => $tab) {
        $cls = $id === $active ? 'ref-tab-link ref-tab-active' : 'ref-tab-link';
        $url = (new moodle_url($tab['url']))->out(false);
        $html .= '<a href="' . $url . '" class="' . $cls . '">' . $tab['label'] . '</a>';
    }
    $html .= '</div>';

    return $html;
}
