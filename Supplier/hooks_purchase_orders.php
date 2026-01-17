<?php
/**
 * Purchase Orders Document Printer Hook
 * 
 * Maintains backward compatibility with legacy rep209.php
 * Maps $_POST parameters to new PurchaseOrders service
 */

declare(strict_types=1);

use FA\Reports\Supplier\PurchaseOrders;
use FA\Reports\Base\ParameterExtractor;

function hooks_purchase_orders(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from' => $extractor->getInt('PARAM_0'),
        'to' => $extractor->getInt('PARAM_1'),
        'currency' => $extractor->getString('PARAM_2'),
        'email' => $extractor->getInt('PARAM_3', 0),
        'comments' => $extractor->getString('PARAM_4', ''),
        'orientation' => $extractor->getInt('PARAM_5', 0)
    ];
    
    // Create and execute service
    $service = new PurchaseOrders($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep209.php') {
    hooks_purchase_orders();
}
