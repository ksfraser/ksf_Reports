<?php
/**
 * GRN Valuation Report Hook
 * 
 * Provides backward compatibility for rep305.php by delegating to GRNValuationReport service.
 * 
 * @package    FrontAccounting
 * @subpackage Reports
 * @since      5.0
 */

use FA\Modules\Reports\Inventory\GRNValuationReport;
use FA\Events\EventDispatcher;

if (!function_exists('print_grn_valuation')) {
    function print_grn_valuation(): void
    {
        $eventDispatcher = EventDispatcher::getInstance();
        $service = new GRNValuationReport($eventDispatcher);
        $service->generate();
    }
}
