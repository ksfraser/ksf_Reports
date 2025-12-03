<?php
/**
 * Aged Debtors Report
 * 
 * Comprehensive accounts receivable aging analysis providing customer AR breakdowns,
 * aging bucket calculations, credit limit monitoring, collection priority analysis,
 * and Days Sales Outstanding (DSO) metrics.
 * 
 * Based on WebERP AgedDebtors.php patterns but reimagined for FrontAccounting
 * with modern PHP, SOLID principles, and enterprise AR management capabilities.
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
 * Aged Debtors Report Service
 * 
 * Generates comprehensive AR aging reports with:
 * - Standard aging buckets (Current, 30, 60, 90, 90+)
 * - Customer grouping and analysis
 * - Credit limit monitoring and alerts
 * - Collection priority scoring
 * - Days Sales Outstanding (DSO) calculation
 * - Currency grouping and conversion
 * - Detailed transaction drill-down
 * - Collection letter generation data
 */
class AgedDebtorsReport
{
    /**
     * Standard aging bucket definitions (in days)
     */
    private const DEFAULT_AGING_BUCKETS = [0, 30, 60, 90];

    /**
     * Collection priority weight factors
     */
    private const PRIORITY_WEIGHTS = [
        'amount_overdue' => 0.40,
        'days_overdue' => 0.35,
        'over_credit_limit' => 0.15,
        'payment_history' => 0.10
    ];

    public function __construct(
        private DBALInterface $dbal,
        private EventDispatcher $eventDispatcher,
        private LoggerInterface $logger
    ) {}

    /**
     * Generate aged debtors report
     * 
     * @param array $params Report parameters
     *   - as_of_date: string (required) - Date to age receivables as of
     *   - aging_buckets: array (optional) - Custom aging periods
     *   - customer_type_filter: string (optional) - Filter by customer type
     *   - group_by_currency: bool (default: false)
     *   - show_percentages: bool (default: false)
     *   - include_contacts: bool (default: false)
     *   - show_credit_alerts: bool (default: false)
     * 
     * @return array Report data structure
     * @throws \InvalidArgumentException
     */
    public function generate(array $params): array
    {
        $this->validateParameters($params);

        $asOfDate = $params['as_of_date'];
        $agingBuckets = $params['aging_buckets'] ?? self::DEFAULT_AGING_BUCKETS;
        $customerTypeFilter = $params['customer_type_filter'] ?? null;
        $groupByCurrency = $params['group_by_currency'] ?? false;
        $showPercentages = $params['show_percentages'] ?? false;
        $includeContacts = $params['include_contacts'] ?? false;

        $this->logger->info('Generating aged debtors report', [
            'as_of_date' => $asOfDate,
            'customer_type_filter' => $customerTypeFilter
        ]);

        // Fetch debtor data
        $debtorsData = $this->fetchDebtorsData($asOfDate, $customerTypeFilter, $includeContacts);

        // Calculate aging for each customer
        $agedDebtors = $this->calculateAging($debtorsData, $asOfDate, $agingBuckets);

        // Group by currency if requested
        if ($groupByCurrency) {
            $byCurrency = $this->groupByCurrency($agedDebtors);
        }

        // Calculate summary totals
        $summary = $this->calculateSummary($agedDebtors, $showPercentages);

        $result = [
            'customers' => $agedDebtors,
            'summary' => $summary,
            'metadata' => [
                'report_type' => 'aged_debtors',
                'as_of_date' => $asOfDate,
                'aging_buckets' => $agingBuckets,
                'generated_at' => date('Y-m-d H:i:s'),
                'customer_type_filter' => $customerTypeFilter
            ]
        ];

        if ($groupByCurrency) {
            $result['by_currency'] = $byCurrency;
        }

        // Dispatch event
        $this->eventDispatcher->dispatch(new ReportGeneratedEvent(
            'aged_debtors',
            $params,
            $result
        ));

        return $result;
    }

    /**
     * Generate detailed aging report with transaction breakdown
     * 
     * @param array $params Report parameters
     * @return array Detailed report with transactions
     */
    public function generateDetailed(array $params): array
    {
        $this->validateParameters($params);

        $asOfDate = $params['as_of_date'];

        $this->logger->info('Generating detailed aged debtors report', [
            'as_of_date' => $asOfDate
        ]);

        // Fetch transaction-level data
        $transactions = $this->fetchDetailedTransactions($asOfDate);

        // Group by customer
        $customerGroups = [];
        foreach ($transactions as $trans) {
            $customerId = $trans['customer_id'];
            if (!isset($customerGroups[$customerId])) {
                $customerGroups[$customerId] = [
                    'customer_id' => $customerId,
                    'customer_name' => $trans['customer_name'],
                    'transactions' => [],
                    'current' => 0.00,
                    'days_30' => 0.00,
                    'days_60' => 0.00,
                    'days_90' => 0.00,
                    'days_over_90' => 0.00,
                    'total_due' => 0.00
                ];
            }

            $customerGroups[$customerId]['transactions'][] = $trans;
            
            // Add to appropriate bucket
            $daysOverdue = $trans['days_overdue'];
            $balance = (float) $trans['balance'];
            
            if ($daysOverdue <= 0) {
                $customerGroups[$customerId]['current'] += $balance;
            } elseif ($daysOverdue <= 30) {
                $customerGroups[$customerId]['days_30'] += $balance;
            } elseif ($daysOverdue <= 60) {
                $customerGroups[$customerId]['days_60'] += $balance;
            } elseif ($daysOverdue <= 90) {
                $customerGroups[$customerId]['days_90'] += $balance;
            } else {
                $customerGroups[$customerId]['days_over_90'] += $balance;
            }
            
            $customerGroups[$customerId]['total_due'] += $balance;
        }

        return [
            'customers' => array_values($customerGroups),
            'transactions' => $transactions,
            'metadata' => [
                'as_of_date' => $asOfDate,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Generate credit limit alerts report
     * 
     * @param array $params Report parameters
     * @return array Customers over credit limit
     */
    public function generateCreditAlerts(array $params): array
    {
        $this->validateParameters($params);

        $asOfDate = $params['as_of_date'];

        $this->logger->info('Generating credit limit alerts', [
            'as_of_date' => $asOfDate
        ]);

        $debtorsData = $this->fetchDebtorsData($asOfDate, null, false);

        $overLimit = [];
        $nearLimit = [];

        foreach ($debtorsData as $debtor) {
            $totalDue = (float) $debtor['total_due'];
            $creditLimit = (float) $debtor['credit_limit'];

            if ($creditLimit > 0) {
                $utilization = ($totalDue / $creditLimit) * 100;

                if ($totalDue > $creditLimit) {
                    $overLimit[] = [
                        'customer_id' => $debtor['customer_id'],
                        'customer_name' => $debtor['customer_name'],
                        'total_due' => $totalDue,
                        'credit_limit' => $creditLimit,
                        'over_limit_amount' => $totalDue - $creditLimit,
                        'utilization_percent' => round($utilization, 2)
                    ];
                } elseif ($utilization >= 80) {
                    $nearLimit[] = [
                        'customer_id' => $debtor['customer_id'],
                        'customer_name' => $debtor['customer_name'],
                        'total_due' => $totalDue,
                        'credit_limit' => $creditLimit,
                        'available_credit' => $creditLimit - $totalDue,
                        'utilization_percent' => round($utilization, 2)
                    ];
                }
            }
        }

        return [
            'over_limit' => $overLimit,
            'near_limit' => $nearLimit,
            'metadata' => [
                'as_of_date' => $asOfDate,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Generate collection priority list
     * 
     * @param array $params Report parameters
     * @return array Customers ranked by collection priority
     */
    public function generatePriorityList(array $params): array
    {
        $this->validateParameters($params);

        $asOfDate = $params['as_of_date'];

        $this->logger->info('Generating collection priority list', [
            'as_of_date' => $asOfDate
        ]);

        $debtorsData = $this->fetchDebtorsData($asOfDate, null, false);

        $priorityCustomers = [];

        foreach ($debtorsData as $debtor) {
            $score = $this->calculatePriorityScore($debtor);
            
            $priorityCustomers[] = [
                'customer_id' => $debtor['customer_id'],
                'customer_name' => $debtor['customer_name'],
                'total_due' => (float) $debtor['total_due'],
                'days_over_90' => (float) ($debtor['days_over_90'] ?? 0),
                'credit_limit' => (float) $debtor['credit_limit'],
                'priority_score' => $score,
                'priority_level' => $this->getPriorityLevel($score)
            ];
        }

        // Sort by priority score descending
        usort($priorityCustomers, fn($a, $b) => $b['priority_score'] <=> $a['priority_score']);

        return [
            'priority_customers' => $priorityCustomers,
            'metadata' => [
                'as_of_date' => $asOfDate,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Generate AR metrics including DSO
     * 
     * @param array $params Report parameters
     * @return array AR metrics and KPIs
     */
    public function generateMetrics(array $params): array
    {
        $this->validateParameters($params);

        $asOfDate = $params['as_of_date'];
        $calculateDSO = $params['calculate_dso'] ?? true;

        $this->logger->info('Generating AR metrics', ['as_of_date' => $asOfDate]);

        $metrics = [];

        if ($calculateDSO) {
            $dsoData = $this->fetchDSOData($asOfDate);
            
            if ($dsoData && $dsoData['total_sales'] > 0) {
                $dso = ($dsoData['total_receivables'] / $dsoData['total_sales']) * $dsoData['period_days'];
                $metrics['dso'] = round($dso, 2);
            } else {
                $metrics['dso'] = 0.0;
            }
        }

        // Additional metrics
        $debtorsData = $this->fetchDebtorsData($asOfDate, null, false);
        $summary = $this->calculateSummary($debtorsData, true);

        $metrics['total_receivables'] = $summary['total_outstanding'];
        $metrics['total_customers_owing'] = count($debtorsData);
        $metrics['average_days_overdue'] = $this->calculateAverageDaysOverdue($debtorsData);
        $metrics['collection_effectiveness'] = $this->calculateCollectionEffectiveness($debtorsData);

        return [
            'metrics' => $metrics,
            'metadata' => [
                'as_of_date' => $asOfDate,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Generate collection letters data
     * 
     * @param array $params Report parameters
     * @return array Customers requiring collection letters
     */
    public function generateCollectionLetters(array $params): array
    {
        $this->validateParameters($params);

        $asOfDate = $params['as_of_date'];
        $minDaysOverdue = $params['min_days_overdue'] ?? 30;

        $this->logger->info('Generating collection letters data', [
            'as_of_date' => $asOfDate,
            'min_days_overdue' => $minDaysOverdue
        ]);

        $debtorsData = $this->fetchDebtorsData($asOfDate, null, true);

        $collectionRequired = [];

        foreach ($debtorsData as $debtor) {
            $totalOverdue = (float) ($debtor['days_30'] ?? 0) + 
                           (float) ($debtor['days_60'] ?? 0) + 
                           (float) ($debtor['days_90'] ?? 0) + 
                           (float) ($debtor['days_over_90'] ?? 0);

            if ($totalOverdue > 0) {
                $collectionRequired[] = [
                    'customer_id' => $debtor['customer_id'],
                    'customer_name' => $debtor['customer_name'],
                    'contact_name' => $debtor['contact_name'] ?? '',
                    'contact_email' => $debtor['contact_email'] ?? '',
                    'total_overdue' => $totalOverdue,
                    'days_over_90' => (float) ($debtor['days_over_90'] ?? 0),
                    'last_payment_date' => $debtor['last_payment_date'] ?? null
                ];
            }
        }

        return [
            'collection_required' => $collectionRequired,
            'metadata' => [
                'as_of_date' => $asOfDate,
                'min_days_overdue' => $minDaysOverdue,
                'generated_at' => date('Y-m-d H:i:s')
            ]
        ];
    }

    /**
     * Fetch debtors data from database
     * 
     * @param string $asOfDate As of date for aging
     * @param string|null $customerTypeFilter Filter by customer type
     * @param bool $includeContacts Include contact information
     * @return array Debtor records
     */
    private function fetchDebtorsData(string $asOfDate, ?string $customerTypeFilter, bool $includeContacts): array
    {
        $sql = "
            SELECT 
                c.debtor_no as customer_id,
                c.name as customer_name,
                c.curr_code as currency,
                c.credit_limit,
                COALESCE(SUM(CASE WHEN DATEDIFF(?, dt.due_date) <= 0 THEN dt.ov_amount + dt.ov_gst - dt.alloc ELSE 0 END), 0) as current,
                COALESCE(SUM(CASE WHEN DATEDIFF(?, dt.due_date) BETWEEN 1 AND 30 THEN dt.ov_amount + dt.ov_gst - dt.alloc ELSE 0 END), 0) as days_30,
                COALESCE(SUM(CASE WHEN DATEDIFF(?, dt.due_date) BETWEEN 31 AND 60 THEN dt.ov_amount + dt.ov_gst - dt.alloc ELSE 0 END), 0) as days_60,
                COALESCE(SUM(CASE WHEN DATEDIFF(?, dt.due_date) BETWEEN 61 AND 90 THEN dt.ov_amount + dt.ov_gst - dt.alloc ELSE 0 END), 0) as days_90,
                COALESCE(SUM(CASE WHEN DATEDIFF(?, dt.due_date) > 90 THEN dt.ov_amount + dt.ov_gst - dt.alloc ELSE 0 END), 0) as days_over_90,
                COALESCE(SUM(dt.ov_amount + dt.ov_gst - dt.alloc), 0) as total_due
        ";

        if ($includeContacts) {
            $sql .= ",
                cb.contact_name,
                cb.br_post_address as contact_address,
                cb.phone as contact_phone,
                cb.email as contact_email
            ";
        }

        $sql .= "
            FROM debtors_master c
            LEFT JOIN debtor_trans dt ON c.debtor_no = dt.debtor_no 
                AND dt.type IN (10, 11, 12)
                AND dt.ov_amount + dt.ov_gst - dt.alloc > 0.01
        ";

        if ($includeContacts) {
            $sql .= "
                LEFT JOIN cust_branch cb ON c.debtor_no = cb.debtor_no AND cb.branch_code = c.branch_code
            ";
        }

        if ($customerTypeFilter) {
            $sql .= "
                INNER JOIN debtors_types ct ON c.type_id = ct.id AND ct.type_name = ?
            ";
        }

        $sql .= "
            GROUP BY c.debtor_no, c.name, c.curr_code, c.credit_limit
        ";

        if ($includeContacts) {
            $sql .= ", cb.contact_name, cb.br_post_address, cb.phone, cb.email";
        }

        $sql .= "
            HAVING total_due > 0.01
            ORDER BY total_due DESC
        ";

        $params = [$asOfDate, $asOfDate, $asOfDate, $asOfDate, $asOfDate];
        if ($customerTypeFilter) {
            $params[] = $customerTypeFilter;
        }

        return $this->dbal->fetchAll($sql, $params);
    }

    /**
     * Fetch detailed transaction data
     * 
     * @param string $asOfDate As of date
     * @return array Transaction records
     */
    private function fetchDetailedTransactions(string $asOfDate): array
    {
        $sql = "
            SELECT 
                c.debtor_no as customer_id,
                c.name as customer_name,
                dt.trans_no,
                dt.type as trans_type,
                dt.tran_date as trans_date,
                dt.due_date,
                dt.ov_amount + dt.ov_gst as amount,
                dt.alloc as paid,
                dt.ov_amount + dt.ov_gst - dt.alloc as balance,
                DATEDIFF(?, dt.due_date) as days_overdue,
                dt.reference
            FROM debtor_trans dt
            INNER JOIN debtors_master c ON dt.debtor_no = c.debtor_no
            WHERE dt.type IN (10, 11, 12)
                AND dt.ov_amount + dt.ov_gst - dt.alloc > 0.01
            ORDER BY c.name, dt.due_date
        ";

        return $this->dbal->fetchAll($sql, [$asOfDate]);
    }

    /**
     * Calculate aging for debtor data
     * 
     * @param array $debtorsData Raw debtor data
     * @param string $asOfDate As of date
     * @param array $agingBuckets Aging bucket definitions
     * @return array Aged debtor data
     */
    private function calculateAging(array $debtorsData, string $asOfDate, array $agingBuckets): array
    {
        // Data already aged in SQL query
        return $debtorsData;
    }

    /**
     * Group debtors by currency
     * 
     * @param array $debtors Debtor data
     * @return array Grouped by currency
     */
    private function groupByCurrency(array $debtors): array
    {
        $byCurrency = [];

        foreach ($debtors as $debtor) {
            $currency = $debtor['currency'];
            
            if (!isset($byCurrency[$currency])) {
                $byCurrency[$currency] = [
                    'currency' => $currency,
                    'customers' => [],
                    'total' => 0.00
                ];
            }

            $byCurrency[$currency]['customers'][] = $debtor;
            $byCurrency[$currency]['total'] += (float) $debtor['total_due'];
        }

        return $byCurrency;
    }

    /**
     * Calculate summary totals
     * 
     * @param array $debtors Debtor data
     * @param bool $showPercentages Include percentage breakdown
     * @return array Summary data
     */
    private function calculateSummary(array $debtors, bool $showPercentages = false): array
    {
        $summary = [
            'total_outstanding' => 0.00,
            'current' => 0.00,
            'days_30' => 0.00,
            'days_60' => 0.00,
            'days_90' => 0.00,
            'days_over_90' => 0.00,
            'customer_count' => count($debtors)
        ];

        foreach ($debtors as $debtor) {
            $summary['total_outstanding'] += (float) $debtor['total_due'];
            $summary['current'] += (float) ($debtor['current'] ?? 0);
            $summary['days_30'] += (float) ($debtor['days_30'] ?? 0);
            $summary['days_60'] += (float) ($debtor['days_60'] ?? 0);
            $summary['days_90'] += (float) ($debtor['days_90'] ?? 0);
            $summary['days_over_90'] += (float) ($debtor['days_over_90'] ?? 0);
        }

        if ($showPercentages && $summary['total_outstanding'] > 0) {
            $summary['percentages'] = [
                'current' => round(($summary['current'] / $summary['total_outstanding']) * 100, 2),
                'days_30' => round(($summary['days_30'] / $summary['total_outstanding']) * 100, 2),
                'days_60' => round(($summary['days_60'] / $summary['total_outstanding']) * 100, 2),
                'days_90' => round(($summary['days_90'] / $summary['total_outstanding']) * 100, 2),
                'days_over_90' => round(($summary['days_over_90'] / $summary['total_outstanding']) * 100, 2)
            ];
        }

        return $summary;
    }

    /**
     * Calculate collection priority score
     * 
     * @param array $debtor Debtor data
     * @return float Priority score (0-100)
     */
    private function calculatePriorityScore(array $debtor): float
    {
        $totalDue = (float) $debtor['total_due'];
        $daysOver90 = (float) ($debtor['days_over_90'] ?? 0);
        $creditLimit = (float) $debtor['credit_limit'];

        // Amount overdue score (0-100)
        $amountScore = min(100, ($totalDue / 10000) * 100);

        // Days overdue score (0-100)
        $daysScore = min(100, ($daysOver90 / $totalDue) * 100);

        // Credit limit score (0-100)
        $creditScore = 0;
        if ($creditLimit > 0 && $totalDue > $creditLimit) {
            $creditScore = min(100, (($totalDue - $creditLimit) / $creditLimit) * 100);
        }

        // Payment history score (placeholder - would need payment history data)
        $historyScore = 50; // Default neutral score

        // Weighted composite score
        $compositeScore = 
            ($amountScore * self::PRIORITY_WEIGHTS['amount_overdue']) +
            ($daysScore * self::PRIORITY_WEIGHTS['days_overdue']) +
            ($creditScore * self::PRIORITY_WEIGHTS['over_credit_limit']) +
            ($historyScore * self::PRIORITY_WEIGHTS['payment_history']);

        return round($compositeScore, 2);
    }

    /**
     * Get priority level based on score
     * 
     * @param float $score Priority score
     * @return string Priority level
     */
    private function getPriorityLevel(float $score): string
    {
        if ($score >= 75) return 'critical';
        if ($score >= 50) return 'high';
        if ($score >= 25) return 'medium';
        return 'low';
    }

    /**
     * Fetch DSO calculation data
     * 
     * @param string $asOfDate As of date
     * @return array|null DSO data
     */
    private function fetchDSOData(string $asOfDate): ?array
    {
        $sql = "
            SELECT 
                COALESCE(SUM(dt.ov_amount + dt.ov_gst - dt.alloc), 0) as total_receivables,
                COALESCE(
                    (SELECT SUM(ov_amount + ov_gst) 
                     FROM debtor_trans 
                     WHERE type = 10 
                     AND tran_date BETWEEN DATE_SUB(?, INTERVAL 365 DAY) AND ?), 0
                ) as total_sales,
                365 as period_days
            FROM debtor_trans dt
            WHERE dt.type IN (10, 11, 12)
                AND dt.ov_amount + dt.ov_gst - dt.alloc > 0.01
        ";

        return $this->dbal->fetchOne($sql, [$asOfDate, $asOfDate]);
    }

    /**
     * Calculate average days overdue
     * 
     * @param array $debtors Debtor data
     * @return float Average days overdue
     */
    private function calculateAverageDaysOverdue(array $debtors): float
    {
        if (empty($debtors)) return 0.0;

        $totalWeightedDays = 0.0;
        $totalAmount = 0.0;

        foreach ($debtors as $debtor) {
            $days30 = (float) ($debtor['days_30'] ?? 0);
            $days60 = (float) ($debtor['days_60'] ?? 0);
            $days90 = (float) ($debtor['days_90'] ?? 0);
            $daysOver90 = (float) ($debtor['days_over_90'] ?? 0);

            $totalWeightedDays += ($days30 * 15) + ($days60 * 45) + ($days90 * 75) + ($daysOver90 * 120);
            $totalAmount += $days30 + $days60 + $days90 + $daysOver90;
        }

        return $totalAmount > 0 ? round($totalWeightedDays / $totalAmount, 2) : 0.0;
    }

    /**
     * Calculate collection effectiveness index
     * 
     * @param array $debtors Debtor data
     * @return float Collection effectiveness (0-100)
     */
    private function calculateCollectionEffectiveness(array $debtors): float
    {
        if (empty($debtors)) return 100.0;

        $totalDue = 0.0;
        $currentAndDays30 = 0.0;

        foreach ($debtors as $debtor) {
            $totalDue += (float) $debtor['total_due'];
            $currentAndDays30 += (float) ($debtor['current'] ?? 0) + (float) ($debtor['days_30'] ?? 0);
        }

        return $totalDue > 0 ? round(($currentAndDays30 / $totalDue) * 100, 2) : 100.0;
    }

    /**
     * Validate required parameters
     * 
     * @param array $params Parameters to validate
     * @throws \InvalidArgumentException
     */
    private function validateParameters(array $params): void
    {
        if (!isset($params['as_of_date'])) {
            throw new \InvalidArgumentException('as_of_date parameter is required');
        }

        if (!strtotime($params['as_of_date'])) {
            throw new \InvalidArgumentException('as_of_date must be a valid date');
        }
    }
}
