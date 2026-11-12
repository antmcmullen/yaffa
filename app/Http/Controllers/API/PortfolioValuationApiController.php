<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\PortfolioValuationRequest;
use App\Models\Currency;
use App\Models\User;
use App\Services\PortfolioTimeWeightedReturnService;
use App\Services\PortfolioValuationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use LogicException;

#[Middleware('auth:sanctum')]
#[Middleware('verified')]
#[Middleware('abilities:read', only: ['series'])]
class PortfolioValuationApiController extends Controller
{
    public function __construct(
        private readonly PortfolioValuationService $portfolioValuationService,
        private readonly PortfolioTimeWeightedReturnService $portfolioTimeWeightedReturnService,
    ) {
    }

    public function series(PortfolioValuationRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validated();
        /** @var array<int, int|string> $accountIds */
        $accountIds = $validated['account_ids'];
        /** @var array<int, string> $dates */
        $dates = $validated['dates'];
        $currency = Currency::query()
            ->where('user_id', $user->id)
            ->findOrFail((int) $validated['currency_id']);

        $accountEntityIds = array_map('intval', $accountIds);
        $valuationDates = array_map(
            static fn (string $date): Carbon => Carbon::parse($date),
            $dates,
        );
        $series = $this->portfolioValuationService->series(
            $user,
            $accountEntityIds,
            $currency,
            $valuationDates,
        );
        $timeWeightedReturns = $this->portfolioTimeWeightedReturnService->series(
            $user,
            $accountEntityIds,
            $currency,
            $valuationDates,
            $series,
        );
        $returnsByDate = collect($timeWeightedReturns)->keyBy('date');

        foreach ($series as &$point) {
            if (! $returnsByDate->has($point['date'])) {
                throw new LogicException('A portfolio valuation point has no matching return result.');
            }
            $point['time_weighted_return'] = $returnsByDate->get($point['date']);
        }

        return response()->json(['series' => $series]);
    }
}
