<?php
/**
 * Print Invoices Service
 * 
 * Generates customer invoice documents with:
 * - Invoice header with customer/branch details
 * - Line items with quantities, prices, discounts
 * - Tax calculations (included/excluded)
 * - Prepayment/partial invoice support
 * - Freight charges
 * - Total with price in words
 * 
 * Report: rep107
 * Category: Customer Reports
 */

declare(strict_types=1);

namespace FA\Reports\Customer;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class PrintInvoices extends AbstractReportService
{
    private const REPORT_ID = 107;
    private const REPORT_TITLE = 'INVOICE';
    
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
        return ['left', 'left', 'right', 'center', 'right', 'right', 'right'];
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
        $customer = $config->getParam('customer', null);
        
        $fno = explode("-", $from);
        $tno = explode("-", $to);
        $fromNum = min($fno[0], $tno[0]);
        $toNum = max($fno[0], $tno[0]);
        
        // Get invoice range
        $range = $this->getInvoiceRange($fromNum, $toNum, $currency);
        
        $invoices = [];
        foreach ($range as $row) {
            if (!exists_customer_trans(ST_SALESINVOICE, $row['trans_no'])) {
                continue;
            }
            
            $invoice = get_customer_trans($row['trans_no'], ST_SALESINVOICE);
            
            if ($customer && $invoice['debtor_no'] != $customer) {
                continue;
            }
            
            $invoice['details'] = $this->getInvoiceDetails($row['trans_no']);
            $invoice['branch'] = get_branch($invoice['branch_code']);
            $invoice['sales_order'] = get_sales_order_header($invoice['order_'], ST_SALESORDER);
            $invoice['bank_account'] = get_default_bank_account($invoice['curr_code']);
            $invoice['contacts'] = get_branch_contacts($invoice['branch']['branch_code'], 'invoice', $invoice['branch']['debtor_no'], true);
            $invoice['memo'] = get_comments_string(ST_SALESINVOICE, $row['trans_no']);
            $invoice['tax_items'] = $this->getTaxDetails($row['trans_no']);
            
            // Get prepayments if applicable
            $invoice['prepayments'] = [];
            if (!empty($invoice['prepaid'])) {
                $prepayResult = get_sales_order_invoices($invoice['order_']);
                while ($inv = db_fetch($prepayResult)) {
                    $invoice['prepayments'][] = $inv;
                    if ($inv['trans_no'] == $row['trans_no']) {
                        break;
                    }
                }
            }
            
            $invoices[] = $invoice;
        }
        
        $data = [
            'invoices' => $invoices,
            'email' => $config->getParam('email', 0),
            'pay_service' => $config->getParam('pay_service'),
            'long_description' => !empty($SysPrefs->prefs['long_description_invoice']),
            'no_zero_lines' => $SysPrefs->no_zero_lines_amount()
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
        
        $processedInvoices = [];
        foreach ($data['invoices'] as $invoice) {
            $sign = 1;
            $subTotal = 0.0;
            $processedDetails = [];
            
            foreach ($invoice['details'] as $detail) {
                if ($detail['quantity'] == 0) {
                    continue;
                }
                
                $net = round2($sign * ((1 - $detail['discount_percent']) * $detail['unit_price'] * $detail['quantity']), $dec);
                $subTotal += $net;
                
                $processedDetails[] = [
                    'stock_id' => $detail['stock_id'],
                    'description' => $detail['StockDescription'],
                    'long_description' => $detail['StockLongDescription'] ?? '',
                    'quantity' => (float)$detail['quantity'] * $sign,
                    'units' => $detail['units'],
                    'unit_price' => (float)$detail['unit_price'],
                    'discount_percent' => (float)$detail['discount_percent'],
                    'net' => $net,
                    'mb_flag' => $detail['mb_flag']
                ];
            }
            
            $processedInvoices[] = [
                'invoice_data' => $invoice,
                'details' => $processedDetails,
                'subtotal' => $subTotal,
                'sign' => $sign
            ];
        }
        
        $processed = [
            'invoices' => $processedInvoices,
            'email' => $data['email'],
            'pay_service' => $data['pay_service'],
            'long_description' => $data['long_description'],
            'no_zero_lines' => $data['no_zero_lines']
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    private function getInvoiceRange(int $from, int $to, $currency): array
    {
        global $SysPrefs;
        
        $ref = ($SysPrefs->print_invoice_no() == 1 ? "trans_no" : "reference");
        
        $sql = "SELECT trans.trans_no, trans.reference
                FROM ".TB_PREF."debtor_trans trans 
                    LEFT JOIN ".TB_PREF."voided voided ON trans.type=voided.type AND trans.trans_no=voided.id";
        
        if ($currency !== false && $currency != ALL_TEXT) {
            $sql .= " LEFT JOIN ".TB_PREF."debtors_master cust ON trans.debtor_no=cust.debtor_no";
        }
        
        $sql .= " WHERE trans.type=".ST_SALESINVOICE."
                  AND ISNULL(voided.id)
                  AND trans.trans_no BETWEEN ".$this->db->escape($from)." AND ".$this->db->escape($to);
        
        if ($currency !== false && $currency != ALL_TEXT) {
            $sql .= " AND cust.curr_code=".$this->db->escape($currency);
        }
        
        $sql .= " ORDER BY trans.tran_date, trans.$ref";
        
        return $this->db->fetchAll($sql);
    }
    
    private function getInvoiceDetails(int $transNo): array
    {
        return get_customer_trans_details(ST_SALESINVOICE, $transNo);
    }
    
    private function getTaxDetails(int $transNo): array
    {
        $taxItems = get_trans_tax_details(ST_SALESINVOICE, $transNo);
        $items = [];
        while ($item = db_fetch($taxItems)) {
            $items[] = $item;
        }
        return $items;
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
        
        foreach ($processedData['invoices'] as $invData) {
            $invoice = $invData['invoice_data'];
            $sign = $invData['sign'];
            
            if ($email == 1) {
                global $path_to_root;
                include_once($path_to_root . "/reporting/includes/pdf_report.inc");
                
                $orientation = $this->getDefaultOrientation();
                $rep = new \FrontReport("", "", user_pagesize(), 9, $orientation);
                $rep->title = $this->getReportTitle();
                $rep->filename = "Invoice" . $invoice['reference'] . ".pdf";
                
                if ($orientation == 'L') {
                    $cols = $this->defineColumns();
                    recalculate_cols($cols);
                }
            }
            
            $rep->currency = $cur;
            $rep->Font();
            
            $params = $this->defineParams($config);
            $params['bankaccount'] = $invoice['bank_account']['id'];
            $cols = $this->defineColumns();
            $aligns = $this->defineAlignments();
            $rep->Info($params, $cols, null, $aligns);
            
            $invoice['bank_account']['payment_service'] = $processedData['pay_service'];
            $rep->SetCommonData($invoice, $invoice['branch'], $invoice['sales_order'], $invoice['bank_account'], ST_SALESINVOICE, $invoice['contacts']);
            $rep->SetHeaderType('Header2');
            $rep->NewPage();
            
            $summaryStartRow = $rep->bottomMargin + (15 * $rep->lineHeight);
            $showThisPayment = $rep->formData['prepaid'] == 'partial';
            
            // Adjust for prepayments
            if ($rep->formData['prepaid'] && count($invoice['prepayments']) > ($showThisPayment ? 0 : 1)) {
                $summaryStartRow += count($invoice['prepayments']) * $rep->lineHeight;
            }
            
            // Line items
            foreach ($invData['details'] as $detail) {
                $displayPrice = \FormatService::numberFormat2($detail['unit_price'], $dec);
                $displayQty = \FormatService::numberFormat2($detail['quantity'], get_qty_dec($detail['stock_id']));
                $displayNet = \FormatService::numberFormat2($detail['net'], $dec);
                $displayDiscount = $detail['discount_percent'] == 0 ? "" : \FormatService::numberFormat2($detail['discount_percent'] * 100, \FA\UserPrefsCache::getPercentDecimals()) . "%";
                
                $c = 0;
                $rep->TextCol($c++, $c, $detail['stock_id'], -2);
                $oldrow = $rep->row;
                $rep->TextColLines($c++, $c, $detail['description'], -2);
                
                if ($processedData['long_description'] && !empty($detail['long_description'])) {
                    $c--;
                    $rep->TextColLines($c++, $c, $detail['long_description'], -2);
                }
                
                $newrow = $rep->row;
                $rep->row = $oldrow;
                
                if ($detail['net'] != 0.0 || !\FA\Services\InventoryService::isService($detail['mb_flag']) || !$processedData['no_zero_lines']) {
                    $rep->TextCol($c++, $c, $displayQty, -2);
                    $rep->TextCol($c++, $c, $detail['units'], -2);
                    $rep->TextCol($c++, $c, $displayPrice, -2);
                    $rep->TextCol($c++, $c, $displayDiscount, -2);
                    $rep->TextCol($c++, $c, $displayNet, -2);
                }
                
                $rep->row = $newrow;
                if ($rep->row < $summaryStartRow) {
                    $rep->NewPage();
                }
            }
            
            // Memo
            if ($invoice['memo'] != "") {
                $rep->NewLine();
                $rep->TextColLines(1, 3, $invoice['memo'], -2);
            }
            
            // Summary section
            $rep->row = $summaryStartRow;
            
            // Prepayments table
            if (!empty($invoice['prepayments']) && count($invoice['prepayments']) > ($showThisPayment ? 0 : 1)) {
                $rep->TextCol(0, 3, _("Prepayments invoiced to this order up to day:"));
                $rep->TextCol(0, 3, str_pad('', 150, '_'));
                $rep->cols[2] -= 20;
                $rep->aligns[2] = 'right';
                $rep->NewLine();
                $rep->TextCol(0, 3, str_pad('', 150, '_'));
                
                $c = 0;
                $rep->TextCol($c++, $c, _("Date"));
                $rep->TextCol($c++, $c, _("Invoice reference"));
                $rep->TextCol($c++, $c, _("Amount"));
                
                $totPym = 0.0;
                foreach ($invoice['prepayments'] as $prep) {
                    if ($showThisPayment || ($prep['reference'] != $invoice['reference'])) {
                        $rep->NewLine();
                        $c = 0;
                        $totPym += $prep['prep_amount'];
                        $rep->TextCol($c++, $c, \DateService::sql2dateStatic($prep['tran_date']));
                        $rep->TextCol($c++, $c, $prep['reference']);
                        $rep->TextCol($c++, $c, \FormatService::numberFormat2($prep['prep_amount'], $dec));
                    }
                    if ($prep['reference'] == $invoice['reference']) break;
                }
                
                $rep->TextCol(0, 3, str_pad('', 150, '_'));
                $rep->NewLine();
                $rep->TextCol(1, 2, _("Total payments:"));
                $rep->TextCol(2, 3, \FormatService::numberFormat2($totPym, $dec));
            }
            
            $rep->row = $summaryStartRow;
            $rep->cols[2] += 20;
            $rep->cols[3] += 20;
            $rep->aligns[3] = 'left';
            
            // Subtotal
            $rep->TextCol(3, 6, _("Sub-total"), -2);
            $rep->TextCol(6, 7, \FormatService::numberFormat2($invData['subtotal'], $dec), -2);
            $rep->NewLine();
            
            // Freight
            if ($invoice['ov_freight'] != 0.0) {
                $rep->TextCol(3, 6, _("Shipping"), -2);
                $rep->TextCol(6, 7, \FormatService::numberFormat2($sign * $invoice['ov_freight'], $dec), -2);
                $rep->NewLine();
            }
            
            // Taxes
            $first = true;
            foreach ($invoice['tax_items'] as $taxItem) {
                if ($taxItem['amount'] == 0) continue;
                
                $displayTax = \FormatService::numberFormat2($sign * $taxItem['amount'], $dec);
                $taxTypeName = $SysPrefs->suppress_tax_rates() == 1 ? 
                    $taxItem['tax_type_name'] : 
                    $taxItem['tax_type_name'] . " (" . $taxItem['rate'] . "%) ";
                
                if ($invoice['tax_included']) {
                    if ($SysPrefs->alternative_tax_include_on_docs() == 1) {
                        if ($first) {
                            $rep->TextCol(3, 6, _("Total Tax Excluded"), -2);
                            $rep->TextCol(6, 7, \FormatService::numberFormat2($sign * $taxItem['net_amount'], $dec), -2);
                            $rep->NewLine();
                        }
                        $rep->TextCol(3, 6, $taxTypeName, -2);
                        $rep->TextCol(6, 7, $displayTax, -2);
                        $first = false;
                    } else {
                        $rep->TextCol(3, 6, _("Included") . " " . $taxTypeName . _("Amount") . ": " . $displayTax, -2);
                    }
                } else {
                    $rep->TextCol(3, 6, $taxTypeName, -2);
                    $rep->TextCol(6, 7, $displayTax, -2);
                }
                $rep->NewLine();
            }
            
            // Total
            $rep->NewLine();
            $displayTotal = \FormatService::numberFormat2($sign * ($invoice['ov_freight'] + $invoice['ov_gst'] + $invoice['ov_amount'] + $invoice['ov_freight_tax']), $dec);
            $rep->Font('bold');
            $rep->TextCol(3, 6, $rep->formData['prepaid'] ? _("TOTAL ORDER VAT INCL.") : _("TOTAL INVOICE"), -2);
            $rep->TextCol(6, 7, $displayTotal, -2);
            
            if ($rep->formData['prepaid']) {
                $rep->NewLine();
                $rep->Font('bold');
                $rep->TextCol(3, 6, $rep->formData['prepaid'] == 'final' ? _("THIS INVOICE") : _("TOTAL INVOICE"), -2);
                $rep->TextCol(6, 7, \FormatService::numberFormat2($invoice['prep_amount'], $dec), -2);
            }
            
            $words = price_in_words($rep->formData['prepaid'] ? $invoice['prep_amount'] : $invoice['Total'], 
                ['type' => ST_SALESINVOICE, 'currency' => $invoice['curr_code']]);
            
            if ($words != "") {
                $rep->NewLine(1);
                $rep->TextCol(1, 7, $invoice['curr_code'] . ": " . $words, -2);
            }
            $rep->Font();
            
            if ($email == 1) {
                $rep->End($email, sprintf(_("Invoice %s from %s"), $invoice['reference'], htmlspecialchars_decode(\FA\Services\CompanyPrefsService::getCompanyPref('coy_name'))));
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
