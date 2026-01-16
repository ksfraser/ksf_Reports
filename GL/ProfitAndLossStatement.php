<?php

declare(strict_types=1);

namespace FA\Modules\Reports\GL;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use Psr\Log\LoggerInterface;

/**
 * Profit & Loss Statement (rep707)
 * 
 * Shows period performance and accumulated/budget comparison for income/expense accounts.
 * Groups accounts hierarchically by class and types.
 * Displays achievement percentage (period vs accumulated/budget).
 * 
 * @package FA\Modules\Reports\GL
 */
class ProfitAndLossStatement extends AbstractReportService
{
    private const COMPARE_ACCUMULATED = 0;
    private const COMPARE_PRIOR_YEAR = 1;
    private const COMPARE_BUDGET = 2;

    public function __construct(
        DBALInterface $dbal,
        EventDispatcher $dispatcher,
        LoggerInterface $logger
    ) {
        parent::__construct(
            $dbal,
            $dispatcher,
            $logger,
            'Profit and Loss Statement',
            'profit_and_loss'
        );
    }

    /**
     * Fetch Profit & Loss data
     */
    protected function fetchData(ReportConfig $config): array
    {
        $tags = $config->getAdditionalParam('tags', -1);
        $compare = $config->getAdditionalParam('compare', self::COMPARE_ACCUMULATED);
        
        // Calculate comparison period
        [$beginDate, $endDate, $compareLabel] = $this->calculateComparisonPeriod(
            $config->getFromDate(),
            $config->getToDate(),
            $compare
        );
        
        // Get P&L account classes (class type 0 = profit & loss)
        $classes = $this->fetchAccountClasses(false); // All classes for P&L
        
        $results = [];
        foreach ($classes as $class) {
            $classData = [
                'class_id' => $class['cid'],
                'class_name' => $class['class_name'],
                'class_type' => $class['ctype'],
                'types' => [],
                'period_total' => 0.0,
                'accumulated_total' => 0.0
            ];
            
            // Get account types with no parents for this class
            $types = $this->fetchAccountTypes($class['cid'], -1);
            
            foreach ($types as $type) {
                $typeData = $this->fetchTypeData(
                    $type['id'],
                    $type['name'],
                    $config,
                    $beginDate,
                    $endDate,
                    $compare,
                    $tags
                );
                
                if ($typeData['has_data']) {
                    $classData['types'][] = $typeData;
                    $classData['period_total'] += $typeData['period_total'];
                    $classData['accumulated_total'] += $typeData['accumulated_total'];
                }
            }
            
            if (!empty($classData['types'])) {
                $results[] = $classData;
            }
        }
        
        return [
            'classes' => $results,
            'tags' => $tags,
            'compare' => $compare,
            'compare_label' => $compareLabel,
            'begin_date' => $beginDate,
            'end_date' => $endDate
        ];
    }

    /**
     * Process data to apply conversions and calculate totals
     */
    protected function processData(array $rawData, ReportConfig $config): array
    {
        $totalPeriod = 0.0;
        $totalAccumulated = 0.0;
        
        foreach ($rawData['classes'] as &$class) {
            $convert = $this->getClassTypeConvert($class['class_type']);
            
            // Apply conversion
            $class['period_total'] *= $convert;
            $class['accumulated_total'] *= $convert;
            
            // Convert types and accounts
            foreach ($class['types'] as &$type) {
                $type['period_total'] *= $convert;
                $type['accumulated_total'] *= $convert;
                
                foreach ($type['accounts'] as &$account) {
                    $account['period_balance'] *= $convert;
                    $account['accumulated_balance'] *= $convert;
                    $account['achieved_percent'] = $this->calculateAchievement(
                        $account['period_balance'],
                        $account['accumulated_balance']
                    );
                }
                
                // Recurse for sub-types
                $this->convertSubTypes($type, $convert);
                
                // Calculate type achievement
                $type['achieved_percent'] = $this->calculateAchievement(
                    $type['period_total'],
                    $type['accumulated_total']
                );
            }
            
            // Calculate class achievement
            $class['achieved_percent'] = $this->calculateAchievement(
                $class['period_total'],
                $class['accumulated_total']
            );
            
            $totalPeriod += $class['period_total'];
            $totalAccumulated += $class['accumulated_total'];
        }
        
        // Calculate overall return (inverted since expenses are negative)
        $rawData['totals'] = [
            'period' => $totalPeriod * -1, // Always convert
            'accumulated' => $totalAccumulated * -1,
            'achieved_percent' => $this->calculateAchievement($totalPeriod, $totalAccumulated)
        ];
        
        return $rawData;
    }

    /**
     * Format data for output
     */
    protected function formatData(array $processedData, ReportConfig $config): array
    {
        return [
            'classes' => $processedData['classes'],
            'totals' => $processedData['totals'],
            'compare_label' => $processedData['compare_label'],
            'summary' => [
                'class_count' => count($processedData['classes']),
                'return' => $processedData['totals']['period']
            ]
        ];
    }

    /**
     * Calculate comparison period dates
     */
    private function calculateComparisonPeriod(
        string $fromDate,
        string $toDate,
        int $compare
    ): array {
        if ($compare === self::COMPARE_ACCUMULATED) {
            $begin = \FA\Services\DateService::beginFiscalyear();
            $end = $toDate;
            $label = _('Accumulated');
        } elseif ($compare === self::COMPARE_PRIOR_YEAR) {
            $begin = \DateService::addMonthsStatic($fromDate, -12);
            $end = \DateService::addMonthsStatic($toDate, -12);
            
            // Compensate for leap years
            if (\DateService::dateCompStatic($toDate, \DateService::endMonthStatic($toDate)) === 0) {
                $end = \DateService::endMonthStatic($end);
            }
            
            $label = _('Period Y-1');
        } else { // COMPARE_BUDGET
            $begin = $fromDate;
            $end = $toDate;
            $label = _('Budget');
        }
        
        return [$begin, $end, $label];
    }

    /**
     * Recursively convert sub-types
     */
    private function convertSubTypes(array &$type, float $convert): void
    {
        if (isset($type['sub_types'])) {
            foreach ($type['sub_types'] as &$subType) {
                $subType['period_total'] *= $convert;
                $subType['accumulated_total'] *= $convert;
                $subType['achieved_percent'] = $this->calculateAchievement(
                    $subType['period_total'],
                    $subType['accumulated_total']
                );
                
                foreach ($subType['accounts'] as &$account) {
                    $account['period_balance'] *= $convert;
                    $account['accumulated_balance'] *= $convert;
                    $account['achieved_percent'] = $this->calculateAchievement(
                        $account['period_balance'],
                        $account['accumulated_balance']
                    );
                }
                
                $this->convertSubTypes($subType, $convert);
            }
        }
    }

    /**
     * Calculate achievement percentage
     */
    private function calculateAchievement(float $period, float $accumulated): float
    {
        if ($period == 0.0 && $accumulated == 0.0) {
            return 0.0;
        }
        
        if ($accumulated == 0.0) {
            return 999.0;
        }
        
        $result = ($period / $accumulated) * 100.0;
        
        if ($result > 999.0) {
            return 999.0;
        }
        
        return $result;
    }

    /**
     * Fetch account classes
     */
    private function fetchAccountClasses(bool $profitLossOnly = false): array
    {
        $sql = "SELECT cid, class_name, ctype 
                FROM " . TB_PREF . "chart_class 
                WHERE 1=1";
        
        if ($profitLossOnly) {
            $sql .= " AND ctype = 0"; // Only P&L classes
        }
        
        $sql .= " ORDER BY cid";
        
        return $this->dbal->fetchAll($sql);
    }

    /**
     * Fetch account types
     */
    private function fetchAccountTypes(int $classId, int $parentId = -1): array
    {
        $sql = "SELECT id, name 
                FROM " . TB_PREF . "chart_types 
                WHERE class_id = :class_id 
                AND parent = :parent_id 
                ORDER BY id";
        
        return $this->dbal->fetchAll($sql, [
            'class_id' => $classId,
            'parent_id' => $parentId
        ]);
    }

    /**
     * Fetch data for account type (recursive)
     */
    private function fetchTypeData(
        int $typeId,
        string $typeName,
        ReportConfig $config,
        string $beginDate,
        string $endDate,
        int $compare,
        $tags
    ): array {
        $typeData = [
            'type_id' => $typeId,
            'type_name' => $typeName,
            'accounts' => [],
            'sub_types' => [],
            'period_total' => 0.0,
            'accumulated_total' => 0.0,
            'has_data' => false
        ];
        
        // Fetch accounts for this type
        $accounts = $this->fetchAccountsForType(
            $typeId,
            $config,
            $beginDate,
            $endDate,
            $compare,
            $tags
        );
        
        foreach ($accounts as $account) {
            if ($this->hasAnyBalance($account)) {
                $typeData['accounts'][] = $account;
                $typeData['has_data'] = true;
                $typeData['period_total'] += $account['period_balance'];
                $typeData['accumulated_total'] += $account['accumulated_balance'];
            }
        }
        
        // Fetch sub-types recursively (parent = current type id for nested types)
        // Need to get class_id from the type
        $typeInfo = $this->dbal->fetchOne(
            "SELECT class_id FROM " . TB_PREF . "chart_types WHERE id = :type_id",
            ['type_id' => $typeId]
        );
        $classId = $typeInfo['class_id'];
        
        $subTypes = $this->fetchAccountTypes($classId, $typeId);
        foreach ($subTypes as $subType) {
            $subTypeData = $this->fetchTypeData(
                $subType['id'],
                $subType['name'],
                $config,
                $beginDate,
                $endDate,
                $compare,
                $tags
            );
            
            if ($subTypeData['has_data']) {
                $typeData['sub_types'][] = $subTypeData;
                $typeData['has_data'] = true;
                $typeData['period_total'] += $subTypeData['period_total'];
                $typeData['accumulated_total'] += $subTypeData['accumulated_total'];
            }
        }
        
        return $typeData;
    }

    /**
     * Fetch accounts for a type
     */
    private function fetchAccountsForType(
        int $typeId,
        ReportConfig $config,
        string $beginDate,
        string $endDate,
        int $compare,
        $tags
    ): array {
        $sql = "SELECT account_code, account_name 
                FROM " . TB_PREF . "chart_master 
                WHERE account_type = :type_id 
                ORDER BY account_code";
        
        $accounts = $this->dbal->fetchAll($sql, ['type_id' => $typeId]);
        
        $results = [];
        foreach ($accounts as $account) {
            // Check tags
            if ($tags != -1 && is_array($tags) && !empty($tags)) {
                if (!is_record_in_tags($tags, TAG_ACCOUNT, $account['account_code'])) {
                    continue;
                }
            }
            
            // Get period balance
            $periodBalance = $this->getGLTransFromTo(
                $config->getFromDate(),
                $config->getToDate(),
                $account['account_code'],
                $config
            );
            
            // Get accumulated/budget balance
            if ($compare === self::COMPARE_BUDGET) {
                $accumulatedBalance = $this->getBudgetTransFromTo(
                    $beginDate,
                    $endDate,
                    $account['account_code'],
                    $config
                );
            } else {
                $accumulatedBalance = $this->getGLTransFromTo(
                    $beginDate,
                    $endDate,
                    $account['account_code'],
                    $config
                );
            }
            
            // Skip if both are zero
            if (!$periodBalance && !$accumulatedBalance) {
                continue;
            }
            
            $results[] = [
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'period_balance' => $periodBalance,
                'accumulated_balance' => $accumulatedBalance
            ];
        }
        
        return $results;
    }

    /**
     * Get GL transactions sum for period
     */
    private function getGLTransFromTo(
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
                AND tran_date >= :from_date 
                AND tran_date <= :to_date";
        
        $sql .= $this->buildDimensionFilter($config);
        
        $result = $this->dbal->fetchOne($sql, [
            'account_code' => $accountCode,
            'from_date' => $fromDateSql,
            'to_date' => $toDateSql
        ]);
        
        return (float)($result['balance'] ?? 0.0);
    }

    /**
     * Get budget transactions for period
     */
    private function getBudgetTransFromTo(
        string $fromDate,
        string $toDate,
        string $accountCode,
        ReportConfig $config
    ): float {
        $fromDateSql = \DateService::date2sqlStatic($fromDate);
        $toDateSql = \DateService::date2sqlStatic($toDate);
        
        $sql = "SELECT COALESCE(SUM(amount), 0) as balance 
                FROM " . TB_PREF . "budget_trans 
                WHERE account = :account_code 
                AND tran_date >= :from_date 
                AND tran_date <= :to_date";
        
        $sql .= $this->buildDimensionFilter($config);
        
        $result = $this->dbal->fetchOne($sql, [
            'account_code' => $accountCode,
            'from_date' => $fromDateSql,
            'to_date' => $toDateSql
        ]);
        
        return (float)($result['balance'] ?? 0.0);
    }

    /**
     * Check if account has any balance
     */
    private function hasAnyBalance(array $account): bool
    {
        return abs($account['period_balance']) >= 0.01 
            || abs($account['accumulated_balance']) >= 0.01;
    }

    /**
     * Get class type conversion multiplier
     */
    private function getClassTypeConvert(int $classType): int
    {
        return get_class_type_convert($classType);
    }

    /**
     * Get column definitions
     */
    protected function getColumns(ReportConfig $config): array
    {
        return [0, 60, 200, 350, 425, 500];
    }

    /**
     * Get header labels
     */
    protected function getHeaders(ReportConfig $config): array
    {
        $compareLabel = $config->getAdditionalParam('compare_label', _('Accumulated'));
        
        return [
            _('Account'),
            _('Account Name'),
            _('Period'),
            $compareLabel,
            _('Achieved %')
        ];
    }

    /**
     * Get column alignments
     */
    protected function getAligns(ReportConfig $config): array
    {
        return ['left', 'left', 'right', 'right', 'right'];
    }
}
