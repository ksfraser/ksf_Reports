<?php
declare(strict_types=1);

use FA\Modules\Reports\Customer\OrderStatusListing;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Modules\Reports\Base\ParameterExtractor;

function get_order_status_listing_service(): OrderStatusListing
{
    global $db, $dispatcher, $logger;
    return new OrderStatusListing($db, $dispatcher, $logger);
}

function generate_order_status_listing_from_post(): array
{
    $extractor = new ParameterExtractor();
    $service = get_order_status_listing_service();
    
    $location = $extractor->getString('PARAM_3', 'All');
    
    $config = new ReportConfig(
        $extractor->getString('PARAM_0'),
        $extractor->getString('PARAM_1'),
        0, 0,
        (bool)$extractor->getInt('PARAM_7', 0),
        $extractor->getBool('PARAM_6', true) ? 'L' : 'P',
        0, null, false,
        [
            'category' => $extractor->getInt('PARAM_2', 0),
            'location' => $location === 'All' ? null : $location,
            'backorder' => (bool)$extractor->getInt('PARAM_4', 0),
            'comments' => $extractor->getString('PARAM_5', '')
        ]
    );
    
    return $service->generate($config);
}
