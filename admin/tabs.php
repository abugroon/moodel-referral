<?php
// /local/referral/admin/tabs.php
// شريط التنقل المشترك لصفحات إدارة البرنامج
defined('MOODLE_INTERNAL') || die();

function local_referral_admin_tabs(string $active): string {
    $tabs = [
        'manage'    => ['icon' => '&#x1F465;', 'label' => 'إدارة المسوقين', 'url' => '/local/referral/admin/manage.php'],
        'payments'  => ['icon' => '&#x1F4B0;', 'label' => 'توزيع الأرباح', 'url' => '/local/referral/admin/payments.php'],
        'report'    => ['icon' => '&#x1F4CB;', 'label' => 'التقارير',       'url' => '/local/referral/admin/report.php'],
        'dashboard' => ['icon' => '&#x1F4CA;', 'label' => 'لوحة التحكم',   'url' => '/local/referral/admin/dashboard.php'],
    ];

    $html  = '<style>';
    $html .= '
.ref-tabs-wrap {
    background: #fff;
    border-radius: 14px;
    padding: 6px;
    box-shadow: 0 1px 8px rgba(0,0,0,.08);
    margin-bottom: 26px;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    border: 1px solid #e2e8f0;
    direction: rtl;
}
.ref-tab-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 20px;
    border-radius: 9px;
    font-size: .88rem;
    font-weight: 600;
    color: #475569;
    text-decoration: none;
    transition: background .15s, color .15s;
    white-space: nowrap;
}
.ref-tab-link:hover {
    background: #f1f5f9;
    color: #1e293b;
    text-decoration: none;
}
.ref-tab-link.ref-tab-active {
    background: #2563eb;
    color: #fff;
    box-shadow: 0 2px 8px rgba(37,99,235,.35);
}
@media (max-width: 600px) {
    .ref-tab-link { padding: 8px 14px; font-size: .82rem; }
}
    ';
    $html .= '</style>';

    $html .= '<div class="ref-tabs-wrap">';
    foreach ($tabs as $id => $tab) {
        $cls = $id === $active ? 'ref-tab-link ref-tab-active' : 'ref-tab-link';
        $url = (new moodle_url($tab['url']))->out(false);
        $html .= '<a href="' . $url . '" class="' . $cls . '">'
               . $tab['icon'] . ' ' . $tab['label']
               . '</a>';
    }
    $html .= '</div>';

    return $html;
}
