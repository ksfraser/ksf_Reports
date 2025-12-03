<?php
/**
 * Annual Expense Breakdown Report
 * 
 * Comprehensive expense analysis report providing category breakdowns, budget variance,
 * year-over-year comparisons, trend analysis, and detailed account-level reporting.
 * 
 * Based on WebERP GLProfit_Loss.php patterns but reimagined for FrontAccounting
 * with modern PHP, SOLID principles, and enterprise reporting capabilities.
 * 
 * @package    KSF\Reports
 * @subpackage Financial
 * @author     KSF Development Team
 * @copyright  2025 KSFraser
 * @license    MIT
 * @version    1.0.0
 * @link       https://github.com/ksfraser/ksf_Reports
 */

declare(strict_types=1);

namespace KSF\Reports\Financial;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use KSF\Reports\Events\ReportGeneratedEvent;
use Psr\Log\LoggerInterface;

/**
 * Annual Expense Breakdown Report Service
 * 
 * Generates comprehensive expense reports with:
 * - Category and sub-category breakdowns
 * - Budget vs actual variance analysis
 * - Year-over-year comparisons
 * - Monthly and quarterly trends
 * - Top expense account identification
 * - Detailed account-level reporting
 */
class AnnualExpenseBreakdownReport
{
    /**
     * Expense account ranges (configurable per chart of accounts)
     */
    private const EXPENSE_ACCOUNT_RANGES = [
        'min' => 5000,
        'max' => 6999
    ];

    /**
     * Expense category mappings
     */
    private const EXPENSE_CATEGORIES = [
        'Salaries & Wages' => ['5000-5099'],
        'Operating Expenses' => ['5100-5199'],
        'Administrative Expenses' => ['5200-5299'],
        'Marketing & Sales' => ['5300-5399'],
        'Professional Fees' => ['5400-5499'],
        'Technology & IT' => ['5500-5599'],
        'Depreciation & Amortization' => ['5600-5699'],
        'Interest & Finance Charges' => ['5700-5799'],
        'Other Expenses' => ['5800-6999']
    ];

    public function __construct(
        private DBALInterface $dbal,
        private EventDispatcher $eventDispatcher,
        private LoggerInterface $logger
    ) {}

    /**
     * Generate annual expense breakdown report
     * 
     * @param array $params Report parameters
     *   - fiscal_year: int (required)
     *   - include_budget: bool (default: true)
     *   - group_by_category: bool (default: true)
     *   - category_filter: string|null
     *   - format: string (pdf|excel|csv)
     * 
     * @return array Report data structure
     * @throws \InvalidArgumentException
     */
    public function generate(array $params): array
    {
        $this->validateParameters($params);

        $fiscalYear = (int) $params['fiscal_year'];
        $includeBudget = $params['include_budget'] ?? true;
        $groupByCategory = $params['group_by_category'] ?? true;
        $categoryFilter = $params['category_filter'] ?? null;

        $this->logger->info('Generating annual expense breakdown', [
            'fiscal_year' => $fiscalYear,
            'include_budget' => $includeBudget
        ]);

        // Fetch expense data
        $expenseData = $this->fetchExpenseData($fiscalYear, $includeBudget, $categoryFilter);

        // Group and aggregate
        $categorized = $groupByCategory 
            ? $this->categorizeExpenses($expenseData)
            : $expenseData;

        // Calculate totals and variances
        $totals = $this->calculateTotals($categorized);

        $result = [
            'categories' => $categorized,
            'totals' => $totals,
            'metadata' => [
                'report_type' => 'annual_expense_breakdown',
                'fiscal_year' => $fiscalYear,
                'generated_at' => date('Y-m-d H:i:s'),
                'include_budget' => $includeBudget,
                'category_filter' => $categoryFilter
            ]
        ];

        // Dispatch event
        $this->eventDispatcher->dispatch(new ReportGeneratedEvent(
            'annual_expense_breakdown',
            $params,
            $result
        ));

        return $result;
    }

    /**
     * Generate year-over-year comparison report
     * 
     * @param array $params Report parameters with 'compare_years' array
     * @return array Comparison data
     */
    public function generateComparison(array $params): array
    {
        if (!isset($params['compare_years']) || !is_array($params['compare_years'])) {
            throw new \InvalidArgumentException('compare_years parameter must be an array');
        }

        $years = $params['compare_years'];
        $groupByCategory = $params['group_by_category'] ?? true;

        $this->logger->info('Generating year-over-year expense comparison', [
            'years' => $years
        ]);

        $comparison = [];
        $yearData = [];

        // Fetch data for each year
        foreach ($years as $year) {
            $data = $this->fetchExpenseData((int) $year, false, null);
            $yearData[$year] = $groupByCategory 
                ? $this->categorizeExpenses($data)
                : $data;
        }

        // Build comparison structure
        if ($groupByCategory) {
            $allCategories = array_keys(self::EXPENSE_CATEGORIES);
            
            foreach ($allCategories as $category) {
                $comparisonRow = ['category' => $category];
                
                foreach ($years as $year) {
                    $amount = 0.00;
                    if (isset($yearData[$year][$category])) {
                        $amount = array_sum(array_column($yearData[$year][$category]['accounts'], 'amount'));
                    }
                    $comparisonRow["year_$year"] = $amount;
                }
                
                // Calculate change between first and last year
                $firstYear = reset($years);
                $lastYear = end($years);
                $change = $comparisonRow["year_$lastYear"] - $comparisonRow["year_$firstYear"];
                $changePercent = $comparisonRow["year_$firstYear"] > 0 
                    ? ($change / $comparisonRow["year_$firstYear"]) * 100 
                    : 0;
                
                $comparisonRow['change'] = $change;
                $comparisonRow['change_percent'] = round($changePercent, 2);
                
                $comparison[] = $comparisonRow;
            }
        }

        return [
            'comparison' => $comparison,
            'metadata' => [
                'years' => $years,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Generate monthly expense trends
     * 
     * @param array $params Report parameters with 'fiscal_year' and 'show_trends'
     * @return array Trend data
     */
    public function generateTrends(array $params): array
    {
        $this->validateParameters($params);

        $fiscalYear = (int) $params['fiscal_year'];

        $this->logger->info('Generating expense trends', ['fiscal_year' => $fiscalYear]);

        $sql = "
            SELECT 
                MONTH(trans_date) as month,
                MONTHNAME(trans_date) as month_name,
                SUM(ABS(amount)) as expenses,
                (SELECT SUM(amount) FROM budget_trans WHERE fiscal_year = ? AND MONTH(period_date) = MONTH(gl.trans_date)) as budget,
                (SELECT SUM(amount) FROM budget_trans WHERE fiscal_year = ? AND MONTH(period_date) = MONTH(gl.trans_date)) - SUM(ABS(amount)) as variance
            FROM gl_trans gl
            INNER JOIN chart_master cm ON gl.account = cm.account_code
            WHERE YEAR(trans_date) = ?
                AND cm.account_code BETWEEN ? AND ?
            GROUP BY MONTH(trans_date), MONTHNAME(trans_date)
            ORDER BY MONTH(trans_date)
        ";

        $trends = $this->dbal->fetchAll($sql, [
            $fiscalYear,
            $fiscalYear,
            $fiscalYear,
            self::EXPENSE_ACCOUNT_RANGES['min'],
            self::EXPENSE_ACCOUNT_RANGES['max']
        ]);

        // Format results
        $formattedTrends = [];
        foreach ($trends as $trend) {
            $formattedTrends[] = [
                'month' => $trend['month_name'] ?? date('M', mktime(0, 0, 0, (int)$trend['month'], 1)),
                'period' => (int) $trend['month'],
                'expenses' => (float) ($trend['expenses'] ?? 0),
                'budget' => (float) ($trend['budget'] ?? 0),
                'variance' => (float) ($trend['variance'] ?? 0)
            ];
        }

        return [
            'trends' => $formattedTrends,
            'metadata' => [
                'fiscal_year' => $fiscalYear,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Generate top expenses report
     * 
     * @param array $params Report parameters with 'fiscal_year' and 'top_accounts'
     * @return array Top expense accounts
     */
    public function generateTopExpenses(array $params): array
    {
        $this->validateParameters($params);

        $fiscalYear = (int) $params['fiscal_year'];
        $topCount = $params['top_accounts'] ?? 10;

        $this->logger->info('Generating top expenses', [
            'fiscal_year' => $fiscalYear,
            'limit' => $topCount
        ]);

        $sql = "
            SELECT 
                cm.account_code,
                cm.account_name,
                SUM(ABS(gl.amount)) as amount,
                (SUM(ABS(gl.amount)) / (SELECT SUM(ABS(amount)) FROM gl_trans WHERE YEAR(trans_date) = ? AND account BETWEEN ? AND ?) * 100) as percent_of_total
            FROM gl_trans gl
            INNER JOIN chart_master cm ON gl.account = cm.account_code
            WHERE YEAR(gl.trans_date) = ?
                AND cm.account_code BETWEEN ? AND ?
            GROUP BY cm.account_code, cm.account_name
            ORDER BY amount DESC
            LIMIT ?
        ";

        $topExpenses = $this->dbal->fetchAll($sql, [
            $fiscalYear,
            self::EXPENSE_ACCOUNT_RANGES['min'],
            self::EXPENSE_ACCOUNT_RANGES['max'],
            $fiscalYear,
            self::EXPENSE_ACCOUNT_RANGES['min'],
            self::EXPENSE_ACCOUNT_RANGES['max'],
            $topCount
        ]);

        return [
            'top_expenses' => $topExpenses,
            'metadata' => [
                'fiscal_year' => $fiscalYear,
                'limit' => $topCount,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Generate budget variance analysis
     * 
     * @param array $params Report parameters
     * @return array Variance analysis
     */
    public function generateVarianceAnalysis(array $params): array
    {
        $this->validateParameters($params);

        $fiscalYear = (int) $params['fiscal_year'];
        $threshold = $params['variance_threshold'] ?? 5.0;

        $this->logger->info('Generating variance analysis', [
            'fiscal_year' => $fiscalYear,
            'threshold' => $threshold
        ]);

        $expenseData = $this->fetchExpenseData($fiscalYear, true, null);
        $categorized = $this->categorizeExpenses($expenseData);

        $variances = [];
        foreach ($categorized as $categoryName => $categoryData) {
            $totalActual = array_sum(array_column($categoryData['accounts'], 'amount'));
            $totalBudget = array_sum(array_column($categoryData['accounts'], 'budget'));
            
            $variance = $totalActual - $totalBudget;
            $variancePercent = $totalBudget > 0 ? ($variance / $totalBudget) * 100 : 0;
            
            $status = 'on_budget';
            if (abs($variancePercent) > $threshold) {
                $status = $variance > 0 ? 'over_budget' : 'under_budget';
            }

            $variances[] = [
                'category' => $categoryName,
                'budget' => $totalBudget,
                'actual' => $totalActual,
                'variance' => $variance,
                'variance_percent' => round($variancePercent, 2),
                'status' => $status
            ];
        }

        return [
            'variances' => $variances,
            'threshold' => $threshold,
            'metadata' => [
                'fiscal_year' => $fiscalYear,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Generate quarterly expense summary
     * 
     * @param array $params Report parameters
     * @return array Quarterly data
     */
    public function generateQuarterly(array $params): array
    {
        $this->validateParameters($params);

        $fiscalYear = (int) $params['fiscal_year'];

        $this->logger->info('Generating quarterly breakdown', ['fiscal_year' => $fiscalYear]);

        $sql = "
            SELECT 
                QUARTER(trans_date) as quarter_num,
                CONCAT('Q', QUARTER(trans_date)) as quarter,
                SUM(ABS(amount)) as expenses
            FROM gl_trans gl
            INNER JOIN chart_master cm ON gl.account = cm.account_code
            WHERE YEAR(trans_date) = ?
                AND cm.account_code BETWEEN ? AND ?
            GROUP BY QUARTER(trans_date)
            ORDER BY QUARTER(trans_date)
        ";

        $quarters = $this->dbal->fetchAll($sql, [
            $fiscalYear,
            self::EXPENSE_ACCOUNT_RANGES['min'],
            self::EXPENSE_ACCOUNT_RANGES['max']
        ]);

        return [
            'quarters' => $quarters,
            'metadata' => [
                'fiscal_year' => $fiscalYear,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Fetch expense data from database
     * 
     * @param int $fiscalYear Fiscal year
     * @param bool $includeBudget Include budget data
     * @param string|null $categoryFilter Filter by category
     * @return array Expense records
     */
    private function fetchExpenseData(int $fiscalYear, bool $includeBudget, ?string $categoryFilter): array
    {
        $sql = "
            SELECT 
                cm.account_code,
                cm.account_name,
                SUM(ABS(gl.amount)) as amount,
                COUNT(gl.trans_id) as transactions
        ";

        if ($includeBudget) {
            $sql .= ",
                COALESCE((SELECT SUM(amount) FROM budget_trans WHERE account = cm.account_code AND fiscal_year = ?), 0) as budget,
                COALESCE((SELECT SUM(amount) FROM budget_trans WHERE account = cm.account_code AND fiscal_year = ?), 0) - SUM(ABS(gl.amount)) as variance,
                ((COALESCE((SELECT SUM(amount) FROM budget_trans WHERE account = cm.account_code AND fiscal_year = ?), 0) - SUM(ABS(gl.amount))) / NULLIF(COALESCE((SELECT SUM(amount) FROM budget_trans WHERE account = cm.account_code AND fiscal_year = ?), 0), 0)) * 100 as variance_percent
            ";
        }

        $sql .= "
            FROM gl_trans gl
            INNER JOIN chart_master cm ON gl.account = cm.account_code
            WHERE YEAR(gl.trans_date) = ?
                AND cm.account_code BETWEEN ? AND ?
        ";

        if ($categoryFilter) {
            $ranges = $this->getCategoryRanges($categoryFilter);
            if (!empty($ranges)) {
                $sql .= " AND (";
                $conditions = [];
                foreach ($ranges as $range) {
                    [$min, $max] = explode('-', $range);
                    $conditions[] = "(cm.account_code BETWEEN $min AND $max)";
                }
                $sql .= implode(' OR ', $conditions);
                $sql .= ")";
            }
        }

        $sql .= "
            GROUP BY cm.account_code, cm.account_name
            ORDER BY cm.account_code
        ";

        $params = $includeBudget 
            ? [$fiscalYear, $fiscalYear, $fiscalYear, $fiscalYear, $fiscalYear, 
               self::EXPENSE_ACCOUNT_RANGES['min'], self::EXPENSE_ACCOUNT_RANGES['max']]
            : [$fiscalYear, self::EXPENSE_ACCOUNT_RANGES['min'], self::EXPENSE_ACCOUNT_RANGES['max']];

        return $this->dbal->fetchAll($sql, $params);
    }

    /**
     * Categorize expenses by predefined categories
     * 
     * @param array $expenseData Raw expense data
     * @return array Categorized expenses
     */
    private function categorizeExpenses(array $expenseData): array
    {
        $categorized = [];

        foreach (self::EXPENSE_CATEGORIES as $categoryName => $ranges) {
            $categorized[$categoryName] = [
                'accounts' => [],
                'total' => 0.00
            ];

            foreach ($expenseData as $expense) {
                $accountCode = (int) $expense['account_code'];
                
                foreach ($ranges as $range) {
                    [$min, $max] = explode('-', $range);
                    if ($accountCode >= (int) $min && $accountCode <= (int) $max) {
                        $categorized[$categoryName]['accounts'][] = $expense;
                        $categorized[$categoryName]['total'] += (float) $expense['amount'];
                        break 2;
                    }
                }
            }
        }

        // Remove empty categories
        return array_filter($categorized, fn($cat) => !empty($cat['accounts']));
    }

    /**
     * Calculate total amounts and variances
     * 
     * @param array $categorizedData Categorized expense data
     * @return array Calculated totals
     */
    private function calculateTotals(array $categorizedData): array
    {
        $totalActual = 0.00;
        $totalBudget = 0.00;

        foreach ($categorizedData as $category) {
            foreach ($category['accounts'] as $account) {
                $totalActual += (float) $account['amount'];
                $totalBudget += (float) ($account['budget'] ?? 0);
            }
        }

        $variance = $totalActual - $totalBudget;
        $variancePercent = $totalBudget > 0 ? ($variance / $totalBudget) * 100 : 0;

        return [
            'actual' => $totalActual,
            'budget' => $totalBudget,
            'variance' => $variance,
            'variance_percent' => round($variancePercent, 2)
        ];
    }

    /**
     * Get account ranges for a specific category
     * 
     * @param string $categoryName Category name
     * @return array Account ranges
     */
    private function getCategoryRanges(string $categoryName): array
    {
        return self::EXPENSE_CATEGORIES[$categoryName] ?? [];
    }

    /**
     * Validate required parameters
     * 
     * @param array $params Parameters to validate
     * @throws \InvalidArgumentException
     */
    private function validateParameters(array $params): void
    {
        if (!isset($params['fiscal_year'])) {
            throw new \InvalidArgumentException('fiscal_year parameter is required');
        }

        if (!is_numeric($params['fiscal_year']) || (int) $params['fiscal_year'] < 2000) {
            throw new \InvalidArgumentException('fiscal_year must be a valid year (>= 2000)');
        }
    }
}
