<?php

/**
 * Working Capital Analysis Integration Hooks
 * 
 * This file provides integration hooks for the Working Capital Analysis
 * with the FrontAccounting reporting system.
 * 
 * @package FrontAccounting
 * @subpackage Reports
 */

declare(strict_types=1);

use FA\Modules\Reports\Financial\WorkingCapitalAnalysis;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

/**
 * Install/Register the Working Capital Analysis
 */
function working_capital_analysis_install(): void
{
    global $installed_extensions;
    
    $report = [
        'id' => 712,
        'category' => RC_GL,
        'name' => _('Working Capital Analysis'),
        'description' => _('Comprehensive working capital management with liquidity ratios, cash conversion cycle, and efficiency metrics'),
        'class' => 'FA\\Modules\\Reports\\Financial\\WorkingCapitalAnalysis',
        'file' => 'modules/Reports/Financial/WorkingCapitalAnalysis.php',
        'version' => '1.0.0',
        'author' => 'FrontAccounting Development Team',
        'date_added' => '2025-12-04',
        'icon' => 'chart-line'
    ];
    
    if (!isset($installed_extensions['reports'])) {
        $installed_extensions['reports'] = [];
    }
    $installed_extensions['reports']['working_capital'] = $report;
    
    add_menu_item('banking', 'Working Capital Analysis', 'rep712.php', RC_GL, MENU_GL);
}

/**
 * Add menu item for Working Capital Analysis
 */
function working_capital_analysis_add_menu(): void
{
    global $path_to_root;
    
    $menu_entry = [
        'title' => _('Working Capital Analysis'),
        'url' => $path_to_root . '/reporting/rep712.php',
        'access' => 'SA_GLANALYTIC'
    ];
    
    add_menu_item(_('Financial Reports'), $menu_entry);
}

/**
 * Dashboard widget for Working Capital summary
 */
function working_capital_dashboard_widget(): array
{
    return [
        'id' => 'working_capital_summary',
        'title' => _('Working Capital Health'),
        'description' => _('Current liquidity position and efficiency metrics'),
        'content_callback' => 'working_capital_dashboard_data',
        'size' => 'medium',
        'icon' => 'fa-chart-area',
        'refresh_interval' => 3600,
        'order' => 25
    ];
}

/**
 * Generate dashboard widget data
 */
function working_capital_dashboard_data(): array
{
    global $path_to_root;
    
    require_once($path_to_root . '/includes/db/database.inc');
    require_once($path_to_root . '/includes/ui.inc');
    
    try {
        $db = get_db_connection();
        $dispatcher = new EventDispatcher();
        $logger = get_logger();
        
        $report = new WorkingCapitalAnalysis($db, $dispatcher, $logger);
        
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-1 year'));
        
        $result = $report->generate($startDate, $endDate);
        
        return [
            'working_capital' => $result['working_capital'],
            'ratios' => $result['ratios'],
            'metrics' => $result['metrics'],
            'health_status' => $result['health_status'],
            'efficiency_score' => $result['efficiency_score'],
            'top_recommendations' => array_slice($result['recommendations'], 0, 3),
            'period' => $result['period']
        ];
        
    } catch (\Exception $e) {
        error_log('Working Capital dashboard widget error: ' . $e->getMessage());
        return [
            'error' => _('Unable to load working capital data'),
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Render Working Capital dashboard widget HTML
 */
function render_working_capital_widget(array $data): string
{
    if (isset($data['error'])) {
        return '<div class="alert alert-error">' . $data['error'] . '</div>';
    }
    
    $ratios = $data['ratios'];
    $metrics = $data['metrics'];
    $healthStatus = $data['health_status'];
    $efficiencyScore = $data['efficiency_score'];
    
    $html = '<div class="working-capital-widget">';
    
    // Health status banner
    $statusClass = match($healthStatus) {
        'Healthy' => 'success',
        'Caution' => 'warning',
        'Critical' => 'danger',
        default => 'info'
    };
    
    $html .= '<div class="health-banner health-' . $statusClass . '">';
    $html .= '<h3>' . $healthStatus . '</h3>';
    $html .= '<div class="efficiency-score">';
    $html .= _('Efficiency Score') . ': <strong>' . round($efficiencyScore) . '/100</strong>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Key metrics
    $html .= '<div class="metrics-section">';
    $html .= '<div class="metric-card">';
    $html .= '<div class="metric-label">' . _('Working Capital') . '</div>';
    $html .= '<div class="metric-value ' . ($data['working_capital'] > 0 ? 'positive' : 'negative') . '">';
    $html .= '$' . number_format($data['working_capital'], 0);
    $html .= '</div>';
    $html .= '</div>';
    
    $html .= '<div class="metric-card">';
    $html .= '<div class="metric-label">' . _('Current Ratio') . '</div>';
    $html .= '<div class="metric-value ' . ($ratios['current_ratio'] >= 1.5 ? 'positive' : ($ratios['current_ratio'] >= 1.0 ? 'warning' : 'negative')) . '">';
    $html .= number_format($ratios['current_ratio'], 2);
    $html .= '</div>';
    $html .= '<div class="metric-benchmark">Target: 1.5-2.5</div>';
    $html .= '</div>';
    
    $html .= '<div class="metric-card">';
    $html .= '<div class="metric-label">' . _('Quick Ratio') . '</div>';
    $html .= '<div class="metric-value ' . ($ratios['quick_ratio'] >= 1.0 ? 'positive' : 'warning') . '">';
    $html .= number_format($ratios['quick_ratio'], 2);
    $html .= '</div>';
    $html .= '<div class="metric-benchmark">Target: ≥1.0</div>';
    $html .= '</div>';
    
    $html .= '<div class="metric-card">';
    $html .= '<div class="metric-label">' . _('Cash Conversion') . '</div>';
    $html .= '<div class="metric-value ' . ($metrics['cash_conversion_cycle'] <= 45 ? 'positive' : 'warning') . '">';
    $html .= round($metrics['cash_conversion_cycle']) . ' days';
    $html .= '</div>';
    $html .= '<div class="metric-benchmark">Target: 30-45 days</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Efficiency details
    $html .= '<div class="efficiency-details">';
    $html .= '<h5>' . _('Efficiency Metrics') . '</h5>';
    $html .= '<table class="table-compact">';
    $html .= '<tr>';
    $html .= '<td>' . _('Days Sales Outstanding') . ':</td>';
    $html .= '<td class="amount">' . round($metrics['days_sales_outstanding']) . ' days</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td>' . _('Days Inventory Outstanding') . ':</td>';
    $html .= '<td class="amount">' . round($metrics['days_inventory_outstanding']) . ' days</td>';
    $html .= '</tr>';
    $html .= '<tr>';
    $html .= '<td>' . _('Days Payable Outstanding') . ':</td>';
    $html .= '<td class="amount">' . round($metrics['days_payable_outstanding']) . ' days</td>';
    $html .= '</tr>';
    $html .= '<tr class="total-row">';
    $html .= '<td><strong>' . _('Cash Conversion Cycle') . ':</strong></td>';
    $html .= '<td class="amount"><strong>' . round($metrics['cash_conversion_cycle']) . ' days</strong></td>';
    $html .= '</tr>';
    $html .= '</table>';
    $html .= '</div>';
    
    // Top recommendations
    if (!empty($data['top_recommendations'])) {
        $html .= '<div class="recommendations">';
        $html .= '<h5>' . _('Top Recommendations') . '</h5>';
        foreach ($data['top_recommendations'] as $rec) {
            $typeClass = strtolower($rec['type']);
            $icon = match($rec['type']) {
                'Critical' => '🔴',
                'Warning' => '⚠️',
                'Opportunity' => '💡',
                default => 'ℹ️'
            };
            $html .= '<div class="recommendation recommendation-' . $typeClass . '">';
            $html .= '<div class="rec-header">' . $icon . ' <strong>' . $rec['category'] . '</strong></div>';
            $html .= '<div class="rec-text">' . $rec['recommendation'] . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    
    $html .= '<div class="widget-footer">';
    $html .= '<a href="' . $path_to_root . '/reporting/rep712.php" class="btn btn-primary">';
    $html .= _('View Full Analysis') . '</a>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    // Widget styles
    $html .= '<style>
        .working-capital-widget { padding: 10px; }
        .working-capital-widget .health-banner { padding: 15px; border-radius: 6px; text-align: center; margin-bottom: 15px; }
        .working-capital-widget .health-success { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
        .working-capital-widget .health-warning { background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); color: white; }
        .working-capital-widget .health-danger { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; }
        .working-capital-widget .health-banner h3 { margin: 0 0 10px 0; font-size: 1.5em; }
        .working-capital-widget .efficiency-score { font-size: 1.1em; }
        .working-capital-widget .metrics-section { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 15px; }
        .working-capital-widget .metric-card { background: #f8f9fa; padding: 12px; border-radius: 6px; text-align: center; border-left: 4px solid #007bff; }
        .working-capital-widget .metric-label { font-size: 0.85em; color: #6c757d; margin-bottom: 5px; }
        .working-capital-widget .metric-value { font-size: 1.5em; font-weight: bold; margin-bottom: 3px; }
        .working-capital-widget .metric-value.positive { color: #28a745; }
        .working-capital-widget .metric-value.warning { color: #ffc107; }
        .working-capital-widget .metric-value.negative { color: #dc3545; }
        .working-capital-widget .metric-benchmark { font-size: 0.75em; color: #6c757d; }
        .working-capital-widget .efficiency-details { background: #fff; padding: 10px; border-radius: 6px; margin-bottom: 15px; border: 1px solid #dee2e6; }
        .working-capital-widget .efficiency-details h5 { margin: 0 0 10px 0; color: #495057; }
        .working-capital-widget .table-compact { width: 100%; font-size: 0.9em; }
        .working-capital-widget .table-compact td { padding: 5px 8px; }
        .working-capital-widget .table-compact .amount { text-align: right; font-weight: 500; }
        .working-capital-widget .table-compact .total-row { border-top: 2px solid #dee2e6; }
        .working-capital-widget .recommendations { margin-bottom: 15px; }
        .working-capital-widget .recommendations h5 { margin: 0 0 10px 0; color: #495057; }
        .working-capital-widget .recommendation { padding: 10px; margin-bottom: 8px; border-radius: 4px; border-left: 4px solid; }
        .working-capital-widget .recommendation-critical { background: #f8d7da; border-color: #dc3545; }
        .working-capital-widget .recommendation-warning { background: #fff3cd; border-color: #ffc107; }
        .working-capital-widget .recommendation-opportunity { background: #d1ecf1; border-color: #17a2b8; }
        .working-capital-widget .rec-header { font-weight: bold; margin-bottom: 5px; }
        .working-capital-widget .rec-text { font-size: 0.9em; }
    </style>';
    
    return $html;
}

// Register hooks
if (!function_exists('add_hook')) {
    function add_hook(string $hook, callable $callback): void
    {
        global $hooks;
        if (!isset($hooks[$hook])) {
            $hooks[$hook] = [];
        }
        $hooks[$hook][] = $callback;
    }
}

add_hook('module_install', 'working_capital_analysis_install');
add_hook('menu_items', 'working_capital_analysis_add_menu');
add_hook('dashboard_widgets', 'working_capital_dashboard_widget');
