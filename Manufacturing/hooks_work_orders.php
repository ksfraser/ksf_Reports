<?php
/**
 * Work Orders Document Printer Hook
 * 
 * Maintains backward compatibility with legacy rep409.php
 * Maps $_POST parameters to new WorkOrders service
 */

declare(strict_types=1);

use FA\Reports\Manufacturing\WorkOrders;
use FA\Reports\Base\ParameterExtractor;

function hooks_work_orders(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from' => $extractor->getString('PARAM_0'),
        'to' => $extractor->getString('PARAM_1'),
        'email' => $extractor->getInt('PARAM_2', 0),
        'comments' => $extractor->getString('PARAM_3', ''),
        'orientation' => $extractor->getInt('PARAM_4', 0)
    ];
    
    // Create and execute service
    $service = new WorkOrders($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep409.php') {
    hooks_work_orders();
}
