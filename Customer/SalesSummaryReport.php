<?php
/**
 * Sales Summary Report Service
 * 
 * Generates sales summary by customer showing:
 * - Total sales excluding tax
 * - Tax amounts (included and excluded)
 * - Tax ID filtering
 * 
 * Report: rep114
 * Category: Customer/Sales Reports
 */

declare(strict_types=1);

namespace FA\Reports\Customer;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class SalesSummaryReport extends AbstractReportService
{
    private const REPORT_ID = 114;
    private const REPORT_TITLE = 'Sales Summary Report';
    
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
        return [0, 130, 180, 270, 350, 500];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('Customer'),
            _('Tax Id'),
            _('Total ex. Tax'),
            _('Tax')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'right', 'right'];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $taxIdOnly = $config->getParam('tax_id_only') ? _('Yes') : _('No');
        
        return [
            0 => $config->getParam('comments'),
            1 => [
                'text' => _('Period'),
                'from' => $config->getParam('from_date'),
                'to' => $config->getParam('to_date')
            ],
            2 => [
                'text' => _('Tax Id Only'),
                'from' => $taxIdOnly,
                'to' => ''
            ]
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $fromDate = \DateService::date2sqlStatic($config->getParam('from_date'));
        $toDate = \DateService::date2sqlStatic($config->getParam('to_date'));
        $taxIdOnly = (bool)$config->getParam('tax_id_only');
        
        // Get all sales transactions
        $sql = "SELECT d.debtor_no, 
                    d.name AS cust_name, 
                    d.tax_id, 
                    dt.type, 
                    dt.trans_no,  
                    CASE 
                        WHEN dt.type = ".ST_CUSTCREDIT." THEN (ov_amount+ov_freight+ov_discount)*-1 
                        ELSE (ov_amount+ov_freight+ov_discount) 
                    END * dt.rate AS total
                FROM ".TB_PREF."debtor_trans dt
                LEFT JOIN ".TB_PREF."debtors_master d ON d.debtor_no = dt.debtor_no
                WHERE (dt.type = ".ST_SALESINVOICE." OR dt.type = ".ST_CUSTCREDIT.") ";
        
        if ($taxIdOnly) {
            $sql .= "AND tax_id <> '' ";
        }
        
        $sql .= "AND dt.tran_date >= ".$this->db->escape($fromDate)."
                 AND dt.tran_date <= ".$this->db->escape($toDate)."
                 ORDER BY d.debtor_no";
        
        $transactions = $this->db->fetchAll($sql);
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $transactions
        ]);
        
        return $transactions;
    }
    
    protected function processData(ReportConfig $config, array $data): array
    {
        $this->dispatchEvent('before_process_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        $grouped = [];
        $currentCustomer = null;
        $customerData = null;
        
        foreach ($data as $trans) {
            $debtorNo = $trans['debtor_no'];
            
            // Start new customer group
            if ($currentCustomer !== $debtorNo) {
                if ($currentCustomer !== null) {
                    $grouped[] = $customerData;
                }
                
                $currentCustomer = $debtorNo;
                $customerData = [
                    'debtor_no' => $debtorNo,
                    'cust_name' => $trans['cust_name'],
                    'tax_id' => $trans['tax_id'],
                    'total' => 0.0,
                    'tax' => 0.0
                ];
            }
            
            // Get tax details for this transaction
            $taxes = $this->getTaxDetails($trans['type'], $trans['trans_no']);
            
            $transTotal = (float)$trans['total'];
            $transTax = 0.0;
            
            if ($taxes) {
                $transTax = (float)$taxes['tax'];
                
                // If tax is included in price, subtract from total
                if ($taxes['included_in_price']) {
                    $transTotal -= $transTax;
                }
            }
            
            $customerData['total'] += $transTotal;
            $customerData['tax'] += $transTax;
        }
        
        // Add last customer
        if ($currentCustomer !== null) {
            $grouped[] = $customerData;
        }
        
        // Calculate grand totals
        $grandTotal = 0.0;
        $grandTax = 0.0;
        foreach ($grouped as $custData) {
            $grandTotal += $custData['total'];
            $grandTax += $custData['tax'];
        }
        
        $processed = [
            'customers' => $grouped,
            'grand_total' => $grandTotal,
            'grand_tax' => $grandTax
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get tax details for a transaction
     */
    private function getTaxDetails(int $type, int $transNo): ?array
    {
        $sql = "SELECT included_in_price, 
                    SUM(CASE 
                        WHEN trans_type = ".ST_CUSTCREDIT." THEN -amount 
                        ELSE amount 
                    END * ex_rate) AS tax
                FROM ".TB_PREF."trans_tax_details 
                WHERE trans_type = ".$this->db->escape($type)."
                  AND trans_no = ".$this->db->escape($transNo)."
                GROUP BY included_in_price";
        
        return $this->db->fetchOne($sql);
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        
        $rep->TextCol(0, 4, _('Balances in Home Currency'));
        $rep->NewLine(2);
        
        // Render customer data
        foreach ($processedData['customers'] as $custData) {
            $rep->TextCol(0, 1, $custData['cust_name']);
            $rep->TextCol(1, 2, $custData['tax_id']);
            $rep->AmountCol(2, 3, $custData['total'], $dec);
            $rep->AmountCol(3, 4, $custData['tax'], $dec);
            $rep->NewLine();
            
            if ($rep->row < $rep->bottomMargin + $rep->lineHeight) {
                $rep->Line($rep->row - 2);
                $rep->NewPage();
            }
        }
        
        // Grand total
        $rep->Font('bold');
        $rep->NewLine();
        $rep->Line($rep->row + $rep->lineHeight);
        $rep->TextCol(0, 2, _('Total'));
        $rep->AmountCol(2, 3, $processedData['grand_total'], $dec);
        $rep->AmountCol(3, 4, $processedData['grand_tax'], $dec);
        $rep->Line($rep->row - 5);
        $rep->Font();
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
