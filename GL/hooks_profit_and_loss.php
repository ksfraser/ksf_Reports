<?php

declare(strict_types=1);

/**
 * Hooks for Profit & Loss Statement (rep707)
 * 
 * Provides factory and helper functions for backward compatibility.
 */

use FA\Modules\Reports\GL\ProfitAndLossStatement;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Modules\Reports\Base\ParameterExtractor;

/**
 * Factory function to get Profit & Loss service
 */
function get_profit_and_loss_service(): ProfitAndLossStatement
{
    global $db, $dispatcher, $logger;
    
    return new ProfitAndLossStatement(
        $db,
        $dispatcher,
        $logger
    );
}

/**
 * Generate Profit & Loss report
 *
 * @param string $fromDate Start date for period
 * @param string $toDate End date for period
 * @param int $compare Comparison type (0=accumulated, 1=prior year, 2=budget)
 * @param int $dimension1 First dimension filter (0 = all)
 * @param int $dimension2 Second dimension filter (0 = all)
 * @param array|int $tags Tag filter (-1 = all)
 * @param int $decimals Number of decimal places (0, 1, or 2)
 * @param bool $graphics Include charts/graphics
 * @param string $comments Report comments
 * @param string $orientation Page orientation ('L' or 'P')
 * @param bool $exportToExcel Export to Excel instead of PDF
 * @return array Generated report data
 */
function generate_profit_and_loss(
    string $fromDate,
    string $toDate,
    int $compare = 0,
    int $dimension1 = 0,
    int $dimension2 = 0,
    $tags = -1,
    int $decimals = 0,
    bool $graphics = false,
    string $comments = '',
    string $orientation = 'L',
    bool $exportToExcel = false
): array {
    $service = get_profit_and_loss_service();
    
    $config = new ReportConfig(
        $fromDate,
        $toDate,
        $dimension1,
        $dimension2,
        $exportToExcel,
        $orientation,
        $decimals,
        null, // currency
        false, // suppressZeros
        [
            'compare' => $compare,
            'tags' => $tags,
            'graphics' => $graphics,
            'comments' => $comments
        ]
    );
    
    return $service->generate($config);
}

/**
 * Generate Profit & Loss from POST parameters
 *
 * @return array Generated report data
 */
function generate_profit_and_loss_from_post(): array
{
    $extractor = new ParameterExtractor();
    $config = $extractor->extractGLReportConfig();
    
    // Extract P&L specific parameters
    $compare = $extractor->getInt('PARAM_2', 0);
    
    // Tags parameter position depends on dimension count
    $dim = \FA\Services\CompanyPrefsService::getUseDimensions();
    if ($dim == 2) {
        $tags = isset($_POST['PARAM_5']) ? $_POST['PARAM_5'] : -1;
        $decimals = $extractor->getInt('PARAM_6', 0);
        $graphics = $extractor->getBool('PARAM_7', false);
        $comments = $extractor->getString('PARAM_8', '');
    } elseif ($dim == 1) {
        $tags = isset($_POST['PARAM_4']) ? $_POST['PARAM_4'] : -1;
        $decimals = $extractor->getInt('PARAM_5', 0);
        $graphics = $extractor->getBool('PARAM_6', false);
        $comments = $extractor->getString('PARAM_7', '');
    } else {
        $tags = isset($_POST['PARAM_3']) ? $_POST['PARAM_3'] : -1;
        $decimals = $extractor->getInt('PARAM_4', 0);
        $graphics = $extractor->getBool('PARAM_5', false);
        $comments = $extractor->getString('PARAM_6', '');
    }
    
    // Create new config with additional params
    $plConfig = new ReportConfig(
        $config->getFromDate(),
        $config->getToDate(),
        $config->getDimension1(),
        $config->getDimension2(),
        $config->shouldExportToExcel(),
        $config->getOrientation(),
        $decimals,
        null, // currency
        false, // suppressZeros
        [
            'compare' => $compare,
            'tags' => $tags,
            'graphics' => $graphics,
            'comments' => $comments
        ]
    );
    
    $service = get_profit_and_loss_service();
    return $service->generate($plConfig);
}

/**
 * Export Profit & Loss to PDF or Excel
 *
 * @param array $data Report data
 * @param ReportConfig $config Report configuration
 * @return void Outputs file to browser
 */
function export_profit_and_loss(array $data, ReportConfig $config): void
{
    $service = get_profit_and_loss_service();
    $service->export($data, 'Profit and Loss Statement', $config);
}
