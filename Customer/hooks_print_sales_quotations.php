<?php
/**
 * Print Sales Quotations Hook (uses PrintSalesOrders with print_as_quote=1)
 */

declare(strict_types=1);

use FA\Reports\Customer\PrintSalesOrders;
use FA\Reports\Base\ParameterExtractor;

function hooks_print_sales_quotations(): void
{
    global $db, $eventDispatcher;
    
    $extractor = new ParameterExtractor($_POST);
    
    // rep111 always has print_as_quote = 1
    $params = [
        'from' => $extractor->getInt('PARAM_0'),
        'to' => $extractor->getInt('PARAM_1'),
        'currency' => $extractor->getString('PARAM_2'),
        'email' => $extractor->getInt('PARAM_3', 0),
        'print_as_quote' => 1, // Always quotation mode
        'comments' => $extractor->getString('PARAM_4', ''),
        'orientation' => $extractor->getInt('PARAM_5', 0)
    ];
    
    $service = new PrintSalesOrders($db, $eventDispatcher);
    $service->generate($params);
}

if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep111.php') {
    hooks_print_sales_quotations();
}
