<?php
/**
 * Stock Check Sheet Report Service
 * 
 * Generates inventory check sheets with quantities, demand, and on-order information.
 * Supports barcode generation and item pictures for physical inventory counts.
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

class StockCheckSheet extends AbstractReportService
{
    private const BARCODE_STYLE = [
        'position' => 'L',
        'stretch' => false,
        'fitwidth' => true,
        'cellfitalign' => '',
        'border' => false,
        'padding' => 3,
        'fgcolor' => [0,0,0],
        'bgcolor' => false,
        'text' => true,
        'font' => 'helvetica',
        'fontsize' => 8,
        'stretchtext' => 4
    ];

    protected ?int $category;
    protected string $location;
    protected bool $pictures;
    protected bool $check;
    protected bool $shortage;
    protected bool $noZeros;
    protected string $like;
    protected bool $barcodes;

    public function __construct(EventDispatcher $eventDispatcher)
    {
        parent::__construct($eventDispatcher);
    }

    protected function initializeConfig(): ReportConfig
    {
        return new ReportConfig(
            reportNumber: 303,
            title: _('Stock Check Sheets'),
            fileName: 'StockCheckSheet',
            orientation: $this->params['orientation'] ?? 'P'
        );
    }

    protected function loadParameters(): void
    {
        $this->category = $this->extractor->getIntOrNull('PARAM_0');
        $this->location = $this->extractor->getString('PARAM_1');
        $this->pictures = $this->extractor->getBool('PARAM_2');
        $this->check = $this->extractor->getBool('PARAM_3');
        $this->shortage = $this->extractor->getBool('PARAM_4');
        $this->noZeros = $this->extractor->getBool('PARAM_5');
        $this->like = $this->extractor->getString('PARAM_6');
        $this->params['comments'] = $this->extractor->getString('PARAM_7');
        $this->params['orientation'] = $this->extractor->getBool('PARAM_8') ? 'L' : 'P';
        $this->params['destination'] = $this->extractor->getString('PARAM_9');

        global $SysPrefs;
        $this->barcodes = !empty($SysPrefs->prefs['barcodes_on_stock']);
    }

    protected function defineColumns(): array
    {
        if ($this->check) {
            return [0, 75, 225, 250, 295, 345, 390, 445, 515];
        }
        return [0, 75, 225, 250, 315, 380, 445, 515];
    }

    protected function defineHeaders(): array
    {
        $available = $this->shortage ? _('Shortage') : _('Available');
        
        if ($this->check) {
            return [
                _('Stock ID'),
                _('Description'),
                _('UOM'),
                _('Quantity'),
                _('Check'),
                _('Demand'),
                $available,
                _('On Order')
            ];
        }
        return [
            _('Stock ID'),
            _('Description'),
            _('UOM'),
            _('Quantity'),
            _('Demand'),
            $available,
            _('On Order')
        ];
    }

    protected function defineAlignments(): array
    {
        if ($this->check) {
            return ['left', 'left', 'left', 'right', 'right', 'right', 'right', 'right'];
        }
        return ['left', 'left', 'left', 'right', 'right', 'right', 'right'];
    }

    protected function buildReportParameters(): array
    {
        $cat = $this->category === null ? _('All') : get_category_name($this->category);
        $loc = $this->location === 'all' ? _('All') : get_location_name($this->location);
        $short = $this->shortage ? _('Yes') : _('No');
        $nozeros = $this->noZeros ? _('Yes') : _('No');

        return [
            0 => $this->params['comments'],
            1 => ['text' => _('Category'), 'from' => $cat, 'to' => ''],
            2 => ['text' => _('Location'), 'from' => $loc, 'to' => ''],
            3 => ['text' => _('Only Shortage'), 'from' => $short, 'to' => ''],
            4 => ['text' => _('Suppress Zeros'), 'from' => $nozeros, 'to' => '']
        ];
    }

    protected function fetchData(): array
    {
        $sql = "SELECT item.category_id,
                category.description AS cat_description,
                item.stock_id, item.units,
                item.description, item.inactive,
                IF(move.stock_id IS NULL, '', move.loc_code) AS loc_code,
                SUM(IF(move.stock_id IS NULL,0,move.qty)) AS QtyOnHand
            FROM ("
                .TB_PREF."stock_master item,"
                .TB_PREF."stock_category category)
                LEFT JOIN ".TB_PREF."stock_moves move ON item.stock_id=move.stock_id
            WHERE item.category_id=category.category_id
            AND (item.mb_flag='B' OR item.mb_flag='M')";

        if ($this->category !== null) {
            $sql .= " AND item.category_id = ".db_escape($this->category);
        }

        if ($this->location !== 'all') {
            $sql .= " AND IF(move.stock_id IS NULL, '1=1',move.loc_code = ".db_escape($this->location).")";
        }

        if ($this->like) {
            $regexp = null;
            if (sscanf($this->like, "/%s", $regexp) === 1) {
                $sql .= " AND item.stock_id RLIKE ".db_escape($regexp);
            } else {
                $sql .= " AND item.stock_id LIKE ".db_escape($this->like);
            }
        }

        $sql .= " GROUP BY item.category_id,
            category.description,
            item.stock_id,
            item.description
            ORDER BY item.category_id,
            item.stock_id";

        return $this->db->fetchAll($sql);
    }

    protected function processData(array $data): array
    {
        $locCode = $this->location === 'all' ? '' : $this->location;
        $processed = [];

        foreach ($data as $row) {
            $demandQty = get_demand_qty($row['stock_id'], $locCode);
            $demandQty += get_demand_asm_qty($row['stock_id'], $locCode);
            $onOrder = get_on_porder_qty($row['stock_id'], $locCode);
            $onOrder += get_on_worder_qty($row['stock_id'], $locCode);

            // Skip if suppressing zeros
            if ($this->noZeros && $row['QtyOnHand'] == 0 && $demandQty == 0 && $onOrder == 0) {
                continue;
            }

            // Skip if showing only shortage
            if ($this->shortage && $row['QtyOnHand'] - $demandQty >= 0) {
                continue;
            }

            $row['demand_qty'] = $demandQty;
            $row['on_order'] = $onOrder;
            $row['available'] = $row['QtyOnHand'] - $demandQty;

            $processed[] = $row;
        }

        return $processed;
    }

    protected function renderReport(array $data): void
    {
        global $SysPrefs;
        $catt = '';

        foreach ($data as $row) {
            // Category header
            if ($catt !== $row['cat_description']) {
                if ($catt !== '') {
                    $this->rep->Line($this->rep->row - 2);
                    $this->rep->NewLine(2, 3);
                }
                $this->rep->TextCol(0, 1, $row['category_id']);
                $this->rep->TextCol(1, 2, $row['cat_description']);
                $catt = $row['cat_description'];
                $this->rep->NewLine();
            }

            $this->rep->NewLine();
            $dec = get_qty_dec($row['stock_id']);
            
            // Item details
            $this->rep->TextCol(0, 1, $row['stock_id']);
            $this->rep->TextCol(1, 2, $row['description'].($row['inactive']==1 ? " ("._("Inactive").")" : ""), -1);
            $this->rep->TextCol(2, 3, $row['units']);
            $this->rep->AmountCol(3, 4, $row['QtyOnHand'], $dec);

            if ($this->check) {
                $this->rep->TextCol(4, 5, "_________");
                $this->rep->AmountCol(5, 6, $row['demand_qty'], $dec);
                $this->rep->AmountCol(6, 7, $row['available'], $dec);
                $this->rep->AmountCol(7, 8, $row['on_order'], $dec);
            } else {
                $this->rep->AmountCol(4, 5, $row['demand_qty'], $dec);
                $this->rep->AmountCol(5, 6, $row['available'], $dec);
                $this->rep->AmountCol(6, 7, $row['on_order'], $dec);
            }

            // Add pictures or barcodes if requested
            if ($this->pictures || $this->barcodes) {
                $this->rep->NewLine();
                if ($this->rep->row - $SysPrefs->pic_height < $this->rep->bottomMargin) {
                    $this->rep->NewPage();
                }

                $firstcol = 1;
                $adjust = false;

                // Add barcode
                if ($this->barcodes && $this->barcodeCheck($row['stock_id'])) {
                    $adjust = true;
                    $barY = $this->rep->GetY();
                    $barcode = str_pad($row['stock_id'], 7, '0', STR_PAD_LEFT);
                    $barcode = substr($barcode, 0, 8);
                    $this->rep->write1DBarcode(
                        $barcode,
                        'EAN8',
                        $this->rep->cols[$firstcol++],
                        $barY + 22,
                        22,
                        $SysPrefs->pic_height,
                        1.2,
                        self::BARCODE_STYLE,
                        'N'
                    );
                }

                // Add picture
                if ($this->pictures) {
                    $adjust = true;
                    $image = company_path() . '/images/' . item_img_name($row['stock_id']) . '.jpg';
                    if (file_exists($image)) {
                        $this->rep->AddImage(
                            $image,
                            $this->rep->cols[$firstcol],
                            $this->rep->row - $SysPrefs->pic_height,
                            0,
                            $SysPrefs->pic_height
                        );
                    }
                }

                if ($adjust) {
                    $this->rep->row -= $SysPrefs->pic_height;
                }
            }
        }

        $this->rep->Line($this->rep->row - 4);
        $this->rep->NewLine();
    }

    /**
     * Check if a barcode can be valid and returns type of barcode
     * 
     * @param string $code Barcode to check
     * @return bool True if valid barcode
     */
    private function barcodeCheck(string $code): bool
    {
        $code = trim($code);
        if (preg_match('/[^0-9]/', $code)) {
            return false;
        }

        $length = strlen($code);
        if (!(($length > 11 && $length <= 14) || $length === 8)) {
            return false;
        }

        $zeroes = 18 - $length;
        $fill = str_repeat("0", $zeroes);
        $code = $fill . $code;

        $calc = 0;
        for ($i = 0; $i < (strlen($code) - 1); $i++) {
            $calc += ($i % 2 ? $code[$i] * 1 : $code[$i] * 3);
        }

        return substr(10 - (substr($calc, -1)), -1) == substr($code, -1);
    }
}
