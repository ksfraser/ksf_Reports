<?php
/**
 * Aged Supplier Analysis Service
 * 
 * Generates supplier aging analysis showing:
 * - Current, 30-day, 60-day, 90+ day buckets
 * - Summary and detailed modes
 * - Currency conversion support
 * 
 * Report: rep202
 * Category: Supplier/Purchasing Reports
 */

declare(strict_types=1);

namespace FA\Reports\Supplier;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class AgedSupplierAnalysis extends AbstractReportService
{
    private const REPORT_ID = 202;
    private const REPORT_TITLE = 'Aged Supplier Analysis';
    
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
            _('Supplier'),
            _('Cur'),
            _('Current'),
            '0-30',
            '30-60',
            '60-90',
            _('Over 90'),
            _('Total Balance')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return [
            'left', 'left', 'right', 'right', 'right', 'right', 'right', 'right'
        ];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $supplier = $config->getParam('supplier');
        $supplierName = ($supplier == ALL_TEXT) ? _('All') : get_supplier_name($supplier);
        
        $currency = $config->getParam('currency');
        $currencyText = ($currency == ALL_TEXT) ? _('Balances in Home Currency') : $currency;
        
        $showAll = $config->getParam('show_all') ? _('Yes') : _('No');
        $summary = $config->getParam('summary_only') ? _('Summary Only') : _('Detailed Report');
        $noZeros = $config->getParam('no_zeros') ? _('Yes') : _('No');
        
        return [
            0 => $config->getParam('comments'),
            1 => ['text' => _('End Date'), 'from' => $config->getParam('to_date'), 'to' => ''],
            2 => ['text' => _('Supplier'), 'from' => $supplierName, 'to' => ''],
            3 => ['text' => _('Currency'), 'from' => $currencyText, 'to' => ''],
            4 => ['text' => _('Type'), 'from' => $summary, 'to' => ''],
            5 => ['text' => _('Show All'), 'from' => $showAll, 'to' => ''],
            6 => ['text' => _('Suppress Zeros'), 'from' => $noZeros, 'to' => '']
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $supplier = $config->getParam('supplier');
        
        // Get all suppliers or specific supplier
        $sql = "SELECT supplier_id, supp_name, curr_code, inactive 
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
        
        $toDate = $config->getParam('to_date');
        $currency = $config->getParam('currency');
        $showAll = (bool)$config->getParam('show_all');
        $summaryOnly = (bool)$config->getParam('summary_only');
        $noZeros = (bool)$config->getParam('no_zeros');
        $convert = ($currency == ALL_TEXT);
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        
        $suppliers = [];
        $grandTotals = [
            'current' => 0.0,
            'due' => 0.0,
            'overdue1' => 0.0,
            'overdue2' => 0.0,
            'total' => 0.0
        ];
        
        foreach ($data as $supplier) {
            // Skip if currency doesn't match
            if (!$convert && $currency != $supplier['curr_code']) {
                continue;
            }
            
            $rate = $convert 
                ? \FA\Services\BankingService::getExchangeRateFromHomeCurrency($supplier['curr_code'], \DateService::todayStatic())
                : 1;
            
            // Get invoices for this supplier
            $invoices = $this->getInvoices($supplier['supplier_id'], $toDate, $showAll);
            
            $totals = [
                'current' => 0.0,
                'due' => 0.0,
                'overdue1' => 0.0,
                'overdue2' => 0.0,
                'total' => 0.0
            ];
            
            $details = [];
            
            foreach ($invoices as $inv) {
                $balance = (float)$inv['Balance'] * $rate;
                $due = (float)$inv['Due'] * $rate;
                $overdue1 = (float)$inv['Overdue1'] * $rate;
                $overdue2 = (float)$inv['Overdue2'] * $rate;
                
                $current = $balance - $due;
                $days30 = $due - $overdue1;
                $days60 = $overdue1 - $overdue2;
                $days90 = $overdue2;
                
                $totals['current'] += $current;
                $totals['due'] += $days30;
                $totals['overdue1'] += $days60;
                $totals['overdue2'] += $days90;
                $totals['total'] += $balance;
                
                if (!$summaryOnly) {
                    $details[] = [
                        'type' => $inv['type'],
                        'reference' => $inv['reference'],
                        'tran_date' => $inv['tran_date'],
                        'current' => $current,
                        'days30' => $days30,
                        'days60' => $days60,
                        'days90' => $days90,
                        'total' => $balance
                    ];
                }
            }
            
            // Skip if no balance and suppressing zeros
            if ($noZeros && $totals['total'] == 0.0) {
                continue;
            }
            
            $suppliers[] = [
                'supplier_id' => $supplier['supplier_id'],
                'supp_name' => $supplier['supp_name'],
                'curr_code' => $supplier['curr_code'],
                'inactive' => $supplier['inactive'],
                'totals' => $totals,
                'details' => $details
            ];
            
            foreach ($totals as $key => $value) {
                $grandTotals[$key] += $value;
            }
        }
        
        $processed = [
            'suppliers' => $suppliers,
            'grand_totals' => $grandTotals,
            'summary_only' => $summaryOnly
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get invoices for a supplier
     */
    private function getInvoices(int $supplierId, string $toDate, bool $showAll): array
    {
        $toSql = \DateService::date2sqlStatic($toDate);
        $pastDueDays1 = \FA\Services\CompanyPrefsService::getCompanyPref('past_due_days');
        $pastDueDays2 = 2 * $pastDueDays1;
        
        $value = $showAll
            ? "(trans.ov_amount + trans.ov_gst + trans.ov_discount)"
            : "IF (trans.type = ".ST_SUPPINVOICE." OR trans.type = ".ST_BANKDEPOSIT." OR (trans.type = ".ST_JOURNAL." AND (trans.ov_amount + trans.ov_gst + trans.ov_discount) > 0),  
                (trans.ov_amount + trans.ov_gst + trans.ov_discount - trans.alloc),
                (trans.ov_amount + trans.ov_gst + trans.ov_discount + trans.alloc))";
        
        $due = "IF (trans.type = ".ST_SUPPINVOICE." OR trans.type = ".ST_SUPPCREDIT.", trans.due_date, trans.tran_date)";
        
        $sql = "SELECT trans.type,
                    trans.reference,
                    trans.tran_date,
                    $value as Balance,
                    IF ((TO_DAYS(".$this->db->escape($toSql).") - TO_DAYS($due)) > 0, $value, 0) AS Due,
                    IF ((TO_DAYS(".$this->db->escape($toSql).") - TO_DAYS($due)) > $pastDueDays1, $value, 0) AS Overdue1,
                    IF ((TO_DAYS(".$this->db->escape($toSql).") - TO_DAYS($due)) > $pastDueDays2, $value, 0) AS Overdue2
                FROM ".TB_PREF."suppliers supplier
                INNER JOIN ".TB_PREF."supp_trans trans ON supplier.supplier_id = trans.supplier_id
                WHERE trans.supplier_id = ".$this->db->escape($supplierId)."
                  AND trans.tran_date <= ".$this->db->escape($toSql)."
                  AND ABS(trans.ov_amount + trans.ov_gst + trans.ov_discount) > ".FLOAT_COMP_DELTA;
        
        if (!$showAll) {
            $sql .= " AND $value <> 0";
        }
        
        $sql .= " ORDER BY trans.tran_date";
        
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
        $summaryOnly = $processedData['summary_only'];
        
        foreach ($processedData['suppliers'] as $suppData) {
            // Supplier header
            $name = $suppData['supp_name'];
            if ($suppData['inactive']) {
                $name .= ' ('._('Inactive').')';
            }
            
            $rep->fontSize += 2;
            $rep->TextCol(0, 2, $name);
            $rep->TextCol(2, 3, $suppData['curr_code']);
            $rep->fontSize -= 2;
            $rep->NewLine(2);
            
            // Detail lines if not summary
            if (!$summaryOnly && !empty($suppData['details'])) {
                foreach ($suppData['details'] as $detail) {
                    $rep->TextCol(0, 1, $GLOBALS['systypes_array'][$detail['type']]);
                    $rep->TextCol(1, 2, $detail['reference']);
                    $rep->DateCol(2, 3, $detail['tran_date'], true);
                    $rep->AmountCol(3, 4, $detail['current'], $dec);
                    $rep->AmountCol(4, 5, $detail['days30'], $dec);
                    $rep->AmountCol(5, 6, $detail['days60'], $dec);
                    $rep->AmountCol(6, 7, $detail['days90'], $dec);
                    $rep->AmountCol(7, 8, $detail['total'], $dec);
                    $rep->NewLine();
                    
                    if ($rep->row < $rep->bottomMargin + (3 * $rep->lineHeight)) {
                        $rep->NewPage();
                    }
                }
            }
            
            // Supplier totals
            $rep->NewLine();
            $rep->fontSize += 2;
            $rep->TextCol(0, 3, _('Total'));
            $rep->fontSize -= 2;
            $rep->AmountCol(3, 4, $suppData['totals']['current'], $dec);
            $rep->AmountCol(4, 5, $suppData['totals']['due'], $dec);
            $rep->AmountCol(5, 6, $suppData['totals']['overdue1'], $dec);
            $rep->AmountCol(6, 7, $suppData['totals']['overdue2'], $dec);
            $rep->AmountCol(7, 8, $suppData['totals']['total'], $dec);
            $rep->Line($rep->row - 4);
            $rep->NewLine(2);
        }
        
        // Grand totals
        $rep->fontSize += 2;
        $rep->TextCol(0, 3, _('Grand Total'));
        $rep->fontSize -= 2;
        $rep->AmountCol(3, 4, $processedData['grand_totals']['current'], $dec);
        $rep->AmountCol(4, 5, $processedData['grand_totals']['due'], $dec);
        $rep->AmountCol(5, 6, $processedData['grand_totals']['overdue1'], $dec);
        $rep->AmountCol(6, 7, $processedData['grand_totals']['overdue2'], $dec);
        $rep->AmountCol(7, 8, $processedData['grand_totals']['total'], $dec);
        $rep->Line($rep->row - 4);
        $rep->NewLine();
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
