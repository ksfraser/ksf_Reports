<?php
/**
 * Sales Summary Report Hook
 * 
 * Maintains backward compatibility with legacy rep114.php
 * Maps $_POST parameters to new SalesSummaryReport service
 */

declare(strict_types=1);

use FA\Reports\Customer\SalesSummaryReport;
use FA\Reports\Base\ParameterExtractor;

function hooks_sales_summary_report(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from_date' => $extractor->getString('PARAM_0'),
        'to_date' => $extractor->getString('PARAM_1'),
        'tax_id_only' => $extractor->getInt('PARAM_2', 0),
        'comments' => $extractor->getString('PARAM_3', ''),
        'orientation' => $extractor->getInt('PARAM_4', 0),
        'destination' => $extractor->getInt('PARAM_5', 0)
    ];
    
    // Create and execute service
    $service = new SalesSummaryReport($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep114.php') {
    hooks_sales_summary_report();
}
