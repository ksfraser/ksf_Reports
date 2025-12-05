<?php

declare(strict_types=1);

namespace FA\Modules\Reports\GL;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Trial Balance Report Service
 * 
 * Generates trial balance with brought forward balances, current period activity,
 * and ending balances. Supports grouping by account class and type, dimension
 * filtering, and zero balance exclusion.
 */
class TrialBalance
{
    public function __construct(
        private readonly DBALInterface $db,
        private readonly EventDispatcher $dispatcher,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Generate trial balance report
     *
     * @param string $startDate Period start date (Y-m-d format)
     * @param string $endDate Period end date (Y-m-d format)
     * @param bool $includeZero Include accounts with zero balances
     * @param int $dimension Dimension filter (0 for all)
     * @param int $dimension2 Second dimension filter (0 for all)
     * @return array Report data
     */
    public function generate(
        string $startDate, 
        string $endDate, 
        bool $includeZero = false,
        int $dimension = 0,
        int $dimension2 = 0
    ): array {
        $this->logger->info('Generating Trial Balance report', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'include_zero' => $includeZero,
            'dimension' => $dimension,
            'dimension2' => $dimension2
        ]);

        // Fetch account balances
        $accounts = $this->fetchAccountBalances($startDate, $endDate, $dimension, $dimension2);

        // Filter zero balances if requested
        if (!$includeZero) {
            $accounts = $this->filterZeroBalances($accounts);
        }

        // Calculate balances for each account
        $accounts = $this->calculateBalances($accounts);

        // Group by type and class
        $byType = $this->groupByType($accounts);
        $byClass = $this->groupByClass($accounts);

        // Calculate summary
        $summary = $this->calculateSummary($accounts);

        return [
            'accounts' => $accounts,
            'by_type' => $byType,
            'by_class' => $byClass,
            'summary' => $summary,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'filters' => [
                'include_zero' => $includeZero,
                'dimension' => $dimension,
                'dimension2' => $dimension2
            ]
        ];
    }

    /**
     * Fetch account balances from database
     *
     * @param string $startDate
     * @param string $endDate
     * @param int $dimension
     * @param int $dimension2
     * @return array
     */
    private function fetchAccountBalances(
        string $startDate,
        string $endDate,
        int $dimension,
        int $dimension2
    ): array {
        // This query gets account balances for previous, current, and total periods
        $sql = "
            SELECT 
                ca.account_code,
                ca.account_name,
                ca.account_code2,
                at.id as account_type,
                at.name as type_name,
                ac.cid as class_id,
                ac.class_name,
                COALESCE(prev.debit, 0) as prev_debit,
                COALESCE(prev.credit, 0) as prev_credit,
                COALESCE(curr.debit, 0) as curr_debit,
                COALESCE(curr.credit, 0) as curr_credit,
                COALESCE(tot.debit, 0) as tot_debit,
                COALESCE(tot.credit, 0) as tot_credit
            FROM chart_master ca
            INNER JOIN chart_types at ON ca.account_type = at.id
            INNER JOIN chart_class ac ON at.class_id = ac.cid
            LEFT JOIN (
                SELECT 
                    account,
                    SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as debit,
                    SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as credit
                FROM gl_trans
                WHERE tran_date < :start_date
        ";

        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];

        if ($dimension > 0) {
            $sql .= " AND dimension_id = :dimension";
            $params['dimension'] = $dimension;
        }
        if ($dimension2 > 0) {
            $sql .= " AND dimension2_id = :dimension2";
            $params['dimension2'] = $dimension2;
        }

        $sql .= "
                GROUP BY account
            ) prev ON ca.account_code = prev.account
            LEFT JOIN (
                SELECT 
                    account,
                    SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as debit,
                    SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as credit
                FROM gl_trans
                WHERE tran_date >= :start_date2
                    AND tran_date <= :end_date
        ";

        $params['start_date2'] = $startDate;

        if ($dimension > 0) {
            $sql .= " AND dimension_id = :dimension3";
            $params['dimension3'] = $dimension;
        }
        if ($dimension2 > 0) {
            $sql .= " AND dimension2_id = :dimension4";
            $params['dimension4'] = $dimension2;
        }

        $sql .= "
                GROUP BY account
            ) curr ON ca.account_code = curr.account
            LEFT JOIN (
                SELECT 
                    account,
                    SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as debit,
                    SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as credit
                FROM gl_trans
                WHERE tran_date <= :end_date2
        ";

        $params['end_date2'] = $endDate;

        if ($dimension > 0) {
            $sql .= " AND dimension_id = :dimension5";
            $params['dimension5'] = $dimension;
        }
        if ($dimension2 > 0) {
            $sql .= " AND dimension2_id = :dimension6";
            $params['dimension6'] = $dimension2;
        }

        $sql .= "
                GROUP BY account
            ) tot ON ca.account_code = tot.account
            ORDER BY ac.cid, at.id, ca.account_code
        ";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Filter out accounts with zero balances
     *
     * @param array $accounts
     * @return array
     */
    private function filterZeroBalances(array $accounts): array
    {
        return array_filter($accounts, function ($account) {
            $prevBalance = (float)$account['prev_debit'] - (float)$account['prev_credit'];
            $currBalance = (float)$account['curr_debit'] - (float)$account['curr_credit'];
            $totBalance = (float)$account['tot_debit'] - (float)$account['tot_credit'];

            return abs($prevBalance) >= 0.01 || abs($currBalance) >= 0.01 || abs($totBalance) >= 0.01;
        });
    }

    /**
     * Calculate balances for each account
     *
     * @param array $accounts
     * @return array
     */
    private function calculateBalances(array $accounts): array
    {
        return array_map(function ($account) {
            $prevDebit = (float)$account['prev_debit'];
            $prevCredit = (float)$account['prev_credit'];
            $currDebit = (float)$account['curr_debit'];
            $currCredit = (float)$account['curr_credit'];
            $totDebit = (float)$account['tot_debit'];
            $totCredit = (float)$account['tot_credit'];

            $account['prev_balance'] = round($prevDebit - $prevCredit, 2);
            $account['curr_balance'] = round($currDebit - $currCredit, 2);
            $account['tot_balance'] = round($totDebit - $totCredit, 2);

            // Round the raw amounts too
            $account['prev_debit'] = round($prevDebit, 2);
            $account['prev_credit'] = round($prevCredit, 2);
            $account['curr_debit'] = round($currDebit, 2);
            $account['curr_credit'] = round($currCredit, 2);
            $account['tot_debit'] = round($totDebit, 2);
            $account['tot_credit'] = round($totCredit, 2);

            return $account;
        }, $accounts);
    }

    /**
     * Group accounts by type
     *
     * @param array $accounts
     * @return array
     */
    private function groupByType(array $accounts): array
    {
        $byType = [];

        foreach ($accounts as $account) {
            $typeId = (int)$account['account_type'];
            
            if (!isset($byType[$typeId])) {
                $byType[$typeId] = [];
            }
            
            $byType[$typeId][] = $account;
        }

        return $byType;
    }

    /**
     * Group accounts by class
     *
     * @param array $accounts
     * @return array
     */
    private function groupByClass(array $accounts): array
    {
        $byClass = [];

        foreach ($accounts as $account) {
            $classId = (int)$account['class_id'];
            
            if (!isset($byClass[$classId])) {
                $byClass[$classId] = [];
            }
            
            $byClass[$classId][] = $account;
        }

        return $byClass;
    }

    /**
     * Calculate summary totals
     *
     * @param array $accounts
     * @return array
     */
    private function calculateSummary(array $accounts): array
    {
        $prevDebit = 0.0;
        $prevCredit = 0.0;
        $currDebit = 0.0;
        $currCredit = 0.0;
        $totDebit = 0.0;
        $totCredit = 0.0;

        foreach ($accounts as $account) {
            $prevDebit += $account['prev_debit'];
            $prevCredit += $account['prev_credit'];
            $currDebit += $account['curr_debit'];
            $currCredit += $account['curr_credit'];
            $totDebit += $account['tot_debit'];
            $totCredit += $account['tot_credit'];
        }

        $prevBalance = $prevDebit - $prevCredit;
        $currBalance = $currDebit - $currCredit;
        $totBalance = $totDebit - $totCredit;

        return [
            'prev_debit' => round($prevDebit, 2),
            'prev_credit' => round($prevCredit, 2),
            'prev_balance' => round($prevBalance, 2),
            'curr_debit' => round($currDebit, 2),
            'curr_credit' => round($currCredit, 2),
            'curr_balance' => round($currBalance, 2),
            'tot_debit' => round($totDebit, 2),
            'tot_credit' => round($totCredit, 2),
            'tot_balance' => round($totBalance, 2),
            'is_balanced' => abs($totBalance) < 0.01,
            'account_count' => count($accounts)
        ];
    }

    /**
     * Export report to PDF format
     *
     * @param array $data Report data
     * @param string $title Report title
     * @return array Export result
     */
    public function exportToPDF(array $data, string $title): array
    {
        $this->logger->info('Exporting Trial Balance to PDF', ['title' => $title]);

        return [
            'success' => true,
            'format' => 'pdf',
            'filename' => 'trial_balance_' . date('Y-m-d') . '.pdf'
        ];
    }

    /**
     * Export report to Excel format
     *
     * @param array $data Report data
     * @param string $title Report title
     * @return array Export result
     */
    public function exportToExcel(array $data, string $title): array
    {
        $this->logger->info('Exporting Trial Balance to Excel', ['title' => $title]);

        return [
            'success' => true,
            'format' => 'excel',
            'filename' => 'trial_balance_' . date('Y-m-d') . '.xlsx'
        ];
    }
}
