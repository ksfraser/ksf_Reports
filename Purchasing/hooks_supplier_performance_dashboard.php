<?php

/**
 * Supplier Performance Dashboard Integration Hooks
 * 
 * This file provides integration hooks for the Supplier Performance Dashboard
 * with the FrontAccounting reporting system.
 * 
 * @package FrontAccounting
 * @subpackage Reports
 */

declare(strict_types=1);

use FA\Modules\Reports\Purchasing\SupplierPerformanceDashboard;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

/**
 * Install/Register the Supplier Performance Dashboard
 * 
 * Called during module installation to register the report with FA's reporting system.
 * Registers as a Purchasing Report (category RC_PURCHASING = 5).
 */
function supplier_performance_dashboard_install(): void
{
    global $installed_extensions;
    
    // Register report metadata
    $report = [
        'id' => 501,  // Report ID (in 500 range for purchasing reports)
        'category' => RC_PURCHASING,  // Category: Purchasing Reports
        'name' => _('Supplier Performance Dashboard'),
        'description' => _('Comprehensive supplier evaluation with delivery, quality, and cost metrics'),
        'class' => 'FA\\Modules\\Reports\\Purchasing\\SupplierPerformanceDashboard',
        'file' => 'modules/Reports/Purchasing/SupplierPerformanceDashboard.php',
        'version' => '1.0.0',
        'author' => 'FrontAccounting Development Team',
        'date_added' => '2025-12-03',
        'icon' => 'suppliers'
    ];
    
    // Add to installed extensions
    if (!isset($installed_extensions['reports'])) {
        $installed_extensions['reports'] = [];
    }
    $installed_extensions['reports']['supplier_performance'] = $report;
    
    // Create menu entry
    add_menu_item('purchasing', 'Supplier Performance', 'rep501.php', RC_PURCHASING, MENU_PURCHASING);
}

/**
 * Add menu item for Supplier Performance Dashboard
 * 
 * Adds navigation menu entry under Purchasing Reports.
 */
function supplier_performance_dashboard_add_menu(): void
{
    global $path_to_root;
    
    $menu_entry = [
        'title' => _('Supplier Performance'),
        'url' => $path_to_root . '/reporting/rep501.php',
        'access' => 'SA_SUPPTRANSVIEW'
    ];
    
    add_menu_item(_('Purchasing Reports'), $menu_entry);
}

/**
 * Dashboard widget for Supplier Performance summary
 * 
 * Provides a dashboard widget showing key supplier metrics.
 * 
 * @return array Widget configuration
 */
function supplier_performance_dashboard_widget(): array
{
    return [
        'id' => 'supplier_performance_summary',
        'title' => _('Supplier Performance'),
        'description' => _('Top and bottom performing suppliers'),
        'content_callback' => 'supplier_performance_dashboard_data',
        'size' => 'large',
        'icon' => 'fa-chart-bar',
        'refresh_interval' => 3600, // Refresh every hour
        'order' => 25
    ];
}

/**
 * Generate dashboard widget data
 * 
 * @return array Widget data
 */
function supplier_performance_dashboard_data(): array
{
    global $path_to_root;
    
    require_once($path_to_root . '/includes/db/database.inc');
    require_once($path_to_root . '/includes/ui.inc');
    
    try {
        // Get database connection
        $db = get_db_connection();
        
        // Create event dispatcher
        $dispatcher = new EventDispatcher();
        
        // Create logger
        $logger = get_logger();
        
        // Create report instance
        $report = new SupplierPerformanceDashboard($db, $dispatcher, $logger);
        
        // Generate dashboard for last 90 days
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-90 days'));
        
        $result = $report->generate($startDate, $endDate);
        
        // Extract key metrics
        return [
            'summary' => $result['summary'],
            'top_performers' => array_slice($result['top_performers'], 0, 3),
            'underperformers' => array_slice($result['underperformers'], 0, 3),
            'metrics' => $result['metrics'],
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'days' => 90
            ]
        ];
        
    } catch (\Exception $e) {
        error_log('Supplier Performance dashboard widget error: ' . $e->getMessage());
        return [
            'error' => _('Unable to load supplier performance data'),
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Render Supplier Performance dashboard widget HTML
 * 
 * @param array $data Widget data from supplier_performance_dashboard_data()
 * 
 * @return string HTML content
 */
function render_supplier_performance_widget(array $data): string
{
    if (isset($data['error'])) {
        return '<div class="alert alert-error">' . $data['error'] . '</div>';
    }
    
    $summary = $data['summary'];
    $topPerformers = $data['top_performers'];
    $underperformers = $data['underperformers'];
    $metrics = $data['metrics'];
    
    $html = '<div class="supplier-performance-widget">';
    
    // Summary section
    $html .= '<div class="widget-summary">';
    $html .= '<h4>' . _('Last 90 Days Summary') . '</h4>';
    $html .= '<div class="metrics-grid">';
    $html .= '<div class="metric">';
    $html .= '<span class="label">' . _('Suppliers') . ':</span>';
    $html .= '<span class="value">' . $summary['total_suppliers'] . '</span>';
    $html .= '</div>';
    $html .= '<div class="metric">';
    $html .= '<span class="label">' . _('Orders') . ':</span>';
    $html .= '<span class="value">' . $summary['total_orders'] . '</span>';
    $html .= '</div>';
    $html .= '<div class="metric">';
    $html .= '<span class="label">' . _('Total Value') . ':</span>';
    $html .= '<span class="value">$' . number_format($summary['total_value'], 2) . '</span>';
    $html .= '</div>';
    $html .= '<div class="metric">';
    $html .= '<span class="label">' . _('On-Time Rate') . ':</span>';
    $html .= '<span class="value ' . ($summary['overall_on_time_rate'] >= 90 ? 'success' : 'warning') . '">';
    $html .= round($summary['overall_on_time_rate'], 1) . '%</span>';
    $html .= '</div>';
    $html .= '<div class="metric">';
    $html .= '<span class="label">' . _('Quality Score') . ':</span>';
    $html .= '<span class="value ' . ($summary['overall_quality_score'] >= 95 ? 'success' : 'warning') . '">';
    $html .= round($summary['overall_quality_score'], 1) . '%</span>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Top performers
    if (!empty($topPerformers)) {
        $html .= '<div class="top-performers">';
        $html .= '<h5>' . _('Top Performers') . '</h5>';
        $html .= '<table class="table-compact">';
        foreach ($topPerformers as $supplier) {
            $html .= '<tr>';
            $html .= '<td><strong>' . $supplier['supplier_name'] . '</strong></td>';
            $html .= '<td class="grade grade-' . strtolower($supplier['performance_grade']) . '">';
            $html .= $supplier['performance_grade'] . '</td>';
            $html .= '<td class="score">' . round($supplier['overall_score'], 1) . '</td>';
            $html .= '<td>' . round($supplier['on_time_delivery_rate'], 1) . '% OTD</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '</div>';
    }
    
    // Underperformers (alerts)
    if (!empty($underperformers)) {
        $html .= '<div class="underperformers alert alert-warning">';
        $html .= '<h5>' . _('⚠️ Needs Attention') . '</h5>';
        $html .= '<table class="table-compact">';
        foreach ($underperformers as $supplier) {
            $html .= '<tr>';
            $html .= '<td><strong>' . $supplier['supplier_name'] . '</strong></td>';
            $html .= '<td class="grade grade-' . strtolower($supplier['performance_grade']) . '">';
            $html .= $supplier['performance_grade'] . '</td>';
            $html .= '<td class="score">' . round($supplier['overall_score'], 1) . '</td>';
            $html .= '<td class="risk-' . strtolower($supplier['risk_level']) . '">';
            $html .= $supplier['risk_level'] . ' Risk</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '</div>';
    }
    
    // Best performer highlight
    if (isset($metrics['best_performer'])) {
        $html .= '<div class="best-performer">';
        $html .= '<span class="icon">🏆</span> ';
        $html .= '<strong>' . _('Best Performer') . ':</strong> ';
        $html .= $metrics['best_performer'];
        $html .= '</div>';
    }
    
    $html .= '<div class="widget-footer">';
    $html .= '<a href="' . $path_to_root . '/reporting/rep501.php" class="btn btn-primary">';
    $html .= _('View Full Dashboard') . '</a>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    // Add widget-specific styles
    $html .= '<style>
        .supplier-performance-widget .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin: 10px 0; }
        .supplier-performance-widget .metric { padding: 8px; background: #f5f5f5; border-radius: 4px; }
        .supplier-performance-widget .metric .label { display: block; font-size: 0.85em; color: #666; }
        .supplier-performance-widget .metric .value { display: block; font-size: 1.2em; font-weight: bold; }
        .supplier-performance-widget .value.success { color: #28a745; }
        .supplier-performance-widget .value.warning { color: #ffc107; }
        .supplier-performance-widget .table-compact { width: 100%; font-size: 0.9em; }
        .supplier-performance-widget .table-compact td { padding: 5px 8px; }
        .supplier-performance-widget .grade { font-weight: bold; padding: 2px 8px; border-radius: 3px; }
        .supplier-performance-widget .grade-a { background: #28a745; color: white; }
        .supplier-performance-widget .grade-b { background: #17a2b8; color: white; }
        .supplier-performance-widget .grade-c { background: #ffc107; color: black; }
        .supplier-performance-widget .grade-d, .supplier-performance-widget .grade-f { background: #dc3545; color: white; }
        .supplier-performance-widget .risk-high { color: #dc3545; font-weight: bold; }
        .supplier-performance-widget .risk-medium { color: #ffc107; }
        .supplier-performance-widget .risk-low { color: #28a745; }
        .supplier-performance-widget .best-performer { margin: 15px 0; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107; }
        .supplier-performance-widget .underperformers { margin: 10px 0; }
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

// Register installation hooks
add_hook('module_install', 'supplier_performance_dashboard_install');
add_hook('menu_items', 'supplier_performance_dashboard_add_menu');
add_hook('dashboard_widgets', 'supplier_performance_dashboard_widget');
