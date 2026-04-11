<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\MetricsService;

class TelemetryMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Skip /metrics endpoint
        if ($request->is('api/metrics') || $request->is('metrics')) {
            return $next($request);
        }

        $startTime = microtime(true);

        // Correlation ID
        $correlationId = $request->header('X-Request-Id') ?: (string) Str::uuid();

        $response = $next($request);

        $latencyMs = round((microtime(true) - $startTime) * 1000, 2);

        // Determine error category
        $errorCategory = null;
        $severity = 'info';
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 400) {
            $severity = 'error';
            $errorCategory = app('error_category') ?? 'SYSTEM_ERROR';
        }

        // Timeout classification: even if 200, if latency > 4000ms = TIMEOUT_ERROR
        if ($latencyMs > 4000) {
            $errorCategory = 'TIMEOUT_ERROR';
            $severity = 'error';
        }

        // Build log record with stable schema
        $logData = [
            'correlation_id'       => $correlationId,
            'timestamp'            => now()->toISOString(),
            'method'               => $request->method(),
            'path'                 => $request->path(),
            'route_name'           => optional($request->route())->getName() ?? 'unknown',
            'status_code'          => $statusCode,
            'latency_ms'           => $latencyMs,
            'error_category'       => $errorCategory,
            'severity'             => $severity,
            'client_ip'            => $request->ip(),
            'user_agent'           => $request->userAgent() ?? null,
            'query'                => $request->getQueryString() ?? null,
            'payload_size_bytes'   => strlen($request->getContent()),
            'response_size_bytes'  => strlen($response->getContent()),
            'host'                 => gethostname(),
            'build_version'        => env('BUILD_VERSION', '1.0.0'),
        ];

        Log::channel('aiops')->info('request', $logData);
        // Record Prometheus metrics
MetricsService::record(
    $request->method(),
    '/' . $request->path(),
    $statusCode,
    $latencyMs / 1000,
    $errorCategory
);

        // Attach correlation ID to response
        $response->headers->set('X-Request-Id', $correlationId);

        return $response;
    }
}