<?php
/**
 * Outstanding GRNs Report Hook
 * 
 * Maintains backward compatibility with legacy rep204.php
 * Maps $_POST parameters to new OutstandingGRNs service
 */

declare(strict_types=1);

use FA\Reports\Supplier\OutstandingGRNs;
use FA\Reports\Base\ParameterExtractor;

function hooks_outstanding_grns(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'supplier' => $extractor->getString('PARAM_0', ALL_TEXT),
        'comments' => $extractor->getString('PARAM_1', ''),
        'orientation' => $extractor->getInt('PARAM_2', 0),
        'destination' => $extractor->getInt('PARAM_3', 0)
    ];
    
    // Create and execute service
    $service = new OutstandingGRNs($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep204.php') {
    hooks_outstanding_grns();
}
