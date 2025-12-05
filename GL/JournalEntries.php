<?php

declare(strict_types=1);

namespace FA\Modules\Reports\GL;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Journal Entries Report Service
 * 
 * Generates detailed list of journal entries with transaction grouping,
 * debit/credit calculations, and dimension tracking.
 */
class JournalEntries
{
    public function __construct(
        private readonly DBALInterface $db,
        private readonly EventDispatcher $dispatcher,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Generate journal entries report
     *
     * @param string $startDate Start date (Y-m-d format)
     * @param string $endDate End date (Y-m-d format)
     * @param int|null $systemType Filter by system type (null for all)
     * @return array Report data with entries and summary
     */
    public function generate(string $startDate, string $endDate, ?int $systemType = null): array
    {
        $this->logger->info('Generating Journal Entries report', [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'system_type' => $systemType
        ]);

        // Fetch GL transactions
        $transactions = $this->fetchGLTransactions($startDate, $endDate, $systemType);

        // Group by transaction
        $entries = $this->groupByTransaction($transactions);

        // Calculate summary
        $summary = $this->calculateSummary($entries);

        return [
            'entries' => $entries,
            'summary' => $summary,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'filters' => [
                'system_type' => $systemType
            ]
        ];
    }

    /**
     * Fetch GL transactions from database
     *
     * @param string $startDate
     * @param string $endDate
     * @param int|null $systemType
     * @return array
     */
    private function fetchGLTransactions(string $startDate, string $endDate, ?int $systemType): array
    {
        $sql = "
            SELECT 
                gl.type,
                gl.type_no,
                gl.tran_date,
                gl.account,
                ca.account_name,
                gl.amount,
                gl.person_id,
                gl.dimension_id,
                gl.dimension2_id,
                gl.memo_
            FROM gl_trans gl
            LEFT JOIN chart_master ca ON gl.account = ca.account_code
            WHERE gl.tran_date >= :start_date
                AND gl.tran_date <= :end_date
                AND gl.amount != 0
        ";

        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];

        if ($systemType !== null) {
            $sql .= " AND gl.type = :system_type";
            $params['system_type'] = $systemType;
        }

        $sql .= " ORDER BY gl.type, gl.type_no, gl.tran_date, gl.counter";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Group transactions by type and type_no
     *
     * @param array $transactions
     * @return array
     */
    private function groupByTransaction(array $transactions): array
    {
        $entries = [];
        $currentEntry = null;

        foreach ($transactions as $row) {
            $key = $row['type'] . '-' . $row['type_no'];

            // Start new entry if type/type_no changed
            if ($currentEntry === null || $currentEntry['key'] !== $key) {
                // Save previous entry
                if ($currentEntry !== null) {
                    $currentEntry = $this->finalizeEntry($currentEntry);
                    unset($currentEntry['key']);
                    $entries[] = $currentEntry;
                }

                // Start new entry
                $currentEntry = [
                    'key' => $key,
                    'type' => (int)$row['type'],
                    'type_no' => (int)$row['type_no'],
                    'tran_date' => $row['tran_date'],
                    'lines' => [],
                    'total_debit' => 0.0,
                    'total_credit' => 0.0
                ];
            }

            // Add line to current entry
            $amount = (float)$row['amount'];
            $currentEntry['lines'][] = [
                'account' => $row['account'],
                'account_name' => $row['account_name'] ?? '',
                'amount' => $amount,
                'debit' => $amount > 0 ? $amount : 0.0,
                'credit' => $amount < 0 ? abs($amount) : 0.0,
                'person_id' => $row['person_id'],
                'dimension_id' => (int)($row['dimension_id'] ?? 0),
                'dimension2_id' => (int)($row['dimension2_id'] ?? 0),
                'memo_' => $row['memo_'] ?? ''
            ];

            // Update totals
            if ($amount > 0) {
                $currentEntry['total_debit'] += $amount;
            } else {
                $currentEntry['total_credit'] += abs($amount);
            }
        }

        // Don't forget last entry
        if ($currentEntry !== null) {
            $currentEntry = $this->finalizeEntry($currentEntry);
            unset($currentEntry['key']);
            $entries[] = $currentEntry;
        }

        return $entries;
    }

    /**
     * Finalize entry with calculated fields
     *
     * @param array $entry
     * @return array
     */
    private function finalizeEntry(array $entry): array
    {
        // Check if balanced
        $diff = abs($entry['total_debit'] - $entry['total_credit']);
        $entry['is_balanced'] = $diff < 0.01; // Allow for rounding

        // Round totals
        $entry['total_debit'] = round($entry['total_debit'], 2);
        $entry['total_credit'] = round($entry['total_credit'], 2);

        return $entry;
    }

    /**
     * Calculate summary statistics
     *
     * @param array $entries
     * @return array
     */
    private function calculateSummary(array $entries): array
    {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $transactionCount = count($entries);
        $unbalancedCount = 0;

        foreach ($entries as $entry) {
            $totalDebit += $entry['total_debit'];
            $totalCredit += $entry['total_credit'];
            
            if (!$entry['is_balanced']) {
                $unbalancedCount++;
            }
        }

        return [
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'transaction_count' => $transactionCount,
            'unbalanced_count' => $unbalancedCount,
            'is_balanced' => abs($totalDebit - $totalCredit) < 0.01
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
        $this->logger->info('Exporting Journal Entries to PDF', ['title' => $title]);

        // Placeholder for PDF generation
        return [
            'success' => true,
            'format' => 'pdf',
            'filename' => 'journal_entries_' . date('Y-m-d') . '.pdf'
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
        $this->logger->info('Exporting Journal Entries to Excel', ['title' => $title]);

        // Placeholder for Excel generation
        return [
            'success' => true,
            'format' => 'excel',
            'filename' => 'journal_entries_' . date('Y-m-d') . '.xlsx'
        ];
    }
}
