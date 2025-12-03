# Product Profitability Analysis Report

## Overview

The **Product Profitability Analysis** report provides comprehensive product-level profitability metrics with cost breakdowns, pricing recommendations, and strategic insights for inventory and pricing decisions.

## Features

### Core Profitability Metrics
- **Gross Profit Analysis**: Revenue minus cost of goods sold
- **Gross Margin Percentage**: Profitability rate per product
- **Contribution Margin**: Revenue minus variable costs (material + labor)
- **Contribution Margin %**: Variable cost efficiency
- **Per-Unit Economics**: Revenue, cost, and profit per unit sold

### Cost Structure Analysis
- **Material Costs**: Direct material expenses
- **Labor Costs**: Direct labor allocation
- **Overhead Costs**: Indirect manufacturing costs
- **Cost Breakdown %**: Percentage distribution by component

### Strategic Analytics
- **Break-Even Analysis**: Units required to cover fixed costs
- **Pricing Recommendations**: Target prices for 30%, 40%, 50% margins
- **Revenue Contribution**: Each product's % of total revenue
- **Profit Contribution**: Each product's % of total profit
- **Top Performers**: 10 most profitable products
- **Underperformers**: 10 least profitable products
- **Profitability Trends**: Monthly trend analysis

## Installation

### Automatic Integration
The report hooks are automatically registered with FrontAccounting:
```php
require_once 'modules/Reports/Sales/hooks_product_profitability_analysis.php';
```

### Manual Registration
If needed, manually register the report:
```php
$report_id = 401;
$report_category = RC_SALES;
$report_name = 'Product Profitability Analysis';
```

## Usage

### Basic Usage
```php
use FA\Modules\Reports\Sales\ProductProfitabilityAnalysis;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

// Initialize dependencies
$db = get_db_connection();
$dispatcher = new EventDispatcher();
$logger = get_logger();

// Create report instance
$report = new ProductProfitabilityAnalysis($db, $dispatcher, $logger);

// Generate report (last 90 days)
$endDate = date('Y-m-d');
$startDate = date('Y-m-d', strtotime('-90 days'));

$result = $report->generate($startDate, $endDate);
```

### Accessing Results
```php
// Summary metrics
$summary = $result['summary'];
echo "Total Revenue: $" . number_format($summary['total_revenue'], 2);
echo "Total Profit: $" . number_format($summary['total_profit'], 2);
echo "Overall Margin: " . round($summary['overall_margin_percent'], 1) . "%";

// Product-level details
foreach ($result['products'] as $product) {
    echo $product['description'] . ": ";
    echo "$" . number_format($product['gross_profit'], 2) . " profit ";
    echo "(" . round($product['gross_margin_percent'], 1) . "% margin)";
}

// Top performers
foreach ($result['top_profitable'] as $product) {
    echo "⭐ " . $product['description'];
    echo " - $" . number_format($product['gross_profit'], 2);
}

// Products needing attention
foreach ($result['least_profitable'] as $product) {
    echo "⚠️  " . $product['description'];
    echo " - " . round($product['gross_margin_percent'], 1) . "% margin";
}
```

### Category-Based Analysis
```php
// Analyze by product category
$categoryResults = $report->generateByCategory($startDate, $endDate);

foreach ($categoryResults as $category => $data) {
    echo "Category: " . $category;
    echo "Products: " . $data['product_count'];
    echo "Revenue: $" . number_format($data['total_revenue'], 2);
    echo "Margin: " . round($data['overall_margin_percent'], 1) . "%";
}
```

### Trend Analysis
```php
// Analyze profitability trend for a specific product
$stockId = 'PROD001';
$trendData = $report->generateTrend($stockId, $startDate, $endDate);

echo "Product: " . $trendData['product_info']['description'];
echo "Trend: " . $trendData['trend']; // improving, declining, stable

foreach ($trendData['monthly_data'] as $month) {
    echo $month['month'] . ": ";
    echo "$" . number_format($month['revenue'], 2) . " revenue, ";
    echo "$" . number_format($month['profit'], 2) . " profit, ";
    echo round($month['margin_percent'], 1) . "% margin";
}
```

## Understanding the Metrics

### Gross Profit vs. Contribution Margin

**Gross Profit** = Revenue - Cost of Goods Sold (COGS)
- Includes all manufacturing costs (material + labor + overhead)
- Shows total profitability after direct product costs
- Used for overall product viability

**Contribution Margin** = Revenue - Variable Costs (Material + Labor)
- Excludes fixed overhead costs
- Shows contribution to covering fixed costs and profit
- Used for pricing and volume decisions

### Margin Benchmarks
- **High Margin**: ≥40% - Premium products, strong pricing power
- **Healthy Margin**: 25-40% - Competitive, sustainable
- **Low Margin**: 15-25% - Volume-dependent, watch costs
- **Marginal**: 0-15% - Review pricing or discontinue
- **Unprofitable**: <0% - Immediate action required

### Cost Structure Interpretation
Typical cost distributions:
- **Material-Heavy** (80%+ material): Commodity products, focus on supplier negotiations
- **Labor-Heavy** (30%+ labor): Custom/artisan products, optimize workflows
- **Overhead-Heavy** (15%+ overhead): Capital-intensive products, maximize utilization

### Break-Even Analysis
```
Break-Even Units = Fixed Costs ÷ (Price per Unit - Variable Cost per Unit)
```
Shows minimum sales volume needed for profitability.

### Pricing Recommendations
The report suggests target prices to achieve desired margins:
```
Target Price = Cost ÷ (1 - Target Margin %)
```

Example: $100 cost, 40% target margin
```
Target Price = $100 ÷ (1 - 0.40) = $166.67
```

## Use Cases

### 1. Product Portfolio Optimization
**Objective**: Identify products to expand, maintain, or discontinue

**Strategy**:
```php
$result = $report->generate($startDate, $endDate);

// Expand high-margin, high-volume products
foreach ($result['top_profitable'] as $product) {
    if ($product['gross_margin_percent'] >= 40 && $product['units_sold'] > 100) {
        echo "EXPAND: " . $product['description'];
    }
}

// Review low-margin products
foreach ($result['products'] as $product) {
    if ($product['gross_margin_percent'] < 15) {
        echo "REVIEW: " . $product['description'];
        echo " - Consider price increase or cost reduction";
    }
}

// Discontinue unprofitable products
if ($result['summary']['unprofitable_products'] > 0) {
    foreach ($result['products'] as $product) {
        if ($product['gross_profit'] < 0) {
            echo "DISCONTINUE: " . $product['description'];
        }
    }
}
```

### 2. Pricing Strategy Development
**Objective**: Optimize product pricing for target profitability

**Strategy**:
```php
foreach ($result['products'] as $product) {
    $currentMargin = $product['gross_margin_percent'];
    $targetMargin = 35; // Target 35% margin
    
    if ($currentMargin < $targetMargin) {
        echo "Product: " . $product['description'];
        echo "Current Price: $" . number_format($product['revenue_per_unit'], 2);
        echo "Current Margin: " . round($currentMargin, 1) . "%";
        
        // Get pricing recommendation
        if (isset($product['pricing_recommendations'])) {
            $recommended = $product['pricing_recommendations']['target_40_percent'];
            echo "Recommended Price: $" . number_format($recommended, 2);
            $increase = (($recommended / $product['revenue_per_unit']) - 1) * 100;
            echo "Price Increase: " . round($increase, 1) . "%";
        }
    }
}
```

### 3. Cost Reduction Targets
**Objective**: Identify cost-saving opportunities by component

**Strategy**:
```php
foreach ($result['products'] as $product) {
    $costBreakdown = $product['cost_breakdown'];
    
    // High material cost - negotiate with suppliers
    if ($costBreakdown['material_percent'] > 80) {
        $saving = $product['material_cost'] * 0.05; // 5% reduction target
        $impactOnMargin = ($saving / $product['revenue']) * 100;
        
        echo "Product: " . $product['description'];
        echo "Material Cost: $" . number_format($product['material_cost'], 2);
        echo "5% Reduction = $" . number_format($saving, 2);
        echo "Margin Impact: +" . round($impactOnMargin, 1) . "%";
    }
    
    // High labor cost - process improvement
    if ($costBreakdown['labor_percent'] > 20) {
        echo "Labor-intensive: " . $product['description'];
        echo "Labor Cost: $" . number_format($product['labor_cost'], 2);
        echo "Consider automation or workflow optimization";
    }
}
```

### 4. Sales Team Performance
**Objective**: Guide sales focus to high-margin products

**Strategy**:
```php
// Create sales priority list
$highMarginProducts = array_filter($result['products'], function($p) {
    return $p['gross_margin_percent'] >= 35;
});

usort($highMarginProducts, function($a, $b) {
    return $b['gross_margin_percent'] <=> $a['gross_margin_percent'];
});

echo "PRIORITY PRODUCTS FOR SALES TEAM:\n";
foreach (array_slice($highMarginProducts, 0, 10) as $i => $product) {
    echo ($i + 1) . ". " . $product['description'];
    echo " - Margin: " . round($product['gross_margin_percent'], 1) . "%";
    echo " - Profit per Sale: $" . number_format($product['profit_per_unit'], 2);
}
```

### 5. Profitability Monitoring
**Objective**: Track profitability changes over time

**Strategy**:
```php
// Compare current quarter to previous quarter
$currentStart = date('Y-m-d', strtotime('-90 days'));
$currentEnd = date('Y-m-d');
$previousStart = date('Y-m-d', strtotime('-180 days'));
$previousEnd = date('Y-m-d', strtotime('-90 days'));

$currentResults = $report->generate($currentStart, $currentEnd);
$previousResults = $report->generate($previousStart, $previousEnd);

$marginChange = $currentResults['summary']['overall_margin_percent'] - 
                $previousResults['summary']['overall_margin_percent'];

if ($marginChange > 0) {
    echo "✓ Profitability improving: +" . round($marginChange, 1) . "%";
} else {
    echo "⚠️ Profitability declining: " . round($marginChange, 1) . "%";
}

// Identify products with declining profitability
foreach ($currentResults['products'] as $currentProduct) {
    $stockId = $currentProduct['stock_id'];
    $previousProduct = array_filter($previousResults['products'], 
        fn($p) => $p['stock_id'] === $stockId);
    
    if (!empty($previousProduct)) {
        $previousProduct = reset($previousProduct);
        $profitChange = $currentProduct['gross_profit'] - $previousProduct['gross_profit'];
        
        if ($profitChange < -500) { // $500+ profit drop
            echo "📉 " . $currentProduct['description'];
            echo " - Profit down $" . number_format(abs($profitChange), 2);
        }
    }
}
```

## Dashboard Integration

### Dashboard Widget
The report includes a dashboard widget showing:
- Last 90 days summary (revenue, profit, margin)
- Top 5 most profitable products
- Bottom 3 products needing attention
- Alert count for unprofitable products

```php
// Widget is automatically registered
// Access widget data:
$widgetData = product_profitability_dashboard_data();
$html = render_product_profitability_widget($widgetData);
```

## Export Options

### PDF Export
```php
$result = $report->generate($startDate, $endDate);
$pdf = $report->exportToPDF($result, 'Product Profitability Report');
// Returns PDF file path
```

### Excel Export
```php
$result = $report->generate($startDate, $endDate);
$excel = $report->exportToExcel($result, 'Product Profitability Analysis');
// Returns Excel file path
```

## Best Practices

### 1. Regular Monitoring
- Run monthly for all products
- Run weekly for high-volume products
- Run quarterly for strategic planning

### 2. Data Quality
- Ensure accurate cost data in inventory system
- Update standard costs regularly
- Verify material/labor/overhead allocations

### 3. Action Thresholds
Set clear thresholds for decision-making:
- Margin <15%: Immediate pricing review
- Margin <0%: Discontinuation consideration
- Volume + High Margin: Expansion candidates
- Declining trend for 3+ months: Investigation required

### 4. Cross-Functional Collaboration
Share insights with:
- **Sales Team**: Focus on high-margin products
- **Purchasing**: Target cost reductions on high-material products
- **Production**: Optimize processes for labor-heavy products
- **Management**: Strategic portfolio decisions

### 5. Pricing Psychology
When implementing price increases:
- Small increments (3-5%) are less noticeable
- Bundle with value-adds or service improvements
- Communicate value, not just price
- Test with small customer segments first

## Technical Details

### Database Tables Used
- `stock_master`: Product information
- `debtor_trans`: Sales transaction headers
- `debtor_trans_details`: Sales line items with revenue and cost

### Performance Considerations
- Indexes recommended on:
  - `debtor_trans.tran_date`
  - `debtor_trans_details.stock_id`
  - `stock_master.stock_id`
- Large date ranges may impact performance
- Consider scheduled report generation for large datasets

### Events Dispatched
- `product_profitability.analysis.started`
- `product_profitability.analysis.completed`
- `product_profitability.data.processed`

## Troubleshooting

### No Data Returned
- Verify date range includes sales transactions
- Check that products have associated sales
- Ensure cost data is populated in inventory

### Negative Margins
- Review cost allocation methods
- Verify standard costs are up to date
- Check for data entry errors in costs

### Incorrect Profit Calculations
- Ensure COGS includes all components (material + labor + overhead)
- Verify revenue includes all applicable charges
- Check for discounts or returns affecting calculations

## Support

For issues or questions:
- Check FrontAccounting forums
- Review module documentation
- Contact development team

## Changelog

### Version 1.0.0 (2025-12-03)
- Initial release
- Comprehensive profitability metrics
- Cost breakdown analysis
- Pricing recommendations
- Top/least profitable identification
- Category and trend analysis
- Dashboard widget
- PDF/Excel export support
