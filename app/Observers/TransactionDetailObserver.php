<?php

namespace App\Observers;

use App\Services\BalanceCheckpointService;
use Illuminate\Validation\ValidationException;

class TransactionDetailObserver
{
    protected BalanceCheckpointService $balanceService;

    public function __construct()
    {
        $this->balanceService = new BalanceCheckpointService();
    }

    public function creating($detail): bool
    {
        // If the detail is being created before the transaction exists, skip.
        if (! $detail->relationLoaded('transaction')) {
            $detail->load('transaction');
        }

        if (! $detail->transaction) {
            return true;
        }

        // Validate using the transaction context
        $result = $this->balanceService->validateTransaction($detail->transaction, false);

        if (! $result['valid']) {
            throw ValidationException::withMessages([
                'transaction' => [$result['message'] ?? 'Transaction detail would violate balance checkpoint']
            ]);
        }

        return true;
    }

    public function updating($detail): bool
    {
        if (! $detail->relationLoaded('transaction')) {
            $detail->load('transaction');
        }

        if (! $detail->transaction) {
            return true;
        }

        $result = $this->balanceService->validateTransaction($detail->transaction, true);

        if (! $result['valid']) {
            throw ValidationException::withMessages([
                'transaction' => [$result['message'] ?? 'Transaction detail update would violate balance checkpoint']
            ]);
        }

        return true;
    }

    public function deleting($detail): bool
    {
        if (! $detail->relationLoaded('transaction')) {
            $detail->load('transaction');
        }

        if (! $detail->transaction) {
            return true;
        }

        $result = $this->balanceService->validateDeletion($detail->transaction);

        if (! $result['valid']) {
            throw ValidationException::withMessages([
                'transaction' => [$result['message'] ?? 'Deleting this detail would violate balance checkpoint']
            ]);
        }

        return true;
    }
}
