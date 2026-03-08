<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Trading212StatementParser;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use App\Models\AccountEntity;

class ImportTrading212Statement extends Command
{
    protected $signature = 'trading212:preview {file=storage/app/file.json} {--commit}';
    protected $description = 'Parse Trading212 statement JSON and preview trades/positions vs DB';

    public function handle(Trading212StatementParser $parser, Filesystem $fs): int
    {
        $file = $this->argument('file');

        if (! $fs->exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $this->info('Parsing statement: ' . $file);

        $data = json_decode($fs->get($file), true);
        if (! $data) {
            $this->error('Unable to decode JSON');
            return 1;
        }

        $result = $parser->parse($data);

        // Compare positions to DB holdings where possible
        foreach ($result['accounts'] as $key => &$account) {
            // Attempt to find an AccountEntity by name
            $name = $account['name'] ?? null;
            $account['db_match'] = null;
            if ($name) {
                $match = AccountEntity::whereHas('config', function ($q) use ($name) {
                    $q->where('name', 'like', "%" . $name . "%");
                })->first();
                if ($match) {
                    $account['db_match'] = [
                        'id' => $match->id,
                        'name' => $match->name,
                    ];
                }
            }

            // Basic reconciliation: load investments linked to account if present
            $account['reconciliation'] = [];
            if ($account['db_match']) {
                $ae = AccountEntity::find($account['db_match']['id']);
                if ($ae) {
                    $investments = DB::table('investments')->where('account_entity_id', $ae->id)->get();
                } else {
                    $investments = collect();
                }
            } else {
                $investments = collect();
            }

            // For each parsed position try to match by symbol or ISIN in investments table
            $account['reconciliation']['positions'] = [];
            foreach ($account['positions'] as $pos) {
                $symbol = $pos['symbol'] ?? null;
                $isin = $pos['isin'] ?? null;
                $matched = null;

                if ($symbol) {
                    $matched = DB::table('investments')->where('symbol', $symbol)->first();
                }
                if (! $matched && $isin) {
                    $matched = DB::table('investments')->where('isin', $isin)->first();
                }

                $dbQty = $matched->quantity ?? null;
                $parsedQty = $pos['quantity'] ?? null;
                $diff = null;
                if (is_numeric($dbQty) && is_numeric($parsedQty)) {
                    $diff = $parsedQty - floatval($dbQty);
                }

                $account['reconciliation']['positions'][] = [
                    'parsed' => $pos,
                    'matched_investment' => $matched ? [
                        'id' => $matched->id,
                        'symbol' => $matched->symbol ?? null,
                        'isin' => $matched->isin ?? null,
                        'quantity' => $matched->quantity ?? null,
                    ] : null,
                    'difference' => $diff,
                ];
            }
        }

        // Write outputs to storage/trading212-preview
        $outDir = storage_path('app/trading212-preview');
        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $reportPath = $outDir . '/report.json';
        file_put_contents($reportPath, json_encode($result, JSON_PRETTY_PRINT));

        $this->info('Report written to: ' . $reportPath);

        // write CSVs for accounts
        foreach ($result['accounts'] as $accountKey => $account) {
            $csvPath = $outDir . "/account_{$accountKey}_positions.csv";
            $fh = fopen($csvPath, 'w');
            fputcsv($fh, ['symbol', 'name', 'quantity', 'price', 'currency', 'market_value']);
            foreach ($account['positions'] as $pos) {
                fputcsv($fh, [$pos['symbol'] ?? '', $pos['name'] ?? '', $pos['quantity'] ?? '', $pos['price'] ?? '', $pos['currency'] ?? '', $pos['market_value'] ?? '']);
            }
            fclose($fh);
            $this->info('Wrote ' . $csvPath);
        }

        $this->line('Preview complete.');

        if ($this->option('commit')) {
            $this->warn('Commit option requested, but posting is not implemented in preview command.');
        }

        return 0;
    }
}
