<?php
/**
 * Inventory Purchasing Report Service
 * 
 * Analyzes purchases by item, supplier with quantity and cost details.
 * Shows supplier invoice references and purchase order details.
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

class InventoryPurchasingReport extends AbstractReportService
{
    protected string $fromDate;
    protected string $toDate;
    protected ?int $category;
    protected string $location;
    protected string $fromSupp;
    protected string $item;
    protected int $dec;

    public function __construct(EventDispatcher $eventDispatcher)
    {
        parent::__construct($eventDispatcher);
    }

    protected function initializeConfig(): ReportConfig
    {
        return new ReportConfig(
            reportNumber: 306,
            title: _('Inventory Purchasing Report'),
            fileName: 'InventoryPurchasingReport',
            orientation: $this->params['orientation'] ?? 'P'
        );
    }

    protected function loadParameters(): void
    {
        $this->fromDate = $this->extractor->getDate('PARAM_0');
        $this->toDate = $this->extractor->getDate('PARAM_1');
        $this->category = $this->extractor->getIntOrNull('PARAM_2');
        $this->location = $this->extractor->getString('PARAM_3');
        $this->fromSupp = $this->extractor->getString('PARAM_4');
        $this->item = $this->extractor->getString('PARAM_5');
        $this->params['comments'] = $this->extractor->getString('PARAM_6');
        $this->params['orientation'] = $this->extractor->getBool('PARAM_7') ? 'L' : 'P';
        $this->params['destination'] = $this->extractor->getString('PARAM_8');

        $this->dec = \FA\UserPrefsCache::getPriceDecimals();
    }

    protected function defineColumns(): array
    {
        return [0, 60, 180, 225, 275, 400, 420, 465, 520];
    }

    protected function defineHeaders(): array
    {
        $headers = [
            _('Category'),
            _('Description'),
            _('Date'),
            _('#'),
            _('Supplier'),
            _('Qty'),
            _('Unit Price'),
            _('Total')
        ];

        if ($this->fromSupp !== '') {
            $headers[4] = '';
        }

        return $headers;
    }

    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'left', 'left', 'left', 'right', 'right'];
    }

    protected function buildReportParameters(): array
    {
        $cat = $this->category === null ? _('All') : get_category_name($this->category);
        $loc = $this->location === '' ? _('All') : get_location_name($this->location);
        $froms = $this->fromSupp === '' ? _('All') : get_supplier_name($this->fromSupp);
        $itm = $this->item === '' ? _('All') : $this->item;

        return [
            0 => $this->params['comments'],
            1 => ['text' => _('Period'), 'from' => $this->fromDate, 'to' => $this->toDate],
            2 => ['text' => _('Category'), 'from' => $cat, 'to' => ''],
            3 => ['text' => _('Location'), 'from' => $loc, 'to' => ''],
            4 => ['text' => _('Supplier'), 'from' => $froms, 'to' => ''],
            5 => ['text' => _('Item'), 'from' => $itm, 'to' => '']
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
                move.loc_code,
                supplier.supplier_id, IF(ISNULL(grn.rate), credit.rate, grn.rate) ex_rate,
                supplier.supp_name AS supplier_name,
                move.tran_date,
                move.qty AS qty,
                move.price
            FROM ".TB_PREF."stock_moves move
                    LEFT JOIN ".TB_PREF."supp_trans credit ON credit.trans_no=move.trans_no AND credit.type=move.type
                    LEFT JOIN ".TB_PREF."grn_batch grn ON grn.id=move.trans_no AND 25=move.type
                    LEFT JOIN ".TB_PREF."suppliers supplier ON IFNULL(grn.supplier_id, credit.supplier_id)=supplier.supplier_id,
                ".TB_PREF."stock_master item,
                ".TB_PREF."stock_category category
            WHERE item.stock_id=move.stock_id
            AND item.category_id=category.category_id
            AND move.tran_date>='$from'
            AND move.tran_date<='$to'
            AND (move.type=".ST_SUPPRECEIVE." OR move.type=".ST_SUPPCREDIT.")
            AND (item.mb_flag='B' OR item.mb_flag='M')";

        if ($this->category !== null) {
            $sql .= " AND item.category_id = ".db_escape($this->category);
        }
        if ($this->location !== '') {
            $sql .= " AND move.loc_code = ".db_escape($this->location);
        }
        if ($this->fromSupp !== '') {
            $sql .= " AND supplier.supplier_id = ".db_escape($this->fromSupp);
        }
        if ($this->item !== '') {
            $sql .= " AND item.stock_id = ".db_escape($this->item);
        }

        $sql .= " ORDER BY item.category_id,
            supplier.supp_name, item.stock_id, move.tran_date";

        return $this->db->fetchAll($sql);
    }

    protected function processData(array $data): array
    {
        foreach ($data as &$row) {
            // Get supplier invoice reference
            $row['supp_reference'] = $this->getSupplierInvoiceReference(
                $row['supplier_id'],
                $row['stock_id'],
                $row['tran_date']
            );

            // Convert price to home currency
            $row['price'] *= $row['ex_rate'];
        }

        return $data;
    }

    protected function renderReport(array $data): void
    {
        $total = $totalSupp = $grandTotal = 0.0;
        $totalQty = 0.0;
        $catt = $stockDescription = $stockId = $supplierName = '';

        foreach ($data as $row) {
            // Stock description break
            if ($stockDescription !== $row['description']) {
                if ($stockDescription !== '') {
                    if ($supplierName !== '') {
                        $this->rep->NewLine(2, 3);
                        $this->rep->TextCol(0, 1, _('Total'));
                        $this->rep->TextCol(1, 4, $stockDescription);
                        $this->rep->TextCol(4, 5, $supplierName);
                        $this->rep->AmountCol(5, 7, $totalQty, get_qty_dec($stockId));
                        $this->rep->AmountCol(7, 8, $totalSupp, $this->dec);
                        $this->rep->Line($this->rep->row - 2);
                        $this->rep->NewLine();
                        $totalSupp = $totalQty = 0.0;
                        $supplierName = $row['supplier_name'];
                    }
                }
                $stockId = $row['stock_id'];
                $stockDescription = $row['description'];
            }

            // Supplier break
            if ($supplierName !== $row['supplier_name']) {
                if ($supplierName !== '') {
                    $this->rep->NewLine(2, 3);
                    $this->rep->TextCol(0, 1, _('Total'));
                    $this->rep->TextCol(1, 4, $stockDescription);
                    $this->rep->TextCol(4, 5, $supplierName);
                    $this->rep->AmountCol(5, 7, $totalQty, get_qty_dec($stockId));
                    $this->rep->AmountCol(7, 8, $totalSupp, $this->dec);
                    $this->rep->Line($this->rep->row - 2);
                    $this->rep->NewLine();
                    $totalSupp = $totalQty = 0.0;
                }
                $supplierName = $row['supplier_name'];
            }

            // Category break
            if ($catt !== $row['cat_description']) {
                if ($catt !== '') {
                    $this->rep->NewLine(2, 3);
                    $this->rep->TextCol(0, 1, _('Total'));
                    $this->rep->TextCol(1, 7, $catt);
                    $this->rep->AmountCol(7, 8, $total, $this->dec);
                    $this->rep->Line($this->rep->row - 2);
                    $this->rep->NewLine();
                    $this->rep->NewLine();
                    $total = 0.0;
                }
                $this->rep->TextCol(0, 1, $row['category_id']);
                $this->rep->TextCol(1, 6, $row['cat_description']);
                $catt = $row['cat_description'];
                $this->rep->NewLine();
            }

            // Detail line
            $this->rep->NewLine();
            $this->rep->fontSize -= 2;
            $this->rep->TextCol(0, 1, $row['stock_id']);
            
            if ($this->fromSupp === '') {
                $this->rep->TextCol(1, 2, $row['description'].($row['inactive']==1 ? " ("._("Inactive").")" : ""), -1);
                $this->rep->TextCol(2, 3, \DateService::sql2dateStatic($row['tran_date']));
                $this->rep->TextCol(3, 4, $row['supp_reference']);
                $this->rep->TextCol(4, 5, $row['supplier_name']);
            } else {
                $this->rep->TextCol(1, 2, $row['description'].($row['inactive']==1 ? " ("._("Inactive").")" : ""), -1);
                $this->rep->TextCol(2, 3, \DateService::sql2dateStatic($row['tran_date']));
                $this->rep->TextCol(3, 4, $row['supp_reference']);
            }

            $this->rep->AmountCol(5, 6, $row['qty'], get_qty_dec($row['stock_id']));
            $this->rep->AmountCol(6, 7, $row['price'], $this->dec);
            $amt = $row['qty'] * $row['price'];
            $this->rep->AmountCol(7, 8, $amt, $this->dec);
            $this->rep->fontSize += 2;

            $total += $amt;
            $totalSupp += $amt;
            $grandTotal += $amt;
            $totalQty += $row['qty'];
        }

        // Final totals
        if ($stockDescription !== '') {
            if ($supplierName !== '') {
                $this->rep->NewLine(2, 3);
                $this->rep->TextCol(0, 1, _('Total'));
                $this->rep->TextCol(1, 4, $stockDescription);
                $this->rep->TextCol(4, 5, $supplierName);
                $this->rep->AmountCol(5, 7, $totalQty, get_qty_dec($stockId));
                $this->rep->AmountCol(7, 8, $totalSupp, $this->dec);
                $this->rep->Line($this->rep->row - 2);
                $this->rep->NewLine();
                $this->rep->NewLine();
            }
        }

        if ($catt !== '') {
            $this->rep->NewLine(2, 3);
            $this->rep->TextCol(0, 1, _('Total'));
            $this->rep->TextCol(1, 7, $catt);
            $this->rep->AmountCol(7, 8, $total, $this->dec);
            $this->rep->Line($this->rep->row - 2);
            $this->rep->NewLine();
        }

        // Grand total
        $this->rep->NewLine(2, 1);
        $this->rep->TextCol(0, 7, _('Grand Total'));
        $this->rep->AmountCol(7, 8, $grandTotal, $this->dec);
        $this->rep->Line($this->rep->row - 4);
        $this->rep->NewLine();
    }

    /**
     * Get supplier invoice reference for a purchase
     * 
     * @param int $supplierId Supplier ID
     * @param string $stockId Stock ID
     * @param string $date Transaction date (SQL format)
     * @return string Supplier invoice reference
     */
    private function getSupplierInvoiceReference(int $supplierId, string $stockId, string $date): string
    {
        $sql = "SELECT trans.supp_reference
            FROM ".TB_PREF."supp_trans trans,
                ".TB_PREF."supp_invoice_items line,
                ".TB_PREF."grn_batch batch,
                ".TB_PREF."grn_items item
            WHERE trans.type=line.supp_trans_type
            AND trans.trans_no=line.supp_trans_no
            AND item.grn_batch_id=batch.id
            AND item.item_code=line.stock_id
            AND trans.supplier_id=".db_escape($supplierId)."
            AND line.stock_id=".db_escape($stockId)."
            AND trans.tran_date=".db_escape($date);

        $result = $this->db->fetchOne($sql);
        return $result['supp_reference'] ?? '';
    }
}
