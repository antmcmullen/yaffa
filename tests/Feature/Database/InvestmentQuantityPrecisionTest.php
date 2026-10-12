<?php

use App\Models\TransactionDetailInvestment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('investment quantity schema preserves integer capacity and allows ten fractional digits', function () {
    $column = DB::selectOne(
        "SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_details_investment'
         AND COLUMN_NAME = 'quantity'"
    );

    expect($column->COLUMN_TYPE)->toBe('decimal(20,10) unsigned')
        ->and($column->IS_NULLABLE)->toBe('YES');
});

test('investment quantities round trip as exact decimal strings', function (?string $quantity, ?string $stored) {
    $config = TransactionDetailInvestment::factory()->create(['quantity' => $quantity]);
    $fresh = $config->fresh();

    expect($fresh->toArray()['quantity'])->toBe($stored);
    if ($quantity !== null) {
        expect($fresh->quantity)->toBeInstanceOf(BigDecimal::class)
            ->and((string) $fresh->quantity)->toBe($stored);
    }
})->with([
    ['12.3456', '12.3456000000'],
    ['0.0000000001', '0.0000000001'],
    ['9999999999.9999999999', '9999999999.9999999999'],
    [null, null],
]);

test('quantity migration refuses rollback that would lose fractional digits', function () {
    TransactionDetailInvestment::factory()->create(['quantity' => '1.1234567891']);
    $migration = require database_path('migrations/2026_10_05_142246_widen_transaction_details_investment_quantity_scale.php');

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'more than four fractional digits');
});

test('investment cashflow includes the tenth quantity decimal place', function () {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->buy($user, [
        'quantity' => '0.0000000001',
        'price' => '1000000000',
        'commission' => 0,
        'tax' => 0,
    ])->create();

    $cashflow = app(TransactionService::class)->getTransactionCashFlow($transaction->fresh());

    expect($cashflow)->not->toBeNull()
        ->and($cashflow->getAmount()->isEqualTo('-0.1'))->toBeTrue();
});
