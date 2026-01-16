<?php

declare(strict_types=1);

/**
 * Hooks for Balance Sheet Report (rep706)
 * 
 * Provides factory and helper functions for backward compatibility.
 */

use FA\Modules\Reports\GL\BalanceSheet;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Modules\Reports\Base\ParameterExtractor;

/**
 * Factory function to get Balance Sheet service
 */
function get_balance_sheet_service(): BalanceSheet
{
    global $db, $dispatcher, $logger;
    
    return new BalanceSheet(
        $db,
        $dispatcher,
        $logger
    );
}

/**
 * Generate Balance Sheet report
 *
 * @param string $fromDate Start date for period
 * @param string $toDate End date for period
 * @param int $dimension1 First dimension filter (0 = all)
 * @param int $dimension2 Second dimension filter (0 = all)
 * @param array|int $tags Tag filter (-1 = all)
 * @param int $decimals Number of decimal places (0, 1, or 2)
 * @param bool $graphics Include charts/graphics
 * @param string $orientation Page orientation ('L' or 'P')
 * @param bool $exportToExcel Export to Excel instead of PDF
 * @return array Generated report data
 */
function generate_balance_sheet(
    string $fromDate,
    string $toDate,
    int $dimension1 = 0,
    int $dimension2 = 0,
    $tags = -1,
    int $decimals = 0,
    bool $graphics = false,
    string $orientation = 'L',
    bool $exportToExcel = false
): array {
    $service = get_balance_sheet_service();
    
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
            'tags' => $tags,
            'graphics' => $graphics
        ]
    );
    
    return $service->generate($config);
}

/**
 * Generate Balance Sheet from POST parameters
 *
 * @return array Generated report data
 */
function generate_balance_sheet_from_post(): array
{
    $extractor = new ParameterExtractor();
    $config = $extractor->extractGLReportConfig();
    
    // Add Balance Sheet specific parameters
    $tags = isset($_POST['PARAM_5']) ? $_POST['PARAM_5'] : -1;
    $decimals = $extractor->getInt('PARAM_6', 0);
    $graphics = $extractor->getBool('PARAM_7', false);
    
    // Create new config with additional params
    $balanceSheetConfig = new ReportConfig(
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
            'tags' => $tags,
            'graphics' => $graphics
        ]
    );
    
    $service = get_balance_sheet_service();
    return $service->generate($balanceSheetConfig);
}

/**
 * Export Balance Sheet to PDF or Excel
 *
 * @param array $data Report data
 * @param ReportConfig $config Report configuration
 * @return void Outputs file to browser
 */
function export_balance_sheet(array $data, ReportConfig $config): void
{
    $service = get_balance_sheet_service();
    $service->export($data, 'Balance Sheet', $config);
}
