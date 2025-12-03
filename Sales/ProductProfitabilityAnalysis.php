<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Sales;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Product Profitability Analysis Report
 * 
 * Comprehensive product-level profitability analysis with gross profit, contribution margins,
 * cost breakdowns, pricing recommendations, and profitability rankings. Helps identify
 * most and least profitable products for strategic pricing and portfolio decisions.
 * 
 * @package FA\Modules\Reports\Sales
 * @author FrontAccounting Development Team
 * @version 1.0.0
 * @since 2025-12-03
 */
class ProductProfitabilityAnalysis
{
    private DBALInterface $db;
    private EventDispatcher $dispatcher;
    private LoggerInterface $logger;

    // Target margin thresholds for recommendations
    private const TARGET_MARGIN_30 = 30.0;
    private const TARGET_MARGIN_40 = 40.0;
    private const TARGET_MARGIN_50 = 50.0;

    // Profitability thresholds
    private const HIGH_MARGIN_THRESHOLD = 40.0;   // >= 40% = high margin
    private const LOW_MARGIN_THRESHOLD = 15.0;    // < 15% = low margin
    private const UNPROFITABLE_THRESHOLD = 0.0;   // < 0% = loss

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
     * Generate product profitability analysis
     * 
     * @param string $startDate Start date (YYYY-MM-DD)
     * @param string $endDate End date (YYYY-MM-DD)
     * 
     * @return array Profitability analysis results
     * 
     * @throws \InvalidArgumentException If date range is invalid
     * @throws \Exception If database error occurs
     */
    public function generate(string $startDate, string $endDate): array
    {
        try {
            $this->validateDateRange($startDate, $endDate);
            
            $this->logger->info('Generating Product Profitability Analysis', [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            // Fetch product sales and cost data
            $products = $this->getProductProfitabilityData($startDate, $endDate);

            if (empty($products)) {
                return $this->getEmptyResult();
            }

            // Calculate profitability metrics for each product
            $products = $this->calculateProfitabilityMetrics($products);

            // Calculate contribution percentages
            $products = $this->calculateContributions($products);

            // Sort by gross profit descending
            usort($products, fn($a, $b) => $b['gross_profit'] <=> $a['gross_profit']);

            // Identify top and least profitable products
            $topProfitable = $this->identifyTopProfitable($products);
            $leastProfitable = $this->identifyLeastProfitable($products);

            // Generate summary metrics
            $summary = $this->generateSummary($products);

            return [
                'products' => $products,
                'summary' => $summary,
                'top_profitable' => $topProfitable,
                'least_profitable' => $leastProfitable
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate Product Profitability Analysis', [
                'error' => $e->getMessage(),
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
            throw $e;
        }
    }

    /**
     * Generate profitability analysis by category
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return array Analysis grouped by category
     */
    public function generateByCategory(string $startDate, string $endDate): array
    {
        $products = $this->getProductProfitabilityData($startDate, $endDate);
        $products = $this->calculateProfitabilityMetrics($products);

        $categories = [];
        foreach ($products as $product) {
            $category = $product['category'] ?? 'Uncategorized';
            if (!isset($categories[$category])) {
                $categories[$category] = [];
            }
            $categories[$category][] = $product;
        }

        return ['categories' => $categories];
    }

    /**
     * Generate profitability trend for a specific product
     * 
     * @param string $stockId Product ID
     * 
     * @return array Trend data
     */
    public function generateTrend(string $stockId): array
    {
        $sql = "
            SELECT 
                DATE_FORMAT(t.tran_date, '%Y-%m') as month,
                SUM(dt.quantity) as units_sold,
                SUM(dt.quantity * dt.unit_price) as revenue,
                SUM(dt.quantity * dt.standard_cost) as cost
            FROM ".TB_PREF."debtor_trans_details dt
            JOIN ".TB_PREF."debtor_trans t ON dt.debtor_trans_no = t.trans_no AND dt.debtor_trans_type = t.type
            WHERE dt.stock_id = ?
              AND t.type = ".ST_SALESINVOICE."
              AND t.tran_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(t.tran_date, '%Y-%m')
            ORDER BY month ASC
        ";

        $monthlyData = $this->db->fetchAll($sql, [$stockId]) ?? [];

        // Calculate profitability for each month
        $trends = array_map(function($month) {
            $month['profit'] = $month['revenue'] - $month['cost'];
            $month['margin'] = $month['revenue'] > 0 
                ? ($month['profit'] / $month['revenue']) * 100 
                : 0;
            return $month;
        }, $monthlyData);

        return [
            'stock_id' => $stockId,
            'monthly_trends' => $trends,
            'profitability_trend' => $this->calculateProfitabilityTrend($trends)
        ];
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
        return 'Product Profitability Analysis - PDF Export';
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
        return 'Product Profitability Analysis - Excel Export';
    }

    /**
     * Validate date range
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @throws \InvalidArgumentException If dates are invalid
     */
    private function validateDateRange(string $startDate, string $endDate): void
    {
        if (strtotime($startDate) > strtotime($endDate)) {
            throw new \InvalidArgumentException('Start date must be before end date');
        }
    }

    /**
     * Get product profitability data from database
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return array Product profitability records
     */
    private function getProductProfitabilityData(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                s.stock_id,
                s.description,
                s.category_id as category,
                
                -- Sales data
                SUM(dt.quantity) as units_sold,
                SUM(dt.quantity * dt.unit_price) as sales_revenue,
                
                -- Cost data
                SUM(dt.quantity * dt.standard_cost) as cost_of_goods,
                s.material_cost * SUM(dt.quantity) as material_cost,
                s.labour_cost * SUM(dt.quantity) as labor_cost,
                s.overhead_cost * SUM(dt.quantity) as overhead_cost
                
            FROM ".TB_PREF."stock_master s
            JOIN ".TB_PREF."debtor_trans_details dt ON s.stock_id = dt.stock_id
            JOIN ".TB_PREF."debtor_trans t ON dt.debtor_trans_no = t.trans_no AND dt.debtor_trans_type = t.type
            WHERE t.type = ".ST_SALESINVOICE."
              AND t.tran_date BETWEEN ? AND ?
              AND s.inactive = 0
              AND s.mb_flag != 'D'
            GROUP BY s.stock_id, s.description, s.category_id, s.material_cost, s.labour_cost, s.overhead_cost
            HAVING units_sold > 0
            ORDER BY sales_revenue DESC
        ";

        return $this->db->fetchAll($sql, [$startDate, $endDate]) ?? [];
    }

    /**
     * Calculate profitability metrics for each product
     * 
     * @param array $products Product records
     * 
     * @return array Products with calculated metrics
     */
    private function calculateProfitabilityMetrics(array $products): array
    {
        return array_map(function ($product) {
            $revenue = $product['sales_revenue'];
            $cost = $product['cost_of_goods'];
            $units = $product['units_sold'];
            $materialCost = $product['material_cost'];
            $laborCost = $product['labor_cost'];
            $overheadCost = $product['overhead_cost'];

            // Gross profit
            $product['gross_profit'] = $revenue - $cost;
            $product['gross_margin_percent'] = $revenue > 0 
                ? ($product['gross_profit'] / $revenue) * 100 
                : 0;

            // Contribution margin (Revenue - Variable costs)
            // Assuming material and labor are variable costs, overhead is fixed
            $variableCosts = $materialCost + $laborCost;
            $product['contribution_margin'] = $revenue - $variableCosts;
            $product['contribution_margin_percent'] = $revenue > 0 
                ? ($product['contribution_margin'] / $revenue) * 100 
                : 0;

            // Per unit metrics
            $product['revenue_per_unit'] = $units > 0 ? $revenue / $units : 0;
            $product['cost_per_unit'] = $units > 0 ? $cost / $units : 0;
            $product['profit_per_unit'] = $units > 0 ? $product['gross_profit'] / $units : 0;

            // Cost breakdown
            $product['cost_breakdown'] = $this->calculateCostBreakdown(
                $materialCost,
                $laborCost,
                $overheadCost
            );

            // Break-even analysis
            $product['break_even_units'] = $this->calculateBreakEvenUnits(
                $product['revenue_per_unit'],
                $product['cost_per_unit'],
                $overheadCost
            );

            // Pricing recommendations
            $product['pricing_recommendations'] = $this->generatePricingRecommendations(
                $product['cost_per_unit']
            );

            // Set defaults for optional fields
            if (!isset($product['category'])) {
                $product['category'] = 'General';
            }

            return $product;
        }, $products);
    }

    /**
     * Calculate cost breakdown percentages
     * 
     * @param float $materialCost Material cost
     * @param float $laborCost Labor cost
     * @param float $overheadCost Overhead cost
     * 
     * @return array Cost breakdown with percentages
     */
    private function calculateCostBreakdown(
        float $materialCost, 
        float $laborCost, 
        float $overheadCost
    ): array {
        $totalCost = $materialCost + $laborCost + $overheadCost;

        if ($totalCost == 0) {
            return [
                'material_percent' => 0,
                'labor_percent' => 0,
                'overhead_percent' => 0
            ];
        }

        return [
            'material_percent' => ($materialCost / $totalCost) * 100,
            'labor_percent' => ($laborCost / $totalCost) * 100,
            'overhead_percent' => ($overheadCost / $totalCost) * 100
        ];
    }

    /**
     * Calculate break-even units
     * 
     * @param float $pricePerUnit Price per unit
     * @param float $costPerUnit Cost per unit
     * @param float $fixedCosts Fixed costs
     * 
     * @return int Break-even units
     */
    private function calculateBreakEvenUnits(
        float $pricePerUnit, 
        float $costPerUnit, 
        float $fixedCosts
    ): int {
        $contributionPerUnit = $pricePerUnit - $costPerUnit;
        
        if ($contributionPerUnit <= 0) {
            return 0;
        }

        return (int) ceil($fixedCosts / $contributionPerUnit);
    }

    /**
     * Generate pricing recommendations for target margins
     * 
     * @param float $costPerUnit Cost per unit
     * 
     * @return array Recommended prices for various target margins
     */
    private function generatePricingRecommendations(float $costPerUnit): array
    {
        return [
            'target_price_30_margin' => $this->calculatePriceForMargin($costPerUnit, self::TARGET_MARGIN_30),
            'target_price_40_margin' => $this->calculatePriceForMargin($costPerUnit, self::TARGET_MARGIN_40),
            'target_price_50_margin' => $this->calculatePriceForMargin($costPerUnit, self::TARGET_MARGIN_50)
        ];
    }

    /**
     * Calculate price needed to achieve target margin
     * 
     * @param float $cost Cost
     * @param float $targetMarginPercent Target margin percentage
     * 
     * @return float Required price
     */
    private function calculatePriceForMargin(float $cost, float $targetMarginPercent): float
    {
        // Price = Cost / (1 - Target Margin)
        return $cost / (1 - ($targetMarginPercent / 100));
    }

    /**
     * Calculate contribution percentages for all products
     * 
     * @param array $products All products
     * 
     * @return array Products with contribution percentages
     */
    private function calculateContributions(array $products): array
    {
        $totalRevenue = array_sum(array_column($products, 'sales_revenue'));
        $totalProfit = array_sum(array_column($products, 'gross_profit'));

        return array_map(function ($product) use ($totalRevenue, $totalProfit) {
            // Revenue contribution
            $product['revenue_contribution_percent'] = $totalRevenue > 0 
                ? ($product['sales_revenue'] / $totalRevenue) * 100 
                : 0;

            // Profit contribution
            $product['profit_contribution_percent'] = $totalProfit > 0 
                ? ($product['gross_profit'] / $totalProfit) * 100 
                : 0;

            return $product;
        }, $products);
    }

    /**
     * Identify top profitable products
     * 
     * @param array $products All products sorted by profit
     * 
     * @return array Top 10 most profitable products
     */
    private function identifyTopProfitable(array $products): array
    {
        return array_slice($products, 0, 10);
    }

    /**
     * Identify least profitable products
     * 
     * @param array $products All products
     * 
     * @return array Bottom 10 least profitable products
     */
    private function identifyLeastProfitable(array $products): array
    {
        return array_slice(array_reverse($products), 0, 10);
    }

    /**
     * Generate summary statistics
     * 
     * @param array $products All products
     * 
     * @return array Summary metrics
     */
    private function generateSummary(array $products): array
    {
        $totalProducts = count($products);
        $totalUnits = array_sum(array_column($products, 'units_sold'));
        $totalRevenue = array_sum(array_column($products, 'sales_revenue'));
        $totalCost = array_sum(array_column($products, 'cost_of_goods'));
        $totalProfit = array_sum(array_column($products, 'gross_profit'));

        return [
            'total_products' => $totalProducts,
            'total_units_sold' => $totalUnits,
            'total_revenue' => $totalRevenue,
            'total_cost' => $totalCost,
            'total_profit' => $totalProfit,
            'overall_margin_percent' => $totalRevenue > 0 
                ? ($totalProfit / $totalRevenue) * 100 
                : 0,
            'avg_profit_per_product' => $totalProducts > 0 
                ? $totalProfit / $totalProducts 
                : 0,
            'high_margin_products' => count(array_filter(
                $products, 
                fn($p) => $p['gross_margin_percent'] >= self::HIGH_MARGIN_THRESHOLD
            )),
            'low_margin_products' => count(array_filter(
                $products, 
                fn($p) => $p['gross_margin_percent'] < self::LOW_MARGIN_THRESHOLD && 
                          $p['gross_margin_percent'] >= self::UNPROFITABLE_THRESHOLD
            )),
            'unprofitable_products' => count(array_filter(
                $products, 
                fn($p) => $p['gross_margin_percent'] < self::UNPROFITABLE_THRESHOLD
            ))
        ];
    }

    /**
     * Get empty result structure
     * 
     * @return array Empty result
     */
    private function getEmptyResult(): array
    {
        return [
            'products' => [],
            'summary' => [
                'total_products' => 0,
                'total_units_sold' => 0,
                'total_revenue' => 0,
                'total_cost' => 0,
                'total_profit' => 0,
                'overall_margin_percent' => 0,
                'avg_profit_per_product' => 0,
                'high_margin_products' => 0,
                'low_margin_products' => 0,
                'unprofitable_products' => 0
            ],
            'top_profitable' => [],
            'least_profitable' => []
        ];
    }

    /**
     * Calculate profitability trend from monthly data
     * 
     * @param array $monthlyData Monthly profitability data
     * 
     * @return string Trend direction (improving/declining/stable)
     */
    private function calculateProfitabilityTrend(array $monthlyData): string
    {
        if (count($monthlyData) < 3) {
            return 'stable';
        }

        $recent = array_slice($monthlyData, -3);
        $margins = array_column($recent, 'margin');

        // Simple trend: compare first and last margin
        if ($margins[2] > $margins[0] + 5) {
            return 'improving';
        } elseif ($margins[2] < $margins[0] - 5) {
            return 'declining';
        } else {
            return 'stable';
        }
    }
}
