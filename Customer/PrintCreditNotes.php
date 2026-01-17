<?php
/**
 * Print Credit Notes Service
 * 
 * Generates customer credit notes with:
 * - Negative line items (returns/corrections)
 * - Tax calculations (included/excluded)
 * - Discounts and totals
 * - Payment links (optional)
 * 
 * Report: rep113
 * Category: Customer Reports
 */

declare(strict_types=1);

namespace FA\Reports\Customer;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class PrintCreditNotes extends AbstractReportService
{
    private const REPORT_ID = 113;
    private const REPORT_TITLE = 'CREDIT NOTE';
    
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
            'comments' => $config->getParam('comments')
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $from = $config->getParam('from');
        $to = $config->getParam('to');
        $currency = $config->getParam('currency');
        
        $fno = explode("-", $from);
        $tno = explode("-", $to);
        $fromNo = min($fno[0], $tno[0]);
        $toNo = max($fno[0], $tno[0]);
        
        $credits = [];
        for ($i = $fromNo; $i <= $toNo; $i++) {
            if (!exists_customer_trans(ST_CUSTCREDIT, $i)) {
                continue;
            }
            
            $credit = get_customer_trans($i, ST_CUSTCREDIT);
            
            if ($currency != ALL_TEXT && $credit['curr_code'] != $currency) {
                continue;
            }
            
            $credit['bank_account'] = get_default_bank_account($credit['curr_code']);
            $credit['branch'] = get_branch($credit['branch_code']);
            $credit['branch']['disable_branch'] = $config->getParam('paylink', 0); // helper for payment link
            $credit['sales_order'] = null;
            $credit['contacts'] = get_branch_contacts($credit['branch_code'], 'invoice', $credit['debtor_no'], true);
            $credit['details'] = $this->getCreditDetails($i);
            $credit['memo'] = get_comments_string(ST_CUSTCREDIT, $i);
            $credit['taxes'] = $this->getTaxDetails($i);
            
            $credits[] = $credit;
        }
        
        $data = [
            'credits' => $credits,
            'email' => $config->getParam('email', 0)
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
        $sign = -1; // Credit notes are negative
        
        $processedCredits = [];
        foreach ($data['credits'] as $credit) {
            $subtotal = 0;
            $processedDetails = [];
            
            foreach ($credit['details'] as $line) {
                if ($line['quantity'] == 0) {
                    continue;
                }
                
                $net = round2(
                    $sign * (1 - $line['discount_percent']) * $line['unit_price'] * $line['quantity'],
                    $dec
                );
                $subtotal += $net;
                
                $line['net'] = $net;
                $line['display_price'] = \FormatService::numberFormat2($line['unit_price'], $dec);
                $line['display_qty'] = \FormatService::numberFormat2($sign * $line['quantity'], get_qty_dec($line['stock_id']));
                $line['display_net'] = \FormatService::numberFormat2($net, $dec);
                $line['display_discount'] = $line['discount_percent'] == 0 ? '' :
                    \FormatService::numberFormat2($line['discount_percent'] * 100, \FA\UserPrefsCache::getPercentDecimals()) . '%';
                
                $processedDetails[] = $line;
            }
            
            $credit['processed_details'] = $processedDetails;
            $credit['subtotal'] = $subtotal;
            
            $processedCredits[] = $credit;
        }
        
        $processed = [
            'credits' => $processedCredits,
            'email' => $data['email']
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    private function getCreditDetails(int $transNo): array
    {
        $result = get_customer_trans_details(ST_CUSTCREDIT, $transNo);
        $details = [];
        while ($row = db_fetch($result)) {
            $details[] = $row;
        }
        return $details;
    }
    
    private function getTaxDetails(int $transNo): array
    {
        $result = get_trans_tax_details(ST_CUSTCREDIT, $transNo);
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
        
        foreach ($processedData['credits'] as $credit) {
            if ($email == 1) {
                global $path_to_root;
                include_once($path_to_root . "/reporting/includes/pdf_report.inc");
                
                $orientation = $this->getDefaultOrientation();
                $rep = new \FrontReport("", "", user_pagesize(), 9, $orientation);
                $rep->title = _('CREDIT NOTE');
                $rep->filename = "CreditNote" . $credit['reference'] . ".pdf";
                
                if ($orientation == 'L') {
                    $cols = $this->defineColumns();
                    recalculate_cols($cols);
                }
            }
            
            $rep->currency = $cur;
            $rep->Font();
            
            $params = $this->defineParams($config);
            $params['bankaccount'] = $credit['bank_account']['id'];
            $cols = $this->defineColumns();
            $aligns = $this->defineAlignments();
            $rep->Info($params, $cols, null, $aligns);
            
            $rep->SetCommonData($credit, $credit['branch'], $credit['sales_order'], $credit['bank_account'], ST_CUSTCREDIT, $credit['contacts']);
            $rep->SetHeaderType('Header2');
            $rep->NewPage();
            
            // Line items
            foreach ($credit['processed_details'] as $line) {
                $rep->TextCol(0, 1, $line['stock_id'], -2);
                $oldrow = $rep->row;
                $rep->TextColLines(1, 2, $line['StockDescription'], -2);
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
            
            // Memo
            if ($credit['memo'] != "") {
                $rep->NewLine();
                $rep->TextColLines(1, 3, $credit['memo'], -2);
            }
            
            // Summary
            $rep->row = $rep->bottomMargin + (15 * $rep->lineHeight);
            
            $displaySubTotal = \FormatService::numberFormat2($credit['subtotal'], $dec);
            $rep->TextCol(3, 6, _("Sub-total"), -2);
            $rep->TextCol(6, 7, $displaySubTotal, -2);
            $rep->NewLine();
            
            if ($credit['ov_freight'] != 0.0) {
                $displayFreight = \FormatService::numberFormat2(-$credit['ov_freight'], $dec);
                $rep->TextCol(3, 6, _("Shipping"), -2);
                $rep->TextCol(6, 7, $displayFreight, -2);
                $rep->NewLine();
            }
            
            $total = $credit['subtotal'];
            $displayTotal = \FormatService::numberFormat2($total, $dec);
            
            if ($credit['tax_included'] == 0) {
                $rep->TextCol(3, 6, _("TOTAL CREDIT EX VAT"), -2);
                $rep->TextCol(6, 7, $displayTotal, -2);
                $rep->NewLine();
            }
            
            // Taxes
            $first = true;
            foreach ($credit['taxes'] as $tax) {
                if ($tax['amount'] == 0) continue;
                
                $displayTax = \FormatService::numberFormat2(-$tax['amount'], $dec);
                
                if ($SysPrefs->suppress_tax_rates() == 1) {
                    $taxName = $tax['tax_type_name'];
                } else {
                    $taxName = $tax['tax_type_name'] . " (" . $tax['rate'] . "%) ";
                }
                
                if ($credit['tax_included']) {
                    if ($SysPrefs->alternative_tax_include_on_docs() == 1) {
                        if ($first) {
                            $rep->TextCol(3, 6, _("Total Tax Excluded"), -2);
                            $rep->TextCol(6, 7, \FormatService::numberFormat2(-$tax['net_amount'], $dec), -2);
                            $rep->NewLine();
                        }
                        $rep->TextCol(3, 6, $taxName, -2);
                        $rep->TextCol(6, 7, $displayTax, -2);
                        $first = false;
                    } else {
                        $rep->TextCol(3, 7, _("Included") . " " . $taxName . _("Amount") . ": " . $displayTax, -2);
                    }
                } else {
                    $total -= $tax['amount'];
                    $rep->TextCol(3, 6, $taxName, -2);
                    $rep->TextCol(6, 7, $displayTax, -2);
                }
                $rep->NewLine();
            }
            
            $rep->NewLine();
            $displayTotal = \FormatService::numberFormat2($total, $dec);
            $rep->Font('bold');
            $rep->TextCol(3, 6, _("TOTAL CREDIT VAT INCL."), -2);
            $rep->TextCol(6, 7, $displayTotal, -2);
            
            $words = price_in_words($total, ST_CUSTCREDIT);
            if ($words != "") {
                $rep->NewLine(1);
                $rep->Font();
                $rep->TextCol(0, 7, $credit['curr_code'] . ": " . $words, -2);
            }
            
            if ($email == 1) {
                $rep->End($email, _("Credit Note") . " " . $credit['reference'] . " " . _("from") . " " . 
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
