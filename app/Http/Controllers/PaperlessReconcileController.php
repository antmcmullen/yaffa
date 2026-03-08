<?php
namespace App\Http\Controllers;

use App\Http\Requests\PaperlessReconcileRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\AccountEntity;
use App\Models\Transaction;

class PaperlessReconcileController extends Controller
{
    public function show()
    {
        $userId = auth()->id();
        $accounts = AccountEntity::where('user_id', $userId)
            ->where('config_type', 'account')
            ->where('active', 1)
            ->orderBy('name')
            ->get();

        return view('paperless.reconcile', ['accounts' => $accounts]);
    }

    public function run(PaperlessReconcileRequest $request)
    {
        // Use server-side Paperless host/token only (do not accept credentials from UI)
        $host = config('app.paperless_host', env('PAPERLESS_HOST', 'http://192.168.1.208:8000'));
        $token = env('PAPERLESS_API_TOKEN', '');
        $docId = $request->input('doc_id');

        // Determine CSV source: either generate from account/date range, or use uploaded CSV
        if ($request->filled('account_id')) {
            $userId = $request->user()->id;
            $accountId = (int) $request->input('account_id');
            $start = $request->input('start');
            $end = $request->input('end');

            // Generate CSV export from selected account + date range (no upload/download)
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

            $bindings = [$userId, $userId, $accountId, $accountId, $accountId, $start, $end];
            $rows = DB::select($sql, $bindings);

            $csvName = 'generated_' . $accountId . '_' . time() . '.csv';
            $csvPath = storage_path('app/reconcile/' . $csvName);
            if (!is_dir(dirname($csvPath))) mkdir(dirname($csvPath), 0777, true);
            $f = fopen($csvPath, 'w');
            $columns = array_keys((array)($rows[0] ?? ['id' => '']));
            fputcsv($f, $columns);
            foreach ($rows as $r) {
                $arr = (array)$r;
                $line = [];
                foreach ($columns as $c) $line[] = $arr[$c] ?? '';
                fputcsv($f, $line);
            }
            fclose($f);
            $csvFull = $csvPath;
        } else {
            // save uploaded CSV
            $csvPath = $request->file('csv')->storeAs('reconcile', 'uploaded_' . time() . '.csv');
            $csvFull = storage_path('app/' . $csvPath);
        }

        // fetch paperless text via HTTP using simple curl (no extra deps)
        $text = $this->fetchPaperlessText($host, $token, $docId);
        if ($text === false) {
            return redirect()->back()->withErrors(['fetch' => 'Failed to fetch document from Paperless-ngx.']);
        }

        $mdName = 'paperless_' . preg_replace('/[^a-z0-9_-]/i','_', $docId) . '_' . time() . '.md';
        $mdPath = storage_path('app/reconcile/' . $mdName);
        if (!is_dir(dirname($mdPath))) mkdir(dirname($mdPath), 0777, true);
        file_put_contents($mdPath, $text);

        // call the existing reconcile script
        $script = base_path('scripts/reconcile/reconcile.php');
        $cmd = 'php ' . escapeshellarg($script) . ' ' . escapeshellarg($csvFull) . ' ' . escapeshellarg($mdPath) . ' --json 2>&1';
        exec($cmd, $output, $ret);
        $rawOut = implode("\n", $output);
        $report = $rawOut;
        $suggestions = null;
        $decoded = json_decode($rawOut, true);
        if (is_array($decoded)) {
            $suggestions = $decoded;
            // Mark matched CSV transactions as reconciled (only if they belong to the current user)
            foreach ($suggestions['matches'] ?? [] as $m) {
                $csv = $m['csv'] ?? null;
                if (is_array($csv) && !empty($csv['"id"'])) {
                    // Note: CSV exporter sometimes quotes the id header; support both variants
                    $tid = (int)($csv['"id"'] ?? $csv['id'] ?? 0);
                    if ($tid > 0) {
                        $tx = Transaction::find($tid);
                        if ($tx && $tx->user_id === ($request->user()->id ?? auth()->id())) {
                            $tx->reconciled = true;
                            $tx->reconciled_at = now();
                            $tx->reconciled_by = $request->user()->id ?? auth()->id();
                            $tx->save();
                        }
                    }
                }
            }
        }

        // log for audit
        Log::info('Paperless reconcile run', ['user_id' => $request->user()->id ?? null, 'doc' => $docId, 'script_ret' => $ret]);

        // ensure the account list is passed to the view (same as show())
        $userId = $request->user()->id ?? auth()->id();
        $accounts = AccountEntity::where('user_id', $userId)
            ->where('config_type', 'account')
            ->where('active', 1)
            ->orderBy('name')
            ->get();

        return view('paperless.reconcile', ['report' => $report, 'md' => $mdName, 'accounts' => $accounts, 'suggestions' => $suggestions, 'selected_account_id' => $accountId ?? null]);
    }

    /**
     * Return a JSON draft transaction for a given suggestion row.
     */
    public function previewDraft(Request $request)
    {
        $this->middleware(['auth']);
        $data = $request->validate([
            'ref' => 'required|string',
            'date' => 'required|date',
            'amount' => 'required|numeric',
            'description' => 'nullable|string',
            'account_id' => 'required|integer',
        ]);

        $amount = (float)$data['amount'];
        $typeName = $amount >= 0 ? 'deposit' : 'withdrawal';

        $draft = [
            'date' => $data['date'],
            'transaction_type' => ['name' => $typeName],
            'comment' => $data['description'] ?? $data['ref'],
            'user_id' => $request->user()->id,
            'cashflow_value' => $amount,
            'config' => [
                // Standard transaction: positive => account_to, negative => account_from
                $amount >= 0 ? 'account_to_id' : 'account_from_id' => (int)$data['account_id'],
                'amount_from' => $amount < 0 ? abs($amount) : null,
                'amount_to' => $amount >= 0 ? $amount : null,
            ],
            'config_type' => 'standard',
        ];

        return response()->json(['draft' => $draft]);
    }

    /**
     * Forward a draft to the existing transactions.createFromDraft flow.
     * Accepts a `draft` JSON payload and renders a form that posts to the createFromDraft route.
     */
    public function createDraft(Request $request)
    {
        $this->middleware(['auth']);
        $draft = $request->input('draft');
        if (is_string($draft)) {
            $draft = json_decode($draft, true);
        }
        if (!is_array($draft)) {
            return redirect()->back()->withErrors(['draft' => 'Invalid draft payload']);
        }

        // Basic ownership/permission check for account ids referenced in config
        $acctId = $draft['config']['account_from_id'] ?? $draft['config']['account_to_id'] ?? null;
        if ($acctId !== null) {
            $acct = AccountEntity::find((int)$acctId);
            if (!$acct || $acct->user_id !== $request->user()->id) {
                return redirect()->back()->withErrors(['account' => 'Account not found or not owned by you']);
            }
        }

        // Render an auto-submitting form that calls transactions.createFromDraft
        $html = '<form id="f" method="post" action="' . route('transactions.createFromDraft') . '">';
        $html .= csrf_field();
        $html .= '<input type="hidden" name="transaction" value="' . e(json_encode($draft)) . '">';
        $html .= '<input type="hidden" name="mail_id" value="">';
        $html .= '</form>';
        $html .= '<script>document.getElementById("f").submit();</script>';

        return response($html);
    }

    protected function fetchPaperlessText(string $host, string $token, string $docId)
    {
        $host = rtrim(trim($host), '/');
        $token = trim($token);
        $headers = ["Authorization: Token $token"];
        // try pages
        $url = $host . '/api/documents/' . rawurlencode($docId) . '/pages/';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        $curlErr = null;
        if ($res === false) {
            $curlErr = curl_error($ch);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false) {
            Log::warning('Paperless pages curl error', ['url' => $url, 'err' => $curlErr]);
        }
        if ($code >= 200 && $code < 300) {
            $json = json_decode($res, true);
            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Paperless pages JSON decode failed', ['url' => $url, 'err' => json_last_error_msg(), 'resp' => substr($res ?? '', 0, 200)]);
            }
            $texts = [];
            if (is_array($json)) {
                foreach ($json as $p) {
                    if (!empty($p['ocr_text'])) $texts[] = $p['ocr_text'];
                    elseif (!empty($p['rendered_text'])) $texts[] = $p['rendered_text'];
                }
            }
            if (!empty($texts)) return implode("\n\n", $texts);
        } else {
            Log::warning('Paperless pages fetch failed', ['url' => $url, 'code' => $code, 'resp' => substr($res ?? '', 0, 200)]);
        }
        // fallback to document
        $url = $host . '/api/documents/' . rawurlencode($docId) . '/';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        $curlErr = null;
        if ($res === false) {
            $curlErr = curl_error($ch);
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false) {
            Log::warning('Paperless document curl error', ['url' => $url, 'err' => $curlErr]);
        }
        if ($code >= 200 && $code < 300) {
            $json = json_decode($res, true);
            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Paperless document JSON decode failed', ['url' => $url, 'err' => json_last_error_msg(), 'resp' => substr($res ?? '', 0, 200)]);
            }
            if (!empty($json['extracted_text'])) return $json['extracted_text'];
            if (!empty($json['text'])) return $json['text'];
            // Some Paperless-ngx installs expose the OCR/markdown under `content`
            if (!empty($json['content'])) return $json['content'];
        } else {
            Log::warning('Paperless document fetch failed', ['url' => $url, 'code' => $code, 'resp' => substr($res ?? '', 0, 200)]);
        }
        return false;
    }
}
