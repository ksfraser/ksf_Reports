<?php
/**
 * Stock Check Sheet Report Hook
 * 
 * Provides backward compatibility for rep303.php by delegating to StockCheckSheet service.
 * 
 * @package    FrontAccounting
 * @subpackage Reports
 * @since      5.0
 */

use FA\Modules\Reports\Inventory\StockCheckSheet;
use FA\Events\EventDispatcher;

if (!function_exists('print_stock_check')) {
    function print_stock_check(): void
    {
        $eventDispatcher = EventDispatcher::getInstance();
        $service = new StockCheckSheet($eventDispatcher);
        $service->generate();
    }
}
