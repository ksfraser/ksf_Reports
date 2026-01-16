<?php
/**
 * Item Sales Summary Report Hook
 * 
 * Provides backward compatibility for rep309.php by delegating to ItemSalesSummaryReport service.
 * 
 * @package    FrontAccounting
 * @subpackage Reports
 * @since      5.0
 */

use FA\Modules\Reports\Inventory\ItemSalesSummaryReport;
use FA\Events\EventDispatcher;

if (!function_exists('print_inventory_sales')) {
    function print_inventory_sales(): void
    {
        $eventDispatcher = EventDispatcher::getInstance();
        $service = new ItemSalesSummaryReport($eventDispatcher);
        $service->generate();
    }
}
