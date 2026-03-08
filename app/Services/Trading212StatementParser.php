<?php

namespace App\Services;

class Trading212StatementParser
{
    /**
     * Parse the parsed PDF JSON structure (Docling style) and extract accounts, positions and trades.
     * This implementation focuses on extracting "open positions" tables and basic metadata.
     *
     * @param array $doc Parsed JSON
     * @return array
     */
    public function parse(array $doc): array
    {
        $result = [
            'meta' => [],
            'accounts' => [],
        ];

        // naive scan: extract all texts and look for section headers containing "account" and "open positions"
        $texts = $this->indexTexts($doc);

        // First, try occurrences search for 'open positions'
        $occurrences = $this->findOccurrences($doc, 'open positions');
        foreach ($occurrences as $i => $occ) {
            $pageNo = $occ['page_no'] ?? null;
            $accountName = $this->findAccountNameNear($doc, $pageNo);
            $accountKey = $this->slugify($accountName ?: ('account_' . $i));
            $positions = $this->extractPositionsTableNear($doc, $pageNo, $occ['bbox'] ?? null);

            $result['accounts'][$accountKey] = [
                'name' => $accountName,
                'positions' => $positions,
            ];
        }

        // Also scan all tables for recognizable holdings tables (headers with instrument + quantity)
        $allTables = $this->findTablesRecursive($doc);
        // Debug: dump first few tables to storage for inspection
        try {
            $outDir = storage_path('app/trading212-preview');
            if (! is_dir($outDir)) {
                mkdir($outDir, 0755, true);
            }
            file_put_contents($outDir . '/debug_tables.json', json_encode(array_slice($allTables, 0, 5), JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            // ignore
        }
        foreach ($allTables as $table) {
            if (! isset($table['rows']) || ! is_array($table['rows'])) {
                continue;
            }
            // detect header row index
            $headerRowIndex = null;
            foreach ($table['rows'] as $ri => $row) {
                foreach ($row['cells'] as $cell) {
                    $t = mb_strtolower($cell['text'] ?? '');
                    if (str_contains($t, 'instrument') && str_contains($t, 'quantity')) {
                        $headerRowIndex = $ri;
                        break 2;
                    }
                }
            }
            if ($headerRowIndex === null) {
                // try looser detection: any row containing 'instrument' or 'quantity'
                foreach ($table['rows'] as $ri => $row) {
                    $foundInst = $foundQty = false;
                    foreach ($row['cells'] as $cell) {
                        $t = mb_strtolower($cell['text'] ?? '');
                        if (str_contains($t, 'instrument')) {
                            $foundInst = true;
                        }
                        if (str_contains($t, 'quantity') || str_contains($t, 'qty')) {
                            $foundQty = true;
                        }
                    }
                    if ($foundInst || $foundQty) {
                        $headerRowIndex = $ri;
                        break;
                    }
                }
            }
            if ($headerRowIndex === null) {
                continue;
            }

            // extract positions from this table
            $positions = $this->extractPositionsFromTable($table, $headerRowIndex);

            if (empty($positions)) {
                continue;
            }

            // try to find account name by page_no
            $pageNo = $table['prov'][0]['page_no'] ?? null;
            $accountName = $this->findAccountNameNear($doc, $pageNo);
            $accountKey = $this->slugify($accountName ?: ('account_table_' . ($pageNo ?? 'unknown')));

            if (! isset($result['accounts'][$accountKey])) {
                $result['accounts'][$accountKey] = [
                    'name' => $accountName,
                    'positions' => [],
                ];
            }
            $result['accounts'][$accountKey]['positions'] = array_merge($result['accounts'][$accountKey]['positions'], $positions);
        }

        // Also store some metadata like name
        if (isset($doc['name'])) {
            $result['meta']['name'] = $doc['name'];
        }

        return $result;
    }

    protected function indexTexts(array $doc): array
    {
        $texts = [];
        if (! isset($doc['body']['children']) || ! is_array($doc['body']['children'])) {
            return $texts;
        }
        foreach ($doc['body']['children'] as $ref) {
            if (! isset($ref['$ref'])) {
                continue;
            }
            $path = substr($ref['$ref'], 2); // remove #/
            $parts = explode('/', $path);
            if ($parts[0] === 'texts' && isset($doc['texts'][(int)$parts[1]])) {
                $texts[] = $doc['texts'][(int)$parts[1]];
            }
        }
        return $texts;
    }

    protected function findOccurrences(array $doc, string $needle): array
    {
        $needle = mb_strtolower($needle);
        $found = [];

        // search text nodes
        if (isset($doc['texts']) && is_array($doc['texts'])) {
            foreach ($doc['texts'] as $t) {
                if (isset($t['text']) && mb_stripos($t['text'], $needle) !== false) {
                    $found[] = [
                        'text' => $t['text'],
                        'page_no' => $t['prov'][0]['page_no'] ?? null,
                        'bbox' => $t['prov'][0]['bbox'] ?? null,
                    ];
                }
            }
        }

        // search table cells
        $tables = $this->findTablesRecursive($doc);
        foreach ($tables as $table) {
            // table may be ['rows'=>...]
            if (! isset($table['rows'])) {
                continue;
            }
            foreach ($table['rows'] as $row) {
                foreach ($row['cells'] as $cell) {
                    $text = $cell['text'] ?? '';
                    if ($text && mb_stripos($text, $needle) !== false) {
                        $found[] = [
                            'text' => $text,
                            'page_no' => $table['prov'][0]['page_no'] ?? null,
                            'bbox' => $cell['bbox'] ?? null,
                        ];
                    }
                }
            }
        }

        return $found;
    }

    protected function findAccountNameNear(array $doc, $pageNo = null): ?string
    {
        // scan texts on the same page for lines containing 'account' and return the closest
        if (! isset($doc['texts']) || ! is_array($doc['texts'])) {
            return null;
        }
        foreach ($doc['texts'] as $t) {
            if (($t['prov'][0]['page_no'] ?? null) === $pageNo) {
                if (isset($t['text']) && mb_stripos($t['text'], 'account') !== false) {
                    return $t['text'];
                }
            }
        }
        return null;
    }

    protected function findAccountNameBefore(array $texts, int $index): ?string
    {
        for ($j = $index - 1; $j >= 0 && $j >= $index - 8; $j--) {
            $t = $texts[$j]['text'] ?? '';
            if (preg_match('/(invest|stocks isa|cash isa|invest account|stocks isa account|cash isa account)/i', $t, $m)) {
                return $t;
            }
            if (trim($t) !== '') {
                // heuristics: account name lines often include 'Trading 212' or account type
                if (preg_match('/^\s*[A-Za-z0-9\-\s]+\s*account/i', $t)) {
                    return $t;
                }
            }
        }
        return null;
    }

    protected function slugify(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9]+/', '_', mb_strtolower($s));
        return trim($s, '_');
    }

    protected function extractPositionsTableNear(array $doc, $pageNo = null, $bbox = null): array
    {
        // Very lightweight extraction: search doc->tables entries and match page_no
        $positions = [];
        $tables = [];
        if (isset($doc['tables']) && is_array($doc['tables'])) {
            $tables = $doc['tables'];
        } else {
            // Fallback: scan recursively to find any sub-objects that look like tables (have 'rows')
            $tables = $this->findTablesRecursive($doc);
        }

        foreach ($tables as $table) {
            if (!is_array($table) || !isset($table['rows']) || !is_array($table['rows'])) {
                continue;
            }
            if ($pageNo && isset($table['prov'][0]['page_no']) && $table['prov'][0]['page_no'] != $pageNo) {
                continue;
            }
            // attempt to map columns by header names (normalize common variants)
            $headers = [];
            // try to find header row by checking 'column_header' flag
            $headerRowIndex = null;
            foreach ($table['rows'] as $ri => $row) {
                foreach ($row['cells'] as $cell) {
                    if (!empty($cell['column_header'])) {
                        $headerRowIndex = $ri;
                        break 2;
                    }
                }
            }

            // fallback detection: look for a row containing common header words
            if ($headerRowIndex === null) {
                $headerCandidates = ['instrument', 'quantity', 'qty', 'price', 'value', 'market', 'symbol', 'isin', 'name'];
                foreach ($table['rows'] as $ri => $row) {
                    $matches = 0;
                    foreach ($row['cells'] as $cell) {
                        $text = mb_strtolower($cell['text'] ?? '');
                        foreach ($headerCandidates as $cand) {
                            if (str_contains($text, $cand)) {
                                $matches++;
                                break;
                            }
                        }
                    }
                    if ($matches >= 1) {
                        $headerRowIndex = $ri;
                        break;
                    }
                }
            }

            // fallback: assume first row is header
            if ($headerRowIndex === null) {
                $headerRowIndex = 0;
            }

            if (! empty($table['rows'][$headerRowIndex]['cells'])) {
                foreach ($table['rows'][$headerRowIndex]['cells'] as $cell) {
                    $raw = trim($cell['text'] ?? '');
                    $key = mb_strtolower($raw);
                    // normalize common header names
                    if (str_contains($key, 'instrument') || str_contains($key, 'description') || str_contains($key, 'security')) {
                        $norm = 'name';
                    } elseif (str_contains($key, 'symbol') || str_contains($key, 'ticker')) {
                        $norm = 'symbol';
                    } elseif (str_contains($key, 'isin')) {
                        $norm = 'isin';
                    } elseif (str_contains($key, 'quantity') || str_contains($key, 'qty') || str_contains($key, 'size')) {
                        $norm = 'quantity';
                    } elseif (str_contains($key, 'price') || str_contains($key, 'avg price') || str_contains($key, 'avgprice')) {
                        $norm = 'price';
                    } elseif (str_contains($key, 'value') || str_contains($key, 'market') || str_contains($key, 'value (')) {
                        $norm = 'market_value';
                    } elseif (preg_match('/^\p{Sc}/u', $raw) || preg_match('/[A-Z]{3}/', $raw)) {
                        $norm = 'currency';
                    } else {
                        $norm = $key;
                    }

                    $headers[] = $norm;
                }
            }

            // scan rows following header
            for ($r = $headerRowIndex + 1; $r < count($table['rows']); $r++) {
                $row = $table['rows'][$r]['cells'] ?? [];
                $cols = array_map(fn($c) => $c['text'] ?? '', $row);
                $item = [];
                foreach ($headers as $hi => $h) {
                    $item[$h] = $cols[$hi] ?? null;
                }

                // Heuristics: require quantity and instrument/name or symbol or isin
                if ((isset($item['quantity']) || isset($item['qty'])) && (isset($item['symbol']) || isset($item['name']) || isset($item['isin']) || isset($item['instrument']))) {
                    // if instrument present but name missing, map
                    if (isset($item['instrument']) && ! isset($item['name'])) {
                        $item['name'] = $item['instrument'];
                    }

                    $positions[] = [
                        'raw' => $item,
                        'symbol' => $item['symbol'] ?? null,
                        'name' => $item['name'] ?? null,
                        'isin' => $item['isin'] ?? null,
                        'quantity' => $this->parseNumber($item['quantity'] ?? ($item['qty'] ?? null)),
                        'price' => $this->parseCurrency($item['price'] ?? null),
                        'currency' => $item['currency'] ?? null,
                        'market_value' => $this->parseCurrency($item['market_value'] ?? ($item['market value'] ?? null)),
                    ];
                }
            }
        }

        return $positions;
    }

    protected function findTablesRecursive(array $node): array
    {
        $found = [];
        $stack = [$node];
        while ($stack) {
            $current = array_pop($stack);
            if (! is_array($current)) {
                continue;
            }

            // If this node already has rows, accept it
            if (isset($current['rows']) && is_array($current['rows'])) {
                $found[] = $current;
                continue;
            }

            // Detect flat cell arrays (cells with start_row_offset_idx)
            if ($this->looksLikeCellArray($current)) {
                // group into rows by start_row_offset_idx
                $rowsMap = [];
                foreach ($current as $cell) {
                    $r = $cell['start_row_offset_idx'] ?? 0;
                    $rowsMap[$r][] = $cell;
                }
                ksort($rowsMap);
                $rows = [];
                foreach ($rowsMap as $r => $cells) {
                    // sort cells by start_col_offset_idx
                    usort($cells, function ($a, $b) {
                        return ($a['start_col_offset_idx'] ?? 0) <=> ($b['start_col_offset_idx'] ?? 0);
                    });
                    $rows[] = ['cells' => $cells];
                }
                $found[] = ['rows' => $rows, 'prov' => $current[0]['prov'] ?? null];
                continue;
            }

            foreach ($current as $v) {
                if (is_array($v)) {
                    $stack[] = $v;
                }
            }
        }

        return $found;
    }

    protected function extractPositionsFromTable(array $table, int $headerRowIndex): array
    {
        $positions = [];

        // build headers
        $headers = [];
        if (! empty($table['rows'][$headerRowIndex]['cells'])) {
            foreach ($table['rows'][$headerRowIndex]['cells'] as $cell) {
                $raw = trim($cell['text'] ?? '');
                $key = mb_strtolower($raw);
                if (str_contains($key, 'instrument') || str_contains($key, 'description') || str_contains($key, 'security')) {
                    $norm = 'name';
                } elseif (str_contains($key, 'symbol') || str_contains($key, 'ticker')) {
                    $norm = 'symbol';
                } elseif (str_contains($key, 'isin')) {
                    $norm = 'isin';
                } elseif (str_contains($key, 'quantity') || str_contains($key, 'qty') || str_contains($key, 'size')) {
                    $norm = 'quantity';
                } elseif (str_contains($key, 'price') || str_contains($key, 'avg price')) {
                    $norm = 'price';
                } elseif (str_contains($key, 'value') || str_contains($key, 'market')) {
                    $norm = 'market_value';
                } elseif (preg_match('/^\p{Sc}/u', $raw) || preg_match('/[A-Z]{3}/', $raw)) {
                    $norm = 'currency';
                } else {
                    $norm = $key;
                }
                $headers[] = $norm;
            }
        }

        for ($r = $headerRowIndex + 1; $r < count($table['rows']); $r++) {
            $row = $table['rows'][$r]['cells'] ?? [];
            $cols = array_map(fn($c) => $c['text'] ?? '', $row);
            $item = [];
            foreach ($headers as $hi => $h) {
                $item[$h] = $cols[$hi] ?? null;
            }
            if ((isset($item['quantity']) || isset($item['qty'])) && (isset($item['symbol']) || isset($item['name']) || isset($item['isin']))) {
                if (isset($item['instrument']) && ! isset($item['name'])) {
                    $item['name'] = $item['instrument'];
                }
                $positions[] = [
                    'raw' => $item,
                    'symbol' => $item['symbol'] ?? null,
                    'name' => $item['name'] ?? null,
                    'isin' => $item['isin'] ?? null,
                    'quantity' => $this->parseNumber($item['quantity'] ?? ($item['qty'] ?? null)),
                    'price' => $this->parseCurrency($item['price'] ?? null),
                    'currency' => $item['currency'] ?? null,
                    'market_value' => $this->parseCurrency($item['market_value'] ?? ($item['market value'] ?? null)),
                ];
            }
        }

        return $positions;
    }

    protected function looksLikeCellArray(array $arr): bool
    {
        if (empty($arr)) {
            return false;
        }
        // check first few entries for cell-like keys
        $count = 0;
        $loop = 0;
        foreach ($arr as $item) {
            if (! is_array($item)) {
                return false;
            }
            if (isset($item['start_row_offset_idx']) && isset($item['start_col_offset_idx']) && isset($item['text'])) {
                $count++;
            }
            if ($count >= 3) {
                return true;
            }
            // limit checks
            if ($count === 0 && $loop++ > 10) {
                break;
            }
        }
        return false;
    }

    protected function parseNumber($v)
    {
        if ($v === null) {
            return null;
        }
        $v = preg_replace('/[^0-9\-\.\,]/', '', (string)$v);
        $v = str_replace(',', '', $v);
        return is_numeric($v) ? floatval($v) : null;
    }

    protected function parseCurrency($v)
    {
        if ($v === null) {
            return null;
        }
        // remove currency symbols
        $v = preg_replace('/[^0-9\-\.\,]/', '', (string)$v);
        $v = str_replace(',', '', $v);
        return is_numeric($v) ? floatval($v) : null;
    }
}
