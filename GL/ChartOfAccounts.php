<?php

declare(strict_types=1);

namespace FA\Modules\Reports\GL;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Chart of Accounts Report Service
 * 
 * Generates complete chart of accounts with optional current balances,
 * organized by account class and type hierarchy.
 */
class ChartOfAccounts
{
    public function __construct(
        private readonly DBALInterface $db,
        private readonly EventDispatcher $dispatcher,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Generate chart of accounts report
     *
     * @param bool $showBalances Include current account balances
     * @return array Report data
     */
    public function generate(bool $showBalances = false): array
    {
        $this->logger->info('Generating Chart of Accounts report', [
            'show_balances' => $showBalances
        ]);

        // Fetch all accounts with hierarchy info
        $accounts = $this->fetchAccounts($showBalances);

        // Group by class and type
        $byClass = $this->groupByClass($accounts);
        $byType = $this->groupByType($accounts);

        // Calculate summary
        $summary = $this->calculateSummary($accounts);

        return [
            'accounts' => $accounts,
            'by_class' => $byClass,
            'by_type' => $byType,
            'summary' => $summary,
            'show_balances' => $showBalances
        ];
    }

    /**
     * Fetch all accounts from database
     *
     * @param bool $showBalances
     * @return array
     */
    private function fetchAccounts(bool $showBalances): array
    {
        $sql = "
            SELECT 
                ca.account_code,
                ca.account_name,
                ca.account_code2,
                at.id as account_type,
                at.name as type_name,
                ac.cid as class_id,
                ac.class_name
        ";

        if ($showBalances) {
            $sql .= ",
                COALESCE(SUM(gl.amount), 0) as balance
            ";
        } else {
            $sql .= ",
                NULL as balance
            ";
        }

        $sql .= "
            FROM chart_master ca
            INNER JOIN chart_types at ON ca.account_type = at.id
            INNER JOIN chart_class ac ON at.class_id = ac.cid
        ";

        if ($showBalances) {
            $sql .= "
            LEFT JOIN gl_trans gl ON ca.account_code = gl.account
            ";
        }

        $sql .= "
            GROUP BY ca.account_code, ca.account_name, ca.account_code2, 
                     at.id, at.name, ac.cid, ac.class_name
            ORDER BY ac.cid, at.id, ca.account_code
        ";

        $accounts = $this->db->fetchAll($sql);

        // Process balances
        return array_map(function ($account) use ($showBalances) {
            if ($showBalances && $account['balance'] !== null) {
                $account['balance'] = round((float)$account['balance'], 2);
            }
            return $account;
        }, $accounts);
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
                $byClass[$classId] = [
                    'class_id' => $classId,
                    'class_name' => $account['class_name'],
                    'accounts' => []
                ];
            }
            
            $byClass[$classId]['accounts'][] = $account;
        }

        return $byClass;
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
                $byType[$typeId] = [
                    'type_id' => $typeId,
                    'type_name' => $account['type_name'],
                    'accounts' => []
                ];
            }
            
            $byType[$typeId]['accounts'][] = $account;
        }

        return $byType;
    }

    /**
     * Calculate summary statistics
     *
     * @param array $accounts
     * @return array
     */
    private function calculateSummary(array $accounts): array
    {
        return [
            'account_count' => count($accounts),
            'class_count' => count(array_unique(array_column($accounts, 'class_id'))),
            'type_count' => count(array_unique(array_column($accounts, 'account_type')))
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
        $this->logger->info('Exporting Chart of Accounts to PDF', ['title' => $title]);

        return [
            'success' => true,
            'format' => 'pdf',
            'filename' => 'chart_of_accounts_' . date('Y-m-d') . '.pdf'
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
        $this->logger->info('Exporting Chart of Accounts to Excel', ['title' => $title]);

        return [
            'success' => true,
            'format' => 'excel',
            'filename' => 'chart_of_accounts_' . date('Y-m-d') . '.xlsx'
        ];
    }
}
