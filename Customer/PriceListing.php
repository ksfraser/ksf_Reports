<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Customer;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use Psr\Log\LoggerInterface;

/**
 * Price Listing Report (rep104)
 * 
 * Lists all item prices by category with optional gross profit percentage.
 * Includes both regular items and sales kits.
 */
class PriceListing extends AbstractReportService
{
    public function __construct(DBALInterface $dbal, EventDispatcher $dispatcher, LoggerInterface $logger)
    {
        parent::__construct($dbal, $dispatcher, $logger, 'Price Listing', 'price_listing');
    }

    protected function fetchData(ReportConfig $config): array
    {
        $category = $config->getAdditionalParam('category', 0);
        $salesType = $config->getAdditionalParam('sales_type', 0);
        $currency = $config->getCurrency();
        $showGP = $config->getAdditionalParam('show_gp', false);
        
        $items = $this->fetchItems($category);
        $kits = $this->fetchKits($category);
        
        return [
            'items' => $items,
            'kits' => $kits,
            'sales_type' => $salesType,
            'currency' => $currency,
            'show_gp' => $showGP
        ];
    }

    protected function processData(array $rawData, ReportConfig $config): array
    {
        return $rawData;
    }

    protected function formatData(array $processedData, ReportConfig $config): array
    {
        return $processedData;
    }

    private function fetchItems(int $category): array
    {
        $sql = "SELECT item.stock_id, item.description AS name,
                item.material_cost AS Standardcost, item.category_id,
                item.units, category.description
            FROM " . TB_PREF . "stock_master item,
                " . TB_PREF . "stock_category category
            WHERE item.category_id = category.category_id AND NOT item.inactive";
        
        $params = [];
        if ($category != 0) {
            $sql .= " AND category.category_id = :category";
            $params['category'] = $category;
        }
        
        $sql .= " AND item.mb_flag <> 'F' ORDER BY item.category_id, item.stock_id";
        
        return $this->dbal->fetchAll($sql, $params);
    }

    private function fetchKits(int $category): array
    {
        $sql = "SELECT i.item_code AS kit_code, i.description AS kit_name,
                c.category_id AS cat_id, c.description AS cat_name,
                count(*) > 1 AS kit
            FROM " . TB_PREF . "item_codes i
            LEFT JOIN " . TB_PREF . "stock_category c ON i.category_id = c.category_id
            WHERE !i.is_foreign AND i.item_code != i.stock_id";
        
        $params = [];
        if ($category != 0) {
            $sql .= " AND c.category_id = :category";
            $params['category'] = $category;
        }
        
        $sql .= " GROUP BY i.item_code";
        
        return $this->dbal->fetchAll($sql, $params);
    }

    protected function getColumns(ReportConfig $config): array
    {
        return [0, 100, 360, 385, 450, 515];
    }

    protected function getHeaders(ReportConfig $config): array
    {
        return [
            _('Category/Items'),
            _('Description'),
            _('UOM'),
            _('Price'),
            _('GP %')
        ];
    }

    protected function getAligns(ReportConfig $config): array
    {
        return ['left', 'left', 'left', 'right', 'right'];
    }
}
