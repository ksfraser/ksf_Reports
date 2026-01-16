<?php
/**
 * Inventory Sales Report Hook
 * 
 * Provides backward compatibility for rep304.php by delegating to InventorySalesReport service.
 * 
 * @package    FrontAccounting
 * @subpackage Reports
 * @since      5.0
 */

use FA\Modules\Reports\Inventory\InventorySalesReport;
use FA\Events\EventDispatcher;

if (!function_exists('print_inventory_sales')) {
    function print_inventory_sales(): void
    {
        $eventDispatcher = EventDispatcher::getInstance();
        $service = new InventorySalesReport($eventDispatcher);
        $service->generate();
    }
}
