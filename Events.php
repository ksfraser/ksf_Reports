<?php
/**
 * FrontAccounting Reports Module - Events
 *
 * PSR-14 compatible event classes for report operations.
 *
 * @package FA\Modules\Reports
 * @version 1.0.0
 * @author FrontAccounting Team
 * @license GPL-3.0
 */

namespace FA\Modules\Reports\Events;

use FA\Modules\Reports\Entities\Report;
use FA\Modules\Reports\Entities\ScheduledReport;

/**
 * Report Generated Event
 *
 * Dispatched when a report is successfully generated.
 */
class ReportGeneratedEvent
{
    private Report $report;
    private int $userId;
    private float $executionTime;

    public function __construct(Report $report, int $userId, float $executionTime)
    {
        $this->report = $report;
        $this->userId = $userId;
        $this->executionTime = $executionTime;
    }

    public function getReport(): Report
    {
        return $this->report;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }
}

/**
 * Report Exported Event
 *
 * Dispatched when a report is exported to a file format.
 */
class ReportExportedEvent
{
    private Report $report;
    private string $format;
    private string $filePath;
    private int $userId;

    public function __construct(Report $report, string $format, string $filePath, int $userId)
    {
        $this->report = $report;
        $this->format = $format;
        $this->filePath = $filePath;
        $this->userId = $userId;
    }

    public function getReport(): Report
    {
        return $this->report;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}

/**
 * Report Scheduled Event
 *
 * Dispatched when a report is scheduled for automatic generation.
 */
class ReportScheduledEvent
{
    private ScheduledReport $scheduledReport;
    private int $userId;

    public function __construct(ScheduledReport $scheduledReport, int $userId)
    {
        $this->scheduledReport = $scheduledReport;
        $this->userId = $userId;
    }

    public function getScheduledReport(): ScheduledReport
    {
        return $this->scheduledReport;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}

/**
 * Report Error Event
 *
 * Dispatched when report generation or export fails.
 */
class ReportErrorEvent
{
    private string $reportCode;
    private array $parameters;
    private string $errorMessage;
    private ?\Throwable $exception;
    private int $userId;

    public function __construct(
        string $reportCode,
        array $parameters,
        string $errorMessage,
        ?\Throwable $exception = null,
        int $userId = 0
    ) {
        $this->reportCode = $reportCode;
        $this->parameters = $parameters;
        $this->errorMessage = $errorMessage;
        $this->exception = $exception;
        $this->userId = $userId;
    }

    public function getReportCode(): string
    {
        return $this->reportCode;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}

/**
 * Scheduled Report Executed Event
 *
 * Dispatched when a scheduled report runs automatically.
 */
class ScheduledReportExecutedEvent
{
    private ScheduledReport $scheduledReport;
    private Report $report;
    private bool $success;
    private ?string $errorMessage;

    public function __construct(
        ScheduledReport $scheduledReport,
        Report $report,
        bool $success,
        ?string $errorMessage = null
    ) {
        $this->scheduledReport = $scheduledReport;
        $this->report = $report;
        $this->success = $success;
        $this->errorMessage = $errorMessage;
    }

    public function getScheduledReport(): ScheduledReport
    {
        return $this->scheduledReport;
    }

    public function getReport(): Report
    {
        return $this->report;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
