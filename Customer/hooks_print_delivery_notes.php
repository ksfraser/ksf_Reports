<?php
/**
 * Print Delivery Notes Hook
 */

declare(strict_types=1);

use FA\Reports\Customer\PrintDeliveryNotes;
use FA\Reports\Base\ParameterExtractor;

function hooks_print_delivery_notes(): void
{
    global $db, $eventDispatcher;
    
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from' => $extractor->getString('PARAM_0'),
        'to' => $extractor->getString('PARAM_1'),
        'email' => $extractor->getInt('PARAM_2', 0),
        'packing_slip' => $extractor->getInt('PARAM_3', 0),
        'comments' => $extractor->getString('PARAM_4', ''),
        'orientation' => $extractor->getInt('PARAM_5', 0)
    ];
    
    $service = new PrintDeliveryNotes($db, $eventDispatcher);
    $service->generate($params);
}

if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep110.php') {
    hooks_print_delivery_notes();
}
