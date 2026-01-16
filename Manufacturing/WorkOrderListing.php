<?php
/**
 * Work Order Listing Report Service
 * 
 * Generates comprehensive work order listing with:
 * - Work order details (type, ref, location, item)
 * - Required and manufactured quantities
 * - Optional GL transaction details
 * 
 * Report: rep402
 * Category: Manufacturing Reports
 */

declare(strict_types=1);

namespace FA\Reports\Manufacturing;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class WorkOrderListing extends AbstractReportService
{
    private const REPORT_ID = 402;
    private const REPORT_TITLE = 'Work Order Listing';
    
    public function __construct(
        DBALInterface $db,
        EventDispatcher $eventDispatcher
    ) {
        parent::__construct($db, $eventDispatcher);
    }
    
    protected function getReportId(): int
    {
        return self::REPORT_ID;
    }
    
    protected function getReportTitle(): string
    {
        return self::REPORT_TITLE;
    }
    
    protected function getDefaultOrientation(): string
    {
        $orientation = $this->config->getParam('orientation', 0);
        return $orientation ? 'L' : 'P';
    }
    
    protected function defineColumns(): array
    {
        return [0, 100, 120, 165, 210, 275, 315, 375, 385, 440, 495, 555];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('Type'),
            '#',
            _('Reference'),
            _('Location'),
            _('Item'),
            _('Required'),
            _('Manufactured'),
            ' ',
            _('Date'),
            _('Required By'),
            _('Closed')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'left', 'left', 'right', 'right', 'left', 'left', 'left', 'left'];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $item = $config->getParam('item');
        $itemName = $item ? get_item($item)['description'] : _('All');
        
        $location = $config->getParam('location');
        $locName = $location ? get_location_name($location) : _('All');
        
        $openOnly = $config->getParam('open_only') ? _('Yes') : _('No');
        $showGL = $config->getParam('show_gl') ? _('Yes') : _('No');
        
        return [
            0 => $config->getParam('comments'),
            1 => ['text' => _('Items'), 'from' => $itemName, 'to' => ''],
            2 => ['text' => _('Location'), 'from' => $locName, 'to' => ''],
            3 => ['text' => _('Open Only'), 'from' => $openOnly, 'to' => ''],
            4 => ['text' => _('Show GL Rows'), 'from' => $showGL, 'to' => '']
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $item = $config->getParam('item');
        $location = $config->getParam('location');
        $openOnly = $config->getParam('open_only');
        $showGL = $config->getParam('show_gl');
        
        $workOrders = $this->getWorkOrders($item, $openOnly, $location);
        
        // Get GL details if requested
        if ($showGL) {
            foreach ($workOrders as &$wo) {
                $wo['gl_productions'] = $this->getGLWOProductions($wo['id']);
                $wo['gl_issues'] = $this->getGLWOIssues($wo['id']);
                $wo['gl_costs'] = $this->getGLWOCosts($wo['id']);
                $wo['gl_trans'] = $this->getGLTrans($wo['id']);
            }
        }
        
        $data = [
            'work_orders' => $workOrders,
            'show_gl' => $showGL
        ];
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        return $data;
    }
    
    protected function processData(ReportConfig $config, array $data): array
    {
        $this->dispatchEvent('before_process_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        // Process work orders
        $processedWOs = [];
        foreach ($data['work_orders'] as $wo) {
            $processedWOs[] = [
                'id' => $wo['id'],
                'wo_ref' => $wo['wo_ref'],
                'type' => $wo['type'],
                'location_name' => $wo['location_name'],
                'description' => $wo['description'],
                'stock_id' => $wo['stock_id'],
                'units_reqd' => (float)$wo['units_reqd'],
                'units_issued' => (float)$wo['units_issued'],
                'date_' => $wo['date_'],
                'required_by' => $wo['required_by'],
                'closed' => (int)$wo['closed'],
                'gl_productions' => $wo['gl_productions'] ?? [],
                'gl_issues' => $wo['gl_issues'] ?? [],
                'gl_costs' => $wo['gl_costs'] ?? [],
                'gl_trans' => $wo['gl_trans'] ?? []
            ];
        }
        
        $processed = [
            'work_orders' => $processedWOs,
            'show_gl' => $data['show_gl']
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get work orders
     */
    private function getWorkOrders($item, $openOnly, $location): array
    {
        $sql = "SELECT
                workorder.id,
                workorder.wo_ref,
                workorder.type,
                location.location_name,
                item.description,
                workorder.units_reqd,
                workorder.units_issued,
                workorder.date_,
                workorder.required_by,
                workorder.closed,
                workorder.stock_id
            FROM ".TB_PREF."workorders as workorder
                LEFT JOIN ".TB_PREF."voided v ON v.id=workorder.id and v.type=".ST_WORKORDER.","
                .TB_PREF."stock_master as item,"
                .TB_PREF."locations as location
            WHERE ISNULL(v.id)
              AND workorder.stock_id=item.stock_id 
              AND workorder.loc_code=location.loc_code";
        
        if ($openOnly != 0) {
            $sql .= " AND workorder.closed=0";
        }
        
        if ($location != '') {
            $sql .= " AND workorder.loc_code=".$this->db->escape($location);
        }
        
        if ($item != '') {
            $sql .= " AND workorder.stock_id=".$this->db->escape($item);
        }
        
        $sql .= " ORDER BY workorder.id";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Get GL work order productions
     */
    private function getGLWOProductions(int $woId): array
    {
        return get_gl_wo_productions($woId, true);
    }
    
    /**
     * Get GL work order issues
     */
    private function getGLWOIssues(int $woId): array
    {
        return get_gl_wo_issue_trans($woId, -1, true);
    }
    
    /**
     * Get GL work order costs
     */
    private function getGLWOCosts(int $woId): array
    {
        return get_gl_wo_cost_trans($woId, -1, true);
    }
    
    /**
     * Get GL transactions
     */
    private function getGLTrans(int $woId): array
    {
        return get_gl_trans(ST_WORKORDER, $woId);
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        global $wo_types_array;
        
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        foreach ($processedData['work_orders'] as $wo) {
            $rep->TextCol(0, 1, $wo_types_array[$wo['type']]);
            $rep->TextCol(1, 2, (string)$wo['id'], -1);
            $rep->TextCol(2, 3, $wo['wo_ref'], -1);
            $rep->TextCol(3, 4, $wo['location_name'], -1);
            $rep->TextCol(4, 5, $wo['description'], -1);
            
            $dec = get_qty_dec($wo['stock_id']);
            $rep->AmountCol(5, 6, $wo['units_reqd'], $dec);
            $rep->AmountCol(6, 7, $wo['units_issued'], $dec);
            $rep->TextCol(7, 8, '', -1);
            $rep->TextCol(8, 9, \DateService::sql2dateStatic($wo['date_']), -1);
            $rep->TextCol(9, 10, \DateService::sql2dateStatic($wo['required_by']), -1);
            $rep->TextCol(10, 11, $wo['closed'] ? ' ' : _('No'), -1);
            
            if ($processedData['show_gl']) {
                $rep->NewLine();
                $this->printGLRows($rep, $wo['gl_productions'], _("Finished Product Requirements"));
                $this->printGLRows($rep, $wo['gl_issues'], _("Additional Material Issues"));
                $this->printGLRows($rep, $wo['gl_costs'], _("Additional Costs"));
                $this->printGLRows($rep, $wo['gl_trans'], _("Finished Product Receival"));
                $rep->Line($rep->row - 2);
                $rep->NewLine();
            }
            
            $rep->NewLine();
            
            if ($rep->row < $rep->bottomMargin + $rep->lineHeight) {
                $rep->NewPage();
            }
        }
        
        $rep->Line($rep->row);
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
    
    /**
     * Print GL rows
     */
    private function printGLRows($rep, $result, string $title): void
    {
        global $systypes_array;
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        
        if (!empty($result)) {
            $rep->Line($rep->row -= 4);
            $rep->NewLine();
            $rep->Font('italic');
            $rep->TextCol(3, 11, $title);
            $rep->Font();
            $rep->Line($rep->row -= 4);
            
            foreach ($result as $row) {
                $rep->NewLine();
                $rep->TextCol(0, 2, $systypes_array[$row['type']] . ' ' . $row['type_no'], -2);
                $rep->TextCol(2, 3, \DateService::sql2dateStatic($row["tran_date"]), -2);
                $rep->TextCol(3, 4, $row['account'], -2);
                $rep->TextCol(4, 5, $row['account_name'], -2);
                
                if ($row['amount'] > 0.0) {
                    $rep->AmountCol(5, 6, $row['amount'], $dec);
                } else {
                    $rep->AmountCol(6, 7, $row['amount'] * -1, $dec, -1);
                }
                
                $rep->TextCol(8, 11, $row['memo_']);
            }
        }
    }
}
