# Annual Expense Breakdown Report Module

## Overview

Comprehensive annual expense analysis report for FrontAccounting, providing detailed breakdowns by category, budget variance analysis, year-over-year comparisons, and expense trend tracking. Inspired by WebERP's GLProfit_Loss.php but modernized with SOLID principles, TDD methodology, and enterprise reporting capabilities.

## Features

### Core Functionality

- **Category Breakdown**: Automatically categorizes expenses into logical groups (Salaries, Operating, Administrative, Marketing, etc.)
- **Budget Variance Analysis**: Compares actual expenses against budgeted amounts with variance calculations
- **Year-over-Year Comparison**: Compare expenses across multiple fiscal years to identify trends
- **Monthly Trends**: Track expense patterns throughout the fiscal year
- **Quarterly Summaries**: Aggregate expenses by quarter for high-level analysis
- **Top Expense Accounts**: Identify highest spending accounts and categories
- **Multi-Format Export**: Generate reports in PDF, Excel, CSV, or screen display

### Advanced Features

- **Variance Alerts**: Configurable threshold alerts when expenses exceed budget
- **Dashboard Widgets**: Real-time expense monitoring and budget alerts
- **Scheduled Reports**: Automated monthly and quarterly report generation
- **API Endpoints**: RESTful API for external integrations
- **Custom Categories**: Configurable expense category mappings

## Installation

### Requirements

- FrontAccounting 2.4+
- PHP 8.0+
- Reports module (parent dependency)
- MySQL 5.7+ or MariaDB 10.3+

### Installation Steps

1. **Install Reports Module** (if not already installed):
   ```bash
   cd modules
   git submodule add https://github.com/ksfraser/ksf_Reports.git Reports
   ```

2. **Initialize Module**:
   - Navigate to `Setup > Install/Activate Extensions`
   - Select "Annual Expense Breakdown Report"
   - Click "Install"

3. **Configure Permissions**:
   - Go to `Setup > Access Setup`
   - Assign "Annual Expense Reports" permission to appropriate roles

4. **Verify Installation**:
   - Check that menu items appear under "GL Reports"
   - Test report generation with current fiscal year

## Usage

### Basic Report Generation

1. Navigate to `GL Reports > Annual Expense Breakdown`
2. Select fiscal year
3. Choose options:
   - Include Budget Comparison
   - Group by Category
   - Filter by specific category (optional)
4. Click "Generate Report"

### Year-over-Year Comparison

1. Navigate to `GL Reports > Annual Expense Breakdown`
2. Enable "Compare Years" option
3. Select 2-5 years to compare
4. View side-by-side comparison with change percentages

### Budget Variance Analysis

1. Navigate to `GL Reports > Budget Variance Analysis`
2. Select fiscal year
3. Set variance alert threshold (default: 5%)
4. Review categories exceeding threshold
5. Export detailed variance report

### Monthly Trends

1. Navigate to `GL Reports > Expense Trends`
2. Select fiscal year
3. View monthly expense progression
4. Compare against budget by month
5. Identify seasonal patterns

## API Usage

### Get Annual Expense Breakdown

```http
GET /api/reports/annual-expense-breakdown?fiscal_year=2024&include_budget=true
Authorization: Bearer {api_token}
```

**Response:**
```json
{
  "categories": {
    "Salaries & Wages": {
      "accounts": [
        {
          "account_code": "5000",
          "account_name": "Salaries",
          "amount": 250000.00,
          "budget": 240000.00,
          "variance": 10000.00,
          "variance_percent": 4.17
        }
      ],
      "total": 285000.00
    }
  },
  "totals": {
    "actual": 363500.00,
    "budget": 353600.00,
    "variance": 9900.00,
    "variance_percent": 2.80
  },
  "metadata": {
    "fiscal_year": 2024,
    "generated_at": "2024-12-15 10:30:00"
  }
}
```

### Get Expense Trends

```http
GET /api/reports/expense-trends?fiscal_year=2024
Authorization: Bearer {api_token}
```

### Get Budget Variance

```http
GET /api/reports/budget-variance?fiscal_year=2024&threshold=5.0
Authorization: Bearer {api_token}
```

## Configuration

### Module Settings

Access via `Setup > Company Setup > System and General GL Setup > Annual Expense Settings`:

- **Default Variance Threshold**: Alert percentage for budget overruns (default: 5%)
- **Custom Expense Categories**: JSON configuration for custom category mappings
- **Enable Email Alerts**: Send automatic alerts when thresholds exceeded
- **Alert Recipients**: Email addresses for budget alert notifications

### Custom Category Mappings

Edit `Custom Expense Categories` setting with JSON:

```json
{
  "Personnel Costs": ["5000-5099", "5200-5210"],
  "Facility Costs": ["5100-5150"],
  "Marketing": ["5300-5399"],
  "Technology": ["5500-5599"]
}
```

### Scheduled Report Configuration

Configure automated reports via `Setup > Scheduled Tasks`:

- **Monthly Expense Summary**: Generates on 1st of each month
- **Quarterly Variance Report**: Generates at quarter-end
- **Annual Comparison**: Generates at fiscal year-end

## Dashboard Widgets

### Expense Overview Widget

Displays current month-to-date expenses and active account count.

### Budget Alerts Widget

Shows top 5 expense categories exceeding variance threshold.

**To Add Widgets:**
1. Navigate to Dashboard
2. Click "Add Widget"
3. Select "Expense Overview" or "Budget Alerts"
4. Configure refresh interval

## Report Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `fiscal_year` | Integer | Yes | Fiscal year (2000-2100) |
| `include_budget` | Boolean | No | Include budget comparison (default: true) |
| `group_by_category` | Boolean | No | Group by expense category (default: true) |
| `category_filter` | String | No | Filter by specific category |
| `compare_years` | Array | No | Years for comparison (max 5) |
| `variance_threshold` | Decimal | No | Alert threshold percentage (default: 5.0) |
| `format` | String | No | Export format: screen, pdf, excel, csv |

## Expense Categories

Default category mappings (customizable):

| Category | Account Range | Description |
|----------|---------------|-------------|
| Salaries & Wages | 5000-5099 | Personnel costs |
| Operating Expenses | 5100-5199 | Rent, utilities, general operations |
| Administrative Expenses | 5200-5299 | Office supplies, admin costs |
| Marketing & Sales | 5300-5399 | Advertising, promotions |
| Professional Fees | 5400-5499 | Legal, consulting, accounting |
| Technology & IT | 5500-5599 | Software, hardware, IT services |
| Depreciation & Amortization | 5600-5699 | Asset depreciation |
| Interest & Finance Charges | 5700-5799 | Loan interest, bank fees |
| Other Expenses | 5800-6999 | Miscellaneous expenses |

## Security & Permissions

### Access Levels

- **SA_GLREP**: General Ledger Reports (view reports)
- **SA_ANNUALEXPENSE**: Annual Expense Reports (full access)
- **SA_GLSETUP**: GL Setup (configure categories and settings)

### Role Assignment

1. Navigate to `Setup > Access Setup`
2. Select security role
3. Enable appropriate permissions:
   - View: `SA_GLREP`
   - Configure: `SA_GLSETUP`
   - Full Access: `SA_ANNUALEXPENSE`

## Export Formats

### PDF Export
- Professional formatted document
- Company header and branding
- Multi-page support with page numbers
- Summary and detailed sections

### Excel Export
- Formatted spreadsheet with multiple sheets
- Formulas for dynamic calculations
- Charts and graphs
- Pivot table ready data

### CSV Export
- Plain text comma-separated values
- Import into external systems
- Compatible with Excel, Google Sheets

## Troubleshooting

### No Data Displayed

**Issue**: Report shows no data for fiscal year

**Solutions**:
- Verify transactions exist for selected fiscal year
- Check expense account codes are in range 5000-6999
- Ensure chart of accounts is properly configured
- Verify user has access to all required GL accounts

### Budget Data Missing

**Issue**: Budget comparison shows zeros

**Solutions**:
- Confirm budgets entered via `GL Setup > Budget Entry`
- Check budget fiscal year matches report year
- Verify budget accounts match expense accounts

### Performance Issues

**Issue**: Report generation is slow

**Solutions**:
- Add database indexes on `gl_trans.trans_date` and `gl_trans.account`
- Limit year-over-year comparisons to 3-4 years maximum
- Use category filtering for large datasets
- Schedule reports during off-peak hours

### Export Errors

**Issue**: PDF/Excel export fails

**Solutions**:
- Verify DOMPDF and PhpSpreadsheet dependencies installed
- Check PHP memory limit (recommended: 256M+)
- Ensure `tmp/` directory is writable
- Review error logs for specific failures

## Development

### Architecture

Built following SOLID principles:

- **Single Responsibility**: Each method has one clear purpose
- **Open/Closed**: Extensible through configuration, not modification
- **Liskov Substitution**: Report implements standard interface
- **Interface Segregation**: Minimal required dependencies
- **Dependency Inversion**: Depends on abstractions (DBAL, Events, Logger)

### Testing

Comprehensive PHPUnit test suite with 12 test cases:

```bash
# Run all tests
vendor/bin/phpunit tests/Reports/Financial/AnnualExpenseBreakdownTest.php

# Run specific test
vendor/bin/phpunit --filter it_generates_annual_expense_breakdown_for_single_year

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/ tests/Reports/Financial/
```

### Extending Functionality

**Add Custom Report Type:**

```php
class CustomExpenseReport extends AnnualExpenseBreakdownReport
{
    public function generateCustom(array $params): array
    {
        // Custom logic
        return $this->formatResults($data);
    }
}
```

**Add Custom Export Format:**

```php
// In ReportExporter.php
public function exportToCustomFormat(array $data, string $title): string
{
    // Custom export logic
    return $formattedData;
}
```

## Performance Optimization

### Database Indexes

Recommended indexes for optimal performance:

```sql
CREATE INDEX idx_gl_trans_date_account ON gl_trans(trans_date, account);
CREATE INDEX idx_budget_trans_year_account ON budget_trans(fiscal_year, account);
CREATE INDEX idx_chart_master_code ON chart_master(account_code);
```

### Caching

Enable report caching for frequently accessed data:

```php
// In config
'report_cache_ttl' => 3600, // 1 hour
'enable_report_cache' => true
```

## Support & Contributing

### Issues

Report bugs or request features at:
https://github.com/ksfraser/ksf_Reports/issues

### Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/amazing-feature`)
3. Write tests for new functionality
4. Ensure all tests pass
5. Commit changes (`git commit -m 'Add amazing feature'`)
6. Push to branch (`git push origin feature/amazing-feature`)
7. Open Pull Request

### Code Standards

- Follow PSR-12 coding standards
- Include PHPDoc blocks for all methods
- Write unit tests for new features
- Maintain test coverage above 80%

## License

MIT License - see LICENSE file for details

## Changelog

### Version 1.0.0 (2025-01-15)

**Initial Release:**
- Core expense breakdown functionality
- Budget variance analysis
- Year-over-year comparisons
- Monthly and quarterly trends
- Multi-format exports (PDF, Excel, CSV)
- Dashboard widgets
- RESTful API endpoints
- Scheduled report generation
- Comprehensive test suite (12 test cases)
- FrontAccounting hooks integration

## Credits

**Developed by**: KSF Development Team  
**Inspired by**: WebERP GLProfit_Loss.php  
**Based on**: FrontAccounting ERP System  

## Resources

- [FrontAccounting Documentation](https://frontaccounting.com/fawiki/)
- [WebERP Project](https://www.weberp.org/)
- [Module Development Guide](https://github.com/ksfraser/ksf_Reports/wiki)
- [API Reference](https://github.com/ksfraser/ksf_Reports/wiki/api)

---

**Last Updated**: 2025-01-15  
**Module Version**: 1.0.0  
**Compatible With**: FrontAccounting 2.4+
