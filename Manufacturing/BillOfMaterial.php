<?php
/**
 * Bill of Material Report Service
 * 
 * Generates BOM listing showing all components for manufactured items:
 * - Component details with descriptions
 * - Location and work center assignments
 * - Required quantities per unit
 * 
 * Report: rep401
 * Category: Manufacturing Reports
 */

declare(strict_types=1);

namespace FA\Reports\Manufacturing;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class BillOfMaterial extends AbstractReportService
{
    private const REPORT_ID = 401;
    private const REPORT_TITLE = 'Bill of Material Listing';
    
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
        return [0, 50, 305, 375, 445, 515];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('Component'),
            _('Description'),
            _('Loc'),
            _('Wrk Ctr'),
            _('Quantity')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'left', 'right'];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        return [
            0 => $config->getParam('comments'),
            1 => [
                'text' => _('Component'),
                'from' => $config->getParam('from_part'),
                'to' => $config->getParam('to_part')
            ]
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $fromPart = $config->getParam('from_part');
        $toPart = $config->getParam('to_part');
        
        $bomData = $this->getBOMTransactions($fromPart, $toPart);
        
        $data = [
            'bom_data' => $bomData
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
        
        // Group components by parent
        $grouped = [];
        foreach ($data['bom_data'] as $row) {
            $parent = $row['parent'];
            if (!isset($grouped[$parent])) {
                $grouped[$parent] = [
                    'parent' => $parent,
                    'parent_desc' => $this->getItemDescription($parent),
                    'components' => []
                ];
            }
            
            $grouped[$parent]['components'][] = [
                'component' => $row['component'],
                'description' => $row['CompDescription'],
                'location' => $this->getLocationName($row['loc_code']),
                'workcentre' => $this->getWorkCentreName($row['workcentre_added']),
                'quantity' => (float)$row['quantity']
            ];
        }
        
        $processed = [
            'bom_groups' => $grouped
        ];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get BOM transactions
     */
    private function getBOMTransactions(string $fromPart, string $toPart): array
    {
        $sql = "SELECT bom.parent,
                    bom.component,
                    item.description as CompDescription,
                    bom.quantity,
                    bom.loc_code,
                    bom.workcentre_added
                FROM "
                    .TB_PREF."stock_master item,"
                    .TB_PREF."bom bom
                WHERE item.stock_id = bom.component
                  AND bom.parent >= ".$this->db->escape($fromPart)."
                  AND bom.parent <= ".$this->db->escape($toPart)."
                ORDER BY
                    bom.parent,
                    bom.component";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Get item description
     */
    private function getItemDescription(string $stockId): string
    {
        $item = get_item($stockId);
        return $item ? $item['description'] : '';
    }
    
    /**
     * Get location name
     */
    private function getLocationName(string $locCode): string
    {
        return get_location_name($locCode);
    }
    
    /**
     * Get work centre name
     */
    private function getWorkCentreName(int $workcentreId): string
    {
        if ($workcentreId == 0) {
            return '';
        }
        $wc = get_work_centre($workcentreId);
        return $wc ? $wc['name'] : '';
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        foreach ($processedData['bom_groups'] as $group) {
            // Parent header
            $rep->TextCol(0, 1, $group['parent']);
            $rep->TextCol(1, 2, $group['parent_desc']);
            $rep->NewLine();
            $rep->NewLine();
            
            // Components
            foreach ($group['components'] as $comp) {
                $dec = get_qty_dec($comp['component']);
                $rep->TextCol(0, 1, $comp['component']);
                $rep->TextCol(1, 2, $comp['description']);
                $rep->TextCol(2, 3, $comp['location']);
                $rep->TextCol(3, 4, $comp['workcentre']);
                $rep->AmountCol(4, 5, $comp['quantity'], $dec);
                $rep->NewLine();
            }
            
            $rep->Line($rep->row - 2);
            $rep->NewLine(2, 3);
            
            if ($rep->row < $rep->bottomMargin + $rep->lineHeight) {
                $rep->NewPage();
            }
        }
        
        $rep->Line($rep->row - 4);
        $rep->NewLine();
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
