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

        $marketerstable = new xmldb_table('local_ref_marketers');
        if ($dbman->table_exists($marketerstable)) {
            $parentfield = new xmldb_field('parent_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'userid');
            if (!$dbman->field_exists($marketerstable, $parentfield)) {
                $dbman->add_field($marketerstable, $parentfield);
            }
        }

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

    if ($oldversion < 2026040200) {
        $table = new xmldb_table('local_ref_marketers');
        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('parent_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'userid');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026040200, 'local', 'referral');
    }

    if ($oldversion < 2026040300) {

        $new_table = new xmldb_table('local_ref_marketer_profile');
        if (!$dbman->table_exists($new_table)) {
            $new_table->add_field('id',                   XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $new_table->add_field('userid',               XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);
            $new_table->add_field('code',                 XMLDB_TYPE_CHAR,    '64',   null, XMLDB_NOTNULL, null,           null);
            $new_table->add_field('commission_percentage',XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           '10');
            $new_table->add_field('parent_userid',        XMLDB_TYPE_INTEGER, '10',   null, null,           null,           null);
            $new_table->add_field('timecreated',          XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);
            $new_table->add_field('timemodified',         XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);

            $new_table->add_key('primary',     XMLDB_KEY_PRIMARY, ['id']);
            $new_table->add_index('userid_uix', XMLDB_INDEX_UNIQUE,    ['userid']);
            $new_table->add_index('code_uix',   XMLDB_INDEX_UNIQUE,    ['code']);

            $dbman->create_table($new_table);
        }

        $old_table = new xmldb_table('local_ref_marketers');
        if ($dbman->table_exists($old_table)) {
            $old_rows = $DB->get_records_select(
                'local_ref_marketers',
                'userid IS NOT NULL',
                [],
                '',
                'id, userid, code, commission_percentage, parent_id, timecreated, timemodified'
            );

            $id_to_userid = [];
            foreach ($old_rows as $r) {
                $id_to_userid[$r->id] = (int)$r->userid;
            }

            foreach ($old_rows as $r) {
                if ($DB->record_exists('local_ref_marketer_profile', ['userid' => $r->userid])) {
                    continue;
                }
                $parent_userid = (!empty($r->parent_id) && isset($id_to_userid[$r->parent_id]))
                    ? $id_to_userid[$r->parent_id]
                    : null;

                $DB->insert_record('local_ref_marketer_profile', (object)[
                    'userid'                => (int)$r->userid,
                    'code'                  => $r->code,
                    'commission_percentage' => (int)$r->commission_percentage,
                    'parent_userid'         => $parent_userid,
                    'timecreated'           => (int)$r->timecreated,
                    'timemodified'          => (int)$r->timemodified,
                ]);
            }

            foreach ($id_to_userid as $old_id => $uid) {
                foreach (['local_ref_users', 'local_ref_commissions', 'local_ref_withdrawals'] as $tbl) {
                    $DB->execute(
                        "UPDATE {{$tbl}} SET marketerid = :uid WHERE marketerid = :old_id",
                        ['uid' => $uid, 'old_id' => $old_id]
                    );
                }
            }

            $dbman->drop_table($old_table);
        }

        upgrade_plugin_savepoint(true, 2026040300, 'local', 'referral');
    }

    if ($oldversion < 2026050901) {
        $table = new xmldb_table('local_ref_withdrawals');
        $field = new xmldb_field('receipt_file', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'notes');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026050901, 'local', 'referral');
    }

    if ($oldversion < 2026051001) {
        $profile_table = new xmldb_table('local_ref_marketer_profile');
        if (!$dbman->table_exists($profile_table)) {
            $profile_table->add_field('id',                    XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $profile_table->add_field('userid',                XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null,           null);
            $profile_table->add_field('code',                  XMLDB_TYPE_CHAR,    '64',  null, XMLDB_NOTNULL, null,           null);
            $profile_table->add_field('commission_percentage', XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null,           '10');
            $profile_table->add_field('parent_userid',         XMLDB_TYPE_INTEGER, '10',  null, null,           null,           null);
            $profile_table->add_field('timecreated',           XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null,           null);
            $profile_table->add_field('timemodified',          XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null,           null);

            $profile_table->add_key('primary',    XMLDB_KEY_PRIMARY, ['id']);
            $profile_table->add_index('userid_uix', XMLDB_INDEX_UNIQUE, ['userid']);
            $profile_table->add_index('code_uix',   XMLDB_INDEX_UNIQUE, ['code']);

            $dbman->create_table($profile_table);
        }

        $wr_table = new xmldb_table('local_ref_withdrawals');
        if ($dbman->table_exists($wr_table)) {
            $rf_field = new xmldb_field('receipt_file', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'notes');
            if (!$dbman->field_exists($wr_table, $rf_field)) {
                $dbman->add_field($wr_table, $rf_field);
            }
        }

        $old_table = new xmldb_table('local_ref_marketers');
        if ($dbman->table_exists($old_table)) {
            $old_rows = $DB->get_records_select(
                'local_ref_marketers',
                'userid IS NOT NULL',
                [],
                '',
                'id, userid, code, commission_percentage, parent_id, timecreated, timemodified'
            );
            $id_to_userid = [];
            foreach ($old_rows as $r) {
                $id_to_userid[$r->id] = (int)$r->userid;
            }
            foreach ($old_rows as $r) {
                if ($DB->record_exists('local_ref_marketer_profile', ['userid' => $r->userid])) {
                    continue;
                }
                $parent_userid = (!empty($r->parent_id) && isset($id_to_userid[$r->parent_id]))
                    ? $id_to_userid[$r->parent_id] : null;
                $now = time();
                $DB->insert_record('local_ref_marketer_profile', (object)[
                    'userid'                => (int)$r->userid,
                    'code'                  => $r->code,
                    'commission_percentage' => (int)$r->commission_percentage,
                    'parent_userid'         => $parent_userid,
                    'timecreated'           => !empty($r->timecreated)  ? (int)$r->timecreated  : $now,
                    'timemodified'          => !empty($r->timemodified) ? (int)$r->timemodified : $now,
                ]);
            }
            foreach ($id_to_userid as $old_id => $uid) {
                foreach (['local_ref_users', 'local_ref_commissions', 'local_ref_withdrawals'] as $tbl) {
                    if ($dbman->table_exists(new xmldb_table($tbl))) {
                        $DB->execute(
                            "UPDATE {{$tbl}} SET marketerid = :uid WHERE marketerid = :old_id",
                            ['uid' => $uid, 'old_id' => $old_id]
                        );
                    }
                }
            }
            $dbman->drop_table($old_table);
        }

        upgrade_plugin_savepoint(true, 2026051001, 'local', 'referral');
    }

    // =========================================================================
    // 2026051100 — Add marketer type system and withdrawal routing fields.
    // =========================================================================
    if ($oldversion < 2026051100) {

        // --- local_ref_marketer_profile: add 'type' field ---
        $profile_tbl = new xmldb_table('local_ref_marketer_profile');

        if (!$dbman->field_exists($profile_tbl, new xmldb_field('type'))) {
            $type_field = new xmldb_field('type', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'main', 'parent_userid');
            $dbman->add_field($profile_tbl, $type_field);
        }

        // Backfill type from existing parent_userid data (idempotent).
        // Sub-marketer = has a parent_userid set; main = no parent_userid.
        $DB->execute(
            "UPDATE {local_ref_marketer_profile}
                SET type = 'sub'
              WHERE parent_userid IS NOT NULL AND parent_userid > 0"
        );
        $DB->execute(
            "UPDATE {local_ref_marketer_profile}
                SET type = 'main'
              WHERE (parent_userid IS NULL OR parent_userid = 0)"
        );

        // Add type index if missing.
        $type_index = new xmldb_index('type_idx', XMLDB_INDEX_NOTUNIQUE, ['type']);
        if (!$dbman->index_exists($profile_tbl, $type_index)) {
            $dbman->add_index($profile_tbl, $type_index);
        }

        // Add parent_userid index if missing.
        $parent_index = new xmldb_index('parent_idx', XMLDB_INDEX_NOTUNIQUE, ['parent_userid']);
        if (!$dbman->index_exists($profile_tbl, $parent_index)) {
            $dbman->add_index($profile_tbl, $parent_index);
        }

        // --- local_ref_withdrawals: add routing + audit fields ---
        $wr_tbl = new xmldb_table('local_ref_withdrawals');

        if (!$dbman->field_exists($wr_tbl, new xmldb_field('mainmarketerid'))) {
            $f = new xmldb_field('mainmarketerid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'marketerid');
            $dbman->add_field($wr_tbl, $f);
        }

        if (!$dbman->field_exists($wr_tbl, new xmldb_field('createdby'))) {
            $f = new xmldb_field('createdby', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'receipt_file');
            $dbman->add_field($wr_tbl, $f);
        }

        if (!$dbman->field_exists($wr_tbl, new xmldb_field('approvedby'))) {
            $f = new xmldb_field('approvedby', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'createdby');
            $dbman->add_field($wr_tbl, $f);
        }

        // Backfill createdby = marketerid for existing rows.
        $DB->execute(
            "UPDATE {local_ref_withdrawals} SET createdby = marketerid WHERE createdby = 0 OR createdby IS NULL"
        );

        // Backfill mainmarketerid: for sub-marketers set their parent; for mains set 0.
        // Use a PHP loop instead of JOIN-UPDATE for cross-DB compatibility.
        $wr_rows = $DB->get_records_select('local_ref_withdrawals', 'mainmarketerid IS NULL', [], '', 'id, marketerid');
        foreach ($wr_rows as $wr) {
            $mp = $DB->get_record('local_ref_marketer_profile', ['userid' => $wr->marketerid], 'parent_userid', IGNORE_MISSING);
            $main = ($mp && !empty($mp->parent_userid)) ? (int)$mp->parent_userid : 0;
            $DB->set_field('local_ref_withdrawals', 'mainmarketerid', $main, ['id' => $wr->id]);
        }

        // Add mainmarketerid index if missing.
        $mm_index = new xmldb_index('mainmarketer_idx', XMLDB_INDEX_NOTUNIQUE, ['mainmarketerid']);
        if (!$dbman->index_exists($wr_tbl, $mm_index)) {
            $dbman->add_index($wr_tbl, $mm_index);
        }

        upgrade_plugin_savepoint(true, 2026051100, 'local', 'referral');
    }

    return true;
}
