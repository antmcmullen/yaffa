<?php

namespace Tests\Feature\API;

use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntity;
use App\Models\Currency;
use App\Models\Investment;
use App\Models\InvestmentPrice;
use App\Models\Payee;
use App\Models\Transaction;
use App\Models\TransactionDetailInvestment;
use App\Models\TransactionDetailStandard;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdvancedReconcileApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private AccountEntity $account;
    private AccountEntity $payee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-15');

        $this->user = User::factory()->create();
        $currency = Currency::factory()
            ->for($this->user)
            ->fromIsoCodes(['USD'])
            ->create(['base' => true]);

        $this->account = AccountEntity::factory()
            ->for($this->user)
            ->for(Account::factory()->withUser($this->user)->create([
                'currency_id' => $currency->id,
                'opening_balance' => 100,
            ]), 'config')
            ->create(['active' => true]);

        $this->payee = AccountEntity::factory()
            ->for($this->user)
            ->for(Payee::factory()->withUser($this->user), 'config')
            ->create(['active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_user_can_summarize_cash_reconciliation_without_checkpoint(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->createJulyCashMovements();

        $summaryResponse = $this->getJulySummary();
        $summaryResponse->assertOk();
        $summaryResponse->assertJsonPath('cash.opening_balance', 100);
        $summaryResponse->assertJsonPath('cash.total_withdrawals', 30);
        $summaryResponse->assertJsonPath('cash.total_deposits', 50);
        $summaryResponse->assertJsonPath('cash.balance', 120);
        $summaryResponse->assertJsonPath('cash.status', 'no_checkpoint');
    }

    public function test_user_can_save_matching_cash_checkpoint(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $checkpointResponse = $this->postJson(route('api.v1.accounts.balance-checkpoints.store', [
            'accountEntity' => $this->account,
        ]), [
            'checkpoint_date' => '2026-07-31',
            'checkpoint_type' => 'cash',
            'balance' => 120,
            'note' => 'July statement',
        ]);

        $checkpointResponse->assertCreated();
        $this->assertDatabaseHas('account_balance_checkpoints', [
            'account_entity_id' => $this->account->id,
            'checkpoint_type' => 'cash',
            'balance' => 120,
            'note' => 'July statement',
        ]);
    }

    public function test_saved_matching_cash_checkpoint_marks_summary_as_matched(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->createJulyCashMovements();
        $this->postJson(route('api.v1.accounts.balance-checkpoints.store', [
            'accountEntity' => $this->account,
        ]), [
            'checkpoint_date' => '2026-07-31',
            'checkpoint_type' => 'cash',
            'balance' => 120,
            'note' => 'July statement',
        ])->assertCreated();

        $this->getJulySummary()
            ->assertOk()
            ->assertJsonPath('cash.status', 'matched')
            ->assertJsonPath('cash.variance', 0);
    }

    public function test_dashboard_marks_variance_as_reconcile_required(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->createDeposit('2026-07-05', 50);

        $this->postJson(route('api.v1.accounts.balance-checkpoints.store', [
            'accountEntity' => $this->account,
        ]), [
            'checkpoint_date' => '2026-07-31',
            'checkpoint_type' => 'cash',
            'balance' => 200,
        ])->assertCreated();

        $response = $this->getJson(route('api.v1.reports.advanced-reconcile', [
            'checkpoint_type' => 'cash',
            'display' => 'status',
        ]));

        $response->assertOk();
        $response->assertJsonPath('rows.0.months.2026-07.status', 'reconcile_required');
        $response->assertJsonPath('rows.0.months.2026-07.variance', 50);
    }

    public function test_investment_holdings_include_statement_price_editing_metadata(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $investment = Investment::factory()->withUser($this->user)->create([
            'currency_id' => $this->account->config->currency_id,
        ]);

        $this->createInvestmentBuy($investment, '2026-06-15', 10);

        $openingPrice = InvestmentPrice::factory()->create([
            'investment_id' => $investment->id,
            'date' => '2026-07-01',
            'price' => 12.34,
        ]);
        InvestmentPrice::factory()->create([
            'investment_id' => $investment->id,
            'date' => '2026-07-20',
            'price' => 15.67,
        ]);

        $response = $this->getJson(route('api.v1.accounts.advanced-reconcile.show', [
            'accountEntity' => $this->account,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]));

        $response->assertOk();
        $response->assertJsonPath('investment.holdings.0.investment_id', $investment->id);
        $response->assertJsonPath('investment.holdings.0.open_quantity', 10);
        $response->assertJsonPath('investment.holdings.0.close_quantity', 10);
        $response->assertJsonPath('investment.holdings.0.open_price', 12.34);
        $response->assertJsonPath('investment.holdings.0.close_price', 15.67);
        $response->assertJsonPath('investment.holdings.0.open_stored_price_id', $openingPrice->id);
        $response->assertJsonPath('investment.holdings.0.close_stored_price_id', null);
    }

    public function test_user_cannot_save_checkpoint_for_another_users_account(): void
    {
        $otherUser = User::factory()->create();
        Sanctum::actingAs($otherUser, ['*']);

        $response = $this->postJson(route('api.v1.accounts.balance-checkpoints.store', [
            'accountEntity' => $this->account,
        ]), [
            'checkpoint_date' => '2026-07-31',
            'checkpoint_type' => 'cash',
            'balance' => 120,
        ]);

        $response->assertForbidden();
    }

    public function test_checkpoint_type_must_be_valid_when_saving_checkpoint(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.v1.accounts.balance-checkpoints.store', [
            'accountEntity' => $this->account,
        ]), [
            'checkpoint_date' => '2026-07-31',
            'checkpoint_type' => 'other',
            'balance' => 120,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('checkpoint_type');
    }

    public function test_balance_is_required_when_saving_checkpoint(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.v1.accounts.balance-checkpoints.store', [
            'accountEntity' => $this->account,
        ]), [
            'checkpoint_date' => '2026-07-31',
            'checkpoint_type' => 'cash',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('balance');
    }

    public function test_checkpoint_date_must_be_a_valid_date_when_saving_checkpoint(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson(route('api.v1.accounts.balance-checkpoints.store', [
            'accountEntity' => $this->account,
        ]), [
            'checkpoint_date' => 'not-a-date',
            'checkpoint_type' => 'cash',
            'balance' => 120,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('checkpoint_date');
    }

    private function getJulySummary(): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(route('api.v1.accounts.advanced-reconcile.show', [
            'accountEntity' => $this->account,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ]));
    }

    public function test_special_investment_cashflows_and_schedules_are_reconciled_correctly(): void
    {
        Sanctum::actingAs($this->user, ['*']);
        $investment = Investment::factory()->withUser($this->user)->create([
            'currency_id' => $this->account->config->currency_id,
        ]);

        foreach ([
            [TransactionType::PURCHASED_INTEREST, ['dividend' => '20.1250'], false],
            [TransactionType::TAX_RELIEF, ['tax' => '10.2500'], false],
            [TransactionType::PRODUCT_FEE, ['commission' => '1.1250'], false],
            [TransactionType::TAX_RELIEF, ['tax' => '999.0000'], true],
        ] as [$type, $amounts, $scheduled]) {
            $detail = TransactionDetailInvestment::factory()->create(array_merge([
                'account_id' => $this->account->id,
                'investment_id' => $investment->id,
                'quantity' => null,
                'price' => null,
                'dividend' => null,
                'commission' => null,
                'tax' => null,
            ], $amounts));
            $transaction = Transaction::factory()->create([
                'user_id' => $this->user->id,
                'config_type' => 'investment',
                'config_id' => $detail->id,
                'transaction_type' => $type,
                'date' => '2026-07-15',
                'schedule' => $scheduled,
            ]);
            $transaction->cashflow_value = app(\App\Services\TransactionService::class)->getTransactionCashFlow($transaction);
            $transaction->save();
        }

        $this->getJulySummary()->assertOk()
            ->assertJsonPath('cash.opening_balance', 100)
            ->assertJsonPath('cash.total_deposits', 10.25)
            ->assertJsonPath('cash.total_withdrawals', 21.25)
            ->assertJsonPath('cash.balance', 89)
            ->assertJsonPath('total.balance', 89);
    }

    public function test_user_cannot_read_another_users_reconciliation(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->getJulySummary()->assertForbidden();
    }

    public function test_checkpoint_rejects_excess_precision(): void
    {
        Sanctum::actingAs($this->user, ['*']);
        $this->postJson(route('api.v1.accounts.balance-checkpoints.store', ['accountEntity' => $this->account]), [
            'checkpoint_date' => '2026-07-31',
            'checkpoint_type' => 'cash',
            'balance' => '100.001',
        ])->assertUnprocessable()->assertJsonValidationErrors('balance');
    }

    public function test_summary_accepts_an_end_date_without_a_start_date(): void
    {
        Sanctum::actingAs($this->user, ['*']);
        $this->getJson(route('api.v1.accounts.advanced-reconcile.show', [
            'accountEntity' => $this->account,
            'date_to' => '2026-07-31',
        ]))->assertOk()->assertJsonPath('date_from', '2026-07-01');
    }

    public function test_summary_rejects_a_reversed_period(): void
    {
        Sanctum::actingAs($this->user, ['*']);
        $this->getJson(route('api.v1.accounts.advanced-reconcile.show', [
            'accountEntity' => $this->account,
            'date_from' => '2026-08-01',
            'date_to' => '2026-07-31',
        ]))->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }

    public function test_read_only_token_cannot_save_a_checkpoint(): void
    {
        Sanctum::actingAs($this->user, ['read']);
        $this->getJulySummary()->assertOk();
        $this->postJson(route('api.v1.accounts.balance-checkpoints.store', ['accountEntity' => $this->account]), [
            'checkpoint_date' => '2026-07-31',
            'checkpoint_type' => 'cash',
            'balance' => 100,
        ])->assertForbidden();
    }

    public function test_token_without_read_ability_cannot_read_reconciliation(): void
    {
        Sanctum::actingAs($this->user, ['write']);
        $this->getJulySummary()->assertForbidden();
        $this->getJson(route('api.v1.reports.advanced-reconcile'))->assertForbidden();
    }

    private function createJulyCashMovements(): void
    {
        $this->createWithdrawal('2026-07-03', 30);
        $this->createDeposit('2026-07-05', 50);
    }

    private function createWithdrawal(string $date, float $amount): void
    {
        $detail = TransactionDetailStandard::create([
            'account_from_id' => $this->account->id,
            'account_to_id' => $this->payee->id,
            'amount_from' => $amount,
            'amount_to' => $amount,
        ]);

        $transaction = Transaction::factory()->make([
            'date' => $date,
            'transaction_type' => TransactionType::WITHDRAWAL,
            'reconciled' => false,
            'schedule' => false,
            'config_type' => 'standard',
            'config_id' => $detail->id,
            'user_id' => $this->user->id,
        ]);
        $transaction->save();
    }

    private function createDeposit(string $date, float $amount): void
    {
        $detail = TransactionDetailStandard::create([
            'account_from_id' => $this->payee->id,
            'account_to_id' => $this->account->id,
            'amount_from' => $amount,
            'amount_to' => $amount,
        ]);

        $transaction = Transaction::factory()->make([
            'date' => $date,
            'transaction_type' => TransactionType::DEPOSIT,
            'reconciled' => false,
            'schedule' => false,
            'config_type' => 'standard',
            'config_id' => $detail->id,
            'user_id' => $this->user->id,
        ]);
        $transaction->save();
    }

    private function createInvestmentBuy(Investment $investment, string $date, float $quantity): void
    {
        $detail = TransactionDetailInvestment::create([
            'account_id' => $this->account->id,
            'investment_id' => $investment->id,
            'quantity' => $quantity,
            'price' => 10,
            'commission' => 0,
            'tax' => 0,
            'dividend' => null,
        ]);

        $transaction = Transaction::factory()->make([
            'date' => $date,
            'transaction_type' => TransactionType::BUY,
            'reconciled' => false,
            'schedule' => false,
            'config_type' => 'investment',
            'config_id' => $detail->id,
            'cashflow_value' => -100,
            'user_id' => $this->user->id,
        ]);
        $transaction->save();
    }
}
