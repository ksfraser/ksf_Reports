<?php

namespace FA\Modules\Reports;

use FA\Core\EventDispatcherInterface;
use FA\Core\LoggerInterface;
use FA\Modules\Reports\Entities\Report;
use FA\Modules\Reports\Events\ReportExportedEvent;
use FA\Modules\Reports\ReportsException\{ReportExportException, ReportValidationException};
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportExporter
{
    private EventDispatcherInterface $eventDispatcher;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
        $this->config = array_merge([
            'pdf_orientation' => 'portrait',
            'pdf_paper_size' => 'A4',
            'excel_creator' => 'FrontAccounting',
            'csv_delimiter' => ',',
            'csv_enclosure' => '"',
            'temp_directory' => sys_get_temp_dir()
        ], $config);
    }

    /**
     * Export report to specified format
     */
    public function export(Report $report, string $format, array $options = []): string
    {
        $this->logger->info('Exporting report', [
            'report_id' => $report->id,
            'report_code' => $report->code,
            'format' => $format
        ]);

        try {
            $filePath = match (strtolower($format)) {
                'pdf' => $this->exportToPdf($report, $options),
                'excel', 'xlsx' => $this->exportToExcel($report, $options),
                'csv' => $this->exportToCsv($report, $options),
                default => throw new ReportValidationException(
                    "Unsupported export format: {$format}",
                    ['format' => $format]
                )
            };

            // Dispatch event
            $event = new ReportExportedEvent($report, $format, $filePath);
            $this->eventDispatcher->dispatch($event);

            $this->logger->info('Report exported successfully', [
                'report_id' => $report->id,
                'format' => $format,
                'file_path' => $filePath
            ]);

            return $filePath;

        } catch (\Exception $e) {
            $this->logger->error('Report export failed', [
                'report_id' => $report->id,
                'format' => $format,
                'error' => $e->getMessage()
            ]);

            throw new ReportExportException(
                "Failed to export report: {$e->getMessage()}",
                $report->code,
                $format,
                $e
            );
        }
    }

    /**
     * Export report to PDF
     */
    private function exportToPdf(Report $report, array $options): string
    {
        $orientation = $options['orientation'] ?? $this->config['pdf_orientation'];
        $paperSize = $options['paper_size'] ?? $this->config['pdf_paper_size'];

        // Configure DOMPDF
        $dompdfOptions = new Options();
        $dompdfOptions->set('isHtml5ParserEnabled', true);
        $dompdfOptions->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($dompdfOptions);

        // Generate HTML for the report
        $html = $this->generateReportHtml($report, $options);

        $dompdf->loadHtml($html);
        $dompdf->setPaper($paperSize, $orientation);
        $dompdf->render();

        // Generate filename
        $filename = $this->generateFilename($report, 'pdf');
        $filePath = $this->config['temp_directory'] . DIRECTORY_SEPARATOR . $filename;

        // Save PDF
        file_put_contents($filePath, $dompdf->output());

        return $filePath;
    }

    /**
     * Export report to Excel
     */
    private function exportToExcel(Report $report, array $options): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set metadata
        $spreadsheet->getProperties()
            ->setCreator($this->config['excel_creator'])
            ->setTitle($report->name)
            ->setDescription("Generated: " . $report->generatedAt->format('Y-m-d H:i:s'));

        // Write title
        $row = 1;
        $sheet->setCellValue("A{$row}", $report->name);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
        $row += 2;

        // Write parameters
        if (!empty($report->parameters)) {
            $sheet->setCellValue("A{$row}", "Parameters:");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;

            foreach ($report->parameters as $key => $value) {
                $sheet->setCellValue("A{$row}", $key);
                $sheet->setCellValue("B{$row}", is_array($value) ? json_encode($value) : $value);
                $row++;
            }
            $row++;
        }

        // Write column headers
        $col = 'A';
        foreach ($report->columns as $column) {
            $columnLabel = is_array($column) ? ($column['label'] ?? $column['field']) : $column;
            $sheet->setCellValue("{$col}{$row}", $columnLabel);
            $sheet->getStyle("{$col}{$row}")->getFont()->setBold(true);
            $sheet->getStyle("{$col}{$row}")->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('CCCCCC');
            $col++;
        }
        $row++;

        // Write data rows
        foreach ($report->data as $dataRow) {
            $col = 'A';
            foreach ($report->columns as $column) {
                $field = is_array($column) ? $column['field'] : $column;
                $value = $dataRow[$field] ?? '';
                
                // Format value based on type
                if (is_array($column) && isset($column['type'])) {
                    $value = $this->formatCellValue($value, $column['type']);
                }
                
                $sheet->setCellValue("{$col}{$row}", $value);
                $col++;
            }
            $row++;
        }

        // Write summary if available
        if (!empty($report->summary)) {
            $row += 2;
            $sheet->setCellValue("A{$row}", "Summary:");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;

            foreach ($report->summary as $key => $value) {
                $sheet->setCellValue("A{$row}", $key);
                $sheet->setCellValue("B{$row}", is_array($value) ? json_encode($value) : $value);
                $row++;
            }
        }

        // Auto-size columns
        foreach (range('A', $col) as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }

        // Generate filename and save
        $filename = $this->generateFilename($report, 'xlsx');
        $filePath = $this->config['temp_directory'] . DIRECTORY_SEPARATOR . $filename;

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return $filePath;
    }

    /**
     * Export report to CSV
     */
    private function exportToCsv(Report $report, array $options): string
    {
        $delimiter = $options['delimiter'] ?? $this->config['csv_delimiter'];
        $enclosure = $options['enclosure'] ?? $this->config['csv_enclosure'];

        $filename = $this->generateFilename($report, 'csv');
        $filePath = $this->config['temp_directory'] . DIRECTORY_SEPARATOR . $filename;

        $handle = fopen($filePath, 'w');

        if ($handle === false) {
            throw new ReportExportException(
                "Failed to create CSV file",
                $report->code,
                'csv'
            );
        }

        try {
            // Write column headers
            $headers = array_map(function ($column) {
                return is_array($column) ? ($column['label'] ?? $column['field']) : $column;
            }, $report->columns);

            fputcsv($handle, $headers, $delimiter, $enclosure);

            // Write data rows
            foreach ($report->data as $dataRow) {
                $row = [];
                foreach ($report->columns as $column) {
                    $field = is_array($column) ? $column['field'] : $column;
                    $value = $dataRow[$field] ?? '';
                    
                    // Format value
                    if (is_array($value)) {
                        $value = json_encode($value);
                    }
                    
                    $row[] = $value;
                }
                fputcsv($handle, $row, $delimiter, $enclosure);
            }

        } finally {
            fclose($handle);
        }

        return $filePath;
    }

    /**
     * Generate HTML for PDF report
     */
    private function generateReportHtml(Report $report, array $options): string
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
        }
        h1 {
            font-size: 16pt;
            margin-bottom: 10px;
        }
        .parameters {
            margin-bottom: 15px;
            font-size: 9pt;
        }
        .parameters strong {
            font-weight: bold;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        th {
            background-color: #cccccc;
            font-weight: bold;
            padding: 5px;
            border: 1px solid #000;
            text-align: left;
        }
        td {
            padding: 4px;
            border: 1px solid #666;
        }
        .summary {
            margin-top: 15px;
            font-size: 9pt;
        }
        .summary strong {
            font-weight: bold;
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 8pt;
            color: #666;
        }
    </style>
</head>
<body>';

        // Title
        $html .= '<h1>' . htmlspecialchars($report->name) . '</h1>';

        // Parameters
        if (!empty($report->parameters)) {
            $html .= '<div class="parameters"><strong>Parameters:</strong><br>';
            foreach ($report->parameters as $key => $value) {
                $displayValue = is_array($value) ? json_encode($value) : $value;
                $html .= htmlspecialchars($key) . ': ' . htmlspecialchars($displayValue) . '<br>';
            }
            $html .= '</div>';
        }

        // Data table
        $html .= '<table>';
        
        // Headers
        $html .= '<thead><tr>';
        foreach ($report->columns as $column) {
            $label = is_array($column) ? ($column['label'] ?? $column['field']) : $column;
            $html .= '<th>' . htmlspecialchars($label) . '</th>';
        }
        $html .= '</tr></thead>';

        // Data rows
        $html .= '<tbody>';
        foreach ($report->data as $dataRow) {
            $html .= '<tr>';
            foreach ($report->columns as $column) {
                $field = is_array($column) ? $column['field'] : $column;
                $value = $dataRow[$field] ?? '';
                
                // Format value
                if (is_array($column) && isset($column['type'])) {
                    $value = $this->formatCellValue($value, $column['type']);
                }
                
                if (is_array($value)) {
                    $value = json_encode($value);
                }
                
                $html .= '<td>' . htmlspecialchars($value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // Summary
        if (!empty($report->summary)) {
            $html .= '<div class="summary"><strong>Summary:</strong><br>';
            foreach ($report->summary as $key => $value) {
                $displayValue = is_array($value) ? json_encode($value) : $value;
                $html .= htmlspecialchars($key) . ': ' . htmlspecialchars($displayValue) . '<br>';
            }
            $html .= '</div>';
        }

        // Footer
        $html .= '<div class="footer">Generated: ' . 
                 $report->generatedAt->format('Y-m-d H:i:s') . 
                 ' | Page <script type="text/php">
                    if (isset($pdf)) {
                        echo $PAGE_NUM . " of " . $PAGE_COUNT;
                    }
                 </script></div>';

        $html .= '</body></html>';

        return $html;
    }

    /**
     * Format cell value based on type
     */
    private function formatCellValue($value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($type) {
            'currency' => number_format((float)$value, 2),
            'number' => number_format((float)$value, 0),
            'decimal' => number_format((float)$value, 2),
            'percentage' => number_format((float)$value, 2) . '%',
            'date' => date('Y-m-d', is_numeric($value) ? $value : strtotime($value)),
            'datetime' => date('Y-m-d H:i:s', is_numeric($value) ? $value : strtotime($value)),
            default => (string)$value
        };
    }

    /**
     * Generate filename for exported report
     */
    private function generateFilename(Report $report, string $extension): string
    {
        $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $report->code);
        $timestamp = $report->generatedAt->format('Ymd_His');
        return "{$baseName}_{$timestamp}.{$extension}";
    }

    /**
     * Send exported report as download response
     */
    public function download(string $filePath, ?string $filename = null): void
    {
        if (!file_exists($filePath)) {
            throw new ReportExportException(
                "Export file not found: {$filePath}",
                '',
                ''
            );
        }

        $filename = $filename ?? basename($filePath);
        $mimeType = $this->getMimeType($filePath);

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($filePath);

        // Clean up temp file
        @unlink($filePath);
    }

    /**
     * Get MIME type for file
     */
    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            default => 'application/octet-stream'
        };
    }
}
