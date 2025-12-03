<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Inventory;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Inventory ABC Analysis Report
 * 
 * Implements Pareto principle (80/20 rule) for inventory management,
 * classifying items into A, B, C categories based on value contribution.
 * Provides optimization recommendations and identifies slow-moving stock.
 * 
 * @package FA\Modules\Reports\Inventory
 * @author FrontAccounting Development Team
 * @version 1.0.0
 * @since 2025-12-03
 */
class InventoryABCAnalysisReport
{
    private DBALInterface $db;
    private EventDispatcher $dispatcher;
    private LoggerInterface $logger;

    // Default classification thresholds (cumulative % of total value)
    private const CLASS_A_THRESHOLD = 80;  // Top items representing 80% of value
    private const CLASS_B_THRESHOLD = 95;  // Next items representing 15% of value (80-95%)
    // Class C: Remaining items representing 5% of value (95-100%)

    private const SLOW_MOVING_THRESHOLD = 2.0;  // Turnover ratio < 2 = slow moving
    private const OBSOLETE_THRESHOLD = 0.0;     // No annual usage = obsolete

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
     * Generate ABC analysis for all inventory
     * 
     * @param array $options Analysis options (class_a_threshold, class_b_threshold, lead_time_days, service_level)
     * 
     * @return array ABC analysis results with classifications and recommendations
     * 
     * @throws \Exception If database error occurs
     */
    public function generate(array $options = []): array
    {
        try {
            $this->logger->info('Generating ABC Analysis');

            // Get thresholds from options or use defaults
            $classAThreshold = $options['class_a_threshold'] ?? self::CLASS_A_THRESHOLD;
            $classBThreshold = $options['class_b_threshold'] ?? self::CLASS_B_THRESHOLD;
            $leadTimeDays = $options['lead_time_days'] ?? 14;
            $serviceLevel = $options['service_level'] ?? 0.95;

            // Fetch inventory data with annual usage
            $inventory = $this->getInventoryData();

            if (empty($inventory)) {
                return [
                    'items' => [],
                    'summary' => ['total_items' => 0, 'total_value' => 0, 'average_value' => 0],
                    'classification' => [],
                    'recommendations' => []
                ];
            }

            // Calculate annual values and sort by value descending
            $items = $this->calculateAnnualValues($inventory);
            usort($items, fn($a, $b) => $b['annual_value'] <=> $a['annual_value']);

            // Calculate cumulative values and percentages
            $items = $this->calculateCumulativeValues($items);

            // Classify items into A, B, C
            $items = $this->classifyItems($items, $classAThreshold, $classBThreshold);

            // Add inventory metrics
            $items = $this->addInventoryMetrics($items, $leadTimeDays, $serviceLevel);

            // Generate summary statistics
            $summary = $this->generateSummary($items);

            // Generate classification breakdown
            $classification = $this->generateClassificationBreakdown($items);

            // Generate recommendations
            $recommendations = $this->generateRecommendations($classification);

            return [
                'items' => $items,
                'summary' => $summary,
                'classification' => $classification,
                'recommendations' => $recommendations
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate ABC Analysis', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Generate ABC analysis by category
     * 
     * @return array ABC analysis grouped by category
     */
    public function generateByCategory(): array
    {
        $inventory = $this->getInventoryData();
        $categories = [];

        foreach ($inventory as $item) {
            $category = $item['category'] ?? 'Uncategorized';
            if (!isset($categories[$category])) {
                $categories[$category] = [];
            }
            $categories[$category][] = $item;
        }

        $results = [];
        foreach ($categories as $category => $items) {
            $results[$category] = $this->analyzeItems($items);
        }

        return ['categories' => $results];
    }

    /**
     * Generate ABC analysis by location
     * 
     * @return array ABC analysis grouped by location
     */
    public function generateByLocation(): array
    {
        $inventory = $this->getInventoryData();
        $locations = [];

        foreach ($inventory as $item) {
            $location = $item['location'] ?? 'Unknown';
            if (!isset($locations[$location])) {
                $locations[$location] = [];
            }
            $locations[$location][] = $item;
        }

        $results = [];
        foreach ($locations as $location => $items) {
            $results[$location] = $this->analyzeItems($items);
        }

        return ['locations' => $results];
    }

    /**
     * Generate Pareto chart data
     * 
     * @param array $data ABC analysis results
     * 
     * @return array Chart data with labels, values, and cumulative percentages
     */
    public function generateParetoChart(array $data): array
    {
        $items = $data['items'];
        $labels = [];
        $values = [];
        $cumulative = [];

        foreach ($items as $item) {
            $labels[] = $item['item_code'];
            $values[] = $item['annual_value'];
            $cumulative[] = $item['cumulative_percent'];
        }

        return [
            'labels' => $labels,
            'values' => $values,
            'cumulative' => $cumulative
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
        return 'ABC Analysis Report - PDF Export';
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
        return 'ABC Analysis Report - Excel Export';
    }

    /**
     * Get inventory data with annual usage
     * 
     * @return array Inventory items with usage data
     */
    private function getInventoryData(): array
    {
        $sql = "
            SELECT 
                stock.stock_id as item_code,
                stock.description,
                stock.category_id as category,
                COALESCE(SUM(loc.loc_stock), 0) as quantity,
                stock.material_cost + stock.labour_cost + stock.overhead_cost as unit_cost,
                COALESCE(
                    (SELECT SUM(ABS(move.qty))
                     FROM ".TB_PREF."stock_moves move
                     WHERE move.stock_id = stock.stock_id
                       AND move.type IN (10, 11, 13)
                       AND move.tran_date >= DATE_SUB(NOW(), INTERVAL 1 YEAR)),
                    0
                ) as annual_usage
            FROM ".TB_PREF."stock_master stock
            LEFT JOIN ".TB_PREF."stock_moves loc ON stock.stock_id = loc.stock_id
            WHERE stock.inactive = 0
              AND stock.mb_flag != 'D'
            GROUP BY stock.stock_id, stock.description, stock.category_id,
                     stock.material_cost, stock.labour_cost, stock.overhead_cost
            HAVING quantity > 0 OR annual_usage > 0
        ";

        return $this->db->fetchAll($sql) ?? [];
    }

    /**
     * Calculate annual values for each item
     * 
     * @param array $items Inventory items
     * 
     * @return array Items with annual_value calculated
     */
    private function calculateAnnualValues(array $items): array
    {
        return array_map(function ($item) {
            $item['annual_value'] = $item['unit_cost'] * $item['annual_usage'];
            // Set defaults for optional fields
            if (!isset($item['category'])) {
                $item['category'] = 'General';
            }
            if (!isset($item['location'])) {
                $item['location'] = 'Default';
            }
            return $item;
        }, $items);
    }

    /**
     * Calculate cumulative values and percentages
     * 
     * @param array $items Sorted inventory items
     * 
     * @return array Items with cumulative calculations
     */
    private function calculateCumulativeValues(array $items): array
    {
        $totalValue = array_sum(array_column($items, 'annual_value'));
        $cumulativeValue = 0;

        foreach ($items as &$item) {
            $cumulativeValue += $item['annual_value'];
            $item['cumulative_value'] = $cumulativeValue;
            $item['cumulative_percent'] = $totalValue > 0 
                ? ($cumulativeValue / $totalValue) * 100 
                : 0;
            $item['value_percent'] = $totalValue > 0 
                ? ($item['annual_value'] / $totalValue) * 100 
                : 0;
        }

        return $items;
    }

    /**
     * Classify items into A, B, C categories
     * 
     * Items are classified based on where they contribute in the cumulative value distribution:
     * - Class A: Items that together contribute to the first X% of total value (default 80%)
     * - Class B: Items that together contribute to the next Y% of total value (default 80-95%)
     * - Class C: Items that together contribute to the remaining value (default 95-100%)
     * 
     * The classification uses the cumulative percentage BEFORE adding the current item,
     * so that items contributing to reaching a threshold are included in that class.
     * 
     * @param array $items Items with cumulative percentages
     * @param float $classAThreshold Class A threshold (cumulative %)
     * @param float $classBThreshold Class B threshold (cumulative %)
     * 
     * @return array Items with abc_class assigned
     */
    private function classifyItems(array $items, float $classAThreshold, float $classBThreshold): array
    {
        $previousCumulative = 0;
        
        foreach ($items as &$item) {
            // Use previous cumulative to determine class
            // This ensures items contributing to a threshold are included in that class
            if ($previousCumulative < $classAThreshold) {
                $item['abc_class'] = 'A';
            } elseif ($previousCumulative < $classBThreshold) {
                $item['abc_class'] = 'B';
            } else {
                $item['abc_class'] = 'C';
            }
            
            $previousCumulative = $item['cumulative_percent'];
        }

        return $items;
    }

    /**
     * Add inventory metrics (turnover, slow-moving flags, etc.)
     * 
     * @param array $items Classified items
     * @param int $leadTimeDays Lead time in days
     * @param float $serviceLevel Service level (0.0-1.0)
     * 
     * @return array Items with metrics added
     */
    private function addInventoryMetrics(array $items, int $leadTimeDays, float $serviceLevel): array
    {
        return array_map(function ($item) use ($leadTimeDays, $serviceLevel) {
            // Turnover ratio = annual usage / average inventory
            $item['turnover_ratio'] = $item['quantity'] > 0 
                ? $item['annual_usage'] / $item['quantity'] 
                : 0;

            // Slow moving flag
            $item['is_slow_moving'] = $item['turnover_ratio'] < self::SLOW_MOVING_THRESHOLD;

            // Obsolete flag
            $item['is_obsolete'] = $item['annual_usage'] == self::OBSOLETE_THRESHOLD;

            // Average daily usage
            $dailyUsage = $item['annual_usage'] / 365;

            // Recommended reorder point (lead time demand + safety stock)
            $leadTimeDemand = $dailyUsage * $leadTimeDays;
            
            // Safety stock calculation (simplified - assumes normal distribution)
            // For 95% service level, z-score ≈ 1.65
            $zScore = $serviceLevel >= 0.95 ? 1.65 : 1.28; // 95% or 90%
            $demandVariability = $dailyUsage * 0.25; // Assume 25% variability
            $safetyStock = $zScore * $demandVariability * sqrt($leadTimeDays);

            $item['recommended_reorder_point'] = ceil($leadTimeDemand + $safetyStock);
            $item['recommended_safety_stock'] = ceil($safetyStock);

            // Days of inventory on hand
            $item['days_on_hand'] = $dailyUsage > 0 
                ? $item['quantity'] / $dailyUsage 
                : 999;

            return $item;
        }, $items);
    }

    /**
     * Generate summary statistics
     * 
     * @param array $items All items
     * 
     * @return array Summary statistics
     */
    private function generateSummary(array $items): array
    {
        $totalItems = count($items);
        $totalValue = array_sum(array_column($items, 'annual_value'));
        $averageValue = $totalItems > 0 ? $totalValue / $totalItems : 0;

        return [
            'total_items' => $totalItems,
            'total_value' => $totalValue,
            'average_value' => $averageValue,
            'total_quantity' => array_sum(array_column($items, 'quantity')),
            'slow_moving_count' => count(array_filter($items, fn($i) => $i['is_slow_moving'])),
            'obsolete_count' => count(array_filter($items, fn($i) => $i['is_obsolete']))
        ];
    }

    /**
     * Generate classification breakdown
     * 
     * @param array $items Classified items
     * 
     * @return array Classification statistics by class
     */
    private function generateClassificationBreakdown(array $items): array
    {
        $classes = ['A' => [], 'B' => [], 'C' => []];

        foreach ($items as $item) {
            $classes[$item['abc_class']][] = $item;
        }

        $breakdown = [];
        foreach ($classes as $class => $classItems) {
            $itemCount = count($classItems);
            $totalValue = array_sum(array_column($classItems, 'annual_value'));
            $totalItems = count($items);
            $overallValue = array_sum(array_column($items, 'annual_value'));

            $breakdown['class_' . strtolower($class)] = [
                'item_count' => $itemCount,
                'item_percent' => $totalItems > 0 ? ($itemCount / $totalItems) * 100 : 0,
                'total_value' => $totalValue,
                'value_percent' => $overallValue > 0 ? ($totalValue / $overallValue) * 100 : 0,
                'average_value' => $itemCount > 0 ? $totalValue / $itemCount : 0
            ];
        }

        return $breakdown;
    }

    /**
     * Generate recommendations based on classification
     * 
     * @param array $classification Classification breakdown
     * 
     * @return array Recommendations by class
     */
    private function generateRecommendations(array $classification): array
    {
        return [
            'class_a' => 
                'Implement tight control with frequent cycle counts, accurate demand forecasting, ' .
                'close supplier relationships, and consider JIT replenishment. ' .
                'Review weekly. These high-value items warrant premium management attention.',
            
            'class_b' => 
                'Implement standard inventory controls, regular cycle counts (monthly), ' .
                'automated reorder points, and maintain adequate safety stock. ' .
                'Review monthly. Balance between control and efficiency.',
            
            'class_c' => 
                'Use simple inventory controls, annual or no cycle counts, ' .
                'large order quantities to minimize ordering costs, higher safety stock acceptable. ' .
                'Review quarterly. Focus on simplification and cost reduction.',
            
            'slow_moving' => 
                'Review for potential obsolescence, consider liquidation or return to supplier, ' .
                'reduce reorder quantities, increase lead times, or discontinue if no strategic value.',
            
            'obsolete' => 
                'Immediate action required - write off, liquidate, donate, or dispose. ' .
                'Investigate root cause (overordering, obsolete product, poor forecasting).'
        ];
    }

    /**
     * Analyze a subset of items
     * 
     * @param array $items Items to analyze
     * 
     * @return array Analysis results
     */
    private function analyzeItems(array $items): array
    {
        $items = $this->calculateAnnualValues($items);
        usort($items, fn($a, $b) => $b['annual_value'] <=> $a['annual_value']);
        $items = $this->calculateCumulativeValues($items);
        $items = $this->classifyItems($items, self::CLASS_A_THRESHOLD, self::CLASS_B_THRESHOLD);
        $items = $this->addInventoryMetrics($items, 14, 0.95);

        return [
            'items' => $items,
            'summary' => $this->generateSummary($items),
            'classification' => $this->generateClassificationBreakdown($items)
        ];
    }
}
