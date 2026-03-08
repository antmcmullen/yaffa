<?php

// Usage:
// php scripts/reassign_account_transactions.php --from=429 --to=7794 --before=2024-05-29 [--dry-run] [--apply]

require __DIR__ . '/../vendor/autoload.php';

use App\Models\TransactionDetailStandard;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Transaction;

// Bootstrap the Laravel app minimally
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

 $opts = getopt('', ['from:', 'to:', 'before:', 'dry-run', 'apply', 'skip-errors']);

 if (empty($opts['from']) || empty($opts['to']) || empty($opts['before'])) {
    echo "Usage: php scripts/reassign_account_transactions.php --from=429 --to=7794 --before=2024-05-29 [--dry-run] [--apply] [--skip-errors]\n";
    exit(1);
}

$from = (int) $opts['from'];
$to = (int) $opts['to'];
$before = new Carbon($opts['before']);
 $dryRun = isset($opts['dry-run']);
 $apply = isset($opts['apply']);
 $skipErrors = isset($opts['skip-errors']);

if ($dryRun && $apply) {
    echo "Cannot use --dry-run and --apply together.\n";
    exit(1);
}

echo "Scanning transactions: account={$from}, before={$before->toDateString()}\n";

// Fetch candidate transactions and filter in PHP to avoid complex SQL depending on DB schema
$candidates = Transaction::where('config_type', 'standard')
    ->whereDate('date', '<', $before->toDateString())
    ->with('config')
    ->get();

$filtered = $candidates->filter(function (Transaction $tx) use ($from) {
    $detail = $tx->config;
    if (! $detail) {
        return false;
    }
    return ($detail->account_from_id === $from) || ($detail->account_to_id === $from);
});

$count = $filtered->count();
echo "Found {$count} matching transactions.\n";

if ($count === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

$ids = $filtered->pluck('id')->toArray();
echo "Transaction IDs sample: " . implode(', ', array_slice($ids, 0, 20)) . (count($ids) > 20 ? ', ...' : '') . "\n";

if ($dryRun) {
    echo "Dry-run mode; no changes will be made.\n";
    exit(0);
}

if (!$apply) {
    echo "To apply changes re-run with --apply.\n";
    exit(0);
}

// Backup related detail rows to SQL file first
$backupPath = __DIR__ . '/reassign_backup_' . date('Ymd_His') . '.sql';
$fp = fopen($backupPath, 'w');
fwrite($fp, "-- Backup of transaction_details_standard rows for transactions moved from account {$from} to {$to} before {$before->toDateString()}\n");

$detailIds = $filtered->pluck('config_id')->filter()->unique()->values()->toArray();
$details = TransactionDetailStandard::where(function ($q) use ($from) {
    $q->where('account_from_id', $from)->orWhere('account_to_id', $from);
})->whereIn('id', $detailIds)->get();

foreach ($details as $row) {
    $attrs = $row->getAttributes();
    $cols = array_map(function ($c) { return "`$c`"; }, array_keys($attrs));
    $vals = array_map(function ($v) { return is_null($v) ? 'NULL' : "'" . addslashes($v) . "'"; }, array_values($attrs));
    fwrite($fp, "INSERT INTO `transaction_details_standard` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n");
}
fclose($fp);
echo "Backup written to {$backupPath}\n";

// Perform updates in a DB transaction
DB::beginTransaction();
try {
    $updated = 0;
    $skipped = [];
    $transactions = $filtered->values();
    foreach ($transactions as $tx) {
        /** @var TransactionDetailStandard $detail */
        $detail = $tx->config;
        $changed = false;
        if ($detail->account_from_id === $from) {
            $detail->account_from_id = $to;
            $changed = true;
        }
        if ($detail->account_to_id === $from) {
            $detail->account_to_id = $to;
            $changed = true;
        }
        if ($changed) {
            try {
                $detail->save();
                $updated++;
            } catch (\Exception $e) {
                if ($skipErrors) {
                    $skipped[] = $tx->id;
                    continue;
                }
                throw $e;
            }
        }
    }
    DB::commit();
    echo "Reassignment applied. Updated {$updated} transaction detail rows.\n";
    if (!empty($skipped)) {
        echo "Skipped " . count($skipped) . " transactions due to errors: " . implode(', ', $skipped) . "\n";
    }
} catch (\Exception $e) {
    DB::rollBack();
    echo "Error during reassignment: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Done. Please run account summary recalculation if needed.\n";
