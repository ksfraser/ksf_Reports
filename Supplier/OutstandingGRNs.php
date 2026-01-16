<?php
/**
 * Outstanding GRNs Report Service
 * 
 * Generates report showing goods received but not yet invoiced:
 * - GRN batch and order details
 * - Quantity received vs invoiced
 * - Value of outstanding GRNs
 * 
 * Report: rep204
 * Category: Supplier/Purchasing Reports
 */

declare(strict_types=1);

namespace FA\Reports\Supplier;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class OutstandingGRNs extends AbstractReportService
{
    private const REPORT_ID = 204;
    private const REPORT_TITLE = 'Outstanding GRNs Report';
    
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
        return [0, 40, 80, 190, 250, 320, 385, 450, 515];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('GRN'),
            _('Order'),
            _('Item').'/'._('Description'),
            _('Qty Recd'),
            _('qty Inv'),
            _('Balance'),
            _('Act Price'),
            _('Value')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return [
            'left', 'left', 'left', 'right', 'right', 'right', 'right', 'right'
        ];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $supplier = $config->getParam('supplier');
        $supplierName = ($supplier == ALL_TEXT) ? _('All') : get_supplier_name($supplier);
        
        return [
            0 => $config->getParam('comments'),
            1 => ['text' => _('Supplier'), 'from' => $supplierName, 'to' => '']
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $supplier = $config->getParam('supplier');
        
        // Get outstanding GRN items
        $sql = "SELECT grn.id,
                    order_no,
                    grn.supplier_id,
                    supplier.supp_name,
                    item.item_code,
                    item.description,
                    qty_recd,
                    quantity_inv,
                    std_cost_unit,
                    act_price,
                    unit_price
                FROM ".TB_PREF."grn_items item
                INNER JOIN ".TB_PREF."grn_batch grn ON grn.id = item.grn_batch_id
                INNER JOIN ".TB_PREF."purch_order_details poline ON item.po_detail_item = poline.po_detail_item
                INNER JOIN ".TB_PREF."suppliers supplier ON grn.supplier_id = supplier.supplier_id
                WHERE qty_recd - quantity_inv != 0";
        
        if ($supplier != ALL_TEXT) {
            $sql .= " AND grn.supplier_id = ".$this->db->escape($supplier);
        }
        
        $sql .= " ORDER BY grn.supplier_id, grn.id";
        
        $grns = $this->db->fetchAll($sql);
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $grns
        ]);
        
        return $grns;
    }
    
    protected function processData(ReportConfig $config, array $data): array
    {
        $this->dispatchEvent('before_process_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        $suppliers = [];
        $currentSupplier = null;
        $supplierData = null;
        $grandTotal = 0.0;
        
        foreach ($data as $grn) {
            $supplierId = $grn['supplier_id'];
            
            // Start new supplier group
            if ($currentSupplier !== $supplierId) {
                if ($currentSupplier !== null) {
                    $suppliers[] = $supplierData;
                }
                
                $currentSupplier = $supplierId;
                $supplierData = [
                    'supplier_id' => $supplierId,
                    'supp_name' => $grn['supp_name'],
                    'items' => [],
                    'total' => 0.0
                ];
            }
            
            // Calculate values
            $qtyRecd = (float)$grn['qty_recd'];
            $qtyInv = (float)$grn['quantity_inv'];
            $balance = $qtyRecd - $qtyInv;
            $actPrice = (float)$grn['act_price'];
            $value = $balance * $actPrice;
            
            $supplierData['items'][] = [
                'grn_id' => $grn['id'],
                'order_no' => $grn['order_no'],
                'item_code' => $grn['item_code'],
                'description' => $grn['description'],
                'qty_recd' => $qtyRecd,
                'qty_inv' => $qtyInv,
                'balance' => $balance,
                'act_price' => $actPrice,
                'value' => $value
            ];
            
            $supplierData['total'] += $value;
            $grandTotal += $value;
        }
        
        // Add last supplier
        if ($currentSupplier !== null) {
            $suppliers[] = $supplierData;
        }
        
        $processed = [
            'suppliers' => $suppliers,
            'grand_total' => $grandTotal
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        
        foreach ($processedData['suppliers'] as $suppData) {
            // Supplier header
            $rep->TextCol(0, 6, $suppData['supp_name']);
            $rep->NewLine(2);
            
            // GRN items
            foreach ($suppData['items'] as $item) {
                $dec2 = get_qty_dec($item['item_code']);
                
                $rep->TextCol(0, 1, $item['grn_id']);
                $rep->TextCol(1, 2, $item['order_no']);
                $rep->TextCol(2, 3, $item['item_code']);
                $rep->NewLine();
                
                $rep->TextCol(2, 3, $item['description']);
                $rep->AmountCol(3, 4, $item['qty_recd'], $dec2);
                $rep->AmountCol(4, 5, $item['qty_inv'], $dec2);
                $rep->AmountCol(5, 6, $item['balance'], $dec2);
                $rep->AmountCol(6, 7, $item['act_price'], $dec);
                $rep->AmountCol(7, 8, $item['value'], $dec);
                $rep->NewLine();
                
                if ($rep->row < $rep->bottomMargin + (3 * $rep->lineHeight)) {
                    $rep->NewPage();
                }
            }
            
            // Supplier total
            $rep->NewLine(2);
            $rep->TextCol(0, 7, _('Total'));
            $rep->AmountCol(7, 8, $suppData['total'], $dec);
            $rep->Line($rep->row - 2);
            $rep->NewLine(3);
        }
        
        // Grand total
        if (!empty($processedData['suppliers'])) {
            $rep->fontSize += 2;
            $rep->TextCol(0, 7, _('Grand Total'));
            $rep->fontSize -= 2;
            $rep->AmountCol(7, 8, $processedData['grand_total'], $dec);
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
