<?php
/**
 * Print Sales Orders Hook
 */

declare(strict_types=1);

use FA\Reports\Customer\PrintSalesOrders;
use FA\Reports\Base\ParameterExtractor;

function hooks_print_sales_orders(): void
{
    global $db, $eventDispatcher;
    
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from' => $extractor->getInt('PARAM_0'),
        'to' => $extractor->getInt('PARAM_1'),
        'currency' => $extractor->getString('PARAM_2'),
        'email' => $extractor->getInt('PARAM_3', 0),
        'print_as_quote' => $extractor->getInt('PARAM_4', 0),
        'comments' => $extractor->getString('PARAM_5', ''),
        'orientation' => $extractor->getInt('PARAM_6', 0)
    ];
    
    $service = new PrintSalesOrders($db, $eventDispatcher);
    $service->generate($params);
}

if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep109.php') {
    hooks_print_sales_orders();
}
