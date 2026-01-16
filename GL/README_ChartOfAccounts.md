# Chart of Accounts Report Service

## Overview

The Chart of Accounts report service provides a complete hierarchical view of all general ledger accounts organized by account class and type. This report is essential for understanding the structure of the company's chart of accounts and can optionally include current account balances.

## Features

- Complete chart of accounts listing
- Optional balance display
- Hierarchical grouping by account class and type
- Account code and name display
- Summary statistics (account count, class count, type count)
- Export to PDF and Excel formats
- PSR-3 logging support
- Event-driven architecture for extensibility

## Service Location

```
modules/Reports/GL/ChartOfAccounts.php
```

## Dependencies

- `FA\Database\DBALInterface` - Database operations
- `FA\Events\EventDispatcher` - Event handling
- `Psr\Log\LoggerInterface` - Logging

## Usage

### Basic Usage (Without Balances)

```php
<?php
use FA\Modules\Reports\GL\ChartOfAccounts;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\NullLogger;

// Initialize service
$db = /* your DBAL instance */;
$eventDispatcher = new EventDispatcher();
$logger = new NullLogger();

$service = new ChartOfAccounts($db, $eventDispatcher, $logger);

// Generate report without balances
$report = $service->generate(false);

// Access results
foreach ($report['accounts_by_class'] as $class) {
    echo "Class: {$class['class_name']}\n";
    foreach ($class['accounts'] as $account) {
        echo "  {$account['account_code']} - {$account['account_name']}\n";
    }
}
```

### With Balance Display

```php
<?php
// Generate report with current balances
$report = $service->generate(true);

// Access accounts with balances
foreach ($report['accounts_by_class'] as $class) {
    echo "Class: {$class['class_name']}\n";
    foreach ($class['accounts'] as $account) {
        $balance = number_format($account['balance'] ?? 0, 2);
        echo "  {$account['account_code']} - {$account['account_name']}: {$balance}\n";
    }
}
```

### Using Helper Functions

The module provides convenient helper functions in `hooks_chart_of_accounts.php`:

```php
<?php
require_once __DIR__ . '/hooks_chart_of_accounts.php';

// Generate chart of accounts without balances
$report = generate_chart_of_accounts(false);

// Generate with balances
$report = generate_chart_of_accounts(true);

// Export to PDF
$pdfResult = export_chart_of_accounts_pdf($report, "Chart of Accounts");

// Export to Excel
$excelResult = export_chart_of_accounts_excel($report, "Chart of Accounts");
```

## Report Structure

### Output Format

The report returns an array with the following structure:

```php
[
    'accounts_by_class' => [
        [
            'class_id' => 1,
            'class_name' => 'Assets',
            'accounts' => [
                [
                    'account_code' => '1000',
                    'account_name' => 'Cash',
                    'account_code2' => null,
                    'account_type' => 1,
                    'type_name' => 'Current Assets',
                    'class_id' => 1,
                    'class_name' => 'Assets',
                    'balance' => 50000.00 // Only if showBalances=true
                ],
                // ... more accounts
            ]
        ],
        // ... more classes
    ],
    'accounts_by_type' => [
        [
            'account_type' => 1,
            'type_name' => 'Current Assets',
            'accounts' => [ /* accounts */ ]
        ],
        // ... more types
    ],
    'summary' => [
        'account_count' => 45,
        'class_count' => 5,
        'type_count' => 12
    ]
]
```

## Account Classes

Standard FrontAccounting account classes:

- **Class 1**: Assets
- **Class 2**: Liabilities
- **Class 3**: Income
- **Class 4**: Expenses
- **Class 5**: Equity

## Account Types

Common account types include:

- Current Assets
- Fixed Assets
- Current Liabilities
- Long-term Liabilities
- Sales Revenue
- Cost of Goods Sold
- Operating Expenses
- Other Income
- Other Expenses
- Equity

## Use Cases

### 1. Account Setup Verification

Verify that all required accounts are set up correctly:

```php
<?php
$report = generate_chart_of_accounts(false);

// Check if critical accounts exist
$accountCodes = array_column($report['accounts_by_class'][0]['accounts'], 'account_code');
$requiredAccounts = ['1000', '1100', '1200', '2000', '3000'];

foreach ($requiredAccounts as $required) {
    if (!in_array($required, $accountCodes)) {
        echo "Missing account: {$required}\n";
    }
}
```

### 2. Balance Sheet Preparation

Review current balances by account class:

```php
<?php
$report = generate_chart_of_accounts(true);

foreach ($report['accounts_by_class'] as $class) {
    echo "{$class['class_name']}\n";
    $classTotal = 0;
    
    foreach ($class['accounts'] as $account) {
        $balance = $account['balance'] ?? 0;
        $classTotal += $balance;
        echo "  {$account['account_code']} - {$account['account_name']}: " . 
             number_format($balance, 2) . "\n";
    }
    
    echo "Total {$class['class_name']}: " . number_format($classTotal, 2) . "\n\n";
}
```

### 3. Account Structure Documentation

Generate documentation of the chart of accounts:

```php
<?php
$report = generate_chart_of_accounts(false);

echo "# Chart of Accounts Documentation\n\n";
echo "Total Accounts: {$report['summary']['account_count']}\n\n";

foreach ($report['accounts_by_class'] as $class) {
    echo "## {$class['class_name']}\n\n";
    
    foreach ($class['accounts'] as $account) {
        echo "- **{$account['account_code']}** - {$account['account_name']}\n";
        echo "  - Type: {$account['type_name']}\n";
        if ($account['account_code2']) {
            echo "  - Alternative Code: {$account['account_code2']}\n";
        }
    }
    echo "\n";
}
```

### 4. Account Migration Planning

Compare account structures between companies or systems:

```php
<?php
// Company A
$reportA = generate_chart_of_accounts(false);
$accountsA = array_column($reportA['accounts_by_class'][0]['accounts'], 'account_code');

// Company B (different database connection)
$reportB = generate_chart_of_accounts(false);
$accountsB = array_column($reportB['accounts_by_class'][0]['accounts'], 'account_code');

// Find differences
$onlyInA = array_diff($accountsA, $accountsB);
$onlyInB = array_diff($accountsB, $accountsA);

echo "Accounts only in Company A: " . implode(', ', $onlyInA) . "\n";
echo "Accounts only in Company B: " . implode(', ', $onlyInB) . "\n";
```

### 5. Financial Statement Mapping

Map chart accounts to financial statement lines:

```php
<?php
$report = generate_chart_of_accounts(true);

$balanceSheet = [
    'Current Assets' => 0,
    'Fixed Assets' => 0,
    'Current Liabilities' => 0,
    'Long-term Liabilities' => 0,
    'Equity' => 0
];

foreach ($report['accounts_by_type'] as $type) {
    $typeTotal = array_sum(array_column($type['accounts'], 'balance'));
    
    // Map to balance sheet sections
    if (in_array($type['type_name'], ['Current Assets', 'Fixed Assets'])) {
        $balanceSheet[$type['type_name']] += $typeTotal;
    }
    // ... more mapping logic
}
```

## Export Functions

### Export to PDF

```php
<?php
$report = generate_chart_of_accounts(true);
$result = export_chart_of_accounts_pdf($report, "Chart of Accounts - January 2026");

if ($result['success']) {
    echo "PDF exported to: {$result['filename']}\n";
}
```

### Export to Excel

```php
<?php
$report = generate_chart_of_accounts(false);
$result = export_chart_of_accounts_excel($report, "Chart of Accounts Structure");

if ($result['success']) {
    echo "Excel exported to: {$result['filename']}\n";
}
```

## Events

The service dispatches events for extensibility:

### `chart_of_accounts.before_generate`

Fired before generating the report.

```php
<?php
$eventDispatcher->addListener('chart_of_accounts.before_generate', function($event) {
    $showBalances = $event->getData()['show_balances'];
    // Perform pre-generation tasks
});
```

### `chart_of_accounts.after_generate`

Fired after generating the report.

```php
<?php
$eventDispatcher->addListener('chart_of_accounts.after_generate', function($event) {
    $report = $event->getData()['report'];
    $accountCount = $report['summary']['account_count'];
    // Log or process results
});
```

### `chart_of_accounts.before_export`

Fired before export operations.

```php
<?php
$eventDispatcher->addListener('chart_of_accounts.before_export', function($event) {
    $data = $event->getData();
    $format = $data['format']; // 'pdf' or 'excel'
    // Customize export behavior
});
```

## Database Schema

The service queries the following tables:

### chart_master

Main account table:
- `account_code` - Primary account identifier
- `account_name` - Account description
- `account_code2` - Alternative/legacy account code
- `account_type` - Foreign key to chart_types

### chart_types

Account type definitions:
- `id` - Type identifier
- `name` - Type name (e.g., "Current Assets")
- `class_id` - Foreign key to chart_class

### chart_class

Account class definitions:
- `cid` - Class identifier
- `class_name` - Class name (e.g., "Assets")

### gl_trans (optional)

General ledger transactions (used when showBalances=true):
- `account` - Foreign key to chart_master.account_code
- `amount` - Transaction amount

## Performance Considerations

### Balance Calculation

When `showBalances=true`, the service performs a LEFT JOIN with gl_trans and aggregates all transactions per account. For large datasets:

- Consider adding an index on `gl_trans.account`
- The query uses COALESCE to handle accounts with no transactions
- Balances are calculated as SUM(amount) per account

### Caching

For frequently accessed reports:

```php
<?php
$cacheKey = 'chart_of_accounts_' . ($showBalances ? 'with' : 'without') . '_balances';
$cachedReport = $cache->get($cacheKey);

if (!$cachedReport) {
    $cachedReport = generate_chart_of_accounts($showBalances);
    $cache->set($cacheKey, $cachedReport, 3600); // Cache for 1 hour
}
```

## Error Handling

The service logs all operations and errors:

```php
<?php
use Psr\Log\LogLevel;

// Service logs at different levels:
// - INFO: Successful operations
// - WARNING: Empty result sets
// - ERROR: Database errors, exceptions
```

## Troubleshooting

### Empty Report

**Symptom**: Report returns empty arrays

**Possible Causes**:
1. No accounts in chart_master table
2. Database connection issue
3. Incorrect account_type or class_id foreign keys

**Solution**:
```php
<?php
// Check account count
$report = generate_chart_of_accounts(false);
if ($report['summary']['account_count'] === 0) {
    // Verify data exists
    $db = get_connection();
    $count = $db->fetchOne("SELECT COUNT(*) FROM chart_master");
    echo "Accounts in database: {$count}\n";
}
```

### Missing Balances

**Symptom**: Balances show as 0 when showBalances=true

**Possible Causes**:
1. No transactions in gl_trans
2. account field in gl_trans doesn't match account_code in chart_master
3. All transactions have amount=0

**Solution**:
```php
<?php
// Verify transactions exist
$db = get_connection();
$transCount = $db->fetchOne("SELECT COUNT(*) FROM gl_trans WHERE amount != 0");
echo "Non-zero transactions: {$transCount}\n";

// Check specific account
$balance = $db->fetchOne(
    "SELECT COALESCE(SUM(amount), 0) FROM gl_trans WHERE account = :account",
    ['account' => '1000']
);
echo "Account 1000 balance: {$balance}\n";
```

### Incorrect Grouping

**Symptom**: Accounts appear in wrong class or type

**Possible Causes**:
1. Invalid foreign key references
2. Orphaned records (account_type or class_id doesn't exist)

**Solution**:
```php
<?php
// Find orphaned accounts
$db = get_connection();
$orphaned = $db->fetchAll("
    SELECT ca.account_code, ca.account_type 
    FROM chart_master ca
    LEFT JOIN chart_types ct ON ca.account_type = ct.id
    WHERE ct.id IS NULL
");

if (!empty($orphaned)) {
    echo "Orphaned accounts found:\n";
    foreach ($orphaned as $account) {
        echo "  {$account['account_code']} (type: {$account['account_type']})\n";
    }
}
```

## Testing

Run the test suite:

```bash
vendor/bin/phpunit tests/Reports/GL/ChartOfAccountsTest.php
```

Test coverage includes:
- Basic report generation
- Balance calculation
- Grouping by class and type
- Summary statistics
- Empty dataset handling
- Export functions

## Integration with Legacy Code

To use in existing FrontAccounting code:

```php
<?php
// In reporting/rep701.php or similar
require_once __DIR__ . '/../modules/Reports/GL/hooks_chart_of_accounts.php';

// Replace old logic with:
$showBalances = isset($_POST['PARAM_0']) ? $_POST['PARAM_0'] : 0;
$report = generate_chart_of_accounts($showBalances);

// Use $report data for rendering
```

## Related Reports

- **rep702** - Journal Entries Report
- **rep708** - Trial Balance Report
- **rep710** - Profit & Loss Statement

## Version History

- **v1.0.0** (2026-01-15) - Initial implementation
  - Complete chart of accounts listing
  - Optional balance display
  - Hierarchical grouping
  - Export to PDF/Excel
  - Full test coverage

## Support

For issues or questions:
1. Check logs for error messages
2. Verify database schema matches expectations
3. Run test suite to identify service issues
4. Review event listeners for custom behavior

## License

Part of the FrontAccounting Reports module.
