<?php
/**
 * FrontAccounting Reports Module - Exceptions
 *
 * Custom exception hierarchy for report operations.
 *
 * @package FA\Modules\Reports
 * @version 1.0.0
 * @author FrontAccounting Team
 * @license GPL-3.0
 */

namespace FA\Modules\Reports;

/**
 * Base Reports Exception
 */
class ReportsException extends \Exception
{
    protected string $reportCode;

    public function __construct(
        string $message = "",
        string $reportCode = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->reportCode = $reportCode;
    }

    public function getReportCode(): string
    {
        return $this->reportCode;
    }
}

/**
 * Report Not Found Exception
 */
class ReportNotFoundException extends ReportsException
{
    public function __construct(string $reportCode, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Report with code '{$reportCode}' not found",
            $reportCode,
            $code,
            $previous
        );
    }
}

/**
 * Report Validation Exception
 */
class ReportValidationException extends ReportsException
{
    protected array $validationErrors;

    public function __construct(
        string $message = "",
        string $reportCode = "",
        array $validationErrors = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $reportCode, $code, $previous);
        $this->validationErrors = $validationErrors;
    }

    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    public function hasValidationErrors(): bool
    {
        return !empty($this->validationErrors);
    }

    public function getValidationError(string $field): ?string
    {
        return $this->validationErrors[$field] ?? null;
    }
}

/**
 * Report Generation Exception
 */
class ReportGenerationException extends ReportsException
{
    protected string $sqlError;

    public function __construct(
        string $message,
        string $reportCode = "",
        string $sqlError = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $reportCode, $code, $previous);
        $this->sqlError = $sqlError;
    }

    public function getSqlError(): string
    {
        return $this->sqlError;
    }
}

/**
 * Report Export Exception
 */
class ReportExportException extends ReportsException
{
    protected string $format;
    protected string $exportError;

    public function __construct(
        string $message,
        string $reportCode = "",
        string $format = "",
        string $exportError = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $reportCode, $code, $previous);
        $this->format = $format;
        $this->exportError = $exportError;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getExportError(): string
    {
        return $this->exportError;
    }
}

/**
 * Report Permission Exception
 */
class ReportPermissionException extends ReportsException
{
    protected int $userId;
    protected string $requiredPermission;

    public function __construct(
        string $message,
        int $userId,
        string $reportCode = "",
        string $requiredPermission = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $reportCode, $code, $previous);
        $this->userId = $userId;
        $this->requiredPermission = $requiredPermission;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getRequiredPermission(): string
    {
        return $this->requiredPermission;
    }
}

/**
 * Report Execution Timeout Exception
 */
class ReportTimeoutException extends ReportsException
{
    protected float $executionTime;
    protected float $maxExecutionTime;

    public function __construct(
        string $reportCode,
        float $executionTime,
        float $maxExecutionTime,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $message = "Report '{$reportCode}' execution timeout: {$executionTime}s (max: {$maxExecutionTime}s)";
        parent::__construct($message, $reportCode, $code, $previous);
        $this->executionTime = $executionTime;
        $this->maxExecutionTime = $maxExecutionTime;
    }

    public function getExecutionTime(): float
    {
        return $this->executionTime;
    }

    public function getMaxExecutionTime(): float
    {
        return $this->maxExecutionTime;
    }
}
