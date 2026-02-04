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

    return true;
}
