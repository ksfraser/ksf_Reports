<?php
/**
 * Print Receipts Service
 * 
 * Generates customer payment receipts with:
 * - Payment allocation to invoices
 * - Total allocated vs remaining
 * - Discount amounts
 * - Bank check details
 * 
 * Report: rep112
 * Category: Customer Reports
 */

declare(strict_types=1);

namespace FA\Reports\Customer;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class PrintReceipts extends AbstractReportService
{
    private const REPORT_ID = 112;
    private const REPORT_TITLE = 'RECEIPT';
    
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
        return [4, 85, 150, 225, 275, 360, 450, 515];
    }
    
    protected function defineHeaders(): array
    {
        return []; // Headers in doctext
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'left', 'right', 'right', 'right'];
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
        
        $receipts = [];
        for ($i = $fromNo; $i <= $toNo; $i++) {
            $types = ($fno[0] == $tno[0]) ? [$fno[1]] : [ST_BANKDEPOSIT, ST_CUSTPAYMENT];
            
            foreach ($types as $type) {
                $receipt = $this->getReceipt($type, $i);
                if (!$receipt) {
                    continue;
                }
                
                if ($currency != ALL_TEXT && $receipt['curr_code'] != $currency) {
                    continue;
                }
                
                $res = get_bank_trans($type, $i);
                $receipt['bank_account'] = db_fetch($res);
                $receipt['contacts'] = get_branch_contacts($receipt['branch_code'], 'invoice', $receipt['debtor_no']);
                $receipt['allocations'] = $this->getAllocations($receipt['debtor_no'], $receipt['trans_no'], $receipt['type']);
                $receipt['memo'] = get_comments_string($type, $i);
                $receipt['trans_type'] = $type;
                
                $receipts[] = $receipt;
            }
        }
        
        $data = [
            'receipts' => $receipts,
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
        
        $processedReceipts = [];
        foreach ($data['receipts'] as $receipt) {
            $totalAllocated = 0;
            $processedAllocations = [];
            
            foreach ($receipt['allocations'] as $alloc) {
                $totalAllocated += $alloc['amt'];
                $processedAllocations[] = $alloc;
            }
            
            $receipt['total_allocated'] = $totalAllocated;
            $receipt['left_to_allocate'] = $receipt['Total'] + $receipt['ov_discount'] - $totalAllocated;
            $receipt['processed_allocations'] = $processedAllocations;
            
            $processedReceipts[] = $receipt;
        }
        
        $processed = [
            'receipts' => $processedReceipts,
            'email' => $data['email']
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    private function getReceipt(int $type, int $transNo)
    {
        $sql = "SELECT trans.*,
                    (trans.ov_amount + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax) AS Total,
                    trans.ov_discount,
                    debtor.name AS DebtorName,
                    debtor.debtor_ref,
                    debtor.curr_code,
                    debtor.payment_terms,
                    debtor.tax_id AS tax_id,
                    debtor.address
                FROM ".TB_PREF."debtor_trans trans,
                     ".TB_PREF."debtors_master debtor
                WHERE trans.debtor_no = debtor.debtor_no
                  AND trans.type = ".$this->db->escape($type)."
                  AND trans.trans_no = ".$this->db->escape($transNo);
        
        $result = $this->db->fetchAll($sql);
        return $result ? $result[0] : false;
    }
    
    private function getAllocations(int $debtorNo, int $transNo, int $type): array
    {
        $result = get_allocatable_to_cust_transactions($debtorNo, $transNo, $type);
        $allocations = [];
        while ($row = db_fetch($result)) {
            $allocations[] = $row;
        }
        return $allocations;
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
        
        foreach ($processedData['receipts'] as $receipt) {
            if ($email == 1) {
                global $path_to_root;
                include_once($path_to_root . "/reporting/includes/pdf_report.inc");
                
                $orientation = $this->getDefaultOrientation();
                $rep = new \FrontReport("", "", user_pagesize(), 9, $orientation);
                $rep->title = _('RECEIPT');
                $rep->filename = "Receipt" . $receipt['trans_no'] . ".pdf";
                
                if ($orientation == 'L') {
                    $cols = $this->defineColumns();
                    recalculate_cols($cols);
                }
            }
            
            $rep->currency = $cur;
            $rep->Font();
            
            $params = $this->defineParams($config);
            $params['bankaccount'] = $receipt['bank_account']['bank_act'];
            $cols = $this->defineColumns();
            $aligns = $this->defineAlignments();
            $rep->Info($params, $cols, null, $aligns);
            
            $rep->SetCommonData($receipt, null, $receipt, $receipt['bank_account'], ST_CUSTPAYMENT, $receipt['contacts']);
            $rep->SetHeaderType('Header2');
            $rep->NewPage();
            
            // Allocations header
            $rep->TextCol(0, 4, _("As advance / full / part / payment towards:"), -2);
            $rep->NewLine(2);
            
            // Allocations
            foreach ($receipt['processed_allocations'] as $alloc) {
                $rep->TextCol(0, 1, $systypes_array[$alloc['type']], -2);
                $rep->TextCol(1, 2, $alloc['reference'], -2);
                $rep->TextCol(2, 3, \DateService::sql2dateStatic($alloc['tran_date']), -2);
                $rep->TextCol(3, 4, \DateService::sql2dateStatic($alloc['due_date']), -2);
                $rep->AmountCol(4, 5, $alloc['Total'], $dec, -2);
                $rep->AmountCol(5, 6, $alloc['Total'] - $alloc['alloc'], $dec, -2);
                $rep->AmountCol(6, 7, $alloc['amt'], $dec, -2);
                
                $rep->NewLine(1);
                if ($rep->row < $rep->bottomMargin + (15 * $rep->lineHeight)) {
                    $rep->NewPage();
                }
            }
            
            // Memo
            if ($receipt['memo'] != "") {
                $rep->NewLine();
                $rep->TextColLines(1, 5, $receipt['memo'], -2);
            }
            
            // Summary
            $rep->row = $rep->bottomMargin + (16 * $rep->lineHeight);
            
            $rep->TextCol(3, 6, _("Total Allocated"), -2);
            $rep->AmountCol(6, 7, $receipt['total_allocated'], $dec, -2);
            $rep->NewLine();
            
            $rep->TextCol(3, 6, _("Left to Allocate"), -2);
            $rep->AmountCol(6, 7, $receipt['left_to_allocate'], $dec, -2);
            
            if (floatcmp($receipt['ov_discount'], 0)) {
                $rep->NewLine();
                $rep->TextCol(3, 6, _("Discount"), -2);
                $rep->AmountCol(6, 7, -$receipt['ov_discount'], $dec, -2);
            }
            
            $rep->NewLine();
            $rep->Font('bold');
            $rep->TextCol(3, 6, _("TOTAL RECEIPT"), -2);
            $rep->AmountCol(6, 7, $receipt['Total'], $dec, -2);
            
            $words = price_in_words($receipt['Total'], ST_CUSTPAYMENT);
            if ($words != "") {
                $rep->NewLine(1);
                $rep->TextCol(0, 7, $receipt['curr_code'] . ": " . $words, -2);
            }
            
            $rep->Font();
            $rep->NewLine();
            $rep->TextCol(6, 7, _("Received / Sign"), -2);
            $rep->NewLine();
            $rep->TextCol(0, 2, _("By Cash / Cheque* / Draft No."), -2);
            $rep->TextCol(2, 4, "______________________________", -2);
            $rep->TextCol(4, 5, _("Dated"), -2);
            $rep->TextCol(5, 6, "__________________", -2);
            $rep->NewLine(1);
            $rep->TextCol(0, 2, _("Drawn on Bank"), -2);
            $rep->TextCol(2, 4, "______________________________", -2);
            $rep->TextCol(4, 5, _("Branch"), -2);
            $rep->TextCol(5, 6, "__________________", -2);
            $rep->TextCol(6, 7, "__________________");
            
            if ($email == 1) {
                $rep->End($email);
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
