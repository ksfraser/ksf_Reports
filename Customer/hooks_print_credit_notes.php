<?php
/**
 * Print Credit Notes Hook
 */

declare(strict_types=1);

use FA\Reports\Customer\PrintCreditNotes;
use FA\Reports\Base\ParameterExtractor;

function hooks_print_credit_notes(): void
{
    global $db, $eventDispatcher;
    
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'from' => $extractor->getString('PARAM_0'),
        'to' => $extractor->getString('PARAM_1'),
        'currency' => $extractor->getString('PARAM_2'),
        'email' => $extractor->getInt('PARAM_3', 0),
        'paylink' => $extractor->getInt('PARAM_4', 0),
        'comments' => $extractor->getString('PARAM_5', ''),
        'orientation' => $extractor->getInt('PARAM_6', 0)
    ];
    
    $service = new PrintCreditNotes($db, $eventDispatcher);
    $service->generate($params);
}

if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep113.php') {
    hooks_print_credit_notes();
}
