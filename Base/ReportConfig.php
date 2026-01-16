<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Base;

/**
 * Value object representing common report configuration
 * 
 * Encapsulates parameters that appear across all FA legacy reports:
 * - Date range (from/to)
 * - Dimensions (dimension1, dimension2)
 * - Output format (PDF vs Excel via destination flag)
 * - Page orientation (Portrait vs Landscape)
 * - User preferences (decimals, page size)
 * - Comments/notes
 * - Currency conversion options
 * 
 * @package FA\Modules\Reports\Base
 */
class ReportConfig
{
    private string $fromDate;
    private string $toDate;
    private int $dimension1;
    private int $dimension2;
    private bool $exportToExcel; // true = Excel, false = PDF
    private bool $landscapeOrientation; // true = Landscape, false = Portrait
    private int $decimals;
    private string $pageSize;
    private string $comments;
    private ?string $currency;
    private bool $convertCurrency;
    private bool $suppressZeros;
    private array $additionalParams;

    /**
     * @param string $fromDate Start date (user format or SQL format)
     * @param string $toDate End date (user format or SQL format)
     * @param int $dimension1 First dimension filter (0 = all)
     * @param int $dimension2 Second dimension filter (0 = all)
     * @param bool $exportToExcel True for Excel, false for PDF
     * @param bool $landscapeOrientation True for landscape, false for portrait
     * @param int $decimals Price decimal places
     * @param string $pageSize Page size (A4, Letter, etc.)
     * @param string $comments Report comments/notes
     * @param string|null $currency Currency code (null = home currency)
     * @param bool $convertCurrency Whether to convert to home currency
     * @param bool $suppressZeros Whether to hide zero-value rows
     * @param array $additionalParams Additional report-specific parameters
     */
    public function __construct(
        string $fromDate,
        string $toDate,
        int $dimension1 = 0,
        int $dimension2 = 0,
        bool $exportToExcel = false,
        bool $landscapeOrientation = false,
        int $decimals = 2,
        string $pageSize = 'A4',
        string $comments = '',
        ?string $currency = null,
        bool $convertCurrency = false,
        bool $suppressZeros = false,
        array $additionalParams = []
    ) {
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->dimension1 = $dimension1;
        $this->dimension2 = $dimension2;
        $this->exportToExcel = $exportToExcel;
        $this->landscapeOrientation = $landscapeOrientation;
        $this->decimals = $decimals;
        $this->pageSize = $pageSize;
        $this->comments = $comments;
        $this->currency = $currency;
        $this->convertCurrency = $convertCurrency;
        $this->suppressZeros = $suppressZeros;
        $this->additionalParams = $additionalParams;
    }

    public function getFromDate(): string
    {
        return $this->fromDate;
    }

    public function getToDate(): string
    {
        return $this->toDate;
    }

    public function getDimension1(): int
    {
        return $this->dimension1;
    }

    public function getDimension2(): int
    {
        return $this->dimension2;
    }

    public function shouldExportToExcel(): bool
    {
        return $this->exportToExcel;
    }

    public function isLandscapeOrientation(): bool
    {
        return $this->landscapeOrientation;
    }

    public function getOrientation(): string
    {
        return $this->landscapeOrientation ? 'L' : 'P';
    }

    public function getDecimals(): int
    {
        return $this->decimals;
    }

    public function getPageSize(): string
    {
        return $this->pageSize;
    }

    public function getComments(): string
    {
        return $this->comments;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function shouldConvertCurrency(): bool
    {
        return $this->convertCurrency;
    }

    public function shouldSuppressZeros(): bool
    {
        return $this->suppressZeros;
    }

    public function getAdditionalParam(string $key, $default = null)
    {
        return $this->additionalParams[$key] ?? $default;
    }

    public function getAllAdditionalParams(): array
    {
        return $this->additionalParams;
    }

    /**
     * Check if dimensions are enabled and being used
     */
    public function hasDimension1(): bool
    {
        return $this->dimension1 > 0;
    }

    public function hasDimension2(): bool
    {
        return $this->dimension2 > 0;
    }

    public function hasAnyDimension(): bool
    {
        return $this->dimension1 > 0 || $this->dimension2 > 0;
    }

    /**
     * Get report format as string for event dispatching
     */
    public function getFormat(): string
    {
        return $this->exportToExcel ? 'excel' : 'pdf';
    }

    /**
     * Convert to array for legacy compatibility
     */
    public function toArray(): array
    {
        return [
            'from_date' => $this->fromDate,
            'to_date' => $this->toDate,
            'dimension1' => $this->dimension1,
            'dimension2' => $this->dimension2,
            'export_to_excel' => $this->exportToExcel,
            'orientation' => $this->getOrientation(),
            'decimals' => $this->decimals,
            'page_size' => $this->pageSize,
            'comments' => $this->comments,
            'currency' => $this->currency,
            'convert_currency' => $this->convertCurrency,
            'suppress_zeros' => $this->suppressZeros,
            'additional_params' => $this->additionalParams,
        ];
    }
}
