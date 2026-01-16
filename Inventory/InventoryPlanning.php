<?php
/**
 * Inventory Planning Report Service
 * 
 * Generates demand planning report showing:
 * - Current stock levels
 * - Historical demand (last 5 months)
 * - Suggested order quantities
 * 
 * Report: rep302
 * Category: Inventory Reports
 */

declare(strict_types=1);

namespace FA\Reports\Inventory;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class InventoryPlanning extends AbstractReportService
{
    private const REPORT_ID = 302;
    private const REPORT_TITLE = 'Inventory Planning Report';
    
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
        return 'L';
    }
    
    protected function defineColumns(): array
    {
        return [0, 50, 150, 180, 210, 240, 270, 300, 330, 390, 435, 480, 525];
    }
    
    protected function defineHeaders(): array
    {
        global $tmonths;
        
        $per0 = $tmonths[date('n', mktime(0, 0, 0, date('m'), 1, date('Y')))];
        $per1 = $tmonths[date('n', mktime(0, 0, 0, date('m') - 1, 1, date('Y')))];
        $per2 = $tmonths[date('n', mktime(0, 0, 0, date('m') - 2, 1, date('Y')))];
        $per3 = $tmonths[date('n', mktime(0, 0, 0, date('m') - 3, 1, date('Y')))];
        $per4 = $tmonths[date('n', mktime(0, 0, 0, date('m') - 4, 1, date('Y')))];
        
        return [
            _('Cat'),
            _('Description'),
            _('On Hand'),
            $per4,
            $per3,
            $per2,
            $per1,
            $per0,
            _('Average'),
            _('Required'),
            _('On Order'),
            _('Suggest')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return [
            'left', 'left', 'right', 'right', 'right', 'right',
            'right', 'right', 'right', 'right', 'right', 'right'
        ];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $category = $config->getParam('category');
        $catName = ($category == 0 || $category == ALL_NUMERIC) ? _('All') : get_category_name($category);
        
        $location = $config->getParam('location');
        $locName = ($location == 'all' || $location == ALL_TEXT) ? _('All') : get_location_name($location);
        
        return [
            0 => $config->getParam('comments'),
            1 => ['text' => _('Category'), 'from' => $catName, 'to' => ''],
            2 => ['text' => _('Location'), 'from' => $locName, 'to' => '']
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $category = $config->getParam('category');
        $location = $config->getParam('location');
        
        if ($category == ALL_NUMERIC) $category = 0;
        if ($location == ALL_TEXT) $location = 'all';
        
        $sql = "SELECT item.category_id,
                    category.description AS cat_description,
                    item.stock_id,
                    item.description,
                    item.inactive,
                    IF(move.stock_id IS NULL, '', move.loc_code) AS loc_code,
                    SUM(IF(move.stock_id IS NULL, 0, move.qty)) AS qty_on_hand
                FROM (".TB_PREF."stock_master item
                INNER JOIN ".TB_PREF."stock_category category ON item.category_id = category.category_id)
                LEFT JOIN ".TB_PREF."stock_moves move ON item.stock_id = move.stock_id
                WHERE (item.mb_flag = 'B' OR item.mb_flag = 'M')";
        
        if ($category != 0) {
            $sql .= " AND item.category_id = ".$this->db->escape($category);
        }
        
        if ($location != 'all') {
            $sql .= " AND IF(move.stock_id IS NULL, '1=1', move.loc_code = ".$this->db->escape($location).")";
        }
        
        $sql .= " GROUP BY item.category_id, category.description, item.stock_id, item.description
                  ORDER BY item.category_id, item.stock_id";
        
        $items = $this->db->fetchAll($sql);
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $items
        ]);
        
        return $items;
    }
    
    protected function processData(ReportConfig $config, array $data): array
    {
        $this->dispatchEvent('before_process_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        $location = $config->getParam('location');
        if ($location == ALL_TEXT) $location = 'all';
        
        $categories = [];
        $currentCategory = null;
        $categoryData = null;
        
        foreach ($data as $item) {
            $categoryId = $item['category_id'];
            
            // Start new category
            if ($currentCategory !== $categoryId) {
                if ($currentCategory !== null) {
                    $categories[] = $categoryData;
                }
                
                $currentCategory = $categoryId;
                $categoryData = [
                    'category_id' => $categoryId,
                    'cat_description' => $item['cat_description'],
                    'items' => []
                ];
            }
            
            // Get period demands
            $periods = $this->getPeriodDemands($item['stock_id'], $item['loc_code'] ?: $location);
            
            // Calculate average
            $average = ($periods['prd0'] + $periods['prd1'] + $periods['prd2'] + 
                       $periods['prd3'] + $periods['prd4']) / 5;
            
            // Get required quantity (components for manufacturing)
            $required = $this->getRequiredQty($item['stock_id'], $item['loc_code'] ?: $location);
            
            // Get on order quantity
            $onOrder = $this->getOnOrderQty($item['stock_id'], $item['loc_code'] ?: $location);
            
            // Calculate suggested order quantity
            $qtyOnHand = (float)$item['qty_on_hand'];
            $suggest = max(0, $required - $qtyOnHand - $onOrder);
            
            $categoryData['items'][] = [
                'stock_id' => $item['stock_id'],
                'description' => $item['description'],
                'inactive' => $item['inactive'],
                'qty_on_hand' => $qtyOnHand,
                'prd0' => $periods['prd0'],
                'prd1' => $periods['prd1'],
                'prd2' => $periods['prd2'],
                'prd3' => $periods['prd3'],
                'prd4' => $periods['prd4'],
                'average' => $average,
                'required' => $required,
                'on_order' => $onOrder,
                'suggest' => $suggest
            ];
        }
        
        // Add last category
        if ($currentCategory !== null) {
            $categories[] = $categoryData;
        }
        
        $processed = ['categories' => $categories];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get demand for last 5 months
     */
    private function getPeriodDemands(string $stockId, string $location): array
    {
        $date5 = date('Y-m-d');
        $date4 = date('Y-m-d', mktime(0, 0, 0, date('m'), 1, date('Y')));
        $date3 = date('Y-m-d', mktime(0, 0, 0, date('m') - 1, 1, date('Y')));
        $date2 = date('Y-m-d', mktime(0, 0, 0, date('m') - 2, 1, date('Y')));
        $date1 = date('Y-m-d', mktime(0, 0, 0, date('m') - 3, 1, date('Y')));
        $date0 = date('Y-m-d', mktime(0, 0, 0, date('m') - 4, 1, date('Y')));
        
        $sql = "SELECT 
                SUM(CASE WHEN tran_date >= '$date0' AND tran_date < '$date1' THEN -qty ELSE 0 END) AS prd0,
                SUM(CASE WHEN tran_date >= '$date1' AND tran_date < '$date2' THEN -qty ELSE 0 END) AS prd1,
                SUM(CASE WHEN tran_date >= '$date2' AND tran_date < '$date3' THEN -qty ELSE 0 END) AS prd2,
                SUM(CASE WHEN tran_date >= '$date3' AND tran_date < '$date4' THEN -qty ELSE 0 END) AS prd3,
                SUM(CASE WHEN tran_date >= '$date4' AND tran_date <= '$date5' THEN -qty ELSE 0 END) AS prd4
                FROM ".TB_PREF."stock_moves
                WHERE stock_id = ".$this->db->escape($stockId)."
                  AND loc_code = ".$this->db->escape($location)."
                  AND (type = ".ST_CUSTDELIVERY." OR type = ".ST_CUSTCREDIT.")";
        
        $result = $this->db->fetchOne($sql);
        
        return $result ?: [
            'prd0' => 0,
            'prd1' => 0,
            'prd2' => 0,
            'prd3' => 0,
            'prd4' => 0
        ];
    }
    
    /**
     * Get required quantity for work orders
     */
    private function getRequiredQty(string $stockId, string $location): float
    {
        $sql = "SELECT SUM(units_reqd - units_issued) AS required
                FROM ".TB_PREF."wo_requirements req
                INNER JOIN ".TB_PREF."workorders wo ON req.workorder_id = wo.id
                WHERE req.stock_id = ".$this->db->escape($stockId)."
                  AND wo.loc_code = ".$this->db->escape($location)."
                  AND wo.released = 1";
        
        $result = $this->db->fetchOne($sql);
        return $result ? (float)$result['required'] : 0.0;
    }
    
    /**
     * Get quantity on purchase orders
     */
    private function getOnOrderQty(string $stockId, string $location): float
    {
        $sql = "SELECT SUM(quantity_ordered - quantity_received) AS on_order
                FROM ".TB_PREF."purch_order_details pod
                INNER JOIN ".TB_PREF."purch_orders po ON pod.order_no = po.order_no
                WHERE pod.item_code = ".$this->db->escape($stockId)."
                  AND po.into_stock_location = ".$this->db->escape($location)."
                  AND quantity_ordered > quantity_received";
        
        $result = $this->db->fetchOne($sql);
        return $result ? (float)$result['on_order'] : 0.0;
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        $dec = \FA\UserPrefsCache::getQtyDecimals();
        
        foreach ($processedData['categories'] as $catData) {
            $rep->fontSize += 2;
            $rep->TextCol(0, 1, $catData['category_id']);
            $rep->TextCol(1, 4, $catData['cat_description']);
            $rep->fontSize -= 2;
            $rep->NewLine(2);
            
            foreach ($catData['items'] as $item) {
                $displayDesc = $item['description'];
                if ($item['inactive']) {
                    $displayDesc .= ' ('._('Inactive').')';
                }
                
                $rep->TextCol(1, 2, $displayDesc);
                $rep->AmountCol(2, 3, $item['qty_on_hand'], $dec);
                $rep->AmountCol(3, 4, $item['prd4'], $dec);
                $rep->AmountCol(4, 5, $item['prd3'], $dec);
                $rep->AmountCol(5, 6, $item['prd2'], $dec);
                $rep->AmountCol(6, 7, $item['prd1'], $dec);
                $rep->AmountCol(7, 8, $item['prd0'], $dec);
                $rep->AmountCol(8, 9, $item['average'], $dec);
                $rep->AmountCol(9, 10, $item['required'], $dec);
                $rep->AmountCol(10, 11, $item['on_order'], $dec);
                
                if ($item['suggest'] != 0) {
                    $rep->AmountCol(11, 12, $item['suggest'], $dec);
                }
                
                $rep->NewLine();
                
                if ($rep->row < $rep->bottomMargin + (3 * $rep->lineHeight)) {
                    $rep->NewPage();
                }
            }
            
            $rep->NewLine();
        }
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
