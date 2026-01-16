<?php
/**
 * GRN Valuation Report Service
 * 
 * Analyzes goods received notes (GRNs) with valuation information.
 * Shows PO prices vs invoice prices for received goods, highlighting variances.
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

require_once($GLOBALS['path_to_root'] . "/includes/BankingService.php");

class GRNValuationReport extends AbstractReportService
{
    protected string $fromDate;
    protected string $toDate;
    protected int $dec;

    public function __construct(EventDispatcher $eventDispatcher)
    {
        parent::__construct($eventDispatcher);
    }

    protected function initializeConfig(): ReportConfig
    {
        return new ReportConfig(
            reportNumber: 305,
            title: _('GRN Valuation Report'),
            fileName: 'GRNValuationReport',
            orientation: $this->params['orientation'] ?? 'P'
        );
    }

    protected function loadParameters(): void
    {
        $this->fromDate = $this->extractor->getDate('PARAM_0');
        $this->toDate = $this->extractor->getDate('PARAM_1');
        $this->params['comments'] = $this->extractor->getString('PARAM_2');
        $this->params['orientation'] = $this->extractor->getBool('PARAM_3') ? 'L' : 'P';
        $this->params['destination'] = $this->extractor->getString('PARAM_4');

        $this->dec = \FA\UserPrefsCache::getPriceDecimals();
    }

    protected function defineColumns(): array
    {
        return [0, 75, 225, 260, 295, 330, 370, 410, 455, 515];
    }

    protected function defineHeaders(): array
    {
        return [
            _('Stock ID'),
            _('Description'),
            _('PO No'),
            _('GRN')."#",
            _('Inv')."#",
            _('Qty'),
            _('Inv Price'),
            _('PO Price'),
            _('Total')
        ];
    }

    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'left', 'left', 'right', 'right', 'right', 'right'];
    }

    protected function buildReportParameters(): array
    {
        return [
            0 => $this->params['comments'],
            1 => ['text' => _('Period'), 'from' => $this->fromDate, 'to' => $this->toDate]
        ];
    }

    protected function fetchData(): array
    {
        $from = \DateService::date2sqlStatic($this->fromDate);
        $to = \DateService::date2sqlStatic($this->toDate);

        $sql = "SELECT grn.id batch_no,
                grn.supplier_id,
                grn.delivery_date,
                poline.*,
                item.description,
                grn_line.qty_recd,
                grn_line.quantity_inv,
                grn_line.id grn_item_id
            FROM "
                .TB_PREF."stock_master item,"
                .TB_PREF."purch_order_details poline,"
                .TB_PREF."grn_batch grn,"
                .TB_PREF."grn_items grn_line
            WHERE item.stock_id=poline.item_code
            AND grn.purch_order_no=poline.order_no
            AND grn.id = grn_line.grn_batch_id
            AND grn_line.po_detail_item = poline.po_detail_item
            AND grn_line.qty_recd>0
            AND grn.delivery_date>='$from'
            AND grn.delivery_date<='$to'
            AND item.mb_flag <>'F'
            ORDER BY item.stock_id, grn.delivery_date";

        return $this->db->fetchAll($sql);
    }

    protected function processData(array $data): array
    {
        $processed = [];

        foreach ($data as $row) {
            // Get supplier invoice details for this GRN item
            $row['invoices'] = $this->getSupplierInvoiceDetails($row['grn_item_id']);
            $processed[] = $row;
        }

        return $processed;
    }

    protected function renderReport(array $data): void
    {
        $total = $qtotal = $grandtotal = 0.0;
        $stockId = '';

        foreach ($data as $row) {
            // Item break with totals
            if ($stockId !== $row['item_code']) {
                if ($stockId !== '') {
                    $this->rep->Line($this->rep->row - 4);
                    $this->rep->NewLine(2);
                    $this->rep->TextCol(0, 3, _('Total'));
                    $qdec = get_qty_dec($stockId);
                    $this->rep->AmountCol(5, 6, $qtotal, $qdec);
                    $this->rep->AmountCol(8, 9, $total, $this->dec);
                    $this->rep->NewLine();
                    $total = $qtotal = 0;
                }
                $stockId = $row['item_code'];
            }

            $this->rep->NewLine();
            $this->rep->TextCol(0, 1, $row['item_code']);
            $this->rep->TextCol(1, 2, $row['description']);
            $this->rep->TextCol(2, 3, $row['order_no']);
            $qdec = get_qty_dec($row['item_code']);
            $this->rep->TextCol(3, 4, $row['batch_no']);

            // Process invoices for this GRN item
            if ($row['quantity_inv']) {
                foreach ($row['invoices'] as $inv) {
                    $this->rep->TextCol(4, 5, $inv['inv_no']);
                    $this->rep->AmountCol(5, 6, $inv['inv_qty'], $qdec);
                    $this->rep->AmountCol(6, 7, $inv['inv_price'], $this->dec);
                    $this->rep->AmountCol(7, 8, $row['std_cost_unit'], $this->dec);
                    $amt = round2($inv['inv_qty'] * $inv['inv_price'], $this->dec);
                    $this->rep->AmountCol(8, 9, $amt, $this->dec);
                    $this->rep->NewLine();
                    $total += $amt;
                    $qtotal += $inv['inv_qty'];
                    $grandtotal += $amt;
                }
            }

            // Handle uninvoiced quantities
            $uninvoicedQty = $row['qty_recd'] - $row['quantity_inv'];
            if ($uninvoicedQty != 0) {
                $curr = get_supplier_currency($row['supplier_id']);
                $rate = \BankingService::getExchangeRateFromHomeCurrency(
                    $curr,
                    \DateService::sql2dateStatic($row['delivery_date'])
                );
                $unitPrice = $row['unit_price'] * $rate;

                $this->rep->TextCol(4, 5, "--");
                $this->rep->AmountCol(5, 6, $uninvoicedQty, $qdec);
                $this->rep->AmountCol(7, 8, $unitPrice, $this->dec);
                $amt = round2($uninvoicedQty * $unitPrice, $this->dec);
                $this->rep->AmountCol(8, 9, $amt, $this->dec);
                $total += $amt;
                $qtotal += $uninvoicedQty;
                $grandtotal += $amt;
            } else {
                $this->rep->NewLine(-1);
            }
        }

        // Final item total
        if ($stockId !== '') {
            $this->rep->Line($this->rep->row - 4);
            $this->rep->NewLine(2);
            $this->rep->TextCol(0, 3, _('Total'));
            $qdec = get_qty_dec($stockId);
            $this->rep->AmountCol(5, 6, $qtotal, $qdec);
            $this->rep->AmountCol(8, 9, $total, $this->dec);
            $this->rep->Line($this->rep->row - 4);
            $this->rep->NewLine(2);
            $this->rep->TextCol(0, 7, _('Grand Total'));
            $this->rep->AmountCol(8, 9, $grandtotal, $this->dec);
        }

        $this->rep->Line($this->rep->row - 4);
        $this->rep->NewLine();
    }

    /**
     * Get supplier invoice details for a GRN item
     * 
     * @param int $grnItemId GRN item ID
     * @return array Invoice details with quantities and prices
     */
    private function getSupplierInvoiceDetails(int $grnItemId): array
    {
        $sql = "SELECT
                inv_line.supp_trans_no inv_no,
                inv_line.quantity inv_qty,
                inv.rate,
                IF (inv.tax_included = 1, inv_line.unit_price - inv_line.unit_tax, inv_line.unit_price) inv_price
                FROM "
                    .TB_PREF."grn_items grn_line,"
                    .TB_PREF."supp_trans inv,"
                    .TB_PREF."supp_invoice_items inv_line
                WHERE grn_line.id = inv_line.grn_item_id
                AND grn_line.po_detail_item = inv_line.po_detail_item_id
                AND grn_line.item_code = inv_line.stock_id
                AND inv.type = inv_line.supp_trans_type
                AND inv.trans_no = inv_line.supp_trans_no
                AND inv_line.supp_trans_type = 20
                AND inv_line.grn_item_id = ".db_escape($grnItemId)."
                ORDER BY inv_line.id asc";

        $result = $this->db->fetchAll($sql);

        // Convert invoice prices to home currency
        foreach ($result as &$inv) {
            $inv['inv_price'] *= $inv['rate'];
        }

        return $result;
    }
}
