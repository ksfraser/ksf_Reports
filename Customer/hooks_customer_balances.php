<?php

declare(strict_types=1);

/**
 * Hooks for Customer Balances Report (rep101)
 * 
 * Provides factory and helper functions for backward compatibility.
 */

use FA\Modules\Reports\Customer\CustomerBalances;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Modules\Reports\Base\ParameterExtractor;

/**
 * Factory function to get Customer Balances service
 */
function get_customer_balances_service(): CustomerBalances
{
    global $db, $dispatcher, $logger;
    
    return new CustomerBalances(
        $db,
        $dispatcher,
        $logger
    );
}

/**
 * Generate Customer Balances report
 *
 * @param string $fromDate Start date
 * @param string $toDate End date
 * @param string $customerId Customer ID or 'All'
 * @param bool $showBalance Show running balance instead of outstanding
 * @param string $currency Currency code or 'All' for home currency conversion
 * @param bool $suppressZeros Suppress zero balances
 * @param string $comments Report comments
 * @param string $orientation Page orientation ('L' or 'P')
 * @param bool $exportToExcel Export to Excel instead of PDF
 * @return array Generated report data
 */
function generate_customer_balances(
    string $fromDate,
    string $toDate,
    string $customerId = 'All',
    bool $showBalance = false,
    string $currency = 'All',
    bool $suppressZeros = false,
    string $comments = '',
    string $orientation = 'L',
    bool $exportToExcel = false
): array {
    $service = get_customer_balances_service();
    
    $config = new ReportConfig(
        $fromDate,
        $toDate,
        0, // no dimensions
        0,
        $exportToExcel,
        $orientation,
        0, // decimals from prefs
        $currency,
        $suppressZeros,
        [
            'customer_id' => $customerId,
            'show_balance' => $showBalance,
            'comments' => $comments
        ]
    );
    
    return $service->generate($config);
}

/**
 * Generate Customer Balances from POST parameters
 *
 * @return array Generated report data
 */
function generate_customer_balances_from_post(): array
{
    $extractor = new ParameterExtractor();
    
    $fromDate = $extractor->getString('PARAM_0');
    $toDate = $extractor->getString('PARAM_1');
    $customerId = $extractor->getString('PARAM_2', 'All');
    $showBalance = (bool)$extractor->getInt('PARAM_3', 0);
    $currency = $extractor->getString('PARAM_4', 'All');
    $suppressZeros = (bool)$extractor->getInt('PARAM_5', 0);
    $comments = $extractor->getString('PARAM_6', '');
    $orientation = $extractor->getBool('PARAM_7', true) ? 'L' : 'P';
    $exportToExcel = (bool)$extractor->getInt('PARAM_8', 0);
    
    return generate_customer_balances(
        $fromDate,
        $toDate,
        $customerId,
        $showBalance,
        $currency,
        $suppressZeros,
        $comments,
        $orientation,
        $exportToExcel
    );
}

/**
 * Export Customer Balances to PDF or Excel
 *
 * @param array $data Report data
 * @param ReportConfig $config Report configuration
 * @return void Outputs file to browser
 */
function export_customer_balances(array $data, ReportConfig $config): void
{
    $service = get_customer_balances_service();
    $service->export($data, 'Customer Balances', $config);
}
