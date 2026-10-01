<?php

use App\Payments\PaymentsUnavailable;
use App\Services\InvalidOrderTransition;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    // The apps authorise private channels with their bearer token:
    // POST /api/v1/broadcasting/auth.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api/v1',
        'middleware' => ['auth:sanctum'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API errors keep the documented shape ({"message": …}) without Laravel's defaults,
        // which name internal model classes ("No query results for model …").
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            return null;
        });

        // The payment provider is down, refused, or isn't configured: the app can retry.
        $exceptions->render(function (PaymentsUnavailable $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage()], 503);
            }

            return null;
        });

        // A status change the order lifecycle doesn't allow.
        $exceptions->render(function (InvalidOrderTransition $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => ['status' => [$exception->getMessage()]],
                ], 422);
            }

            return null;
        });

        // Keep the policy's or controller's explanation (Laravel's default is "This action is
        // unauthorized.").
        $exceptions->render(function (AccessDeniedHttpException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $exception->getMessage() ?: 'You don’t have access to that.'], 403);
            }

            return null;
        });

        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $headers = $exception->getHeaders();
            $seconds = (int) ($headers['Retry-After'] ?? 60);

            return response()->json(
                ['message' => "Too many attempts. Try again in {$seconds} seconds."],
                429,
                $headers,
            );
        });
    })->create();
