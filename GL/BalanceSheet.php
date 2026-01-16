<?php

declare(strict_types=1);

namespace FA\Modules\Reports\GL;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use Psr\Log\LoggerInterface;

/**
 * Balance Sheet Report (rep706)
 * 
 * Shows opening balance, period change, and closing balance for all balance sheet accounts.
 * Groups accounts hierarchically by class (Assets, Liabilities, Equity) and types.
 * Calculates assets vs liabilities + equity to verify balance.
 * 
 * @package FA\Modules\Reports\GL
 */
class BalanceSheet extends AbstractReportService
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
            'Balance Sheet',
            'balance_sheet'
        );
    }

    /**
     * Fetch balance sheet data
     */
    protected function fetchData(ReportConfig $config): array
    {
        $tags = $config->getAdditionalParam('tags', -1);
        
        // Get balance sheet account classes (class type 1 = balance sheet)
        $classes = $this->fetchAccountClasses(true);
        
        $results = [];
        foreach ($classes as $class) {
            $classData = [
                'class_id' => $class['cid'],
                'class_name' => $class['class_name'],
                'class_type' => $class['ctype'],
                'types' => [],
                'open_total' => 0.0,
                'period_total' => 0.0,
                'close_total' => 0.0
            ];
            
            // Get account types with no parents for this class
            $types = $this->fetchAccountTypes($class['cid'], -1);
            
            foreach ($types as $type) {
                $typeData = $this->fetchTypeData(
                    $type['id'],
                    $type['name'],
                    $config,
                    $tags
                );
                
                if ($typeData['has_data']) {
                    $classData['types'][] = $typeData;
                    $classData['open_total'] += $typeData['open_total'];
                    $classData['period_total'] += $typeData['period_total'];
                    $classData['close_total'] += $typeData['close_total'];
                }
            }
            
            if (!empty($classData['types'])) {
                $results[] = $classData;
            }
        }
        
        return [
            'classes' => $results,
            'tags' => $tags
        ];
    }

    /**
     * Process data to apply conversions and calculate totals
     */
    protected function processData(array $rawData, ReportConfig $config): array
    {
        $assetTotal = ['open' => 0.0, 'period' => 0.0, 'close' => 0.0];
        $liabilityTotal = ['open' => 0.0, 'period' => 0.0, 'close' => 0.0];
        $equityTotal = ['open' => 0.0, 'period' => 0.0, 'close' => 0.0];
        
        foreach ($rawData['classes'] as &$class) {
            $convert = $this->getClassTypeConvert($class['class_type']);
            
            // Apply conversion
            $class['open_total'] *= $convert;
            $class['period_total'] *= $convert;
            $class['close_total'] *= $convert;
            
            // Convert types and accounts
            foreach ($class['types'] as &$type) {
                $type['open_total'] *= $convert;
                $type['period_total'] *= $convert;
                $type['close_total'] *= $convert;
                
                foreach ($type['accounts'] as &$account) {
                    $account['open_balance'] *= $convert;
                    $account['period_balance'] *= $convert;
                    $account['close_balance'] *= $convert;
                }
                
                // Recurse for sub-types
                $this->convertSubTypes($type, $convert);
            }
            
            // Categorize by class type
            if ($class['class_type'] == 1) { // Assets
                $assetTotal['open'] += $class['open_total'];
                $assetTotal['period'] += $class['period_total'];
                $assetTotal['close'] += $class['close_total'];
            } elseif ($class['class_type'] == 2) { // Liabilities
                $liabilityTotal['open'] += $class['open_total'];
                $liabilityTotal['period'] += $class['period_total'];
                $liabilityTotal['close'] += $class['close_total'];
            } elseif ($class['class_type'] == 3) { // Equity
                $equityTotal['open'] += $class['open_total'];
                $equityTotal['period'] += $class['period_total'];
                $equityTotal['close'] += $class['close_total'];
            }
        }
        
        // Calculate differences (Assets - (Liabilities + Equity))
        $diff = [
            'open' => $assetTotal['open'] - ($liabilityTotal['open'] + $equityTotal['open']),
            'period' => $assetTotal['period'] - ($liabilityTotal['period'] + $equityTotal['period']),
            'close' => $assetTotal['close'] - ($liabilityTotal['close'] + $equityTotal['close'])
        ];
        
        $rawData['totals'] = [
            'assets' => $assetTotal,
            'liabilities' => $liabilityTotal,
            'equity' => $equityTotal,
            'difference' => $diff,
            'is_balanced' => abs($diff['close']) < 0.01
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
            'summary' => [
                'class_count' => count($processedData['classes']),
                'is_balanced' => $processedData['totals']['is_balanced']
            ]
        ];
    }

    /**
     * Recursively convert sub-types
     */
    private function convertSubTypes(array &$type, float $convert): void
    {
        if (isset($type['sub_types'])) {
            foreach ($type['sub_types'] as &$subType) {
                $subType['open_total'] *= $convert;
                $subType['period_total'] *= $convert;
                $subType['close_total'] *= $convert;
                
                foreach ($subType['accounts'] as &$account) {
                    $account['open_balance'] *= $convert;
                    $account['period_balance'] *= $convert;
                    $account['close_balance'] *= $convert;
                }
                
                $this->convertSubTypes($subType, $convert);
            }
        }
    }

    /**
     * Fetch account classes
     */
    private function fetchAccountClasses(bool $balanceSheetOnly = false): array
    {
        $sql = "SELECT cid, class_name, ctype 
                FROM " . TB_PREF . "chart_class 
                WHERE 1=1";
        
        if ($balanceSheetOnly) {
            $sql .= " AND ctype = 1"; // Only balance sheet classes
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
        $tags
    ): array {
        $typeData = [
            'type_id' => $typeId,
            'type_name' => $typeName,
            'accounts' => [],
            'sub_types' => [],
            'open_total' => 0.0,
            'period_total' => 0.0,
            'close_total' => 0.0,
            'has_data' => false
        ];
        
        // Fetch accounts for this type
        $accounts = $this->fetchAccountsForType($typeId, $config, $tags);
        
        foreach ($accounts as $account) {
            if ($this->hasAnyBalance($account)) {
                $typeData['accounts'][] = $account;
                $typeData['has_data'] = true;
                $typeData['open_total'] += $account['open_balance'];
                $typeData['period_total'] += $account['period_balance'];
                $typeData['close_total'] += $account['close_balance'];
            }
        }
        
        // Fetch sub-types recursively
        $subTypes = $this->fetchAccountTypes($typeId, $typeId); // parent = typeId for sub-types
        foreach ($subTypes as $subType) {
            $subTypeData = $this->fetchTypeData(
                $subType['id'],
                $subType['name'],
                $config,
                $tags
            );
            
            if ($subTypeData['has_data']) {
                $typeData['sub_types'][] = $subTypeData;
                $typeData['has_data'] = true;
                $typeData['open_total'] += $subTypeData['open_total'];
                $typeData['period_total'] += $subTypeData['period_total'];
                $typeData['close_total'] += $subTypeData['close_total'];
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
            
            // Get balances
            $openBalance = $this->getGLBalanceFromTo(
                '',
                $config->getFromDate(),
                $account['account_code'],
                $config
            );
            
            $periodBalance = $this->getGLTransFromTo(
                $config->getFromDate(),
                $config->getToDate(),
                $account['account_code'],
                $config
            );
            
            $closeBalance = $openBalance + $periodBalance;
            
            $results[] = [
                'account_code' => $account['account_code'],
                'account_name' => $account['account_name'],
                'open_balance' => $openBalance,
                'period_balance' => $periodBalance,
                'close_balance' => $closeBalance
            ];
        }
        
        return $results;
    }

    /**
     * Get GL balance from beginning to date
     */
    private function getGLBalanceFromTo(
        string $fromDate,
        string $toDate,
        string $accountCode,
        ReportConfig $config
    ): float {
        $fromDateSql = $fromDate ? \DateService::date2sqlStatic($fromDate) : '';
        $toDateSql = \DateService::date2sqlStatic($toDate);
        
        $sql = "SELECT COALESCE(SUM(amount), 0) as balance 
                FROM " . TB_PREF . "gl_trans 
                WHERE account = :account_code";
        
        if ($fromDateSql) {
            $sql .= " AND tran_date > :from_date";
        }
        
        $sql .= " AND tran_date < :to_date";
        $sql .= $this->buildDimensionFilter($config);
        
        $params = ['account_code' => $accountCode, 'to_date' => $toDateSql];
        if ($fromDateSql) {
            $params['from_date'] = $fromDateSql;
        }
        
        $result = $this->dbal->fetchOne($sql, $params);
        
        return (float)($result['balance'] ?? 0.0);
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
     * Check if account has any balance
     */
    private function hasAnyBalance(array $account): bool
    {
        return abs($account['open_balance']) >= 0.01 
            || abs($account['period_balance']) >= 0.01 
            || abs($account['close_balance']) >= 0.01;
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
        return [
            _('Account'),
            _('Account Name'),
            _('Open Balance'),
            _('Period'),
            _('Close Balance')
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
