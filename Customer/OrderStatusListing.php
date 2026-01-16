<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Customer;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use Psr\Log\LoggerInterface;

/**
 * Order Status Listing Report (rep105)
 * 
 * Lists sales orders with line item details showing quantities ordered, delivered, and outstanding.
 * Supports filtering by category, location, and backorder status.
 */
class OrderStatusListing extends AbstractReportService
{
    public function __construct(DBALInterface $dbal, EventDispatcher $dispatcher, LoggerInterface $logger)
    {
        parent::__construct($dbal, $dispatcher, $logger, 'Order Status Listing', 'order_status');
    }

    protected function fetchData(ReportConfig $config): array
    {
        $category = $config->getAdditionalParam('category', 0);
        $location = $config->getAdditionalParam('location', null);
        $backorder = $config->getAdditionalParam('backorder', false);
        
        $orders = $this->getSalesOrders(
            $config->getFromDate(),
            $config->getToDate(),
            $category,
            $location,
            $backorder
        );
        
        return ['orders' => $orders];
    }

    protected function processData(array $rawData, ReportConfig $config): array
    {
        $grandTotal = 0.0;
        
        foreach ($rawData['orders'] as $order) {
            $grandTotal += $order['total'];
        }
        
        $rawData['grand_total'] = $grandTotal;
        return $rawData;
    }

    protected function formatData(array $processedData, ReportConfig $config): array
    {
        return $processedData;
    }

    private function getSalesOrders(
        string $from,
        string $to,
        int $category,
        ?string $location,
        bool $backorder
    ): array {
        $fromDate = \DateService::date2sqlStatic($from);
        $toDate = \DateService::date2sqlStatic($to);
        
        $sql = "SELECT sorder.order_no, sorder.debtor_no, sorder.branch_code,
                sorder.customer_ref, sorder.ord_date, sorder.from_stk_loc,
                sorder.delivery_date, sorder.total, line.stk_code,
                item.description, item.units, line.quantity, line.qty_sent
            FROM " . TB_PREF . "sales_orders sorder
            INNER JOIN " . TB_PREF . "sales_order_details line
                ON sorder.order_no = line.order_no
                AND sorder.trans_type = line.trans_type
                AND sorder.trans_type = " . ST_SALESORDER . "
            INNER JOIN " . TB_PREF . "stock_master item
                ON line.stk_code = item.stock_id
            WHERE sorder.ord_date >= :from_date
            AND sorder.ord_date <= :to_date";
        
        $params = ['from_date' => $fromDate, 'to_date' => $toDate];
        
        if ($category > 0) {
            $sql .= " AND item.category_id = :category";
            $params['category'] = $category;
        }
        
        if ($location !== null) {
            $sql .= " AND sorder.from_stk_loc = :location";
            $params['location'] = $location;
        }
        
        if ($backorder) {
            $sql .= " AND line.quantity - line.qty_sent > 0";
        }
        
        $sql .= " ORDER BY sorder.order_no";
        
        return $this->dbal->fetchAll($sql, $params);
    }

    protected function getColumns(ReportConfig $config): array
    {
        return [0, 60, 150, 260, 325, 385, 450, 515];
    }

    protected function getHeaders(ReportConfig $config): array
    {
        return [
            _('Code'),
            _('Description'),
            _('Ordered'),
            _('Delivered'),
            _('Outstanding'),
            '',
            _('Total Amount')
        ];
    }

    protected function getAligns(ReportConfig $config): array
    {
        return ['left', 'left', 'right', 'right', 'right', 'right', 'right'];
    }
}
