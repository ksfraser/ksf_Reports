<?php

/**
 * Inventory ABC Analysis Report Integration Hooks
 * 
 * This file provides integration hooks for the Inventory ABC Analysis Report
 * with the FrontAccounting reporting system.
 * 
 * @package FrontAccounting
 * @subpackage Reports
 */

declare(strict_types=1);

use FA\Modules\Reports\Inventory\InventoryABCAnalysisReport;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

/**
 * Install/Register the ABC Analysis Report
 * 
 * Called during module installation to register the report with FA's reporting system.
 * Registers as an Inventory Report (category RC_INVENTORY = 2).
 */
function inventory_abc_analysis_install(): void
{
    global $installed_extensions;
    
    // Register report metadata
    $report = [
        'id' => 302,  // Report ID (in 300 range for inventory reports)
        'category' => RC_INVENTORY,  // Category: Inventory Reports
        'name' => _('Inventory ABC Analysis'),
        'description' => _('ABC classification of inventory items based on value contribution (Pareto principle)'),
        'class' => 'FA\\Modules\\Reports\\Inventory\\InventoryABCAnalysisReport',
        'file' => 'modules/Reports/Inventory/InventoryABCAnalysisReport.php',
        'version' => '1.0.0',
        'author' => 'FrontAccounting Development Team',
        'date_added' => '2025-12-03',
        'icon' => 'inventory'
    ];
    
    // Add to installed extensions
    if (!isset($installed_extensions['reports'])) {
        $installed_extensions['reports'] = [];
    }
    $installed_extensions['reports']['inventory_abc_analysis'] = $report;
    
    // Create menu entry
    add_menu_item('inventory', 'ABC Analysis', 'rep302.php', RC_INVENTORY, MENU_INVENTORY);
}

/**
 * Add menu item for ABC Analysis Report
 * 
 * Adds navigation menu entry under Inventory Reports.
 */
function inventory_abc_analysis_add_menu(): void
{
    global $path_to_root;
    
    $menu_entry = [
        'title' => _('ABC Analysis'),
        'url' => $path_to_root . '/reporting/rep302.php',
        'access' => 'SA_ITEMSANALYTIC'
    ];
    
    add_menu_item(_('Inventory Reports'), $menu_entry);
}

/**
 * Dashboard widget for ABC Analysis summary
 * 
 * Provides a dashboard widget showing key ABC classification metrics.
 * 
 * @return array Widget configuration
 */
function inventory_abc_analysis_dashboard_widget(): array
{
    return [
        'id' => 'abc_analysis_summary',
        'title' => _('Inventory ABC Classification'),
        'description' => _('Summary of inventory items by ABC class'),
        'content_callback' => 'inventory_abc_analysis_dashboard_data',
        'size' => 'medium',
        'icon' => 'fa-chart-pie',
        'refresh_interval' => 3600, // Refresh every hour
        'order' => 30
    ];
}

/**
 * Generate dashboard widget data
 * 
 * @return array Widget data
 */
function inventory_abc_analysis_dashboard_data(): array
{
    global $path_to_root;
    
    require_once($path_to_root . '/includes/db/database.inc');
    require_once($path_to_root . '/includes/ui.inc');
    
    try {
        // Get database connection
        $db = get_db_connection();
        
        // Create event dispatcher (simplified for dashboard)
        $dispatcher = new EventDispatcher();
        
        // Create logger
        $logger = get_logger();
        
        // Create report instance
        $report = new InventoryABCAnalysisReport($db, $dispatcher, $logger);
        
        // Generate analysis
        $result = $report->generate();
        
        // Extract summary data
        $classification = $result['classification'];
        
        return [
            'class_a' => [
                'count' => $classification['class_a']['item_count'],
                'percent' => round($classification['class_a']['item_percent'], 1),
                'value' => number_format($classification['class_a']['total_value'], 2),
                'value_percent' => round($classification['class_a']['value_percent'], 1)
            ],
            'class_b' => [
                'count' => $classification['class_b']['item_count'],
                'percent' => round($classification['class_b']['item_percent'], 1),
                'value' => number_format($classification['class_b']['total_value'], 2),
                'value_percent' => round($classification['class_b']['value_percent'], 1)
            ],
            'class_c' => [
                'count' => $classification['class_c']['item_count'],
                'percent' => round($classification['class_c']['item_percent'], 1),
                'value' => number_format($classification['class_c']['total_value'], 2),
                'value_percent' => round($classification['class_c']['value_percent'], 1)
            ],
            'total_items' => $result['summary']['total_items'],
            'total_value' => number_format($result['summary']['total_value'], 2),
            'slow_moving' => $result['summary']['slow_moving_count'],
            'obsolete' => $result['summary']['obsolete_count']
        ];
        
    } catch (\Exception $e) {
        error_log('ABC Analysis dashboard widget error: ' . $e->getMessage());
        return [
            'error' => _('Unable to load ABC Analysis data'),
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Render ABC Analysis dashboard widget HTML
 * 
 * @param array $data Widget data from inventory_abc_analysis_dashboard_data()
 * 
 * @return string HTML content
 */
function render_abc_analysis_widget(array $data): string
{
    if (isset($data['error'])) {
        return '<div class="alert alert-error">' . $data['error'] . '</div>';
    }
    
    $html = '<div class="abc-analysis-widget">';
    $html .= '<div class="widget-summary">';
    $html .= '<p><strong>' . _('Total Items') . ':</strong> ' . $data['total_items'] . '</p>';
    $html .= '<p><strong>' . _('Total Value') . ':</strong> $' . $data['total_value'] . '</p>';
    $html .= '</div>';
    
    $html .= '<table class="table-striped">';
    $html .= '<thead><tr>';
    $html .= '<th>' . _('Class') . '</th>';
    $html .= '<th>' . _('Items') . '</th>';
    $html .= '<th>' . _('% of Items') . '</th>';
    $html .= '<th>' . _('Value') . '</th>';
    $html .= '<th>' . _('% of Value') . '</th>';
    $html .= '</tr></thead>';
    $html .= '<tbody>';
    
    // Class A
    $html .= '<tr class="class-a">';
    $html .= '<td><strong>A</strong></td>';
    $html .= '<td>' . $data['class_a']['count'] . '</td>';
    $html .= '<td>' . $data['class_a']['percent'] . '%</td>';
    $html .= '<td>$' . $data['class_a']['value'] . '</td>';
    $html .= '<td><strong>' . $data['class_a']['value_percent'] . '%</strong></td>';
    $html .= '</tr>';
    
    // Class B
    $html .= '<tr class="class-b">';
    $html .= '<td><strong>B</strong></td>';
    $html .= '<td>' . $data['class_b']['count'] . '</td>';
    $html .= '<td>' . $data['class_b']['percent'] . '%</td>';
    $html .= '<td>$' . $data['class_b']['value'] . '</td>';
    $html .= '<td>' . $data['class_b']['value_percent'] . '%</td>';
    $html .= '</tr>';
    
    // Class C
    $html .= '<tr class="class-c">';
    $html .= '<td><strong>C</strong></td>';
    $html .= '<td>' . $data['class_c']['count'] . '</td>';
    $html .= '<td>' . $data['class_c']['percent'] . '%</td>';
    $html .= '<td>$' . $data['class_c']['value'] . '</td>';
    $html .= '<td>' . $data['class_c']['value_percent'] . '%</td>';
    $html .= '</tr>';
    
    $html .= '</tbody></table>';
    
    // Alerts
    if ($data['slow_moving'] > 0) {
        $html .= '<div class="alert alert-warning">';
        $html .= '<strong>' . _('Slow Moving') . ':</strong> ' . $data['slow_moving'] . ' ' . _('items');
        $html .= '</div>';
    }
    
    if ($data['obsolete'] > 0) {
        $html .= '<div class="alert alert-danger">';
        $html .= '<strong>' . _('Obsolete') . ':</strong> ' . $data['obsolete'] . ' ' . _('items');
        $html .= '</div>';
    }
    
    $html .= '<div class="widget-footer">';
    $html .= '<a href="' . $path_to_root . '/reporting/rep302.php" class="btn btn-primary">';
    $html .= _('View Full Report') . '</a>';
    $html .= '</div>';
    
    $html .= '</div>';
    
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
add_hook('module_install', 'inventory_abc_analysis_install');
add_hook('menu_items', 'inventory_abc_analysis_add_menu');
add_hook('dashboard_widgets', 'inventory_abc_analysis_dashboard_widget');
