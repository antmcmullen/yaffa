<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\TransactionDetailStandard;
use App\Models\Transaction;
use App\Services\BalanceCheckpointService;
use Carbon\Carbon;

$templateId = $argv[1] ?? 24991;

$template = Transaction::with('config')->find($templateId);
if (! $template) {
    echo "Template transaction {$templateId} not found\n";
    exit(2);
}

// Build validated data similar to controller
$validated = [
    'date' => $template->date->format('Y-m-d'),
    'transaction_type_id' => $template->transaction_type_id,
    'config' => $template->config->toArray(),
    'items' => [],
    'schedule_config' => $template->transactionSchedule?->toArray() ?? [],
    'action' => 'create',
    'fromModal' => false,
];

// Create config first
$detail = TransactionDetailStandard::create($validated['config']);

// Create transaction instance (not saved)
$transaction = new Transaction($validated);
$transaction->user_id = $template->user_id;
$transaction->config()->associate($detail);

// Validate using BalanceCheckpointService before saving (use update-style validation)
$svc = new BalanceCheckpointService();
$result = $svc->validateTransaction($transaction, true);

echo "Simulation validation for template {$templateId}:\n";
var_export($result);
echo "\n";

if ($result['valid']) {
    echo "Validation passed; attempting to push (will roll back after test)\n";
    try {
        \DB::beginTransaction();
        $transaction->push();
        echo "Pushed; transaction id: {$transaction->id}\n";
        \DB::rollBack();
    } catch (Exception $e) {
        echo "Push failed: " . $e->getMessage() . "\n";
        \DB::rollBack();
    }
} else {
    echo "Validation blocked; not pushing.\n";
}
