<?php
/**
 * Print Invoices Hook
 * 
 * Maintains backward compatibility with legacy rep107.php
 */

declare(strict_types=1);

use FA\Reports\Customer\PrintInvoices;
use FA\Reports\Base\ParameterExtractor;

function hooks_print_invoices(): void
{
    global $db, $eventDispatcher;
    
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from' => $extractor->getString('PARAM_0'),
        'to' => $extractor->getString('PARAM_1'),
        'currency' => $extractor->getString('PARAM_2'),
        'email' => $extractor->getInt('PARAM_3', 0),
        'pay_service' => $extractor->getString('PARAM_4'),
        'comments' => $extractor->getString('PARAM_5', ''),
        'customer' => $extractor->getString('PARAM_6', null),
        'orientation' => $extractor->getInt('PARAM_7', 0)
    ];
    
    $service = new PrintInvoices($db, $eventDispatcher);
    $service->generate($params);
}

if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep107.php') {
    hooks_print_invoices();
}
