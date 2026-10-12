<?php

use App\Models\Account;
use App\Models\AccountEntity;
use App\Models\AccountGroup;
use App\Models\Currency;
use App\Models\CurrencyRate;
use App\Models\Investment;
use App\Models\InvestmentPrice;
use App\Models\Transaction;
use App\Models\TransactionDetailStandard;
use App\Models\User;
use App\Services\PortfolioTimeWeightedReturnService;
use App\Services\PortfolioValuationService;
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
});

function createPortfolioDeposit(User $user, AccountEntity $account, string $date, string $amount): void
{
    $payee = AccountEntity::factory()->asPayee($user)->create();
    $detail = TransactionDetailStandard::query()->create([
        'account_from_id' => $payee->id,
        'account_to_id' => $account->id,
        'amount_from' => $amount,
        'amount_to' => $amount,
    ]);

    Transaction::query()->forceCreate([
        'user_id' => $user->id,
        'date' => $date,
        'transaction_type' => 'deposit',
        'config_type' => 'standard',
        'config_id' => $detail->id,
        'schedule' => false,
    ]);
}

function calculatePortfolioReturns(User $user, AccountEntity $account, Currency $currency, array $dates): array
{
    $valuationService = app(PortfolioValuationService::class);
    $valuations = $valuationService->series($user, [$account->id], $currency, $dates);

    return app(PortfolioTimeWeightedReturnService::class)->series(
        $user,
        [$account->id],
        $currency,
        $dates,
        $valuations,
    );
}

it('neutralizes external contributions at their end-of-day valuation date', function (): void {
    createPortfolioDeposit($this->user, $this->account, '2026-01-05', '100');

    $returns = calculatePortfolioReturns(
        $this->user,
        $this->account,
        $this->currency,
        [Carbon::parse('2026-01-01'), Carbon::parse('2026-01-10')],
    );

    expect($returns[0]['status'])->toBe('baseline')
        ->and($returns[0]['cumulative_return'])->toBe('0.0000000000')
        ->and($returns[1]['status'])->toBe('complete')
        ->and($returns[1]['period_return'])->toBe('0.0000000000')
        ->and($returns[1]['cumulative_return'])->toBe('0.0000000000');
});

it('converts foreign-currency cashflows with the rate available on the flow date', function (): void {
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
        ->create(['date' => '2026-01-01', 'rate' => '2']);
    createPortfolioDeposit($this->user, $foreignAccount, '2026-01-05', '100');

    $returns = calculatePortfolioReturns(
        $this->user,
        $foreignAccount,
        $this->currency,
        [Carbon::parse('2026-01-01'), Carbon::parse('2026-01-10')],
    );

    expect($returns[1]['status'])->toBe('complete')
        ->and($returns[1]['period_return'])->toBe('0.0000000000')
        ->and($returns[1]['cumulative_return'])->toBe('0.0000000000');
});

it('links market growth between external cashflow boundaries', function (): void {
    $investment = Investment::factory()
        ->withUser($this->user)
        ->create(['currency_id' => $this->currency->id]);
    Transaction::factory()
        ->for($this->user)
        ->buy($this->user, [
            'account_id' => $this->account->id,
            'investment_id' => $investment->id,
            'price' => '10',
            'quantity' => '10',
            'commission' => null,
            'tax' => null,
        ])
        ->create(['date' => '2026-01-02']);
    InvestmentPrice::factory()->for($investment)->create([
        'date' => '2026-01-10',
        'price' => '11',
    ]);

    $returns = calculatePortfolioReturns(
        $this->user,
        $this->account,
        $this->currency,
        [Carbon::parse('2026-01-01'), Carbon::parse('2026-01-10')],
    );

    expect($returns[1]['status'])->toBe('complete')
        ->and($returns[1]['period_return'])->toBe('0.0100000000')
        ->and($returns[1]['cumulative_return'])->toBe('0.0100000000');
});

it('does not calculate a return across an unclassified share adjustment', function (): void {
    $investment = Investment::factory()
        ->withUser($this->user)
        ->create(['currency_id' => $this->currency->id]);
    Transaction::factory()
        ->for($this->user)
        ->add_shares($this->user, [
            'account_id' => $this->account->id,
            'investment_id' => $investment->id,
            'quantity' => '1',
        ])
        ->create(['date' => '2026-01-05']);
    InvestmentPrice::factory()->for($investment)->create([
        'date' => '2026-01-05',
        'price' => '10',
    ]);

    $returns = calculatePortfolioReturns(
        $this->user,
        $this->account,
        $this->currency,
        [Carbon::parse('2026-01-01'), Carbon::parse('2026-01-10')],
    );

    expect($returns[1]['status'])->toBe('incomplete')
        ->and($returns[1]['period_return'])->toBeNull()
        ->and($returns[1]['cumulative_return'])->toBeNull()
        ->and($returns[1]['missing_inputs'])->toContain([
            'kind' => 'unclassified_share_adjustment',
            'date' => '2026-01-05',
            'account_id' => $this->account->id,
            'investment_id' => $investment->id,
            'transaction_id' => Transaction::query()->where('transaction_type', 'add_shares')->value('id'),
            'transaction_type' => 'add_shares',
        ]);
});

it('reports missing dated FX for an external cashflow instead of estimating a return', function (): void {
    $foreignCurrency = Currency::factory()->for($this->user)->create();
    $foreignAccount = AccountEntity::factory()
        ->for($this->user)
        ->for(Account::factory()->withUser($this->user)->create([
            'account_group_id' => $this->accountGroup->id,
            'currency_id' => $foreignCurrency->id,
            'opening_balance' => '0',
        ]), 'config')
        ->create();
    createPortfolioDeposit($this->user, $foreignAccount, '2026-01-05', '100');

    $returns = calculatePortfolioReturns(
        $this->user,
        $foreignAccount,
        $this->currency,
        [Carbon::parse('2026-01-01'), Carbon::parse('2026-01-10')],
    );

    expect($returns[1]['status'])->toBe('incomplete')
        ->and($returns[1]['period_return'])->toBeNull()
        ->and($returns[1]['cumulative_return'])->toBeNull()
        ->and($returns[1]['missing_inputs'])->toContain([
            'kind' => 'cashflow_fx',
            'date' => '2026-01-05',
            'account_id' => $foreignAccount->id,
            'transaction_id' => Transaction::query()->where('transaction_type', 'deposit')->value('id'),
        ]);
});
