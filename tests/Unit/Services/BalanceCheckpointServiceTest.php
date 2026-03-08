<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\AccountEntity;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionDetailStandard;
use App\Models\TransactionType;
use App\Models\AccountBalanceCheckpoint;
use Illuminate\Validation\ValidationException;

class BalanceCheckpointServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloning_transaction_breaks_checkpoint_and_prevents_save(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $account = AccountEntity::factory()
            ->for($user)
            ->for(
                Account::factory()->withUser($user)->state(['opening_balance' => 0]),
                'config'
            )
            ->create();

        $other = AccountEntity::factory()
            ->for($user)
            ->for(
                Account::factory()->withUser($user)->state(['opening_balance' => 0]),
                'config'
            )
            ->create();

        $txType = TransactionType::firstWhere('name', 'Withdrawal') ?? TransactionType::factory()->create([
            'name' => 'Withdrawal',
            'type' => 'standard',
            'amount_multiplier' => -1,
        ]);

        $original = Transaction::factory()
            ->for($user)
            ->for($txType, 'transactionType')
            ->for(
                TransactionDetailStandard::factory()->state([
                    'account_from_id' => $account->id,
                    'account_to_id' => $other->id,
                    'amount_from' => 100,
                    'amount_to' => 100,
                ]),
                'config'
            )
            ->create(['date' => '2026-02-15']);

        // Create an active checkpoint on 2026-02-28 with balance matching current facts (0)
        AccountBalanceCheckpoint::create([
            'user_id' => $user->id,
            'account_entity_id' => $account->id,
            'checkpoint_date' => '2026-02-28',
            'balance' => 0.00,
            'note' => 'Test checkpoint',
            'active' => true,
        ]);

        // Duplicate the transaction (unsaved clone) and attempt to save - should be blocked
        $clone = $original->duplicate();
        $clone->date = $original->date;

        $this->expectException(ValidationException::class);
        $clone->saveQuietly();
    }
}
