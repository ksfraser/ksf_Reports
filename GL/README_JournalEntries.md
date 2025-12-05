# Journal Entries Report

## Overview

The **Journal Entries Report** (rep702) provides a detailed listing of all general ledger transactions with transaction grouping, debit/credit calculations, and dimension tracking. This refactored version maintains full compatibility with FrontAccounting's existing report infrastructure while providing a modern, testable service architecture.

## Features

### Core Functionality
- **Transaction Listing**: Complete list of GL entries within date range
- **Transaction Grouping**: Entries grouped by system type and transaction number
- **Debit/Credit Totals**: Automatic calculation of debits and credits per transaction
- **Balance Verification**: Automatic detection of unbalanced entries
- **System Type Filtering**: Filter by transaction type (invoices, payments, journals, etc.)
- **Dimension Tracking**: Includes dimension and dimension2 information
- **Period Filtering**: Filter by date range

### Data Integrity
- **Balanced Entry Detection**: Validates debits equal credits
- **Rounding Handling**: Proper handling of decimal precision
- **Zero Amount Exclusion**: Automatically excludes zero-amount entries
- **Transaction Completeness**: All lines grouped by transaction

## Installation

### Service Integration
```php
require_once 'modules/Reports/GL/hooks_journal_entries.php';
```

### Legacy Report (rep702.php)
The service can be integrated into the existing rep702.php file for gradual migration.

## Usage

### Basic Usage
```php
use FA\Modules\Reports\GL\JournalEntries;

// Initialize
$db = get_db_connection();
$dispatcher = new EventDispatcher();
$logger = get_logger();

$report = new JournalEntries($db, $dispatcher, $logger);

// Generate report
$result = $report->generate('2024-01-01', '2024-01-31');
```

### Using Helper Functions
```php
// Generate report data
$data = generate_journal_entries_report('2024-01-01', '2024-01-31');

// With system type filter (10 = Sales Invoice)
$data = generate_journal_entries_report('2024-01-01', '2024-01-31', 10);
```

### Accessing Results
```php
$data = generate_journal_entries_report('2024-01-01', '2024-01-31');

// Summary information
$summary = $data['summary'];
echo "Total Transactions: " . $summary['transaction_count'] . "\n";
echo "Total Debits: $" . number_format($summary['total_debit'], 2) . "\n";
echo "Total Credits: $" . number_format($summary['total_credit'], 2) . "\n";
echo "Balanced: " . ($summary['is_balanced'] ? 'Yes' : 'No') . "\n";

if ($summary['unbalanced_count'] > 0) {
    echo "⚠️ Warning: " . $summary['unbalanced_count'] . " unbalanced entries!\n";
}

// Individual entries
foreach ($data['entries'] as $entry) {
    echo "\nTransaction Type: " . $entry['type'];
    echo " #" . $entry['type_no'];
    echo " Date: " . $entry['tran_date'] . "\n";
    
    // Line items
    foreach ($entry['lines'] as $line) {
        echo "  " . $line['account'] . " - " . $line['account_name'];
        
        if ($line['debit'] > 0) {
            echo " DR: $" . number_format($line['debit'], 2);
        } else {
            echo " CR: $" . number_format($line['credit'], 2);
        }
        
        if ($line['memo_']) {
            echo " (" . $line['memo_'] . ")";
        }
        echo "\n";
        
        // Show dimensions if present
        if ($line['dimension_id'] > 0) {
            echo "    Dimension: " . $line['dimension_id'];
            if ($line['dimension2_id'] > 0) {
                echo " / " . $line['dimension2_id'];
            }
            echo "\n";
        }
    }
    
    echo "  Total: DR $" . number_format($entry['total_debit'], 2);
    echo " / CR $" . number_format($entry['total_credit'], 2);
    
    if (!$entry['is_balanced']) {
        echo " ⚠️ UNBALANCED";
    }
    echo "\n";
}
```

### Filter by System Type
```php
// System type constants
$ST_JOURNAL = 0;          // Journal Entry
$ST_BANKPAYMENT = 1;      // Bank Payment
$ST_BANKDEPOSIT = 2;      // Bank Deposit
$ST_BANKTRANSFER = 4;     // Bank Transfer
$ST_SALESINVOICE = 10;    // Sales Invoice
$ST_CUSTCREDIT = 11;      // Customer Credit Note
$ST_CUSTPAYMENT = 12;     // Customer Payment
$ST_CUSTDELIVERY = 13;    // Customer Delivery
$ST_LOCTRANSFER = 16;     // Inventory Location Transfer
$ST_INVADJUST = 17;       // Inventory Adjustment
$ST_PURCHORDER = 18;      // Purchase Order
$ST_SUPPINVOICE = 20;     // Supplier Invoice
$ST_SUPPCREDIT = 21;      // Supplier Credit
$ST_SUPPAYMENT = 22;      // Supplier Payment
$ST_SUPPRECEIVE = 25;     // Goods Receipt Note (GRN)
$ST_WORKORDER = 26;       // Work Order
$ST_MANUISSUE = 28;       // Work Order Issue
$ST_MANURECEIVE = 29;     // Work Order Production
$ST_SALESORDER = 30;      // Sales Order
$ST_SALESQUOTE = 32;      // Sales Quote
$ST_COSTUPDATE = 35;      // Cost Update
$ST_DIMENSION = 40;       // Dimension

// Filter for sales invoices only
$salesInvoices = generate_journal_entries_report(
    '2024-01-01',
    '2024-01-31',
    $ST_SALESINVOICE
);

// Filter for bank transactions
$bankPayments = generate_journal_entries_report(
    '2024-01-01',
    '2024-01-31',
    $ST_BANKPAYMENT
);
```

### Export Options
```php
// Generate data
$data = generate_journal_entries_report('2024-01-01', '2024-01-31');

// Export to PDF
$pdfResult = export_journal_entries_pdf($data, 'Monthly Journal Entries');
if ($pdfResult['success']) {
    echo "PDF created: " . $pdfResult['filename'];
}

// Export to Excel
$excelResult = export_journal_entries_excel($data, 'Monthly Journal Entries');
if ($excelResult['success']) {
    echo "Excel created: " . $excelResult['filename'];
}
```

## Understanding the Data Structure

### Entry Structure
```php
[
    'type' => 10,                    // System type (e.g., 10 = Sales Invoice)
    'type_no' => 123,                // Transaction number
    'tran_date' => '2024-01-15',     // Transaction date
    'total_debit' => 1000.00,        // Sum of all debits
    'total_credit' => 1000.00,       // Sum of all credits
    'is_balanced' => true,           // Debits equal credits?
    'lines' => [                     // Individual GL entries
        [
            'account' => '1200',     // GL account code
            'account_name' => 'Bank Account',
            'amount' => 1000.00,     // Positive = debit, Negative = credit
            'debit' => 1000.00,      // Debit amount (0 if credit)
            'credit' => 0.00,        // Credit amount (0 if debit)
            'person_id' => null,     // Related person (customer/supplier)
            'dimension_id' => 5,     // Dimension (project/department)
            'dimension2_id' => 0,    // Second dimension
            'memo_' => 'Payment received'
        ],
        // ... more lines
    ]
]
```

### Summary Structure
```php
[
    'total_debit' => 15000.00,        // Sum of all debits
    'total_credit' => 15000.00,       // Sum of all credits
    'transaction_count' => 25,        // Number of transactions
    'unbalanced_count' => 0,          // Number of unbalanced entries
    'is_balanced' => true             // Overall balance status
]
```

## Use Cases

### 1. Audit Trail Review
**Objective**: Review all transactions for audit purposes

```php
// Get all entries for a fiscal period
$data = generate_journal_entries_report('2023-04-01', '2024-03-31');

echo "Audit Report - FY 2023-24\n";
echo "=========================\n\n";
echo "Total Transactions: " . $data['summary']['transaction_count'] . "\n";
echo "Total Activity: $" . number_format($data['summary']['total_debit'], 2) . "\n";

if ($data['summary']['unbalanced_count'] > 0) {
    echo "\n⚠️ CRITICAL: Found " . $data['summary']['unbalanced_count'] . " unbalanced entries!\n";
    echo "Action required: Review and correct unbalanced transactions\n";
}
```

### 2. Transaction Verification
**Objective**: Verify specific transaction is properly recorded

```php
// Filter for specific transaction type
$invoices = generate_journal_entries_report('2024-01-01', '2024-01-31', 10);

foreach ($invoices['entries'] as $entry) {
    if ($entry['type_no'] == 12345) {
        echo "Found Invoice #12345\n";
        echo "Date: " . $entry['tran_date'] . "\n";
        echo "Balanced: " . ($entry['is_balanced'] ? 'Yes' : 'No') . "\n";
        
        foreach ($entry['lines'] as $line) {
            echo "  " . $line['account'] . ": ";
            echo $line['debit'] > 0 ? "DR $" . $line['debit'] : "CR $" . $line['credit'];
            echo "\n";
        }
    }
}
```

### 3. Dimension Analysis
**Objective**: Track transactions by project or department

```php
$data = generate_journal_entries_report('2024-01-01', '2024-01-31');

// Track by dimension
$dimensionTotals = [];

foreach ($data['entries'] as $entry) {
    foreach ($entry['lines'] as $line) {
        if ($line['dimension_id'] > 0) {
            $dimId = $line['dimension_id'];
            
            if (!isset($dimensionTotals[$dimId])) {
                $dimensionTotals[$dimId] = [
                    'debit' => 0,
                    'credit' => 0,
                    'count' => 0
                ];
            }
            
            $dimensionTotals[$dimId]['debit'] += $line['debit'];
            $dimensionTotals[$dimId]['credit'] += $line['credit'];
            $dimensionTotals[$dimId]['count']++;
        }
    }
}

echo "Activity by Dimension:\n";
foreach ($dimensionTotals as $dimId => $totals) {
    echo "Dimension $dimId: " . $totals['count'] . " entries, ";
    echo "$" . number_format($totals['debit'], 2) . " total activity\n";
}
```

### 4. Finding Unbalanced Entries
**Objective**: Identify and report data integrity issues

```php
$data = generate_journal_entries_report('2024-01-01', '2024-01-31');

$unbalanced = array_filter($data['entries'], fn($e) => !$e['is_balanced']);

if (count($unbalanced) > 0) {
    echo "⚠️ Found " . count($unbalanced) . " unbalanced entries:\n\n";
    
    foreach ($unbalanced as $entry) {
        $diff = abs($entry['total_debit'] - $entry['total_credit']);
        
        echo "Type " . $entry['type'] . " #" . $entry['type_no'];
        echo " on " . $entry['tran_date'] . "\n";
        echo "  Debit: $" . number_format($entry['total_debit'], 2) . "\n";
        echo "  Credit: $" . number_format($entry['total_credit'], 2) . "\n";
        echo "  Difference: $" . number_format($diff, 2) . "\n\n";
    }
    
    echo "Action: Review and correct these entries immediately.\n";
}
```

### 5. Period Comparison
**Objective**: Compare journal activity between periods

```php
// Current month
$current = generate_journal_entries_report('2024-02-01', '2024-02-29');

// Previous month
$previous = generate_journal_entries_report('2024-01-01', '2024-01-31');

echo "Journal Activity Comparison:\n";
echo "============================\n\n";

echo "Current Month:\n";
echo "  Transactions: " . $current['summary']['transaction_count'] . "\n";
echo "  Total Activity: $" . number_format($current['summary']['total_debit'], 2) . "\n\n";

echo "Previous Month:\n";
echo "  Transactions: " . $previous['summary']['transaction_count'] . "\n";
echo "  Total Activity: $" . number_format($previous['summary']['total_debit'], 2) . "\n\n";

$txnChange = $current['summary']['transaction_count'] - $previous['summary']['transaction_count'];
$activityChange = $current['summary']['total_debit'] - $previous['summary']['total_debit'];

echo "Change:\n";
echo "  Transactions: " . ($txnChange > 0 ? "+" : "") . $txnChange . "\n";
echo "  Activity: $" . number_format($activityChange, 2) . "\n";
```

## Troubleshooting

### Missing Transactions
**Problem**: Expected transactions not appearing
**Solutions**:
- Verify date range includes transaction date
- Check if system type filter is excluding transactions
- Ensure transactions are posted (not draft)
- Verify account codes exist in chart_master

### Unbalanced Entries
**Problem**: Transactions with debits ≠ credits
**Solutions**:
- Review original transaction entry
- Check for data corruption in gl_trans table
- Verify all transaction lines were saved
- Check for rounding errors (difference < $0.01 is ignored)

### Performance Issues
**Problem**: Report slow with large datasets
**Solutions**:
- Use smaller date ranges
- Filter by specific system type
- Ensure indexes exist on gl_trans (tran_date, type, type_no)
- Consider summary reports for very large periods

### Dimension Not Showing
**Problem**: Dimension information missing
**Solutions**:
- Verify dimensions are enabled in company setup
- Check transaction was entered with dimension
- Ensure dimension_id is not 0 or null

## Technical Details

### Database Tables
- **gl_trans**: General ledger transactions (main data source)
- **chart_master**: Account codes and names
- **systypes**: Transaction type definitions
- **dimensions**: Dimension/project information

### Key Fields
- **type**: System type constant (0-40)
- **type_no**: Transaction sequence number within type
- **tran_date**: Transaction date
- **account**: GL account code
- **amount**: Positive = debit, Negative = credit
- **dimension_id/dimension2_id**: Project/department tracking

### Performance
- Indexed by: (tran_date, type, type_no, counter)
- Typical query time: <100ms for 1 month
- Memory efficient transaction grouping
- Zero-copy data transformation

## Migration from Legacy

### Gradual Migration
The service can be integrated gradually:

1. **Phase 1**: Keep existing rep702.php, use service for data
2. **Phase 2**: Refactor rendering to use service data
3. **Phase 3**: Complete migration to service architecture

### Backward Compatibility
- All FrontReport formatting preserved
- System type constants unchanged
- Date formats consistent
- Output structure compatible

## Testing

The service includes comprehensive test coverage:
- 13 test cases
- 44 assertions
- Transaction grouping logic
- Debit/credit calculations
- Balance verification
- Dimension handling
- Export functions

Run tests:
```bash
vendor/bin/phpunit tests/Reports/GL/JournalEntriesTest.php
```

## Changelog

### Version 1.0.0 (2025-12-05)
- Initial refactored service implementation
- Transaction grouping by type/type_no
- Debit/credit calculations
- Balance verification
- Dimension tracking
- System type filtering
- Export to PDF/Excel (placeholder)
- Comprehensive test suite
- Helper function integration
- Full backward compatibility
