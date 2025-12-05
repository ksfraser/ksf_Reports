# Trial Balance Report

## Overview

The **Trial Balance Report** (rep708) provides a comprehensive view of all general ledger account balances with three time periods: brought forward balances, current period activity, and ending balances. Essential for financial statement preparation and account reconciliation.

## Features

### Core Functionality
- **Three-Period View**: Brought forward, current period, and total balances
- **Debit/Credit Display**: Shows both debit and credit amounts or net balances
- **Account Grouping**: Organized by account class and type hierarchy
- **Dimension Filtering**: Filter by one or two dimensions (projects/departments)
- **Zero Balance Control**: Option to include or exclude accounts with zero balances
- **Balance Verification**: Automatic detection of unbalanced trial balance
- **Fiscal Year Integration**: Properly handles fiscal year beginnings

### Data Integrity
- **Balance Validation**: Ensures total debits equal total credits
- **Opening Balance Warning**: Detects non-closed previous fiscal years
- **Precision Handling**: Proper decimal rounding throughout

## Installation

### Service Integration
```php
require_once 'modules/Reports/GL/hooks_trial_balance.php';
```

## Usage

### Basic Usage
```php
use FA\Modules\Reports\GL\TrialBalance;

// Initialize
$db = get_db_connection();
$dispatcher = new EventDispatcher();
$logger = get_logger();

$report = new TrialBalance($db, $dispatcher, $logger);

// Generate trial balance for period
$result = $report->generate('2024-01-01', '2024-01-31');
```

### Using Helper Functions
```php
// Basic trial balance (excludes zero balances)
$data = generate_trial_balance_report('2024-01-01', '2024-01-31');

// Include zero balance accounts
$data = generate_trial_balance_report('2024-01-01', '2024-01-31', true);

// With dimension filtering
$data = generate_trial_balance_report('2024-01-01', '2024-01-31', false, 5, 0);
```

### Accessing Results
```php
$data = generate_trial_balance_report('2024-01-01', '2024-01-31');

// Summary information
$summary = $data['summary'];
echo "Total Accounts: " . $summary['account_count'] . "\n";
echo "Total Debits: $" . number_format($summary['tot_debit'], 2) . "\n";
echo "Total Credits: $" . number_format($summary['tot_credit'], 2) . "\n";
echo "Net Balance: $" . number_format($summary['tot_balance'], 2) . "\n";

if (!$summary['is_balanced']) {
    echo "⚠️ WARNING: Trial balance is out of balance!\n";
    echo "Difference: $" . number_format(abs($summary['tot_balance']), 2) . "\n";
}

// Individual accounts
foreach ($data['accounts'] as $account) {
    echo "\n" . $account['account_code'] . " - " . $account['account_name'] . "\n";
    echo "  Class: " . $account['class_name'] . "\n";
    echo "  Type: " . $account['type_name'] . "\n";
    
    // Brought forward
    echo "  Brought Forward:\n";
    echo "    Debit: $" . number_format($account['prev_debit'], 2) . "\n";
    echo "    Credit: $" . number_format($account['prev_credit'], 2) . "\n";
    echo "    Balance: $" . number_format($account['prev_balance'], 2) . "\n";
    
    // Current period
    echo "  Current Period:\n";
    echo "    Debit: $" . number_format($account['curr_debit'], 2) . "\n";
    echo "    Credit: $" . number_format($account['curr_credit'], 2) . "\n";
    echo "    Balance: $" . number_format($account['curr_balance'], 2) . "\n";
    
    // Total
    echo "  Ending Balance:\n";
    echo "    Debit: $" . number_format($account['tot_debit'], 2) . "\n";
    echo "    Credit: $" . number_format($account['tot_credit'], 2) . "\n";
    echo "    Balance: $" . number_format($account['tot_balance'], 2) . "\n";
}
```

### Grouping by Account Class
```php
$data = generate_trial_balance_report('2024-01-01', '2024-01-31');

foreach ($data['by_class'] as $classId => $accounts) {
    $className = $accounts[0]['class_name'];
    echo "\n" . $className . " (Class $classId)\n";
    echo str_repeat("=", 50) . "\n";
    
    $classDebit = 0;
    $classCredit = 0;
    
    foreach ($accounts as $account) {
        echo $account['account_code'] . " " . $account['account_name'];
        echo " - Balance: $" . number_format($account['tot_balance'], 2) . "\n";
        
        $classDebit += $account['tot_debit'];
        $classCredit += $account['tot_credit'];
    }
    
    echo "\nClass Total:\n";
    echo "  Debit: $" . number_format($classDebit, 2) . "\n";
    echo "  Credit: $" . number_format($classCredit, 2) . "\n";
    echo "  Balance: $" . number_format($classDebit - $classCredit, 2) . "\n";
}
```

### Grouping by Account Type
```php
$data = generate_trial_balance_report('2024-01-01', '2024-01-31');

foreach ($data['by_type'] as $typeId => $accounts) {
    $typeName = $accounts[0]['type_name'];
    echo "\n" . $typeName . " (Type $typeId)\n";
    echo str_repeat("-", 40) . "\n";
    
    foreach ($accounts as $account) {
        echo $account['account_code'] . " " . $account['account_name'];
        
        if ($account['tot_balance'] > 0) {
            echo " DR: $" . number_format($account['tot_balance'], 2);
        } else {
            echo " CR: $" . number_format(abs($account['tot_balance']), 2);
        }
        echo "\n";
    }
}
```

### Dimension Filtering
```php
// Filter by single dimension (e.g., Project #5)
$projectData = generate_trial_balance_report(
    '2024-01-01',
    '2024-01-31',
    false,  // exclude zero balances
    5,      // dimension 1 = Project #5
    0       // dimension 2 = all
);

echo "Trial Balance for Project #5\n";
echo "Total Activity: $" . number_format($projectData['summary']['tot_debit'], 2) . "\n";

// Filter by two dimensions (Project #5, Department #3)
$filtered = generate_trial_balance_report(
    '2024-01-01',
    '2024-01-31',
    false,
    5,  // Project #5
    3   // Department #3
);

echo "\nTrial Balance for Project #5, Department #3\n";
echo "Accounts: " . $filtered['summary']['account_count'] . "\n";
```

### Include/Exclude Zero Balances
```php
// Exclude zero balances (default) - cleaner report
$active = generate_trial_balance_report('2024-01-01', '2024-01-31', false);
echo "Active Accounts: " . count($active['accounts']) . "\n";

// Include zero balances - complete chart of accounts
$complete = generate_trial_balance_report('2024-01-01', '2024-01-31', true);
echo "All Accounts: " . count($complete['accounts']) . "\n";
echo "Inactive: " . (count($complete['accounts']) - count($active['accounts'])) . "\n";
```

## Understanding the Data Structure

### Account Structure
```php
[
    'account_code' => '1200',
    'account_name' => 'Bank Account',
    'account_code2' => 'BANK001',        // Alternative code
    'account_type' => 1,                  // Account type ID
    'type_name' => 'Current Assets',
    'class_id' => 1,                      // Account class ID
    'class_name' => 'Assets',
    
    // Brought forward balances (before period start)
    'prev_debit' => 10000.00,
    'prev_credit' => 5000.00,
    'prev_balance' => 5000.00,            // prev_debit - prev_credit
    
    // Current period activity
    'curr_debit' => 3000.00,
    'curr_credit' => 1000.00,
    'curr_balance' => 2000.00,            // curr_debit - curr_credit
    
    // Total (from beginning to period end)
    'tot_debit' => 13000.00,
    'tot_credit' => 6000.00,
    'tot_balance' => 7000.00              // tot_debit - tot_credit
]
```

### Summary Structure
```php
[
    'prev_debit' => 50000.00,             // Sum of all previous debits
    'prev_credit' => 50000.00,            // Sum of all previous credits
    'prev_balance' => 0.00,               // prev_debit - prev_credit
    'curr_debit' => 15000.00,             // Sum of all current debits
    'curr_credit' => 15000.00,            // Sum of all current credits
    'curr_balance' => 0.00,               // curr_debit - curr_credit
    'tot_debit' => 65000.00,              // Sum of all total debits
    'tot_credit' => 65000.00,             // Sum of all total credits
    'tot_balance' => 0.00,                // tot_debit - tot_credit
    'is_balanced' => true,                // abs(tot_balance) < 0.01
    'account_count' => 45                 // Number of accounts
]
```

## Use Cases

### 1. Month-End Financial Close
**Objective**: Verify all accounts are balanced before closing period

```php
$monthEnd = generate_trial_balance_report('2024-01-01', '2024-01-31');

echo "Month-End Trial Balance - January 2024\n";
echo "=====================================\n\n";

$summary = $monthEnd['summary'];

echo "Accounts in Trial Balance: " . $summary['account_count'] . "\n";
echo "Total Activity: $" . number_format($summary['tot_debit'], 2) . "\n\n";

if ($summary['is_balanced']) {
    echo "✓ Trial Balance is BALANCED\n";
    echo "  Total Debits: $" . number_format($summary['tot_debit'], 2) . "\n";
    echo "  Total Credits: $" . number_format($summary['tot_credit'], 2) . "\n";
    echo "\nReady to close period.\n";
} else {
    echo "✗ Trial Balance is OUT OF BALANCE\n";
    echo "  Difference: $" . number_format(abs($summary['tot_balance']), 2) . "\n";
    echo "\n⚠️ CRITICAL: Cannot close period until balanced!\n";
    echo "Action: Review and correct unbalanced entries.\n";
}
```

### 2. Financial Statement Preparation
**Objective**: Extract data for balance sheet and income statement

```php
$yearEnd = generate_trial_balance_report('2024-01-01', '2024-12-31');

// Separate balance sheet and income statement accounts
$balanceSheet = [];
$incomeStatement = [];

foreach ($yearEnd['by_class'] as $classId => $accounts) {
    $className = $accounts[0]['class_name'];
    
    // Balance sheet classes: Assets (1), Liabilities (2), Equity (4)
    if (in_array($classId, [1, 2, 4])) {
        $balanceSheet[$className] = $accounts;
    }
    // Income statement classes: Income (3), Expenses (5)
    elseif (in_array($classId, [3, 5])) {
        $incomeStatement[$className] = $accounts;
    }
}

echo "Balance Sheet Accounts:\n";
foreach ($balanceSheet as $className => $accounts) {
    $total = array_sum(array_column($accounts, 'tot_balance'));
    echo "  $className: $" . number_format($total, 2) . "\n";
}

echo "\nIncome Statement Accounts:\n";
foreach ($incomeStatement as $className => $accounts) {
    $total = array_sum(array_column($accounts, 'tot_balance'));
    echo "  $className: $" . number_format($total, 2) . "\n";
}
```

### 3. Account Reconciliation
**Objective**: Identify accounts needing reconciliation

```php
$current = generate_trial_balance_report('2024-01-01', '2024-01-31');

echo "Accounts Requiring Reconciliation:\n";
echo "==================================\n\n";

foreach ($current['accounts'] as $account) {
    // Flag accounts with significant activity
    if (abs($account['curr_balance']) > 1000) {
        echo $account['account_code'] . " - " . $account['account_name'] . "\n";
        echo "  Current Period Activity: $" . number_format(abs($account['curr_balance']), 2) . "\n";
        
        // Check if balance changed significantly
        $changePercent = 0;
        if ($account['prev_balance'] != 0) {
            $changePercent = (($account['curr_balance'] / $account['prev_balance']) - 1) * 100;
        }
        
        if (abs($changePercent) > 20) {
            echo "  ⚠️ Large change: " . number_format($changePercent, 1) . "%\n";
        }
        
        echo "\n";
    }
}
```

### 4. Budget vs. Actual Analysis
**Objective**: Compare trial balance totals to budget

```php
$actual = generate_trial_balance_report('2024-01-01', '2024-01-31');

// Sample budget data (would come from database)
$budget = [
    'Revenue' => 50000.00,
    'Expenses' => 35000.00
];

echo "Budget vs. Actual Analysis - January 2024\n";
echo "=========================================\n\n";

// Get actual revenue (Income class, typically negative balance)
$actualRevenue = 0;
$actualExpenses = 0;

foreach ($actual['by_class'] as $classId => $accounts) {
    $className = $accounts[0]['class_name'];
    $total = array_sum(array_column($accounts, 'tot_balance'));
    
    if ($classId == 3) { // Income
        $actualRevenue = abs($total);
    } elseif ($classId == 5) { // Expenses
        $actualExpenses = abs($total);
    }
}

// Revenue comparison
$revenueVariance = $actualRevenue - $budget['Revenue'];
$revenuePercent = ($revenueVariance / $budget['Revenue']) * 100;

echo "Revenue:\n";
echo "  Budget: $" . number_format($budget['Revenue'], 2) . "\n";
echo "  Actual: $" . number_format($actualRevenue, 2) . "\n";
echo "  Variance: $" . number_format($revenueVariance, 2);
echo " (" . number_format($revenuePercent, 1) . "%)\n\n";

// Expense comparison
$expenseVariance = $actualExpenses - $budget['Expenses'];
$expensePercent = ($expenseVariance / $budget['Expenses']) * 100;

echo "Expenses:\n";
echo "  Budget: $" . number_format($budget['Expenses'], 2) . "\n";
echo "  Actual: $" . number_format($actualExpenses, 2) . "\n";
echo "  Variance: $" . number_format($expenseVariance, 2);
echo " (" . number_format($expensePercent, 1) . "%)\n";
```

### 5. Project Profitability
**Objective**: Analyze profitability by dimension (project)

```php
// Assuming dimensions 1-5 are different projects
$projects = [1, 2, 3, 4, 5];

echo "Project Profitability Analysis\n";
echo "==============================\n\n";

foreach ($projects as $projectId) {
    $projectData = generate_trial_balance_report(
        '2024-01-01',
        '2024-12-31',
        false,
        $projectId,
        0
    );
    
    $revenue = 0;
    $expenses = 0;
    
    foreach ($projectData['by_class'] as $classId => $accounts) {
        $total = array_sum(array_column($accounts, 'tot_balance'));
        
        if ($classId == 3) { // Income
            $revenue = abs($total);
        } elseif ($classId == 5) { // Expenses
            $expenses = abs($total);
        }
    }
    
    $profit = $revenue - $expenses;
    $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
    
    echo "Project #$projectId:\n";
    echo "  Revenue: $" . number_format($revenue, 2) . "\n";
    echo "  Expenses: $" . number_format($expenses, 2) . "\n";
    echo "  Profit: $" . number_format($profit, 2);
    echo " (" . number_format($margin, 1) . "% margin)\n";
    
    if ($profit < 0) {
        echo "  ⚠️ Project is operating at a loss!\n";
    }
    echo "\n";
}
```

## Troubleshooting

### Out of Balance Warning
**Problem**: Trial balance totals don't match (debits ≠ credits)
**Causes**:
- Previous fiscal year not properly closed
- Incomplete transaction entries
- Data corruption in gl_trans table
- Opening balance adjustments needed

**Solutions**:
```php
$data = generate_trial_balance_report('2024-01-01', '2024-12-31');

if (!$data['summary']['is_balanced']) {
    $diff = $data['summary']['tot_balance'];
    
    echo "Trial Balance Out of Balance: $" . number_format(abs($diff), 2) . "\n";
    
    // Check if it's an opening balance issue
    if (abs($data['summary']['prev_balance']) > 0.01) {
        echo "Issue: Opening balance not zero\n";
        echo "Action: Close previous fiscal year or post opening balance adjustment\n";
    } else {
        echo "Issue: Current period transactions unbalanced\n";
        echo "Action: Review journal entries for completeness\n";
    }
}
```

### Missing Accounts
**Problem**: Expected accounts not showing in trial balance
**Solutions**:
- Ensure accounts exist in chart_master
- Include zero balance accounts: `generate_trial_balance_report($from, $to, true)`
- Check dimension filters aren't excluding accounts
- Verify date range includes expected activity

### Performance Issues
**Problem**: Report slow with many accounts
**Solutions**:
- Ensure indexes exist on gl_trans (account, tran_date)
- Use dimension filtering to reduce dataset
- Exclude zero balances for faster processing
- Consider date range - full year slower than single month

## Technical Details

### Database Tables
- **chart_master**: Account definitions
- **chart_types**: Account type groupings
- **chart_class**: Account class groupings  
- **gl_trans**: General ledger transactions
- **dimensions**: Dimension/project definitions

### Balance Calculations
```
Brought Forward Balance = SUM(amount) WHERE tran_date < start_date
Current Period Balance = SUM(amount) WHERE tran_date >= start_date AND tran_date <= end_date
Total Balance = SUM(amount) WHERE tran_date <= end_date

Debit = SUM(amount) WHERE amount > 0
Credit = SUM(ABS(amount)) WHERE amount < 0
Balance = Debit - Credit
```

### Performance
- Indexed queries on tran_date and account
- Efficient LEFT JOINs for three time periods
- Single query execution
- Memory-efficient aggregation

## Migration from Legacy

### Gradual Integration
1. Keep existing rep708.php operational
2. Use service for data retrieval
3. Maintain FrontReport formatting
4. Full migration when ready

### Backward Compatibility
- All existing parameters supported
- Dimension filtering preserved
- Zero balance option maintained
- Output structure compatible

## Testing

Comprehensive test suite with 14 test cases:
- Balance calculations
- Summary totals
- Zero balance filtering
- Grouping by class and type
- Dimension filtering
- Unbalanced detection
- Export functions

Run tests:
```bash
vendor/bin/phpunit tests/Reports/GL/TrialBalanceTest.php
```

## Changelog

### Version 1.0.0 (2025-12-05)
- Initial refactored service implementation
- Three-period balance calculation
- Account grouping by class and type
- Dimension filtering (1 or 2 dimensions)
- Zero balance inclusion/exclusion
- Balance verification
- Comprehensive test coverage
- Helper function integration
- Full backward compatibility with rep708.php
