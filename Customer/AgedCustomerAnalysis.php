<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Customer;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use Psr\Log\LoggerInterface;

/**
 * Aged Customer Analysis Report (rep102)
 * 
 * Shows customer balances grouped by aging periods.
 * Displays current, 30 days, 60 days, and over periods.
 * Supports summary and detailed views with currency conversion.
 * 
 * @package FA\Modules\Reports\Customer
 */
class AgedCustomerAnalysis extends AbstractReportService
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
            'Aged Customer Analysis',
            'aged_customer_analysis'
        );
    }

    /**
     * Fetch aged customer data
     */
    protected function fetchData(ReportConfig $config): array
    {
        $customerId = $config->getAdditionalParam('customer_id', 'All');
        $showAll = $config->getAdditionalParam('show_all', false);
        $summaryOnly = $config->getAdditionalParam('summary_only', false);
        $currency = $config->getCurrency();
        $convert = ($currency === 'All');
        
        $toDate = $config->getToDate();
        $pastDueDays1 = \FA\Services\CompanyPrefsService::getCompanyPref('past_due_days');
        $pastDueDays2 = 2 * $pastDueDays1;
        
        // Fetch customers
        $customers = $this->fetchCustomers($customerId);
        
        $results = [];
        foreach ($customers as $customer) {
            if (!$convert && $currency !== $customer['curr_code']) {
                continue;
            }
            
            $rate = $convert
                ? \FA\Services\BankingService::getExchangeRateFromHomeCurrency($customer['curr_code'], $toDate)
                : 1.0;
            
            // Get customer aging details
            $custRec = $this->getCustomerDetails($customer['debtor_no'], $toDate, $showAll);
            if (!$custRec) {
                continue;
            }
            
            // Apply exchange rate
            $custRec['Balance'] *= $rate;
            $custRec['Due'] *= $rate;
            $custRec['Overdue1'] *= $rate;
            $custRec['Overdue2'] *= $rate;
            
            // Calculate aging buckets
            $aging = [
                'current' => $custRec['Balance'] - $custRec['Due'],
                'days_1' => $custRec['Due'] - $custRec['Overdue1'],
                'days_2' => $custRec['Overdue1'] - $custRec['Overdue2'],
                'days_3' => $custRec['Overdue2'],
                'total' => $custRec['Balance']
            ];
            
            // Skip if zero suppression enabled and total is zero
            if ($config->shouldSuppressZeros() && floatcmp(array_sum($aging), 0) == 0) {
                continue;
            }
            
            $customerData = [
                'customer' => $customer,
                'aging' => $aging,
                'rate' => $rate
            ];
            
            // Fetch detailed invoices if not summary only
            if (!$summaryOnly) {
                $customerData['invoices'] = $this->getInvoices(
                    $customer['debtor_no'],
                    $toDate,
                    $showAll,
                    $rate,
                    $pastDueDays1,
                    $pastDueDays2
                );
            }
            
            $results[] = $customerData;
        }
        
        return [
            'customers' => $results,
            'summary_only' => $summaryOnly,
            'convert' => $convert,
            'past_due_days_1' => $pastDueDays1,
            'past_due_days_2' => $pastDueDays2
        ];
    }

    /**
     * Process data and calculate totals
     */
    protected function processData(array $rawData, ReportConfig $config): array
    {
        $grandTotal = [0.0, 0.0, 0.0, 0.0, 0.0];
        
        foreach ($rawData['customers'] as $customerData) {
            $aging = $customerData['aging'];
            $grandTotal[0] += $aging['current'];
            $grandTotal[1] += $aging['days_1'];
            $grandTotal[2] += $aging['days_2'];
            $grandTotal[3] += $aging['days_3'];
            $grandTotal[4] += $aging['total'];
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
            'summary_only' => $processedData['summary_only'],
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
     * Get customer aging details
     */
    private function getCustomerDetails(string $customerId, string $toDate, bool $showAll): ?array
    {
        $toDateSql = \DateService::date2sqlStatic($toDate);
        $pastDue1 = \FA\Services\CompanyPrefsService::getCompanyPref('past_due_days');
        $pastDue2 = 2 * $pastDue1;
        
        $sign = "IF(trans.type IN(" . implode(',', [ST_CUSTCREDIT, ST_CUSTPAYMENT, ST_BANKDEPOSIT]) . "), -1, 1)";
        $value = "$sign*(IF(trans.prep_amount, trans.prep_amount,
            ABS(trans.ov_amount + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax + trans.ov_discount)) " . ($showAll ? '' : "- trans.alloc") . ")";
        $due = "IF (trans.type=" . ST_SALESINVOICE . ", trans.due_date, trans.tran_date)";
        
        $sql = "SELECT 
            SUM(IFNULL($value, 0)) AS Balance,
            SUM(IF ((TO_DAYS(:to_date) - TO_DAYS($due)) >= 0, $value, 0)) AS Due,
            SUM(IF ((TO_DAYS(:to_date) - TO_DAYS($due)) >= $pastDue1, $value, 0)) AS Overdue1,
            SUM(IF ((TO_DAYS(:to_date) - TO_DAYS($due)) >= $pastDue2, $value, 0)) AS Overdue2
        FROM " . TB_PREF . "debtors_master debtor
        LEFT JOIN " . TB_PREF . "debtor_trans trans 
            ON trans.tran_date <= :to_date 
            AND debtor.debtor_no = trans.debtor_no 
            AND trans.type <> " . ST_CUSTDELIVERY . "
        WHERE debtor.debtor_no = :customer_id";
        
        if (!$showAll) {
            $sql .= " AND ABS(IF(trans.prep_amount, trans.prep_amount, ABS(trans.ov_amount) + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax + trans.ov_discount) - trans.alloc) > " . FLOAT_COMP_DELTA;
        }
        
        $sql .= " GROUP BY debtor.debtor_no";
        
        return $this->dbal->fetchOne($sql, [
            'customer_id' => $customerId,
            'to_date' => $toDateSql
        ]);
    }

    /**
     * Get invoice details for customer
     */
    private function getInvoices(
        string $customerId,
        string $toDate,
        bool $showAll,
        float $rate,
        int $pastDueDays1,
        int $pastDueDays2
    ): array {
        $toDateSql = \DateService::date2sqlStatic($toDate);
        
        $sign = "IF(`type` IN(" . implode(',', [ST_CUSTCREDIT, ST_CUSTPAYMENT, ST_BANKDEPOSIT]) . "), -1, 1)";
        $value = "$sign*(IF(trans.prep_amount, trans.prep_amount,
            ABS(trans.ov_amount + trans.ov_gst + trans.ov_freight + trans.ov_freight_tax + trans.ov_discount)) " . ($showAll ? '' : "- trans.alloc") . ")";
        $due = "IF (type=" . ST_SALESINVOICE . ", due_date, tran_date)";
        
        $sql = "SELECT type, reference, tran_date,
            $value as Balance,
            IF ((TO_DAYS(:to_date) - TO_DAYS($due)) >= 0, $value, 0) AS Due,
            IF ((TO_DAYS(:to_date) - TO_DAYS($due)) >= $pastDueDays1, $value, 0) AS Overdue1,
            IF ((TO_DAYS(:to_date) - TO_DAYS($due)) >= $pastDueDays2, $value, 0) AS Overdue2
        FROM " . TB_PREF . "debtor_trans trans
        WHERE type <> " . ST_CUSTDELIVERY . "
        AND debtor_no = :customer_id
        AND tran_date <= :to_date
        AND ABS($value) > " . FLOAT_COMP_DELTA . "
        ORDER BY tran_date";
        
        $invoices = $this->dbal->fetchAll($sql, [
            'customer_id' => $customerId,
            'to_date' => $toDateSql
        ]);
        
        // Apply rate and calculate aging
        foreach ($invoices as &$invoice) {
            foreach ($invoice as $key => $value) {
                if (is_numeric($value)) {
                    $invoice[$key] = (float)$value * $rate;
                }
            }
            
            $invoice['aging'] = [
                $invoice['Balance'] - $invoice['Due'],
                $invoice['Due'] - $invoice['Overdue1'],
                $invoice['Overdue1'] - $invoice['Overdue2'],
                $invoice['Overdue2'],
                $invoice['Balance']
            ];
        }
        
        return $invoices;
    }

    /**
     * Get column definitions
     */
    protected function getColumns(ReportConfig $config): array
    {
        return [0, 100, 130, 190, 250, 320, 385, 450, 515];
    }

    /**
     * Get header labels
     */
    protected function getHeaders(ReportConfig $config): array
    {
        $pastDueDays1 = \FA\Services\CompanyPrefsService::getCompanyPref('past_due_days');
        $pastDueDays2 = 2 * $pastDueDays1;
        
        $nowDue = "1-" . $pastDueDays1 . " " . _('Days');
        $pastDue1 = ($pastDueDays1 + 1) . "-" . $pastDueDays2 . " " . _('Days');
        $pastDue2 = _('Over') . " " . $pastDueDays2 . " " . _('Days');
        
        return [
            _('Customer'),
            '',
            '',
            _('Current'),
            $nowDue,
            $pastDue1,
            $pastDue2,
            _('Total Balance')
        ];
    }

    /**
     * Get column alignments
     */
    protected function getAligns(ReportConfig $config): array
    {
        return ['left', 'left', 'left', 'right', 'right', 'right', 'right', 'right'];
    }
}
