<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\PortfolioValuationRequest;
use App\Models\User;
use App\Services\PortfolioValuationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware('auth:sanctum')]
#[Middleware('verified')]
#[Middleware('abilities:read', only: ['series'])]
class PortfolioValuationApiController extends Controller
{
    public function __construct(
        private readonly PortfolioValuationService $portfolioValuationService,
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
        $currency = $user->currencies()->findOrFail((int) $validated['currency_id']);

        return response()->json([
            'series' => $this->portfolioValuationService->series(
                $user,
                array_map('intval', $accountIds),
                $currency,
                array_map(
                    static fn (string $date): Carbon => Carbon::parse($date),
                    $dates,
                ),
            ),
        ]);
    }
}
