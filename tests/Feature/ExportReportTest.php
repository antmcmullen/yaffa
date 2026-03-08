<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;
use App\Models\Transaction;
use App\Models\TransactionItem;

class ExportReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_generates_excel_file()
    {
        $user = User::factory()->create(['id' => 4]);

        $txn = Transaction::factory()->create([
            'user_id' => $user->id,
            'date' => '2024-06-01',
        ]);

        TransactionItem::factory()->create([
            'transaction_id' => $txn->id,
            'amount' => 123.45,
        ]);

        $output = storage_path('app/exports/test-report.xlsx');
        if (! is_dir(dirname($output))) {
            mkdir(dirname($output), 0755, true);
        }

        $this->artisan('yaffa:export-report', ['--years' => '2024,2025', '--user' => 4, '--output' => $output])
            ->assertExitCode(0);

        $this->assertFileExists($output);

        // cleanup
        @unlink($output);
    }
}
