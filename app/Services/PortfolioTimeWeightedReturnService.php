<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountEntity;
use App\Models\Currency;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

class PortfolioTimeWeightedReturnService
{
    public function __construct(
        private readonly PortfolioCashflowService $portfolioCashflowService,
        private readonly PortfolioValuationService $portfolioValuationService,
    ) {
    }

    /**
     * @param array<int, int> $accountEntityIds
     * @param array<int, Carbon> $dates
     * @param array<int, array<string, mixed>> $reportValuations
     * @return array<int, array{
     *     date: string,
     *     period_start_date: string|null,
     *     period_return: string|null,
     *     cumulative_return: string|null,
     *     status: 'baseline'|'complete'|'incomplete',
     *     missing_inputs: array<int, array<string, mixed>>
     * }>
     */
    public function series(
        User $user,
        array $accountEntityIds,
        Currency $currency,
        array $dates,
        array $reportValuations,
    ): array {
        if (count($dates) !== count($reportValuations)) {
            throw new LogicException('Portfolio valuation dates and points must have matching lengths.');
        }

        $dateValues = [];
        $valuationByDate = [];
        foreach ($dates as $index => $date) {
            $dateString = $date->toDateString();
            if (isset($valuationByDate[$dateString])) {
                throw new LogicException('Portfolio return dates must be unique.');
            }

            $dateValues[] = $date->copy()->startOfDay();
            $valuationByDate[$dateString] = $reportValuations[$index];
        }
        usort($dateValues, static fn (Carbon $left, Carbon $right): int => $left <=> $right);

        if ($dateValues === []) {
            return [];
        }

        $firstDate = $dateValues[0];
        $lastDate = $dateValues[count($dateValues) - 1];
        $flows = $firstDate->lessThan($lastDate)
            ? $this->portfolioCashflowService->externalCashflows(
                $user,
                $accountEntityIds,
                $firstDate->copy()->addDay(),
                $lastDate,
            )
            : [];
        $adjustments = $firstDate->lessThan($lastDate)
            ? $this->portfolioCashflowService->unclassifiedShareAdjustments(
                $user,
                $accountEntityIds,
                $firstDate->copy()->addDay(),
                $lastDate,
            )
            : [];

        $accountCurrencies = AccountEntity::query()
            ->where('user_id', $user->id)
            ->where('config_type', 'account')
            ->whereIn('id', array_values(array_unique($accountEntityIds)))
            ->with('config.currency')
            ->get()
            ->mapWithKeys(function (AccountEntity $account): array {
                if (! $account->config instanceof Account) {
                    throw new ModelNotFoundException('A selected account has no account configuration.');
                }

                return [$account->id => $account->config->currency];
            })
            ->all();

        $flowsByDate = [];
        $missingConversionByDate = [];
        foreach ($flows as $flow) {
            $accountCurrency = $accountCurrencies[$flow['account_id']] ?? null;
            if (! $accountCurrency instanceof Currency
                || $accountCurrency->iso_code !== $flow['currency']) {
                throw new LogicException('A portfolio cashflow currency does not match its account.');
            }

            $date = Carbon::parse($flow['date']);
            $convertedAmount = $this->portfolioValuationService->convertCashflowAmount(
                $user,
                $flow['amount'],
                $accountCurrency,
                $currency,
                $date,
            );
            if ($convertedAmount === null) {
                $missingConversionByDate[$flow['date']][] = [
                    'kind' => 'cashflow_fx',
                    'date' => $flow['date'],
                    'account_id' => $flow['account_id'],
                    'transaction_id' => $flow['transaction_id'],
                ];
                continue;
            }

            $flowsByDate[$flow['date']] ??= BigDecimal::zero();
            $flowsByDate[$flow['date']] = $flowsByDate[$flow['date']]->plus($convertedAmount);
        }

        $adjustmentsByDate = [];
        foreach ($adjustments as $adjustment) {
            $adjustmentsByDate[$adjustment['date']][] = [
                'kind' => 'unclassified_share_adjustment',
                'date' => $adjustment['date'],
                'account_id' => $adjustment['account_id'],
                'investment_id' => $adjustment['investment_id'],
                'transaction_id' => $adjustment['transaction_id'],
                'transaction_type' => $adjustment['transaction_type'],
            ];
        }

        $requiredDates = [];
        foreach ($flows as $flow) {
            $requiredDates[$flow['date']] = Carbon::parse($flow['date']);
        }
        foreach ($dateValues as $date) {
            $requiredDates[$date->toDateString()] = $date;
        }

        $extraDates = array_values(array_filter(
            $requiredDates,
            fn (Carbon $date): bool => ! array_key_exists($date->toDateString(), $valuationByDate),
        ));
        if ($extraDates !== []) {
            foreach ($this->portfolioValuationService->series(
                $user,
                $accountEntityIds,
                $currency,
                $extraDates,
            ) as $valuation) {
                $valuationByDate[$valuation['date']] = $valuation;
            }
        }

        $firstValuation = $valuationByDate[$firstDate->toDateString()] ?? null;
        $chainFactor = ($firstValuation['total_value'] ?? null) === null
            ? null
            : BigDecimal::one();
        $results = [];

        foreach ($dateValues as $index => $date) {
            $dateString = $date->toDateString();
            if ($index === 0) {
                $results[] = [
                    'date' => $dateString,
                    'period_start_date' => null,
                    'period_return' => null,
                    'cumulative_return' => $chainFactor === null ? null : '0.0000000000',
                    'status' => $chainFactor === null ? 'incomplete' : 'baseline',
                    'missing_inputs' => $chainFactor === null
                        ? $this->valuationMissingInputs($firstValuation, $dateString)
                        : [],
                ];
                continue;
            }

            $periodStartDate = $dateValues[$index - 1];
            $intervalAdjustments = [];
            foreach ($adjustmentsByDate as $adjustmentDate => $dateAdjustments) {
                if ($adjustmentDate > $periodStartDate->toDateString() && $adjustmentDate <= $dateString) {
                    array_push($intervalAdjustments, ...$dateAdjustments);
                }
            }

            $missingInputs = $intervalAdjustments;
            $periodFactor = BigDecimal::one();
            $previousValue = BigDecimal::of($valuationByDate[$periodStartDate->toDateString()]['total_value'] ?? '0');
            $intervalDates = array_filter(
                array_unique(array_merge(
                    array_keys($flowsByDate),
                    array_keys($missingConversionByDate),
                )),
                fn (string $flowDate): bool => $flowDate > $periodStartDate->toDateString()
                    && $flowDate <= $dateString,
            );
            $intervalDates[] = $dateString;
            $intervalDates = array_values(array_unique($intervalDates));
            sort($intervalDates);

            if (($valuationByDate[$periodStartDate->toDateString()]['total_value'] ?? null) === null) {
                array_push(
                    $missingInputs,
                    ...$this->valuationMissingInputs(
                        $valuationByDate[$periodStartDate->toDateString()] ?? null,
                        $periodStartDate->toDateString(),
                    ),
                );
            }

            foreach ($intervalDates as $intervalDate) {
                $valuation = $valuationByDate[$intervalDate] ?? null;
                $dateHasMissingInputs = false;
                if (isset($missingConversionByDate[$intervalDate])) {
                    array_push($missingInputs, ...$missingConversionByDate[$intervalDate]);
                    $dateHasMissingInputs = true;
                }
                if (($valuation['total_value'] ?? null) === null) {
                    array_push($missingInputs, ...$this->valuationMissingInputs($valuation, $intervalDate));
                    $dateHasMissingInputs = true;
                }
                if ($dateHasMissingInputs) {
                    continue;
                }

                $currentValue = BigDecimal::of($valuation['total_value']);
                $externalCashflow = $flowsByDate[$intervalDate] ?? BigDecimal::zero();
                $preFlowValue = $currentValue->minus($externalCashflow);

                if ($previousValue->isLessThanOrEqualTo(BigDecimal::zero())) {
                    $missingInputs[] = [
                        'kind' => 'non_positive_opening_value',
                        'date' => $intervalDate,
                    ];
                    continue;
                }
                if ($preFlowValue->isLessThan(BigDecimal::zero())) {
                    $missingInputs[] = [
                        'kind' => 'negative_pre_flow_value',
                        'date' => $intervalDate,
                    ];
                    continue;
                }

                $periodFactor = $periodFactor->multipliedBy(
                    $preFlowValue->dividedBy($previousValue, 20, RoundingMode::HalfUp),
                );
                $previousValue = $currentValue;
            }

            $missingInputs = array_values(array_unique($missingInputs, SORT_REGULAR));
            $isComplete = $missingInputs === [];
            $periodReturn = $isComplete
                ? $periodFactor->minus(BigDecimal::one())
                : null;
            if (! $isComplete) {
                $chainFactor = null;
            } elseif ($chainFactor !== null) {
                $chainFactor = $chainFactor->multipliedBy($periodFactor);
            }

            $results[] = [
                'date' => $dateString,
                'period_start_date' => $periodStartDate->toDateString(),
                'period_return' => $periodReturn === null ? null : $this->formatReturn($periodReturn),
                'cumulative_return' => $chainFactor === null
                    ? null
                    : $this->formatReturn($chainFactor->minus(BigDecimal::one())),
                'status' => $isComplete ? 'complete' : 'incomplete',
                'missing_inputs' => $missingInputs,
            ];
        }

        return $results;
    }

    /**
     * @param array<string, mixed>|null $valuation
     * @return array<int, array<string, mixed>>
     */
    private function valuationMissingInputs(?array $valuation, string $date): array
    {
        $missingInputs = $valuation['missing_inputs'] ?? [];
        if ($missingInputs === []) {
            return [['kind' => 'valuation', 'date' => $date]];
        }

        return array_map(
            static fn (array $missingInput): array => ['date' => $date] + $missingInput,
            $missingInputs,
        );
    }

    private function formatReturn(BigDecimal $return): string
    {
        return (string) $return->toScale(10, RoundingMode::HalfUp);
    }
}
