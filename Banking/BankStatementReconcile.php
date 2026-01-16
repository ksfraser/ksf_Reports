<?php
/**
 * Bank Statement with Reconcile Service
 * 
 * Generates bank statement with reconciliation columns showing:
 * - Transaction details with running balance
 * - Reconciliation dates
 * - Narration/memo fields
 * 
 * Report: rep602
 * Category: Banking Reports
 */

declare(strict_types=1);

namespace FA\Reports\Banking;

use FA\Reports\Base\AbstractReportService;
use FA\Reports\Base\ReportConfig;
use FA\Database\DBALInterface;
use FA\Events\EventDispatcher;

class BankStatementReconcile extends AbstractReportService
{
    private const REPORT_ID = 602;
    private const REPORT_TITLE = 'Bank Statement w/Reconcile';
    
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
    
    protected function getDefaultOrientation(): string
    {
        return 'L'; // Landscape for reconcile columns
    }
    
    protected function defineColumns(): array
    {
        return [0, 90, 120, 170, 225, 450, 500, 550, 600, 660, 700];
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
            _('Balance'),
            _('Reco Date'),
            _('Narration')
        ];
    }
    
    protected function defineAlignments(): array
    {
        return [
            'left', 'left', 'left', 'left', 'left', 'right', 'right', 'right', 'center', 'left'
        ];
    }
    
    protected function defineParams(ReportConfig $config): array
    {
        $accountId = $config->getParam('account');
        $account = get_bank_account($accountId);
        $accountName = $account['bank_account_name'].' - '.$account['bank_curr_code'].' - '.$account['bank_account_number'];
        
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
        
        $accountId = $config->getParam('account');
        $fromDate = $config->getParam('from_date');
        $toDate = $config->getParam('to_date');
        
        // Get opening balance
        $openingBalance = $this->getOpeningBalance($accountId, $fromDate);
        
        // Get transactions with memo
        $transactions = $this->getTransactions($accountId, $fromDate, $toDate);
        
        $data = [
            'account' => get_bank_account($accountId),
            'opening_balance' => $openingBalance,
            'transactions' => $transactions
        ];
        
        $this->dispatchEvent('after_fetch_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        return $data;
    }
    
    protected function processData(ReportConfig $config, array $data): array
    {
        $this->dispatchEvent('before_process_data', [
            'config' => $config,
            'data' => $data
        ]);
        
        $account = $data['account'];
        $openingBalance = $data['opening_balance'];
        $transactions = $data['transactions'];
        
        $processedTrans = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $runningBalance = $openingBalance;
        
        foreach ($transactions as $trans) {
            $amount = (float)$trans['amount'];
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
                'balance' => $runningBalance,
                'reconciled' => $trans['reconciled'],
                'memo' => $trans['memo_']
            ];
        }
        
        $processed = [
            'account' => $account,
            'opening_balance' => $openingBalance,
            'transactions' => $processedTrans,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => $runningBalance
        ];
        
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
     * Get transactions with memo for an account
     */
    private function getTransactions(int $accountId, string $fromDate, string $toDate): array
    {
        $from = \DateService::date2sqlStatic($fromDate);
        $to = \DateService::date2sqlStatic($toDate);
        
        $sql = "SELECT trans.*, com.memo_
                FROM ".TB_PREF."bank_trans trans
                LEFT JOIN ".TB_PREF."comments com ON trans.type = com.type AND trans.trans_no = com.id
                WHERE trans.bank_act = ".$this->db->escape($accountId)."
                  AND trans_date >= ".$this->db->escape($from)."
                  AND trans_date <= ".$this->db->escape($to)."
                  AND trans.amount <> 0
                ORDER BY trans_date, trans.id";
        
        return $this->db->fetchAll($sql);
    }
    
    protected function renderReport($rep, ReportConfig $config, array $processedData): void
    {
        $this->dispatchEvent('before_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
        
        $dec = \FA\UserPrefsCache::getPriceDecimals();
        $account = $processedData['account'];
        
        // Only render if there's data
        if ($processedData['opening_balance'] != 0.0 || count($processedData['transactions']) > 0) {
            // Account header with opening balance
            $rep->Font('bold');
            $accountName = $account['bank_account_name'].' - '.$account['bank_curr_code'].' - '.$account['bank_account_number'];
            $rep->TextCol(0, 3, $accountName);
            $rep->TextCol(3, 5, _('Opening Balance'));
            if ($processedData['opening_balance'] > 0.0) {
                $rep->AmountCol(5, 6, abs($processedData['opening_balance']), $dec);
            } else {
                $rep->AmountCol(6, 7, abs($processedData['opening_balance']), $dec);
            }
            $rep->Font();
            $rep->NewLine(2);
            
            // Transactions
            foreach ($processedData['transactions'] as $trans) {
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
                
                // Reconciliation date
                if ($trans['reconciled'] && $trans['reconciled'] != '0000-00-00') {
                    $rep->DateCol(8, 9, $trans['reconciled'], true);
                }
                
                // Narration/memo
                $rep->TextCol(9, 10, $trans['memo']);
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
            if ($processedData['closing_balance'] > 0.0) {
                $rep->AmountCol(5, 6, abs($processedData['closing_balance']), $dec);
            } else {
                $rep->AmountCol(6, 7, abs($processedData['closing_balance']), $dec);
            }
            $rep->Font();
            $rep->Line($rep->row - 8);
            $rep->NewLine();
        }
        
        $this->dispatchEvent('after_render', [
            'report' => $rep,
            'config' => $config,
            'data' => $processedData
        ]);
    }
}
