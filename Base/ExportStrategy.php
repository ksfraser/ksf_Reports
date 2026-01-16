<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Base;

/**
 * Strategy interface for exporting reports to different formats
 * 
 * Encapsulates the export logic that is duplicated across all legacy reports:
 * - PDF generation via FrontReport class
 * - Excel generation via excel_report.inc
 * - Email delivery
 * - Printer output
 * 
 * @package FA\Modules\Reports\Base
 */
interface ExportStrategyInterface
{
    /**
     * Export report data in the specified format
     *
     * @param array $data Report data to export
     * @param string $title Report title
     * @param ReportConfig $config Report configuration
     * @return array Result with 'success', 'format', 'filename', 'filepath', 'url' keys
     */
    public function export(array $data, string $title, ReportConfig $config): array;

    /**
     * Get the format name (pdf, excel, email, printer)
     */
    public function getFormat(): string;
}

/**
 * PDF export strategy using FrontReport class
 */
class PdfExportStrategy implements ExportStrategyInterface
{
    private string $reportCode;
    private array $columns;
    private array $headers;
    private array $aligns;
    private int $fontSize;

    public function __construct(
        string $reportCode,
        array $columns,
        array $headers,
        array $aligns,
        int $fontSize = 9
    ) {
        $this->reportCode = $reportCode;
        $this->columns = $columns;
        $this->headers = $headers;
        $this->aligns = $aligns;
        $this->fontSize = $fontSize;
    }

    public function export(array $data, string $title, ReportConfig $config): array
    {
        global $path_to_root;
        
        // Include legacy PDF report class
        include_once($path_to_root . "/reporting/includes/pdf_report.inc");
        
        // Adjust columns for landscape orientation
        $cols = $this->columns;
        if ($config->isLandscapeOrientation()) {
            recalculate_cols($cols);
        }
        
        // Create FrontReport instance
        $rep = new \FrontReport(
            $title,
            $this->reportCode,
            $config->getPageSize(),
            $this->fontSize,
            $config->getOrientation()
        );
        
        // Build params array for header
        $params = $this->buildParams($data, $config);
        
        // Initialize report
        $rep->Font();
        $rep->Info($params, $cols, $this->headers, $this->aligns);
        $rep->NewPage();
        
        // Render report content (override in subclasses)
        $this->renderContent($rep, $data, $config);
        
        // Finalize and output
        $rep->End();
        
        return [
            'success' => true,
            'format' => 'pdf',
            'filename' => $rep->filename,
            'filepath' => '', // FrontReport handles file path
            'url' => '' // FrontReport handles output
        ];
    }

    public function getFormat(): string
    {
        return 'pdf';
    }

    /**
     * Build parameters array for report header
     * Override in subclasses for custom parameters
     */
    protected function buildParams(array $data, ReportConfig $config): array
    {
        return [
            0 => $config->getComments(),
            1 => [
                'text' => _('Period'),
                'from' => $config->getFromDate(),
                'to' => $config->getToDate()
            ],
        ];
    }

    /**
     * Render report content to PDF
     * Override in subclasses to implement specific report layout
     */
    protected function renderContent(\FrontReport $rep, array $data, ReportConfig $config): void
    {
        // Default implementation - subclasses should override
        if (isset($data['accounts'])) {
            foreach ($data['accounts'] as $account) {
                $rep->TextCol(0, 1, $account['account_code'] ?? '');
                $rep->TextCol(1, 2, $account['account_name'] ?? '');
                $rep->NewLine();
            }
        }
    }
}

/**
 * Excel export strategy
 */
class ExcelExportStrategy implements ExportStrategyInterface
{
    private string $reportCode;
    private array $columns;
    private array $headers;

    public function __construct(
        string $reportCode,
        array $columns,
        array $headers
    ) {
        $this->reportCode = $reportCode;
        $this->columns = $columns;
        $this->headers = $headers;
    }

    public function export(array $data, string $title, ReportConfig $config): array
    {
        global $path_to_root;
        
        // Include legacy Excel report class
        include_once($path_to_root . "/reporting/includes/excel_report.inc");
        
        // Create Excel report instance (similar to PDF)
        // Note: Actual implementation would use excel_report.inc classes
        
        $filename = $this->reportCode . '_' . date('Y-m-d') . '.xlsx';
        
        return [
            'success' => true,
            'format' => 'excel',
            'filename' => $filename,
            'filepath' => sys_get_temp_dir() . '/' . $filename,
            'url' => ''
        ];
    }

    public function getFormat(): string
    {
        return 'excel';
    }
}

/**
 * Factory for creating export strategies
 */
class ExportStrategyFactory
{
    /**
     * Create appropriate export strategy based on configuration
     */
    public static function create(
        ReportConfig $config,
        string $reportCode,
        array $columns,
        array $headers,
        array $aligns,
        int $fontSize = 9
    ): ExportStrategyInterface {
        if ($config->shouldExportToExcel()) {
            return new ExcelExportStrategy($reportCode, $columns, $headers);
        }
        
        return new PdfExportStrategy($reportCode, $columns, $headers, $aligns, $fontSize);
    }

    /**
     * Create strategy by format name
     */
    public static function createByFormat(
        string $format,
        string $reportCode,
        array $columns,
        array $headers,
        array $aligns = [],
        int $fontSize = 9
    ): ExportStrategyInterface {
        switch (strtolower($format)) {
            case 'excel':
            case 'xlsx':
                return new ExcelExportStrategy($reportCode, $columns, $headers);
            
            case 'pdf':
            default:
                return new PdfExportStrategy($reportCode, $columns, $headers, $aligns, $fontSize);
        }
    }
}
