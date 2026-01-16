<?php
/**
 * Salesman Report Hook
 * 
 * Maintains backward compatibility with legacy rep106.php
 * Maps $_POST parameters to new SalesmanReport service
 */

declare(strict_types=1);

use FA\Reports\Customer\SalesmanReport;
use FA\Reports\Base\ParameterExtractor;

function hooks_salesman_report(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from_date' => $extractor->getString('PARAM_0'),
        'to_date' => $extractor->getString('PARAM_1'),
        'summary' => $extractor->getInt('PARAM_2', 0),
        'comments' => $extractor->getString('PARAM_3', ''),
        'orientation' => $extractor->getInt('PARAM_4', 0),
        'destination' => $extractor->getInt('PARAM_5', 0)
    ];
    
    // Create and execute service
    $service = new SalesmanReport($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep106.php') {
    hooks_salesman_report();
}
