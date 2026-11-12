<?php

use App\Models\Account;
use App\Models\AccountEntity;
use App\Models\AccountGroup;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->currency = Currency::factory()->for($this->user)->create(['base' => true]);
    $accountGroup = AccountGroup::factory()->for($this->user)->create();
    $this->account = AccountEntity::factory()
        ->for($this->user)
        ->for(Account::factory()->withUser($this->user)->create([
            'account_group_id' => $accountGroup->id,
            'currency_id' => $this->currency->id,
            'opening_balance' => '1200',
        ]), 'config')
        ->create();
});

function portfolioValuationUrl(array $accountIds, int $currencyId, array $dates): string
{
    return route('api.v1.reports.portfolio-valuation') . '?' . http_build_query([
        'account_ids' => $accountIds,
        'currency_id' => $currencyId,
        'dates' => $dates,
    ]);
}

it('returns decimal-string valuation points for explicitly selected accounts and dates', function (): void {
    $response = $this->actingAs($this->user)->getJson(portfolioValuationUrl(
        [$this->account->id],
        $this->currency->id,
        ['2026-01-01', '2026-01-02'],
    ));

    $response->assertOk()
        ->assertJsonCount(2, 'series')
        ->assertJsonPath('series.0.date', '2026-01-01')
        ->assertJsonPath('series.0.currency', $this->currency->iso_code)
        ->assertJsonPath('series.0.known_value', '1200.0000')
        ->assertJsonPath('series.0.total_value', '1200.0000')
        ->assertJsonPath('series.0.status', 'complete')
        ->assertJsonPath('series.0.components.0.amount', '1200.0000')
        ->assertJsonPath('series.0.time_weighted_return.status', 'baseline')
        ->assertJsonPath('series.0.time_weighted_return.cumulative_return', '0.0000000000')
        ->assertJsonPath('series.1.time_weighted_return.period_return', '0.0000000000');
});

it('requires authentication to read valuation series', function (): void {
    $this->getJson(portfolioValuationUrl(
        [$this->account->id],
        $this->currency->id,
        ['2026-01-01'],
    ))->assertUnauthorized();
});

it('returns a complete zero for an explicitly empty account scope', function (): void {
    $url = route('api.v1.reports.portfolio-valuation') . '?' . http_build_query([
        'account_ids' => '',
        'currency_id' => $this->currency->id,
        'dates' => ['2026-01-01'],
    ]);

    $response = $this->actingAs($this->user)->getJson($url);

    $response->assertOk()
        ->assertJsonPath('series.0.known_value', '0.0000')
        ->assertJsonPath('series.0.total_value', '0.0000')
        ->assertJsonPath('series.0.status', 'complete')
        ->assertJsonPath('series.0.components', []);
});

it('requires an explicit account scope', function (): void {
    $url = route('api.v1.reports.portfolio-valuation') . '?' . http_build_query([
        'currency_id' => $this->currency->id,
        'dates' => ['2026-01-01'],
    ]);

    $this->actingAs($this->user)
        ->getJson($url)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account_ids']);
});

it('rejects foreign currencies and accounts outside the authenticated user scope', function (): void {
    $otherUser = User::factory()->create();
    $otherCurrency = Currency::factory()->for($otherUser)->create(['base' => true]);
    $otherAccount = AccountEntity::factory()->asAccount($otherUser)->create();

    $this->actingAs($this->user)
        ->getJson(portfolioValuationUrl(
            [$this->account->id],
            $otherCurrency->id,
            ['2026-01-01'],
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['currency_id']);

    $this->actingAs($this->user)
        ->getJson(portfolioValuationUrl(
            [$otherAccount->id],
            $this->currency->id,
            ['2026-01-01'],
        ))
        ->assertNotFound();
});

it('validates date format and the 31-point request limit', function (): void {
    $url = portfolioValuationUrl(
        [$this->account->id],
        $this->currency->id,
        ['not-a-date'],
    );
    $this->actingAs($this->user)
        ->getJson($url)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['dates.0']);

    $dates = array_map(
        fn (int $offset): string => now()->startOfDay()->addDays($offset)->toDateString(),
        range(0, 31),
    );
    $this->actingAs($this->user)
        ->getJson(portfolioValuationUrl([$this->account->id], $this->currency->id, $dates))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['dates']);
});

it('rejects duplicate dates in the requested series', function (): void {
    $this->actingAs($this->user)
        ->getJson(portfolioValuationUrl(
            [$this->account->id],
            $this->currency->id,
            ['2026-01-01', '2026-01-01'],
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['dates.1']);
});

it('requires account and date inputs to be ordered lists', function (): void {
    $this->actingAs($this->user)
        ->getJson(portfolioValuationUrl(
            ['selected' => $this->account->id],
            $this->currency->id,
            ['latest' => '2026-01-01'],
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account_ids', 'dates']);
});
