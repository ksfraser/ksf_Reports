<?php
/**
 * Inventory Sales Report Service
 * 
 * Analyzes sales by item, customer, and category with contribution margin analysis.
 * Shows quantity sold, revenue, cost, and contribution for each item.
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

require_once($GLOBALS['path_to_root'] . "/includes/InventoryService.php");
require_once($GLOBALS['path_to_root'] . "/includes/BankingService.php");

class InventorySalesReport extends AbstractReportService
{
    protected string $fromDate;
    protected string $toDate;
    protected ?int $category;
    protected string $location;
    protected string $fromCust;
    protected bool $showService;
    protected int $dec;

    public function __construct(EventDispatcher $eventDispatcher)
    {
        parent::__construct($eventDispatcher);
    }

    protected function initializeConfig(): ReportConfig
    {
        return new ReportConfig(
            reportNumber: 304,
            title: _('Inventory Sales Report'),
            fileName: 'InventorySalesReport',
            orientation: $this->params['orientation'] ?? 'P'
        );
    }

    protected function loadParameters(): void
    {
        $this->fromDate = $this->extractor->getDate('PARAM_0');
        $this->toDate = $this->extractor->getDate('PARAM_1');
        $this->category = $this->extractor->getIntOrNull('PARAM_2');
        $this->location = $this->extractor->getString('PARAM_3');
        $this->fromCust = $this->extractor->getString('PARAM_4');
        $this->showService = $this->extractor->getBool('PARAM_5');
        $this->params['comments'] = $this->extractor->getString('PARAM_6');
        $this->params['orientation'] = $this->extractor->getBool('PARAM_7') ? 'L' : 'P';
        $this->params['destination'] = $this->extractor->getString('PARAM_8');

        $this->dec = \FA\UserPrefsCache::getPriceDecimals();
    }

    protected function defineColumns(): array
    {
        return [0, 75, 175, 250, 300, 375, 450, 515];
    }

    protected function defineHeaders(): array
    {
        $headers = [
            _('Category'),
            _('Description'),
            _('Customer'),
            _('Qty'),
            _('Sales'),
            _('Cost'),
            _('Contribution')
        ];

        if ($this->fromCust !== '') {
            $headers[2] = '';
        }

        return $headers;
    }

    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'right', 'right', 'right', 'right'];
    }

    protected function buildReportParameters(): array
    {
        $cat = $this->category === null ? _('All') : get_category_name($this->category);
        $loc = $this->location === '' ? _('All') : get_location_name($this->location);
        $fromc = $this->fromCust === '' ? _('All') : get_customer_name($this->fromCust);
        $showServiceItems = $this->showService ? _('Yes') : _('No');

        return [
            0 => $this->params['comments'],
            1 => ['text' => _('Period'), 'from' => $this->fromDate, 'to' => $this->toDate],
            2 => ['text' => _('Category'), 'from' => $cat, 'to' => ''],
            3 => ['text' => _('Location'), 'from' => $loc, 'to' => ''],
            4 => ['text' => _('Customer'), 'from' => $fromc, 'to' => ''],
            5 => ['text' => _('Show Service Items'), 'from' => $showServiceItems, 'to' => '']
        ];
    }

    protected function fetchData(): array
    {
        $from = \DateService::date2sqlStatic($this->fromDate);
        $to = \DateService::date2sqlStatic($this->toDate);

        $sql = "SELECT item.category_id,
                category.description AS cat_description,
                item.stock_id,
                item.description, item.inactive,
                item.mb_flag,
                move.loc_code,
                trans.debtor_no,
                debtor.name AS debtor_name,
                move.tran_date,
                SUM(-move.qty) AS qty,
                SUM(-move.qty*move.price) AS amt,
                SUM(-IF(move.standard_cost <> 0, move.qty * move.standard_cost, move.qty *item.material_cost)) AS cost
            FROM ".TB_PREF."stock_master item,
                ".TB_PREF."stock_category category,
                ".TB_PREF."debtor_trans trans,
                ".TB_PREF."debtors_master debtor,
                ".TB_PREF."stock_moves move
            WHERE item.stock_id=move.stock_id
            AND item.category_id=category.category_id
            AND trans.debtor_no=debtor.debtor_no
            AND move.type=trans.type
            AND move.trans_no=trans.trans_no
            AND move.tran_date>='$from'
            AND move.tran_date<='$to'
            AND (trans.type=".ST_CUSTDELIVERY." OR move.type=".ST_CUSTCREDIT.")";

        if (!$this->showService) {
            $sql .= " AND (item.mb_flag='B' OR item.mb_flag='M')";
        } else {
            $sql .= " AND item.mb_flag<>'F'";
        }

        if ($this->category !== null) {
            $sql .= " AND item.category_id = ".db_escape($this->category);
        }

        if ($this->location !== '') {
            $sql .= " AND move.loc_code = ".db_escape($this->location);
        }

        if ($this->fromCust !== '') {
            $sql .= " AND debtor.debtor_no = ".db_escape($this->fromCust);
        }

        $sql .= " GROUP BY item.stock_id, debtor.name ORDER BY item.category_id,
            item.stock_id, debtor.name";

        return $this->db->fetchAll($sql);
    }

    protected function processData(array $data): array
    {
        $processed = [];
        $categoryTotals = [];

        foreach ($data as $row) {
            // Get exchange rate for customer currency
            $curr = get_customer_currency($row['debtor_no']);
            $rate = \BankingService::getExchangeRateFromHomeCurrency(
                $curr,
                \DateService::sql2dateStatic($row['tran_date'])
            );

            // Convert to home currency
            $row['amt'] *= $rate;
            $row['contribution'] = $row['amt'] - $row['cost'];

            // Track category totals
            if (!isset($categoryTotals[$row['category_id']])) {
                $categoryTotals[$row['category_id']] = [
                    'amt' => 0,
                    'cost' => 0,
                    'contribution' => 0
                ];
            }
            $categoryTotals[$row['category_id']]['amt'] += $row['amt'];
            $categoryTotals[$row['category_id']]['cost'] += $row['cost'];
            $categoryTotals[$row['category_id']]['contribution'] += $row['contribution'];

            $processed[] = $row;
        }

        return [
            'rows' => $processed,
            'categoryTotals' => $categoryTotals
        ];
    }

    protected function renderReport(array $data): void
    {
        $rows = $data['rows'];
        $categoryTotals = $data['categoryTotals'];

        $total = $total1 = $total2 = 0.0;
        $grandTotal = $grandTotal1 = $grandTotal2 = 0.0;
        $catt = '';

        foreach ($rows as $row) {
            // Category header and totals
            if ($catt !== $row['cat_description']) {
                if ($catt !== '') {
                    $this->rep->NewLine(2, 3);
                    $this->rep->TextCol(0, 4, _('Total'));
                    $this->rep->AmountCol(4, 5, $total, $this->dec);
                    $this->rep->AmountCol(5, 6, $total1, $this->dec);
                    $this->rep->AmountCol(6, 7, $total2, $this->dec);
                    $this->rep->Line($this->rep->row - 2);
                    $this->rep->NewLine();
                    $this->rep->NewLine();
                    $total = $total1 = $total2 = 0.0;
                }
                $this->rep->TextCol(0, 1, $row['category_id']);
                $this->rep->TextCol(1, 6, $row['cat_description']);
                $catt = $row['cat_description'];
                $this->rep->NewLine();
            }

            $this->rep->NewLine();
            $this->rep->fontSize -= 2;
            
            // Item and customer
            $this->rep->TextCol(0, 1, $row['stock_id']);
            if ($this->fromCust === '') {
                $this->rep->TextCol(1, 2, $row['description'].($row['inactive']==1 ? " ("._("Inactive").")" : ""), -1);
                $this->rep->TextCol(2, 3, $row['debtor_name']);
            } else {
                $this->rep->TextCol(1, 3, $row['description'].($row['inactive']==1 ? " ("._("Inactive").")" : ""), -1);
            }

            // Amounts
            $this->rep->AmountCol(3, 4, $row['qty'], get_qty_dec($row['stock_id']));
            $this->rep->AmountCol(4, 5, $row['amt'], $this->dec);
            
            if (\InventoryService::isService($row['mb_flag'])) {
                $this->rep->TextCol(5, 6, "---");
            } else {
                $this->rep->AmountCol(5, 6, $row['cost'], $this->dec);
            }
            
            $this->rep->AmountCol(6, 7, $row['contribution'], $this->dec);
            $this->rep->fontSize += 2;

            // Accumulate totals
            $total += $row['amt'];
            $total1 += $row['cost'];
            $total2 += $row['contribution'];
            $grandTotal += $row['amt'];
            $grandTotal1 += $row['cost'];
            $grandTotal2 += $row['contribution'];
        }

        // Final category total
        if ($catt !== '') {
            $this->rep->NewLine(2, 3);
            $this->rep->TextCol(0, 4, _('Total'));
            $this->rep->AmountCol(4, 5, $total, $this->dec);
            $this->rep->AmountCol(5, 6, $total1, $this->dec);
            $this->rep->AmountCol(6, 7, $total2, $this->dec);
            $this->rep->Line($this->rep->row - 2);
            $this->rep->NewLine();
        }

        // Grand total
        $this->rep->NewLine(2, 1);
        $this->rep->TextCol(0, 4, _('Grand Total'));
        $this->rep->AmountCol(4, 5, $grandTotal, $this->dec);
        $this->rep->AmountCol(5, 6, $grandTotal1, $this->dec);
        $this->rep->AmountCol(6, 7, $grandTotal2, $this->dec);
        $this->rep->Line($this->rep->row - 4);
        $this->rep->NewLine();
    }
}
