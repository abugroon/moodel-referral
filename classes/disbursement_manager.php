<?php
// /local/referral/classes/disbursement_manager.php
namespace local_referral;

defined('MOODLE_INTERNAL') || die();

class disbursement_manager
{
    /**
     * Sum of paid marketing commissions (status=2) in the given timestamp range.
     */
    public static function ensure_table(): void
    {
        global $DB;
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('local_ref_disbursements')) {
            return;
        }
        $table = new \xmldb_table('local_ref_disbursements');
        $table->add_field('id',             XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('category',       XMLDB_TYPE_CHAR,    '64',   null, XMLDB_NOTNULL, null,           null);
        $table->add_field('recipient_name', XMLDB_TYPE_CHAR,    '255',  null, XMLDB_NOTNULL, null,           null);
        $table->add_field('amount',         XMLDB_TYPE_NUMBER,  '10,2', null, XMLDB_NOTNULL, null,           '0.00');
        $table->add_field('period_start',   XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);
        $table->add_field('period_end',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);
        $table->add_field('receipt_file',   XMLDB_TYPE_CHAR,    '255',  null, null,           null,           null);
        $table->add_field('notes',          XMLDB_TYPE_TEXT,    null,   null, null,           null,           null);
        $table->add_field('created_by',     XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);
        $table->add_field('timecreated',    XMLDB_TYPE_INTEGER, '10',   null, XMLDB_NOTNULL, null,           null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('category_idx',   XMLDB_INDEX_NOTUNIQUE, ['category']);
        $table->add_index('period_idx',     XMLDB_INDEX_NOTUNIQUE, ['period_start', 'period_end']);
        $table->add_index('created_by_idx', XMLDB_INDEX_NOTUNIQUE, ['created_by']);
        $dbman->create_table($table);
    }

    public static function get_marketing_paid(int $start, int $end): float
    {
        global $DB;
        $sql = "SELECT COALESCE(SUM(commission), 0)
                  FROM {local_ref_commissions}
                 WHERE status = 2
                   AND timecreated >= :start
                   AND timecreated <= :end";
        return (float)$DB->get_field_sql($sql, ['start' => $start, 'end' => $end]);
    }

    /**
     * Sum of paid teacher transactions (status='paid') in the given timestamp range.
     * Returns 0 silently if local_tc_transactions does not exist.
     */
    public static function get_teacher_paid(int $start, int $end): float
    {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_tc_transactions')) {
            return 0.0;
        }
        $sql = "SELECT COALESCE(SUM(commissionamount), 0)
                  FROM {local_tc_transactions}
                 WHERE status = 'paid'
                   AND timecreated >= :start
                   AND timecreated <= :end";
        return (float)$DB->get_field_sql($sql, ['start' => $start, 'end' => $end]);
    }

    /**
     * Sum of recorded disbursements for a manual category whose period falls
     * within [start, end].
     */
    public static function get_disbursed_total(string $category, int $start, int $end): float
    {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_ref_disbursements')) {
            return 0.0;
        }
        $sql = "SELECT COALESCE(SUM(amount), 0)
                  FROM {local_ref_disbursements}
                 WHERE category    = :category
                   AND period_start >= :start
                   AND period_end   <= :end";
        return (float)$DB->get_field_sql($sql, [
            'category' => $category,
            'start'    => $start,
            'end'      => $end,
        ]);
    }

    /**
     * Insert a disbursement record. File handling is done by the caller.
     * Returns the new record id.
     */
    public static function save(array $data): int
    {
        global $DB, $USER;
        return (int)$DB->insert_record('local_ref_disbursements', (object)[
            'category'       => $data['category'],
            'recipient_name' => $data['recipient_name'],
            'amount'         => $data['amount'],
            'period_start'   => $data['period_start'],
            'period_end'     => $data['period_end'],
            'receipt_file'   => '',
            'notes'          => $data['notes'],
            'created_by'     => $USER->id,
            'timecreated'    => time(),
        ]);
    }

    /**
     * Update the stored filename after the file has been saved via File API.
     */
    public static function update_receipt_file(int $id, string $filename): void
    {
        global $DB;
        $DB->set_field('local_ref_disbursements', 'receipt_file', $filename, ['id' => $id]);
    }

    /**
     * Disbursement history rows recorded during [start, end], ordered by
     * category then date descending.
     */
    public static function get_history(int $start, int $end): array
    {
        global $DB;
        if (!$DB->get_manager()->table_exists('local_ref_disbursements')) {
            return [];
        }
        $sql = "SELECT d.id, d.category, d.recipient_name, d.amount,
                       d.period_start, d.period_end, d.receipt_file,
                       d.notes, d.timecreated,
                       u.firstname, u.lastname
                  FROM {local_ref_disbursements} d
                  JOIN {user} u ON u.id = d.created_by
                 WHERE d.timecreated >= :start
                   AND d.timecreated <= :end
                 ORDER BY d.category ASC, d.timecreated DESC";
        return $DB->get_records_sql($sql, ['start' => $start, 'end' => $end]);
    }
}
