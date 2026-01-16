<?php

declare(strict_types=1);

namespace FA\Modules\Reports\GL;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use Psr\Log\LoggerInterface;

/**
 * GL Account Transactions Report (rep704)
 * 
 * Lists all transactions for specified GL accounts with running balance.
 * Shows transaction type, reference, date, dimensions, person/item, debit/credit, and balance.
 * Includes opening balance (for P&L accounts, from fiscal year begin).
 * 
 * @package FA\Modules\Reports\GL
 */
class GLAccountTransactions extends AbstractReportService
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
            'GL Account Transactions',
            'gl_account_transactions'
        );
    }

    /**
     * Generate GL account transactions report
     * 
     * @param string $fromAccount Starting account code
     * @param string $toAccount Ending account code
     * @param ReportConfig $config Report configuration
     * @return array Report data with accounts, transactions, balances
     */
    public function generateForAccounts(string $fromAccount, string $toAccount, ReportConfig $config): array
    {
        $newConfig = new ReportConfig(
            fromDate: $config->getFromDate(),
            toDate: $config->getToDate(),
            dimension1: $config->getDimension1(),
            dimension2: $config->getDimension2(),
            exportToExcel: $config->shouldExportToExcel(),
            landscapeOrientation: $config->isLandscapeOrientation(),
            decimals: $config->getDecimals(),
            pageSize: $config->getPageSize(),
            comments: $config->getComments(),
            currency: $config->getCurrency(),
            convertCurrency: $config->shouldConvertCurrency(),
            suppressZeros: $config->shouldSuppressZeros(),
            additionalParams: $config->getAllAdditionalParams() + [
                'from_account' => $fromAccount,
                'to_account' => $toAccount
            ]
        );
        
        return $this->generate($newConfig);
    }

    /**
     * Fetch account transactions from database
     */
    protected function fetchData(ReportConfig $config): array
    {
        $fromAccount = $config->getAdditionalParam('from_account');
        $toAccount = $config->getAdditionalParam('to_account');

        // Get accounts in range
        $accounts = $this->fetchAccounts($fromAccount, $toAccount);
        
        // For each account, get opening balance and transactions
        $results = [];
        foreach ($accounts as $account) {
            $accountData = [
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'is_balance_sheet' => $this->isBalanceSheetAccount($account['account_code']),
                'opening_balance' => 0.0,
                'transactions' => [],
                'closing_balance' => 0.0
            ];

            // Calculate opening balance
            if (!$accountData['is_balance_sheet']) {
                $beginDate = $this->getFiscalYearBegin($config->getFromDate());
                $accountData['opening_balance'] = $this->getBalanceFromTo(
                    $beginDate,
                    $config->getFromDate(),
                    $account['account_code'],
                    $config
                );
            }

            // Fetch transactions
            $accountData['transactions'] = $this->fetchTransactions(
                $config->getFromDate(),
                $config->getToDate(),
                $account['account_code'],
                $config
            );

            // Calculate closing balance
            $accountData['closing_balance'] = $accountData['opening_balance'] + 
                array_sum(array_column($accountData['transactions'], 'amount'));

            // Skip accounts with no activity
            if ($accountData['opening_balance'] == 0.0 && empty($accountData['transactions'])) {
                continue;
            }

            $results[] = $accountData;
        }

        return $results;
    }

    /**
     * Process data to add running balance
     */
    protected function processData(array $rawData, ReportConfig $config): array
    {
        foreach ($rawData as &$account) {
            $runningBalance = $account['opening_balance'];
            
            foreach ($account['transactions'] as &$transaction) {
                $runningBalance += $transaction['amount'];
                $transaction['balance'] = $runningBalance;
            }
        }

        return $rawData;
    }

    /**
     * Format data for output
     */
    protected function formatData(array $processedData, ReportConfig $config): array
    {
        return [
            'accounts' => $processedData,
            'summary' => [
                'account_count' => count($processedData),
                'total_transactions' => array_sum(array_map(
                    fn($a) => count($a['transactions']),
                    $processedData
                ))
            ]
        ];
    }

    /**
     * Fetch accounts in range
     */
    private function fetchAccounts(string $fromAccount, string $toAccount): array
    {
        $sql = "SELECT account_code, account_name 
                FROM " . TB_PREF . "chart_master 
                WHERE account_code >= :from_account 
                AND account_code <= :to_account 
                ORDER BY account_code";

        return $this->dbal->fetchAll($sql, [
            'from_account' => $fromAccount,
            'to_account' => $toAccount
        ]);
    }

    /**
     * Fetch transactions for an account
     */
    private function fetchTransactions(
        string $fromDate,
        string $toDate,
        string $accountCode,
        ReportConfig $config
    ): array {
        $fromDateSql = \DateService::date2sqlStatic($fromDate);
        $toDateSql = \DateService::date2sqlStatic($toDate);

        $sql = "SELECT 
                    gl.type,
                    gl.type_no,
                    gl.tran_date,
                    gl.amount,
                    gl.dimension_id,
                    gl.dimension2_id,
                    gl.person_type_id,
                    gl.person_id,
                    gl.memo_
                FROM " . TB_PREF . "gl_trans gl
                WHERE gl.account = :account_code
                AND gl.tran_date >= :from_date
                AND gl.tran_date <= :to_date";

        // Add dimension filters
        $sql .= $this->buildDimensionFilter($config);

        $sql .= " ORDER BY gl.tran_date, gl.counter";

        return $this->dbal->fetchAll($sql, [
            'account_code' => $accountCode,
            'from_date' => $fromDateSql,
            'to_date' => $toDateSql
        ]);
    }

    /**
     * Get balance for account in date range
     */
    private function getBalanceFromTo(
        string $fromDate,
        string $toDate,
        string $accountCode,
        ReportConfig $config
    ): float {
        $fromDateSql = \DateService::date2sqlStatic($fromDate);
        $toDateSql = \DateService::date2sqlStatic($toDate);

        $sql = "SELECT COALESCE(SUM(amount), 0) as balance
                FROM " . TB_PREF . "gl_trans
                WHERE account = :account_code
                AND tran_date > :from_date
                AND tran_date < :to_date";

        $sql .= $this->buildDimensionFilter($config);

        $result = $this->dbal->fetchOne($sql, [
            'account_code' => $accountCode,
            'from_date' => $fromDateSql,
            'to_date' => $toDateSql
        ]);

        return (float)($result['balance'] ?? 0.0);
    }

    /**
     * Get fiscal year begin date for given date
     */
    private function getFiscalYearBegin(string $date): string
    {
        $beginDate = get_fiscalyear_begin_for_date($date);
        
        // If fiscal year begin is after from date, use from date
        if (\DateService::date1GreaterDate2Static($beginDate, $date)) {
            $beginDate = $date;
        }
        
        // Subtract one day to get "up to but not including" from date
        return \DateService::addDaysStatic($beginDate, -1);
    }

    /**
     * Check if account is a balance sheet account
     */
    private function isBalanceSheetAccount(string $accountCode): bool
    {
        return is_account_balancesheet($accountCode);
    }

    /**
     * Get column definitions
     */
    protected function getColumns(ReportConfig $config): array
    {
        $dimCount = \FA\Services\CompanyPrefsService::getUseDimensions();

        if ($dimCount == 2) {
            return [0, 65, 105, 125, 175, 230, 290, 345, 405, 465, 525];
        } elseif ($dimCount == 1) {
            return [0, 65, 105, 125, 175, 260, 260, 345, 405, 465, 525];
        } else {
            return [0, 65, 105, 125, 175, 175, 175, 345, 405, 465, 525];
        }
    }

    /**
     * Get header labels
     */
    protected function getHeaders(ReportConfig $config): array
    {
        $dimCount = \FA\Services\CompanyPrefsService::getUseDimensions();

        if ($dimCount == 2) {
            return [
                _('Type'), _('Ref'), _('#'), _('Date'),
                _('Dimension') . " 1", _('Dimension') . " 2",
                _('Person/Item'), _('Debit'), _('Credit'), _('Balance')
            ];
        } elseif ($dimCount == 1) {
            return [
                _('Type'), _('Ref'), _('#'), _('Date'),
                _('Dimension'), "",
                _('Person/Item'), _('Debit'), _('Credit'), _('Balance')
            ];
        } else {
            return [
                _('Type'), _('Ref'), _('#'), _('Date'),
                "", "",
                _('Person/Item'), _('Debit'), _('Credit'), _('Balance')
            ];
        }
    }

    /**
     * Get column alignments
     */
    protected function getAligns(ReportConfig $config): array
    {
        return ['left', 'left', 'left', 'left', 'left', 'left', 'left', 'right', 'right', 'right'];
    }
}
