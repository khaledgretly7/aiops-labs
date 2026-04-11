<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\App\Http\Middleware\TelemetryMiddleware::class);
})
   ->withExceptions(function (Exceptions $exceptions) {
    $exceptions->shouldRenderJsonWhen(function ($request, $e) {
        return $request->is('api/*');
    });

    $exceptions->render(function (\Throwable $e, $request) {
        $category = match(true) {
            $e instanceof \Illuminate\Validation\ValidationException  => 'VALIDATION_ERROR',
            $e instanceof \Illuminate\Database\QueryException         => 'DATABASE_ERROR',
            str_contains(strtolower($e->getMessage()), 'timeout')    => 'TIMEOUT_ERROR',
            $e instanceof \Symfony\Component\HttpKernel\Exception\HttpException => 'SYSTEM_ERROR',
            default => 'UNKNOWN',
        };

        app()->instance('error_category', $category);

        if ($request->is('api/*')) {
            $status = $e instanceof \Illuminate\Validation\ValidationException ? 422
                    : ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException ? $e->getStatusCode() : 500);

            return response()->json([
                'status'         => 'error',
                'error_category' => $category,
                'message'        => $e->getMessage(),
                'errors'         => $e instanceof \Illuminate\Validation\ValidationException ? $e->errors() : null,
            ], $status);
        }
    });
})->create();