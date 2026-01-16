<?php

declare(strict_types=1);

namespace FA\Modules\Reports\Base;

/**
 * Extracts report parameters from $_POST array
 * 
 * All FA legacy reports use the same pattern:
 * - PARAM_0, PARAM_1, PARAM_2, ... for report-specific parameters
 * - Last 2-3 parameters are always: comments, orientation, destination
 * - If dimensions enabled: dimension params inserted before comments
 * 
 * This class standardizes parameter extraction and provides type safety.
 * 
 * @package FA\Modules\Reports\Base
 */
class ParameterExtractor
{
    private const ALL_TEXT = 'All';
    
    private array $params;
    private int $dimensionCount;

    /**
     * @param array $params Source array (typically $_POST)
     * @param int $dimensionCount Number of dimension parameters (0, 1, or 2)
     */
    public function __construct(array $params = [], int $dimensionCount = 0)
    {
        $this->params = $params;
        $this->dimensionCount = $dimensionCount;
    }

    /**
     * Get string parameter by index
     */
    public function getString(string $key, string $default = ''): string
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Get integer parameter by index
     */
    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->params[$key] ?? $default;
        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * Get boolean parameter (handles 0/1 and true/false)
     */
    public function getBool(string $key, bool $default = false): bool
    {
        if (!isset($this->params[$key])) {
            return $default;
        }
        $value = $this->params[$key];
        if (is_bool($value)) {
            return $value;
        }
        return in_array($value, [1, '1', 'true', 'yes', 'on'], true);
    }

    /**
     * Check if parameter is "All"
     */
    public function isAll(string $key): bool
    {
        return ($this->params[$key] ?? '') === self::ALL_TEXT;
    }

    /**
     * Get parameter or null if "All"
     */
    public function getOrNullIfAll(string $key): ?string
    {
        $value = $this->params[$key] ?? null;
        return ($value === self::ALL_TEXT || $value === '') ? null : $value;
    }

    /**
     * Extract standard report configuration
     * 
     * Handles the common pattern across all FA reports:
     * - Date range parameters
     * - Dimension parameters (if enabled)
     * - Comments, orientation, destination at the end
     * 
     * @param int $reportSpecificParamCount Number of report-specific params before standard params
     * @return ReportConfig
     */
    public function extractReportConfig(int $reportSpecificParamCount = 0): ReportConfig
    {
        $paramIndex = $reportSpecificParamCount;
        
        // Standard parameters that come after report-specific ones
        $dimension1 = 0;
        $dimension2 = 0;
        
        // Extract dimensions if enabled
        if ($this->dimensionCount >= 1) {
            $dimension1 = $this->getInt("PARAM_$paramIndex", 0);
            $paramIndex++;
        }
        if ($this->dimensionCount >= 2) {
            $dimension2 = $this->getInt("PARAM_$paramIndex", 0);
            $paramIndex++;
        }
        
        // Last 3 parameters are always: comments, orientation, destination
        $comments = $this->getString("PARAM_$paramIndex", '');
        $paramIndex++;
        $landscapeOrientation = $this->getBool("PARAM_$paramIndex", false);
        $paramIndex++;
        $exportToExcel = $this->getBool("PARAM_$paramIndex", false);
        
        // Get user preferences
        $decimals = \FA\UserPrefsCache::getPriceDecimals();
        $pageSize = function_exists('user_pagesize') ? user_pagesize() : 'A4';
        
        return new ReportConfig(
            fromDate: $this->getString('PARAM_0', ''),
            toDate: $this->getString('PARAM_1', ''),
            dimension1: $dimension1,
            dimension2: $dimension2,
            exportToExcel: $exportToExcel,
            landscapeOrientation: $landscapeOrientation,
            decimals: $decimals,
            pageSize: $pageSize,
            comments: $comments
        );
    }

    /**
     * Extract configuration for GL reports
     * Pattern: from, to, [dimension1], [dimension2], comments, orientation, destination
     */
    public function extractGLReportConfig(): ReportConfig
    {
        $fromDate = $this->getString('PARAM_0');
        $toDate = $this->getString('PARAM_1');
        
        // Determine dimension count dynamically
        $dimCount = \FA\Services\CompanyPrefsService::getUseDimensions();
        
        $paramIndex = 2;
        $dimension1 = 0;
        $dimension2 = 0;
        
        if ($dimCount >= 1) {
            $dimension1 = $this->getInt("PARAM_$paramIndex", 0);
            $paramIndex++;
        }
        if ($dimCount >= 2) {
            $dimension2 = $this->getInt("PARAM_$paramIndex", 0);
            $paramIndex++;
        }
        
        $comments = $this->getString("PARAM_$paramIndex", '');
        $paramIndex++;
        $landscapeOrientation = $this->getBool("PARAM_$paramIndex", false);
        $paramIndex++;
        $exportToExcel = $this->getBool("PARAM_$paramIndex", false);
        
        $decimals = \FA\UserPrefsCache::getPriceDecimals();
        $pageSize = function_exists('user_pagesize') ? user_pagesize() : 'A4';
        
        return new ReportConfig(
            fromDate: $fromDate,
            toDate: $toDate,
            dimension1: $dimension1,
            dimension2: $dimension2,
            exportToExcel: $exportToExcel,
            landscapeOrientation: $landscapeOrientation,
            decimals: $decimals,
            pageSize: $pageSize,
            comments: $comments
        );
    }

    /**
     * Extract configuration for customer/supplier reports
     * Pattern: from, to, customer/supplier, show_balance, currency, no_zeros, comments, orientation, destination
     */
    public function extractCustomerSupplierConfig(): ReportConfig
    {
        $fromDate = $this->getString('PARAM_0');
        $toDate = $this->getString('PARAM_1');
        $entity = $this->getOrNullIfAll('PARAM_2'); // customer_id or supplier_id
        $showBalance = $this->getBool('PARAM_3', false);
        $currency = $this->getOrNullIfAll('PARAM_4');
        $suppressZeros = $this->getBool('PARAM_5', false);
        $comments = $this->getString('PARAM_6', '');
        $landscapeOrientation = $this->getBool('PARAM_7', false);
        $exportToExcel = $this->getBool('PARAM_8', false);
        
        $decimals = \FA\UserPrefsCache::getPriceDecimals();
        $pageSize = function_exists('user_pagesize') ? user_pagesize() : 'A4';
        $convertCurrency = $currency === null; // null means "All" = convert to home
        
        return new ReportConfig(
            fromDate: $fromDate,
            toDate: $toDate,
            exportToExcel: $exportToExcel,
            landscapeOrientation: $landscapeOrientation,
            decimals: $decimals,
            pageSize: $pageSize,
            comments: $comments,
            currency: $currency,
            convertCurrency: $convertCurrency,
            suppressZeros: $suppressZeros,
            additionalParams: [
                'entity_id' => $entity,
                'show_balance' => $showBalance,
            ]
        );
    }

    /**
     * Get all parameters as array
     */
    public function getAllParams(): array
    {
        return $this->params;
    }

    /**
     * Create from $_POST (convenience method)
     */
    public static function fromPost(): self
    {
        $dimCount = \FA\Services\CompanyPrefsService::getUseDimensions();
        return new self($_POST, $dimCount);
    }

    /**
     * Create for testing (convenience method)
     */
    public static function fromArray(array $params, int $dimensionCount = 0): self
    {
        return new self($params, $dimensionCount);
    }
}
