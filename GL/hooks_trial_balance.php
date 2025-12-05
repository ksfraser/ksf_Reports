<?php

declare(strict_types=1);

/**
 * Trial Balance Report Hooks
 * 
 * Integration hooks for FrontAccounting's Trial Balance report
 */

use FA\Modules\Reports\GL\TrialBalance;
use FA\Database\DatabaseFactory;
use FA\Events\EventDispatcher;

// Register report on module installation
add_hook('install_module', 'trial_balance_install');

// Add menu item
add_hook('setup_menu', 'trial_balance_add_menu');

/**
 * Install hook - register report
 */
function trial_balance_install(): void
{
    // Trial Balance is report #708 in GL Reports category (RC_GL = 6)
    $reportId = 708;
    $category = 6; // RC_GL
    
    error_log("Trial Balance Report (708) service integration enabled");
}

/**
 * Add menu items
 */
function trial_balance_add_menu(): void
{
    // Menu item already exists in FA core
}

/**
 * Get Trial Balance service instance
 * 
 * @return TrialBalance
 */
function get_trial_balance_service(): TrialBalance
{
    $db = DatabaseFactory::getConnection();
    $dispatcher = EventDispatcher::getInstance();
    $logger = get_logger();
    
    return new TrialBalance($db, $dispatcher, $logger);
}

/**
 * Generate Trial Balance report data
 * 
 * @param string $startDate Period start date
 * @param string $endDate Period end date
 * @param bool $includeZero Include zero balance accounts
 * @param int $dimension Dimension filter
 * @param int $dimension2 Second dimension filter
 * @return array Report data
 */
function generate_trial_balance_report(
    string $startDate,
    string $endDate,
    bool $includeZero = false,
    int $dimension = 0,
    int $dimension2 = 0
): array {
    $service = get_trial_balance_service();
    return $service->generate($startDate, $endDate, $includeZero, $dimension, $dimension2);
}

/**
 * Export Trial Balance to PDF
 * 
 * @param array $data Report data
 * @param string $title Report title
 * @return array Export result
 */
function export_trial_balance_pdf(array $data, string $title = 'Trial Balance'): array
{
    $service = get_trial_balance_service();
    return $service->exportToPDF($data, $title);
}

/**
 * Export Trial Balance to Excel
 * 
 * @param array $data Report data
 * @param string $title Report title
 * @return array Export result
 */
function export_trial_balance_excel(array $data, string $title = 'Trial Balance'): array
{
    $service = get_trial_balance_service();
    return $service->exportToExcel($data, $title);
}
