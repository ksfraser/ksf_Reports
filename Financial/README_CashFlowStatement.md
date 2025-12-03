# Cash Flow Statement Report (Indirect Method)

## Overview

The Cash Flow Statement Report provides comprehensive cash flow analysis using the indirect method, which starts with net income and adjusts for non-cash items and changes in working capital. This critical financial report helps organizations understand their cash generation and usage across operating, investing, and financing activities.

## Features

### Core Functionality
- **Indirect Method Calculation**: Starts with net income, adjusts for non-cash expenses
- **Operating Activities**: Working capital changes, depreciation, amortization
- **Investing Activities**: Capital expenditures, asset sales, investments
- **Financing Activities**: Debt, equity, dividends
- **Period Comparison**: Compare current vs prior periods
- **Quarterly Analysis**: Break down annual cash flow by quarter

### Cash Flow Metrics
- **Free Cash Flow**: Operating cash flow minus capital expenditures
- **Operating Cash Flow Ratio**: Cash from operations / Current liabilities
- **Cash Flow Margin**: Operating cash flow / Revenue (%)
- **Cash Flow Coverage Ratio**: Operating cash flow / Total debt

### Reporting Options
- Standard (annual/periodic)
- Quarterly breakdown
- Year-over-year comparison
- PDF and Excel export

## Installation

### Prerequisites
- FrontAccounting 2.4+
- PHP 8.0+
- Reports Module installed

### Setup
1. Module is auto-loaded via Reports module
2. Register with FA reporting system via hooks
3. Access via Reports menu → General Ledger → Cash Flow Statement

## Usage

### Basic Report Generation

```php
use FA\Modules\Reports\Financial\CashFlowStatementReport;

// Initialize report
$report = new CashFlowStatementReport($dbal, $eventDispatcher, $logger);

// Generate cash flow statement
$result = $report->generate('2024-01-01', '2024-12-31');

// Access components
$operating = $result['operating_activities'];
$investing = $result['investing_activities'];
$financing = $result['financing_activities'];
$metrics = $result['metrics'];
```

### Period Comparison

```php
// Compare current year to prior year
$comparison = $report->generateComparison(
    '2024-01-01', '2024-12-31',  // Current period
    '2023-01-01', '2023-12-31'   // Prior period
);

$variance = $comparison['variance'];
$variancePercent = $comparison['variance_percent'];
```

### Quarterly Analysis

```php
// Generate quarterly breakdown for 2024
$quarterly = $report->generateQuarterly(2024);

foreach ($quarterly as $quarter => $data) {
    echo "$quarter Operating Cash Flow: " . 
         $data['operating_activities']['net_cash_from_operations'];
}
```

### Export Options

```php
// Export to PDF
$pdf = $report->exportToPDF($result);
file_put_contents('cash_flow.pdf', $pdf);

// Export to Excel
$excel = $report->exportToExcel($result);
file_put_contents('cash_flow.xlsx', $excel);
```

## Report Structure

### Operating Activities (Indirect Method)
```
Net Income                              $XXX,XXX
Adjustments for non-cash items:
  + Depreciation and Amortization        $XX,XXX
  + Bad Debt Expense                     $X,XXX
  + Stock-based Compensation             $X,XXX
Changes in working capital:
  - Increase in Accounts Receivable    ($XX,XXX)
  - Increase in Inventory              ($XX,XXX)
  + Increase in Accounts Payable         $XX,XXX
  + Increase in Accrued Expenses         $X,XXX
Net Cash from Operating Activities      $XXX,XXX
```

### Investing Activities
```
Capital Expenditures:
  - Purchase of Equipment              ($XX,XXX)
  - Purchase of Vehicles               ($XX,XXX)
Proceeds from Sales:
  + Sale of Fixed Assets                 $X,XXX
  + Sale of Investments                  $X,XXX
Net Cash from Investing Activities     ($XX,XXX)
```

### Financing Activities
```
Debt:
  + Bank Loan Proceeds                   $XX,XXX
  - Loan Repayments                    ($XX,XXX)
Equity:
  + Share Capital Issued                 $XX,XXX
  - Dividends Paid                     ($XX,XXX)
Net Cash from Financing Activities       $XX,XXX
```

### Summary
```
Beginning Cash Balance                   $XX,XXX
Net Cash Change                          $XX,XXX
Ending Cash Balance                     $XXX,XXX
```

## Key Metrics Explained

### Free Cash Flow (FCF)
```
FCF = Operating Cash Flow - Capital Expenditures
```
Measures cash available after maintaining/expanding asset base. Positive FCF indicates company can fund growth, pay dividends, or reduce debt.

### Operating Cash Flow Ratio
```
OCF Ratio = Operating Cash Flow / Current Liabilities
```
Measures ability to cover short-term liabilities with operating cash. Ratio > 1.0 is healthy.

### Cash Flow Margin
```
CF Margin = (Operating Cash Flow / Revenue) × 100
```
Shows percentage of revenue converted to cash. Higher is better; indicates efficient cash generation.

### Cash Flow Coverage Ratio
```
CF Coverage = Operating Cash Flow / Total Debt
```
Measures ability to repay debt from operations. Higher ratio indicates stronger debt service capacity.

## Integration

### With FA Reporting System
Report is registered as GL Report #711:
- Accessible via Reports → General Ledger → Cash Flow Statement
- Supports standard FA report parameters
- Compatible with FA security permissions

### Dashboard Widget
Provides real-time cash flow summary:
- Last month's operating/investing/financing cash flows
- Net cash change
- Visual chart representation

### Event System
Report generation triggers FA events (when events module available):
- `ReportGenerationStartedEvent`: Before generation
- `ReportGeneratedEvent`: After successful generation

## Database Requirements

### GL Transactions
Queries `gl_trans` table for:
- Revenue and expense transactions (net income calculation)
- Non-cash expense transactions (depreciation, etc.)
- Asset and liability transactions (investing/financing)

### Chart of Accounts
Uses `chart_master` for account classification:
- Account Type 0: Cash/Bank accounts
- Account Types 10-11: Revenue accounts
- Account Types 6-9: Expense accounts
- Account Types 1-2, 5: Working capital accounts
- Account Types 3-4: Fixed assets, long-term investments

## Configuration

### GL Account Mapping
Ensure proper account type assignments in Chart of Accounts:

| Account Type | Description | Usage |
|---|---|---|
| 0 | Cash & Bank | Beginning/ending cash |
| 1-2 | Current Assets | Working capital changes |
| 3-4 | Fixed Assets | Investing activities |
| 5 | Current Liabilities | Working capital changes |
| 6-9 | Expenses | Net income calculation |
| 10-11 | Revenue | Net income calculation |

### Non-Cash Expense Detection
Automatic detection based on account names containing:
- "depreciation"
- "amortization"
- "stock based compensation"
- "bad debt"

Custom patterns can be added by extending the `getNonCashExpenses()` method.

## Best Practices

### 1. Account Classification
Ensure all GL accounts have correct account types for accurate cash flow categorization.

### 2. Transaction Memos
Use descriptive memos for investing/financing transactions to improve report clarity.

### 3. Regular Reconciliation
Compare cash flow statement ending cash with actual bank balances regularly.

### 4. Quarterly Review
Generate quarterly reports to identify trends and seasonal patterns.

### 5. Metric Monitoring
Track key metrics (FCF, OCF Ratio) month-over-month for early warning signs.

## Troubleshooting

### Issue: Ending Cash Doesn't Match Bank Balance
**Solution**: Verify all transactions are posted to correct GL accounts and account types are properly configured.

### Issue: Large Working Capital Swings
**Solution**: Review accounts receivable, inventory, and accounts payable transactions for accuracy.

### Issue: Negative Operating Cash Flow
**Analysis**: Compare to net income. If net income is positive but OCF is negative, investigate working capital changes.

### Issue: Missing Investing/Financing Activities
**Solution**: Ensure transactions have proper memos and are posted to correct account types (3-4 for investing).

## Performance Considerations

- Report caches GL transaction summaries for the period
- Large date ranges may require longer processing time
- Consider using quarterly reports for multi-year analysis
- Database indexes on `gl_trans.tran_date` and `gl_trans.account` improve performance

## Technical Details

### Class: `CashFlowStatementReport`
- **Namespace**: `FA\Modules\Reports\Financial`
- **Dependencies**: DBALInterface, EventDispatcher, LoggerInterface
- **Methods**:
  - `generate(string $startDate, string $endDate, array $options = []): array`
  - `generateComparison(string $currentStart, string $currentEnd, string $priorStart, string $priorEnd): array`
  - `generateQuarterly(int $year): array`
  - `exportToPDF(array $data): string`
  - `exportToExcel(array $data): string`

### Return Structure
```php
[
    'period' => ['start_date' => '...', 'end_date' => '...'],
    'operating_activities' => [
        'net_income' => 0.0,
        'non_cash_adjustments' => [...],
        'non_cash_expenses' => 0.0,
        'working_capital_details' => [...],
        'working_capital_change' => 0.0,
        'net_cash_from_operations' => 0.0
    ],
    'investing_activities' => [
        'transactions' => [...],
        'net_cash_from_investing' => 0.0
    ],
    'financing_activities' => [
        'transactions' => [...],
        'net_cash_from_financing' => 0.0
    ],
    'net_cash_change' => 0.0,
    'summary' => [
        'beginning_cash' => 0.0,
        'net_change' => 0.0,
        'ending_cash' => 0.0
    ],
    'metrics' => [
        'free_cash_flow' => 0.0,
        'operating_cash_flow_ratio' => 0.0,
        'cash_flow_margin' => 0.0,
        'cash_flow_coverage_ratio' => 0.0,
        'capital_expenditures' => 0.0
    ]
]
```

## Support

- **Documentation**: `/modules/Reports/docs/`
- **Issues**: GitHub Issues
- **Community**: FrontAccounting Forum

## License

GPL-3.0 - Same as FrontAccounting

## Changelog

### Version 1.0.0 (2025-12-03)
- Initial release
- Indirect method cash flow statement
- Operating, investing, financing activities
- Period comparison and quarterly analysis
- Cash flow metrics and ratios
- PDF and Excel export
- Dashboard widget
- FA reporting system integration

## Credits

Developed by the FrontAccounting Development Team
Inspired by WebERP's cash flow reporting capabilities
