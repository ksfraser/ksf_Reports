<?php
/**
 * Bank Statement with Reconcile Hook
 * 
 * Maintains backward compatibility with legacy rep602.php
 * Maps $_POST parameters to new BankStatementReconcile service
 */

declare(strict_types=1);

use FA\Reports\Banking\BankStatementReconcile;
use FA\Reports\Base\ParameterExtractor;

function hooks_bank_statement_reconcile(): void
{
    global $db, $eventDispatcher;
    
    // Extract parameters
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'account' => $extractor->getInt('PARAM_0'),
        'from_date' => $extractor->getString('PARAM_1'),
        'to_date' => $extractor->getString('PARAM_2'),
        'comments' => $extractor->getString('PARAM_3', ''),
        'destination' => $extractor->getInt('PARAM_4', 0)
    ];
    
    // Create and execute service
    $service = new BankStatementReconcile($db, $eventDispatcher);
    $service->generate($params);
}

// Auto-execute if called directly from legacy report
if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep602.php') {
    hooks_bank_statement_reconcile();
}
