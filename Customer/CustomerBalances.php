<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Customer;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use Psr\Log\LoggerInterface;

/**
 * Customer Balances Report (rep101)
 * 
 * Shows customer balances with transaction details for a period.
 * Displays opening balance, transactions, and running/outstanding balance.
 * Supports currency conversion and zero suppression.
 * 
 * @package FA\Modules\Reports\Customer
 */
class CustomerBalances extends AbstractReportService
{
    public function __construct(
        DBALInterface $dbal,
        EventDispatcher $dispatcher,
        LoggerInterface $logger
    ) {
        parent::__construct(
            $dbal,
            $dispatcher,
            $logger,
            'Customer Balances',
            'customer_balances'
        );
    }

    /**
     * Fetch customer balances data
     */
    protected function fetchData(ReportConfig $config): array
    {
        $customerId = $config->getAdditionalParam('customer_id', 'All');
        $showBalance = $config->getAdditionalParam('show_balance', false);
        $currency = $config->getCurrency();
        $convert = ($currency === 'All');
        
        // Fetch customers
        $customers = $this->fetchCustomers($customerId);
        
        $results = [];
        foreach ($customers as $customer) {
            // Skip if currency doesn't match (when not converting)
            if (!$convert && $currency !== $customer['curr_code']) {
                continue;
            }
            
            // Get exchange rate
            $rate = $convert 
                ? \FA\Services\BankingService::getExchangeRateFromHomeCurrency(
                    $customer['curr_code'],
                    \DateService::todayStatic()
                )
                : 1.0;
            
            // Get opening balance
            $openBalance = $this->getOpenBalance($customer['debtor_no'], $config->getFromDate());
            
            // Get transactions
            $transactions = $this->fetchCustomerTransactions(
                $customer['debtor_no'],
                $config->getFromDate(),
                $config->getToDate()
            );
            
            // Skip if no transactions and suppress zeros
            if ($config->shouldSuppressZeros() && empty($transactions)) {
                continue;
            }
            
            $results[] = [
                'customer' => $customer,
                'open_balance' => $openBalance,
                'transactions' => $transactions,
                'rate' => $rate,
                'convert' => $convert
            ];
        }
        
        return [
            'customers' => $results,
            'show_balance' => $showBalance,
            'convert' => $convert
        ];
    }

    /**
     * Process data with currency conversion
     */
    protected function processData(array $rawData, ReportConfig $config): array
    {
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        $showBalance = $rawData['show_balance'];
        $suppressZeros = $config->shouldSuppressZeros();
        
        $grandTotal = [0.0, 0.0, 0.0, 0.0];
        
        foreach ($rawData['customers'] as &$customerData) {
            $rate = $customerData['rate'];
            $bal = $customerData['open_balance'];
            
            // Initialize opening balance
            $init = [
                0 => round2(abs($bal['charges'] ?? 0) * $rate, $dec),
                1 => round2(abs($bal['credits'] ?? 0) * $rate, $dec),
                2 => round2(($bal['Allocated'] ?? 0) * $rate, $dec),
                3 => 0.0
            ];
            
            if ($showBalance) {
                $init[3] = $init[0] - $init[1];
            } else {
                $init[3] = round2(($bal['OutStanding'] ?? 0) * $rate, $dec);
            }
            
            $accumulate = $showBalance ? $init[3] : 0.0;
            
            $total = [0.0, 0.0, 0.0, 0.0];
            for ($i = 0; $i < 4; $i++) {
                $total[$i] += $init[$i];
                $grandTotal[$i] += $init[$i];
            }
            
            // Process transactions
            $processedTrans = [];
            foreach ($customerData['transactions'] as $trans) {
                // Apply zero suppression
                if ($suppressZeros) {
                    if ($showBalance) {
                        if ($trans['TotalAmount'] == 0) continue;
                    } else {
                        if (floatcmp(abs($trans['TotalAmount']), $trans['Allocated']) == 0) continue;
                    }
                }
                
                $item = [0.0, 0.0, 0.0, 0.0];
                
                // Adjust for credit/payment types
                $totalAmount = $trans['TotalAmount'];
                if (in_array($trans['type'], [ST_CUSTCREDIT, ST_CUSTPAYMENT, ST_BANKDEPOSIT])) {
                    $totalAmount *= -1;
                }
                
                if ($totalAmount > 0.0) {
                    $item[0] = round2($totalAmount * $rate, $dec);
                    $accumulate += $item[0];
                    $item[2] = round2($trans['Allocated'] * $rate, $dec);
                } else {
                    $item[1] = round2(abs($totalAmount) * $rate, $dec);
                    $accumulate -= $item[1];
                    $item[2] = round2($trans['Allocated'] * $rate, $dec) * -1;
                }
                
                // Calculate outstanding
                if (($trans['type'] == ST_JOURNAL && $item[0]) || 
                    $trans['type'] == ST_SALESINVOICE || 
                    $trans['type'] == ST_BANKPAYMENT) {
                    $item[3] = $item[0] - $item[2];
                } else {
                    $item[3] = -$item[1] - $item[2];
                }
                
                $trans['item'] = $item;
                $trans['accumulate'] = $accumulate;
                
                for ($i = 0; $i < 4; $i++) {
                    $total[$i] += $item[$i];
                    $grandTotal[$i] += $item[$i];
                }
                
                $processedTrans[] = $trans;
            }
            
            if ($showBalance) {
                $total[3] = $total[0] - $total[1];
            }
            
            $customerData['init'] = $init;
            $customerData['transactions'] = $processedTrans;
            $customerData['total'] = $total;
        }
        
        if ($showBalance) {
            $grandTotal[3] = $grandTotal[0] - $grandTotal[1];
        }
        
        $rawData['grand_total'] = $grandTotal;
        
        return $rawData;
    }

    /**
     * Format data for output
     */
    protected function formatData(array $processedData, ReportConfig $config): array
    {
        return [
            'customers' => $processedData['customers'],
            'grand_total' => $processedData['grand_total'],
            'show_balance' => $processedData['show_balance'],
            'convert' => $processedData['convert'],
            'customer_count' => count($processedData['customers'])
        ];
    }

    /**
     * Fetch customers
     */
    private function fetchCustomers(string $customerId): array
    {
        $sql = "SELECT debtor_no, name, curr_code, inactive 
                FROM " . TB_PREF . "debtors_master";
        
        $params = [];
        if ($customerId !== 'All') {
            $sql .= " WHERE debtor_no = :customer_id";
            $params['customer_id'] = $customerId;
        }
        
        $sql .= " ORDER BY name";
        
        return $this->dbal->fetchAll($sql, $params);
    }

    /**
     * Get opening balance for customer
     */
    private function getOpenBalance(string $debtorNo, string $toDate): array
    {
        $toDateSql = $toDate ? \DateService::date2sqlStatic($toDate) : null;
        
        $sql = "SELECT 
            SUM(IF(t.type = " . ST_SALESINVOICE . " OR (t.type IN (" . ST_JOURNAL . ", " . ST_BANKPAYMENT . ") AND t.ov_amount > 0),
                -abs(IF(t.prep_amount, t.prep_amount, t.ov_amount + t.ov_gst + t.ov_freight + t.ov_freight_tax + t.ov_discount)), 0)) AS charges,
            SUM(IF(t.type != " . ST_SALESINVOICE . " AND NOT(t.type IN (" . ST_JOURNAL . ", " . ST_BANKPAYMENT . ") AND t.ov_amount > 0),
                abs(t.ov_amount + t.ov_gst + t.ov_freight + t.ov_freight_tax + t.ov_discount) * -1, 0)) AS credits,
            SUM(IF(t.type != " . ST_SALESINVOICE . " AND NOT(t.type IN (" . ST_JOURNAL . ", " . ST_BANKPAYMENT . ")), t.alloc * -1, t.alloc)) AS Allocated,
            SUM(IF(t.type = " . ST_SALESINVOICE . " OR (t.type IN (" . ST_JOURNAL . ", " . ST_BANKPAYMENT . ") AND t.ov_amount > 0), 1, -1) *
                (IF(t.prep_amount, t.prep_amount, abs(t.ov_amount + t.ov_gst + t.ov_freight + t.ov_freight_tax + t.ov_discount)) - abs(t.alloc))) AS OutStanding
        FROM " . TB_PREF . "debtor_trans t
        WHERE t.debtor_no = :debtor_no
        AND t.type <> " . ST_CUSTDELIVERY;
        
        $params = ['debtor_no' => $debtorNo];
        
        if ($toDateSql) {
            $sql .= " AND t.tran_date < :to_date";
            $params['to_date'] = $toDateSql;
        }
        
        $sql .= " GROUP BY debtor_no";
        
        $result = $this->dbal->fetchOne($sql, $params);
        
        return $result ?: ['charges' => 0, 'credits' => 0, 'Allocated' => 0, 'OutStanding' => 0];
    }

    /**
     * Fetch customer transactions
     */
    private function fetchCustomerTransactions(
        string $debtorNo,
        string $fromDate,
        string $toDate
    ): array {
        $fromDateSql = \DateService::date2sqlStatic($fromDate);
        $toDateSql = \DateService::date2sqlStatic($toDate);
        
        $allocatedFrom = "(SELECT trans_type_from as trans_type, trans_no_from as trans_no, date_alloc, sum(amt) amount
            FROM " . TB_PREF . "cust_allocations alloc
            WHERE person_id = :debtor_no
            AND date_alloc <= :to_date
            GROUP BY trans_type_from, trans_no_from) alloc_from";
        
        $allocatedTo = "(SELECT trans_type_to as trans_type, trans_no_to as trans_no, date_alloc, sum(amt) amount
            FROM " . TB_PREF . "cust_allocations alloc
            WHERE person_id = :debtor_no
            AND date_alloc <= :to_date
            GROUP BY trans_type_to, trans_no_to) alloc_to";
        
        $sql = "SELECT trans.*,
            IF(trans.prep_amount, trans.prep_amount, trans.ov_amount + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax + trans.ov_discount) AS TotalAmount,
            IFNULL(alloc_from.amount, alloc_to.amount) AS Allocated,
            ((trans.type = " . ST_SALESINVOICE . ") AND trans.due_date < :to_date) AS OverDue
        FROM " . TB_PREF . "debtor_trans trans
        LEFT JOIN " . TB_PREF . "voided voided ON trans.type = voided.type AND trans.trans_no = voided.id
        LEFT JOIN $allocatedFrom ON alloc_from.trans_type = trans.type AND alloc_from.trans_no = trans.trans_no
        LEFT JOIN $allocatedTo ON alloc_to.trans_type = trans.type AND alloc_to.trans_no = trans.trans_no
        WHERE trans.tran_date >= :from_date
        AND trans.tran_date <= :to_date
        AND trans.debtor_no = :debtor_no
        AND trans.type <> " . ST_CUSTDELIVERY . "
        AND ISNULL(voided.id)
        ORDER BY trans.tran_date";
        
        return $this->dbal->fetchAll($sql, [
            'debtor_no' => $debtorNo,
            'from_date' => $fromDateSql,
            'to_date' => $toDateSql
        ]);
    }

    /**
     * Get column definitions
     */
    protected function getColumns(ReportConfig $config): array
    {
        return [0, 95, 140, 200, 250, 320, 385, 450, 515];
    }

    /**
     * Get header labels
     */
    protected function getHeaders(ReportConfig $config): array
    {
        $showBalance = $config->getAdditionalParam('show_balance', false);
        
        $headers = [
            _('Trans Type'),
            _('#'),
            _('Date'),
            _('Due Date'),
            _('Debits'),
            _('Credits'),
            _('Allocated'),
            $showBalance ? _('Balance') : _('Outstanding')
        ];
        
        return $headers;
    }

    /**
     * Get column alignments
     */
    protected function getAligns(ReportConfig $config): array
    {
        return ['left', 'left', 'left', 'left', 'right', 'right', 'right', 'right'];
    }
}
