<?php

declare(strict_types=1);

use FA\Modules\Reports\GL\ChartOfAccounts;
use FA\Database\DatabaseFactory;
use FA\Events\EventDispatcher;

add_hook('install_module', 'chart_of_accounts_install');

function chart_of_accounts_install(): void
{
    error_log("Chart of Accounts Report (701) service integration enabled");
}

function get_chart_of_accounts_service(): ChartOfAccounts
{
    $db = DatabaseFactory::getConnection();
    $dispatcher = EventDispatcher::getInstance();
    $logger = get_logger();
    
    return new ChartOfAccounts($db, $dispatcher, $logger);
}

function generate_chart_of_accounts(bool $showBalances = false): array
{
    $service = get_chart_of_accounts_service();
    return $service->generate($showBalances);
}

function export_chart_of_accounts_pdf(array $data, string $title = 'Chart of Accounts'): array
{
    $service = get_chart_of_accounts_service();
    return $service->exportToPDF($data, $title);
}

function export_chart_of_accounts_excel(array $data, string $title = 'Chart of Accounts'): array
{
    $service = get_chart_of_accounts_service();
    return $service->exportToExcel($data, $title);
}
