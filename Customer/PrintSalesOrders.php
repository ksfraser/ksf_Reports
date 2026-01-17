<?php
/**
 * Print Sales Orders Service (including Quotations)
 * 
 * Generates sales order/quotation documents with:
 * - Line items with discounts
 * - Tax calculations (included/excluded)
 * - Freight charges
 * - Summary totals
 * 
 * Report: rep109
 * Category: Customer Reports
 */

declare(strict_types=1);

namespace FA\Reports\Customer;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class PrintSalesOrders extends AbstractReportService
{
    private const REPORT_ID = 109;
    private const REPORT_TITLE = 'SALES ORDER';
    
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
            'print_quote' => $config->getParam('print_as_quote')
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $from = $config->getParam('from');
        $to = $config->getParam('to');
        $currency = $config->getParam('currency');
        
        $orders = [];
        for ($i = $from; $i <= $to; $i++) {
            $order = get_sales_order_header($i, ST_SALESORDER);
            if (!$order) continue;
            
            if ($currency != ALL_TEXT && $order['curr_code'] != $currency) {
                continue;
            }
            
            $order['bank_account'] = get_default_bank_account($order['curr_code']);
            $order['branch'] = get_branch($order['branch_code']);
            $order['contacts'] = get_branch_contacts($order['branch_code'], 'order', $order['debtor_no'], true);
            $order['details'] = $this->getOrderDetails($i);
            
            $orders[] = $order;
        }
        
        $data = [
            'orders' => $orders,
            'email' => $config->getParam('email', 0),
            'print_as_quote' => $config->getParam('print_as_quote', 0)
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
        
        $processedOrders = [];
        foreach ($data['orders'] as $order) {
            $items = [];
            $prices = [];
            $subtotal = 0;
            
            foreach ($order['details'] as $line) {
                $net = round2(
                    (1 - $line['discount_percent']) * $line['unit_price'] * $line['quantity'],
                    $dec
                );
                
                $items[] = $line['stk_code'];
                $prices[] = $net;
                $subtotal += $net;
                
                $line['net'] = $net;
                $line['display_price'] = \FormatService::numberFormat2($line['unit_price'], $dec);
                $line['display_qty'] = \FormatService::numberFormat2($line['quantity'], get_qty_dec($line['stk_code']));
                $line['display_net'] = \FormatService::numberFormat2($net, $dec);
                $line['display_discount'] = $line['discount_percent'] == 0 ? '' : 
                    \FormatService::numberFormat2($line['discount_percent'] * 100, \FA\UserPrefsCache::getPercentDecimals()) . '%';
                
                $order['processed_details'][] = $line;
            }
            
            $order['items'] = $items;
            $order['prices'] = $prices;
            $order['subtotal'] = $subtotal;
            $order['taxes'] = get_tax_for_items($items, $prices, $order['freight_cost'], 
                $order['tax_group_id'], $order['tax_included'], null);
            
            $processedOrders[] = $order;
        }
        
        $processed = [
            'orders' => $processedOrders,
            'email' => $data['email'],
            'print_as_quote' => $data['print_as_quote']
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    private function getOrderDetails(int $orderId): array
    {
        $result = get_sales_order_details($orderId, ST_SALESORDER);
        $details = [];
        while ($row = db_fetch($result)) {
            $details[] = $row;
        }
        return $details;
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
        $printAsQuote = $processedData['print_as_quote'];
        
        foreach ($processedData['orders'] as $order) {
            if ($email == 1) {
                global $path_to_root;
                include_once($path_to_root . "/reporting/includes/pdf_report.inc");
                
                $orientation = $this->getDefaultOrientation();
                $rep = new \FrontReport("", "", user_pagesize(), 9, $orientation);
                
                if ($printAsQuote == 1) {
                    $rep->title = _('QUOTE');
                    $rep->filename = "Quote" . $order['order_no'] . ".pdf";
                } else {
                    $rep->title = _("SALES ORDER");
                    $rep->filename = "SalesOrder" . $order['order_no'] . ".pdf";
                }
                
                if ($orientation == 'L') {
                    $cols = $this->defineColumns();
                    recalculate_cols($cols);
                }
            }
            
            $rep->currency = $cur;
            $rep->Font();
            
            $params = $this->defineParams($config);
            $params['bankaccount'] = $order['bank_account']['id'];
            $cols = $this->defineColumns();
            $aligns = $this->defineAlignments();
            $rep->Info($params, $cols, null, $aligns);
            
            $rep->SetCommonData($order, $order['branch'], $order, $order['bank_account'], ST_SALESORDER, $order['contacts']);
            $rep->SetHeaderType('Header2');
            $rep->NewPage();
            
            // Line items
            foreach ($order['processed_details'] as $line) {
                $rep->TextCol(0, 1, $line['stk_code'], -2);
                $oldrow = $rep->row;
                $rep->TextColLines(1, 2, $line['description'], -2);
                $newrow = $rep->row;
                $rep->row = $oldrow;
                
                if ($line['net'] != 0.0 || !\FA\Services\InventoryService::isService($line['mb_flag']) || 
                    !$SysPrefs->no_zero_lines_amount()) {
                    $rep->TextCol(2, 3, $line['display_qty'], -2);
                    $rep->TextCol(3, 4, $line['units'], -2);
                    $rep->TextCol(4, 5, $line['display_price'], -2);
                    $rep->TextCol(5, 6, $line['display_discount'], -2);
                    $rep->TextCol(6, 7, $line['display_net'], -2);
                }
                
                $rep->row = $newrow;
                if ($rep->row < $rep->bottomMargin + (15 * $rep->lineHeight)) {
                    $rep->NewPage();
                }
            }
            
            // Comments
            if ($order['comments'] != "") {
                $rep->NewLine();
                $rep->TextColLines(1, 3, $order['comments'], -2);
            }
            
            // Summary
            $rep->row = $rep->bottomMargin + (15 * $rep->lineHeight);
            
            $displaySubTotal = \FormatService::numberFormat2($order['subtotal'], $dec);
            $rep->TextCol(3, 6, _("Sub-total"), -2);
            $rep->TextCol(6, 7, $displaySubTotal, -2);
            $rep->NewLine();
            
            if ($order['freight_cost'] != 0.0) {
                $displayFreight = \FormatService::numberFormat2($order['freight_cost'], $dec);
                $rep->TextCol(3, 6, _("Shipping"), -2);
                $rep->TextCol(6, 7, $displayFreight, -2);
                $rep->NewLine();
            }
            
            $total = $order['freight_cost'] + $order['subtotal'];
            $displayTotal = \FormatService::numberFormat2($total, $dec);
            
            if ($order['tax_included'] == 0) {
                $rep->TextCol(3, 6, _("TOTAL ORDER EX VAT"), -2);
                $rep->TextCol(6, 7, $displayTotal, -2);
                $rep->NewLine();
            }
            
            // Taxes
            $first = true;
            foreach ($order['taxes'] as $tax) {
                if ($tax['Value'] == 0) continue;
                
                $displayTax = \FormatService::numberFormat2($tax['Value'], $dec);
                $taxName = $tax['tax_type_name'];
                
                if ($order['tax_included']) {
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
                        $rep->TextCol(3, 7, _("Included") . " " . $taxName . " " . _("Amount") . ": " . $displayTax, -2);
                    }
                } else {
                    $total += $tax['Value'];
                    $rep->TextCol(3, 6, $taxName, -2);
                    $rep->TextCol(6, 7, $displayTax, -2);
                }
                $rep->NewLine();
            }
            
            $rep->NewLine();
            $displayTotal = \FormatService::numberFormat2($total, $dec);
            $rep->Font('bold');
            $rep->TextCol(3, 6, _("TOTAL ORDER VAT INCL."), -2);
            $rep->TextCol(6, 7, $displayTotal, -2);
            
            $words = price_in_words($total, ST_SALESORDER);
            if ($words != "") {
                $rep->NewLine(1);
                $rep->Font();
                $rep->TextCol(0, 7, $order['curr_code'] . ": " . $words, -2);
            }
            
            if ($email == 1) {
                $rep->End($email, _("Sales Order") . " " . $order['order_no'] . " " . _("from") . " " . 
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
