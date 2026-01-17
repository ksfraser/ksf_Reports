<?php
/**
 * Purchase Orders Document Printer Service
 * 
 * Generates purchase order documents for printing/email:
 * - PO header with supplier details
 * - Line items with delivery dates, quantities, prices
 * - Tax calculations with included/excluded handling
 * - Subtotal and total with price in words
 * - Email support for individual POs
 * 
 * Report: rep209
 * Category: Supplier Reports
 */

declare(strict_types=1);

namespace FA\Reports\Supplier;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class PurchaseOrders extends AbstractReportService
{
    private const REPORT_ID = 209;
    private const REPORT_TITLE = 'PURCHASE ORDER';
    
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
        return [4, 60, 225, 300, 340, 385, 450, 515];
    }
    
    protected function defineHeaders(): array
    {
        return []; // Headers defined in doctext
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'right', 'left', 'right', 'right'];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        return [
            'comments' => $config->getParam('comments')
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        global $SysPrefs;
        
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $from = $config->getParam('from');
        $to = $config->getParam('to');
        $currency = $config->getParam('currency');
        $email = $config->getParam('email', 0);
        
        // Get purchase orders in range
        $purchaseOrders = [];
        for ($i = $from; $i <= $to; $i++) {
            $po = $this->getPurchaseOrder($i);
            if ($po !== false) {
                // Skip if currency filter doesn't match
                if ($currency != ALL_TEXT && $po['curr_code'] != $currency) {
                    continue;
                }
                
                $po['details'] = $this->getPODetails($i);
                $po['bank_account'] = get_default_bank_account($po['curr_code']);
                $po['contacts'] = get_supplier_contacts($po['supplier_id'], 'order');
                $purchaseOrders[] = $po;
            }
        }
        
        $data = [
            'purchase_orders' => $purchaseOrders,
            'email' => $email,
            'show_po_codes' => $SysPrefs->show_po_item_codes()
        ];
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        return $data;
    }
    
    protected function processData(ReportConfig $config, array $data): array
    {
        global $SysPrefs;
        
        $this->dispatchEvent('before_process_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        
        // Process each purchase order
        $processedPOs = [];
        foreach ($data['purchase_orders'] as $po) {
            $items = [];
            $prices = [];
            $subTotal = 0.0;
            $processedDetails = [];
            
            foreach ($po['details'] as $detail) {
                // Get purchase data for supplier-specific info
                $purchaseData = get_purchase_data($po['supplier_id'], $detail['item_code']);
                
                if ($purchaseData !== false) {
                    // Use supplier description if available
                    if (!$detail['editable'] && $purchaseData['supplier_description'] != "" && 
                        $detail['description'] != $purchaseData['supplier_description']) {
                        $detail['description'] = $purchaseData['supplier_description'];
                    }
                    
                    // Use supplier UOM if available
                    if ($purchaseData['suppliers_uom'] != "") {
                        $detail['units'] = $purchaseData['suppliers_uom'];
                    }
                    
                    // Convert quantities/prices if needed
                    if ($purchaseData['conversion_factor'] != 1) {
                        $detail['unit_price'] = round2($detail['unit_price'] * $purchaseData['conversion_factor'], $dec);
                        $detail['quantity_ordered'] = round2($detail['quantity_ordered'] / $purchaseData['conversion_factor'], get_qty_dec($detail['item_code']));
                    }
                }
                
                $net = round2($detail['unit_price'] * $detail['quantity_ordered'], $dec);
                $prices[] = $net;
                $items[] = $detail['item_code'];
                $subTotal += $net;
                
                $processedDetails[] = [
                    'item_code' => $detail['item_code'],
                    'description' => $detail['description'],
                    'delivery_date' => $detail['delivery_date'],
                    'quantity_ordered' => (float)$detail['quantity_ordered'],
                    'units' => $detail['units'],
                    'unit_price' => (float)$detail['unit_price'],
                    'net' => $net
                ];
            }
            
            // Calculate taxes
            $taxItems = get_tax_for_items($items, $prices, 0,
                $po['tax_group_id'], $po['tax_included'], null, TCA_LINES);
            
            $totalAmount = $subTotal;
            $processedTaxes = [];
            
            foreach ($taxItems as $taxItem) {
                if ($taxItem['Value'] == 0) {
                    continue;
                }
                
                if (!$po['tax_included']) {
                    $totalAmount += $taxItem['Value'];
                }
                
                $processedTaxes[] = [
                    'tax_type_name' => $taxItem['tax_type_name'],
                    'value' => (float)$taxItem['Value'],
                    'net_amount' => (float)$taxItem['net_amount']
                ];
            }
            
            $processedPOs[] = [
                'po_data' => $po,
                'details' => $processedDetails,
                'taxes' => $processedTaxes,
                'subtotal' => $subTotal,
                'total' => $totalAmount,
                'words' => price_in_words($totalAmount, ST_PURCHORDER)
            ];
        }
        
        $processed = [
            'purchase_orders' => $processedPOs,
            'email' => $data['email'],
            'show_po_codes' => $data['show_po_codes']
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get purchase order
     */
    private function getPurchaseOrder(int $orderNo)
    {
        $sql = "SELECT po.*, supplier.supp_name, supplier.supp_account_no, supplier.tax_included,
                    supplier.gst_no AS tax_id,
                    supplier.curr_code, supplier.payment_terms, loc.location_name,
                    supplier.address, supplier.contact, supplier.tax_group_id
                FROM ".TB_PREF."purch_orders po,"
                    .TB_PREF."suppliers supplier,"
                    .TB_PREF."locations loc
                WHERE po.supplier_id = supplier.supplier_id
                  AND loc.loc_code = into_stock_location
                  AND po.order_no = ".$this->db->escape($orderNo);
        
        return $this->db->fetchOne($sql);
    }
    
    /**
     * Get PO details
     */
    private function getPODetails(int $orderNo): array
    {
        $sql = "SELECT poline.*, units, editable
                FROM ".TB_PREF."purch_order_details poline
                    LEFT JOIN ".TB_PREF."stock_master item ON poline.item_code=item.stock_id
                WHERE order_no =".$this->db->escape($orderNo)."
                ORDER BY po_detail_item";
        
        return $this->db->fetchAll($sql);
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        global $SysPrefs;
        
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        $cur = \FA\Services\CompanyPrefsService::getDefaultCurrency();
        $email = $processedData['email'];
        $showPOCodes = $processedData['show_po_codes'];
        
        foreach ($processedData['purchase_orders'] as $poData) {
            $po = $poData['po_data'];
            
            // If emailing individually, create new report for each
            if ($email == 1) {
                global $path_to_root;
                include_once($path_to_root . "/reporting/includes/pdf_report.inc");
                
                $orientation = $this->getDefaultOrientation();
                $rep = new \FrontReport("", "", user_pagesize(), 9, $orientation);
                $rep->title = $this->getReportTitle();
                $rep->filename = "PurchaseOrder" . $po['order_no'] . ".pdf";
                
                if ($orientation == 'L') {
                    $cols = $this->defineColumns();
                    recalculate_cols($cols);
                }
            }
            
            $rep->currency = $cur;
            $rep->Font();
            
            $params = $this->defineParams($config);
            $params['bankaccount'] = $po['bank_account']['id'];
            $cols = $this->defineColumns();
            $aligns = $this->defineAlignments();
            $rep->Info($params, $cols, null, $aligns);
            
            $rep->SetCommonData($po, null, $po, $po['bank_account'], ST_PURCHORDER, $po['contacts']);
            $rep->SetHeaderType('Header2');
            $rep->NewPage();
            
            // Line items
            foreach ($poData['details'] as $detail) {
                $dec2 = 0;
                $displayPrice = price_decimal_format($detail['unit_price'], $dec2);
                $displayQty = \FormatService::numberFormat2($detail['quantity_ordered'], get_qty_dec($detail['item_code']));
                $displayNet = \FormatService::numberFormat2($detail['net'], $dec);
                
                if ($showPOCodes) {
                    $rep->TextCol(0, 1, $detail['item_code'], -2);
                    $rep->TextCol(1, 2, $detail['description'], -2);
                } else {
                    $rep->TextCol(0, 2, $detail['description'], -2);
                }
                
                $rep->TextCol(2, 3, \DateService::sql2dateStatic($detail['delivery_date']), -2);
                $rep->TextCol(3, 4, $displayQty, -2);
                $rep->TextCol(4, 5, $detail['units'], -2);
                $rep->TextCol(5, 6, $displayPrice, -2);
                $rep->TextCol(6, 7, $displayNet, -2);
                $rep->NewLine(1);
                
                if ($rep->row < $rep->bottomMargin + (15 * $rep->lineHeight)) {
                    $rep->NewPage();
                }
            }
            
            // Comments
            if ($po['comments'] != "") {
                $rep->NewLine();
                $rep->TextColLines(1, 4, $po['comments'], -2);
            }
            
            // Subtotal and taxes
            $rep->row = $rep->bottomMargin + (15 * $rep->lineHeight);
            
            $displaySubTot = \FormatService::numberFormat2($poData['subtotal'], $dec);
            $rep->TextCol(3, 6, _("Sub-total"), -2);
            $rep->TextCol(6, 7, $displaySubTot, -2);
            $rep->NewLine();
            
            $first = true;
            foreach ($poData['taxes'] as $taxItem) {
                $displayTax = \FormatService::numberFormat2($taxItem['value'], $dec);
                
                if ($po['tax_included']) {
                    if ($SysPrefs->alternative_tax_include_on_docs() == 1) {
                        if ($first) {
                            $rep->TextCol(3, 6, _("Total Tax Excluded"), -2);
                            $rep->TextCol(6, 7, \FormatService::numberFormat2($taxItem['net_amount'], $dec), -2);
                            $rep->NewLine();
                        }
                        $rep->TextCol(3, 6, $taxItem['tax_type_name'], -2);
                        $rep->TextCol(6, 7, $displayTax, -2);
                        $first = false;
                    } else {
                        $rep->TextCol(3, 7, _("Included") . " " . $taxItem['tax_type_name'] . _("Amount") . ": " . $displayTax, -2);
                    }
                } else {
                    $rep->TextCol(3, 6, $taxItem['tax_type_name'], -2);
                    $rep->TextCol(6, 7, $displayTax, -2);
                }
                $rep->NewLine();
            }
            
            // Total
            $rep->NewLine();
            $displayTotal = \FormatService::numberFormat2($poData['total'], $dec);
            $rep->Font('bold');
            $rep->TextCol(3, 6, _("TOTAL PO"), -2);
            $rep->TextCol(6, 7, $displayTotal, -2);
            
            if ($poData['words'] != "") {
                $rep->NewLine(1);
                $rep->TextCol(1, 7, $po['curr_code'] . ": " . $poData['words'], -2);
            }
            $rep->Font();
            
            // End report for individual email
            if ($email == 1) {
                $po['DebtorName'] = $po['supp_name'];
                if ($po['reference'] == "") {
                    $po['reference'] = $po['order_no'];
                }
                $rep->End($email);
            }
        }
        
        // End bulk report
        if ($email == 0) {
            $rep->End();
        }
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
