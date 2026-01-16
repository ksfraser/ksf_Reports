<?php
/**
 * Aged Supplier Analysis Hook
 * 
 * Maintains backward compatibility with legacy rep202.php
 * Maps $_POST parameters to new AgedSupplierAnalysis service
 */

declare(strict_types=1);

use FA\Reports\Supplier\AgedSupplierAnalysis;
use FA\Reports\Base\ParameterExtractor;

function hooks_aged_supplier_analysis(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'to_date' => $extractor->getString('PARAM_0'),
        'supplier' => $extractor->getString('PARAM_1', ALL_TEXT),
        'currency' => $extractor->getString('PARAM_2', ALL_TEXT),
        'show_all' => $extractor->getInt('PARAM_3', 1),
        'summary_only' => $extractor->getInt('PARAM_4', 0),
        'no_zeros' => $extractor->getInt('PARAM_5', 0),
        'graphics' => $extractor->getInt('PARAM_6', 0),
        'comments' => $extractor->getString('PARAM_7', ''),
        'orientation' => $extractor->getInt('PARAM_8', 0),
        'destination' => $extractor->getInt('PARAM_9', 0)
    ];
    
    // Create and execute service
    $service = new AgedSupplierAnalysis($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep202.php') {
    hooks_aged_supplier_analysis();
}
