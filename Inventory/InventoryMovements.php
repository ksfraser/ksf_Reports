<?php
/**
 * Inventory Movements Report Service
 * 
 * Shows opening stock, inward/outward movements, and closing stock by item.
 * Tracks quantity movements across a date range.
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

class InventoryMovements extends AbstractReportService
{
    protected string $fromDate;
    protected string $toDate;
    protected ?int $category;
    protected string $location;

    public function __construct(EventDispatcher $eventDispatcher)
    {
        parent::__construct($eventDispatcher);
    }

    protected function initializeConfig(): ReportConfig
    {
        return new ReportConfig(
            reportNumber: 307,
            title: _('Inventory Movements'),
            fileName: 'InventoryMovements',
            orientation: $this->params['orientation'] ?? 'P'
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
    }

    protected function defineColumns(): array
    {
        return [0, 60, 220, 240, 310, 380, 450, 520];
    }

    protected function defineHeaders(): array
    {
        return [
            _('Category'),
            _('Description'),
            _('UOM'),
            _('Opening'),
            _('Quantity In'),
            _('Quantity Out'),
            _('Balance')
        ];
    }

    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'right', 'right', 'right', 'right'];
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
                stock.category_id,
                units,
                cat.description
            FROM ".TB_PREF."stock_master stock LEFT JOIN ".TB_PREF."stock_category cat ON stock.category_id=cat.category_id
                WHERE mb_flag <> 'D' AND mb_flag <>'F'";

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
            // Calculate movements
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

            $inward = $this->getTransQty($row['stock_id'], $this->location, true);
            $outward = $this->getTransQty($row['stock_id'], $this->location, false);

            $row['qoh_start'] = $qohStart;
            $row['qoh_end'] = $qohEnd;
            $row['inward'] = $inward;
            $row['outward'] = $outward;

            $processed[] = $row;
        }

        return $processed;
    }

    protected function renderReport(array $data): void
    {
        $catgor = '';

        foreach ($data as $row) {
            // Category header
            if ($catgor !== $row['description']) {
                $this->rep->Line($this->rep->row - $this->rep->lineHeight);
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
            $this->rep->TextCol(1, 2, $row['name']);
            $this->rep->TextCol(2, 3, $row['units']);

            $stockQtyDec = get_qty_dec($row['stock_id']);
            $this->rep->AmountCol(3, 4, $row['qoh_start'], $stockQtyDec);
            $this->rep->AmountCol(4, 5, $row['inward'], $stockQtyDec);
            $this->rep->AmountCol(5, 6, $row['outward'], $stockQtyDec);
            $this->rep->AmountCol(6, 7, $row['qoh_end'], $stockQtyDec);
            $this->rep->NewLine(0, 1);
        }

        $this->rep->Line($this->rep->row - 4);
        $this->rep->NewLine();
    }

    /**
     * Get transaction quantity for a stock item
     * 
     * @param string $stockId Stock ID
     * @param string $location Location code
     * @param bool $inward True for inward movements, false for outward
     * @return float Transaction quantity
     */
    private function getTransQty(string $stockId, string $location, bool $inward): float
    {
        $from = \DateService::date2sqlStatic($this->fromDate);
        $to = \DateService::date2sqlStatic($this->toDate);

        $sql = "SELECT ".($inward ? '' : '-')."SUM(qty) FROM ".TB_PREF."stock_moves
            WHERE stock_id=".db_escape($stockId)."
            AND tran_date >= '$from'
            AND tran_date <= '$to'";

        if ($location !== '') {
            $sql .= " AND loc_code = ".db_escape($location);
        }

        if ($inward) {
            $sql .= " AND qty > 0 ";
        } else {
            $sql .= " AND qty < 0 ";
        }

        $result = $this->db->fetchOne($sql);
        return $result[0] ?? 0.0;
    }
}
