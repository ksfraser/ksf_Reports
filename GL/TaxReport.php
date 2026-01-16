<?php

declare(strict_types=1);

namespace FA\Modules\Reports\GL;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use Psr\Log\LoggerInterface;

/**
 * Tax Report (rep709)
 * 
 * Shows tax transactions with detailed breakdown and summary.
 * Categorizes into inputs (purchases) and outputs (sales).
 * Calculates net tax payable or refundable.
 * 
 * @package FA\Modules\Reports\GL
 */
class TaxReport extends AbstractReportService
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
            'Tax Report',
            'tax_report'
        );
    }

    /**
     * Fetch tax report data
     */
    protected function fetchData(ReportConfig $config): array
    {
        $summaryOnly = (bool)$config->getAdditionalParam('summary_only', false);
        
        // Get all tax types
        $taxTypes = $this->fetchTaxTypes();
        
        // Initialize totals array
        $taxes = [0 => ['in' => 0.0, 'out' => 0.0, 'taxin' => 0.0, 'taxout' => 0.0]];
        foreach ($taxTypes as $taxType) {
            $taxes[$taxType['id']] = [
                'in' => 0.0,
                'out' => 0.0,
                'taxin' => 0.0,
                'taxout' => 0.0,
                'name' => $taxType['name'],
                'rate' => $taxType['rate']
            ];
        }
        
        // Fetch transactions
        $transactions = $this->fetchTaxTransactions($config);
        
        $totalNet = 0.0;
        $totalTax = 0.0;
        
        foreach ($transactions as &$trans) {
            // Apply sign corrections for credits and purchases
            if (in_array($trans['trans_type'], [ST_CUSTCREDIT, ST_SUPPINVOICE]) ||
                ($trans['trans_type'] == ST_JOURNAL && $trans['reg_type'] == TR_INPUT)) {
                $trans['net_amount'] *= -1;
                $trans['amount'] *= -1;
            }
            
            // Categorize transaction
            $taxTypeId = $trans['tax_type_id'];
            
            if ($trans['trans_type'] == ST_JOURNAL && $trans['reg_type'] == TR_INPUT) {
                $taxes[$taxTypeId]['taxin'] += $trans['amount'];
                $taxes[$taxTypeId]['in'] += $trans['net_amount'];
            } elseif ($trans['trans_type'] == ST_JOURNAL && $trans['reg_type'] == TR_OUTPUT) {
                $taxes[$taxTypeId]['taxout'] += $trans['amount'];
                $taxes[$taxTypeId]['out'] += $trans['net_amount'];
            } elseif (in_array($trans['trans_type'], [ST_BANKDEPOSIT, ST_SALESINVOICE, ST_CUSTCREDIT])) {
                $taxes[$taxTypeId]['taxout'] += $trans['amount'];
                $taxes[$taxTypeId]['out'] += $trans['net_amount'];
            } elseif ($trans['reg_type'] !== null) {
                $taxes[$taxTypeId]['taxin'] += $trans['amount'];
                $taxes[$taxTypeId]['in'] += $trans['net_amount'];
            }
            
            $totalNet += $trans['net_amount'];
            $totalTax += $trans['amount'];
        }
        
        return [
            'transactions' => $summaryOnly ? [] : $transactions,
            'tax_types' => $taxes,
            'totals' => [
                'net' => $totalNet,
                'tax' => $totalTax
            ],
            'summary_only' => $summaryOnly
        ];
    }

    /**
     * Process data
     */
    protected function processData(array $rawData, ReportConfig $config): array
    {
        // Calculate net tax payable
        $taxTotal = 0.0;
        
        foreach ($rawData['tax_types'] as $taxType) {
            $taxTotal += $taxType['taxout'] + $taxType['taxin'];
        }
        
        $rawData['tax_payable'] = $taxTotal;
        
        return $rawData;
    }

    /**
     * Format data for output
     */
    protected function formatData(array $processedData, ReportConfig $config): array
    {
        return [
            'transactions' => $processedData['transactions'],
            'tax_summary' => $processedData['tax_types'],
            'tax_payable' => $processedData['tax_payable'],
            'totals' => $processedData['totals'],
            'summary_only' => $processedData['summary_only']
        ];
    }

    /**
     * Fetch tax types
     */
    private function fetchTaxTypes(): array
    {
        $sql = "SELECT id, name, rate 
                FROM " . TB_PREF . "tax_types 
                ORDER BY id";
        
        return $this->dbal->fetchAll($sql);
    }

    /**
     * Fetch tax transactions
     */
    private function fetchTaxTransactions(ReportConfig $config): array
    {
        $fromDateSql = \DateService::date2sqlStatic($config->getFromDate());
        $toDateSql = \DateService::date2sqlStatic($config->getToDate());
        
        $sql = "SELECT 
                tt.name as taxname, 
                taxrec.*, 
                taxrec.amount * ex_rate AS amount,
                taxrec.net_amount * ex_rate AS net_amount,
                IF(taxrec.trans_type = " . ST_BANKPAYMENT . " OR taxrec.trans_type = " . ST_BANKDEPOSIT . ", 
                    IF(gl.person_type_id <> " . PT_MISC . ", gl.memo_, gl.person_id), 
                    IF(ISNULL(supp.supp_name), debt.name, supp.supp_name)) as name,
                branch.br_name
            FROM " . TB_PREF . "trans_tax_details taxrec
            LEFT JOIN " . TB_PREF . "tax_types tt
                ON taxrec.tax_type_id = tt.id
            LEFT JOIN " . TB_PREF . "gl_trans gl 
                ON taxrec.trans_type = gl.type AND taxrec.trans_no = gl.type_no 
                AND gl.amount <> 0 AND gl.amount = taxrec.amount 
                AND (tt.purchasing_gl_code = gl.account OR tt.sales_gl_code = gl.account)
            LEFT JOIN " . TB_PREF . "supp_trans strans
                ON taxrec.trans_no = strans.trans_no AND taxrec.trans_type = strans.type
            LEFT JOIN " . TB_PREF . "suppliers as supp 
                ON strans.supplier_id = supp.supplier_id
            LEFT JOIN " . TB_PREF . "debtor_trans dtrans
                ON taxrec.trans_no = dtrans.trans_no AND taxrec.trans_type = dtrans.type
            LEFT JOIN " . TB_PREF . "debtors_master as debt 
                ON dtrans.debtor_no = debt.debtor_no
            LEFT JOIN " . TB_PREF . "cust_branch as branch 
                ON dtrans.branch_code = branch.branch_code
            WHERE (taxrec.amount <> 0 OR taxrec.net_amount <> 0)
                AND !ISNULL(taxrec.reg_type)
                AND taxrec.tran_date >= :from_date
                AND taxrec.tran_date <= :to_date
            ORDER BY taxrec.trans_type, taxrec.tran_date, taxrec.trans_no, taxrec.ex_rate";
        
        return $this->dbal->fetchAll($sql, [
            'from_date' => $fromDateSql,
            'to_date' => $toDateSql
        ]);
    }

    /**
     * Get column definitions for detail report
     */
    protected function getColumns(ReportConfig $config): array
    {
        $summaryOnly = (bool)$config->getAdditionalParam('summary_only', false);
        
        if ($summaryOnly) {
            // Summary columns
            return [0, 100, 180, 260, 340, 420, 500];
        }
        
        // Detail columns
        return [0, 80, 130, 180, 270, 350, 400, 430, 480, 485, 520];
    }

    /**
     * Get header labels
     */
    protected function getHeaders(ReportConfig $config): array
    {
        $summaryOnly = (bool)$config->getAdditionalParam('summary_only', false);
        
        if ($summaryOnly) {
            return [
                _('Tax Rate'),
                _('Outputs'),
                _('Output Tax'),
                _('Inputs'),
                _('Input Tax'),
                _('Net Tax')
            ];
        }
        
        return [
            _('Trans Type'),
            _('Ref'),
            _('Date'),
            _('Name'),
            _('Branch Name'),
            _('Net'),
            _('Rate'),
            _('Tax'),
            '',
            _('Name')
        ];
    }

    /**
     * Get column alignments
     */
    protected function getAligns(ReportConfig $config): array
    {
        $summaryOnly = (bool)$config->getAdditionalParam('summary_only', false);
        
        if ($summaryOnly) {
            return ['left', 'right', 'right', 'right', 'right', 'right'];
        }
        
        return ['left', 'left', 'left', 'left', 'left', 'right', 'right', 'right', 'right', 'left'];
    }
}
