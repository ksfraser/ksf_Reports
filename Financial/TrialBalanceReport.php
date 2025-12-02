<?php

namespace FA\Modules\Reports\Financial;

use FA\Core\DBALInterface;

class TrialBalanceReport
{
    private DBALInterface $db;

    public function __construct(DBALInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Generate Trial Balance Report
     */
    public function generate(array $parameters, int $page = 1, int $perPage = 100): array
    {
        $dateFrom = $parameters['date_from'];
        $dateTo = $parameters['date_to'];
        $dimension = $parameters['dimension'] ?? null;

        // Build query for account balances
        $sql = "SELECT 
                    ca.account_code,
                    ca.account_name,
                    ca.account_code2,
                    cat.class_name,
                    cat.balance_sheet,
                    COALESCE(SUM(CASE WHEN gl.amount > 0 THEN gl.amount ELSE 0 END), 0) as total_debit,
                    COALESCE(SUM(CASE WHEN gl.amount < 0 THEN ABS(gl.amount) ELSE 0 END), 0) as total_credit,
                    COALESCE(SUM(gl.amount), 0) as net_balance
                FROM chart_master ca
                LEFT JOIN chart_types cat ON ca.account_type = cat.id
                LEFT JOIN gl_trans gl ON ca.account_code = gl.account 
                    AND gl.tran_date BETWEEN ? AND ?";

        $params = [$dateFrom, $dateTo];

        if ($dimension !== null) {
            $sql .= " AND gl.dimension_id = ?";
            $params[] = $dimension;
        }

        $sql .= " GROUP BY ca.account_code
                  HAVING total_debit != 0 OR total_credit != 0
                  ORDER BY ca.account_code";

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM ({$sql}) as count_query";
        $countResult = $this->db->query($countSql, $params);
        $totalRows = (int)$countResult[0]['total'];

        // Add pagination
        $offset = ($page - 1) * $perPage;
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        // Execute query
        $results = $this->db->query($sql, $params);

        // Calculate summary totals
        $summarySql = "SELECT 
                        SUM(total_debit) as grand_total_debit,
                        SUM(total_credit) as grand_total_credit,
                        SUM(net_balance) as grand_net_balance
                      FROM ({$sql}) as summary";
        $summaryResult = $this->db->query($summarySql, array_slice($params, 0, -2));

        $grandTotalDebit = $summaryResult[0]['grand_total_debit'] ?? 0;
        $grandTotalCredit = $summaryResult[0]['grand_total_credit'] ?? 0;
        $difference = abs($grandTotalDebit - $grandTotalCredit);

        return [
            'data' => $results,
            'columns' => [
                ['field' => 'account_code', 'label' => 'Account Code', 'type' => 'string'],
                ['field' => 'account_name', 'label' => 'Account Name', 'type' => 'string'],
                ['field' => 'class_name', 'label' => 'Account Type', 'type' => 'string'],
                ['field' => 'total_debit', 'label' => 'Debit', 'type' => 'currency'],
                ['field' => 'total_credit', 'label' => 'Credit', 'type' => 'currency'],
                ['field' => 'net_balance', 'label' => 'Balance', 'type' => 'currency'],
            ],
            'total_rows' => $totalRows,
            'summary' => [
                'Total Debits' => number_format($grandTotalDebit, 2),
                'Total Credits' => number_format($grandTotalCredit, 2),
                'Difference' => number_format($difference, 2),
                'Balanced' => $difference < 0.01 ? 'Yes' : 'No',
                'Period' => $dateFrom . ' to ' . $dateTo,
            ]
        ];
    }
}
