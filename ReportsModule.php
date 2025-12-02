<?php

namespace FA\Modules\Reports;

use FA\Core\DBALInterface;
use FA\Core\EventDispatcherInterface;
use FA\Core\LoggerInterface;
use FA\Modules\Reports\Sales\OrderStatusReport;
use FA\Modules\Reports\Inventory\StockStatusReport;
use FA\Modules\Reports\Financial\TrialBalanceReport;

class ReportsModule
{
    private ReportService $reportService;
    private ReportExporter $exporter;
    private DBALInterface $db;

    public function __construct(
        DBALInterface $db,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->db = $db;
        $this->reportService = new ReportService($db, $eventDispatcher, $logger);
        $this->exporter = new ReportExporter($eventDispatcher, $logger, $config);
        
        $this->registerReports();
    }

    /**
     * Register all report generators
     */
    private function registerReports(): void
    {
        // Sales Reports
        $this->reportService->registerReport(
            'SALES_ORDER_STATUS',
            function ($params, $page, $perPage) {
                $report = new OrderStatusReport($this->db);
                return $report->generate($params, $page, $perPage);
            }
        );

        $this->reportService->registerReport(
            'SALES_ANALYSIS',
            function ($params, $page, $perPage) {
                // TODO: Implement SalesAnalysisReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'AGED_DEBTORS',
            function ($params, $page, $perPage) {
                // TODO: Implement AgedDebtorsReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'CUSTOMER_TRANSACTIONS',
            function ($params, $page, $perPage) {
                // TODO: Implement CustomerTransactionsReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        // Purchasing Reports
        $this->reportService->registerReport(
            'PURCHASE_ORDER_STATUS',
            function ($params, $page, $perPage) {
                // TODO: Implement PurchaseOrderStatusReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'SUPPLIER_TRANSACTIONS',
            function ($params, $page, $perPage) {
                // TODO: Implement SupplierTransactionsReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'AGED_SUPPLIERS',
            function ($params, $page, $perPage) {
                // TODO: Implement AgedSuppliersReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'SUPPLIER_PERFORMANCE',
            function ($params, $page, $perPage) {
                // TODO: Implement SupplierPerformanceReport (integrate with SupplierPerformance module)
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        // Inventory Reports
        $this->reportService->registerReport(
            'STOCK_STATUS',
            function ($params, $page, $perPage) {
                $report = new StockStatusReport($this->db);
                return $report->generate($params, $page, $perPage);
            }
        );

        $this->reportService->registerReport(
            'STOCK_VALUATION',
            function ($params, $page, $perPage) {
                // TODO: Implement StockValuationReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'STOCK_MOVEMENTS',
            function ($params, $page, $perPage) {
                // TODO: Implement StockMovementsReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'REORDER_LEVEL',
            function ($params, $page, $perPage) {
                // TODO: Implement ReorderLevelReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'BOM_LISTING',
            function ($params, $page, $perPage) {
                // TODO: Implement BOMListingReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        // Financial Reports
        $this->reportService->registerReport(
            'TRIAL_BALANCE',
            function ($params, $page, $perPage) {
                $report = new TrialBalanceReport($this->db);
                return $report->generate($params, $page, $perPage);
            }
        );

        $this->reportService->registerReport(
            'BALANCE_SHEET',
            function ($params, $page, $perPage) {
                // TODO: Implement BalanceSheetReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'PROFIT_LOSS',
            function ($params, $page, $perPage) {
                // TODO: Implement ProfitLossReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'CASH_FLOW',
            function ($params, $page, $perPage) {
                // TODO: Implement CashFlowReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'GL_INQUIRY',
            function ($params, $page, $perPage) {
                // TODO: Implement GLInquiryReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        // Manufacturing Reports
        $this->reportService->registerReport(
            'WORK_ORDER_STATUS',
            function ($params, $page, $perPage) {
                // TODO: Implement WorkOrderStatusReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'PRODUCTION_COSTING',
            function ($params, $page, $perPage) {
                // TODO: Implement ProductionCostingReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'MATERIAL_REQUIREMENTS',
            function ($params, $page, $perPage) {
                // TODO: Implement MaterialRequirementsReport (integrate with MRP module)
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );

        $this->reportService->registerReport(
            'CAPACITY_PLANNING',
            function ($params, $page, $perPage) {
                // TODO: Implement CapacityPlanningReport
                return [
                    'data' => [],
                    'columns' => [],
                    'total_rows' => 0
                ];
            }
        );
    }

    /**
     * Get report service
     */
    public function getReportService(): ReportService
    {
        return $this->reportService;
    }

    /**
     * Get report exporter
     */
    public function getExporter(): ReportExporter
    {
        return $this->exporter;
    }

    /**
     * Generate and export a report
     */
    public function generateAndExport(
        string $reportCode,
        array $parameters,
        string $format,
        ?int $userId = null
    ): string {
        // Generate report
        $report = $this->reportService->generateReport($reportCode, $parameters, $userId);
        
        // Export to format
        return $this->exporter->export($report, $format);
    }

    /**
     * Generate report and send as download
     */
    public function downloadReport(
        string $reportCode,
        array $parameters,
        string $format,
        ?int $userId = null
    ): void {
        $filePath = $this->generateAndExport($reportCode, $parameters, $format, $userId);
        $this->exporter->download($filePath);
    }
}
