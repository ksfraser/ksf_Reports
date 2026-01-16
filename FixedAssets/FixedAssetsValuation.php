<?php
/**
 * Fixed Assets Valuation Report Service
 * 
 * Generates fixed asset valuation report showing:
 * - Asset details by class
 * - Initial purchase cost
 * - Accumulated depreciation
 * - Current book value
 * 
 * Report: rep451
 * Category: Fixed Assets Reports
 */

declare(strict_types=1);

namespace FA\Reports\FixedAssets;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class FixedAssetsValuation extends AbstractReportService
{
    private const REPORT_ID = 451;
    private const REPORT_TITLE = 'Fixed Assets Valuation Report';
    
    public function __construct(
        DBALInterface $db,
        EventDispatcher $eventDispatcher
    ) {
        parent::__construct($db, $eventDispatcher);
    }
    
    protected function getReportId(): int
    {
        return self::REPORT_ID;
    }
    
    protected function getReportTitle(): string
    {
        return self::REPORT_TITLE;
    }
    
    protected function getDefaultOrientation(): string
    {
        $orientation = $this->config->getParam('orientation', 0);
        return $orientation ? 'L' : 'P';
    }
    
    protected function defineColumns(): array
    {
        return [0, 75, 225, 250, 350, 450, 515];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('Class'),
            '',
            _('UOM'),
            _('Initial'),
            _('Depreciations'),
            _('Current')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'right', 'right', 'right', 'right'];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $class = $config->getParam('class');
        if ($class == ALL_NUMERIC) {
            $class = 0;
        }
        $className = ($class == 0) ? _('All') : get_fixed_asset_classname($class);
        
        $location = $config->getParam('location');
        if ($location == ALL_TEXT) {
            $location = 'all';
        }
        $locName = ($location == 'all') ? _('All') : get_location_name($location);
        
        return [
            0 => $config->getParam('comments'),
            1 => ['text' => _('End Date'), 'from' => $config->getParam('date'), 'to' => ''],
            2 => ['text' => _('Class'), 'from' => $className, 'to' => ''],
            3 => ['text' => _('Location'), 'from' => $locName, 'to' => '']
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $date = $config->getParam('date');
        $class = $config->getParam('class');
        $location = $config->getParam('location');
        
        // Normalize parameters
        if ($class == ALL_NUMERIC) {
            $class = 0;
        }
        if ($location == ALL_TEXT) {
            $location = 'all';
        }
        
        $className = ($class == 0) ? null : get_fixed_asset_classname($class);
        
        // Get fixed assets
        $assets = $this->getFixedAssets($date, $className, $location);
        
        $data = [
            'assets' => $assets,
            'date' => $date,
            'class_name' => $className
        ];
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        return $data;
    }
    
    protected function processData(ReportConfig $config, array $data): array
    {
        $this->dispatchEvent('before_process_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        $detail = !$config->getParam('detail', 0);
        $date = $data['date'];
        $className = $data['class_name'];
        
        // Group assets by class
        $grouped = [];
        foreach ($data['assets'] as $asset) {
            // Check purchase date
            $purchase = get_fixed_asset_purchase($asset['stock_id']);
            $purchaseDate = \DateService::sql2dateStatic($purchase['tran_date']);
            
            if (\DateService::date1GreaterDate2Static($purchaseDate, $date)) {
                continue;
            }
            
            // Filter by class if specified
            if ($className !== null && $className !== $asset['description']) {
                continue;
            }
            
            $class = $asset['description'];
            if (!isset($grouped[$class])) {
                $grouped[$class] = [
                    'class_name' => $class,
                    'assets' => [],
                    'total' => 0.0
                ];
            }
            
            $unitCost = (float)$asset['purchase_cost'];
            $depreciation = (float)$asset['purchase_cost'] - (float)$asset['material_cost'];
            $balance = (float)$asset['material_cost'];
            
            $grouped[$class]['assets'][] = [
                'stock_id' => $asset['stock_id'],
                'name' => $asset['name'],
                'units' => $asset['units'],
                'unit_cost' => $unitCost,
                'depreciation' => $depreciation,
                'balance' => $balance
            ];
            
            $grouped[$class]['total'] += $balance;
        }
        
        $processed = [
            'asset_groups' => $grouped,
            'detail' => $detail,
            'grand_total' => array_sum(array_column($grouped, 'total'))
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get fixed assets with location filtering
     */
    private function getFixedAssets(string $date, $className, $location): array
    {
        $sql = get_sql_for_fixed_assets(false);
        $assets = $this->db->fetchAll($sql);
        
        // Filter by location
        if ($location !== 'all') {
            $filteredAssets = [];
            foreach ($assets as $asset) {
                $loc = $this->findLastLocation($asset['stock_id'], $date);
                if ($loc === $location) {
                    $filteredAssets[] = $asset;
                }
            }
            return $filteredAssets;
        }
        
        return $assets;
    }
    
    /**
     * Find last location for an asset
     */
    private function findLastLocation(string $stockId, string $endDate)
    {
        $endDate = \DateService::date2sqlStatic($endDate);
        
        $sql = "SELECT loc_code 
                FROM ".TB_PREF."stock_moves 
                WHERE stock_id = ".$this->db->escape($stockId)."
                  AND tran_date <= ".$this->db->escape($endDate)."
                ORDER BY tran_date DESC 
                LIMIT 1";
        
        $result = $this->db->fetchOne($sql);
        return $result ? $result['loc_code'] : false;
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        $detail = $processedData['detail'];
        
        foreach ($processedData['asset_groups'] as $group) {
            // Class header
            $rep->TextCol(0, 2, $group['class_name']);
            if ($detail) {
                $rep->NewLine();
            }
            
            // Asset details
            if ($detail) {
                foreach ($group['assets'] as $asset) {
                    $rep->NewLine();
                    $rep->TextCol(0, 1, $asset['stock_id']);
                    $rep->TextCol(1, 2, $asset['name']);
                    $rep->TextCol(2, 3, $asset['units']);
                    $rep->AmountCol(3, 4, $asset['unit_cost'], $dec);
                    $rep->AmountCol(4, 5, $asset['depreciation'], $dec);
                    $rep->AmountCol(5, 6, $asset['balance'], $dec);
                }
                
                $rep->NewLine(2, 3);
                $rep->TextCol(0, 4, _('Total'));
            }
            
            $rep->AmountCol(5, 6, $group['total'], $dec);
            
            if ($detail) {
                $rep->Line($rep->row - 2);
                $rep->NewLine();
            }
            $rep->NewLine();
            
            if ($rep->row < $rep->bottomMargin + $rep->lineHeight) {
                $rep->NewPage();
            }
        }
        
        // Grand total
        $rep->Font('bold');
        $rep->TextCol(0, 4, _('Grand Total'));
        $rep->AmountCol(5, 6, $processedData['grand_total'], $dec);
        $rep->Font();
        $rep->Line($rep->row - 4);
        $rep->NewLine();
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
