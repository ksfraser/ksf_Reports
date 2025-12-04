# Working Capital Analysis Report

## Overview

The **Working Capital Analysis** report provides comprehensive insights into your company's liquidity position, efficiency in managing short-term assets and liabilities, and recommendations for optimization.

## Features

### Liquidity Ratios
- **Current Ratio**: Current Assets ÷ Current Liabilities
- **Quick Ratio (Acid Test)**: (Current Assets - Inventory) ÷ Current Liabilities
- **Cash Ratio**: (Cash + Marketable Securities) ÷ Current Liabilities

### Efficiency Metrics
- **Days Sales Outstanding (DSO)**: Average days to collect receivables
- **Days Inventory Outstanding (DIO)**: Average days inventory held
- **Days Payable Outstanding (DPO)**: Average days to pay suppliers
- **Cash Conversion Cycle (CCC)**: DSO + DIO - DPO
- **Working Capital Turnover**: Revenue ÷ Working Capital
- **Working Capital Ratio**: Working Capital ÷ Total Assets

### Analysis Components
- **Health Status**: Automated assessment (Healthy/Caution/Critical)
- **Efficiency Score**: 0-100 composite score
- **Component Breakdown**: Detailed asset and liability composition
- **Trend Analysis**: Historical performance tracking
- **Industry Benchmarks**: Comparison with industry standards
- **Actionable Recommendations**: Prioritized improvement suggestions

## Installation

### Automatic Integration
The report hooks are automatically registered with FrontAccounting:
```php
require_once 'modules/Reports/Financial/hooks_working_capital_analysis.php';
```

### Manual Registration
If needed, manually register the report:
```php
$report_id = 712;
$report_category = RC_GL;
$report_name = 'Working Capital Analysis';
```

## Usage

### Basic Usage
```php
use FA\Modules\Reports\Financial\WorkingCapitalAnalysis;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

// Initialize dependencies
$db = get_db_connection();
$dispatcher = new EventDispatcher();
$logger = get_logger();

// Create report instance
$report = new WorkingCapitalAnalysis($db, $dispatcher, $logger);

// Generate report for the last year
$endDate = date('Y-m-d');
$startDate = date('Y-m-d', strtotime('-1 year'));

$result = $report->generate($startDate, $endDate);
```

### Accessing Results
```php
// Working capital amount
$workingCapital = $result['working_capital'];
echo "Working Capital: $" . number_format($workingCapital, 2);

// Liquidity ratios
$ratios = $result['ratios'];
echo "Current Ratio: " . number_format($ratios['current_ratio'], 2);
echo "Quick Ratio: " . number_format($ratios['quick_ratio'], 2);
echo "Cash Ratio: " . number_format($ratios['cash_ratio'], 2);

// Efficiency metrics
$metrics = $result['metrics'];
echo "Days Sales Outstanding: " . round($metrics['days_sales_outstanding']) . " days";
echo "Cash Conversion Cycle: " . round($metrics['cash_conversion_cycle']) . " days";

// Health assessment
echo "Health Status: " . $result['health_status'];
echo "Efficiency Score: " . round($result['efficiency_score']) . "/100";
```

### Trend Analysis
```php
// Analyze trends over time
$trendData = $report->generateTrend($startDate, $endDate);

echo "Trend Direction: " . $trendData['trend_direction'];

foreach ($trendData['trends'] as $period) {
    echo $period['period'] . ": ";
    echo "Current Ratio " . number_format($period['current_ratio'], 2) . ", ";
    echo "WC $" . number_format($period['working_capital'], 0);
}
```

### Industry Benchmarks
```php
// Compare against industry standards
$benchmarks = $report->generateBenchmarks($startDate, $endDate, 'manufacturing');

$companyRatios = $benchmarks['company_ratios'];
$industryBench = $benchmarks['industry_benchmarks'];
$comparison = $benchmarks['comparison'];

echo "Current Ratio - Company: " . number_format($companyRatios['current_ratio'], 2);
echo "Current Ratio - Industry: " . number_format($industryBench['current_ratio'], 2);
echo "Difference: " . number_format($comparison['current_ratio_vs_benchmark'], 2);
```

## Understanding the Metrics

### Current Ratio
```
Current Ratio = Current Assets ÷ Current Liabilities
```

**Interpretation:**
- **≥ 2.5**: Excessive liquidity, potential inefficiency
- **1.5 - 2.5**: Healthy (optimal range)
- **1.0 - 1.5**: Caution, monitor closely
- **< 1.0**: Critical, liquidity risk

**Example:**
Current Assets: $500,000, Current Liabilities: $300,000
Current Ratio = 500,000 ÷ 300,000 = 1.67 (Healthy)

### Quick Ratio (Acid Test)
```
Quick Ratio = (Current Assets - Inventory) ÷ Current Liabilities
```

More conservative than current ratio as it excludes inventory (less liquid).

**Interpretation:**
- **≥ 1.0**: Strong liquidity without relying on inventory
- **0.5 - 1.0**: Adequate but monitor inventory levels
- **< 0.5**: Heavy dependence on inventory liquidation

### Cash Conversion Cycle
```
CCC = DSO + DIO - DPO
```

Measures the time (in days) between paying suppliers and collecting from customers.

**Interpretation:**
- **< 30 days**: Excellent efficiency
- **30 - 45 days**: Optimal range
- **45 - 60 days**: Room for improvement
- **> 60 days**: Significant cash tied up in operations

**Example:**
- DSO (collect receivables): 30 days
- DIO (hold inventory): 40 days
- DPO (pay suppliers): 35 days
- CCC = 30 + 40 - 35 = 35 days

### Days Sales Outstanding (DSO)
```
DSO = (Accounts Receivable ÷ Annual Revenue) × 365
```

Average number of days to collect payment from customers.

**Targets:**
- **< 30 days**: Excellent collections
- **30 - 45 days**: Industry standard
- **> 60 days**: Collection issues

### Days Inventory Outstanding (DIO)
```
DIO = (Inventory ÷ Cost of Goods Sold) × 365
```

Average number of days inventory is held before sale.

**Targets:**
- **< 30 days**: Fast-moving inventory
- **30 - 60 days**: Normal turnover
- **> 90 days**: Slow-moving, risk of obsolescence

### Days Payable Outstanding (DPO)
```
DPO = (Accounts Payable ÷ Cost of Goods Sold) × 365
```

Average number of days to pay suppliers.

**Strategy:**
- **Optimize**: Extend DPO without damaging supplier relationships
- **Balance**: Early payment discounts vs. cash preservation

## Use Cases

### 1. Liquidity Crisis Prevention
**Objective**: Identify and address liquidity issues before they become critical

**Strategy:**
```php
$result = $report->generate($startDate, $endDate);

if ($result['health_status'] === 'Critical') {
    echo "🔴 LIQUIDITY ALERT: Immediate action required\n";
    
    // Review critical recommendations
    foreach ($result['recommendations'] as $rec) {
        if ($rec['type'] === 'Critical') {
            echo "Issue: " . $rec['issue'] . "\n";
            echo "Action: " . $rec['recommendation'] . "\n";
            
            // Execute priority actions
            foreach ($rec['actions'] as $action) {
                echo "  - " . $action . "\n";
            }
        }
    }
}

// Monitor current ratio trend
if ($result['ratios']['current_ratio'] < 1.0) {
    echo "Working capital deficit: $" . number_format(abs($result['working_capital']), 2) . "\n";
    echo "Increase current assets or reduce current liabilities\n";
}
```

### 2. Cash Flow Optimization
**Objective**: Minimize cash conversion cycle

**Strategy:**
```php
$metrics = $result['metrics'];

echo "Current Cash Conversion Cycle: " . round($metrics['cash_conversion_cycle']) . " days\n\n";

// DSO optimization
if ($metrics['days_sales_outstanding'] > 45) {
    $excessDays = $metrics['days_sales_outstanding'] - 45;
    $dailyRevenue = $data['annual_revenue'] / 365;
    $cashOpportunity = $excessDays * $dailyRevenue;
    
    echo "DSO Opportunity:\n";
    echo "Reduce DSO by " . round($excessDays) . " days\n";
    echo "Free up $" . number_format($cashOpportunity, 2) . " in cash\n";
    echo "Actions: Tighten credit policies, offer early payment discounts\n\n";
}

// DIO optimization
if ($metrics['days_inventory_outstanding'] > 60) {
    $excessDays = $metrics['days_inventory_outstanding'] - 45;
    $dailyCOGS = $data['cost_of_goods_sold'] / 365;
    $inventoryReduction = $excessDays * $dailyCOGS;
    
    echo "DIO Opportunity:\n";
    echo "Reduce DIO by " . round($excessDays) . " days\n";
    echo "Free up $" . number_format($inventoryReduction, 2) . " from inventory\n";
    echo "Actions: Implement JIT, liquidate slow-movers\n\n";
}

// DPO optimization
if ($metrics['days_payable_outstanding'] < 30) {
    $improvementDays = 45 - $metrics['days_payable_outstanding'];
    $dailyCOGS = $data['cost_of_goods_sold'] / 365;
    $cashBenefit = $improvementDays * $dailyCOGS;
    
    echo "DPO Opportunity:\n";
    echo "Extend DPO by " . round($improvementDays) . " days\n";
    echo "Retain $" . number_format($cashBenefit, 2) . " longer\n";
    echo "Actions: Negotiate extended terms with suppliers\n";
}
```

### 3. Working Capital Efficiency Improvement
**Objective**: Achieve optimal working capital turnover

**Strategy:**
```php
$wcTurnover = $metrics['working_capital_turnover'];

echo "Current WC Turnover: " . number_format($wcTurnover, 2) . "x\n";
echo "Target Range: 4-8x\n\n";

if ($wcTurnover < 4) {
    echo "⚠️ Low turnover - too much capital tied up\n";
    echo "Revenue: $" . number_format($data['annual_revenue'], 2) . "\n";
    echo "Working Capital: $" . number_format($result['working_capital'], 2) . "\n";
    
    $targetWC = $data['annual_revenue'] / 6; // Target 6x turnover
    $excessWC = $result['working_capital'] - $targetWC;
    
    echo "Target WC: $" . number_format($targetWC, 2) . "\n";
    echo "Excess WC: $" . number_format($excessWC, 2) . "\n";
    echo "\nActions to improve:\n";
    echo "- Accelerate collections (reduce DSO)\n";
    echo "- Optimize inventory levels (reduce DIO)\n";
    echo "- Extend payables strategically (increase DPO)\n";
    
} elseif ($wcTurnover > 8) {
    echo "⚠️ High turnover - potential liquidity stress\n";
    echo "Consider increasing working capital buffer\n";
}
```

### 4. Component Analysis for Targeted Improvements
**Objective**: Identify which components need attention

**Strategy:**
```php
$components = $result['components'];

echo "Current Assets Breakdown:\n";
echo "Cash: $" . number_format($components['assets']['cash'], 2);
echo " (" . round($components['assets']['cash_percent'], 1) . "%)\n";
echo "A/R: $" . number_format($components['assets']['accounts_receivable'], 2);
echo " (" . round($components['assets']['ar_percent'], 1) . "%)\n";
echo "Inventory: $" . number_format($components['assets']['inventory'], 2);
echo " (" . round($components['assets']['inventory_percent'], 1) . "%)\n\n";

// High inventory percentage
if ($components['assets']['inventory_percent'] > 50) {
    echo "⚠️ Inventory represents " . round($components['assets']['inventory_percent'], 1) . "% of current assets\n";
    echo "Risks: Obsolescence, storage costs, cash tied up\n";
    echo "Actions: Reduce inventory through better demand forecasting\n\n";
}

// High receivables percentage
if ($components['assets']['ar_percent'] > 40) {
    echo "⚠️ Receivables represent " . round($components['assets']['ar_percent'], 1) . "% of current assets\n";
    echo "Risks: Collection difficulties, bad debts\n";
    echo "Actions: Tighten credit policies, improve collections\n\n";
}

// Low cash percentage
if ($components['assets']['cash_percent'] < 10) {
    echo "⚠️ Cash represents only " . round($components['assets']['cash_percent'], 1) . "% of current assets\n";
    echo "Risks: Limited flexibility, inability to seize opportunities\n";
    echo "Actions: Build cash reserves, improve cash flow\n";
}
```

### 5. Benchmarking and Goal Setting
**Objective**: Compare performance against industry standards

**Strategy:**
```php
$benchmarks = $report->generateBenchmarks($startDate, $endDate, 'manufacturing');

echo "Industry Comparison (Manufacturing):\n\n";

// Current Ratio
$crDiff = $benchmarks['comparison']['current_ratio_vs_benchmark'];
echo "Current Ratio:\n";
echo "  Your Company: " . number_format($benchmarks['company_ratios']['current_ratio'], 2) . "\n";
echo "  Industry Avg: " . number_format($benchmarks['industry_benchmarks']['current_ratio'], 2) . "\n";
echo "  Difference: " . ($crDiff > 0 ? "+" : "") . number_format($crDiff, 2);
echo " (" . ($crDiff > 0 ? "Above" : "Below") . " benchmark)\n\n";

// Set improvement goals
if ($crDiff < 0) {
    $targetIncrease = abs($crDiff) * $result['current_liabilities'];
    echo "Goal: Improve current ratio to industry average\n";
    echo "Required increase in current assets: $" . number_format($targetIncrease, 2) . "\n";
    echo "Timeframe: 6-12 months\n";
}
```

## Dashboard Integration

### Dashboard Widget
The report includes a comprehensive dashboard widget showing:
- Health status banner (Healthy/Caution/Critical)
- Efficiency score (0-100)
- Key liquidity ratios
- Cash conversion cycle breakdown
- Top 3 priority recommendations

```php
// Widget is automatically registered
// Access widget data:
$widgetData = working_capital_dashboard_data();
$html = render_working_capital_widget($widgetData);
```

## Best Practices

### 1. Regular Monitoring
- **Daily**: Cash position
- **Weekly**: Receivables aging, payables schedule
- **Monthly**: Full working capital analysis
- **Quarterly**: Trend analysis and benchmark comparison

### 2. Target Ranges by Industry

**Manufacturing:**
- Current Ratio: 1.5 - 2.0
- Quick Ratio: 1.0 - 1.3
- CCC: 40 - 60 days

**Retail:**
- Current Ratio: 1.2 - 1.8
- Quick Ratio: 0.5 - 0.8
- CCC: 20 - 40 days

**Services:**
- Current Ratio: 1.3 - 2.0
- Quick Ratio: 1.0 - 1.5
- CCC: 15 - 30 days

### 3. Seasonal Adjustments
For businesses with seasonal patterns:
- Calculate ratios at peak and off-peak periods
- Use 13-month rolling averages for trends
- Maintain higher liquidity buffers before peak seasons

### 4. Action Prioritization
Prioritize improvements based on:
1. **Critical Issues** (Current ratio < 1.0): Immediate action
2. **High Impact** (CCC > 60 days): Significant cash benefit
3. **Quick Wins** (Easy collection improvements): Fast implementation
4. **Long-term** (Supplier negotiations): Sustained benefit

### 5. Holistic Approach
Working capital optimization affects:
- **Sales**: Credit policies impact customer relationships
- **Operations**: Inventory levels affect production
- **Purchasing**: Payment terms affect supplier relationships
- **Finance**: Cash flow affects borrowing needs

Balance efficiency with business relationships and operational needs.

## Troubleshooting

### Current Ratio Too Low
**Symptoms**: Ratio < 1.0, negative working capital
**Causes**:
- Excessive short-term debt
- Slow collections
- High inventory levels
- Operating losses

**Solutions**:
1. Accelerate collections
2. Extend payables
3. Convert short-term to long-term debt
4. Reduce inventory
5. Inject equity capital

### Cash Conversion Cycle Too Long
**Symptoms**: CCC > 60 days, cash flow issues
**Causes**:
- Slow customer payments (high DSO)
- Excess inventory (high DIO)
- Fast supplier payments (low DPO)

**Solutions**:
1. Implement aggressive collection strategies
2. Optimize inventory through better forecasting
3. Negotiate extended payment terms
4. Consider supply chain financing

### Efficiency Score Too Low
**Symptoms**: Score < 50
**Causes**:
- Multiple inefficiencies across metrics
- Ratios outside optimal ranges

**Solutions**:
1. Focus on biggest gap first
2. Implement recommendations in priority order
3. Set incremental improvement goals
4. Track progress monthly

## Export Options

### PDF Export
```php
$result = $report->generate($startDate, $endDate);
$pdf = $report->exportToPDF($result, 'Working Capital Analysis');
```

### Excel Export
```php
$result = $report->generate($startDate, $endDate);
$excel = $report->exportToExcel($result, 'Working Capital Analysis');
```

## Technical Details

### Database Tables Used
- `chart_master`: Balance sheet accounts
- `debtor_trans`: Sales transactions (for revenue)
- `debtor_trans_details`: Line items (for COGS)

### Performance Considerations
- Indexes recommended on `tran_date` fields
- Consider materialized views for large datasets
- Cache results for dashboard widgets

## Support

For issues or questions:
- Check FrontAccounting forums
- Review module documentation
- Contact development team

## Changelog

### Version 1.0.0 (2025-12-04)
- Initial release
- Liquidity ratios (current, quick, cash)
- Efficiency metrics (DSO, DIO, DPO, CCC)
- Health status assessment
- Efficiency scoring system
- Actionable recommendations
- Trend analysis
- Industry benchmarks
- Dashboard widget
- PDF/Excel export support
