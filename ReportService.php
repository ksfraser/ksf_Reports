<?php

namespace FA\Modules\Reports;

use FA\Core\DBALInterface;
use FA\Core\EventDispatcherInterface;
use FA\Core\LoggerInterface;
use FA\Modules\Reports\Entities\{Report, ReportDefinition, ScheduledReport};
use FA\Modules\Reports\Events\{
    ReportGeneratedEvent,
    ReportExportedEvent,
    ReportScheduledEvent,
    ReportErrorEvent,
    ScheduledReportExecutedEvent
};
use FA\Modules\Reports\ReportsException\{
    ReportNotFoundException,
    ReportValidationException,
    ReportGenerationException,
    ReportTimeoutException,
    ReportPermissionException
};

class ReportService
{
    private DBALInterface $db;
    private EventDispatcherInterface $eventDispatcher;
    private LoggerInterface $logger;
    private array $reportRegistry = [];
    private int $defaultTimeout = 300; // 5 minutes
    private int $maxRowsPerPage = 1000;

    public function __construct(
        DBALInterface $db,
        EventDispatcherInterface $eventDispatcher,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->eventDispatcher = $eventDispatcher;
        $this->logger = $logger;
    }

    /**
     * Register a report type
     */
    public function registerReport(string $code, callable $generator, array $config = []): void
    {
        $this->reportRegistry[$code] = [
            'generator' => $generator,
            'config' => $config
        ];

        $this->logger->info('Report registered', [
            'report_code' => $code,
            'config' => $config
        ]);
    }

    /**
     * Get report definition by code
     */
    public function getReportDefinition(string $code): ReportDefinition
    {
        $sql = "SELECT * FROM report_definitions WHERE code = ?";
        $result = $this->db->query($sql, [$code]);

        if (empty($result)) {
            throw new ReportNotFoundException("Report definition not found: {$code}", $code);
        }

        $data = $result[0];
        return new ReportDefinition(
            (int)$data['id'],
            $data['code'],
            $data['name'],
            $data['description'],
            $data['category'],
            json_decode($data['default_parameters'], true),
            json_decode($data['required_permissions'], true),
            (int)$data['timeout_seconds'],
            (bool)$data['allow_export'],
            json_decode($data['export_formats'], true),
            (bool)$data['allow_scheduling'],
            new \DateTime($data['created_at']),
            $data['updated_at'] ? new \DateTime($data['updated_at']) : null
        );
    }

    /**
     * Generate a report
     */
    public function generateReport(
        string $code,
        array $parameters = [],
        ?int $userId = null,
        int $page = 1,
        int $perPage = 100
    ): Report {
        $startTime = microtime(true);

        try {
            // Get report definition
            $definition = $this->getReportDefinition($code);

            // Check permissions
            if ($userId !== null && !$this->checkPermissions($userId, $definition->requiredPermissions)) {
                throw new ReportPermissionException(
                    "User does not have permission to generate report: {$code}",
                    $code,
                    $definition->requiredPermissions
                );
            }

            // Validate parameters
            $this->validateParameters($parameters, $definition->defaultParameters);

            // Check if report generator is registered
            if (!isset($this->reportRegistry[$code])) {
                throw new ReportNotFoundException("Report generator not registered: {$code}", $code);
            }

            $generator = $this->reportRegistry[$code]['generator'];
            $timeout = $definition->timeoutSeconds ?? $this->defaultTimeout;

            // Set execution timeout
            set_time_limit($timeout);

            // Generate report data
            $this->logger->info('Generating report', [
                'report_code' => $code,
                'parameters' => $parameters,
                'user_id' => $userId,
                'page' => $page,
                'per_page' => $perPage
            ]);

            $reportData = $generator($parameters, $page, $perPage);

            // Validate report data structure
            if (!isset($reportData['data']) || !is_array($reportData['data'])) {
                throw new ReportGenerationException(
                    "Invalid report data structure for: {$code}",
                    $code,
                    $parameters
                );
            }

            $executionTime = microtime(true) - $startTime;

            $report = new Report(
                null,
                $code,
                $definition->name,
                $parameters,
                $reportData['data'],
                $reportData['columns'] ?? [],
                $reportData['total_rows'] ?? count($reportData['data']),
                $page,
                $perPage,
                $reportData['summary'] ?? [],
                $userId,
                $executionTime
            );

            // Save report to history
            $reportId = $this->saveReportHistory($report);
            $report = new Report(
                $reportId,
                $report->code,
                $report->name,
                $report->parameters,
                $report->data,
                $report->columns,
                $report->totalRows,
                $report->page,
                $report->perPage,
                $report->summary,
                $report->userId,
                $report->executionTime,
                $report->generatedAt
            );

            // Dispatch event
            $event = new ReportGeneratedEvent($report);
            $this->eventDispatcher->dispatch($event);

            $this->logger->info('Report generated successfully', [
                'report_id' => $reportId,
                'report_code' => $code,
                'execution_time' => $executionTime,
                'rows' => count($reportData['data'])
            ]);

            return $report;

        } catch (ReportNotFoundException | ReportPermissionException | ReportValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $executionTime = microtime(true) - $startTime;

            // Dispatch error event
            $errorEvent = new ReportErrorEvent($code, $parameters, $e->getMessage(), $userId);
            $this->eventDispatcher->dispatch($errorEvent);

            $this->logger->error('Report generation failed', [
                'report_code' => $code,
                'parameters' => $parameters,
                'error' => $e->getMessage(),
                'execution_time' => $executionTime
            ]);

            throw new ReportGenerationException(
                "Failed to generate report: {$e->getMessage()}",
                $code,
                $parameters,
                $e
            );
        }
    }

    /**
     * Get report from history
     */
    public function getReportFromHistory(int $reportId, ?int $userId = null): Report
    {
        $sql = "SELECT * FROM report_history WHERE id = ?";
        $params = [$reportId];

        if ($userId !== null) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }

        $result = $this->db->query($sql, $params);

        if (empty($result)) {
            throw new ReportNotFoundException("Report not found in history: {$reportId}");
        }

        $data = $result[0];
        return new Report(
            (int)$data['id'],
            $data['report_code'],
            $data['report_name'],
            json_decode($data['parameters'], true),
            json_decode($data['data'], true),
            json_decode($data['columns'], true),
            (int)$data['total_rows'],
            (int)$data['page'],
            (int)$data['per_page'],
            json_decode($data['summary'], true) ?? [],
            $data['user_id'] ? (int)$data['user_id'] : null,
            (float)$data['execution_time'],
            new \DateTime($data['generated_at'])
        );
    }

    /**
     * Schedule a report
     */
    public function scheduleReport(
        string $code,
        string $schedule,
        array $parameters = [],
        string $deliveryMethod = 'email',
        ?array $recipients = null,
        ?int $userId = null
    ): ScheduledReport {
        // Validate report exists and scheduling is allowed
        $definition = $this->getReportDefinition($code);

        if (!$definition->allowScheduling) {
            throw new ReportValidationException(
                "Report does not support scheduling: {$code}",
                ['allow_scheduling' => false]
            );
        }

        // Validate parameters
        $this->validateParameters($parameters, $definition->defaultParameters);

        $sql = "INSERT INTO scheduled_reports 
                (report_code, schedule, parameters, delivery_method, recipients, user_id, is_active, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW())";

        $this->db->execute($sql, [
            $code,
            $schedule,
            json_encode($parameters),
            $deliveryMethod,
            json_encode($recipients),
            $userId
        ]);

        $scheduleId = $this->db->lastInsertId();

        $scheduledReport = new ScheduledReport(
            $scheduleId,
            $code,
            $schedule,
            $parameters,
            $deliveryMethod,
            $recipients,
            true,
            $userId,
            new \DateTime()
        );

        // Dispatch event
        $event = new ReportScheduledEvent($scheduledReport);
        $this->eventDispatcher->dispatch($event);

        $this->logger->info('Report scheduled', [
            'schedule_id' => $scheduleId,
            'report_code' => $code,
            'schedule' => $schedule
        ]);

        return $scheduledReport;
    }

    /**
     * Execute scheduled reports
     */
    public function executeScheduledReports(): array
    {
        $sql = "SELECT * FROM scheduled_reports 
                WHERE is_active = 1 
                AND (last_run_at IS NULL OR next_run_at <= NOW())";

        $schedules = $this->db->query($sql);
        $results = [];

        foreach ($schedules as $scheduleData) {
            try {
                $schedule = new ScheduledReport(
                    (int)$scheduleData['id'],
                    $scheduleData['report_code'],
                    $scheduleData['schedule'],
                    json_decode($scheduleData['parameters'], true),
                    $scheduleData['delivery_method'],
                    json_decode($scheduleData['recipients'], true),
                    (bool)$scheduleData['is_active'],
                    $scheduleData['user_id'] ? (int)$scheduleData['user_id'] : null,
                    new \DateTime($scheduleData['created_at']),
                    $scheduleData['last_run_at'] ? new \DateTime($scheduleData['last_run_at']) : null,
                    $scheduleData['next_run_at'] ? new \DateTime($scheduleData['next_run_at']) : null
                );

                // Generate report
                $report = $this->generateReport(
                    $schedule->reportCode,
                    $schedule->parameters,
                    $schedule->userId
                );

                // Deliver report (implementation depends on delivery method)
                $this->deliverReport($report, $schedule);

                // Update schedule
                $nextRun = $schedule->calculateNextRun();
                $updateSql = "UPDATE scheduled_reports 
                             SET last_run_at = NOW(), next_run_at = ? 
                             WHERE id = ?";
                $this->db->execute($updateSql, [$nextRun->format('Y-m-d H:i:s'), $schedule->id]);

                // Dispatch event
                $event = new ScheduledReportExecutedEvent($schedule, $report, true);
                $this->eventDispatcher->dispatch($event);

                $results[] = [
                    'schedule_id' => $schedule->id,
                    'report_code' => $schedule->reportCode,
                    'status' => 'success',
                    'report_id' => $report->id
                ];

            } catch (\Exception $e) {
                $this->logger->error('Scheduled report execution failed', [
                    'schedule_id' => $scheduleData['id'],
                    'report_code' => $scheduleData['report_code'],
                    'error' => $e->getMessage()
                ]);

                $results[] = [
                    'schedule_id' => $scheduleData['id'],
                    'report_code' => $scheduleData['report_code'],
                    'status' => 'failed',
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * List available reports
     */
    public function listReports(?string $category = null, ?int $userId = null): array
    {
        $sql = "SELECT * FROM report_definitions WHERE 1=1";
        $params = [];

        if ($category !== null) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }

        $sql .= " ORDER BY category, name";

        $results = $this->db->query($sql, $params);
        $reports = [];

        foreach ($results as $data) {
            $definition = new ReportDefinition(
                (int)$data['id'],
                $data['code'],
                $data['name'],
                $data['description'],
                $data['category'],
                json_decode($data['default_parameters'], true),
                json_decode($data['required_permissions'], true),
                (int)$data['timeout_seconds'],
                (bool)$data['allow_export'],
                json_decode($data['export_formats'], true),
                (bool)$data['allow_scheduling'],
                new \DateTime($data['created_at']),
                $data['updated_at'] ? new \DateTime($data['updated_at']) : null
            );

            // Filter by permissions if user specified
            if ($userId !== null && !$this->checkPermissions($userId, $definition->requiredPermissions)) {
                continue;
            }

            $reports[] = $definition;
        }

        return $reports;
    }

    /**
     * Validate report parameters
     */
    private function validateParameters(array $parameters, array $defaults): void
    {
        $errors = [];

        foreach ($defaults as $key => $config) {
            if (isset($config['required']) && $config['required'] && !isset($parameters[$key])) {
                $errors[$key] = "Required parameter missing: {$key}";
            }

            if (isset($parameters[$key]) && isset($config['type'])) {
                $value = $parameters[$key];
                $type = $config['type'];

                switch ($type) {
                    case 'date':
                        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                            $errors[$key] = "Invalid date format for {$key}. Expected: YYYY-MM-DD";
                        }
                        break;
                    case 'int':
                        if (!is_numeric($value) || (int)$value != $value) {
                            $errors[$key] = "Invalid integer value for {$key}";
                        }
                        break;
                    case 'array':
                        if (!is_array($value)) {
                            $errors[$key] = "Expected array for {$key}";
                        }
                        break;
                }
            }
        }

        if (!empty($errors)) {
            throw new ReportValidationException('Invalid report parameters', $errors);
        }
    }

    /**
     * Check user permissions
     */
    private function checkPermissions(int $userId, array $requiredPermissions): bool
    {
        if (empty($requiredPermissions)) {
            return true;
        }

        // Query user permissions
        $sql = "SELECT permission_code FROM user_permissions WHERE user_id = ?";
        $result = $this->db->query($sql, [$userId]);
        $userPermissions = array_column($result, 'permission_code');

        // Check if user has all required permissions
        foreach ($requiredPermissions as $permission) {
            if (!in_array($permission, $userPermissions)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Save report to history
     */
    private function saveReportHistory(Report $report): int
    {
        $sql = "INSERT INTO report_history 
                (report_code, report_name, parameters, data, columns, total_rows, page, per_page, summary, user_id, execution_time, generated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $this->db->execute($sql, [
            $report->code,
            $report->name,
            json_encode($report->parameters),
            json_encode($report->data),
            json_encode($report->columns),
            $report->totalRows,
            $report->page,
            $report->perPage,
            json_encode($report->summary),
            $report->userId,
            $report->executionTime
        ]);

        return $this->db->lastInsertId();
    }

    /**
     * Deliver report based on delivery method
     */
    private function deliverReport(Report $report, ScheduledReport $schedule): void
    {
        // This would be implemented based on delivery method
        // For now, just log that delivery would happen
        $this->logger->info('Report delivery', [
            'report_id' => $report->id,
            'delivery_method' => $schedule->deliveryMethod,
            'recipients' => $schedule->recipients
        ]);

        // TODO: Implement actual delivery methods:
        // - email: Use mailer service to send report as PDF/Excel attachment
        // - ftp: Upload report file to FTP server
        // - api: POST report data to external API
        // - file: Save report to file system location
    }
}
