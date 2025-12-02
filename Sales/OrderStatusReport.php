<?php

namespace FA\Modules\Reports\Sales;

use FA\Core\DBALInterface;

class OrderStatusReport
{
    private DBALInterface $db;

    public function __construct(DBALInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Generate Sales Order Status Report
     */
    public function generate(array $parameters, int $page = 1, int $perPage = 100): array
    {
        $dateFrom = $parameters['date_from'];
        $dateTo = $parameters['date_to'];
        $status = $parameters['status'] ?? null;
        $customerId = $parameters['customer_id'] ?? null;

        // Build query
        $sql = "SELECT 
                    so.order_no,
                    so.reference,
                    so.customer_ref,
                    so.ord_date,
                    so.delivery_date,
                    so.deliver_to,
                    so.delivery_address,
                    c.name as customer_name,
                    c.curr_code,
                    bt.name as branch_name,
                    st.name as sales_type,
                    so.freight_cost,
                    sp.salesman_name,
                    CASE 
                        WHEN so.order_no IN (SELECT order_ FROM debtor_trans WHERE type = 13) THEN 'invoiced'
                        WHEN so.order_no IN (SELECT order_ FROM debtor_trans WHERE type = 30) THEN 'delivered'
                        ELSE 'open'
                    END as status,
                    SUM(sol.quantity) as total_quantity,
                    SUM(sol.quantity * sol.unit_price * (1 - sol.discount_percent / 100)) as order_value,
                    COUNT(DISTINCT sol.id) as line_count
                FROM sales_orders so
                INNER JOIN debtors_master c ON so.debtor_no = c.debtor_no
                LEFT JOIN cust_branch bt ON so.branch_code = bt.branch_code AND so.debtor_no = bt.debtor_no
                LEFT JOIN sales_types st ON so.sales_type = st.id
                LEFT JOIN sales_pos sp ON so.sales_person = sp.salesman_code
                LEFT JOIN sales_order_details sol ON so.order_no = sol.order_no
                WHERE so.ord_date BETWEEN ? AND ?";

        $params = [$dateFrom, $dateTo];

        if ($customerId !== null) {
            $sql .= " AND so.debtor_no = ?";
            $params[] = $customerId;
        }

        $sql .= " GROUP BY so.order_no";

        // Add status filter if specified
        if ($status !== null && !empty($status)) {
            $sql = "SELECT * FROM ({$sql}) as orders WHERE status IN (" . 
                   implode(',', array_fill(0, count($status), '?')) . ")";
            $params = array_merge($params, $status);
        }

        $sql .= " ORDER BY so.ord_date DESC, so.order_no DESC";

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM ({$sql}) as count_query";
        $countResult = $this->db->query($countSql, $params);
        $totalRows = (int)$countResult[0]['total'];

        // Add pagination
        $offset = ($page - 1) * $perPage;
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;

        // Execute query
        $results = $this->db->query($sql, $params);

        // Calculate summary
        $summarySql = "SELECT 
                        COUNT(DISTINCT order_no) as total_orders,
                        SUM(order_value) as total_value,
                        SUM(total_quantity) as total_quantity,
                        AVG(order_value) as average_order_value
                     FROM ({$sql}) as summary";
        $summaryResult = $this->db->query($summarySql, array_slice($params, 0, -2)); // Remove pagination params

        return [
            'data' => $results,
            'columns' => [
                ['field' => 'order_no', 'label' => 'Order #', 'type' => 'string'],
                ['field' => 'reference', 'label' => 'Reference', 'type' => 'string'],
                ['field' => 'customer_ref', 'label' => 'Customer Ref', 'type' => 'string'],
                ['field' => 'ord_date', 'label' => 'Order Date', 'type' => 'date'],
                ['field' => 'delivery_date', 'label' => 'Delivery Date', 'type' => 'date'],
                ['field' => 'customer_name', 'label' => 'Customer', 'type' => 'string'],
                ['field' => 'branch_name', 'label' => 'Branch', 'type' => 'string'],
                ['field' => 'sales_type', 'label' => 'Sales Type', 'type' => 'string'],
                ['field' => 'salesman_name', 'label' => 'Salesperson', 'type' => 'string'],
                ['field' => 'status', 'label' => 'Status', 'type' => 'string'],
                ['field' => 'line_count', 'label' => 'Lines', 'type' => 'number'],
                ['field' => 'total_quantity', 'label' => 'Quantity', 'type' => 'number'],
                ['field' => 'order_value', 'label' => 'Order Value', 'type' => 'currency'],
                ['field' => 'freight_cost', 'label' => 'Freight', 'type' => 'currency'],
            ],
            'total_rows' => $totalRows,
            'summary' => [
                'Total Orders' => $summaryResult[0]['total_orders'] ?? 0,
                'Total Value' => number_format($summaryResult[0]['total_value'] ?? 0, 2),
                'Total Quantity' => $summaryResult[0]['total_quantity'] ?? 0,
                'Average Order Value' => number_format($summaryResult[0]['average_order_value'] ?? 0, 2),
            ]
        ];
    }
}
