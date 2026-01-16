<?php

declare(strict_types=1);

namespace FA\Modules\Reports\GL;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use Psr\Log\LoggerInterface;

/**
 * Annual Expense Breakdown Report (rep705)
 * 
 * Shows 12-month breakdown of expenses by account with hierarchical grouping.
 * Displays monthly columns plus annual total for each expense account.
 * Supports dimension filtering, tag filtering, and optional thousands display.
 * 
 * @package FA\Modules\Reports\GL
 */
class AnnualExpenseBreakdown extends AbstractReportService
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
            'Annual Expense Breakdown',
            'annual_expense_breakdown'
        );
    }

    /**
     * Generate annual expense breakdown for fiscal year
     * 
     * @param int $fiscalYearId Fiscal year ID
     * @param ReportConfig $config Report configuration
     * @return array Report data with monthly breakdown
     */
    public function generateForFiscalYear(int $fiscalYearId, ReportConfig $config): array
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
            additionalParams: $config->getAllAdditionalParams() + [
                'fiscal_year_id' => $fiscalYearId
            ]
        );
        
        return $this->generate($newConfig);
    }

    /**
     * Fetch data for annual breakdown
     */
    protected function fetchData(ReportConfig $config): array
    {
        $fiscalYearId = $config->getAdditionalParam('fiscal_year_id');
        $tags = $config->getAdditionalParam('tags', -1);
        $inThousands = $config->getAdditionalParam('in_thousands', false);
        
        // Get fiscal year details
        $fiscalYear = $this->fetchFiscalYear($fiscalYearId);
        
        // Calculate 12 period dates
        $periods = $this->calculatePeriods($fiscalYear['end_year'], $fiscalYear['end_month']);
        
        // Get account classes
        $classes = $this->fetchAccountClasses();
        
        $divisor = $inThousands ? 1000 : 1;
        
        // For each class, get account types and accounts
        $results = [];
        foreach ($classes as $class) {
            $classData = [
                'class_id' => $class['cid'],
                'class_name' => $class['class_name'],
                'class_type' => $class['ctype'],
                'types' => [],
                'totals' => array_fill(1, 13, 0.0)
            ];
            
            // Get account types for this class
            $types = $this->fetchAccountTypes($class['cid']);
            
            foreach ($types as $type) {
                $typeData = $this->fetchTypeData(
                    $type['id'],
                    $type['name'],
                    $periods,
                    $config,
                    $tags,
                    $divisor
                );
                
                if ($typeData['has_data']) {
                    $classData['types'][] = $typeData;
                    
                    // Add to class totals
                    for ($i = 1; $i <= 13; $i++) {
                        $classData['totals'][$i] += $typeData['totals'][$i];
                    }
                }
            }
            
            if (!empty($classData['types'])) {
                $results[] = $classData;
            }
        }
        
        return [
            'fiscal_year' => $fiscalYear,
            'periods' => $periods,
            'classes' => $results,
            'in_thousands' => $inThousands,
            'divisor' => $divisor
        ];
    }

    /**
     * Process data to calculate grand totals
     */
    protected function processData(array $rawData, ReportConfig $config): array
    {
        $grandTotals = array_fill(1, 13, 0.0);
        
        foreach ($rawData['classes'] as &$class) {
            $convert = $this->getClassTypeConvert($class['class_type']);
            
            // Apply conversion to class totals
            for ($i = 1; $i <= 13; $i++) {
                $class['totals'][$i] *= $convert;
                $grandTotals[$i] += $class['totals'][$i];
            }
            
            // Convert type and account totals
            foreach ($class['types'] as &$type) {
                for ($i = 1; $i <= 13; $i++) {
                    $type['totals'][$i] *= $convert;
                }
                
                foreach ($type['accounts'] as &$account) {
                    for ($i = 1; $i <= 13; $i++) {
                        $account['balance'][$i] *= $convert;
                    }
                }
            }
        }
        
        $rawData['grand_totals'] = $grandTotals;
        
        return $rawData;
    }

    /**
     * Format data for output
     */
    protected function formatData(array $processedData, ReportConfig $config): array
    {
        return [
            'fiscal_year' => $processedData['fiscal_year'],
            'period_names' => $processedData['periods']['names'],
            'classes' => $processedData['classes'],
            'grand_totals' => $processedData['grand_totals'],
            'in_thousands' => $processedData['in_thousands'],
            'summary' => [
                'class_count' => count($processedData['classes']),
                'total_accounts' => array_sum(array_map(
                    fn($c) => array_sum(array_map(
                        fn($t) => count($t['accounts']),
                        $c['types']
                    )),
                    $processedData['classes']
                ))
            ]
        ];
    }

    /**
     * Fetch fiscal year details
     */
    private function fetchFiscalYear(int $fiscalYearId): array
    {
        $sql = "SELECT begin, end, YEAR(end) AS end_year, MONTH(end) AS end_month 
                FROM " . TB_PREF . "fiscal_year 
                WHERE id = :fiscal_year_id";
        
        $row = $this->dbal->fetchOne($sql, ['fiscal_year_id' => $fiscalYearId]);
        
        return [
            'begin' => $row['begin'],
            'end' => $row['end'],
            'end_year' => (int)$row['end_year'],
            'end_month' => (int)$row['end_month']
        ];
    }

    /**
     * Calculate 12 period boundaries
     */
    private function calculatePeriods(int $year, int $month): array
    {
        global $tmonths;
        
        $dates = [];
        $names = [];
        
        for ($i = 0; $i <= 12; $i++) {
            $dates[$i] = date('Y-m-d', mktime(0, 0, 0, $month - 11 + $i, 1, $year));
        }
        
        for ($i = 1; $i <= 12; $i++) {
            $monthNum = date('n', mktime(0, 0, 0, $month - 12 + $i, 1, $year));
            $names[$i] = $tmonths[$monthNum] ?? "Month $i";
        }
        
        return [
            'dates' => $dates,
            'names' => $names
        ];
    }

    /**
     * Fetch account classes
     */
    private function fetchAccountClasses(): array
    {
        $sql = "SELECT cid, class_name, ctype 
                FROM " . TB_PREF . "chart_class 
                WHERE ctype > 0 
                ORDER BY cid";
        
        return $this->dbal->fetchAll($sql);
    }

    /**
     * Fetch account types for a class
     */
    private function fetchAccountTypes(int $classId): array
    {
        $sql = "SELECT id, name 
                FROM " . TB_PREF . "chart_types 
                WHERE class_id = :class_id 
                AND parent = -1 
                ORDER BY id";
        
        return $this->dbal->fetchAll($sql, ['class_id' => $classId]);
    }

    /**
     * Fetch data for an account type (recursive for sub-types)
     */
    private function fetchTypeData(
        int $typeId,
        string $typeName,
        array $periods,
        ReportConfig $config,
        $tags,
        float $divisor
    ): array {
        $typeData = [
            'type_id' => $typeId,
            'type_name' => $typeName,
            'accounts' => [],
            'sub_types' => [],
            'totals' => array_fill(1, 13, 0.0),
            'has_data' => false
        ];
        
        // Fetch accounts directly under this type
        $accounts = $this->fetchAccountsForType($typeId, $periods['dates'], $config, $tags, $divisor);
        
        foreach ($accounts as $account) {
            if ($this->hasAnyBalance($account['balance'])) {
                $typeData['accounts'][] = $account;
                $typeData['has_data'] = true;
                
                for ($i = 1; $i <= 13; $i++) {
                    $typeData['totals'][$i] += $account['balance'][$i];
                }
            }
        }
        
        // Recursively fetch sub-types
        $subTypes = $this->fetchSubTypes($typeId);
        foreach ($subTypes as $subType) {
            $subTypeData = $this->fetchTypeData(
                $subType['id'],
                $subType['name'],
                $periods,
                $config,
                $tags,
                $divisor
            );
            
            if ($subTypeData['has_data']) {
                $typeData['sub_types'][] = $subTypeData;
                $typeData['has_data'] = true;
                
                for ($i = 1; $i <= 13; $i++) {
                    $typeData['totals'][$i] += $subTypeData['totals'][$i];
                }
            }
        }
        
        return $typeData;
    }

    /**
     * Fetch accounts for a type with monthly balances
     */
    private function fetchAccountsForType(
        int $typeId,
        array $periodDates,
        ReportConfig $config,
        $tags,
        float $divisor
    ): array {
        // Get accounts for this type
        $sql = "SELECT account_code, account_name 
                FROM " . TB_PREF . "chart_master 
                WHERE account_type = :type_id 
                ORDER BY account_code";
        
        $accounts = $this->dbal->fetchAll($sql, ['type_id' => $typeId]);
        
        $results = [];
        foreach ($accounts as $account) {
            // Check tags if applicable
            if ($tags != -1 && is_array($tags) && !empty($tags)) {
                if (!is_record_in_tags($tags, TAG_ACCOUNT, $account['account_code'])) {
                    continue;
                }
            }
            
            // Get monthly balances
            $balances = $this->fetchAccountPeriods(
                $account['account_code'],
                $periodDates,
                $config,
                $divisor
            );
            
            $results[] = [
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'balance' => $balances
            ];
        }
        
        return $results;
    }

    /**
     * Fetch monthly balances for an account
     */
    private function fetchAccountPeriods(
        string $accountCode,
        array $periodDates,
        ReportConfig $config,
        float $divisor
    ): array {
        $cases = [];
        for ($i = 1; $i <= 12; $i++) {
            $cases[] = "SUM(CASE WHEN tran_date >= '{$periodDates[$i-1]}' AND tran_date < '{$periodDates[$i]}' THEN amount / $divisor ELSE 0 END) AS per" . str_pad($i, 2, '0', STR_PAD_LEFT);
        }
        
        $casesStr = implode(', ', $cases);
        $totalCase = "SUM(CASE WHEN tran_date >= '{$periodDates[0]}' AND tran_date < '{$periodDates[12]}' THEN amount / $divisor ELSE 0 END) AS pertotal";
        
        $sql = "SELECT $casesStr, $totalCase 
                FROM " . TB_PREF . "gl_trans 
                WHERE account = :account_code";
        
        $sql .= $this->buildDimensionFilter($config);
        
        $result = $this->dbal->fetchOne($sql, ['account_code' => $accountCode]);
        
        $balances = [];
        for ($i = 1; $i <= 12; $i++) {
            $key = 'per' . str_pad($i, 2, '0', STR_PAD_LEFT);
            $balances[$i] = (float)($result[$key] ?? 0.0);
        }
        $balances[13] = (float)($result['pertotal'] ?? 0.0);
        
        return $balances;
    }

    /**
     * Fetch sub-types of a type
     */
    private function fetchSubTypes(int $parentTypeId): array
    {
        $sql = "SELECT id, name 
                FROM " . TB_PREF . "chart_types 
                WHERE parent = :parent_id 
                ORDER BY id";
        
        return $this->dbal->fetchAll($sql, ['parent_id' => $parentTypeId]);
    }

    /**
     * Check if account has any non-zero balance
     */
    private function hasAnyBalance(array $balances): bool
    {
        foreach ($balances as $balance) {
            if (abs($balance) >= 0.01) {
                return true;
            }
        }
        return false;
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
        return [0, 34, 130, 163, 196, 229, 262, 295, 328, 361, 394, 427, 460, 493, 526, 561];
    }

    /**
     * Get header labels
     */
    protected function getHeaders(ReportConfig $config): array
    {
        global $tmonths;
        
        // Would need period names from data - this is a simplified version
        return [
            _('Account'), _('Account Name'),
            'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
            'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
            _('Total')
        ];
    }

    /**
     * Get column alignments
     */
    protected function getAligns(ReportConfig $config): array
    {
        return ['left', 'left', 'right', 'right', 'right', 'right', 'right', 'right',
                'right', 'right', 'right', 'right', 'right', 'right', 'right'];
    }

    /**
     * Get font size based on thousands flag
     */
    protected function getFontSize(): int
    {
        // Would be determined by config - defaulting to 9
        return 9;
    }
}
