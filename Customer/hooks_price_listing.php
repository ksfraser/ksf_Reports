<?php
declare(strict_types=1);

use FA\Modules\Reports\Customer\PriceListing;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Modules\Reports\Base\ParameterExtractor;

function get_price_listing_service(): PriceListing
{
    global $db, $dispatcher, $logger;
    return new PriceListing($db, $dispatcher, $logger);
}

function generate_price_listing_from_post(): array
{
    $extractor = new ParameterExtractor();
    $service = get_price_listing_service();
    
    $config = new ReportConfig(
        '', '',
        0, 0,
        (bool)$extractor->getInt('PARAM_7', 0),
        $extractor->getBool('PARAM_6', true) ? 'L' : 'P',
        0,
        $extractor->getString('PARAM_0', 'All'),
        false,
        [
            'category' => $extractor->getInt('PARAM_1', 0),
            'sales_type' => $extractor->getInt('PARAM_2', 0),
            'pictures' => (bool)$extractor->getInt('PARAM_3', 0),
            'show_gp' => (bool)$extractor->getInt('PARAM_4', 0),
            'comments' => $extractor->getString('PARAM_5', '')
        ]
    );
    
    return $service->generate($config);
}
