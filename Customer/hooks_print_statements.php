<?php
/**
 * Print Statements Hook
 */

declare(strict_types=1);

use FA\Reports\Customer\PrintStatements;
use FA\Reports\Base\ParameterExtractor;

function hooks_print_statements(): void
{
    global $db, $eventDispatcher;
    
    $extractor = new ParameterExtractor($_POST);
    
    $params = [
        'customer' => $extractor->getString('PARAM_0'),
        'currency' => $extractor->getString('PARAM_1'),
        'show_also_allocated' => $extractor->getInt('PARAM_2', 0),
        'email' => $extractor->getInt('PARAM_3', 0),
        'comments' => $extractor->getString('PARAM_4', ''),
        'orientation' => $extractor->getInt('PARAM_5', 0)
    ];
    
    $service = new PrintStatements($db, $eventDispatcher);
    $service->generate($params);
}

if (basename($_SERVER['SCRIPT_FILENAME']) === 'rep108.php') {
    hooks_print_statements();
}
