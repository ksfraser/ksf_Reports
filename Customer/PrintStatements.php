<?php
/**
 * Print Statements Service
 * 
 * Generates customer statements showing:
 * - Outstanding transactions
 * - Aging analysis (current, 30 days, 60 days, over 60)
 * - Allocated and balance amounts
 * 
 * Report: rep108
 * Category: Customer Reports
 */

declare(strict_types=1);

namespace FA\Reports\Customer;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class PrintStatements extends AbstractReportService
{
    private const REPORT_ID = 108;
    private const REPORT_TITLE = 'STATEMENT';
    
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
        return [4, 100, 130, 190, 250, 320, 385, 450, 515];
    }
    
    protected function defineHeaders(): array
    {
        return []; // Headers in doctext
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'left', 'right', 'right', 'right', 'right'];
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
        
        $customer = $config->getParam('customer');
        $currency = $config->getParam('currency');
        $showAllocated = $config->getParam('show_also_allocated');
        
        // Get customers
        $customers = $this->getCustomers($customer);
        $date = date('Y-m-d');
        
        $statements = [];
        foreach ($customers as $cust) {
            if ($currency != ALL_TEXT && $cust['curr_code'] != $currency) {
                continue;
            }
            
            $trans = $this->getTransactions($cust['debtor_no'], $date, $showAllocated);
            
            if (count($trans) == 0) {
                continue;
            }
            
            $cust['transactions'] = $trans;
            $cust['bank_account'] = get_default_bank_account($cust['curr_code']);
            $cust['contacts'] = get_customer_contacts($cust['debtor_no'], 'invoice');
            $cust['details'] = get_customer_details($cust['debtor_no'], null, $showAllocated);
            $cust['order_'] = "";
            $cust['tran_date'] = $date;
            
            $statements[] = $cust;
        }
        
        $data = [
            'statements' => $statements,
            'email' => $config->getParam('email', 0),
            'date' => $date
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
        
        $processedStatements = [];
        foreach ($data['statements'] as $stmt) {
            $processedTrans = [];
            
            foreach ($stmt['transactions'] as $trans) {
                $processedTrans[] = [
                    'type' => $trans['type'],
                    'reference' => $trans['reference'],
                    'tran_date' => $trans['tran_date'],
                    'due_date' => $trans['due_date'],
                    'total_amount' => abs((float)$trans['TotalAmount']),
                    'allocated' => (float)$trans['Allocated'],
                    'net' => abs((float)$trans['TotalAmount']) - (float)$trans['Allocated']
                ];
            }
            
            $processedStatements[] = [
                'customer_data' => $stmt,
                'transactions' => $processedTrans
            ];
        }
        
        $processed = [
            'statements' => $processedStatements,
            'email' => $data['email'],
            'date' => $data['date']
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    private function getCustomers($customer): array
    {
        $sql = "SELECT debtor_no, name AS DebtorName, address, tax_id, curr_code, curdate() AS tran_date 
                FROM ".TB_PREF."debtors_master";
        
        if ($customer != ALL_TEXT) {
            $sql .= " WHERE debtor_no = ".$this->db->escape($customer);
        } else {
            $sql .= " ORDER BY name";
        }
        
        return $this->db->fetchAll($sql);
    }
    
    private function getTransactions(int $debtorNo, string $date, $showAllocated): array
    {
        $sql = "SELECT trans.type,
                    trans.trans_no,
                    trans.order_,
                    trans.reference,
                    trans.tran_date,
                    trans.due_date,
                    IF(prep_amount, prep_amount, ov_amount + ov_gst + ov_freight + ov_freight_tax + ov_discount) AS TotalAmount,
                    alloc AS Allocated,
                    ((trans.type = ".ST_SALESINVOICE.") AND due_date < ".$this->db->escape($date).") AS OverDue
                FROM ".TB_PREF."debtor_trans trans
                LEFT JOIN ".TB_PREF."voided as v
                    ON trans.trans_no=v.id AND trans.type=v.type
                WHERE tran_date <= ".$this->db->escape($date)."
                  AND debtor_no = ".$this->db->escape($debtorNo)."
                  AND trans.type <> ".ST_CUSTDELIVERY."
                  AND ISNULL(v.date_)
                  AND ABS(ABS(ov_amount) + ov_gst + ov_freight + ov_freight_tax + ov_discount) > ".FLOAT_COMP_DELTA;
        
        if (!$showAllocated) {
            $sql .= " AND ABS(IF(prep_amount, prep_amount, ABS(ov_amount) + ov_gst + ov_freight + ov_freight_tax + ov_discount) - alloc) > ".FLOAT_COMP_DELTA;
        }
        
        $sql .= " ORDER BY tran_date";
        
        return $this->db->fetchAll($sql);
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        global $systypes_array;
        
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        $cur = \FA\Services\CompanyPrefsService::getDefaultCurrency();
        $email = $processedData['email'];
        $date = $processedData['date'];
        
        $pastDueDays1 = \FA\Services\CompanyPrefsService::getCompanyPref('past_due_days');
        $pastDueDays2 = 2 * $pastDueDays1;
        
        $hasData = false;
        
        foreach ($processedData['statements'] as $stmtData) {
            $hasData = true;
            $customer = $stmtData['customer_data'];
            
            if ($email == 1) {
                global $path_to_root;
                include_once($path_to_root . "/reporting/includes/pdf_report.inc");
                
                $orientation = $this->getDefaultOrientation();
                $rep = new \FrontReport("", "", user_pagesize(), 9, $orientation);
                $rep->title = $this->getReportTitle();
                $rep->filename = "Statement" . $customer['debtor_no'] . ".pdf";
                
                if ($orientation == 'L') {
                    $cols = $this->defineColumns();
                    recalculate_cols($cols);
                }
            }
            
            $rep->currency = $cur;
            $rep->Font();
            
            $params = $this->defineParams($config);
            $params['bankaccount'] = $customer['bank_account']['id'];
            $cols = $this->defineColumns();
            $aligns = $this->defineAlignments();
            $rep->Info($params, $cols, null, $aligns);
            
            $rep->SetCommonData($customer, null, null, $customer['bank_account'], ST_STATEMENT, $customer['contacts']);
            $rep->SetHeaderType('Header2');
            $rep->NewPage();
            $rep->NewLine();
            
            $rep->fontSize += 2;
            $rep->TextCol(0, 8, _("Outstanding Transactions"));
            $rep->fontSize -= 2;
            $rep->NewLine(2);
            
            // Transactions
            foreach ($stmtData['transactions'] as $trans) {
                $displayTotal = \FormatService::numberFormat2($trans['total_amount'], $dec);
                $displayAlloc = \FormatService::numberFormat2($trans['allocated'], $dec);
                $displayNet = \FormatService::numberFormat2($trans['net'], $dec);
                
                $rep->TextCol(0, 1, $systypes_array[$trans['type']], -2);
                $rep->TextCol(1, 2, $trans['reference'], -2);
                $rep->TextCol(2, 3, \DateService::sql2dateStatic($trans['tran_date']), -2);
                
                if ($trans['type'] == ST_SALESINVOICE) {
                    $rep->TextCol(3, 4, \DateService::sql2dateStatic($trans['due_date']), -2);
                }
                
                if ($trans['type'] == ST_SALESINVOICE || $trans['type'] == ST_BANKPAYMENT || 
                    ($trans['type'] == ST_JOURNAL && $trans['total_amount'] > 0)) {
                    $rep->TextCol(4, 5, $displayTotal, -2);
                } else {
                    $rep->TextCol(5, 6, $displayTotal, -2);
                }
                
                $rep->TextCol(6, 7, $displayAlloc, -2);
                $rep->TextCol(7, 8, $displayNet, -2);
                $rep->NewLine();
                
                if ($rep->row < $rep->bottomMargin + (10 * $rep->lineHeight)) {
                    $rep->NewPage();
                }
            }
            
            // Aging summary
            $nowdue = "1-" . $pastDueDays1 . " " . _("Days");
            $pastdue1 = ($pastDueDays1 + 1) . "-" . $pastDueDays2 . " " . _("Days");
            $pastdue2 = _("Over") . " " . $pastDueDays2 . " " . _("Days");
            
            $details = $customer['details'];
            $str = [_("Current"), $nowdue, $pastdue1, $pastdue2, _("Total Balance")];
            $str2 = [
                \FormatService::numberFormat2($details["Balance"] - $details["Due"], $dec),
                \FormatService::numberFormat2($details["Due"] - $details["Overdue1"], $dec),
                \FormatService::numberFormat2($details["Overdue1"] - $details["Overdue2"], $dec),
                \FormatService::numberFormat2($details["Overdue2"], $dec),
                \FormatService::numberFormat2($details["Balance"], $dec)
            ];
            
            $col = [
                $rep->cols[0],
                $rep->cols[0] + 110,
                $rep->cols[0] + 210,
                $rep->cols[0] + 310,
                $rep->cols[0] + 410,
                $rep->cols[0] + 510
            ];
            
            $rep->row = $rep->bottomMargin + (10 * $rep->lineHeight - 6);
            for ($i = 0; $i < 5; $i++) {
                $rep->TextWrap($col[$i], $rep->row, $col[$i + 1] - $col[$i], $str[$i], 'right');
            }
            
            $rep->NewLine();
            for ($i = 0; $i < 5; $i++) {
                $rep->TextWrap($col[$i], $rep->row, $col[$i + 1] - $col[$i], $str2[$i], 'right');
            }
            
            if ($email == 1) {
                if ($details["Balance"] != ($details["Balance"] - $details["Due"])) {
                    $rep->End($email, _("Statement") . " " . _("as of") . " " . \DateService::sql2dateStatic($date) . " " . _("from") . " " . htmlspecialchars_decode(\FA\Services\CompanyPrefsService::getCompanyPref('coy_name')));
                } else {
                    display_notification(sprintf(_("Customer %s has no overdue debits. No e-mail is sent."), $customer["DebtorName"]));
                }
            }
        }
        
        if (!$hasData) {
            display_notification(_("No customers with outstanding balances found"));
        } elseif ($email == 0) {
            $rep->End();
        }
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
