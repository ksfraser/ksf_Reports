<?php

/**
 * Sales Analysis Dashboard Integration Hooks
 * 
 * This file provides integration hooks for the Sales Analysis Dashboard
 * with the FrontAccounting reporting system.
 * 
 * @package FrontAccounting
 * @subpackage Reports
 */

declare(strict_types=1);

use FA\Modules\Reports\Sales\SalesAnalysisDashboard;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

/**
 * Install/Register the Sales Analysis Dashboard
 */
function sales_analysis_dashboard_install(): void
{
    global $installed_extensions;
    
    $report = [
        'id' => 402,
        'category' => RC_SALES,
        'name' => _('Sales Analysis Dashboard'),
        'description' => _('Comprehensive sales analytics with trends, customer analysis, product performance, and forecasting'),
        'class' => 'FA\\Modules\\Reports\\Sales\\SalesAnalysisDashboard',
        'file' => 'modules/Reports/Sales/SalesAnalysisDashboard.php',
        'version' => '1.0.0',
        'author' => 'FrontAccounting Development Team',
        'date_added' => '2025-12-04',
        'icon' => 'chart-bar'
    ];
    
    if (!isset($installed_extensions['reports'])) {
        $installed_extensions['reports'] = [];
    }
    $installed_extensions['reports']['sales_dashboard'] = $report;
    
    add_menu_item('sales', 'Sales Analysis Dashboard', 'rep402.php', RC_SALES, MENU_SALES);
}

/**
 * Add menu item for Sales Analysis Dashboard
 */
function sales_analysis_dashboard_add_menu(): void
{
    global $path_to_root;
    
    $menu_entry = [
        'title' => _('Sales Dashboard'),
        'url' => $path_to_root . '/reporting/rep402.php',
        'access' => 'SA_SALESTRANSVIEW'
    ];
    
    add_menu_item(_('Sales Reports'), $menu_entry);
}

/**
 * Dashboard widget for Sales Analysis summary
 */
function sales_analysis_dashboard_widget(): array
{
    return [
        'id' => 'sales_analysis_summary',
        'title' => _('Sales Performance'),
        'description' => _('Key sales metrics and trends'),
        'content_callback' => 'sales_analysis_dashboard_data',
        'size' => 'large',
        'icon' => 'fa-chart-line',
        'refresh_interval' => 1800,
        'order' => 15
    ];
}

/**
 * Generate dashboard widget data
 */
function sales_analysis_dashboard_data(): array
{
    global $path_to_root;
    
    require_once($path_to_root . '/includes/db/database.inc');
    require_once($path_to_root . '/includes/ui.inc');
    
    try {
        $db = get_db_connection();
        $dispatcher = new EventDispatcher();
        $logger = get_logger();
        
        $report = new SalesAnalysisDashboard($db, $dispatcher, $logger);
        
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-30 days'));
        
        $result = $report->generate($startDate, $endDate);
        
        // Get growth comparison
        $growth = $report->generateGrowthAnalysis($startDate, $endDate);
        
        return [
            'summary' => $result['summary'],
            'customer_metrics' => $result['customer_metrics'],
            'top_products' => array_slice($result['top_products'], 0, 5),
            'top_customers' => array_slice($result['top_customers'], 0, 5),
            'trend' => $result['trend'],
            'growth_rate' => $growth['growth_rate'] ?? 0,
            'period' => $result['period']
        ];
        
    } catch (\Exception $e) {
        error_log('Sales Analysis dashboard widget error: ' . $e->getMessage());
        return [
            'error' => _('Unable to load sales analysis data'),
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Render Sales Analysis dashboard widget HTML
 */
function render_sales_analysis_widget(array $data): string
{
    if (isset($data['error'])) {
        return '<div class="alert alert-error">' . $data['error'] . '</div>';
    }
    
    $summary = $data['summary'];
    $customerMetrics = $data['customer_metrics'];
    $trend = $data['trend'];
    $growthRate = $data['growth_rate'];
    
    $html = '<div class="sales-analysis-widget">';
    
    // Trend banner
    $trendClass = match($trend) {
        'Growing' => 'success',
        'Declining' => 'danger',
        default => 'stable'
    };
    
    $trendIcon = match($trend) {
        'Growing' => '📈',
        'Declining' => '📉',
        default => '➡️'
    };
    
    $html .= '<div class="trend-banner trend-' . $trendClass . '">';
    $html .= $trendIcon . ' <strong>' . $trend . '</strong>';
    if ($growthRate != 0) {
        $html .= ' <span class="growth-rate">(' . ($growthRate > 0 ? '+' : '') . round($growthRate, 1) . '%)</span>';
    }
    $html .= '</div>';
    
    // Key metrics grid
    $html .= '<div class="metrics-grid">';
    
    $html .= '<div class="metric-card primary">';
    $html .= '<div class="metric-icon">💰</div>';
    $html .= '<div class="metric-info">';
    $html .= '<div class="metric-label">' . _('Total Revenue') . '</div>';
    $html .= '<div class="metric-value">$' . number_format($summary['total_revenue'], 0) . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    $html .= '<div class="metric-card">';
    $html .= '<div class="metric-icon">🛒</div>';
    $html .= '<div class="metric-info">';
    $html .= '<div class="metric-label">' . _('Orders') . '</div>';
    $html .= '<div class="metric-value">' . number_format($summary['total_orders']) . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    $html .= '<div class="metric-card">';
    $html .= '<div class="metric-icon">👥</div>';
    $html .= '<div class="metric-info">';
    $html .= '<div class="metric-label">' . _('Customers') . '</div>';
    $html .= '<div class="metric-value">' . number_format($summary['total_customers']) . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    $html .= '<div class="metric-card">';
    $html .= '<div class="metric-icon">📊</div>';
    $html .= '<div class="metric-info">';
    $html .= '<div class="metric-label">' . _('Avg Order Value') . '</div>';
    $html .= '<div class="metric-value">$' . number_format($summary['average_order_value'], 0) . '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    // Customer insights
    $html .= '<div class="insights-section">';
    $html .= '<div class="insight-card">';
    $html .= '<h5>👤 ' . _('Customer Insights') . '</h5>';
    $html .= '<div class="insight-stats">';
    $html .= '<div class="stat">';
    $html .= '<span class="stat-value">' . round($customerMetrics['retention_rate']) . '%</span>';
    $html .= '<span class="stat-label">' . _('Retention') . '</span>';
    $html .= '</div>';
    $html .= '<div class="stat">';
    $html .= '<span class="stat-value">' . $customerMetrics['new_customers'] . '</span>';
    $html .= '<span class="stat-label">' . _('New') . '</span>';
    $html .= '</div>';
    $html .= '<div class="stat">';
    $html .= '<span class="stat-value">' . $customerMetrics['returning_customers'] . '</span>';
    $html .= '<span class="stat-label">' . _('Returning') . '</span>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Top products
    if (!empty($data['top_products'])) {
        $html .= '<div class="top-items">';
        $html .= '<h5>⭐ ' . _('Top Products') . '</h5>';
        $html .= '<table class="table-compact">';
        foreach ($data['top_products'] as $i => $product) {
            $html .= '<tr>';
            $html .= '<td class="rank">#' . ($i + 1) . '</td>';
            $html .= '<td class="name">' . htmlspecialchars($product['description']) . '</td>';
            $html .= '<td class="amount">$' . number_format($product['revenue'], 0) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '</div>';
    }
    
    // Top customers
    if (!empty($data['top_customers'])) {
        $html .= '<div class="top-items">';
        $html .= '<h5>🏆 ' . _('Top Customers') . '</h5>';
        $html .= '<table class="table-compact">';
        foreach ($data['top_customers'] as $i => $customer) {
            $html .= '<tr>';
            $html .= '<td class="rank">#' . ($i + 1) . '</td>';
            $html .= '<td class="name">' . htmlspecialchars($customer['name']) . '</td>';
            $html .= '<td class="amount">$' . number_format($customer['revenue'], 0) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '</div>';
    }
    
    $html .= '<div class="widget-footer">';
    $html .= '<a href="' . $path_to_root . '/reporting/rep402.php" class="btn btn-primary">';
    $html .= _('View Full Dashboard') . ' →</a>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    // Widget styles
    $html .= '<style>
        .sales-analysis-widget { padding: 10px; }
        .sales-analysis-widget .trend-banner { padding: 12px; border-radius: 6px; text-align: center; margin-bottom: 15px; font-size: 1.1em; }
        .sales-analysis-widget .trend-success { background: linear-gradient(135deg, #28a745, #20c997); color: white; }
        .sales-analysis-widget .trend-danger { background: linear-gradient(135deg, #dc3545, #c82333); color: white; }
        .sales-analysis-widget .trend-stable { background: linear-gradient(135deg, #6c757d, #5a6268); color: white; }
        .sales-analysis-widget .growth-rate { font-weight: bold; }
        .sales-analysis-widget .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 15px; }
        .sales-analysis-widget .metric-card { display: flex; align-items: center; background: #f8f9fa; padding: 12px; border-radius: 6px; border-left: 4px solid #007bff; }
        .sales-analysis-widget .metric-card.primary { background: linear-gradient(135deg, #007bff, #0056b3); color: white; border-left-color: #0056b3; }
        .sales-analysis-widget .metric-icon { font-size: 2em; margin-right: 10px; }
        .sales-analysis-widget .metric-info { flex: 1; }
        .sales-analysis-widget .metric-label { font-size: 0.8em; opacity: 0.8; }
        .sales-analysis-widget .metric-value { font-size: 1.3em; font-weight: bold; margin-top: 3px; }
        .sales-analysis-widget .insights-section { margin-bottom: 15px; }
        .sales-analysis-widget .insight-card { background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #dee2e6; }
        .sales-analysis-widget .insight-card h5 { margin: 0 0 10px 0; color: #495057; font-size: 0.95em; }
        .sales-analysis-widget .insight-stats { display: flex; justify-content: space-around; }
        .sales-analysis-widget .stat { text-align: center; }
        .sales-analysis-widget .stat-value { display: block; font-size: 1.5em; font-weight: bold; color: #007bff; }
        .sales-analysis-widget .stat-label { display: block; font-size: 0.8em; color: #6c757d; margin-top: 3px; }
        .sales-analysis-widget .top-items { margin-bottom: 15px; }
        .sales-analysis-widget .top-items h5 { margin: 0 0 10px 0; color: #495057; font-size: 0.95em; }
        .sales-analysis-widget .table-compact { width: 100%; font-size: 0.85em; }
        .sales-analysis-widget .table-compact td { padding: 6px 8px; border-bottom: 1px solid #f0f0f0; }
        .sales-analysis-widget .table-compact .rank { width: 30px; font-weight: bold; color: #6c757d; }
        .sales-analysis-widget .table-compact .name { max-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .sales-analysis-widget .table-compact .amount { text-align: right; font-weight: 600; color: #28a745; white-space: nowrap; }
        .sales-analysis-widget .widget-footer { margin-top: 15px; text-align: center; }
        .sales-analysis-widget .btn-primary { display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: 500; }
        .sales-analysis-widget .btn-primary:hover { background: #0056b3; }
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

add_hook('module_install', 'sales_analysis_dashboard_install');
add_hook('menu_items', 'sales_analysis_dashboard_add_menu');
add_hook('dashboard_widgets', 'sales_analysis_dashboard_widget');
