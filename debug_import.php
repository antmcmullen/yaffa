<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$id = (int)($argv[1] ?? 0);
if ($id <= 0) {
    echo "Usage: php debug_import.php <import_id>\n";
    exit(1);
}

$import = App\Models\ImportJob::find($id);
if (! $import) {
    echo "Import job {$id} not found\n";
    exit(1);
}

echo "=== Import Job {$id} ===\n";
echo "Status: " . $import->status . "\n";
echo "Processed Rows: " . $import->processed_rows . "\n";
echo "Total Rows: " . ($import->total_rows ?? 'null') . "\n";
echo "Started at: " . ($import->started_at?->toDateTimeString() ?? 'null') . "\n";
echo "Finished at: " . ($import->finished_at?->toDateTimeString() ?? 'null') . "\n";
echo "File: " . $import->file_path . "\n\n";

// Count transactions created by this import
$txCount = App\Models\Transaction::where('import_job_id', $id)->count();
echo "Transactions created: {$txCount}\n";

// Show first 10 transactions
$transactions = App\Models\Transaction::where('import_job_id', $id)
    ->with(['config', 'transactionItems.category'])
    ->take(10)
    ->get();

if ($transactions->count() > 0) {
    echo "\n=== Sample Transactions (first 10) ===\n";
    foreach ($transactions as $t) {
        echo "\nTransaction ID: {$t->id}\n";
        echo "Date: {$t->date}\n";
        echo "Config type: {$t->config_type}\n";
        if ($t->config) {
            echo "Config class: " . get_class($t->config) . "\n";
        }
        echo "Items: " . $t->transactionItems->count() . "\n";
    }
}

// Show errors if present
if ($import->errors && is_array($import->errors) && count($import->errors) > 0) {
    echo "\n=== Import Errors (first 20) ===\n";
    foreach (array_slice($import->errors, 0, 20) as $e) {
        echo $e . "\n";
    }
}

// Check CSV file row count if file exists
$filePath = storage_path('app/' . $import->file_path);
if (file_exists($filePath)) {
    $fh = fopen($filePath, 'r');
    $header = fgetcsv($fh);
    $rows = 0;
    while (fgetcsv($fh) !== false) $rows++;
    fclose($fh);
    echo "\nCSV rows: {$rows}\n";
    echo "Import processed_rows: {$import->processed_rows}\n";
}

// Check Laravel failed jobs table for related entries
try {
    $failed = \DB::table('failed_jobs')->where('payload', 'like', '%"importJobId":' . $id . '%')->get();
    if ($failed->count()) {
        echo "\nFound " . $failed->count() . " failed_jobs entries related to this import:\n";
        foreach ($failed as $f) {
            echo "- " . $f->exception . "\n";
        }
    }
} catch (\Throwable $e) {
    // ignore if table not present
}

echo "\nDone.\n";
