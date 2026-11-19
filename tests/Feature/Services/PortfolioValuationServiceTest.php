<?php

use App\Models\Account;
use App\Models\AccountEntity;
use App\Models\AccountGroup;
use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\TransactionDetailStandard;
use App\Models\User;
use App\Services\PortfolioValuationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->currency = Currency::factory()->for($this->user)->create(['base' => true]);
    $this->accountGroup = AccountGroup::factory()->for($this->user)->create();
    $this->account = AccountEntity::factory()
        ->for($this->user)
        ->for(Account::factory()->withUser($this->user)->create([
            'account_group_id' => $this->accountGroup->id,
            'currency_id' => $this->currency->id,
            'opening_balance' => '1000',
        ]), 'config')
        ->create();
    $this->investment = Investment::factory()
        ->withUser($this->user)
        ->create(['currency_id' => $this->currency->id]);
});

it('values cash and holdings from recorded activity using transaction price evidence', function (): void {
    Transaction::factory()
        ->for($this->user)
        ->buy($this->user, [
            'account_id' => $this->account->id,
            'investment_id' => $this->investment->id,
            'price' => '10',
            'quantity' => '2',
            'commission' => null,
            'tax' => null,
        ])
        ->create(['date' => '2026-01-02']);
    Transaction::factory()
        ->for($this->user)
        ->buy($this->user, [
            'account_id' => $this->account->id,
            'investment_id' => $this->investment->id,
            'price' => '15',
            'quantity' => '1',
            'commission' => null,
            'tax' => null,
        ])
        ->create(['date' => '2026-01-04']);

    $points = app(PortfolioValuationService::class)->series(
        $this->user,
        [$this->account->id],
        $this->currency,
        [Carbon::parse('2026-01-03')],
    );
    expect($points[0]['status'])->toBe('complete')
        ->and($points[0]['known_value'])->toBe('1000.0000')
        ->and($points[0]['total_value'])->toBe('1000.0000')
        ->and($points[0]['components'][1]['price_source'])->toBe([
            'kind' => 'transaction',
            'id' => Transaction::query()->where('config_type', 'investment')->value('id'),
            'date' => '2026-01-02',
        ])
        ->and($points[0]['components'][1]['quantity'])->toBe('2.0000000000');
});

it('values standard transactions linked to payees without loading a nonexistent payee currency', function (): void {
    $transaction = Transaction::factory()
        ->for($this->user)
        ->withdrawal($this->user)
        ->create(['date' => '2026-01-02']);
    TransactionDetailStandard::query()
        ->whereKey($transaction->config_id)
        ->update(['amount_from' => '0', 'amount_to' => '0']);

    $point = app(PortfolioValuationService::class)->series(
        $this->user,
        [$this->account->id],
        $this->currency,
        [Carbon::parse('2026-01-03')],
    )[0];

    expect($point['status'])->toBe('complete')
        ->and($point['known_value'])->toBe('1000.0000')
        ->and($point['total_value'])->toBe('1000.0000');
});

it('marks a non-zero unpriced holding incomplete but permits a zero holding without a price', function (): void {
    Transaction::factory()
        ->for($this->user)
        ->add_shares($this->user, [
            'account_id' => $this->account->id,
            'investment_id' => $this->investment->id,
            'quantity' => '2',
        ])
        ->create(['date' => '2026-01-02']);

    $service = app(PortfolioValuationService::class);
    $date = Carbon::parse('2026-01-03');
    $incomplete = $service->series($this->user, [$this->account->id], $this->currency, [$date])[0];

    expect($incomplete['known_value'])->toBe('1000.0000')
        ->and($incomplete['total_value'])->toBeNull()
        ->and($incomplete['missing_inputs'])->toBe([
            [
                'account_id' => $this->account->id,
                'investment_id' => $this->investment->id,
                'kind' => 'price',
            ],
        ]);

    Transaction::factory()
        ->for($this->user)
        ->remove_shares($this->user, [
            'account_id' => $this->account->id,
            'investment_id' => $this->investment->id,
            'quantity' => '2',
        ])
        ->create(['date' => '2026-01-02']);

    $complete = $service->series($this->user, [$this->account->id], $this->currency, [$date])[0];

    expect($complete['status'])->toBe('complete')
        ->and($complete['total_value'])->toBe('1000.0000')
        ->and($complete['missing_inputs'])->toBe([]);
});

it('uses only exchange rates on or before the valuation date', function (): void {
    $foreignCurrency = Currency::factory()->for($this->user)->create();
    $foreignAccount = AccountEntity::factory()
        ->for($this->user)
        ->for(Account::factory()->withUser($this->user)->create([
            'account_group_id' => $this->accountGroup->id,
            'currency_id' => $foreignCurrency->id,
            'opening_balance' => '100',
        ]), 'config')
        ->create();

    $service = app(PortfolioValuationService::class);
    $date = Carbon::parse('2026-01-03');
    $missingRate = $service->series($this->user, [$foreignAccount->id], $this->currency, [$date])[0];
    expect($missingRate['status'])->toBe('incomplete')
        ->and($missingRate['total_value'])->toBeNull()
        ->and($missingRate['missing_inputs'])->toBe([
            ['account_id' => $foreignAccount->id, 'kind' => 'fx'],
        ]);

    CurrencyRate::factory()
        ->betweenCurrencies($foreignCurrency, $this->currency)
        ->create(['date' => '2026-01-01', 'rate' => '2']);
    CurrencyRate::factory()
        ->betweenCurrencies($foreignCurrency, $this->currency)
        ->create(['date' => '2026-01-04', 'rate' => '3']);

    $points = $service->series(
        $this->user,
        [$foreignAccount->id],
        $this->currency,
        [$date],
    );

    expect($points[0]['known_value'])->toBe('200.0000')
        ->and($points[0]['components'][0]['fx']['rates'][0]['date'])->toBe('2026-01-01');
});

it('does not treat a zero exchange rate as a valid valuation', function (): void {
    $foreignCurrency = Currency::factory()->for($this->user)->create();
    $foreignAccount = AccountEntity::factory()
        ->for($this->user)
        ->for(Account::factory()->withUser($this->user)->create([
            'account_group_id' => $this->accountGroup->id,
            'currency_id' => $foreignCurrency->id,
            'opening_balance' => '100',
        ]), 'config')
        ->create();
    CurrencyRate::factory()
        ->betweenCurrencies($foreignCurrency, $this->currency)
        ->create(['date' => '2026-01-01', 'rate' => '0']);

    $point = app(PortfolioValuationService::class)->series(
        $this->user,
        [$foreignAccount->id],
        $this->currency,
        [Carbon::parse('2026-01-03')],
    )[0];

    expect($point['status'])->toBe('incomplete')
        ->and($point['known_value'])->toBe('0.0000')
        ->and($point['total_value'])->toBeNull()
        ->and($point['missing_inputs'])->toBe([
            ['account_id' => $foreignAccount->id, 'kind' => 'fx'],
        ]);
});

it('rejects accounts outside the requested user scope', function (): void {
    $otherUser = User::factory()->create();
    $otherCurrency = Currency::factory()->for($otherUser)->create(['base' => true]);
    $otherAccount = AccountEntity::factory()->asAccount($otherUser)->create();

    app(PortfolioValuationService::class)->series(
        $this->user,
        [$otherAccount->id],
        $this->currency,
        [Carbon::parse('2026-01-03')],
    );
})->throws(ModelNotFoundException::class);
