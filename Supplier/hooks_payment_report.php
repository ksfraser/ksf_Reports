<?php
/**
 * Payment Report Hook
 * 
 * Maintains backward compatibility with legacy rep203.php
 * Maps $_POST parameters to new PaymentReport service
 */

declare(strict_types=1);

use FA\Reports\Supplier\PaymentReport;
use FA\Reports\Base\ParameterExtractor;

function hooks_payment_report(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'to_date' => $extractor->getString('PARAM_0'),
        'supplier' => $extractor->getString('PARAM_1', ALL_TEXT),
        'currency' => $extractor->getString('PARAM_2', ALL_TEXT),
        'no_zeros' => $extractor->getInt('PARAM_3', 0),
        'comments' => $extractor->getString('PARAM_4', ''),
        'orientation' => $extractor->getInt('PARAM_5', 0),
        'destination' => $extractor->getInt('PARAM_6', 0)
    ];
    
    // Create and execute service
    $service = new PaymentReport($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep203.php') {
    hooks_payment_report();
}
