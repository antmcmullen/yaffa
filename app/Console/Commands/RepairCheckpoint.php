<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Models\AccountBalanceCheckpoint;
use App\Models\AccountEntity;
use App\Services\BalanceCheckpointService;
use Carbon\Carbon;

class RepairCheckpoint extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'yaffa:repair-checkpoint {transactionId} {--fix : Apply repair (create new checkpoint and deactivate old)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect and optionally repair balance checkpoint(s) affected by a transaction';

    public function handle(): int
    {
        $transactionId = (int) $this->argument('transactionId');

        $transaction = Transaction::with('config')->find($transactionId);

        if (! $transaction) {
            $this->error("Transaction {$transactionId} not found");
            return 1;
        }

        $this->info("Inspecting transaction {$transaction->id} (date: {$transaction->date})");

        // Determine affected account ids similar to BalanceCheckpointService::getAffectedAccounts
        $accountIds = [];
        $config = $transaction->config;

        if ($transaction->isStandard()) {
            if ($config && isset($config->account_from_id)) {
                $accountFrom = AccountEntity::find($config->account_from_id);
                if ($accountFrom && $accountFrom->isAccount()) {
                    $accountIds[] = $config->account_from_id;
                }
            }

            if ($config && isset($config->account_to_id)) {
                $accountTo = AccountEntity::find($config->account_to_id);
                if ($accountTo && $accountTo->isAccount()) {
                    $accountIds[] = $config->account_to_id;
                }
            }
        } elseif ($transaction->isInvestment()) {
            if ($config && isset($config->account_id)) {
                $accountIds[] = $config->account_id;
            }
        }

        $accountIds = array_unique($accountIds);

        if (empty($accountIds)) {
            $this->info('No affected accounts detected for this transaction');
            return 0;
        }

        $svc = new BalanceCheckpointService();

        foreach ($accountIds as $accountId) {
            $this->line("\nAccount: {$accountId}");

            $checkpoint = AccountBalanceCheckpoint::active()
                ->forAccount($accountId)
                ->where('checkpoint_date', '<=', $transaction->date)
                ->orderBy('checkpoint_date', 'desc')
                ->first();

            if (! $checkpoint) {
                $this->line('  No checkpoint found on or before the transaction date');
                continue;
            }

            $this->line('  Checkpoint id: '.$checkpoint->id.' date: '.$checkpoint->checkpoint_date->format('Y-m-d').' balance: '.$checkpoint->balance);

            $currentBalance = $svc->calculateBalanceAtDate($accountId, $checkpoint->checkpoint_date, null);
            $balanceWithoutTx = $svc->calculateBalanceAtDate($accountId, $checkpoint->checkpoint_date, $transaction->id);
            $withTx = $svc->calculateBalanceWithTransaction($accountId, $checkpoint->checkpoint_date, $transaction, false);

            $this->line('  Computed current balance (including all transactions): '.$currentBalance);
            $this->line('  Computed balance without this transaction: '.$balanceWithoutTx);
            $this->line('  Computed balance with this transaction: '.$withTx);

            $diff = abs($currentBalance - $checkpoint->balance);

            if ($diff < 0.01) {
                $this->info('  Checkpoint currently MATCHES computed balance (difference '.$diff.')');
                $this->info('  No automatic repair needed for this checkpoint');
                continue;
            }

            $this->warn('  Checkpoint MISMATCH detected (difference '.$diff.')');

            if (! $this->option('fix')) {
                $this->line('  Run with --fix to create a corrected checkpoint and deactivate the old one');
                continue;
            }

            // Create a new checkpoint with the computed balance
            $newCheckpoint = $svc->createCheckpoint($checkpoint->user_id, $checkpoint->account_entity_id, Carbon::instance($checkpoint->checkpoint_date), $currentBalance, 'Auto-repaired by yaffa:repair-checkpoint');

            // Deactivate old checkpoint
            $checkpoint->update(['active' => false]);

            $this->info('  Created new checkpoint id '.$newCheckpoint->id.' with balance '.$currentBalance.' and deactivated old checkpoint '.$checkpoint->id);
        }

        return 0;
    }
}
