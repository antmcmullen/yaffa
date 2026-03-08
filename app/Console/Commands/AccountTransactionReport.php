<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;

class AccountTransactionReport extends Command
{
    protected $signature = 'yaffa:account-report {account_id : Account id} {--user=1 : User id} {--limit=1000}';

    protected $description = 'Produce a transactions report for a given account using the DB models and query builder.';

    public function handle(): int
    {
        $accountId = (int) $this->argument('account_id');
        $userId = (int) $this->option('user');
        $limit = (int) $this->option('limit');

        $query = $this->buildQueryForAccount($accountId, $userId);

        $this->info("Generating report for account {$accountId} (user {$userId})...");

        $rows = $query->limit($limit)->get();

        if ($rows->isEmpty()) {
            $this->info('No rows found.');
            return 0;
        }

        $headers = array_keys((array) $rows->first());
        $this->table($headers, $rows->map(function ($r) {
            return (array) $r;
        })->toArray());

        return 0;
    }

    /**
     * Build the query used for the report so it can be inspected/tested.
     */
    public function buildQueryForAccount(int $accountId, int $userId): Builder
    {
        $t = 'transactions';

        $base = DB::table($t)
            ->select([
                "{$t}.id",
                DB::raw("COALESCE({$t}.date, DATE({$t}.created_at)) as effective_date"),
                'transaction_types.name as transaction_type',
                "{$t}.comment",
                "{$t}.cashflow_value",
                'transaction_items.category_id as category_id',
                'transaction_details_standard.account_from_id as account_from_id',
                'transaction_details_standard.account_to_id as account_to_id',
                'transaction_details_investment.account_id as investment_account_id',
                DB::raw('COALESCE(transaction_items.amount, transaction_details_standard.amount_from, 0) AS amt_from'),
                DB::raw("CASE WHEN {$t}.config_type LIKE '%Invest%' THEN (COALESCE(transaction_details_investment.quantity,0)*COALESCE(transaction_details_investment.price,0)) - COALESCE(transaction_details_investment.commission,0) - COALESCE(transaction_details_investment.tax,0) + COALESCE(transaction_details_investment.dividend,0) ELSE COALESCE(transaction_items.amount, transaction_details_standard.amount_to, 0) END AS amt_to"),
                DB::raw("CASE WHEN {$t}.cashflow_value IS NULL THEN -1 ELSE transaction_items.category_id END AS adj_category_id"),
                'L1.name as reporting_level_1',
                'L2.name as reporting_level_2',
                'L3.name as reporting_bottom_level',
                'acc_from.name as acc_from_name',
                'acc_to.name as acc_to_name',
                "{$t}.config_type",
                'tags_aggs.tag',
                'transaction_details_investment.price',
                'transaction_details_investment.quantity',
                'transaction_details_investment.commission',
                'transaction_details_investment.tax',
                'transaction_details_investment.dividend',
                'transaction_details_investment.investment_id',
            ])
            ->leftJoin('transaction_types', "{$t}.transaction_type_id", '=', 'transaction_types.id')
            ->leftJoin('transaction_items', 'transaction_items.transaction_id', '=', "{$t}.id")
            ->leftJoin('transaction_details_standard', function ($join) use ($t) {
                $join->on("{$t}.config_id", '=', 'transaction_details_standard.id')
                     ->where("{$t}.config_type", 'like', '%Standard%');
            })
            ->leftJoin('transaction_details_investment', function ($join) use ($t) {
                $join->on("{$t}.config_id", '=', 'transaction_details_investment.id')
                     ->where("{$t}.config_type", 'like', '%Invest%');
            })
            ->leftJoin('categories as L3', DB::raw("CASE WHEN {$t}.cashflow_value IS NULL THEN -1 ELSE transaction_items.category_id END"), '=', 'L3.id')
            ->leftJoin('categories as L2', 'L3.parent_id', '=', 'L2.id')
            ->leftJoin('categories as L1', 'L2.parent_id', '=', 'L1.id')
            ->leftJoin('account_entities as acc_from', 'transaction_details_standard.account_from_id', '=', 'acc_from.id')
            ->leftJoin('account_entities as acc_to', 'transaction_details_standard.account_to_id', '=', 'acc_to.id')
            ->leftJoin(DB::raw('(SELECT transaction_item_id, GROUP_CONCAT(DISTINCT tags.name ORDER BY tag_id) AS tag FROM transaction_items_tags LEFT JOIN tags ON transaction_items_tags.tag_id = tags.id AND tags.user_id = '.$userId.' GROUP BY transaction_item_id) as tags_aggs'), 'transaction_items.id', '=', 'tags_aggs.transaction_item_id')
                        ->where("{$t}.user_id", $userId)
                        ->where(function ($q) use ($accountId) {
                                $q->where('transaction_details_standard.account_from_id', $accountId)
                                    ->orWhere('transaction_details_standard.account_to_id', $accountId)
                                    ->orWhere('transaction_details_investment.account_id', $accountId);
                        })
            ->orderBy('effective_date')
            ->orderBy('transactions.id');

        return $base;
    }
}
