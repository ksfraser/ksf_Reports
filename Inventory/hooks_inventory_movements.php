<?php
/**
 * Inventory Movements Report Hook
 * 
 * Provides backward compatibility for rep307.php by delegating to InventoryMovements service.
 * 
 * @package    FrontAccounting
 * @subpackage Reports
 * @since      5.0
 */

use FA\Modules\Reports\Inventory\InventoryMovements;
use FA\Events\EventDispatcher;

if (!function_exists('inventory_movements')) {
    function inventory_movements(): void
    {
        $eventDispatcher = EventDispatcher::getInstance();
        $service = new InventoryMovements($eventDispatcher);
        $service->generate();
    }
}
