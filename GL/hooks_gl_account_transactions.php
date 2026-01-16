<?php

declare(strict_types=1);

/**
 * Hook functions for GL Account Transactions report (rep704)
 * 
 * Provides factory and helper functions for integrating the new
 * GLAccountTransactions service with legacy code.
 */

use FA\Database\DatabaseConnection;
use FA\Events\EventDispatcher;
use FA\Modules\Reports\Base\ParameterExtractor;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Modules\Reports\GL\GLAccountTransactions;
use Psr\Log\NullLogger;

/**
 * Factory function to create GLAccountTransactions service
 *
 * @return GLAccountTransactions
 */
function get_gl_account_transactions_service(): GLAccountTransactions
{
    static $service = null;
    
    if ($service === null) {
        $dbal = DatabaseConnection::getInstance()->getDbal();
        $dispatcher = EventDispatcher::getInstance();
        $logger = new NullLogger();
        
        $service = new GLAccountTransactions($dbal, $dispatcher, $logger);
    }
    
    return $service;
}

/**
 * Generate GL account transactions report
 *
 * @param string $fromAccount Starting account code
 * @param string $toAccount Ending account code
 * @param string $fromDate Start date
 * @param string $toDate End date
 * @param int $dimension1 First dimension filter (0 = all)
 * @param int $dimension2 Second dimension filter (0 = all)
 * @param string $comments Report comments
 * @param bool $landscapeOrientation True for landscape, false for portrait
 * @param bool $exportToExcel True for Excel, false for PDF
 * @return array Report data
 */
function generate_gl_account_transactions(
    string $fromAccount,
    string $toAccount,
    string $fromDate,
    string $toDate,
    int $dimension1 = 0,
    int $dimension2 = 0,
    string $comments = '',
    bool $landscapeOrientation = false,
    bool $exportToExcel = false
): array {
    $service = get_gl_account_transactions_service();
    
    $config = new ReportConfig(
        fromDate: $fromDate,
        toDate: $toDate,
        dimension1: $dimension1,
        dimension2: $dimension2,
        exportToExcel: $exportToExcel,
        landscapeOrientation: $landscapeOrientation,
        decimals: \FA\UserPrefsCache::getPriceDecimals(),
        pageSize: function_exists('user_pagesize') ? user_pagesize() : 'A4',
        comments: $comments
    );
    
    return $service->generateForAccounts($fromAccount, $toAccount, $config);
}

/**
 * Generate GL account transactions report from $_POST parameters
 *
 * @return array Report data
 */
function generate_gl_account_transactions_from_post(): array
{
    $extractor = ParameterExtractor::fromPost();
    
    $fromDate = $extractor->getString('PARAM_0');
    $toDate = $extractor->getString('PARAM_1');
    $fromAccount = $extractor->getString('PARAM_2');
    $toAccount = $extractor->getString('PARAM_3');
    
    $dimCount = \FA\Services\CompanyPrefsService::getUseDimensions();
    $paramIndex = 4;
    
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
    
    $comments = $extractor->getString("PARAM_$paramIndex", '');
    $paramIndex++;
    $landscapeOrientation = $extractor->getBool("PARAM_$paramIndex", false);
    $paramIndex++;
    $exportToExcel = $extractor->getBool("PARAM_$paramIndex", false);
    
    return generate_gl_account_transactions(
        $fromAccount,
        $toAccount,
        $fromDate,
        $toDate,
        $dimension1,
        $dimension2,
        $comments,
        $landscapeOrientation,
        $exportToExcel
    );
}

/**
 * Export GL account transactions to PDF
 *
 * @param array $data Report data
 * @param string $title Report title
 * @return array Export result with success, format, filename keys
 */
function export_gl_account_transactions_pdf(array $data, string $title): array
{
    $service = get_gl_account_transactions_service();
    
    $config = new ReportConfig(
        fromDate: date('Y-m-d'),
        toDate: date('Y-m-d'),
        exportToExcel: false
    );
    
    return $service->export($data, $title, $config);
}

/**
 * Export GL account transactions to Excel
 *
 * @param array $data Report data
 * @param string $title Report title
 * @return array Export result with success, format, filename keys
 */
function export_gl_account_transactions_excel(array $data, string $title): array
{
    $service = get_gl_account_transactions_service();
    
    $config = new ReportConfig(
        fromDate: date('Y-m-d'),
        toDate: date('Y-m-d'),
        exportToExcel: true
    );
    
    return $service->export($data, $title, $config);
}
