<?php
/**
 * Supplier Trial Balance Service (Detailed)
 * 
 * Generates detailed supplier trial balance showing:
 * - Opening balances
 * - Period debits and credits
 * - Closing balances
 * - Currency conversion support
 * 
 * Report: rep206
 * Category: Supplier/Purchasing Reports
 */

declare(strict_types=1);

namespace FA\Reports\Supplier;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class SupplierTrialBalance extends AbstractReportService
{
    private const REPORT_ID = 206;
    private const REPORT_TITLE = 'Supplier Trial Balance';
    
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
        return [0, 100, 130, 190, 250, 320, 385, 450, 515];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('Name'),
            '',
            '',
            _('Open Balance'),
            _('Debit'),
            _('Credit'),
            '',
            _('Balance')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return [
            'left', 'left', 'left', 'right', 'right', 'right', 'right', 'right'
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
            1 => [
                'text' => _('Period'),
                'from' => $config->getParam('from_date'),
                'to' => $config->getParam('to_date')
            ],
            2 => ['text' => _('Supplier'), 'from' => $supplierName, 'to' => ''],
            3 => ['text' => _('Currency'), 'from' => $currencyText, 'to' => ''],
            4 => ['text' => _('Suppress Zeros'), 'from' => $noZeros, 'to' => '']
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $supplier = $config->getParam('supplier');
        
        // Get suppliers
        $sql = "SELECT supplier_id, supp_name AS name, curr_code, inactive 
                FROM ".TB_PREF."suppliers";
        
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
        
        $fromDate = $config->getParam('from_date');
        $toDate = $config->getParam('to_date');
        $currency = $config->getParam('currency');
        $noZeros = (bool)$config->getParam('no_zeros');
        $convert = ($currency == ALL_TEXT);
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        
        $suppliers = [];
        $totOpen = $totDb = $totCr = 0.0;
        
        foreach ($data as $row) {
            // Skip if currency doesn't match
            if (!$convert && $currency != $row['curr_code']) {
                continue;
            }
            
            $rate = $convert 
                ? \FA\Services\BankingService::getExchangeRateFromHomeCurrency($row['curr_code'], \DateService::todayStatic())
                : 1;
            
            // Get opening balance
            $openBal = $this->getOpenBalance($row['supplier_id'], $fromDate);
            $charges = $openBal ? round2(abs($openBal['charges']) * $rate, $dec) : 0;
            $credits = $openBal ? round2(abs($openBal['credits']) * $rate, $dec) : 0;
            $currOpen = $credits - $charges;
            
            // Get period transactions
            $trans = $this->getTransactions($row['supplier_id'], $fromDate, $toDate);
            
            // If no transactions and no zeros suppression, include supplier
            if (count($trans) == 0 && !$noZeros) {
                $suppliers[] = [
                    'name' => $row['name'],
                    'inactive' => $row['inactive'],
                    'open_balance' => $currOpen,
                    'debit' => 0.0,
                    'credit' => 0.0,
                    'balance' => $currOpen
                ];
                $totOpen += $currOpen;
                continue;
            }
            
            // Calculate period debits and credits
            $periodDb = 0.0;
            $periodCr = 0.0;
            foreach ($trans as $t) {
                $amount = (float)$t['TotalAmount'] * $rate;
                if ($amount > 0.0) {
                    $periodCr += round2(abs($amount), $dec);
                } else {
                    $periodDb += round2(abs($amount), $dec);
                }
            }
            
            $closingBal = $currOpen - $periodDb + $periodCr;
            
            // Skip if zero suppression and all zeros
            if ($noZeros && $closingBal == 0.0 && $periodDb == 0.0 && $periodCr == 0.0) {
                continue;
            }
            
            $suppliers[] = [
                'name' => $row['name'],
                'inactive' => $row['inactive'],
                'open_balance' => $currOpen,
                'debit' => $periodDb,
                'credit' => $periodCr,
                'balance' => $closingBal
            ];
            
            $totOpen += $currOpen;
            $totDb += $periodDb;
            $totCr += $periodCr;
        }
        
        $processed = [
            'suppliers' => $suppliers,
            'total_open' => $totOpen,
            'total_debit' => $totDb,
            'total_credit' => $totCr,
            'total_balance' => $totOpen - $totDb + $totCr
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get opening balance for a supplier
     */
    private function getOpenBalance(int $supplierId, string $toDate): ?array
    {
        $to = \DateService::date2sqlStatic($toDate);
        
        $sql = "SELECT 
                SUM(IF(t.type = ".ST_SUPPINVOICE." OR (t.type IN (".ST_JOURNAL.", ".ST_BANKDEPOSIT.") AND t.ov_amount > 0),
                    -abs(t.ov_amount + t.ov_gst + t.ov_discount), 0)) AS charges,
                SUM(IF(t.type != ".ST_SUPPINVOICE." AND NOT(t.type IN (".ST_JOURNAL.", ".ST_BANKDEPOSIT.") AND t.ov_amount > 0),
                    abs(t.ov_amount + t.ov_gst + t.ov_discount) * -1, 0)) AS credits,
                SUM(IF(t.type != ".ST_SUPPINVOICE." AND NOT(t.type IN (".ST_JOURNAL.", ".ST_BANKDEPOSIT.")), 
                    t.alloc * -1, t.alloc)) AS Allocated,
                SUM(IF(t.type = ".ST_SUPPINVOICE.", 1, -1) *
                    (abs(t.ov_amount + t.ov_gst + t.ov_discount) - abs(t.alloc))) AS OutStanding
                FROM ".TB_PREF."supp_trans t
                WHERE t.supplier_id = ".$this->db->escape($supplierId)."
                  AND t.tran_date < ".$this->db->escape($to)."
                GROUP BY supplier_id";
        
        return $this->db->fetchOne($sql);
    }
    
    /**
     * Get transactions for a supplier in a period
     */
    private function getTransactions(int $supplierId, string $fromDate, string $toDate): array
    {
        $from = \DateService::date2sqlStatic($fromDate);
        $to = \DateService::date2sqlStatic($toDate);
        
        $sql = "SELECT supp_trans.*, comments.memo_,
                    (supp_trans.ov_amount + supp_trans.ov_gst + supp_trans.ov_discount) AS TotalAmount,
                    supp_trans.alloc AS Allocated,
                    ((supp_trans.type = ".ST_SUPPINVOICE.") AND supp_trans.due_date < ".$this->db->escape($to).") AS OverDue
                FROM ".TB_PREF."supp_trans
                LEFT JOIN ".TB_PREF."comments comments 
                    ON supp_trans.type = comments.type AND supp_trans.trans_no = comments.id
                WHERE supp_trans.tran_date >= ".$this->db->escape($from)."
                  AND supp_trans.tran_date <= ".$this->db->escape($to)."
                  AND supp_trans.supplier_id = ".$this->db->escape($supplierId)."
                  AND supp_trans.ov_amount != 0
                ORDER BY supp_trans.tran_date";
        
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
        
        // Render supplier data
        foreach ($processedData['suppliers'] as $suppData) {
            $name = $suppData['name'];
            if ($suppData['inactive'] == 1) {
                $name .= ' ('._('Inactive').')';
            }
            
            $rep->TextCol(0, 2, $name);
            $rep->AmountCol(3, 4, $suppData['open_balance'], $dec);
            $rep->AmountCol(4, 5, $suppData['debit'], $dec);
            $rep->AmountCol(5, 6, $suppData['credit'], $dec);
            $rep->AmountCol(7, 8, $suppData['balance'], $dec);
            $rep->NewLine();
        }
        
        // Grand total
        $rep->Line($rep->row + 4);
        $rep->NewLine();
        $rep->fontSize += 2;
        $rep->TextCol(0, 3, _('Grand Total'));
        $rep->fontSize -= 2;
        
        $rep->AmountCol(3, 4, $processedData['total_open'], $dec);
        $rep->AmountCol(4, 5, $processedData['total_debit'], $dec);
        $rep->AmountCol(5, 6, $processedData['total_credit'], $dec);
        $rep->AmountCol(7, 8, $processedData['total_balance'], $dec);
        $rep->Line($rep->row - 6, 1);
        $rep->NewLine();
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
