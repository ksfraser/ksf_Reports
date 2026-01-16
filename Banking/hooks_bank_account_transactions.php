<?php
/**
 * Bank Account Transactions Hook
 * 
 * Maintains backward compatibility with legacy rep601.php
 * Maps $_POST parameters to new BankAccountTransactions service
 */

declare(strict_types=1);

use FA\Reports\Banking\BankAccountTransactions;
use FA\Reports\Base\ParameterExtractor;

function hooks_bank_account_transactions(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'account' => $extractor->getString('PARAM_0', ALL_TEXT),
        'from_date' => $extractor->getString('PARAM_1'),
        'to_date' => $extractor->getString('PARAM_2'),
        'show_zero' => $extractor->getInt('PARAM_3', 1),
        'comments' => $extractor->getString('PARAM_4', ''),
        'orientation' => $extractor->getInt('PARAM_5', 0),
        'destination' => $extractor->getInt('PARAM_6', 0)
    ];
    
    // Create and execute service
    $service = new BankAccountTransactions($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep601.php') {
    hooks_bank_account_transactions();
}
