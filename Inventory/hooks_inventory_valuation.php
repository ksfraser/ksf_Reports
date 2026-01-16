<?php
/**
 * Inventory Valuation Hook
 * 
 * Maintains backward compatibility with legacy rep301.php
 * Maps $_POST parameters to new InventoryValuation service
 */

declare(strict_types=1);

use FA\Reports\Inventory\InventoryValuation;
use FA\Reports\Base\ParameterExtractor;

function hooks_inventory_valuation(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'category' => $extractor->getInt('PARAM_0', ALL_NUMERIC),
        'location' => $extractor->getString('PARAM_1', ALL_TEXT),
        'date' => $extractor->getString('PARAM_2'),
        'comments' => $extractor->getString('PARAM_3', ''),
        'orientation' => $extractor->getInt('PARAM_4', 0),
        'destination' => $extractor->getInt('PARAM_5', 0)
    ];
    
    // Create and execute service
    $service = new InventoryValuation($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep301.php') {
    hooks_inventory_valuation();
}
