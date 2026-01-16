<?php
/**
 * Bill of Material Hook
 * 
 * Maintains backward compatibility with legacy rep401.php
 * Maps $_POST parameters to new BillOfMaterial service
 */

declare(strict_types=1);

use FA\Reports\Manufacturing\BillOfMaterial;
use FA\Reports\Base\ParameterExtractor;

function hooks_bill_of_material(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from_part' => $extractor->getString('PARAM_0'),
        'to_part' => $extractor->getString('PARAM_1'),
        'comments' => $extractor->getString('PARAM_2', ''),
        'orientation' => $extractor->getInt('PARAM_3', 0),
        'destination' => $extractor->getInt('PARAM_4', 0)
    ];
    
    // Create and execute service
    $service = new BillOfMaterial($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep401.php') {
    hooks_bill_of_material();
}
