<?php
/**
 * Salesman Report Service
 * 
 * Generates sales performance reports by salesman showing:
 * - Invoice details per salesman
 * - Commission/provision calculations
 * - Summary and detailed modes
 * 
 * Report: rep106
 * Category: Customer/Sales Reports
 */

declare(strict_types=1);

namespace FA\Reports\Customer;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class SalesmanReport extends AbstractReportService
{
    private const REPORT_ID = 106;
    private const REPORT_TITLE = 'Salesman Listing';
    
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
        return 'L'; // Landscape for more columns
    }
    
    protected function defineColumns(): array
    {
        return [
            0, 60, 150, 220, 325, 385, 450, 515
        ];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('Invoice'),
            _('Customer'),
            _('Branch'),
            _('Customer Ref'),
            _('Inv Date'),
            _('Total'),
            _('Provision')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return [
            'left', 'left', 'left', 'left', 'left', 'right', 'right'
        ];
    }
    
    protected function defineSecondaryHeaders(): array
    {
        return [
            _('Salesman'),
            ' ',
            _('Phone'),
            _('Email'),
            _('Provision'),
            _('Break Pt.'),
            _('Provision').' 2'
        ];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $summary = $config->getParam('summary') ? _('Yes') : _('No');
        
        return [
            0 => $config->getParam('comments'),
            1 => [
                'text' => _('Period'),
                'from' => $config->getParam('from_date'),
                'to' => $config->getParam('to_date')
            ],
            2 => [
                'text' => _('Summary Only'),
                'from' => $summary,
                'to' => ''
            ]
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $fromDate = \DateService::date2sqlStatic($config->getParam('from_date'));
        $toDate = \DateService::date2sqlStatic($config->getParam('to_date'));
        
        $sql = "SELECT DISTINCT trans.*,
                    ov_amount+ov_discount AS InvoiceTotal,
                    cust.name AS DebtorName,
                    cust.curr_code,
                    branch.br_name,
                    sorder.customer_ref,
                    salesman.*
                FROM ".TB_PREF."debtor_trans trans
                INNER JOIN ".TB_PREF."debtors_master cust 
                    ON trans.debtor_no = cust.debtor_no
                INNER JOIN ".TB_PREF."sales_orders sorder 
                    ON sorder.order_no = trans.order_
                    AND sorder.trans_type = ".ST_SALESORDER."
                INNER JOIN ".TB_PREF."cust_branch branch 
                    ON sorder.branch_code = branch.branch_code
                INNER JOIN ".TB_PREF."salesman salesman 
                    ON branch.salesman = salesman.salesman_code
                WHERE (trans.type = ".ST_SALESINVOICE." OR trans.type = ".ST_CUSTCREDIT.")
                  AND trans.tran_date >= ".$this->db->escape($fromDate)."
                  AND trans.tran_date <= ".$this->db->escape($toDate)."
                ORDER BY salesman.salesman_code, trans.tran_date";
        
        $result = $this->db->fetchAll($sql);
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $result
        ]);
        
        return $result;
    }
    
    protected function processData(ReportConfig $config, array $data): array
    {
        $this->dispatchEvent('before_process_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        $isSummary = (bool)$config->getParam('summary');
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        $pctDec = \FA\UserPrefsCache::getPercentDecimals();
        
        $grouped = [];
        $currentSalesman = null;
        $salesmanData = [];
        
        foreach ($data as $row) {
            $salesmanCode = $row['salesman_code'];
            
            // Start new salesman group
            if ($currentSalesman !== $salesmanCode) {
                if ($currentSalesman !== null) {
                    $grouped[] = $salesmanData;
                }
                
                $currentSalesman = $salesmanCode;
                $salesmanData = [
                    'salesman_code' => $row['salesman_code'],
                    'salesman_name' => $row['salesman_name'],
                    'salesman_phone' => $row['salesman_phone'],
                    'salesman_email' => $row['salesman_email'],
                    'provision' => $row['provision'],
                    'break_pt' => $row['break_pt'],
                    'provision2' => $row['provision2'],
                    'transactions' => [],
                    'subtotal' => 0.0,
                    'subprov' => 0.0
                ];
            }
            
            // Calculate amounts
            $rate = $row['rate'];
            $amt = $row['InvoiceTotal'] * $rate;
            
            // Calculate provision based on break point
            if ($row['provision2'] == 0) {
                $prov = $row['provision'] * $amt / 100;
            } else {
                $amt1 = min($amt, max(0, $row['break_pt'] - $salesmanData['subtotal']));
                $amt2 = $amt - $amt1;
                $prov = ($amt1 * $row['provision'] / 100) + ($amt2 * $row['provision2'] / 100);
            }
            
            // Add transaction if not summary
            if (!$isSummary) {
                $salesmanData['transactions'][] = [
                    'trans_no' => $row['trans_no'],
                    'debtor_name' => $row['DebtorName'],
                    'branch_name' => $row['br_name'],
                    'customer_ref' => $row['customer_ref'],
                    'tran_date' => $row['tran_date'],
                    'amount' => $amt,
                    'provision' => $prov
                ];
            }
            
            $salesmanData['subtotal'] += $amt;
            $salesmanData['subprov'] += $prov;
        }
        
        // Add last salesman group
        if ($currentSalesman !== null) {
            $grouped[] = $salesmanData;
        }
        
        // Calculate grand totals
        $grandTotal = 0.0;
        $grandProvision = 0.0;
        foreach ($grouped as $salesmanData) {
            $grandTotal += $salesmanData['subtotal'];
            $grandProvision += $salesmanData['subprov'];
        }
        
        $processed = [
            'salesmen' => $grouped,
            'grand_total' => $grandTotal,
            'grand_provision' => $grandProvision,
            'is_summary' => $isSummary
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
        $pctDec = \FA\UserPrefsCache::getPercentDecimals();
        $isSummary = $processedData['is_summary'];
        
        foreach ($processedData['salesmen'] as $salesmanData) {
            $rep->NewLine(0, 2, false);
            
            // Salesman header
            $rep->TextCol(0, 2, $salesmanData['salesman_code'].' '.$salesmanData['salesman_name']);
            $rep->TextCol(2, 3, $salesmanData['salesman_phone']);
            $rep->TextCol(3, 4, $salesmanData['salesman_email']);
            $rep->TextCol(4, 5, \FormatService::numberFormat2($salesmanData['provision'], $pctDec).' %');
            $rep->AmountCol(5, 6, $salesmanData['break_pt'], $dec);
            $rep->TextCol(6, 7, \FormatService::numberFormat2($salesmanData['provision2'], $pctDec).' %');
            $rep->NewLine(2);
            
            // Transaction details (if not summary)
            if (!$isSummary) {
                foreach ($salesmanData['transactions'] as $trans) {
                    $rep->TextCol(0, 1, $trans['trans_no']);
                    $rep->TextCol(1, 2, $trans['debtor_name']);
                    $rep->TextCol(2, 3, $trans['branch_name']);
                    $rep->TextCol(3, 4, $trans['customer_ref']);
                    $rep->DateCol(4, 5, $trans['tran_date'], true);
                    $rep->AmountCol(5, 6, $trans['amount'], $dec);
                    $rep->AmountCol(6, 7, $trans['provision'], $dec);
                    $rep->NewLine();
                    
                    if ($rep->row < $rep->bottomMargin + (3 * $rep->lineHeight)) {
                        $rep->NewPage();
                    }
                }
            }
            
            // Salesman subtotal
            $rep->Line($rep->row - 8);
            $rep->NewLine(2);
            $rep->TextCol(0, 3, _('Total'));
            $rep->AmountCol(5, 6, $salesmanData['subtotal'], $dec);
            $rep->AmountCol(6, 7, $salesmanData['subprov'], $dec);
            $rep->Line($rep->row - 4);
            $rep->NewLine(2);
        }
        
        // Grand total
        if (!empty($processedData['salesmen'])) {
            $rep->fontSize += 2;
            $rep->TextCol(0, 3, _('Grand Total'));
            $rep->fontSize -= 2;
            $rep->AmountCol(5, 6, $processedData['grand_total'], $dec);
            $rep->AmountCol(6, 7, $processedData['grand_provision'], $dec);
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
