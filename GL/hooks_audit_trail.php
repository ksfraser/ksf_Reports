<?php

declare(strict_types=1);

/**
 * Hooks for Audit Trail Report (rep710)
 * 
 * Provides factory and helper functions for backward compatibility.
 */

use FA\Modules\Reports\GL\AuditTrail;
use FA\Modules\Reports\Base\ReportConfig;
use FA\Modules\Reports\Base\ParameterExtractor;

/**
 * Factory function to get Audit Trail service
 */
function get_audit_trail_service(): AuditTrail
{
    global $db, $dispatcher, $logger;
    
    return new AuditTrail(
        $db,
        $dispatcher,
        $logger
    );
}

/**
 * Generate Audit Trail report
 *
 * @param string $fromDate Start date
 * @param string $toDate End date
 * @param int $transType Transaction type filter (-1 = all)
 * @param int $userId User ID filter (-1 = all)
 * @param string $comments Report comments
 * @param string $orientation Page orientation ('L' or 'P')
 * @param bool $exportToExcel Export to Excel instead of PDF
 * @return array Generated report data
 */
function generate_audit_trail(
    string $fromDate,
    string $toDate,
    int $transType = -1,
    int $userId = -1,
    string $comments = '',
    string $orientation = 'L',
    bool $exportToExcel = false
): array {
    $service = get_audit_trail_service();
    
    $config = new ReportConfig(
        $fromDate,
        $toDate,
        0, // no dimensions
        0,
        $exportToExcel,
        $orientation,
        0, // decimals handled by system prefs
        null, // currency
        false, // suppressZeros
        [
            'trans_type' => $transType,
            'user_id' => $userId,
            'comments' => $comments
        ]
    );
    
    return $service->generate($config);
}

/**
 * Generate Audit Trail from POST parameters
 *
 * @return array Generated report data
 */
function generate_audit_trail_from_post(): array
{
    $extractor = new ParameterExtractor();
    
    $fromDate = $extractor->getString('PARAM_0');
    $toDate = $extractor->getString('PARAM_1');
    $transType = $extractor->getInt('PARAM_2', -1);
    $userId = $extractor->getInt('PARAM_3', -1);
    $comments = $extractor->getString('PARAM_4', '');
    $orientation = $extractor->getBool('PARAM_5', true) ? 'L' : 'P';
    $exportToExcel = (bool)$extractor->getInt('PARAM_6', 0);
    
    return generate_audit_trail(
        $fromDate,
        $toDate,
        $transType,
        $userId,
        $comments,
        $orientation,
        $exportToExcel
    );
}

/**
 * Export Audit Trail to PDF or Excel
 *
 * @param array $data Report data
 * @param ReportConfig $config Report configuration
 * @return void Outputs file to browser
 */
function export_audit_trail(array $data, ReportConfig $config): void
{
    $service = get_audit_trail_service();
    $service->export($data, 'Audit Trail', $config);
}
