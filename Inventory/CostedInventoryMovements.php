<?php
/**
 * Costed Inventory Movements Report Service
 * 
 * Shows inventory movements with cost information including opening/closing values.
 * Tracks quantity and value of stock movements with average unit costs.
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

require_once($GLOBALS['path_to_root'] . "/includes/DateService.php");
require_once($GLOBALS['path_to_root'] . "/includes/InventoryService.php");

class CostedInventoryMovements extends AbstractReportService
{
    protected string $fromDate;
    protected string $toDate;
    protected ?int $category;
    protected string $location;
    protected int $dec;

    public function __construct(EventDispatcher $eventDispatcher)
    {
        parent::__construct($eventDispatcher);
    }

    protected function initializeConfig(): ReportConfig
    {
        return new ReportConfig(
            reportNumber: 308,
            title: _('Costed Inventory Movements'),
            fileName: 'CostedInventoryMovements',
            orientation: $this->params['orientation'] ?? 'P',
            fontSize: 8
        );
    }

    protected function loadParameters(): void
    {
        $this->fromDate = $this->extractor->getDate('PARAM_0');
        $this->toDate = $this->extractor->getDate('PARAM_1');
        $this->category = $this->extractor->getIntOrNull('PARAM_2');
        $this->location = $this->extractor->getString('PARAM_3');
        $this->params['comments'] = $this->extractor->getString('PARAM_4');
        $this->params['orientation'] = $this->extractor->getBool('PARAM_5') ? 'L' : 'P';
        $this->params['destination'] = $this->extractor->getString('PARAM_6');

        $this->dec = \FA\UserPrefsCache::getPriceDecimals();
    }

    protected function defineColumns(): array
    {
        return [0, 60, 134, 160, 185, 215, 250, 275, 305, 340, 365, 395, 430, 455, 485, 520];
    }

    protected function defineHeaders(): array
    {
        return [
            _('Category'),
            _('Description'),
            _('UOM'),
            '',
            '',
            _('OpeningStock'),
            '',
            '',
            _('StockIn'),
            '',
            '',
            _('Delivery'),
            '',
            '',
            _('ClosingStock')
        ];
    }

    protected function defineHeaders2(): array
    {
        return [
            "",
            "",
            "",
            _("QTY"),
            _("Rate"),
            _("Value"),
            _("QTY"),
            _("Rate"),
            _("Value"),
            _("QTY"),
            _("Rate"),
            _("Value"),
            _("QTY"),
            _("Rate"),
            _("Value")
        ];
    }

    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'right', 'right', 'right', 'right', 'right', 'right', 'right', 'right', 'right', 'right', 'right', 'right'];
    }

    protected function buildReportParameters(): array
    {
        $cat = $this->category === null ? _('All') : get_category_name($this->category);
        $loc = $this->location === '' ? _('All') : get_location_name($this->location);

        return [
            0 => $this->params['comments'],
            1 => ['text' => _('Period'), 'from' => $this->fromDate, 'to' => $this->toDate],
            2 => ['text' => _('Category'), 'from' => $cat, 'to' => ''],
            3 => ['text' => _('Location'), 'from' => $loc, 'to' => '']
        ];
    }

    protected function fetchData(): array
    {
        $sql = "SELECT stock_id, stock.description AS name,
                stock.category_id,units,
                cat.description
            FROM ".TB_PREF."stock_master stock LEFT JOIN ".TB_PREF."stock_category cat ON stock.category_id=cat.category_id
                WHERE mb_flag <> 'D' AND mb_flag <> 'F'";

        if ($this->category !== null) {
            $sql .= " AND cat.category_id = ".db_escape($this->category);
        }

        $sql .= " ORDER BY stock.category_id, stock_id";

        return $this->db->fetchAll($sql);
    }

    protected function processData(array $data): array
    {
        $processed = [];

        foreach ($data as $row) {
            // Calculate quantities
            $qohStart = \InventoryService::getQohOnDate(
                $row['stock_id'],
                $this->location,
                \DateService::addDaysStatic($this->fromDate, -1)
            );
            $qohEnd = \InventoryService::getQohOnDate(
                $row['stock_id'],
                $this->location,
                $this->toDate
            );

            $inward = $this->getTransQty($row['stock_id'], true);
            $outward = $this->getTransQty($row['stock_id'], false);

            // Calculate unit costs
            $openCost = $this->getAvgUnitCost($row['stock_id'], $this->fromDate);
            $unitCost = $this->getAvgUnitCost($row['stock_id'], \DateService::addDaysStatic($this->toDate, 1));

            // Skip if no activity
            if ($qohStart == 0 && $inward == 0 && $outward == 0 && $qohEnd == 0) {
                continue;
            }

            $row['qoh_start'] = $qohStart;
            $row['qoh_end'] = $qohEnd;
            $row['inward'] = $inward;
            $row['outward'] = $outward;
            $row['open_cost'] = $openCost;
            $row['unit_cost'] = $unitCost;

            // Calculate costs for inward/outward movements
            if ($inward > 0) {
                $row['unit_cost_in'] = $this->getTransQtyUnitCost($row['stock_id'], true);
            } else {
                $row['unit_cost_in'] = 0;
            }

            if ($outward > 0) {
                $row['unit_cost_out'] = $this->getTransQtyUnitCost($row['stock_id'], false);
            } else {
                $row['unit_cost_out'] = 0;
            }

            $processed[] = $row;
        }

        return $processed;
    }

    protected function renderReport(array $data): void
    {
        $totvalOpen = $totvalIn = $totvalOut = $totvalClose = 0;
        $catgor = '';

        foreach ($data as $row) {
            // Category header
            if ($catgor !== $row['description']) {
                $this->rep->NewLine(2);
                $this->rep->fontSize += 2;
                $this->rep->TextCol(0, 3, $row['category_id'] . " - " . $row['description']);
                $catgor = $row['description'];
                $this->rep->fontSize -= 2;
                $this->rep->NewLine();
            }

            // Item line
            $this->rep->NewLine();
            $this->rep->TextCol(0, 1, $row['stock_id']);
            $this->rep->TextCol(1, 2, substr($row['name'], 0, 24) . ' ');
            $this->rep->TextCol(2, 3, $row['units']);

            $stockQtyDec = get_qty_dec($row['stock_id']);

            // Opening stock
            $this->rep->AmountCol(3, 4, $row['qoh_start'], $stockQtyDec);
            $this->rep->AmountCol(4, 5, $row['open_cost'], $this->dec);
            $openVal = $row['open_cost'] * $row['qoh_start'];
            $totvalOpen += $openVal;
            $this->rep->AmountCol(5, 6, $openVal);

            // Stock in
            if ($row['inward'] > 0) {
                $this->rep->AmountCol(6, 7, $row['inward'], $stockQtyDec);
                $this->rep->AmountCol(7, 8, $row['unit_cost_in'], $this->dec);
                $inVal = $row['unit_cost_in'] * $row['inward'];
                $totvalIn += $inVal;
                $this->rep->AmountCol(8, 9, $inVal);
            }

            // Delivery (outward)
            if ($row['outward'] > 0) {
                $this->rep->AmountCol(9, 10, $row['outward'], $stockQtyDec);
                $this->rep->AmountCol(10, 11, $row['unit_cost_out'], $this->dec);
                $outVal = $row['unit_cost_out'] * $row['outward'];
                $totvalOut += $outVal;
                $this->rep->AmountCol(11, 12, $outVal);
            }

            // Closing stock
            $this->rep->AmountCol(12, 13, $row['qoh_end'], $stockQtyDec);
            $this->rep->AmountCol(13, 14, $row['unit_cost'], $this->dec);
            $closeVal = $row['unit_cost'] * $row['qoh_end'];
            $totvalClose += $closeVal;
            $this->rep->AmountCol(14, 15, $closeVal);

            $this->rep->NewLine(0, 1);
        }

        // Totals
        $this->rep->Line($this->rep->row - 4);
        $this->rep->NewLine(2);
        $this->rep->TextCol(0, 1, _("Total Movement"));
        $this->rep->AmountCol(5, 6, $totvalOpen);
        $this->rep->AmountCol(8, 9, $totvalIn);
        $this->rep->AmountCol(11, 12, $totvalOut);
        $this->rep->AmountCol(14, 15, $totvalOpen + $totvalIn - $totvalOut);
        $this->rep->NewLine(1);
        $this->rep->TextCol(0, 1, _("Total Out"));
        $this->rep->AmountCol(14, 15, $totvalClose);
        $this->rep->Line($this->rep->row - 4);
    }

    /**
     * Get transaction quantity for a stock item
     */
    private function getTransQty(string $stockId, bool $inward): float
    {
        $from = \DateService::date2sqlStatic($this->fromDate);
        $to = \DateService::date2sqlStatic($this->toDate);

        $sql = "SELECT ".($inward ? '' : '-')."SUM(qty) FROM ".TB_PREF."stock_moves
            WHERE stock_id=".db_escape($stockId)."
            AND tran_date >= '$from'
            AND tran_date <= '$to' AND type <> ".ST_LOCTRANSFER;

        if ($this->location !== '') {
            $sql .= " AND loc_code = ".db_escape($this->location);
        }

        if ($inward) {
            $sql .= " AND qty > 0 ";
        } else {
            $sql .= " AND qty < 0 ";
        }

        $result = $this->db->fetchOne($sql);
        return $result[0] ?? 0.0;
    }

    /**
     * Get average unit cost at a specific date
     */
    private function getAvgUnitCost(string $stockId, string $toDate): float
    {
        $to = \DateService::date2sqlStatic($toDate);

        $sql = "SELECT move.*, supplier.supplier_id person_id, IF(ISNULL(grn.rate), credit.rate, grn.rate) ex_rate
            FROM ".TB_PREF."stock_moves move
                    LEFT JOIN ".TB_PREF."supp_trans credit ON credit.trans_no=move.trans_no AND credit.type=move.type
                    LEFT JOIN ".TB_PREF."grn_batch grn ON grn.id=move.trans_no AND 25=move.type
                    LEFT JOIN ".TB_PREF."suppliers supplier ON IFNULL(grn.supplier_id, credit.supplier_id)=supplier.supplier_id
                    LEFT JOIN ".TB_PREF."debtor_trans cust_trans ON cust_trans.trans_no=move.trans_no AND cust_trans.type=move.type
                    LEFT JOIN ".TB_PREF."debtors_master debtor ON cust_trans.debtor_no=debtor.debtor_no
                WHERE stock_id=".db_escape($stockId)."
                AND move.tran_date < '$to' AND qty <> 0 AND move.type <> ".ST_LOCTRANSFER;

        if ($this->location !== '') {
            $sql .= " AND move.loc_code = ".db_escape($this->location);
        }

        $sql .= " ORDER BY tran_date";

        $result = $this->db->fetchAll($sql);
        if (empty($result)) {
            return 0;
        }

        $qty = $totCost = 0;
        foreach ($result as $row) {
            $qty += $row['qty'];
            $price = $this->getDomesticPrice($row, $stockId);
            $totCost += $price * $row['qty'];
        }

        return $qty == 0 ? 0 : $totCost / $qty;
    }

    /**
     * Get transaction quantity unit cost
     */
    private function getTransQtyUnitCost(string $stockId, bool $inward): float
    {
        $from = \DateService::date2sqlStatic($this->fromDate);
        $to = \DateService::date2sqlStatic($this->toDate);

        $sql = "SELECT move.*, supplier.supplier_id person_id, IF(ISNULL(grn.rate), credit.rate, grn.rate) ex_rate
            FROM ".TB_PREF."stock_moves move
                    LEFT JOIN ".TB_PREF."supp_trans credit ON credit.trans_no=move.trans_no AND credit.type=move.type
                    LEFT JOIN ".TB_PREF."grn_batch grn ON grn.id=move.trans_no AND 25=move.type
                    LEFT JOIN ".TB_PREF."suppliers supplier ON IFNULL(grn.supplier_id, credit.supplier_id)=supplier.supplier_id
                    LEFT JOIN ".TB_PREF."debtor_trans cust_trans ON cust_trans.trans_no=move.trans_no AND cust_trans.type=move.type
                    LEFT JOIN ".TB_PREF."debtors_master debtor ON cust_trans.debtor_no=debtor.debtor_no
            WHERE stock_id=".db_escape($stockId)."
            AND move.tran_date >= '$from' AND move.tran_date <= '$to' AND qty <> 0 AND move.type <> ".ST_LOCTRANSFER;

        if ($this->location !== '') {
            $sql .= " AND move.loc_code = ".db_escape($this->location);
        }

        if ($inward) {
            $sql .= " AND qty > 0 ";
        } else {
            $sql .= " AND qty < 0 ";
        }

        $sql .= " ORDER BY tran_date";

        $result = $this->db->fetchAll($sql);
        if (empty($result)) {
            return 0;
        }

        $qty = $totCost = 0;
        foreach ($result as $row) {
            $qty += $row['qty'];
            $price = $this->getDomesticPrice($row, $stockId);
            $totCost += $row['qty'] * $price;
        }

        return $qty == 0 ? 0 : $totCost / $qty;
    }

    /**
     * Get domestic price for a stock movement
     */
    private function getDomesticPrice(array $row, string $stockId): float
    {
        if ($row['type'] == ST_SUPPRECEIVE || $row['type'] == ST_SUPPCREDIT) {
            $price = $row['price'];
            if ($row['person_id'] > 0) {
                $supp = get_supplier($row['person_id']);
                $price *= $row['ex_rate'];
            }
        } else {
            $price = $row['standard_cost'];
        }

        return $price;
    }
}
