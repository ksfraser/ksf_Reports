<?php
/**
 * Supplier Balances Report Service
 * 
 * Generates detailed supplier balances showing:
 * - Opening balances
 * - Transaction details (charges, credits, allocations)
 * - Running balances or outstanding amounts
 * - Currency conversion support
 * 
 * Report: rep201
 * Category: Supplier Reports
 */

declare(strict_types=1);

namespace FA\Reports\Supplier;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class SupplierBalances extends AbstractReportService
{
    private const REPORT_ID = 201;
    private const REPORT_TITLE = 'Supplier Balances';
    
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
        return [0, 95, 140, 200, 250, 320, 385, 450, 515];
    }
    
    protected function defineHeaders(): array
    {
        $showBalance = $this->config->getParam('show_balance', 0);
        $headers = [
            _('Trans Type'),
            _('#'),
            _('Date'),
            _('Due Date'),
            _('Charges'),
            _('Credits'),
            _('Allocated'),
            $showBalance ? _('Balance') : _('Outstanding')
        ];
        return $headers;
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'left', 'right', 'right', 'right', 'right'];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $fromSupp = $config->getParam('from_supp');
        $suppName = ($fromSupp == ALL_TEXT) ? _('All') : get_supplier_name($fromSupp);
        
        $currency = $config->getParam('currency');
        $currDisplay = ($currency == ALL_TEXT) ? _('Balances in Home currency') : $currency;
        
        $noZeros = $config->getParam('no_zeros') ? _('Yes') : _('No');
        
        return [
            0 => $config->getParam('comments'),
            1 => [
                'text' => _('Period'),
                'from' => $config->getParam('from_date'),
                'to' => $config->getParam('to_date')
            ],
            2 => ['text' => _('Supplier'), 'from' => $suppName, 'to' => ''],
            3 => ['text' => _('Currency'), 'from' => $currDisplay, 'to' => ''],
            4 => ['text' => _('Suppress Zeros'), 'from' => $noZeros, 'to' => '']
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $fromDate = $config->getParam('from_date');
        $toDate = $config->getParam('to_date');
        $fromSupp = $config->getParam('from_supp');
        $currency = $config->getParam('currency');
        $convert = ($currency == ALL_TEXT);
        
        // Get suppliers
        $suppliers = $this->getSuppliers($fromSupp);
        
        // Get transactions for each supplier
        foreach ($suppliers as &$supp) {
            $supp['open_balance'] = $this->getOpenBalance($supp['supplier_id'], $fromDate);
            $supp['transactions'] = $this->getTransactions($supp['supplier_id'], $fromDate, $toDate);
        }
        
        $data = [
            'suppliers' => $suppliers,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'currency' => $currency,
            'convert' => $convert
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
        $showBalance = $config->getParam('show_balance', 0);
        $noZeros = $config->getParam('no_zeros', 0);
        $convert = $data['convert'];
        $currency = $data['currency'];
        
        $processedSuppliers = [];
        $grandTotal = [0.0, 0.0, 0.0, 0.0];
        
        foreach ($data['suppliers'] as $supp) {
            // Skip if currency doesn't match
            if (!$convert && $currency != $supp['curr_code']) {
                continue;
            }
            
            // Skip if no transactions and no_zeros is set
            if ($noZeros && count($supp['transactions']) == 0) {
                continue;
            }
            
            $rate = $convert ? \FA\Services\BankingService::getExchangeRateFromHomeCurrency($supp['curr_code'], \DateService::todayStatic()) : 1;
            
            $bal = $supp['open_balance'];
            $init = [
                round2(($bal ? abs($bal['charges']) : 0) * $rate, $dec),
                round2(($bal ? abs($bal['credits']) : 0) * $rate, $dec),
                round2(($bal ? $bal['Allocated'] : 0) * $rate, $dec),
                0.0
            ];
            
            if ($showBalance) {
                $init[3] = $init[0] - $init[1];
            } else {
                $init[3] = round2(($bal ? $bal['OutStanding'] : 0) * $rate, $dec);
            }
            
            $accumulate = $showBalance ? $init[3] : 0.0;
            
            // Process transactions
            $processedTrans = [];
            $total = [0.0, 0.0, 0.0, 0.0];
            
            foreach ($supp['transactions'] as $trans) {
                if ($noZeros && floatcmp(abs($trans['TotalAmount']), $trans['Allocated']) == 0) {
                    continue;
                }
                
                $item = [0.0, 0.0, 0.0, 0.0];
                
                if ($trans['TotalAmount'] > 0.0) {
                    $item[0] = round2(abs($trans['TotalAmount']) * $rate, $dec);
                    $accumulate += $item[0];
                    $item[2] = round2($trans['Allocated'] * $rate, $dec);
                    $item[3] = $item[0] - $item[2];
                } else {
                    $item[1] = round2(abs($trans['TotalAmount']) * $rate, $dec);
                    $accumulate -= $item[1];
                    $item[2] = round2($trans['Allocated'] * $rate, $dec) * -1;
                    $item[3] = -$item[1] - $item[2];
                }
                
                $processedTrans[] = [
                    'type' => $trans['type'],
                    'reference' => $trans['reference'],
                    'tran_date' => $trans['tran_date'],
                    'due_date' => $trans['due_date'],
                    'charges' => $item[0],
                    'credits' => $item[1],
                    'allocated' => $item[2],
                    'outstanding' => $showBalance ? $accumulate : $item[3]
                ];
                
                for ($i = 0; $i < 4; $i++) {
                    $total[$i] += $item[$i];
                }
            }
            
            if ($showBalance) {
                $total[3] = $total[0] - $total[1];
            }
            
            for ($i = 0; $i < 4; $i++) {
                $total[$i] += $init[$i];
                $grandTotal[$i] += $total[$i];
            }
            
            $processedSuppliers[] = [
                'name' => $supp['supp_name'],
                'inactive' => $supp['inactive'],
                'curr_code' => $supp['curr_code'],
                'init' => $init,
                'transactions' => $processedTrans,
                'total' => $total
            ];
        }
        
        if ($showBalance) {
            $grandTotal[3] = $grandTotal[0] - $grandTotal[1];
        }
        
        $processed = [
            'suppliers' => $processedSuppliers,
            'grand_total' => $grandTotal,
            'convert' => $convert,
            'show_balance' => $showBalance
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get suppliers
     */
    private function getSuppliers($fromSupp): array
    {
        $sql = "SELECT supplier_id, supp_name, curr_code, inactive 
                FROM ".TB_PREF."suppliers";
        
        if ($fromSupp != ALL_TEXT) {
            $sql .= " WHERE supplier_id=".$this->db->escape($fromSupp);
        }
        
        $sql .= " ORDER BY supp_name";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Get opening balance
     */
    private function getOpenBalance(int $supplierId, string $toDate)
    {
        $to = \DateService::date2sqlStatic($toDate);
        
        $sql = "SELECT 
                SUM(IF(t.type = ".ST_SUPPINVOICE." OR (t.type IN (".ST_JOURNAL." , ".ST_BANKDEPOSIT.") AND t.ov_amount>0),
                    -abs(t.ov_amount + t.ov_gst + t.ov_discount), 0)) AS charges,
                SUM(IF(t.type != ".ST_SUPPINVOICE." AND NOT(t.type IN (".ST_JOURNAL." , ".ST_BANKDEPOSIT.") AND t.ov_amount>0),
                    abs(t.ov_amount + t.ov_gst + t.ov_discount) * -1, 0)) AS credits,
                SUM(IF(t.type != ".ST_SUPPINVOICE." AND NOT(t.type IN (".ST_JOURNAL." , ".ST_BANKDEPOSIT.") AND t.ov_amount>0), t.alloc * -1, t.alloc)) 
                    AS Allocated,
                SUM(IF(t.type = ".ST_SUPPINVOICE." OR (t.type IN (".ST_JOURNAL." , ".ST_BANKDEPOSIT.") AND t.ov_amount>0), 1, -1) *
                    (abs(t.ov_amount + t.ov_gst + t.ov_discount) - abs(t.alloc))) AS OutStanding
                FROM ".TB_PREF."supp_trans t
                WHERE t.supplier_id = ".$this->db->escape($supplierId)."
                  AND t.tran_date < ".$this->db->escape($to)."
                GROUP BY supplier_id";
        
        return $this->db->fetchOne($sql);
    }
    
    /**
     * Get transactions
     */
    private function getTransactions(int $supplierId, string $fromDate, string $toDate): array
    {
        $from = \DateService::date2sqlStatic($fromDate);
        $to = \DateService::date2sqlStatic($toDate);
        
        $sql = "SELECT *,
                    (ov_amount + ov_gst + ov_discount) AS TotalAmount,
                    alloc AS Allocated,
                    ((type = ".ST_SUPPINVOICE.") AND due_date < ".$this->db->escape($to).") AS OverDue
                FROM ".TB_PREF."supp_trans
                WHERE tran_date >= ".$this->db->escape($from)."
                  AND tran_date <= ".$this->db->escape($to)."
                  AND supplier_id = ".$this->db->escape($supplierId)."
                  AND ov_amount != 0
                ORDER BY tran_date";
        
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
        $convert = $processedData['convert'];
        
        foreach ($processedData['suppliers'] as $supp) {
            // Supplier header
            $rep->fontSize += 2;
            $rep->TextCol(0, 2, $supp['name'].($supp['inactive'] == 1 ? " ("._("Inactive").")" : ""));
            if ($convert) {
                $rep->TextCol(2, 3, $supp['curr_code']);
            }
            $rep->fontSize -= 2;
            
            // Opening balance
            $rep->TextCol(3, 4, _("Open Balance"));
            $rep->AmountCol(4, 5, $supp['init'][0], $dec);
            $rep->AmountCol(5, 6, $supp['init'][1], $dec);
            $rep->AmountCol(6, 7, $supp['init'][2], $dec);
            $rep->AmountCol(7, 8, $supp['init'][3], $dec);
            $rep->NewLine(1, 2);
            $rep->Line($rep->row + 4);
            
            if (count($supp['transactions']) == 0) {
                $rep->NewLine(1, 2);
                continue;
            }
            
            // Transactions
            foreach ($supp['transactions'] as $trans) {
                $rep->NewLine(1, 2);
                $rep->TextCol(0, 1, $systypes_array[$trans['type']]);
                $rep->TextCol(1, 2, $trans['reference']);
                $rep->DateCol(2, 3, $trans['tran_date'], true);
                
                if ($trans['type'] == ST_SUPPINVOICE) {
                    $rep->DateCol(3, 4, $trans['due_date'], true);
                }
                
                if ($trans['charges'] > 0) {
                    $rep->AmountCol(4, 5, $trans['charges'], $dec);
                }
                if ($trans['credits'] > 0) {
                    $rep->AmountCol(5, 6, $trans['credits'], $dec);
                }
                $rep->AmountCol(6, 7, $trans['allocated'], $dec);
                $rep->AmountCol(7, 8, $trans['outstanding'], $dec);
            }
            
            // Supplier total
            $rep->Line($rep->row - 8);
            $rep->NewLine(2);
            $rep->TextCol(0, 3, _('Total'));
            for ($i = 0; $i < 4; $i++) {
                $rep->AmountCol($i + 4, $i + 5, $supp['total'][$i], $dec);
            }
            $rep->Line($rep->row - 4);
            $rep->NewLine(2);
            
            if ($rep->row < $rep->bottomMargin + $rep->lineHeight) {
                $rep->NewPage();
            }
        }
        
        // Grand total
        $rep->fontSize += 2;
        $rep->TextCol(0, 3, _('Grand Total'));
        $rep->fontSize -= 2;
        for ($i = 0; $i < 4; $i++) {
            $rep->AmountCol($i + 4, $i + 5, $processedData['grand_total'][$i], $dec);
        }
        $rep->Line($rep->row - 4);
        $rep->NewLine();
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
