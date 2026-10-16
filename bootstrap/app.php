<?php

use App\Http\Middleware\EnsureAdminRole;
use App\Http\Middleware\EnsureDriverApproved;
use App\Http\Middleware\EnsureUserType;
use App\Http\Middleware\LogRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        attributes: ['middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'user.type' => EnsureUserType::class,
            'admin.role' => EnsureAdminRole::class,
            'driver.approved' => EnsureDriverApproved::class,
        ]);

        $middleware->api(prepend: [
            LogRequests::class,
        ]);

        // There is no login page to send guests to: an unauthenticated request
        // gets a 401, even without a JSON Accept header (a link preview, say).
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error_code' => 'UNAUTHENTICATED',
                ], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error_code' => 'VALIDATION_ERROR',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // Laravel converts ModelNotFoundException into NotFoundHttpException before
        // render callbacks run, so the original exception is inspected here. A
        // request that matched no route has no route; an abort(404, '...') from a
        // controller keeps its message.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => match (true) {
                        $e->getPrevious() instanceof ModelNotFoundException => 'Resource not found.',
                        $request->route() === null => 'The requested endpoint does not exist.',
                        default => $e->getMessage() ?: 'Resource not found.',
                    },
                    'error_code' => 'NOT_FOUND',
                ], 404);
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'An error occurred.',
                    'error_code' => 'HTTP_ERROR',
                ], $e->getStatusCode(), $e->getHeaders());
            }
        });

        // Malformed identifiers or dates that reach PostgreSQL (e.g. a non-UUID
        // filter value) are client errors, not server faults.
        $exceptions->render(function (QueryException $e, Request $request) {
            $invalidInputStates = ['22P02', '22007', '22008'];

            if (($request->is('api/*') || $request->expectsJson())
                && in_array($e->errorInfo[0] ?? $e->getCode(), $invalidInputStates, true)) {
                report($e);

                return response()->json([
                    'message' => 'One or more parameters have an invalid format.',
                    'error_code' => 'INVALID_PARAMETER',
                ], 422);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $isDebug = config('app.debug') || app()->environment('staging');

                return response()->json(array_filter([
                    'message' => $isDebug ? $e->getMessage() : 'Internal server error.',
                    'error_code' => 'SERVER_ERROR',
                    'details' => $isDebug ? [
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ] : null,
                ]), 500);
            }
        });
    })->create();
