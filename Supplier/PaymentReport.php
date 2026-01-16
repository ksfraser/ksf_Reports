<?php
/**
 * Payment Report Service
 * 
 * Generates supplier payment report showing:
 * - Outstanding balances by supplier
 * - Transaction details with due dates
 * - Currency conversion support
 * 
 * Report: rep203
 * Category: Supplier/Purchasing Reports
 */

declare(strict_types=1);

namespace FA\Reports\Supplier;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class PaymentReport extends AbstractReportService
{
    private const REPORT_ID = 203;
    private const REPORT_TITLE = 'Payment Report';
    
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
        return [0, 100, 160, 210, 250, 320, 385, 450, 515];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('Trans Type'),
            _('#'),
            _('Due Date'),
            '',
            '',
            '',
            _('Total'),
            _('Balance')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return [
            'left', 'left', 'left', 'left', 'right', 'right', 'right', 'right'
        ];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $supplier = $config->getParam('supplier');
        $supplierName = ($supplier == ALL_TEXT) ? _('All') : get_supplier_name($supplier);
        
        $currency = $config->getParam('currency');
        $currencyText = ($currency == ALL_TEXT) ? _('Balances in Home Currency') : $currency;
        
        $noZeros = $config->getParam('no_zeros') ? _('Yes') : _('No');
        
        return [
            0 => $config->getParam('comments'),
            1 => ['text' => _('End Date'), 'from' => $config->getParam('to_date'), 'to' => ''],
            2 => ['text' => _('Supplier'), 'from' => $supplierName, 'to' => ''],
            3 => ['text' => _('Currency'), 'from' => $currencyText, 'to' => ''],
            4 => ['text' => _('Suppress Zeros'), 'from' => $noZeros, 'to' => '']
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $supplier = $config->getParam('supplier');
        
        // Get suppliers with payment terms
        $sql = "SELECT supplier_id, supp_name AS name, curr_code, s.inactive, pt.terms 
                FROM ".TB_PREF."suppliers s
                INNER JOIN ".TB_PREF."payment_terms pt ON s.payment_terms = pt.terms_indicator";
        
        if ($supplier != ALL_TEXT) {
            $sql .= " WHERE supplier_id = ".$this->db->escape($supplier);
        }
        
        $sql .= " ORDER BY supp_name";
        
        $suppliers = $this->db->fetchAll($sql);
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $suppliers
        ]);
        
        return $suppliers;
    }
    
    protected function processData(ReportConfig $config, array $data): array
    {
        $this->dispatchEvent('before_process_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        $toDate = $config->getParam('to_date');
        $currency = $config->getParam('currency');
        $noZeros = (bool)$config->getParam('no_zeros');
        $convert = ($currency == ALL_TEXT);
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        
        $suppliers = [];
        $grandTotal = ['total' => 0.0, 'balance' => 0.0];
        
        foreach ($data as $supplier) {
            // Skip if currency doesn't match
            if (!$convert && $currency != $supplier['curr_code']) {
                continue;
            }
            
            $rate = $convert 
                ? \FA\Services\BankingService::getExchangeRateFromHomeCurrency($supplier['curr_code'], \DateService::todayStatic())
                : 1;
            
            // Get transactions for this supplier
            $transactions = $this->getTransactions($supplier['supplier_id'], $toDate);
            
            $suppTotal = 0.0;
            $suppBalance = 0.0;
            $transDetails = [];
            
            foreach ($transactions as $trans) {
                $tranTotal = (float)$trans['TranTotal'] * $rate;
                $balance = (float)$trans['Balance'] * $rate;
                
                $suppTotal += $tranTotal;
                $suppBalance += $balance;
                
                $transDetails[] = [
                    'type' => $trans['type'],
                    'trans_no' => $trans['trans_no'],
                    'supp_reference' => $trans['supp_reference'],
                    'tran_date' => $trans['tran_date'],
                    'due_date' => $trans['due_date'],
                    'total' => $tranTotal,
                    'balance' => $balance
                ];
            }
            
            // Skip if no balance and suppressing zeros
            if ($noZeros && $suppBalance == 0.0) {
                continue;
            }
            
            // Skip if no transactions at all
            if (empty($transDetails)) {
                continue;
            }
            
            $suppliers[] = [
                'supplier_id' => $supplier['supplier_id'],
                'name' => $supplier['name'],
                'curr_code' => $supplier['curr_code'],
                'inactive' => $supplier['inactive'],
                'terms' => $supplier['terms'],
                'total' => $suppTotal,
                'balance' => $suppBalance,
                'transactions' => $transDetails
            ];
            
            $grandTotal['total'] += $suppTotal;
            $grandTotal['balance'] += $suppBalance;
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
    
    /**
     * Get transactions for a supplier
     */
    private function getTransactions(int $supplierId, string $toDate): array
    {
        $date = \DateService::date2sqlStatic($toDate);
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        
        $sql = "SELECT supp_reference, tran_date, due_date, trans_no, type, rate,
                    (ABS(ov_amount) + ABS(ov_gst) - alloc) AS Balance,
                    (ABS(ov_amount) + ABS(ov_gst)) AS TranTotal
                FROM ".TB_PREF."supp_trans
                WHERE supplier_id = ".$this->db->escape($supplierId)."
                  AND ROUND(ABS(ov_amount), $dec) + ROUND(ABS(ov_gst), $dec) - ROUND(alloc, $dec) != 0
                  AND tran_date <= ".$this->db->escape($date)."
                ORDER BY type, trans_no";
        
        return $this->db->fetchAll($sql);
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
            $rep->fontSize += 2;
            $rep->TextCol(0, 6, $suppData['name']);
            $rep->TextCol(6, 7, _('Terms').": ".$suppData['terms']);
            if ($suppData['inactive']) {
                $rep->TextCol(7, 8, _('Inactive'));
            }
            $rep->fontSize -= 2;
            $rep->NewLine(2);
            
            // Transaction details
            foreach ($suppData['transactions'] as $trans) {
                $rep->TextCol(0, 1, $GLOBALS['systypes_array'][$trans['type']]);
                $rep->TextCol(1, 2, $trans['trans_no']);
                $rep->TextCol(2, 3, \DateService::sql2dateStatic($trans['due_date']));
                $rep->TextCol(3, 4, $trans['supp_reference']);
                $rep->DateCol(4, 5, $trans['tran_date'], true);
                $rep->AmountCol(6, 7, $trans['total'], $dec);
                $rep->AmountCol(7, 8, $trans['balance'], $dec);
                $rep->NewLine();
                
                if ($rep->row < $rep->bottomMargin + (3 * $rep->lineHeight)) {
                    $rep->NewPage();
                }
            }
            
            // Supplier totals
            $rep->NewLine();
            $rep->fontSize += 1;
            $rep->TextCol(0, 6, _('Total'));
            $rep->fontSize -= 1;
            $rep->AmountCol(6, 7, $suppData['total'], $dec);
            $rep->AmountCol(7, 8, $suppData['balance'], $dec);
            $rep->Line($rep->row - 2);
            $rep->NewLine(2);
        }
        
        // Grand totals
        $rep->fontSize += 2;
        $rep->TextCol(0, 6, _('Grand Total'));
        $rep->fontSize -= 2;
        $rep->AmountCol(6, 7, $processedData['grand_total']['total'], $dec);
        $rep->AmountCol(7, 8, $processedData['grand_total']['balance'], $dec);
        $rep->Line($rep->row - 4);
        $rep->NewLine();
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
