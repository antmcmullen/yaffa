<?php

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntity;
use App\Models\AccountGroup;
use App\Models\Currency;
use App\Models\Investment;
use App\Models\Transaction;
use App\Models\TransactionDetailStandard;
use App\Models\User;
use App\Services\PortfolioCashflowService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->currency = Currency::factory()->for($this->user)->create(['base' => true]);
    $this->accountGroup = AccountGroup::factory()->for($this->user)->create();
    $makeAccount = fn (): AccountEntity => AccountEntity::factory()
        ->for($this->user)
        ->for(Account::factory()->withUser($this->user)->create([
            'account_group_id' => $this->accountGroup->id,
            'currency_id' => $this->currency->id,
            'opening_balance' => '0',
        ]), 'config')
        ->create();

    $this->account = $makeAccount();
    $this->otherAccount = $makeAccount();
    $this->outsideAccount = $makeAccount();
});

it('classifies deposits, withdrawals and only portfolio-boundary transfer legs', function (): void {
    $payee = AccountEntity::factory()->asPayee($this->user)->create();
    $createTransaction = function (
        TransactionType $type,
        ?int $fromId,
        ?int $toId,
        string $amountFrom,
        string $amountTo,
    ): Transaction {
        $detail = TransactionDetailStandard::factory()->create([
            'account_from_id' => $fromId,
            'account_to_id' => $toId,
            'amount_from' => $amountFrom,
            'amount_to' => $amountTo,
        ]);

        return Transaction::query()->forceCreate([
            'user_id' => $this->user->id,
            'date' => '2026-01-10',
            'transaction_type' => $type,
            'config_type' => 'standard',
            'config_id' => $detail->id,
            'schedule' => false,
        ]);
    };

    $deposit = $createTransaction(TransactionType::DEPOSIT, $payee->id, $this->account->id, '50', '50');
    $withdrawal = $createTransaction(TransactionType::WITHDRAWAL, $this->account->id, $payee->id, '12', '12');
    $inboundTransfer = $createTransaction(
        TransactionType::TRANSFER,
        $this->outsideAccount->id,
        $this->account->id,
        '25',
        '25',
    );
    $outboundTransfer = $createTransaction(
        TransactionType::TRANSFER,
        $this->account->id,
        $this->outsideAccount->id,
        '8',
        '8',
    );
    $createTransaction(TransactionType::TRANSFER, $this->account->id, $this->otherAccount->id, '15', '15');
    $outsideRange = $createTransaction(TransactionType::DEPOSIT, $payee->id, $this->account->id, '100', '100');
    $outsideRange->update(['date' => '2025-12-31']);

    $cashflows = app(PortfolioCashflowService::class)->externalCashflows(
        $this->user,
        [$this->account->id, $this->otherAccount->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    );

    expect($cashflows)->toBe([
        [
            'transaction_id' => $deposit->id,
            'date' => '2026-01-10',
            'kind' => 'contribution',
            'source_type' => 'deposit',
            'account_id' => $this->account->id,
            'amount' => '50.0000',
            'currency' => $this->currency->iso_code,
        ],
        [
            'transaction_id' => $withdrawal->id,
            'date' => '2026-01-10',
            'kind' => 'withdrawal',
            'source_type' => 'withdrawal',
            'account_id' => $this->account->id,
            'amount' => '-12.0000',
            'currency' => $this->currency->iso_code,
        ],
        [
            'transaction_id' => $inboundTransfer->id,
            'date' => '2026-01-10',
            'kind' => 'contribution',
            'source_type' => 'transfer',
            'account_id' => $this->account->id,
            'amount' => '25.0000',
            'currency' => $this->currency->iso_code,
        ],
        [
            'transaction_id' => $outboundTransfer->id,
            'date' => '2026-01-10',
            'kind' => 'withdrawal',
            'source_type' => 'transfer',
            'account_id' => $this->account->id,
            'amount' => '-8.0000',
            'currency' => $this->currency->iso_code,
        ],
    ]);
});

it('returns share adjustments as unclassified events and excludes investment activity from external cashflows', function (): void {
    $investment = Investment::factory()
        ->withUser($this->user)
        ->create(['currency_id' => $this->currency->id]);
    $addShares = Transaction::factory()
        ->for($this->user)
        ->add_shares($this->user, [
            'account_id' => $this->account->id,
            'investment_id' => $investment->id,
            'quantity' => '4',
        ])
        ->create(['date' => '2026-01-10']);
    $removeShares = Transaction::factory()
        ->for($this->user)
        ->remove_shares($this->user, [
            'account_id' => $this->account->id,
            'investment_id' => $investment->id,
            'quantity' => '1',
        ])
        ->create(['date' => '2026-01-11']);
    Transaction::factory()
        ->for($this->user)
        ->buy($this->user, [
            'account_id' => $this->account->id,
            'investment_id' => $investment->id,
            'price' => '10',
            'quantity' => '2',
        ])
        ->create(['date' => '2026-01-12']);

    $service = app(PortfolioCashflowService::class);

    expect($service->externalCashflows(
        $this->user,
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    ))->toBe([])
        ->and($service->unclassifiedShareAdjustments(
            $this->user,
            [$this->account->id],
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-01-31'),
        ))->toBe([
            [
                'transaction_id' => $addShares->id,
                'date' => '2026-01-10',
                'account_id' => $this->account->id,
                'investment_id' => $investment->id,
                'transaction_type' => 'add_shares',
                'quantity' => '4.0000000000',
                'classification' => 'unclassified',
            ],
            [
                'transaction_id' => $removeShares->id,
                'date' => '2026-01-11',
                'account_id' => $this->account->id,
                'investment_id' => $investment->id,
                'transaction_type' => 'remove_shares',
                'quantity' => '-1.0000000000',
                'classification' => 'unclassified',
            ],
        ]);
});

it('excludes scheduled transactions and validates selected-account ownership', function (): void {
    Transaction::factory()
        ->for($this->user)
        ->withdrawal_schedule($this->user)
        ->create(['date' => '2026-01-15']);

    $otherUser = User::factory()->create();
    $foreignAccount = AccountEntity::factory()->asAccount($otherUser)->create();

    $service = app(PortfolioCashflowService::class);

    expect($service->externalCashflows(
        $this->user,
        [$this->account->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    ))->toBe([]);

    expect(fn () => $service->externalCashflows(
        $this->user,
        [$foreignAccount->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    ))->toThrow(ModelNotFoundException::class);

    expect(fn () => $service->unclassifiedShareAdjustments(
        $this->user,
        [$foreignAccount->id],
        Carbon::parse('2026-01-01'),
        Carbon::parse('2026-01-31'),
    ))->toThrow(ModelNotFoundException::class);
});
