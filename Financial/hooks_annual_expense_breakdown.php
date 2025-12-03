<?php
/**
 * Annual Expense Breakdown Report - FrontAccounting Hooks
 * 
 * Integration module for FrontAccounting module system providing menu items,
 * permissions, and report access points.
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
 * 
 * Called when FrontAccounting loads module metadata
 * 
 * @return array Module configuration
 */
function annual_expense_breakdown_init(): array
{
    return [
        'name' => 'Annual Expense Breakdown Report',
        'version' => '1.0.0',
        'author' => 'KSF Development Team',
        'description' => 'Comprehensive annual expense analysis with budget variance, trends, and comparisons',
        'type' => 'report',
        'category' => 'financial',
        'dependencies' => ['Reports']
    ];
}

/**
 * Install hook
 * 
 * Creates necessary database tables and default configurations
 * 
 * @return bool Installation success
 */
function annual_expense_breakdown_install(): bool
{
    global $db;

    // Register report definition
    $sql = "
        INSERT INTO report_definitions (
            report_code,
            report_name,
            report_category,
            description,
            parameters,
            created_at
        ) VALUES (
            'annual_expense_breakdown',
            'Annual Expense Breakdown',
            'Financial',
            'Comprehensive expense analysis with category breakdowns, budget variance, and year-over-year comparisons',
            '{\"fiscal_year\":\"required\",\"include_budget\":\"boolean\",\"group_by_category\":\"boolean\"}',
            NOW()
        ) ON DUPLICATE KEY UPDATE
            report_name = VALUES(report_name),
            description = VALUES(description),
            parameters = VALUES(parameters)
    ";

    db_query($sql, 'Failed to register Annual Expense Breakdown report');

    return true;
}

/**
 * Menu items hook
 * 
 * Defines menu structure for the report module
 * 
 * @return array Menu items
 */
function annual_expense_breakdown_menu(): array
{
    return [
        [
            'label' => _('Annual Expense Breakdown'),
            'url' => '/modules/Reports/Financial/annual_expense_breakdown.php',
            'access' => 'SA_GLREP',
            'section' => 'GL Reports',
            'position' => 150
        ],
        [
            'label' => _('Expense Trends'),
            'url' => '/modules/Reports/Financial/annual_expense_breakdown.php?mode=trends',
            'access' => 'SA_GLREP',
            'section' => 'GL Reports',
            'position' => 151
        ],
        [
            'label' => _('Budget Variance Analysis'),
            'url' => '/modules/Reports/Financial/annual_expense_breakdown.php?mode=variance',
            'access' => 'SA_GLREP',
            'section' => 'GL Reports',
            'position' => 152
        ]
    ];
}

/**
 * Security roles hook
 * 
 * Defines required security access levels
 * 
 * @return array Security areas
 */
function annual_expense_breakdown_security(): array
{
    return [
        [
            'code' => 'SA_ANNUALEXPENSE',
            'name' => _('Annual Expense Reports'),
            'description' => _('Access to annual expense breakdown reports')
        ]
    ];
}

/**
 * Report parameters hook
 * 
 * Defines configurable report parameters for UI generation
 * 
 * @return array Parameter definitions
 */
function annual_expense_breakdown_parameters(): array
{
    return [
        'fiscal_year' => [
            'type' => 'year',
            'label' => _('Fiscal Year'),
            'required' => true,
            'default' => date('Y'),
            'validation' => ['min' => 2000, 'max' => 2100]
        ],
        'include_budget' => [
            'type' => 'checkbox',
            'label' => _('Include Budget Comparison'),
            'default' => true
        ],
        'group_by_category' => [
            'type' => 'checkbox',
            'label' => _('Group by Category'),
            'default' => true
        ],
        'category_filter' => [
            'type' => 'select',
            'label' => _('Filter by Category'),
            'options' => [
                '' => _('All Categories'),
                'Salaries & Wages' => _('Salaries & Wages'),
                'Operating Expenses' => _('Operating Expenses'),
                'Administrative Expenses' => _('Administrative Expenses'),
                'Marketing & Sales' => _('Marketing & Sales'),
                'Professional Fees' => _('Professional Fees'),
                'Technology & IT' => _('Technology & IT'),
                'Depreciation & Amortization' => _('Depreciation & Amortization'),
                'Interest & Finance Charges' => _('Interest & Finance Charges'),
                'Other Expenses' => _('Other Expenses')
            ],
            'required' => false
        ],
        'compare_years' => [
            'type' => 'multi_year',
            'label' => _('Compare Years'),
            'required' => false,
            'max_selections' => 5
        ],
        'variance_threshold' => [
            'type' => 'decimal',
            'label' => _('Variance Alert Threshold (%)'),
            'default' => 5.0,
            'min' => 0,
            'max' => 100
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
 * 
 * Provides dashboard widgets for expense monitoring
 * 
 * @return array Widget definitions
 */
function annual_expense_breakdown_widgets(): array
{
    return [
        [
            'id' => 'expense_overview',
            'title' => _('Expense Overview'),
            'description' => _('Current month expense summary'),
            'callback' => 'render_expense_overview_widget',
            'refresh_interval' => 3600,
            'permissions' => ['SA_GLREP']
        ],
        [
            'id' => 'budget_alerts',
            'title' => _('Budget Alerts'),
            'description' => _('Categories exceeding budget threshold'),
            'callback' => 'render_budget_alerts_widget',
            'refresh_interval' => 1800,
            'permissions' => ['SA_GLREP']
        ]
    ];
}

/**
 * Render expense overview widget
 * 
 * @return string Widget HTML
 */
function render_expense_overview_widget(): string
{
    global $db;

    $currentYear = date('Y');
    $currentMonth = date('m');

    $sql = "
        SELECT 
            SUM(ABS(amount)) as total_expenses,
            COUNT(DISTINCT account) as account_count
        FROM gl_trans gl
        INNER JOIN chart_master cm ON gl.account = cm.account_code
        WHERE YEAR(trans_date) = ?
            AND MONTH(trans_date) = ?
            AND cm.account_code BETWEEN 5000 AND 6999
    ";

    $result = db_query($sql, 'Failed to fetch expense overview', [$currentYear, $currentMonth]);
    $data = db_fetch($result);

    $totalExpenses = number_format($data['total_expenses'] ?? 0, 2);
    $accountCount = $data['account_count'] ?? 0;

    return "
        <div class='widget-content'>
            <div class='expense-total'>
                <span class='label'>" . _('Total Expenses (MTD)') . ":</span>
                <span class='amount'>\$$totalExpenses</span>
            </div>
            <div class='expense-accounts'>
                <span class='label'>" . _('Active Accounts') . ":</span>
                <span class='count'>$accountCount</span>
            </div>
        </div>
    ";
}

/**
 * Render budget alerts widget
 * 
 * @return string Widget HTML
 */
function render_budget_alerts_widget(): string
{
    global $db;

    $currentYear = date('Y');

    $sql = "
        SELECT 
            cm.account_code,
            cm.account_name,
            SUM(ABS(gl.amount)) as actual,
            COALESCE(b.amount, 0) as budget,
            ((SUM(ABS(gl.amount)) - COALESCE(b.amount, 0)) / NULLIF(COALESCE(b.amount, 0), 0)) * 100 as variance_percent
        FROM gl_trans gl
        INNER JOIN chart_master cm ON gl.account = cm.account_code
        LEFT JOIN budget_trans b ON b.account = cm.account_code AND b.fiscal_year = ?
        WHERE YEAR(gl.trans_date) = ?
            AND cm.account_code BETWEEN 5000 AND 6999
        GROUP BY cm.account_code, cm.account_name, b.amount
        HAVING variance_percent > 5
        ORDER BY variance_percent DESC
        LIMIT 5
    ";

    $result = db_query($sql, 'Failed to fetch budget alerts', [$currentYear, $currentYear]);

    $html = "<div class='widget-content'><ul class='budget-alerts'>";

    $hasAlerts = false;
    while ($row = db_fetch($result)) {
        $hasAlerts = true;
        $variance = number_format($row['variance_percent'], 1);
        $html .= "
            <li class='alert-item'>
                <span class='account'>{$row['account_code']} - {$row['account_name']}</span>
                <span class='variance over-budget'>+{$variance}%</span>
            </li>
        ";
    }

    if (!$hasAlerts) {
        $html .= "<li class='no-alerts'>" . _('No budget alerts') . "</li>";
    }

    $html .= "</ul></div>";

    return $html;
}

/**
 * Scheduled tasks hook
 * 
 * Defines automated report generation schedules
 * 
 * @return array Scheduled tasks
 */
function annual_expense_breakdown_scheduled_tasks(): array
{
    return [
        [
            'name' => 'monthly_expense_summary',
            'description' => _('Generate monthly expense summary email'),
            'frequency' => 'monthly',
            'day_of_month' => 1,
            'callback' => 'generate_monthly_expense_email',
            'enabled' => true
        ],
        [
            'name' => 'quarterly_variance_report',
            'description' => _('Generate quarterly budget variance report'),
            'frequency' => 'quarterly',
            'callback' => 'generate_quarterly_variance_report',
            'enabled' => true
        ]
    ];
}

/**
 * Configuration options hook
 * 
 * Module-specific configuration settings
 * 
 * @return array Configuration options
 */
function annual_expense_breakdown_config(): array
{
    return [
        'default_variance_threshold' => [
            'type' => 'decimal',
            'label' => _('Default Variance Alert Threshold (%)'),
            'default' => 5.0,
            'description' => _('Alert when expenses exceed budget by this percentage')
        ],
        'expense_categories' => [
            'type' => 'textarea',
            'label' => _('Custom Expense Categories'),
            'default' => '',
            'description' => _('JSON configuration for custom expense category mappings')
        ],
        'enable_email_alerts' => [
            'type' => 'checkbox',
            'label' => _('Enable Email Alerts'),
            'default' => true,
            'description' => _('Send email alerts when variance threshold is exceeded')
        ],
        'alert_recipients' => [
            'type' => 'text',
            'label' => _('Alert Email Recipients'),
            'default' => '',
            'description' => _('Comma-separated email addresses for budget alerts')
        ]
    ];
}

/**
 * API endpoints hook
 * 
 * REST API endpoints for external integrations
 * 
 * @return array API endpoint definitions
 */
function annual_expense_breakdown_api_endpoints(): array
{
    return [
        [
            'path' => '/api/reports/annual-expense-breakdown',
            'method' => 'GET',
            'callback' => 'api_get_annual_expense_breakdown',
            'auth_required' => true,
            'permissions' => ['SA_GLREP']
        ],
        [
            'path' => '/api/reports/expense-trends',
            'method' => 'GET',
            'callback' => 'api_get_expense_trends',
            'auth_required' => true,
            'permissions' => ['SA_GLREP']
        ],
        [
            'path' => '/api/reports/budget-variance',
            'method' => 'GET',
            'callback' => 'api_get_budget_variance',
            'auth_required' => true,
            'permissions' => ['SA_GLREP']
        ]
    ];
}

/**
 * Uninstall hook
 * 
 * Cleanup when module is removed
 * 
 * @return bool Uninstall success
 */
function annual_expense_breakdown_uninstall(): bool
{
    global $db;

    // Remove report definition
    $sql = "DELETE FROM report_definitions WHERE report_code = 'annual_expense_breakdown'";
    db_query($sql, 'Failed to remove report definition');

    return true;
}
