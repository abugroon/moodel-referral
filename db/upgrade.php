<?php
// /local/referral/db/upgrade.php
defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade hook for local_referral.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_referral_upgrade($oldversion)
{
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026020400) {
        $marketerstable = new xmldb_table('local_ref_marketers');

        $useridfield = new xmldb_field('userid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'name');
        if (!$dbman->field_exists($marketerstable, $useridfield)) {
            $dbman->add_field($marketerstable, $useridfield);
        }

        $commissionfield = new xmldb_field(
            'commission_percentage',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '10',
            'userid'
        );
        if (!$dbman->field_exists($marketerstable, $commissionfield)) {
            $dbman->add_field($marketerstable, $commissionfield);
        }

        $useridindex = new xmldb_index('userid_uix', XMLDB_INDEX_UNIQUE, ['userid']);
        if (!$dbman->index_exists($marketerstable, $useridindex)) {
            $dbman->add_index($marketerstable, $useridindex);
        }

        $commissionstable = new xmldb_table('local_ref_commissions');
        if (!$dbman->table_exists($commissionstable)) {
            $commissionstable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $commissionstable->add_field('marketerid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $commissionstable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $commissionstable->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $commissionstable->add_field('amount', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, '0.00');
            $commissionstable->add_field('commission', XMLDB_TYPE_NUMBER, '10,2', null, XMLDB_NOTNULL, null, '0.00');
            $commissionstable->add_field('status', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $commissionstable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $commissionstable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $commissionstable->add_index('marketer_idx', XMLDB_INDEX_NOTUNIQUE, ['marketerid']);
            $commissionstable->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $commissionstable->add_index('courseid_idx', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $commissionstable->add_index('usercourse_uix', XMLDB_INDEX_UNIQUE, ['userid', 'courseid']);

            $dbman->create_table($commissionstable);
        }

        upgrade_plugin_savepoint(true, 2026020400, 'local', 'referral');
    }

    // =============================================
    // الإصدار الجديد: إضافة جدول طلبات السحب
    // =============================================
    if ($oldversion < 2026040100) {

        $withdrawalstable = new xmldb_table('local_ref_withdrawals');
        if (!$dbman->table_exists($withdrawalstable)) {
            $withdrawalstable->add_field('id',           XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $withdrawalstable->add_field('marketerid',   XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null,           null);
            $withdrawalstable->add_field('amount',       XMLDB_TYPE_NUMBER,  '10,2',  null, XMLDB_NOTNULL, null,           '0.00');
            $withdrawalstable->add_field('status',       XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null,           '0');
            $withdrawalstable->add_field('notes',        XMLDB_TYPE_TEXT,    null,    null, null,           null,           null);
            $withdrawalstable->add_field('timecreated',  XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null,           null);
            $withdrawalstable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10',    null, XMLDB_NOTNULL, null,           null);

            $withdrawalstable->add_key('primary',       XMLDB_KEY_PRIMARY,  ['id']);
            $withdrawalstable->add_index('marketer_idx', XMLDB_INDEX_NOTUNIQUE, ['marketerid']);
            $withdrawalstable->add_index('status_idx',   XMLDB_INDEX_NOTUNIQUE, ['status']);

            $dbman->create_table($withdrawalstable);
        }

        upgrade_plugin_savepoint(true, 2026040100, 'local', 'referral');
    }

    if ($oldversion < 2026050801) {

        // Add parent_id to local_ref_marketers.
        $marketerstable = new xmldb_table('local_ref_marketers');
        $parentfield = new xmldb_field('parent_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'userid');
        if (!$dbman->field_exists($marketerstable, $parentfield)) {
            $dbman->add_field($marketerstable, $parentfield);
        }

        // Create local_ref_disbursements table.
        $disbtable = new xmldb_table('local_ref_disbursements');
        if (!$dbman->table_exists($disbtable)) {
            $disbtable->add_field('id',             XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $disbtable->add_field('category',       XMLDB_TYPE_CHAR,    '64',   null, XMLDB_NOTNULL, null,           null);
            $disbtable->add_field('recipient_name', XMLDB_TYPE_CHAR,    '255',  null, XMLDB_NOTNULL, null,           null);
            $disbtable->add_field('amount',         XMLDB_TYPE_NUMBER,  '10,2', null, XMLDB_NOTNULL, null,           '0.00');
            $disbtable->add_field('period_start',   XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);
            $disbtable->add_field('period_end',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);
            $disbtable->add_field('receipt_file',   XMLDB_TYPE_CHAR,    '255',  null, null,           null,           null);
            $disbtable->add_field('notes',          XMLDB_TYPE_TEXT,    null,   null, null,           null,           null);
            $disbtable->add_field('created_by',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);
            $disbtable->add_field('timecreated',    XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);

            $disbtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $disbtable->add_index('category_idx',   XMLDB_INDEX_NOTUNIQUE, ['category']);
            $disbtable->add_index('period_idx',     XMLDB_INDEX_NOTUNIQUE, ['period_start', 'period_end']);
            $disbtable->add_index('created_by_idx', XMLDB_INDEX_NOTUNIQUE, ['created_by']);

            $dbman->create_table($disbtable);
        }

        upgrade_plugin_savepoint(true, 2026050801, 'local', 'referral');
    }

    return true;
}
