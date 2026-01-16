<?php
/**
 * Supplier Details Listing Service
 * 
 * Generates comprehensive supplier directory showing:
 * - Contact information and addresses
 * - Turnover filtering
 * - CRM contacts integration
 * 
 * Report: rep205
 * Category: Supplier/Purchasing Reports
 */

declare(strict_types=1);

namespace FA\Reports\Supplier;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class SupplierDetailsListing extends AbstractReportService
{
    private const REPORT_ID = 205;
    private const REPORT_TITLE = 'Supplier Details Listing';
    
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
    
    protected function defineColumns(): array
    {
        return [0, 150, 300, 425, 550];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('Mailing Address:'),
            _('Turnover'),
            _('Contact Information'),
            _('Physical Address')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return ['left', 'left', 'left', 'left'];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $dec = 0;
        $more = $config->getParam('more', '');
        $less = $config->getParam('less', '');
        
        $moreStr = ($more !== '') ? _('Greater than ').\FormatService::numberFormat2((float)$more, $dec) : '';
        $lessStr = ($less !== '') ? _('Less than ').\FormatService::numberFormat2((float)$less, $dec) : '';
        
        return [
            0 => $config->getParam('comments'),
            1 => ['text' => _('Activity Since'), 'from' => $config->getParam('from_date'), 'to' => ''],
            2 => ['text' => _('Activity'), 'from' => $moreStr, 'to' => $lessStr]
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        // Get all active suppliers
        $sql = "SELECT supplier_id, supp_name, address, supp_address, supp_ref,
                    contact, curr_code, dimension_id, dimension2_id, notes, gst_no
                FROM ".TB_PREF."suppliers
                WHERE inactive = 0
                ORDER BY supp_name";
        
        $suppliers = $this->db->fetchAll($sql);
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $suppliers
        ]);
        
        return $suppliers;
    }
    
    protected function processData(ReportConfig $config, array $data): array
    {
        $this->dispatchEvent('before_process_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        $fromDate = $config->getParam('from_date');
        $more = (float)$config->getParam('more', 0);
        $less = (float)$config->getParam('less', 0);
        
        $suppliers = [];
        
        foreach ($data as $supplier) {
            // Get turnover
            $turnover = $this->getTurnover($supplier['supplier_id'], $fromDate);
            
            // Apply filters
            if ($more != 0 && $turnover < $more) {
                continue;
            }
            if ($less != 0 && $turnover > $less) {
                continue;
            }
            
            // Get contacts
            $contacts = $this->getContacts($supplier['supplier_id']);
            
            $suppliers[] = [
                'supplier_id' => $supplier['supplier_id'],
                'supp_name' => $supplier['supp_name'],
                'address' => $supplier['address'],
                'supp_address' => $supplier['supp_address'],
                'supp_ref' => $supplier['supp_ref'],
                'contact' => $supplier['contact'],
                'curr_code' => $supplier['curr_code'],
                'gst_no' => $supplier['gst_no'],
                'notes' => $supplier['notes'],
                'turnover' => $turnover,
                'contacts' => $contacts
            ];
        }
        
        $processed = ['suppliers' => $suppliers];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get turnover for a supplier since a date
     */
    private function getTurnover(int $supplierId, string $fromDate): float
    {
        $date = \DateService::date2sqlStatic($fromDate);
        
        $sql = "SELECT SUM((ov_amount + ov_discount) * rate) AS Turnover
                FROM ".TB_PREF."supp_trans
                WHERE supplier_id = ".$this->db->escape($supplierId)."
                  AND (type = ".ST_SUPPINVOICE." OR type = ".ST_SUPPCREDIT.")
                  AND tran_date >= ".$this->db->escape($date);
        
        $result = $this->db->fetchOne($sql);
        return $result ? (float)$result['Turnover'] : 0.0;
    }
    
    /**
     * Get CRM contacts for a supplier
     */
    private function getContacts(int $supplierId): array
    {
        $sql = "SELECT * FROM ".TB_PREF."crm_contacts
                WHERE type = 'supplier'
                  AND action = 'supplier_id'
                  AND entity_id = ".$this->db->escape($supplierId)."
                ORDER BY name";
        
        return $this->db->fetchAll($sql);
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        $dec = 0;
        
        foreach ($processedData['suppliers'] as $supplier) {
            $rep->fontSize += 3;
            $rep->TextCol(0, 2, $supplier['supp_name']);
            $rep->fontSize -= 3;
            $rep->NewLine(2);
            
            // Mailing address
            $rep->TextCol(0, 1, $supplier['address']);
            
            // Turnover
            $rep->AmountCol(1, 2, $supplier['turnover'], $dec);
            
            // Contact information
            $contactInfo = '';
            if ($supplier['contact']) {
                $contactInfo .= $supplier['contact'];
            }
            if ($supplier['gst_no']) {
                if ($contactInfo) $contactInfo .= "\n";
                $contactInfo .= _('Tax Id').': '.$supplier['gst_no'];
            }
            if ($supplier['curr_code']) {
                if ($contactInfo) $contactInfo .= "\n";
                $contactInfo .= _('Currency').': '.$supplier['curr_code'];
            }
            $rep->TextCol(2, 3, $contactInfo);
            
            // Physical address
            $rep->TextCol(3, 4, $supplier['supp_address']);
            $rep->NewLine();
            
            // Notes
            if ($supplier['notes']) {
                $rep->TextCol(0, 4, _('Notes').': '.$supplier['notes']);
                $rep->NewLine();
            }
            
            // CRM Contacts
            if (!empty($supplier['contacts'])) {
                $rep->NewLine();
                $rep->TextCol(0, 1, _('Contacts').':');
                $rep->NewLine();
                
                foreach ($supplier['contacts'] as $contact) {
                    $contactLine = $contact['name'];
                    if (!empty($contact['phone'])) {
                        $contactLine .= ', '._('Phone').': '.$contact['phone'];
                    }
                    if (!empty($contact['email'])) {
                        $contactLine .= ', '._('Email').': '.$contact['email'];
                    }
                    $rep->TextCol(0, 4, $contactLine);
                    $rep->NewLine();
                }
            }
            
            $rep->NewLine(2);
            
            if ($rep->row < $rep->bottomMargin + (6 * $rep->lineHeight)) {
                $rep->NewPage();
            }
        }
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
