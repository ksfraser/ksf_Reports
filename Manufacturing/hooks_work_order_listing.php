<?php
/**
 * Work Order Listing Hook
 * 
 * Maintains backward compatibility with legacy rep402.php
 * Maps $_POST parameters to new WorkOrderListing service
 */

declare(strict_types=1);

use FA\Reports\Manufacturing\WorkOrderListing;
use FA\Reports\Base\ParameterExtractor;

function hooks_work_order_listing(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'item' => $extractor->getString('PARAM_0', ''),
        'location' => $extractor->getString('PARAM_1', ''),
        'open_only' => $extractor->getInt('PARAM_2', 0),
        'show_gl' => $extractor->getInt('PARAM_3', 0),
        'comments' => $extractor->getString('PARAM_4', ''),
        'orientation' => $extractor->getInt('PARAM_5', 0),
        'destination' => $extractor->getInt('PARAM_6', 0)
    ];
    
    // Create and execute service
    $service = new WorkOrderListing($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep402.php') {
    hooks_work_order_listing();
}
