<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntity;
use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\TransactionDetailInvestment;
use App\Models\TransactionDetailStandard;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

class PortfolioValuationService
{
    public function __construct(
        private readonly InvestmentService $investmentService,
        private readonly TransactionService $transactionService,
    ) {
    }

    /**
     * Value a portfolio at each explicitly requested date.
     *
     * Dates are deliberately caller-supplied: this service does not impose a reporting
     * cadence or persist snapshots. Account IDs and the output currency must belong to the user.
     *
     * @param array<int, int> $accountEntityIds
     * @param array<int, Carbon> $dates
     * @return array<int, array{
     *     date: string,
     *     currency: string,
     *     known_value: string,
     *     total_value: string|null,
     *     status: 'complete'|'incomplete',
     *     missing_inputs: array<int, array{account_id: int, investment_id?: int, kind: string}>,
     *     components: array<int, array<string, mixed>>
     * }>
     */
    public function series(User $user, array $accountEntityIds, Currency $currency, array $dates): array
    {
        $accountEntityIds = array_values(array_unique($accountEntityIds));
        $accounts = $this->getOwnedAccounts($user, $accountEntityIds);
        $outputCurrency = Currency::query()
            ->whereKey($currency->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        return array_map(
            fn (Carbon $date): array => $this->valueAt($user, $accounts, $outputCurrency, $date),
            $dates,
        );
    }

    /**
     * @param EloquentCollection<int, AccountEntity> $accounts
     * @return array<string, mixed>
     */
    private function valueAt(User $user, EloquentCollection $accounts, Currency $outputCurrency, Carbon $date): array
    {
        $asOfDate = $date->copy()->startOfDay();
        $accountIds = $accounts->modelKeys();
        $cashBalances = [];
        $accountCurrencies = [];
        $components = [];
        $missingInputs = [];
        $knownValue = BigDecimal::zero();
        $fxCache = [];
        $resolveFx = function (Currency $from) use (&$fxCache, $user, $outputCurrency, $asOfDate): ?array {
            $key = $from->id . '|' . $outputCurrency->id;
            if (! array_key_exists($key, $fxCache)) {
                $fxCache[$key] = $this->resolveFxRate($user, $from, $outputCurrency, $asOfDate);
            }

            return $fxCache[$key];
        };

        foreach ($accounts as $account) {
            $accountConfig = $account->config;
            if (! $accountConfig instanceof Account) {
                throw new ModelNotFoundException('A selected account has no account configuration.');
            }

            $accountCurrency = $accountConfig->currency;
            if ($accountCurrency->user_id !== $user->id) {
                throw new ModelNotFoundException('A selected account uses a currency owned by another user.');
            }
            $accountCurrencies[$account->id] = $accountCurrency;
            $cashBalances[$account->id] = $accountConfig->opening_balance->getAmount();
        }

        $standardTransactions = Transaction::query()
            ->where('user_id', $user->id)
            ->where('config_type', 'standard')
            ->where('schedule', false)
            ->whereNotNull('date')
            ->whereDate('date', '<=', $asOfDate)
            ->whereHasMorph(
                'config',
                [TransactionDetailStandard::class],
                fn (Builder $query) => $query->where(function (Builder $accountQuery) use ($accountIds): void {
                    $accountQuery->whereIn('account_from_id', $accountIds)
                        ->orWhereIn('account_to_id', $accountIds);
                }),
            )
            ->with(['config.accountFrom.config', 'config.accountTo.config'])
            ->get()
            ->loadMorph('config.accountFrom.config', [
                Account::class => ['currency'],
            ])
            ->loadMorph('config.accountTo.config', [
                Account::class => ['currency'],
            ]);

        foreach ($standardTransactions as $transaction) {
            $this->applyStandardCashMovement($transaction, $accountIds, $cashBalances);
        }

        $investmentTransactions = Transaction::query()
            ->where('user_id', $user->id)
            ->where('config_type', 'investment')
            ->where('schedule', false)
            ->whereNotNull('date')
            ->whereDate('date', '<=', $asOfDate)
            ->whereHasMorph(
                'config',
                [TransactionDetailInvestment::class],
                fn (Builder $query) => $query->whereIn('account_id', $accountIds),
            )
            ->with(['config.account.config.currency', 'config.investment.currency'])
            ->get();

        $positionQuantities = [];

        foreach ($investmentTransactions as $transaction) {
            $detail = $transaction->config;
            if (! $detail instanceof TransactionDetailInvestment) {
                continue;
            }

            $investment = $detail->investment;
            if ($investment->user_id !== $user->id) {
                throw new ModelNotFoundException('A selected account references an investment owned by another user.');
            }
            $investmentCurrency = $investment->currency;
            if ($investmentCurrency->user_id !== $user->id) {
                throw new ModelNotFoundException('A selected account references an investment currency owned by another user.');
            }

            $accountId = $detail->account_id;
            $accountCurrency = $accountCurrencies[$accountId];
            $cashFlow = $this->transactionService->getTransactionCashFlow($transaction);

            if ($cashFlow instanceof Money) {
                if ($cashFlow->getCurrency()->getCurrencyCode() !== $accountCurrency->iso_code) {
                    $missingInputs[] = ['account_id' => $accountId, 'kind' => 'cashflow'];
                } else {
                    $cashBalances[$accountId] = $cashBalances[$accountId]->plus($cashFlow->getAmount());
                }
            } elseif ($this->hasCashflowTerms($transaction, $detail)) {
                $missingInputs[] = ['account_id' => $accountId, 'kind' => 'cashflow'];
            }

            $multiplier = $transaction->transaction_type->quantityMultiplier();
            if ($multiplier === null || $detail->quantity === null) {
                continue;
            }

            $key = $accountId . '|' . $investment->id;
            $positionQuantities[$key] ??= [
                'account_id' => $accountId,
                'investment' => $investment,
                'quantity' => BigDecimal::zero(),
            ];
            $positionQuantities[$key]['quantity'] = $positionQuantities[$key]['quantity']
                ->plus($detail->quantity->multipliedBy((string) $multiplier));
        }

        $priceRequests = collect($positionQuantities)
            ->filter(fn (array $position): bool => ! $position['quantity']->isZero())
            ->map(fn (array $position): array => [
                'investment' => $position['investment'],
                'date' => $asOfDate,
            ]);
        $priceEvidenceByInvestmentDate = $this->investmentService->getLatestPricesEvidenceBatch($priceRequests);

        foreach ($accounts as $account) {
            $cashAmount = $cashBalances[$account->id];
            if ($cashAmount->isZero()) {
                $components[] = [
                    'kind' => 'cash',
                    'account_id' => $account->id,
                    'amount' => $this->formatAmount($cashAmount),
                    'currency' => $accountCurrencies[$account->id]->iso_code,
                    'value' => $this->formatAmount(BigDecimal::zero()),
                    'fx' => null,
                ];
                continue;
            }

            $fx = $resolveFx($accountCurrencies[$account->id]);

            if ($fx === null) {
                $missingInputs[] = ['account_id' => $account->id, 'kind' => 'fx'];
                $components[] = [
                    'kind' => 'cash',
                    'account_id' => $account->id,
                    'amount' => $this->formatAmount($cashAmount),
                    'currency' => $accountCurrencies[$account->id]->iso_code,
                    'value' => null,
                    'fx' => null,
                ];
                continue;
            }

            $value = $cashAmount->multipliedBy($fx['rate']);
            $knownValue = $knownValue->plus($value);
            $components[] = [
                'kind' => 'cash',
                'account_id' => $account->id,
                'amount' => $this->formatAmount($cashAmount),
                'currency' => $accountCurrencies[$account->id]->iso_code,
                'value' => $this->formatAmount($value),
                'fx' => $this->serializeFxEvidence($fx),
            ];
        }

        foreach ($positionQuantities as $position) {
            /** @var Investment $investment */
            $investment = $position['investment'];
            /** @var BigDecimal $quantity */
            $quantity = $position['quantity'];
            $investmentCurrency = $investment->currency;

            if ($quantity->isZero()) {
                $components[] = [
                    'kind' => 'security',
                    'account_id' => $position['account_id'],
                    'investment_id' => $investment->id,
                    'quantity' => (string) $quantity,
                    'price' => null,
                    'value' => $this->formatAmount(BigDecimal::zero()),
                    'price_source' => null,
                    'fx' => null,
                ];
                continue;
            }

            $priceEvidence = $priceEvidenceByInvestmentDate[
                $this->investmentService->priceBatchKey($investment->id, $asOfDate)
            ] ?? null;
            if ($priceEvidence === null) {
                $missingInputs[] = [
                    'account_id' => $position['account_id'],
                    'investment_id' => $investment->id,
                    'kind' => 'price',
                ];
                $components[] = [
                    'kind' => 'security',
                    'account_id' => $position['account_id'],
                    'investment_id' => $investment->id,
                    'quantity' => (string) $quantity,
                    'price' => null,
                    'value' => null,
                    'price_source' => null,
                    'fx' => null,
                ];
                continue;
            }

            $fx = $resolveFx($investmentCurrency);
            if ($fx === null) {
                $missingInputs[] = [
                    'account_id' => $position['account_id'],
                    'investment_id' => $investment->id,
                    'kind' => 'fx',
                ];
                $components[] = [
                    'kind' => 'security',
                    'account_id' => $position['account_id'],
                    'investment_id' => $investment->id,
                    'quantity' => (string) $quantity,
                    'price' => (string) $priceEvidence['price'],
                    'value' => null,
                    'price_source' => $this->priceSource($priceEvidence),
                    'fx' => null,
                ];
                continue;
            }

            $value = $quantity
                ->multipliedBy($priceEvidence['price'])
                ->multipliedBy($fx['rate']);
            $knownValue = $knownValue->plus($value);
            $components[] = [
                'kind' => 'security',
                'account_id' => $position['account_id'],
                'investment_id' => $investment->id,
                'quantity' => (string) $quantity,
                'price' => (string) $priceEvidence['price'],
                'value' => $this->formatAmount($value),
                'price_source' => $this->priceSource($priceEvidence),
                'fx' => $this->serializeFxEvidence($fx),
            ];
        }

        $missingInputs = collect($missingInputs)
            ->unique(fn (array $input): string => implode('|', [
                $input['account_id'],
                $input['investment_id'] ?? '',
                $input['kind'],
            ]))
            ->values()
            ->all();
        $isComplete = $missingInputs === [];
        $formattedKnownValue = $this->formatAmount($knownValue);

        return [
            'date' => $asOfDate->toDateString(),
            'currency' => $outputCurrency->iso_code,
            'known_value' => $formattedKnownValue,
            'total_value' => $isComplete ? $formattedKnownValue : null,
            'status' => $isComplete ? 'complete' : 'incomplete',
            'missing_inputs' => $missingInputs,
            'components' => $components,
        ];
    }

    /**
     * @param array<int, int> $accountIds
     * @param array<int, BigDecimal> $cashBalances
     */
    private function applyStandardCashMovement(Transaction $transaction, array $accountIds, array &$cashBalances): void
    {
        $detail = $transaction->config;
        if (! $detail instanceof TransactionDetailStandard) {
            return;
        }

        $type = $transaction->transaction_type;

        if ($type === TransactionType::DEPOSIT
            && $detail->account_to_id !== null
            && in_array($detail->account_to_id, $accountIds, true)) {
            $cashBalances[$detail->account_to_id] = $cashBalances[$detail->account_to_id]
                ->plus($detail->amount_to->getAmount());
        } elseif ($type === TransactionType::WITHDRAWAL
            && $detail->account_from_id !== null
            && in_array($detail->account_from_id, $accountIds, true)) {
            $cashBalances[$detail->account_from_id] = $cashBalances[$detail->account_from_id]
                ->minus($detail->amount_from->getAmount());
        } elseif ($type === TransactionType::TRANSFER) {
            if ($detail->account_from_id !== null && in_array($detail->account_from_id, $accountIds, true)) {
                $cashBalances[$detail->account_from_id] = $cashBalances[$detail->account_from_id]
                    ->minus($detail->amount_from->getAmount());
            }
            if ($detail->account_to_id !== null && in_array($detail->account_to_id, $accountIds, true)) {
                $cashBalances[$detail->account_to_id] = $cashBalances[$detail->account_to_id]
                    ->plus($detail->amount_to->getAmount());
            }
        }
    }

    private function hasCashflowTerms(Transaction $transaction, TransactionDetailInvestment $detail): bool
    {
        return ($transaction->transaction_type->quantityMultiplier() !== null
                && $transaction->transaction_type->amountMultiplier() !== null)
            || $detail->dividend !== null
            || $detail->tax !== null
            || $detail->commission !== null;
    }

    /**
     * @return array{rate: BigDecimal, method: string, rates: array<int, array{id: int, from_currency_id: int, to_currency_id: int, date: string, rate: string}>}|null
     */
    private function resolveFxRate(User $user, Currency $from, Currency $to, Carbon $date): ?array
    {
        if ($from->id === $to->id) {
            return $this->identityRate($from, $to);
        }

        $direct = $this->latestRate($from->id, $to->id, $date);
        if ($direct !== null) {
            if ($direct->rate->isLessThanOrEqualTo(BigDecimal::zero())) {
                return null;
            }

            return [
                'rate' => $direct->rate,
                'method' => 'direct',
                'rates' => [$this->rateEvidence($direct)],
            ];
        }

        $inverse = $this->latestRate($to->id, $from->id, $date);
        if ($inverse !== null) {
            if ($inverse->rate->isLessThanOrEqualTo(BigDecimal::zero())) {
                return null;
            }

            return [
                'rate' => BigDecimal::one()->dividedBy($inverse->rate, 20, RoundingMode::HalfUp),
                'method' => 'inverse',
                'rates' => [$this->rateEvidence($inverse)],
            ];
        }

        $baseCurrency = $user->baseCurrency();
        if ($baseCurrency === null) {
            return null;
        }

        $fromBaseRate = $from->id === $baseCurrency->id
            ? null
            : $this->latestRate($from->id, $baseCurrency->id, $date);
        $toBaseRate = $to->id === $baseCurrency->id
            ? null
            : $this->latestRate($to->id, $baseCurrency->id, $date);

        if (($from->id !== $baseCurrency->id && $fromBaseRate === null)
            || ($to->id !== $baseCurrency->id && $toBaseRate === null)) {
            return null;
        }

        $fromRate = $fromBaseRate?->rate ?? BigDecimal::one();
        $toRate = $toBaseRate?->rate ?? BigDecimal::one();
        if ($fromRate->isLessThanOrEqualTo(BigDecimal::zero())
            || $toRate->isLessThanOrEqualTo(BigDecimal::zero())) {
            return null;
        }
        $rates = array_values(array_filter([
            $fromBaseRate === null ? null : $this->rateEvidence($fromBaseRate),
            $toBaseRate === null ? null : $this->rateEvidence($toBaseRate),
        ]));

        return [
            'rate' => $fromRate->dividedBy($toRate, 20, RoundingMode::HalfUp),
            'method' => 'base_cross',
            'rates' => $rates,
        ];
    }

    private function latestRate(int $fromId, int $toId, Carbon $date): ?CurrencyRate
    {
        return CurrencyRate::query()
            ->where('from_id', $fromId)
            ->where('to_id', $toId)
            ->whereDate('date', '<=', $date)
            ->orderByDesc('date')
            ->first();
    }

    /**
     * @return array{rate: BigDecimal, method: string, rates: array<never, never>}
     */
    private function identityRate(Currency $from, Currency $to): array
    {
        if ($from->id !== $to->id) {
            throw new LogicException('An identity exchange rate requires matching currencies.');
        }

        return ['rate' => BigDecimal::one(), 'method' => 'identity', 'rates' => []];
    }

    /**
     * @return array{id: int, from_currency_id: int, to_currency_id: int, date: string, rate: string}
     */
    private function rateEvidence(CurrencyRate $rate): array
    {
        return [
            'id' => $rate->id,
            'from_currency_id' => $rate->from_id,
            'to_currency_id' => $rate->to_id,
            'date' => $rate->date->format('Y-m-d'),
            'rate' => (string) $rate->rate,
        ];
    }

    /**
     * @param array{rate: BigDecimal, method: string, rates: array<int, array{id: int, from_currency_id: int, to_currency_id: int, date: string, rate: string}>} $fx
     * @return array{rate: string, method: string, rates: array<int, array{id: int, from_currency_id: int, to_currency_id: int, date: string, rate: string}>}
     */
    private function serializeFxEvidence(array $fx): array
    {
        return [
            'rate' => (string) $fx['rate'],
            'method' => $fx['method'],
            'rates' => $fx['rates'],
        ];
    }

    /**
     * @param array{price: BigDecimal, source_kind: 'stored'|'transaction', source_id: int, date: string} $evidence
     * @return array{kind: string, id: int, date: string}
     */
    private function priceSource(array $evidence): array
    {
        return [
            'kind' => $evidence['source_kind'],
            'id' => $evidence['source_id'],
            'date' => $evidence['date'],
        ];
    }

    /**
     * @param array<int, int> $accountIds
     * @return EloquentCollection<int, AccountEntity>
     */
    private function getOwnedAccounts(User $user, array $accountIds): EloquentCollection
    {
        $accounts = AccountEntity::query()
            ->where('user_id', $user->id)
            ->accounts()
            ->whereIn('id', $accountIds)
            ->with('config.currency')
            ->get();

        if ($accounts->count() !== count($accountIds)) {
            throw new ModelNotFoundException('One or more selected accounts do not belong to this user.');
        }

        return $accounts;
    }

    private function formatAmount(BigDecimal $amount): string
    {
        return (string) $amount->toScale(4, RoundingMode::HalfUp);
    }
}
