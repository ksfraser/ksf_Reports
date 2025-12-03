# Inventory ABC Analysis Report

## Overview

The Inventory ABC Analysis Report implements the **Pareto Principle (80/20 rule)** for inventory management, classifying items into three categories based on their value contribution to total inventory:

- **Class A**: High-value items (typically ~20% of items, ~80% of value)
- **Class B**: Medium-value items (typically ~30% of items, ~15% of value)
- **Class C**: Low-value items (typically ~50% of items, ~5% of value)

This analysis helps optimize inventory management by focusing attention and resources on the items that matter most to your business.

## Features

### Core Functionality
- **ABC Classification**: Automatic classification of all inventory items based on annual value
- **Pareto Analysis**: Validates that high-value items follow the 80/20 principle
- **Custom Thresholds**: Configurable classification boundaries (default 80/95%)
- **Annual Value Calculation**: `unit_cost × annual_usage` for each item
- **Inventory Turnover**: Calculates turnover ratio for each item
- **Slow-Moving Detection**: Identifies items with low turnover (< 2.0)
- **Obsolete Identification**: Flags items with zero annual usage
- **Reorder Point Recommendations**: Calculates optimal reorder points based on lead time and service level
- **Safety Stock Calculation**: Statistical safety stock recommendations
- **Category Breakdown**: ABC analysis grouped by product category
- **Location Breakdown**: ABC analysis grouped by warehouse location
- **Pareto Chart**: Visual representation of value distribution
- **Export Options**: PDF and Excel export functionality

### Metrics Provided

For each item:
- Item code and description
- Current quantity on hand
- Unit cost
- Annual usage (quantity)
- Annual value (cost × usage)
- ABC classification (A/B/C)
- Cumulative value percentage
- Individual value percentage
- Inventory turnover ratio
- Slow-moving flag
- Obsolete flag
- Recommended reorder point
- Recommended safety stock
- Days of inventory on hand

Summary statistics:
- Total items by class
- Percentage of items by class
- Total value by class
- Percentage of value by class
- Average value by class
- Slow-moving item count
- Obsolete item count

## Usage

### Basic Usage

```php
use FA\Modules\Reports\Inventory\InventoryABCAnalysisReport;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

// Initialize dependencies
$db = get_db_connection();
$dispatcher = new EventDispatcher();
$logger = get_logger();

// Create report instance
$report = new InventoryABCAnalysisReport($db, $dispatcher, $logger);

// Generate ABC analysis
$result = $report->generate();

// Access results
$items = $result['items'];
$summary = $result['summary'];
$classification = $result['classification'];
$recommendations = $result['recommendations'];
```

### Custom Classification Thresholds

```php
// Custom thresholds: 70% for Class A, 90% for Class B
$options = [
    'class_a_threshold' => 70,
    'class_b_threshold' => 90,
    'lead_time_days' => 21,      // Lead time for reorder calculations
    'service_level' => 0.99       // 99% service level
];

$result = $report->generate($options);
```

### Analysis by Category

```php
// Get ABC analysis grouped by product category
$byCategory = $report->generateByCategory();

foreach ($byCategory['categories'] as $category => $analysis) {
    echo "Category: $category\n";
    echo "Class A items: " . count(array_filter(
        $analysis['items'], 
        fn($item) => $item['abc_class'] === 'A'
    )) . "\n";
}
```

### Analysis by Location

```php
// Get ABC analysis grouped by warehouse location
$byLocation = $report->generateByLocation();

foreach ($byLocation['locations'] as $location => $analysis) {
    echo "Location: $location\n";
    echo "Total value: $" . number_format($analysis['summary']['total_value'], 2) . "\n";
}
```

### Generate Pareto Chart

```php
$result = $report->generate();
$chartData = $report->generateParetoChart($result);

// $chartData contains:
// - labels: Item codes
// - values: Annual values
// - cumulative: Cumulative percentages
```

### Export to PDF

```php
$result = $report->generate();
$pdf = $report->exportToPDF($result);

// Save or output PDF
file_put_contents('abc_analysis.pdf', $pdf);
```

### Export to Excel

```php
$result = $report->generate();
$excel = $report->exportToExcel($result);

// Save or output Excel file
file_put_contents('abc_analysis.xlsx', $excel);
```

## Understanding the Results

### Classification Breakdown

```php
$classification = $result['classification'];

// Class A
echo "Class A Items: " . $classification['class_a']['item_count'];
echo " (" . round($classification['class_a']['item_percent'], 1) . "% of items)\n";
echo "Class A Value: $" . number_format($classification['class_a']['total_value'], 2);
echo " (" . round($classification['class_a']['value_percent'], 1) . "% of value)\n";
```

### Item Details

```php
foreach ($result['items'] as $item) {
    echo $item['item_code'] . " - " . $item['description'] . "\n";
    echo "Class: " . $item['abc_class'] . "\n";
    echo "Annual Value: $" . number_format($item['annual_value'], 2) . "\n";
    echo "Turnover Ratio: " . round($item['turnover_ratio'], 2) . "\n";
    
    if ($item['is_slow_moving']) {
        echo "⚠️ SLOW MOVING\n";
    }
    
    if ($item['is_obsolete']) {
        echo "🔴 OBSOLETE\n";
    }
    
    echo "Recommended Reorder Point: " . $item['recommended_reorder_point'] . "\n";
    echo "Recommended Safety Stock: " . $item['recommended_safety_stock'] . "\n";
    echo "\n";
}
```

### Recommendations

```php
$recommendations = $result['recommendations'];

echo "Class A Management:\n";
echo $recommendations['class_a'] . "\n\n";

echo "Class B Management:\n";
echo $recommendations['class_b'] . "\n\n";

echo "Class C Management:\n";
echo $recommendations['class_c'] . "\n\n";

echo "Slow-Moving Items:\n";
echo $recommendations['slow_moving'] . "\n\n";

echo "Obsolete Items:\n";
echo $recommendations['obsolete'] . "\n";
```

## Management Strategies by Class

### Class A Items (High Value)
- **Tight inventory control**: Frequent cycle counts (weekly)
- **Accurate demand forecasting**: Use statistical methods, monitor trends
- **Close supplier relationships**: Negotiate favorable terms, ensure reliability
- **JIT replenishment**: Consider Just-In-Time to reduce holding costs
- **Premium attention**: Assign dedicated personnel to manage these items
- **Frequent review**: Weekly review of stock levels and trends

**Goal**: Minimize stockouts while optimizing inventory investment

### Class B Items (Moderate Value)
- **Standard controls**: Regular cycle counts (monthly)
- **Automated reorder points**: Use system-calculated reorder points
- **Adequate safety stock**: Balance between availability and cost
- **Monthly review**: Review stock levels and adjust parameters monthly
- **Efficiency focus**: Balance control costs with inventory risks

**Goal**: Maintain availability with reasonable investment

### Class C Items (Low Value)
- **Simple controls**: Annual or no cycle counts
- **Large order quantities**: Minimize ordering costs by ordering less frequently
- **Higher safety stock**: Acceptable to carry more buffer stock
- **Quarterly review**: Infrequent review sufficient
- **Cost reduction**: Focus on minimizing transaction costs

**Goal**: Simplify management and reduce administrative overhead

## Metrics Explained

### Annual Value
```
Annual Value = Unit Cost × Annual Usage
```
Represents the total value of each item moved through inventory annually.

### Inventory Turnover Ratio
```
Turnover Ratio = Annual Usage ÷ Average Inventory Quantity
```
Higher turnover indicates faster-moving inventory. Class A items should typically have higher turnover.

### Slow-Moving Threshold
Items with turnover < 2.0 are flagged as slow-moving (default). Adjust threshold based on your industry.

### Obsolete Items
Items with zero annual usage in the past year. Candidates for liquidation or write-off.

### Reorder Point Calculation
```
Reorder Point = (Daily Usage × Lead Time Days) + Safety Stock
```
When inventory reaches this level, it's time to reorder.

### Safety Stock Calculation
```
Safety Stock = Z-Score × √Lead Time × Usage Variability
```
Buffer stock to account for demand variability and lead time uncertainty.
- 95% service level: Z-score = 1.65
- 90% service level: Z-score = 1.28

### Days on Hand
```
Days on Hand = Current Quantity ÷ Daily Usage
```
Indicates how many days of supply you have at current usage rates.

## Configuration

### Default Thresholds
- **Class A threshold**: 80% (items contributing to first 80% of value)
- **Class B threshold**: 95% (items contributing to 80-95% of value)
- **Class C**: Remaining items (95-100% of value)

### Customization
```php
$options = [
    'class_a_threshold' => 70,    // More items in Class A
    'class_b_threshold' => 90,    // Adjusted B threshold
    'lead_time_days' => 14,       // 2-week lead time
    'service_level' => 0.95       // 95% service level target
];

$result = $report->generate($options);
```

## Integration with FrontAccounting

The report integrates seamlessly with FrontAccounting:

1. **Menu Entry**: Added to Inventory Reports menu
2. **Report ID**: 302 (in 300 series for inventory reports)
3. **Category**: RC_INVENTORY (Inventory Reports)
4. **Dashboard Widget**: Summary widget showing ABC classification
5. **Access Control**: Requires `SA_ITEMSANALYTIC` permission

### Installation

The report is automatically registered when the Reports module is loaded:

```php
require_once('modules/Reports/Inventory/hooks_inventory_abc_analysis.php');
inventory_abc_analysis_install();
```

### Dashboard Widget

The dashboard widget displays:
- Total items and total value
- Items and value by class (A/B/C)
- Percentage distribution
- Slow-moving and obsolete item counts
- Link to full report

## Best Practices

1. **Run Regularly**: Generate ABC analysis monthly or quarterly
2. **Review Classifications**: Classifications may change as business evolves
3. **Adjust Thresholds**: Customize thresholds based on your business needs
4. **Take Action**: Use recommendations to implement control strategies
5. **Monitor Changes**: Track how items move between classes over time
6. **Combine with Other Reports**: Use alongside inventory valuation and turnover reports
7. **Address Obsolete Items**: Promptly act on obsolete inventory
8. **Focus on Class A**: Dedicate most attention to high-value items
9. **Document Decisions**: Keep records of why items are in each class
10. **Train Staff**: Ensure team understands ABC principles and their role

## Troubleshooting

### No Items Classified as Class A
- Check if data is available (annual usage tracked)
- Verify thresholds aren't too restrictive
- Ensure items have both cost and usage data

### Too Many Class A Items
- Lower the `class_a_threshold` (e.g., from 80 to 70)
- Review if high-value items are accurately costed

### Inaccurate Turnover Ratios
- Ensure annual usage data is complete
- Verify current quantity on hand is accurate
- Check for data quality issues in stock movements

### Reorder Points Seem Wrong
- Adjust `lead_time_days` to match actual supplier lead times
- Change `service_level` based on stockout tolerance
- Consider seasonal variations in usage

## Technical Details

### Database Tables Used
- `stock_master`: Item master data (cost, description)
- `stock_moves`: Movement history for annual usage calculation
- Movement types considered: 10 (Sales Invoice), 11 (Credit Note), 13 (Delivery)

### Performance Considerations
- Report calculates annual usage by summing movements from past year
- For large datasets (>10,000 items), consider:
  - Caching results
  - Running during off-peak hours
  - Filtering by category or location
  - Using database indexes on `stock_id` and `tran_date`

### Dependencies
- PHP 8.0+
- FrontAccounting 2.4+
- DBAL Database Interface
- PSR-3 Logger
- Event Dispatcher (optional)

## Support

For questions or issues:
- Check FrontAccounting forums
- Review code comments in `InventoryABCAnalysisReport.php`
- Consult this README
- Contact FrontAccounting development team

## Version History

### 1.0.0 (2025-12-03)
- Initial release
- Basic ABC classification
- Pareto analysis
- Turnover calculations
- Slow-moving and obsolete detection
- Reorder point recommendations
- Safety stock calculations
- Category and location breakdowns
- Export to PDF and Excel
- Dashboard widget integration

## License

This report is part of the FrontAccounting Reports module and follows the same license terms as FrontAccounting.
