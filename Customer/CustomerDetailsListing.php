<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Customer;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use Psr\Log\LoggerInterface;

/**
 * Customer Details Listing Report (rep103)
 * 
 * Comprehensive customer directory with full contact details, branches, and turnover.
 * Groups by sales area and salesperson with filtering options.
 */
class CustomerDetailsListing extends AbstractReportService
{
    public function __construct(DBALInterface $dbal, EventDispatcher $dispatcher, LoggerInterface $logger)
    {
        parent::__construct($dbal, $dispatcher, $logger, 'Customer Details Listing', 'customer_details');
    }

    protected function fetchData(ReportConfig $config): array
    {
        $area = $config->getAdditionalParam('area', 0);
        $salesPerson = $config->getAdditionalParam('sales_person', 0);
        $minTurnover = $config->getAdditionalParam('min_turnover', 0.0);
        $maxTurnover = $config->getAdditionalParam('max_turnover', 0.0);
        $fromDate = $config->getFromDate();
        
        $customers = $this->fetchCustomerDetails($area, $salesPerson);
        
        $results = [];
        foreach ($customers as $customer) {
            if ($minTurnover > 0 || $maxTurnover > 0) {
                $turnover = $this->getTransactions($customer['debtor_no'], $customer['branch_code'], $fromDate);
                if ($minTurnover > 0 && $turnover <= $minTurnover) continue;
                if ($maxTurnover > 0 && $turnover >= $maxTurnover) continue;
                $customer['turnover'] = $turnover;
            }
            
            $customer['contacts'] = $this->getContactsForBranch($customer['branch_code']);
            $results[] = $customer;
        }
        
        return ['customers' => $results];
    }

    protected function processData(array $rawData, ReportConfig $config): array
    {
        return $rawData;
    }

    protected function formatData(array $processedData, ReportConfig $config): array
    {
        return $processedData;
    }

    private function fetchCustomerDetails(int $area, int $salesPerson): array
    {
        $sql = "SELECT debtor.debtor_no, debtor.name, debtor.address, debtor.curr_code,
                debtor.dimension_id, debtor.dimension2_id, debtor.notes,
                pricelist.sales_type, branch.branch_code, branch.br_name,
                branch.br_address, branch.br_post_address, branch.area,
                branch.salesman, area.description, salesman.salesman_name
            FROM " . TB_PREF . "debtors_master debtor
            INNER JOIN " . TB_PREF . "cust_branch branch ON debtor.debtor_no = branch.debtor_no
            INNER JOIN " . TB_PREF . "sales_types pricelist ON debtor.sales_type = pricelist.id
            INNER JOIN " . TB_PREF . "areas area ON branch.area = area.area_code
            INNER JOIN " . TB_PREF . "salesman salesman ON branch.salesman = salesman.salesman_code
            WHERE debtor.inactive = 0";
        
        $params = [];
        if ($area != 0) {
            $sql .= " AND area.area_code = :area";
            $params['area'] = $area;
        }
        if ($salesPerson != 0) {
            $sql .= " AND salesman.salesman_code = :sales_person";
            $params['sales_person'] = $salesPerson;
        }
        
        $sql .= " ORDER BY area.description, salesman.salesman_name, debtor.debtor_no, branch.branch_code";
        
        return $this->dbal->fetchAll($sql, $params);
    }

    private function getContactsForBranch(string $branchCode): array
    {
        $sql = "SELECT p.*, r.action, r.type, CONCAT(r.type, '.', r.action) as ext_type
            FROM " . TB_PREF . "crm_persons p, " . TB_PREF . "crm_contacts r
            WHERE r.person_id = p.id AND r.type = 'cust_branch'
            AND r.entity_id = :branch_code";
        
        return $this->dbal->fetchAll($sql, ['branch_code' => $branchCode]);
    }

    private function getTransactions(string $debtorNo, string $branchCode, string $date): float
    {
        $dateSql = \DateService::date2sqlStatic($date);
        
        $sql = "SELECT SUM((ov_amount + ov_freight + ov_discount) * rate) AS Turnover
            FROM " . TB_PREF . "debtor_trans
            WHERE debtor_no = :debtor_no AND branch_code = :branch_code
            AND (type = " . ST_SALESINVOICE . " OR type = " . ST_CUSTCREDIT . ")
            AND tran_date >= :date";
        
        $result = $this->dbal->fetchOne($sql, [
            'debtor_no' => $debtorNo,
            'branch_code' => $branchCode,
            'date' => $dateSql
        ]);
        
        return (float)($result['Turnover'] ?? 0);
    }

    protected function getColumns(ReportConfig $config): array
    {
        return [0, 150, 300, 425, 550];
    }

    protected function getHeaders(ReportConfig $config): array
    {
        return [
            _('Customer Postal Address'),
            _('Price/Turnover'),
            _('Branch Contact Information'),
            _('Branch Delivery Address')
        ];
    }

    protected function getAligns(ReportConfig $config): array
    {
        return ['left', 'left', 'left', 'left'];
    }
}
