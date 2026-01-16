<?php
/**
 * Customer Trial Balance Hook
 * 
 * Maintains backward compatibility with legacy rep115.php
 * Maps $_POST parameters to new CustomerTrialBalance service
 */

declare(strict_types=1);

use FA\Reports\Customer\CustomerTrialBalance;
use FA\Reports\Base\ParameterExtractor;

function hooks_customer_trial_balance(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from_date' => $extractor->getString('PARAM_0'),
        'to_date' => $extractor->getString('PARAM_1'),
        'customer' => $extractor->getString('PARAM_2', ALL_TEXT),
        'area' => $extractor->getInt('PARAM_3', ALL_NUMERIC),
        'sales_person' => $extractor->getInt('PARAM_4', ALL_NUMERIC),
        'currency' => $extractor->getString('PARAM_5', ALL_TEXT),
        'no_zeros' => $extractor->getInt('PARAM_6', 0),
        'comments' => $extractor->getString('PARAM_7', ''),
        'orientation' => $extractor->getInt('PARAM_8', 0),
        'destination' => $extractor->getInt('PARAM_9', 0)
    ];
    
    // Create and execute service
    $service = new CustomerTrialBalance($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep115.php') {
    hooks_customer_trial_balance();
}
