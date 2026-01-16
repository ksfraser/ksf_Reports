<?php
/**
 * Supplier Details Listing Hook
 * 
 * Maintains backward compatibility with legacy rep205.php
 * Maps $_POST parameters to new SupplierDetailsListing service
 */

declare(strict_types=1);

use FA\Reports\Supplier\SupplierDetailsListing;
use FA\Reports\Base\ParameterExtractor;

function hooks_supplier_details_listing(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from_date' => $extractor->getString('PARAM_0'),
        'more' => $extractor->getString('PARAM_1', ''),
        'less' => $extractor->getString('PARAM_2', ''),
        'comments' => $extractor->getString('PARAM_3', ''),
        'orientation' => $extractor->getInt('PARAM_4', 0),
        'destination' => $extractor->getInt('PARAM_5', 0)
    ];
    
    // Create and execute service
    $service = new SupplierDetailsListing($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep205.php') {
    hooks_supplier_details_listing();
}
