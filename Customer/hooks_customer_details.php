<?php
declare(strict_types=1);

use FA\Modules\Reports\Customer\CustomerDetailsListing;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Modules\Reports\Base\ParameterExtractor;

function get_customer_details_listing_service(): CustomerDetailsListing
{
    global $db, $dispatcher, $logger;
    return new CustomerDetailsListing($db, $dispatcher, $logger);
}

function generate_customer_details_listing_from_post(): array
{
    $extractor = new ParameterExtractor();
    $service = get_customer_details_listing_service();
    
    $config = new ReportConfig(
        $extractor->getString('PARAM_0'),
        $extractor->getString('PARAM_0'),
        0, 0,
        (bool)$extractor->getInt('PARAM_7', 0),
        $extractor->getBool('PARAM_6', true) ? 'L' : 'P',
        0, null, false,
        [
            'area' => $extractor->getInt('PARAM_1', 0),
            'sales_person' => $extractor->getInt('PARAM_2', 0),
            'min_turnover' => (float)$extractor->getString('PARAM_3', '0'),
            'max_turnover' => (float)$extractor->getString('PARAM_4', '0'),
            'comments' => $extractor->getString('PARAM_5', '')
        ]
    );
    
    return $service->generate($config);
}
