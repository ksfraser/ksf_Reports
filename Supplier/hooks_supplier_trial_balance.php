<?php
/**
 * Supplier Trial Balance Hook
 * 
 * Maintains backward compatibility with legacy rep206.php
 * Maps $_POST parameters to new SupplierTrialBalance service
 */

declare(strict_types=1);

use FA\Reports\Supplier\SupplierTrialBalance;
use FA\Reports\Base\ParameterExtractor;

function hooks_supplier_trial_balance(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from_date' => $extractor->getString('PARAM_0'),
        'to_date' => $extractor->getString('PARAM_1'),
        'supplier' => $extractor->getString('PARAM_2', ALL_TEXT),
        'currency' => $extractor->getString('PARAM_3', ALL_TEXT),
        'no_zeros' => $extractor->getInt('PARAM_4', 0),
        'comments' => $extractor->getString('PARAM_5', ''),
        'orientation' => $extractor->getInt('PARAM_6', 0),
        'destination' => $extractor->getInt('PARAM_7', 0)
    ];
    
    // Create and execute service
    $service = new SupplierTrialBalance($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep206.php') {
    hooks_supplier_trial_balance();
}
