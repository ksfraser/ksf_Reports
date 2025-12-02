<?php

namespace FA\Modules\Reports\Inventory;

use FA\Core\DBALInterface;

class StockStatusReport
{
    private DBALInterface $db;

    public function __construct(DBALInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Generate Stock Status Report
     */
    public function generate(array $parameters, int $page = 1, int $perPage = 100): array
    {
        $locationId = $parameters['location_id'] ?? null;
        $category = $parameters['category'] ?? null;
        $showZeroStock = $parameters['show_zero_stock'] ?? false;

        // Build query
        $sql = "SELECT 
                    si.stock_id,
                    si.description,
                    si.long_description,
                    sc.description as category_name,
                    si.units,
                    si.mb_flag,
                    loc.location_name,
                    sm.qty as quantity_on_hand,
                    si.reorder_level,
                    si.material_cost,
                    si.labour_cost,
                    si.overhead_cost,
                    (si.material_cost + si.labour_cost + si.overhead_cost) as unit_cost,
                    (sm.qty * (si.material_cost + si.labour_cost + si.overhead_cost)) as stock_value,
                    CASE 
                        WHEN sm.qty <= 0 THEN 'out_of_stock'
                        WHEN sm.qty <= si.reorder_level THEN 'reorder'
                        ELSE 'in_stock'
                    END as stock_status
                FROM stock_master si
                LEFT JOIN stock_category sc ON si.category_id = sc.category_id
                LEFT JOIN stock_moves sm ON si.stock_id = sm.stock_id
                LEFT JOIN locations loc ON sm.loc_code = loc.loc_code
                WHERE 1=1";

        $params = [];

        if ($locationId !== null) {
            $sql .= " AND sm.loc_code = ?";
            $params[] = $locationId;
        }

        if ($category !== null) {
            $sql .= " AND sc.category_id = ?";
            $params[] = $category;
        }

        if (!$showZeroStock) {
            $sql .= " AND sm.qty > 0";
        }

        $sql .= " GROUP BY si.stock_id, loc.loc_code
                  ORDER BY si.stock_id, loc.location_name";

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
                        COUNT(DISTINCT stock_id) as total_items,
                        SUM(quantity_on_hand) as total_quantity,
                        SUM(stock_value) as total_value,
                        SUM(CASE WHEN stock_status = 'out_of_stock' THEN 1 ELSE 0 END) as out_of_stock_count,
                        SUM(CASE WHEN stock_status = 'reorder' THEN 1 ELSE 0 END) as reorder_count
                      FROM ({$sql}) as summary";
        $summaryResult = $this->db->query($summarySql, array_slice($params, 0, -2));

        return [
            'data' => $results,
            'columns' => [
                ['field' => 'stock_id', 'label' => 'Item Code', 'type' => 'string'],
                ['field' => 'description', 'label' => 'Description', 'type' => 'string'],
                ['field' => 'category_name', 'label' => 'Category', 'type' => 'string'],
                ['field' => 'location_name', 'label' => 'Location', 'type' => 'string'],
                ['field' => 'units', 'label' => 'UOM', 'type' => 'string'],
                ['field' => 'quantity_on_hand', 'label' => 'Qty On Hand', 'type' => 'decimal'],
                ['field' => 'reorder_level', 'label' => 'Reorder Level', 'type' => 'decimal'],
                ['field' => 'unit_cost', 'label' => 'Unit Cost', 'type' => 'currency'],
                ['field' => 'stock_value', 'label' => 'Stock Value', 'type' => 'currency'],
                ['field' => 'stock_status', 'label' => 'Status', 'type' => 'string'],
            ],
            'total_rows' => $totalRows,
            'summary' => [
                'Total Items' => $summaryResult[0]['total_items'] ?? 0,
                'Total Quantity' => number_format($summaryResult[0]['total_quantity'] ?? 0, 2),
                'Total Value' => number_format($summaryResult[0]['total_value'] ?? 0, 2),
                'Out of Stock' => $summaryResult[0]['out_of_stock_count'] ?? 0,
                'Reorder Required' => $summaryResult[0]['reorder_count'] ?? 0,
            ]
        ];
    }
}
