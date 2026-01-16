<?php
/**
 * Inventory Valuation Report Service
 * 
 * Generates inventory valuation showing:
 * - Stock quantities by location
 * - Average costs and total values
 * - Category grouping
 * 
 * Report: rep301
 * Category: Inventory Reports
 */

declare(strict_types=1);

namespace FA\Reports\Inventory;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class InventoryValuation extends AbstractReportService
{
    private const REPORT_ID = 301;
    private const REPORT_TITLE = 'Inventory Valuation Report';
    
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
    
    protected function defineColumns(): array
    {
        return [0, 50, 100, 250, 350, 450, 515];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('Category'),
            _('Code'),
            _('Description'),
            _('Quantity'),
            _('Unit Cost'),
            _('Total')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'right', 'right', 'right'];
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
            2 => ['text' => _('Location'), 'from' => $locName, 'to' => ''],
            3 => ['text' => _('At Date'), 'from' => $config->getParam('date'), 'to' => '']
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $category = $config->getParam('category');
        $location = $config->getParam('location');
        $date = $config->getParam('date');
        $dateSql = \DateService::date2sqlStatic($date);
        
        if ($category == ALL_NUMERIC) $category = 0;
        if ($location == ALL_TEXT) $location = 'all';
        
        $sql = "SELECT item.category_id,
                    category.description AS cat_description,
                    item.stock_id,
                    item.units,
                    item.description,
                    item.inactive,
                    move.loc_code,
                    units.decimals,
                    SUM(move.qty) AS QtyOnHand,
                    item.material_cost AS UnitCost,
                    SUM(move.qty) * item.material_cost AS ItemTotal
                FROM ".TB_PREF."stock_master item
                INNER JOIN ".TB_PREF."stock_category category ON item.category_id = category.category_id
                INNER JOIN ".TB_PREF."stock_moves move ON item.stock_id = move.stock_id
                INNER JOIN ".TB_PREF."item_units units ON item.units = units.abbr
                WHERE item.mb_flag <> 'D' 
                  AND item.mb_flag <> 'F'
                  AND move.tran_date <= ".$this->db->escape($dateSql);
        
        if ($category != 0) {
            $sql .= " AND item.category_id = ".$this->db->escape($category);
        }
        
        if ($location != 'all') {
            $sql .= " AND move.loc_code = ".$this->db->escape($location);
        }
        
        $sql .= " GROUP BY item.category_id, category.description, ";
        
        if ($location != 'all') {
            $sql .= "move.loc_code, ";
        }
        
        $sql .= "item.stock_id, item.description
                 HAVING SUM(move.qty) != 0
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
        
        $date = $config->getParam('date');
        $location = $config->getParam('location');
        if ($location == ALL_TEXT) $location = 'all';
        
        $categories = [];
        $currentCategory = null;
        $categoryData = null;
        $grandTotal = 0.0;
        
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
                    'items' => [],
                    'total' => 0.0
                ];
            }
            
            // Calculate average cost for this item
            $avgCost = $this->getAverageCost($item['stock_id'], $item['loc_code'] ?? $location, $date);
            $qty = (float)$item['QtyOnHand'];
            $total = $qty * $avgCost;
            
            $categoryData['items'][] = [
                'stock_id' => $item['stock_id'],
                'description' => $item['description'],
                'loc_code' => $item['loc_code'] ?? '',
                'qty' => $qty,
                'decimals' => $item['decimals'],
                'unit_cost' => $avgCost,
                'total' => $total,
                'inactive' => $item['inactive']
            ];
            
            $categoryData['total'] += $total;
            $grandTotal += $total;
        }
        
        // Add last category
        if ($currentCategory !== null) {
            $categories[] = $categoryData;
        }
        
        $processed = [
            'categories' => $categories,
            'grand_total' => $grandTotal
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Calculate average cost for an item
     */
    private function getAverageCost(string $stockId, string $location, string $toDate): float
    {
        $to = \DateService::date2sqlStatic($toDate);
        
        $sql = "SELECT move.*, 
                    supplier.supplier_id AS person_id,
                    IF(ISNULL(grn.rate), credit.rate, grn.rate) AS ex_rate
                FROM ".TB_PREF."stock_moves move
                LEFT JOIN ".TB_PREF."supp_trans credit 
                    ON credit.trans_no = move.trans_no AND credit.type = move.type
                LEFT JOIN ".TB_PREF."grn_batch grn 
                    ON grn.id = move.trans_no AND 25 = move.type
                LEFT JOIN ".TB_PREF."suppliers supplier 
                    ON IFNULL(grn.supplier_id, credit.supplier_id) = supplier.supplier_id
                WHERE move.stock_id = ".$this->db->escape($stockId)."
                  AND move.tran_date <= ".$this->db->escape($to)."
                  AND move.standard_cost > 0.001
                  AND move.qty <> 0
                  AND move.type <> ".ST_LOCTRANSFER;
        
        if ($location != 'all') {
            $sql .= " AND move.loc_code = ".$this->db->escape($location);
        }
        
        $sql .= " ORDER BY tran_date";
        
        $moves = $this->db->fetchAll($sql);
        
        $qty = 0.0;
        $totCost = 0.0;
        
        foreach ($moves as $row) {
            $qty += $row['qty'];
            $price = $this->getDomesticPrice($row, $stockId);
            $tranCost = $row['qty'] * $price;
            $totCost += $tranCost;
        }
        
        return ($qty == 0) ? 0.0 : $totCost / $qty;
    }
    
    /**
     * Get domestic price for a stock move
     */
    private function getDomesticPrice(array $move, string $stockId): float
    {
        if ($move['type'] == ST_SUPPRECEIVE || $move['type'] == ST_SUPPCREDIT) {
            $price = (float)$move['price'];
            
            // Adjust for foreign currency
            if (!empty($move['person_id']) && $move['person_id'] > 0) {
                $exRate = (float)$move['ex_rate'];
                $price *= $exRate;
            }
        } else {
            // Use standard cost for sales deliveries
            $price = (float)$move['standard_cost'];
        }
        
        return $price;
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        
        foreach ($processedData['categories'] as $catData) {
            $rep->fontSize += 2;
            $rep->TextCol(0, 1, $catData['category_id']);
            $rep->TextCol(1, 5, $catData['cat_description']);
            $rep->fontSize -= 2;
            $rep->NewLine(2);
            
            foreach ($catData['items'] as $item) {
                $rep->TextCol(0, 1, '');
                $rep->TextCol(1, 2, $item['stock_id']);
                
                $displayDesc = $item['description'];
                if ($item['inactive']) {
                    $displayDesc .= ' ('._('Inactive').')';
                }
                $rep->TextCol(2, 3, $displayDesc);
                
                $rep->AmountCol(3, 4, $item['qty'], $item['decimals']);
                $rep->AmountCol(4, 5, $item['unit_cost'], $dec);
                $rep->AmountCol(5, 6, $item['total'], $dec);
                $rep->NewLine();
                
                if ($rep->row < $rep->bottomMargin + (3 * $rep->lineHeight)) {
                    $rep->NewPage();
                }
            }
            
            // Category total
            $rep->NewLine();
            $rep->TextCol(0, 5, _('Total').' '.$catData['cat_description']);
            $rep->AmountCol(5, 6, $catData['total'], $dec);
            $rep->Line($rep->row - 2);
            $rep->NewLine(2);
        }
        
        // Grand total
        if (!empty($processedData['categories'])) {
            $rep->fontSize += 2;
            $rep->TextCol(0, 5, _('Grand Total'));
            $rep->fontSize -= 2;
            $rep->AmountCol(5, 6, $processedData['grand_total'], $dec);
            $rep->Line($rep->row - 4);
            $rep->NewLine();
        }
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
