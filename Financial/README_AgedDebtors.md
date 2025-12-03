# Aged Debtors Report Module

## Overview

Comprehensive accounts receivable aging analysis for FrontAccounting, providing detailed AR breakdowns, collection prioritization, credit limit monitoring, and DSO (Days Sales Outstanding) metrics. Inspired by WebERP's AgedDebtors.php but modernized with SOLID principles, TDD methodology, and enterprise AR management capabilities.

## Features

### Core Functionality

- **Standard Aging Buckets**: Current, 1-30, 31-60, 61-90, 90+ days aging analysis
- **Custom Aging Periods**: Configurable aging bucket definitions
- **Customer Analysis**: Detailed breakdown by customer with contact information
- **Credit Limit Monitoring**: Automatic alerts for customers over/near credit limits
- **Collection Priority Scoring**: Algorithmic ranking of customers needing collection action
- **DSO Calculation**: Days Sales Outstanding metric with trending
- **Currency Grouping**: Multi-currency support with consolidation
- **Transaction Drill-Down**: Detailed invoice-level aging information

### Advanced Features

- **Collection Letters**: Automated generation of collection letter data
- **AR Metrics Dashboard**: Key performance indicators and trends
- **Credit Alerts**: Real-time monitoring of credit limit utilization
- **Payment History**: Customer payment pattern analysis
- **Collection Effectiveness Index**: Measure of collection performance
- **Percentage Analysis**: Aging distribution by percentage
- **Customer Type Filtering**: Retail, wholesale, distributor segmentation

## Installation

### Requirements

- FrontAccounting 2.4+
- PHP 8.0+
- Reports module (parent dependency)
- MySQL 5.7+ or MariaDB 10.3+

### Installation Steps

1. **Ensure Reports Module Installed**:
   ```bash
   cd modules
   # Reports module should already be present
   ```

2. **Initialize Module**:
   - Navigate to `Setup > Install/Activate Extensions`
   - Select "Aged Debtors Report"
   - Click "Install"

3. **Configure Permissions**:
   - Go to `Setup > Access Setup`
   - Assign appropriate permissions:
     - `SA_SALESREP` - View reports
     - `SA_ARCOLLECTION` - Collection management
     - `SA_SALESMANAGER` - Credit alerts

4. **Configure Settings**:
   - Navigate to `Setup > Company Setup > AR Settings`
   - Set aging bucket preferences
   - Configure credit alert thresholds
   - Set up email notifications

## Usage

### Basic AR Aging Report

1. Navigate to `AR Reports > Aged Debtors Report`
2. Select "As Of Date" (typically end of period)
3. Choose options:
   - Group by Currency
   - Show Percentage Breakdown
   - Include Contact Information
4. Click "Generate Report"

### Collection Priority List

1. Navigate to `AR Reports > Collection Priority List`
2. Select date
3. Review prioritized customer list
4. Priority levels:
   - **Critical** (75-100): Immediate action required
   - **High** (50-74): Follow-up within 1 week
   - **Medium** (25-49): Monitor closely
   - **Low** (0-24): Normal follow-up

### Credit Limit Alerts

1. Navigate to `AR Reports > Credit Limit Alerts`
2. View customers:
   - **Over Limit**: Exceeding credit limit
   - **Near Limit**: 80%+ utilization
3. Take appropriate credit hold actions

### AR Metrics Dashboard

1. Navigate to `AR Reports > AR Metrics Dashboard`
2. View key metrics:
   - **DSO (Days Sales Outstanding)**: Target < 45 days
   - **Total Receivables**: Current AR balance
   - **Customers Owing**: Number of active debtors
   - **Average Days Overdue**: Weighted average
   - **Collection Effectiveness**: % current + 30 days

## API Usage

### Get Aged Debtors Report

```http
GET /api/reports/aged-debtors?as_of_date=2024-12-31&group_by_currency=true
Authorization: Bearer {api_token}
```

**Response:**
```json
{
  "customers": [
    {
      "customer_id": 1,
      "customer_name": "ABC Corporation",
      "current": 5000.00,
      "days_30": 3000.00,
      "days_60": 2000.00,
      "days_90": 1000.00,
      "days_over_90": 500.00,
      "total_due": 11500.00,
      "credit_limit": 15000.00,
      "currency": "USD"
    }
  ],
  "summary": {
    "total_outstanding": 11500.00,
    "current": 5000.00,
    "days_30": 3000.00,
    "days_60": 2000.00,
    "days_90": 1000.00,
    "days_over_90": 500.00,
    "customer_count": 1,
    "percentages": {
      "current": 43.48,
      "days_30": 26.09,
      "days_60": 17.39,
      "days_90": 8.70,
      "days_over_90": 4.35
    }
  },
  "metadata": {
    "as_of_date": "2024-12-31",
    "generated_at": "2024-12-31 10:30:00"
  }
}
```

### Get Collection Priority

```http
GET /api/reports/collection-priority?as_of_date=2024-12-31
Authorization: Bearer {api_token}
```

### Get Credit Alerts

```http
GET /api/reports/credit-alerts?as_of_date=2024-12-31
Authorization: Bearer {api_token}
```

### Get AR Metrics

```http
GET /api/reports/ar-metrics?as_of_date=2024-12-31&calculate_dso=true
Authorization: Bearer {api_token}
```

## Configuration

### Module Settings

Access via `Setup > Company Setup > AR Settings`:

- **Default Aging Buckets**: Comma-separated periods (default: 0,30,60,90)
- **Credit Alert Threshold**: Utilization % to trigger alerts (default: 80%)
- **Enable Auto Collection Letters**: Automatically generate letters (default: false)
- **Collection Letter Days**: Days overdue to trigger letters (default: 30,60,90)
- **AR Alert Recipients**: Email addresses for alerts
- **DSO Target Days**: Target DSO for performance alerts (default: 45)

### Aging Bucket Customization

Modify aging periods in settings:
```
0,15,30,45,60,90  // More granular aging
0,30,60           // Simplified 3-bucket aging
0,45,90,180       // Extended aging periods
```

### Priority Score Weights

Priority scoring algorithm weights (configurable in code):
```php
const PRIORITY_WEIGHTS = [
    'amount_overdue' => 0.40,  // 40% weight on dollar amount
    'days_overdue' => 0.35,     // 35% weight on aging
    'over_credit_limit' => 0.15, // 15% weight on credit status
    'payment_history' => 0.10    // 10% weight on payment patterns
];
```

## Dashboard Widgets

### AR Aging Summary Widget

Displays current aging breakdown:
- Current balance
- 1-30 days
- 31-60 days
- Over 60 days
- Total AR

### Credit Alerts Widget

Shows top 5 customers over or near credit limits with utilization percentages.

### Collection Priority Widget

Displays top 5 customers requiring immediate collection action with 90+ day amounts.

### DSO Metric Widget

Shows current Days Sales Outstanding with visual status indicator:
- **Good**: < 45 days (green)
- **Warning**: 45-60 days (yellow)
- **Critical**: > 60 days (red)

**To Add Widgets:**
1. Navigate to Dashboard
2. Click "Add Widget"
3. Select desired AR widget
4. Configure position and refresh interval

## Report Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `as_of_date` | Date | Yes | Date to calculate aging as of |
| `aging_buckets` | Array | No | Custom aging periods [0,30,60,90] |
| `customer_type_filter` | String | No | Filter: retail, wholesale, distributor |
| `group_by_currency` | Boolean | No | Group results by currency (default: false) |
| `show_percentages` | Boolean | No | Include percentage breakdown (default: false) |
| `include_contacts` | Boolean | No | Include contact info (default: false) |
| `show_credit_alerts` | Boolean | No | Highlight credit issues (default: false) |
| `overdue_only` | Boolean | No | Show only overdue amounts (default: false) |
| `min_amount` | Decimal | No | Minimum balance to include (default: 0) |
| `format` | String | No | Export format: screen, pdf, excel, csv |

## Collection Priority Algorithm

### Priority Score Calculation (0-100)

```
Priority Score = 
  (Amount Score × 0.40) +
  (Days Score × 0.35) +
  (Credit Score × 0.15) +
  (History Score × 0.10)
```

**Amount Score**: Normalized by total outstanding (max 100)
**Days Score**: Based on % in 90+ days bucket (max 100)
**Credit Score**: Percentage over credit limit (max 100)
**History Score**: Based on payment patterns (max 100)

### Priority Levels

- **Critical** (75-100): Contact within 24 hours, escalate to management
- **High** (50-74): Contact within 1 week, send formal notice
- **Medium** (25-49): Contact within 2 weeks, standard follow-up
- **Low** (0-24): Monitor, routine reminders

## Security & Permissions

### Access Levels

- **SA_SALESREP**: View aging reports and AR metrics
- **SA_ARCOLLECTION**: Full collection management access
- **SA_SALESMANAGER**: Credit alerts and limit management
- **SA_AGEDDEBTORS**: Specific aged debtors report access

### Role Assignment

1. Navigate to `Setup > Access Setup`
2. Select security role
3. Enable appropriate permissions:
   - Sales Rep: `SA_SALESREP`
   - Collection Agent: `SA_ARCOLLECTION`
   - Sales Manager: `SA_SALESMANAGER`

## Export Formats

### PDF Export
- Professional formatted document
- Aging breakdown tables
- Summary totals and percentages
- Customer contact information (if enabled)

### Excel Export
- Multiple sheets: Summary, Details, Alerts
- Formulas for dynamic calculations
- Pivot table ready data
- Conditional formatting for aging categories

### CSV Export
- Comma-separated values
- Import to external CRM/collection systems
- Integration with third-party analytics

## Scheduled Tasks

### Weekly AR Aging Report
- **Frequency**: Every Monday
- **Action**: Email aging report to AR team
- **Recipients**: Configured in settings

### Daily Credit Alerts
- **Frequency**: Daily at 8 AM
- **Action**: Email credit limit alerts
- **Threshold**: 80% utilization or over limit

### Monthly Collection Letters
- **Frequency**: 1st of each month
- **Action**: Generate collection letter data
- **Triggers**: 30, 60, 90 days overdue

## Troubleshooting

### No Customers Displayed

**Issue**: Report shows no data

**Solutions**:
- Verify outstanding invoices exist in `debtor_trans` table
- Check `as_of_date` is current or future
- Ensure customer has balance > 0.01
- Verify user has access to customers (location/branch filters)

### Incorrect Aging Calculations

**Issue**: Amounts in wrong aging buckets

**Solutions**:
- Verify `due_date` populated on invoices
- Check system date/timezone settings
- Ensure `as_of_date` parameter is correct
- Review custom aging bucket definitions

### DSO Calculation Zero

**Issue**: DSO metric shows 0.00

**Solutions**:
- Confirm sales transactions exist in last 365 days
- Check invoice type = 10 (sales invoice)
- Verify `tran_date` populated correctly
- Ensure receivables balance > 0

### Credit Alerts Not Showing

**Issue**: Over-limit customers not appearing

**Solutions**:
- Verify `credit_limit` set on customer master
- Check `show_credit_alerts` parameter enabled
- Confirm customer balance exceeds threshold
- Review credit alert threshold setting (default 80%)

### Performance Issues

**Issue**: Report generation slow

**Solutions**:
- Add indexes on `debtor_trans.debtor_no`, `due_date`, `type`
- Limit date range for detailed reports
- Use summary report instead of detailed
- Filter by customer type or currency
- Schedule large reports during off-peak hours

## Development

### Architecture

Built following SOLID principles:

- **Single Responsibility**: Each method handles one specific calculation
- **Open/Closed**: Extensible through configuration, not modification
- **Liskov Substitution**: Implements standard report interface
- **Interface Segregation**: Minimal required dependencies
- **Dependency Inversion**: Depends on abstractions (DBAL, Events, Logger)

### Testing

Comprehensive PHPUnit test suite with 15 test cases:

```bash
# Run all tests
vendor/bin/phpunit tests/Reports/Financial/AgedDebtorsReportTest.php

# Run specific test
vendor/bin/phpunit --filter it_generates_aged_debtors_report_with_standard_buckets

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/ tests/Reports/Financial/
```

### Test Coverage

- Aging bucket calculations
- Credit limit alerts
- Collection priority scoring
- DSO metrics
- Currency grouping
- Customer type filtering
- Transaction drill-down
- Parameter validation
- Edge case handling

### Extending Functionality

**Add Custom Priority Factor:**

```php
private function calculatePriorityScore(array $debtor): float
{
    // Add custom factor
    $customScore = $this->calculateCustomFactor($debtor);
    
    $compositeScore = 
        ($amountScore * 0.35) +
        ($daysScore * 0.30) +
        ($creditScore * 0.15) +
        ($historyScore * 0.10) +
        ($customScore * 0.10);  // Custom 10% weight
    
    return round($compositeScore, 2);
}
```

**Add Custom Aging Bucket:**

```php
// In configuration
'aging_buckets' => [0, 15, 30, 45, 60, 90, 120, 180]
```

## Performance Optimization

### Database Indexes

Recommended indexes for optimal performance:

```sql
CREATE INDEX idx_debtor_trans_aging ON debtor_trans(debtor_no, type, due_date);
CREATE INDEX idx_debtor_trans_balance ON debtor_trans(debtor_no, type, alloc);
CREATE INDEX idx_debtors_credit ON debtors_master(debtor_no, credit_limit);
CREATE INDEX idx_debtor_trans_date ON debtor_trans(tran_date, type);
```

### Query Optimization

- Use date range filters to limit transaction scan
- Leverage indexed columns in WHERE clauses
- Group aggregations at database level
- Cache summary data for dashboard widgets

### Caching Strategy

```php
// In configuration
'cache_aging_results' => true,
'cache_ttl' => 3600,  // 1 hour
'cache_by_date' => true  // Cache per as_of_date
```

## Integration Examples

### Export to Excel for Analysis

```php
$report = new AgedDebtorsReport($dbal, $dispatcher, $logger);
$result = $report->generate([
    'as_of_date' => '2024-12-31',
    'include_contacts' => true,
    'show_percentages' => true
]);

$exporter = new ReportExporter($dbal);
$exporter->exportToExcel($result, 'Aged_Debtors_2024-12-31');
```

### Generate Collection Letters

```php
$report = new AgedDebtorsReport($dbal, $dispatcher, $logger);
$letters = $report->generateCollectionLetters([
    'as_of_date' => '2024-12-31',
    'min_days_overdue' => 60
]);

foreach ($letters['collection_required'] as $customer) {
    sendCollectionEmail($customer);
}
```

### Monitor Credit Limits

```php
$report = new AgedDebtorsReport($dbal, $dispatcher, $logger);
$alerts = $report->generateCreditAlerts([
    'as_of_date' => date('Y-m-d')
]);

foreach ($alerts['over_limit'] as $customer) {
    applyCreditHold($customer['customer_id']);
    notifySalesManager($customer);
}
```

## Support & Contributing

### Issues

Report bugs or request features:
https://github.com/ksfraser/ksf_Reports/issues

### Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/ar-enhancement`)
3. Write tests for new functionality
4. Ensure all tests pass
5. Commit changes (`git commit -m 'Add AR enhancement'`)
6. Push to branch (`git push origin feature/ar-enhancement`)
7. Open Pull Request

### Code Standards

- Follow PSR-12 coding standards
- Include PHPDoc blocks for all methods
- Write unit tests for new features
- Maintain test coverage above 80%
- Use type declarations (strict_types=1)

## License

MIT License - see LICENSE file for details

## Changelog

### Version 1.0.0 (2025-01-15)

**Initial Release:**
- Core aged debtors reporting with standard aging buckets
- Credit limit monitoring and alerts
- Collection priority scoring algorithm
- DSO (Days Sales Outstanding) calculation
- Multi-currency support with grouping
- Transaction-level drill-down
- Dashboard widgets (4 widgets)
- RESTful API endpoints (4 endpoints)
- Scheduled tasks (3 tasks)
- Comprehensive test suite (15 test cases)
- FrontAccounting hooks integration
- Collection letter generation
- AR metrics dashboard

## Credits

**Developed by**: KSF Development Team  
**Inspired by**: WebERP AgedDebtors.php  
**Based on**: FrontAccounting ERP System  

## Resources

- [FrontAccounting Documentation](https://frontaccounting.com/fawiki/)
- [WebERP Project](https://www.weberp.org/)
- [AR Best Practices Guide](https://github.com/ksfraser/ksf_Reports/wiki/ar-best-practices)
- [Collection Management Guide](https://github.com/ksfraser/ksf_Reports/wiki/collection-management)

---

**Last Updated**: 2025-01-15  
**Module Version**: 1.0.0  
**Compatible With**: FrontAccounting 2.4+
