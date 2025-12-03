<?php

/**
 * Product Profitability Analysis Integration Hooks
 * 
 * This file provides integration hooks for the Product Profitability Analysis
 * with the FrontAccounting reporting system.
 * 
 * @package FrontAccounting
 * @subpackage Reports
 */

declare(strict_types=1);

use FA\Modules\Reports\Sales\ProductProfitabilityAnalysis;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

/**
 * Install/Register the Product Profitability Analysis
 */
function product_profitability_analysis_install(): void
{
    global $installed_extensions;
    
    $report = [
        'id' => 401,
        'category' => RC_SALES,
        'name' => _('Product Profitability Analysis'),
        'description' => _('Comprehensive product-level profitability with margins, costs, and pricing recommendations'),
        'class' => 'FA\\Modules\\Reports\\Sales\\ProductProfitabilityAnalysis',
        'file' => 'modules/Reports/Sales/ProductProfitabilityAnalysis.php',
        'version' => '1.0.0',
        'author' => 'FrontAccounting Development Team',
        'date_added' => '2025-12-03',
        'icon' => 'products'
    ];
    
    if (!isset($installed_extensions['reports'])) {
        $installed_extensions['reports'] = [];
    }
    $installed_extensions['reports']['product_profitability'] = $report;
    
    add_menu_item('sales', 'Product Profitability', 'rep401.php', RC_SALES, MENU_SALES);
}

/**
 * Add menu item for Product Profitability Analysis
 */
function product_profitability_analysis_add_menu(): void
{
    global $path_to_root;
    
    $menu_entry = [
        'title' => _('Product Profitability'),
        'url' => $path_to_root . '/reporting/rep401.php',
        'access' => 'SA_SALESTRANSVIEW'
    ];
    
    add_menu_item(_('Sales Reports'), $menu_entry);
}

/**
 * Dashboard widget for Product Profitability summary
 */
function product_profitability_dashboard_widget(): array
{
    return [
        'id' => 'product_profitability_summary',
        'title' => _('Product Profitability'),
        'description' => _('Top and least profitable products'),
        'content_callback' => 'product_profitability_dashboard_data',
        'size' => 'large',
        'icon' => 'fa-chart-line',
        'refresh_interval' => 3600,
        'order' => 35
    ];
}

/**
 * Generate dashboard widget data
 */
function product_profitability_dashboard_data(): array
{
    global $path_to_root;
    
    require_once($path_to_root . '/includes/db/database.inc');
    require_once($path_to_root . '/includes/ui.inc');
    
    try {
        $db = get_db_connection();
        $dispatcher = new EventDispatcher();
        $logger = get_logger();
        
        $report = new ProductProfitabilityAnalysis($db, $dispatcher, $logger);
        
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-90 days'));
        
        $result = $report->generate($startDate, $endDate);
        
        return [
            'summary' => $result['summary'],
            'top_profitable' => array_slice($result['top_profitable'], 0, 5),
            'least_profitable' => array_slice($result['least_profitable'], 0, 3),
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'days' => 90
            ]
        ];
        
    } catch (\Exception $e) {
        error_log('Product Profitability dashboard widget error: ' . $e->getMessage());
        return [
            'error' => _('Unable to load product profitability data'),
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Render Product Profitability dashboard widget HTML
 */
function render_product_profitability_widget(array $data): string
{
    if (isset($data['error'])) {
        return '<div class="alert alert-error">' . $data['error'] . '</div>';
    }
    
    $summary = $data['summary'];
    $topProfitable = $data['top_profitable'];
    $leastProfitable = $data['least_profitable'];
    
    $html = '<div class="product-profitability-widget">';
    
    // Summary section
    $html .= '<div class="widget-summary">';
    $html .= '<h4>' . _('Last 90 Days') . '</h4>';
    $html .= '<div class="metrics-grid">';
    $html .= '<div class="metric">';
    $html .= '<span class="label">' . _('Products') . ':</span>';
    $html .= '<span class="value">' . $summary['total_products'] . '</span>';
    $html .= '</div>';
    $html .= '<div class="metric">';
    $html .= '<span class="label">' . _('Revenue') . ':</span>';
    $html .= '<span class="value">$' . number_format($summary['total_revenue'], 2) . '</span>';
    $html .= '</div>';
    $html .= '<div class="metric">';
    $html .= '<span class="label">' . _('Profit') . ':</span>';
    $html .= '<span class="value ' . ($summary['total_profit'] > 0 ? 'success' : 'danger') . '">$';
    $html .= number_format($summary['total_profit'], 2) . '</span>';
    $html .= '</div>';
    $html .= '<div class="metric">';
    $html .= '<span class="label">' . _('Margin') . ':</span>';
    $html .= '<span class="value ' . ($summary['overall_margin_percent'] >= 30 ? 'success' : 'warning') . '">';
    $html .= round($summary['overall_margin_percent'], 1) . '%</span>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Top profitable products
    if (!empty($topProfitable)) {
        $html .= '<div class="top-profitable">';
        $html .= '<h5>💰 ' . _('Most Profitable') . '</h5>';
        $html .= '<table class="table-compact">';
        foreach ($topProfitable as $product) {
            $html .= '<tr>';
            $html .= '<td><strong>' . $product['description'] . '</strong></td>';
            $html .= '<td class="amount">$' . number_format($product['gross_profit'], 0) . '</td>';
            $html .= '<td class="margin margin-' . ($product['gross_margin_percent'] >= 40 ? 'high' : 'medium') . '">';
            $html .= round($product['gross_margin_percent'], 1) . '%</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '</div>';
    }
    
    // Least profitable products (alerts)
    if (!empty($leastProfitable)) {
        $html .= '<div class="least-profitable alert alert-warning">';
        $html .= '<h5>⚠️ ' . _('Needs Attention') . '</h5>';
        $html .= '<table class="table-compact">';
        foreach ($leastProfitable as $product) {
            $html .= '<tr>';
            $html .= '<td><strong>' . $product['description'] . '</strong></td>';
            $html .= '<td class="amount">$' . number_format($product['gross_profit'], 0) . '</td>';
            $html .= '<td class="margin margin-low">';
            $html .= round($product['gross_margin_percent'], 1) . '%</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        $html .= '</div>';
    }
    
    // Summary insights
    if ($summary['unprofitable_products'] > 0) {
        $html .= '<div class="alert alert-danger">';
        $html .= '🔴 <strong>' . $summary['unprofitable_products'] . '</strong> ';
        $html .= _('unprofitable product(s) - review pricing');
        $html .= '</div>';
    }
    
    if ($summary['high_margin_products'] > 0) {
        $html .= '<div class="insight">';
        $html .= '✓ ' . $summary['high_margin_products'] . ' ';
        $html .= _('high-margin products (≥40%)');
        $html .= '</div>';
    }
    
    $html .= '<div class="widget-footer">';
    $html .= '<a href="' . $path_to_root . '/reporting/rep401.php" class="btn btn-primary">';
    $html .= _('View Full Analysis') . '</a>';
    $html .= '</div>';
    
    $html .= '</div>';
    
    // Widget styles
    $html .= '<style>
        .product-profitability-widget .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin: 10px 0; }
        .product-profitability-widget .metric { padding: 8px; background: #f5f5f5; border-radius: 4px; }
        .product-profitability-widget .metric .label { display: block; font-size: 0.85em; color: #666; }
        .product-profitability-widget .metric .value { display: block; font-size: 1.2em; font-weight: bold; }
        .product-profitability-widget .value.success { color: #28a745; }
        .product-profitability-widget .value.warning { color: #ffc107; }
        .product-profitability-widget .value.danger { color: #dc3545; }
        .product-profitability-widget .table-compact { width: 100%; font-size: 0.9em; }
        .product-profitability-widget .table-compact td { padding: 5px 8px; }
        .product-profitability-widget .amount { text-align: right; font-weight: bold; }
        .product-profitability-widget .margin { text-align: center; font-weight: bold; padding: 2px 8px; border-radius: 3px; }
        .product-profitability-widget .margin-high { background: #28a745; color: white; }
        .product-profitability-widget .margin-medium { background: #17a2b8; color: white; }
        .product-profitability-widget .margin-low { background: #ffc107; color: black; }
        .product-profitability-widget .insight { margin: 10px 0; padding: 8px; background: #d4edda; border-left: 4px solid #28a745; font-size: 0.9em; }
        .product-profitability-widget .alert { margin: 10px 0; padding: 10px; border-radius: 4px; }
        .product-profitability-widget .alert-warning { background: #fff3cd; border-left: 4px solid #ffc107; }
        .product-profitability-widget .alert-danger { background: #f8d7da; border-left: 4px solid #dc3545; }
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

add_hook('module_install', 'product_profitability_analysis_install');
add_hook('menu_items', 'product_profitability_analysis_add_menu');
add_hook('dashboard_widgets', 'product_profitability_dashboard_widget');
