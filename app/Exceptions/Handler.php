<?php
namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontReport = [];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        $category = $this->categorizeException($e);

        // Store category so TelemetryMiddleware can read it
        app()->instance('error_category', $category);

        if ($request->is('api/*') || $request->expectsJson()) {
            $statusCode = $this->getStatusCode($e);

            return response()->json([
                'status'         => 'error',
                'error_category' => $category,
                'message'        => $e->getMessage(),
                'errors'         => $e instanceof ValidationException
                                    ? $e->errors()
                                    : null,
            ], $statusCode);
        }

        return parent::render($request, $e);
    }

    private function categorizeException(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return 'VALIDATION_ERROR';
        }

        if ($e instanceof QueryException) {
            return 'DATABASE_ERROR';
        }

        // HTTP exceptions (abort())
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
            $code = $e->getStatusCode();
            if ($code === 408 || str_contains(strtolower($e->getMessage()), 'timeout')) {
                return 'TIMEOUT_ERROR';
            }
            return 'SYSTEM_ERROR';
        }

        if (str_contains(strtolower($e->getMessage()), 'timeout')) {
            return 'TIMEOUT_ERROR';
        }

        if ($e instanceof \RuntimeException) {
            return 'SYSTEM_ERROR';
        }

        return 'UNKNOWN';
    }

    private function getStatusCode(Throwable $e): int
    {
        if ($e instanceof ValidationException)  return 422;
        if ($e instanceof QueryException)       return 500;
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
            return $e->getStatusCode();
        }
        return 500;
    }
}