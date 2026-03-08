<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Investment;
use App\Models\Account;
use App\Models\AccountEntity;
use App\Models\TransactionType;
use App\Models\TransactionDetailStandard;
use App\Models\Transaction;
use App\Models\TransactionDetailInvestment;

class AutoInvestmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_creates_buy_investment_transaction()
    {
        $user = User::factory()->create();

        $investment = Investment::factory()->for($user)->create();

        $account = Account::factory()->withUser($user)->create();

        // Create account entity (the app uses account_entities for UI-level settings)
        $accountEntity = AccountEntity::factory()->for($user)->for($account, 'config')->create([
            'default_investment_id' => $investment->id,
        ]);

        // Create a payee entity for the from side
        $payeeEntity = AccountEntity::factory()->for($user)->for(\App\Models\Payee::factory()->withUser($user), 'config')->create();

        // Create transaction types
        $depositType = TransactionType::create(['name' => 'deposit', 'type' => 'standard', 'amount_multiplier' => 1]);
        TransactionType::create(['name' => 'Buy', 'type' => 'investment', 'quantity_multiplier' => 1]);

        // Create standard detail and transaction (deposit into accountEntity)
        $detail = TransactionDetailStandard::create([
            'account_from_id' => $payeeEntity->id,
            'account_to_id' => $accountEntity->id,
            'amount_from' => 0,
            'amount_to' => 123.45,
        ]);

        $tx = new Transaction([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'transaction_type_id' => $depositType->id,
            'config_type' => 'standard',
            'config_id' => $detail->id,
            'schedule' => false,
            'budget' => false,
            'reconciled' => false,
            'currency_id' => $account->currency_id,
        ]);

        $tx->save();

        // Assert that an investment detail was created for that account/investment
        $this->assertDatabaseHas('transaction_details_investment', [
            'account_id' => $account->id,
            'investment_id' => $investment->id,
            'quantity' => 123.45,
            'price' => 1,
        ]);

        // And that a corresponding investment transaction exists
        $this->assertDatabaseHas('transactions', [
            'config_type' => 'investment',
            'currency_id' => $account->currency_id,
        ]);
    }
}
