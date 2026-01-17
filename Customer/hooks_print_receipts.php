<?php
/**
 * Print Receipts Hook
 */

declare(strict_types=1);

use FA\Reports\Customer\PrintReceipts;
use FA\Reports\Base\ParameterExtractor;

function hooks_print_receipts(): void
{
    global $db, $eventDispatcher;
    
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from' => $extractor->getString('PARAM_0'),
        'to' => $extractor->getString('PARAM_1'),
        'currency' => $extractor->getString('PARAM_2'),
        'email' => $extractor->getInt('PARAM_3', 0),
        'comments' => $extractor->getString('PARAM_4', ''),
        'orientation' => $extractor->getInt('PARAM_5', 0)
    ];
    
    $service = new PrintReceipts($db, $eventDispatcher);
    $service->generate($params);
}

if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep112.php') {
    hooks_print_receipts();
}
