<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionDetailInvestment;
use App\Models\TransactionDetailStandard;
use App\Models\TransactionType;
use App\Models\AccountEntity;
use Illuminate\Support\Facades\Log;
use App\Services\TransactionService;

class AutoInvestmentService
{
    /**
     * Create investment Buy/Sell transactions for a standard transaction when the related
     * account entities have `default_investment_id` configured.
     */
    public function handleTransaction(Transaction $transaction): void
    {
        // Only operate on standard transactions
        if (! $transaction->isStandard()) {
            return;
        }

        // Skip scheduled/budget transactions
        if ($transaction->schedule || $transaction->budget) {
            return;
        }

        // Ensure relations are loaded
        $transaction->loadDetails();

        $typeName = $transaction->transactionType->name ?? null;

        // Deposit -> buy on accountTo
        if ($typeName === 'deposit') {
            $this->maybeCreateInvestmentForAccount($transaction, $transaction->config->accountTo, $transaction->config->amount_to, 'Buy');
            return;
        }

        // Withdrawal -> sell on accountFrom
        if ($typeName === 'withdrawal') {
            $this->maybeCreateInvestmentForAccount($transaction, $transaction->config->accountFrom, $transaction->config->amount_from, 'Sell');
            return;
        }

        // Transfer -> sell on from, buy on to
        if ($typeName === 'transfer') {
            $this->maybeCreateInvestmentForAccount($transaction, $transaction->config->accountFrom, $transaction->config->amount_from, 'Sell');
            $this->maybeCreateInvestmentForAccount($transaction, $transaction->config->accountTo, $transaction->config->amount_to, 'Buy');
            return;
        }
    }

    protected function maybeCreateInvestmentForAccount(Transaction $transaction, ?AccountEntity $accountEntity, ?float $amount, string $action): void
    {
        if (! $accountEntity || ! $amount || $amount == 0) {
            return;
        }

        // If the account entity has no default investment, skip
        if (! $accountEntity->default_investment_id) {
            return;
        }

        // Ensure the investment exists, is active and belongs to a group with auto_invest enabled
        $investment = \App\Models\Investment::find($accountEntity->default_investment_id);
        if (! $investment || ! $investment->active || ! ($investment->investmentGroup && $investment->investmentGroup->auto_invest)) {
            return;
        }

        // Find the transaction type for the action
        $txType = TransactionType::where('name', $action)->first();
        if (! $txType) {
            Log::warning("AutoInvestment: transaction type {$action} not found");
            return;
        }

        // Avoid creating duplicate investment transactions: check for existing transaction on same date
        $existing = Transaction::where('date', $transaction->date)
            ->where('transaction_type_id', $txType->id)
            ->where('config_type', 'investment')
            ->whereIn('config_id', function ($query) use ($accountEntity) {
                $query->select('id')->from('transaction_details_investment')
                    ->where('account_id', $accountEntity->config->id)
                    ->where('investment_id', $accountEntity->default_investment_id);
            })->exists();

        if ($existing) {
            return;
        }

        // Create investment detail and transaction
        $detail = TransactionDetailInvestment::create([
            'account_id' => $accountEntity->config->id,
            'investment_id' => $accountEntity->default_investment_id,
            'price' => 1,
            'quantity' => $amount,
            'commission' => null,
            'tax' => null,
            'dividend' => null,
        ]);

        // Determine cashflow: Buy -> negative outflow, Sell -> positive inflow
        $cashflow = ($action === 'Buy') ? -1 * 1 * $amount : 1 * 1 * $amount;

        $newTx = new Transaction([
            'user_id' => $transaction->user_id,
            'date' => $transaction->date,
            'transaction_type_id' => $txType->id,
            'config_type' => 'investment',
            'config_id' => $detail->id,
            'schedule' => $transaction->schedule,
            'budget' => $transaction->budget,
            'reconciled' => $transaction->reconciled,
            'comment' => $transaction->comment ? $transaction->comment . " ({$action})" : $action . ' (Auto)',
            'currency_id' => $accountEntity->config->currency_id,
            'cashflow_value' => $cashflow,
        ]);

        $newTx->saveQuietly();

        // Recalculate summaries
        $service = new TransactionService();
        $service->recalculateMonthlySummaries($newTx);
    }
}
