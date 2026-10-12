<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntity;
use App\Models\Transaction;
use App\Models\TransactionDetailInvestment;
use App\Models\TransactionDetailStandard;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

class PortfolioCashflowService
{
    /**
     * @param array<int, int> $accountEntityIds
     * @return array<int, array{
     *     transaction_id: int,
     *     date: string,
     *     kind: 'contribution'|'withdrawal',
     *     source_type: 'deposit'|'withdrawal'|'transfer',
     *     account_id: int,
     *     amount: string,
     *     currency: string
     * }>
     */
    public function externalCashflows(
        User $user,
        array $accountEntityIds,
        Carbon $fromDate,
        Carbon $toDate,
    ): array {
        $accountEntityIds = array_values(array_unique($accountEntityIds));
        if ($accountEntityIds === []) {
            return [];
        }

        $accounts = $this->getOwnedAccounts($user, $accountEntityIds);
        $accountCurrencies = [];
        foreach ($accounts as $account) {
            $accountConfig = $account->config;
            if (! $accountConfig instanceof Account) {
                throw new ModelNotFoundException('A selected account has no account configuration.');
            }

            $currency = $accountConfig->currency;
            if ($currency->user_id !== $user->id) {
                throw new ModelNotFoundException('A selected account uses a currency owned by another user.');
            }
            $accountCurrencies[$account->id] = $currency;
        }

        $transactions = $this->standardTransactions(
            $user,
            $accountEntityIds,
            $fromDate,
            $toDate,
        );
        $cashflows = [];

        foreach ($transactions as $transaction) {
            $detail = $transaction->config;
            if (! $detail instanceof TransactionDetailStandard) {
                continue;
            }

            $fromAccountId = $detail->account_from_id;
            $toAccountId = $detail->account_to_id;
            $fromIsSelected = $fromAccountId !== null && in_array($fromAccountId, $accountEntityIds, true);
            $toIsSelected = $toAccountId !== null && in_array($toAccountId, $accountEntityIds, true);

            $flow = match ($transaction->transaction_type) {
                TransactionType::DEPOSIT => $toIsSelected
                    ? [$toAccountId, $detail->amount_from, 'contribution']
                    : null,
                TransactionType::WITHDRAWAL => $fromIsSelected
                    ? [$fromAccountId, $detail->amount_from, 'withdrawal']
                    : null,
                TransactionType::TRANSFER => $fromIsSelected === $toIsSelected
                    ? null
                    : ($fromIsSelected
                        ? [$fromAccountId, $detail->amount_from, 'withdrawal']
                        : [$toAccountId, $detail->amount_to, 'contribution']),
                default => null,
            };

            if ($flow === null) {
                continue;
            }

            [$accountId, $unsignedAmount, $kind] = $flow;
            if (! is_int($accountId)) {
                throw new LogicException('A portfolio cashflow is missing its account.');
            }
            if ($unsignedAmount->isNegative()) {
                throw new LogicException('A standard transaction cashflow amount cannot be negative.');
            }

            $currency = $accountCurrencies[$accountId];
            if ($unsignedAmount->getCurrency()->getCurrencyCode() !== $currency->iso_code) {
                throw new LogicException('A portfolio cashflow amount does not match its account currency.');
            }
            $amount = $kind === 'withdrawal' ? $unsignedAmount->negated() : $unsignedAmount;

            $cashflows[] = [
                'transaction_id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'kind' => $kind,
                'source_type' => $transaction->transaction_type->value,
                'account_id' => $accountId,
                'amount' => (string) $amount->getAmount(),
                'currency' => $amount->getCurrency()->getCurrencyCode(),
            ];
        }

        return $cashflows;
    }

    /**
     * Share additions and removals do not record whether they represent a bonus, split,
     * in-kind transfer, or correction. Return them as unresolved candidates rather than
     * inferring an action or treating the quantity change as an external cashflow.
     *
     * @param array<int, int> $accountEntityIds
     * @return array<int, array{
     *     transaction_id: int,
     *     date: string,
     *     account_id: int,
     *     investment_id: int,
     *     transaction_type: 'add_shares'|'remove_shares',
     *     quantity: string|null,
     *     classification: 'unclassified'
     * }>
     */
    public function unclassifiedShareAdjustments(
        User $user,
        array $accountEntityIds,
        Carbon $fromDate,
        Carbon $toDate,
    ): array {
        $accountEntityIds = array_values(array_unique($accountEntityIds));
        if ($accountEntityIds === []) {
            return [];
        }

        $this->getOwnedAccounts($user, $accountEntityIds);

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->where('config_type', 'investment')
            ->where('schedule', false)
            ->whereNotNull('date')
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->whereIn('transaction_type', [
                TransactionType::ADD_SHARES->value,
                TransactionType::REMOVE_SHARES->value,
            ])
            ->whereHasMorph(
                'config',
                [TransactionDetailInvestment::class],
                fn (Builder $query) => $query->whereIn('account_id', $accountEntityIds),
            )
            ->with(['config.account', 'config.investment'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return $transactions
            ->map(function (Transaction $transaction) use ($user): array {
                $detail = $transaction->config;
                if (! $detail instanceof TransactionDetailInvestment) {
                    throw new LogicException('A share adjustment transaction has an invalid detail type.');
                }
                if ($detail->account->user_id !== $user->id || $detail->investment->user_id !== $user->id) {
                    throw new ModelNotFoundException('A share adjustment references data outside the authenticated user scope.');
                }

                return [
                    'transaction_id' => $transaction->id,
                    'date' => $transaction->date->toDateString(),
                    'account_id' => $detail->account_id,
                    'investment_id' => $detail->investment_id,
                    'transaction_type' => $transaction->transaction_type->value,
                    'quantity' => $detail->quantity === null
                        ? null
                        : (string) $detail->quantity->multipliedBy(
                            (string) $transaction->transaction_type->quantityMultiplier(),
                        ),
                    'classification' => 'unclassified',
                ];
            })
            ->all();
    }

    /**
     * @param array<int, int> $accountEntityIds
     * @return \Illuminate\Database\Eloquent\Collection<int, AccountEntity>
     */
    private function getOwnedAccounts(User $user, array $accountEntityIds): \Illuminate\Database\Eloquent\Collection
    {
        $accounts = AccountEntity::query()
            ->where('user_id', $user->id)
            ->where('config_type', 'account')
            ->whereIn('id', $accountEntityIds)
            ->with('config.currency')
            ->get();

        if ($accounts->count() !== count($accountEntityIds)) {
            throw new ModelNotFoundException('One or more selected accounts do not belong to this user.');
        }
        return $accounts;
    }

    /**
     * @param array<int, int> $accountEntityIds
     * @return \Illuminate\Database\Eloquent\Collection<int, Transaction>
     */
    private function standardTransactions(
        User $user,
        array $accountEntityIds,
        Carbon $fromDate,
        Carbon $toDate,
    ): \Illuminate\Database\Eloquent\Collection {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('config_type', 'standard')
            ->where('schedule', false)
            ->whereNotNull('date')
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->whereHasMorph(
                'config',
                [TransactionDetailStandard::class],
                fn (Builder $query) => $query->where(function (Builder $accountQuery) use ($accountEntityIds): void {
                    $accountQuery->whereIn('account_from_id', $accountEntityIds)
                        ->orWhereIn('account_to_id', $accountEntityIds);
                }),
            )
            ->with(['config.accountFrom.config', 'config.accountTo.config'])
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->loadMorph('config.accountFrom.config', [
                Account::class => ['currency'],
            ])
            ->loadMorph('config.accountTo.config', [
                Account::class => ['currency'],
            ]);
    }
}
