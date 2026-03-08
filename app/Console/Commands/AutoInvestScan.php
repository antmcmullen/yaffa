<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Services\AutoInvestmentService;

class AutoInvestScan extends Command
{
    protected $signature = 'yaffa:auto-invest-scan {--since= : Only scan transactions on or after this date (YYYY-MM-DD)}';

    protected $description = 'Scan past standard transactions and create missing automated investment buy/sell transactions.';

    public function handle(): int
    {
        $since = $this->option('since');

        $query = Transaction::query()->where('config_type', 'standard')
            ->whereHas('transactionType', function ($q) {
                $q->whereIn('name', ['deposit', 'withdrawal', 'transfer']);
            });

        if ($since) {
            $query->where('date', '>=', $since);
        }

        $service = new AutoInvestmentService();

        $bar = $this->output->createProgressBar($query->count());
        $bar->start();

        $query->get()->each(function (Transaction $tx) use ($service, $bar) {
            $service->handleTransaction($tx);
            $bar->advance();
        });

        $bar->finish();
        $this->info('\nDone.');

        return 0;
    }
}
