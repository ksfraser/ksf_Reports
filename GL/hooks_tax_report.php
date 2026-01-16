<?php

declare(strict_types=1);

/**
 * Hooks for Tax Report (rep709)
 * 
 * Provides factory and helper functions for backward compatibility.
 */

use FA\Modules\Reports\GL\TaxReport;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Modules\Reports\Base\ParameterExtractor;

/**
 * Factory function to get Tax Report service
 */
function get_tax_report_service(): TaxReport
{
    global $db, $dispatcher, $logger;
    
    return new TaxReport(
        $db,
        $dispatcher,
        $logger
    );
}

/**
 * Generate Tax Report
 *
 * @param string $fromDate Start date
 * @param string $toDate End date
 * @param bool $summaryOnly Show summary only (no detail)
 * @param string $comments Report comments
 * @param string $orientation Page orientation ('L' or 'P')
 * @param bool $exportToExcel Export to Excel instead of PDF
 * @return array Generated report data
 */
function generate_tax_report(
    string $fromDate,
    string $toDate,
    bool $summaryOnly = false,
    string $comments = '',
    string $orientation = 'L',
    bool $exportToExcel = false
): array {
    $service = get_tax_report_service();
    
    $config = new ReportConfig(
        $fromDate,
        $toDate,
        0, // no dimensions
        0,
        $exportToExcel,
        $orientation,
        0, // decimals handled by system prefs
        null, // currency
        false, // suppressZeros
        [
            'summary_only' => $summaryOnly,
            'comments' => $comments
        ]
    );
    
    return $service->generate($config);
}

/**
 * Generate Tax Report from POST parameters
 *
 * @return array Generated report data
 */
function generate_tax_report_from_post(): array
{
    $extractor = new ParameterExtractor();
    
    $fromDate = $extractor->getString('PARAM_0');
    $toDate = $extractor->getString('PARAM_1');
    $summaryOnly = (bool)$extractor->getInt('PARAM_2', 0);
    $comments = $extractor->getString('PARAM_3', '');
    $orientation = $extractor->getBool('PARAM_4', true) ? 'L' : 'P';
    $exportToExcel = (bool)$extractor->getInt('PARAM_5', 0);
    
    return generate_tax_report(
        $fromDate,
        $toDate,
        $summaryOnly,
        $comments,
        $orientation,
        $exportToExcel
    );
}

/**
 * Export Tax Report to PDF or Excel
 *
 * @param array $data Report data
 * @param ReportConfig $config Report configuration
 * @return void Outputs file to browser
 */
function export_tax_report(array $data, ReportConfig $config): void
{
    $service = get_tax_report_service();
    $service->export($data, 'Tax Report', $config);
}

/**
 * Hook called when tax report generation is complete
 * Allows custom extensions to add functionality
 */
function hook_tax_report_done(): void
{
    // Placeholder for extensions
    global $dispatcher;
    
    if (isset($dispatcher)) {
        $dispatcher->dispatch('tax_report.done', []);
    }
}
