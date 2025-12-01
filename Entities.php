<?php
/**
 * FrontAccounting Reports Module - Entities
 *
 * Entity classes for report definitions and data structures.
 *
 * @package FA\Modules\Reports
 * @version 1.0.0
 * @author FrontAccounting Team
 * @license GPL-3.0
 */

namespace FA\Modules\Reports\Entities;

/**
 * Report Entity
 *
 * Represents a generated report with data and metadata.
 */
class Report
{
    private string $reportCode;
    private string $reportName;
    private string $category;
    private array $parameters;
    private array $data;
    private array $columns;
    private array $summary;
    private string $generatedAt;
    private float $executionTime;
    private int $rowCount;

    public function __construct(array $data)
    {
        $this->reportCode = $data['report_code'] ?? '';
        $this->reportName = $data['report_name'] ?? '';
        $this->category = $data['category'] ?? '';
        $this->parameters = $data['parameters'] ?? [];
        $this->data = $data['data'] ?? [];
        $this->columns = $data['columns'] ?? [];
        $this->summary = $data['summary'] ?? [];
        $this->generatedAt = $data['generated_at'] ?? date('Y-m-d H:i:s');
        $this->executionTime = (float)($data['execution_time'] ?? 0.0);
        $this->rowCount = count($this->data);
    }

    // Getters
    public function getReportCode(): string { return $this->reportCode; }
    public function getReportName(): string { return $this->reportName; }
    public function getCategory(): string { return $this->category; }
    public function getParameters(): array { return $this->parameters; }
    public function getData(): array { return $this->data; }
    public function getColumns(): array { return $this->columns; }
    public function getSummary(): array { return $this->summary; }
    public function getGeneratedAt(): string { return $this->generatedAt; }
    public function getExecutionTime(): float { return $this->executionTime; }
    public function getRowCount(): int { return $this->rowCount; }

    /**
     * Get report title with parameters
     */
    public function getFullTitle(): string
    {
        $title = $this->reportName;
        if (!empty($this->parameters)) {
            $paramStr = [];
            foreach ($this->parameters as $key => $value) {
                if (!is_array($value)) {
                    $paramStr[] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
                }
            }
            if ($paramStr) {
                $title .= ' (' . implode(', ', $paramStr) . ')';
            }
        }
        return $title;
    }

    /**
     * Check if report has data
     */
    public function hasData(): bool
    {
        return $this->rowCount > 0;
    }

    /**
     * Get paginated data
     */
    public function getPaginatedData(int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;
        return array_slice($this->data, $offset, $perPage);
    }

    /**
     * Get total pages
     */
    public function getTotalPages(int $perPage = 50): int
    {
        return (int)ceil($this->rowCount / $perPage);
    }

    public function toArray(): array
    {
        return [
            'report_code' => $this->reportCode,
            'report_name' => $this->reportName,
            'category' => $this->category,
            'parameters' => $this->parameters,
            'data' => $this->data,
            'columns' => $this->columns,
            'summary' => $this->summary,
            'generated_at' => $this->generatedAt,
            'execution_time' => $this->executionTime,
            'row_count' => $this->rowCount
        ];
    }
}

/**
 * Report Definition Entity
 *
 * Represents a report configuration/template.
 */
class ReportDefinition
{
    private int $id;
    private string $reportCode;
    private string $reportName;
    private string $category;
    private string $description;
    private array $parameters;
    private array $columns;
    private ?string $sqlTemplate;
    private bool $requiresParameters;
    private array $defaultParameters;
    private bool $cacheable;
    private int $cacheTtl;
    private string $createdAt;
    private string $updatedAt;

    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? 0;
        $this->reportCode = $data['report_code'] ?? '';
        $this->reportName = $data['report_name'] ?? '';
        $this->category = $data['category'] ?? '';
        $this->description = $data['description'] ?? '';
        $this->parameters = $data['parameters'] ?? [];
        $this->columns = $data['columns'] ?? [];
        $this->sqlTemplate = $data['sql_template'] ?? null;
        $this->requiresParameters = (bool)($data['requires_parameters'] ?? false);
        $this->defaultParameters = $data['default_parameters'] ?? [];
        $this->cacheable = (bool)($data['cacheable'] ?? true);
        $this->cacheTtl = (int)($data['cache_ttl'] ?? 3600);
        $this->createdAt = $data['created_at'] ?? '';
        $this->updatedAt = $data['updated_at'] ?? '';
    }

    // Getters
    public function getId(): int { return $this->id; }
    public function getReportCode(): string { return $this->reportCode; }
    public function getReportName(): string { return $this->reportName; }
    public function getCategory(): string { return $this->category; }
    public function getDescription(): string { return $this->description; }
    public function getParameters(): array { return $this->parameters; }
    public function getColumns(): array { return $this->columns; }
    public function getSqlTemplate(): ?string { return $this->sqlTemplate; }
    public function requiresParameters(): bool { return $this->requiresParameters; }
    public function getDefaultParameters(): array { return $this->defaultParameters; }
    public function isCacheable(): bool { return $this->cacheable; }
    public function getCacheTtl(): int { return $this->cacheTtl; }
    public function getCreatedAt(): string { return $this->createdAt; }
    public function getUpdatedAt(): string { return $this->updatedAt; }

    /**
     * Validate parameters against definition
     */
    public function validateParameters(array $params): array
    {
        $errors = [];
        
        foreach ($this->parameters as $paramDef) {
            $paramName = $paramDef['name'];
            $required = $paramDef['required'] ?? false;
            
            if ($required && !isset($params[$paramName])) {
                $errors[$paramName] = "Parameter '{$paramName}' is required";
            }
            
            if (isset($params[$paramName]) && isset($paramDef['type'])) {
                $value = $params[$paramName];
                $type = $paramDef['type'];
                
                if ($type === 'date' && !$this->isValidDate($value)) {
                    $errors[$paramName] = "Invalid date format for '{$paramName}'";
                }
                
                if ($type === 'int' && !is_numeric($value)) {
                    $errors[$paramName] = "Parameter '{$paramName}' must be numeric";
                }
            }
        }
        
        return $errors;
    }

    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'report_code' => $this->reportCode,
            'report_name' => $this->reportName,
            'category' => $this->category,
            'description' => $this->description,
            'parameters' => $this->parameters,
            'columns' => $this->columns,
            'sql_template' => $this->sqlTemplate,
            'requires_parameters' => $this->requiresParameters,
            'default_parameters' => $this->defaultParameters,
            'cacheable' => $this->cacheable,
            'cache_ttl' => $this->cacheTtl,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt
        ];
    }
}

/**
 * Scheduled Report Entity
 *
 * Represents a scheduled report configuration.
 */
class ScheduledReport
{
    private int $id;
    private string $reportCode;
    private array $parameters;
    private string $schedule;
    private array $recipients;
    private string $format;
    private ?string $lastRun;
    private ?string $nextRun;
    private bool $active;
    private string $createdAt;

    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? 0;
        $this->reportCode = $data['report_code'] ?? '';
        $this->parameters = $data['parameters'] ?? [];
        $this->schedule = $data['schedule'] ?? 'daily';
        $this->recipients = $data['recipients'] ?? [];
        $this->format = $data['format'] ?? 'pdf';
        $this->lastRun = $data['last_run'] ?? null;
        $this->nextRun = $data['next_run'] ?? null;
        $this->active = (bool)($data['active'] ?? true);
        $this->createdAt = $data['created_at'] ?? '';
    }

    // Getters
    public function getId(): int { return $this->id; }
    public function getReportCode(): string { return $this->reportCode; }
    public function getParameters(): array { return $this->parameters; }
    public function getSchedule(): string { return $this->schedule; }
    public function getRecipients(): array { return $this->recipients; }
    public function getFormat(): string { return $this->format; }
    public function getLastRun(): ?string { return $this->lastRun; }
    public function getNextRun(): ?string { return $this->nextRun; }
    public function isActive(): bool { return $this->active; }
    public function getCreatedAt(): string { return $this->createdAt; }

    /**
     * Check if report should run now
     */
    public function shouldRun(): bool
    {
        if (!$this->active || !$this->nextRun) {
            return false;
        }
        
        return strtotime($this->nextRun) <= time();
    }

    /**
     * Calculate next run time
     */
    public function calculateNextRun(): string
    {
        $now = time();
        
        switch ($this->schedule) {
            case 'daily':
                return date('Y-m-d H:i:s', strtotime('+1 day', $now));
            case 'weekly':
                return date('Y-m-d H:i:s', strtotime('+1 week', $now));
            case 'monthly':
                return date('Y-m-d H:i:s', strtotime('+1 month', $now));
            case 'quarterly':
                return date('Y-m-d H:i:s', strtotime('+3 months', $now));
            default:
                return date('Y-m-d H:i:s', strtotime('+1 day', $now));
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'report_code' => $this->reportCode,
            'parameters' => $this->parameters,
            'schedule' => $this->schedule,
            'recipients' => $this->recipients,
            'format' => $this->format,
            'last_run' => $this->lastRun,
            'next_run' => $this->nextRun,
            'active' => $this->active,
            'created_at' => $this->createdAt
        ];
    }
}
