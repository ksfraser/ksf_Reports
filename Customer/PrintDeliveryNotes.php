<?php
/**
 * Print Delivery Notes Service
 * 
 * Generates delivery notes/packing slips with:
 * - Item quantities and descriptions  
 * - Prices (delivery notes only)
 * - Tax calculations (delivery notes only)
 * 
 * Report: rep110
 * Category: Customer Reports
 */

declare(strict_types=1);

namespace FA\Reports\Customer;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class PrintDeliveryNotes extends AbstractReportService
{
    private const REPORT_ID = 110;
    private const REPORT_TITLE = 'DELIVERY';
    
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
        return [4, 60, 225, 300, 325, 385, 450, 515];
    }
    
    protected function defineHeaders(): array
    {
        return []; // Headers in doctext
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'right', 'left', 'right', 'right', 'right'];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        return [
            'comments' => $config->getParam('comments'),
            'packing_slip' => $config->getParam('packing_slip', 0)
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $from = $config->getParam('from');
        $to = $config->getParam('to');
        
        $fno = explode("-", $from);
        $tno = explode("-", $to);
        $from = min($fno[0], $tno[0]);
        $to = max($fno[0], $tno[0]);
        
        $deliveries = [];
        for ($i = $from; $i <= $to; $i++) {
            if (!exists_customer_trans(ST_CUSTDELIVERY, $i)) {
                continue;
            }
            
            $delivery = get_customer_trans($i, ST_CUSTDELIVERY);
            $delivery['branch'] = get_branch($delivery['branch_code']);
            $delivery['sales_order'] = get_sales_order_header($delivery['order_'], ST_SALESORDER);
            $delivery['contacts'] = get_branch_contacts($delivery['branch_code'], 'delivery', $delivery['debtor_no'], true);
            $delivery['details'] = $this->getDeliveryDetails($i);
            $delivery['memo'] = get_comments_string(ST_CUSTDELIVERY, $i);
            
            if ($config->getParam('packing_slip', 0) == 0) {
                $delivery['taxes'] = $this->getTaxDetails($i);
            }
            
            $deliveries[] = $delivery;
        }
        
        $data = [
            'deliveries' => $deliveries,
            'email' => $config->getParam('email', 0),
            'packing_slip' => $config->getParam('packing_slip', 0)
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
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        
        $processedDeliveries = [];
        foreach ($data['deliveries'] as $delivery) {
            $subtotal = 0;
            $processedDetails = [];
            
            foreach ($delivery['details'] as $line) {
                if ($line['quantity'] == 0) {
                    continue;
                }
                
                $net = round2(
                    (1 - $line['discount_percent']) * $line['unit_price'] * $line['quantity'],
                    $dec
                );
                $subtotal += $net;
                
                $line['net'] = $net;
                $line['display_price'] = \FormatService::numberFormat2($line['unit_price'], $dec);
                $line['display_qty'] = \FormatService::numberFormat2($line['quantity'], get_qty_dec($line['stock_id']));
                $line['display_net'] = \FormatService::numberFormat2($net, $dec);
                $line['display_discount'] = $line['discount_percent'] == 0 ? '' :
                    \FormatService::numberFormat2($line['discount_percent'] * 100, \FA\UserPrefsCache::getPercentDecimals()) . '%';
                
                $processedDetails[] = $line;
            }
            
            $delivery['processed_details'] = $processedDetails;
            $delivery['subtotal'] = $subtotal;
            
            $processedDeliveries[] = $delivery;
        }
        
        $processed = [
            'deliveries' => $processedDeliveries,
            'email' => $data['email'],
            'packing_slip' => $data['packing_slip']
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    private function getDeliveryDetails(int $transNo): array
    {
        $result = get_customer_trans_details(ST_CUSTDELIVERY, $transNo);
        $details = [];
        while ($row = db_fetch($result)) {
            $details[] = $row;
        }
        return $details;
    }
    
    private function getTaxDetails(int $transNo): array
    {
        $result = get_trans_tax_details(ST_CUSTDELIVERY, $transNo);
        $taxes = [];
        while ($row = db_fetch($result)) {
            $taxes[] = $row;
        }
        return $taxes;
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
        $packingSlip = $processedData['packing_slip'];
        
        foreach ($processedData['deliveries'] as $delivery) {
            if ($email == 1) {
                global $path_to_root;
                include_once($path_to_root . "/reporting/includes/pdf_report.inc");
                
                $orientation = $this->getDefaultOrientation();
                $rep = new \FrontReport("", "", user_pagesize(), 9, $orientation);
                
                if ($packingSlip == 0) {
                    $rep->title = _('DELIVERY NOTE');
                    $rep->filename = "Delivery" . $delivery['reference'] . ".pdf";
                } else {
                    $rep->title = _('PACKING SLIP');
                    $rep->filename = "Packing_slip" . $delivery['reference'] . ".pdf";
                }
                
                if ($orientation == 'L') {
                    $cols = $this->defineColumns();
                    recalculate_cols($cols);
                }
            }
            
            $rep->currency = $cur ?? "USD";
            $rep->Font();
            
            $params = $this->defineParams($config);
            $cols = $this->defineColumns();
            $aligns = $this->defineAlignments();
            $rep->Info($params, $cols, null, $aligns);
            
            $rep->SetCommonData($delivery, $delivery['branch'], $delivery['sales_order'], '', ST_CUSTDELIVERY, $delivery['contacts']);
            $rep->SetHeaderType('Header2');
            $rep->NewPage();
            
            // Line items
            foreach ($delivery['processed_details'] as $line) {
                $rep->TextCol(0, 1, $line['stock_id'], -2);
                $oldrow = $rep->row;
                $rep->TextColLines(1, 2, $line['StockDescription'], -2);
                $newrow = $rep->row;
                $rep->row = $oldrow;
                
                if ($line['net'] != 0.0 || !\FA\Services\InventoryService::isService($line['mb_flag']) || 
                    !$SysPrefs->no_zero_lines_amount()) {
                    $rep->TextCol(2, 3, $line['display_qty'], -2);
                    $rep->TextCol(3, 4, $line['units'], -2);
                    
                    if ($packingSlip == 0) {
                        $rep->TextCol(4, 5, $line['display_price'], -2);
                        $rep->TextCol(5, 6, $line['display_discount'], -2);
                        $rep->TextCol(6, 7, $line['display_net'], -2);
                    }
                }
                
                $rep->row = $newrow;
                if ($rep->row < $rep->bottomMargin + (15 * $rep->lineHeight)) {
                    $rep->NewPage();
                }
            }
            
            // Memo
            if ($delivery['memo'] != "") {
                $rep->NewLine();
                $rep->TextColLines(1, 3, $delivery['memo'], -2);
            }
            
            // Summary (delivery notes only)
            $rep->row = $rep->bottomMargin + (15 * $rep->lineHeight);
            
            if ($packingSlip == 0) {
                $displaySubTotal = \FormatService::numberFormat2($delivery['subtotal'], $dec);
                $rep->TextCol(3, 6, _("Sub-total"), -2);
                $rep->TextCol(6, 7, $displaySubTotal, -2);
                $rep->NewLine();
                
                if ($delivery['ov_freight'] != 0.0) {
                    $displayFreight = \FormatService::numberFormat2($delivery['ov_freight'], $dec);
                    $rep->TextCol(3, 6, _("Shipping"), -2);
                    $rep->TextCol(6, 7, $displayFreight, -2);
                    $rep->NewLine();
                }
                
                // Taxes
                $first = true;
                foreach ($delivery['taxes'] as $tax) {
                    if ($tax['amount'] == 0) continue;
                    
                    $displayTax = \FormatService::numberFormat2($tax['amount'], $dec);
                    
                    if ($SysPrefs->suppress_tax_rates() == 1) {
                        $taxName = $tax['tax_type_name'];
                    } else {
                        $taxName = $tax['tax_type_name'] . " (" . $tax['rate'] . "%) ";
                    }
                    
                    if ($delivery['tax_included']) {
                        if ($SysPrefs->alternative_tax_include_on_docs() == 1) {
                            if ($first) {
                                $rep->TextCol(3, 6, _("Total Tax Excluded"), -2);
                                $rep->TextCol(6, 7, \FormatService::numberFormat2($tax['net_amount'], $dec), -2);
                                $rep->NewLine();
                            }
                            $rep->TextCol(3, 6, $taxName, -2);
                            $rep->TextCol(6, 7, $displayTax, -2);
                            $first = false;
                        } else {
                            $rep->TextCol(3, 7, _("Included") . " " . $taxName . _("Amount") . ": " . $displayTax, -2);
                        }
                    } else {
                        $rep->TextCol(3, 6, $taxName, -2);
                        $rep->TextCol(6, 7, $displayTax, -2);
                    }
                    $rep->NewLine();
                }
                
                $rep->NewLine();
                $total = $delivery['ov_amount'] + $delivery['ov_gst'] + $delivery['ov_freight'];
                $displayTotal = \FormatService::numberFormat2($total, $dec);
                $rep->Font('bold');
                $rep->TextCol(3, 6, _("TOTAL DELIVERY INCL VAT"), -2);
                $rep->TextCol(6, 7, $displayTotal, -2);
            }
            
            if ($email == 1) {
                $title = $packingSlip == 0 ? _("Delivery Note") : _("Packing Slip");
                $rep->End($email, $title . " " . $delivery['reference'] . " " . _("from") . " " . 
                    htmlspecialchars_decode(\FA\Services\CompanyPrefsService::getCompanyPref('coy_name')));
            }
        }
        
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
