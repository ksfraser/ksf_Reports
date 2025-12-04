# Sales Analysis Dashboard Report

## Overview

The **Sales Analysis Dashboard** provides comprehensive sales analytics including revenue trends, customer insights, product performance, regional analysis, and forecasting capabilities to drive data-driven sales decisions.

## Features

### Core Sales Metrics
- **Total Revenue**: Aggregate sales revenue for the period
- **Total Orders**: Number of completed sales transactions
- **Total Customers**: Unique customer count
- **Average Order Value (AOV)**: Revenue per order
- **Daily Average**: Average daily sales revenue
- **Conversion Rate**: Quote-to-order conversion percentage

### Customer Analytics
- **New Customer Acquisition**: First-time buyers in period
- **Customer Retention Rate**: Returning customer percentage
- **Customer Lifetime Value (LTV)**: Total value per customer
- **New vs. Returning Split**: Customer composition analysis

### Product Performance
- **Top Products by Revenue**: Best-selling products
- **Product Mix Analysis**: Category contribution percentages
- **Product Trends**: Growth/decline patterns by product

### Sales Trends & Forecasting
- **Monthly Sales Trends**: Revenue trajectory analysis
- **Growth Rate Analysis**: Period-over-period comparison
- **Year-over-Year (YoY) Comparison**: Annual performance
- **Sales Forecasting**: Predictive revenue projections
- **Seasonality Analysis**: Peak and low sales periods

### Geographic & Segmentation
- **Sales by Region**: Geographic performance breakdown
- **Sales by Category**: Product category analysis
- **Salesman Performance**: Individual sales rep metrics

## Installation

### Automatic Integration
```php
require_once 'modules/Reports/Sales/hooks_sales_analysis_dashboard.php';
```

### Manual Registration
```php
$report_id = 402;
$report_category = RC_SALES;
$report_name = 'Sales Analysis Dashboard';
```

## Usage

### Basic Usage
```php
use FA\Modules\Reports\Sales\SalesAnalysisDashboard;

// Initialize
$db = get_db_connection();
$dispatcher = new EventDispatcher();
$logger = get_logger();

$report = new SalesAnalysisDashboard($db, $dispatcher, $logger);

// Generate dashboard for last 30 days
$endDate = date('Y-m-d');
$startDate = date('Y-m-d', strtotime('-30 days'));

$result = $report->generate($startDate, $endDate);
```

### Accessing Results
```php
// Summary metrics
$summary = $result['summary'];
echo "Revenue: $" . number_format($summary['total_revenue'], 2);
echo "Orders: " . $summary['total_orders'];
echo "AOV: $" . number_format($summary['average_order_value'], 2);
echo "Conversion Rate: " . round($summary['conversion_rate'], 1) . "%";

// Customer metrics
$customers = $result['customer_metrics'];
echo "New Customers: " . $customers['new_customers'];
echo "Retention Rate: " . round($customers['retention_rate'], 1) . "%";

// Top performers
foreach ($result['top_products'] as $product) {
    echo $product['description'] . ": $" . number_format($product['revenue'], 2);
}

foreach ($result['top_customers'] as $customer) {
    echo $customer['name'] . ": $" . number_format($customer['revenue'], 2);
}

// Trend
echo "Sales Trend: " . $result['trend']; // Growing, Declining, Stable
```

### Growth Analysis
```php
// Compare current period to previous period
$growth = $report->generateGrowthAnalysis($startDate, $endDate);

echo "Current Period Revenue: $" . number_format($growth['current_period']['revenue'], 2);
echo "Previous Period Revenue: $" . number_format($growth['previous_period']['revenue'], 2);
echo "Growth Rate: " . round($growth['growth_rate'], 1) . "%";

if ($growth['growth_rate'] > 0) {
    echo "📈 Sales are growing!";
} elseif ($growth['growth_rate'] < 0) {
    echo "📉 Sales are declining - action needed";
}
```

### Regional Analysis
```php
// Analyze sales by geographic region
$regional = $report->generateByRegion($startDate, $endDate);

foreach ($regional['regions'] as $region) {
    echo $region['region'] . ": ";
    echo "$" . number_format($region['revenue'], 2) . " ";
    echo "(" . $region['orders'] . " orders)";
}
```

### Category Performance
```php
// Product category breakdown
$categories = $report->generateByCategory($startDate, $endDate);

foreach ($categories['categories'] as $cat) {
    echo $cat['category'] . ": ";
    echo "$" . number_format($cat['revenue'], 2) . " ";
    echo "(" . number_format($cat['quantity']) . " units)";
}
```

### Seasonality Analysis
```php
// Identify peak sales periods
$seasonality = $report->generateSeasonality('2023-01-01', '2024-12-31');

echo "Peak Month: " . $seasonality['peak_month'];

foreach ($seasonality['seasonality'] as $month) {
    echo "Month " . $month['month'] . ": ";
    echo "$" . number_format($month['avg_revenue'], 2) . " avg";
}
```

### Salesman Performance
```php
// Individual sales rep analysis
$performance = $report->generateSalesmanPerformance($startDate, $endDate);

foreach ($performance['salespeople'] as $salesperson) {
    echo $salesperson['name'] . ":\n";
    echo "  Revenue: $" . number_format($salesperson['revenue'], 2) . "\n";
    echo "  Orders: " . $salesperson['orders'] . "\n";
    echo "  Customers: " . $salesperson['customers'] . "\n";
    echo "  AOV: $" . number_format($salesperson['revenue'] / $salesperson['orders'], 2);
}
```

### Sales Forecasting
```php
// Predict next 3 months revenue
$forecast = $report->generateForecast($startDate, $endDate, 3);

foreach ($forecast['forecast']['next_3_months'] as $period) {
    echo "Period " . $period['period'] . ": ";
    echo "$" . number_format($period['forecast_revenue'], 2) . " (projected)";
}

echo "Trend Slope: " . round($forecast['forecast']['trend_slope'], 2);
```

## Understanding the Metrics

### Average Order Value (AOV)
```
AOV = Total Revenue ÷ Total Orders
```

**Interpretation:**
- Higher AOV = More revenue per transaction
- Track over time to measure upselling success
- Benchmark against industry standards

**Improvement Strategies:**
- Bundle products together
- Implement minimum order thresholds for free shipping
- Offer volume discounts
- Train sales team on upselling

### Customer Retention Rate
```
Retention Rate = (Returning Customers ÷ Total Customers) × 100
```

**Benchmarks:**
- **80%+**: Excellent retention
- **60-80%**: Good retention
- **40-60%**: Average, room for improvement
- **<40%**: Critical - focus on retention

### Conversion Rate
```
Conversion Rate = (Orders ÷ Quotes) × 100
```

**Industry Standards:**
- **B2B**: 20-30% is typical
- **B2C**: 2-5% for cold traffic, 10-30% for warm leads

### Growth Rate
```
Growth Rate = ((Current - Previous) ÷ Previous) × 100
```

**Healthy Growth:**
- **10-25% YoY**: Sustainable growth
- **>25% YoY**: High growth (ensure scalability)
- **Negative**: Declining (investigate causes)

## Use Cases

### 1. Monthly Sales Review
**Objective**: Assess overall sales performance

**Strategy:**
```php
$thisMonth = date('Y-m-01');
$today = date('Y-m-d');

$result = $report->generate($thisMonth, $today);

echo "Month-to-Date Performance:\n";
echo "Revenue: $" . number_format($result['summary']['total_revenue'], 2);
echo " from " . $result['summary']['total_orders'] . " orders\n";
echo "Daily Avg: $" . number_format($result['summary']['daily_average'], 2) . "\n";
echo "Trend: " . $result['trend'] . "\n\n";

// Compare to same period last month
$lastMonth = date('Y-m-01', strtotime('-1 month'));
$lastMonthDay = date('Y-m-d', strtotime('-1 month'));
$lastResult = $report->generate($lastMonth, $lastMonthDay);

$change = $result['summary']['total_revenue'] - $lastResult['summary']['total_revenue'];
$percentChange = ($change / $lastResult['summary']['total_revenue']) * 100;

echo "vs Last Month: " . ($change > 0 ? "+" : "") . "$" . number_format($change, 2);
echo " (" . round($percentChange, 1) . "%)\n";
```

### 2. Customer Acquisition vs. Retention Focus
**Objective**: Balance new customer acquisition with retention

**Strategy:**
```php
$metrics = $result['customer_metrics'];

$newCustomerRate = $metrics['new_customer_rate'];
$retentionRate = $metrics['retention_rate'];

echo "Customer Composition:\n";
echo "New: " . round($newCustomerRate, 1) . "% (" . $metrics['new_customers'] . " customers)\n";
echo "Returning: " . round($retentionRate, 1) . "% (" . $metrics['returning_customers'] . " customers)\n\n";

// Strategic recommendations
if ($newCustomerRate > 50) {
    echo "⚠️ High new customer rate - focus on retention\n";
    echo "Actions:\n";
    echo "- Implement customer loyalty program\n";
    echo "- Improve post-purchase follow-up\n";
    echo "- Analyze why customers don't return\n";
} elseif ($retentionRate < 40) {
    echo "🔴 Low retention rate - critical issue\n";
    echo "Actions:\n";
    echo "- Survey customers about experience\n";
    echo "- Review product quality and service\n";
    echo "- Create win-back campaigns\n";
} else {
    echo "✓ Balanced customer composition\n";
}
```

### 3. Product Portfolio Optimization
**Objective**: Identify winning products and underperformers

**Strategy:**
```php
// Get top and category analysis
$topProducts = $result['top_products'];
$productMix = $report->generateProductMix($startDate, $endDate);

echo "Product Performance Analysis:\n\n";

// Top 5 products (80/20 rule)
echo "Top 5 Products (Drive majority of revenue):\n";
$top5Revenue = 0;
for ($i = 0; $i < min(5, count($topProducts)); $i++) {
    $product = $topProducts[$i];
    $top5Revenue += $product['revenue'];
    echo ($i + 1) . ". " . $product['description'];
    echo " - $" . number_format($product['revenue'], 2) . "\n";
}

$totalRevenue = $result['summary']['total_revenue'];
$top5Percent = ($top5Revenue / $totalRevenue) * 100;

echo "\nTop 5 represent " . round($top5Percent, 1) . "% of total revenue\n";

if ($top5Percent > 80) {
    echo "⚠️ Heavy concentration - diversification recommended\n";
}

// Category mix
echo "\nCategory Distribution:\n";
foreach ($productMix['product_mix'] as $cat) {
    echo $cat['category'] . ": " . round($cat['percentage'], 1) . "%\n";
}
```

### 4. Regional Performance & Expansion
**Objective**: Identify high-performing regions and expansion opportunities

**Strategy:**
```php
$regional = $report->generateByRegion($startDate, $endDate);

echo "Regional Performance:\n\n";

// Sort by revenue
$regions = $regional['regions'];
usort($regions, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

foreach ($regions as $i => $region) {
    $revenue = $region['revenue'];
    $orders = $region['orders'];
    $aov = $orders > 0 ? $revenue / $orders : 0;
    
    echo ($i + 1) . ". " . $region['region'] . "\n";
    echo "   Revenue: $" . number_format($revenue, 2) . "\n";
    echo "   Orders: " . $orders . "\n";
    echo "   AOV: $" . number_format($aov, 2) . "\n";
    
    if ($i == 0) {
        echo "   ⭐ Top performing region\n";
    } elseif ($revenue < $regions[0]['revenue'] * 0.5) {
        echo "   💡 Expansion opportunity\n";
    }
    echo "\n";
}
```

### 5. Sales Team Performance Management
**Objective**: Evaluate and optimize sales team effectiveness

**Strategy:**
```php
$performance = $report->generateSalesmanPerformance($startDate, $endDate);

echo "Sales Team Performance:\n\n";

$salespeople = $performance['salespeople'];
$totalRevenue = array_sum(array_column($salespeople, 'revenue'));

foreach ($salespeople as $i => $person) {
    $revenue = $person['revenue'];
    $orders = $person['orders'];
    $customers = $person['customers'];
    $contribution = ($revenue / $totalRevenue) * 100;
    $aov = $orders > 0 ? $revenue / $orders : 0;
    
    echo ($i + 1) . ". " . $person['name'] . "\n";
    echo "   Revenue: $" . number_format($revenue, 2);
    echo " (" . round($contribution, 1) . "% of total)\n";
    echo "   Orders: " . $orders . " | Customers: " . $customers . "\n";
    echo "   AOV: $" . number_format($aov, 2) . "\n";
    
    // Performance rating
    if ($contribution > 20) {
        echo "   🏆 Top Performer\n";
    } elseif ($contribution < 5) {
        echo "   ⚠️ Needs Support\n";
    }
    echo "\n";
}

// Team metrics
$avgRevenue = $totalRevenue / count($salespeople);
echo "Team Average Revenue: $" . number_format($avgRevenue, 2) . "\n";
```

### 6. Forecasting & Planning
**Objective**: Plan inventory, staffing, and marketing based on projections

**Strategy:**
```php
// Analyze historical trends
$forecast = $report->generateForecast($startDate, $endDate, 3);
$seasonality = $report->generateSeasonality(date('Y-01-01', strtotime('-1 year')), date('Y-12-31'));

echo "Sales Forecast (Next 3 Months):\n\n";

foreach ($forecast['forecast']['next_3_months'] as $period) {
    echo "Period +" . $period['period'] . " month: ";
    echo "$" . number_format($period['forecast_revenue'], 2) . "\n";
}

echo "\nTrend: " . ($forecast['forecast']['trend_slope'] > 0 ? "📈 Growing" : "📉 Declining") . "\n";
echo "Confidence: " . $forecast['forecast']['confidence'] . "\n\n";

// Seasonal planning
echo "Seasonal Insights:\n";
echo "Peak Month: " . $seasonality['peak_month'] . "\n";
echo "Action: Increase inventory and staffing before peak season\n";
```

## Dashboard Integration

### Dashboard Widget
The report includes a comprehensive dashboard widget with:
- Trend indicator (Growing/Declining/Stable) with growth rate
- Key metrics grid (Revenue, Orders, Customers, AOV)
- Customer insights (Retention, New, Returning)
- Top 5 products and customers
- Quick access to full dashboard

```php
// Widget automatically registered
$widgetData = sales_analysis_dashboard_data();
$html = render_sales_analysis_widget($widgetData);
```

## Best Practices

### 1. Regular Review Cadence
- **Daily**: Quick metrics check (revenue, orders)
- **Weekly**: Trend analysis, top products/customers
- **Monthly**: Comprehensive review with growth analysis
- **Quarterly**: Strategic planning with forecasts

### 2. Set SMART Goals
- **Specific**: "Increase AOV by 15%"
- **Measurable**: Track metric weekly
- **Achievable**: Based on historical data
- **Relevant**: Aligned with business strategy
- **Time-bound**: "By end of Q2"

### 3. Actionable Insights
Don't just report numbers - take action:
- **Revenue Down**: Analyze causes, launch promotions
- **Low Conversion**: Improve sales process, training
- **High AOV**: Document winning tactics, replicate
- **Poor Retention**: Implement loyalty program

### 4. Segment Analysis
Break down metrics by:
- **Customer Type**: B2B vs. B2C
- **Product Category**: High-margin vs. volume
- **Region**: Urban vs. rural
- **Sales Channel**: Online vs. in-store

### 5. Combine with Other Reports
- **Product Profitability**: Revenue + Profit = Complete picture
- **Customer LTV**: Acquisition cost + Lifetime value
- **Working Capital**: Sales growth + Cash flow impact

## Troubleshooting

### Low Conversion Rate
**Symptoms**: High quotes, few orders
**Solutions**:
- Review pricing competitiveness
- Improve quote follow-up process
- Provide sales training
- Simplify ordering process

### Declining Sales Trend
**Symptoms**: Negative growth rate
**Solutions**:
- Analyze lost customers
- Review competitive landscape
- Launch marketing campaigns
- Introduce new products

### Poor Customer Retention
**Symptoms**: Low retention rate
**Solutions**:
- Implement customer feedback system
- Create loyalty program
- Improve post-sale support
- Personalize communications

## Export Options

### PDF Export
```php
$result = $report->generate($startDate, $endDate);
$pdf = $report->exportToPDF($result, 'Sales Analysis Dashboard');
```

### Excel Export
```php
$result = $report->generate($startDate, $endDate);
$excel = $report->exportToExcel($result, 'Sales Analysis Dashboard');
```

## Technical Details

### Database Tables Used
- `debtor_trans`: Sales transaction headers
- `debtor_trans_details`: Line item details
- `debtors_master`: Customer information
- `stock_master`: Product information
- `stock_category`: Product categories
- `sales_areas`: Geographic regions
- `salesman`: Sales representative data

### Performance Considerations
- Indexes on `tran_date`, `debtor_no`, `stock_id`
- Use date range filters to limit dataset
- Cache dashboard widget results
- Consider summary tables for large datasets

## Support

For issues or questions:
- Check FrontAccounting forums
- Review module documentation
- Contact development team

## Changelog

### Version 1.0.0 (2025-12-04)
- Initial release
- Comprehensive sales metrics (revenue, orders, customers, AOV)
- Customer analytics (acquisition, retention, LTV)
- Product performance analysis
- Regional and category breakdowns
- Sales trend analysis
- Growth rate calculations
- Year-over-year comparisons
- Seasonality analysis
- Salesman performance tracking
- Sales forecasting capabilities
- Dashboard widget with key metrics
- PDF/Excel export support
