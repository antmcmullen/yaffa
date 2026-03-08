<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Transaction;
use App\Services\BalanceCheckpointService;

$id = $argv[1] ?? 24991;

$transaction = Transaction::with('config')->find($id);
if (! $transaction) {
    echo "Transaction {$id} not found\n";
    exit(2);
}

$svc = new BalanceCheckpointService();

echo "Transaction {$id} summary:\n";
echo " - date: " . $transaction->date . "\n";
echo " - config_type: " . $transaction->config_type . "\n";
echo " - config: \n";
var_export($transaction->config->toArray());
echo "\n\n";

echo "validateTransaction (isUpdate = false) result:\n";
$resultFalse = $svc->validateTransaction($transaction, false);
var_export($resultFalse);
echo "\n\n";

echo "validateTransaction (isUpdate = true) result:\n";
$resultTrue = $svc->validateTransaction($transaction, true);
var_export($resultTrue);
echo "\n\n";

// Print active checkpoints for involved accounts (if any)
$accounts = [];
if ($transaction->isStandard()) {
    if (!empty($transaction->config->account_from_id)) $accounts[] = $transaction->config->account_from_id;
    if (!empty($transaction->config->account_to_id)) $accounts[] = $transaction->config->account_to_id;
} elseif ($transaction->isInvestment()) {
    if (!empty($transaction->config->account_id)) $accounts[] = $transaction->config->account_id;
}

$accounts = array_unique($accounts);

foreach ($accounts as $acct) {
    echo "Active checkpoints for account entity {$acct}:\n";
    $cps = \App\Models\AccountBalanceCheckpoint::active()->forAccount($acct)->orderBy('checkpoint_date','desc')->get();
    foreach ($cps as $cp) {
        echo " - id={$cp->id} date=" . $cp->checkpoint_date->format('Y-m-d') . " balance={$cp->balance} active={$cp->active}\n";
    }
    if ($cps->isEmpty()) echo " - none\n";
}

