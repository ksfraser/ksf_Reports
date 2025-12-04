<?php

/**
 * Working Capital Analysis Report
 * 
 * Provides comprehensive analysis of working capital management including liquidity ratios,
 * cash conversion cycle, efficiency metrics, and actionable recommendations.
 * 
 * @package FrontAccounting
 * @subpackage Reports
 */

declare(strict_types=1);

namespace FA\Modules\Reports\Financial;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

class WorkingCapitalAnalysis
{
    private DBALInterface $db;
    private EventDispatcher $dispatcher;
    private LoggerInterface $logger;

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
     * Generate working capital analysis
     * 
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * 
     * @return array Analysis results
     */
    public function generate(string $startDate, string $endDate): array
    {
        $this->logger->info('Generating working capital analysis', [
            'period' => $startDate . ' to ' . $endDate
        ]);

        $data = $this->getWorkingCapitalData($startDate, $endDate);
        
        $workingCapital = $data['current_assets'] - $data['current_liabilities'];
        $ratios = $this->calculateLiquidityRatios($data);
        $metrics = $this->calculateEfficiencyMetrics($data);
        $components = $this->analyzeComponents($data);
        $healthStatus = $this->assessHealthStatus($ratios);
        $efficiencyScore = $this->calculateEfficiencyScore($ratios, $metrics);
        $recommendations = $this->generateRecommendations($data, $ratios, $metrics);

        $result = [
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'working_capital' => $workingCapital,
            'ratios' => $ratios,
            'metrics' => $metrics,
            'components' => $components,
            'health_status' => $healthStatus,
            'efficiency_score' => $efficiencyScore,
            'recommendations' => $recommendations,
            'generated_at' => date('Y-m-d H:i:s')
        ];

        return $result;
    }

    /**
     * Get working capital data from database
     * 
     * @param string $startDate
     * @param string $endDate
     * 
     * @return array Working capital data
     */
    private function getWorkingCapitalData(string $startDate, string $endDate): array
    {
        // Query for current assets and liabilities
        $sql = "
            SELECT
                SUM(CASE WHEN account_type = 'Current Assets' THEN balance ELSE 0 END) as current_assets,
                SUM(CASE WHEN account_type = 'Current Liabilities' THEN balance ELSE 0 END) as current_liabilities,
                SUM(CASE WHEN account_name LIKE '%Cash%' OR account_name LIKE '%Bank%' THEN balance ELSE 0 END) as cash,
                SUM(CASE WHEN account_name LIKE '%Receivable%' OR account_name LIKE '%Debtors%' THEN balance ELSE 0 END) as accounts_receivable,
                SUM(CASE WHEN account_name LIKE '%Inventory%' OR account_name LIKE '%Stock%' THEN balance ELSE 0 END) as inventory,
                SUM(CASE WHEN account_name LIKE '%Payable%' OR account_name LIKE '%Creditors%' THEN balance ELSE 0 END) as accounts_payable,
                SUM(CASE WHEN account_name LIKE '%Short%' AND account_name LIKE '%Debt%' THEN balance ELSE 0 END) as short_term_debt,
                SUM(CASE WHEN account_name LIKE '%Securities%' THEN balance ELSE 0 END) as marketable_securities,
                (SELECT SUM(balance) FROM ".TB_PREF."chart_master) as total_assets
            FROM ".TB_PREF."chart_master
            WHERE inactive = 0
        ";

        $balanceData = $this->db->fetchOne($sql) ?? [];

        // Query for revenue and COGS for the period
        $salesSql = "
            SELECT 
                SUM(dt.quantity * dt.unit_price) as annual_revenue,
                SUM(dt.quantity * dt.standard_cost) as cost_of_goods_sold
            FROM ".TB_PREF."debtor_trans_details dt
            JOIN ".TB_PREF."debtor_trans t ON dt.debtor_trans_no = t.trans_no AND dt.debtor_trans_type = t.type
            WHERE t.type = ".ST_SALESINVOICE."
              AND t.tran_date BETWEEN ? AND ?
        ";

        $salesData = $this->db->fetchOne($salesSql, [$startDate, $endDate]) ?? [];

        // Merge the data
        return array_merge($balanceData, $salesData);
    }

    /**
     * Calculate liquidity ratios
     * 
     * @param array $data Working capital data
     * 
     * @return array Liquidity ratios
     */
    private function calculateLiquidityRatios(array $data): array
    {
        $currentAssets = $data['current_assets'] ?? 0;
        $currentLiabilities = $data['current_liabilities'] ?? 0;
        $cash = $data['cash'] ?? 0;
        $ar = $data['accounts_receivable'] ?? 0;
        $inventory = $data['inventory'] ?? 0;
        $securities = $data['marketable_securities'] ?? 0;

        // Current Ratio = Current Assets / Current Liabilities
        $currentRatio = $currentLiabilities > 0 
            ? $currentAssets / $currentLiabilities 
            : 0;

        // Quick Ratio (Acid Test) = (Current Assets - Inventory) / Current Liabilities
        $quickRatio = $currentLiabilities > 0 
            ? ($currentAssets - $inventory) / $currentLiabilities 
            : 0;

        // Cash Ratio = (Cash + Marketable Securities) / Current Liabilities
        $cashRatio = $currentLiabilities > 0 
            ? ($cash + $securities) / $currentLiabilities 
            : 0;

        return [
            'current_ratio' => $currentRatio,
            'quick_ratio' => $quickRatio,
            'cash_ratio' => $cashRatio
        ];
    }

    /**
     * Calculate efficiency metrics
     * 
     * @param array $data Working capital data
     * 
     * @return array Efficiency metrics
     */
    private function calculateEfficiencyMetrics(array $data): array
    {
        $currentAssets = $data['current_assets'] ?? 0;
        $currentLiabilities = $data['current_liabilities'] ?? 0;
        $ar = $data['accounts_receivable'] ?? 0;
        $inventory = $data['inventory'] ?? 0;
        $ap = $data['accounts_payable'] ?? 0;
        $revenue = $data['annual_revenue'] ?? 0;
        $cogs = $data['cost_of_goods_sold'] ?? 0;
        $totalAssets = $data['total_assets'] ?? 0;

        $workingCapital = $currentAssets - $currentLiabilities;

        // Days Sales Outstanding (DSO) = (AR / Revenue) * 365
        $dso = $revenue > 0 ? ($ar / $revenue) * 365 : 0;

        // Days Inventory Outstanding (DIO) = (Inventory / COGS) * 365
        $dio = $cogs > 0 ? ($inventory / $cogs) * 365 : 0;

        // Days Payable Outstanding (DPO) = (AP / COGS) * 365
        $dpo = $cogs > 0 ? ($ap / $cogs) * 365 : 0;

        // Cash Conversion Cycle (CCC) = DSO + DIO - DPO
        $ccc = $dso + $dio - $dpo;

        // Working Capital Turnover = Revenue / Working Capital
        $wcTurnover = $workingCapital > 0 ? $revenue / $workingCapital : 0;

        // Working Capital Ratio = Working Capital / Total Assets
        $wcRatio = $totalAssets > 0 ? $workingCapital / $totalAssets : 0;

        // Days Working Capital = (Working Capital / Revenue) * 365
        $daysWC = $revenue > 0 ? ($workingCapital / $revenue) * 365 : 0;

        return [
            'days_sales_outstanding' => $dso,
            'days_inventory_outstanding' => $dio,
            'days_payable_outstanding' => $dpo,
            'cash_conversion_cycle' => $ccc,
            'working_capital_turnover' => $wcTurnover,
            'working_capital_ratio' => $wcRatio,
            'days_working_capital' => $daysWC
        ];
    }

    /**
     * Analyze working capital components
     * 
     * @param array $data Working capital data
     * 
     * @return array Component breakdown
     */
    private function analyzeComponents(array $data): array
    {
        $currentAssets = $data['current_assets'] ?? 0;
        $currentLiabilities = $data['current_liabilities'] ?? 0;
        $cash = $data['cash'] ?? 0;
        $ar = $data['accounts_receivable'] ?? 0;
        $inventory = $data['inventory'] ?? 0;
        $ap = $data['accounts_payable'] ?? 0;
        $debt = $data['short_term_debt'] ?? 0;

        $otherAssets = $currentAssets - $cash - $ar - $inventory;
        $otherLiabilities = $currentLiabilities - $ap - $debt;

        return [
            'assets' => [
                'cash' => $cash,
                'cash_percent' => $currentAssets > 0 ? ($cash / $currentAssets) * 100 : 0,
                'accounts_receivable' => $ar,
                'ar_percent' => $currentAssets > 0 ? ($ar / $currentAssets) * 100 : 0,
                'inventory' => $inventory,
                'inventory_percent' => $currentAssets > 0 ? ($inventory / $currentAssets) * 100 : 0,
                'other' => $otherAssets,
                'other_percent' => $currentAssets > 0 ? ($otherAssets / $currentAssets) * 100 : 0,
                'total' => $currentAssets
            ],
            'liabilities' => [
                'accounts_payable' => $ap,
                'ap_percent' => $currentLiabilities > 0 ? ($ap / $currentLiabilities) * 100 : 0,
                'short_term_debt' => $debt,
                'debt_percent' => $currentLiabilities > 0 ? ($debt / $currentLiabilities) * 100 : 0,
                'other' => $otherLiabilities,
                'other_percent' => $currentLiabilities > 0 ? ($otherLiabilities / $currentLiabilities) * 100 : 0,
                'total' => $currentLiabilities
            ]
        ];
    }

    /**
     * Assess working capital health status
     * 
     * @param array $ratios Liquidity ratios
     * 
     * @return string Health status (Healthy, Caution, Critical)
     */
    private function assessHealthStatus(array $ratios): string
    {
        $currentRatio = $ratios['current_ratio'];

        if ($currentRatio >= 1.5) {
            return 'Healthy';
        } elseif ($currentRatio >= 1.0) {
            return 'Caution';
        } else {
            return 'Critical';
        }
    }

    /**
     * Calculate overall efficiency score
     * 
     * @param array $ratios Liquidity ratios
     * @param array $metrics Efficiency metrics
     * 
     * @return float Efficiency score (0-100)
     */
    private function calculateEfficiencyScore(array $ratios, array $metrics): float
    {
        $score = 0;

        // Current Ratio Score (30 points)
        // Optimal range: 1.5 - 2.5
        $currentRatio = $ratios['current_ratio'];
        if ($currentRatio >= 1.5 && $currentRatio <= 2.5) {
            $score += 30;
        } elseif ($currentRatio >= 1.0 && $currentRatio < 1.5) {
            $score += 20;
        } elseif ($currentRatio > 2.5 && $currentRatio <= 3.0) {
            $score += 25; // Too high can indicate inefficiency
        } elseif ($currentRatio >= 0.8) {
            $score += 10;
        }

        // Quick Ratio Score (25 points)
        // Optimal: >= 1.0
        $quickRatio = $ratios['quick_ratio'];
        if ($quickRatio >= 1.0) {
            $score += 25;
        } elseif ($quickRatio >= 0.8) {
            $score += 20;
        } elseif ($quickRatio >= 0.5) {
            $score += 10;
        }

        // Cash Conversion Cycle Score (25 points)
        // Lower is better, optimal: 30-45 days
        $ccc = $metrics['cash_conversion_cycle'];
        if ($ccc >= 30 && $ccc <= 45) {
            $score += 25;
        } elseif ($ccc >= 20 && $ccc < 30) {
            $score += 20;
        } elseif ($ccc > 45 && $ccc <= 60) {
            $score += 20;
        } elseif ($ccc < 20 || $ccc > 60) {
            $score += 10;
        }

        // Working Capital Turnover Score (20 points)
        // Higher is better, optimal: 4-8
        $wcTurnover = $metrics['working_capital_turnover'];
        if ($wcTurnover >= 4 && $wcTurnover <= 8) {
            $score += 20;
        } elseif ($wcTurnover >= 3 && $wcTurnover < 4) {
            $score += 15;
        } elseif ($wcTurnover > 8 && $wcTurnover <= 12) {
            $score += 15;
        } elseif ($wcTurnover >= 2) {
            $score += 10;
        }

        return min(100, $score);
    }

    /**
     * Generate actionable recommendations
     * 
     * @param array $data Working capital data
     * @param array $ratios Liquidity ratios
     * @param array $metrics Efficiency metrics
     * 
     * @return array Recommendations
     */
    private function generateRecommendations(array $data, array $ratios, array $metrics): array
    {
        $recommendations = [];

        // Current Ratio Analysis
        if ($ratios['current_ratio'] < 1.0) {
            $recommendations[] = [
                'type' => 'Critical',
                'category' => 'Liquidity',
                'issue' => 'Current ratio below 1.0 indicates liquidity risk',
                'recommendation' => 'Increase current assets or reduce short-term liabilities immediately',
                'actions' => [
                    'Accelerate collections from customers',
                    'Negotiate extended payment terms with suppliers',
                    'Consider short-term financing',
                    'Reduce inventory levels'
                ]
            ];
        } elseif ($ratios['current_ratio'] < 1.5) {
            $recommendations[] = [
                'type' => 'Warning',
                'category' => 'Liquidity',
                'issue' => 'Current ratio below optimal range (1.5-2.5)',
                'recommendation' => 'Improve liquidity position to ensure financial flexibility',
                'actions' => [
                    'Improve collection efficiency',
                    'Review and optimize inventory levels',
                    'Delay non-essential capital expenditures'
                ]
            ];
        } elseif ($ratios['current_ratio'] > 3.0) {
            $recommendations[] = [
                'type' => 'Opportunity',
                'category' => 'Efficiency',
                'issue' => 'Current ratio too high may indicate inefficient use of assets',
                'recommendation' => 'Consider redeploying excess current assets',
                'actions' => [
                    'Invest excess cash in higher-return opportunities',
                    'Pay down expensive debt',
                    'Return capital to shareholders',
                    'Invest in growth initiatives'
                ]
            ];
        }

        // Quick Ratio Analysis
        if ($ratios['quick_ratio'] < 1.0) {
            $recommendations[] = [
                'type' => 'Warning',
                'category' => 'Liquidity',
                'issue' => 'Quick ratio below 1.0 indicates dependence on inventory liquidation',
                'recommendation' => 'Increase liquid assets relative to current liabilities',
                'actions' => [
                    'Improve accounts receivable collection',
                    'Maintain higher cash reserves',
                    'Reduce reliance on inventory as liquidity source'
                ]
            ];
        }

        // Days Sales Outstanding (DSO)
        if ($metrics['days_sales_outstanding'] > 45) {
            $recommendations[] = [
                'type' => 'Warning',
                'category' => 'Collections',
                'issue' => 'High DSO (' . round($metrics['days_sales_outstanding']) . ' days) indicates slow collections',
                'recommendation' => 'Accelerate accounts receivable collection process',
                'actions' => [
                    'Implement stricter credit policies',
                    'Offer early payment discounts',
                    'Improve invoicing and follow-up processes',
                    'Consider factoring for problematic accounts'
                ]
            ];
        }

        // Days Inventory Outstanding (DIO)
        if ($metrics['days_inventory_outstanding'] > 60) {
            $recommendations[] = [
                'type' => 'Warning',
                'category' => 'Inventory',
                'issue' => 'High DIO (' . round($metrics['days_inventory_outstanding']) . ' days) indicates slow inventory turnover',
                'recommendation' => 'Optimize inventory management to reduce holding costs',
                'actions' => [
                    'Implement just-in-time inventory practices',
                    'Identify and liquidate slow-moving items',
                    'Improve demand forecasting',
                    'Negotiate vendor-managed inventory agreements'
                ]
            ];
        }

        // Days Payable Outstanding (DPO)
        if ($metrics['days_payable_outstanding'] < 30) {
            $recommendations[] = [
                'type' => 'Opportunity',
                'category' => 'Payables',
                'issue' => 'Low DPO (' . round($metrics['days_payable_outstanding']) . ' days) - paying suppliers quickly',
                'recommendation' => 'Extend payables while maintaining supplier relationships',
                'actions' => [
                    'Negotiate extended payment terms',
                    'Take full advantage of payment terms',
                    'Balance early payment discounts against cash needs'
                ]
            ];
        }

        // Cash Conversion Cycle (CCC)
        if ($metrics['cash_conversion_cycle'] > 60) {
            $recommendations[] = [
                'type' => 'Warning',
                'category' => 'Efficiency',
                'issue' => 'Long cash conversion cycle (' . round($metrics['cash_conversion_cycle']) . ' days)',
                'recommendation' => 'Reduce time between paying suppliers and collecting from customers',
                'actions' => [
                    'Accelerate receivables collection',
                    'Improve inventory turnover',
                    'Extend payables where possible',
                    'Consider supply chain financing options'
                ]
            ];
        } elseif ($metrics['cash_conversion_cycle'] < 20) {
            $recommendations[] = [
                'type' => 'Opportunity',
                'category' => 'Efficiency',
                'issue' => 'Very short cash conversion cycle (' . round($metrics['cash_conversion_cycle']) . ' days)',
                'recommendation' => 'Excellent working capital efficiency - maintain or leverage',
                'actions' => [
                    'Use freed cash for growth investments',
                    'Consider expanding with similar efficiency',
                    'Benchmark practices for competitive advantage'
                ]
            ];
        }

        return $recommendations;
    }

    /**
     * Generate trend analysis over time
     * 
     * @param string $startDate
     * @param string $endDate
     * 
     * @return array Trend data
     */
    public function generateTrend(string $startDate, string $endDate): array
    {
        // For simplicity, this generates quarterly trends
        // In production, would query actual historical data
        $trends = [
            ['period' => '2024-Q1', 'current_ratio' => 1.5, 'working_capital' => 150000],
            ['period' => '2024-Q2', 'current_ratio' => 1.6, 'working_capital' => 180000],
            ['period' => '2024-Q3', 'current_ratio' => 1.7, 'working_capital' => 200000],
            ['period' => '2024-Q4', 'current_ratio' => 1.8, 'working_capital' => 220000]
        ];

        $direction = $this->determineTrendDirection($trends);

        return [
            'trends' => $trends,
            'trend_direction' => $direction,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ];
    }

    /**
     * Determine trend direction
     * 
     * @param array $trends Trend data points
     * 
     * @return string Trend direction
     */
    private function determineTrendDirection(array $trends): string
    {
        if (count($trends) < 2) {
            return 'Stable';
        }

        $first = reset($trends)['current_ratio'];
        $last = end($trends)['current_ratio'];

        if ($last > $first * 1.1) {
            return 'Improving';
        } elseif ($last < $first * 0.9) {
            return 'Declining';
        } else {
            return 'Stable';
        }
    }

    /**
     * Generate benchmark comparison
     * 
     * @param string $startDate
     * @param string $endDate
     * @param string $industry Industry type
     * 
     * @return array Benchmark comparison
     */
    public function generateBenchmarks(string $startDate, string $endDate, string $industry): array
    {
        $companyData = $this->generate($startDate, $endDate);

        // Industry benchmarks (typical values)
        $benchmarks = $this->getIndustryBenchmarks($industry);

        return [
            'company_ratios' => $companyData['ratios'],
            'industry_benchmarks' => $benchmarks,
            'comparison' => [
                'current_ratio_vs_benchmark' => $companyData['ratios']['current_ratio'] - $benchmarks['current_ratio'],
                'quick_ratio_vs_benchmark' => $companyData['ratios']['quick_ratio'] - $benchmarks['quick_ratio']
            ]
        ];
    }

    /**
     * Get industry benchmark values
     * 
     * @param string $industry
     * 
     * @return array Benchmark values
     */
    private function getIndustryBenchmarks(string $industry): array
    {
        $benchmarks = [
            'manufacturing' => [
                'current_ratio' => 1.8,
                'quick_ratio' => 1.0,
                'cash_conversion_cycle' => 50
            ],
            'retail' => [
                'current_ratio' => 1.5,
                'quick_ratio' => 0.8,
                'cash_conversion_cycle' => 35
            ],
            'services' => [
                'current_ratio' => 1.3,
                'quick_ratio' => 1.2,
                'cash_conversion_cycle' => 25
            ]
        ];

        return $benchmarks[$industry] ?? $benchmarks['manufacturing'];
    }

    /**
     * Export report to PDF
     * 
     * @param array $data Report data
     * @param string $title Report title
     * 
     * @return string PDF content/path
     */
    public function exportToPDF(array $data, string $title): string
    {
        // Placeholder - would integrate with actual PDF library
        return 'PDF export placeholder for: ' . $title;
    }

    /**
     * Export report to Excel
     * 
     * @param array $data Report data
     * @param string $title Report title
     * 
     * @return string Excel content/path
     */
    public function exportToExcel(array $data, string $title): string
    {
        // Placeholder - would integrate with actual Excel library
        return 'Excel export placeholder for: ' . $title;
    }
}
