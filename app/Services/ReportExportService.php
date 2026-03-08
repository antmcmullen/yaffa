<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use App\Models\Transaction;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

class ReportExportService
{
    /**
     * Export transactions and a simple year-end balance sheet for given years.
     *
     * @param array $years
     * @param string $outputPath
     * @return void
     */
    public function export(array $years, string $outputPath, int $userId): void
    {
        $spreadsheet = new Spreadsheet();

        // Transactions sheet
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Transactions');

        $headers = [
            'ID', 'Date', 'Type', 'Account From', 'Account To', 'Amount', 'Currency', 'Categories', 'Tags', 'Comment', 'Reconciled'
        ];

        $sheet->fromArray($headers, null, 'A1');

        $row = 2;

        $txns = Transaction::query()
            ->whereIn(DB::raw('YEAR(date)'), $years)
            ->where('user_id', $userId)
            ->with(['transactionType', 'transactionItems.category', 'transactionItems.tags', 'transactionType'])
            ->orderBy('date')
            ->get();

        $sanitize = function ($v) {
            if (is_string($v) && str_starts_with($v, '=')) {
                return '\'' . $v;
            }
            return $v;
        };

        foreach ($txns as $txn) {
            $txn->loadDetails();

            $amount = $txn->transactionItems->sum('amount');

            $categories = $txn->transactionItems->pluck('category')->filter()->map(fn ($c) => $c->name)->implode(', ');
            $tags = $txn->transactionItems->flatMap(fn ($i) => $i->tags)->unique('id')->pluck('name')->implode(', ');

            $accountFrom = null;
            $accountTo = null;

            // Try to derive account names from config if available (best-effort)
            if ($txn->isStandard() && $txn->config) {
                $cfg = $txn->config;
                if (isset($cfg->account_from_name)) {
                    $accountFrom = $cfg->account_from_name;
                }
                if (isset($cfg->account_to_name)) {
                    $accountTo = $cfg->account_to_name;
                }
            }

            $currency = optional($txn->transaction_currency)->code ?? null;

            $rowData = [
                $txn->id,
                $txn->date?->toDateString(),
                $txn->transactionType->name ?? null,
                $accountFrom,
                $accountTo,
                $amount,
                $currency,
                $categories,
                $tags,
                $txn->comment,
                $txn->reconciled ? 'yes' : 'no',
            ];

            $rowData = array_map($sanitize, $rowData);

            $sheet->fromArray($rowData, null, "A{$row}");

            $row++;
        }

        // Year-end balances sheet (simple opening_balance listing per account)
        $balancesSheet = $spreadsheet->createSheet();
        $balancesSheet->setTitle('YearEndBalances');

        $col = 1;
        $balancesSheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', 'Account');
        $col++;
        foreach ($years as $i => $y) {
            $cell = Coordinate::stringFromColumnIndex($col + $i) . '1';
            $balancesSheet->setCellValue($cell, (string) $y);
        }

        $accounts = Account::query()
            ->whereHas('config', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->orderBy('id')
            ->get();
        $r = 2;
        foreach ($accounts as $acct) {
            $balancesSheet->setCellValue('A' . $r, $acct->config->name ?? ('Account ' . $acct->id));
            foreach ($years as $i => $y) {
                // Simple placeholder: show opening balance. Detailed balance calc is out of scope here.
                $cell = Coordinate::stringFromColumnIndex(2 + $i) . $r;
                $balancesSheet->setCellValue($cell, $acct->opening_balance);
            }
            $r++;
        }

        // Ensure directory exists
        $dir = dirname($outputPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($outputPath);
    }
}
