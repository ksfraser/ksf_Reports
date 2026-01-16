<?php
/**
 * Inventory Purchasing Report Hook
 * 
 * Provides backward compatibility for rep306.php by delegating to InventoryPurchasingReport service.
 * 
 * @package    FrontAccounting
 * @subpackage Reports
 * @since      5.0
 */

use FA\Modules\Reports\Inventory\InventoryPurchasingReport;
use FA\Events\EventDispatcher;

if (!function_exists('print_inventory_purchase')) {
    function print_inventory_purchase(): void
    {
        $eventDispatcher = EventDispatcher::getInstance();
        $service = new InventoryPurchasingReport($eventDispatcher);
        $service->generate();
    }
}
