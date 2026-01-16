<?php
/**
 * Work Orders Document Printer Service
 * 
 * Generates work order documents for printing/email:
 * - Work order header with item details
 * - Component requirements with locations and work centers
 * - Quantities required and issued
 * - Email support for individual work orders
 * 
 * Report: rep409
 * Category: Manufacturing Reports
 */

declare(strict_types=1);

namespace FA\Reports\Manufacturing;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class WorkOrders extends AbstractReportService
{
    private const REPORT_ID = 409;
    private const REPORT_TITLE = 'WORK ORDER';
    
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
        return [4, 60, 190, 255, 320, 385, 450, 515];
    }
    
    protected function defineHeaders(): array
    {
        return []; // Headers defined in doctext
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'left', 'right', 'right', 'right'];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        return [
            'comments' => $config->getParam('comments')
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        global $dflt_lang;
        
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $from = $config->getParam('from');
        $to = $config->getParam('to');
        $email = $config->getParam('email', 0);
        
        // Parse work order numbers
        $fno = explode("-", $from);
        $tno = explode("-", $to);
        $fromWO = min($fno[0], $tno[0]);
        $toWO = max($fno[0], $tno[0]);
        
        // Fetch work orders in range
        $workOrders = [];
        for ($i = $fromWO; $i <= $toWO; $i++) {
            $wo = get_work_order($i, true);
            if ($wo !== false) {
                $wo['requirements'] = $this->getWORequirements($i);
                $wo['memo'] = get_comments_string(ST_WORKORDER, $i);
                $workOrders[] = $wo;
            }
        }
        
        $data = [
            'work_orders' => $workOrders,
            'email' => $email,
            'dflt_lang' => $dflt_lang
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
        
        // Process each work order
        $processedWOs = [];
        foreach ($data['work_orders'] as $wo) {
            $processedReqs = [];
            foreach ($wo['requirements'] as $req) {
                $processedReqs[] = [
                    'stock_id' => $req['stock_id'],
                    'description' => $req['description'],
                    'location_name' => $req['location_name'],
                    'WorkCentreDescription' => $req['WorkCentreDescription'],
                    'units_req' => (float)$req['units_req'],
                    'units_issued' => (float)$req['units_issued'],
                    'total_req' => (float)$req['units_req'] * (float)$wo['units_issued']
                ];
            }
            
            $processedWOs[] = [
                'wo_data' => $wo,
                'requirements' => $processedReqs,
                'memo' => $wo['memo'],
                'contact' => [
                    'email' => $wo['email'],
                    'lang' => $data['dflt_lang'],
                    'name' => $wo['contact'],
                    'name2' => '',
                    'type' => 'contact'
                ]
            ];
        }
        
        $processed = [
            'work_orders' => $processedWOs,
            'email' => $data['email']
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get work order requirements
     */
    private function getWORequirements(int $woId): array
    {
        return get_wo_requirements($woId);
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        $cur = \FA\Services\CompanyPrefsService::getDefaultCurrency();
        $email = $processedData['email'];
        
        foreach ($processedData['work_orders'] as $woData) {
            $wo = $woData['wo_data'];
            $contact = $woData['contact'];
            
            // If emailing individually, create new report for each
            if ($email == 1) {
                global $path_to_root;
                include_once($path_to_root . "/reporting/includes/pdf_report.inc");
                
                $orientation = $this->getDefaultOrientation();
                $rep = new \FrontReport("", "", user_pagesize(), 9, $orientation);
                $rep->title = $this->getReportTitle();
                $rep->filename = "WorkOrder" . $wo['wo_ref'] . ".pdf";
                
                if ($orientation == 'L') {
                    $cols = $this->defineColumns();
                    recalculate_cols($cols);
                }
            }
            
            $rep->currency = $cur;
            $rep->Font();
            
            $params = $this->defineParams($config);
            $cols = $this->defineColumns();
            $aligns = $this->defineAlignments();
            $rep->Info($params, $cols, null, $aligns);
            
            $rep->SetCommonData($wo, null, null, '', 26, $contact);
            $rep->SetHeaderType('Header2');
            $rep->NewPage();
            
            // Requirements section
            $rep->TextCol(0, 5, _("Work Order Requirements"), -2);
            $rep->NewLine(2);
            
            foreach ($woData['requirements'] as $req) {
                $rep->TextCol(0, 1, $req['stock_id'], -2);
                $rep->TextCol(1, 2, $req['description'], -2);
                $rep->TextCol(2, 3, $req['location_name'], -2);
                $rep->TextCol(3, 4, $req['WorkCentreDescription'], -2);
                
                $dec = get_qty_dec($req["stock_id"]);
                $rep->AmountCol(4, 5, $req['units_req'], $dec, -2);
                $rep->AmountCol(5, 6, $req['total_req'], $dec, -2);
                $rep->AmountCol(6, 7, $req['units_issued'], $dec, -2);
                $rep->NewLine(1);
                
                if ($rep->row < $rep->bottomMargin + (15 * $rep->lineHeight)) {
                    $rep->NewPage();
                }
            }
            
            // Memo/comments
            if ($woData['memo'] != "") {
                $rep->NewLine();
                $rep->TextColLines(1, 5, $woData['memo'], -2);
            }
            
            // End report for individual email
            if ($email == 1) {
                $wo['DebtorName'] = $wo['contact'];
                $wo['reference'] = $wo['wo_ref'];
                $rep->End($email);
            }
        }
        
        // End bulk report
        if ($email == 0) {
            $rep->End();
        }
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
