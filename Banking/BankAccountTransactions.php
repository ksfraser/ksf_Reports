<?php
/**
 * Bank Account Transactions Service
 * 
 * Generates bank transaction report showing:
 * - Opening balance
 * - Transaction details with running balance
 * - Debit and credit totals
 * - Closing balance
 * 
 * Report: rep601
 * Category: Banking Reports
 */

declare(strict_types=1);

namespace FA\Reports\Banking;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class BankAccountTransactions extends AbstractReportService
{
    private const REPORT_ID = 601;
    private const REPORT_TITLE = 'Bank Statement';
    
    public function __construct(
        DBALInterface $db,
        EventDispatcher $eventDispatcher
    ) {
        parent::__construct($db, $eventDispatcher);
    }
    
    protected function getReportId(): int
    {
        return self::REPORT_ID;
    }
    
    protected function getReportTitle(): string
    {
        return self::REPORT_TITLE;
    }
    
    protected function defineColumns(): array
    {
        return [0, 90, 120, 170, 225, 350, 400, 460, 520];
    }
    
    protected function defineHeaders(): array
    {
        return [
            _('Type'),
            _('#'),
            _('Reference'),
            _('Date'),
            _('Person/Item'),
            _('Debit'),
            _('Credit'),
            _('Balance')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return [
            'left', 'left', 'left', 'left', 'left', 'right', 'right', 'right'
        ];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $account = $config->getParam('account');
        $accountName = ($account == ALL_TEXT) ? _('All') : $this->getBankAccountName($account);
        
        return [
            0 => $config->getParam('comments'),
            1 => [
                'text' => _('Period'),
                'from' => $config->getParam('from_date'),
                'to' => $config->getParam('to_date')
            ],
            2 => ['text' => _('Bank Account'), 'from' => $accountName, 'to' => '']
        ];
    }
    
    protected function fetchData(ReportConfig $config): array
    {
        $this->dispatchEvent('before_fetch_data', ['config' => $config]);
        
        $account = $config->getParam('account');
        
        // Get bank accounts
        $sql = "SELECT id, bank_account_name, bank_curr_code, bank_account_number 
                FROM ".TB_PREF."bank_accounts";
        
        if ($account != ALL_TEXT) {
            $sql .= " WHERE id = ".$this->db->escape($account);
        }
        
        $accounts = $this->db->fetchAll($sql);
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $accounts
        ]);
        
        return $accounts;
    }
    
    protected function processData(ReportConfig $config, array $data): array
    {
        $this->dispatchEvent('before_process_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        $fromDate = $config->getParam('from_date');
        $toDate = $config->getParam('to_date');
        $showZero = (bool)$config->getParam('show_zero', 1);
        
        $accounts = [];
        
        foreach ($data as $account) {
            $accountId = $account['id'];
            
            // Get opening balance
            $openingBalance = $this->getOpeningBalance($accountId, $fromDate);
            
            // Get transactions
            $transactions = $this->getTransactions($accountId, $fromDate, $toDate);
            
            // Process transactions
            $processedTrans = [];
            $totalDebit = 0.0;
            $totalCredit = 0.0;
            $runningBalance = $openingBalance;
            
            foreach ($transactions as $trans) {
                $amount = (float)$trans['amount'];
                
                // Skip zero amounts if not showing them
                if (!$showZero && $amount == 0.0) {
                    continue;
                }
                
                $runningBalance += $amount;
                
                if ($amount > 0.0) {
                    $totalDebit += abs($amount);
                } else {
                    $totalCredit += abs($amount);
                }
                
                $processedTrans[] = [
                    'type' => $trans['type'],
                    'trans_no' => $trans['trans_no'],
                    'ref' => $trans['ref'],
                    'trans_date' => $trans['trans_date'],
                    'person_type_id' => $trans['person_type_id'],
                    'person_id' => $trans['person_id'],
                    'amount' => $amount,
                    'balance' => $runningBalance
                ];
            }
            
            // Only include account if it has transactions or non-zero opening balance
            if ($openingBalance != 0.0 || count($processedTrans) > 0) {
                $accounts[] = [
                    'id' => $accountId,
                    'name' => $account['bank_account_name'],
                    'currency' => $account['bank_curr_code'],
                    'number' => $account['bank_account_number'],
                    'opening_balance' => $openingBalance,
                    'transactions' => $processedTrans,
                    'total_debit' => $totalDebit,
                    'total_credit' => $totalCredit,
                    'closing_balance' => $runningBalance
                ];
            }
        }
        
        $processed = ['accounts' => $accounts];
        
        $this->dispatchEvent('after_process_data', [
            'config' => $config,
            'processed' => $processed
        ]);
        
        return $processed;
    }
    
    /**
     * Get opening balance for an account
     */
    private function getOpeningBalance(int $accountId, string $toDate): float
    {
        $to = \DateService::date2sqlStatic($toDate);
        
        $sql = "SELECT SUM(amount) AS balance 
                FROM ".TB_PREF."bank_trans 
                WHERE bank_act = ".$this->db->escape($accountId)."
                  AND trans_date < ".$this->db->escape($to);
        
        $result = $this->db->fetchOne($sql);
        return $result ? (float)$result['balance'] : 0.0;
    }
    
    /**
     * Get transactions for an account in period
     */
    private function getTransactions(int $accountId, string $fromDate, string $toDate): array
    {
        $from = \DateService::date2sqlStatic($fromDate);
        $to = \DateService::date2sqlStatic($toDate);
        
        $sql = "SELECT * FROM ".TB_PREF."bank_trans
                WHERE bank_act = ".$this->db->escape($accountId)."
                  AND trans_date >= ".$this->db->escape($from)."
                  AND trans_date <= ".$this->db->escape($to)."
                ORDER BY trans_date, id";
        
        return $this->db->fetchAll($sql);
    }
    
    /**
     * Get bank account name
     */
    private function getBankAccountName($accountId): string
    {
        $sql = "SELECT bank_account_name, bank_curr_code, bank_account_number 
                FROM ".TB_PREF."bank_accounts 
                WHERE id = ".$this->db->escape($accountId);
        
        $account = $this->db->fetchOne($sql);
        if ($account) {
            return $account['bank_account_name'].' - '.$account['bank_curr_code'].' - '.$account['bank_account_number'];
        }
        return '';
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        
        foreach ($processedData['accounts'] as $account) {
            // Account header with opening balance
            $rep->Font('bold');
            $accountName = $account['name'].' - '.$account['currency'].' - '.$account['number'];
            $rep->TextCol(0, 3, $accountName);
            $rep->TextCol(3, 5, _('Opening Balance'));
            if ($account['opening_balance'] > 0.0) {
                $rep->AmountCol(5, 6, abs($account['opening_balance']), $dec);
            } else {
                $rep->AmountCol(6, 7, abs($account['opening_balance']), $dec);
            }
            $rep->Font();
            $rep->NewLine(2);
            
            // Transactions
            foreach ($account['transactions'] as $trans) {
                $rep->TextCol(0, 1, $GLOBALS['systypes_array'][$trans['type']]);
                $rep->TextCol(1, 2, $trans['trans_no']);
                $rep->TextCol(2, 3, $trans['ref']);
                $rep->DateCol(3, 4, $trans['trans_date'], true);
                $rep->TextCol(4, 5, payment_person_name($trans['person_type_id'], $trans['person_id'], false));
                
                if ($trans['amount'] > 0.0) {
                    $rep->AmountCol(5, 6, abs($trans['amount']), $dec);
                } else {
                    $rep->AmountCol(6, 7, abs($trans['amount']), $dec);
                }
                
                $rep->AmountCol(7, 8, $trans['balance'], $dec);
                $rep->NewLine();
                
                if ($rep->row < $rep->bottomMargin + $rep->lineHeight) {
                    $rep->Line($rep->row - 2);
                    $rep->NewPage();
                }
            }
            
            // Closing balance
            $rep->NewLine();
            $rep->Font('bold');
            $rep->TextCol(0, 3, _('Ending Balance'));
            if ($account['closing_balance'] > 0.0) {
                $rep->AmountCol(5, 6, abs($account['closing_balance']), $dec);
            } else {
                $rep->AmountCol(6, 7, abs($account['closing_balance']), $dec);
            }
            $rep->Font();
            $rep->Line($rep->row - 8);
            $rep->NewLine(3);
        }
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
