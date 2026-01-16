<?php
/**
 * Inventory Planning Hook
 * 
 * Maintains backward compatibility with legacy rep302.php
 * Maps $_POST parameters to new InventoryPlanning service
 */

declare(strict_types=1);

use FA\Reports\Inventory\InventoryPlanning;
use FA\Reports\Base\ParameterExtractor;

function hooks_inventory_planning(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'category' => $extractor->getInt('PARAM_0', ALL_NUMERIC),
        'location' => $extractor->getString('PARAM_1', ALL_TEXT),
        'comments' => $extractor->getString('PARAM_2', ''),
        'orientation' => $extractor->getInt('PARAM_3', 0),
        'destination' => $extractor->getInt('PARAM_4', 0)
    ];
    
    // Create and execute service
    $service = new InventoryPlanning($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep302.php') {
    hooks_inventory_planning();
}
