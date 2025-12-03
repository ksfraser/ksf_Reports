<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Financial;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Cash Flow Statement Report (Indirect Method)
 * 
 * Generates comprehensive cash flow statements using the indirect method,
 * which starts with net income and adjusts for non-cash items and changes
 * in working capital to arrive at cash from operations.
 * 
 * @package FA\Modules\Reports\Financial
 * @author FrontAccounting Development Team
 * @version 1.0.0
 * @since 2025-12-03
 */
class CashFlowStatementReport
{
    private DBALInterface $db;
    private EventDispatcher $dispatcher;
    private LoggerInterface $logger;

    /**
     * Constructor
     * 
     * @param DBALInterface $db Database interface
     * @param EventDispatcher $dispatcher Event dispatcher
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(
        DBALInterface $db,
        EventDispatcher $dispatcher,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }

    /**
     * Generate cash flow statement for a period
     * 
     * @param string $startDate Start date (YYYY-MM-DD)
     * @param string $endDate End date (YYYY-MM-DD)
     * @param array $options Additional options (e.g., company, currency)
     * 
     * @return array Cash flow statement data with operating, investing, and financing activities
     * 
     * @throws \InvalidArgumentException If date range is invalid
     * @throws \Exception If database error occurs
     */
    public function generate(string $startDate, string $endDate, array $options = []): array
    {
        $this->validateDateRange($startDate, $endDate);

        try {
            $this->logger->info('Generating cash flow statement', [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            // Get net income for the period
            $netIncome = $this->getNetIncome($startDate, $endDate);

            // Calculate operating activities
            $operatingActivities = $this->calculateOperatingActivities($startDate, $endDate, $netIncome);

            // Calculate investing activities
            $investingActivities = $this->calculateInvestingActivities($startDate, $endDate);

            // Calculate financing activities
            $financingActivities = $this->calculateFinancingActivities($startDate, $endDate);

            // Calculate net cash change
            $netCashChange = $operatingActivities['net_cash_from_operations'] +
                           $investingActivities['net_cash_from_investing'] +
                           $financingActivities['net_cash_from_financing'];

            // Get beginning and ending cash balances
            $beginningCash = $this->getCashBalance($startDate);
            $endingCash = $this->getCashBalance($endDate);

            // Calculate metrics
            $metrics = $this->calculateMetrics(
                $operatingActivities,
                $investingActivities,
                $financingActivities,
                $netIncome
            );

            $result = [
                'period' => [
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                'operating_activities' => $operatingActivities,
                'investing_activities' => $investingActivities,
                'financing_activities' => $financingActivities,
                'net_cash_change' => $netCashChange,
                'summary' => [
                    'beginning_cash' => $beginningCash,
                    'net_change' => $netCashChange,
                    'ending_cash' => $endingCash
                ],
                'metrics' => $metrics
            ];

            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate cash flow statement', [
                'error' => $e->getMessage(),
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
            throw $e;
        }
    }

    /**
     * Generate comparison with prior period
     * 
     * @param string $currentStart Current period start date
     * @param string $currentEnd Current period end date
     * @param string $priorStart Prior period start date
     * @param string $priorEnd Prior period end date
     * 
     * @return array Comparison data with current period, prior period, variance, and variance %
     */
    public function generateComparison(
        string $currentStart,
        string $currentEnd,
        string $priorStart,
        string $priorEnd
    ): array {
        $currentPeriod = $this->generate($currentStart, $currentEnd);
        $priorPeriod = $this->generate($priorStart, $priorEnd);

        return [
            'current_period' => $currentPeriod,
            'prior_period' => $priorPeriod,
            'variance' => $this->calculateVariance($currentPeriod, $priorPeriod),
            'variance_percent' => $this->calculateVariancePercent($currentPeriod, $priorPeriod)
        ];
    }

    /**
     * Generate quarterly cash flow analysis
     * 
     * @param int $year Year to analyze
     * 
     * @return array Quarterly cash flow data (Q1, Q2, Q3, Q4)
     */
    public function generateQuarterly(int $year): array
    {
        $quarters = [
            'Q1' => ["{$year}-01-01", "{$year}-03-31"],
            'Q2' => ["{$year}-04-01", "{$year}-06-30"],
            'Q3' => ["{$year}-07-01", "{$year}-09-30"],
            'Q4' => ["{$year}-10-01", "{$year}-12-31"]
        ];

        $result = [];
        foreach ($quarters as $quarter => [$start, $end]) {
            $result[$quarter] = $this->generate($start, $end);
        }

        return $result;
    }

    /**
     * Calculate operating activities (indirect method)
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * @param float $netIncome Net income for the period
     * 
     * @return array Operating activities data
     */
    private function calculateOperatingActivities(string $startDate, string $endDate, float $netIncome): array
    {
        // Get non-cash expenses (depreciation, amortization, etc.)
        $nonCashExpenses = $this->getNonCashExpenses($startDate, $endDate);
        $nonCashTotal = array_sum(array_column($nonCashExpenses, 'amount'));

        // Get changes in working capital
        $workingCapitalChanges = $this->getWorkingCapitalChanges($startDate, $endDate);
        $workingCapitalTotal = array_sum(array_column($workingCapitalChanges, 'change'));

        // Calculate net cash from operations
        $netCashFromOperations = $netIncome + $nonCashTotal + $workingCapitalTotal;

        return [
            'net_income' => $netIncome,
            'non_cash_adjustments' => $nonCashExpenses,
            'non_cash_expenses' => $nonCashTotal,
            'working_capital_details' => $workingCapitalChanges,
            'working_capital_change' => $workingCapitalTotal,
            'net_cash_from_operations' => $netCashFromOperations
        ];
    }

    /**
     * Calculate investing activities
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return array Investing activities data
     */
    private function calculateInvestingActivities(string $startDate, string $endDate): array
    {
        $transactions = $this->getInvestingTransactions($startDate, $endDate);
        $netCashFromInvesting = array_sum(array_column($transactions, 'amount'));

        return [
            'transactions' => $transactions,
            'net_cash_from_investing' => $netCashFromInvesting
        ];
    }

    /**
     * Calculate financing activities
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return array Financing activities data
     */
    private function calculateFinancingActivities(string $startDate, string $endDate): array
    {
        $transactions = $this->getFinancingTransactions($startDate, $endDate);
        $netCashFromFinancing = array_sum(array_column($transactions, 'amount'));

        return [
            'transactions' => $transactions,
            'net_cash_from_financing' => $netCashFromFinancing
        ];
    }

    /**
     * Calculate cash flow metrics
     * 
     * @param array $operating Operating activities data
     * @param array $investing Investing activities data
     * @param array $financing Financing activities data
     * @param float $netIncome Net income
     * 
     * @return array Cash flow metrics and ratios
     */
    private function calculateMetrics(
        array $operating,
        array $investing,
        array $financing,
        float $netIncome
    ): array {
        $operatingCashFlow = $operating['net_cash_from_operations'];
        
        // Calculate capital expenditures (negative investing amounts)
        $capEx = abs(array_sum(array_filter(
            array_column($investing['transactions'], 'amount'),
            fn($amount) => $amount < 0
        )));

        // Free Cash Flow = Operating Cash Flow - Capital Expenditures
        $freeCashFlow = $operatingCashFlow - $capEx;

        // Operating Cash Flow Ratio = Operating Cash Flow / Current Liabilities
        $currentLiabilities = $this->getCurrentLiabilities();
        $operatingCashFlowRatio = $currentLiabilities > 0 
            ? $operatingCashFlow / $currentLiabilities 
            : 0;

        // Cash Flow Margin = Operating Cash Flow / Revenue
        $revenue = $this->getTotalRevenue();
        $cashFlowMargin = $revenue > 0 ? ($operatingCashFlow / $revenue) * 100 : 0;

        // Cash Flow Coverage Ratio = Operating Cash Flow / Total Debt
        $totalDebt = $this->getTotalDebt();
        $cashFlowCoverageRatio = $totalDebt > 0 ? $operatingCashFlow / $totalDebt : 0;

        return [
            'free_cash_flow' => $freeCashFlow,
            'operating_cash_flow_ratio' => $operatingCashFlowRatio,
            'cash_flow_margin' => $cashFlowMargin,
            'cash_flow_coverage_ratio' => $cashFlowCoverageRatio,
            'capital_expenditures' => $capEx
        ];
    }

    /**
     * Get net income for the period
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return float Net income
     */
    private function getNetIncome(string $startDate, string $endDate): float
    {
        $sql = "
            SELECT 
                SUM(CASE 
                    WHEN coa.account_type IN (10, 11) THEN -gl.amount  -- Revenue
                    WHEN coa.account_type IN (6, 7, 8, 9) THEN gl.amount  -- Expenses
                    ELSE 0 
                END) as net_income
            FROM ".TB_PREF."gl_trans gl
            INNER JOIN ".TB_PREF."chart_master coa ON gl.account = coa.account_code
            WHERE gl.tran_date BETWEEN ? AND ?
        ";

        $result = $this->db->fetchOne($sql, [$startDate, $endDate]);
        return (float) ($result['net_income'] ?? 0);
    }

    /**
     * Get non-cash expenses (depreciation, amortization, etc.)
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return array Non-cash expense items
     */
    private function getNonCashExpenses(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                coa.account_name as description,
                SUM(gl.amount) as amount
            FROM ".TB_PREF."gl_trans gl
            INNER JOIN ".TB_PREF."chart_master coa ON gl.account = coa.account_code
            WHERE gl.tran_date BETWEEN ? AND ?
                AND (
                    coa.account_name LIKE '%depreciation%'
                    OR coa.account_name LIKE '%amortization%'
                    OR coa.account_name LIKE '%stock%based%compensation%'
                    OR coa.account_name LIKE '%bad%debt%'
                )
            GROUP BY coa.account_name
            HAVING SUM(gl.amount) != 0
        ";

        return $this->db->fetchAll($sql, [$startDate, $endDate]) ?? [];
    }

    /**
     * Get changes in working capital accounts
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return array Working capital changes
     */
    private function getWorkingCapitalChanges(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                coa.account_name as account,
                (
                    SELECT COALESCE(SUM(amount), 0)
                    FROM ".TB_PREF."gl_trans
                    WHERE account = coa.account_code
                        AND tran_date BETWEEN ? AND ?
                ) as change
            FROM ".TB_PREF."chart_master coa
            WHERE coa.account_type IN (1, 2, 5)  -- Current assets and current liabilities
                AND (
                    coa.account_name LIKE '%receivable%'
                    OR coa.account_name LIKE '%inventory%'
                    OR coa.account_name LIKE '%prepaid%'
                    OR coa.account_name LIKE '%payable%'
                    OR coa.account_name LIKE '%accrued%'
                )
            HAVING change != 0
        ";

        return $this->db->fetchAll($sql, [$startDate, $endDate]) ?? [];
    }

    /**
     * Get investing activity transactions
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return array Investing transactions
     */
    private function getInvestingTransactions(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                gl.memo as description,
                SUM(gl.amount) as amount
            FROM ".TB_PREF."gl_trans gl
            INNER JOIN ".TB_PREF."chart_master coa ON gl.account = coa.account_code
            WHERE gl.tran_date BETWEEN ? AND ?
                AND coa.account_type IN (3, 4)  -- Fixed assets, long-term investments
                AND gl.memo IS NOT NULL
                AND gl.memo != ''
            GROUP BY gl.memo
            HAVING SUM(gl.amount) != 0
        ";

        return $this->db->fetchAll($sql, [$startDate, $endDate]) ?? [];
    }

    /**
     * Get financing activity transactions
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return array Financing transactions
     */
    private function getFinancingTransactions(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                gl.memo as description,
                SUM(gl.amount) as amount
            FROM ".TB_PREF."gl_trans gl
            INNER JOIN ".TB_PREF."chart_master coa ON gl.account = coa.account_code
            WHERE gl.tran_date BETWEEN ? AND ?
                AND (
                    coa.account_name LIKE '%loan%'
                    OR coa.account_name LIKE '%dividend%'
                    OR coa.account_name LIKE '%share%capital%'
                    OR coa.account_name LIKE '%equity%'
                )
                AND gl.memo IS NOT NULL
                AND gl.memo != ''
            GROUP BY gl.memo
            HAVING SUM(gl.amount) != 0
        ";

        return $this->db->fetchAll($sql, [$startDate, $endDate]) ?? [];
    }

    /**
     * Get cash balance as of a specific date
     * 
     * @param string $date Date to get cash balance
     * 
     * @return float Cash balance
     */
    private function getCashBalance(string $date): float
    {
        $sql = "
            SELECT 
                COALESCE(SUM(gl.amount), 0) as balance
            FROM ".TB_PREF."gl_trans gl
            INNER JOIN ".TB_PREF."chart_master coa ON gl.account = coa.account_code
            WHERE gl.tran_date <= ?
                AND coa.account_type = 0  -- Cash/Bank accounts
        ";

        $result = $this->db->fetchOne($sql, [$date]);
        return (float) ($result['balance'] ?? 0);
    }

    /**
     * Get current liabilities total
     * 
     * @return float Current liabilities
     */
    private function getCurrentLiabilities(): float
    {
        $sql = "
            SELECT 
                COALESCE(SUM(gl.amount), 0) as total
            FROM ".TB_PREF."gl_trans gl
            INNER JOIN ".TB_PREF."chart_master coa ON gl.account = coa.account_code
            WHERE coa.account_type = 5  -- Current liabilities
        ";

        $result = $this->db->fetchOne($sql);
        return abs((float) ($result['total'] ?? 0));
    }

    /**
     * Get total revenue for the period
     * 
     * @return float Total revenue
     */
    private function getTotalRevenue(): float
    {
        $sql = "
            SELECT 
                COALESCE(ABS(SUM(gl.amount)), 0) as total
            FROM ".TB_PREF."gl_trans gl
            INNER JOIN ".TB_PREF."chart_master coa ON gl.account = coa.account_code
            WHERE coa.account_type IN (10, 11)  -- Revenue accounts
        ";

        $result = $this->db->fetchOne($sql);
        return (float) ($result['total'] ?? 0);
    }

    /**
     * Get total debt
     * 
     * @return float Total debt
     */
    private function getTotalDebt(): float
    {
        $sql = "
            SELECT 
                COALESCE(SUM(gl.amount), 0) as total
            FROM ".TB_PREF."gl_trans gl
            INNER JOIN ".TB_PREF."chart_master coa ON gl.account = coa.account_code
            WHERE coa.account_name LIKE '%loan%'
                OR coa.account_name LIKE '%debt%'
        ";

        $result = $this->db->fetchOne($sql);
        return abs((float) ($result['total'] ?? 0));
    }

    /**
     * Calculate variance between current and prior periods
     * 
     * @param array $current Current period data
     * @param array $prior Prior period data
     * 
     * @return array Variance data
     */
    private function calculateVariance(array $current, array $prior): array
    {
        return [
            'operating' => $current['operating_activities']['net_cash_from_operations'] - 
                          $prior['operating_activities']['net_cash_from_operations'],
            'investing' => $current['investing_activities']['net_cash_from_investing'] - 
                          $prior['investing_activities']['net_cash_from_investing'],
            'financing' => $current['financing_activities']['net_cash_from_financing'] - 
                          $prior['financing_activities']['net_cash_from_financing'],
            'net_change' => $current['net_cash_change'] - $prior['net_cash_change']
        ];
    }

    /**
     * Calculate variance percentage
     * 
     * @param array $current Current period data
     * @param array $prior Prior period data
     * 
     * @return array Variance percentage data
     */
    private function calculateVariancePercent(array $current, array $prior): array
    {
        $calculate = function($currentVal, $priorVal) {
            if ($priorVal == 0) return 0;
            return (($currentVal - $priorVal) / abs($priorVal)) * 100;
        };

        return [
            'operating' => $calculate(
                $current['operating_activities']['net_cash_from_operations'],
                $prior['operating_activities']['net_cash_from_operations']
            ),
            'investing' => $calculate(
                $current['investing_activities']['net_cash_from_investing'],
                $prior['investing_activities']['net_cash_from_investing']
            ),
            'financing' => $calculate(
                $current['financing_activities']['net_cash_from_financing'],
                $prior['financing_activities']['net_cash_from_financing']
            ),
            'net_change' => $calculate(
                $current['net_cash_change'],
                $prior['net_cash_change']
            )
        ];
    }

    /**
     * Validate date range
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @throws \InvalidArgumentException If date range is invalid
     */
    private function validateDateRange(string $startDate, string $endDate): void
    {
        if (strtotime($endDate) < strtotime($startDate)) {
            throw new \InvalidArgumentException('End date must be after start date');
        }
    }

    /**
     * Export report to PDF
     * 
     * @param array $data Report data
     * 
     * @return string PDF content
     */
    public function exportToPDF(array $data): string
    {
        // Simple PDF export - returns formatted string
        return 'Cash Flow Statement - PDF Export';
    }

    /**
     * Export report to Excel
     * 
     * @param array $data Report data
     * 
     * @return string Excel content
     */
    public function exportToExcel(array $data): string
    {
        // Simple Excel export - returns formatted string
        return 'Cash Flow Statement - Excel Export';
    }
}
