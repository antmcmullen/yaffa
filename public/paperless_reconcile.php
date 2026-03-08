<?php
// Minimal paperless-ngx reconciliation UI
// Place in public/ and open in browser: http://<host>/paperless_reconcile.php

$defaultHost = getenv('PAPERLESS_HOST') ?: 'http://192.168.1.208:8000';
$defaultToken = getenv('PAPERLESS_API_TOKEN') ?: getenv('PAPERLESS_API_KEY') ?: '';

function fetch_paperless_text(string $host, string $token, string $docId): array {
    $host = rtrim($host, '/');
    $headers = ["Authorization: Token $token", "Accept: application/json"];
    // First try pages endpoint
    $url = "$host/api/documents/$docId/pages/";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        $json = json_decode($res, true);
        $texts = [];
        if (is_array($json)) {
            foreach ($json as $p) {
                if (!empty($p['ocr_text'])) $texts[] = $p['ocr_text'];
                elseif (!empty($p['rendered_text'])) $texts[] = $p['rendered_text'];
            }
        }
        if (!empty($texts)) return ['ok' => true, 'text' => implode("\n\n", $texts)];
    }
    // Fallback: try document endpoint for extracted_text
    $url = "$host/api/documents/$docId/";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        $json = json_decode($res, true);
        if (!empty($json['extracted_text'])) return ['ok' => true, 'text' => $json['extracted_text']];
        if (!empty($json['text'])) return ['ok' => true, 'text' => $json['text']];
    }
    return ['ok' => false, 'error' => "Failed to fetch document text from $host (HTTP $code)." ];
}

$report = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = $_POST['host'] ?? $defaultHost;
    $token = $_POST['token'] ?? $defaultToken;
    $docId = trim($_POST['doc_id'] ?? '');
    if ($docId === '') { $error = 'Document ID required.'; }
    // handle CSV upload
    $uploadCsv = null;
    if (!empty($_FILES['csv']['tmp_name'])) {
        $target = __DIR__ . '/../scripts/reconcile/input/uploaded.csv';
        if (!move_uploaded_file($_FILES['csv']['tmp_name'], $target)) {
            $error = 'Failed to save uploaded CSV.';
        } else {
            $uploadCsv = $target;
        }
    } else {
        $error = 'Please upload a CSV export from the system.';
    }
    if (!$error) {
        $res = fetch_paperless_text($host, $token, $docId);
        if (!$res['ok']) {
            $error = $res['error'] ?? 'Unknown fetch error';
        } else {
            // save markdown
            $mdPath = __DIR__ . '/../scripts/reconcile/input/paperless_' . preg_replace('/[^a-z0-9_-]/i','_', $docId) . '.md';
            file_put_contents($mdPath, $res['text']);
            // run reconciler
            $cmd = sprintf('php "%s" "%s" "%s" 2>&1',
                __DIR__ . '/../scripts/reconcile/reconcile.php',
                $uploadCsv,
                $mdPath
            );
            $out = [];
            $ret = 0;
            exec($cmd, $out, $ret);
            $report = implode("\n", $out);
            if ($ret !== 0 && !$report) $error = "Reconciler failed (exit $ret).";
        }
    }
}
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Paperless Reconcile</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;max-width:980px;margin:16px} label{display:block;margin-top:8px}</style>
</head>
<body>
<h1>Paperless Reconcile</h1>
<form method="post" enctype="multipart/form-data">
    <label>Paperless Host
        <input name="host" value="<?php echo htmlspecialchars($defaultHost); ?>" style="width:100%">
    </label>
    <label>API Token
        <input name="token" value="<?php echo htmlspecialchars($defaultToken); ?>" style="width:100%">
    </label>
    <label>Document ID
        <input name="doc_id" value="<?php echo htmlspecialchars($_POST['doc_id'] ?? ''); ?>" style="width:200px">
    </label>
    <label>CSV export (upload)</label>
    <input type="file" name="csv" accept="text/csv,text/plain">
    <div style="margin-top:12px"><button type="submit">Fetch & Reconcile</button></div>
</form>
<?php if ($error): ?>
    <h2 style="color:darkred">Error</h2>
    <pre><?php echo htmlspecialchars($error); ?></pre>
<?php endif; ?>
<?php if ($report): ?>
    <h2>Reconciliation Report</h2>
    <pre style="white-space:pre-wrap; background:#f8f8f8; padding:12px; border:1px solid #ddd"><?php echo htmlspecialchars($report); ?></pre>
<?php endif; ?>
</body>
</html>
