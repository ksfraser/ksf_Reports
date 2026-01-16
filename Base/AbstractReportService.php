<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Base;

use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Abstract base class for all report services
 * 
 * Provides common functionality across all FA reports:
 * - Dependency injection (database, events, logging)
 * - Event dispatching for lifecycle hooks
 * - Common error handling and logging
 * - Export strategy pattern
 * - Parameter validation
 * 
 * Subclasses implement report-specific logic in:
 * - fetchData(): Query database for report data
 * - processData(): Transform/calculate report values
 * - formatData(): Structure data for output
 * 
 * @package FA\Modules\Reports\Base
 */
abstract class AbstractReportService
{
    protected DBALInterface $dbal;
    protected EventDispatcher $dispatcher;
    protected LoggerInterface $logger;
    protected string $reportName;
    protected string $reportCode;

    public function __construct(
        DBALInterface $dbal,
        EventDispatcher $dispatcher,
        LoggerInterface $logger,
        string $reportName,
        string $reportCode
    ) {
        $this->dbal = $dbal;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
        $this->reportName = $reportName;
        $this->reportCode = $reportCode;
    }

    /**
     * Generate report with given configuration
     * Template method pattern - calls hooks in sequence
     */
    public function generate(ReportConfig $config): array
    {
        try {
            // Dispatch before event
            $this->dispatchEvent("{$this->reportCode}.before_generate", [
                'config' => $config
            ]);

            // Validate configuration
            $this->validateConfig($config);

            // Fetch raw data from database
            $rawData = $this->fetchData($config);

            // Process and calculate
            $processedData = $this->processData($rawData, $config);

            // Format for output
            $result = $this->formatData($processedData, $config);

            // Dispatch after event
            $this->dispatchEvent("{$this->reportCode}.after_generate", [
                'config' => $config,
                'result' => $result
            ]);

            $this->logger->info("Report generated successfully", [
                'report' => $this->reportName,
                'code' => $this->reportCode,
                'from' => $config->getFromDate(),
                'to' => $config->getToDate()
            ]);

            return $result;

        } catch (\Exception $e) {
            // Dispatch error event
            $this->dispatchEvent("{$this->reportCode}.error", [
                'config' => $config,
                'error' => $e
            ]);

            $this->logger->error("Report generation failed", [
                'report' => $this->reportName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Export report data using specified strategy
     */
    public function export(array $data, string $title, ReportConfig $config): array
    {
        try {
            // Create export strategy based on config
            $strategy = ExportStrategyFactory::create(
                $config,
                $this->reportCode,
                $this->getColumns($config),
                $this->getHeaders($config),
                $this->getAligns($config),
                $this->getFontSize()
            );

            // Dispatch before export event
            $this->dispatchEvent("{$this->reportCode}.before_export", [
                'data' => $data,
                'format' => $strategy->getFormat(),
                'config' => $config
            ]);

            // Perform export
            $result = $strategy->export($data, $title, $config);

            // Dispatch after export event
            $this->dispatchEvent("{$this->reportCode}.after_export", [
                'result' => $result,
                'format' => $strategy->getFormat()
            ]);

            $this->logger->info("Report exported successfully", [
                'report' => $this->reportName,
                'format' => $strategy->getFormat()
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logger->error("Report export failed", [
                'report' => $this->reportName,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Validate configuration before generating report
     * Override in subclasses for custom validation
     */
    protected function validateConfig(ReportConfig $config): void
    {
        if (empty($config->getFromDate())) {
            throw new \InvalidArgumentException('From date is required');
        }
        if (empty($config->getToDate())) {
            throw new \InvalidArgumentException('To date is required');
        }
    }

    /**
     * Fetch raw data from database
     * Must be implemented by subclasses
     */
    abstract protected function fetchData(ReportConfig $config): array;

    /**
     * Process and calculate report values
     * Override in subclasses for custom processing
     */
    protected function processData(array $rawData, ReportConfig $config): array
    {
        return $rawData;
    }

    /**
     * Format data for output
     * Override in subclasses for custom formatting
     */
    protected function formatData(array $processedData, ReportConfig $config): array
    {
        return $processedData;
    }

    /**
     * Get column definitions for PDF/Excel output
     * Must be implemented by subclasses
     */
    abstract protected function getColumns(ReportConfig $config): array;

    /**
     * Get header labels for PDF/Excel output
     * Must be implemented by subclasses
     */
    abstract protected function getHeaders(ReportConfig $config): array;

    /**
     * Get column alignments for PDF output
     * Override in subclasses for custom alignments
     */
    protected function getAligns(ReportConfig $config): array
    {
        // Default: left-align text columns, right-align numeric columns
        return array_fill(0, count($this->getHeaders($config)), 'left');
    }

    /**
     * Get font size for PDF output
     * Override in subclasses for custom font size
     */
    protected function getFontSize(): int
    {
        return 9;
    }

    /**
     * Dispatch event with data
     */
    protected function dispatchEvent(string $eventName, array $data = []): void
    {
        try {
            $this->dispatcher->dispatch("report.$eventName", $data);
        } catch (\Exception $e) {
            // Log but don't throw - events shouldn't break report generation
            $this->logger->warning("Event dispatch failed", [
                'event' => $eventName,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Apply dimension filters to SQL WHERE clause
     * Helper for common dimension filtering logic
     */
    protected function buildDimensionFilter(ReportConfig $config, string $dim1Column = 'dimension_id', string $dim2Column = 'dimension2_id'): string
    {
        $filters = [];
        
        if ($config->hasDimension1()) {
            $filters[] = "$dim1Column = " . $config->getDimension1();
        }
        
        if ($config->hasDimension2()) {
            $filters[] = "$dim2Column = " . $config->getDimension2();
        }
        
        return empty($filters) ? '' : ' AND ' . implode(' AND ', $filters);
    }

    /**
     * Get report name
     */
    public function getReportName(): string
    {
        return $this->reportName;
    }

    /**
     * Get report code
     */
    public function getReportCode(): string
    {
        return $this->reportCode;
    }
}
