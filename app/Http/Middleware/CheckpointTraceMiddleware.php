<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CheckpointTraceMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // No-op when tracing is disabled in config
        if (!config('yaffa.balance_checkpoint_trace_enabled')) {
            return $next($request);
        }

        $traceId = Str::uuid()->toString();
        $ts = now()->toDateTimeString();

        $entry = [
            'ts' => $ts,
            'trace_id' => $traceId,
            'path' => $request->path(),
            'method' => $request->method(),
            'user_id' => optional($request->user())->id,
            'ip' => $request->ip(),
            'headers' => $this->filterHeaders($request->headers->all()),
            'input' => $this->filterInput($request->all()),
        ];

        $this->appendLog($entry);

        $response = $next($request);

        $entry['response_status'] = $response->getStatusCode();
        $entry['response_length'] = strlen((string) $response->getContent());

        $this->appendLog($entry);

        // Expose trace id to client for correlation if needed
        $response->headers->set('X-Checkpoint-Trace-Id', $traceId);

        return $response;
    }

    protected function appendLog(array $entry): void
    {
        $path = storage_path('logs/checkpoint_request_trace.log');
        file_put_contents($path, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    protected function filterHeaders(array $headers): array
    {
        // Keep common headers useful for debugging, hide long values
        $keep = ['user-agent', 'content-type', 'referer', 'x-requested-with', 'accept'];
        $out = [];
        foreach ($headers as $k => $v) {
            $lk = strtolower($k);
            if (in_array($lk, $keep, true)) {
                $out[$lk] = is_array($v) ? $v : [$v];
            }
        }
        return $out;
    }

    protected function filterInput(array $input): array
    {
        // Avoid logging binary or excessively large content
        $filtered = [];
        foreach ($input as $k => $v) {
            if (is_string($v) && strlen($v) > 1000) {
                $filtered[$k] = '[truncated]';
            } else {
                $filtered[$k] = $v;
            }
        }
        return $filtered;
    }
}
