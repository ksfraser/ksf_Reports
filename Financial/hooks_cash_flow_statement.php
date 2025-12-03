<?php
/**
 * Cash Flow Statement Report Hooks
 * 
 * Integration hooks for Cash Flow Statement Report with FrontAccounting
 * 
 * @package FA\Modules\Reports\Financial
 * @author FrontAccounting Development Team
 * @version 1.0.0
 * @since 2025-12-03
 */

declare(strict_types=1);

use FA\Modules\Reports\Financial\CashFlowStatementReport;

/**
 * Install hook - Register report with FA reporting system
 * 
 * @param mixed $reports BoxReports instance
 * @return void
 */
function cash_flow_statement_install($reports): void
{
    if (!defined('RC_GL')) {
        define('RC_GL', 6);
    }

    $reports->addReport(
        RC_GL,
        711,
        _('Cash Flow Statement'),
        [
            _('Start Date') => 'DATEBEGINM',
            _('End Date') => 'DATEENDM',
            _('Compare to Prior Period') => 'YES_NO',
            _('Show Quarterly') => 'YES_NO',
            _('Comments') => 'TEXTBOX',
            _('Orientation') => 'ORIENTATION',
            _('Destination') => 'DESTINATION'
        ]
    );
}

/**
 * Menu hook - Add menu items for cash flow reports
 * 
 * @param mixed $app Application instance
 * @return void
 */
function cash_flow_statement_add_menu($app): void
{
    $app->add_rapp_function(
        1,
        _("Cash Flow &Statement"),
        "modules/Reports/views/cash_flow_statement.php",
        'SA_GLREP',
        MENU_REPORT
    );
}

/**
 * Dashboard hook - Add cash flow widget to dashboard
 * 
 * @return array Dashboard widget configuration
 */
function cash_flow_statement_dashboard_widget(): array
{
    return [
        'title' => _('Cash Flow Summary'),
        'type' => 'chart',
        'size' => 'medium',
        'callback' => 'cash_flow_statement_dashboard_data'
    ];
}

/**
 * Get dashboard data for cash flow widget
 * 
 * @return array Cash flow summary data
 */
function cash_flow_statement_dashboard_data(): array
{
    global $db, $path_to_root;
    
    require_once $path_to_root . '/modules/Reports/bootstrap.php';
    
    $report = \FA\Services\ServiceContainer::getInstance()->get(CashFlowStatementReport::class);
    
    $endDate = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-1 month'));
    
    try {
        $result = $report->generate($startDate, $endDate);
        
        return [
            'operating' => $result['operating_activities']['net_cash_from_operations'] ?? 0,
            'investing' => $result['investing_activities']['net_cash_from_investing'] ?? 0,
            'financing' => $result['financing_activities']['net_cash_from_financing'] ?? 0,
            'net_change' => $result['net_cash_change'] ?? 0,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ];
    } catch (\Exception $e) {
        error_log('Cash Flow Dashboard Error: ' . $e->getMessage());
        return [];
    }
}

// Register hooks
if (function_exists('hook_add')) {
    hook_add('reports_install', 'cash_flow_statement_install');
    hook_add('add_menu_items', 'cash_flow_statement_add_menu');
    hook_add('dashboard_widgets', 'cash_flow_statement_dashboard_widget');
}
