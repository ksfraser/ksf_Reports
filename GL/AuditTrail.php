<?php

declare(strict_types=1);

namespace FA\Modules\Reports\GL;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use Psr\Log\LoggerInterface;

/**
 * Audit Trail Report (rep710)
 * 
 * Shows audit trail of all transactions with user activity.
 * Displays transaction date, time, user, type, reference, and action.
 * Can filter by transaction type and user.
 * 
 * @package FA\Modules\Reports\GL
 */
class AuditTrail extends AbstractReportService
{
    public function __construct(
        DBALInterface $dbal,
        EventDispatcher $dispatcher,
        LoggerInterface $logger
    ) {
        parent::__construct(
            $dbal,
            $dispatcher,
            $logger,
            'Audit Trail',
            'audit_trail'
        );
    }

    /**
     * Fetch audit trail data
     */
    protected function fetchData(ReportConfig $config): array
    {
        $transType = $config->getAdditionalParam('trans_type', -1);
        $userId = $config->getAdditionalParam('user_id', -1);
        
        $transactions = $this->fetchAuditTransactions($config, $transType, $userId);
        
        // Get user information for display
        $userInfo = null;
        if ($userId != -1) {
            $userInfo = $this->getUserInfo($userId);
        }
        
        return [
            'transactions' => $transactions,
            'trans_type' => $transType,
            'user_id' => $userId,
            'user_info' => $userInfo
        ];
    }

    /**
     * Process data
     */
    protected function processData(array $rawData, ReportConfig $config): array
    {
        $totalAmount = 0.0;
        $transType = $rawData['trans_type'];
        
        // Calculate total only if filtering by specific transaction type
        if ($transType != -1) {
            foreach ($rawData['transactions'] as $trans) {
                if ($trans['amount'] !== null) {
                    $totalAmount += $trans['amount'];
                }
            }
        }
        
        $rawData['total_amount'] = $totalAmount;
        $rawData['show_total'] = ($transType != -1);
        
        return $rawData;
    }

    /**
     * Format data for output
     */
    protected function formatData(array $processedData, ReportConfig $config): array
    {
        return [
            'transactions' => $processedData['transactions'],
            'total_amount' => $processedData['total_amount'],
            'show_total' => $processedData['show_total'],
            'trans_count' => count($processedData['transactions'])
        ];
    }

    /**
     * Fetch audit trail transactions
     */
    private function fetchAuditTransactions(
        ReportConfig $config,
        int $transType,
        int $userId
    ): array {
        $fromDateSql = \DateService::date2sqlStatic($config->getFromDate()) . ' 00:00:00';
        $toDateSql = \DateService::date2sqlStatic($config->getToDate()) . ' 23:59:59';
        
        $sql = "SELECT a.*, 
                SUM(IF(ISNULL(g.amount), NULL, IF(g.amount > 0, g.amount, 0))) AS amount,
                u.user_id,
                UNIX_TIMESTAMP(a.stamp) as unix_stamp
            FROM " . TB_PREF . "audit_trail AS a 
            JOIN " . TB_PREF . "users AS u ON a.user = u.id
            LEFT JOIN " . TB_PREF . "gl_trans AS g 
                ON (g.type_no = a.trans_no AND g.type = a.type)
            WHERE a.stamp >= :from_date 
            AND a.stamp <= :to_date";
        
        $params = [
            'from_date' => $fromDateSql,
            'to_date' => $toDateSql
        ];
        
        if ($transType != -1) {
            $sql .= " AND a.type = :trans_type";
            $params['trans_type'] = $transType;
        }
        
        if ($userId != -1) {
            $sql .= " AND a.user = :user_id";
            $params['user_id'] = $userId;
        }
        
        $sql .= " GROUP BY a.trans_no, a.gl_seq, a.stamp 
                  ORDER BY a.stamp, a.gl_seq";
        
        return $this->dbal->fetchAll($sql, $params);
    }

    /**
     * Get user information
     */
    private function getUserInfo(int $userId): ?array
    {
        $sql = "SELECT id, user_id, real_name 
                FROM " . TB_PREF . "users 
                WHERE id = :user_id";
        
        return $this->dbal->fetchOne($sql, ['user_id' => $userId]);
    }

    /**
     * Get column definitions
     */
    protected function getColumns(ReportConfig $config): array
    {
        return [0, 60, 120, 180, 240, 340, 400, 460, 520];
    }

    /**
     * Get header labels
     */
    protected function getHeaders(ReportConfig $config): array
    {
        return [
            _('Date'),
            _('Time'),
            _('User'),
            _('Trans Date'),
            _('Type'),
            _('#'),
            _('Action'),
            _('Amount')
        ];
    }

    /**
     * Get column alignments
     */
    protected function getAligns(ReportConfig $config): array
    {
        return ['left', 'left', 'left', 'left', 'left', 'left', 'left', 'right'];
    }
}
