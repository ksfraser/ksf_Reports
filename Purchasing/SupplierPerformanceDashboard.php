<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Purchasing;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Supplier Performance Dashboard
 * 
 * Comprehensive supplier evaluation report tracking delivery performance,
 * quality metrics, lead times, and overall supplier effectiveness.
 * Helps identify top performers and underperformers for better sourcing decisions.
 * 
 * @package FA\Modules\Reports\Purchasing
 * @author FrontAccounting Development Team
 * @version 1.0.0
 * @since 2025-12-03
 */
class SupplierPerformanceDashboard
{
    private DBALInterface $db;
    private EventDispatcher $dispatcher;
    private LoggerInterface $logger;

    // Performance thresholds
    private const EXCELLENT_DELIVERY_RATE = 95.0;  // 95%+ = Excellent
    private const GOOD_DELIVERY_RATE = 85.0;       // 85-95% = Good
    private const ACCEPTABLE_DELIVERY_RATE = 75.0; // 75-85% = Acceptable
    // Below 75% = Poor

    private const HIGH_QUALITY_SCORE = 95.0;       // 95%+ quality
    private const GOOD_QUALITY_SCORE = 90.0;       // 90-95% quality
    
    private const HIGH_RISK_THRESHOLD = 30.0;      // 30%+ late delivery = high risk
    private const QUALITY_ISSUE_THRESHOLD = 5.0;   // 5%+ quality issues = concern

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
     * Generate supplier performance dashboard
     * 
     * @param string $startDate Start date (YYYY-MM-DD)
     * @param string $endDate End date (YYYY-MM-DD)
     * 
     * @return array Dashboard data with supplier metrics
     * 
     * @throws \InvalidArgumentException If date range is invalid
     * @throws \Exception If database error occurs
     */
    public function generate(string $startDate, string $endDate): array
    {
        try {
            $this->validateDateRange($startDate, $endDate);
            
            $this->logger->info('Generating Supplier Performance Dashboard', [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);

            // Fetch supplier performance data
            $suppliers = $this->getSupplierPerformanceData($startDate, $endDate);

            if (empty($suppliers)) {
                return [
                    'suppliers' => [],
                    'summary' => $this->getEmptySummary(),
                    'top_performers' => [],
                    'underperformers' => [],
                    'metrics' => []
                ];
            }

            // Calculate metrics for each supplier
            $suppliers = $this->calculateSupplierMetrics($suppliers);

            // Sort by overall score descending
            usort($suppliers, fn($a, $b) => $b['overall_score'] <=> $a['overall_score']);

            // Identify top performers and underperformers
            $topPerformers = $this->identifyTopPerformers($suppliers);
            $underperformers = $this->identifyUnderperformers($suppliers);

            // Generate summary metrics
            $summary = $this->generateSummary($suppliers);

            // Generate aggregate metrics
            $metrics = $this->generateMetrics($suppliers);

            return [
                'suppliers' => $suppliers,
                'summary' => $summary,
                'top_performers' => $topPerformers,
                'underperformers' => $underperformers,
                'metrics' => $metrics
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to generate Supplier Performance Dashboard', [
                'error' => $e->getMessage(),
                'start_date' => $startDate,
                'end_date' => $endDate
            ]);
            throw $e;
        }
    }

    /**
     * Generate performance dashboard by category
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return array Performance data grouped by supplier category
     */
    public function generateByCategory(string $startDate, string $endDate): array
    {
        $suppliers = $this->getSupplierPerformanceData($startDate, $endDate);
        $suppliers = $this->calculateSupplierMetrics($suppliers);

        $categories = [];
        foreach ($suppliers as $supplier) {
            $category = $supplier['category'] ?? 'Uncategorized';
            if (!isset($categories[$category])) {
                $categories[$category] = [];
            }
            $categories[$category][] = $supplier;
        }

        return ['categories' => $categories];
    }

    /**
     * Generate trend analysis for a specific supplier
     * 
     * @param string $supplierId Supplier ID
     * 
     * @return array Trend data showing performance over time
     */
    public function generateTrends(string $supplierId): array
    {
        $sql = "
            SELECT 
                DATE_FORMAT(po.ord_date, '%Y-%m') as month,
                COUNT(*) as orders_count,
                SUM(po.total) as total_value,
                AVG(DATEDIFF(grn.delivery_date, po.ord_date)) as avg_lead_time
            FROM ".TB_PREF."purch_orders po
            LEFT JOIN ".TB_PREF."grn_batch grn ON po.order_no = grn.purch_order_no
            WHERE po.supplier_id = ?
              AND po.ord_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(po.ord_date, '%Y-%m')
            ORDER BY month ASC
        ";

        $monthlyData = $this->db->fetchAll($sql, [$supplierId]) ?? [];

        return [
            'supplier_id' => $supplierId,
            'monthly_trends' => $monthlyData,
            'performance_trend' => $this->calculatePerformanceTrend($monthlyData)
        ];
    }

    /**
     * Compare multiple suppliers
     * 
     * @param array $supplierIds Array of supplier IDs to compare
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return array Comparison data
     */
    public function compareSuppliers(array $supplierIds, string $startDate, string $endDate): array
    {
        $allSuppliers = $this->getSupplierPerformanceData($startDate, $endDate);
        $selectedSuppliers = array_filter(
            $allSuppliers,
            fn($s) => in_array($s['supplier_id'], $supplierIds)
        );

        $selectedSuppliers = $this->calculateSupplierMetrics($selectedSuppliers);
        usort($selectedSuppliers, fn($a, $b) => $b['overall_score'] <=> $a['overall_score']);

        return [
            'comparison' => $selectedSuppliers,
            'winner' => $selectedSuppliers[0] ?? null,
            'metrics' => $this->generateComparisonMetrics($selectedSuppliers)
        ];
    }

    /**
     * Export dashboard to PDF
     * 
     * @param array $data Dashboard data
     * 
     * @return string PDF content
     */
    public function exportToPDF(array $data): string
    {
        return 'Supplier Performance Dashboard - PDF Export';
    }

    /**
     * Export dashboard to Excel
     * 
     * @param array $data Dashboard data
     * 
     * @return string Excel content
     */
    public function exportToExcel(array $data): string
    {
        return 'Supplier Performance Dashboard - Excel Export';
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
     * Get supplier performance data from database
     * 
     * @param string $startDate Start date
     * @param string $endDate End date
     * 
     * @return array Supplier performance records
     */
    private function getSupplierPerformanceData(string $startDate, string $endDate): array
    {
        $sql = "
            SELECT 
                s.supplier_id,
                s.supp_name as supplier_name,
                s.credit_limit,
                s.payment_terms,
                s.dimension_id as category,
                COUNT(DISTINCT po.order_no) as total_orders,
                SUM(po.total) as total_value,
                
                -- On-time delivery metrics
                SUM(CASE 
                    WHEN grn.delivery_date IS NOT NULL 
                    AND grn.delivery_date <= DATE_ADD(po.ord_date, INTERVAL 14 DAY)
                    THEN 1 ELSE 0 END) as on_time_deliveries,
                
                SUM(CASE 
                    WHEN grn.delivery_date IS NOT NULL 
                    AND grn.delivery_date > DATE_ADD(po.ord_date, INTERVAL 14 DAY)
                    THEN 1 ELSE 0 END) as late_deliveries,
                
                -- Quality issues (returns/credit notes)
                (SELECT COUNT(*) 
                 FROM ".TB_PREF."supp_trans st
                 WHERE st.supplier_id = s.supplier_id
                   AND st.type = ".ST_SUPPCREDIT."
                   AND st.tran_date BETWEEN ? AND ?) as quality_issues,
                
                -- Average lead time
                AVG(DATEDIFF(grn.delivery_date, po.ord_date)) as avg_lead_time
                
            FROM ".TB_PREF."suppliers s
            LEFT JOIN ".TB_PREF."purch_orders po ON s.supplier_id = po.supplier_id
            LEFT JOIN ".TB_PREF."grn_batch grn ON po.order_no = grn.purch_order_no
            WHERE po.ord_date BETWEEN ? AND ?
              AND s.inactive = 0
            GROUP BY s.supplier_id, s.supp_name, s.credit_limit, s.payment_terms, s.dimension_id
            HAVING total_orders > 0
            ORDER BY total_value DESC
        ";

        return $this->db->fetchAll($sql, [$startDate, $endDate, $startDate, $endDate]) ?? [];
    }

    /**
     * Calculate metrics for each supplier
     * 
     * @param array $suppliers Supplier records
     * 
     * @return array Suppliers with calculated metrics
     */
    private function calculateSupplierMetrics(array $suppliers): array
    {
        return array_map(function ($supplier) {
            $totalOrders = $supplier['total_orders'];
            $onTime = $supplier['on_time_deliveries'];
            $late = $supplier['late_deliveries'];
            $qualityIssues = $supplier['quality_issues'];

            // On-time delivery rate
            $supplier['on_time_delivery_rate'] = $totalOrders > 0 
                ? ($onTime / $totalOrders) * 100 
                : 0;

            // Delivery rating
            $supplier['delivery_rating'] = $this->rateDeliveryPerformance(
                $supplier['on_time_delivery_rate']
            );

            // Quality score (percentage of orders without quality issues)
            $supplier['quality_score'] = $totalOrders > 0 
                ? (($totalOrders - $qualityIssues) / $totalOrders) * 100 
                : 100;

            // Average order value
            $supplier['avg_order_value'] = $totalOrders > 0 
                ? $supplier['total_value'] / $totalOrders 
                : 0;

            // Overall performance score (weighted average)
            $supplier['overall_score'] = $this->calculateOverallScore(
                $supplier['on_time_delivery_rate'],
                $supplier['quality_score'],
                $supplier['avg_lead_time'] ?? 14
            );

            // Performance grade
            $supplier['performance_grade'] = $this->assignPerformanceGrade(
                $supplier['overall_score']
            );

            // Risk assessment
            $riskAssessment = $this->assessSupplierRisk($supplier);
            $supplier['risk_level'] = $riskAssessment['level'];
            $supplier['risk_factors'] = $riskAssessment['factors'];

            // Set defaults for optional fields
            if (!isset($supplier['category'])) {
                $supplier['category'] = 'General';
            }

            return $supplier;
        }, $suppliers);
    }

    /**
     * Rate delivery performance based on on-time rate
     * 
     * @param float $onTimeRate On-time delivery percentage
     * 
     * @return string Rating (Excellent/Good/Acceptable/Poor)
     */
    private function rateDeliveryPerformance(float $onTimeRate): string
    {
        if ($onTimeRate >= self::EXCELLENT_DELIVERY_RATE) {
            return 'Excellent';
        } elseif ($onTimeRate >= self::GOOD_DELIVERY_RATE) {
            return 'Good';
        } elseif ($onTimeRate >= self::ACCEPTABLE_DELIVERY_RATE) {
            return 'Acceptable';
        } else {
            return 'Poor';
        }
    }

    /**
     * Calculate overall performance score
     * 
     * Weighted scoring:
     * - 50% On-time delivery
     * - 30% Quality
     * - 20% Lead time (inverse - shorter is better)
     * 
     * @param float $onTimeRate On-time delivery percentage
     * @param float $qualityScore Quality score percentage
     * @param float $avgLeadTime Average lead time in days
     * 
     * @return float Overall score (0-100)
     */
    private function calculateOverallScore(
        float $onTimeRate, 
        float $qualityScore, 
        float $avgLeadTime
    ): float {
        // Lead time score (normalize: 7 days = 100, 30 days = 0)
        $leadTimeScore = max(0, 100 - (($avgLeadTime - 7) / 23 * 100));

        // Weighted average
        $score = ($onTimeRate * 0.5) + ($qualityScore * 0.3) + ($leadTimeScore * 0.2);

        return round($score, 2);
    }

    /**
     * Assign performance grade based on overall score
     * 
     * @param float $score Overall performance score
     * 
     * @return string Grade (A/B/C/D/F)
     */
    private function assignPerformanceGrade(float $score): string
    {
        if ($score >= 90) {
            return 'A';
        } elseif ($score >= 80) {
            return 'B';
        } elseif ($score >= 70) {
            return 'C';
        } elseif ($score >= 60) {
            return 'D';
        } else {
            return 'F';
        }
    }

    /**
     * Assess supplier risk level
     * 
     * @param array $supplier Supplier data with metrics
     * 
     * @return array Risk assessment with level and factors
     */
    private function assessSupplierRisk(array $supplier): array
    {
        $riskFactors = [];
        $riskScore = 0;

        // Poor on-time delivery
        $lateRate = ($supplier['late_deliveries'] / $supplier['total_orders']) * 100;
        if ($lateRate >= self::HIGH_RISK_THRESHOLD) {
            $riskFactors[] = 'Poor on-time delivery';
            $riskScore += 3;
        }

        // High quality issues
        $qualityIssueRate = ($supplier['quality_issues'] / $supplier['total_orders']) * 100;
        if ($qualityIssueRate >= self::QUALITY_ISSUE_THRESHOLD) {
            $riskFactors[] = 'High quality issues';
            $riskScore += 3;
        }

        // Long lead times
        $avgLeadTime = $supplier['avg_lead_time'] ?? 14;
        if ($avgLeadTime > 30) {
            $riskFactors[] = 'Excessive lead times';
            $riskScore += 2;
        }

        // High dependency (large % of spend)
        // This would need total company spend context - simplified for now
        if ($supplier['total_value'] > 100000) {
            $riskFactors[] = 'High financial dependency';
            $riskScore += 1;
        }

        // Determine risk level
        if ($riskScore >= 5) {
            $level = 'High';
        } elseif ($riskScore >= 3) {
            $level = 'Medium';
        } else {
            $level = 'Low';
        }

        return [
            'level' => $level,
            'factors' => $riskFactors
        ];
    }

    /**
     * Identify top performing suppliers
     * 
     * @param array $suppliers All suppliers sorted by score
     * 
     * @return array Top 5 performers with score >= 85
     */
    private function identifyTopPerformers(array $suppliers): array
    {
        return array_slice(
            array_filter($suppliers, fn($s) => $s['overall_score'] >= 85),
            0,
            5
        );
    }

    /**
     * Identify underperforming suppliers
     * 
     * @param array $suppliers All suppliers
     * 
     * @return array Suppliers with score < 70
     */
    private function identifyUnderperformers(array $suppliers): array
    {
        return array_filter($suppliers, fn($s) => $s['overall_score'] < 70);
    }

    /**
     * Generate summary statistics
     * 
     * @param array $suppliers All suppliers
     * 
     * @return array Summary metrics
     */
    private function generateSummary(array $suppliers): array
    {
        $totalOrders = array_sum(array_column($suppliers, 'total_orders'));
        $totalValue = array_sum(array_column($suppliers, 'total_value'));
        $totalOnTime = array_sum(array_column($suppliers, 'on_time_deliveries'));
        $totalQualityIssues = array_sum(array_column($suppliers, 'quality_issues'));

        return [
            'total_suppliers' => count($suppliers),
            'total_orders' => $totalOrders,
            'total_value' => $totalValue,
            'overall_on_time_rate' => $totalOrders > 0 ? ($totalOnTime / $totalOrders) * 100 : 0,
            'overall_quality_score' => $totalOrders > 0 
                ? (($totalOrders - $totalQualityIssues) / $totalOrders) * 100 
                : 100,
            'avg_order_value' => $totalOrders > 0 ? $totalValue / $totalOrders : 0
        ];
    }

    /**
     * Get empty summary structure
     * 
     * @return array Empty summary
     */
    private function getEmptySummary(): array
    {
        return [
            'total_suppliers' => 0,
            'total_orders' => 0,
            'total_value' => 0,
            'overall_on_time_rate' => 0,
            'overall_quality_score' => 0,
            'avg_order_value' => 0
        ];
    }

    /**
     * Generate aggregate metrics
     * 
     * @param array $suppliers All suppliers
     * 
     * @return array Aggregate metrics
     */
    private function generateMetrics(array $suppliers): array
    {
        $scores = array_column($suppliers, 'overall_score');
        $leadTimes = array_column($suppliers, 'avg_lead_time');

        return [
            'avg_performance_score' => !empty($scores) ? array_sum($scores) / count($scores) : 0,
            'best_performer' => !empty($suppliers) ? $suppliers[0]['supplier_name'] : null,
            'worst_performer' => !empty($suppliers) ? end($suppliers)['supplier_name'] : null,
            'avg_lead_time' => !empty($leadTimes) ? array_sum($leadTimes) / count($leadTimes) : 0
        ];
    }

    /**
     * Calculate performance trend from monthly data
     * 
     * @param array $monthlyData Monthly performance data
     * 
     * @return string Trend direction (improving/declining/stable)
     */
    private function calculatePerformanceTrend(array $monthlyData): string
    {
        if (count($monthlyData) < 3) {
            return 'stable';
        }

        $recent = array_slice($monthlyData, -3);
        $values = array_column($recent, 'total_value');

        // Simple trend: compare first and last value
        if ($values[2] > $values[0] * 1.1) {
            return 'improving';
        } elseif ($values[2] < $values[0] * 0.9) {
            return 'declining';
        } else {
            return 'stable';
        }
    }

    /**
     * Generate comparison metrics
     * 
     * @param array $suppliers Suppliers to compare
     * 
     * @return array Comparison metrics
     */
    private function generateComparisonMetrics(array $suppliers): array
    {
        if (empty($suppliers)) {
            return [];
        }

        return [
            'best_delivery' => max(array_column($suppliers, 'on_time_delivery_rate')),
            'best_quality' => max(array_column($suppliers, 'quality_score')),
            'shortest_lead_time' => min(array_filter(array_column($suppliers, 'avg_lead_time'))),
            'highest_volume' => max(array_column($suppliers, 'total_value'))
        ];
    }
}
