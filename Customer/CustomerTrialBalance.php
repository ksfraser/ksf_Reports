<?php
/**
 * Customer Trial Balance Service
 * 
 * Generates customer trial balance showing:
 * - Opening balances
 * - Period debits and credits
 * - Closing balances
 * - Filtering by area, salesperson, currency
 * 
 * Report: rep115
 * Category: Customer/Sales Reports
 */

declare(strict_types=1);

namespace FA\Reports\Customer;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class CustomerTrialBalance extends AbstractReportService
{
    private const REPORT_ID = 115;
    private const REPORT_TITLE = 'Customer Trial Balance';
    
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
        $customer = $config->getParam('customer');
        if ($customer == ALL_TEXT) {
            $custName = _('All');
        } else {
            $custName = get_customer_name($customer);
        }
        
        $area = $config->getParam('area', ALL_NUMERIC);
        $areaName = ($area == 0 || $area == ALL_NUMERIC) ? _('All Areas') : get_area_name($area);
        
        $salesPerson = $config->getParam('sales_person', ALL_NUMERIC);
        $salesPersonName = ($salesPerson == 0 || $salesPerson == ALL_NUMERIC) ? _('All Sales Man') : get_salesman_name($salesPerson);
        
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
            2 => ['text' => _('Customer'), 'from' => $custName, 'to' => ''],
            3 => ['text' => _('Sales Areas'), 'from' => $areaName, 'to' => ''],
            4 => ['text' => _('Sales Folk'), 'from' => $salesPersonName, 'to' => ''],
            5 => ['text' => _('Currency'), 'from' => $currencyText, 'to' => ''],
            6 => ['text' => _('Suppress Zeros'), 'from' => $noZeros, 'to' => '']
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $customer = $config->getParam('customer');
        $area = $config->getParam('area', ALL_NUMERIC);
        $salesPerson = $config->getParam('sales_person', ALL_NUMERIC);
        
        // Normalize area and sales person
        if ($area == ALL_NUMERIC) $area = 0;
        if ($salesPerson == ALL_NUMERIC) $salesPerson = 0;
        
        // Build query
        $sql = "SELECT d.debtor_no, name, curr_code, d.inactive 
                FROM ".TB_PREF."debtors_master d
                INNER JOIN ".TB_PREF."cust_branch b ON d.debtor_no = b.debtor_no
                INNER JOIN ".TB_PREF."areas a ON b.area = a.area_code
                INNER JOIN ".TB_PREF."salesman s ON b.salesman = s.salesman_code";
        
        $where = [];
        if ($customer != ALL_TEXT) {
            $where[] = "d.debtor_no = ".$this->db->escape($customer);
        } elseif ($area != 0) {
            $where[] = "a.area_code = ".$this->db->escape($area);
            if ($salesPerson != 0) {
                $where[] = "s.salesman_code = ".$this->db->escape($salesPerson);
            }
        } elseif ($salesPerson != 0) {
            $where[] = "s.salesman_code = ".$this->db->escape($salesPerson);
        }
        
        if (!empty($where)) {
            $sql .= " WHERE ".implode(' AND ', $where);
        }
        
        $sql .= " GROUP BY d.debtor_no ORDER BY name";
        
        $customers = $this->db->fetchAll($sql);
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $customers
        ]);
        
        return $customers;
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
        
        $customers = [];
        $totOpen = $totDb = $totCr = 0.0;
        
        foreach ($data as $row) {
            // Filter by currency if not converting
            if (!$convert && $currency != $row['curr_code']) {
                continue;
            }
            
            $rate = $convert 
                ? \FA\Services\BankingService::getExchangeRateFromHomeCurrency($row['curr_code'], \DateService::todayStatic()) 
                : 1;
            
            // Get opening balance
            $openBal = $this->getOpenBalance($row['debtor_no'], $fromDate);
            $currDb = $openBal ? round2(abs($openBal['charges'] * $rate), $dec) : 0;
            $currCr = $openBal ? round2(abs($openBal['credits'] * $rate), $dec) : 0;
            $currOpen = $currDb - $currCr;
            
            // Get period transactions
            $trans = $this->getTransactions($row['debtor_no'], $fromDate, $toDate);
            
            // If no transactions and no zeros suppression, include customer
            if (count($trans) == 0 && !$noZeros) {
                $customers[] = [
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
                    $periodDb += round2($amount, $dec);
                } else {
                    $periodCr += -round2($amount, $dec);
                }
            }
            
            $closingBal = $currOpen + $periodDb - $periodCr;
            
            // Skip if zero suppression and all zeros
            if ($noZeros && $currOpen == 0.0 && $periodDb == 0.0 && $periodCr == 0.0) {
                continue;
            }
            
            $customers[] = [
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
            'customers' => $customers,
            'total_open' => $totOpen,
            'total_debit' => $totDb,
            'total_credit' => $totCr,
            'total_balance' => $totOpen + $totDb - $totCr
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get opening balance for a customer
     */
    private function getOpenBalance(int $debtorNo, string $toDate): ?array
    {
        $to = \DateService::date2sqlStatic($toDate);
        
        $sql = "SELECT 
                SUM(IF(t.type = ".ST_SALESINVOICE." OR (t.type IN (".ST_JOURNAL.", ".ST_BANKPAYMENT.") AND t.ov_amount > 0),
                    -abs(IF(t.prep_amount, t.prep_amount, t.ov_amount + t.ov_gst + t.ov_freight + t.ov_freight_tax + t.ov_discount)), 0)) AS charges,
                SUM(IF(t.type != ".ST_SALESINVOICE." AND NOT(t.type IN (".ST_JOURNAL.", ".ST_BANKPAYMENT.") AND t.ov_amount > 0),
                    abs(t.ov_amount + t.ov_gst + t.ov_freight + t.ov_freight_tax + t.ov_discount) * -1, 0)) AS credits,
                SUM(IF(t.type != ".ST_SALESINVOICE." AND NOT(t.type IN (".ST_JOURNAL.", ".ST_BANKPAYMENT.")), t.alloc * -1, t.alloc)) AS Allocated,
                SUM(IF(t.type = ".ST_SALESINVOICE." OR (t.type IN (".ST_JOURNAL.", ".ST_BANKPAYMENT.") AND t.ov_amount > 0), 1, -1) *
                    (IF(t.prep_amount, t.prep_amount, abs(t.ov_amount + t.ov_gst + t.ov_freight + t.ov_freight_tax + t.ov_discount)) - abs(t.alloc))) AS OutStanding
                FROM ".TB_PREF."debtor_trans t
                WHERE t.debtor_no = ".$this->db->escape($debtorNo)."
                  AND t.type <> ".ST_CUSTDELIVERY."
                  AND t.tran_date < ".$this->db->escape($to)."
                GROUP BY debtor_no";
        
        return $this->db->fetchOne($sql);
    }
    
    /**
     * Get transactions for a customer in a period
     */
    private function getTransactions(int $debtorNo, string $fromDate, string $toDate): array
    {
        $from = \DateService::date2sqlStatic($fromDate);
        $to = \DateService::date2sqlStatic($toDate);
        
        $sign = "IF(trans.type IN(".implode(',', [ST_CUSTCREDIT, ST_CUSTPAYMENT, ST_BANKDEPOSIT])."), -1, 1)";
        
        $allocatedFrom = 
            "(SELECT trans_type_from as trans_type, trans_no_from as trans_no, date_alloc, sum(amt) amount
            FROM ".TB_PREF."cust_allocations alloc
            WHERE person_id = ".$this->db->escape($debtorNo)."
            AND date_alloc <= ".$this->db->escape($to)."
            GROUP BY trans_type_from, trans_no_from) alloc_from";
        
        $allocatedTo = 
            "(SELECT trans_type_to as trans_type, trans_no_to as trans_no, date_alloc, sum(amt) amount
            FROM ".TB_PREF."cust_allocations alloc
            WHERE person_id = ".$this->db->escape($debtorNo)."
            AND date_alloc <= ".$this->db->escape($to)."
            GROUP BY trans_type_to, trans_no_to) alloc_to";
        
        $sql = "SELECT trans.*, comments.memo_,
                $sign*IF(trans.prep_amount, trans.prep_amount, trans.ov_amount + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax + trans.ov_discount) AS TotalAmount,
                $sign*IFNULL(alloc_from.amount, alloc_to.amount) AS Allocated,
                ((trans.type = ".ST_SALESINVOICE.") AND trans.due_date < ".$this->db->escape($to).") AS OverDue
            FROM ".TB_PREF."debtor_trans trans
            LEFT JOIN ".TB_PREF."voided voided ON trans.type = voided.type AND trans.trans_no = voided.id
            LEFT JOIN ".TB_PREF."comments comments ON trans.type = comments.type AND trans.trans_no = comments.id
            LEFT JOIN $allocatedFrom ON alloc_from.trans_type = trans.type AND alloc_from.trans_no = trans.trans_no
            LEFT JOIN $allocatedTo ON alloc_to.trans_type = trans.type AND alloc_to.trans_no = trans.trans_no
            WHERE trans.tran_date >= ".$this->db->escape($from)."
              AND trans.tran_date <= ".$this->db->escape($to)."
              AND trans.debtor_no = ".$this->db->escape($debtorNo)."
              AND trans.type <> ".ST_CUSTDELIVERY."
              AND ISNULL(voided.id)
            ORDER BY trans.tran_date";
        
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
        
        // Render customer data
        foreach ($processedData['customers'] as $custData) {
            $name = $custData['name'];
            if ($custData['inactive'] == 1) {
                $name .= ' ('._('Inactive').')';
            }
            
            $rep->TextCol(0, 2, $name);
            $rep->AmountCol(3, 4, $custData['open_balance'], $dec);
            $rep->AmountCol(4, 5, $custData['debit'], $dec);
            $rep->AmountCol(5, 6, $custData['credit'], $dec);
            $rep->AmountCol(7, 8, $custData['balance'], $dec);
            $rep->NewLine(1);
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
