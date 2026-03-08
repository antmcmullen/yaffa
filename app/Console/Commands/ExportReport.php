<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ReportExportService;

class ExportReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'yaffa:export-report {--years=} {--user=} {--output=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Export transactions and summary for given years to an Excel file.';

    public function handle(ReportExportService $service): int
    {
        $yearsOption = $this->option('years') ?? '';
        $years = array_filter(array_map('trim', explode(',', $yearsOption)));

        if (empty($years)) {
            $this->error('Please provide --years, e.g. --years=2024,2025');
            return 1;
        }

        $output = $this->option('output') ?: storage_path('app/exports/yaffa-report-' . implode('-', $years) . '.xlsx');

        $userOption = $this->option('user');
        if (! $userOption || ! is_numeric($userOption)) {
            $this->error('Please provide numeric --user, e.g. --user=4');
            return 1;
        }
        $userId = (int) $userOption;

        $this->info('Generating export for years: ' . implode(', ', $years));

        $service->export($years, $output, $userId);

        $this->info('Wrote export to: ' . $output);

        return 0;
    }
}
