<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\AccountEntity;

class AccountTransactionsQueryController extends Controller
{
    public function form()
    {
        $userId = auth()->id();
        $accounts = AccountEntity::where('user_id', $userId)
            ->where('config_type', 'account')
            ->where('active', 1)
            ->orderBy('name')
            ->get();
        return view('reports.account_transactions_query', ['accounts' => $accounts]);
    }

    public function run(Request $request)
    {
        $request->validate([
            'account_id' => ['required','integer'],
            'start' => ['required','date'],
            'end' => ['required','date'],
        ]);

        $userId = auth()->id();
        $accountId = (int) $request->input('account_id');
        $start = $request->input('start');
        $end = $request->input('end');

        $sql = <<<'SQL'
SELECT
t.id,
COALESCE(t.date, DATE(t.created_at)) AS effective_date,
tt.name AS TRANSACTION_Type,
t.comment,
t.cashflow_value,
transaction_items.category_id AS category_id,
tds.account_from_id,
tds.account_to_id,
tdi.account_id AS investment_account_id,
COALESCE(transaction_items.amount, tds.amount_from, 0) AS amt_from,
CASE
WHEN t.config_type LIKE '%Invest%' THEN
(COALESCE(tdi.quantity,0) * COALESCE(tdi.price,0))
- COALESCE(tdi.commission,0)
- COALESCE(tdi.tax,0)
+ COALESCE(tdi.dividend,0)
ELSE COALESCE(transaction_items.amount, tds.amount_to, 0)
END AS amt_to,
CASE WHEN t.cashflow_value IS NULL THEN -1 ELSE transaction_items.category_id END AS adj_category_id,
COALESCE(L1.name, L2.name) AS reporting_level_1,
COALESCE(L2.name, L3.name) AS reporting_level_2,
L3.name AS reporting_bottom_level,
CASE t.config_type WHEN 'Standard' THEN acc_from.name ELSE CASE WHEN t.cashflow_value < 0 THEN acc_invest.name ELSE invest_name.name END END AS acc_from_name,
CASE t.config_type WHEN 'Standard' THEN acc_to.name ELSE CASE WHEN t.cashflow_value > 0 THEN acc_invest.name ELSE invest_name.name END END AS acc_to_name,
t.config_type,
tags_aggs.tag,
tdi.price,
tdi.quantity,
tdi.commission,
tdi.tax,
tdi.dividend,
tdi.investment_id
FROM transactions t
LEFT JOIN transaction_types tt ON t.transaction_type_id = tt.id
LEFT JOIN transaction_items ON transaction_items.transaction_id = t.id
LEFT JOIN transaction_details_standard tds ON t.config_id = tds.id AND t.config_type LIKE '%Standard%'
LEFT JOIN transaction_details_investment tdi ON t.config_id = tdi.id AND t.config_type LIKE '%Invest%'
LEFT JOIN categories L3 ON (CASE WHEN t.cashflow_value IS NULL THEN -1 ELSE transaction_items.category_id END) = L3.id
LEFT JOIN categories L2 ON L3.parent_id = L2.id
LEFT JOIN categories L1 ON L2.parent_id = L1.id
LEFT JOIN account_entities acc_from ON tds.account_from_id = acc_from.id
LEFT JOIN account_entities acc_to ON tds.account_to_id = acc_to.id
LEFT JOIN account_entities acc_invest ON tdi.account_id = acc_invest.id
LEFT JOIN investments invest_name ON invest_name.id = tdi.investment_id
LEFT JOIN (
    SELECT transaction_item_id, GROUP_CONCAT(DISTINCT tags.name ORDER BY tag_id) AS tag
    FROM transaction_items_tags
    LEFT JOIN tags ON transaction_items_tags.tag_id = tags.id AND tags.user_id = ?
    GROUP BY transaction_item_id
) tags_aggs ON transaction_items.id = tags_aggs.transaction_item_id
WHERE t.user_id = ? AND t.`schedule` = 0
AND (
    tds.account_from_id = ?
    OR tds.account_to_id = ?
    OR tdi.account_id = ?
)
AND COALESCE(t.date, DATE(t.created_at)) BETWEEN ? AND ?
ORDER BY effective_date, t.id
SQL;
        // Bindings must be in order for the positional placeholders used above.
        $bindings = [
            $userId, // tags subquery user_id
            $userId, // main where t.user_id
            $accountId,
            $accountId,
            $accountId,
            $start,
            $end,
        ];
        $rows = DB::select($sql, $bindings);

        // if download requested, stream CSV
        if ($request->has('download')) {
            $filename = 'account_transactions_' . $accountId . '_' . $start . '_' . $end . '.csv';
            $columns = array_keys((array)($rows[0] ?? ['id' => '']));
            $stream = function() use ($rows, $columns) {
                $f = fopen('php://output', 'w');
                fputcsv($f, $columns);
                foreach ($rows as $r) {
                    $line = [];
                    $arr = (array)$r;
                    foreach ($columns as $c) $line[] = $arr[$c] ?? '';
                    fputcsv($f, $line);
                }
                fclose($f);
            };
            return response()->stream($stream, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        $accounts = AccountEntity::where('user_id', $userId)
            ->where('config_type', 'account')
            ->where('active', 1)
            ->orderBy('name')
            ->get();

        return view('reports.account_transactions_query', ['rows' => $rows, 'accounts' => $accounts, 'selected' => $accountId, 'start' => $start, 'end' => $end]);
    }
}
