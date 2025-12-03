<?php
/**
 * Aged Debtors Report - FrontAccounting Hooks
 * 
 * Integration module for FrontAccounting providing AR aging analysis,
 * collection management, and credit monitoring capabilities.
 * 
 * @package    KSF\Reports
 * @subpackage Financial
 * @author     KSF Development Team
 * @copyright  2025 KSFraser
 * @license    MIT
 * @version    1.0.0
 * @link       https://github.com/ksfraser/ksf_Reports
 */

declare(strict_types=1);

/**
 * Module initialization hook
 */
function aged_debtors_init(): array
{
    return [
        'name' => 'Aged Debtors Report',
        'version' => '1.0.0',
        'author' => 'KSF Development Team',
        'description' => 'Comprehensive accounts receivable aging analysis with collection prioritization',
        'type' => 'report',
        'category' => 'financial',
        'dependencies' => ['Reports']
    ];
}

/**
 * Install hook
 */
function aged_debtors_install(): bool
{
    global $db;

    $sql = "
        INSERT INTO report_definitions (
            report_code,
            report_name,
            report_category,
            description,
            parameters,
            created_at
        ) VALUES (
            'aged_debtors',
            'Aged Debtors Report',
            'Financial',
            'Accounts receivable aging analysis with collection priority and credit monitoring',
            '{\"as_of_date\":\"required\",\"aging_buckets\":\"array\",\"customer_type_filter\":\"string\",\"group_by_currency\":\"boolean\"}',
            NOW()
        ) ON DUPLICATE KEY UPDATE
            report_name = VALUES(report_name),
            description = VALUES(description),
            parameters = VALUES(parameters)
    ";

    db_query($sql, 'Failed to register Aged Debtors report');

    return true;
}

/**
 * Menu items hook
 */
function aged_debtors_menu(): array
{
    return [
        [
            'label' => _('Aged Debtors Report'),
            'url' => '/modules/Reports/Financial/aged_debtors.php',
            'access' => 'SA_SALESREP',
            'section' => 'AR Reports',
            'position' => 200
        ],
        [
            'label' => _('Collection Priority List'),
            'url' => '/modules/Reports/Financial/aged_debtors.php?mode=priority',
            'access' => 'SA_SALESREP',
            'section' => 'AR Reports',
            'position' => 201
        ],
        [
            'label' => _('Credit Limit Alerts'),
            'url' => '/modules/Reports/Financial/aged_debtors.php?mode=credit_alerts',
            'access' => 'SA_SALESMANAGER',
            'section' => 'AR Reports',
            'position' => 202
        ],
        [
            'label' => _('AR Metrics Dashboard'),
            'url' => '/modules/Reports/Financial/aged_debtors.php?mode=metrics',
            'access' => 'SA_SALESREP',
            'section' => 'AR Reports',
            'position' => 203
        ]
    ];
}

/**
 * Security roles hook
 */
function aged_debtors_security(): array
{
    return [
        [
            'code' => 'SA_AGEDDEBTORS',
            'name' => _('Aged Debtors Reports'),
            'description' => _('Access to AR aging and collection reports')
        ],
        [
            'code' => 'SA_ARCOLLECTION',
            'name' => _('AR Collection Management'),
            'description' => _('Access to collection priority and follow-up tools')
        ]
    ];
}

/**
 * Report parameters hook
 */
function aged_debtors_parameters(): array
{
    return [
        'as_of_date' => [
            'type' => 'date',
            'label' => _('As Of Date'),
            'required' => true,
            'default' => date('Y-m-d'),
            'description' => _('Date to calculate aging as of')
        ],
        'aging_buckets' => [
            'type' => 'array',
            'label' => _('Aging Buckets (days)'),
            'default' => [0, 30, 60, 90],
            'description' => _('Custom aging period definitions')
        ],
        'customer_type_filter' => [
            'type' => 'select',
            'label' => _('Customer Type'),
            'options' => [
                '' => _('All Types'),
                'retail' => _('Retail'),
                'wholesale' => _('Wholesale'),
                'distributor' => _('Distributor'),
                'government' => _('Government')
            ],
            'required' => false
        ],
        'group_by_currency' => [
            'type' => 'checkbox',
            'label' => _('Group by Currency'),
            'default' => false
        ],
        'show_percentages' => [
            'type' => 'checkbox',
            'label' => _('Show Percentage Breakdown'),
            'default' => true
        ],
        'include_contacts' => [
            'type' => 'checkbox',
            'label' => _('Include Contact Information'),
            'default' => false,
            'description' => _('Include customer contact details for follow-up')
        ],
        'show_credit_alerts' => [
            'type' => 'checkbox',
            'label' => _('Highlight Credit Limit Alerts'),
            'default' => true
        ],
        'overdue_only' => [
            'type' => 'checkbox',
            'label' => _('Show Overdue Only'),
            'default' => false
        ],
        'min_amount' => [
            'type' => 'decimal',
            'label' => _('Minimum Balance to Include'),
            'default' => 0.00,
            'min' => 0
        ],
        'format' => [
            'type' => 'select',
            'label' => _('Export Format'),
            'options' => [
                'screen' => _('Screen Display'),
                'pdf' => _('PDF Document'),
                'excel' => _('Excel Spreadsheet'),
                'csv' => _('CSV File')
            ],
            'default' => 'screen'
        ]
    ];
}

/**
 * Dashboard widgets hook
 */
function aged_debtors_widgets(): array
{
    return [
        [
            'id' => 'ar_summary',
            'title' => _('AR Aging Summary'),
            'description' => _('Current accounts receivable aging breakdown'),
            'callback' => 'render_ar_summary_widget',
            'refresh_interval' => 3600,
            'permissions' => ['SA_SALESREP']
        ],
        [
            'id' => 'credit_alerts',
            'title' => _('Credit Alerts'),
            'description' => _('Customers over or near credit limits'),
            'callback' => 'render_credit_alerts_widget',
            'refresh_interval' => 1800,
            'permissions' => ['SA_SALESMANAGER']
        ],
        [
            'id' => 'collection_priority',
            'title' => _('Collection Priority'),
            'description' => _('Top customers requiring immediate collection action'),
            'callback' => 'render_collection_priority_widget',
            'refresh_interval' => 3600,
            'permissions' => ['SA_ARCOLLECTION']
        ],
        [
            'id' => 'dso_metric',
            'title' => _('Days Sales Outstanding'),
            'description' => _('Current DSO metric and trend'),
            'callback' => 'render_dso_widget',
            'refresh_interval' => 86400,
            'permissions' => ['SA_SALESREP']
        ]
    ];
}

/**
 * Render AR summary widget
 */
function render_ar_summary_widget(): string
{
    global $db;

    $today = date('Y-m-d');

    $sql = "
        SELECT 
            COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) <= 0 THEN ov_amount + ov_gst - alloc ELSE 0 END), 0) as current,
            COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 1 AND 30 THEN ov_amount + ov_gst - alloc ELSE 0 END), 0) as days_30,
            COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) BETWEEN 31 AND 60 THEN ov_amount + ov_gst - alloc ELSE 0 END), 0) as days_60,
            COALESCE(SUM(CASE WHEN DATEDIFF(?, due_date) > 60 THEN ov_amount + ov_gst - alloc ELSE 0 END), 0) as days_over_60,
            COALESCE(SUM(ov_amount + ov_gst - alloc), 0) as total
        FROM debtor_trans
        WHERE type IN (10, 11, 12)
            AND ov_amount + ov_gst - alloc > 0.01
    ";

    $result = db_query($sql, 'Failed to fetch AR summary', [$today, $today, $today, $today]);
    $data = db_fetch($result);

    $current = number_format($data['current'] ?? 0, 2);
    $days30 = number_format($data['days_30'] ?? 0, 2);
    $days60 = number_format($data['days_60'] ?? 0, 2);
    $daysOver60 = number_format($data['days_over_60'] ?? 0, 2);
    $total = number_format($data['total'] ?? 0, 2);

    return "
        <div class='widget-content ar-summary'>
            <table class='aging-table'>
                <tr>
                    <td class='label'>" . _('Current') . ":</td>
                    <td class='amount'>\$$current</td>
                </tr>
                <tr>
                    <td class='label'>" . _('1-30 Days') . ":</td>
                    <td class='amount warning'>\$$days30</td>
                </tr>
                <tr>
                    <td class='label'>" . _('31-60 Days') . ":</td>
                    <td class='amount alert'>\$$days60</td>
                </tr>
                <tr>
                    <td class='label'>" . _('Over 60 Days') . ":</td>
                    <td class='amount critical'>\$$daysOver60</td>
                </tr>
                <tr class='total-row'>
                    <td class='label'><strong>" . _('Total AR') . ":</strong></td>
                    <td class='amount'><strong>\$$total</strong></td>
                </tr>
            </table>
        </div>
    ";
}

/**
 * Render credit alerts widget
 */
function render_credit_alerts_widget(): string
{
    global $db;

    $sql = "
        SELECT 
            c.debtor_no,
            c.name,
            c.credit_limit,
            COALESCE(SUM(dt.ov_amount + dt.ov_gst - dt.alloc), 0) as total_due
        FROM debtors_master c
        LEFT JOIN debtor_trans dt ON c.debtor_no = dt.debtor_no 
            AND dt.type IN (10, 11, 12)
            AND dt.ov_amount + dt.ov_gst - dt.alloc > 0.01
        WHERE c.credit_limit > 0
        GROUP BY c.debtor_no, c.name, c.credit_limit
        HAVING total_due > c.credit_limit * 0.8
        ORDER BY (total_due - c.credit_limit) DESC
        LIMIT 5
    ";

    $result = db_query($sql, 'Failed to fetch credit alerts');

    $html = "<div class='widget-content'><ul class='credit-alerts'>";

    $hasAlerts = false;
    while ($row = db_fetch($result)) {
        $hasAlerts = true;
        $utilization = ($row['total_due'] / $row['credit_limit']) * 100;
        $status = $utilization > 100 ? 'over-limit' : 'near-limit';
        
        $html .= "
            <li class='alert-item $status'>
                <span class='customer'>{$row['name']}</span>
                <span class='utilization'>" . number_format($utilization, 1) . "%</span>
            </li>
        ";
    }

    if (!$hasAlerts) {
        $html .= "<li class='no-alerts'>" . _('No credit alerts') . "</li>";
    }

    $html .= "</ul></div>";

    return $html;
}

/**
 * Render collection priority widget
 */
function render_collection_priority_widget(): string
{
    global $db;

    $today = date('Y-m-d');

    $sql = "
        SELECT 
            c.debtor_no,
            c.name,
            COALESCE(SUM(CASE WHEN DATEDIFF(?, dt.due_date) > 90 THEN dt.ov_amount + dt.ov_gst - dt.alloc ELSE 0 END), 0) as over_90,
            COALESCE(SUM(dt.ov_amount + dt.ov_gst - dt.alloc), 0) as total_due
        FROM debtors_master c
        INNER JOIN debtor_trans dt ON c.debtor_no = dt.debtor_no 
            AND dt.type IN (10, 11, 12)
            AND dt.ov_amount + dt.ov_gst - dt.alloc > 0.01
        GROUP BY c.debtor_no, c.name
        HAVING over_90 > 0
        ORDER BY over_90 DESC
        LIMIT 5
    ";

    $result = db_query($sql, 'Failed to fetch collection priority', [$today]);

    $html = "<div class='widget-content'><ul class='collection-priority'>";

    $hasItems = false;
    while ($row = db_fetch($result)) {
        $hasItems = true;
        $over90 = number_format($row['over_90'], 2);
        
        $html .= "
            <li class='priority-item'>
                <span class='customer'>{$row['name']}</span>
                <span class='amount critical'>\$$over90</span>
            </li>
        ";
    }

    if (!$hasItems) {
        $html .= "<li class='no-items'>" . _('No immediate collection actions required') . "</li>";
    }

    $html .= "</ul></div>";

    return $html;
}

/**
 * Render DSO metric widget
 */
function render_dso_widget(): string
{
    global $db;

    $today = date('Y-m-d');

    $sql = "
        SELECT 
            COALESCE(SUM(dt.ov_amount + dt.ov_gst - dt.alloc), 0) as total_receivables,
            COALESCE(
                (SELECT SUM(ov_amount + ov_gst) 
                 FROM debtor_trans 
                 WHERE type = 10 
                 AND tran_date BETWEEN DATE_SUB(?, INTERVAL 365 DAY) AND ?), 0
            ) as total_sales
        FROM debtor_trans dt
        WHERE dt.type IN (10, 11, 12)
            AND dt.ov_amount + dt.ov_gst - dt.alloc > 0.01
    ";

    $result = db_query($sql, 'Failed to fetch DSO data', [$today, $today]);
    $data = db_fetch($result);

    $dso = 0;
    if ($data && $data['total_sales'] > 0) {
        $dso = ($data['total_receivables'] / $data['total_sales']) * 365;
    }

    $dsoFormatted = number_format($dso, 1);
    $status = $dso > 60 ? 'critical' : ($dso > 45 ? 'warning' : 'good');

    return "
        <div class='widget-content dso-metric'>
            <div class='metric-value $status'>
                <span class='value'>$dsoFormatted</span>
                <span class='unit'>" . _('days') . "</span>
            </div>
            <div class='metric-label'>" . _('Days Sales Outstanding') . "</div>
            <div class='metric-target'>" . _('Target: < 45 days') . "</div>
        </div>
    ";
}

/**
 * Scheduled tasks hook
 */
function aged_debtors_scheduled_tasks(): array
{
    return [
        [
            'name' => 'weekly_ar_aging',
            'description' => _('Generate weekly AR aging report email'),
            'frequency' => 'weekly',
            'day_of_week' => 1, // Monday
            'callback' => 'generate_weekly_ar_report',
            'enabled' => true
        ],
        [
            'name' => 'daily_credit_alerts',
            'description' => _('Send daily credit limit alerts'),
            'frequency' => 'daily',
            'callback' => 'send_credit_limit_alerts',
            'enabled' => true
        ],
        [
            'name' => 'monthly_collection_letters',
            'description' => _('Generate monthly collection letters'),
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'callback' => 'generate_collection_letters',
            'enabled' => true
        ]
    ];
}

/**
 * Configuration options hook
 */
function aged_debtors_config(): array
{
    return [
        'default_aging_buckets' => [
            'type' => 'text',
            'label' => _('Default Aging Buckets'),
            'default' => '0,30,60,90',
            'description' => _('Comma-separated aging period boundaries in days')
        ],
        'credit_alert_threshold' => [
            'type' => 'decimal',
            'label' => _('Credit Alert Threshold (%)'),
            'default' => 80.0,
            'description' => _('Alert when credit utilization exceeds this percentage')
        ],
        'enable_auto_collection_letters' => [
            'type' => 'checkbox',
            'label' => _('Enable Automatic Collection Letters'),
            'default' => false,
            'description' => _('Automatically generate collection letters for overdue accounts')
        ],
        'collection_letter_days' => [
            'type' => 'text',
            'label' => _('Collection Letter Days'),
            'default' => '30,60,90',
            'description' => _('Days overdue to trigger collection letters')
        ],
        'ar_alert_recipients' => [
            'type' => 'text',
            'label' => _('AR Alert Email Recipients'),
            'default' => '',
            'description' => _('Comma-separated email addresses for AR alerts')
        ],
        'dso_target_days' => [
            'type' => 'int',
            'label' => _('DSO Target Days'),
            'default' => 45,
            'description' => _('Target days sales outstanding for alerts')
        ]
    ];
}

/**
 * API endpoints hook
 */
function aged_debtors_api_endpoints(): array
{
    return [
        [
            'path' => '/api/reports/aged-debtors',
            'method' => 'GET',
            'callback' => 'api_get_aged_debtors',
            'auth_required' => true,
            'permissions' => ['SA_SALESREP']
        ],
        [
            'path' => '/api/reports/collection-priority',
            'method' => 'GET',
            'callback' => 'api_get_collection_priority',
            'auth_required' => true,
            'permissions' => ['SA_ARCOLLECTION']
        ],
        [
            'path' => '/api/reports/credit-alerts',
            'method' => 'GET',
            'callback' => 'api_get_credit_alerts',
            'auth_required' => true,
            'permissions' => ['SA_SALESMANAGER']
        ],
        [
            'path' => '/api/reports/ar-metrics',
            'method' => 'GET',
            'callback' => 'api_get_ar_metrics',
            'auth_required' => true,
            'permissions' => ['SA_SALESREP']
        ]
    ];
}

/**
 * Uninstall hook
 */
function aged_debtors_uninstall(): bool
{
    global $db;

    $sql = "DELETE FROM report_definitions WHERE report_code = 'aged_debtors'";
    db_query($sql, 'Failed to remove report definition');

    return true;
}
