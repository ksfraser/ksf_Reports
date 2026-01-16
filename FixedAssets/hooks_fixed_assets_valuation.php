<?php
/**
 * Fixed Assets Valuation Hook
 * 
 * Maintains backward compatibility with legacy rep451.php
 * Maps $_POST parameters to new FixedAssetsValuation service
 */

declare(strict_types=1);

use FA\Reports\FixedAssets\FixedAssetsValuation;
use FA\Reports\Base\ParameterExtractor;

function hooks_fixed_assets_valuation(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'date' => $extractor->getString('PARAM_0'),
        'class' => $extractor->getInt('PARAM_1', 0),
        'location' => $extractor->getString('PARAM_2', ''),
        'detail' => $extractor->getInt('PARAM_3', 0),
        'comments' => $extractor->getString('PARAM_4', ''),
        'orientation' => $extractor->getInt('PARAM_5', 0),
        'destination' => $extractor->getInt('PARAM_6', 0)
    ];
    
    // Create and execute service
    $service = new FixedAssetsValuation($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep451.php') {
    hooks_fixed_assets_valuation();
}
