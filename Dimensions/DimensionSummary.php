<?php
/**
 * Dimension Summary Report Service
 * 
 * Generates dimension summary report showing:
 * - Dimension reference and name
 * - Type, dates, and closure status
 * - Optional year-to-date balances
 * 
 * Report: rep501
 * Category: Dimensions Reports
 */

declare(strict_types=1);

namespace FA\Reports\Dimensions;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class DimensionSummary extends AbstractReportService
{
    private const REPORT_ID = 501;
    private const REPORT_TITLE = 'Dimension Summary';
    
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
        return [0, 50, 210, 250, 320, 395, 465, 515];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('Reference'),
            _('Name'),
            _('Type'),
            _('Date'),
            _('Due Date'),
            _('Closed'),
            _('YTD')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'left', 'left', 'left', 'right'];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $fromDim = $config->getParam('from_dim');
        $toDim = $config->getParam('to_dim');
        
        return [
            0 => $config->getParam('comments'),
            1 => [
                'text' => _('Dimension'),
                'from' => get_dimension_string($fromDim),
                'to' => get_dimension_string($toDim)
            ]
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $fromDim = $config->getParam('from_dim');
        $toDim = $config->getParam('to_dim');
        $showBalance = $config->getParam('show_balance');
        
        $dimensions = $this->getDimensions($fromDim, $toDim);
        
        // Get YTD balances if requested
        if ($showBalance) {
            foreach ($dimensions as &$dim) {
                $dim['ytd_balance'] = $this->getYTDBalance($dim['id']);
            }
        }
        
        $data = [
            'dimensions' => $dimensions,
            'show_balance' => $showBalance
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
        
        $processedDims = [];
        foreach ($data['dimensions'] as $dim) {
            $processedDims[] = [
                'reference' => $dim['reference'],
                'name' => $dim['name'],
                'type' => $dim['type_'],
                'date' => $dim['date_'],
                'due_date' => $dim['due_date'],
                'closed' => (int)$dim['closed'],
                'ytd_balance' => $dim['ytd_balance'] ?? 0.0
            ];
        }
        
        $processed = [
            'dimensions' => $processedDims,
            'show_balance' => $data['show_balance']
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get dimensions in range
     */
    private function getDimensions(int $fromDim, int $toDim): array
    {
        $sql = "SELECT *
                FROM ".TB_PREF."dimensions
                WHERE id >= ".$this->db->escape($fromDim)."
                  AND id <= ".$this->db->escape($toDim)."
                ORDER BY reference";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Get year-to-date balance for a dimension
     */
    private function getYTDBalance(int $dimId): float
    {
        $date = \DateService::todayStatic();
        $date = \FA\Services\DateService::beginFiscalYear($date);
        $sqlDate = \DateService::date2sqlStatic($date);
        
        $sql = "SELECT SUM(amount) AS Balance
                FROM ".TB_PREF."gl_trans
                WHERE (dimension_id = ".$this->db->escape($dimId)." OR dimension2_id = ".$this->db->escape($dimId).")
                  AND tran_date >= ".$this->db->escape($sqlDate);
        
        $result = $this->db->fetchOne($sql);
        return $result ? (float)$result['Balance'] : 0.0;
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        foreach ($processedData['dimensions'] as $dim) {
            $rep->TextCol(0, 1, $dim['reference']);
            $rep->TextCol(1, 2, $dim['name']);
            $rep->TextCol(2, 3, $dim['type']);
            $rep->DateCol(3, 4, $dim['date'], true);
            $rep->DateCol(4, 5, $dim['due_date'], true);
            
            $closedStr = $dim['closed'] ? _('Yes') : _('No');
            $rep->TextCol(5, 6, $closedStr);
            
            if ($processedData['show_balance']) {
                $rep->AmountCol(6, 7, $dim['ytd_balance'], 0);
            }
            
            $rep->NewLine(1, 2);
            
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
}
