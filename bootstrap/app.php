<?php

use App\Http\Middleware\AssignRequestId;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Lottery;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportDuplicates();
        // In an outage every request fails the same way; sample those reports so logs
        // stay readable. Deliberately cache-free: the cache may be what is down.
        $exceptions->throttle(fn (Throwable $e) => $e instanceof QueryException || $e instanceof PDOException
            ? Lottery::odds(1, 25)
            : Limit::none());

        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson());
        $exceptions->respond(function (Response $response) {
            if (! request()->is('api/*') || $response->getStatusCode() < 400) {
                return $response;
            }
            $status = $response->getStatusCode();
            $code = match ($status) {
                400 => 'bad_request', 401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found',
                405 => 'method_not_allowed', 413 => 'payload_too_large', 422 => 'validation_failed',
                429 => 'rate_limited', 503 => 'service_unavailable', default => 'request_failed',
            };
            $message = match ($status) {
                404 => 'The requested resource was not found.',
                405 => 'This method is not allowed for the requested resource.',
                422 => 'Some request parameters are invalid.',
                429 => 'Too many requests. Please try again shortly.',
                default => $status >= 500 ? 'The service is temporarily unavailable.' : 'The request could not be completed.',
            };
            $original = $response instanceof JsonResponse ? $response->getData(true) : [];
            $error = ['code' => $code, 'message' => $message];
            if ($requestId = request()->attributes->get('request_id')) {
                $error['request_id'] = $requestId;
            }
            $payload = ['data' => null, 'message' => $message, 'error' => $error];
            if ($status === 422 && isset($original['errors'])) {
                $payload['errors'] = $original['errors'];
            }

            return response()->json($payload, $status, array_merge($response->headers->all(), ['Cache-Control' => 'no-store']));
        });
    })->create();
