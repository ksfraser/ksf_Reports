<?php
/**
 * Supplier Balances Hook
 * 
 * Maintains backward compatibility with legacy rep201.php
 * Maps $_POST parameters to new SupplierBalances service
 */

declare(strict_types=1);

use FA\Reports\Supplier\SupplierBalances;
use FA\Reports\Base\ParameterExtractor;

function hooks_supplier_balances(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from_date' => $extractor->getString('PARAM_0'),
        'to_date' => $extractor->getString('PARAM_1'),
        'from_supp' => $extractor->getString('PARAM_2'),
        'show_balance' => $extractor->getInt('PARAM_3', 0),
        'currency' => $extractor->getString('PARAM_4'),
        'no_zeros' => $extractor->getInt('PARAM_5', 0),
        'comments' => $extractor->getString('PARAM_6', ''),
        'orientation' => $extractor->getInt('PARAM_7', 0),
        'destination' => $extractor->getInt('PARAM_8', 0)
    ];
    
    // Create and execute service
    $service = new SupplierBalances($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep201.php') {
    hooks_supplier_balances();
}
