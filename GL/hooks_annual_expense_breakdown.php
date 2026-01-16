<?php

declare(strict_types=1);

/**
 * Hook functions for Annual Expense Breakdown report (rep705)
 */

use FA\Database\DatabaseConnection;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\ParameterExtractor;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Modules\Reports\GL\AnnualExpenseBreakdown;
use Psr\Log\NullLogger;

/**
 * Factory function to create AnnualExpenseBreakdown service
 */
function get_annual_expense_breakdown_service(): AnnualExpenseBreakdown
{
    static $service = null;
    
    if ($service === null) {
        $dbal = DatabaseConnection::getInstance()->getDbal();
        $dispatcher = EventDispatcher::getInstance();
        $logger = new NullLogger();
        
        $service = new AnnualExpenseBreakdown($dbal, $dispatcher, $logger);
    }
    
    return $service;
}

/**
 * Generate annual expense breakdown report
 */
function generate_annual_expense_breakdown(
    int $fiscalYearId,
    int $dimension1 = 0,
    int $dimension2 = 0,
    $tags = -1,
    bool $inThousands = false,
    string $comments = '',
    bool $landscapeOrientation = true,
    bool $exportToExcel = false
): array {
    $service = get_annual_expense_breakdown_service();
    
    $config = new ReportConfig(
        fromDate: '', // Determined by fiscal year
        toDate: '',
        dimension1: $dimension1,
        dimension2: $dimension2,
        exportToExcel: $exportToExcel,
        landscapeOrientation: $landscapeOrientation,
        decimals: $inThousands ? 1 : 2,
        pageSize: function_exists('user_pagesize') ? user_pagesize() : 'A4',
        comments: $comments,
        additionalParams: [
            'tags' => $tags,
            'in_thousands' => $inThousands
        ]
    );
    
    return $service->generateForFiscalYear($fiscalYearId, $config);
}

/**
 * Generate annual expense breakdown from $_POST
 */
function generate_annual_expense_breakdown_from_post(): array
{
    $extractor = ParameterExtractor::fromPost();
    $dimCount = \FA\Services\CompanyPrefsService::getUseDimensions();
    
    $fiscalYearId = $extractor->getInt('PARAM_0');
    
    $paramIndex = 1;
    $dimension1 = 0;
    $dimension2 = 0;
    
    if ($dimCount >= 1) {
        $dimension1 = $extractor->getInt("PARAM_$paramIndex", 0);
        $paramIndex++;
    }
    if ($dimCount >= 2) {
        $dimension2 = $extractor->getInt("PARAM_$paramIndex", 0);
        $paramIndex++;
    }
    
    $tags = $extractor->getInt("PARAM_$paramIndex", -1);
    if (isset($_POST["PARAM_$paramIndex"]) && is_array($_POST["PARAM_$paramIndex"])) {
        $tags = $_POST["PARAM_$paramIndex"];
    }
    $paramIndex++;
    
    $comments = $extractor->getString("PARAM_$paramIndex", '');
    $paramIndex++;
    $landscapeOrientation = $extractor->getBool("PARAM_$paramIndex", true);
    $paramIndex++;
    $inThousands = $extractor->getBool("PARAM_$paramIndex", false);
    $paramIndex++;
    $exportToExcel = $extractor->getBool("PARAM_$paramIndex", false);
    
    return generate_annual_expense_breakdown(
        $fiscalYearId,
        $dimension1,
        $dimension2,
        $tags,
        $inThousands,
        $comments,
        $landscapeOrientation,
        $exportToExcel
    );
}

/**
 * Export to PDF
 */
function export_annual_expense_breakdown_pdf(array $data, string $title): array
{
    $service = get_annual_expense_breakdown_service();
    
    $config = new ReportConfig(
        fromDate: date('Y-m-d'),
        toDate: date('Y-m-d'),
        exportToExcel: false,
        landscapeOrientation: true
    );
    
    return $service->export($data, $title, $config);
}

/**
 * Export to Excel
 */
function export_annual_expense_breakdown_excel(array $data, string $title): array
{
    $service = get_annual_expense_breakdown_service();
    
    $config = new ReportConfig(
        fromDate: date('Y-m-d'),
        toDate: date('Y-m-d'),
        exportToExcel: true,
        landscapeOrientation: true
    );
    
    return $service->export($data, $title, $config);
}
