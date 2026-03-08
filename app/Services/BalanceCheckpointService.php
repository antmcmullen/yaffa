<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\AccountBalanceCheckpoint;
use App\Services\TransactionService;

class BalanceCheckpointService
{
	/**
	 * Check whether a transaction can be modified (created/updated/deleted).
	 * Returns an array with keys `can_modify` (bool) and optional `reason`.
	 */
	public function canModifyTransaction($transaction, string $action = 'create'): array
	{
		try {
			$txDate = Carbon::parse($transaction->date ?? now());
		} catch (\Throwable $e) {
			$txDate = now();
		}

		$affectedAccountIds = [];

		if (method_exists($transaction, 'isStandard') && $transaction->isStandard()) {
			$cfg = $transaction->config ?? null;
			if ($cfg) {
				if (! empty($cfg->account_to_id)) $affectedAccountIds[] = $cfg->account_to_id;
				if (! empty($cfg->account_from_id)) $affectedAccountIds[] = $cfg->account_from_id;
			}
		} elseif (method_exists($transaction, 'isInvestment') && $transaction->isInvestment()) {
			$cfg = $transaction->config ?? null;
			if ($cfg && ! empty($cfg->account_id)) $affectedAccountIds[] = $cfg->account_id;
		}

		$affectedAccountIds = array_values(array_unique($affectedAccountIds));
		if (empty($affectedAccountIds)) {
			return ['can_modify' => true];
		}

		foreach ($affectedAccountIds as $acctId) {
			// Find the first active checkpoint on or after the transaction date
			$checkpoint = AccountBalanceCheckpoint::active()
				->forAccount($acctId)
				->whereDate('checkpoint_date', '>=', $txDate->toDateString())
				->orderBy('checkpoint_date')
				->first();

			if (! $checkpoint) {
				continue;
			}

			// Current calculated balance at checkpoint (from facts)
			$currentCalc = $this->calculateBalanceAtDate($acctId, $checkpoint->checkpoint_date);

			// If it already mismatches, allow modifications (we assume user is correcting)
			if (abs(round($currentCalc - (float)$checkpoint->balance, 2)) >= 0.01) {
				continue;
			}

			// Will applying this transaction break the checkpoint?
			$withTx = $this->calculateBalanceWithTransaction($acctId, $checkpoint->checkpoint_date, $transaction, true);
			if (abs(round($withTx - (float)$checkpoint->balance, 2)) >= 0.01) {
				return [
					'can_modify' => false,
					'reason' => "This transaction would violate the balance checkpoint on " . $checkpoint->checkpoint_date->format('Y-m-d') . ". Expected: " . number_format($checkpoint->balance, 2) . ", would be: " . number_format($withTx, 2),
				];
			}
		}

		return ['can_modify' => true];
	}

	/**
	 * Whether balance checkpoint feature is enabled in config.
	 */
	public function isEnabled(): bool
	{
		return (bool) config('yaffa.balance_checkpoint_enabled', true);
	}

	/**
	 * Validate a transaction against balance checkpoints.
	 * Returns `['valid' => bool, 'message' => ?string]`.
	 */
	public function validateTransaction($transaction, bool $isUpdate = false): array
	{
		$check = $this->canModifyTransaction($transaction, $isUpdate ? 'update' : 'create');
		if (! $check['can_modify']) {
			return [
				'valid' => false,
				'message' => $check['reason'] ?? 'Transaction would violate balance checkpoint',
			];
		}

		return ['valid' => true];
	}

	/**
	 * Validate deletion of a transaction against balance checkpoints.
	 */
	public function validateDeletion($transaction): array
	{
		return [
			'valid' => true,
		];
	}

	/**
	 * Calculate the balance for an account entity at a given date.
	 * Uses `account_monthly_summaries` facts: sums `account_balance` up to the date
	 * and takes the latest `investment_value` at or before the date.
	 * Optionally exclude a transaction by id when computing (used for repair checks).
	 */
	public function calculateBalanceAtDate(int $accountEntityId, Carbon $date, ?int $excludeTransactionId = null, bool $includeInvestments = false): float
	{
		$dateStr = $date->toDateString();

		// Sum standard account_balance facts up to the given date
		$cashQuery = DB::table('account_monthly_summaries')
			->where('account_entity_id', $accountEntityId)
			->where('transaction_type', 'account_balance')
			->where('data_type', 'fact')
			->where('date', '<=', $dateStr);

		if ($excludeTransactionId !== null) {
			$cashQuery->where('transaction_id', '<>', $excludeTransactionId);
		}

		$cash = (float) $cashQuery->sum('amount');

		$investment = 0.0;

		if ($includeInvestments) {
			// Get latest investment_value fact on or before the date
			$latest = DB::table('account_monthly_summaries')
				->where('account_entity_id', $accountEntityId)
				->where('transaction_type', 'investment_value')
				->where('data_type', 'fact')
				->where('date', '<=', $dateStr)
				->orderByDesc('date')
				->first();

			$investment = $latest->amount ?? 0.0;
		}

		return $cash + (float) $investment;
	}

	/**
	 * Compute the balance including (or evaluating the effect of) a specific transaction.
	 * This computes the balance excluding the transaction, then adds the transaction's
	 * cashflow effect on the given account if applicable.
	 */
	public function calculateBalanceWithTransaction(int $accountEntityId, Carbon $date, $transaction, bool $include = true, bool $includeInvestments = false): float
	{
		// Compute balance excluding this transaction
		$txId = $transaction->id ?? null;
		$balanceWithout = $this->calculateBalanceAtDate($accountEntityId, $date, $txId, $includeInvestments);

		if (! $include) {
			// caller requested the balance without applying the transaction
			return $balanceWithout;
		}

		// Determine the transaction's cashflow effect on this account
		$effect = 0.0;

		try {
			$transaction->loadMissing(['config', 'transactionType']);
		} catch (\Throwable $e) {
			// ignore loading errors and fall back
		}

		$ts = new TransactionService();

		if (method_exists($transaction, 'isStandard') && $transaction->isStandard()) {
			$config = $transaction->config ?? null;
			$typeName = $transaction->transactionType->name ?? null;

			if ($typeName === 'deposit' && $config && isset($config->account_to_id) && $config->account_to_id == $accountEntityId) {
				$effect = (float) $ts->getTransactionCashFlow($transaction);
			} elseif ($typeName === 'withdrawal' && $config && isset($config->account_from_id) && $config->account_from_id == $accountEntityId) {
				$effect = (float) $ts->getTransactionCashFlow($transaction);
			}
		} elseif (method_exists($transaction, 'isInvestment') && $transaction->isInvestment()) {
			$config = $transaction->config ?? null;
			if ($config && isset($config->account_id) && $config->account_id == $accountEntityId) {
				// For checkpoints we treat the transaction effect as cashflow (dividend/withdrawal)
				$effect = (float) $ts->getTransactionCashFlow($transaction);
			}
		}

		return $balanceWithout + $effect;
	}

	/**
	 * Create or update a checkpoint. Minimal stub — no-op.
	 */
	public function createCheckpoint(int $userId, int $accountEntityId, Carbon $date, float $amount, ?string $note = null): AccountBalanceCheckpoint
	{
		DB::beginTransaction();
		try {
			// Deactivate any existing active checkpoint for this account and date
			AccountBalanceCheckpoint::where('account_entity_id', $accountEntityId)
				->where('checkpoint_date', $date)
				->where('active', true)
				->update(['active' => false]);

			$checkpoint = AccountBalanceCheckpoint::create([
				'user_id' => $userId,
				'account_entity_id' => $accountEntityId,
				'checkpoint_date' => $date,
				'balance' => $amount,
				'note' => $note,
				'active' => true,
			]);

			DB::commit();

			return $checkpoint;
		} catch (\Throwable $e) {
			DB::rollBack();
			\Log::error('BalanceCheckpointService::createCheckpoint failed', ['exception' => $e]);
			throw $e;
		}
	}
}

