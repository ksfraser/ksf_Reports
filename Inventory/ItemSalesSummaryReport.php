<?php
/**
 * Item Sales Summary Report Service
 * 
 * Summarizes sales by item with quantities, unit prices, and total sales values.
 * Groups by category and identifies gift items (zero price).
 * 
 * @package    FrontAccounting
 * @subpackage Reports
 * @since      5.0
 */

declare(strict_types=1);

namespace FA\Modules\Reports\Inventory;

use FA\Modules\Reports\Base\AbstractReportService;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Events\EventDispatcher;

class ItemSalesSummaryReport extends AbstractReportService
{
    protected string $fromDate;
    protected string $toDate;
    protected ?int $category;
    protected int $dec;

    public function __construct(EventDispatcher $eventDispatcher)
    {
        parent::__construct($eventDispatcher);
    }

    protected function initializeConfig(): ReportConfig
    {
        return new ReportConfig(
            reportNumber: 309,
            title: _('Item Sales Summary Report'),
            fileName: 'ItemSalesSummaryReport',
            orientation: $this->params['orientation'] ?? 'P'
        );
    }

    protected function loadParameters(): void
    {
        $this->fromDate = $this->extractor->getDate('PARAM_0');
        $this->toDate = $this->extractor->getDate('PARAM_1');
        $this->category = $this->extractor->getIntOrNull('PARAM_2');
        $this->params['comments'] = $this->extractor->getString('PARAM_3');
        $this->params['orientation'] = $this->extractor->getBool('PARAM_4') ? 'L' : 'P';
        $this->params['destination'] = $this->extractor->getString('PARAM_5');

        $this->dec = \FA\UserPrefsCache::getPriceDecimals();
    }

    protected function defineColumns(): array
    {
        return [0, 100, 260, 300, 350, 425, 430, 515];
    }

    protected function defineHeaders(): array
    {
        return [
            _('Item/Category'),
            _('Description'),
            _('Qty'),
            _('Unit Price'),
            _('Sales'),
            '',
            _('Remark')
        ];
    }

    protected function defineAlignments(): array
    {
        return ['left', 'left', 'right', 'right', 'right', 'right', 'left'];
    }

    protected function buildReportParameters(): array
    {
        $cat = $this->category === null ? _('All') : get_category_name($this->category);

        return [
            0 => $this->params['comments'],
            1 => ['text' => _('Period'), 'from' => $this->fromDate, 'to' => $this->toDate],
            2 => ['text' => _('Category'), 'from' => $cat, 'to' => '']
        ];
    }

    protected function fetchData(): array
    {
        $from = \DateService::date2sqlStatic($this->fromDate);
        $to = \DateService::date2sqlStatic($this->toDate);

        $sql = "SELECT item.category_id,
                category.description AS cat_description,
                item.stock_id,
                item.description,
                line.unit_price * trans.rate AS unit_price,
                SUM(IF(line.debtor_trans_type = ".ST_CUSTCREDIT.", -line.quantity, line.quantity)) AS quantity
            FROM ".TB_PREF."stock_master item,
                ".TB_PREF."stock_category category,
                ".TB_PREF."debtor_trans trans,
                ".TB_PREF."debtor_trans_details line
            WHERE line.stock_id = item.stock_id
            AND item.category_id=category.category_id
            AND line.debtor_trans_type=trans.type
            AND line.debtor_trans_no=trans.trans_no
            AND trans.tran_date>='$from'
            AND trans.tran_date<='$to'
            AND line.quantity<>0
            AND item.mb_flag <>'F'
            AND (line.debtor_trans_type = ".ST_SALESINVOICE." OR line.debtor_trans_type = ".ST_CUSTCREDIT.")";

        if ($this->category !== null) {
            $sql .= " AND item.category_id = ".db_escape($this->category);
        }

        $sql .= " GROUP BY item.category_id,
            category.description,
            item.stock_id,
            item.description,
            line.unit_price
        ORDER BY item.category_id, item.stock_id, line.unit_price";

        return $this->db->fetchAll($sql);
    }

    protected function processData(array $data): array
    {
        // Calculate category totals
        $categoryTotals = [];
        
        foreach ($data as $row) {
            $salesAmount = $row['quantity'] * $row['unit_price'];
            
            if (!isset($categoryTotals[$row['category_id']])) {
                $categoryTotals[$row['category_id']] = 0;
            }
            $categoryTotals[$row['category_id']] += $salesAmount;
        }

        return [
            'rows' => $data,
            'categoryTotals' => $categoryTotals
        ];
    }

    protected function renderReport(array $data): void
    {
        $rows = $data['rows'];
        $total = $grandTotal = 0.0;
        $catt = '';

        foreach ($rows as $row) {
            // Category header
            if ($catt !== $row['cat_description']) {
                if ($catt !== '') {
                    $this->rep->NewLine(2, 3);
                    $this->rep->TextCol(0, 4, _('Total'));
                    $this->rep->AmountCol(4, 5, $total, $this->dec);
                    $this->rep->Line($this->rep->row - 2);
                    $this->rep->NewLine();
                    $this->rep->NewLine();
                    $total = 0.0;
                }
                $this->rep->TextCol(0, 1, $row['category_id']);
                $this->rep->TextCol(1, 7, $row['cat_description']);
                $catt = $row['cat_description'];
                $this->rep->NewLine();
            }

            // Item detail line
            $this->rep->NewLine();
            $this->rep->fontSize -= 2;
            $this->rep->TextCol(0, 1, $row['stock_id']);
            $this->rep->TextCol(1, 2, $row['description']);
            $this->rep->AmountCol(2, 3, $row['quantity'], get_qty_dec($row['stock_id']));
            $this->rep->AmountCol(3, 4, $row['unit_price'], $this->dec);
            
            $salesAmount = $row['quantity'] * $row['unit_price'];
            $this->rep->AmountCol(4, 5, $salesAmount, $this->dec);
            
            // Mark gifts (zero price items)
            if ($row['unit_price'] == 0) {
                $this->rep->TextCol(6, 7, _('Gift'));
            }
            
            $this->rep->fontSize += 2;
            $total += $salesAmount;
            $grandTotal += $salesAmount;
        }

        // Final category total
        if ($catt !== '') {
            $this->rep->NewLine(2, 3);
            $this->rep->TextCol(0, 4, _('Total'));
            $this->rep->AmountCol(4, 5, $total, $this->dec);
            $this->rep->Line($this->rep->row - 2);
            $this->rep->NewLine();
        }

        // Grand total
        $this->rep->NewLine(2, 1);
        $this->rep->TextCol(0, 4, _('Grand Total'));
        $this->rep->AmountCol(4, 5, $grandTotal, $this->dec);
        $this->rep->Line($this->rep->row - 4);
        $this->rep->NewLine();
    }
}
