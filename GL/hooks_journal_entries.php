<?php

declare(strict_types=1);

/**
 * Journal Entries Report Hooks
 * 
 * Integration hooks for FrontAccounting's Journal Entries report
 */

use FA\Modules\Reports\GL\JournalEntries;
use FA\Database\DatabaseFactory;
use FA\Events\EventDispatcher;

// Register report on module installation
add_hook('install_module', 'journal_entries_install');

// Add menu item
add_hook('setup_menu', 'journal_entries_add_menu');

/**
 * Install hook - register report
 */
function journal_entries_install(): void
{
    // Journal Entries is report #702 in GL Reports category (RC_GL = 6)
    $reportId = 702;
    $category = 6; // RC_GL
    
    // Report is already built into FA, this hook provides service integration
    error_log("Journal Entries Report (702) service integration enabled");
}

/**
 * Add menu items
 */
function journal_entries_add_menu(): void
{
    // Menu item already exists in FA core
    // This hook can be used for additional menu customization if needed
}

/**
 * Get Journal Entries service instance
 * 
 * Factory function for creating JournalEntries service
 * 
 * @return JournalEntries
 */
function get_journal_entries_service(): JournalEntries
{
    $db = DatabaseFactory::getConnection();
    $dispatcher = EventDispatcher::getInstance();
    $logger = get_logger();
    
    return new JournalEntries($db, $dispatcher, $logger);
}

/**
 * Generate Journal Entries report data
 * 
 * Wrapper function for use in legacy code
 * 
 * @param string $startDate Start date (Y-m-d)
 * @param string $endDate End date (Y-m-d)
 * @param int|null $systemType System type filter (null for all)
 * @return array Report data
 */
function generate_journal_entries_report(string $startDate, string $endDate, ?int $systemType = null): array
{
    $service = get_journal_entries_service();
    return $service->generate($startDate, $endDate, $systemType);
}

/**
 * Export Journal Entries to PDF
 * 
 * @param array $data Report data
 * @param string $title Report title
 * @return array Export result
 */
function export_journal_entries_pdf(array $data, string $title = 'Journal Entries'): array
{
    $service = get_journal_entries_service();
    return $service->exportToPDF($data, $title);
}

/**
 * Export Journal Entries to Excel
 * 
 * @param array $data Report data
 * @param string $title Report title
 * @return array Export result
 */
function export_journal_entries_excel(array $data, string $title = 'Journal Entries'): array
{
    $service = get_journal_entries_service();
    return $service->exportToExcel($data, $title);
}
