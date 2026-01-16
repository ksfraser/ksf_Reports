<?php

declare(strict_types=1);

/**
 * Hooks for Aged Customer Analysis Report (rep102)
 */

use FA\Modules\Reports\Customer\AgedCustomerAnalysis;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Modules\Reports\Base\ParameterExtractor;

function get_aged_customer_analysis_service(): AgedCustomerAnalysis
{
    global $db, $dispatcher, $logger;
    return new AgedCustomerAnalysis($db, $dispatcher, $logger);
}

function generate_aged_customer_analysis(
    string $toDate,
    string $customerId = 'All',
    string $currency = 'All',
    bool $showAll = false,
    bool $summaryOnly = false,
    bool $suppressZeros = false,
    bool $graphics = false,
    string $comments = '',
    string $orientation = 'L',
    bool $exportToExcel = false
): array {
    $service = get_aged_customer_analysis_service();
    
    $config = new ReportConfig(
        $toDate, // from
        $toDate, // to (same for aged analysis)
        0, 0,
        $exportToExcel,
        $orientation,
        0,
        $currency,
        $suppressZeros,
        [
            'customer_id' => $customerId,
            'show_all' => $showAll,
            'summary_only' => $summaryOnly,
            'graphics' => $graphics,
            'comments' => $comments
        ]
    );
    
    return $service->generate($config);
}

function generate_aged_customer_analysis_from_post(): array
{
    $extractor = new ParameterExtractor();
    
    return generate_aged_customer_analysis(
        $extractor->getString('PARAM_0'),
        $extractor->getString('PARAM_1', 'All'),
        $extractor->getString('PARAM_2', 'All'),
        (bool)$extractor->getInt('PARAM_3', 0),
        (bool)$extractor->getInt('PARAM_4', 0),
        (bool)$extractor->getInt('PARAM_5', 0),
        (bool)$extractor->getInt('PARAM_6', 0),
        $extractor->getString('PARAM_7', ''),
        $extractor->getBool('PARAM_8', true) ? 'L' : 'P',
        (bool)$extractor->getInt('PARAM_9', 0)
    );
}
