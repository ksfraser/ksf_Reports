# Supplier Performance Dashboard

## Overview

The Supplier Performance Dashboard is a comprehensive supplier evaluation and monitoring system that tracks key performance indicators (KPIs) across delivery performance, quality metrics, lead times, and overall supplier effectiveness. This report helps procurement teams make data-driven sourcing decisions, identify top performers for strategic partnerships, and flag underperformers requiring corrective action.

## Features

### Core Metrics

**Delivery Performance**
- On-time delivery rate (% of orders delivered on schedule)
- Late delivery tracking
- Delivery rating (Excellent/Good/Acceptable/Poor)
- Average lead time in days

**Quality Metrics**
- Quality score (% of orders without quality issues)
- Quality issue tracking (returns, credit notes)
- Defect rate analysis

**Cost Analysis**
- Total order value by supplier
- Average order value
- Price competitiveness tracking

**Performance Scoring**
- Overall performance score (0-100)
- Performance grade (A/B/C/D/F)
- Weighted composite scoring:
  - 50% On-time delivery
  - 30% Quality
  - 20% Lead time

**Risk Assessment**
- Supplier risk level (High/Medium/Low)
- Risk factors identification:
  - Poor delivery performance
  - High quality issues
  - Excessive lead times
  - High financial dependency

### Advanced Features

- **Top Performers**: Identifies suppliers with scores ≥85
- **Underperformers**: Flags suppliers with scores <70
- **Trend Analysis**: Monthly performance trends for individual suppliers
- **Supplier Comparison**: Side-by-side comparison of multiple suppliers
- **Category Analysis**: Performance breakdown by supplier category
- **Dashboard Widget**: Real-time performance summary for FA dashboard

## Usage

### Basic Usage

```php
use FA\Modules\Reports\Purchasing\SupplierPerformanceDashboard;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

// Initialize dependencies
$db = get_db_connection();
$dispatcher = new EventDispatcher();
$logger = get_logger();

// Create dashboard instance
$dashboard = new SupplierPerformanceDashboard($db, $dispatcher, $logger);

// Generate performance report for date range
$result = $dashboard->generate('2024-01-01', '2024-12-31');

// Access results
$suppliers = $result['suppliers'];
$summary = $result['summary'];
$topPerformers = $result['top_performers'];
$underperformers = $result['underperformers'];
$metrics = $result['metrics'];
```

### Analyzing Individual Suppliers

```php
foreach ($suppliers as $supplier) {
    echo "Supplier: " . $supplier['supplier_name'] . "\n";
    echo "Performance Grade: " . $supplier['performance_grade'] . "\n";
    echo "Overall Score: " . round($supplier['overall_score'], 1) . "/100\n";
    echo "On-Time Delivery: " . round($supplier['on_time_delivery_rate'], 1) . "%\n";
    echo "Quality Score: " . round($supplier['quality_score'], 1) . "%\n";
    echo "Average Lead Time: " . round($supplier['avg_lead_time'], 1) . " days\n";
    echo "Risk Level: " . $supplier['risk_level'] . "\n";
    
    if (!empty($supplier['risk_factors'])) {
        echo "Risk Factors:\n";
        foreach ($supplier['risk_factors'] as $factor) {
            echo "  - " . $factor . "\n";
        }
    }
    echo "\n";
}
```

### Top Performers Analysis

```php
$result = $dashboard->generate('2024-01-01', '2024-12-31');

echo "Top 5 Suppliers:\n";
foreach ($result['top_performers'] as $rank => $supplier) {
    echo ($rank + 1) . ". " . $supplier['supplier_name'];
    echo " (Score: " . round($supplier['overall_score'], 1) . ", ";
    echo "Grade: " . $supplier['performance_grade'] . ")\n";
}
```

### Underperformer Identification

```php
$result = $dashboard->generate('2024-01-01', '2024-12-31');

if (!empty($result['underperformers'])) {
    echo "⚠️ Suppliers Requiring Attention:\n\n";
    
    foreach ($result['underperformers'] as $supplier) {
        echo $supplier['supplier_name'] . ":\n";
        echo "  Score: " . round($supplier['overall_score'], 1) . "/100\n";
        echo "  On-Time: " . round($supplier['on_time_delivery_rate'], 1) . "%\n";
        echo "  Quality: " . round($supplier['quality_score'], 1) . "%\n";
        echo "  Risk: " . $supplier['risk_level'] . "\n";
        echo "  Issues:\n";
        foreach ($supplier['risk_factors'] as $factor) {
            echo "    - " . $factor . "\n";
        }
        echo "\n";
    }
}
```

### Performance by Category

```php
$byCategory = $dashboard->generateByCategory('2024-01-01', '2024-12-31');

foreach ($byCategory['categories'] as $category => $suppliers) {
    echo "Category: $category\n";
    echo "Suppliers: " . count($suppliers) . "\n";
    
    $avgScore = array_sum(array_column($suppliers, 'overall_score')) / count($suppliers);
    echo "Average Score: " . round($avgScore, 1) . "\n\n";
}
```

### Trend Analysis

```php
// Analyze trends for a specific supplier
$trends = $dashboard->generateTrends('SUP001');

echo "Supplier: " . $trends['supplier_id'] . "\n";
echo "Performance Trend: " . $trends['performance_trend'] . "\n\n";

echo "Monthly Breakdown:\n";
foreach ($trends['monthly_trends'] as $month) {
    echo $month['month'] . ": ";
    echo $month['orders_count'] . " orders, ";
    echo "$" . number_format($month['total_value'], 2) . ", ";
    echo round($month['avg_lead_time'], 1) . " days lead time\n";
}
```

### Supplier Comparison

```php
// Compare multiple suppliers
$comparison = $dashboard->compareSuppliers(
    ['SUP001', 'SUP002', 'SUP003'],
    '2024-01-01',
    '2024-12-31'
);

echo "Winner: " . $comparison['winner']['supplier_name'] . "\n";
echo "Score: " . round($comparison['winner']['overall_score'], 1) . "\n\n";

echo "Comparison Metrics:\n";
echo "Best Delivery: " . round($comparison['metrics']['best_delivery'], 1) . "%\n";
echo "Best Quality: " . round($comparison['metrics']['best_quality'], 1) . "%\n";
echo "Shortest Lead Time: " . round($comparison['metrics']['shortest_lead_time'], 1) . " days\n";
echo "Highest Volume: $" . number_format($comparison['metrics']['highest_volume'], 2) . "\n";
```

### Export Options

```php
$result = $dashboard->generate('2024-01-01', '2024-12-31');

// Export to PDF
$pdf = $dashboard->exportToPDF($result);
file_put_contents('supplier_performance.pdf', $pdf);

// Export to Excel
$excel = $dashboard->exportToExcel($result);
file_put_contents('supplier_performance.xlsx', $excel);
```

## Performance Scoring

### Overall Score Calculation

The overall performance score is a weighted composite of three key metrics:

```
Overall Score = (On-Time Delivery × 0.5) + (Quality Score × 0.3) + (Lead Time Score × 0.2)
```

**Weight Distribution:**
- **50%** On-time delivery rate (most critical for operations)
- **30%** Quality score (impacts product quality and costs)
- **20%** Lead time performance (affects inventory levels)

**Lead Time Scoring:**
```
Lead Time Score = max(0, 100 - ((avg_lead_time - 7) / 23 × 100))
```
- 7 days = 100 points (excellent)
- 30 days = 0 points (poor)
- Linear scale between benchmarks

### Performance Grades

| Grade | Score Range | Interpretation |
|-------|-------------|----------------|
| **A** | 90-100 | Excellent - Strategic partner quality |
| **B** | 80-89 | Good - Reliable supplier |
| **C** | 70-79 | Acceptable - Monitor closely |
| **D** | 60-69 | Poor - Requires improvement plan |
| **F** | <60 | Failing - Consider replacement |

### Delivery Ratings

| Rating | On-Time Rate | Action |
|--------|--------------|--------|
| **Excellent** | ≥95% | Maintain relationship |
| **Good** | 85-94% | Continue monitoring |
| **Acceptable** | 75-84% | Discuss improvements |
| **Poor** | <75% | Corrective action required |

### Quality Scoring

```
Quality Score = ((Total Orders - Quality Issues) / Total Orders) × 100
```

**Quality Issues include:**
- Supplier credit notes (returns)
- Rejected deliveries
- Quality complaints
- Non-conformances

**Thresholds:**
- ≥95% = High quality
- 90-94% = Good quality
- <90% = Concerning quality issues

## Risk Assessment

### Risk Levels

**High Risk** - Score ≥5 points from:
- Poor on-time delivery (≥30% late) = 3 points
- High quality issues (≥5% defect rate) = 3 points
- Excessive lead times (>30 days) = 2 points
- High financial dependency (>$100k spend) = 1 point

**Medium Risk** - Score 3-4 points

**Low Risk** - Score <3 points

### Risk Factors

The dashboard automatically identifies:

1. **Poor on-time delivery** (≥30% late rate)
   - Impact: Production disruptions, stockouts
   - Action: Performance improvement plan required

2. **High quality issues** (≥5% defect rate)
   - Impact: Increased costs, customer complaints
   - Action: Quality audit, corrective actions

3. **Excessive lead times** (>30 days)
   - Impact: High inventory carrying costs
   - Action: Evaluate alternative suppliers

4. **High financial dependency** (large % of spend)
   - Impact: Business continuity risk
   - Action: Develop backup suppliers

## Dashboard Summary Metrics

```php
$summary = $result['summary'];

echo "Total Suppliers: " . $summary['total_suppliers'] . "\n";
echo "Total Orders: " . $summary['total_orders'] . "\n";
echo "Total Value: $" . number_format($summary['total_value'], 2) . "\n";
echo "Overall On-Time Rate: " . round($summary['overall_on_time_rate'], 1) . "%\n";
echo "Overall Quality Score: " . round($summary['overall_quality_score'], 1) . "%\n";
echo "Average Order Value: $" . number_format($summary['avg_order_value'], 2) . "\n";
```

## Use Cases

### 1. Strategic Sourcing Decisions

Identify suppliers worthy of increased business or strategic partnerships:

```php
$topPerformers = $result['top_performers'];

foreach ($topPerformers as $supplier) {
    if ($supplier['overall_score'] >= 95 && $supplier['risk_level'] === 'Low') {
        echo "Strategic Partner Candidate: " . $supplier['supplier_name'] . "\n";
        echo "Consider for:\n";
        echo "  - Increased purchase volume\n";
        echo "  - Long-term contracts\n";
        echo "  - Collaborative product development\n";
    }
}
```

### 2. Supplier Development Programs

Identify suppliers with potential for improvement:

```php
foreach ($suppliers as $supplier) {
    if ($supplier['overall_score'] >= 70 && $supplier['overall_score'] < 85) {
        echo $supplier['supplier_name'] . " - Development Opportunity\n";
        
        if ($supplier['quality_score'] < 95) {
            echo "  → Quality improvement program\n";
        }
        if ($supplier['on_time_delivery_rate'] < 90) {
            echo "  → Delivery performance coaching\n";
        }
        if ($supplier['avg_lead_time'] > 14) {
            echo "  → Lead time reduction initiatives\n";
        }
    }
}
```

### 3. Supplier Rationalization

Identify suppliers to phase out:

```php
$underperformers = $result['underperformers'];

foreach ($underperformers as $supplier) {
    if ($supplier['overall_score'] < 60 || $supplier['risk_level'] === 'High') {
        echo "Consider Replacing: " . $supplier['supplier_name'] . "\n";
        echo "Reasons:\n";
        foreach ($supplier['risk_factors'] as $factor) {
            echo "  - " . $factor . "\n";
        }
        echo "Alternative suppliers needed\n\n";
    }
}
```

### 4. Performance Reviews

Generate quarterly business reviews:

```php
// Q1 Performance
$q1 = $dashboard->generate('2024-01-01', '2024-03-31');

// Q2 Performance
$q2 = $dashboard->generate('2024-04-01', '2024-06-30');

// Compare trends
foreach ($q1['suppliers'] as $s1) {
    $s2 = array_filter($q2['suppliers'], fn($s) => $s['supplier_id'] === $s1['supplier_id']);
    $s2 = reset($s2);
    
    if ($s2) {
        $scoreDelta = $s2['overall_score'] - $s1['overall_score'];
        echo $s1['supplier_name'] . ": ";
        
        if ($scoreDelta > 5) {
            echo "✓ Improving (" . round($scoreDelta, 1) . " points)\n";
        } elseif ($scoreDelta < -5) {
            echo "⚠ Declining (" . round($scoreDelta, 1) . " points)\n";
        } else {
            echo "→ Stable\n";
        }
    }
}
```

### 5. Category Management

Evaluate performance by commodity category:

```php
$byCategory = $dashboard->generateByCategory('2024-01-01', '2024-12-31');

foreach ($byCategory['categories'] as $category => $suppliers) {
    $scores = array_column($suppliers, 'overall_score');
    $avgScore = array_sum($scores) / count($scores);
    $bestScore = max($scores);
    
    echo "Category: $category\n";
    echo "Average Score: " . round($avgScore, 1) . "\n";
    echo "Best Score: " . round($bestScore, 1) . "\n";
    
    if ($avgScore < 75) {
        echo "⚠️ Category needs attention - explore alternative sources\n";
    }
    echo "\n";
}
```

## Integration with FrontAccounting

### Menu Integration

The dashboard is automatically added to the Purchasing Reports menu:
- **Location**: Purchasing → Reports → Supplier Performance
- **Report ID**: 501
- **Access Level**: `SA_SUPPTRANSVIEW`

### Dashboard Widget

A summary widget is available for the FA dashboard showing:
- Last 90 days performance summary
- Top 3 performers
- Suppliers needing attention
- Key aggregate metrics

### Database Tables Used

- `suppliers` - Supplier master data
- `purch_orders` - Purchase orders
- `grn_batch` - Goods receipt notes (delivery dates)
- `supp_trans` - Supplier transactions (quality issues via credit notes)

## Best Practices

### 1. Regular Monitoring

Run the dashboard monthly or quarterly to track trends:

```php
// Monthly review
$thisMonth = $dashboard->generate(
    date('Y-m-01'),
    date('Y-m-t')
);

// Compare to last month
$lastMonth = $dashboard->generate(
    date('Y-m-01', strtotime('last month')),
    date('Y-m-t', strtotime('last month'))
);
```

### 2. Set Performance Expectations

Communicate KPI targets to suppliers:
- On-time delivery: ≥95%
- Quality score: ≥98%
- Lead time: ≤14 days
- Overall grade: B or better

### 3. Use Data in Negotiations

Leverage performance data in supplier negotiations:
- Request pricing concessions from underperformers
- Reward top performers with increased volume
- Include performance clauses in contracts

### 4. Document Corrective Actions

Track improvement plans for underperformers:
```php
foreach ($underperformers as $supplier) {
    echo "Supplier: " . $supplier['supplier_name'] . "\n";
    echo "Current Score: " . round($supplier['overall_score'], 1) . "\n";
    echo "Target Score: 75 (within 6 months)\n";
    echo "Action Plan:\n";
    
    if ($supplier['on_time_delivery_rate'] < 85) {
        echo "  1. Require delivery schedule commitments\n";
        echo "  2. Implement weekly status calls\n";
    }
    if ($supplier['quality_score'] < 95) {
        echo "  3. Conduct quality audit\n";
        echo "  4. Require process improvements\n";
    }
}
```

### 5. Benchmark Performance

Compare your suppliers against industry standards:
- Typical on-time delivery: 90-95%
- Typical quality score: 95-99%
- Typical lead times: 7-21 days (varies by industry)

## Troubleshooting

### No Suppliers Showing

**Cause**: No purchase orders in date range
**Solution**: 
- Verify date range includes order activity
- Check supplier accounts are active
- Ensure purchase orders are properly recorded

### Inaccurate Delivery Metrics

**Cause**: Missing or incorrect delivery dates in GRN
**Solution**:
- Ensure all GRNs have delivery_date populated
- Verify GRN properly linked to purchase orders
- Update historical records if needed

### Quality Scores Seem High Despite Known Issues

**Cause**: Quality issues not recorded as supplier credit notes
**Solution**:
- Ensure all returns/defects create supplier credit notes
- Train staff on proper quality issue documentation
- Review credit note creation process

### Risk Assessment Not Detecting Issues

**Cause**: Thresholds may need adjustment for your business
**Solution**:
- Review risk scoring thresholds in code
- Adjust HIGH_RISK_THRESHOLD constants
- Customize for your industry standards

## Technical Details

### Performance Considerations

- Dashboard queries aggregate data from multiple tables
- For large datasets (>100 suppliers, >10,000 orders):
  - Consider caching results
  - Run during off-peak hours
  - Use date range filters
  - Index supplier_id, ord_date, delivery_date columns

### Dependencies

- PHP 8.0+
- FrontAccounting 2.4+
- DBAL Database Interface
- PSR-3 Logger
- Event Dispatcher

### Customization

Adjust scoring weights in `calculateOverallScore()`:
```php
private function calculateOverallScore(
    float $onTimeRate, 
    float $qualityScore, 
    float $avgLeadTime
): float {
    // Customize weights as needed
    $score = ($onTimeRate * 0.5) + ($qualityScore * 0.3) + ($leadTimeScore * 0.2);
    return round($score, 2);
}
```

Adjust risk thresholds:
```php
private const HIGH_RISK_THRESHOLD = 30.0;      // % late deliveries
private const QUALITY_ISSUE_THRESHOLD = 5.0;   // % quality issues
```

## Version History

### 1.0.0 (2025-12-03)
- Initial release
- On-time delivery tracking
- Quality score calculations
- Lead time analysis
- Overall performance scoring
- Risk assessment
- Top performers identification
- Underperformer flagging
- Trend analysis
- Supplier comparison
- Category breakdown
- Dashboard widget integration
- PDF/Excel export

## Support

For questions or issues:
- Review code comments in `SupplierPerformanceDashboard.php`
- Check FrontAccounting forums
- Consult this README
- Contact FrontAccounting development team

## License

This report is part of the FrontAccounting Reports module and follows the same license terms as FrontAccounting.
