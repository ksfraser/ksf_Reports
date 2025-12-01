# FrontAccounting Reports Module

Comprehensive reporting system inspired by WebERP reports with modern PHP architecture.

## Overview

The Reports module provides a robust framework for generating business intelligence reports across all functional areas of FrontAccounting. Reports are organized by category and can export to multiple formats (PDF, Excel, CSV).

## Module Structure

```
Reports/
├── Sales/              # Sales and customer reports
├── Purchasing/         # Supplier and purchase order reports  
├── Inventory/          # Stock and inventory reports
├── Financial/          # General ledger and financial reports
├── Manufacturing/      # Work order and production reports
├── ReportService.php   # Base reporting service
├── ReportExporter.php  # Export utilities (PDF, Excel, CSV)
└── Entities/           # Report definition entities
```

## Report Categories

### Sales Reports
- **Order Status Report** - Sales order status by date range, category, location
- **Sales Analysis** - Sales performance by customer, product, period
- **Top Customers** - Highest revenue customers
- **Top Items** - Best selling products
- **Customer Transactions** - Detailed transaction listing
- **Sales by Salesperson** - Commission and sales performance
- **Aged Debtors** - Outstanding receivables aging

### Purchasing Reports
- **Purchase Order Report** - PO status and tracking
- **Supplier Transactions** - Payment and invoice history
- **Aged Suppliers** - Outstanding payables aging
- **Goods Received** - GRN listing and analysis
- **Outstanding GRNs** - Uninvoiced goods received

### Inventory Reports
- **Stock Status** - Current stock levels by location
- **Stock Movements** - Transaction history
- **Stock Valuation** - Inventory value by category/location
- **Reorder Level** - Items below reorder point
- **Stock Negatives** - Negative stock items
- **Aged Stock** - Slow moving inventory
- **BOM Listing** - Bill of materials reports

### Financial Reports
- **Trial Balance** - GL account balances
- **Balance Sheet** - Financial position statement
- **Profit & Loss** - Income statement
- **Cash Flow** - Cash flow statement (indirect method)
- **GL Account Inquiry** - Transaction detail by account
- **Bank Reconciliation** - Bank statement matching
- **Tax Report** - Sales tax summary

### Manufacturing Reports
- **Work Order Status** - Production order tracking
- **Work Order Costing** - Production costs and variance
- **Material Requirements** - MRP demand and supply
- **Production Specification** - Product specifications
- **Work Centre Load** - Capacity utilization

## Features

### Report Framework
- **Flexible Filtering** - Date ranges, categories, locations, customers, etc.
- **Multiple Formats** - PDF, Excel (XLSX), CSV export
- **Parameter Validation** - Input sanitization and validation
- **Performance Optimization** - Query optimization and caching
- **Access Control** - Role-based report access
- **Scheduled Reports** - Automated report generation and distribution

### Technical Features
- **Service-Oriented Design** - Reusable report services
- **Database Abstraction** - Via DBALInterface
- **Event Integration** - PSR-14 events for report generation
- **Logging** - PSR-3 logging for audit trails
- **Dependency Injection** - Constructor-based DI
- **Modern PHP** - PHP 8.0+ features (typed properties, named arguments)

## Installation

The Reports module integrates with existing FrontAccounting modules:

```bash
# Module is auto-discovered via PSR-4 autoloading
composer dump-autoload
```

## Usage Examples

### Generating a Sales Order Status Report

```php
use FA\Modules\Reports\ReportService;
use FA\Modules\Reports\Sales\OrderStatusReport;

$reportService = new ReportService($dbal, $eventDispatcher, $logger);

$parameters = [
    'from_date' => '2025-11-01',
    'to_date' => '2025-11-30',
    'category_id' => 'All',
    'location' => 'All'
];

$report = $reportService->generateReport('sales.order_status', $parameters);

// Export to PDF
$pdf = $reportService->exportToPDF($report);

// Export to Excel
$excel = $reportService->exportToExcel($report);
```

### Stock Valuation Report

```php
$parameters = [
    'location' => 'MAIN',
    'category' => 'All',
    'as_of_date' => '2025-11-30'
];

$report = $reportService->generateReport('inventory.stock_valuation', $parameters);
```

### Financial Trial Balance

```php
$parameters = [
    'from_period' => 1,
    'to_period' => 12,
    'fiscal_year' => 2025,
    'show_zero_balances' => false
];

$report = $reportService->generateReport('financial.trial_balance', $parameters);
```

## Report Service API

### Core Methods

```php
// Generate report
public function generateReport(string $reportType, array $parameters): Report

// Export to PDF
public function exportToPDF(Report $report, array $options = []): string

// Export to Excel
public function exportToExcel(Report $report, array $options = []): string

// Export to CSV
public function exportToCSV(Report $report, array $options = []): string

// Schedule report
public function scheduleReport(string $reportType, array $parameters, string $schedule): int

// Get available reports
public function getAvailableReports(string $category = null): array
```

## Report Categories Integration

### Module-Specific Reports

Reports that only query data from a single module are placed in that module:

- **CRM Module** - Customer analysis, contact reports
- **MRP Module** - Material requirements, shortage reports
- **QualityControl Module** - Test results, inspection reports
- **SupplierPerformance Module** - Evaluation reports, tender analysis

### Cross-Module Reports

Reports that join data across multiple modules are in the Reports module:

- Order fulfillment (Sales + Inventory + Manufacturing)
- Profitability analysis (Sales + Purchasing + Financial)
- Inventory turnover (Sales + Inventory + Purchasing)

## Database Schema

The Reports module uses existing FA tables plus report-specific tables:

```sql
-- Report definitions
CREATE TABLE report_definitions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_code VARCHAR(50) UNIQUE,
    report_name VARCHAR(100),
    category VARCHAR(50),
    description TEXT,
    sql_template TEXT,
    parameters JSON,
    created_at DATETIME,
    updated_at DATETIME
);

-- Scheduled reports
CREATE TABLE scheduled_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_code VARCHAR(50),
    parameters JSON,
    schedule VARCHAR(50),  -- daily, weekly, monthly
    recipients JSON,
    format VARCHAR(10),    -- pdf, excel, csv
    last_run DATETIME,
    next_run DATETIME,
    active BOOLEAN,
    created_at DATETIME
);

-- Report history
CREATE TABLE report_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    report_code VARCHAR(50),
    parameters JSON,
    generated_by INT,
    generated_at DATETIME,
    execution_time FLOAT,
    row_count INT,
    file_path VARCHAR(255)
);
```

## Events

The Reports module dispatches PSR-14 events:

- `ReportGeneratedEvent` - When a report is created
- `ReportExportedEvent` - When a report is exported
- `ReportScheduledEvent` - When a report is scheduled
- `ReportErrorEvent` - When report generation fails

## Configuration

Report settings in `config.php`:

```php
$ReportConfig = [
    'default_format' => 'pdf',
    'max_execution_time' => 300,
    'cache_enabled' => true,
    'cache_ttl' => 3600,
    'export_path' => '/tmp/reports/',
    'max_rows' => 50000,
    'pdf_engine' => 'dompdf',  // dompdf, tcpdf
    'excel_writer' => 'phpspreadsheet'
];
```

## Performance Optimization

### Query Optimization
- Indexed columns for common filters
- Query result caching
- Pagination for large datasets
- Materialized views for complex aggregations

### Export Optimization
- Streaming for large exports
- Chunked processing
- Memory-efficient data handling
- Background processing for heavy reports

## Security

### Access Control
- Role-based report access permissions
- Location-based data filtering
- Audit logging of report generation
- Parameter injection prevention

### Data Protection
- Sensitive data masking options
- Export encryption
- Secure file storage
- Automatic file cleanup

## Testing

Comprehensive test coverage:

```bash
# Run all report tests
php vendor/bin/phpunit tests/Reports/

# Run specific report category tests
php vendor/bin/phpunit tests/Reports/SalesReportsTest.php
```

## Dependencies

- **PHP**: 8.0+
- **FA Database**: DBALInterface
- **PSR-14**: Event Dispatcher
- **PSR-3**: Logger
- **DOMPDF**: PDF generation
- **PhpSpreadsheet**: Excel export

## Migration from WebERP

Reports are redesigned with modern architecture while maintaining familiar functionality:

1. **SQL Queries** - Optimized and parameterized
2. **UI** - Vue.js frontend (separate from module)
3. **Export** - Multiple format support
4. **Scheduling** - Built-in automation
5. **Performance** - Caching and optimization

## Roadmap

- [ ] Interactive dashboards
- [ ] Custom report builder UI
- [ ] Real-time reporting
- [ ] Advanced data visualization
- [ ] Report sharing and collaboration
- [ ] API endpoints for external integration
- [ ] Mobile-responsive report viewing

## License

GPL-3.0 - Same as FrontAccounting

## Support

For issues and feature requests, use the FA issue tracker or contact the development team.
